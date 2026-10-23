<?php
declare(strict_types=1);

/** Repeatable, offline application tests. Each suite executes in its own process. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__.'/prepare.php';

function wk_test_help(): void
{
    echo <<<'HELP'
Usage:
  php tests/run.php --lint     PHP syntax and JavaScript syntax (requires Node.js).
  php tests/run.php --unit     Pure policy/runtime tests and offline AWS-plan tests; no database.
  php tests/run.php            All suites, including isolated DB and local HTTP tests.

Full local example, for the project's isolated MariaDB server:
  WK_TEST_ALLOW_WRITES=1 \
  WK_TEST_DB_HOST=127.0.0.1 WK_TEST_DB_PORT=3307 \
  WK_TEST_DB_USER=root WK_TEST_DB_PASS='' composer --no-plugins test

Use fixture credentials for a disposable server only. The account needs CREATE
DATABASE plus schema/table privileges. Each run creates fresh, explicitly named
waithaka_test_<run>_<suite>_test databases. Existing databases are never reused.
Databases are retained for inspection; this runner never drops them. GitHub CI
discards its entire MySQL service container after each job.

APP/DB/provider environment variables and app/config.php are not inherited by
test processes. Mail is disabled. HTTP uses the safe test router on its own
temporary loopback port, then terminates only the server it started.

CI runs PHP 8.3 and 8.4 against MySQL 8.4. Local MariaDB results establish local
compatibility only; they do not replace the MySQL CI matrix.

Dependency checks used by CI:
  composer validate --strict
  composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
  composer check-platform-reqs
  composer audit --locked --no-interaction

HELP;
}

function wk_test_child_environment(): array
{
    $environment = [];
    // An allowlist, not a denylist, prevents inheriting production connection,
    // provider, Composer auth, AWS profile or automatic PHP-prepend variables.
    foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR', 'LANG', 'LC_ALL'] as $key) {
        $value = getenv($key);
        if ($value !== false) $environment[$key] = $value;
    }
    $environment['AWS_EC2_METADATA_DISABLED'] = 'true';
    return $environment;
}

function wk_test_stop_process(mixed &$process): void
{
    if (!is_resource($process)) return;
    $status = proc_get_status($process);
    if ($status['running']) {
        proc_terminate($process);
        $deadline = microtime(true) + 2;
        do {
            usleep(50000);
            $status = proc_get_status($process);
        } while ($status['running'] && microtime(true) < $deadline);
        if ($status['running']) proc_terminate($process, 9);
    }
    proc_close($process);
    $process = null;
}

function wk_test_process_command(array $command, array $environment): array
{
    // PHP proc_open omits empty environment values on the local PHP 8.3 build.
    // Restore only empty assignments through env(1); nonempty credentials always
    // stay in the environment and never appear in process arguments or logs.
    $empty = [];
    foreach ($environment as $key => $value) if ($value === '') $empty[] = $key.'=';
    return $empty ? array_merge(['/usr/bin/env'], $empty, $command) : $command;
}

function wk_test_process(array $command, array $environment, string $label, bool $quiet = false, int $timeout = 120): bool
{
    $output = tmpfile();
    if ($output === false) throw new RuntimeException('Cannot create a temporary process log.');
    $process = null;
    try {
        // Array arguments bypass the shell. Passwords travel only in the child environment.
        $process = proc_open(wk_test_process_command($command, $environment), [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start '.$label.'.');
        $deadline = microtime(true) + $timeout;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                wk_test_stop_process($process);
                throw new RuntimeException($label.' exceeded its '.$timeout.' second timeout.');
            }
            usleep(50000);
        } while (true);
        $closed = proc_close($process);
        $process = null;
        $code = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        rewind($output);
        $text = (string)stream_get_contents($output);
        if (!$quiet || $code !== 0) {
            echo "\n[".($code === 0 ? 'PASS' : 'FAIL').'] '.$label."\n";
            if ($text !== '') echo rtrim($text)."\n";
        }
        return $code === 0;
    } finally {
        wk_test_stop_process($process);
        fclose($output);
    }
}

function wk_test_lint(array $environment): bool
{
    $root = dirname(__DIR__);
    $files = [$root.'/index.php'];
    foreach (['app', 'bin', 'deploy', 'install', 'tests', 'views', 'assets/js'] as $directory) {
        if (!is_dir($root.'/'.$directory)) continue;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) continue;
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($relative === 'app/config.php' || preg_match('~(?:^|/)\.~', $relative)) continue;
            if (in_array($file->getExtension(), ['php', 'js'], true)) $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);
    $counts = ['php' => 0, 'js' => 0];
    $passed = true;
    foreach ($files as $file) {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $command = $extension === 'php' ? [PHP_BINARY, '-l', $file] : ['node', '--check', $file];
        $ok = wk_test_process($command, $environment, substr($file, strlen($root) + 1), true, 30);
        $passed = $ok && $passed;
        $counts[$extension]++;
    }
    echo ($passed ? 'PASS' : 'FAIL').': syntax checks for '.$counts['php'].' PHP files and '.$counts['js']." JavaScript files.\n";
    return $passed;
}

function wk_test_fixture_environment(array $base, string $configuration): array
{
    return array_merge($base, [
        'APP_CONFIG_FILE' => $configuration,
        'APP_ENV' => 'testing',
        'APP_READY' => '1',
        'APP_URL' => 'http://127.0.0.1',
        'APP_KEY' => 'local-test-key-not-for-production-use',
        'APP_TIMEZONE' => 'Africa/Nairobi',
        'APP_FORCE_CANONICAL' => '0',
        'APP_ALLOWED_HOSTS' => '127.0.0.1',
        'TRUSTED_PROXY_CIDRS' => '',
        'SESSION_DRIVER' => 'database',
        'MAIL_TRANSPORT' => 'disabled',
        'MAIL_FROM' => 'fixture@example.invalid',
        'MAIL_FROM_NAME' => 'Local test fixture',
        'MAIL_POSTAL_ADDRESS' => 'Synthetic test address',
        'MEDIA_DRIVER' => 'local',
    ]);
}

function wk_test_suite_environment(array $base, array $settings, string $name): array
{
    wk_test_assert_database_name($name);
    $dsn = 'mysql:host='.$settings['host'].';port='.$settings['port'].';dbname='.$name.';charset=utf8mb4';
    return array_merge($base, [
        'WK_TEST_ALLOW_WRITES' => '1',
        'WK_TEST_DB_HOST' => $settings['host'],
        'WK_TEST_DB_PORT' => (string)$settings['port'],
        'WK_TEST_DB_USER' => $settings['user'],
        'WK_TEST_DB_PASS' => $settings['pass'],
        'WK_TEST_DB_NAME' => $name,
        'DB_HOST' => $settings['host'],
        'DB_PORT' => (string)$settings['port'],
        'DB_DATABASE' => $name,
        'DB_USERNAME' => $settings['user'],
        'DB_PASSWORD' => $settings['pass'],
        'DB_SSL_CA' => '',
        'JOURNEYS_TEST_ALLOW_WRITES' => '1',
        'JOURNEYS_TEST_DSN' => $dsn,
        'JOURNEYS_TEST_USER' => $settings['user'],
        'JOURNEYS_TEST_PASSWORD' => $settings['pass'],
        'PUBLISHING_TEST_ALLOW_WRITES' => '1',
        'PUBLISHING_TEST_DSN' => $dsn,
        'PUBLISHING_TEST_USER' => $settings['user'],
        'PUBLISHING_TEST_PASSWORD' => $settings['pass'],
    ]);
}

function wk_test_http(array $environment, string $temporaryDirectory): array
{
    if (!extension_loaded('curl')) throw new RuntimeException('HTTP tests require the curl extension.');
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if ($socket === false) throw new RuntimeException('Cannot reserve a temporary loopback port for HTTP tests.');
    $address = (string)stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr(strrchr($address, ':'), 1);
    if ($port < 1) throw new RuntimeException('Could not select the HTTP test port.');
    $baseUrl = 'http://127.0.0.1:'.$port;
    $environment['APP_URL'] = $baseUrl;
    $environment['WK_TEST_BASE_URL'] = $baseUrl;
    $environment['TEST_BASE_URL'] = $baseUrl;
    $log = $temporaryDirectory.'/http-server.log';
    $process = null;
    try {
        $serverCommand = [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', dirname(__DIR__), __DIR__.'/010_security_router.php'];
        $process = proc_open(wk_test_process_command($serverCommand, $environment),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start the isolated HTTP test server.');
        $deadline = microtime(true) + 15;
        $ready = false;
        do {
            if (!proc_get_status($process)['running']) break;
            // Verify our process actually bound the port before contacting it.
            $started = str_contains((string)@file_get_contents($log), 'Development Server ('.$baseUrl.') started');
            if ($started) {
                $handle = curl_init($baseUrl.'/healthz');
                curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500,
                    CURLOPT_CONNECTTIMEOUT_MS => 250, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '']);
                $body = curl_exec($handle);
                $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
                curl_close($handle);
                if ($status === 200 && $body === '{"ok":true}' && proc_get_status($process)['running']) {
                    $ready = true;
                    break;
                }
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        if (!$ready) throw new RuntimeException('The isolated HTTP test server did not become ready.');
        $results = ['passed' => 0, 'failures' => []];
        foreach (['010_security_http.php' => 'HTTP security and shared layouts',
                  'publishing_http_test.php' => 'HTTP publishing workflow',
                  'newsletter_http_test.php' => 'HTTP newsletter workflow',
                  'implementation_http_test.php' => 'HTTP media, operations and feedback',
                  'archive_http_test.php' => 'HTTP archive discovery and editorial privacy',
                  'editorial_http.php' => 'HTTP editorial publishing and evidence',
                  'reader_http_test.php' => 'HTTP reader preferences and follow-up',
                  'discovery_http_test.php' => 'HTTP search and discovery controls',
                  'growth_http_test.php' => 'HTTP Google tag coverage and campaigns'] as $file => $label) {
            if (wk_test_process([PHP_BINARY, __DIR__.'/'.$file], $environment, $label)) $results['passed']++;
            else $results['failures'][] = $label;
        }
        if ($results['failures']) echo substr((string)file_get_contents($log), -12000)."\n";
        return $results;
    } catch (Throwable $exception) {
        if (is_file($log)) fwrite(STDERR, substr((string)file_get_contents($log), -12000)."\n");
        throw $exception;
    } finally {
        // Never pkill or terminate a server owned by another test run.
        wk_test_stop_process($process);
        if (is_file($log)) unlink($log);
    }
}

$temporaryDirectory = null;
$configuration = null;
$exitCode = 1;
try {
    $arguments = array_slice($argv, 1);
    if (count($arguments) > 1 || ($arguments && !in_array($arguments[0], ['--help', '--unit', '--lint'], true))) {
        throw new RuntimeException('Unknown test option. Use php tests/run.php --help.');
    }
    $mode = $arguments[0] ?? 'all';
    if ($mode === '--help') {
        wk_test_help();
        exit(0);
    }
    if (PHP_VERSION_ID < 80300) throw new RuntimeException('These tests require PHP 8.3 or newer.');
    $environment = wk_test_child_environment();
    if ($mode === '--lint') {
        $exitCode = wk_test_lint($environment) ? 0 : 1;
    } else {
        $settings = $mode === 'all' ? wk_test_database_settings() : null;
        $runId = gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
        $temporaryDirectory = sys_get_temp_dir().'/waithaka-tests-'.$runId;
        if (!mkdir($temporaryDirectory, 0700)) throw new RuntimeException('Cannot create the test fixture directory.');
        $configuration = $temporaryDirectory.'/fixture-config.php';
        if (file_put_contents($configuration, "<?php\nreturn [];\n") === false) throw new RuntimeException('Cannot write isolated fixture configuration.');
        chmod($configuration, 0600);
        $environment = wk_test_fixture_environment($environment, $configuration);
        echo 'Test runtime: PHP '.PHP_VERSION."\n";
        $failed = [];
        $suiteCount = 0;
        $passedCount = 0;
        foreach (['010_security_unit.php' => 'Pure security', 'publishing_test.php' => 'Pure publishing',
                  'runtime_config_test.php' => 'Pure runtime configuration',
                  'impact_unit.php' => '2030 reporting validation and source boundaries',
                  'google_workspace_unit.php'=>'Google report, calendar selection and MCP boundaries',
                  'meetings_unit.php'=>'Meeting account, read permissions and join-link boundaries',
                  'studio_unit.php' => 'Private capture and explicit AI drafting boundaries',
                  'meta_mcp_unit.php' => 'Local aggregate Meta MCP lifecycle and privacy',
                  'instagram_engagement_unit.php' => 'Instagram engagement ownership and private read boundaries',
                  'media_centre_unit.php' => 'Social reporting privacy, input and identity boundaries',
                  'newsletter_unit.php'=>'Pure newsletter policy','youtube_test.php'=>'Pure YouTube policy','tiktok_test.php'=>'Pure TikTok permissions and reporting',
                  'tiktok_business_test.php'=>'Pure TikTok Accounts API publishing policy',
                  'analytics_unit.php'=>'Analytics destination and campaign privacy',
                  'editorial_unit.php'=>'Editorial content and evidence privacy',
                  'reader_unit.php'=>'Reader preferences and private-link policy',
                  'discovery_test.php'=>'Search, report and image contracts',
                  'readiness_unit.php'=>'Pure operations diagnostics','mail_feedback_unit.php'=>'Signed SNS and email contracts'] as $file => $label) {
            $suiteCount++;
            if (wk_test_process([PHP_BINARY, __DIR__.'/'.$file], $environment, $label)) $passedCount++;
            else $failed[] = $label;
        }
        $suiteCount++;
        if(wk_test_process(['node',__DIR__.'/analytics_browser_test.js'],$environment,'Offline Google tag consent and duplicate-event states'))$passedCount++;
        else $failed[]='Offline Google tag consent and duplicate-event states';
        $suiteCount++;
        if(wk_test_process(['python3','-B',__DIR__.'/readiness_deployment_test.py'],$environment,'Offline AWS recovery and cutover plans'))$passedCount++;
        else $failed[]='Offline AWS recovery and cutover plans';
        foreach (['branded_dns_test.py'=>'Offline branded DNS preparation and recovery',
                  'dns_probe_test.py'=>'Offline authoritative, parent and recursive DNS checks',
                  'google_migration_deployment_test.py'=>'Temporary migration access cleanup boundaries'] as $file => $label) {
            $suiteCount++;
            if (wk_test_process(['python3','-B',__DIR__.'/'.$file], $environment, $label)) $passedCount++;
            else $failed[]=$label;
        }
        if ($mode === 'all') {
            $suiteCount++;
            if(wk_test_process([PHP_BINARY,__DIR__.'/youtube_http_test.php'],$environment,'YouTube resumable protocol fixture'))$passedCount++;
            else $failed[]='YouTube resumable protocol fixture';
            $suites = [
                'security' => '010_security_database.php',
                'journeys' => 'journeys_workflows.php',
                'publishing' => 'publishing_database_test.php',
                'operations' => 'operations_test.php',
                'newsletter' => 'newsletter_database_test.php',
                'readiness' => 'readiness_database.php',
                'feedback' => 'mail_feedback_database.php',
                'media' => 'media_library_database.php',
                'youtube' => 'youtube_database_test.php',
                'tiktok' => 'tiktok_database_test.php',
                'tiktok_business' => 'tiktok_business_database_test.php',
                'editorial' => 'editorial_database.php',
                'reader' => 'reader_database_test.php',
                'discovery' => 'discovery_database_test.php',
                'campaign' => 'campaign_database_test.php',
                'google' => 'google_workspace_database.php',
                'http' => '010_security_http.php',
            ];
            foreach ($suites as $suite => $file) {
                $suiteCount += $suite === 'http' ? 9 : 1;
                $name = 'waithaka_test_'.$runId.'_'.$suite.'_test';
                $suiteEnvironment = wk_test_suite_environment($environment, $settings, $name);
                $prepare = [PHP_BINARY, __DIR__.'/prepare.php', '--database='.$name];
                if ($suite === 'publishing') $prepare[] = '--empty';
                try {
                    if (!wk_test_process($prepare, $suiteEnvironment, 'Prepare '.$suite.' database')) {
                        $failed[] = $suite.' preparation';
                        continue;
                    }
                    if ($suite === 'http') {
                        $results = wk_test_http($suiteEnvironment, $temporaryDirectory);
                        $passedCount += $results['passed'];
                        $failed = array_merge($failed, $results['failures']);
                    } elseif (wk_test_process([PHP_BINARY, __DIR__.'/'.$file], $suiteEnvironment, ucfirst($suite).' database')) {
                        $passedCount++;
                    } else $failed[] = $suite;
                } catch (Throwable $exception) {
                    $failed[] = $suite;
                    fwrite(STDERR, '[FAIL] '.$suite.': '.$exception->getMessage()."\n");
                }
            }
            echo "\nDisposable databases were retained for inspection; no databases were dropped.\n";
        }
        $exitCode = $failed ? 1 : 0;
        echo "\n".($failed ? 'FAIL' : 'PASS').': '.$passedCount.'/'.$suiteCount." suites passed.\n";
        if ($failed) echo 'Failed suites: '.implode(', ', $failed)."\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Test runner failed: '.$exception->getMessage()."\n");
} finally {
    if ($configuration !== null && is_file($configuration)) unlink($configuration);
    if ($temporaryDirectory !== null && is_dir($temporaryDirectory)) rmdir($temporaryDirectory);
}
exit($exitCode);
