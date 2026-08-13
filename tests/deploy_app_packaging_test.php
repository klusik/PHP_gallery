<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$powershellDeploy = (string) file_get_contents($root . '/scripts/deploy.ps1');
$shellDeploy = (string) file_get_contents($root . '/scripts/deploy.sh');

$failures = [];

if (!str_contains($powershellDeploy, '$alwaysIncludeRelatives = @(' . "'app'" . ')')) {
    $failures[] = 'The PowerShell deploy script must always include the complete app directory.';
}

if (!str_contains($shellDeploy, 'always_include_relatives=("app")')) {
    $failures[] = 'The shell deploy script must always include the complete app directory.';
}

if (!str_contains($powershellDeploy, ".Replace('\\', '/')")) {
    $failures[] = 'The PowerShell deploy ZIP must use portable forward-slash entry paths.';
}

foreach ([$powershellDeploy, $shellDeploy] as $deploySource) {
    if (stripos($deploySource, 'manifest') !== false
        || str_contains($deploySource, '& php ')
        || preg_match('/^\s*php\s/m', $deploySource) === 1) {
        $failures[] = 'Deploy helpers must package files without manifest handling or PHP execution.';
    }
}

foreach (['app/bootstrap/dispatch.php', 'app/controllers/public_gallery_page.php', 'app/lang/en.json'] as $relativePath) {
    if (!is_file($root . '/' . $relativePath)) {
        $failures[] = 'Expected application fixture is missing: ' . $relativePath;
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Deploy app packaging checks passed.\n";
