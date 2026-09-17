<?php
/**
 * Shared functions for godwitd (CLI) and godwit-api.php (web). Functions
 * reachable from both SAPIs must never reference STDOUT/STDERR/$argv — the
 * web SAPI has none of these (CLAUDE.md rule).
 */
declare(strict_types=1);

/**
 * Appends a timestamped line to godwitd's log file, redacted unconditionally
 * — not just at call sites that happen to carry secret-shaped data today. A
 * future call site that logs an rc response verbatim (e.g. a health-check
 * error message) must not have to remember to redact first.
 */
function godwit_log(string $logFile, string $message): void
{
    $line = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), godwit_redact($message), PHP_EOL);
    file_put_contents($logFile, $line, FILE_APPEND);
}

/**
 * Whether $dir is a real mountpoint (a separate filesystem from its
 * parent), via the `mountpoint` CLI. Used to refuse creating godwit's
 * state dir under /mnt/cache before the cache pool is actually mounted —
 * at boot, plugins install ~14s before that mount happens, and writing
 * there beforehand silently lands on the RAM rootfs, covered up (and lost)
 * once the real mount lands on top of it.
 */
function godwit_is_mountpoint(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    exec('mountpoint -q ' . escapeshellarg($dir), $output, $exitCode);
    return $exitCode === 0;
}

/**
 * Resolves whether the cache mount is ready, honoring a test override so
 * godwitd's refusal-to-start path is exercisable without a real mount:
 * $override '1'/'0' forces true/false, anything else (including the false
 * getenv() returns for an unset var) falls through to the real check.
 */
function godwit_resolve_cache_mounted($override, string $cacheDir): bool
{
    if ($override === '1') {
        return true;
    }
    if ($override === '0') {
        return false;
    }
    return godwit_is_mountpoint($cacheDir);
}

/**
 * Builds the argv for `rclone rcd`, given a listener spec. Never emits
 * --rc-no-auth (non-negotiable) and always emits --config and
 * --rc-user/--rc-pass, on *both* listener types. Ground-truthed locally
 * (Phase 2): rclone's rc auth gate is not exempted by unix-socket
 * transport — only commands rclone itself marks NoAuth (rc/noop,
 * core/version, the ones Phase 1's heartbeat already used) skip it; the
 * config/* and operations/about calls Phase 2 needs are not NoAuth and
 * 403 with "authentication must be set up on the rc server" against a
 * unix listener with no credentials configured, which is what Phase 1
 * shipped. Every listener therefore gets generated credentials now; the
 * socket directory's own permissions (0700, godwit-only) still gate who
 * can even attempt a connection, credentials gate what they can do once
 * connected.
 */
function godwit_rcd_argv(string $rcloneBin, string $configPath, array $listener, string $rcdLogFile): array
{
    $argv = [$rcloneBin, 'rcd'];

    if ($listener['type'] === 'unix') {
        $argv[] = '--rc-addr=unix://' . $listener['path'];
    } elseif ($listener['type'] === 'tcp') {
        $argv[] = '--rc-addr=' . $listener['host'] . ':' . $listener['port'];
    } else {
        throw new \InvalidArgumentException('unknown listener type: ' . $listener['type']);
    }
    $argv[] = '--rc-user=' . $listener['user'];
    $argv[] = '--rc-pass=' . $listener['pass'];

    $argv[] = '--config=' . $configPath;
    $argv[] = '--log-file=' . $rcdLogFile;
    $argv[] = '--log-level=INFO';

    return $argv;
}

/**
 * Finds a free TCP port by asking the OS for one and releasing it
 * immediately. Small window between release and rcd binding it, acceptable
 * for a fallback path that only exists because a unix socket bind failed.
 * ponytail: TOCTOU race on the freed port; retry-on-bind-failure in the
 * caller if this ever proves flaky in practice.
 */
function godwit_free_tcp_port(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        throw new \RuntimeException("could not allocate a free port: $errstr");
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/** Random base64url credential for the TCP rcd fallback's --rc-user/--rc-pass. */
function godwit_generate_credential(int $bytes = 18): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function godwit_open_db(string $dbPath): SQLite3
{
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $db = new SQLite3($dbPath);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS heartbeat (
        ts INTEGER NOT NULL,
        daemon_start_ts INTEGER NOT NULL,
        rcd_pid INTEGER NOT NULL,
        listener TEXT NOT NULL,
        rc_noop_ok INTEGER NOT NULL,
        rclone_version TEXT
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_heartbeat_ts ON heartbeat(ts)');
    // Created here (not lazily on first health check) so a READONLY web-SAPI
    // connection can always SELECT from it, even before any remote has ever
    // been checked.
    godwit_open_remotes_health_table($db);
    return $db;
}

function godwit_record_heartbeat(SQLite3 $db, int $ts, int $daemonStartTs, int $rcdPid, string $listener, bool $rcNoopOk, ?string $rcloneVersion): void
{
    $stmt = $db->prepare('INSERT INTO heartbeat (ts, daemon_start_ts, rcd_pid, listener, rc_noop_ok, rclone_version) VALUES (:ts, :start, :pid, :listener, :ok, :ver)');
    $stmt->bindValue(':ts', $ts, SQLITE3_INTEGER);
    $stmt->bindValue(':start', $daemonStartTs, SQLITE3_INTEGER);
    $stmt->bindValue(':pid', $rcdPid, SQLITE3_INTEGER);
    $stmt->bindValue(':listener', $listener, SQLITE3_TEXT);
    $stmt->bindValue(':ok', $rcNoopOk ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':ver', $rcloneVersion, SQLITE3_TEXT);
    $stmt->execute();
}

/** Keeps the heartbeat table from growing without bound; called on the daemon's hourly tick. */
function godwit_trim_heartbeat(SQLite3 $db, int $now, int $retainSeconds = 86400): void
{
    $stmt = $db->prepare('DELETE FROM heartbeat WHERE ts < :cutoff');
    $stmt->bindValue(':cutoff', $now - $retainSeconds, SQLITE3_INTEGER);
    $stmt->execute();
}

function godwit_latest_heartbeat(SQLite3 $db): ?array
{
    $row = $db->query('SELECT * FROM heartbeat ORDER BY ts DESC LIMIT 1')->fetchArray(SQLITE3_ASSOC);
    return $row === false ? null : $row;
}

function godwit_daemon_running(string $pidFile): bool
{
    if (!file_exists($pidFile)) {
        return false;
    }
    $pid = (int) trim((string) file_get_contents($pidFile));
    return $pid > 0 && posix_kill($pid, 0);
}

/**
 * Calls an rclone RC endpoint over the given listener. Returns the decoded
 * JSON body, or null on any transport/HTTP failure — callers treat a null
 * the same as "rcd not answering right now", never a fatal error.
 */
function godwit_rc_call(array $listener, string $rcPath): ?array
{
    // PLAN.md §2 lists sqlite3/pcntl/posix as confirmed on the host but not
    // curl — if it turns out to be absent, this must degrade to "no
    // heartbeat data" rather than fatal the whole daemon on an undefined
    // function call.
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    if ($listener['type'] === 'unix') {
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $listener['path']);
        curl_setopt($ch, CURLOPT_URL, 'http://localhost/' . $rcPath);
    } else {
        curl_setopt($ch, CURLOPT_URL, 'http://' . $listener['host'] . ':' . $listener['port'] . '/' . $rcPath);
    }
    // Ground-truthed (Phase 2): once rcd has any credentials configured at
    // all (now true for every listener type, see godwit_rcd_argv), it
    // requires Basic Auth on *every* rc call, including the NoAuth-marked
    // ones this function was originally written for (rc/noop, core/version)
    // — the NoAuth exemption only applies when rcd has no credentials
    // configured in the first place, which is no longer the case for us.
    if (isset($listener['user'], $listener['pass'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $listener['user'] . ':' . $listener['pass']);
    }

    $body = curl_exec($ch);
    $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);

    if (!$ok) {
        return null;
    }
    $decoded = json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : null;
}

/** Status payload for the settings page — reads only the heartbeat db and pid file, never talks to rcd itself. */
function godwit_build_status(SQLite3 $db, string $pidFile): array
{
    $heartbeat = godwit_latest_heartbeat($db);
    return [
        'daemon_running' => godwit_daemon_running($pidFile),
        'rcd_pid' => $heartbeat['rcd_pid'] ?? null,
        'listener' => $heartbeat['listener'] ?? null,
        'rclone_version' => $heartbeat['rclone_version'] ?? null,
        'rc_noop_ok' => $heartbeat !== null ? (bool) $heartbeat['rc_noop_ok'] : null,
        'last_heartbeat_ts' => $heartbeat['ts'] ?? null,
    ];
}

// === Phase 2: Remotes ======================================================
//
// Everything below is new for Phase 2. Remote CRUD and health checks talk to
// the running rcd over its rc API (never the CLI — rcd holds config in
// memory and doesn't watch the file, so a CLI `--config` write would leave a
// running rcd blind to the change until its next restart, and hourly health
// checks would fail against a remote the page just "created"). Ground-truthed
// locally against the bundled rclone v1.75.1 binary, --config against a
// scratch file, no network, no secrets (see CHANGELOG 0.2.0 for the exact
// commands run and their output):
//   - `config create <name> drive client_id=.. client_secret=.. scope=drive
//     token=<json>` persists the remote section to disk immediately, even
//     though it returns a non-empty State (a follow-up question). Declining
//     every follow-up with result "false" (oauth-confirm's "replace the
//     token?", teamdrive's "configure as Shared Drive?") walks the state
//     machine to State "" without changing anything we already set.
//   - Over the rc API the same walk needs `opt={"nonInteractive":true}` on
//     the initial `config/create` (a plain POST with no opt hangs — rcd has
//     no stdin to prompt against), and a `config/update` continue call needs
//     `parameters` present (an empty object suffices) plus `state`/`result`
//     nested *inside* `opt`, not as top-level fields — confirmed by the rc
//     framework's "Didn't find key" 400 until parameters was added.
//   - `config create <name> onedrive token=<json>` walks a similar chain,
//     but after declining the refresh-token replace it reaches
//     "choose_type_done" and must be answered "onedrive" (not declined) —
//     that answer makes rclone's own onedrive backend call Microsoft Graph
//     (`/me/drives` and `/me/drive`) using the pasted access_token to
//     resolve drive_id/drive_type itself, so Godwit never needs to. Tested
//     against a syntactically-invalid access_token, which surfaced as HTTP
//     401 `InvalidAuthenticationToken` on that exact call — that failure
//     shape is also this feature's auth-expired fixture (see
//     godwit_classify_error()) and is the "unverified until a real OneDrive
//     token exists" case called out in the handback.
//   - rclone's own remote-name validation error text: "config name contains
//     invalid characters - may only contain numbers, letters, `_`, `-`,
//     `.`, `+`, `@` and space, while not start with `-` or space, and not
//     end with space" — godwit_validate_remote_name() mirrors this rule so
//     bad names are rejected before ever reaching rcd.

/**
 * Redacts secret-shaped values from a line of text before it is ever logged.
 * Covers both the ini shape rclone.conf and our own log lines use (`key =
 * rest of the line`) and the JSON shape rc request/response bodies use
 * (`"key":"value"`, including a JSON-encoded token blob nested as a string).
 * Applied to every log line this feature writes; never trust a caller to
 * have redacted first.
 */
function godwit_redact(string $text): string
{
    foreach (['refresh_token', 'access_token', 'client_secret', 'client_id', 'token'] as $key) {
        $q = preg_quote($key, '/');
        $text = preg_replace('/"' . $q . '"\s*:\s*"(?:\\\\.|[^"\\\\])*"/i', '"' . $key . '":"<redacted>"', $text);
        // ini shape: the key starts a line, value runs to end of line (a
        // token value is itself a JSON blob that can contain spaces).
        $text = preg_replace('/(^|[\r\n])(\s*' . $q . '\s*=\s*).*/i', '$1$2<redacted>', $text);
        // inline shape: key=value embedded mid-string (e.g. a log message
        // built from rc params), value runs to the next whitespace/quote.
        $text = preg_replace('/\b' . $q . '=[^\s"\']*/i', $key . '=<redacted>', $text);
    }
    return $text;
}

/**
 * Mirrors rclone's own `config create` name-validation rule (see the
 * evidence block above) plus a clash check against remotes that already
 * exist. Returns an error string, or null if the name is valid.
 */
function godwit_validate_remote_name(string $name, array $existingNames = []): ?string
{
    if ($name === '') {
        return 'name is required';
    }
    if (!preg_match('/^[A-Za-z0-9_.+@ -]+$/', $name) || $name[0] === '-' || $name[0] === ' ' || substr($name, -1) === ' ') {
        return 'name may only contain numbers, letters, _ - . + @ and space, must not start with - or space, and must not end with space';
    }
    if (in_array($name, $existingNames, true)) {
        return "a remote named \"$name\" already exists";
    }
    return null;
}

/** Validates a pasted `rclone authorize` token blob without ever logging it. */
function godwit_validate_token_json(string $json): ?string
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return 'token must be valid JSON (paste the whole blob rclone authorize printed)';
    }
    if (empty($decoded['access_token']) || !is_string($decoded['access_token'])) {
        return 'token JSON is missing access_token';
    }
    if (empty($decoded['refresh_token']) || !is_string($decoded['refresh_token'])) {
        return 'token JSON is missing refresh_token';
    }
    return null;
}

/** Like godwit_rc_call() but posts arbitrary rc parameters and returns the decoded body even on a non-200 response, since rc error bodies (e.g. an expired-token failure from operations/about) carry the "error" field callers need to classify — a plain null there would be indistinguishable from rcd not answering at all. Returns null only on a transport failure or a non-JSON body. */
function godwit_rc_call_params(array $listener, string $rcPath, array $params): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if ($listener['type'] === 'unix') {
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $listener['path']);
        curl_setopt($ch, CURLOPT_URL, 'http://localhost/' . $rcPath);
    } else {
        curl_setopt($ch, CURLOPT_URL, 'http://' . $listener['host'] . ':' . $listener['port'] . '/' . $rcPath);
    }
    if (isset($listener['user'], $listener['pass'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $listener['user'] . ':' . $listener['pass']);
    }

    $body = curl_exec($ch);
    curl_close($ch);
    if ($body === false) {
        return null;
    }
    $decoded = json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Persists the current rcd listener (including its generated credentials)
 * to a runtime-only file so the web SAPI can build authenticated rc calls
 * for remote management without ever storing credentials of its own —
 * godwitd is the only process that generates them, on every (re)start.
 * 0600, under the same runtime dir as the socket itself, so it shares that
 * directory's trust boundary (0700, godwit-only) rather than adding a new
 * one. This is a deliberate Phase 2 architecture change from Phase 1's
 * "the web SAPI never needs rcd credentials" — see CHANGELOG 0.2.0: rc auth
 * turned out not to be exempted by unix-socket transport (see
 * godwit_rcd_argv()), so remote management genuinely needs them now.
 */
function godwit_write_rc_credentials(string $runDir, array $listener): void
{
    $path = $runDir . '/rc-credentials.json';
    file_put_contents($path, json_encode($listener));
    @chmod($path, 0600);
}

/** Reads back the listener godwit_write_rc_credentials() last wrote. Returns null if godwitd has never (yet) written one — callers must treat that as "remote management isn't available right now", not a fatal error. */
function godwit_read_rc_credentials(string $runDir): ?array
{
    $path = $runDir . '/rc-credentials.json';
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function godwit_drive_create_params(string $name, string $clientId, string $clientSecret, string $tokenJson): array
{
    return [
        'name' => $name,
        'type' => 'drive',
        'parameters' => json_encode([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'drive',
            'token' => $tokenJson,
        ]),
        'opt' => json_encode(['nonInteractive' => true]),
    ];
}

function godwit_onedrive_create_params(string $name, string $tokenJson, ?string $clientId, ?string $clientSecret): array
{
    $parameters = ['token' => $tokenJson];
    if ($clientId !== null && $clientId !== '') {
        $parameters['client_id'] = $clientId;
    }
    if ($clientSecret !== null && $clientSecret !== '') {
        $parameters['client_secret'] = $clientSecret;
    }
    return [
        'name' => $name,
        'type' => 'onedrive',
        'parameters' => json_encode($parameters),
        'opt' => json_encode(['nonInteractive' => true]),
    ];
}

/** Params for a `config/update --continue` call answering one state-machine question. See the evidence block above for why state/result live inside opt. */
function godwit_config_continue_params(string $name, string $state, string $result): array
{
    return [
        'name' => $name,
        'parameters' => '{}',
        'opt' => json_encode(['nonInteractive' => true, 'continue' => true, 'state' => $state, 'result' => $result]),
    ];
}

/**
 * Walks rclone's non-interactive config state machine to completion,
 * declining every confirmation ("replace the token?", "Shared Drive?") with
 * "false" except OneDrive's account-type question ("choose_type_done"),
 * which must be answered "onedrive" so rclone resolves drive_id/drive_type
 * itself via its own Graph call. $call is injected so tests can drive this
 * against a fixture sequence instead of a real rcd. Throws on a
 * backend-reported Error (e.g. the token doesn't work) — callers classify
 * the message with godwit_classify_error() before showing it to the user.
 */
function godwit_walk_config_state(callable $call, string $name, array $response, string $type): array
{
    $steps = 0;
    while (!empty($response['State']) && $steps < 10) {
        if (!empty($response['Error'])) {
            throw new \RuntimeException((string) $response['Error']);
        }
        $state = $response['State'];
        $result = ($type === 'onedrive' && $state === 'choose_type_done') ? 'onedrive' : 'false';
        $response = $call(godwit_config_continue_params($name, $state, $result));
        if (!is_array($response)) {
            throw new \RuntimeException('rcd did not answer the config continue call');
        }
        $steps++;
    }
    if (!empty($response['Error'])) {
        throw new \RuntimeException((string) $response['Error']);
    }
    if (!empty($response['State'])) {
        // The step cap was hit with the state machine still non-terminal
        // (no Error, but not State "" either) — e.g. a real OneDrive
        // "driveid" manual-entry prompt with no attached Error text. Both
        // callers (remotes_add_*, remotes_reauth) only check for a thrown
        // exception before reporting success, so silently returning here
        // would report {"ok": true} for a remote rcd never finished
        // configuring. Treat it the same as an Error.
        throw new \RuntimeException('config did not reach a terminal state after ' . $steps . ' steps (stuck at "' . $response['State'] . '")');
    }
    return $response;
}

/** Reads all configured remotes via config/dump and strips every secret-shaped field before it ever leaves this function — client_id, client_secret and token are never returned to the browser, only whether each is set. */
function godwit_list_remotes(array $listener): ?array
{
    $dump = godwit_rc_call_params($listener, 'config/dump', []);
    if ($dump === null) {
        return null;
    }
    $remotes = [];
    foreach ($dump as $name => $fields) {
        if (!is_array($fields)) {
            continue;
        }
        $remotes[] = [
            'name' => $name,
            'type' => $fields['type'] ?? 'unknown',
            'has_client_secret' => !empty($fields['client_secret']),
            'has_token' => !empty($fields['token']),
            'scope' => $fields['scope'] ?? null,
            'drive_type' => $fields['drive_type'] ?? null,
        ];
    }
    return $remotes;
}

/** Classifies a backend error message as a distinct "auth-expired" state (a token that needs re-authorising) vs a generic "error" (network, quota, anything else). */
function godwit_classify_error(string $message): string
{
    foreach (['invalid_grant', 'AADSTS7000', 'InvalidAuthenticationToken', '401', 'invalid_token', 'token expired'] as $pattern) {
        if (stripos($message, $pattern) !== false) {
            return 'auth-expired';
        }
    }
    return 'error';
}

/** Parses an rc operations/about response into total/used/free bytes and a used-percentage. pct is null when the remote reports no total (e.g. an "unlimited" quota), since a percentage of nothing is meaningless, not zero. */
function godwit_parse_about(array $about): array
{
    $total = $about['total'] ?? null;
    $used = $about['used'] ?? null;
    $free = $about['free'] ?? null;
    $pct = ($total !== null && $total > 0 && $used !== null) ? ($used / $total) * 100 : null;
    return ['total' => $total, 'used' => $used, 'free' => $free, 'pct' => $pct];
}

/** One read-only health check for $remoteName: OK, or a classified failure. Never mutates anything, safe to call on demand from the page as well as from godwitd's hourly tick. */
function godwit_check_remote_about(array $listener, string $remoteName): array
{
    $resp = godwit_rc_call_params($listener, 'operations/about', ['fs' => $remoteName . ':']);
    if ($resp === null) {
        return ['status' => 'unchecked', 'total' => null, 'used' => null, 'free' => null, 'pct' => null, 'error' => 'rcd did not answer'];
    }
    if (isset($resp['error'])) {
        // Redacted defensively: an rclone backend error can in principle echo
        // back part of what it was given (e.g. an invalid client_id), and this
        // message is stored, shown on the page and passed to godwit_notify().
        $message = godwit_redact((string) $resp['error']);
        return ['status' => godwit_classify_error($message), 'total' => null, 'used' => null, 'free' => null, 'pct' => null, 'error' => $message];
    }
    return array_merge(['status' => 'ok', 'error' => null], godwit_parse_about($resp));
}

/**
 * Decides which Unraid notifications to send for a remote's new health
 * result, given its previously stored row (null if never checked before).
 * Notifies once per OK <-> auth-expired/error transition, never on every
 * tick, and once when pct crosses 90%, resetting that flag once pct drops
 * back below 90 so a genuine later crossing notifies again. A first-ever
 * check (no previous row) never itself fires a status-transition
 * notification — there is nothing to transition from.
 */
function godwit_health_notifications(string $remoteName, ?array $prevRow, array $newResult): array
{
    $notifications = [];
    $prevStatus = $prevRow['status'] ?? null;
    $newStatus = $newResult['status'];

    if ($prevStatus !== null && $prevStatus !== $newStatus) {
        if ($newStatus !== 'ok' && $prevStatus === 'ok') {
            $notifications[] = [
                'subject' => "Godwit: $remoteName is $newStatus",
                'description' => $newResult['error'] ?? "$remoteName health check reports $newStatus",
                'importance' => 'alert',
            ];
        } elseif ($newStatus === 'ok' && $prevStatus !== 'ok') {
            $notifications[] = [
                'subject' => "Godwit: $remoteName recovered",
                'description' => "$remoteName is OK again",
                'importance' => 'normal',
            ];
        }
    }

    $prevQuotaAlerted = (bool) ($prevRow['quota_alerted'] ?? false);
    $pct = $newResult['pct'] ?? null;
    $quotaAlerted = $prevQuotaAlerted;
    if ($pct !== null && $pct >= 90 && !$prevQuotaAlerted) {
        $notifications[] = [
            'subject' => "Godwit: $remoteName quota above 90%",
            'description' => sprintf('%s is at %.1f%% used', $remoteName, $pct),
            'importance' => 'warning',
        ];
        $quotaAlerted = true;
    } elseif ($pct !== null && $pct < 90 && $prevQuotaAlerted) {
        $quotaAlerted = false;
    }

    return ['notifications' => $notifications, 'quota_alerted' => $quotaAlerted];
}

/** Invokes Unraid's own notify script (confirmed on the live host at this path, used the same way by appdata.backup's ABHelper.php: -e event/source, -s subject, -d description, -i importance). Overridable via GODWIT_NOTIFY so tests stub it and assert args instead of touching the real webGui. Callers must only ever pass status/quota prose here, never remote credentials. */
function godwit_notify(string $subject, string $description, string $importance): void
{
    $script = getenv('GODWIT_NOTIFY') ?: '/usr/local/emhttp/webGui/scripts/notify';
    if (!is_file($script)) {
        return;
    }
    $cmd = escapeshellarg($script)
        . ' -e ' . escapeshellarg('Godwit')
        . ' -s ' . escapeshellarg($subject)
        . ' -d ' . escapeshellarg($description)
        . ' -i ' . escapeshellarg($importance);
    exec($cmd);
}

function godwit_open_remotes_health_table(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS remotes_health (
        name TEXT PRIMARY KEY,
        status TEXT NOT NULL,
        checked_ts INTEGER NOT NULL,
        total INTEGER,
        used INTEGER,
        free INTEGER,
        pct REAL,
        error TEXT,
        quota_alerted INTEGER NOT NULL DEFAULT 0
    )');
}

function godwit_remote_health_row(SQLite3 $db, string $name): ?array
{
    $stmt = $db->prepare('SELECT * FROM remotes_health WHERE name = :name');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row === false ? null : $row;
}

function godwit_store_remote_health(SQLite3 $db, string $name, int $ts, array $result, bool $quotaAlerted): void
{
    $stmt = $db->prepare('INSERT INTO remotes_health (name, status, checked_ts, total, used, free, pct, error, quota_alerted)
        VALUES (:name, :status, :ts, :total, :used, :free, :pct, :error, :quota_alerted)
        ON CONFLICT(name) DO UPDATE SET status=excluded.status, checked_ts=excluded.checked_ts,
            total=excluded.total, used=excluded.used, free=excluded.free, pct=excluded.pct,
            error=excluded.error, quota_alerted=excluded.quota_alerted');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':status', $result['status'], SQLITE3_TEXT);
    $stmt->bindValue(':ts', $ts, SQLITE3_INTEGER);
    $stmt->bindValue(':total', $result['total'], SQLITE3_INTEGER);
    $stmt->bindValue(':used', $result['used'], SQLITE3_INTEGER);
    $stmt->bindValue(':free', $result['free'], SQLITE3_INTEGER);
    $stmt->bindValue(':pct', $result['pct'], SQLITE3_FLOAT);
    $stmt->bindValue(':error', $result['error'], SQLITE3_TEXT);
    $stmt->bindValue(':quota_alerted', $quotaAlerted ? 1 : 0, SQLITE3_INTEGER);
    $stmt->execute();
}

/** Runs one read-only health check for $remoteName, stores it and fires any notifications it triggers. Shared by godwitd's periodic tick, its on-demand marker-file check, and the page's "Test connection" action, so all three go through the exact same notify-once logic. */
function godwit_run_health_check(SQLite3 $db, array $listener, string $remoteName): array
{
    godwit_open_remotes_health_table($db);
    $prevRow = godwit_remote_health_row($db, $remoteName);
    $result = godwit_check_remote_about($listener, $remoteName);
    $decision = godwit_health_notifications($remoteName, $prevRow, $result);
    foreach ($decision['notifications'] as $n) {
        godwit_notify($n['subject'], $n['description'], $n['importance']);
    }
    godwit_store_remote_health($db, $remoteName, time(), $result, $decision['quota_alerted']);
    return $result;
}

/** Params for a `config/update` call that replaces an existing remote's token only (re-authorise). Other fields (client_id, scope, drive_type, ...) are left exactly as they are — config/update only touches keys it's given. */
function godwit_reauth_params(string $name, string $tokenJson): array
{
    return [
        'name' => $name,
        'parameters' => json_encode(['token' => $tokenJson]),
        'opt' => json_encode(['nonInteractive' => true]),
    ];
}

/** Whether a hypothetical job references $name. No job type exists yet (Phase 3+), so this is always false today via the "no jobs.json" branch — kept as the hook Phase 3's job format wires a real check into before remote deletion is exposed as truly safe to run unattended. */
function godwit_remote_in_use(string $name, string $cfgDir): bool
{
    $jobsFile = $cfgDir . '/jobs.json';
    if (!is_file($jobsFile)) {
        return false;
    }
    $jobs = json_decode((string) file_get_contents($jobsFile), true);
    if (!is_array($jobs)) {
        return false;
    }
    foreach ($jobs as $job) {
        if (($job['remote'] ?? null) === $name) {
            return true;
        }
    }
    return false;
}

/**
 * All remote-management actions posted from the page funnel through here.
 * Every branch talks to rcd using the credentials godwitd itself generated
 * and wrote via godwit_write_rc_credentials() — the web SAPI never
 * generates or stores rcd credentials of its own, only reads the current
 * ones back (godwit_read_rc_credentials()). Returns an array ready for
 * json_encode() straight back to the browser; no branch ever returns a
 * secret value.
 */
function godwit_handle_remote_action(string $action, array $post, string $dbPath, string $runDir): array
{
    if (!is_file($dbPath)) {
        return ['error' => 'heartbeat database not found yet — is godwitd running?'];
    }
    try {
        $db = new SQLite3($dbPath);
        $db->busyTimeout(5000);
    } catch (\Throwable $e) {
        return ['error' => 'state database unavailable'];
    }

    $listener = godwit_read_rc_credentials($runDir);
    if ($listener === null) {
        return ['error' => 'remote management is unavailable right now — godwitd has not written rcd credentials yet. Make sure godwitd is running and try again in a moment.'];
    }

    if ($action === 'remotes_list') {
        $remotes = godwit_list_remotes($listener);
        if ($remotes === null) {
            return ['error' => 'rcd did not answer'];
        }
        foreach ($remotes as &$remote) {
            $health = godwit_remote_health_row($db, $remote['name']);
            $remote['status'] = $health['status'] ?? 'unchecked';
            $remote['checked_ts'] = $health['checked_ts'] ?? null;
            $remote['total'] = $health['total'] ?? null;
            $remote['used'] = $health['used'] ?? null;
            $remote['free'] = $health['free'] ?? null;
            $remote['pct'] = $health['pct'] ?? null;
        }
        unset($remote);
        return ['remotes' => $remotes];
    }

    if ($action === 'remotes_test') {
        $name = (string) ($post['name'] ?? '');
        if ($name === '') {
            return ['error' => 'name is required'];
        }
        return ['result' => godwit_run_health_check($db, $listener, $name)];
    }

    if ($action === 'remotes_delete') {
        $name = (string) ($post['name'] ?? '');
        if ($name === '') {
            return ['error' => 'name is required'];
        }
        if (($post['confirm'] ?? '') !== '1') {
            return ['error' => 'delete requires confirm=1'];
        }
        $cfgDir = getenv('GODWIT_CFGDIR') ?: '/boot/config/plugins/godwit';
        if (godwit_remote_in_use($name, $cfgDir)) {
            return ['error' => "\"$name\" is referenced by a job and cannot be deleted"];
        }
        $resp = godwit_rc_call_params($listener, 'config/delete', ['name' => $name]);
        if ($resp === null) {
            return ['error' => 'rcd did not answer'];
        }
        return ['ok' => true];
    }

    if ($action === 'remotes_add_drive' || $action === 'remotes_add_onedrive') {
        $name = (string) ($post['name'] ?? '');
        $tokenJson = (string) ($post['token'] ?? '');
        $existing = godwit_list_remotes($listener) ?? [];
        $existingNames = array_map(fn ($r) => $r['name'], $existing);

        $nameError = godwit_validate_remote_name($name, $existingNames);
        if ($nameError !== null) {
            return ['error' => $nameError];
        }
        $tokenError = godwit_validate_token_json($tokenJson);
        if ($tokenError !== null) {
            return ['error' => $tokenError];
        }

        if ($action === 'remotes_add_drive') {
            $clientId = (string) ($post['client_id'] ?? '');
            $clientSecret = (string) ($post['client_secret'] ?? '');
            if ($clientId === '' || $clientSecret === '') {
                return ['error' => 'client_id and client_secret are required for Google Drive'];
            }
            $params = godwit_drive_create_params($name, $clientId, $clientSecret, $tokenJson);
            $type = 'drive';
        } else {
            $clientId = ((string) ($post['client_id'] ?? '')) ?: null;
            $clientSecret = ((string) ($post['client_secret'] ?? '')) ?: null;
            $params = godwit_onedrive_create_params($name, $tokenJson, $clientId, $clientSecret);
            $type = 'onedrive';
        }

        $initial = godwit_rc_call_params($listener, 'config/create', $params);
        if ($initial === null) {
            return ['error' => 'rcd did not answer'];
        }
        if (!empty($initial['Error'])) {
            $message = godwit_redact((string) $initial['Error']);
            return ['error' => $message, 'classification' => godwit_classify_error($message)];
        }
        $updateCall = function (array $p) use ($listener) {
            return godwit_rc_call_params($listener, 'config/update', $p);
        };
        try {
            godwit_walk_config_state($updateCall, $name, $initial, $type);
        } catch (\Throwable $e) {
            // Roll back the half-created remote rather than leaving a broken
            // entry the page can't fix except by hand.
            godwit_rc_call_params($listener, 'config/delete', ['name' => $name]);
            $message = godwit_redact($e->getMessage());
            return ['error' => $message, 'classification' => godwit_classify_error($message)];
        }
        return ['ok' => true, 'name' => $name];
    }

    if ($action === 'remotes_reauth') {
        $name = (string) ($post['name'] ?? '');
        $tokenJson = (string) ($post['token'] ?? '');
        $tokenError = godwit_validate_token_json($tokenJson);
        if ($tokenError !== null) {
            return ['error' => $tokenError];
        }
        $existing = godwit_list_remotes($listener) ?? [];
        $current = null;
        foreach ($existing as $r) {
            if ($r['name'] === $name) {
                $current = $r;
            }
        }
        if ($current === null) {
            return ['error' => "no remote named \"$name\""];
        }
        $updateCall = function (array $p) use ($listener) {
            return godwit_rc_call_params($listener, 'config/update', $p);
        };
        $initial = $updateCall(godwit_reauth_params($name, $tokenJson));
        if ($initial === null) {
            return ['error' => 'rcd did not answer'];
        }
        try {
            godwit_walk_config_state($updateCall, $name, $initial, $current['type']);
        } catch (\Throwable $e) {
            $message = godwit_redact($e->getMessage());
            return ['error' => $message, 'classification' => godwit_classify_error($message)];
        }
        return ['ok' => true];
    }

    return ['error' => 'unknown action'];
}
