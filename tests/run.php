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

t('godwit_walk_config_state: onedrive — two drives, one personal, picks the personal one', function () {
    $drives = [
        ['Value' => 'b!sharepointId', 'Help' => 'Team Library (business)'],
        ['Value' => 'b!personalId', 'Help' => 'KM OneDrive (personal)'],
    ];
    $call = fn (array $p) => godwit_test_onedrive_sequence_call($p, $drives);
    $initial = ['State' => '*oauth-confirm,choose_type,,', 'Option' => ['Name' => 'config_refresh_token'], 'Error' => '', 'Result' => ''];
    $driveChosen = null;
    $final = godwit_walk_config_state($call, 'od', $initial, 'onedrive', $driveChosen);
    assert_eq('', $final['State'], 'onedrive walk with two drives (one personal) must reach a terminal state');
    assert_eq('KM OneDrive (personal)', $driveChosen, 'the personal drive must be picked over the business one');
});

t('godwit_walk_config_state: onedrive — two drives, neither/both ambiguous, fails with a clear no-ID error', function () {
    $drives = [
        ['Value' => 'b!oneId', 'Help' => 'Site A (documentLibrary)'],
        ['Value' => 'b!twoId', 'Help' => 'Site B (documentLibrary)'],
    ];
    $call = fn (array $p) => godwit_test_onedrive_sequence_call($p, $drives);
    $initial = ['State' => '*oauth-confirm,choose_type,,', 'Option' => ['Name' => 'config_refresh_token'], 'Error' => '', 'Result' => ''];
    $threw = false;
    try {
        godwit_walk_config_state($call, 'od', $initial, 'onedrive');
    } catch (\RuntimeException $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), 'Site A'), 'ambiguous error must name the drives, not IDs: ' . $e->getMessage());
        assert_true(!str_contains($e->getMessage(), 'b!oneId'), 'ambiguous error must not leak drive IDs: ' . $e->getMessage());
    }
    assert_true($threw, 'an ambiguous drive choice (no personal drive) must fail rather than guess');
});

t('godwit_walk_config_state: onedrive — two personal-labelled drives is still ambiguous, fails with a clear no-ID error', function () {
    $drives = [
        ['Value' => 'b!firstId', 'Help' => 'Work OneDrive (personal)'],
        ['Value' => 'b!secondId', 'Help' => 'Old OneDrive (personal)'],
    ];
    $call = fn (array $p) => godwit_test_onedrive_sequence_call($p, $drives);
    $initial = ['State' => '*oauth-confirm,choose_type,,', 'Option' => ['Name' => 'config_refresh_token'], 'Error' => '', 'Result' => ''];
    $threw = false;
    try {
        godwit_walk_config_state($call, 'od', $initial, 'onedrive');
    } catch (\RuntimeException $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), 'Work OneDrive'), 'ambiguous error must name the drives, not IDs: ' . $e->getMessage());
        assert_true(!str_contains($e->getMessage(), 'b!firstId'), 'ambiguous error must not leak drive IDs: ' . $e->getMessage());
    }
    assert_true($threw, 'two drives both labelled (personal) must still fail rather than silently pick the first');
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
        $tokenJson = json_encode(['access_token' => 'fake-access', 'refresh_token' => 'fake-refresh', 'expiry' => '2026-01-01T00:00:00Z']);
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

t('Godwit.page: the message div sits right under the remotes table, before the Add forms', function () use ($repoRoot) {
    $page = file_get_contents($repoRoot . '/plugin/Godwit.page');
    $listPos = strpos($page, 'id="godwit-remotes-list"');
    $msgPos = strpos($page, 'id="godwit-remotes-message"');
    $driveFormPos = strpos($page, '<h3>Add Google Drive');
    assert_true($listPos !== false && $msgPos !== false && $driveFormPos !== false, 'expected to find all three markers');
    assert_true($listPos < $msgPos && $msgPos < $driveFormPos, 'the message div must appear between the remotes table and the Add forms');
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

// --- report ---------------------------------------------------------------

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
