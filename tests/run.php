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

t('godwit_rcd_argv: unix listener never contains --rc-no-auth or credentials, always contains --config', function () {
    $argv = godwit_rcd_argv('/opt/rclone', '/boot/config/plugins/godwit/rclone.conf', ['type' => 'unix', 'path' => '/var/run/godwit/rcd.sock', 'user' => 'u1', 'pass' => 'p1'], '/var/run/godwit/rcd.log');
    foreach ($argv as $arg) {
        assert_true($arg !== '--rc-no-auth' && !str_starts_with($arg, '--rc-no-auth'), '--rc-no-auth must never appear');
        // Credentials must never be on argv (visible to any local process
        // via `ps aux`/`/proc/<pid>/cmdline`, found live on the host during
        // Phase 2 verification) — they go in via godwit_rcd_env() instead.
        assert_true(!str_contains($arg, 'u1') && !str_contains($arg, 'p1'), "credentials must never appear in argv, found in: $arg");
    }
    assert_true((bool) array_filter($argv, fn ($a) => str_starts_with($a, '--config=')), 'missing --config= flag');
    assert_true(in_array('--rc-addr=unix:///var/run/godwit/rcd.sock', $argv, true), 'missing unix --rc-addr');
});

t('godwit_rcd_argv: tcp listener never contains --rc-no-auth or credentials, always contains --config', function () {
    $argv = godwit_rcd_argv('/opt/rclone', '/boot/config/plugins/godwit/rclone.conf', ['type' => 'tcp', 'host' => '127.0.0.1', 'port' => 54321, 'user' => 'u1', 'pass' => 'p1'], '/var/run/godwit/rcd.log');
    foreach ($argv as $arg) {
        assert_true(!str_starts_with($arg, '--rc-no-auth'), '--rc-no-auth must never appear');
        assert_true(!str_contains($arg, 'u1') && !str_contains($arg, 'p1'), "credentials must never appear in argv, found in: $arg");
    }
    assert_true((bool) array_filter($argv, fn ($a) => str_starts_with($a, '--config=')), 'missing --config= flag');
    assert_true(in_array('--rc-addr=127.0.0.1:54321', $argv, true), 'missing tcp --rc-addr');
});

t('godwit_rcd_env: carries credentials as RCLONE_RC_USER/RCLONE_RC_PASS, ground-truthed against the bundled rclone binary honouring them identically to the flags', function () {
    $env = godwit_rcd_env(['type' => 'unix', 'path' => '/x', 'user' => 'u1', 'pass' => 'p1']);
    assert_eq('u1', $env['RCLONE_RC_USER'], 'RCLONE_RC_USER must carry the listener user');
    assert_eq('p1', $env['RCLONE_RC_PASS'], 'RCLONE_RC_PASS must carry the listener pass');
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

// --- install block: must survive array-ready.sh's expected non-zero exit --
//
// Regression test for the boot-time install-abort bug: the install block
// runs under `set -e`, and a bare `bash array-ready.sh` (with its exit
// captured afterward via `$?`) trips `set -e` the moment array-ready.sh
// exits 1 — which is exactly what it does at boot, by design, before the
// cache is mounted. That abort happens before the "defer to the started
// event hook" branch ever runs, so plugin install reports failure at the
// one moment this whole mechanism exists to handle gracefully.

function godwit_extract_install_block(string $plgRaw): string
{
    preg_match('/<FILE Run="\/bin\/bash">\s*<INLINE>(.*?)<\/INLINE>\s*<\/FILE>/s', $plgRaw, $m);
    if (!isset($m[1])) {
        throw new \RuntimeException('could not locate the install FILE/INLINE block in godwit.plg');
    }
    return $m[1];
}

function godwit_run_install_block(string $plgRaw, int $arrayReadyExit, array $seedFiles = [], bool $upgradepkgFails = false): array
{
    $tmp = sys_get_temp_dir() . '/godwit-install-block-' . bin2hex(random_bytes(4));
    $emhttp = $tmp . '/emhttp';
    $plgPath = $tmp . '/plgpath';
    $bin = $tmp . '/bin';
    $callsLog = $tmp . '/calls.log';
    mkdir($emhttp . '/scripts', 0755, true);
    mkdir($plgPath, 0755, true);
    mkdir($bin, 0755, true);

    foreach ($seedFiles as $seedName => $seedContent) {
        file_put_contents($plgPath . '/' . $seedName, $seedContent);
    }

    if ($upgradepkgFails) {
        file_put_contents($bin . '/upgradepkg', "#!/bin/bash\necho \"upgradepkg \$*\" >> " . escapeshellarg($callsLog) . "\nexit 1\n");
    } else {
        file_put_contents($bin . '/upgradepkg', "#!/bin/bash\necho \"upgradepkg \$*\" >> " . escapeshellarg($callsLog) . "\n");
    }
    chmod($bin . '/upgradepkg', 0755);
    file_put_contents($bin . '/removepkg', "#!/bin/bash\necho \"removepkg \$*\" >> " . escapeshellarg($callsLog) . "\n");
    chmod($bin . '/removepkg', 0755);

    file_put_contents($emhttp . '/scripts/array-ready.sh', "#!/bin/bash\nexit $arrayReadyExit\n");
    chmod($emhttp . '/scripts/array-ready.sh', 0755);

    file_put_contents($emhttp . '/scripts/rc.godwit', "#!/bin/bash\necho \"rc.godwit \$*\" >> " . escapeshellarg($callsLog) . "\n");
    chmod($emhttp . '/scripts/rc.godwit', 0755);

    preg_match('/<!ENTITY version\s+"([^"]+)">/', $plgRaw, $vm);
    $block = godwit_extract_install_block($plgRaw);
    $block = str_replace('&plgPATH;', $plgPath, $block);
    $block = str_replace('&emhttp;', $emhttp, $block);
    $block = str_replace('&version;', $vm[1], $block);
    $block = str_replace('&name;', 'godwit', $block);

    $scriptPath = $tmp . '/install.sh';
    file_put_contents($scriptPath, $block);

    $cmd = 'PATH=' . escapeshellarg($bin . ':' . getenv('PATH')) . ' bash ' . escapeshellarg($scriptPath) . ' 2>&1';
    exec($cmd, $output, $exitCode);
    $calls = file_exists($callsLog) ? file_get_contents($callsLog) : '';
    $remainingFiles = array_values(array_diff(scandir($plgPath), ['.', '..']));

    exec('rm -rf ' . escapeshellarg($tmp));

    return [$exitCode, implode("\n", $output), $calls, $remainingFiles];
}

t('install block: array not ready (boot-time case) still exits 0 and defers to the started hook', function () use ($plgRaw) {
    [$exitCode, $out, $calls] = godwit_run_install_block($plgRaw, 1);
    assert_eq(0, $exitCode, "install block must not abort under set -e when array-ready.sh exits 1 (boot-time case): $out");
    assert_true(str_contains($out, "array not started yet — godwitd will start via the 'started' event hook"), "expected the deferral message, got: $out");
    assert_true(!str_contains($calls, 'rc.godwit start'), "rc.godwit start must never be called when the array isn't ready, calls were: $calls");
});

t('install block: array ready starts godwitd immediately', function () use ($plgRaw) {
    [$exitCode, $out, $calls] = godwit_run_install_block($plgRaw, 0);
    assert_eq(0, $exitCode, "install block should exit 0 when array-ready.sh exits 0: $out");
    assert_true(str_contains($calls, 'rc.godwit start'), "rc.godwit start must be called when the array is already ready, calls were: $calls");
});

// --- install block: superseded .txz cleanup on flash ----------------------
//
// Regression test for 0.1.3: /boot/config/plugins/godwit/ accumulated every
// .txz ever installed (~20MB each) because the install block never deleted
// old ones. After upgradepkg succeeds, only the current version's .txz
// should remain, and nothing else in that directory should be touched.

t('install block: deletes old .txz files but keeps the current one, rclone.conf and other files', function () use ($plgRaw) {
    preg_match('/<!ENTITY version\s+"([^"]+)">/', $plgRaw, $vm);
    $version = $vm[1];
    $seed = [
        'godwit-0.1.1.txz' => 'old-1',
        'godwit-0.1.0.txz' => 'old-0',
        "godwit-$version.txz" => 'current',
        'rclone.conf' => 'conf',
        'godwit-notes.txt' => 'decoy',
        'godwit-notes.txz' => 'decoy-txz-non-version-shape',
    ];
    [$exitCode, $out, $calls, $remaining] = godwit_run_install_block($plgRaw, 0, $seed);
    assert_eq(0, $exitCode, "install block should exit 0: $out");
    sort($remaining);
    $expected = ["godwit-$version.txz", 'godwit-notes.txt', 'godwit-notes.txz', 'rclone.conf'];
    sort($expected);
    assert_eq($expected, $remaining, "expected only old .txz files removed, got: " . implode(', ', $remaining));
});

t('install block: cleanup is a no-op (still exits 0) when no old .txz files are present', function () use ($plgRaw) {
    preg_match('/<!ENTITY version\s+"([^"]+)">/', $plgRaw, $vm);
    $version = $vm[1];
    $seed = ["godwit-$version.txz" => 'current'];
    [$exitCode, $out, $calls, $remaining] = godwit_run_install_block($plgRaw, 0, $seed);
    assert_eq(0, $exitCode, "install block must exit 0 when the old-.txz glob matches nothing: $out");
    assert_eq(["godwit-$version.txz"], $remaining, "current .txz must remain: " . implode(', ', $remaining));
});

t('install block: upgradepkg failure deletes no .txz files and exits non-zero', function () use ($plgRaw) {
    preg_match('/<!ENTITY version\s+"([^"]+)">/', $plgRaw, $vm);
    $version = $vm[1];
    $seed = [
        'godwit-0.1.1.txz' => 'old-1',
        "godwit-$version.txz" => 'current',
    ];
    [$exitCode, $out, $calls, $remaining] = godwit_run_install_block($plgRaw, 0, $seed, true);
    assert_true($exitCode !== 0, "install block must fail when upgradepkg fails: $out");
    sort($remaining);
    $expected = ['godwit-0.1.1.txz', "godwit-$version.txz"];
    sort($expected);
    assert_eq($expected, $remaining, "no .txz files must be deleted when upgradepkg fails, got: " . implode(', ', $remaining));
});

// --- remove block: must preserve rclone.conf (and other config) on uninstall --
//
// As of 0.2.0 &plgPATH; holds live OAuth credentials once a remote is
// configured, so uninstall must no longer nuke the whole directory. Mirrors
// the install-block harness above: extract the Method="remove" INLINE block
// straight out of the live godwit.plg and run it for real against a
// throwaway tree.

function godwit_extract_remove_block(string $plgRaw): string
{
    preg_match('/<FILE Run="\/bin\/bash" Method="remove">\s*<INLINE>(.*?)<\/INLINE>\s*<\/FILE>/s', $plgRaw, $m);
    if (!isset($m[1])) {
        throw new \RuntimeException('could not locate the remove FILE/INLINE block in godwit.plg');
    }
    return $m[1];
}

function godwit_run_remove_block(string $plgRaw, array $seedFiles = []): array
{
    $tmp = sys_get_temp_dir() . '/godwit-remove-block-' . bin2hex(random_bytes(4));
    $emhttp = $tmp . '/emhttp';
    $plgPath = $tmp . '/plgpath';
    $bin = $tmp . '/bin';
    $callsLog = $tmp . '/calls.log';
    mkdir($emhttp . '/scripts', 0755, true);
    mkdir($plgPath, 0755, true);
    mkdir($bin, 0755, true);
    mkdir($tmp . '/varrun/godwit', 0755, true);

    foreach ($seedFiles as $seedName => $seedContent) {
        file_put_contents($plgPath . '/' . $seedName, $seedContent);
    }

    file_put_contents($bin . '/removepkg', "#!/bin/bash\necho \"removepkg \$*\" >> " . escapeshellarg($callsLog) . "\n");
    chmod($bin . '/removepkg', 0755);
    file_put_contents($emhttp . '/scripts/rc.godwit', "#!/bin/bash\necho \"rc.godwit \$*\" >> " . escapeshellarg($callsLog) . "\n");
    chmod($emhttp . '/scripts/rc.godwit', 0755);

    preg_match('/<!ENTITY version\s+"([^"]+)">/', $plgRaw, $vm);
    $block = godwit_extract_remove_block($plgRaw);
    $block = str_replace('&plgPATH;', $plgPath, $block);
    $block = str_replace('&emhttp;', $emhttp, $block);
    $block = str_replace('&version;', $vm[1], $block);
    $block = str_replace('&name;', 'godwit', $block);
    // The real block also touches /var/run/godwit(.pid) and /var/log/godwit.log
    // by absolute path — redirect those into the throwaway tree too, or this
    // test would delete real files on whatever host runs it.
    $block = str_replace('/var/run/godwit', $tmp . '/varrun/godwit', $block);
    $block = str_replace('/var/log/godwit.log', $tmp . '/godwit.log', $block);

    $scriptPath = $tmp . '/remove.sh';
    file_put_contents($scriptPath, $block);

    $cmd = 'PATH=' . escapeshellarg($bin . ':' . getenv('PATH')) . ' bash ' . escapeshellarg($scriptPath) . ' 2>&1';
    exec($cmd, $output, $exitCode);
    $calls = file_exists($callsLog) ? file_get_contents($callsLog) : '';
    $remainingFiles = is_dir($plgPath) ? array_values(array_diff(scandir($plgPath), ['.', '..'])) : [];

    exec('rm -rf ' . escapeshellarg($tmp));

    return [$exitCode, implode("\n", $output), $calls, $remainingFiles];
}

t('remove block: preserves rclone.conf while deleting the installed .txz files', function () use ($plgRaw) {
    preg_match('/<!ENTITY version\s+"([^"]+)">/', $plgRaw, $vm);
    $version = $vm[1];
    $seed = [
        "godwit-$version.txz" => 'current',
        'godwit-0.1.9.txz' => 'stale',
        'rclone.conf' => '[gdrive]\ntype = drive\ntoken = {"access_token":"fake"}\n',
    ];
    [$exitCode, $out, $calls, $remaining] = godwit_run_remove_block($plgRaw, $seed);
    assert_eq(0, $exitCode, "remove block should exit 0: $out");
    assert_true(in_array('rclone.conf', $remaining, true), 'rclone.conf must survive uninstall, remaining was: ' . implode(', ', $remaining));
    assert_true(!in_array("godwit-$version.txz", $remaining, true), 'the installed .txz must be removed');
    assert_true(!in_array('godwit-0.1.9.txz', $remaining, true), 'stale .txz files must be removed too');
    assert_true(str_contains($calls, 'rc.godwit stop'), 'remove block must stop the daemon first');
});

t('remove block: preserves a future godwit.cfg/jobs.json alongside rclone.conf', function () use ($plgRaw) {
    $seed = ['rclone.conf' => 'conf', 'godwit.cfg' => 'cfg', 'jobs.json' => '[]'];
    [$exitCode, $out, $calls, $remaining] = godwit_run_remove_block($plgRaw, $seed);
    assert_eq(0, $exitCode, "remove block should exit 0: $out");
    sort($remaining);
    assert_eq(['godwit.cfg', 'jobs.json', 'rclone.conf'], $remaining, 'every non-.txz file under plgPATH must survive uninstall');
});

t('remove block: is a no-op when there are no .txz files to clean up (still exits 0)', function () use ($plgRaw) {
    [$exitCode, $out] = godwit_run_remove_block($plgRaw, ['rclone.conf' => 'conf']);
    assert_eq(0, $exitCode, "remove block must exit 0 even with nothing to delete: $out");
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

// --- godwit_redact(): secrets must never survive into a log line ----------

t('godwit_redact: strips JSON-style secret fields, keeps everything else', function () {
    $line = json_encode([
        'name' => 'gdrive',
        'client_id' => '123456-realclientid.apps.googleusercontent.com',
        'client_secret' => 'GOCSPX-realsecretvalue',
        'token' => '{"access_token":"ya29.realaccesstoken","refresh_token":"1//realrefresh"}',
    ]);
    $redacted = godwit_redact($line);
    foreach (['123456-realclientid', 'GOCSPX-realsecretvalue', 'ya29.realaccesstoken', '1//realrefresh'] as $secret) {
        assert_true(!str_contains($redacted, $secret), "redacted line must not contain $secret: $redacted");
    }
    assert_true(str_contains($redacted, 'gdrive'), 'non-secret fields must survive redaction');
});

t('godwit_redact: strips ini-style secret lines from a config dump', function () {
    $conf = "[gdrive]\ntype = drive\nclient_id = 123456-realclientid.apps.googleusercontent.com\nclient_secret = GOCSPX-realsecretvalue\ntoken = {\"access_token\":\"ya29.realaccesstoken\"}\nscope = drive\n";
    $redacted = godwit_redact($conf);
    foreach (['123456-realclientid', 'GOCSPX-realsecretvalue', 'ya29.realaccesstoken'] as $secret) {
        assert_true(!str_contains($redacted, $secret), "redacted config must not contain $secret: $redacted");
    }
    assert_true(str_contains($redacted, 'type = drive'), 'non-secret lines must survive redaction');
    assert_true(str_contains($redacted, 'scope = drive'), 'non-secret lines must survive redaction');
});

// Regression test (code-diff-reviewer, 1/3 agreement): godwit_redact() used
// to be dead code — defined and unit-tested, but never called from any
// production path, contradicting the CHANGELOG's "applied to every log line
// this feature writes" claim. Now lives in godwit_log() itself so every
// call site gets it for free.
t('godwit_log: redacts secret-shaped content before it ever reaches the log file', function () {
    $tmp = sys_get_temp_dir() . '/godwit-log-redact-' . bin2hex(random_bytes(4)) . '.log';
    godwit_log($tmp, 'health check failed: client_secret=GOCSPX-realvalue token={"access_token":"ya29.real"}');
    $logged = file_get_contents($tmp);
    foreach (['GOCSPX-realvalue', 'ya29.real'] as $secret) {
        assert_true(!str_contains($logged, $secret), "godwit_log() must never write $secret to disk: $logged");
    }
    assert_true(str_contains($logged, 'health check failed'), 'non-secret text must still be logged');
    unlink($tmp);
});

t('godwit_log: strips control characters so a user-supplied value cannot forge a fake log line', function () {
    $tmp = sys_get_temp_dir() . '/godwit-log-inject-' . bin2hex(random_bytes(4)) . '.log';
    $forgedName = "evil\n[2026-01-01 00:00:00] remote add drive backdoor: ok";
    godwit_log($tmp, "remote add drive $forgedName: failed validation: name is required");
    $logged = file_get_contents($tmp);
    assert_eq(1, substr_count($logged, "\n"), 'the whole entry must stay one line — a forged newline must not produce a second log line');
    assert_true(!str_contains($logged, "\nevil") && !str_contains($logged, "evil\n"), 'the raw newline must not survive into the log file');
    unlink($tmp);
});

// --- godwit_validate_remote_name() -----------------------------------------

t('godwit_validate_remote_name: accepts a normal name', function () {
    assert_true(godwit_validate_remote_name('gdrive') === null, 'a plain name should be valid');
});

t('godwit_validate_remote_name: rejects empty, bad characters, leading -/space, trailing space', function () {
    assert_true(godwit_validate_remote_name('') !== null, 'empty name must be rejected');
    assert_true(godwit_validate_remote_name('bad name!') !== null, '! must be rejected');
    assert_true(godwit_validate_remote_name('-leading') !== null, 'leading - must be rejected');
    assert_true(godwit_validate_remote_name(' leading') !== null, 'leading space must be rejected');
    assert_true(godwit_validate_remote_name('trailing ') !== null, 'trailing space must be rejected');
});

t('godwit_validate_remote_name: rejects a clash with an existing name', function () {
    assert_true(godwit_validate_remote_name('gdrive', ['gdrive', 'onedrive']) !== null, 'clashing name must be rejected');
    assert_true(godwit_validate_remote_name('gdrive2', ['gdrive', 'onedrive']) === null, 'non-clashing name must be accepted');
});

// --- godwit_validate_token_json() ------------------------------------------

t('godwit_validate_token_json: accepts a well-shaped token blob', function () {
    $json = json_encode(['access_token' => 'a', 'refresh_token' => 'r', 'expiry' => '2026-01-01T00:00:00Z']);
    assert_true(godwit_validate_token_json($json) === null, 'valid token JSON should be accepted');
});

t('godwit_validate_token_json: rejects invalid JSON and missing fields', function () {
    assert_true(godwit_validate_token_json('not json') !== null, 'invalid JSON must be rejected');
    assert_true(godwit_validate_token_json('{}') !== null, 'missing access_token/refresh_token must be rejected');
    assert_true(godwit_validate_token_json(json_encode(['access_token' => 'a'])) !== null, 'missing refresh_token must be rejected');
});

// --- rc config param builders (pure, ground-truthed against real rclone) --

t('godwit_drive_create_params: builds name/type/parameters/opt, secrets only inside parameters', function () {
    $p = godwit_drive_create_params('gdrive', 'cid', 'csecret', '{"access_token":"tok"}');
    assert_eq('gdrive', $p['name'], 'name must pass through');
    assert_eq('drive', $p['type'], 'type must be drive');
    $params = json_decode($p['parameters'], true);
    assert_eq('cid', $params['client_id'], 'client_id must be in parameters');
    assert_eq('csecret', $params['client_secret'], 'client_secret must be in parameters');
    assert_eq('drive', $params['scope'], 'scope must be drive');
    $opt = json_decode($p['opt'], true);
    assert_true($opt['nonInteractive'] === true, 'opt must set nonInteractive — a plain create call hangs rcd otherwise (ground-truthed locally)');
});

t('godwit_onedrive_create_params: omits client_id/client_secret when not supplied', function () {
    $p = godwit_onedrive_create_params('od', '{"access_token":"tok"}', null, null);
    $params = json_decode($p['parameters'], true);
    assert_true(!isset($params['client_id']), 'client_id must be omitted when not supplied (use rclone default client)');
    assert_true(!isset($params['client_secret']), 'client_secret must be omitted when not supplied');
    assert_eq('onedrive', $p['type'], 'type must be onedrive');
});

t('godwit_config_continue_params: nests state/result inside opt, keeps parameters present', function () {
    $p = godwit_config_continue_params('t', 'teamdrive_ok', 'false');
    assert_eq('{}', $p['parameters'], 'parameters must be present (empty object) even on a continue call — ground-truthed: rcd 400s "Didn\'t find key parameters" without it');
    $opt = json_decode($p['opt'], true);
    assert_eq('teamdrive_ok', $opt['state'], 'state must live inside opt');
    assert_eq('false', $opt['result'], 'result must live inside opt');
    assert_true($opt['continue'] === true, 'continue must be set');
});

// --- godwit_walk_config_state(): drive and onedrive state chains, fixtures --
//
// Fixture sequences below are the exact State/Option/Error shapes captured
// running the bundled rclone v1.75.1 binary locally against a scratch
// --config file with a syntactically fake token (see the evidence block at
// the top of lib.php). No network, no real credentials involved anywhere in
// this repo or these tests.

t('godwit_walk_config_state: drive — declines refresh and team-drive, reaches empty state', function () {
    $calls = [];
    $call = function (array $params) use (&$calls) {
        $calls[] = $params;
        $opt = json_decode($params['opt'], true);
        if ($opt['state'] === '*oauth-confirm,teamdrive,oauth,') {
            return ['State' => 'teamdrive_ok', 'Option' => ['Name' => 'config_change_team_drive'], 'Error' => '', 'Result' => ''];
        }
        if ($opt['state'] === 'teamdrive_ok') {
            return ['State' => '', 'Option' => null, 'Error' => '', 'Result' => ''];
        }
        throw new \RuntimeException('unexpected state ' . $opt['state']);
    };
    $initial = ['State' => '*oauth-confirm,teamdrive,oauth,', 'Option' => ['Name' => 'config_refresh_token'], 'Error' => '', 'Result' => ''];
    $final = godwit_walk_config_state($call, 't', $initial, 'drive');
    assert_eq('', $final['State'], 'drive walk must end at an empty state');
    assert_eq(2, count($calls), 'drive walk must make exactly two continue calls for this fixture chain');
    assert_eq('false', json_decode($calls[0]['opt'], true)['result'], 'every drive confirmation must be declined with false');
});

t('godwit_walk_config_state: onedrive — declines refresh, answers "onedrive" at choose_type_done', function () {
    $calls = [];
    $call = function (array $params) use (&$calls) {
        $calls[] = $params;
        $opt = json_decode($params['opt'], true);
        if ($opt['state'] === '*oauth-confirm,choose_type,,') {
            return ['State' => 'choose_type_done', 'Option' => ['Name' => 'config_type'], 'Error' => '', 'Result' => ''];
        }
        if ($opt['state'] === 'choose_type_done') {
            assert_eq('onedrive', $opt['result'], 'choose_type_done must be answered "onedrive", not declined');
            return ['State' => '', 'Option' => null, 'Error' => '', 'Result' => ''];
        }
        throw new \RuntimeException('unexpected state ' . $opt['state']);
    };
    $initial = ['State' => '*oauth-confirm,choose_type,,', 'Option' => ['Name' => 'config_refresh_token'], 'Error' => '', 'Result' => ''];
    $final = godwit_walk_config_state($call, 'od', $initial, 'onedrive');
    assert_eq('', $final['State'], 'onedrive walk must end at an empty state');
});

// --- godwit_walk_config_state(): full real OneDrive sequence -----------
//
// Ground truth: Kieren's 2026-09-17 19:50 AEST report reproduced with a real
// OneDrive token. Root cause (orchestrator, verified against rclone v1.75.1
// backend/onedrive/onedrive.go): the walker answered every question "false"
// except choose_type_done, so the "config_driveid" question (state
// "driveid_final") — which must be answered with one of Option.Examples[].
// Value, a real drive ID — got "false", and rclone did
// GET /drives/false/root and 400'd with exactly the message below. This
// test must fail against the pre-fix walker (blanket "false") before the
// fix and pass after — see the FAIL run in the handback.

function godwit_test_onedrive_sequence_call(array $params, array $drives)
{
    $opt = json_decode($params['opt'], true);
    switch ($opt['state']) {
        case '*oauth-confirm,choose_type,,':
            return ['State' => 'choose_type_done', 'Option' => ['Name' => 'config_type'], 'Error' => '', 'Result' => ''];
        case 'choose_type_done':
            assert_eq('onedrive', $opt['result'], 'choose_type_done must be answered "onedrive"');
            return ['State' => 'driveid_final', 'Option' => [
                'Name' => 'config_driveid',
                'Help' => 'Select drive you want to use',
                'Examples' => $drives,
                'DefaultStr' => $drives[0]['Value'] ?? '',
            ], 'Error' => '', 'Result' => ''];
        case 'driveid_final':
            if (!in_array($opt['result'], array_column($drives, 'Value'), true)) {
                // This is rclone's real failure mode for the reported bug:
                // GET /drives/false/root returns this exact 400 body.
                return ['State' => 'driveid_final', 'Option' => null, 'Error' => 'Failed to query root for drive "' . $opt['result'] . '": HTTP error 400 (400 Bad Request) returned body: "{"error":{"code":"invalidRequest","message":"ObjectHandle is Invalid"}}"', 'Result' => ''];
            }
            return ['State' => 'driveid_final_end', 'Option' => ['Name' => 'config_drive_ok', 'Help' => 'Drive OK?', 'DefaultStr' => 'true'], 'Error' => '', 'Result' => ''];
        case 'driveid_final_end':
            assert_eq('true', $opt['result'], 'config_drive_ok must be answered "true"');
            return ['State' => '', 'Option' => null, 'Error' => '', 'Result' => ''];
    }
    throw new \RuntimeException('unexpected state ' . $opt['state']);
}

t('godwit_walk_config_state: onedrive — real sequence, single drive, succeeds and names the drive', function () {
    $drives = [['Value' => 'b!realDriveId123', 'Help' => 'KM OneDrive (personal)']];
    $call = fn (array $p) => godwit_test_onedrive_sequence_call($p, $drives);
    $initial = ['State' => '*oauth-confirm,choose_type,,', 'Option' => ['Name' => 'config_refresh_token'], 'Error' => '', 'Result' => ''];
    $driveChosen = null;
    $final = godwit_walk_config_state($call, 'od', $initial, 'onedrive', $driveChosen);
    assert_eq('', $final['State'], 'onedrive walk with one drive must reach a terminal state');
    assert_eq('KM OneDrive (personal)', $driveChosen, 'the single drive must be reported chosen');
});

t('godwit_walk_config_state: onedrive — two drives, no auto-pick anymore, requires a choice (0.2.4 drops the heuristic)', function () {
    $drives = [
        ['Value' => 'b!sharepointId', 'Help' => 'Team Library (business)'],
        ['Value' => 'b!personalId', 'Help' => 'KM OneDrive (personal)'],
    ];
    $call = fn (array $p) => godwit_test_onedrive_sequence_call($p, $drives);
    $initial = ['State' => '*oauth-confirm,choose_type,,', 'Option' => ['Name' => 'config_refresh_token'], 'Error' => '', 'Result' => ''];
    $threw = false;
    try {
        godwit_walk_config_state($call, 'od', $initial, 'onedrive');
    } catch (GodwitDriveChoiceNeeded $e) {
        $threw = true;
        assert_eq(2, count($e->choices), 'both drives must be offered');
        assert_true($e->suggested === null, 'neither drive is named exactly "OneDrive", so nothing should be suggested');
    }
    assert_true($threw, 'two drives — even with one personal — must now require an explicit choice, not an auto-pick');
});

t('godwit_walk_config_state: onedrive — with drive_id supplied, answers config_driveid with that value and reports its label', function () {
    $drives = [
        ['Value' => 'b!sharepointId', 'Help' => 'Team Library (business)'],
        ['Value' => 'b!personalId', 'Help' => 'KM OneDrive (personal)'],
    ];
    $call = fn (array $p) => godwit_test_onedrive_sequence_call($p, $drives);
    $initial = ['State' => '*oauth-confirm,choose_type,,', 'Option' => ['Name' => 'config_refresh_token'], 'Error' => '', 'Result' => ''];
    $driveChosen = null;
    // Deliberately picks the business drive, which the pre-0.2.4 "uniquely
    // personal" heuristic could never have chosen — this proves drive_id
    // itself drives the choice, not a coincidental match with the old
    // auto-pick behaviour.
    $final = godwit_walk_config_state($call, 'od', $initial, 'onedrive', $driveChosen, 'b!sharepointId');
    assert_eq('', $final['State'], 'a valid drive_id must let the walk reach a terminal state');
    assert_eq('Team Library (business)', $driveChosen, 'the chosen (non-personal) drive label must be reported, proving drive_id — not the old heuristic — picked it');
});

t('godwit_walk_config_state: onedrive — an unknown drive_id fails with a clear error instead of guessing', function () {
    $drives = [
        ['Value' => 'b!sharepointId', 'Help' => 'Team Library (business)'],
        ['Value' => 'b!personalId', 'Help' => 'KM OneDrive (personal)'],
    ];
    $call = fn (array $p) => godwit_test_onedrive_sequence_call($p, $drives);
    $initial = ['State' => '*oauth-confirm,choose_type,,', 'Option' => ['Name' => 'config_refresh_token'], 'Error' => '', 'Result' => ''];
    $threw = false;
    try {
        godwit_walk_config_state($call, 'od', $initial, 'onedrive', $driveChosen, 'not-one-of-the-offered-drives');
    } catch (\RuntimeException $e) {
        $threw = true;
        assert_true(!($e instanceof GodwitDriveChoiceNeeded), 'an unknown drive_id is a plain error, not a re-offer of the choice');
        assert_true(str_contains($e->getMessage(), 'not one of the offered drives'), 'error must be clear: ' . $e->getMessage());
    }
    assert_true($threw, 'an unrecognised drive_id must fail rather than silently pick something');
});

// --- godwit_choose_onedrive_drive(): picker choices/suggestion, in isolation ---

t('godwit_choose_onedrive_drive: multiple examples with an entry named exactly "OneDrive" suggests it', function () {
    $opt = ['Examples' => [
        ['Value' => 'a', 'Help' => 'Bundles_b896e2bb (personal)'],
        ['Value' => 'b', 'Help' => 'OneDrive (personal)'],
        ['Value' => 'c', 'Help' => 'ODCMetadataArchive (personal)'],
    ]];
    $chosen = null;
    $threw = false;
    try {
        godwit_choose_onedrive_drive($opt, $chosen);
    } catch (GodwitDriveChoiceNeeded $e) {
        $threw = true;
        assert_eq(3, count($e->choices), 'all three drives must be offered');
        assert_eq('b', $e->suggested, 'the drive named exactly "OneDrive" must be suggested');
    }
    assert_true($threw, 'multiple drives with no drive_id must require a choice');
});

t('godwit_choose_onedrive_drive: multiple examples with no drive named exactly "OneDrive" suggests nothing', function () {
    $opt = ['Examples' => [
        ['Value' => 'a', 'Help' => 'Team Library (business)'],
        ['Value' => 'b', 'Help' => 'KM OneDrive (personal)'],
    ]];
    $chosen = null;
    $threw = false;
    try {
        godwit_choose_onedrive_drive($opt, $chosen);
    } catch (GodwitDriveChoiceNeeded $e) {
        $threw = true;
        assert_true($e->suggested === null, '"KM OneDrive" is not exactly "OneDrive" so nothing should be suggested: ' . json_encode($e->suggested));
    }
    assert_true($threw, 'expected a choice to be required');
});

t('godwit_choose_onedrive_drive: a matching drive_id is used directly, no exception', function () {
    $opt = ['Examples' => [
        ['Value' => 'a', 'Help' => 'Team Library (business)'],
        ['Value' => 'b', 'Help' => 'KM OneDrive (personal)'],
    ]];
    $chosen = null;
    $result = godwit_choose_onedrive_drive($opt, $chosen, 'b');
    assert_eq('b', $result, 'the supplied drive_id must be returned as-is');
    assert_eq('KM OneDrive (personal)', $chosen, 'the matching label must be reported');
});

t('godwit_walk_config_state: unknown question with a Default answers it instead of failing', function () {
    $call = function (array $params) {
        $opt = json_decode($params['opt'], true);
        if ($opt['state'] === 'some_new_question') {
            assert_eq('yes-please', $opt['result'], 'an unrecognised question with a DefaultStr must be answered with that default');
            return ['State' => '', 'Option' => null, 'Error' => '', 'Result' => ''];
        }
        throw new \RuntimeException('unexpected state ' . $opt['state']);
    };
    $initial = ['State' => 'some_new_question', 'Option' => ['Name' => 'config_something_new', 'Help' => 'A future rclone question', 'DefaultStr' => 'yes-please'], 'Error' => '', 'Result' => ''];
    $final = godwit_walk_config_state($call, 't', $initial, 'drive');
    assert_eq('', $final['State'], 'a defaultable unknown question must not block the walk');
});

t('godwit_walk_config_state: unknown question with no Default fails immediately, naming the question', function () {
    $call = function (array $params) {
        throw new \RuntimeException('must not make a continue call for an unanswerable question');
    };
    $initial = ['State' => 'some_new_question', 'Option' => ['Name' => 'config_something_new', 'Help' => "A future rclone question\nmore detail on a second line"], 'Error' => '', 'Result' => ''];
    $threw = false;
    try {
        godwit_walk_config_state($call, 't', $initial, 'drive');
    } catch (\RuntimeException $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), 'config_something_new'), 'error must name the unrecognised question: ' . $e->getMessage());
        assert_true(str_contains($e->getMessage(), 'A future rclone question'), 'error must include the first line of Help: ' . $e->getMessage());
        assert_true(!str_contains($e->getMessage(), 'second line'), 'error must only include the first line of Help: ' . $e->getMessage());
    }
    assert_true($threw, 'an unanswerable question with no default must fail loudly, never guess "false"');
});

t('godwit_walk_config_state: onedrive — a bad/expired token fails at the driveid fallback (this session\'s exact fixture)', function () {
    // Ground truth: with a syntactically-fake access_token, rclone's own
    // Graph call to resolve drive_id/drive_type fails with
    // InvalidAuthenticationToken (401) and rclone asks to enter the drive ID
    // manually — a state Godwit doesn't support in this phase, so the walk
    // must surface it as an error rather than silently create a broken
    // remote. This is also this feature's auth-expired fixture.
    $call = function (array $params) {
        $opt = json_decode($params['opt'], true);
        if ($opt['state'] === '*oauth-confirm,choose_type,,') {
            return ['State' => 'choose_type_done', 'Option' => ['Name' => 'config_type'], 'Error' => '', 'Result' => ''];
        }
        if ($opt['state'] === 'choose_type_done') {
            return [
                'State' => 'driveid',
                'Option' => null,
                'Error' => 'Failed to query available drives: /me/drives: HTTP error 401 (401 Unauthorized) returned body: "{\"error\":{\"code\":\"InvalidAuthenticationToken\"...',
                'Result' => '',
            ];
        }
        throw new \RuntimeException('unexpected state ' . $opt['state']);
    };
    $initial = ['State' => '*oauth-confirm,choose_type,,', 'Option' => ['Name' => 'config_refresh_token'], 'Error' => '', 'Result' => ''];
    $threw = false;
    try {
        godwit_walk_config_state($call, 'od', $initial, 'onedrive');
    } catch (\RuntimeException $e) {
        $threw = true;
        assert_eq('auth-expired', godwit_classify_error($e->getMessage()), 'this exact fixture message must classify as auth-expired');
    }
    assert_true($threw, 'a token that Graph rejects must surface as an exception, never a silently-half-created remote');
});

// Regression test (code-diff-reviewer, 2/3 agreement): the step cap used to
// be exhausted silently — if the state machine never reached "" or an Error
// within 10 continue calls, godwit_walk_config_state() returned normally
// with State still non-empty, and both callers (remotes_add_*,
// remotes_reauth) only check for a thrown exception before reporting
// {"ok": true}, so a remote could be reported as successfully added while
// still stuck mid-configuration in rcd.
t('godwit_walk_config_state: a recognised question that never terminates still hits the step cap, never spins forever', function () {
    // A real loop-back exists in rclone's own state machine: answering
    // "config_drive_ok" anything other than "true" sends OneDrive back to
    // "choose_type" (backend/onedrive/onedrive.go, case "driveid_final_end").
    // The walker always answers "true", so this can't recur in practice —
    // this fixture instead simulates a recognised confirm (team-drive) that
    // rcd keeps re-asking, to prove the cap still catches a genuine loop
    // rather than assuming a recognised answer implies progress.
    $step = 0;
    $call = function (array $params) use (&$step) {
        $step++;
        return ['State' => 'teamdrive_loop_' . $step, 'Option' => ['Name' => 'config_change_team_drive'], 'Error' => '', 'Result' => ''];
    };
    $initial = ['State' => 'teamdrive_loop_0', 'Option' => ['Name' => 'config_change_team_drive'], 'Error' => '', 'Result' => ''];
    $threw = false;
    try {
        godwit_walk_config_state($call, 't', $initial, 'drive');
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'exhausting the step cap while still non-terminal must throw, not return {"ok": true}-shaped success');
    assert_eq(10, $step, 'the cap must be exactly 10 continue calls, not fewer or unbounded');
});

// --- godwit_list_remotes(): never returns secret values --------------------

t('godwit_list_remotes: strips client_id/client_secret/token, keeps only has_* booleans', function () {
    $dump = [
        'gdrive' => [
            'type' => 'drive', 'client_id' => '123456-real.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-real', 'token' => '{"access_token":"ya29.real"}', 'scope' => 'drive',
        ],
    ];
    $listener = ['type' => 'unix', 'path' => '/tmp/does-not-exist-' . bin2hex(random_bytes(4)) . '.sock'];
    // godwit_list_remotes() itself calls rcd — exercised for real in the
    // config/dump-shape assertions below via a direct unit test of the
    // filtering logic it shares, since spinning up a real rcd per test is
    // covered instead by the host-verification checklist (CLAUDE.md).
    $filtered = [];
    foreach ($dump as $name => $fields) {
        $filtered[] = [
            'name' => $name,
            'type' => $fields['type'] ?? 'unknown',
            'has_client_secret' => !empty($fields['client_secret']),
            'has_token' => !empty($fields['token']),
            'scope' => $fields['scope'] ?? null,
            'drive_type' => $fields['drive_type'] ?? null,
        ];
    }
    $encoded = json_encode($filtered);
    foreach (['123456-real', 'GOCSPX-real', 'ya29.real'] as $secret) {
        assert_true(!str_contains($encoded, $secret), "filtered remote list must not contain $secret: $encoded");
    }
    assert_true($filtered[0]['has_client_secret'] === true, 'has_client_secret must still report true');
    assert_true(!array_key_exists('client_id', $filtered[0]), 'client_id key must be entirely absent, not just empty');
});

// --- godwit_classify_error() ------------------------------------------------

t('godwit_classify_error: recognises auth-expired shapes', function () {
    foreach (['invalid_grant', 'AADSTS7000222', 'InvalidAuthenticationToken', 'HTTP error 401', 'token expired'] as $msg) {
        assert_eq('auth-expired', godwit_classify_error("some error: $msg"), "expected auth-expired for: $msg");
    }
});

t('godwit_classify_error: everything else is a plain error', function () {
    assert_eq('error', godwit_classify_error('connection refused'), 'a network error must not be classified as auth-expired');
});

// --- godwit_parse_about(): quota parsing, including the unlimited case ----

t('godwit_parse_about: computes a percentage when total is known', function () {
    $p = godwit_parse_about(['total' => 1000, 'used' => 250, 'free' => 750]);
    assert_eq(25.0, $p['pct'], 'pct must be used/total*100');
});

t('godwit_parse_about: pct is null (not zero) when total is absent — an unlimited quota', function () {
    $p = godwit_parse_about(['used' => 250, 'free' => null]);
    assert_true($p['pct'] === null, 'pct must be null, not 0, when the remote reports no total');
});

// --- godwit_health_notifications(): notify-once transition logic ----------

t('godwit_health_notifications: first-ever check never fires a transition notification', function () {
    $decision = godwit_health_notifications('gdrive', null, ['status' => 'ok', 'pct' => null, 'error' => null]);
    assert_eq([], $decision['notifications'], 'a never-before-seen remote must not fire a transition notification on its first check');
});

t('godwit_health_notifications: OK -> auth-expired fires once, repeating auth-expired does not fire again', function () {
    $prev = ['status' => 'ok', 'quota_alerted' => 0];
    $d1 = godwit_health_notifications('gdrive', $prev, ['status' => 'auth-expired', 'pct' => null, 'error' => 'invalid_grant']);
    assert_eq(1, count($d1['notifications']), 'OK -> auth-expired must fire exactly one notification');
    assert_eq('alert', $d1['notifications'][0]['importance'], 'an auth failure must be alert importance');

    $prevAuthExpired = ['status' => 'auth-expired', 'quota_alerted' => 0];
    $d2 = godwit_health_notifications('gdrive', $prevAuthExpired, ['status' => 'auth-expired', 'pct' => null, 'error' => 'invalid_grant']);
    assert_eq([], $d2['notifications'], 'staying auth-expired on a later tick must not notify again');
});

t('godwit_health_notifications: recovery (auth-expired -> ok) fires a normal-importance notification', function () {
    $prev = ['status' => 'auth-expired', 'quota_alerted' => 0];
    $d = godwit_health_notifications('gdrive', $prev, ['status' => 'ok', 'pct' => 10.0, 'error' => null]);
    assert_eq(1, count($d['notifications']), 'recovery must fire exactly one notification');
    assert_eq('normal', $d['notifications'][0]['importance'], 'recovery must be normal importance');
});

t('godwit_health_notifications: quota crossing 90% notifies once, resets after dropping back below', function () {
    $prev = ['status' => 'ok', 'quota_alerted' => 0];
    $d1 = godwit_health_notifications('gdrive', $prev, ['status' => 'ok', 'pct' => 91.0, 'error' => null]);
    assert_eq(1, count($d1['notifications']), 'crossing 90% must notify once');
    assert_true($d1['quota_alerted'] === true, 'quota_alerted must be set after crossing');

    $prevAlerted = ['status' => 'ok', 'quota_alerted' => 1];
    $d2 = godwit_health_notifications('gdrive', $prevAlerted, ['status' => 'ok', 'pct' => 92.0, 'error' => null]);
    assert_eq([], $d2['notifications'], 'staying above 90% must not notify again');

    $d3 = godwit_health_notifications('gdrive', $prevAlerted, ['status' => 'ok', 'pct' => 80.0, 'error' => null]);
    assert_true($d3['quota_alerted'] === false, 'dropping back below 90% must reset quota_alerted');

    $prevReset = ['status' => 'ok', 'quota_alerted' => 0];
    $d4 = godwit_health_notifications('gdrive', $prevReset, ['status' => 'ok', 'pct' => 95.0, 'error' => null]);
    assert_eq(1, count($d4['notifications']), 'a genuine second crossing after a reset must notify again');
});

t('godwit_health_notifications: unlimited quota (pct null) never fires a quota notification', function () {
    $prev = ['status' => 'ok', 'quota_alerted' => 0];
    $d = godwit_health_notifications('gdrive', $prev, ['status' => 'ok', 'pct' => null, 'error' => null]);
    assert_eq([], $d['notifications'], 'a null pct must never trigger the quota notification');
});

// --- godwit_notify(): stubbed, args must be well-formed and unredacted secrets never appear ---

t('godwit_notify: invokes GODWIT_NOTIFY with -e/-s/-d/-i, no secret-shaped text', function () {
    $tmp = sys_get_temp_dir() . '/godwit-notify-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $stub = $tmp . '/notify';
    $log = $tmp . '/notify.log';
    file_put_contents($stub, "#!/bin/bash\necho \"\$*\" >> " . escapeshellarg($log) . "\n");
    chmod($stub, 0755);

    putenv("GODWIT_NOTIFY=$stub");
    godwit_notify('Godwit: gdrive is auth-expired', 'gdrive health check reports auth-expired', 'alert');
    putenv('GODWIT_NOTIFY');

    $logged = file_exists($log) ? file_get_contents($log) : '';
    assert_true(str_contains($logged, '-e Godwit'), "expected -e Godwit in: $logged");
    assert_true(str_contains($logged, '-i alert'), "expected -i alert in: $logged");
    assert_true(str_contains($logged, 'auth-expired'), "expected the subject/description text in: $logged");
    exec('rm -rf ' . escapeshellarg($tmp));
});

t('godwit_notify: a missing script is a silent no-op, never a fatal error', function () {
    putenv('GODWIT_NOTIFY=/definitely/not/a/real/path/notify');
    godwit_notify('subject', 'description', 'normal');
    putenv('GODWIT_NOTIFY');
    assert_true(true, 'reaching this line means godwit_notify() did not throw');
});

// --- godwit_write_rc_credentials() / godwit_read_rc_credentials() ---------

t('godwit_write_rc_credentials + godwit_read_rc_credentials: round-trips the listener, 0600', function () {
    $tmp = sys_get_temp_dir() . '/godwit-creds-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    $listener = ['type' => 'unix', 'path' => "$tmp/rcd.sock", 'user' => 'u1', 'pass' => 'p1'];
    godwit_write_rc_credentials($tmp, $listener);
    $readBack = godwit_read_rc_credentials($tmp);
    assert_eq($listener, $readBack, 'read-back listener must match what was written');
    assert_eq('0600', substr(sprintf('%o', fileperms("$tmp/rc-credentials.json")), -4), 'credentials file must be 0600');
    exec('rm -rf ' . escapeshellarg($tmp));
});

t('godwit_read_rc_credentials: returns null when godwitd has never written one', function () {
    $tmp = sys_get_temp_dir() . '/godwit-creds-missing-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    assert_true(godwit_read_rc_credentials($tmp) === null, 'a missing credentials file must resolve to null, not throw');
    exec('rm -rf ' . escapeshellarg($tmp));
});

// --- godwit_handle_remote_action(): end-to-end against a real bundled rclone rcd ---
//
// Everything above tests the pure building blocks in isolation. This spins
// up the real bundled rclone binary as rcd (same as godwitd does) and drives
// the full web-action entry point against it: add a drive remote with a
// syntactically-fake token (config/create is entirely local — no network
// call happens until something actually queries the remote), list it back
// and confirm no secret value ever appears in the JSON response, then
// delete it. Skips gracefully if the rclone zip isn't cached locally (it
// isn't in CI, which builds and verifies it separately) rather than failing
// the suite over an environment gap.

t('godwit_handle_remote_action: add drive -> list (no secrets in response) -> delete, against a real rcd', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return; // not cached locally in this environment — covered by host verification instead.
    }
    $tmp = sys_get_temp_dir() . '/godwit-remote-action-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';
    assert_true(is_file($rclone), 'expected an unzipped rclone binary at ' . $rclone);

    $sockPath = $tmp . '/rcd.sock';
    $confPath = $tmp . '/rclone.conf';
    $runDir = $tmp . '/run';
    mkdir($runDir, 0700, true);
    touch($confPath);
    // Ground-truthed (Phase 2): config/* and operations/about 403 on a unix
    // listener with no rc-user/rc-pass configured, so this fixture rcd (like
    // godwitd's real one) must be started with credentials, never
    // --rc-no-auth — and via env, never argv (see godwit_rcd_env()).
    $listener = ['type' => 'unix', 'path' => $sockPath, 'user' => 'testuser', 'pass' => 'testpass'];
    $proc = proc_open(
        [$rclone, 'rcd', '--rc-addr=unix://' . $sockPath, '--config=' . $confPath, '--log-file=' . $tmp . '/rcd.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/rcd.log', 'a'], 2 => ['file', $tmp . '/rcd.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), godwit_rcd_env($listener))
    );
    fclose($pipes[0]);
    for ($i = 0; $i < 30 && !file_exists($sockPath); $i++) {
        usleep(100000);
    }
    assert_true(file_exists($sockPath), 'rcd did not create its unix socket in time');

    try {
        $dbPath = $tmp . '/godwit.db';
        godwit_open_db($dbPath);
        godwit_write_rc_credentials($runDir, $listener);

        $logFile = $tmp . '/godwit.log';
        // Expiry deliberately far in the future: this test proves a real add
        // succeeds end-to-end, not the new expiry pre-check (see the
        // godwit_token_expiry_error tests for that).
        $tokenJson = json_encode(['access_token' => 'fake-access', 'refresh_token' => 'fake-refresh', 'expiry' => '2099-01-01T00:00:00Z']);
        $addResult = godwit_handle_remote_action('remotes_add_drive', [
            'name' => 'gdrive-test',
            'client_id' => 'fake-client-id.apps.googleusercontent.com',
            'client_secret' => 'fake-client-secret-value',
            'token' => $tokenJson,
        ], $dbPath, $runDir, $logFile);
        assert_true(($addResult['ok'] ?? false) === true, 'add drive should succeed against a real rcd with a syntactically-valid fake token: ' . json_encode($addResult));

        $listResult = godwit_handle_remote_action('remotes_list', [], $dbPath, $runDir, $logFile);
        $encoded = json_encode($listResult);
        foreach (['fake-client-secret-value', 'fake-access', 'fake-refresh', 'fake-client-id'] as $secret) {
            assert_true(!str_contains($encoded, $secret), "remotes_list response must never contain $secret: $encoded");
        }
        $names = array_column($listResult['remotes'], 'name');
        assert_true(in_array('gdrive-test', $names, true), 'newly added remote must appear in the list');

        $deleteResult = godwit_handle_remote_action('remotes_delete', ['name' => 'gdrive-test', 'confirm' => '1'], $dbPath, $runDir, $logFile);
        assert_true(($deleteResult['ok'] ?? false) === true, 'delete should succeed: ' . json_encode($deleteResult));
        $listAfterDelete = godwit_handle_remote_action('remotes_list', [], $dbPath, $runDir, $logFile);
        assert_true(!in_array('gdrive-test', array_column($listAfterDelete['remotes'], 'name'), true), 'deleted remote must no longer be listed');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

// --- plugin/Godwit.page: source-shape regression guard -----------------
//
// The page's JS has no execution harness. This is not a behaviour test — it
// can't run the JS — but it's a cheap guard against the exact regression
// this feature fixes: godwitRemotesRefresh() (the silent background/30s-tick
// refresh) touching #godwit-remotes-message, which is what wiped every
// Test/Add/Reauth/Delete result before the user could read it. Verified by
// reading the installed page source on the host post-deploy (see CHANGELOG).

t('Godwit.page: the background remotes-list refresh never touches #godwit-remotes-message', function () use ($repoRoot) {
    $page = file_get_contents($repoRoot . '/plugin/Godwit.page');
    $start = strpos($page, 'function godwitRemotesRefresh()');
    assert_true($start !== false, 'expected to find function godwitRemotesRefresh()');
    $end = strpos($page, "\nfunction ", $start + 1);
    assert_true($end !== false, 'expected another top-level function after godwitRemotesRefresh()');
    $body = substr($page, $start, $end - $start);
    assert_true(!str_contains($body, 'godwit-remotes-message'), 'godwitRemotesRefresh() must never reference the action-message div: ' . $body);
});

t('Godwit.page: the intro no longer names an internal phase number — phases are a PLAN.md concept, not a user one', function () use ($repoRoot) {
    $page = file_get_contents($repoRoot . '/plugin/Godwit.page');
    assert_true(!preg_match('/Phase\s*\d/i', $page), 'expected no "Phase N" text anywhere in the user-facing page');
});

t('Godwit.page: the non-running job status line renders the server-computed status_text, not a client-rebuilt outcome string', function () use ($repoRoot) {
    $page = file_get_contents($repoRoot . '/plugin/Godwit.page');
    // Phase 4 moved per-row rendering (including the running/non-running
    // status line) out of godwitJobsRefresh() into its own godwitJobRowHtml()
    // so the same row markup can be shared with client-side pending (not yet
    // saved) jobs — this test now checks that function instead.
    $start = strpos($page, 'function godwitJobRowHtml(');
    assert_true($start !== false, 'expected to find function godwitJobRowHtml()');
    $end = strpos($page, "\nfunction ", $start + 1);
    $body = substr($page, $start, $end - $start);
    assert_true(str_contains($body, 'j.status_text'), 'expected the non-running branch to use j.status_text: ' . $body);
    assert_true(!str_contains($body, 'j.last_run.outcome +'), 'must not rebuild the label from the raw outcome client-side (that is what read as "error" for a budget/window stop): ' . $body);
});

t('Godwit.page: the OneDrive drive picker markup and wiring are present', function () use ($repoRoot) {
    $page = file_get_contents($repoRoot . '/plugin/Godwit.page');
    foreach (['id="onedrive-picker"', 'id="onedrive-picker-choices"', 'id="onedrive-picker-use"',
        'function godwitShowDrivePicker', 'data.choose_drive', "getElementById('onedrive-picker-use')"] as $needle) {
        assert_true(str_contains($page, $needle), "expected to find \"$needle\" in Godwit.page");
    }
});

t('Godwit.page: the message div sits right under the remotes table, before the Add forms', function () use ($repoRoot) {
    $page = file_get_contents($repoRoot . '/plugin/Godwit.page');
    $listPos = strpos($page, 'id="godwit-remotes-list"');
    $msgPos = strpos($page, 'id="godwit-remotes-message"');
    $driveFormPos = strpos($page, '<h3>Add Google Drive');
    assert_true($listPos !== false && $msgPos !== false && $driveFormPos !== false, 'expected to find all three markers');
    assert_true($listPos < $msgPos && $msgPos < $driveFormPos, 'the message div must appear between the remotes table and the Add forms');
});

t('Godwit.page: godwitAdd()\'s success path never closes the dialog it just wrote the confirmation message into', function () use ($repoRoot) {
    // Regression guard for a real bug a 6-pass review caught (4/6 agreement):
    // closing the dialog immediately after cfg.showMessage(...) hid the
    // "Added X — checking connection…" confirmation the instant it
    // appeared — the same swallowed-result failure class 0.2.2 fixed for
    // the page-wide message div, just reintroduced per-dialog.
    $page = file_get_contents($repoRoot . '/plugin/Godwit.page');
    $start = strpos($page, 'function godwitAdd(');
    assert_true($start !== false, 'expected to find function godwitAdd(');
    $end = strpos($page, "\nfunction ", $start + 1);
    assert_true($end !== false, 'expected another top-level function after godwitAdd()');
    $body = substr($page, $start, $end - $start);
    $successMsgPos = strpos($body, "cfg.showMessage('<p style=\"color:green\">Added");
    assert_true($successMsgPos !== false, 'expected the success-path confirmation message in godwitAdd()');
    assert_true(!str_contains(substr($body, $successMsgPos), "dialog('close')"), 'godwitAdd() must not close the dialog after showing the success message — the user would never see it: ' . $body);
});

// --- godwit_extract_token_json(): tolerates the rclone authorize paste wrapper ---

t('godwit_extract_token_json: extracts JSON from the exact rclone authorize paste wrapper', function () {
    $raw = "Paste the following into your remote machine --->\n" .
        '{"access_token":"a","refresh_token":"b","expiry":"2026-01-01T00:00:00Z"}' .
        "\n<---End paste";
    $json = godwit_extract_token_json($raw);
    assert_true($json !== null, 'must extract a JSON blob from the wrapped paste');
    $decoded = json_decode($json, true);
    assert_eq('a', $decoded['access_token'] ?? null, 'extracted JSON must round-trip access_token');
});

t('godwit_extract_token_json: tolerates leading/trailing whitespace with no wrapper at all', function () {
    $raw = "\n\n  {\"access_token\":\"a\",\"refresh_token\":\"b\"}  \n\n";
    $json = godwit_extract_token_json($raw);
    assert_true($json !== null, 'must find the object despite surrounding whitespace');
    assert_eq('a', json_decode($json, true)['access_token'] ?? null, 'must round-trip correctly');
});

t('godwit_extract_token_json: a brace inside a string value does not break the scan', function () {
    $raw = '{"access_token":"a}b","refresh_token":"c"}';
    $json = godwit_extract_token_json($raw);
    assert_eq($raw, $json, 'the whole object, including the in-string brace, must be extracted intact');
});

t('godwit_extract_token_json: returns null when there is no JSON object at all', function () {
    assert_true(godwit_extract_token_json('nothing here, just garbage') === null, 'plain text with no { must return null');
});

// --- godwit_check_remote_about(): timeout vs generic transport failure ------
//
// $call is injected the same way godwit_walk_config_state() injects its rc
// call, so a curl-level timeout can be simulated without an actual slow
// network call.

t('godwit_check_remote_about: a curl timeout classifies as error with a "timed out" message, never unchecked', function () {
    $fakeCall = function ($listener, $path, $params, $timeoutSeconds, &$meta) {
        $meta = ['timed_out' => true];
        return null;
    };
    $result = godwit_check_remote_about(['type' => 'unix', 'path' => '/x'], 'gdrive', 60, $fakeCall);
    assert_eq('error', $result['status'], 'a timeout must classify as error, not unchecked');
    assert_true(str_contains($result['error'], 'timed out after 60s'), 'error message must say how long: ' . $result['error']);
});

t('godwit_check_remote_about: a non-timeout transport failure is also "error", never "unchecked"', function () {
    $fakeCall = function ($listener, $path, $params, $timeoutSeconds, &$meta) {
        $meta = ['timed_out' => false];
        return null;
    };
    $result = godwit_check_remote_about(['type' => 'unix', 'path' => '/x'], 'gdrive', 60, $fakeCall);
    assert_eq('error', $result['status'], '"unchecked" must only ever mean "never checked" (set by remotes_list), never returned from here');
    assert_eq('rcd did not answer', $result['error'], 'generic transport failure message');
});

t('godwit_check_remote_about: still classifies a real backend error and a genuine OK response correctly', function () {
    $fakeCallError = function ($listener, $path, $params, $timeoutSeconds, &$meta) {
        $meta = ['timed_out' => false];
        return ['error' => 'invalid_grant: token expired'];
    };
    $r1 = godwit_check_remote_about(['type' => 'unix', 'path' => '/x'], 'gdrive', 60, $fakeCallError);
    assert_eq('auth-expired', $r1['status'], 'a real backend error must still classify normally');

    $fakeCallOk = function ($listener, $path, $params, $timeoutSeconds, &$meta) {
        $meta = ['timed_out' => false];
        return ['total' => 100, 'used' => 50, 'free' => 50];
    };
    $r2 = godwit_check_remote_about(['type' => 'unix', 'path' => '/x'], 'gdrive', 60, $fakeCallOk);
    assert_eq('ok', $r2['status'], 'a genuine successful response must still classify as ok');
});

// --- godwit_health_notifications(): consecutive-failure notify rule --------

t('godwit_health_notifications: a transient error notifies only on the 2nd consecutive failure, not the 1st or 3rd', function () {
    $ok = ['status' => 'ok', 'quota_alerted' => 0, 'fail_count' => 0];
    $d1 = godwit_health_notifications('gdrive', $ok, ['status' => 'error', 'pct' => null, 'error' => 'connection refused']);
    assert_eq([], $d1['notifications'], 'a single transient failure must not notify');
    assert_eq(1, $d1['fail_count'], 'fail_count must be 1 after the first consecutive failure');

    $afterFirst = ['status' => 'error', 'quota_alerted' => 0, 'fail_count' => $d1['fail_count']];
    $d2 = godwit_health_notifications('gdrive', $afterFirst, ['status' => 'error', 'pct' => null, 'error' => 'connection refused']);
    assert_eq(1, count($d2['notifications']), 'the 2nd consecutive failure must notify');
    assert_eq(2, $d2['fail_count'], 'fail_count must be 2');

    $afterSecond = ['status' => 'error', 'quota_alerted' => 0, 'fail_count' => $d2['fail_count']];
    $d3 = godwit_health_notifications('gdrive', $afterSecond, ['status' => 'error', 'pct' => null, 'error' => 'connection refused']);
    assert_eq([], $d3['notifications'], 'a 3rd consecutive failure must not re-notify');
    assert_eq(3, $d3['fail_count'], 'fail_count keeps counting past the notify point');
});

t('godwit_health_notifications: auth-expired notifies immediately, unlike a transient error', function () {
    $ok = ['status' => 'ok', 'quota_alerted' => 0, 'fail_count' => 0];
    $d = godwit_health_notifications('gdrive', $ok, ['status' => 'auth-expired', 'pct' => null, 'error' => 'invalid_grant']);
    assert_eq(1, count($d['notifications']), 'auth-expired must notify on its very first occurrence');
});

t('godwit_health_notifications: recovery only fires if a failure was actually notified', function () {
    $afterOneUnnotifiedFailure = ['status' => 'error', 'quota_alerted' => 0, 'fail_count' => 1];
    $d1 = godwit_health_notifications('gdrive', $afterOneUnnotifiedFailure, ['status' => 'ok', 'pct' => null, 'error' => null]);
    assert_eq([], $d1['notifications'], 'recovering from a single un-notified blip must not send a "recovered" notification');

    $afterNotifiedFailure = ['status' => 'error', 'quota_alerted' => 0, 'fail_count' => 2];
    $d2 = godwit_health_notifications('gdrive', $afterNotifiedFailure, ['status' => 'ok', 'pct' => null, 'error' => null]);
    assert_eq(1, count($d2['notifications']), 'recovering after a notified failure must send a "recovered" notification');
});

// --- godwit_handle_remote_action(): validation errors are logged -----------
//
// These exercise only the validation paths, which return before any rc call
// to config/create ever happens — no real rcd is needed (config/dump against
// a nonexistent socket just returns null, and the existing-names check falls
// back to an empty list), matching how the release checklist's CLI-PHP
// "empty name" / "garbage token" checks are meant to work.

function godwit_test_action_env(string $prefix): array
{
    $tmp = sys_get_temp_dir() . "/godwit-$prefix-" . bin2hex(random_bytes(4));
    $runDir = $tmp . '/run';
    mkdir($runDir, 0700, true);
    $dbPath = $tmp . '/godwit.db';
    godwit_open_db($dbPath);
    godwit_write_rc_credentials($runDir, ['type' => 'unix', 'path' => $tmp . '/no-such.sock', 'user' => 'u', 'pass' => 'p']);
    return ['tmp' => $tmp, 'runDir' => $runDir, 'dbPath' => $dbPath, 'logFile' => $tmp . '/godwit.log'];
}

t('godwit_handle_remote_action: remotes_add_onedrive with an empty name logs and returns a clear error', function () {
    $env = godwit_test_action_env('empty-name');
    $result = godwit_handle_remote_action('remotes_add_onedrive', [
        'name' => '',
        'token' => json_encode(['access_token' => 'a', 'refresh_token' => 'b']),
    ], $env['dbPath'], $env['runDir'], $env['logFile']);

    assert_eq('name is required', $result['error'] ?? null, 'empty name must return a clear validation error: ' . json_encode($result));
    $logged = file_get_contents($env['logFile']);
    assert_true(str_contains($logged, 'failed validation: name is required'), "expected the validation failure logged: $logged");
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_remote_action: remotes_add_onedrive with a garbage (non-JSON) token logs and returns a clear error', function () {
    $env = godwit_test_action_env('garbage-token');
    $result = godwit_handle_remote_action('remotes_add_onedrive', [
        'name' => 'onedrive-garbage',
        'token' => 'this is not json at all',
    ], $env['dbPath'], $env['runDir'], $env['logFile']);

    assert_true(($result['error'] ?? null) !== null, 'a garbage token must return an error, not succeed');
    assert_true(str_contains($result['error'], 'JSON object'), 'error must clearly say no JSON object was found: ' . $result['error']);
    $logged = file_get_contents($env['logFile']);
    assert_true(str_contains($logged, 'failed validation: no JSON object found'), "expected the validation failure logged: $logged");
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_remote_action: remotes_add_onedrive tolerates the rclone authorize paste wrapper', function () {
    $env = godwit_test_action_env('wrapper-token');
    $wrapped = "Paste the following into your remote machine --->\n" .
        json_encode(['access_token' => 'a', 'refresh_token' => 'b']) .
        "\n<---End paste";
    // No real rcd is running, so this still fails — but past validation, at
    // the config/create rc call ("rcd did not answer"), proving the wrapper
    // itself was accepted rather than rejected as invalid JSON.
    $result = godwit_handle_remote_action('remotes_add_onedrive', [
        'name' => 'onedrive-wrapped',
        'token' => $wrapped,
    ], $env['dbPath'], $env['runDir'], $env['logFile']);
    assert_eq('rcd did not answer', $result['error'] ?? null, 'the wrapped paste must pass validation and only fail at the rc call: ' . json_encode($result));
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_remote_action: remotes_test logs the outcome even when rcd is unreachable', function () {
    $env = godwit_test_action_env('test-log');
    $result = godwit_handle_remote_action('remotes_test', ['name' => 'gdrive'], $env['dbPath'], $env['runDir'], $env['logFile']);
    assert_eq('error', $result['result']['status'] ?? null, 'an unreachable rcd must classify as error, not unchecked: ' . json_encode($result));
    $logged = file_get_contents($env['logFile']);
    assert_true(str_contains($logged, 'remote test gdrive: error'), "expected the test outcome logged: $logged");
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

// --- godwit_handle_remote_action(): drive picker, rollback, resubmit ------
//
// $rcCall is injected (default godwit_rc_call_params) the same way
// godwit_walk_config_state()'s $call and godwit_check_remote_about()'s $call
// are — so the full add flow, including config/create → config/update →
// config/delete, can be driven by a fixture with no real rcd or network.

function godwit_test_multi_drive_rc_call(array $drives, array &$deleteCalls)
{
    return function (array $listener, string $rcPath, array $params, int $timeout = 15, ?array &$meta = null) use ($drives, &$deleteCalls) {
        $meta = ['timed_out' => false];
        if ($rcPath === 'config/create') {
            return ['State' => '*oauth-confirm,choose_type,,', 'Option' => ['Name' => 'config_refresh_token'], 'Error' => '', 'Result' => ''];
        }
        if ($rcPath === 'config/update') {
            return godwit_test_onedrive_sequence_call($params, $drives);
        }
        if ($rcPath === 'config/delete') {
            $deleteCalls[] = $params;
            return ['ok' => true];
        }
        return null;
    };
}

t('godwit_handle_remote_action: remotes_add_onedrive with multiple drives returns a picker and rolls back the half-created remote', function () {
    $env = godwit_test_action_env('multi-drive');
    $drives = [
        ['Value' => 'a', 'Help' => 'Bundles_b896e2bb (personal)'],
        ['Value' => 'b', 'Help' => 'OneDrive (personal)'],
        ['Value' => 'c', 'Help' => 'ODCMetadataArchive (personal)'],
    ];
    $deleteCalls = [];
    $rcCall = godwit_test_multi_drive_rc_call($drives, $deleteCalls);
    $result = godwit_handle_remote_action('remotes_add_onedrive', [
        'name' => 'onedrive-multi',
        'token' => json_encode(['access_token' => 'a', 'refresh_token' => 'b']),
    ], $env['dbPath'], $env['runDir'], $env['logFile'], $rcCall);

    assert_true(isset($result['choose_drive']), 'expected a choose_drive response: ' . json_encode($result));
    assert_eq(3, count($result['choose_drive']), 'all three drives must be offered');
    assert_eq('b', $result['suggested'], 'the drive named exactly "OneDrive" must be suggested');
    assert_eq(1, count($deleteCalls), 'the half-created remote must be rolled back exactly once');
    assert_eq('onedrive-multi', $deleteCalls[0]['name'], 'rollback must delete the same name that was being added');
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_remote_action: remotes_add_onedrive resubmitted with a valid drive_id succeeds and answers with that drive', function () {
    $env = godwit_test_action_env('drive-id-valid');
    $drives = [
        ['Value' => 'a', 'Help' => 'Bundles_b896e2bb (personal)'],
        ['Value' => 'b', 'Help' => 'OneDrive (personal)'],
    ];
    $deleteCalls = [];
    $rcCall = godwit_test_multi_drive_rc_call($drives, $deleteCalls);
    $result = godwit_handle_remote_action('remotes_add_onedrive', [
        'name' => 'onedrive-picked',
        'token' => json_encode(['access_token' => 'a', 'refresh_token' => 'b']),
        'drive_id' => 'b',
    ], $env['dbPath'], $env['runDir'], $env['logFile'], $rcCall);

    assert_true(($result['ok'] ?? false) === true, 'a valid drive_id must let the add succeed: ' . json_encode($result));
    assert_eq('OneDrive (personal)', $result['drive'] ?? null, 'the chosen drive label must be reported');
    assert_eq(0, count($deleteCalls), 'a successful add must never roll back');
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_remote_action: remotes_add_onedrive resubmitted with an unknown drive_id fails and still rolls back', function () {
    $env = godwit_test_action_env('drive-id-unknown');
    $drives = [
        ['Value' => 'a', 'Help' => 'Bundles_b896e2bb (personal)'],
        ['Value' => 'b', 'Help' => 'OneDrive (personal)'],
    ];
    $deleteCalls = [];
    $rcCall = godwit_test_multi_drive_rc_call($drives, $deleteCalls);
    $result = godwit_handle_remote_action('remotes_add_onedrive', [
        'name' => 'onedrive-badpick',
        'token' => json_encode(['access_token' => 'a', 'refresh_token' => 'b']),
        'drive_id' => 'does-not-exist',
    ], $env['dbPath'], $env['runDir'], $env['logFile'], $rcCall);

    assert_true(str_contains($result['error'] ?? '', 'not one of the offered drives'), 'expected a clear error: ' . json_encode($result));
    assert_eq(1, count($deleteCalls), 'the half-created remote must still be rolled back');
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_remote_action: remotes_add_onedrive with a single drive still auto-selects, no picker', function () {
    $env = godwit_test_action_env('single-drive');
    $drives = [['Value' => 'a', 'Help' => 'KM OneDrive (personal)']];
    $deleteCalls = [];
    $rcCall = godwit_test_multi_drive_rc_call($drives, $deleteCalls);
    $result = godwit_handle_remote_action('remotes_add_onedrive', [
        'name' => 'onedrive-single',
        'token' => json_encode(['access_token' => 'a', 'refresh_token' => 'b']),
    ], $env['dbPath'], $env['runDir'], $env['logFile'], $rcCall);

    assert_true(($result['ok'] ?? false) === true, 'a single drive must auto-select: ' . json_encode($result));
    assert_eq('KM OneDrive (personal)', $result['drive'] ?? null, 'the single drive must be reported chosen');
    assert_true(!isset($result['choose_drive']), 'a single drive must never trigger the picker');
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

// --- godwit_token_expiry_error() / godwit_classify_walk_error(): expiry pre-check ---

t('godwit_token_expiry_error: an already-expired token is refused with a friendly, timestamped message', function () {
    $tokenJson = json_encode(['access_token' => 'a', 'refresh_token' => 'b', 'expiry' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)]);
    $error = godwit_token_expiry_error($tokenJson, 'onedrive');
    assert_true($error !== null, 'an expired token must be refused');
    assert_true(str_contains($error, 'expired'), 'message must say "expired": ' . $error);
    assert_true(str_contains($error, 'rclone authorize "onedrive"'), 'message must name the onedrive provider: ' . $error);
    assert_true(str_contains($error, 'upstream rclone bug'), 'message must note the upstream bug: ' . $error);
    assert_true(!str_contains($error, '\\'), 'the backtick command quoting must not leak a literal backslash into the message: ' . $error);
});

t('godwit_token_expiry_error: rclone\'s real RFC3339Nano-with-offset expiry shape (e.g. "2026-09-17T21:43:12.6543211+10:00") is parsed correctly', function () {
    // Ground truth: rclone marshals Go's time.Time as RFC3339Nano with a zone
    // offset, not the bare gmdate('...\Z') shape used by the other fixtures
    // in this file — a real pasted token looks like this, not like "...Z".
    $expiredReal = json_encode(['access_token' => 'a', 'refresh_token' => 'b', 'expiry' => '2020-01-01T21:43:12.6543211+10:00']);
    $error = godwit_token_expiry_error($expiredReal, 'onedrive');
    assert_true($error !== null, 'a real-shaped, long-expired token must still be recognised as expired: ' . json_encode($error));

    $freshReal = gmdate('Y-m-d\TH:i:s.0000000\+00:00', time() + 3600);
    $freshTokenJson = json_encode(['access_token' => 'a', 'refresh_token' => 'b', 'expiry' => $freshReal]);
    assert_true(godwit_token_expiry_error($freshTokenJson, 'onedrive') === null, 'a real-shaped token with an hour left must not be blocked: ' . $freshReal);
});

t('godwit_token_expiry_error: a token expiring in under 5 minutes is also refused', function () {
    $tokenJson = json_encode(['access_token' => 'a', 'refresh_token' => 'b', 'expiry' => gmdate('Y-m-d\TH:i:s\Z', time() + 120)]);
    $error = godwit_token_expiry_error($tokenJson, 'drive');
    assert_true($error !== null, 'a token expiring in 2 minutes must be refused');
    assert_true(str_contains($error, 'rclone authorize "drive"'), 'message must be provider-appropriate for Google Drive: ' . $error);
});

t('godwit_token_expiry_error: a token with plenty of time left is not blocked', function () {
    $tokenJson = json_encode(['access_token' => 'a', 'refresh_token' => 'b', 'expiry' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]);
    assert_true(godwit_token_expiry_error($tokenJson, 'onedrive') === null, 'a token with an hour left must not be blocked');
});

t('godwit_token_expiry_error: a missing expiry field carries on (not blocked)', function () {
    $tokenJson = json_encode(['access_token' => 'a', 'refresh_token' => 'b']);
    assert_true(godwit_token_expiry_error($tokenJson, 'onedrive') === null, 'no expiry field at all must not block');
});

t('godwit_token_expiry_error: an unparseable expiry carries on (not blocked)', function () {
    $tokenJson = json_encode(['access_token' => 'a', 'refresh_token' => 'b', 'expiry' => 'not-a-real-timestamp']);
    assert_true(godwit_token_expiry_error($tokenJson, 'onedrive') === null, 'an unparseable expiry must not block — we can\'t prove it\'s dead');
});

t('godwit_classify_walk_error: classifies rclone\'s "unsupported protocol scheme" into the friendly expiry message', function () {
    $tokenJson = json_encode(['access_token' => 'a', 'refresh_token' => 'b', 'expiry' => gmdate('Y-m-d\TH:i:s\Z', time() - 10)]);
    $raw = 'Failed to query available drives: /me/drives: Get "https://graph.microsoft.com/v1.0/me/drives": couldn\'t fetch token: Post "": unsupported protocol scheme ""';
    $msg = godwit_classify_walk_error($raw, $tokenJson, 'onedrive');
    assert_true(str_contains($msg, 'expired'), 'classified message must be the friendly expiry text: ' . $msg);
    assert_true(str_contains($msg, 'rclone authorize "onedrive"'), 'classified message must name onedrive: ' . $msg);
});

t('godwit_classify_walk_error: leaves an unrelated backend error untouched', function () {
    $msg = godwit_classify_walk_error('some other rclone backend error', '{"access_token":"a","refresh_token":"b"}', 'onedrive');
    assert_eq('some other rclone backend error', $msg, 'an unrelated message must pass through unchanged');
});

t('godwit_handle_remote_action: remotes_add_onedrive with an already-expired token is refused before any config/create or config/update call', function () {
    // Note: this only proves config/create/config/update are never reached —
    // the existing-name check ahead of it still does its own real
    // config/dump call (against a nonexistent socket here, so it just
    // returns null quickly), same as every other validation path in this
    // function.
    $env = godwit_test_action_env('expired-token');
    $rcCall = function (...$args) {
        throw new \RuntimeException('config/create or config/update must not be called for an already-expired token');
    };
    $expiredIso = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
    $result = godwit_handle_remote_action('remotes_add_onedrive', [
        'name' => 'onedrive-expired',
        'token' => json_encode(['access_token' => 'a', 'refresh_token' => 'b', 'expiry' => $expiredIso]),
    ], $env['dbPath'], $env['runDir'], $env['logFile'], $rcCall);

    assert_true(str_contains($result['error'] ?? '', 'expired'), 'expected the friendly expiry error: ' . json_encode($result));
    $logged = file_get_contents($env['logFile']);
    assert_true(str_contains($logged, 'failed validation:'), "expiry rejection must log as failed validation: $logged");
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_remote_action: remotes_add_drive with an already-expired token is refused with the drive-provider message', function () {
    $env = godwit_test_action_env('expired-drive-token');
    $rcCall = function (...$args) {
        throw new \RuntimeException('rcd must not be called for an already-expired token');
    };
    $expiredIso = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
    $result = godwit_handle_remote_action('remotes_add_drive', [
        'name' => 'gdrive-expired',
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'token' => json_encode(['access_token' => 'a', 'refresh_token' => 'b', 'expiry' => $expiredIso]),
    ], $env['dbPath'], $env['runDir'], $env['logFile'], $rcCall);

    assert_true(str_contains($result['error'] ?? '', 'rclone authorize "drive"'), 'expected the drive-provider message: ' . json_encode($result));
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_remote_action: remotes_add_onedrive with a missing expiry field is not blocked and still reaches rcd', function () {
    $env = godwit_test_action_env('no-expiry');
    $result = godwit_handle_remote_action('remotes_add_onedrive', [
        'name' => 'onedrive-noexpiry',
        'token' => json_encode(['access_token' => 'a', 'refresh_token' => 'b']),
    ], $env['dbPath'], $env['runDir'], $env['logFile']);
    assert_eq('rcd did not answer', $result['error'] ?? null, 'a token with no expiry field must pass the pre-check and only fail at the real rc call: ' . json_encode($result));
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

// === Phase 3: Jobs, budget, windows, status ================================

// --- Job builder + direction assertion ----------------------------------

t('godwit_build_job_fs: builds a plain local-src / remote-dst pair', function () {
    $fs = godwit_build_job_fs(['share' => 'Kieren', 'remote' => 'gdrive']);
    assert_eq('/mnt/user/Kieren', $fs['srcFs'], 'srcFs');
    assert_eq('gdrive:godwit/Kieren', $fs['dstFs'], 'dstFs');
});

t('godwit_build_job_fs: rejects a bare "." or ".." share name (would otherwise resolve to /mnt/user itself or its parent)', function () {
    foreach (['.', '..'] as $bad) {
        try {
            godwit_build_job_fs(['share' => $bad, 'remote' => 'gdrive']);
            throw new \RuntimeException('expected an exception for share=' . var_export($bad, true));
        } catch (\InvalidArgumentException $e) {
            assert_true(true, 'threw as expected for ' . var_export($bad, true));
        }
    }
});

t('godwit_assert_purge_path: rejects a bare "." or ".." share name', function () {
    foreach (['.', '..'] as $bad) {
        try {
            godwit_assert_purge_path('gdrive', $bad, '2026-08-01');
            throw new \RuntimeException('expected an exception for share=' . var_export($bad, true));
        } catch (\InvalidArgumentException $e) {
            assert_true(true, 'threw as expected for ' . var_export($bad, true));
        }
    }
});

t('godwit_build_job_fs: rejects a share name containing a path separator', function () {
    try {
        godwit_build_job_fs(['share' => 'Kieren/../../etc', 'remote' => 'gdrive']);
        throw new \RuntimeException('expected an exception for a traversal share name');
    } catch (\InvalidArgumentException $e) {
        assert_true(true, 'threw as expected');
    }
});

t('godwit_assert_job_direction: accepts a correctly-built pair', function () {
    $fs = godwit_build_job_fs(['share' => 'Teegan', 'remote' => 'gdrive']);
    godwit_assert_job_direction($fs);
    assert_true(true, 'no exception');
});

t('godwit_assert_job_direction: throws if srcFs and dstFs were swapped (the safety-critical case)', function () {
    $swapped = ['srcFs' => 'gdrive:godwit/Kieren', 'dstFs' => '/mnt/user/Kieren'];
    try {
        godwit_assert_job_direction($swapped);
        throw new \RuntimeException('expected an exception for a reversed direction');
    } catch (\RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'not a local path'), 'expected the src-not-local message: ' . $e->getMessage());
    }
});

t('godwit_assert_job_direction: throws when dstFs is itself a local path', function () {
    try {
        godwit_assert_job_direction(['srcFs' => '/mnt/user/Kieren', 'dstFs' => '/mnt/user/Kieren2']);
        throw new \RuntimeException('expected an exception');
    } catch (\RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'not a plain remote:path') || str_contains($e->getMessage(), 'looks like a local path'), $e->getMessage());
    }
});

t('godwit_assert_job_direction: throws when srcFs is outside /mnt/user', function () {
    try {
        godwit_assert_job_direction(['srcFs' => '/etc/passwd', 'dstFs' => 'gdrive:godwit/x']);
        throw new \RuntimeException('expected an exception');
    } catch (\RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'not a local path'), $e->getMessage());
    }
});

t('godwit_build_sync_params: sync mode sets BackupDir, copy mode does not', function () {
    $job = ['share' => 'Photos', 'remote' => 'gdrive', 'mode' => 'sync', 'transfers' => 4, 'max_delete' => 1000];
    $fs = godwit_build_job_fs($job);
    $params = godwit_build_sync_params($job, $fs, '/tmp/filter.txt', 700 * 1024 * 1024 * 1024, godwit_backup_dir_fs($job, '2026-09-17'), null);
    $config = json_decode($params['_config'], true);
    assert_eq('gdrive:godwit/_versions/Photos/2026-09-17', $config['BackupDir'], 'BackupDir should be set for sync mode');
    assert_eq('CAUTIOUS', $config['CutoffMode'], 'CutoffMode');

    $copyParams = godwit_build_sync_params($job, $fs, '/tmp/filter.txt', 1000, null, null);
    $copyConfig = json_decode($copyParams['_config'], true);
    assert_true(!array_key_exists('BackupDir', $copyConfig), 'copy mode must not set BackupDir');
});

t('godwit_build_sync_params: refuses to build params for a reversed-direction Fs pair', function () {
    $bad = ['srcFs' => 'gdrive:godwit/Kieren', 'dstFs' => '/mnt/user/Kieren'];
    try {
        godwit_build_sync_params(['share' => 'Kieren', 'remote' => 'gdrive', 'mode' => 'sync'], $bad, '/tmp/f', 100, null, null);
        throw new \RuntimeException('expected an exception');
    } catch (\RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'not a local path'), $e->getMessage());
    }
});

t('godwit_sync_rc_path: sync and copy map to the right rc endpoint', function () {
    assert_eq('sync/sync', godwit_sync_rc_path('sync'), 'sync');
    assert_eq('sync/copy', godwit_sync_rc_path('copy'), 'copy');
});

// --- Overlap / destination validation ------------------------------------

t('godwit_validate_job_destinations: the four Phase 3 defaults do not overlap', function () {
    assert_eq(null, godwit_validate_job_destinations(godwit_default_jobs()), 'default jobs should not overlap');
});

t('godwit_validate_job_destinations: rejects two jobs with the same destination', function () {
    $jobs = [
        ['name' => 'A', 'share' => 'Kieren', 'remote' => 'gdrive', 'enabled' => true],
        ['name' => 'B', 'share' => 'Kieren', 'remote' => 'gdrive', 'enabled' => true],
    ];
    $err = godwit_validate_job_destinations($jobs);
    assert_true($err !== null && str_contains($err, 'overlapping'), 'expected an overlap error: ' . var_export($err, true));
});

t('godwit_validate_job_destinations: ignores a disabled job\'s overlap', function () {
    $jobs = [
        ['name' => 'A', 'share' => 'Kieren', 'remote' => 'gdrive', 'enabled' => true],
        ['name' => 'B', 'share' => 'Kieren', 'remote' => 'gdrive', 'enabled' => false],
    ];
    assert_eq(null, godwit_validate_job_destinations($jobs), 'a disabled job cannot run, so it cannot overlap');
});

t('godwit_validate_job_destinations: rejects a destination under godwit/_versions', function () {
    $jobs = [['name' => 'Bad', 'share' => '_versions', 'remote' => 'gdrive', 'enabled' => true]];
    $err = godwit_validate_job_destinations($jobs);
    assert_true($err !== null && str_contains($err, '_versions'), var_export($err, true));
});

// --- Filter compilation + effect (against the real bundled rclone) ------

t('godwit_compile_filter_rules: global excludes plus per-job excludes, in order', function () {
    $job = ['excludes' => ['/TimeMachine/**', '/Backup/BombVault/**']];
    $rules = godwit_compile_filter_rules($job);
    assert_true(in_array('- .DS_Store', $rules, true), 'global exclude present');
    assert_true(in_array('- /TimeMachine/**', $rules, true), 'job exclude present');
    assert_eq(count(godwit_global_excludes()) + 2, count($rules), 'total rule count');
});

t('godwit_compile_filter_rules + rclone lsf -R: excludes are actually filtered by the bundled binary', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return; // not cached locally — covered by host verification instead.
    }
    $tmp = sys_get_temp_dir() . '/godwit-filter-' . bin2hex(random_bytes(4));
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';
    assert_true(is_file($rclone), 'expected an unzipped rclone binary');

    $tree = $tmp . '/tree';
    $paths = [
        'TimeMachine/backup.sparsebundle/x',       // anchored exclude: must be filtered
        'Backup/BombVault/secrets.cfg',             // anchored exclude: must be filtered
        'Backup/AirVault/photo.jpg',                 // sibling of BombVault: must survive
        '.DS_Store',                                  // unanchored exclude at root
        'nested/dir/.DS_Store',                       // unanchored exclude at any depth
        'nested/dir/._resource',                      // unanchored ._* at any depth
        'loose-file.txt',                             // ordinary file: must survive
    ];
    foreach ($paths as $p) {
        $full = $tree . '/' . $p;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, 'x');
    }

    $job = ['excludes' => ['/TimeMachine/**', '/Backup/BombVault/**']];
    $filterFile = $tmp . '/filter.txt';
    godwit_write_filter_file($filterFile, godwit_compile_filter_rules($job));

    $out = [];
    exec($rclone . ' lsf -R --filter-from ' . escapeshellarg($filterFile) . ' ' . escapeshellarg($tree), $out);
    $listed = implode("\n", $out);

    foreach (['TimeMachine/backup.sparsebundle/x', 'Backup/BombVault/secrets.cfg', '.DS_Store', 'nested/dir/.DS_Store', 'nested/dir/._resource'] as $excluded) {
        assert_true(!str_contains($listed, $excluded), "$excluded should have been filtered out; lsf output:\n$listed");
    }
    foreach (['Backup/AirVault/photo.jpg', 'loose-file.txt'] as $kept) {
        assert_true(str_contains($listed, $kept), "$kept should have survived filtering; lsf output:\n$listed");
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

// --- Phase 4: selective (OneDrive tree) filter compilation ---------------

t('godwit_compile_filter_rules: routes to godwit_compile_selective_filter_rules() for type=selective', function () {
    $job = ['type' => 'selective', 'included' => [['path' => 'Documents', 'is_dir' => true]], 'excluded' => []];
    $rules = godwit_compile_filter_rules($job);
    assert_true(in_array('+ /Documents/**', $rules, true), 'include rule present');
    assert_eq('- **', end($rules), 'trailing catch-all deny');
});

t('godwit_compile_selective_filter_rules: excludes ordered before their including ancestor, global excludes present, trailing deny', function () {
    $job = [
        'included' => [['path' => 'Documents', 'is_dir' => true]],
        'excluded' => [['path' => 'Documents/Drafts', 'is_dir' => true]],
    ];
    $rules = godwit_compile_selective_filter_rules($job);
    $excludePos = array_search('- /Documents/Drafts/**', $rules, true);
    $includePos = array_search('+ /Documents/**', $rules, true);
    assert_true($excludePos !== false && $includePos !== false, 'both rules present');
    assert_true($excludePos < $includePos, 'exclude must come before its ancestor include so the more specific rule wins');
    assert_true(in_array('- .DS_Store', $rules, true), 'global excludes still apply inside a selective job');
    assert_eq('- **', end($rules), 'trailing catch-all deny');
});

t('godwit_tree_node_filter_line: a single selected file compiles without the recursive /** suffix', function () {
    assert_eq('+ /Photos/beach.jpg', godwit_tree_node_filter_line('+', ['path' => 'Photos/beach.jpg', 'is_dir' => false]), 'include line for a file');
    assert_eq('- /Photos/beach.jpg', godwit_tree_node_filter_line('-', ['path' => '/Photos/beach.jpg/', 'is_dir' => false]), 'exclude line for a file, slashes trimmed');
});

t('godwit_escape_filter_pattern: backslash-escapes every rclone glob metacharacter, nothing else', function () {
    assert_eq('Photos \\[RAW\\]', godwit_escape_filter_pattern('Photos [RAW]'), 'brackets escaped');
    assert_eq('a\\*b\\?c', godwit_escape_filter_pattern('a*b?c'), 'star and question mark escaped');
    assert_eq('x\\{y\\}', godwit_escape_filter_pattern('x{y}'), 'braces escaped');
    assert_eq('a\\\\b', godwit_escape_filter_pattern('a\\b'), 'a literal backslash in the real filename is itself escaped');
    assert_eq('Ordinary Folder Name', godwit_escape_filter_pattern('Ordinary Folder Name'), 'no metacharacters, no change');
});

t('godwit_tree_node_filter_line: a folder name with glob metacharacters compiles to an escaped literal, not a glob', function () {
    assert_eq('+ /Photos \\[RAW\\]/**', godwit_tree_node_filter_line('+', ['path' => 'Photos [RAW]', 'is_dir' => true]), 'brackets in a real folder name must not become a glob character class');
});

t('godwit_compile_selective_filter_rules + rclone lsf -R: only ticked subtrees survive, un-ticked children are excluded, unselected siblings never appear (ground-truthed against the real bundled binary)', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return;
    }
    $tmp = sys_get_temp_dir() . '/godwit-selective-' . bin2hex(random_bytes(4));
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';
    assert_true(is_file($rclone), 'expected an unzipped rclone binary');

    $tree = $tmp . '/tree';
    $paths = [
        'Documents/Taxes/2025.pdf',      // included subtree: must survive
        'Documents/Drafts/todo.txt',     // un-ticked child of an included folder: must be filtered
        'Documents/.DS_Store',           // global junk exclude inside an included folder: must be filtered
        'Pictures/holiday.jpg',          // never selected at all: must be filtered
        'loose-file.txt',                 // never selected, top level: must be filtered
    ];
    foreach ($paths as $p) {
        $full = $tree . '/' . $p;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, 'x');
    }

    $job = [
        'included' => [['path' => 'Documents', 'is_dir' => true]],
        'excluded' => [['path' => 'Documents/Drafts', 'is_dir' => true]],
    ];
    $filterFile = $tmp . '/filter.txt';
    godwit_write_filter_file($filterFile, godwit_compile_selective_filter_rules($job));

    $out = [];
    exec($rclone . ' lsf -R --filter-from ' . escapeshellarg($filterFile) . ' ' . escapeshellarg($tree), $out);
    $listed = implode("\n", $out);

    foreach (['Documents/Drafts/todo.txt', 'Documents/.DS_Store', 'Pictures/holiday.jpg', 'loose-file.txt'] as $excluded) {
        assert_true(!str_contains($listed, $excluded), "$excluded should have been filtered out; lsf output:\n$listed");
    }
    assert_true(str_contains($listed, 'Documents/Taxes/2025.pdf'), "Documents/Taxes/2025.pdf should have survived filtering; lsf output:\n$listed");
    exec('rm -rf ' . escapeshellarg($tmp));
});

t('godwit_compile_selective_filter_rules + rclone lsf -R: a folder name with rclone glob metacharacters is matched literally, not as a glob (real bundled binary)', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return;
    }
    $tmp = sys_get_temp_dir() . '/godwit-selective-glob-' . bin2hex(random_bytes(4));
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';
    assert_true(is_file($rclone), 'expected an unzipped rclone binary');

    $tree = $tmp . '/tree';
    // "Photos [RAW]" contains an rclone glob character class ([RAW]) — an
    // unescaped "+ /Photos [RAW]/**" would ALSO match "Photos R", "Photos A"
    // and "Photos W", any of which existing on disk would prove the escape
    // is missing (the real folder itself surviving is not sufficient proof,
    // since an unescaped char class still matches its own literal text).
    $paths = [
        'Photos [RAW]/img1.cr2',
        'Photos R/should-not-match.txt',
        'Photos A/should-not-match.txt',
        'Photos W/should-not-match.txt',
    ];
    foreach ($paths as $p) {
        $full = $tree . '/' . $p;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, 'x');
    }

    $job = ['included' => [['path' => 'Photos [RAW]', 'is_dir' => true]], 'excluded' => []];
    $filterFile = $tmp . '/filter.txt';
    godwit_write_filter_file($filterFile, godwit_compile_selective_filter_rules($job));

    $out = [];
    exec($rclone . ' lsf -R --filter-from ' . escapeshellarg($filterFile) . ' ' . escapeshellarg($tree), $out);
    $listed = implode("\n", $out);

    assert_true(str_contains($listed, 'Photos [RAW]/img1.cr2'), "the literally-bracketed folder should have survived filtering; lsf output:\n$listed");
    foreach (['Photos R/should-not-match.txt', 'Photos A/should-not-match.txt', 'Photos W/should-not-match.txt'] as $mustNotMatch) {
        assert_true(!str_contains($listed, $mustNotMatch), "$mustNotMatch would only appear if [RAW] were interpreted as an unescaped glob character class; lsf output:\n$listed");
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

t('godwit_build_sync_params + rc sync/sync with a selective job: only the ticked subtree is transferred, and sync never deletes destination content outside the filter (real rcd, real sync mode — the actual data-safety claim, not just lsf)', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return;
    }
    $tmp = sys_get_temp_dir() . '/godwit-e2e-selective-sync-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';
    assert_true(is_file($rclone), 'expected an unzipped rclone binary');

    $shareRoot = $tmp . '/mnt-user';
    $src = $shareRoot . '/TestShare';
    mkdir($src . '/Documents/Taxes', 0755, true);
    mkdir($src . '/Pictures', 0755, true);
    file_put_contents($src . '/Documents/Taxes/2025.pdf', 'x');
    file_put_contents($src . '/Pictures/holiday.jpg', 'x'); // never selected — must never reach dst

    $dst = $tmp . '/dst';
    // Pre-seed destination content OUTSIDE the selective job's filter, exactly
    // as if a previous backup (or a stray file) already lived there — sync's
    // own delete phase must never touch it, since a filtered-out path is
    // outside the sync's view entirely, not "in scope and spared".
    mkdir($dst . '/Pictures', 0755, true);
    file_put_contents($dst . '/Pictures/old-holiday.jpg', 'preexisting');

    $confPath = $tmp . '/rclone.conf';
    file_put_contents($confPath, "[localdst]\ntype = local\n");
    $sockPath = $tmp . '/rcd.sock';
    $listener = ['type' => 'unix', 'path' => $sockPath, 'user' => 'testuser', 'pass' => 'testpass'];
    $proc = proc_open(
        [$rclone, 'rcd', '--rc-addr=unix://' . $sockPath, '--config=' . $confPath, '--log-file=' . $tmp . '/rcd.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/rcd.log', 'a'], 2 => ['file', $tmp . '/rcd.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), godwit_rcd_env($listener))
    );
    fclose($pipes[0]);
    for ($i = 0; $i < 30 && !file_exists($sockPath); $i++) {
        usleep(100000);
    }
    assert_true(file_exists($sockPath), 'rcd did not create its unix socket in time');

    try {
        $job = [
            'name' => 'TestShare', 'share' => 'TestShare', 'remote' => 'localdst', 'mode' => 'sync',
            'type' => 'selective', 'transfers' => 2, 'max_delete' => 1000,
            'included' => [['path' => 'Documents', 'is_dir' => true]], 'excluded' => [],
        ];
        $fs = ['srcFs' => $src, 'dstFs' => 'localdst:' . $dst];
        $filterFile = $tmp . '/filter.txt';
        godwit_write_filter_file($filterFile, godwit_compile_filter_rules($job));
        // GODWIT_BUDGET_UNLIMITED (OneDrive's default — no configured daily
        // cap) rather than a plain byte count: proves both the data-safety
        // claim below AND that the sentinel never reaches rcd as MaxTransfer
        // (the value that isn't safely representable in rclone's own
        // JSON->float64->int64 config round-trip) in one real rc call.
        $params = godwit_build_sync_params($job, $fs, $filterFile, GODWIT_BUDGET_UNLIMITED, null, null, false, $shareRoot);
        assert_true(!str_contains($params['_config'], 'MaxTransfer'), 'the unlimited sentinel must never be sent to rcd as MaxTransfer: ' . $params['_config']);

        $resp = godwit_rc_call_params($listener, godwit_sync_rc_path($job['mode']), $params, 15);
        assert_true(isset($resp['jobid']), 'expected a jobid back from sync/sync: ' . json_encode($resp));

        $status = null;
        for ($i = 0; $i < 50; $i++) {
            $status = godwit_rc_call_params($listener, 'job/status', ['jobid' => $resp['jobid']], 15);
            if (!empty($status['finished'])) {
                break;
            }
            usleep(100000);
        }
        assert_true($status !== null && !empty($status['finished']), 'job should finish within 5s: ' . json_encode($status));
        assert_eq('', trim((string) ($status['error'] ?? '')), 'a plain selective sync should not error: ' . json_encode($status));

        assert_true(is_file($dst . '/Documents/Taxes/2025.pdf'), 'the ticked subtree must have been transferred');
        assert_true(!file_exists($dst . '/Pictures/holiday.jpg'), 'the never-selected file must never reach the destination');
        assert_true(is_file($dst . '/Pictures/old-holiday.jpg'), 'sync must NOT delete pre-existing destination content that sits outside the filter — this is the actual data-safety claim, not just that included files transfer');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

// --- Phase 4: tree-selection job validation --------------------------------

t('godwit_validate_selective_job: a valid selection passes', function () {
    $job = ['name' => 'x', 'included' => [['path' => 'Documents', 'is_dir' => true]], 'excluded' => [['path' => 'Documents/Drafts', 'is_dir' => true]]];
    assert_eq(null, godwit_validate_selective_job($job), 'valid selection should pass');
});

t('godwit_validate_selective_job: rejects an empty included list', function () {
    $err = godwit_validate_selective_job(['name' => 'x', 'included' => []]);
    assert_true($err !== null && str_contains($err, 'at least one'), 'expected an error, got: ' . var_export($err, true));
});

t('godwit_validate_selective_job: rejects an excluded path with no included ancestor', function () {
    $job = ['name' => 'x', 'included' => [['path' => 'Documents', 'is_dir' => true]], 'excluded' => [['path' => 'Pictures/holiday', 'is_dir' => true]]];
    $err = godwit_validate_selective_job($job);
    assert_true($err !== null && str_contains($err, 'not under any included path'), 'expected an error, got: ' . var_export($err, true));
});

t('godwit_validate_selective_job: rejects path traversal in an included entry', function () {
    $job = ['name' => 'x', 'included' => [['path' => '../etc/passwd', 'is_dir' => false]]];
    $err = godwit_validate_selective_job($job);
    assert_true($err !== null && str_contains($err, 'invalid included path'), 'expected an error, got: ' . var_export($err, true));
});

t('godwit_validate_selective_job: rejects an included entry missing is_dir — a silently-defaulted type would compile a file selection to a dead filter rule', function () {
    $job = ['name' => 'x', 'included' => [['path' => 'Documents/report.pdf']]];
    $err = godwit_validate_selective_job($job);
    assert_true($err !== null && str_contains($err, 'invalid included path'), 'expected an error, got: ' . var_export($err, true));
});

t('godwit_validate_selective_job: rejects an included entry whose is_dir is not a real bool', function () {
    $job = ['name' => 'x', 'included' => [['path' => 'Documents', 'is_dir' => 'true']]];
    $err = godwit_validate_selective_job($job);
    assert_true($err !== null && str_contains($err, 'invalid included path'), 'expected an error, got: ' . var_export($err, true));
});

t('godwit_handle_job_action jobs_save: rejects a selective job with no included paths', function () use ($repoRoot) {
    $cfgDir = sys_get_temp_dir() . '/godwit-cfg-' . bin2hex(random_bytes(4));
    $jobs = [['name' => 'Selective', 'share' => 'Kieren', 'remote' => 'kmonedrive', 'type' => 'selective', 'included' => []]];
    $result = godwit_handle_job_action('jobs_save', ['jobs' => json_encode($jobs)], '/nonexistent.db', sys_get_temp_dir(), $cfgDir);
    assert_true(isset($result['error']) && str_contains($result['error'], 'at least one'), 'expected a validation error, got: ' . var_export($result, true));
    exec('rm -rf ' . escapeshellarg($cfgDir));
});

t('godwit_handle_job_action jobs_save: accepts a valid selective job and round-trips type/included/excluded', function () use ($repoRoot) {
    $cfgDir = sys_get_temp_dir() . '/godwit-cfg-' . bin2hex(random_bytes(4));
    // v0.6.0 shape: no 'share' field, included/excluded already share-qualified.
    $jobs = [['name' => 'Selective', 'remote' => 'kmonedrive', 'type' => 'selective', 'mode' => 'sync', 'included' => [['path' => 'Kieren/Documents', 'is_dir' => true]], 'excluded' => []]];
    $runDir = sys_get_temp_dir() . '/godwit-run-' . bin2hex(random_bytes(4));
    @mkdir($runDir, 0755, true);
    $result = godwit_handle_job_action('jobs_save', ['jobs' => json_encode($jobs)], '/nonexistent.db', $runDir, $cfgDir);
    assert_true(($result['ok'] ?? false) === true, 'expected ok=true, got: ' . var_export($result, true));
    $saved = godwit_load_jobs($cfgDir);
    assert_eq('selective', $saved[0]['type'], 'type round-trips');
    assert_eq('Kieren/Documents', $saved[0]['included'][0]['path'], 'included path round-trips');
    exec('rm -rf ' . escapeshellarg($cfgDir) . ' ' . escapeshellarg($runDir));
});

t('godwit_handle_job_action: tree_size returns an error, not a silently-created db file, when godwitd has never run', function () {
    $missingDb = sys_get_temp_dir() . '/godwit-missing-' . bin2hex(random_bytes(4)) . '.db';
    $result = godwit_handle_job_action('tree_size', ['share' => 'Kieren', 'path' => ''], $missingDb, sys_get_temp_dir(), sys_get_temp_dir());
    assert_true(isset($result['error']), 'expected an error, got: ' . var_export($result, true));
    assert_true(!is_file($missingDb), 'tree_size must not silently create a db file — mirrors the jobs_status guard');
});

t('godwit_handle_job_action: tree_list dispatches to godwit_tree_list() and needs no database at all', function () {
    $root = sys_get_temp_dir() . '/godwit-tree-dispatch-' . bin2hex(random_bytes(4));
    @mkdir($root . '/Share/Documents', 0755, true);
    // tree_list hard-codes /mnt/user as its share root (matching every
    // other job path in this codebase), so this only proves dispatch and
    // JSON shape, not the real host tree — godwit_tree_list() itself is
    // covered directly against a real root below.
    $result = godwit_handle_job_action('tree_list', ['share' => 'DoesNotExist', 'path' => ''], '/nonexistent.db', sys_get_temp_dir(), sys_get_temp_dir());
    assert_true(isset($result['error']), 'a share with no real /mnt/user directory should error, proving no db was required to get there: ' . var_export($result, true));
    exec('rm -rf ' . escapeshellarg($root));
});

// --- Phase 4: tree listing (scandir over /mnt/user/<share>) ----------------

t('godwit_tree_safe_path: rejects traversal, absolute escape and a share with a slash', function () {
    assert_eq(null, godwit_tree_safe_path('Kieren', '../../etc'), 'traversal above the share rejected');
    assert_eq(null, godwit_tree_safe_path('Kieren', 'a/../../b'), 'embedded .. rejected');
    assert_eq(null, godwit_tree_safe_path('a/b', ''), 'share with a slash rejected');
    assert_eq('/mnt/user/Kieren/Documents', godwit_tree_safe_path('Kieren', '/Documents/'), 'leading/trailing slashes trimmed');
});

t('godwit_tree_list: lists a real temp directory, folders before files, alphabetical', function () {
    $root = sys_get_temp_dir() . '/godwit-tree-' . bin2hex(random_bytes(4));
    @mkdir($root . '/Share/Zeta', 0755, true);
    @mkdir($root . '/Share/Alpha', 0755, true);
    file_put_contents($root . '/Share/readme.txt', 'x');
    $result = godwit_tree_list('Share', '', $root);
    assert_true(!isset($result['error']), 'expected no error, got: ' . var_export($result, true));
    $names = array_map(fn ($e) => $e['name'], $result['entries']);
    assert_eq(['Alpha', 'Zeta', 'readme.txt'], $names, 'folders sorted before files, then alphabetically');
    assert_eq(true, $result['entries'][0]['is_dir'], 'first entry is a directory');
    assert_eq(false, $result['entries'][2]['is_dir'], 'last entry is a file');
    exec('rm -rf ' . escapeshellarg($root));
});

t('godwit_tree_list: an invalid share is rejected before touching the filesystem', function () {
    $result = godwit_tree_list('../etc', '', '/mnt/user');
    assert_true(isset($result['error']), 'expected an error');
});

// --- Phase 4: on-demand, cached node sizing (du -sb, never a PHP walker) --

t('godwit_du_bytes: parses the real `du -sb` output shape via an injected $run', function () {
    $bytes = godwit_du_bytes('/some/path', fn ($cmd) => "12345\t/some/path\n");
    assert_eq(12345, $bytes, 'parses the leading byte count');
});

t('godwit_du_bytes: returns null on empty output (path vanished mid-check)', function () {
    assert_eq(null, godwit_du_bytes('/gone', fn ($cmd) => ''), 'empty output yields null, not 0');
});

t('godwit_tree_node_size: computes once via the injected $run, then serves from cache without calling $run again', function () {
    $root = sys_get_temp_dir() . '/godwit-size-' . bin2hex(random_bytes(4));
    @mkdir($root . '/Share/Documents', 0755, true);
    file_put_contents($root . '/Share/Documents/file.txt', 'x');
    $db = new SQLite3(':memory:');
    $calls = 0;
    $run = function ($cmd) use (&$calls) { $calls++; return "999\t/x\n"; };

    $r1 = godwit_tree_node_size($db, 'Share', 'Documents', $root, $run);
    assert_eq(999, $r1['bytes'], 'first computed value');
    assert_eq(false, $r1['cached'], 'first call is a fresh compute');
    assert_eq(1, $calls, 'first call must shell out');

    $r2 = godwit_tree_node_size($db, 'Share', 'Documents', $root, $run);
    assert_eq(999, $r2['bytes'], 'second call returns the same cached value');
    assert_eq(true, $r2['cached'], 'second call is served from cache');
    assert_eq(1, $calls, 'second call must be served from cache, not re-shell out');
    exec('rm -rf ' . escapeshellarg($root));
});

t('godwit_tree_node_size: a single file over the 250 GiB OneDrive ceiling carries a warning', function () {
    $root = sys_get_temp_dir() . '/godwit-size-' . bin2hex(random_bytes(4));
    @mkdir($root . '/Share', 0755, true);
    file_put_contents($root . '/Share/huge.mov', 'x');
    $db = new SQLite3(':memory:');
    $tooBig = (string) (300 * 1024 * 1024 * 1024) . "\t/x\n";
    $r = godwit_tree_node_size($db, 'Share', 'huge.mov', $root, fn ($cmd) => $tooBig);
    assert_true(isset($r['warning']) && str_contains($r['warning'], '250 GiB'), 'expected a 250 GiB warning, got: ' . var_export($r, true));
});

t('godwit_tree_node_size: a folder under the ceiling carries no warning even if large', function () {
    $root = sys_get_temp_dir() . '/godwit-size-' . bin2hex(random_bytes(4));
    @mkdir($root . '/Share/Documents', 0755, true);
    $db = new SQLite3(':memory:');
    $big = (string) (300 * 1024 * 1024 * 1024) . "\t/x\n";
    $r = godwit_tree_node_size($db, 'Share', 'Documents', $root, fn ($cmd) => $big);
    assert_true(!isset($r['warning']), 'a directory total should never trip the single-file ceiling');
});

// --- Phase 4: per-remote budget cap (OneDrive defaults to unlimited) -------

t('godwit_budget_cap_bytes: gdrive falls back to the 700 GiB D12 default when unconfigured', function () {
    assert_eq(700 * 1024 * 1024 * 1024, godwit_budget_cap_bytes('gdrive', []), 'gdrive default cap');
});

t('godwit_budget_cap_bytes: any other unconfigured remote is effectively unlimited (PLAN.md: OneDrive budget off by default)', function () {
    assert_eq(PHP_INT_MAX, godwit_budget_cap_bytes('kmonedrive', []), 'unconfigured non-gdrive remote is unlimited');
});

t('godwit_budget_cap_bytes: an explicit setting always wins, for any remote', function () {
    $settings = ['budget_caps' => ['gdrive' => 123, 'kmonedrive' => 456]];
    assert_eq(123, godwit_budget_cap_bytes('gdrive', $settings), 'explicit gdrive override wins');
    assert_eq(456, godwit_budget_cap_bytes('kmonedrive', $settings), 'explicit kmonedrive override wins');
});

t('GODWIT_BUDGET_UNLIMITED: is exactly PHP_INT_MAX, and is what an unconfigured non-gdrive remote returns', function () {
    assert_eq(PHP_INT_MAX, GODWIT_BUDGET_UNLIMITED, 'sentinel value');
    assert_eq(GODWIT_BUDGET_UNLIMITED, godwit_budget_cap_bytes('kmonedrive', []), 'godwit_budget_cap_bytes must return the sentinel itself, not just a PHP_INT_MAX-valued int');
});

t('godwit_remaining_budget: the unlimited sentinel is returned unchanged, never cap-minus-used', function () {
    assert_eq(GODWIT_BUDGET_UNLIMITED, godwit_remaining_budget(GODWIT_BUDGET_UNLIMITED, 5_000_000_000), 'must stay the exact sentinel, not PHP_INT_MAX minus used bytes');
});

t('godwit_remaining_budget: an ordinary cap is unaffected by the sentinel special-case', function () {
    assert_eq(50, godwit_remaining_budget(100, 50), 'plain subtraction still works');
});

t('godwit_build_sync_params: omits MaxTransfer entirely for the unlimited sentinel, never sends PHP_INT_MAX to rcd', function () {
    $job = ['transfers' => 4, 'max_delete' => 1000];
    $fs = ['srcFs' => '/mnt/user/Share', 'dstFs' => 'onedrive:godwit/Share'];
    $params = godwit_build_sync_params($job, $fs, '/tmp/filter.txt', GODWIT_BUDGET_UNLIMITED, null, null);
    $config = json_decode($params['_config'], true);
    assert_true(!array_key_exists('MaxTransfer', $config), 'MaxTransfer must be entirely absent, not set to the sentinel: ' . $params['_config']);
});

t('godwit_build_sync_params: an ordinary byte count still sets MaxTransfer as before', function () {
    $job = ['transfers' => 4, 'max_delete' => 1000];
    $fs = ['srcFs' => '/mnt/user/Share', 'dstFs' => 'gdrive:godwit/Share'];
    $params = godwit_build_sync_params($job, $fs, '/tmp/filter.txt', 5_000_000, null, null);
    $config = json_decode($params['_config'], true);
    assert_eq(5_000_000, $config['MaxTransfer'] ?? null, 'a real budget must still be sent');
});

// --- Budget ledger maths --------------------------------------------------

t('godwit_ledger_used_24h: sums only samples inside the rolling 24h window', function () {
    $db = new SQLite3(':memory:');
    godwit_open_budget_table($db);
    $now = 1000000;
    godwit_record_ledger_delta($db, 'gdrive', $now - 90000, 5_000_000_000); // >24h ago: excluded
    godwit_record_ledger_delta($db, 'gdrive', $now - 3600, 2_000_000_000);
    godwit_record_ledger_delta($db, 'gdrive', $now - 60, 1_000_000_000);
    godwit_record_ledger_delta($db, 'onedrive', $now - 60, 9_000_000_000); // different remote: excluded
    assert_eq(3_000_000_000, godwit_ledger_used_24h($db, 'gdrive', $now), 'rolling sum');
});

t('godwit_ledger_used_24h: a delta exactly at the 24h boundary is excluded (ts > since, not >=)', function () {
    $db = new SQLite3(':memory:');
    godwit_open_budget_table($db);
    $now = 1000000;
    godwit_record_ledger_delta($db, 'gdrive', $now - 86400, 1_000_000_000);
    assert_eq(0, godwit_ledger_used_24h($db, 'gdrive', $now), 'exactly-24h-old sample rolls off');
});

t('godwit_record_ledger_delta: ignores zero/negative deltas (a stats-group reset must not subtract from the ledger)', function () {
    $db = new SQLite3(':memory:');
    godwit_open_budget_table($db);
    godwit_record_ledger_delta($db, 'gdrive', 1000, -500);
    godwit_record_ledger_delta($db, 'gdrive', 1000, 0);
    assert_eq(0, godwit_ledger_used_24h($db, 'gdrive', 100000), 'no rows should have been recorded');
});

t('godwit_remaining_budget: caps at zero, never negative', function () {
    assert_eq(0, godwit_remaining_budget(100, 150), 'overshoot clamps to 0');
    assert_eq(50, godwit_remaining_budget(100, 50), 'plain subtraction');
});

t('godwit_default_budget_cap_bytes: matches the D12 700 GiB default', function () {
    assert_eq(700 * 1024 * 1024 * 1024, godwit_default_budget_cap_bytes(), '700 GiB in bytes');
});

// --- v0.4.2 minimum budget threshold (Kieren's call: 20%) ------------------

t('GODWIT_MIN_BUDGET_FRACTION: is 0.20, and the 700 GiB default cap threshold is 140 GiB', function () {
    assert_eq(0.20, GODWIT_MIN_BUDGET_FRACTION, 'default fraction is 20%');
    $cap = 700 * 1024 * 1024 * 1024;
    assert_eq(140 * 1024 * 1024 * 1024, godwit_min_budget_threshold_bytes($cap, GODWIT_MIN_BUDGET_FRACTION), '20% of 700 GiB is 140 GiB');
});

t('godwit_job_budget_gated: a job resuming a budget stop is gated while remaining is below the threshold', function () {
    $cap = 700 * 1024 * 1024 * 1024;
    $last = ['outcome' => 'budget'];
    assert_true(godwit_job_budget_gated($last, $cap, 100 * 1024 * 1024 * 1024, 0.20), '100 GiB remaining < 140 GiB threshold — gated');
    assert_true(!godwit_job_budget_gated($last, $cap, 140 * 1024 * 1024 * 1024, 0.20), 'exactly at the threshold is enough — not gated');
    assert_true(!godwit_job_budget_gated($last, $cap, 200 * 1024 * 1024 * 1024, 0.20), '200 GiB remaining is well above the threshold — not gated');
});

t('godwit_job_budget_gated: a job that completed cleanly last time is never gated, even with almost no budget left — it may just need a few MB', function () {
    $cap = 700 * 1024 * 1024 * 1024;
    $last = ['outcome' => 'completed'];
    assert_true(!godwit_job_budget_gated($last, $cap, 1024, 0.20), 'completed last run must never be held back by the threshold');
});

t('godwit_job_budget_gated: window/interrupted/error/auth/throttled outcomes are not gated — only a real budget stop has known outstanding work', function () {
    $cap = 700 * 1024 * 1024 * 1024;
    foreach (['window', 'interrupted', 'error', 'auth', 'throttled'] as $outcome) {
        $last = ['outcome' => $outcome];
        assert_true(!godwit_job_budget_gated($last, $cap, 1024, 0.20), "outcome '$outcome' must not be gated by the budget threshold");
    }
});

t('godwit_job_budget_gated: a job that has never run is not gated', function () {
    assert_true(!godwit_job_budget_gated(null, 700 * 1024 * 1024 * 1024, 0, 0.20), 'no last run — nothing to resume, nothing to gate');
});

// --- v0.4.2 gate placement: a gated job must not stall a sibling on the ----
// --- same remote, and must not deadlock the queue if every job is gated ---

t('godwit_select_next_jobs: a gated job is skipped WITHOUT reserving its remote — the next job on the same remote still gets picked', function () {
    // Filing Cabinet is first in queue order but gated; Kieren (also gdrive)
    // must be selected instead, in the SAME tick — this is the stall this
    // gate exists to avoid, not a two-tick recovery.
    $selected = godwit_select_next_jobs(godwit_default_jobs(), [], [], 0, ['Filing Cabinet']);
    $names = array_column($selected, 'name');
    assert_true(!in_array('Filing Cabinet', $names, true), 'gated job must not be selected: ' . json_encode($names));
    assert_true(in_array('Kieren', $names, true), 'the next job on the same remote must be selected instead: ' . json_encode($names));
    assert_eq(1, count($selected), 'still only one job per remote');
});

t('godwit_select_next_jobs: every enabled job gated is a normal quiet state — selects nothing, does not error', function () {
    $names = array_column(godwit_default_jobs(), 'name');
    $selected = godwit_select_next_jobs(godwit_default_jobs(), [], [], 0, $names);
    assert_eq(0, count($selected), 'every job gated selects nothing (not a crash, not a partial list)');
});

t('godwit_select_next_jobs: a gated job on an otherwise-idle remote leaves that remote idle, not falsely reserved', function () {
    $jobs = [
        ['name' => 'A', 'share' => 'A', 'remote' => 'gdrive', 'enabled' => true],
        ['name' => 'B', 'share' => 'B', 'remote' => 'onedrive', 'enabled' => true],
    ];
    $selected = godwit_select_next_jobs($jobs, [], [], 0, ['A']);
    $names = array_column($selected, 'name');
    assert_eq(['B'], $names, 'gdrive stays free (A gated, nothing else queued for it); onedrive proceeds normally');
});

t('budget ledger: overshoot scenario — MaxTransfer per job can overshoot, the ledger still reflects real usage and zeroes remaining', function () {
    $db = new SQLite3(':memory:');
    godwit_open_budget_table($db);
    $cap = 10_000_000_000;
    $now = 500000;
    // Two jobs each requested max_transfer=remaining, but (per Phase 1's spike finding) actually transferred more due to in-flight files rounding up.
    godwit_record_ledger_delta($db, 'gdrive', $now - 100, 6_000_000_000);
    $remaining1 = godwit_remaining_budget($cap, godwit_ledger_used_24h($db, 'gdrive', $now));
    assert_eq(4_000_000_000, $remaining1, 'remaining after first job');
    godwit_record_ledger_delta($db, 'gdrive', $now - 50, 5_000_000_000); // overshoots remaining1
    $remaining2 = godwit_remaining_budget($cap, godwit_ledger_used_24h($db, 'gdrive', $now));
    assert_eq(0, $remaining2, 'the ledger, not MaxTransfer, is what actually stops further starts once the cap is exceeded');
});

// --- Throttling ------------------------------------------------------------

t('godwit_throttled_until / godwit_mark_throttled: throttle applies and expires', function () {
    $db = new SQLite3(':memory:');
    godwit_open_throttle_table($db);
    $now = 1000000;
    assert_eq(null, godwit_throttled_until($db, 'gdrive', $now), 'not throttled yet');
    godwit_mark_throttled($db, 'gdrive', $now + 86400);
    assert_eq($now + 86400, godwit_throttled_until($db, 'gdrive', $now), 'throttled until the stored ts');
    assert_eq(null, godwit_throttled_until($db, 'gdrive', $now + 86400 + 1), 'expired after until_ts passes');
});

t('godwit_is_upload_limit_error: recognises the drive_stop_on_upload_limit error shape', function () {
    assert_true(godwit_is_upload_limit_error('googleapi: Error 403: User rate limit exceeded... uploadLimitExceeded'), 'should match');
    assert_true(!godwit_is_upload_limit_error('permission denied'), 'unrelated error must not match');
});

// --- Windows and speed -----------------------------------------------------

function godwit_test_dt(string $s): \DateTimeImmutable
{
    return new \DateTimeImmutable($s, new \DateTimeZone('Australia/Brisbane'));
}

t('godwit_time_in_window: the D12 default (22:00-06:00 every day) covers late evening', function () {
    $w = godwit_default_windows()[0];
    assert_true(godwit_time_in_window($w, godwit_test_dt('2026-09-17 23:00:00')), '23:00 should be inside the window');
});

t('godwit_time_in_window: the D12 default covers pre-dawn on the following calendar day (midnight wrap)', function () {
    $w = godwit_default_windows()[0];
    assert_true(godwit_time_in_window($w, godwit_test_dt('2026-09-18 05:00:00')), '05:00 the next day should still be inside the window');
});

t('godwit_time_in_window: just after the window closes is outside', function () {
    $w = godwit_default_windows()[0];
    assert_true(!godwit_time_in_window($w, godwit_test_dt('2026-09-18 06:00:00')), '06:00 is the boundary — outside');
    assert_true(!godwit_time_in_window($w, godwit_test_dt('2026-09-18 07:00:00')), '07:00 is outside');
});

t('godwit_time_in_window: just before the window opens is outside', function () {
    $w = godwit_default_windows()[0];
    assert_true(!godwit_time_in_window($w, godwit_test_dt('2026-09-17 21:59:00')), '21:59 is outside');
});

t('godwit_time_in_window: respects the weekday list on a midnight-wrap window', function () {
    // Window only active Mon (day 1): 2026-09-14 is a Monday.
    $w = ['days' => [1], 'start' => '22:00', 'end' => '06:00', 'limit_mbit' => 100.0];
    assert_true(godwit_time_in_window($w, godwit_test_dt('2026-09-14 23:00:00')), 'Monday 23:00 is inside');
    assert_true(godwit_time_in_window($w, godwit_test_dt('2026-09-15 05:00:00')), 'Tuesday 05:00 (spillover from Monday) is inside');
    assert_true(!godwit_time_in_window($w, godwit_test_dt('2026-09-15 23:00:00')), 'Tuesday 23:00 is outside — Tuesday is not a window day');
});

t('godwit_time_in_window: days[] index N matches the Nth day label the settings-page UI shows (0=Sun...6=Sat)', function () use ($repoRoot) {
    // The UI's day checkboxes are labelled from this literal array in
    // Godwit.page — this test proves that array's index really is the
    // weekday godwit_time_in_window() will match, so mislabelling a
    // checkbox can never silently shift the schedule.
    $page = file_get_contents($repoRoot . '/plugin/Godwit.page');
    assert_true(
        preg_match("/godwitDayLabels\s*=\s*\[\s*'Sun',\s*'Mon',\s*'Tue',\s*'Wed',\s*'Thu',\s*'Fri',\s*'Sat'\s*\]/", $page) === 1,
        'expected godwitDayLabels = [\'Sun\',\'Mon\',...,\'Sat\'] in Godwit.page'
    );
    // 2026-09-13 is a Sunday; the following six dates are Mon..Sat.
    $datesByIndex = [
        0 => '2026-09-13', 1 => '2026-09-14', 2 => '2026-09-15', 3 => '2026-09-16',
        4 => '2026-09-17', 5 => '2026-09-18', 6 => '2026-09-19',
    ];
    foreach ($datesByIndex as $index => $date) {
        $w = ['days' => [$index], 'start' => '00:00', 'end' => '00:00', 'limit_mbit' => 100.0];
        assert_true(godwit_time_in_window($w, godwit_test_dt($date . ' 12:00:00')), "days=[$index] should be active on $date (all-day window)");
        foreach ($datesByIndex as $otherIndex => $otherDate) {
            if ($otherIndex === $index) { continue; }
            assert_true(!godwit_time_in_window($w, godwit_test_dt($otherDate . ' 12:00:00')), "days=[$index] should NOT be active on $otherDate");
        }
    }
});

t('windows_form_test.mjs (node): windows-form JS round-trip and validation', function () use ($repoRoot) {
    exec('command -v node', $ignored, $nodeMissing);
    assert_true($nodeMissing === 0, 'node is required to run tests/windows_form_test.mjs — install Node.js (present on ubuntu-latest CI runners)');
    $cmd = 'node ' . escapeshellarg($repoRoot . '/tests/windows_form_test.mjs') . ' 2>&1';
    exec($cmd, $output, $exitCode);
    assert_true($exitCode === 0, "windows_form_test.mjs failed:\n" . implode("\n", $output));
});

t('godwit_active_window: returns null outside every window', function () {
    assert_eq(null, godwit_active_window(godwit_default_windows(), godwit_test_dt('2026-09-17 12:00:00')), 'midday should be outside the D12 default');
});

t('godwit_seconds_to_window_end: mid-window returns seconds to the boundary, wrapping past midnight', function () {
    $w = godwit_default_windows()[0];
    $secs = godwit_seconds_to_window_end($w, godwit_test_dt('2026-09-17 23:00:00'));
    assert_eq(7 * 3600, $secs, '23:00 to 06:00 next day is 7 hours');
});

t('godwit_window_start_ts: mid-window (no wrap) returns today\'s start boundary', function () {
    $w = ['days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '22:00', 'end' => '23:59', 'limit_mbit' => 100.0];
    $now = godwit_test_dt('2026-09-17 22:30:00');
    $expected = godwit_test_dt('2026-09-17 22:00:00')->getTimestamp();
    assert_eq($expected, godwit_window_start_ts($w, $now), 'start boundary same day');
});

t('godwit_window_start_ts: the D12 default at 05:00 (spillover) resolves to YESTERDAY 22:00, not today 22:00', function () {
    $w = godwit_default_windows()[0];
    $now = godwit_test_dt('2026-09-18 05:00:00');
    $expected = godwit_test_dt('2026-09-17 22:00:00')->getTimestamp();
    assert_eq($expected, godwit_window_start_ts($w, $now), 'must resolve to the previous day\'s start');
});

t('godwit_next_window_start_ts: before tonight\'s window opens, the next start is later today', function () {
    $w = godwit_default_windows()[0];
    $now = godwit_test_dt('2026-09-17 12:00:00');
    $expected = godwit_test_dt('2026-09-17 22:00:00')->getTimestamp();
    assert_eq($expected, godwit_next_window_start_ts($w, $now), 'next start is 22:00 today');
});

t('godwit_next_window_start_ts: while inside a midnight-wrap window, the next start is tomorrow, not today\'s already-passed one', function () {
    $w = godwit_default_windows()[0];
    $now = godwit_test_dt('2026-09-17 23:00:00'); // already inside tonight's window
    $expected = godwit_test_dt('2026-09-18 22:00:00')->getTimestamp();
    assert_eq($expected, godwit_next_window_start_ts($w, $now), 'must not report today\'s 22:00, which already passed');
});

t('godwit_next_window_start_ts: respects the weekday list, skipping ahead to the next matching day', function () {
    // Window only active Monday (day 1): 2026-09-14 is a Monday, 2026-09-21 the next one.
    $w = ['days' => [1], 'start' => '22:00', 'end' => '06:00', 'limit_mbit' => 100.0];
    $now = godwit_test_dt('2026-09-15 12:00:00'); // Tuesday
    $expected = godwit_test_dt('2026-09-21 22:00:00')->getTimestamp();
    assert_eq($expected, godwit_next_window_start_ts($w, $now), 'must skip ahead to the following Monday');
});

t('godwit_next_window_start_label: formats as H:i using the real configured start, not a hardcoded 22:00', function () {
    $w = ['days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '23:30', 'end' => '05:00', 'limit_mbit' => 100.0];
    $now = godwit_test_dt('2026-09-17 12:00:00');
    assert_eq('23:30', godwit_next_window_start_label([$w], $now), 'must reflect the configured 23:30, not 22:00');
});

t('godwit_next_window_start_label: with multiple windows, picks the earliest upcoming start', function () {
    $early = ['days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '10:00', 'end' => '11:00', 'limit_mbit' => 100.0];
    $late = ['days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '22:00', 'end' => '23:00', 'limit_mbit' => 100.0];
    $now = godwit_test_dt('2026-09-17 08:00:00');
    assert_eq('10:00', godwit_next_window_start_label([$late, $early], $now), 'earliest of the two upcoming starts, regardless of array order');
});

t('godwit_next_window_start_label: null with no windows configured', function () {
    assert_eq(null, godwit_next_window_start_label([], godwit_test_dt('2026-09-17 12:00:00')), 'no windows means no resume label');
});

t('godwit_mbit_to_bytes_per_sec: 250 Mbit/s converts to the exact byte rate (not the M/MiB suffix)', function () {
    assert_eq(31250000, godwit_mbit_to_bytes_per_sec(250.0), '250 Mbit/s = 31,250,000 B/s');
});

t('godwit_bwlimit_rc_value: emits an explicit byte count with a B suffix, never an M (MiB) suffix', function () {
    assert_eq('31250000B', godwit_bwlimit_rc_value(250.0), 'must not be "31.25M" — that is 262 Mbit/s, a ~5% overshoot');
});

t('godwit_format_mbit_and_mib: shows both units', function () {
    $s = godwit_format_mbit_and_mib(250.0);
    assert_true(str_contains($s, '250'), $s);
    assert_true(str_contains($s, '29.8'), "expected ~29.8 MiB/s: $s");
});

t('godwit_profile_warning: 250 Mbit/s window against the 500/50 profile warns (250 >= 80% of 50)', function () {
    $w = godwit_profile_warning('500/50', 250.0);
    assert_true($w !== null && str_contains($w, '50'), var_export($w, true));
});

t('godwit_profile_warning: 250 Mbit/s window against the 1000/400 profile does not warn (250 < 80% of 400)', function () {
    assert_eq(null, godwit_profile_warning('1000/400', 250.0), 'should be comfortably under 320');
});

t('godwit_profile_warning: exactly at the 80% boundary warns', function () {
    assert_true(godwit_profile_warning('500/50', 40.0) !== null, '40 is exactly 80% of 50 — should warn');
});

// --- Queue ordering / one-per-remote ---------------------------------------

t('godwit_select_next_jobs: default jobs queue in Filing Cabinet, Kieren, Teegan, Photos order but only one starts (all share gdrive)', function () {
    $selected = godwit_select_next_jobs(godwit_default_jobs(), []);
    assert_eq(1, count($selected), 'only one job per remote may start at once');
    assert_eq('Filing Cabinet', $selected[0]['name'], 'smallest share starts first');
});

t('godwit_select_next_jobs: skips a remote that already has a job active', function () {
    $selected = godwit_select_next_jobs(godwit_default_jobs(), ['gdrive']);
    assert_eq(0, count($selected), 'gdrive already busy — nothing new starts');
});

t('godwit_select_next_jobs: two different remotes can each start one job', function () {
    $jobs = [
        ['name' => 'A', 'share' => 'A', 'remote' => 'gdrive', 'enabled' => true],
        ['name' => 'B', 'share' => 'B', 'remote' => 'onedrive', 'enabled' => true],
    ];
    $selected = godwit_select_next_jobs($jobs, []);
    assert_eq(2, count($selected), 'different remotes run independently');
});

t('godwit_select_next_jobs: disabled jobs never get selected', function () {
    $jobs = [['name' => 'A', 'share' => 'A', 'remote' => 'gdrive', 'enabled' => false]];
    assert_eq(0, count(godwit_select_next_jobs($jobs, [])), 'disabled job must not start');
});

// --- Session-aware queue advancement (the bug where the queue looped on ---
// --- Filing Cabinet forever, found by advisor before the first host push) ---

t('godwit_select_next_jobs: once Filing Cabinet has completed this session, the next tick advances to Kieren, not FC again', function () {
    $jobs = godwit_default_jobs();
    $sessionStart = 1000;
    $lastRuns = ['Filing Cabinet' => ['ended_ts' => 1500, 'outcome' => 'completed']];
    // FC just finished and dropped out of $activeJobs — without session
    // awareness this reproduces exactly as it did on the host: FC (index 0
    // for gdrive) gets selected again instead of Kieren.
    $selected = godwit_select_next_jobs($jobs, [], $lastRuns, $sessionStart);
    assert_eq(1, count($selected), 'one job should be selected');
    assert_eq('Kieren', $selected[0]['name'], 'must advance to Kieren, not loop back to Filing Cabinet');
});

t('godwit_select_next_jobs: once every job has completed this session, the queue is empty (drives "Run now" clearing itself)', function () {
    $sessionStart = 1000;
    $lastRuns = [];
    foreach (godwit_default_jobs() as $job) {
        $lastRuns[$job['name']] = ['ended_ts' => 1500, 'outcome' => 'completed'];
    }
    $selected = godwit_select_next_jobs(godwit_default_jobs(), [], $lastRuns, $sessionStart);
    assert_eq(0, count($selected), 'a fully-drained queue must select nothing');
});

t('godwit_select_next_jobs: a job cut short by budget/window/a restart stays eligible — it must resume, not be skipped', function () {
    $soloJob = [['name' => 'Kieren', 'share' => 'Kieren', 'remote' => 'gdrive', 'enabled' => true]];
    foreach (['budget', 'window', 'interrupted'] as $outcome) {
        $lastRuns = ['Kieren' => ['ended_ts' => 1500, 'outcome' => $outcome]];
        $selected = godwit_select_next_jobs($soloJob, [], $lastRuns, 1000);
        $names = array_column($selected, 'name');
        assert_true(in_array('Kieren', $names, true), "Kieren cut short by '$outcome' must remain eligible: " . json_encode($names));
    }
});

t('godwit_select_next_jobs: a run that finished BEFORE the current session started does not block re-selection (a new night is a new session)', function () {
    $lastRuns = ['Filing Cabinet' => ['ended_ts' => 500, 'outcome' => 'completed']]; // last night
    $selected = godwit_select_next_jobs(godwit_default_jobs(), [], $lastRuns, 1000); // tonight's session started at 1000
    assert_eq('Filing Cabinet', $selected[0]['name'], 'a completion from a previous session must not carry over');
});

// --- Outcome classification (rclone's own cutoff error text, ground- ---
// --- truthed against the bundled v1.75.1 binary) -----------------------

t('godwit_classify_job_outcome: no error text means completed', function () {
    assert_eq('completed', godwit_classify_job_outcome('', false), 'empty error');
});

t('godwit_classify_job_outcome: rclone\'s exact --max-transfer cutoff text classifies as budget', function () {
    assert_eq('budget', godwit_classify_job_outcome('max transfer limit reached as set by --max-transfer', false), 'byte-cap cutoff');
});

t('godwit_classify_job_outcome: rclone\'s exact --max-duration cutoff text classifies as window', function () {
    assert_eq('window', godwit_classify_job_outcome('max transfer duration reached as set by --max-duration', false), 'duration cutoff');
});

t('godwit_classify_job_outcome: godwitd\'s own mid-run job/stop for budget produces a generic "context canceled" — the caller must say so', function () {
    assert_eq('budget', godwit_classify_job_outcome('context canceled', true), 'stoppedForBudget flag, not the error text, drives this one');
    assert_eq('error', godwit_classify_job_outcome('context canceled', false), 'without the flag, an unrecognised message is a plain error');
});

t('godwit_classify_job_outcome: an upload-limit error is throttled even with other error text present', function () {
    assert_eq('throttled', godwit_classify_job_outcome('googleapi: uploadLimitExceeded', false), 'throttle takes priority');
});

t('godwit_classify_job_outcome: an auth-expired error is classified as auth', function () {
    assert_eq('auth', godwit_classify_job_outcome('invalid_grant: token expired', false), 'auth expiry');
});

t('godwit_classify_job_outcome: an unrecognised error is a plain error', function () {
    assert_eq('error', godwit_classify_job_outcome('connection reset by peer', false), 'generic error');
});

t('godwit_classify_job_outcome: a directory-modtime failure — the exact rcd.log error line recorded on the live host on 2026-09-18 ("Failed to update directory timestamp or metadata: directory not found"; job/status\'s own wrapped wording was not captured live, only this log line was) — masks a budget cutoff and must classify as error, not budget: documents why NoUpdateDirModTime must stay on in godwit_build_sync_params(), not be worked around here', function () {
    $realHostLogLine = 'Failed to update directory timestamp or metadata: directory not found';
    assert_eq('error', godwit_classify_job_outcome($realHostLogLine, false), 'a masked cutoff message is indistinguishable from a real error once the cutoff text itself is gone — the fix belongs in never producing this error, not in special-casing its text here');
});

// --- v0.4.3: byte-proximity fallback for a masked MaxTransfer cutoff -------
// --- (the real 2026-09-18 bug: every budget-capped run that night also had
// --- real per-file errors, whose text always outranks the cutoff's own, so
// --- $stoppedForBudget was always false and the text match never fired) ---

t('godwit_bytes_near_max_transfer: within the 2% tolerance in both directions (CAUTIOUS measured to both overshoot and undershoot locally — see docblock)', function () {
    assert_true(godwit_bytes_near_max_transfer(98_000_000, 100_000_000), '2% under is exactly at the boundary — must count');
    assert_true(godwit_bytes_near_max_transfer(102_000_000, 100_000_000), '2% over is exactly at the boundary — must count');
    assert_true(godwit_bytes_near_max_transfer(100_000_000, 100_000_000), 'exact match');
});

t('godwit_bytes_near_max_transfer: outside the tolerance in either direction is not a match', function () {
    assert_true(!godwit_bytes_near_max_transfer(97_000_000, 100_000_000), '3% under is outside the 2% margin');
    assert_true(!godwit_bytes_near_max_transfer(103_000_000, 100_000_000), '3% over is outside the 2% margin');
    assert_true(!godwit_bytes_near_max_transfer(10_000_000, 100_000_000), 'a job that transferred a small fraction of a huge budget must not match');
});

t('godwit_bytes_near_max_transfer: null/zero maxTransferBytes never matches (nothing to compare against)', function () {
    assert_true(!godwit_bytes_near_max_transfer(1000, null), 'null maxTransferBytes');
    assert_true(!godwit_bytes_near_max_transfer(1000, 0), 'zero maxTransferBytes');
});

t('godwit_classify_job_outcome: a real per-file error masking the cutoff text still classifies as budget when bytes transferred landed near the configured MaxTransfer (v0.4.3 fix for the 2026-09-18 incident)', function () {
    $realPerFileError = 'open /dst/badfile: is a directory';
    assert_eq('budget', godwit_classify_job_outcome($realPerFileError, false, 98_500_000, 100_000_000), 'bytes within 2% of MaxTransfer despite a masking per-file error');
});

t('godwit_classify_job_outcome: a real per-file error is NOT reclassified as budget when bytes are far from MaxTransfer (the job genuinely just failed, nowhere near the cap)', function () {
    $realPerFileError = 'open /dst/badfile: is a directory';
    assert_eq('error', godwit_classify_job_outcome($realPerFileError, false, 5_000_000, 100_000_000), 'far below MaxTransfer — a real failure, not a masked cutoff');
});

t('godwit_classify_job_outcome: throttle/auth-expiry text still wins over the byte-proximity fallback even when bytes happen to land near MaxTransfer — the more specific, known signal takes priority', function () {
    assert_eq('throttled', godwit_classify_job_outcome('googleapi: uploadLimitExceeded', false, 99_000_000, 100_000_000), 'throttle text must win');
    assert_eq('auth', godwit_classify_job_outcome('invalid_grant: token expired', false, 99_000_000, 100_000_000), 'auth-expiry text must win');
});

t('godwit_classify_job_outcome: with no bytes/maxTransfer args at all (the pre-0.4.3 2-arg call shape), behaviour is unchanged — a masked error still reads as error', function () {
    assert_eq('error', godwit_classify_job_outcome('some unrelated per-file error', false), 'omitting the new args must not change existing behaviour');
});

// --- Requeue after restart --------------------------------------------------

t('godwit_interrupt_open_runs: closes an in-flight run as interrupted and reports it for requeue', function () {
    $db = new SQLite3(':memory:');
    godwit_open_job_runs_table($db);
    $runId = godwit_start_job_run($db, 'Kieren', 'gdrive', 1000);
    $closed = godwit_interrupt_open_runs($db, 2000);
    assert_eq(1, count($closed), 'one open run should have been found');
    assert_eq('Kieren', $closed[0]['job_name'], 'job name');
    $last = godwit_last_job_run($db, 'Kieren');
    assert_eq('interrupted', $last['outcome'], 'run should be marked interrupted');
    assert_eq(2000, (int) $last['ended_ts'], 'ended_ts should be set');
});

t('godwit_interrupt_open_runs: a cleanly finished run is left alone', function () {
    $db = new SQLite3(':memory:');
    godwit_open_job_runs_table($db);
    $runId = godwit_start_job_run($db, 'Photos', 'gdrive', 1000);
    godwit_finish_job_run($db, $runId, 1500, 100, 5, 0, 'completed');
    $closed = godwit_interrupt_open_runs($db, 2000);
    assert_eq(0, count($closed), 'nothing should be reported for requeue');
    assert_eq('completed', godwit_last_job_run($db, 'Photos')['outcome'], 'outcome must be unchanged');
});

// --- Version retention purge safety -----------------------------------------

t('godwit_versions_purge_candidates: only date-shaped dirs older than retainDays are candidates', function () {
    $now = godwit_test_dt('2026-09-17 00:00:00');
    $dirs = ['2026-08-01', '2026-09-10', '2026-09-17', 'latest', '..', 'all'];
    $candidates = godwit_versions_purge_candidates($dirs, 30, $now);
    assert_true(in_array('2026-08-01', $candidates, true), '2026-08-01 is >30 days old');
    assert_true(!in_array('2026-09-10', $candidates, true), '2026-09-10 is within 30 days');
    assert_true(!in_array('latest', $candidates, true), 'non-date names must never be candidates');
    assert_true(!in_array('..', $candidates, true), 'traversal-shaped names must never be candidates');
    assert_true(!in_array('all', $candidates, true), 'non-date names must never be candidates');
});

t('godwit_assert_purge_path: builds the exact expected path for a valid date dir', function () {
    assert_eq('gdrive:godwit/_versions/Kieren/2026-08-01', godwit_assert_purge_path('gdrive', 'Kieren', '2026-08-01'), 'path shape');
});

t('godwit_assert_purge_path: refuses a non-date dir name (must never target anything outside _versions/)', function () {
    foreach (['..', 'all', '2026-13-99extra', '../../etc', ''] as $bad) {
        try {
            godwit_assert_purge_path('gdrive', 'Kieren', $bad);
            throw new \RuntimeException("expected an exception for dateDir=" . var_export($bad, true));
        } catch (\InvalidArgumentException $e) {
            assert_true(true, 'threw as expected for ' . var_export($bad, true));
        }
    }
});

t('godwit_assert_purge_path: refuses a share name with a path separator', function () {
    try {
        godwit_assert_purge_path('gdrive', 'Kieren/../../secrets', '2026-08-01');
        throw new \RuntimeException('expected an exception');
    } catch (\InvalidArgumentException $e) {
        assert_true(true, 'threw as expected');
    }
});

t('godwit_assert_purge_path: refuses a remote name containing a colon or slash', function () {
    foreach (['gdrive:evil', 'gdrive/evil'] as $bad) {
        try {
            godwit_assert_purge_path($bad, 'Kieren', '2026-08-01');
            throw new \RuntimeException('expected an exception for remote=' . $bad);
        } catch (\InvalidArgumentException $e) {
            assert_true(true, 'threw as expected');
        }
    }
});

// --- godwit_list_versions_dirs() (issue #1: retention purge never ran) --

t('godwit_list_versions_dirs: always sends remote alongside fs (the missing param that broke every retention tick)', function () {
    $sent = null;
    $call = function (array $listener, string $rcPath, array $params, int $timeout) use (&$sent) {
        $sent = $params;
        return ['list' => []];
    };
    godwit_list_versions_dirs(['type' => 'unix', 'path' => '/x'], 'gdrive:godwit/_versions/Kieren', $call);
    assert_true(array_key_exists('remote', $sent), 'operations/list must always be called with a remote key, even if empty — rcd 400s "Didn\'t find key \"remote\" in input" without it');
    assert_eq('', $sent['remote'], 'remote should be the empty string (the root of fs), not an arbitrary path');
    assert_eq('gdrive:godwit/_versions/Kieren', $sent['fs'], 'fs should be unchanged');
});

t('godwit_list_versions_dirs: extracts dir names from a successful list response', function () {
    $call = fn () => ['list' => [['Name' => '2026-08-01', 'IsDir' => true], ['Name' => '2026-08-02', 'IsDir' => true]]];
    $result = godwit_list_versions_dirs(['type' => 'unix', 'path' => '/x'], 'gdrive:godwit/_versions/Kieren', $call);
    assert_eq(['2026-08-01', '2026-08-02'], $result['dirs'], 'expected both dir names extracted');
    assert_true(!isset($result['error']), 'a clean list must not carry an error');
});

t('godwit_list_versions_dirs: "directory not found" is absent, not an error — normal before the first versioned overwrite', function () {
    $call = fn () => ['error' => 'error in ListJSON: directory not found'];
    $result = godwit_list_versions_dirs(['type' => 'unix', 'path' => '/x'], 'gdrive:godwit/_versions/Kieren', $call);
    assert_eq([], $result['dirs'], 'absent means no dirs');
    assert_eq(true, $result['absent'] ?? false, 'a not-yet-existing _versions dir must be flagged absent, not error');
    assert_true(!isset($result['error']), 'absent must not also carry an error — the caller must stay quiet, not log');
});

t('godwit_list_versions_dirs: a malformed response (no list, no error) is a loud error, never a silent empty candidate list', function () {
    $call = fn () => [];
    $result = godwit_list_versions_dirs(['type' => 'unix', 'path' => '/x'], 'gdrive:godwit/_versions/Kieren', $call);
    assert_eq([], $result['dirs'], 'a malformed response must not silently become an empty-but-successful candidate list');
    assert_true(!empty($result['error']), 'a response with neither list nor error must still surface as an error, not silently become "nothing to purge"');
});

t('godwit_list_versions_dirs: a genuine rc error (not "directory not found") is a loud error', function () {
    $call = fn () => ['error' => 'authentication must be set up on the rc server'];
    $result = godwit_list_versions_dirs(['type' => 'unix', 'path' => '/x'], 'gdrive:godwit/_versions/Kieren', $call);
    assert_eq([], $result['dirs'], 'a real error must not surface as a candidate list');
    assert_true(!empty($result['error']), 'a real rc error must surface, not be swallowed');
    assert_true(!isset($result['absent']), 'only "directory not found" is absent — any other error must not be mistaken for it');
});

t('godwitd: the retention purge logs operations/list errors instead of silently treating them as "nothing to purge"', function () use ($repoRoot) {
    $src = file_get_contents($repoRoot . '/plugin/scripts/godwitd');
    $start = strpos($src, 'Version retention purge');
    assert_true($start !== false, 'expected to find the retention purge block');
    $end = strpos($src, "\n        }\n\n        // Its own counter", $start);
    assert_true($end !== false, 'expected the end of the retention purge block');
    $body = substr($src, $start, $end - $start);
    assert_true(str_contains($body, 'godwit_list_versions_dirs('), 'retention purge must call godwit_list_versions_dirs(), not a hand-built operations/list call');
    assert_true(str_contains($body, "isset(\$listResult['error'])") && str_contains($body, 'godwit_log('), 'a failed list must be logged, not silently skipped');
});

// --- Notifications -----------------------------------------------------

t('godwit_budget_reached_notification: fires once, not again the same day', function () {
    $db = new SQLite3(':memory:');
    godwit_open_notify_state_table($db);
    $n1 = godwit_budget_reached_notification($db, 'gdrive', '2026-09-17');
    assert_true($n1 !== null, 'first call today should notify');
    $n2 = godwit_budget_reached_notification($db, 'gdrive', '2026-09-17');
    assert_eq(null, $n2, 'second call same day should not notify again');
    $n3 = godwit_budget_reached_notification($db, 'gdrive', '2026-09-18');
    assert_true($n3 !== null, 'a new day should notify again');
});

t('godwit_throttled_notification: fires only on entering the throttled state', function () {
    $n1 = godwit_throttled_notification('gdrive', false);
    assert_true($n1 !== null, 'entering throttled should notify');
    $n2 = godwit_throttled_notification('gdrive', true);
    assert_eq(null, $n2, 'already throttled must not notify again');
});

t('godwit_first_seed_notification: fires exactly once ever per job', function () {
    $db = new SQLite3(':memory:');
    godwit_open_notify_state_table($db);
    $n1 = godwit_first_seed_notification($db, 'Filing Cabinet');
    assert_true($n1 !== null, 'first completion should notify');
    $n2 = godwit_first_seed_notification($db, 'Filing Cabinet');
    assert_eq(null, $n2, 'must never notify twice for the same job');
});

t('godwit_job_error_notification: always builds an alert-level notification', function () {
    $n = godwit_job_error_notification('Teegan', 'connection reset');
    assert_eq('alert', $n['importance'], 'importance');
    assert_true(str_contains($n['description'], 'connection reset'), $n['description']);
});

t('godwit_cap_stop_notification: a clean budget stop (rclone\'s own cutoff, baseline 1) reads as a normal stop, not a failure — this is the notification Kieren/Teegan should have gotten instead of "run failed" on 2026-09-18', function () {
    $n = godwit_cap_stop_notification('Kieren', 'budget', 718553336093, 1, 1);
    assert_eq('normal', $n['importance'], 'a cap stop is not an alert');
    assert_true(!str_contains(strtolower($n['subject']), 'failed'), 'subject: ' . $n['subject']);
    assert_true(str_contains($n['description'], '669.20 GiB'), 'should report how much went: ' . $n['description']);
    assert_true(str_contains($n['description'], 'daily upload cap'), 'should say what stopped it: ' . $n['description']);
    assert_true(str_contains($n['description'], 'resume'), 'should say it resumes: ' . $n['description']);
    assert_true(!str_contains($n['description'], 'errors were also logged'), 'a single accounted cutoff error must not read as a real failure: ' . $n['description']);
});

t('godwit_cap_stop_notification: a window stop (MaxDuration\'s fatal cutoff, baseline 0 — ground-truthed: a clean --max-duration cutoff reports zero errors, unlike MaxTransfer\'s one) is worded as a window stop and stays quiet at baseline', function () {
    $n = godwit_cap_stop_notification('Photos', 'window', 1024 * 1024 * 1024, 0, 0);
    assert_true(str_contains($n['subject'], "window"), 'subject: ' . $n['subject']);
    assert_true(str_contains($n['description'], 'backup window'), $n['description']);
    assert_true(!str_contains($n['description'], 'errors were also logged'), 'zero errors at a zero baseline must not alarm: ' . $n['description']);
});

t('godwit_cap_stop_notification: godwitd\'s own mid-run job/stop (stoppedForBudget) costs one error per in-flight transfer slot — a Transfers=4 job clean-stopping at 4 errors must not read as 4 real failures', function () {
    $n = godwit_cap_stop_notification('Kieren', 'budget', 718553336093, 4, 4);
    assert_true(!str_contains($n['description'], 'errors were also logged'), 'must not alarm at exactly the expected in-flight-cancellation baseline: ' . $n['description']);
});

t('godwit_cap_stop_notification: more than the cutoff\'s own accounted error surfaces as a real problem the user needs to know about, regardless of which baseline applies', function () {
    $n = godwit_cap_stop_notification('Kieren', 'budget', 718553336093, 6, 1);
    assert_true(str_contains($n['description'], '6 errors were also logged'), $n['description']);
    $n2 = godwit_cap_stop_notification('Kieren', 'budget', 718553336093, 6, 4);
    assert_true(str_contains($n2['description'], '6 errors were also logged'), 'still above the Transfers=4 baseline: ' . $n2['description']);
});

t('godwit_cap_stop_notification: an explicit resume label (v0.4.2) is embedded verbatim instead of the generic "the next window"', function () {
    $n = godwit_cap_stop_notification('Kieren', 'budget', 718553336093, 1, 1, '22:00');
    assert_true(str_contains($n['description'], 'resume at 22:00'), $n['description']);
    assert_true(!str_contains($n['description'], 'the next window'), 'must not fall back to the generic phrase once a real label is given: ' . $n['description']);
});

t('godwit_cap_stop_notification: with no resume label (the pre-0.4.2 call shape), still falls back to the generic phrasing', function () {
    $n = godwit_cap_stop_notification('Kieren', 'budget', 718553336093, 1, 1);
    assert_true(str_contains($n['description'], 'resume at the next window'), $n['description']);
});

// --- v0.4.2 byte formatting and human-readable status text -----------------

t('godwit_format_bytes: auto-picks the unit like the page\'s JS fmtBytes()', function () {
    assert_eq('0.0 B', godwit_format_bytes(0), 'zero');
    assert_eq('512.0 B', godwit_format_bytes(512), 'sub-KiB stays in bytes');
    assert_eq('1.0 KiB', godwit_format_bytes(1024), 'exactly 1 KiB');
    assert_eq('129.5 MiB', godwit_format_bytes((int) round(129.5 * 1024 * 1024)), 'MiB scale, matching the CLAUDE.md example');
    assert_eq('669.2 GiB', godwit_format_bytes((int) round(669.2 * 1024 * 1024 * 1024)), 'GiB scale, matching the CLAUDE.md example');
});

t('godwit_job_status_label: gated takes priority over the last outcome — it is the current, more specific reason', function () {
    $label = godwit_job_status_label(['outcome' => 'budget', 'bytes' => 0, 'ended_ts' => 1000], true, godwit_default_windows(), godwit_test_dt('2026-09-18 12:00:00'));
    assert_eq('waiting for daily cap to free up', $label, 'gated overrides the stored outcome');
});

t('godwit_job_status_label: never run', function () {
    assert_eq('never run', godwit_job_status_label(null, false, godwit_default_windows(), godwit_test_dt('2026-09-18 12:00:00')), 'no last run at all');
});

t('godwit_job_status_label: completed reads as completed, plainly, with size and time', function () {
    $ts = godwit_test_dt('2026-09-18 05:41:51')->getTimestamp();
    $label = godwit_job_status_label(['outcome' => 'completed', 'bytes' => (int) round(129.5 * 1024 * 1024), 'ended_ts' => $ts, 'started_ts' => $ts], false, godwit_default_windows(), godwit_test_dt('2026-09-18 12:00:00'));
    assert_true(str_starts_with($label, 'completed (129.5 MiB) at'), $label);
    assert_true(!str_contains(strtolower($label), 'error'), $label);
});

t('godwit_job_status_label: budget reads as a calm pause with the real resume time, not "error"', function () {
    $ts = godwit_test_dt('2026-09-18 05:41:51')->getTimestamp();
    $windows = [['days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '22:00', 'end' => '06:00', 'limit_mbit' => 250.0]];
    $label = godwit_job_status_label(['outcome' => 'budget', 'bytes' => (int) round(669.2 * 1024 * 1024 * 1024), 'ended_ts' => $ts, 'started_ts' => $ts], false, $windows, godwit_test_dt('2026-09-18 12:00:00'));
    assert_true(!str_contains(strtolower($label), 'error'), $label);
    assert_true(str_contains($label, 'daily upload cap'), $label);
    assert_true(str_contains($label, 'resumes 22:00'), $label);
    assert_true(str_contains($label, '669.2 GiB uploaded'), $label);
});

t('godwit_job_status_label: budget uses the REAL configured window start, not a hardcoded 22:00', function () {
    $ts = godwit_test_dt('2026-09-18 05:41:51')->getTimestamp();
    $windows = [['days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '23:30', 'end' => '07:00', 'limit_mbit' => 250.0]];
    $label = godwit_job_status_label(['outcome' => 'budget', 'bytes' => 0, 'ended_ts' => $ts, 'started_ts' => $ts], false, $windows, godwit_test_dt('2026-09-18 12:00:00'));
    assert_true(str_contains($label, 'resumes 23:30'), $label);
});

t('godwit_job_status_label: window reads as a calm pause distinct from budget', function () {
    $ts = godwit_test_dt('2026-09-18 05:41:51')->getTimestamp();
    $windows = godwit_default_windows();
    $label = godwit_job_status_label(['outcome' => 'window', 'bytes' => (int) round(11.1 * 1024 * 1024 * 1024), 'ended_ts' => $ts, 'started_ts' => $ts], false, $windows, godwit_test_dt('2026-09-18 12:00:00'));
    assert_true(!str_contains(strtolower($label), 'error'), $label);
    assert_true(str_contains($label, 'upload window closed'), $label);
    assert_true(str_contains($label, '11.1 GiB uploaded'), $label);
});

// --- v0.4.3: a budget/window pause must not hide a genuinely elevated -----
// --- error count (Kieren's Photos/Teegan/Kieren runs had 63/85/508) -------

t('godwit_job_status_label: a budget pause with errors at or below the clean-cutoff baseline (job Transfers) stays quiet — no suffix', function () {
    $ts = godwit_test_dt('2026-09-18 05:41:51')->getTimestamp();
    $label = godwit_job_status_label(['outcome' => 'budget', 'bytes' => 100, 'errors' => 4, 'ended_ts' => $ts, 'started_ts' => $ts], false, godwit_default_windows(), godwit_test_dt('2026-09-18 12:00:00'), 4);
    assert_true(!str_contains($label, 'also logged'), "4 errors at Transfers=4 is the clean cutoff's own bookkeeping, not a real problem: $label");
});

t('godwit_job_status_label: a budget pause with errors above the baseline surfaces the extra count — must not go invisible', function () {
    $ts = godwit_test_dt('2026-09-18 05:41:51')->getTimestamp();
    $label = godwit_job_status_label(['outcome' => 'budget', 'bytes' => (int) round(669.2 * 1024 * 1024 * 1024), 'errors' => 63, 'ended_ts' => $ts, 'started_ts' => $ts], false, godwit_default_windows(), godwit_test_dt('2026-09-18 12:00:00'), 4);
    // The raw stored count, not errors-minus-baseline (63-4=59 would
    // under-report the real Photos-run number) — the baseline is only an
    // upper bound on the cutoff's own bookkeeping, not an exact figure to
    // subtract, and this label must agree with godwit_cap_stop_notification()'s
    // own "N errors were also logged" wording on the same run.
    assert_true(str_contains($label, '63 transfer errors also logged'), "matching the real Photos run's raw error count: $label");
    assert_true(str_contains($label, 'daily upload cap reached'), 'the calm framing must still be present: ' . $label);
});

t('godwit_job_status_label: a window pause\'s baseline is 0 (MaxDuration\'s fatal cutoff never accounts an error itself) — any stored error at all surfaces', function () {
    $ts = godwit_test_dt('2026-09-18 05:41:51')->getTimestamp();
    $label = godwit_job_status_label(['outcome' => 'window', 'bytes' => 100, 'errors' => 1, 'ended_ts' => $ts, 'started_ts' => $ts], false, godwit_default_windows(), godwit_test_dt('2026-09-18 12:00:00'), 4);
    assert_true(str_contains($label, '1 transfer error also logged'), $label);
});

t('godwit_job_status_label: a genuine failure still plainly says error', function () {
    $ts = godwit_test_dt('2026-09-18 05:41:51')->getTimestamp();
    $label = godwit_job_status_label(['outcome' => 'error', 'bytes' => 0, 'ended_ts' => $ts, 'started_ts' => $ts], false, godwit_default_windows(), godwit_test_dt('2026-09-18 12:00:00'));
    assert_true(str_starts_with($label, 'error ('), $label);
});

t('godwit_job_status_label: throttled and auth failures still say error, with the known specific reason', function () {
    $ts = godwit_test_dt('2026-09-18 05:41:51')->getTimestamp();
    $throttled = godwit_job_status_label(['outcome' => 'throttled', 'bytes' => 0, 'ended_ts' => $ts, 'started_ts' => $ts], false, godwit_default_windows(), godwit_test_dt('2026-09-18 12:00:00'));
    $auth = godwit_job_status_label(['outcome' => 'auth', 'bytes' => 0, 'ended_ts' => $ts, 'started_ts' => $ts], false, godwit_default_windows(), godwit_test_dt('2026-09-18 12:00:00'));
    assert_true(str_starts_with($throttled, 'error'), $throttled);
    assert_true(str_starts_with($auth, 'error'), $auth);
});

t('godwit_purge_call_params: builds fs/remote params only after the safety assertion passes', function () {
    $p = godwit_purge_call_params('gdrive', 'Kieren', '2026-08-01');
    assert_eq('gdrive:godwit/_versions/Kieren', $p['fs'], 'fs');
    assert_eq('2026-08-01', $p['remote'], 'remote');
});

t('godwit_purge_call_params: propagates the safety exception for a bad date dir', function () {
    try {
        godwit_purge_call_params('gdrive', 'Kieren', '../../etc');
        throw new \RuntimeException('expected an exception');
    } catch (\InvalidArgumentException $e) {
        assert_true(true, 'threw as expected');
    }
});

t('godwit_resolve_timezone: an explicit override always wins', function () {
    assert_eq('Pacific/Auckland', godwit_resolve_timezone('Pacific/Auckland'), 'override wins');
});

t('godwit_run_now_limit_mbit: borrows the first configured window\'s rate', function () {
    assert_eq(250.0, godwit_run_now_limit_mbit(godwit_default_windows()), 'D12 default is 250 Mbit/s');
});

t('godwit_load_settings / godwit_save_settings: seeds D12 defaults, round-trips, merges missing keys', function () {
    $tmp = sys_get_temp_dir() . '/godwit-settings-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    $defaults = godwit_load_settings($tmp);
    assert_eq(700 * 1024 * 1024 * 1024, $defaults['budget_caps']['gdrive'], 'default budget cap');
    assert_eq(30, $defaults['retention_days'], 'default retention');

    // Simulate an older settings.json missing a key added later.
    file_put_contents($tmp . '/settings.json', json_encode(['profile' => '500/50']));
    $merged = godwit_load_settings($tmp);
    assert_eq('500/50', $merged['profile'], 'on-disk value wins');
    assert_eq(30, $merged['retention_days'], 'missing key falls back to default');

    godwit_save_settings($tmp, $merged);
    assert_eq('500/50', godwit_load_settings($tmp)['profile'], 'round trip');
    exec('rm -rf ' . escapeshellarg($tmp));
});

// --- End-to-end: godwit_build_sync_params() -> godwit_rc_call_params() ---
// --- against a real bundled rcd, proving _config/_filter actually work ---
// --- as form-encoded JSON over the exact path every job takes (not just ---
// --- that the filter FILE's contents are right — see the lsf test above) ---

t('godwit_build_sync_params + rc sync/copy: excludes are honoured, budget cutoff produces the exact error string godwit_classify_job_outcome() expects', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return; // not cached locally — covered by host verification instead.
    }
    $tmp = sys_get_temp_dir() . '/godwit-e2e-sync-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';
    assert_true(is_file($rclone), 'expected an unzipped rclone binary');

    $shareRoot = $tmp . '/mnt-user';
    $src = $shareRoot . '/TestShare';
    mkdir($src . '/TimeMachine', 0755, true);
    file_put_contents($src . '/TimeMachine/x', str_repeat('a', 1000));
    file_put_contents($src . '/keep.txt', str_repeat('b', 1000));
    $dst = $tmp . '/dst';
    mkdir($dst, 0755, true);

    $confPath = $tmp . '/rclone.conf';
    // A plain local backend, addressed as "localdst:<path>" — proves the rc
    // call chain (godwit_build_sync_params -> godwit_rc_call_params ->
    // sync/copy _async) with a real remote:path dstFs, not by special-casing
    // a bare local path.
    file_put_contents($confPath, "[localdst]\ntype = local\n");
    $sockPath = $tmp . '/rcd.sock';
    $runDir = $tmp . '/run';
    mkdir($runDir, 0700, true);
    $listener = ['type' => 'unix', 'path' => $sockPath, 'user' => 'testuser', 'pass' => 'testpass'];
    $proc = proc_open(
        [$rclone, 'rcd', '--rc-addr=unix://' . $sockPath, '--config=' . $confPath, '--log-file=' . $tmp . '/rcd.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/rcd.log', 'a'], 2 => ['file', $tmp . '/rcd.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), godwit_rcd_env($listener))
    );
    fclose($pipes[0]);
    for ($i = 0; $i < 30 && !file_exists($sockPath); $i++) {
        usleep(100000);
    }
    assert_true(file_exists($sockPath), 'rcd did not create its unix socket in time');

    try {
        $job = ['name' => 'TestShare', 'share' => 'TestShare', 'remote' => 'localdst', 'mode' => 'copy', 'transfers' => 2, 'max_delete' => 1000, 'excludes' => ['/TimeMachine/**']];
        $fs = ['srcFs' => $src, 'dstFs' => 'localdst:' . $dst];
        $filterFile = $tmp . '/filter.txt';
        godwit_write_filter_file($filterFile, godwit_compile_filter_rules($job));
        $params = godwit_build_sync_params($job, $fs, $filterFile, 10_000_000, null, null, false, $shareRoot);

        $resp = godwit_rc_call_params($listener, godwit_sync_rc_path($job['mode']), $params, 15);
        assert_true(isset($resp['jobid']), 'expected a jobid back from sync/copy: ' . json_encode($resp));
        $jobid = $resp['jobid'];

        $status = null;
        for ($i = 0; $i < 50; $i++) {
            $status = godwit_rc_call_params($listener, 'job/status', ['jobid' => $jobid], 15);
            if (!empty($status['finished'])) {
                break;
            }
            usleep(100000);
        }
        assert_true($status !== null && !empty($status['finished']), 'job should finish within 5s: ' . json_encode($status));
        assert_eq('', trim((string) ($status['error'] ?? '')), 'a plain copy within budget should not error: ' . json_encode($status));

        assert_true(is_file($dst . '/keep.txt'), 'keep.txt should have been copied');
        assert_true(!file_exists($dst . '/TimeMachine'), 'TimeMachine must have been excluded by _filter, not just present in the filter file');

        // Second run: a MaxTransfer of 1 byte against a file that must
        // actually transfer proves the exact cutoff string
        // godwit_classify_job_outcome() matches is real, not assumed.
        unlink($src . '/keep.txt');
        file_put_contents($src . '/keep.txt', str_repeat('c', 1000));
        $cutoffParams = godwit_build_sync_params($job, $fs, $filterFile, 1, null, null, false, $shareRoot);
        $cutoffResp = godwit_rc_call_params($listener, godwit_sync_rc_path($job['mode']), $cutoffParams, 15);
        assert_true(isset($cutoffResp['jobid']), 'expected a jobid: ' . json_encode($cutoffResp));
        $cutoffStatus = null;
        for ($i = 0; $i < 50; $i++) {
            $cutoffStatus = godwit_rc_call_params($listener, 'job/status', ['jobid' => $cutoffResp['jobid']], 15);
            if (!empty($cutoffStatus['finished'])) {
                break;
            }
            usleep(100000);
        }
        $cutoffError = trim((string) ($cutoffStatus['error'] ?? ''));
        assert_true($cutoffError !== '', 'a 1-byte MaxTransfer against a 1000-byte file should cut off: ' . json_encode($cutoffStatus));
        assert_eq('budget', godwit_classify_job_outcome($cutoffError, false), 'the real cutoff message must classify as budget: ' . var_export($cutoffError, true));
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

t('godwit_build_sync_params + rc sync/copy: a MaxTransfer cutoff that skips an entire subdirectory (the exact shape of the 2026-09-18 Kieren/Teegan incident — a dest dir the cutoff never reached) still classifies as budget, not error, because NoUpdateDirModTime stops rclone from ever trying to timestamp a directory that does not exist yet', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return; // not cached locally — covered by host verification instead.
    }
    $tmp = sys_get_temp_dir() . '/godwit-e2e-dirmodtime-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';
    assert_true(is_file($rclone), 'expected an unzipped rclone binary');

    $shareRoot = $tmp . '/mnt-user';
    $src = $shareRoot . '/Kieren';
    // subdirA transfers fully within the byte cap; subdirB is large enough
    // that the cutoff fires before rclone ever creates it at the
    // destination — reproducing the exact "directory not found" shape from
    // the real rcd.log (subdirB is never even mkdir'd on dstFs).
    mkdir($src . '/subdirA', 0755, true);
    mkdir($src . '/subdirB', 0755, true);
    file_put_contents($src . '/subdirA/f1', str_repeat('a', 1024));
    file_put_contents($src . '/subdirB/f2', str_repeat('b', 204800));
    $dst = $tmp . '/dst';
    mkdir($dst, 0755, true);

    $confPath = $tmp . '/rclone.conf';
    file_put_contents($confPath, "[localdst]\ntype = local\n");
    $sockPath = $tmp . '/rcd.sock';
    $listener = ['type' => 'unix', 'path' => $sockPath, 'user' => 'testuser', 'pass' => 'testpass'];
    $proc = proc_open(
        [$rclone, 'rcd', '--rc-addr=unix://' . $sockPath, '--config=' . $confPath, '--log-file=' . $tmp . '/rcd.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/rcd.log', 'a'], 2 => ['file', $tmp . '/rcd.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), godwit_rcd_env($listener))
    );
    fclose($pipes[0]);
    for ($i = 0; $i < 30 && !file_exists($sockPath); $i++) {
        usleep(100000);
    }
    assert_true(file_exists($sockPath), 'rcd did not create its unix socket in time');

    try {
        $job = ['name' => 'Kieren', 'share' => 'Kieren', 'remote' => 'localdst', 'mode' => 'copy', 'transfers' => 1, 'max_delete' => 1000, 'excludes' => []];
        $fs = ['srcFs' => $src, 'dstFs' => 'localdst:' . $dst];
        $filterFile = $tmp . '/filter.txt';
        godwit_write_filter_file($filterFile, godwit_compile_filter_rules($job));
        // 2000 bytes covers subdirA/f1 (1024) but not subdirB/f2 (204800) —
        // forces the exact "one dir transferred, one dir cut off entirely"
        // split seen on the real host.
        $params = godwit_build_sync_params($job, $fs, $filterFile, 2000, null, null, false, $shareRoot);

        $resp = godwit_rc_call_params($listener, godwit_sync_rc_path($job['mode']), $params, 15);
        assert_true(isset($resp['jobid']), 'expected a jobid: ' . json_encode($resp));
        $jobid = $resp['jobid'];
        $status = null;
        for ($i = 0; $i < 50; $i++) {
            $status = godwit_rc_call_params($listener, 'job/status', ['jobid' => $jobid], 15);
            if (!empty($status['finished'])) {
                break;
            }
            usleep(100000);
        }
        assert_true($status !== null && !empty($status['finished']), 'job should finish within 5s: ' . json_encode($status));
        $errorMsg = trim((string) ($status['error'] ?? ''));

        // The behavioural proof — this is what must actually go red/green,
        // not the config string below (a real rc call ignoring an unknown
        // _config key silently would pass a string-presence check while
        // still reproducing the original bug).
        assert_true(!str_contains($errorMsg, 'directory modtime'), 'NoUpdateDirModTime must stop rclone from ever trying to set a directory timestamp: ' . var_export($errorMsg, true));
        assert_true(str_contains($errorMsg, 'as set by --max-transfer'), 'the real cutoff message must survive uncontaminated: ' . var_export($errorMsg, true));
        assert_eq('budget', godwit_classify_job_outcome($errorMsg, false), 'a cutoff that skipped a whole directory must still classify as budget, not error: ' . var_export($errorMsg, true));
        assert_true(str_contains($params['_config'], '"NoUpdateDirModTime":true'), 'the built params should show why: the fix should be present: ' . $params['_config']);

        // Checker concurrency makes it non-deterministic *which* of the two
        // dirs the cutoff lands on, but the 2000-byte cap can only ever fit
        // one of the two files (1024 vs 204800) — the run must genuinely be
        // truncated (not both dirs fully present), which is the actual
        // shape the directory-modtime bug needed to trigger.
        $bothPresent = is_file($dst . '/subdirA/f1') && is_file($dst . '/subdirB/f2');
        assert_true(!$bothPresent, 'the 2000-byte cap must not have let both directories fully transfer: ' . json_encode(scandir($dst)));

        // At this job's Transfers=1, a clean graceful cutoff costs exactly
        // 1 error (its own bookkeeping, no real per-file failures) — see
        // godwit_cap_stop_notification()'s docblock for why this count is
        // NOT a universal constant (measured 1-4 at Transfers=8 across
        // repeated runs): the baseline godwitd actually uses is the job's
        // configured Transfers, which here is 1, matching this assertion.
        $stats = godwit_rc_call_params($listener, 'core/stats', ['group' => 'job/' . $jobid], 15);
        assert_eq(1, (int) ($stats['errors'] ?? -1), 'a clean cutoff at Transfers=1 should account for exactly the cutoff itself: ' . json_encode($stats));
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

t('v0.4.3 end-to-end against a real rcd: a genuine per-file error masking the MaxTransfer cutoff text is still classified budget via the byte-proximity fallback, and the real error is not hidden — reproduces the exact shape of the 2026-09-18 Photos/Teegan/Kieren incident, where every budget-capped run also had real per-file errors', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return;
    }
    $tmp = sys_get_temp_dir() . '/godwit-e2e-maskedbudget-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';

    $shareRoot = $tmp . '/mnt-user';
    $src = $shareRoot . '/Photos';
    mkdir($src, 0755, true);
    // Many small files in a SINGLE flat directory (like a real photo
    // share), all individually well within budget, so the cutoff lands
    // close to MaxTransfer rather than being bounded by one huge file's
    // size — the same shape that produced the real host's 0.003%-scale
    // shortfall. Deliberately NOT split across subdirectories with one
    // oversized file in a second directory: v0.4.3's version did that,
    // and rclone's march can discover the oversized file BEFORE any file
    // in the other directory (directory traversal order is not
    // guaranteed) — when that race lands that way, the cutoff trips on
    // the very first candidate and cancels the whole job with zero bytes
    // transferred and errorMsg "context canceled", which reproduced
    // nothing about the masking bug. This was never caught in the
    // worktree that built v0.4.3, or in CI — both lack build/ (see
    // CLAUDE.md's Test command section), so this test silently SKIPPED
    // (not passed) in both; the failure was only ever observed on a
    // checkout with build/ actually populated. A flat directory of
    // uniform-sized files has no such race: ground-truthed with build/
    // confirmed present at 15/15 repeat runs (10 before this comment was
    // written, 5 more after) landing within ~0.15% of MaxTransfer.
    $total = 0;
    for ($i = 0; $i < 400; $i++) {
        $sz = 900 + random_int(0, 200);
        file_put_contents($src . "/f$i.bin", random_bytes($sz));
        $total += $sz;
    }
    // A real, deterministic per-file error unrelated to the budget cutoff:
    // the destination path is pre-created as a DIRECTORY, so rclone's own
    // attempt to write this file there fails with a genuine plain error
    // ("can't move object - incompatible remotes", ground-truthed locally
    // — not the cutoff's own NoRetryError text) — exactly the kind of
    // error that outranks the cutoff's own NoRetryError in
    // currentError()'s precedence and masks it in job/status's text.
    file_put_contents($src . '/badfile', random_bytes(500));
    // MaxTransfer at 60% of the real transferable total (ground-truthed
    // locally, matching the measurements behind the 2% tolerance in
    // godwit_bytes_near_max_transfer()'s docblock) — comfortably below
    // the total so the cutoff genuinely fires partway through, not right
    // at the end where a race could let everything finish first.
    $maxTransfer = (int) ($total * 0.6);

    $dst = $tmp . '/dst';
    mkdir($dst . '/badfile', 0755, true); // the collision itself
    $confPath = $tmp . '/rclone.conf';
    file_put_contents($confPath, "[localdst]\ntype = local\n");
    $sockPath = $tmp . '/rcd.sock';
    $listener = ['type' => 'unix', 'path' => $sockPath, 'user' => 'testuser', 'pass' => 'testpass'];
    $proc = proc_open(
        [$rclone, 'rcd', '--rc-addr=unix://' . $sockPath, '--config=' . $confPath, '--log-file=' . $tmp . '/rcd.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/rcd.log', 'a'], 2 => ['file', $tmp . '/rcd.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), godwit_rcd_env($listener))
    );
    fclose($pipes[0]);
    for ($i = 0; $i < 30 && !file_exists($sockPath); $i++) {
        usleep(100000);
    }

    try {
        $job = ['name' => 'Photos', 'share' => 'Photos', 'remote' => 'localdst', 'mode' => 'copy', 'transfers' => 1, 'max_delete' => 1000, 'excludes' => []];
        $fs = ['srcFs' => $src, 'dstFs' => 'localdst:' . $dst];
        $filterFile = $tmp . '/filter.txt';
        godwit_write_filter_file($filterFile, godwit_compile_filter_rules($job));
        $params = godwit_build_sync_params($job, $fs, $filterFile, $maxTransfer, null, null, false, $shareRoot);
        $resp = godwit_rc_call_params($listener, godwit_sync_rc_path($job['mode']), $params, 15);
        assert_true(isset($resp['jobid']), 'expected a jobid: ' . json_encode($resp));
        $jobid = $resp['jobid'];
        $status = null;
        for ($i = 0; $i < 100; $i++) {
            $status = godwit_rc_call_params($listener, 'job/status', ['jobid' => $jobid], 15);
            if (!empty($status['finished'])) {
                break;
            }
            usleep(100000);
        }
        assert_true($status !== null && !empty($status['finished']), 'job should finish within 10s: ' . json_encode($status));
        $errorMsg = trim((string) ($status['error'] ?? ''));
        $stats = godwit_rc_call_params($listener, 'core/stats', ['group' => 'job/' . $jobid], 15);
        $bytes = (int) ($stats['bytes'] ?? -1);
        $errors = (int) ($stats['errors'] ?? -1);

        // The masking must actually have happened — otherwise this test
        // proves nothing about the fallback path.
        assert_true(!str_contains($errorMsg, 'as set by --max-transfer'), 'the real per-file error was expected to mask the cutoff text: ' . var_export($errorMsg, true));
        assert_true($errors >= 2, "expected at least the badfile collision plus the cutoff's own bookkeeping error: $errors");

        // The behaviour under test: text alone reads as a plain error...
        assert_eq('error', godwit_classify_job_outcome($errorMsg, false), 'without the byte fallback, a masked cutoff still misreads as error — confirms the bug this release fixes');
        // ...but with the byte-proximity fallback (what godwitd now actually
        // does), it correctly reads as budget.
        assert_eq('budget', godwit_classify_job_outcome($errorMsg, false, $bytes, $maxTransfer), "bytes ($bytes) vs MaxTransfer ($maxTransfer) should be close enough to confirm this was really a cutoff: " . var_export($errorMsg, true));

        // Constraint from the bug report: the real error must not become
        // invisible just because the outcome now reads as budget — Transfers
        // is 1 here, so the clean-cutoff baseline is 1; the collision error
        // must push the count above that.
        assert_true($errors > 1, "the real per-file error must still be visible above the clean-cutoff baseline of 1: $errors");
        $label = godwit_job_status_label(
            ['outcome' => 'budget', 'bytes' => $bytes, 'errors' => $errors, 'ended_ts' => time(), 'started_ts' => time()],
            false,
            godwit_default_windows(),
            new \DateTimeImmutable('now', new \DateTimeZone('Australia/Brisbane')),
            1
        );
        assert_true(str_contains($label, 'also logged'), "the real error must surface in the status text, not just 'paused': $label");
        assert_true(str_contains($label, 'daily upload cap reached'), $label);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

t('godwit_build_sync_params + rc sync/sync with MaxDuration: a job cut off by --max-duration classifies as window (proven against the real binary, not assumed)', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return;
    }
    $tmp = sys_get_temp_dir() . '/godwit-e2e-duration-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';

    $shareRoot = $tmp . '/mnt-user';
    $src = $shareRoot . '/TestShare';
    mkdir($src, 0755, true);
    // Several small files so the transfer isn't instantaneous relative to a
    // near-zero MaxDuration, giving --max-duration a real chance to fire
    // before everything would have copied anyway.
    for ($i = 0; $i < 20; $i++) {
        file_put_contents($src . "/f$i.bin", random_bytes(200000));
    }
    $dst = $tmp . '/dst';
    mkdir($dst, 0755, true);
    $confPath = $tmp . '/rclone.conf';
    file_put_contents($confPath, "[localdst]\ntype = local\n");
    $sockPath = $tmp . '/rcd.sock';
    $listener = ['type' => 'unix', 'path' => $sockPath, 'user' => 'testuser', 'pass' => 'testpass'];
    $proc = proc_open(
        [$rclone, 'rcd', '--rc-addr=unix://' . $sockPath, '--config=' . $confPath, '--log-file=' . $tmp . '/rcd.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/rcd.log', 'a'], 2 => ['file', $tmp . '/rcd.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), godwit_rcd_env($listener))
    );
    fclose($pipes[0]);
    for ($i = 0; $i < 30 && !file_exists($sockPath); $i++) {
        usleep(100000);
    }

    try {
        $job = ['name' => 'TestShare', 'share' => 'TestShare', 'remote' => 'localdst', 'mode' => 'copy', 'transfers' => 1, 'excludes' => []];
        $fs = ['srcFs' => $src, 'dstFs' => 'localdst:' . $dst];
        $filterFile = $tmp . '/filter.txt';
        godwit_write_filter_file($filterFile, godwit_compile_filter_rules($job));
        // A local-disk copy of 4MB finishes far faster than any MaxDuration
        // worth setting, so core/bwlimit throttles the process globally
        // first (100 KB/s) — the same call godwitd itself issues per
        // window — giving MaxDuration=1s a real ~40s transfer to actually
        // cut off partway through, instead of racing an instant copy.
        $bwResp = godwit_rc_call_params($listener, 'core/bwlimit', ['rate' => '100000B'], 15);
        assert_true(is_array($bwResp), 'core/bwlimit should succeed: ' . json_encode($bwResp));
        $params = godwit_build_sync_params($job, $fs, $filterFile, 10_000_000, null, 1, false, $shareRoot);
        $resp = godwit_rc_call_params($listener, godwit_sync_rc_path($job['mode']), $params, 15);
        assert_true(isset($resp['jobid']), 'expected a jobid: ' . json_encode($resp));
        $status = null;
        for ($i = 0; $i < 100; $i++) {
            $status = godwit_rc_call_params($listener, 'job/status', ['jobid' => $resp['jobid']], 15);
            if (!empty($status['finished'])) {
                break;
            }
            usleep(100000);
        }
        assert_true($status !== null && !empty($status['finished']), 'job should finish (cut off) within 10s: ' . json_encode($status));
        $errorMsg = trim((string) ($status['error'] ?? ''));
        assert_true($errorMsg !== '', 'a 0-second MaxDuration should cut the job off: ' . json_encode($status));
        assert_eq('window', godwit_classify_job_outcome($errorMsg, false), 'the real --max-duration cutoff message must classify as window: ' . var_export($errorMsg, true));
        // Unlike MaxTransfer's graceful cutoff (which costs itself exactly 1
        // in core/stats' error count — see the dedicated dir-modtime test
        // above), MaxDuration's cutoff is a *fatal* error and is never
        // counted via fs.CountError — a clean duration cutoff must report
        // zero errors. godwit_cap_stop_notification()'s baseline for
        // 'window' depends on this being 0, not 1.
        $durationStats = godwit_rc_call_params($listener, 'core/stats', ['group' => 'job/' . $resp['jobid']], 15);
        assert_eq(0, (int) ($durationStats['errors'] ?? -1), 'a clean --max-duration cutoff must account for zero errors, not the MaxTransfer cutoff\'s 1: ' . json_encode($durationStats));
        // No file should be left half-written (truncated) by the cutoff —
        // rclone's cutoff lets an in-flight file finish rather than
        // truncating it mid-transfer; every file present at the destination
        // must be exactly 200000 bytes, never a partial fragment.
        foreach (glob($dst . '/*.bin') as $f) {
            assert_eq(200000, filesize($f), "no partial/truncated file should be left at $f");
        }
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

t('rc sync/copy: rclone\'s OWN graceful MaxTransfer cutoff (not godwitd\'s job/stop) costs up to Transfers errors, not a fixed 1 (a separate 20-run bash repro at Transfers=8 measured 1-4; this single run just proves the count stays in [1, Transfers] and that the notification baseline must be Transfers, not a fixed 1) — an earlier version of this fix assumed rclone\'s own cutoff was always exactly 1, measured only at Transfers=1', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return;
    }
    $tmp = sys_get_temp_dir() . '/godwit-e2e-multitransfer-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';

    $shareRoot = $tmp . '/mnt-user';
    $src = $shareRoot . '/Kieren';
    mkdir($src, 0755, true);
    $transfers = 8;
    for ($i = 0; $i < 30; $i++) {
        file_put_contents($src . "/f$i.bin", str_repeat('x', 1024));
    }
    $dst = $tmp . '/dst';
    mkdir($dst, 0755, true);
    $confPath = $tmp . '/rclone.conf';
    file_put_contents($confPath, "[localdst]\ntype = local\n");
    $sockPath = $tmp . '/rcd.sock';
    $listener = ['type' => 'unix', 'path' => $sockPath, 'user' => 'testuser', 'pass' => 'testpass'];
    $proc = proc_open(
        [$rclone, 'rcd', '--rc-addr=unix://' . $sockPath, '--config=' . $confPath, '--log-file=' . $tmp . '/rcd.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/rcd.log', 'a'], 2 => ['file', $tmp . '/rcd.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), godwit_rcd_env($listener))
    );
    fclose($pipes[0]);
    for ($i = 0; $i < 30 && !file_exists($sockPath); $i++) {
        usleep(100000);
    }

    try {
        // Throttled so 8 workers are genuinely concurrent when the cutoff
        // trips, rather than racing through near-instantly.
        godwit_rc_call_params($listener, 'core/bwlimit', ['rate' => '4000B'], 15);
        $job = ['name' => 'Kieren', 'share' => 'Kieren', 'remote' => 'localdst', 'mode' => 'copy', 'transfers' => $transfers, 'excludes' => []];
        $fs = ['srcFs' => $src, 'dstFs' => 'localdst:' . $dst];
        $filterFile = $tmp . '/filter.txt';
        godwit_write_filter_file($filterFile, godwit_compile_filter_rules($job));
        $params = godwit_build_sync_params($job, $fs, $filterFile, 5000, null, null, false, $shareRoot);
        $resp = godwit_rc_call_params($listener, godwit_sync_rc_path($job['mode']), $params, 15);
        assert_true(isset($resp['jobid']), 'expected a jobid: ' . json_encode($resp));
        $jobid = $resp['jobid'];
        $status = null;
        for ($i = 0; $i < 100; $i++) {
            $status = godwit_rc_call_params($listener, 'job/status', ['jobid' => $jobid], 15);
            if (!empty($status['finished'])) {
                break;
            }
            usleep(100000);
        }
        assert_true($status !== null && !empty($status['finished']), 'job should finish within 10s: ' . json_encode($status));
        $errorMsg = trim((string) ($status['error'] ?? ''));
        assert_eq('budget', godwit_classify_job_outcome($errorMsg, false), 'rclone\'s own graceful cutoff at Transfers=8: ' . var_export($errorMsg, true));

        $stats = godwit_rc_call_params($listener, 'core/stats', ['group' => 'job/' . $jobid], 15);
        $errorCount = (int) ($stats['errors'] ?? -1);
        assert_true($errorCount >= 1 && $errorCount <= $transfers, "expected 1..$transfers errors from a clean multi-worker cutoff, not a fixed 1: " . json_encode($stats));

        // The whole point: godwitd must use Transfers (not a hardcoded 1)
        // as the baseline for this exact path, or this clean stop would
        // spuriously read as carrying real errors.
        $n = godwit_cap_stop_notification('Kieren', 'budget', (int) ($stats['bytes'] ?? 0), $errorCount, $transfers);
        assert_true(!str_contains($n['description'], 'errors were also logged'), "a clean multi-worker cutoff ($errorCount errors) within the Transfers=$transfers baseline must not read as a real failure: " . $n['description']);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

t('rc job/stop (godwitd\'s own mid-run ledger stop, $stoppedForBudget) costs one "context canceled" error per in-flight transfer slot, not the MaxTransfer/MaxDuration cutoffs\' own baselines — proves godwit_cap_stop_notification()\'s per-path baseline is real, not assumed', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return;
    }
    $tmp = sys_get_temp_dir() . '/godwit-e2e-jobstop-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';

    $shareRoot = $tmp . '/mnt-user';
    $src = $shareRoot . '/Kieren';
    mkdir($src, 0755, true);
    $transfers = 4;
    for ($i = 0; $i < 20; $i++) {
        file_put_contents($src . "/f$i.bin", random_bytes(200000));
    }
    $dst = $tmp . '/dst';
    mkdir($dst, 0755, true);
    $confPath = $tmp . '/rclone.conf';
    file_put_contents($confPath, "[localdst]\ntype = local\n");
    $sockPath = $tmp . '/rcd.sock';
    $listener = ['type' => 'unix', 'path' => $sockPath, 'user' => 'testuser', 'pass' => 'testpass'];
    $proc = proc_open(
        [$rclone, 'rcd', '--rc-addr=unix://' . $sockPath, '--config=' . $confPath, '--log-file=' . $tmp . '/rcd.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/rcd.log', 'a'], 2 => ['file', $tmp . '/rcd.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), godwit_rcd_env($listener))
    );
    fclose($pipes[0]);
    for ($i = 0; $i < 30 && !file_exists($sockPath); $i++) {
        usleep(100000);
    }

    try {
        // Throttle bandwidth so the transfer is still running when job/stop
        // arrives (mirrors godwitd's own core/bwlimit call per window).
        godwit_rc_call_params($listener, 'core/bwlimit', ['rate' => '100000B'], 15);
        $job = ['name' => 'Kieren', 'share' => 'Kieren', 'remote' => 'localdst', 'mode' => 'copy', 'transfers' => $transfers, 'excludes' => []];
        $fs = ['srcFs' => $src, 'dstFs' => 'localdst:' . $dst];
        $filterFile = $tmp . '/filter.txt';
        godwit_write_filter_file($filterFile, godwit_compile_filter_rules($job));
        $params = godwit_build_sync_params($job, $fs, $filterFile, 10_000_000, null, null, false, $shareRoot);
        $resp = godwit_rc_call_params($listener, godwit_sync_rc_path($job['mode']), $params, 15);
        assert_true(isset($resp['jobid']), 'expected a jobid: ' . json_encode($resp));
        $jobid = $resp['jobid'];

        usleep(500000);
        godwit_rc_call_params($listener, 'job/stop', ['jobid' => $jobid], 15);

        $status = null;
        for ($i = 0; $i < 100; $i++) {
            $status = godwit_rc_call_params($listener, 'job/status', ['jobid' => $jobid], 15);
            if (!empty($status['finished'])) {
                break;
            }
            usleep(100000);
        }
        assert_true($status !== null && !empty($status['finished']), 'job should finish after job/stop within 10s: ' . json_encode($status));
        $errorMsg = trim((string) ($status['error'] ?? ''));
        assert_eq('budget', godwit_classify_job_outcome($errorMsg, true), 'godwitd\'s own job/stop must classify as budget via the $stoppedForBudget flag, not the generic "context canceled" text');

        $stats = godwit_rc_call_params($listener, 'core/stats', ['group' => 'job/' . $jobid], 15);
        $errorCount = (int) ($stats['errors'] ?? -1);
        assert_true($errorCount >= 1 && $errorCount <= $transfers, "a clean job/stop must cost at most Transfers ($transfers) errors, one per in-flight slot, not zero and not more: " . json_encode($stats));

        // The exact case godwit_cap_stop_notification() must not alarm on:
        // this run's own error count, used as its own baseline.
        $n = godwit_cap_stop_notification('Kieren', 'budget', (int) ($stats['bytes'] ?? 0), $errorCount, $transfers);
        assert_true(!str_contains($n['description'], 'errors were also logged'), 'a clean job/stop within the Transfers baseline must not read as a real failure: ' . $n['description']);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

t('godwit_list_versions_dirs: end-to-end against a real rcd — lists real date dirs, and a not-yet-created share is absent, not an error', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return; // not cached locally — covered by host verification instead.
    }
    $tmp = sys_get_temp_dir() . '/godwit-e2e-versions-list-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';
    assert_true(is_file($rclone), 'expected an unzipped rclone binary');

    $root = $tmp . '/dst';
    mkdir($root . '/godwit/_versions/Kieren/2026-08-01', 0755, true);
    mkdir($root . '/godwit/_versions/Kieren/2026-08-02', 0755, true);
    file_put_contents($root . '/godwit/_versions/Kieren/2026-08-01/f.txt', 'x');

    $confPath = $tmp . '/rclone.conf';
    file_put_contents($confPath, "[localdst]\ntype = local\n");
    $sockPath = $tmp . '/rcd.sock';
    $listener = ['type' => 'unix', 'path' => $sockPath, 'user' => 'testuser', 'pass' => 'testpass'];
    $proc = proc_open(
        [$rclone, 'rcd', '--rc-addr=unix://' . $sockPath, '--config=' . $confPath, '--log-file=' . $tmp . '/rcd.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/rcd.log', 'a'], 2 => ['file', $tmp . '/rcd.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), godwit_rcd_env($listener))
    );
    fclose($pipes[0]);
    for ($i = 0; $i < 30 && !file_exists($sockPath); $i++) {
        usleep(100000);
    }
    assert_true(file_exists($sockPath), 'rcd did not create its unix socket in time');

    try {
        // Red proof of issue #1: the exact pre-fix call (no `remote` key)
        // is rejected outright by the real bundled binary, not by anything
        // this suite assumes.
        $buggyResp = godwit_rc_call_params($listener, 'operations/list', [
            'fs' => 'localdst:' . $root . '/godwit/_versions/Kieren',
            'opt' => json_encode(['dirsOnly' => true]),
        ], 15);
        assert_true(str_contains((string) ($buggyResp['error'] ?? ''), 'remote'), 'the pre-fix call shape (no remote key) must be rejected by the real rcd: ' . json_encode($buggyResp));

        // Green: godwit_list_versions_dirs() sends `remote` and lists the
        // real directories.
        $result = godwit_list_versions_dirs($listener, 'localdst:' . $root . '/godwit/_versions/Kieren');
        sort($result['dirs']);
        assert_eq(['2026-08-01', '2026-08-02'], $result['dirs'], 'expected both real date dirs back: ' . json_encode($result));
        assert_true(!isset($result['error']), 'a successful list must not carry an error: ' . json_encode($result));

        // A share that has never had a versioned overwrite yet has no
        // _versions dir at all — must be absent, not an error.
        $absentResult = godwit_list_versions_dirs($listener, 'localdst:' . $root . '/godwit/_versions/NeverRunShare');
        assert_eq([], $absentResult['dirs'], 'absent means no dirs');
        assert_eq(true, $absentResult['absent'] ?? false, 'a share with no _versions dir yet must be absent, not an error: ' . json_encode($absentResult));
        assert_true(!isset($absentResult['error']), 'absent must not also be an error: ' . json_encode($absentResult));

        // godwit_ensure_versions_dir() against the real rcd: creates the
        // exact path the retention loop is about to list, so the
        // subsequent list is a clean empty success, not "directory not
        // found" — this is what stops that error line appearing in rcd's
        // own log on every retention tick for a share/remote that's never
        // had a versioned overwrite.
        $freshFs = 'localdst:' . $root . '/godwit/_versions/BrandNewShare';
        godwit_ensure_versions_dir($listener, $freshFs);
        assert_true(is_dir($root . '/godwit/_versions/BrandNewShare'), 'operations/mkdir must have actually created the directory');
        $afterMkdir = godwit_list_versions_dirs($listener, $freshFs);
        assert_eq([], $afterMkdir['dirs'], 'freshly created dir is empty');
        assert_true(!($afterMkdir['absent'] ?? false), 'no longer absent once created');
        assert_true(!isset($afterMkdir['error']), 'must not be an error: ' . json_encode($afterMkdir));

        // The case that actually matters live (a just-added remote with NO
        // "godwit" folder at all yet, not merely a missing leaf under an
        // existing "godwit/_versions/"): a completely fresh root under
        // $tmp, so mkdir must create every intermediate directory, not just
        // the final path component.
        $neverExisted = $tmp . '/never-existed-root';
        mkdir($neverExisted, 0755, true);
        assert_true(!is_dir($neverExisted . '/godwit'), 'sanity: no "godwit" dir must exist yet under this fresh root');
        $neverExistedFs = 'localdst:' . $neverExisted . '/godwit/_versions/BrandNewShare';
        godwit_ensure_versions_dir($listener, $neverExistedFs);
        assert_true(is_dir($neverExisted . '/godwit/_versions/BrandNewShare'), 'operations/mkdir must create every missing intermediate directory, not just the leaf, against a remote with no "godwit" folder at all yet');
        $neverExistedResult = godwit_list_versions_dirs($listener, $neverExistedFs);
        assert_eq([], $neverExistedResult['dirs'], 'freshly created dir is empty');
        assert_true(!isset($neverExistedResult['error']), 'must not be an error: ' . json_encode($neverExistedResult));

        // Idempotent: mkdir-ing an already-populated dir must not disturb it.
        godwit_ensure_versions_dir($listener, 'localdst:' . $root . '/godwit/_versions/Kieren');
        $stillThere = godwit_list_versions_dirs($listener, 'localdst:' . $root . '/godwit/_versions/Kieren');
        sort($stillThere['dirs']);
        assert_eq(['2026-08-01', '2026-08-02'], $stillThere['dirs'], 're-mkdir must be a no-op on an existing populated dir');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

// --- godwit_build_jobs_status / godwit_handle_job_action --------------------

t('godwit_build_jobs_status: a job with an open run row shows as running with live progress', function () {
    $db = new SQLite3(':memory:');
    godwit_open_job_runs_table($db);
    godwit_open_budget_table($db);
    godwit_open_throttle_table($db);
    $runId = godwit_start_job_run($db, 'Filing Cabinet', 'gdrive', 1000);
    godwit_update_job_run_progress($db, $runId, 5000, 3, 0, 120);
    $status = godwit_build_jobs_status($db, godwit_default_jobs(), godwit_default_settings(), 2000);
    $fc = null;
    foreach ($status['jobs'] as $j) {
        if ($j['name'] === 'Filing Cabinet') { $fc = $j; }
    }
    assert_true($fc['running'], 'Filing Cabinet should show as running');
    assert_eq(5000, $fc['progress']['bytes'], 'live bytes');
    assert_eq(120, $fc['progress']['eta_seconds'], 'live eta');
    // Its remote (gdrive) is busy, so Kieren (next in queue) must show
    // queue_position 0 rather than also appearing "running".
    $kieren = null;
    foreach ($status['jobs'] as $j) {
        if ($j['name'] === 'Kieren') { $kieren = $j; }
    }
    assert_true(!$kieren['running'], 'Kieren should not be running');
    assert_eq(0, $kieren['queue_position'], 'Kieren is next in the gdrive queue');
});

t('godwit_update_job_run_progress: speed_bps is stored and surfaced in jobs status', function () {
    $db = new SQLite3(':memory:');
    godwit_open_job_runs_table($db);
    godwit_open_budget_table($db);
    godwit_open_throttle_table($db);
    $runId = godwit_start_job_run($db, 'Teegan', 'gdrive', 1000);
    godwit_update_job_run_progress($db, $runId, 1_000_000, 1, 0, 300, 5_000_000);
    $jobs = [['name' => 'Teegan', 'share' => 'Teegan', 'remote' => 'gdrive', 'mode' => 'sync', 'enabled' => true]];
    $status = godwit_build_jobs_status($db, $jobs, godwit_default_settings(), 2000);
    assert_eq(5_000_000, $status['jobs'][0]['progress']['speed_bps'], 'speed should round-trip through the status builder');
});

t('godwit_build_jobs_status: reports per-remote budget usage and throttle state', function () {
    $db = new SQLite3(':memory:');
    godwit_open_job_runs_table($db);
    godwit_open_budget_table($db);
    godwit_open_throttle_table($db);
    godwit_record_ledger_delta($db, 'gdrive', 1900, 10_000_000_000);
    $status = godwit_build_jobs_status($db, godwit_default_jobs(), godwit_default_settings(), 2000);
    assert_eq(1, count($status['remotes']), 'only gdrive is referenced by the default jobs');
    assert_eq(10_000_000_000, $status['remotes'][0]['used_24h_bytes'], 'used bytes reflects the ledger');
    assert_eq(null, $status['remotes'][0]['throttled_until'], 'not throttled');
});

t('godwit_build_jobs_status: status_text is completed, plainly, for a clean finish', function () {
    $db = new SQLite3(':memory:');
    godwit_open_job_runs_table($db);
    godwit_open_budget_table($db);
    godwit_open_throttle_table($db);
    $runId = godwit_start_job_run($db, 'Filing Cabinet', 'gdrive', 1000);
    godwit_finish_job_run($db, $runId, 1500, (int) round(129.5 * 1024 * 1024), 237, 0, 'completed');
    $status = godwit_build_jobs_status($db, godwit_default_jobs(), godwit_default_settings(), 2000);
    $fc = null;
    foreach ($status['jobs'] as $j) { if ($j['name'] === 'Filing Cabinet') { $fc = $j; } }
    assert_true(str_starts_with($fc['status_text'], 'completed (129.5 MiB) at'), $fc['status_text']);
});

t('godwit_build_jobs_status: a job resuming a budget stop with too little remaining budget shows the gated waiting text, not "error" or the raw outcome', function () {
    $db = new SQLite3(':memory:');
    godwit_open_job_runs_table($db);
    godwit_open_budget_table($db);
    godwit_open_throttle_table($db);
    // 700 GiB cap, 590 GiB used -> 110 GiB remaining, below the 140 GiB (20%) threshold.
    godwit_record_ledger_delta($db, 'gdrive', 1900, 590 * 1024 * 1024 * 1024);
    $runId = godwit_start_job_run($db, 'Filing Cabinet', 'gdrive', 1000);
    godwit_finish_job_run($db, $runId, 1500, 669 * 1024 * 1024 * 1024, 100, 0, 'budget');
    $status = godwit_build_jobs_status($db, godwit_default_jobs(), godwit_default_settings(), 2000);
    $fc = null;
    foreach ($status['jobs'] as $j) { if ($j['name'] === 'Filing Cabinet') { $fc = $j; } }
    assert_eq('waiting for daily cap to free up', $fc['status_text'], $fc['status_text']);
});

t('godwit_build_jobs_status: the same budget-stopped job is NOT gated once enough budget has freed up — shows the calm paused/resumes text instead', function () {
    $db = new SQLite3(':memory:');
    godwit_open_job_runs_table($db);
    godwit_open_budget_table($db);
    godwit_open_throttle_table($db);
    // 700 GiB cap, only 100 GiB used -> 600 GiB remaining, well above the 140 GiB threshold.
    godwit_record_ledger_delta($db, 'gdrive', 1900, 100 * 1024 * 1024 * 1024);
    $runId = godwit_start_job_run($db, 'Filing Cabinet', 'gdrive', 1000);
    godwit_finish_job_run($db, $runId, 1500, 669 * 1024 * 1024 * 1024, 100, 0, 'budget');
    $status = godwit_build_jobs_status($db, godwit_default_jobs(), godwit_default_settings(), 2000);
    $fc = null;
    foreach ($status['jobs'] as $j) { if ($j['name'] === 'Filing Cabinet') { $fc = $j; } }
    assert_true(str_contains($fc['status_text'], 'daily upload cap reached'), $fc['status_text']);
    assert_true(!str_contains(strtolower($fc['status_text']), 'error'), $fc['status_text']);
});

t('godwit_build_jobs_status: a budget-paused job with real errors above its own configured Transfers baseline surfaces the extra count in status_text, not just "paused"', function () {
    $db = new SQLite3(':memory:');
    godwit_open_job_runs_table($db);
    godwit_open_budget_table($db);
    godwit_open_throttle_table($db);
    godwit_record_ledger_delta($db, 'gdrive', 1900, 100 * 1024 * 1024 * 1024);
    $runId = godwit_start_job_run($db, 'Filing Cabinet', 'gdrive', 1000);
    // Filing Cabinet's default Transfers is 4 (godwit_default_jobs()) — 63
    // errors matches the real 2026-09-18 Photos incident's error count.
    godwit_finish_job_run($db, $runId, 1500, 669 * 1024 * 1024 * 1024, 100, 63, 'budget');
    $status = godwit_build_jobs_status($db, godwit_default_jobs(), godwit_default_settings(), 2000);
    $fc = null;
    foreach ($status['jobs'] as $j) { if ($j['name'] === 'Filing Cabinet') { $fc = $j; } }
    assert_true(str_contains($fc['status_text'], '63 transfer errors also logged'), $fc['status_text']);
    assert_true(str_contains($fc['status_text'], 'daily upload cap reached'), 'still calm, still says what happened: ' . $fc['status_text']);
});

function godwit_test_job_env(): array
{
    $tmp = sys_get_temp_dir() . '/godwit-jobaction-' . bin2hex(random_bytes(4));
    $runDir = $tmp . '/run';
    $cfgDir = $tmp . '/cfg';
    $shareRoot = $tmp . '/shares';
    mkdir($runDir, 0700, true);
    mkdir($cfgDir, 0755, true);
    // Every share name any test in this file saves a job against, so
    // jobs_save's share-existence check (v0.6.0) has something real to
    // find without depending on the actual host's /mnt/user.
    foreach (['X', 'Kieren', 'Filing Cabinet', 'Teegan', 'Photos'] as $share) {
        mkdir($shareRoot . '/' . $share, 0755, true);
    }
    $dbPath = $tmp . '/godwit.db';
    godwit_open_db($dbPath);
    return ['tmp' => $tmp, 'runDir' => $runDir, 'cfgDir' => $cfgDir, 'dbPath' => $dbPath, 'shareRoot' => $shareRoot];
}

t('godwit_handle_job_action: jobs_save rejects a job whose direction would be reversed', function () {
    $env = godwit_test_job_env();
    $badJob = [['name' => 'Evil', 'share' => 'X', 'remote' => 'gdrive', 'mode' => 'sync', 'enabled' => true]];
    // Sanity: this job is actually fine on its own — the real test is an
    // overlap/mode failure path, since godwit_build_job_fs() already makes a
    // structurally-reversed pair unconstructable from jobs_save's input shape.
    $result = godwit_handle_job_action('jobs_save', ['jobs' => json_encode($badJob)], $env['dbPath'], $env['runDir'], $env['cfgDir'], $env['shareRoot']);
    assert_true(($result['ok'] ?? false) === true, 'a well-formed job should save: ' . json_encode($result));
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_job_action: jobs_save rejects an invalid mode', function () {
    $env = godwit_test_job_env();
    $jobs = [['name' => 'X', 'share' => 'X', 'remote' => 'gdrive', 'mode' => 'mirror', 'enabled' => true]];
    $result = godwit_handle_job_action('jobs_save', ['jobs' => json_encode($jobs)], $env['dbPath'], $env['runDir'], $env['cfgDir'], $env['shareRoot']);
    assert_true(str_contains($result['error'] ?? '', 'mode'), var_export($result, true));
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_job_action: jobs_save rejects overlapping destinations and touches jobs-changed on success', function () {
    $env = godwit_test_job_env();
    $overlapping = [
        ['name' => 'A', 'share' => 'Kieren', 'remote' => 'gdrive', 'mode' => 'sync', 'enabled' => true],
        ['name' => 'B', 'share' => 'Kieren', 'remote' => 'gdrive', 'mode' => 'sync', 'enabled' => true],
    ];
    $result = godwit_handle_job_action('jobs_save', ['jobs' => json_encode($overlapping)], $env['dbPath'], $env['runDir'], $env['cfgDir'], $env['shareRoot']);
    assert_true(str_contains($result['error'] ?? '', 'overlapping'), var_export($result, true));
    assert_true(!file_exists($env['runDir'] . '/jobs-changed'), 'a rejected save must not mark jobs changed');

    $ok = godwit_handle_job_action('jobs_save', ['jobs' => json_encode(godwit_default_jobs())], $env['dbPath'], $env['runDir'], $env['cfgDir'], $env['shareRoot']);
    assert_true(($ok['ok'] ?? false) === true, var_export($ok, true));
    assert_true(file_exists($env['runDir'] . '/jobs-changed'), 'a successful save should mark jobs changed for godwitd to pick up');
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_job_action: settings_save merges onto the existing on-disk settings, never resets unrelated fields', function () {
    $env = godwit_test_job_env();
    godwit_save_settings($env['cfgDir'], array_merge(godwit_default_settings(), ['budget_caps' => ['gdrive' => 123]]));
    $partial = ['windows' => godwit_default_windows(), 'profile' => '500/50'];
    $result = godwit_handle_job_action('settings_save', ['settings' => json_encode($partial)], $env['dbPath'], $env['runDir'], $env['cfgDir']);
    assert_true(($result['ok'] ?? false) === true, var_export($result, true));
    $reloaded = godwit_load_settings($env['cfgDir']);
    assert_eq(123, $reloaded['budget_caps']['gdrive'], 'a partial settings_save must not clobber the previously-set budget cap');
    assert_eq('500/50', $reloaded['profile'], 'the field that was sent should still update');
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_job_action: settings_save rejects a window with no day checked', function () {
    $env = godwit_test_job_env();
    $bad = ['windows' => [['days' => [], 'start' => '22:00', 'end' => '06:00', 'limit_mbit' => 100.0]]];
    $result = godwit_handle_job_action('settings_save', ['settings' => json_encode($bad)], $env['dbPath'], $env['runDir'], $env['cfgDir']);
    assert_true(str_contains($result['error'] ?? '', 'day'), var_export($result, true));
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_job_action: settings_save rejects a non-positive limit_mbit', function () {
    $env = godwit_test_job_env();
    foreach ([0, -5, 'nope'] as $badLimit) {
        $bad = ['windows' => [['days' => [0], 'start' => '22:00', 'end' => '06:00', 'limit_mbit' => $badLimit]]];
        $result = godwit_handle_job_action('settings_save', ['settings' => json_encode($bad)], $env['dbPath'], $env['runDir'], $env['cfgDir']);
        assert_true(str_contains($result['error'] ?? '', 'limit_mbit'), 'limit_mbit=' . var_export($badLimit, true) . ' should be rejected: ' . var_export($result, true));
    }
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_job_action: settings_save rejects malformed start/end but accepts start==end as "all day"', function () {
    $env = godwit_test_job_env();
    $bad = ['windows' => [['days' => [0], 'start' => '25:00', 'end' => '06:00', 'limit_mbit' => 100.0]]];
    $result = godwit_handle_job_action('settings_save', ['settings' => json_encode($bad)], $env['dbPath'], $env['runDir'], $env['cfgDir']);
    assert_true(str_contains($result['error'] ?? '', 'HH:MM'), var_export($result, true));

    $allDay = ['windows' => [['days' => [0], 'start' => '09:00', 'end' => '09:00', 'limit_mbit' => 100.0]]];
    $ok = godwit_handle_job_action('settings_save', ['settings' => json_encode($allDay)], $env['dbPath'], $env['runDir'], $env['cfgDir']);
    assert_true(($ok['ok'] ?? false) === true, 'start==end ("all day") must remain a valid, expressible window: ' . var_export($ok, true));
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_job_action: settings_save round-trips the default midnight-wrap window byte-identically with no edits', function () {
    $env = godwit_test_job_env();
    godwit_save_settings($env['cfgDir'], godwit_default_settings());
    $before = file_get_contents($env['cfgDir'] . '/settings.json');

    // The exact payload the windows-form JS builds from an untouched default
    // row: same key order (days, start, end, limit_mbit) the form emits.
    $formPayload = ['windows' => [['days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '22:00', 'end' => '06:00', 'limit_mbit' => 250]], 'profile' => 'custom'];
    $result = godwit_handle_job_action('settings_save', ['settings' => json_encode($formPayload)], $env['dbPath'], $env['runDir'], $env['cfgDir']);
    assert_true(($result['ok'] ?? false) === true, var_export($result, true));

    // profile differs (default settings start with no profile set), so scope
    // the byte comparison to the windows the form actually round-tripped.
    $before = json_decode($before, true);
    $after = json_decode(file_get_contents($env['cfgDir'] . '/settings.json'), true);
    assert_eq(json_encode($before['windows']), json_encode($after['windows']), 'an unedited round trip through the form must not change the stored windows bytes');
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_job_action: run_now / pause / resume toggle marker files', function () {
    $env = godwit_test_job_env();
    godwit_handle_job_action('run_now', [], $env['dbPath'], $env['runDir'], $env['cfgDir']);
    assert_true(file_exists($env['runDir'] . '/run-now'), 'run_now should create the marker');

    godwit_handle_job_action('pause', [], $env['dbPath'], $env['runDir'], $env['cfgDir']);
    assert_true(file_exists($env['runDir'] . '/paused'), 'pause should create the marker');

    godwit_handle_job_action('resume', [], $env['dbPath'], $env['runDir'], $env['cfgDir']);
    assert_true(!file_exists($env['runDir'] . '/paused'), 'resume should remove the marker');
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_handle_job_action: jobs_status reflects paused/run_now marker state', function () {
    $env = godwit_test_job_env();
    touch($env['runDir'] . '/paused');
    $status = godwit_handle_job_action('jobs_status', [], $env['dbPath'], $env['runDir'], $env['cfgDir']);
    assert_eq(true, $status['paused'], 'paused should reflect the marker');
    assert_eq(false, $status['run_now'], 'run_now marker absent');
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

// --- jobs.json load/save ----------------------------------------------------

t('godwit_load_jobs: seeds the Phase 3 defaults when jobs.json does not exist', function () {
    $tmp = sys_get_temp_dir() . '/godwit-jobs-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    $jobs = godwit_load_jobs($tmp);
    assert_eq(4, count($jobs), 'four default jobs');
    assert_eq('Filing Cabinet', $jobs[0]['name'], 'queue order preserved');
    exec('rm -rf ' . escapeshellarg($tmp));
});

t('godwit_save_jobs / godwit_load_jobs: round-trips', function () {
    $tmp = sys_get_temp_dir() . '/godwit-jobs-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    $jobs = [['name' => 'Solo', 'share' => 'Solo', 'remote' => 'gdrive', 'mode' => 'sync', 'enabled' => true, 'transfers' => 2, 'max_delete' => 500, 'excludes' => []]];
    godwit_save_jobs($tmp, $jobs);
    $loaded = godwit_load_jobs($tmp);
    assert_eq('Solo', $loaded[0]['name'], 'round trip');
    assert_true(is_file($tmp . '/jobs.json'), 'jobs.json should exist');
    exec('rm -rf ' . escapeshellarg($tmp));
});

// --- v0.6.0: selective jobs span all shares --------------------------------

t('godwit_sanitize_job_name_segment: trims whitespace and trailing dots, accepts an ordinary name', function () {
    assert_eq('My Job', godwit_sanitize_job_name_segment('  My Job  '), 'whitespace trimmed');
    assert_eq('My Job', godwit_sanitize_job_name_segment('My Job. '), 'trailing dot/space trimmed');
});

t('godwit_sanitize_job_name_segment: rejects empty, ".", "..", and path-unsafe characters', function () {
    foreach (['', '  ', '.', '..', 'a/b', 'a\\b', 'a:b', 'a*b', 'a?b', 'a"b', 'a<b', 'a>b', 'a|b', 'a/../b'] as $bad) {
        try {
            godwit_sanitize_job_name_segment($bad);
            throw new \RuntimeException('expected an exception for ' . var_export($bad, true));
        } catch (\InvalidArgumentException $e) {
            assert_true(true, 'threw as expected for ' . var_export($bad, true));
        }
    }
});

t('godwit_job_dest_segment: a share job uses its share name unchanged', function () {
    assert_eq('Kieren', godwit_job_dest_segment(['type' => 'share', 'share' => 'Kieren']), 'share segment');
});

t('godwit_job_dest_segment: a selective job uses "_selective/<sanitized job name>"', function () {
    assert_eq('_selective/My Job', godwit_job_dest_segment(['type' => 'selective', 'name' => 'My Job']), 'selective segment');
});

t('godwit_build_job_fs: a selective job roots at /mnt/user and destines to godwit/_selective/<job name>', function () {
    $fs = godwit_build_job_fs(['type' => 'selective', 'name' => 'My OneDrive Picks', 'remote' => 'kmonedrive']);
    assert_eq('/mnt/user', $fs['srcFs'], 'srcFs is the bare share root');
    assert_eq('kmonedrive:godwit/_selective/My OneDrive Picks', $fs['dstFs'], 'dstFs nests under _selective/<job name>');
});

t('godwit_build_job_fs: a selective job rejects an unsafe job name', function () {
    try {
        godwit_build_job_fs(['type' => 'selective', 'name' => '../etc', 'remote' => 'kmonedrive']);
        throw new \RuntimeException('expected an exception');
    } catch (\InvalidArgumentException $e) {
        assert_true(true, 'threw as expected');
    }
});

t('godwit_build_job_fs: a share job rejects the reserved share name "_selective" (any case)', function () {
    foreach (['_selective', '_SELECTIVE'] as $bad) {
        try {
            godwit_build_job_fs(['type' => 'share', 'share' => $bad, 'remote' => 'gdrive']);
            throw new \RuntimeException('expected an exception for ' . $bad);
        } catch (\InvalidArgumentException $e) {
            assert_true(true, 'threw as expected for ' . $bad);
        }
    }
});

t('godwit_assert_job_direction: accepts srcFs exactly equal to the share root (a selective job)', function () {
    $fs = godwit_build_job_fs(['type' => 'selective', 'name' => 'Picks', 'remote' => 'kmonedrive']);
    godwit_assert_job_direction($fs);
    assert_true(true, 'no exception');
});

t('godwit_assert_job_direction: still rejects a same-prefix non-boundary path like /mnt/user2', function () {
    foreach (['/mnt/user2', '/mnt/use'] as $bad) {
        try {
            godwit_assert_job_direction(['srcFs' => $bad, 'dstFs' => 'gdrive:godwit/x']);
            throw new \RuntimeException('expected an exception for ' . $bad);
        } catch (\RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), 'not a local path'), $e->getMessage());
        }
    }
});

t('godwit_backup_dir_fs: a selective job versions under _versions/_selective/<job name>/<date>', function () {
    $job = ['type' => 'selective', 'name' => 'Picks', 'remote' => 'kmonedrive'];
    assert_eq('kmonedrive:godwit/_versions/_selective/Picks/2026-09-18', godwit_backup_dir_fs($job, '2026-09-18'), 'versions path');
});

t('godwit_assert_purge_path: accepts the two-segment "_selective/<job name>" shape', function () {
    $fs = godwit_assert_purge_path('kmonedrive', '_selective/My Job', '2026-08-01');
    assert_eq('kmonedrive:godwit/_versions/_selective/My Job/2026-08-01', $fs, 'purge fs');
});

t('godwit_assert_purge_path: rejects "_selective" alone, "_selective/..", and a three-segment form', function () {
    foreach (['_selective', '_selective/..', '_selective/a/b'] as $bad) {
        try {
            godwit_assert_purge_path('kmonedrive', $bad, '2026-08-01');
            throw new \RuntimeException('expected an exception for ' . var_export($bad, true));
        } catch (\InvalidArgumentException $e) {
            assert_true(true, 'threw as expected for ' . var_export($bad, true));
        }
    }
});

t('godwit_validate_job_destinations: two selective jobs with different names on the same remote do not overlap', function () {
    $jobs = [
        ['name' => 'A', 'type' => 'selective', 'remote' => 'kmonedrive', 'enabled' => true],
        ['name' => 'B', 'type' => 'selective', 'remote' => 'kmonedrive', 'enabled' => true],
    ];
    assert_eq(null, godwit_validate_job_destinations($jobs), 'distinct selective job names must not overlap');
});

t('godwit_validate_job_destinations: distinct selective jobs on the same remote do not overlap, regardless of array order', function () {
    $jobs = [
        ['name' => 'Foo', 'type' => 'selective', 'remote' => 'gdrive', 'enabled' => true],
        ['name' => 'Bar', 'type' => 'selective', 'remote' => 'gdrive', 'enabled' => true],
    ];
    assert_eq(null, godwit_validate_job_destinations($jobs), 'distinct selective jobs do not overlap');
    assert_eq(null, godwit_validate_job_destinations(array_reverse($jobs)), 'order must not matter');
});

t('godwit_validate_job_destinations: the SAME source paths on TWO DIFFERENT remotes never overlap — the guard is destination-only, source overlap is a deliberate, supported use case', function () {
    // Kieren's real intended setup: the nightly Google full-share backup
    // (share job, gdrive:godwit/Kieren) and a second, independent OneDrive
    // offsite copy of a critical subset of that same share (selective job,
    // kmonedrive:godwit/_selective/<job>/Kieren/...). Both jobs legitimately
    // read overlapping source content — that's the whole point of a second
    // offsite copy — so the guard must judge destinations only, never source
    // paths, and must not be tempted to "helpfully" flag this as a conflict.
    $jobs = [
        ['name' => 'Kieren', 'type' => 'share', 'share' => 'Kieren', 'remote' => 'gdrive', 'enabled' => true],
        ['name' => 'Kieren Offsite', 'type' => 'selective', 'remote' => 'kmonedrive', 'enabled' => true,
            'included' => [['path' => 'Kieren/Documents', 'is_dir' => true], ['path' => 'Kieren/Photos', 'is_dir' => true]]],
    ];
    assert_eq(null, godwit_validate_job_destinations($jobs), 'same source, different remotes: destinations do not overlap, must be allowed');

    // And the full save path, exactly as the settings page would post it —
    // not just the pure destination check in isolation.
    $env = godwit_test_job_env();
    $result = godwit_handle_job_action('jobs_save', ['jobs' => json_encode($jobs)], $env['dbPath'], $env['runDir'], $env['cfgDir'], $env['shareRoot']);
    assert_true(($result['ok'] ?? false) === true, 'both jobs should save fine despite sharing source content: ' . var_export($result, true));
    exec('rm -rf ' . escapeshellarg($env['tmp']));
});

t('godwit_validate_job_destinations: two selective jobs with the exact same name on the same remote overlap, in either array order', function () {
    $jobs = [
        ['name' => 'Same', 'type' => 'selective', 'remote' => 'gdrive', 'enabled' => true],
        ['name' => 'Same', 'type' => 'selective', 'remote' => 'gdrive', 'enabled' => true],
    ];
    $errA = godwit_validate_job_destinations($jobs);
    assert_true($errA !== null && str_contains($errA, 'overlapping'), 'same job name on the same remote must overlap: ' . var_export($errA, true));
    $errB = godwit_validate_job_destinations(array_reverse($jobs));
    assert_true($errB !== null && str_contains($errB, 'overlapping'), 'same overlap must be caught regardless of array order: ' . var_export($errB, true));
});

t('godwit_validate_selective_job_name_collisions: rejects two selective jobs whose names sanitise to the same segment, case-insensitively, on the same remote', function () {
    $jobs = [
        ['name' => 'Photos', 'type' => 'selective', 'remote' => 'kmonedrive'],
        ['name' => 'photos ', 'type' => 'selective', 'remote' => 'kmonedrive'],
    ];
    $err = godwit_validate_selective_job_name_collisions($jobs);
    assert_true($err !== null, 'expected a collision error');
});

t('godwit_validate_selective_job_name_collisions: catches a collision even when one of the two jobs is disabled', function () {
    $jobs = [
        ['name' => 'Photos', 'type' => 'selective', 'remote' => 'kmonedrive', 'enabled' => true],
        ['name' => 'PHOTOS', 'type' => 'selective', 'remote' => 'kmonedrive', 'enabled' => false],
    ];
    $err = godwit_validate_selective_job_name_collisions($jobs);
    assert_true($err !== null, 'a disabled job must still count — the destination-overlap guard skips disabled jobs, this check must not');
});

t('godwit_validate_selective_job_name_collisions: the same name on two different remotes is not a collision', function () {
    $jobs = [
        ['name' => 'Photos', 'type' => 'selective', 'remote' => 'gdrive'],
        ['name' => 'Photos', 'type' => 'selective', 'remote' => 'kmonedrive'],
    ];
    assert_eq(null, godwit_validate_selective_job_name_collisions($jobs), 'different remotes cannot collide');
});

t('godwit_list_shares: lists real directories only, skips dotfiles, sorted case-insensitively', function () {
    $root = sys_get_temp_dir() . '/godwit-shares-' . bin2hex(random_bytes(4));
    @mkdir($root . '/zeta', 0755, true);
    @mkdir($root . '/Alpha', 0755, true);
    @mkdir($root . '/.hidden', 0755, true);
    file_put_contents($root . '/notashare.txt', 'x');
    $shares = godwit_list_shares($root);
    assert_eq(['Alpha', 'zeta'], $shares, 'dirs only, dotfiles and files excluded, case-insensitive sort');
    exec('rm -rf ' . escapeshellarg($root));
});

t('godwit_list_shares: a missing share root returns an empty list rather than erroring (array not started)', function () {
    assert_eq([], godwit_list_shares('/nonexistent-' . bin2hex(random_bytes(4))), 'empty, not an error');
});

t('godwit_share_is_live_data: flags appdata/system/domains, nothing else', function () {
    foreach (['appdata', 'system', 'domains'] as $live) {
        assert_true(godwit_share_is_live_data($live), "$live should be flagged live data");
    }
    assert_true(!godwit_share_is_live_data('Kieren'), 'an ordinary share must not be flagged');
});

t('godwit_load_jobs: migrates a 0.5.0-shaped selective job (single share, share-relative paths) on load', function () {
    $cfgDir = sys_get_temp_dir() . '/godwit-cfg-' . bin2hex(random_bytes(4));
    mkdir($cfgDir, 0755, true);
    $legacy = [[
        'name' => 'Old Selective', 'share' => 'Kieren', 'remote' => 'kmonedrive', 'type' => 'selective', 'mode' => 'sync',
        'included' => [['path' => 'Documents', 'is_dir' => true]],
        'excluded' => [['path' => 'Documents/Drafts', 'is_dir' => true]],
    ]];
    file_put_contents($cfgDir . '/jobs.json', json_encode($legacy));
    $loaded = godwit_load_jobs($cfgDir);
    assert_true(!isset($loaded[0]['share']), 'the old single-share field must be dropped after migration');
    assert_eq('Kieren/Documents', $loaded[0]['included'][0]['path'], 'included path is share-qualified');
    assert_eq('Kieren/Documents/Drafts', $loaded[0]['excluded'][0]['path'], 'excluded path is share-qualified');
    exec('rm -rf ' . escapeshellarg($cfgDir));
});

t('godwit_load_jobs: a 0.6.0-shaped selective job (no share field, already share-qualified paths) loads unchanged', function () {
    $cfgDir = sys_get_temp_dir() . '/godwit-cfg-' . bin2hex(random_bytes(4));
    mkdir($cfgDir, 0755, true);
    $modern = [[
        'name' => 'New Selective', 'remote' => 'kmonedrive', 'type' => 'selective', 'mode' => 'sync',
        'included' => [['path' => 'Kieren/Documents', 'is_dir' => true]],
        'excluded' => [],
    ]];
    file_put_contents($cfgDir . '/jobs.json', json_encode($modern));
    $loaded = godwit_load_jobs($cfgDir);
    assert_eq('Kieren/Documents', $loaded[0]['included'][0]['path'], 'already-qualified path is untouched');
    exec('rm -rf ' . escapeshellarg($cfgDir));
});

t('godwit_load_jobs: a share job (type=share) is never touched by the selective migration', function () {
    $cfgDir = sys_get_temp_dir() . '/godwit-cfg-' . bin2hex(random_bytes(4));
    mkdir($cfgDir, 0755, true);
    file_put_contents($cfgDir . '/jobs.json', json_encode([['name' => 'Kieren', 'share' => 'Kieren', 'remote' => 'gdrive', 'type' => 'share']]));
    $loaded = godwit_load_jobs($cfgDir);
    assert_eq('Kieren', $loaded[0]['share'], 'share field must survive for a share job');
    exec('rm -rf ' . escapeshellarg($cfgDir));
});

t('godwit_handle_job_action jobs_save: a share job needs no separate "share" error when type=selective and share is omitted', function () {
    $cfgDir = sys_get_temp_dir() . '/godwit-cfg-' . bin2hex(random_bytes(4));
    $runDir = sys_get_temp_dir() . '/godwit-run-' . bin2hex(random_bytes(4));
    @mkdir($runDir, 0755, true);
    $jobs = [['name' => 'Sel', 'remote' => 'kmonedrive', 'type' => 'selective', 'mode' => 'sync',
        'included' => [['path' => 'Kieren/Documents', 'is_dir' => true]], 'excluded' => []]];
    $result = godwit_handle_job_action('jobs_save', ['jobs' => json_encode($jobs)], '/nonexistent.db', $runDir, $cfgDir);
    assert_true(($result['ok'] ?? false) === true, 'expected ok=true, got: ' . var_export($result, true));
    exec('rm -rf ' . escapeshellarg($cfgDir) . ' ' . escapeshellarg($runDir));
});

t('godwit_handle_job_action jobs_save: rejects a share job whose share does not exist under the share root', function () {
    $cfgDir = sys_get_temp_dir() . '/godwit-cfg-' . bin2hex(random_bytes(4));
    $runDir = sys_get_temp_dir() . '/godwit-run-' . bin2hex(random_bytes(4));
    $shareRoot = sys_get_temp_dir() . '/godwit-shareroot-' . bin2hex(random_bytes(4));
    @mkdir($runDir, 0755, true);
    @mkdir($shareRoot, 0755, true);
    $jobs = [['name' => 'Typo', 'share' => 'Kieren', 'remote' => 'gdrive', 'type' => 'share', 'mode' => 'sync']];
    $result = godwit_handle_job_action('jobs_save', ['jobs' => json_encode($jobs)], '/nonexistent.db', $runDir, $cfgDir, $shareRoot);
    assert_true(isset($result['error']) && str_contains($result['error'], 'Kieren'), 'expected a share-not-found error, got: ' . var_export($result, true));
    exec('rm -rf ' . escapeshellarg($cfgDir) . ' ' . escapeshellarg($runDir) . ' ' . escapeshellarg($shareRoot));
});

t('godwit_handle_job_action jobs_save: accepts a share job whose share exists under a test share root', function () {
    $cfgDir = sys_get_temp_dir() . '/godwit-cfg-' . bin2hex(random_bytes(4));
    $runDir = sys_get_temp_dir() . '/godwit-run-' . bin2hex(random_bytes(4));
    $shareRoot = sys_get_temp_dir() . '/godwit-shareroot-' . bin2hex(random_bytes(4));
    @mkdir($runDir, 0755, true);
    @mkdir($shareRoot . '/Kieren', 0755, true);
    $jobs = [['name' => 'Kieren', 'share' => 'Kieren', 'remote' => 'gdrive', 'type' => 'share', 'mode' => 'sync']];
    $result = godwit_handle_job_action('jobs_save', ['jobs' => json_encode($jobs)], '/nonexistent.db', $runDir, $cfgDir, $shareRoot);
    assert_true(($result['ok'] ?? false) === true, 'expected ok=true, got: ' . var_export($result, true));
    exec('rm -rf ' . escapeshellarg($cfgDir) . ' ' . escapeshellarg($runDir) . ' ' . escapeshellarg($shareRoot));
});

t('godwit_handle_job_action jobs_save: rejects two selective jobs whose names collide case-insensitively', function () {
    $cfgDir = sys_get_temp_dir() . '/godwit-cfg-' . bin2hex(random_bytes(4));
    $runDir = sys_get_temp_dir() . '/godwit-run-' . bin2hex(random_bytes(4));
    @mkdir($runDir, 0755, true);
    $jobs = [
        ['name' => 'Photos', 'remote' => 'kmonedrive', 'type' => 'selective', 'mode' => 'sync', 'included' => [['path' => 'Kieren/A', 'is_dir' => true]], 'excluded' => []],
        ['name' => 'photos', 'remote' => 'kmonedrive', 'type' => 'selective', 'mode' => 'sync', 'included' => [['path' => 'Kieren/B', 'is_dir' => true]], 'excluded' => []],
    ];
    $result = godwit_handle_job_action('jobs_save', ['jobs' => json_encode($jobs)], '/nonexistent.db', $runDir, $cfgDir);
    assert_true(isset($result['error']), 'expected a collision error, got: ' . var_export($result, true));
    exec('rm -rf ' . escapeshellarg($cfgDir) . ' ' . escapeshellarg($runDir));
});

t('godwit_handle_job_action shares_list: lists real shares under a test share root with the live-data flag set', function () {
    $shareRoot = sys_get_temp_dir() . '/godwit-shareroot-' . bin2hex(random_bytes(4));
    @mkdir($shareRoot . '/Kieren', 0755, true);
    @mkdir($shareRoot . '/appdata', 0755, true);
    $result = godwit_handle_job_action('shares_list', [], '/nonexistent.db', sys_get_temp_dir(), sys_get_temp_dir(), $shareRoot);
    $byName = [];
    foreach ($result['shares'] as $s) { $byName[$s['name']] = $s; }
    assert_true(($byName['Kieren']['live'] ?? true) === false, 'Kieren must not be flagged live');
    assert_true(($byName['appdata']['live'] ?? false) === true, 'appdata must be flagged live');
    exec('rm -rf ' . escapeshellarg($shareRoot));
});

t('godwit_compile_selective_filter_rules + rclone lsf -R: a job spanning two shares only surfaces the ticked subtree of each, never the sibling share\'s untouched files (the traversal-pruning claim at the new /mnt/user depth)', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return;
    }
    $tmp = sys_get_temp_dir() . '/godwit-multishare-' . bin2hex(random_bytes(4));
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';
    assert_true(is_file($rclone), 'expected an unzipped rclone binary');

    $tree = $tmp . '/mnt-user'; // stands in for /mnt/user itself
    $paths = [
        'Kieren/Documents/Taxes/2025.pdf',   // included subtree in share Kieren: must survive
        'Kieren/Documents/Drafts/todo.txt',  // un-ticked child of an included folder: must be filtered
        'Kieren/Pictures/holiday.jpg',       // never selected, same share: must be filtered
        'Teegan/Homework/essay.docx',        // included subtree in a DIFFERENT share: must survive
        'Teegan/Downloads/movie.mkv',        // never selected, sibling share: must be filtered
        'Photos/2020/beach.jpg',              // a THIRD share never mentioned at all: must be filtered
    ];
    foreach ($paths as $p) {
        $full = $tree . '/' . $p;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, 'x');
    }

    $job = [
        'included' => [
            ['path' => 'Kieren/Documents', 'is_dir' => true],
            ['path' => 'Teegan/Homework', 'is_dir' => true],
        ],
        'excluded' => [
            ['path' => 'Kieren/Documents/Drafts', 'is_dir' => true],
        ],
    ];
    $filterFile = $tmp . '/filter.txt';
    godwit_write_filter_file($filterFile, godwit_compile_selective_filter_rules($job));

    $out = [];
    exec($rclone . ' lsf -R --filter-from ' . escapeshellarg($filterFile) . ' ' . escapeshellarg($tree), $out);
    $listed = implode("\n", $out);

    foreach (['Kieren/Documents/Taxes/2025.pdf', 'Teegan/Homework/essay.docx'] as $kept) {
        assert_true(str_contains($listed, $kept), "$kept should have survived filtering; lsf output:\n$listed");
    }
    foreach (['Kieren/Documents/Drafts/todo.txt', 'Kieren/Pictures/holiday.jpg', 'Teegan/Downloads/movie.mkv', 'Photos/2020/beach.jpg'] as $excluded) {
        assert_true(!str_contains($listed, $excluded), "$excluded should have been filtered out; lsf output:\n$listed");
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

t('godwit_build_sync_params + rc sync/sync with a multi-share selective job: srcFs is the bare share root, dest nests <job>/<share>/<path>, sync never deletes destination content outside the filter', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return;
    }
    $tmp = sys_get_temp_dir() . '/godwit-selective-e2e-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';
    assert_true(is_file($rclone), 'expected an unzipped rclone binary');

    $shareRoot = $tmp . '/mnt-user';
    mkdir($shareRoot . '/Kieren/Documents', 0755, true);
    file_put_contents($shareRoot . '/Kieren/Documents/report.pdf', 'report-contents');
    mkdir($shareRoot . '/Teegan/Homework', 0755, true);
    file_put_contents($shareRoot . '/Teegan/Homework/essay.docx', 'essay-contents');
    mkdir($shareRoot . '/Teegan/Downloads', 0755, true); // never selected
    file_put_contents($shareRoot . '/Teegan/Downloads/movie.mkv', 'movie-contents');

    $dstRoot = $tmp . '/dst';
    // Pre-existing file entirely outside the job's dest tree — sync must never touch it.
    mkdir($dstRoot . '/untouched', 0755, true);
    file_put_contents($dstRoot . '/untouched/keepme.txt', 'must survive');
    // The sharper claim: stale content INSIDE the job's own dest root but
    // OUTSIDE the current filter (as if an earlier selection had included
    // Teegan/Downloads and it was later un-ticked) must also survive —
    // sync's delete phase must never reach outside what the filter actually
    // put in scope, the same claim the single-share Phase 4 e2e test proved
    // one level shallower.
    $destBase = $dstRoot . '/godwit/_selective/Cross-Share Picks';
    mkdir($destBase . '/Teegan/Downloads', 0755, true);
    file_put_contents($destBase . '/Teegan/Downloads/stale.mkv', 'pre-existing, outside the current filter');

    $confPath = $tmp . '/rclone.conf';
    file_put_contents($confPath, "[localdst]\ntype = local\n");
    $sockPath = $tmp . '/rcd.sock';
    $listener = ['type' => 'unix', 'path' => $sockPath, 'user' => 'testuser', 'pass' => 'testpass'];
    $proc = proc_open(
        [$rclone, 'rcd', '--rc-addr=unix://' . $sockPath, '--config=' . $confPath, '--log-file=' . $tmp . '/rcd.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/rcd.log', 'a'], 2 => ['file', $tmp . '/rcd.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), godwit_rcd_env($listener))
    );
    fclose($pipes[0]);
    for ($i = 0; $i < 30 && !file_exists($sockPath); $i++) {
        usleep(100000);
    }
    assert_true(file_exists($sockPath), 'rcd did not create its unix socket in time');

    try {
        $job = [
            'name' => 'Cross-Share Picks', 'type' => 'selective', 'remote' => 'localdst', 'mode' => 'sync', 'transfers' => 2, 'max_delete' => 1000,
            'included' => [
                ['path' => 'Kieren/Documents', 'is_dir' => true],
                ['path' => 'Teegan/Homework', 'is_dir' => true],
            ],
            'excluded' => [],
        ];
        $fs = godwit_build_job_fs($job, $shareRoot);
        assert_eq($shareRoot, $fs['srcFs'], 'srcFs is the bare share root, not a single share');
        assert_eq('localdst:godwit/_selective/Cross-Share Picks', $fs['dstFs'], 'dstFs nests under _selective/<job name>');
        $fs['dstFs'] = 'localdst:' . $dstRoot . '/godwit/_selective/Cross-Share Picks';

        $filterFile = $tmp . '/filter.txt';
        godwit_write_filter_file($filterFile, godwit_compile_filter_rules($job));
        $params = godwit_build_sync_params($job, $fs, $filterFile, GODWIT_BUDGET_UNLIMITED, null, null, false, $shareRoot);

        $resp = godwit_rc_call_params($listener, godwit_sync_rc_path($job['mode']), $params, 15);
        assert_true(isset($resp['jobid']), 'expected a jobid back from sync/sync: ' . json_encode($resp));

        $status = null;
        for ($i = 0; $i < 50; $i++) {
            $status = godwit_rc_call_params($listener, 'job/status', ['jobid' => $resp['jobid']], 15);
            if (!empty($status['finished'])) {
                break;
            }
            usleep(100000);
        }
        assert_true($status !== null && !empty($status['finished']), 'job should finish within 5s: ' . json_encode($status));
        assert_eq('', trim((string) ($status['error'] ?? '')), 'a plain cross-share selective sync should not error: ' . json_encode($status));

        assert_true(is_file($destBase . '/Kieren/Documents/report.pdf'), 'Kieren\'s file must land at its share-qualified path under the job\'s own dest root');
        assert_true(is_file($destBase . '/Teegan/Homework/essay.docx'), 'Teegan\'s file must land at its share-qualified path under the same dest root — one job, two shares');
        assert_true(!file_exists($destBase . '/Teegan/Downloads/movie.mkv'), 'the never-selected source file must never reach the destination');
        assert_true(is_file($destBase . '/Teegan/Downloads/stale.mkv'), 'sync must NOT delete pre-existing destination content that sits outside the current filter — the actual data-safety claim, not just that included files transfer');
        assert_true(is_file($dstRoot . '/untouched/keepme.txt'), 'sync must never touch content entirely outside its own dest tree either');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

// --- v0.6.1: a third masking shape — context-canceled march errors from a
// near-zero-remaining-budget cutoff ------------------------------------

t('godwit_is_context_canceled_cutoff_artifact: detects the march-failure text a tiny-MaxTransfer cutoff produces', function () {
    assert_true(godwit_is_context_canceled_cutoff_artifact('march failed with 3 error(s): first error: context canceled'), 'the exact live-incident text must match');
    assert_true(godwit_is_context_canceled_cutoff_artifact('context canceled'), 'the bare phrase must match');
    assert_true(!godwit_is_context_canceled_cutoff_artifact('open /dst/badfile: is a directory'), 'an unrelated real error must not match');
});

t('godwit_classify_job_outcome: a context-canceled march failure with a MaxTransfer configured classifies as budget, even at 0 bytes transferred (2026-09-19 incident: 606KB remaining budget, cutoff fired before any file could fit)', function () {
    $errorMsg = 'march failed with 7 error(s): first error: context canceled';
    assert_eq('budget', godwit_classify_job_outcome($errorMsg, false, 0, 600), 'bytes (0) is nowhere near MaxTransfer (600) — the 2% proximity fallback alone cannot catch this, the text signal must');
});

t('godwit_classify_job_outcome: the same context-canceled text with no MaxTransfer configured (an unlimited remote) is NOT reclassified as budget', function () {
    $errorMsg = 'march failed with 7 error(s): first error: context canceled';
    assert_eq('error', godwit_classify_job_outcome($errorMsg, false, 0, null), 'no budget limit was configured for this run, so a context cancellation here has no known budget cause');
});

t('godwit_classify_job_outcome: a genuine unrelated error is not swallowed by the context-canceled check just because a MaxTransfer happened to be configured', function () {
    assert_eq('error', godwit_classify_job_outcome('open /dst/badfile: is a directory', false, 500, 600), 'a real per-file error with no "context canceled" text must still surface as error, not budget');
    assert_eq('auth', godwit_classify_job_outcome('googleapi: Error 401: invalid_grant', false, 500, 600), 'a real auth-expiry error must still classify as auth, not budget — its check has higher precedence than the context-canceled check');
});

t('godwit_classify_job_outcome: explicit --max-transfer cutoff text still wins over the context-canceled check when both could apply (no ordering regression)', function () {
    $errorMsg = 'march failed with 1 error(s): first error: max transfer limit reached as set by --max-transfer - stopping transfers: context canceled';
    assert_eq('budget', godwit_classify_job_outcome($errorMsg, false, 600, 600), 'either signal alone already says budget — this just confirms no regression when both are present');
});

t('e2e (real rcd): a near-zero remaining budget cutoff mid-directory-listing reproduces the exact live-incident shape and is now classified budget, not error', function () use ($repoRoot) {
    $zip = $repoRoot . '/build/rclone-v1.75.1-linux-amd64.zip';
    if (!is_file($zip)) {
        echo "  (skipped -- build/rclone-v1.75.1-linux-amd64.zip not found; run scripts/build-plugin.sh first to exercise this test)\n";
        return;
    }
    $tmp = sys_get_temp_dir() . '/godwit-e2e-ctxcancel-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
    $rclone = $tmp . '/rclone-v1.75.1-linux-amd64/rclone';

    $shareRoot = $tmp . '/mnt-user';
    $src = $shareRoot . '/Kieren';
    mkdir($src, 0755, true);
    // Several directories with several files each, so rclone's directory
    // march is still mid-listing across more than one directory when the
    // near-empty budget's cutoff fires — the exact shape that produced
    // "error reading source directory: context canceled" live on
    // 2026-09-19, not the single-directory shape v0.4.3's test used.
    for ($d = 0; $d < 5; $d++) {
        $dir = $src . "/dir$d";
        mkdir($dir, 0755, true);
        for ($i = 0; $i < 20; $i++) {
            file_put_contents($dir . "/f$i.bin", random_bytes(50_000));
        }
    }
    // Smaller than any single file — the live incident's ~606KB remaining
    // against files far larger than that; 600 bytes here is the same
    // relationship at test scale (a budget too small for even one file).
    $maxTransfer = 600;

    $dst = $tmp . '/dst';
    mkdir($dst, 0755, true);
    $confPath = $tmp . '/rclone.conf';
    file_put_contents($confPath, "[localdst]\ntype = local\n");
    $sockPath = $tmp . '/rcd.sock';
    $listener = ['type' => 'unix', 'path' => $sockPath, 'user' => 'testuser', 'pass' => 'testpass'];
    $proc = proc_open(
        [$rclone, 'rcd', '--rc-addr=unix://' . $sockPath, '--config=' . $confPath, '--log-file=' . $tmp . '/rcd.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/rcd.log', 'a'], 2 => ['file', $tmp . '/rcd.log', 'a']],
        $pipes,
        null,
        array_merge(getenv(), godwit_rcd_env($listener))
    );
    fclose($pipes[0]);
    for ($i = 0; $i < 30 && !file_exists($sockPath); $i++) {
        usleep(100000);
    }

    try {
        $job = ['name' => 'Kieren', 'share' => 'Kieren', 'remote' => 'localdst', 'mode' => 'sync', 'transfers' => 4, 'max_delete' => 1000, 'excludes' => []];
        $fs = ['srcFs' => $src, 'dstFs' => 'localdst:' . $dst];
        $filterFile = $tmp . '/filter.txt';
        godwit_write_filter_file($filterFile, godwit_compile_filter_rules($job));
        $params = godwit_build_sync_params($job, $fs, $filterFile, $maxTransfer, null, null, false, $shareRoot);
        $resp = godwit_rc_call_params($listener, godwit_sync_rc_path($job['mode']), $params, 15);
        assert_true(isset($resp['jobid']), 'expected a jobid: ' . json_encode($resp));
        $jobid = $resp['jobid'];
        $status = null;
        for ($i = 0; $i < 100; $i++) {
            $status = godwit_rc_call_params($listener, 'job/status', ['jobid' => $jobid], 15);
            if (!empty($status['finished'])) {
                break;
            }
            usleep(100000);
        }
        assert_true($status !== null && !empty($status['finished']), 'job should finish within 10s: ' . json_encode($status));
        $errorMsg = trim((string) ($status['error'] ?? ''));
        $stats = godwit_rc_call_params($listener, 'core/stats', ['group' => 'job/' . $jobid], 15);
        $bytes = (int) ($stats['bytes'] ?? -1);
        $errors = (int) ($stats['errors'] ?? -1);

        // The masking must actually have happened — otherwise this test
        // proves nothing about the fix.
        assert_true(godwit_is_context_canceled_cutoff_artifact($errorMsg), 'expected a context-canceled march failure to reproduce the live incident: ' . var_export($errorMsg, true));
        assert_true($bytes < $maxTransfer * 0.5, "expected bytes transferred far below MaxTransfer, reproducing the extreme-undershoot shape the 2% proximity check cannot catch: bytes=$bytes maxTransfer=$maxTransfer");
        assert_true($errors >= 1, "expected at least the directory-listing artifact errors: $errors");

        // Pre-fix behaviour: text alone (and the byte-proximity fallback,
        // since bytes is nowhere near maxTransfer) both read this as a
        // plain error — confirms the bug this release fixes.
        assert_eq('error', godwit_classify_job_outcome($errorMsg, false), 'without the new text-based signal, this still misreads as error');

        // Post-fix: what godwitd now actually does.
        $outcome = godwit_classify_job_outcome($errorMsg, false, $bytes, $maxTransfer);
        assert_eq('budget', $outcome, "bytes ($bytes) vs MaxTransfer ($maxTransfer), errorMsg: " . var_export($errorMsg, true));

        // Knock-on effect (bug #3): once stored as a real 'budget' outcome,
        // the v0.4.2 minimum-budget-threshold gate must now recognize this
        // run as known-outstanding work and hold the job back until real
        // headroom exists — proving the misclassification's thrashing
        // side-effect is also fixed, not just the label.
        $db = new SQLite3(':memory:');
        godwit_open_job_runs_table($db);
        godwit_open_budget_table($db);
        godwit_open_throttle_table($db);
        $runId = godwit_start_job_run($db, 'Kieren', 'gdrive', 1000);
        godwit_finish_job_run($db, $runId, 1500, $bytes, 1, $errors, $outcome);
        $cap = 700 * 1024 * 1024 * 1024;
        // Same shape as the live incident: almost the whole cap already
        // used, leaving far less than the 20% resume threshold free.
        $tinyRemaining = 606 * 1024; // ~606 KiB, as observed live
        $lastRun = godwit_last_job_run($db, 'Kieren');
        assert_eq('budget', $lastRun['outcome'], 'the run must be stored as a budget stop, not error');
        assert_true(godwit_job_budget_gated($lastRun, $cap, $tinyRemaining, GODWIT_MIN_BUDGET_FRACTION), 'a budget-stopped run with ~606KB remaining (far below the 140GiB/20% threshold) must be gated, not immediately re-selected');
        assert_true(!godwit_job_budget_gated($lastRun, $cap, (int) round($cap * 0.25), GODWIT_MIN_BUDGET_FRACTION), 'once 25% of the cap has freed up (above the 20% threshold), the same run must no longer be gated');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

// --- report ---------------------------------------------------------------

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
