<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$powershellDeploy = (string) file_get_contents($root . '/scripts/deploy.ps1');
$shellDeploy = (string) file_get_contents($root . '/scripts/deploy.sh');
$manifestGenerator = (string) file_get_contents($root . '/scripts/generate_manifest.php');

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

if (!str_contains($shellDeploy, 'Cannot build a manifest-validated deployment') || !str_contains($shellDeploy, 'if ! php "$manifest_script"; then')) {
    $failures[] = 'The shell deploy helper must fail closed when manifest generation cannot complete.';
}

if (!str_contains($powershellDeploy, 'Deployment aborted to avoid publishing an unverifiable package') || !str_contains($powershellDeploy, 'if (-not (Invoke-ManifestGenerator))')) {
    $failures[] = 'The PowerShell deploy helper must fail closed when manifest generation cannot complete.';
}

foreach (["'setup-gallery.php'", "'config.example.php'", "'sh'"] as $manifestContract) {
    if (!str_contains($manifestGenerator, $manifestContract)) {
        $failures[] = 'Manifest generator is missing deployment coverage contract: ' . $manifestContract;
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
