<?php
/**
 * Status + start/stop endpoint for Godwit.page. Plain urlencoded POST only —
 * no FormData/fetch (incompatible with the host's nginx auth_request setup)
 * and no custom CSRF check (local_prepend.php already consumed csrf_token
 * before this file ran). Must not reference STDOUT/STDERR/$argv — this file
 * is web-only but shares lib.php with the CLI daemon. Never talks to rcd
 * directly: reads only the heartbeat db, and mutates only by invoking
 * rc.godwit, so no rcd credentials ever need to live in the web SAPI.
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Content-Type: application/json');

$pidFile = getenv('GODWIT_PIDFILE') ?: '/var/run/godwit.pid';
$stateDir = getenv('GODWIT_STATEDIR') ?: '/mnt/cache/appdata/godwit';
$dbPath = $stateDir . '/godwit.db';

$action = $_POST['action'] ?? 'status';
if ($action === 'start' || $action === 'stop') {
    $rc = __DIR__ . '/rc.godwit';
    // $action is checked against a fixed allow-list above, never interpolated
    // from a wider set of values, so this exec is not a shell-injection risk.
    shell_exec('bash ' . escapeshellarg($rc) . ' ' . escapeshellarg($action) . ' 2>&1');
}

if (!is_file($dbPath)) {
    echo json_encode([
        'daemon_running' => godwit_daemon_running($pidFile),
        'error' => 'heartbeat database not found yet',
    ]);
    return;
}

try {
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    echo json_encode(godwit_build_status($db, $pidFile));
} catch (\Throwable $e) {
    // A mid-write or momentarily-locked db must not surface as a 500 with a
    // stack trace — the page polls this every 15s and should just show the
    // error and retry next tick.
    echo json_encode([
        'daemon_running' => godwit_daemon_running($pidFile),
        'error' => 'heartbeat database unavailable',
    ]);
}
