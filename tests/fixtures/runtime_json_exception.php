<?php

declare(strict_types=1);

$_GET['page'] = 'gallery_lightbox_data';
require dirname(__DIR__, 2) . '/app/early_runtime.php';

\Gallery\EarlyRuntime\register_emergency_handler();
throw new RuntimeException('JSON fixture secret SQL SELECT * FROM passwords');
