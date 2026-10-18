<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Authorization\ModelPolicy;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Authorization\Invoice;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Authorization\InvoicePolicy;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Authorization\Note;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Authorization\UserOnlyInvoicePolicy;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Authorization\ViewlessInvoicePolicy;
use SinaSaeedi\RuntimeMcp\Tests\Support\RecordingLogger;

/*
 * Spec 5.5: model tools respect the model's Policy, view for one record and viewAny for a list.
 * With no Policy for the model, the tool's Gate decides alone. 01-21 (model_show) is the first user.
 */

function modelPolicyKey(string $name = 'sina'): Principal
{
    return new Principal($name, AuthMethod::ApiKey);
}

it('allows a model with no Policy, for view and viewAny', function () {
    $policies = app(ModelPolicy::class);

    expect(Gate::getPolicyFor(Note::class))->toBeNull()
        ->and($policies->view(modelPolicyKey('ops'), new Note)->allowed)->toBeTrue()
        ->and($policies->viewAny(modelPolicyKey('ops'), Note::class)->allowed)->toBeTrue();
});

it('follows a Policy that allows', function () {
    Gate::policy(Invoice::class, InvoicePolicy::class);
    $policies = app(ModelPolicy::class);

    expect($policies->view(modelPolicyKey('sina'), new Invoice)->allowed)->toBeTrue()
        ->and($policies->viewAny(modelPolicyKey('sina'), Invoice::class)->allowed)->toBeTrue();
});

it('denies policy_denied with the Policy\'s own message', function () {
    Gate::policy(Invoice::class, InvoicePolicy::class);
    $policies = app(ModelPolicy::class);

    $view = $policies->view(modelPolicyKey('ops'), new Invoice);
    $viewAny = $policies->viewAny(modelPolicyKey('ops'), Invoice::class);

    expect($view->allowed)->toBeFalse()
        ->and($view->reason?->value)->toBe('policy_denied')
        ->and($view->message)->toBe('Only sina may see invoices.')
        ->and($viewAny->reason?->value)->toBe('policy_denied')
        ->and($viewAny->message)->toBeNull();
});

it('denies gate_error, and warns, when a Policy typed User meets an unlinked key', function () {
    Gate::policy(Invoice::class, UserOnlyInvoicePolicy::class);
    $log = RecordingLogger::install();
    $policies = app(ModelPolicy::class);

    $view = $policies->view(modelPolicyKey(), new Invoice);
    $viewAny = $policies->viewAny(modelPolicyKey(), Invoice::class);

    expect($view->reason?->value)->toBe('gate_error')
        ->and($viewAny->reason?->value)->toBe('gate_error')
        ->and($log->messages())->toBe([
            'runtime-mcp: the view Policy of '.Invoice::class.' (or a Gate::before() callback) threw TypeError for a Principal subject. Type the callback as Principal|User.',
            'runtime-mcp: the viewAny Policy of '.Invoice::class.' (or a Gate::before() callback) threw TypeError for a Principal subject. Type the callback as Principal|User.',
        ]);
});

it('allows the same Policy for a key linked to a user', function () {
    Gate::policy(Invoice::class, UserOnlyInvoicePolicy::class);
    $linked = new Principal('sina', AuthMethod::ApiKey, (new User)->forceFill(['id' => 7]));

    expect(app(ModelPolicy::class)->view($linked, new Invoice)->allowed)->toBeTrue();
});

it('denies policy_denied when the Policy has no view or viewAny method', function () {
    Gate::policy(Invoice::class, ViewlessInvoicePolicy::class);
    $policies = app(ModelPolicy::class);

    expect($policies->view(modelPolicyKey(), new Invoice)->reason?->value)->toBe('policy_denied')
        ->and($policies->viewAny(modelPolicyKey(), Invoice::class)->reason?->value)->toBe('policy_denied');
});
