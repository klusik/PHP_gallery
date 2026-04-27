<?php

declare(strict_types=1);

session_name('gallery_cms_installer');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$root = __DIR__;
$configFile = $root . '/config.php';
$migrationPath = $root . '/database/migrations';
$messages = [];
$errors = [];

function installer_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installer_post(string $name, string $default = ''): string
{
    return trim((string) ($_POST[$name] ?? $default));
}

function installer_random_secret(): string
{
    return bin2hex(random_bytes(32));
}

function installer_default_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
    $dir = rtrim(str_replace('/install.php', '', $script), '/');
    return $scheme . '://' . $host . $dir;
}

function installer_mysql_dsn(string $host, string $port = '', ?string $database = null): string
{
    $dsn = 'mysql:host=' . $host . ';charset=utf8mb4';
    if ($database !== null && $database !== '') {
        $dsn .= ';dbname=' . $database;
    }
    if ($port !== '') {
        $dsn .= ';port=' . (int) $port;
    }
    return $dsn;
}

function installer_mysql_identifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('Database names may only contain letters, numbers, and underscores.');
    }
    return '`' . str_replace('`', '``', $name) . '`';
}

function installer_mysql_account(string $user, string $host): string
{
    return "'" . str_replace("'", "''", $user) . "'@'" . str_replace("'", "''", $host) . "'";
}

function installer_account_hosts(string $dbHost, string $accountHost): array
{
    if ($accountHost !== '') {
        return [$accountHost];
    }
    if ($dbHost === '127.0.0.1' || $dbHost === 'localhost' || $dbHost === '::1') {
        return ['127.0.0.1', 'localhost'];
    }
    return ['%'];
}

function installer_create_or_update_user(PDO $pdo, string $appUser, string $appPassword, string $appHost, string $plugin): void
{
    if (!preg_match('/^[A-Za-z0-9_@.-]+$/', $appUser)) {
        throw new InvalidArgumentException('Database usernames may only contain letters, numbers, dots, dashes, underscores, and @.');
    }
    if (!in_array($plugin, ['', 'caching_sha2_password', 'mysql_native_password'], true)) {
        throw new InvalidArgumentException('Unsupported authentication plugin.');
    }

    $account = installer_mysql_account($appUser, $appHost);
    $password = $pdo->quote($appPassword);
    $identity = $plugin === '' ? ' IDENTIFIED BY ' . $password : ' IDENTIFIED WITH ' . $plugin . ' BY ' . $password;

    try {
        $pdo->exec('CREATE USER IF NOT EXISTS ' . $account . $identity);
    } catch (PDOException $exception) {
        if (!str_contains($exception->getMessage(), 'already exists')) {
            throw $exception;
        }
    }
    $pdo->exec('ALTER USER ' . $account . $identity);
}

function installer_run_migrations(PDO $pdo, string $migrationPath): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(64) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip($applied);
    $files = glob($migrationPath . '/*.php') ?: [];
    sort($files);
    $ran = [];

    foreach ($files as $file) {
        $version = basename($file, '.php');
        if (isset($applied[$version])) {
            continue;
        }
        $statements = require $file;
        $pdo->beginTransaction();
        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
            $stmt->execute([$version, date('Y-m-d H:i:s')]);
            $pdo->commit();
            $ran[] = $version;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    return $ran;
}

function installer_write_config(string $configFile, array $config): void
{
    $php = "<?php\n\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($configFile, $php, LOCK_EX) === false) {
        throw new RuntimeException('Could not write config.php. Check folder permissions.');
    }
}

if (empty($_SESSION['installer_token'])) {
    $_SESSION['installer_token'] = bin2hex(random_bytes(16));
}

$defaults = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'gallery_cms',
    'db_user' => 'gallery_user',
    'db_user_host' => '',
    'auth_plugin' => 'caching_sha2_password',
    'base_url' => installer_default_base_url(),
    'galleries_root' => $root . '/galleries',
    'zip_cache_path' => $root . '/cache/zips',
    'admin_username' => 'admin',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string) $_SESSION['installer_token'], (string) ($_POST['token'] ?? ''))) {
            throw new RuntimeException('Invalid installer token. Reload the installer and try again.');
        }
        if (is_file($configFile) && (string) ($_POST['overwrite_config'] ?? '') !== '1') {
            throw new RuntimeException('config.php already exists. Tick the overwrite box if you want to replace it.');
        }

        $dbHost = installer_post('db_host', $defaults['db_host']);
        $dbPort = installer_post('db_port', $defaults['db_port']);
        $dbName = installer_post('db_name', $defaults['db_name']);
        $dbUser = installer_post('db_user', $defaults['db_user']);
        $dbPassword = (string) ($_POST['db_password'] ?? '');
        $dbUserHost = installer_post('db_user_host', $defaults['db_user_host']);
        $authPlugin = installer_post('auth_plugin', $defaults['auth_plugin']);
        $adminUser = installer_post('admin_username', $defaults['admin_username']);
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        $baseUrl = rtrim(installer_post('base_url', $defaults['base_url']), '/');
        $galleriesRoot = installer_post('galleries_root', $defaults['galleries_root']);
        $zipCachePath = installer_post('zip_cache_path', $defaults['zip_cache_path']);

        if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPassword === '') {
            throw new RuntimeException('Database host, name, app user, and app user password are required.');
        }
        if ($adminUser === '' || $adminPassword === '') {
            throw new RuntimeException('Admin username and password are required.');
        }
        if ($dbPort !== '' && (!ctype_digit($dbPort) || (int) $dbPort < 1 || (int) $dbPort > 65535)) {
            throw new RuntimeException('Database port must be a number between 1 and 65535.');
        }

        $adminPdo = new PDO(installer_mysql_dsn($dbHost, $dbPort), (string) ($_POST['db_admin_user'] ?? ''), (string) ($_POST['db_admin_password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $dbIdentifier = installer_mysql_identifier($dbName);
        $adminPdo->exec('CREATE DATABASE IF NOT EXISTS ' . $dbIdentifier . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $messages[] = 'Database is ready.';

        $accountHosts = installer_account_hosts($dbHost, $dbUserHost);
        foreach ($accountHosts as $accountHost) {
            installer_create_or_update_user($adminPdo, $dbUser, $dbPassword, $accountHost, $authPlugin);
            $adminPdo->exec('GRANT ALL PRIVILEGES ON ' . $dbIdentifier . '.* TO ' . installer_mysql_account($dbUser, $accountHost));
        }
        $adminPdo->exec('FLUSH PRIVILEGES');
        $messages[] = 'Database user is ready.';

        foreach ([$galleriesRoot, $zipCachePath] as $path) {
            if (!is_dir($path) && !mkdir($path, 0775, true)) {
                throw new RuntimeException('Could not create folder: ' . $path);
            }
            if (!is_writable($path)) {
                throw new RuntimeException('Folder is not writable by PHP: ' . $path);
            }
        }
        $messages[] = 'Writable folders are ready.';

        $config = [
            'database' => [
                'host' => $dbHost,
                'port' => $dbPort === '' ? null : (int) $dbPort,
                'name' => $dbName,
                'user' => $dbUser,
                'password' => $dbPassword,
                'charset' => 'utf8mb4',
            ],
            'base_url' => $baseUrl,
            'galleries_root' => $galleriesRoot,
            'zip_cache_path' => $zipCachePath,
            'admin_session_name' => 'gallery_admin_session',
            'visitor_vote_secret' => installer_random_secret(),
            'setup_key' => installer_random_secret(),
        ];
        installer_write_config($configFile, $config);
        $messages[] = 'config.php was written.';

        $appPdo = new PDO(installer_mysql_dsn($dbHost, $dbPort, $dbName), $dbUser, $dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $ran = installer_run_migrations($appPdo, $migrationPath);
        $messages[] = $ran ? 'Applied migrations: ' . implode(', ', $ran) : 'No pending migrations.';

        $stmt = $appPdo->prepare('INSERT INTO users (username, password_hash, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), updated_at = VALUES(updated_at)');
        $stmt->execute([$adminUser, password_hash($adminPassword, PASSWORD_DEFAULT), 'admin', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $messages[] = 'Admin user is ready.';
        $_SESSION['installer_token'] = bin2hex(random_bytes(16));
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

$value = static function (string $name) use ($defaults): string {
    return installer_e((string) ($_POST[$name] ?? $defaults[$name] ?? ''));
};

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gallery CMS Installer</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; color: #1f2933; background: #f5f7fa; }
        body { margin: 0; }
        main { max-width: 980px; margin: 0 auto; padding: 32px 18px 48px; }
        h1 { margin: 0 0 8px; font-size: 32px; }
        h2 { margin: 0 0 16px; font-size: 20px; }
        p { line-height: 1.5; }
        .panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 20px; margin-top: 18px; }
        .section { border-top: 1px solid #d9e2ec; padding-top: 20px; margin-top: 20px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 18px; }
        label { display: grid; gap: 6px; font-weight: 700; font-size: 14px; }
        input, select { min-height: 42px; border: 1px solid #bcccdc; border-radius: 6px; padding: 0 10px; font: inherit; }
        .full { grid-column: 1 / -1; }
        .help { margin: 6px 0 0; color: #52606d; font-size: 13px; font-weight: 400; }
        .notice { border-radius: 8px; padding: 12px 14px; margin-top: 14px; }
        .ok { background: #e3f8ee; border: 1px solid #8eedbd; }
        .bad { background: #ffe8e8; border: 1px solid #ffb4b4; }
        .actions { display: flex; align-items: center; gap: 16px; margin-top: 18px; flex-wrap: wrap; }
        button { min-height: 44px; border: 0; border-radius: 6px; padding: 0 18px; background: #1f6f8b; color: #fff; font-weight: 700; cursor: pointer; }
        .check { display: flex; align-items: center; gap: 8px; font-weight: 400; }
        .check input { min-height: auto; }
        code { background: #eef2f7; padding: 2px 5px; border-radius: 4px; }
        @media (max-width: 720px) { .grid { grid-template-columns: 1fr; } main { padding-top: 20px; } }
    </style>
</head>
<body>
<main>
    <h1>Gallery CMS Installer</h1>
    <p>Use this once to create the database, write <code>config.php</code>, run migrations, create folders, and add the first admin user.</p>

    <?php foreach ($errors as $error): ?>
        <div class="notice bad"><?php echo installer_e($error); ?></div>
    <?php endforeach; ?>

    <?php if ($messages): ?>
        <div class="notice ok">
            <?php foreach ($messages as $message): ?>
                <div><?php echo installer_e($message); ?></div>
            <?php endforeach; ?>
            <p>Installation finished. Open <a href="index.php?page=admin_login">the admin login</a>, then delete or block <code>install.php</code>.</p>
        </div>
    <?php endif; ?>

    <form method="post" class="panel">
        <input type="hidden" name="token" value="<?php echo installer_e((string) $_SESSION['installer_token']); ?>">

        <h2>Database Admin Login</h2>
        <div class="grid">
            <label>Host
                <input name="db_host" value="<?php echo $value('db_host'); ?>" required>
            </label>
            <label>Port
                <input name="db_port" value="<?php echo $value('db_port'); ?>" inputmode="numeric">
            </label>
            <label>Admin user
                <input name="db_admin_user" value="<?php echo installer_e((string) ($_POST['db_admin_user'] ?? 'root')); ?>" required>
            </label>
            <label>Admin password
                <input name="db_admin_password" type="password">
            </label>
        </div>

        <div class="section">
            <h2>Gallery Database</h2>
            <div class="grid">
                <label>Database name
                    <input name="db_name" value="<?php echo $value('db_name'); ?>" required>
                </label>
                <label>App database user
                    <input name="db_user" value="<?php echo $value('db_user'); ?>" required>
                </label>
                <label>App database password
                    <input name="db_password" type="password" required>
                </label>
                <label>MySQL account host
                    <input name="db_user_host" value="<?php echo $value('db_user_host'); ?>" placeholder="Auto">
                    <span class="help">Leave empty for local installs. The installer will create both <code>127.0.0.1</code> and <code>localhost</code> users.</span>
                </label>
                <label class="full">Authentication plugin
                    <select name="auth_plugin">
                        <?php $selectedPlugin = (string) ($_POST['auth_plugin'] ?? $defaults['auth_plugin']); ?>
                        <option value=""<?php echo $selectedPlugin === '' ? ' selected' : ''; ?>>Server default</option>
                        <option value="caching_sha2_password"<?php echo $selectedPlugin === 'caching_sha2_password' ? ' selected' : ''; ?>>caching_sha2_password</option>
                        <option value="mysql_native_password"<?php echo $selectedPlugin === 'mysql_native_password' ? ' selected' : ''; ?>>mysql_native_password</option>
                    </select>
                    <span class="help">Use <code>caching_sha2_password</code> for newer MySQL. Use server default for MariaDB if the selected plugin is unavailable.</span>
                </label>
            </div>
        </div>

        <div class="section">
            <h2>Application</h2>
            <div class="grid">
                <label class="full">Base URL
                    <input name="base_url" value="<?php echo $value('base_url'); ?>">
                </label>
                <label class="full">Galleries folder
                    <input name="galleries_root" value="<?php echo $value('galleries_root'); ?>" required>
                </label>
                <label class="full">ZIP cache folder
                    <input name="zip_cache_path" value="<?php echo $value('zip_cache_path'); ?>" required>
                </label>
            </div>
        </div>

        <div class="section">
            <h2>First Admin</h2>
            <div class="grid">
                <label>Username
                    <input name="admin_username" value="<?php echo $value('admin_username'); ?>" required>
                </label>
                <label>Password
                    <input name="admin_password" type="password" required>
                </label>
            </div>
        </div>

        <div class="actions">
            <button type="submit">Install Gallery CMS</button>
            <?php if (is_file($configFile)): ?>
                <label class="check"><input type="checkbox" name="overwrite_config" value="1"> Overwrite existing config.php</label>
            <?php endif; ?>
        </div>
    </form>
</main>
</body>
</html>
