<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Principal;
use Throwable;

/**
 * Spec 5.5: a model tool also respects the model's Policy: view for one record, viewAny for a list.
 * With no Policy for the model, the tool's Gate (stage 2) has already decided alone, so this allows.
 * Used by model_show (01-21) and later list tools.
 */
final class ModelPolicy
{
    public function __construct(
        private readonly Gate $gate,
        private readonly GateWarning $warning,
    ) {}

    public function view(Principal $principal, Model $record): GateDecision
    {
        return $this->decide($principal, 'view', $record, $record::class);
    }

    /** @param  class-string<Model>  $modelClass */
    public function viewAny(Principal $principal, string $modelClass): GateDecision
    {
        return $this->decide($principal, 'viewAny', $modelClass, $modelClass);
    }

    private function decide(Principal $principal, string $ability, Model|string $argument, string $modelClass): GateDecision
    {
        if ($this->gate->getPolicyFor($argument) === null) {
            return GateDecision::allow();
        }

        $subject = $principal->subject();

        try {
            // A Policy without this method denies (Laravel's rule); that is kept.
            $response = $this->gate->forUser($subject)->inspect($ability, $argument);
        } catch (Throwable $e) {
            $this->warning->write("the {$ability} Policy of {$modelClass}", $e, $subject);

            return GateDecision::deny(Reason::GateError);
        }

        return $response->allowed()
            ? GateDecision::allow()
            : GateDecision::deny(Reason::PolicyDenied, $response->message());
    }
}
