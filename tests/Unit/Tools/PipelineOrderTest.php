<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tools\GuardPipeline;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\AuditStage;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\CheckGateStage;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\ReadOnlyStage;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\RedactStage;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\ResolvePrincipalStage;

/*
 * Spec 4.4: the order is the security design. Every story that inserts a stage (01-09, 01-16,
 * 01-17) changes this list, so the order is reviewed each time it changes.
 */

it('runs the stages in the spec 4.4 order, outermost first', function () {
    expect(GuardPipeline::STAGES)->toBe([
        AuditStage::class,
        RedactStage::class,
        ResolvePrincipalStage::class,
        CheckGateStage::class,
        ReadOnlyStage::class,
    ]);
});
