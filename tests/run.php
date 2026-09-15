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

// --- rc.godwit start/stop/status against temp paths -----------------------

t('rc.godwit: start/status/stop lifecycle against a stub daemon', function () use ($repoRoot) {
    $tmp = sys_get_temp_dir() . '/godwit-rc-test-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    copy($repoRoot . '/plugin/scripts/rc.godwit', $tmp . '/rc.godwit');
    chmod($tmp . '/rc.godwit', 0755);

    // Stub daemon: writes nothing itself (rc.godwit writes the pidfile via
    // the daemon in production, but here the daemon just needs to run until
    // SIGTERM so start/status/stop can be exercised without a real rcd).
    file_put_contents($tmp . '/godwitd', "#!/bin/bash\ntrap 'exit 0' TERM\nwhile true; do sleep 1; done\n");
    chmod($tmp . '/godwitd', 0755);

    $pidFile = $tmp . '/godwit.pid';
    $logFile = $tmp . '/godwit.log';
    $env = "GODWIT_PIDFILE=" . escapeshellarg($pidFile) . " GODWIT_LOG=" . escapeshellarg($logFile);

    // rc.godwit doesn't write the pidfile itself in this stub (godwitd
    // normally does) — write it the same way the real daemon would, right
    // after start, so status/stop have something to act on.
    exec("$env bash {$tmp}/rc.godwit start 2>&1", $out, $code);
    assert_eq(0, $code, 'start should succeed: ' . implode("\n", $out));

    // Find the backgrounded godwitd pid and write it, mimicking what the
    // real daemon does on its own first line.
    exec("pgrep -f " . escapeshellarg($tmp . '/godwitd'), $pgrepOut);
    assert_true(count($pgrepOut) > 0, 'stub godwitd did not start');
    file_put_contents($pidFile, $pgrepOut[0] . "\n");

    exec("$env bash {$tmp}/rc.godwit status 2>&1", $statusOut, $statusCode);
    assert_eq(0, $statusCode, 'status should report running: ' . implode("\n", $statusOut));

    exec("$env bash {$tmp}/rc.godwit stop 2>&1", $stopOut, $stopCode);
    assert_eq(0, $stopCode, 'stop should succeed: ' . implode("\n", $stopOut));

    exec("$env bash {$tmp}/rc.godwit status 2>&1", $statusOut2, $statusCode2);
    assert_eq(1, $statusCode2, 'status should report stopped after stop: ' . implode("\n", $statusOut2));
});

// --- report ---------------------------------------------------------------

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
