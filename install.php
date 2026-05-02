<?php

declare(strict_types=1);

session_name('gallery_cms_installer');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Variable $root stores this steps working value.
$root = __DIR__;
// Variable $configFile stores this steps working value.
$configFile = $root . '/config.php';
// Variable $migrationPath stores this steps working value.
$migrationPath = $root . '/database/migrations';
// Variable $messages stores this steps working value.
$messages = [];
// Variable $errors stores this steps working value.
$errors = [];
// Variable $installLockFile stores this steps working value.
$installLockFile = $root . '/cache/installed.lock';
// Variable $installationFinished stores this steps working value.
$installationFinished = false;

if (is_file($configFile) || is_file($installLockFile)) {
    http_response_code(403);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Installer locked</title><style>body{font-family:Arial,sans-serif;margin:40px;color:#1f2937}code{background:#eef2f7;padding:2px 5px;border-radius:4px}</style></head><body><main><h1>Installer locked</h1><p>The installer is disabled because this gallery already has a configuration or installation lock. Remove <code>config.php</code> and <code>cache/installed.lock</code> manually only if you intentionally want to reinstall from scratch.</p></main></body></html>';
    exit;
}

/**
 * Escape one value for safe HTML output.
 */
function installer_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Read one POST value as a trimmed string.
 */
function installer_post(string $name, string $default = ''): string
{
    return trim((string) ($_POST[$name] ?? $default));
}

/**
 * Generate a random application secret.
 */
function installer_random_secret(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Detect the public base URL from the current installer request.
 */
function installer_default_base_url(): string
{
    // Variable $forwardedProto stores this steps working value.
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    // Variable $https stores this steps working value.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || $forwardedProto === 'https'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
    // Variable $scheme stores this steps working value.
    $scheme = $https ? 'https' : 'http';
    // Variable $host stores this steps working value.
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    // Variable $script stores this steps working value.
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
    // Variable $dir stores this steps working value.
    $dir = rtrim(str_replace('/install.php', '', $script), '/');
    return rtrim($scheme . '://' . $host . $dir, '/');
}

/**
 * Build a MySQL PDO DSN from installer fields.
 */
function installer_mysql_dsn(string $host, string $port = '', ?string $database = null): string
{
    // Variable $normalizedHost stores this steps working value.
    $normalizedHost = $host !== '' ? $host : 'localhost';
    // Variable $dsn stores this steps working value.
    $dsn = 'mysql:host=' . $normalizedHost . ';charset=utf8mb4';
    if ($database !== null && $database !== '') {
        $dsn .= ';dbname=' . $database;
    }
    if ($port !== '') {
        $dsn .= ';port=' . (int) $port;
    }
    return $dsn;
}

/**
 * Connect to the existing application database.
 */
function installer_connect_database(array $database): PDO
{
    return new PDO(
        installer_mysql_dsn((string) $database['host'], (string) $database['port'], (string) $database['name']),
        (string) $database['user'],
        (string) $database['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

/**
 * Validate and normalize the first installer step.
 */
function installer_collect_database_step(array $defaults): array
{
    // Variable $siteName stores this steps working value.
    $siteName = installer_post('site_name', $defaults['site_name']);
    // Variable $baseUrl stores this steps working value.
    $baseUrl = rtrim(installer_post('base_url', $defaults['base_url']), '/');
    // Variable $dbHost stores this steps working value.
    $dbHost = installer_post('db_host', $defaults['db_host']);
    // Variable $dbPort stores this steps working value.
    $dbPort = installer_post('db_port', $defaults['db_port']);
    // Variable $dbName stores this steps working value.
    $dbName = installer_post('db_name', $defaults['db_name']);
    // Variable $dbUser stores this steps working value.
    $dbUser = installer_post('db_user', $defaults['db_user']);
    // Variable $dbPassword stores this steps working value.
    $dbPassword = (string) ($_POST['db_password'] ?? '');

    if ($siteName === '') {
        throw new RuntimeException('Gallery name is required. You can change it later in Admin.');
    }
    if ($baseUrl === '') {
        throw new RuntimeException('Domain is required. The detected value is usually correct.');
    }
    if ($dbName === '' || $dbUser === '' || $dbPassword === '') {
        throw new RuntimeException('Database name, database user, and database password are required.');
    }
    if ($dbPort !== '' && (!ctype_digit($dbPort) || (int) $dbPort < 1 || (int) $dbPort > 65535)) {
        throw new RuntimeException('Database port must be empty or a number between 1 and 65535.');
    }
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Domain must be a valid absolute URL, for example https://galerie.example.com.');
    }

    return [
        'site_name' => substr($siteName, 0, 120),
        'base_url' => $baseUrl,
        'database' => [
            'host' => $dbHost !== '' ? $dbHost : 'localhost',
            'port' => $dbPort,
            'name' => $dbName,
            'user' => $dbUser,
            'password' => $dbPassword,
            'charset' => 'utf8mb4',
        ],
    ];
}

/**
 * Run all pending database migrations.
 */
function installer_run_migrations(PDO $pdo, string $migrationPath): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(64) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Variable $applied stores this steps working value.
    $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    // Variable $applied stores this steps working value.
    $applied = array_flip($applied);
    // Variable $files stores this steps working value.
    $files = glob($migrationPath . '/*.php') ?: [];
    sort($files);
    // Variable $ran stores this steps working value.
    $ran = [];

    foreach ($files as $file) {
        // Variable $version stores this steps working value.
        $version = basename($file, '.php');
        if (isset($applied[$version])) {
            continue;
        }
        // Variable $statements stores this steps working value.
        $statements = require $file;
        foreach ($statements as $statement) {
            installer_apply_migration_statement($pdo, $statement);
        }
        // Variable $stmt stores this steps working value.
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
        $stmt->execute([$version, date('Y-m-d H:i:s')]);
        $ran[] = $version;
    }

    return $ran;
}

/**
 * Apply one migration statement and tolerate duplicate schema objects.
 */
function installer_apply_migration_statement(PDO $pdo, string $statement): void
{
    try {
        $pdo->exec($statement);
    } catch (PDOException $exception) {
        if (!installer_duplicate_ddl_error($exception)) {
            throw $exception;
        }
    }
}

/**
 * Return true when a migration error is safe to ignore during reinstall-like recovery.
 */
function installer_duplicate_ddl_error(PDOException $exception): bool
{
    // Variable $driverCode stores this steps working value.
    $driverCode = (int) ($exception->errorInfo[1] ?? $exception->getCode());
    if (in_array($driverCode, [1050, 1060, 1061, 1826], true)) {
        return true;
    }

    // Variable $message stores this steps working value.
    $message = $exception->getMessage();
    return str_contains($message, 'already exists')
        || str_contains($message, 'Duplicate column name')
        || str_contains($message, 'Duplicate key name')
        || str_contains($message, 'Duplicate foreign key constraint name')
        || str_contains($message, 'errno: 121');
}

/**
 * Write the final application config.php file.
 */
function installer_write_config(string $configFile, array $config): void
{
    // Variable $php stores this steps working value.
    $php = "<?php\n\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($configFile, $php, LOCK_EX) === false) {
        throw new RuntimeException('Could not write config.php. Check folder permissions.');
    }
}

/**
 * Create the galleries and ZIP cache folders if needed.
 */
function installer_prepare_writable_folders(array $paths): void
{
    foreach ($paths as $path) {
        if (!is_dir($path) && !mkdir($path, 0775, true)) {
            throw new RuntimeException('Could not create folder: ' . $path);
        }
        if (!is_writable($path)) {
            throw new RuntimeException('Folder is not writable by PHP: ' . $path);
        }
    }
}

/**
 * Create or replace the first web admin account.
 */
function installer_create_admin_user(PDO $pdo, string $username, string $password): void
{
    // Variable $stmt stores this steps working value.
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role), updated_at = VALUES(updated_at)');
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
}

/**
 * Store the public site name in application settings.
 */
function installer_save_site_name(PDO $pdo, string $siteName): void
{
    // Variable $stmt stores this steps working value.
    $stmt = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
    $stmt->execute(['site_name', $siteName, date('Y-m-d H:i:s')]);
}

/**
 * Build final application config from verified installer data.
 */
function installer_build_config(array $setup, string $root): array
{
    return [
        'database' => [
            'host' => (string) $setup['database']['host'],
            'port' => (string) $setup['database']['port'] === '' ? null : (int) $setup['database']['port'],
            'name' => (string) $setup['database']['name'],
            'user' => (string) $setup['database']['user'],
            'password' => (string) $setup['database']['password'],
            'charset' => 'utf8mb4',
        ],
        'base_url' => (string) $setup['base_url'],
        'galleries_root' => $root . '/galleries',
        'zip_cache_path' => $root . '/cache/zips',
        'admin_session_name' => 'gallery_admin_session',
        'visitor_vote_secret' => installer_random_secret(),
        'setup_key' => installer_random_secret(),
    ];
}

if (empty($_SESSION['installer_token'])) {
    $_SESSION['installer_token'] = bin2hex(random_bytes(16));
}

// Variable $defaults stores this steps working value.
$defaults = [
    'site_name' => 'My Gallery',
    'base_url' => installer_default_base_url(),
    'db_host' => 'localhost',
    'db_port' => '',
    'db_name' => '',
    'db_user' => '',
    'admin_username' => 'admin',
];

// Variable $currentStep stores this steps working value.
$currentStep = isset($_SESSION['installer_setup']) ? 2 : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string) $_SESSION['installer_token'], (string) ($_POST['token'] ?? ''))) {
            throw new RuntimeException('Invalid installer token. Reload the installer and try again.');
        }

        // Variable $action stores this steps working value.
        $action = (string) ($_POST['installer_action'] ?? '');

        if ($action === 'reset_database_step') {
            unset($_SESSION['installer_setup']);
            $currentStep = 1;
        } elseif ($action === 'database_step') {
            // Variable $setup stores this steps working value.
            $setup = installer_collect_database_step($defaults);
            // Variable $pdo stores this steps working value.
            $pdo = installer_connect_database($setup['database']);
            $pdo->query('SELECT 1')->fetchColumn();
            $_SESSION['installer_setup'] = $setup;
            $_SESSION['installer_token'] = bin2hex(random_bytes(16));
            $messages[] = 'Database connection verified. Continue with the web admin account.';
            $currentStep = 2;
        } elseif ($action === 'admin_step') {
            if (empty($_SESSION['installer_setup']) || !is_array($_SESSION['installer_setup'])) {
                throw new RuntimeException('Database setup was not verified yet. Start with the first step.');
            }

            // Variable $setup stores this steps working value.
            $setup = $_SESSION['installer_setup'];
            // Variable $adminUser stores this steps working value.
            $adminUser = installer_post('admin_username', $defaults['admin_username']);
            // Variable $adminPassword stores this steps working value.
            $adminPassword = (string) ($_POST['admin_password'] ?? '');
            // Variable $adminPasswordConfirm stores this steps working value.
            $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

            if ($adminUser === '' || $adminPassword === '') {
                throw new RuntimeException('Admin username and password are required.');
            }
            if (strlen($adminPassword) < 8) {
                throw new RuntimeException('Admin password must be at least 8 characters.');
            }
            if ($adminPassword !== $adminPasswordConfirm) {
                throw new RuntimeException('Admin passwords do not match.');
            }

            // Variable $galleriesRoot stores this steps working value.
            $galleriesRoot = $root . '/galleries';
            // Variable $zipCachePath stores this steps working value.
            $zipCachePath = $root . '/cache/zips';
            installer_prepare_writable_folders([$galleriesRoot, $zipCachePath]);

            // Variable $pdo stores this steps working value.
            $pdo = installer_connect_database($setup['database']);
            // Variable $ran stores this steps working value.
            $ran = installer_run_migrations($pdo, $migrationPath);
            installer_create_admin_user($pdo, $adminUser, $adminPassword);
            installer_save_site_name($pdo, (string) $setup['site_name']);
            installer_write_config($configFile, installer_build_config($setup, $root));

            if (!is_dir(dirname($installLockFile))) {
                mkdir(dirname($installLockFile), 0775, true);
            }
            file_put_contents($installLockFile, 'installed=' . gmdate('c') . PHP_EOL, LOCK_EX);

            unset($_SESSION['installer_setup']);
            $_SESSION['installer_token'] = bin2hex(random_bytes(16));
            $messages[] = 'Installation finished.';
            $messages[] = $ran ? 'Applied migrations: ' . implode(', ', $ran) : 'No pending migrations.';
            $messages[] = 'Admin user is ready.';
            $messages[] = 'The installer is now disabled for future requests.';
            $installationFinished = true;
            $currentStep = 3;
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
        $currentStep = empty($_SESSION['installer_setup']) ? 1 : 2;
    }
}

// Variable $setup stores this steps working value.
$setup = (isset($_SESSION['installer_setup']) && is_array($_SESSION['installer_setup'])) ? $_SESSION['installer_setup'] : [];

// Variable $value stores this steps working value.
$value = static function (string $name) use ($defaults, $setup): string {
    if ($name === 'site_name' && isset($setup['site_name'])) {
        return installer_e((string) $setup['site_name']);
    }
    if ($name === 'base_url' && isset($setup['base_url'])) {
        return installer_e((string) $setup['base_url']);
    }
    if (isset($setup['database'][$name])) {
        return installer_e((string) $setup['database'][$name]);
    }
    return installer_e((string) ($_POST[$name] ?? $defaults[$name] ?? ''));
};

// Variable $adminUrl stores this steps working value.
$adminUrl = 'index.php?page=admin_login';

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($installationFinished): ?>
        <meta http-equiv="refresh" content="5;url=<?php echo installer_e($adminUrl); ?>">
    <?php endif; ?>
    <title>Gallery Installer</title>
    <style>
        :root {
            color-scheme: light;
            --page: #f5f5f7;
            --panel: rgba(255, 255, 255, 0.88);
            --panel-strong: #ffffff;
            --text: #1d1d1f;
            --muted: #6e6e73;
            --line: rgba(0, 0, 0, 0.12);
            --accent: #0071e3;
            --accent-dark: #005bbd;
            --ok-bg: #edf8f1;
            --ok-line: #b7e3c6;
            --bad-bg: #fff0f0;
            --bad-line: #ffc6c6;
            --shadow: 0 24px 70px rgba(0, 0, 0, 0.10);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(0, 113, 227, 0.13), transparent 34rem),
                linear-gradient(180deg, #fbfbfd 0%, var(--page) 100%);
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; }
        main { width: min(920px, calc(100vw - 32px)); margin: 0 auto; padding: 44px 0 54px; }
        h1 { margin: 0; font-size: clamp(34px, 6vw, 58px); letter-spacing: -0.045em; line-height: 1.02; }
        h2 { margin: 0; font-size: 24px; letter-spacing: -0.02em; }
        p { line-height: 1.55; }
        a { color: var(--accent); font-weight: 700; text-decoration: none; }
        a:hover { text-decoration: underline; }
        code { background: rgba(0, 0, 0, 0.06); padding: 2px 5px; border-radius: 5px; }
        .hero { text-align: center; margin-bottom: 26px; }
        .hero p { max-width: 690px; margin: 14px auto 0; color: var(--muted); font-size: 17px; }
        .steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 24px 0; }
        .step-pill { border: 1px solid var(--line); background: rgba(255, 255, 255, 0.6); border-radius: 999px; padding: 10px 12px; color: var(--muted); text-align: center; font-size: 13px; font-weight: 700; }
        .step-pill.is-active { color: #fff; background: var(--accent); border-color: var(--accent); }
        .step-pill.is-done { color: var(--text); background: rgba(255, 255, 255, 0.92); }
        .panel { background: var(--panel); border: 1px solid rgba(255, 255, 255, 0.7); border-radius: 28px; padding: clamp(22px, 4vw, 34px); box-shadow: var(--shadow); backdrop-filter: blur(20px); }
        .panel + .panel { margin-top: 18px; }
        .panel-header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin-bottom: 24px; }
        .panel-header p { margin: 8px 0 0; color: var(--muted); }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        label { display: grid; gap: 7px; font-weight: 700; font-size: 14px; }
        input { width: 100%; min-height: 46px; border: 1px solid var(--line); border-radius: 14px; padding: 0 13px; font: inherit; background: var(--panel-strong); color: var(--text); outline: none; transition: border-color 0.16s ease, box-shadow 0.16s ease; }
        input:focus { border-color: rgba(0, 113, 227, 0.75); box-shadow: 0 0 0 4px rgba(0, 113, 227, 0.13); }
        .full { grid-column: 1 / -1; }
        .help { margin: 0; color: var(--muted); font-size: 13px; font-weight: 400; line-height: 1.45; }
        .compact-note { margin: 18px 0 0; padding: 13px 15px; border: 1px solid var(--line); border-radius: 16px; background: rgba(255, 255, 255, 0.62); color: var(--muted); font-size: 13px; }
        .notice { border-radius: 18px; padding: 13px 15px; margin: 0 0 14px; }
        .ok { background: var(--ok-bg); border: 1px solid var(--ok-line); }
        .bad { background: var(--bad-bg); border: 1px solid var(--bad-line); }
        .actions { display: flex; align-items: center; gap: 12px; margin-top: 24px; flex-wrap: wrap; }
        button, .button-link { min-height: 46px; border: 0; border-radius: 999px; padding: 0 21px; background: var(--accent); color: #fff; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font: inherit; text-decoration: none; }
        button:hover, .button-link:hover { background: var(--accent-dark); text-decoration: none; }
        button.secondary { background: rgba(0, 0, 0, 0.06); color: var(--text); }
        button.secondary:hover { background: rgba(0, 0, 0, 0.10); }
        .summary-list { display: grid; gap: 10px; margin: 18px 0 0; }
        .summary-item { display: flex; justify-content: space-between; gap: 16px; border-bottom: 1px solid var(--line); padding-bottom: 10px; }
        .summary-item span:first-child { color: var(--muted); }
        .summary-item span:last-child { font-weight: 700; text-align: right; overflow-wrap: anywhere; }
        .success { text-align: center; }
        .success h2 { font-size: clamp(30px, 5vw, 46px); }
        .success p { color: var(--muted); }
        @media (max-width: 760px) {
            main { width: min(100vw - 22px, 920px); padding-top: 24px; }
            .grid, .steps { grid-template-columns: 1fr; }
            .panel-header { display: grid; }
            .summary-item { display: grid; gap: 4px; }
            .summary-item span:last-child { text-align: left; }
        }
    </style>
</head>
<body>
<main>
    <section class="hero">
        <h1>Set up your gallery.</h1>
        <p>The installer only asks for what is required. Existing database, web admin account, done. Technical paths are prepared automatically.</p>
    </section>

    <div class="steps" aria-label="Installation steps">
        <div class="step-pill<?php echo $currentStep === 1 ? ' is-active' : ($currentStep > 1 ? ' is-done' : ''); ?>">1. Gallery and database</div>
        <div class="step-pill<?php echo $currentStep === 2 ? ' is-active' : ($currentStep > 2 ? ' is-done' : ''); ?>">2. Web admin</div>
        <div class="step-pill<?php echo $currentStep === 3 ? ' is-active' : ''; ?>">3. Finished</div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="notice bad"><?php echo installer_e($error); ?></div>
    <?php endforeach; ?>

    <?php if ($messages): ?>
        <div class="notice ok">
            <?php foreach ($messages as $message): ?>
                <div><?php echo installer_e($message); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($installationFinished): ?>
        <section class="panel success">
            <h2>Your gallery is ready.</h2>
            <p>You will be redirected to the admin login in 5 seconds.</p>
            <p><a class="button-link" href="<?php echo installer_e($adminUrl); ?>">Open admin login now</a></p>
            <p>Keep <code>cache/installed.lock</code> in place. You can delete <code>install.php</code> after confirming the site works.</p>
        </section>
    <?php elseif ($currentStep === 1): ?>
        <form method="post" class="panel">
            <input type="hidden" name="token" value="<?php echo installer_e((string) $_SESSION['installer_token']); ?>">
            <input type="hidden" name="installer_action" value="database_step">

            <div class="panel-header">
                <div>
                    <h2>Gallery and database</h2>
                    <p>Name the site and connect to the database your hosting already created.</p>
                </div>
            </div>

            <div class="grid">
                <label class="full">Gallery name
                    <input name="site_name" value="<?php echo $value('site_name'); ?>" maxlength="120" autocomplete="organization" required>
                    <span class="help">This is the public title shown in the header and browser title. You can change it later in Admin.</span>
                </label>

                <label class="full">Domain
                    <input name="base_url" value="<?php echo $value('base_url'); ?>" inputmode="url" autocomplete="url" required>
                    <span class="help">The installer detected this from the current page. Confirm it, or adjust it if your public gallery uses a different domain such as <code>https://galerie.klusik.cz</code>.</span>
                </label>

                <label>Database server
                    <input name="db_host" value="<?php echo $value('db_host'); ?>" autocomplete="off" placeholder="localhost">
                    <span class="help">Leave empty if your hosting does not show it. The installer will use <code>localhost</code>.</span>
                </label>

                <label>Database port
                    <input name="db_port" value="<?php echo $value('db_port'); ?>" inputmode="numeric" autocomplete="off" placeholder="Leave empty">
                    <span class="help">Leave empty unless your hosting explicitly gives you a port. MySQL normally uses its default port automatically.</span>
                </label>

                <label>Database name
                    <input name="db_name" value="<?php echo $value('db_name'); ?>" autocomplete="off" required>
                </label>

                <label>Database user
                    <input name="db_user" value="<?php echo $value('db_user'); ?>" autocomplete="username" required>
                </label>

                <label class="full">Database password
                    <input name="db_password" type="password" autocomplete="current-password" required>
                    <span class="help">This is the password for the database user from your hosting control panel.</span>
                </label>
            </div>

            <p class="compact-note">The installer will not create a database or database user. Most shared hosting providers block that anyway. Use the database name, user, and password you already created in hosting administration.</p>

            <div class="actions">
                <button type="submit">Continue</button>
            </div>
        </form>
    <?php elseif ($currentStep === 2): ?>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Verified database</h2>
                    <p>The installer can connect to your existing database.</p>
                </div>
                <form method="post">
                    <input type="hidden" name="token" value="<?php echo installer_e((string) $_SESSION['installer_token']); ?>">
                    <input type="hidden" name="installer_action" value="reset_database_step">
                    <button class="secondary" type="submit">Change database</button>
                </form>
            </div>

            <div class="summary-list">
                <div class="summary-item"><span>Gallery</span><span><?php echo installer_e((string) ($setup['site_name'] ?? '')); ?></span></div>
                <div class="summary-item"><span>Domain</span><span><?php echo installer_e((string) ($setup['base_url'] ?? '')); ?></span></div>
                <div class="summary-item"><span>Database</span><span><?php echo installer_e((string) ($setup['database']['name'] ?? '')); ?></span></div>
                <div class="summary-item"><span>Automatic galleries folder</span><span><?php echo installer_e($root . '/galleries'); ?></span></div>
                <div class="summary-item"><span>Automatic ZIP cache folder</span><span><?php echo installer_e($root . '/cache/zips'); ?></span></div>
            </div>
        </section>

        <form method="post" class="panel">
            <input type="hidden" name="token" value="<?php echo installer_e((string) $_SESSION['installer_token']); ?>">
            <input type="hidden" name="installer_action" value="admin_step">

            <div class="panel-header">
                <div>
                    <h2>Web admin account</h2>
                    <p>This is not the database admin. This account is used to log in to the gallery Admin area, upload photos, edit galleries, and manage settings.</p>
                </div>
            </div>

            <div class="grid">
                <label>Admin username
                    <input name="admin_username" value="<?php echo installer_e((string) ($_POST['admin_username'] ?? $defaults['admin_username'])); ?>" autocomplete="username" required>
                </label>

                <label>Admin password
                    <input name="admin_password" type="password" autocomplete="new-password" required>
                    <span class="help">Use at least 8 characters.</span>
                </label>

                <label class="full">Confirm admin password
                    <input name="admin_password_confirm" type="password" autocomplete="new-password" required>
                </label>
            </div>

            <p class="compact-note">After this step the installer writes <code>config.php</code>, runs database migrations, creates <code>/galleries</code> and <code>/cache/zips</code>, creates the admin user, and locks itself.</p>

            <div class="actions">
                <button type="submit">Finish setup</button>
            </div>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
