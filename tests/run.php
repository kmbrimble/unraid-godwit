<?php
/**
 * Godwit Phase 1 test suite. Hand-rolled PHP CLI runner, no framework —
 * matches Dormouse's tests/run.php pattern. Run: php tests/run.php
 */
declare(strict_types=1);

$repoRoot = dirname(__DIR__);
require $repoRoot . '/plugin/scripts/lib.php';
require $repoRoot . '/scripts/version-sorts-after.php';

$passed = 0;
$failed = 0;

function t(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "PASS: $name\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "FAIL: $name — " . $e->getMessage() . "\n";
        $failed++;
    }
}

function assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        throw new \RuntimeException($message);
    }
}

function assert_eq($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException("$message (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
    }
}

// --- Version-sort rule -------------------------------------------------

t('version_sorts_after: patch bump sorts after', function () {
    assert_true(version_sorts_after('0.1.0', '0.1.1'), '0.1.1 should sort after 0.1.0');
});

t('version_sorts_after: strcmp, not semver — 0.1.10 sorts BEFORE 0.1.9', function () {
    assert_true(!version_sorts_after('0.1.9', '0.1.10'), '0.1.10 must not sort after 0.1.9 under strcmp');
});

t('version_sorts_after: minor bump sorts after', function () {
    assert_true(version_sorts_after('0.1.9', '0.2.0'), '0.2.0 should sort after 0.1.9');
});

// --- godwit.plg structure -----------------------------------------------

$plgPath = $repoRoot . '/godwit.plg';
$plgRaw = file_get_contents($plgPath);

t('godwit.plg: is well-formed XML (a bare & in an INLINE block breaks the plugin manager\'s parse)', function () use ($plgPath) {
    $prevSetting = libxml_use_internal_errors(true);
    $parsed = simplexml_load_file($plgPath);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prevSetting);
    assert_true($parsed !== false, 'godwit.plg failed to parse as XML: ' . implode('; ', array_map(fn ($e) => trim($e->message), $errors)));
});

t('godwit.plg: version entity and CHANGES entry match', function () use ($plgRaw) {
    preg_match('/<!ENTITY version\s+"([^"]+)">/', $plgRaw, $m);
    assert_true(isset($m[1]), 'version entity not found');
    assert_true(str_contains($plgRaw, '###' . $m[1]), 'no ###' . $m[1] . ' entry in <CHANGES>');
});

t('godwit.plg: txz FILE block has an MD5 tag resolving to a 32-hex-char value', function () use ($plgRaw) {
    assert_true(str_contains($plgRaw, '<MD5>&md5;</MD5>'), 'txz FILE block must carry an <MD5> tag (the release workflow overwrites &md5; with the real hash)');
    preg_match('/<!ENTITY md5\s+"([0-9a-f]+)">/', $plgRaw, $m);
    assert_true(isset($m[1]) && strlen($m[1]) === 32, 'md5 entity must be a 32-hex-char value (placeholder or real)');
});

t('godwit.plg: install/remove scripts gated on -f, never -x', function () use ($plgRaw) {
    assert_true(!str_contains($plgRaw, '[[ -x '), 'found an executable-bit gate ([[ -x ) — must be [[ -f ');
    assert_true(str_contains($plgRaw, '[[ -f &emhttp;/scripts/rc.godwit ]]'), 'expected an [[ -f ]] gate on rc.godwit');
});

t('godwit.plg: FILE Run blocks use a shebang-free bash INLINE (Run="/bin/bash" itself is the interpreter)', function () use ($plgRaw) {
    assert_true(str_contains($plgRaw, 'Run="/bin/bash"'), 'expected Run="/bin/bash" on the install/remove FILE blocks');
});

t('godwit.plg: install step only starts godwitd when array-ready.sh says it is safe, gated on -f', function () use ($plgRaw) {
    assert_true(str_contains($plgRaw, '[[ -f &emhttp;/scripts/array-ready.sh ]]'), 'expected an [[ -f ]] gate on array-ready.sh');
    assert_true(str_contains($plgRaw, 'bash &emhttp;/scripts/array-ready.sh'), 'install step must consult array-ready.sh before starting');
});

// --- plugin/README.md stock shape ---------------------------------------

t('plugin/README.md: bold title, blank line, one paragraph, no headings', function () use ($repoRoot) {
    $readme = file_get_contents($repoRoot . '/plugin/README.md');
    $lines = explode("\n", trim($readme));
    assert_true(str_starts_with($lines[0], '**Godwit**'), 'first line must be a bold one-line title');
    assert_true($lines[1] === '', 'second line must be blank');
    foreach ($lines as $line) {
        assert_true(!str_starts_with($line, '#'), 'README must contain no headings, found: ' . $line);
    }
});

// --- rclone pin file + SHA256 verification --------------------------------

function godwit_parse_shell_env_file(string $path): array
{
    $env = [];
    foreach (file($path) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            $env[$m[1]] = trim($m[2], '"\'');
        }
    }
    return $env;
}

t('rclone-pin.env parses to the expected keys', function () use ($repoRoot) {
    $env = godwit_parse_shell_env_file($repoRoot . '/scripts/rclone-pin.env');
    foreach (['RCLONE_VERSION', 'RCLONE_ZIP_NAME', 'RCLONE_ZIP_URL', 'RCLONE_SUMS_URL'] as $key) {
        assert_true(isset($env[$key]) && $env[$key] !== '', "missing or empty $key in rclone-pin.env");
    }
    assert_true(str_contains($env['RCLONE_ZIP_URL'], $env['RCLONE_VERSION']), 'zip URL should reference the pinned version');
});

function godwit_run_verify_script(string $repoRoot, string $sumsFile, string $zipFile, string $zipName): array
{
    $cmd = sprintf(
        'bash %s %s %s %s 2>&1',
        escapeshellarg($repoRoot . '/scripts/verify-rclone-zip.sh'),
        escapeshellarg($sumsFile),
        escapeshellarg($zipFile),
        escapeshellarg($zipName)
    );
    exec($cmd, $output, $exitCode);
    return [$exitCode, implode("\n", $output)];
}

t('verify-rclone-zip.sh: accepts a matching sha256', function () use ($repoRoot) {
    $tmp = sys_get_temp_dir() . '/godwit-test-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $zipFile = $tmp . '/rclone-fake.zip';
    file_put_contents($zipFile, 'not a real zip, just needs a stable hash');
    $hash = hash_file('sha256', $zipFile);
    $sumsFile = $tmp . '/SHA256SUMS';
    file_put_contents($sumsFile, "$hash  rclone-fake.zip\n");

    [$exitCode, $out] = godwit_run_verify_script($repoRoot, $sumsFile, $zipFile, 'rclone-fake.zip');
    assert_eq(0, $exitCode, "expected success, got: $out");
});

t('verify-rclone-zip.sh: rejects a mismatched sha256', function () use ($repoRoot) {
    $tmp = sys_get_temp_dir() . '/godwit-test-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $zipFile = $tmp . '/rclone-fake.zip';
    file_put_contents($zipFile, 'not a real zip, just needs a stable hash');
    $sumsFile = $tmp . '/SHA256SUMS';
    file_put_contents($sumsFile, str_repeat('0', 64) . "  rclone-fake.zip\n");

    [$exitCode, ] = godwit_run_verify_script($repoRoot, $sumsFile, $zipFile, 'rclone-fake.zip');
    assert_true($exitCode !== 0, 'expected a non-zero exit on hash mismatch');
});

t('verify-rclone-zip.sh: rejects a SUMS file missing the entry', function () use ($repoRoot) {
    $tmp = sys_get_temp_dir() . '/godwit-test-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $zipFile = $tmp . '/rclone-fake.zip';
    file_put_contents($zipFile, 'irrelevant content');
    $sumsFile = $tmp . '/SHA256SUMS';
    file_put_contents($sumsFile, str_repeat('a', 64) . "  some-other-file.zip\n");

    [$exitCode, ] = godwit_run_verify_script($repoRoot, $sumsFile, $zipFile, 'rclone-fake.zip');
    assert_true($exitCode !== 0, 'expected a non-zero exit when the SUMS file has no matching entry');
});

// --- godwitd's rcd command-line builder ----------------------------------

t('godwit_rcd_argv: unix listener never contains --rc-no-auth, always contains --config', function () {
    $argv = godwit_rcd_argv('/opt/rclone', '/boot/config/plugins/godwit/rclone.conf', ['type' => 'unix', 'path' => '/var/run/godwit/rcd.sock'], '/var/run/godwit/rcd.log');
    foreach ($argv as $arg) {
        assert_true($arg !== '--rc-no-auth' && !str_starts_with($arg, '--rc-no-auth'), '--rc-no-auth must never appear');
    }
    assert_true((bool) array_filter($argv, fn ($a) => str_starts_with($a, '--config=')), 'missing --config= flag');
    assert_true(in_array('--rc-addr=unix:///var/run/godwit/rcd.sock', $argv, true), 'missing unix --rc-addr');
});

t('godwit_rcd_argv: tcp listener never contains --rc-no-auth, always contains --config and credentials', function () {
    $argv = godwit_rcd_argv('/opt/rclone', '/boot/config/plugins/godwit/rclone.conf', ['type' => 'tcp', 'host' => '127.0.0.1', 'port' => 54321, 'user' => 'u1', 'pass' => 'p1'], '/var/run/godwit/rcd.log');
    foreach ($argv as $arg) {
        assert_true(!str_starts_with($arg, '--rc-no-auth'), '--rc-no-auth must never appear');
    }
    assert_true((bool) array_filter($argv, fn ($a) => str_starts_with($a, '--config=')), 'missing --config= flag');
    assert_true(in_array('--rc-addr=127.0.0.1:54321', $argv, true), 'missing tcp --rc-addr');
    assert_true(in_array('--rc-user=u1', $argv, true), 'missing --rc-user');
    assert_true(in_array('--rc-pass=p1', $argv, true), 'missing --rc-pass');
});

t('godwit_rcd_argv: rejects an unknown listener type', function () {
    $threw = false;
    try {
        godwit_rcd_argv('/opt/rclone', '/cfg', ['type' => 'carrier-pigeon'], '/log');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected an InvalidArgumentException for an unknown listener type');
});

// --- shebangs present on every invoked script ----------------------------

t('every script the .plg or rc.godwit invokes directly has a shebang', function () use ($repoRoot) {
    $scripts = [
        $repoRoot . '/plugin/scripts/rc.godwit',
        $repoRoot . '/plugin/scripts/godwitd',
        $repoRoot . '/plugin/scripts/array-ready.sh',
        $repoRoot . '/plugin/event/started',
        $repoRoot . '/plugin/event/stopping_svcs',
        $repoRoot . '/scripts/build-plugin.sh',
        $repoRoot . '/scripts/verify-rclone-zip.sh',
        $repoRoot . '/scripts/install-on-host.sh',
        $repoRoot . '/scripts/uninstall-on-host.sh',
    ];
    foreach ($scripts as $script) {
        $first = fgets(fopen($script, 'r'));
        assert_true(str_starts_with((string) $first, '#!'), "$script has no shebang");
    }
});

t('event scripts invoke rc.godwit start/stop, and build-plugin.sh packages them executable', function () use ($repoRoot) {
    $started = file_get_contents($repoRoot . '/plugin/event/started');
    $stopping = file_get_contents($repoRoot . '/plugin/event/stopping_svcs');
    assert_true(str_contains($started, 'rc.godwit" start'), 'event/started must invoke rc.godwit start');
    assert_true(str_contains($stopping, 'rc.godwit" stop'), 'event/stopping_svcs must invoke rc.godwit stop');

    $buildScript = file_get_contents($repoRoot . '/scripts/build-plugin.sh');
    assert_true(str_contains($buildScript, 'event/started'), 'build-plugin.sh must chmod +x event/started — emhttp_event checks the executable bit, not -f');
    assert_true(str_contains($buildScript, 'event/stopping_svcs'), 'build-plugin.sh must chmod +x event/stopping_svcs');
});

// --- array-ready.sh: the install-time array/cache-readiness decision -----

function godwit_run_array_ready(string $repoRoot, array $env): array
{
    $envPrefix = implode(' ', array_map(fn ($k, $v) => $k . '=' . escapeshellarg((string) $v), array_keys($env), $env));
    exec("$envPrefix bash " . escapeshellarg($repoRoot . '/plugin/scripts/array-ready.sh') . ' 2>&1', $output, $exitCode);
    return [$exitCode, implode("\n", $output)];
}

t('array-ready.sh: array STARTED + cache mounted -> ready (exit 0)', function () use ($repoRoot) {
    $tmp = sys_get_temp_dir() . '/godwit-array-ready-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $varIni = $tmp . '/var.ini';
    file_put_contents($varIni, "mdState=\"STARTED\"\n");

    [$code, ] = godwit_run_array_ready($repoRoot, ['GODWIT_VARINI' => $varIni, 'GODWIT_TEST_CACHE_MOUNTED' => '1']);
    assert_eq(0, $code, 'expected ready when array is STARTED and cache is mounted');
});

t('array-ready.sh: array STARTED but cache NOT mounted -> not ready', function () use ($repoRoot) {
    $tmp = sys_get_temp_dir() . '/godwit-array-ready-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $varIni = $tmp . '/var.ini';
    file_put_contents($varIni, "mdState=\"STARTED\"\n");

    [$code, ] = godwit_run_array_ready($repoRoot, ['GODWIT_VARINI' => $varIni, 'GODWIT_TEST_CACHE_MOUNTED' => '0']);
    assert_true($code !== 0, 'must not be ready while the cache mount is not up yet — this is the exact boot-order bug');
});

t('array-ready.sh: cache mounted but array NOT started -> not ready', function () use ($repoRoot) {
    $tmp = sys_get_temp_dir() . '/godwit-array-ready-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $varIni = $tmp . '/var.ini';
    file_put_contents($varIni, "mdState=\"STOPPED\"\n");

    [$code, ] = godwit_run_array_ready($repoRoot, ['GODWIT_VARINI' => $varIni, 'GODWIT_TEST_CACHE_MOUNTED' => '1']);
    assert_true($code !== 0, 'must not be ready while the array is not started');
});

t('array-ready.sh: missing var.ini -> not ready', function () use ($repoRoot) {
    [$code, ] = godwit_run_array_ready($repoRoot, ['GODWIT_VARINI' => '/nonexistent/var.ini', 'GODWIT_TEST_CACHE_MOUNTED' => '1']);
    assert_true($code !== 0, 'must not be ready if var.ini cannot even be read');
});

// --- godwit_resolve_cache_mounted(): godwitd's own mount-guard override --

t('godwit_resolve_cache_mounted: override "1" forces mounted regardless of the real check', function () {
    assert_true(godwit_resolve_cache_mounted('1', '/definitely/not/a/real/mountpoint'), 'override 1 must force true');
});

t('godwit_resolve_cache_mounted: override "0" forces not-mounted regardless of the real check', function () {
    assert_true(!godwit_resolve_cache_mounted('0', '/'), 'override 0 must force false even for a real mountpoint');
});

t('godwit_resolve_cache_mounted: no override falls through to the real mountpoint check', function () {
    assert_true(!godwit_resolve_cache_mounted(null, '/definitely/not/a/real/mountpoint/' . bin2hex(random_bytes(4))), 'a nonexistent dir must not resolve as mounted');
});

// --- godwitd: refuses to start when the cache mount guard says no --------

t('godwitd: refuses to start (exit non-zero, no state dir created) when GODWIT_ASSUME_CACHE_MOUNTED=0', function () use ($repoRoot) {
    $tmp = sys_get_temp_dir() . '/godwit-mountguard-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $stateDir = $tmp . '/statedir';
    $logFile = $tmp . '/godwit.log';
    $pidFile = $tmp . '/godwit.pid';

    // GODWIT_CACHE_MOUNT_DIR points at "/", which IS a real mountpoint —
    // the override must force failure despite that, proving the "0" value
    // actually reaches the guard rather than PHP's `?:` silently
    // collapsing the string "0" to falsy and falling through to the real
    // (in this case true) check. This is the exact bug this override
    // exists to make verifiable: the plugin's other safety guard
    // (array-ready.sh) papers over most premature-start paths, so this is
    // the only place the mount refusal itself gets exercised at all.
    $cmd = sprintf(
        'GODWIT_PIDFILE=%s GODWIT_LOG=%s GODWIT_RUNDIR=%s GODWIT_STATEDIR=%s GODWIT_CFGDIR=%s GODWIT_CACHE_MOUNT_DIR=/ GODWIT_ASSUME_CACHE_MOUNTED=0 timeout 5 php %s 2>&1',
        escapeshellarg($pidFile),
        escapeshellarg($logFile),
        escapeshellarg($tmp . '/rundir'),
        escapeshellarg($stateDir),
        escapeshellarg($tmp . '/cfgdir'),
        escapeshellarg($repoRoot . '/plugin/scripts/godwitd')
    );
    exec($cmd, $out, $exitCode);

    assert_eq(1, $exitCode, 'godwitd must exit 1 when the cache mount guard fails: ' . implode("\n", $out));
    assert_true(!is_dir($stateDir), 'state dir must never be created when the cache is not mounted');
    $log = file_exists($logFile) ? file_get_contents($logFile) : '';
    assert_true(str_contains($log, 'not a mountpoint'), 'expected a clear log line explaining the refusal, got: ' . $log);
});

t('godwitd: GODWIT_ASSUME_CACHE_MOUNTED=1 forces past the guard even for a nonexistent dir', function () use ($repoRoot) {
    $tmp = sys_get_temp_dir() . '/godwit-mountguard-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $stateDir = $tmp . '/statedir';

    $cmd = sprintf(
        'GODWIT_PIDFILE=%s GODWIT_LOG=%s GODWIT_RUNDIR=%s GODWIT_STATEDIR=%s GODWIT_CFGDIR=%s GODWIT_CACHE_MOUNT_DIR=%s GODWIT_ASSUME_CACHE_MOUNTED=1 GODWIT_RCLONE=/bin/false timeout 3 php %s 2>&1',
        escapeshellarg($tmp . '/godwit.pid'),
        escapeshellarg($tmp . '/godwit.log'),
        escapeshellarg($tmp . '/rundir'),
        escapeshellarg($stateDir),
        escapeshellarg($tmp . '/cfgdir'),
        escapeshellarg('/nonexistent/' . bin2hex(random_bytes(4))),
        escapeshellarg($repoRoot . '/plugin/scripts/godwitd')
    );
    exec($cmd, $out, $exitCode);

    // Exits non-zero eventually here only because GODWIT_RCLONE=/bin/false
    // can never actually bind a listener (timeout kills the retry loop) —
    // what this test is actually proving is that it got PAST the mount
    // guard and created the state dir, not the final exit code.
    assert_true(is_dir($stateDir), 'override "1" should force past the guard and create the state dir even for a nonexistent cache dir');
});

t('rc.godwit: pidfile is only removed after confirming the process is actually gone', function () use ($repoRoot) {
    // A SIGKILL that fails to reap the pid (or a still-running rcd) must
    // leave the pidfile in place — otherwise is_running() reports "not
    // running" for a process that is, and `restart` (stop; start,
    // unconditional) would spawn a second godwitd/rcd right alongside the
    // orphan. Can't force a real SIGKILL to fail from a test, so this
    // checks the source ordering directly: rm -f "$PIDFILE" must appear
    // after both liveness checks, not before them.
    $src = file_get_contents($repoRoot . '/plugin/scripts/rc.godwit');
    $stopFnPos = strpos($src, 'stop() {');
    $postEscalationCheckPos = strpos($src, 'kill -0 "$pid" 2>/dev/null; then', strpos($src, 'escalating to SIGKILL', $stopFnPos));
    $rcdCheckPos = strpos($src, 'still running', $postEscalationCheckPos);
    $rmPos = strpos($src, 'rm -f "$PIDFILE"', $rcdCheckPos);
    assert_true($postEscalationCheckPos !== false && $rcdCheckPos !== false && $rmPos !== false, 'could not locate the relevant lines in rc.godwit');
    assert_true($rmPos > $postEscalationCheckPos, 'rm -f "$PIDFILE" must come after both post-escalation liveness checks, not before them');
});

// --- rc.godwit start/stop/status against temp paths -----------------------

function godwit_rc_test_layout(string $repoRoot): string
{
    $tmp = sys_get_temp_dir() . '/godwit-rc-test-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/scripts', 0755, true);
    mkdir($tmp . '/bin', 0755, true);
    copy($repoRoot . '/plugin/scripts/rc.godwit', $tmp . '/scripts/rc.godwit');
    chmod($tmp . '/scripts/rc.godwit', 0755);
    return $tmp;
}

t('rc.godwit: start/status/stop lifecycle against a stub daemon', function () use ($repoRoot) {
    $tmp = godwit_rc_test_layout($repoRoot);

    // Stub daemon self-reports its own pid via $$, exactly like the real
    // godwitd does with getmypid() — pgrep-ing for the pid from the test
    // instead is a self-matching trap (PHP's exec() runs the pgrep command
    // via `sh -c`, whose own argv contains the search pattern too).
    file_put_contents($tmp . '/scripts/godwitd', "#!/bin/bash\necho \$\$ > \"\$GODWIT_PIDFILE\"\ntrap 'exit 0' TERM\nwhile true; do sleep 1; done\n");
    chmod($tmp . '/scripts/godwitd', 0755);

    $pidFile = $tmp . '/godwit.pid';
    $logFile = $tmp . '/godwit.log';
    $env = "GODWIT_PIDFILE=" . escapeshellarg($pidFile) . " GODWIT_LOG=" . escapeshellarg($logFile);

    exec("$env bash {$tmp}/scripts/rc.godwit start 2>&1", $out, $code);
    assert_eq(0, $code, 'start should succeed: ' . implode("\n", $out));
    usleep(200000);

    exec("$env bash {$tmp}/scripts/rc.godwit status 2>&1", $statusOut, $statusCode);
    assert_eq(0, $statusCode, 'status should report running: ' . implode("\n", $statusOut));

    exec("$env bash {$tmp}/scripts/rc.godwit stop 2>&1", $stopOut, $stopCode);
    assert_eq(0, $stopCode, 'stop should succeed: ' . implode("\n", $stopOut));

    exec("$env bash {$tmp}/scripts/rc.godwit status 2>&1", $statusOut2, $statusCode2);
    assert_eq(1, $statusCode2, 'status should report stopped after stop: ' . implode("\n", $statusOut2));
});

t('rc.godwit: escalates to SIGKILL (daemon + bundled rcd) when the daemon ignores SIGTERM', function () use ($repoRoot) {
    $tmp = godwit_rc_test_layout($repoRoot);

    // Stub daemon that ignores TERM entirely, and spawns a stub "rcd" child
    // from the bundled binary path — mimicking a godwitd that is stuck and
    // never runs its own shutdown handler. Both self-report their pid to a
    // file ($$ / $!) so the test can verify liveness with `kill -0 <pid>`
    // afterward — pgrep-ing for them externally is a self-matching trap
    // (PHP's exec() runs the pgrep command via `sh -c`, whose own argv
    // contains the search pattern too).
    $rcdPidFile = $tmp . '/rcd.pid';
    file_put_contents($tmp . '/bin/rclone', "#!/bin/bash\ntrap '' TERM\nwhile true; do sleep 1; done\n");
    chmod($tmp . '/bin/rclone', 0755);
    file_put_contents(
        $tmp . '/scripts/godwitd',
        "#!/bin/bash\necho \$\$ > \"\$GODWIT_PIDFILE\"\ntrap '' TERM\n"
            . escapeshellarg($tmp . '/bin/rclone') . " rcd &\necho \$! > " . escapeshellarg($rcdPidFile) . "\nwhile true; do sleep 1; done\n"
    );
    chmod($tmp . '/scripts/godwitd', 0755);

    $pidFile = $tmp . '/godwit.pid';
    $logFile = $tmp . '/godwit.log';
    // Small wait count so this test doesn't take the production 10s.
    $env = "GODWIT_PIDFILE=" . escapeshellarg($pidFile) . " GODWIT_LOG=" . escapeshellarg($logFile) . " GODWIT_STOP_WAIT_ITERATIONS=2";

    exec("$env bash {$tmp}/scripts/rc.godwit start 2>&1", $out, $code);
    assert_eq(0, $code, 'start should succeed: ' . implode("\n", $out));

    // Give the stub daemon and its rcd child a moment to write their pid files.
    usleep(300000);
    $daemonPid = (int) trim((string) file_get_contents($pidFile));
    $rcdPid = (int) trim((string) file_get_contents($rcdPidFile));
    assert_true($daemonPid > 0, 'stub godwitd did not record its own pid');
    assert_true($rcdPid > 0, 'stub rcd child did not record its own pid');

    exec("$env bash {$tmp}/scripts/rc.godwit stop 2>&1", $stopOut, $stopCode);
    assert_eq(0, $stopCode, 'stop should still report success once escalation kills everything: ' . implode("\n", $stopOut));
    assert_true(str_contains(implode("\n", $stopOut), 'escalating to SIGKILL'), 'expected an escalation log line');

    exec("kill -0 $daemonPid 2>/dev/null", $o1, $daemonAlive);
    assert_true($daemonAlive !== 0, 'stub godwitd must be gone after escalation');
    exec("kill -0 $rcdPid 2>/dev/null", $o2, $rcdAlive);
    assert_true($rcdAlive !== 0, 'stub rcd child must be gone after escalation');
});

// --- report ---------------------------------------------------------------

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
