<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/early_runtime.php';

\Gallery\EarlyRuntime\register_emergency_handler();
throw new RuntimeException('fixture secret path /srv/private/config.php');
