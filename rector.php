<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Classes',
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(codeQuality: true, deadCode: true);
