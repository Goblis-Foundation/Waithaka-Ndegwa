<?php
declare(strict_types=1);

/** Create one new, explicitly named disposable database. Never reuse or drop one. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function wk_test_database_settings(): array
{
    if (getenv('WK_TEST_ALLOW_WRITES') !== '1') {
        throw new RuntimeException('Database tests require WK_TEST_ALLOW_WRITES=1.');
    }
    $host = getenv('WK_TEST_DB_HOST');
    $port = getenv('WK_TEST_DB_PORT');
    $user = getenv('WK_TEST_DB_USER');
    $pass = getenv('WK_TEST_DB_PASS');
    // Numeric loopback avoids localhost silently choosing a different MySQL socket.
    if (!in_array($host, ['127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Set WK_TEST_DB_HOST to a numeric loopback address for an isolated test server.');
    }
    if ($port === false || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        throw new RuntimeException('Set WK_TEST_DB_PORT explicitly to the isolated test server port.');
    }
    if ($user === false || $user === '' || $pass === false) {
        throw new RuntimeException('Set WK_TEST_DB_USER and WK_TEST_DB_PASS explicitly; an empty fixture password is allowed.');
    }
    return ['host' => $host, 'port' => (int)$port, 'user' => $user, 'pass' => $pass, 'charset' => 'utf8mb4'];
}

function wk_test_assert_database_name(string $name): void
{
    if (strlen($name) > 64 || !preg_match('/^waithaka_test_[a-z0-9_]+_test$/D', $name)) {
        throw new RuntimeException('Database names must start waithaka_test_, end _test, and contain only lowercase letters, numbers and underscores (maximum 64 characters).');
    }
}

function wk_test_prepare(string $name, bool $empty): void
{
    wk_test_assert_database_name($name);
    $settings = wk_test_database_settings();
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('The pdo_mysql extension is required.');
    $dsn = 'mysql:host='.$settings['host'].';port='.$settings['port'].';charset=utf8mb4';
    try {
        $admin = new PDO($dsn, $settings['user'], $settings['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        throw new RuntimeException('Cannot connect to the explicitly configured loopback test database server.', 0, $exception);
    }
    $existing = $admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');
    $existing->execute([$name]);
    if ((int)$existing->fetchColumn() !== 0) {
        throw new RuntimeException('Refusing to reuse an existing database: '.$name.'. Choose a fresh name.');
    }
    // No IF NOT EXISTS: a concurrent name collision must also fail without reuse.
    $admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $version = (string)$admin->query('SELECT VERSION()')->fetchColumn();
    echo 'Created disposable database '.$name.' on '.$version.".\n";
    if ($empty) {
        echo "Kept empty for the publishing suite's own disposable-table initialization.\n";
        return;
    }

    // Deliberately avoid bootstrap.php and app/config.php. Only fixture configuration
    // reaches the ordinary migration engine, including its migration hash ledger.
    $GLOBALS['CONFIG'] = [
        'app_env' => 'testing',
        'base_url' => 'http://127.0.0.1',
        'app_key' => 'local-test-key-not-for-production-use',
        'db' => array_merge($settings, ['name' => $name]),
    ];
    $GLOBALS['APP_INSTALLED'] = true;
    date_default_timezone_set('Africa/Nairobi');
    require_once dirname(__DIR__).'/app/functions.php';
    require_once dirname(__DIR__).'/app/migrations.php';
    foreach (wk_migrate(true) as $action) echo $action."\n";
    if (wk_migrate(false, true) !== []) throw new RuntimeException('Prepared database still has pending migrations.');
}

// run.php includes these guarded helpers; direct invocation prepares exactly one DB.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $name = null;
        $empty = false;
        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--help') {
                echo "Usage: php tests/prepare.php --database=waithaka_test_<unique_name>_test [--empty]\n\n";
                echo "Requires WK_TEST_ALLOW_WRITES=1 and explicit WK_TEST_DB_HOST, WK_TEST_DB_PORT,\n";
                echo "WK_TEST_DB_USER and WK_TEST_DB_PASS. Only numeric loopback hosts are accepted.\n";
                echo "Creates a new database and applies the base schema plus all numbered migrations.\n";
                echo "--empty reserves a new empty database for a suite that initializes its own tables.\n";
                echo "Existing databases are refused. Nothing is dropped automatically.\n";
                exit(0);
            }
            if (str_starts_with($argument, '--database=') && $name === null) $name = substr($argument, 11);
            elseif ($argument === '--empty' && !$empty) $empty = true;
            else throw new RuntimeException('Unknown or repeated option. Use --help.');
        }
        if ($name === null) throw new RuntimeException('An explicit --database name is required. Use --help.');
        wk_test_prepare($name, $empty);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Test database preparation failed: '.$exception->getMessage()."\n");
        exit(1);
    }
}
