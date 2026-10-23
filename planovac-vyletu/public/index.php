<?php

declare(strict_types=1);

// Spuštění: php -S 0.0.0.0:8000 -t public public/index.php

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config.php';
Planovac\Bootstrap::app($config)->run();
