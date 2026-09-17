<?php
/**
 * Status + start/stop + remotes endpoint for Godwit.page. Plain urlencoded
 * POST only — no FormData/fetch (incompatible with the host's nginx
 * auth_request setup) and no custom CSRF check (local_prepend.php already
 * consumed csrf_token before this file ran). Must not reference
 * STDOUT/STDERR/$argv — this file is web-only but shares lib.php with the
 * CLI daemon. status/start/stop never talk to rcd directly (read the
 * heartbeat db, mutate only by invoking rc.godwit). The remotes_* actions
 * are the deliberate exception (Phase 2): remote config lives in rcd's
 * memory, not just on disk, so managing it has to go through rcd's own rc
 * API — see godwit_handle_remote_action(), which authenticates using the
 * credentials godwitd itself generated and wrote to a runtime-only file
 * (godwit_write_rc_credentials()/godwit_read_rc_credentials()); this file
 * never generates or stores rcd credentials of its own.
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

// Matches godwitd's own startup (see lib.php's godwit_resolve_timezone()) —
// without this, jobs_status's status_text (v0.4.2) would compute "resumes
// HH:MM" against PHP's UTC default instead of the daemon's real local
// clock, landing hours off on the host (which has no date.timezone ini
// setting and defaults to UTC).
date_default_timezone_set(godwit_resolve_timezone(getenv('GODWIT_TZ') ?: null));

header('Content-Type: application/json');

$pidFile = getenv('GODWIT_PIDFILE') ?: '/var/run/godwit.pid';
$stateDir = getenv('GODWIT_STATEDIR') ?: '/mnt/cache/appdata/godwit';
$runDir = getenv('GODWIT_RUNDIR') ?: '/var/run/godwit';
$dbPath = $stateDir . '/godwit.db';

$action = $_POST['action'] ?? 'status';

if ($action === 'start' || $action === 'stop') {
    $rc = __DIR__ . '/rc.godwit';
    // $action is checked against a fixed allow-list above, never interpolated
    // from a wider set of values, so this exec is not a shell-injection risk.
    shell_exec('bash ' . escapeshellarg($rc) . ' ' . escapeshellarg($action) . ' 2>&1');
}

$remoteActions = ['remotes_list', 'remotes_add_drive', 'remotes_add_onedrive', 'remotes_test', 'remotes_reauth', 'remotes_delete'];
if (in_array($action, $remoteActions, true)) {
    echo json_encode(godwit_handle_remote_action($action, $_POST, $dbPath, $runDir));
    return;
}

$jobActions = ['jobs_status', 'jobs_save', 'settings_save', 'run_now', 'pause', 'resume'];
if (in_array($action, $jobActions, true)) {
    $cfgDir = getenv('GODWIT_CFGDIR') ?: '/boot/config/plugins/godwit';
    echo json_encode(godwit_handle_job_action($action, $_POST, $dbPath, $runDir, $cfgDir));
    return;
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
