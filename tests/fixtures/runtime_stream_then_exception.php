<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/early_runtime.php';

\Gallery\EarlyRuntime\register_emergency_handler();
header('Content-Type: application/octet-stream');
header('Content-Length: 4');
echo 'DATA';
flush();
throw new RuntimeException('stream fixture failure');
