<?php

declare(strict_types=1);

arch('every file in src/ declares strict types', function () {
    expect('SinaSaeedi\RuntimeMcp')->toUseStrictTypes();
});
