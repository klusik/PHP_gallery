<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$ran = run_migrations();
echo $ran ? "Applied migrations:\n" . implode("\n", $ran) . "\n" : "No pending migrations.\n";

