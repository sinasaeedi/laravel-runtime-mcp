<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

/** 01-38: #[RendersApp] only on the parent class; laravel/mcp's ReadsAttributes still finds it. */
final class RendersAppChildTool extends RendersAppTool {}
