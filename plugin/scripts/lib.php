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
 * error message) must not have to remember to redact first. Control
 * characters (newlines above all) are stripped for the same reason: a
 * user-supplied remote name reaches here before godwit_validate_remote_name()
 * has had a chance to reject it (e.g. the empty-name and unknown-remote
 * branches of godwit_handle_remote_action()), so a name containing "\n[fake
 * timestamp] ..." must not be able to forge what looks like a second, distinct
 * log line.
 */
function godwit_log(string $logFile, string $message): void
{
    // Redact first (its ini-shape pattern relies on real \r\n as a line
    // boundary to redact a multi-line config dump), then collapse any
    // remaining control characters so nothing in the message can forge what
    // looks like a second, separately-timestamped log line.
    $redacted = godwit_redact($message);
    $sanitized = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $redacted);
    $line = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $sanitized, PHP_EOL);
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
 * --rc-no-auth (non-negotiable) and always emits --config. Credentials are
 * deliberately NOT included here — see godwit_rcd_env(). Ground-truthed
 * locally (Phase 2): rclone's rc auth gate is not exempted by unix-socket
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

    $argv[] = '--config=' . $configPath;
    $argv[] = '--log-file=' . $rcdLogFile;
    $argv[] = '--log-level=INFO';

    return $argv;
}

/**
 * Environment variables carrying rcd's credentials, kept OUT of argv on
 * purpose. Found live on the host during Phase 2 verification (not caught
 * by review, which doesn't run `ps aux`): a subprocess's command line is
 * visible to any other local process via `ps aux` / `/proc/<pid>/cmdline`,
 * so `--rc-user=`/`--rc-pass=` on argv would print the credentials godwitd
 * just generated to any process on the host that can run `ps`. Confirmed
 * locally that the bundled rclone v1.75.1 honours RCLONE_RC_USER /
 * RCLONE_RC_PASS identically to the flags (rclone's standard
 * RCLONE_<FLAG> environment-variable convention) — same rc/noop
 * behaviour, same 401 without them, and `ps aux` on the spawned process
 * shows no credentials at all.
 */
/**
 * Phase 3 additions: drive_chunk_size and drive_stop_on_upload_limit are
 * backend options, not `_config` rc keys, so they're set once for rcd's
 * whole lifetime via env — rclone's standard RCLONE_<BACKEND>_<FLAG>
 * convention, ground-truthed against the bundled v1.75.1 binary the same
 * way RCLONE_RC_USER/PASS was in Phase 2 (`rclone help flags` lists
 * --drive-chunk-size / --drive-stop-on-upload-limit; the env form follows
 * directly from rclone's documented convention). 64 MiB chunk size: large
 * enough to keep per-chunk HTTP overhead low for the multi-GB files in
 * Teegan (a 134 GB file is ~2100 chunks at 64Mi vs ~16500 at the 8Mi
 * default) without ballooning per-transfer memory at the default 4
 * concurrent transfers (4 × 64Mi ≈ 256 MiB, a reasonable ceiling on Unraid
 * hardware). stop_on_upload_limit=true makes a hit against Google's
 * undocumented 750 GiB/day cap a classified, loggable job error (§4.3's
 * last line of defence) instead of an endless retry loop.
 */
function godwit_rcd_env(array $listener): array
{
    return [
        'RCLONE_RC_USER' => $listener['user'],
        'RCLONE_RC_PASS' => $listener['pass'],
        'RCLONE_DRIVE_CHUNK_SIZE' => '64M',
        'RCLONE_DRIVE_STOP_ON_UPLOAD_LIMIT' => 'true',
    ];
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

/**
 * Extracts the first top-level `{...}` JSON object from a pasted blob,
 * tolerating `rclone authorize`'s own surrounding lines ("Paste the following
 * into your remote machine --->" / "<---End paste") and extra whitespace.
 * Returns null if no balanced `{...}` is found — braces inside a JSON string
 * value are skipped correctly, so a token value containing "{" doesn't
 * confuse the scan. Never logs $raw.
 */
function godwit_extract_token_json(string $raw): ?string
{
    $start = strpos($raw, '{');
    if ($start === false) {
        return null;
    }
    $depth = 0;
    $inString = false;
    $escaped = false;
    for ($i = $start; $i < strlen($raw); $i++) {
        $c = $raw[$i];
        if ($inString) {
            if ($escaped) {
                $escaped = false;
            } elseif ($c === '\\') {
                $escaped = true;
            } elseif ($c === '"') {
                $inString = false;
            }
            continue;
        }
        if ($c === '"') {
            $inString = true;
        } elseif ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($raw, $start, $i - $start + 1);
            }
        }
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

/** Provider name for the `rclone authorize "<provider>"` command a user re-runs, per remote type. */
function godwit_authorize_provider(string $type): string
{
    return $type === 'drive' ? 'drive' : 'onedrive';
}

/**
 * Friendly message for a token whose access part is dead or about to be —
 * upstream rclone bug (verified against v1.75.1 and current master
 * backend/onedrive/onedrive.go): every non-interactive config-wizard state
 * after the first calls oauthutil.NewClient() with the package-global
 * oauthConfig, whose TokenURL/AuthURL are only filled in by makeOauthConfig()
 * for state "" — so a mid-walk refresh attempt posts to "" and fails with
 * "unsupported protocol scheme \"\"" instead of refreshing. $expiryTs is the
 * parsed expiry Unix timestamp when known (omits "at HH:MM" otherwise, e.g.
 * when classifying a backend error where the token had no expiry field).
 */
function godwit_expired_token_message(string $type, ?int $expiryTs): string
{
    $provider = godwit_authorize_provider($type);
    $when = $expiryTs !== null ? ' at ' . date('H:i', $expiryTs) . ' (local time)' : '';
    return "This token's access part expired{$when}. Run `rclone authorize \"$provider\"` again and add it within the hour — rclone can't refresh it during setup (upstream rclone bug).";
}

/**
 * Pre-checks a pasted token's `expiry` field (RFC3339, as `rclone authorize`
 * emits) before Godwit ever calls rcd, so an already-dead token is refused
 * with a clear message instead of failing opaquely inside the config walk
 * (see godwit_expired_token_message()'s doc for the upstream bug it's
 * working around). A missing or unparseable expiry is not blocked — only a
 * token this can positively prove is dead (or about to be, <5min) is
 * refused. Returns null if the token is fine, or we can't tell.
 */
function godwit_token_expiry_error(string $tokenJson, string $type): ?string
{
    $decoded = json_decode($tokenJson, true);
    $expiry = is_array($decoded) ? ($decoded['expiry'] ?? null) : null;
    if (!is_string($expiry) || $expiry === '') {
        return null;
    }
    $ts = strtotime($expiry);
    if ($ts === false || $ts - time() >= 300) {
        return null;
    }
    return godwit_expired_token_message($type, $ts);
}

/**
 * Classifies rclone's own "couldn't fetch token: Post \"\": unsupported
 * protocol scheme \"\"" error — the same upstream bug godwit_token_expiry_error()
 * pre-checks for — into the friendly expiry message, in case the token
 * expired mid-walk rather than before it started. Re-reads $tokenJson's
 * expiry (if any) so the message can still show a time. Any other message
 * passes through unchanged.
 */
function godwit_classify_walk_error(string $message, string $tokenJson, string $type): string
{
    if (stripos($message, 'unsupported protocol scheme') === false) {
        return $message;
    }
    $decoded = json_decode($tokenJson, true);
    $expiry = is_array($decoded) ? ($decoded['expiry'] ?? null) : null;
    $ts = is_string($expiry) ? strtotime($expiry) : false;
    return godwit_expired_token_message($type, $ts !== false ? $ts : null);
}

/**
 * Like godwit_rc_call() but posts arbitrary rc parameters and returns the
 * decoded body even on a non-200 response, since rc error bodies (e.g. an
 * expired-token failure from operations/about) carry the "error" field
 * callers need to classify — a plain null there would be indistinguishable
 * from rcd not answering at all. Returns null only on a transport failure or
 * a non-JSON body. $meta, if passed, is populated with ['timed_out' => bool]
 * so a caller can tell a curl timeout apart from any other transport failure
 * (e.g. rcd not running) — both otherwise collapse to the same null.
 */
function godwit_rc_call_params(array $listener, string $rcPath, array $params, int $timeoutSeconds = 15, ?array &$meta = null): ?array
{
    $meta = ['timed_out' => false];
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);

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
    if ($body === false && curl_errno($ch) === CURLE_OPERATION_TIMEDOUT) {
        $meta = ['timed_out' => true];
    }
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
 * Thrown by godwit_choose_onedrive_drive() when OneDrive's "config_driveid"
 * Examples list has more than one entry and no drive_id was supplied to pick
 * from it. Carries the offered choices (id/label pairs) and a suggested id
 * (the one named exactly "OneDrive", if any) so the caller can roll back the
 * half-created remote and hand the choice back to the page — see
 * CHANGELOG 0.2.4. Replaces 0.2.3's "uniquely personal" auto-pick heuristic,
 * which fails on any account (like Kieren's) with more than one
 * personal-labelled drive.
 */
final class GodwitDriveChoiceNeeded extends \RuntimeException
{
    /** @var array<int, array{id: string, label: string}> */
    public array $choices;
    public ?string $suggested;

    public function __construct(array $choices, ?string $suggested)
    {
        parent::__construct('multiple OneDrive drives found — choose one');
        $this->choices = $choices;
        $this->suggested = $suggested;
    }
}

/**
 * Picks a drive ID from OneDrive's "config_driveid" Examples. Each example's
 * Value is a real drive ID and its Help is "DriveName (driveType)" (rclone
 * v1.75.1 backend/onedrive/onedrive.go chooseDrive(), fmt.Sprintf("%s (%s)",
 * DriveName, DriveType)).
 * A single drive is used automatically. With more than one, $driveId (from a
 * page-side picker choice) is used when it matches one of the Examples;
 * otherwise this throws GodwitDriveChoiceNeeded so the caller can roll back
 * and offer a picker instead of guessing.
 */
function godwit_choose_onedrive_drive(?array $opt, ?string &$driveChosen, ?string $driveId = null): string
{
    $examples = is_array($opt) ? ($opt['Examples'] ?? []) : [];
    if (!is_array($examples) || count($examples) === 0) {
        throw new \RuntimeException('OneDrive returned no drives to choose from');
    }
    if ($driveId !== null) {
        foreach ($examples as $ex) {
            if ((string) ($ex['Value'] ?? '') === $driveId) {
                $driveChosen = (string) ($ex['Help'] ?? '');
                return $driveId;
            }
        }
        throw new \RuntimeException('drive_id is not one of the offered drives');
    }
    if (count($examples) === 1) {
        $driveChosen = (string) ($examples[0]['Help'] ?? '');
        return (string) $examples[0]['Value'];
    }
    $choices = array_map(fn ($ex) => ['id' => (string) ($ex['Value'] ?? ''), 'label' => (string) ($ex['Help'] ?? '')], $examples);
    $suggested = null;
    foreach ($choices as $c) {
        if (preg_match('/^OneDrive \(/', $c['label']) === 1) {
            $suggested = $c['id'];
            break;
        }
    }
    throw new GodwitDriveChoiceNeeded($choices, $suggested);
}

/**
 * Falls back to an unrecognised Option's own DefaultStr when it has a
 * non-empty one; otherwise fails loudly naming the question (Name + first
 * line of Help) instead of guessing "false" — a wrong guess here is exactly
 * how the OneDrive drive-selection bug shipped in the first place.
 */
function godwit_config_default_answer(?array $opt, ?string $optName): string
{
    $default = is_array($opt) ? ($opt['DefaultStr'] ?? null) : null;
    if ($default !== null && $default !== '') {
        return (string) $default;
    }
    $help = is_array($opt) ? (string) ($opt['Help'] ?? '') : '';
    $firstLine = trim((string) strtok($help, "\n"));
    $label = $optName !== null ? "\"$optName\"" : '(unnamed)';
    throw new \RuntimeException("unexpected config question $label" . ($firstLine !== '' ? ": $firstLine" : ''));
}

/**
 * Walks rclone's non-interactive config state machine to completion,
 * answering each question from its Option metadata (Name, and Examples for
 * choices) instead of a blanket "false". Verified against rclone v1.75.1
 * source:
 * - lib/oauthutil/oauthutil.go: "config_refresh_token" confirm ("Token
 *   already configured - replace it?", defaults true) — declined with
 *   "false" so a freshly pasted token always wins.
 * - backend/drive/drive.go Config(): "config_change_team_drive" confirm —
 *   declined with "false"; Google Drive's live add path is unchanged.
 * - backend/onedrive/onedrive.go Config()/chooseDrive(): "config_type"
 *   (state "choose_type_done") answered "onedrive"; the following
 *   "config_driveid" question (state "driveid_final") MUST be answered with
 *   one of Option.Examples[].Value — a real drive ID, not "false" (sending
 *   "false" made rclone GET /drives/false/root and 400, the bug this walker
 *   used to have) — see godwit_choose_onedrive_drive(); "config_drive_ok"
 *   (state "driveid_final_end") confirms the chosen drive and MUST be
 *   answered "true", since anything else loops back to "choose_type".
 * $call is injected so tests can drive this against a fixture sequence
 * instead of a real rcd. Throws on a backend-reported Error (e.g. the token
 * doesn't work), a question this walker can't answer, or (OneDrive, multiple
 * drives, no $driveId) GodwitDriveChoiceNeeded — callers classify a plain
 * exception's message with godwit_classify_error() before showing it to the
 * user, and handle GodwitDriveChoiceNeeded separately. $driveChosen is set
 * to the chosen drive's label when OneDrive's drive question was answered,
 * so the caller can report it on success. $driveId, OneDrive only, answers
 * "config_driveid" with that value when the Examples list has more than one
 * entry — see godwit_choose_onedrive_drive().
 */
function godwit_walk_config_state(callable $call, string $name, array $response, string $type, ?string &$driveChosen = null, ?string $driveId = null): array
{
    $driveChosen = null;
    $steps = 0;
    while (!empty($response['State']) && $steps < 10) {
        if (!empty($response['Error'])) {
            throw new \RuntimeException((string) $response['Error']);
        }
        $state = $response['State'];
        $opt = $response['Option'] ?? null;
        $optName = is_array($opt) ? ($opt['Name'] ?? null) : null;
        if ($optName === 'config_refresh_token' || $optName === 'config_change_team_drive') {
            $result = 'false';
        } elseif ($optName === 'config_type' && $type === 'onedrive') {
            $result = 'onedrive';
        } elseif ($optName === 'config_driveid' && $type === 'onedrive') {
            $result = godwit_choose_onedrive_drive($opt, $driveChosen, $driveId);
        } elseif ($optName === 'config_drive_ok') {
            $result = 'true';
        } else {
            $result = godwit_config_default_answer($opt, $optName);
        }
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

/**
 * One read-only health check for $remoteName: OK, or a classified failure.
 * Never mutates anything, safe to call on demand from the page as well as
 * from godwitd's hourly tick. A failed check is always "error" (with a
 * distinct "timed out after Ns" message when that's what happened) —
 * "unchecked" means "never checked" and is only ever set by remotes_list for
 * a remote with no health row yet, never returned from here.
 * $call is injected (default: godwit_rc_call_params) so tests can simulate a
 * timeout without a real slow network call, same pattern as
 * godwit_walk_config_state()'s $call parameter.
 */
function godwit_check_remote_about(array $listener, string $remoteName, int $timeoutSeconds = 60, ?callable $call = null): array
{
    $call = $call ?? 'godwit_rc_call_params';
    $meta = null;
    $resp = $call($listener, 'operations/about', ['fs' => $remoteName . ':'], $timeoutSeconds, $meta);
    if ($resp === null) {
        $error = ($meta['timed_out'] ?? false) ? "timed out after {$timeoutSeconds}s" : 'rcd did not answer';
        return ['status' => 'error', 'total' => null, 'used' => null, 'free' => null, 'pct' => null, 'error' => $error];
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
 * "error" is treated as possibly transient (one rcd hiccup shouldn't page
 * anyone): it notifies only on the 2nd *consecutive* error, tracked via the
 * returned 'fail_count', and
 * recovery only notifies if a failure was actually reported (fail_count
 * reached 2, or the status was auth-expired). "auth-expired" is treated as
 * decisive and notifies on the very first occurrence. Never notifies on
 * every tick while a status holds steady, and once when pct crosses 90%,
 * resetting that flag once pct drops back below 90 so a genuine later
 * crossing notifies again. A first-ever check (no previous row) never itself
 * fires a status-transition notification — there is nothing to transition
 * from.
 */
function godwit_health_notifications(string $remoteName, ?array $prevRow, array $newResult): array
{
    $notifications = [];
    $prevStatus = $prevRow['status'] ?? null;
    $newStatus = $newResult['status'];
    $prevFailCount = (int) ($prevRow['fail_count'] ?? 0);
    $failCount = ($newStatus === 'error') ? ($prevStatus === 'error' ? $prevFailCount + 1 : 1) : 0;
    $prevFailureWasNotified = $prevStatus === 'auth-expired' || ($prevStatus === 'error' && $prevFailCount >= 2);

    if ($prevStatus !== null && $prevStatus !== $newStatus) {
        if ($newStatus === 'auth-expired' && $prevStatus !== 'auth-expired') {
            $notifications[] = [
                'subject' => "Godwit: $remoteName is $newStatus",
                'description' => $newResult['error'] ?? "$remoteName health check reports $newStatus",
                'importance' => 'alert',
            ];
        } elseif ($newStatus === 'ok' && $prevFailureWasNotified) {
            $notifications[] = [
                'subject' => "Godwit: $remoteName recovered",
                'description' => "$remoteName is OK again",
                'importance' => 'normal',
            ];
        }
    }
    if ($newStatus === 'error' && $failCount === 2) {
        $notifications[] = [
            'subject' => "Godwit: $remoteName is $newStatus",
            'description' => $newResult['error'] ?? "$remoteName health check reports $newStatus",
            'importance' => 'alert',
        ];
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

    return ['notifications' => $notifications, 'quota_alerted' => $quotaAlerted, 'fail_count' => $failCount];
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
        quota_alerted INTEGER NOT NULL DEFAULT 0,
        fail_count INTEGER NOT NULL DEFAULT 0
    )');
    // Migrates a table created by 0.2.1 or earlier, before fail_count
    // existed. exec() returns false (not an exception, exceptions mode is
    // never enabled here) once the column already exists — safe to run on
    // every open.
    @$db->exec('ALTER TABLE remotes_health ADD COLUMN fail_count INTEGER NOT NULL DEFAULT 0');
}

function godwit_remote_health_row(SQLite3 $db, string $name): ?array
{
    $stmt = $db->prepare('SELECT * FROM remotes_health WHERE name = :name');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row === false ? null : $row;
}

function godwit_store_remote_health(SQLite3 $db, string $name, int $ts, array $result, bool $quotaAlerted, int $failCount): void
{
    $stmt = $db->prepare('INSERT INTO remotes_health (name, status, checked_ts, total, used, free, pct, error, quota_alerted, fail_count)
        VALUES (:name, :status, :ts, :total, :used, :free, :pct, :error, :quota_alerted, :fail_count)
        ON CONFLICT(name) DO UPDATE SET status=excluded.status, checked_ts=excluded.checked_ts,
            total=excluded.total, used=excluded.used, free=excluded.free, pct=excluded.pct,
            error=excluded.error, quota_alerted=excluded.quota_alerted, fail_count=excluded.fail_count');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':status', $result['status'], SQLITE3_TEXT);
    $stmt->bindValue(':ts', $ts, SQLITE3_INTEGER);
    $stmt->bindValue(':total', $result['total'], SQLITE3_INTEGER);
    $stmt->bindValue(':used', $result['used'], SQLITE3_INTEGER);
    $stmt->bindValue(':free', $result['free'], SQLITE3_INTEGER);
    $stmt->bindValue(':pct', $result['pct'], SQLITE3_FLOAT);
    $stmt->bindValue(':error', $result['error'], SQLITE3_TEXT);
    $stmt->bindValue(':quota_alerted', $quotaAlerted ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':fail_count', $failCount, SQLITE3_INTEGER);
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
    $ts = time();
    godwit_store_remote_health($db, $remoteName, $ts, $result, $decision['quota_alerted'], $decision['fail_count']);
    return $result + ['checked_ts' => $ts];
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
 * secret value. On-demand test/add/reauth/delete outcomes (success or
 * failure, reason redacted) are logged to $logFile — remotes_list isn't,
 * since the page polls it every 30s and that would just be noise.
 */
function godwit_handle_remote_action(string $action, array $post, string $dbPath, string $runDir, ?string $logFile = null, ?callable $rcCall = null): array
{
    $logFile = $logFile ?? (getenv('GODWIT_LOG') ?: '/var/log/godwit.log');
    $rcCall = $rcCall ?? 'godwit_rc_call_params';
    $log = function (string $message) use ($logFile): void {
        godwit_log($logFile, $message);
    };

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
        $result = godwit_run_health_check($db, $listener, $name);
        $log("remote test $name: {$result['status']}" . ($result['error'] !== null ? " ({$result['error']})" : ''));
        return ['result' => $result];
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
            $log("remote delete $name: failed — referenced by a job");
            return ['error' => "\"$name\" is referenced by a job and cannot be deleted"];
        }
        $resp = godwit_rc_call_params($listener, 'config/delete', ['name' => $name]);
        if ($resp === null) {
            $log("remote delete $name: failed — rcd did not answer");
            return ['error' => 'rcd did not answer'];
        }
        $log("remote delete $name: ok");
        return ['ok' => true];
    }

    if ($action === 'remotes_add_drive' || $action === 'remotes_add_onedrive') {
        $type = $action === 'remotes_add_drive' ? 'drive' : 'onedrive';
        $name = (string) ($post['name'] ?? '');
        $rawToken = (string) ($post['token'] ?? '');

        // Name checked first (even though the token is parsed for the calls
        // below) so a blank name is reported as the actual problem instead of
        // being hidden behind an unrelated token error.
        $existing = godwit_list_remotes($listener) ?? [];
        $existingNames = array_map(fn ($r) => $r['name'], $existing);
        $nameError = godwit_validate_remote_name($name, $existingNames);
        if ($nameError !== null) {
            $log("remote add $type" . ($name !== '' ? " $name" : '') . ": failed validation: $nameError");
            return ['error' => $nameError];
        }

        $extracted = godwit_extract_token_json($rawToken);
        if ($extracted === null) {
            $log("remote add $type $name: failed validation: no JSON object found in the pasted token");
            return ['error' => 'no JSON object found in the pasted text — paste the whole blob rclone authorize printed'];
        }
        $tokenJson = $extracted;
        $tokenError = godwit_validate_token_json($tokenJson);
        if ($tokenError !== null) {
            $log("remote add $type $name: failed validation: $tokenError");
            return ['error' => $tokenError];
        }
        $expiryError = godwit_token_expiry_error($tokenJson, $type);
        if ($expiryError !== null) {
            $log("remote add $type $name: failed validation: $expiryError");
            return ['error' => $expiryError];
        }

        if ($type === 'drive') {
            $clientId = (string) ($post['client_id'] ?? '');
            $clientSecret = (string) ($post['client_secret'] ?? '');
            if ($clientId === '' || $clientSecret === '') {
                $log("remote add $type $name: failed validation: client_id and client_secret are required");
                return ['error' => 'client_id and client_secret are required for Google Drive'];
            }
            $params = godwit_drive_create_params($name, $clientId, $clientSecret, $tokenJson);
        } else {
            $clientId = ((string) ($post['client_id'] ?? '')) ?: null;
            $clientSecret = ((string) ($post['client_secret'] ?? '')) ?: null;
            $params = godwit_onedrive_create_params($name, $tokenJson, $clientId, $clientSecret);
        }

        $initial = $rcCall($listener, 'config/create', $params, 60);
        if ($initial === null) {
            $log("remote add $type $name: failed — rcd did not answer");
            return ['error' => 'rcd did not answer'];
        }
        if (!empty($initial['Error'])) {
            $message = godwit_redact((string) $initial['Error']);
            $log("remote add $type $name: failed: $message");
            return ['error' => $message, 'classification' => godwit_classify_error($message)];
        }
        $updateCall = function (array $p) use ($listener, $rcCall) {
            return $rcCall($listener, 'config/update', $p, 60);
        };
        $driveId = $type === 'onedrive' && ($post['drive_id'] ?? '') !== '' ? (string) $post['drive_id'] : null;
        $driveChosen = null;
        try {
            godwit_walk_config_state($updateCall, $name, $initial, $type, $driveChosen, $driveId);
        } catch (GodwitDriveChoiceNeeded $e) {
            // Roll back the half-created remote — same as any other walk
            // failure below — and hand the choice back to the page instead
            // of guessing.
            $rcCall($listener, 'config/delete', ['name' => $name]);
            $log("remote add $type $name: multiple drives found, awaiting choice");
            return ['choose_drive' => $e->choices, 'suggested' => $e->suggested];
        } catch (\Throwable $e) {
            // Roll back the half-created remote rather than leaving a broken
            // entry the page can't fix except by hand.
            $rcCall($listener, 'config/delete', ['name' => $name]);
            $message = godwit_redact(godwit_classify_walk_error($e->getMessage(), $tokenJson, $type));
            $log("remote add $type $name: failed: $message");
            return ['error' => $message, 'classification' => godwit_classify_error($message)];
        }
        $log("remote add $type $name: ok" . ($driveChosen !== null ? " (drive: $driveChosen)" : ''));
        // Wakes godwitd's loop (polls this marker every second) so the new
        // remote gets its first health check within a second or two instead
        // of waiting up to an hour — see godwitd's docblock for the marker.
        @touch($runDir . '/check-now');
        $result = ['ok' => true, 'name' => $name, 'type' => $type];
        if ($driveChosen !== null) {
            $result['drive'] = $driveChosen;
        }
        return $result;
    }

    if ($action === 'remotes_reauth') {
        $name = (string) ($post['name'] ?? '');
        $rawToken = (string) ($post['token'] ?? '');
        $extracted = godwit_extract_token_json($rawToken);
        if ($extracted === null) {
            $log("remote reauth $name: failed validation: no JSON object found in the pasted token");
            return ['error' => 'no JSON object found in the pasted text — paste the whole blob rclone authorize printed'];
        }
        $tokenJson = $extracted;
        $tokenError = godwit_validate_token_json($tokenJson);
        if ($tokenError !== null) {
            $log("remote reauth $name: failed validation: $tokenError");
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
            $log("remote reauth $name: failed — no such remote");
            return ['error' => "no remote named \"$name\""];
        }
        $expiryError = godwit_token_expiry_error($tokenJson, $current['type']);
        if ($expiryError !== null) {
            $log("remote reauth $name: failed validation: $expiryError");
            return ['error' => $expiryError];
        }
        $updateCall = function (array $p) use ($listener, $rcCall) {
            return $rcCall($listener, 'config/update', $p, 60);
        };
        $initial = $updateCall(godwit_reauth_params($name, $tokenJson));
        if ($initial === null) {
            $log("remote reauth $name: failed — rcd did not answer");
            return ['error' => 'rcd did not answer'];
        }
        try {
            godwit_walk_config_state($updateCall, $name, $initial, $current['type']);
        } catch (\Throwable $e) {
            $message = godwit_redact(godwit_classify_walk_error($e->getMessage(), $tokenJson, $current['type']));
            $log("remote reauth $name: failed: $message");
            return ['error' => $message, 'classification' => godwit_classify_error($message)];
        }
        $log("remote reauth $name: ok");
        @touch($runDir . '/check-now');
        return ['ok' => true];
    }

    return ['error' => 'unknown action'];
}

// === Phase 3: Jobs, budget, windows, status =================================
//
// Everything below is new for Phase 3 — the first phase that writes to Google
// Drive. Every function that touches the source/destination direction is kept
// pure and separately assertable (godwit_assert_job_direction()) so the
// direction guarantee doesn't depend on remembering to check it at every call
// site. Facts ground-truthed against the bundled rclone v1.75.1 binary before
// writing any of this (see the handback for the exact commands):
//   - `rc --loopback options/get` confirms the `_config` keys used below
//     (Transfers, MaxTransfer, CutoffMode, MaxDelete, BackupDir, DryRun,
//     MaxDuration) and their casing/types (CutoffMode is the string
//     HARD|SOFT|CAUTIOUS; MaxDuration is a Duration string like "6h30m").
//   - `--bwlimit 31250000B` is accepted — rclone's `M` suffix is MiB, not
//     Mbit, so passing a raw byte count with the `B` suffix is the only way
//     to avoid a silent ~5% overshoot (250 Mbit/s ≠ "31.25M").
//   - drive_chunk_size / drive_stop_on_upload_limit are backend options, not
//     `_config` keys; set via RCLONE_DRIVE_CHUNK_SIZE / RCLONE_DRIVE_STOP_ON_UPLOAD_LIMIT
//     in rcd's environment (rclone's standard RCLONE_<BACKEND>_<FLAG>
//     convention, same one godwit_rcd_env() already relies on for
//     RCLONE_RC_USER/PASS) — this keeps dstFs a plain "remote:path" with no
//     connection-string params, which keeps godwit_assert_job_direction() and
//     the --backup-dir same-remote check trivial.

/** Excludes applied to every job regardless of share — Mac junk and Windows/Recycle Bin debris. Unanchored (no leading /) so they match at any depth. */
function godwit_global_excludes(): array
{
    return ['.DS_Store', '._*', '.Trashes/**', '.Recycle.Bin/**', 'Thumbs.db'];
}

/**
 * The four Phase 3 default jobs, in the queue order PLAN.md/CLAUDE.md specify
 * (small first, so something is fully backed up quickly): Filing Cabinet,
 * Kieren, Teegan, Photos. Kieren carries the D13/D14 exclusions
 * (TimeMachine — live sparsebundle, heavy churn; Backup/BombVault — contains
 * Godwit's own rclone.conf and the SecretsMan store, must never sit
 * unencrypted in Drive). Anchored (leading /) so only the top-level
 * TimeMachine/Backup folders are excluded, not any same-named folder deeper
 * in the tree.
 */
function godwit_default_jobs(): array
{
    return [
        ['name' => 'Filing Cabinet', 'share' => 'Filing Cabinet', 'remote' => 'gdrive', 'mode' => 'sync', 'enabled' => true, 'transfers' => 4, 'max_delete' => 1000, 'excludes' => []],
        ['name' => 'Kieren', 'share' => 'Kieren', 'remote' => 'gdrive', 'mode' => 'sync', 'enabled' => true, 'transfers' => 4, 'max_delete' => 1000, 'excludes' => ['/TimeMachine/**', '/Backup/BombVault/**']],
        ['name' => 'Teegan', 'share' => 'Teegan', 'remote' => 'gdrive', 'mode' => 'sync', 'enabled' => true, 'transfers' => 4, 'max_delete' => 1000, 'excludes' => []],
        ['name' => 'Photos', 'share' => 'Photos', 'remote' => 'gdrive', 'mode' => 'sync', 'enabled' => true, 'transfers' => 4, 'max_delete' => 1000, 'excludes' => []],
    ];
}

/** Loads jobs.json, seeding the Phase 3 defaults on first run (file absent). Never mutates disk itself — callers that seed defaults must save explicitly. */
function godwit_load_jobs(string $cfgDir): array
{
    $path = $cfgDir . '/jobs.json';
    if (!is_file($path)) {
        return godwit_default_jobs();
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function godwit_save_jobs(string $cfgDir, array $jobs): void
{
    if (!is_dir($cfgDir)) {
        mkdir($cfgDir, 0755, true);
    }
    file_put_contents($cfgDir . '/jobs.json', json_encode(array_values($jobs), JSON_PRETTY_PRINT));
}

/**
 * Compiles a job's exclude rules (global + per-job) to an ordered list of
 * rclone filter-file lines. Exclude-only filter lists need no trailing
 * "+ **" — rclone's own default for a filter with no include rules is to
 * include everything not explicitly excluded.
 */
function godwit_compile_filter_rules(array $job): array
{
    $patterns = array_merge(godwit_global_excludes(), $job['excludes'] ?? []);
    return array_map(fn ($p) => '- ' . $p, $patterns);
}

function godwit_write_filter_file(string $path, array $rules): void
{
    file_put_contents($path, implode(PHP_EOL, $rules) . PHP_EOL);
}

/**
 * The two Fs a job builds — kept separate from the rc-call params so
 * godwit_assert_job_direction() can check them before anything is ever sent
 * to rcd. $job['share'] is deliberately rejected if it contains a path
 * separator (no traversal out of /mnt/user/<share>) and dstFs is always the
 * bare "remote:path" form the direction/overlap checks assume — see the
 * RCLONE_DRIVE_* env vars above for why no connection-string params are
 * needed.
 */
function godwit_build_job_fs(array $job, string $shareRoot = '/mnt/user'): array
{
    $share = (string) ($job['share'] ?? '');
    if ($share === '' || $share === '.' || $share === '..' || strpos($share, '/') !== false || strpos($share, '..') !== false) {
        throw new \InvalidArgumentException('invalid share name for job: ' . var_export($share, true));
    }
    $remote = (string) ($job['remote'] ?? '');
    if ($remote === '' || strpos($remote, '/') !== false || strpos($remote, ':') !== false) {
        throw new \InvalidArgumentException('invalid remote name for job: ' . var_export($remote, true));
    }
    return [
        'srcFs' => rtrim($shareRoot, '/') . '/' . $share,
        'dstFs' => $remote . ':godwit/' . $share,
    ];
}

/**
 * Hard assertion (never bypassable by a caller-supplied direction) that a
 * built job's Fs pair can only ever copy from a local share into a remote —
 * srcFs must be a real absolute path under $shareRoot, dstFs must be
 * "remote:path" with no local path shape. Throws on any violation; callers
 * never send Fs pairs to rcd without calling this first.
 */
function godwit_assert_job_direction(array $fs, string $shareRoot = '/mnt/user'): void
{
    $root = rtrim($shareRoot, '/') . '/';
    if (strpos($fs['srcFs'], $root) !== 0 || strlen($fs['srcFs']) <= strlen($root)) {
        throw new \RuntimeException('refusing job: srcFs is not a local path under ' . $shareRoot . ' (' . $fs['srcFs'] . ')');
    }
    if (!preg_match('/^[A-Za-z0-9_.+@ -]+:[^:]*$/', $fs['dstFs'])) {
        throw new \RuntimeException('refusing job: dstFs is not a plain remote:path (' . $fs['dstFs'] . ')');
    }
    if (strpos($fs['dstFs'], $root) === 0) {
        throw new \RuntimeException('refusing job: dstFs looks like a local path (' . $fs['dstFs'] . ')');
    }
}

function godwit_backup_dir_fs(array $job, string $dateYmd): string
{
    return $job['remote'] . ':godwit/_versions/' . $job['share'] . '/' . $dateYmd;
}

function godwit_sync_rc_path(string $mode): string
{
    return $mode === 'copy' ? 'sync/copy' : 'sync/sync';
}

/**
 * Builds the full `sync/sync` or `sync/copy` `_async` rc call params for a
 * job. $maxTransferBytes is the remaining daily budget (§4.3: the ledger is
 * the real guard, this is belt-and-braces — Phase 1's spike found
 * MaxTransfer can overshoot by up to transfers × average file size).
 * BackupDir is only set in sync mode — copy mode never deletes, so there's
 * nothing to version. MaxDuration, when given, is the soft-stop mechanism
 * (§4.5): a non-HARD CutoffMode with a duration set to "time left in the
 * window" makes rclone itself stop picking up new files at the boundary
 * while letting an in-flight transfer finish — ponytail: this doesn't adapt
 * if the window is edited mid-run; the job just runs to MaxDuration as
 * computed at start. Re-evaluated next tick after the job's next start.
 * NoUpdateDirModTime is always on: directory timestamps have no value for
 * an offsite backup, cost API calls, and — this is the bug it fixes —
 * cannot succeed for a directory a MaxTransfer/MaxDuration cutoff never
 * reached on the destination. Ground-truthed against the bundled v1.75.1
 * binary's `options/info` rc call: the flag's rc `_config` field is
 * `NoUpdateDirModTime` (Go struct field name, not the `--no-update-dir-
 * modtime` flag spelling — `_config` takes fs.ConfigInfo field names).
 */
function godwit_build_sync_params(array $job, array $fs, string $filterFile, int $maxTransferBytes, ?string $backupDirFs, ?int $maxDurationSeconds, bool $dryRun = false, string $shareRoot = '/mnt/user'): array
{
    godwit_assert_job_direction($fs, $shareRoot);
    $config = [
        'Transfers' => (int) ($job['transfers'] ?? 4),
        'MaxDelete' => (int) ($job['max_delete'] ?? 1000),
        'CutoffMode' => 'CAUTIOUS',
        'MaxTransfer' => $maxTransferBytes,
        'DryRun' => $dryRun,
        'NoUpdateDirModTime' => true,
    ];
    if ($backupDirFs !== null) {
        $config['BackupDir'] = $backupDirFs;
    }
    if ($maxDurationSeconds !== null) {
        $config['MaxDuration'] = $maxDurationSeconds . 's';
    }
    return [
        '_async' => true,
        'srcFs' => $fs['srcFs'],
        'dstFs' => $fs['dstFs'],
        '_config' => json_encode($config),
        '_filter' => json_encode(['FilterFrom' => [$filterFile]]),
    ];
}

/**
 * Rejects a jobs.json save when two enabled jobs' destinations overlap
 * (same or nested remote:path) or a destination sits under a remote's own
 * godwit/_versions tree — either would let two jobs write the same object,
 * or a job silently back up its own version history. Returns an error
 * string, or null if the set is safe. Disabled jobs are not checked — they
 * can never run, so an overlap with one is not exploitable.
 */
function godwit_validate_job_destinations(array $jobs): ?string
{
    $dests = [];
    foreach ($jobs as $job) {
        if (empty($job['enabled'])) {
            continue;
        }
        $fs = godwit_build_job_fs($job);
        if (preg_match('#^[^:]+:godwit/_versions(/|$)#', $fs['dstFs'])) {
            return "job \"{$job['name']}\" destination sits under godwit/_versions — not allowed";
        }
        $dests[] = ['name' => $job['name'], 'dst' => rtrim($fs['dstFs'], '/') . '/'];
    }
    for ($i = 0; $i < count($dests); $i++) {
        for ($j = $i + 1; $j < count($dests); $j++) {
            $a = $dests[$i]['dst'];
            $b = $dests[$j]['dst'];
            if (strpos($a, $b) === 0 || strpos($b, $a) === 0) {
                return "jobs \"{$dests[$i]['name']}\" and \"{$dests[$j]['name']}\" have overlapping destinations";
            }
        }
    }
    return null;
}

/** Outcomes that mean "this job actually finished, for better or worse" — it must not be selected again this session. budget/window/interrupted mean it was cut short, not finished, so it stays eligible (a budget-stopped job must resume the next night, not be skipped in favour of the next job in the queue). */
function godwit_terminal_job_outcomes(): array
{
    return ['completed', 'error', 'auth', 'throttled'];
}

/**
 * Picks which enabled, not-already-running jobs to start next: at most one
 * per remote (§4.2), in jobs.json array order (queue order is simply the
 * array order the defaults seed in — Filing Cabinet, Kieren, Teegan,
 * Photos). $activeRemotes lists remotes with a job already running (from
 * either a previous selection this tick, or one still in flight from a
 * prior tick).
 *
 * $lastRuns (job name => ['ended_ts' => ?int, 'outcome' => ?string], as
 * returned by godwit_last_job_run()) and $sessionStartTs together stop the
 * queue looping on the same job forever: without this, a job that just
 * finished and was removed from the daemon's in-memory active-job tracking
 * looks identical to one that never ran, so the very next tick would
 * re-select Filing Cabinet (always first for gdrive) instead of advancing
 * to Kieren — confirmed to reproduce with a two-tick trace before this
 * fix. A job whose last run both ended at/after $sessionStartTs and
 * reached a terminal outcome (godwit_terminal_job_outcomes()) is skipped;
 * everything else (never run, or cut short by budget/window/a restart)
 * stays eligible. $sessionStartTs is the daemon's own bookkeeping of when
 * the current window (or "Run now" override) began — see godwitd.
 */
function godwit_select_next_jobs(array $jobs, array $activeRemotes, array $lastRuns = [], int $sessionStartTs = 0): array
{
    $inUse = $activeRemotes;
    $selected = [];
    $terminal = godwit_terminal_job_outcomes();
    foreach ($jobs as $job) {
        if (empty($job['enabled'])) {
            continue;
        }
        if (in_array($job['remote'], $inUse, true)) {
            continue;
        }
        $last = $lastRuns[$job['name']] ?? null;
        if ($last !== null && $last['ended_ts'] !== null && (int) $last['ended_ts'] >= $sessionStartTs && in_array($last['outcome'], $terminal, true)) {
            continue;
        }
        $selected[] = $job;
        $inUse[] = $job['remote'];
    }
    return $selected;
}

/**
 * Classifies a finished rc job's outcome from its job/status error text (and
 * whether godwitd itself issued the mid-run job/stop that caused it — its
 * own job/stop produces a generic "context canceled", not a distinguishing
 * message, so the caller must tell us). Strings ground-truthed by grepping
 * the exact literals out of the bundled rclone v1.75.1 binary: "max
 * transfer limit reached as set by --max-transfer" (MaxTransfer/budget
 * cutoff) and "max transfer duration reached as set by --max-duration"
 * (MaxDuration/window soft-stop cutoff) are rclone's own fixed error text
 * for those two CutoffMode triggers, not something this codebase invented.
 * Replaces an earlier, broken heuristic that inferred "window" purely from
 * whether a window happened to be active at *poll* time — which
 * misclassified a job that legitimately completed a few seconds after its
 * window closed, and suppressed its first-seed notification.
 *
 * Why matching on $errorMsg text is enough to also catch a genuine
 * transfer failure that happens in the same run, without a separate error
 * count check here (ground-truthed by reading rclone v1.75.1's
 * fs/sync/sync.go): job/status's error is `currentError()`, which resolves
 * in fixed precedence — fatalErr, then a plain err, then noRetryErr.
 * MaxTransfer's CAUTIOUS/graceful cutoff is a NoRetryError (lowest
 * precedence), so a real per-file error occurring in the same run (a plain
 * err) always wins and is what ends up in $errorMsg instead — this
 * function then falls through to 'error', which is correct: the run
 * genuinely needs attention beyond "resume next window". A `budget`
 * result here therefore already means nothing else outranked the cutoff.
 * MaxDuration's cutoff is a *fatal* error (highest precedence), so it is
 * immune to being masked by an unrelated plain error the way the old
 * directory-modtime bug masked MaxTransfer — but the reverse asymmetry
 * exists in principle: a real error co-occurring with a duration cutoff
 * could theoretically lose to the fatal error's precedence and be
 * misread as 'window' from this text alone. Accepted: godwitd still
 * stores the real core/stats error count on every outcome (see
 * godwit_cap_stop_notification()), so it's never silently lost — only
 * the outcome label could, in that rare combination, undersell it.
 */
function godwit_classify_job_outcome(string $errorMsg, bool $stoppedForBudget): string
{
    if ($errorMsg === '') {
        return 'completed';
    }
    if (godwit_is_upload_limit_error($errorMsg)) {
        return 'throttled';
    }
    if ($stoppedForBudget || str_contains($errorMsg, 'as set by --max-transfer')) {
        return 'budget';
    }
    if (str_contains($errorMsg, 'as set by --max-duration')) {
        return 'window';
    }
    if (godwit_classify_error($errorMsg) === 'auth-expired') {
        return 'auth';
    }
    return 'error';
}

// --- Daily budget ledger -----------------------------------------------

function godwit_default_budget_cap_bytes(): int
{
    return 700 * 1024 * 1024 * 1024; // 700 GiB, D12
}

function godwit_open_budget_table(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS budget_ledger (
        remote TEXT NOT NULL,
        ts INTEGER NOT NULL,
        bytes INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_budget_ledger_remote_ts ON budget_ledger(remote, ts)');
}

/** Records one stats-delta sample. Only positive deltas are worth recording — a delta of 0 (or negative, e.g. a stats-group reset) adds nothing to the ledger. */
function godwit_record_ledger_delta(SQLite3 $db, string $remote, int $ts, int $bytesDelta): void
{
    if ($bytesDelta <= 0) {
        return;
    }
    $stmt = $db->prepare('INSERT INTO budget_ledger (remote, ts, bytes) VALUES (:remote, :ts, :bytes)');
    $stmt->bindValue(':remote', $remote, SQLITE3_TEXT);
    $stmt->bindValue(':ts', $ts, SQLITE3_INTEGER);
    $stmt->bindValue(':bytes', $bytesDelta, SQLITE3_INTEGER);
    $stmt->execute();
}

/** Sum of bytes recorded for $remote in the rolling 24h window ending at $now — the real budget guard (§4.3), independent of whatever MaxTransfer was passed to any individual job. */
function godwit_ledger_used_24h(SQLite3 $db, string $remote, int $now): int
{
    $stmt = $db->prepare('SELECT COALESCE(SUM(bytes), 0) AS used FROM budget_ledger WHERE remote = :remote AND ts > :since');
    $stmt->bindValue(':remote', $remote, SQLITE3_TEXT);
    $stmt->bindValue(':since', $now - 86400, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return (int) ($row['used'] ?? 0);
}

/** Drops ledger rows older than the rolling window plus a safety margin — called on godwitd's hourly trim tick, same cadence as godwit_trim_heartbeat(). */
function godwit_trim_ledger(SQLite3 $db, int $now, int $retainSeconds = 90000): void
{
    $stmt = $db->prepare('DELETE FROM budget_ledger WHERE ts < :cutoff');
    $stmt->bindValue(':cutoff', $now - $retainSeconds, SQLITE3_INTEGER);
    $stmt->execute();
}

function godwit_remaining_budget(int $capBytes, int $usedBytes): int
{
    return max(0, $capBytes - $usedBytes);
}

// --- Throttling (drive_stop_on_upload_limit) ----------------------------

function godwit_open_throttle_table(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS remote_throttle (remote TEXT PRIMARY KEY, until_ts INTEGER NOT NULL)');
}

function godwit_mark_throttled(SQLite3 $db, string $remote, int $untilTs): void
{
    $stmt = $db->prepare('INSERT INTO remote_throttle (remote, until_ts) VALUES (:remote, :until)
        ON CONFLICT(remote) DO UPDATE SET until_ts = excluded.until_ts');
    $stmt->bindValue(':remote', $remote, SQLITE3_TEXT);
    $stmt->bindValue(':until', $untilTs, SQLITE3_INTEGER);
    $stmt->execute();
}

/** Null once $now has passed the stored until_ts — a remote is only "throttled" while this returns non-null. */
function godwit_throttled_until(SQLite3 $db, string $remote, int $now): ?int
{
    $stmt = $db->prepare('SELECT until_ts FROM remote_throttle WHERE remote = :remote');
    $stmt->bindValue(':remote', $remote, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row === false || (int) $row['until_ts'] <= $now) {
        return null;
    }
    return (int) $row['until_ts'];
}

/** Whether an rc job error looks like Google's daily-upload-limit rejection (surfaces via --drive-stop-on-upload-limit as a fatal transfer error, distinct from the auth/quota errors godwit_classify_error() already handles). */
function godwit_is_upload_limit_error(string $message): bool
{
    return stripos($message, 'upload limit') !== false || stripos($message, 'uploadLimitExceeded') !== false;
}

// --- Windows and speed ---------------------------------------------------

function godwit_default_windows(): array
{
    return [['days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '22:00', 'end' => '06:00', 'limit_mbit' => 250.0]];
}

function godwit_mbit_to_bytes_per_sec(float $mbit): int
{
    return (int) round($mbit * 1000000 / 8);
}

/** rclone's `M` bwlimit suffix is MiB, not Mbit — passing raw bytes with a `B` suffix is the only way to hit an exact Mbit/s figure. Ground-truthed: the bundled v1.75.1 binary accepts `--bwlimit <n>B`. */
function godwit_bwlimit_rc_value(float $mbit): string
{
    return godwit_mbit_to_bytes_per_sec($mbit) . 'B';
}

function godwit_format_mbit_and_mib(float $mbit): string
{
    $mib = godwit_mbit_to_bytes_per_sec($mbit) / 1048576;
    return sprintf('%s Mbit/s (%.1f MiB/s)', rtrim(rtrim(sprintf('%.2f', $mbit), '0'), '.'), $mib);
}

/**
 * Whether $window is active at $now, handling the midnight-wrap case
 * (end < start, e.g. the D12 default 22:00–06:00) by treating it as two
 * half-open ranges: "today from start to midnight" and "today from midnight
 * to end, if yesterday was a window day". $now's timezone is used as-is —
 * callers are responsible for constructing it in the daemon's configured
 * timezone (Australia/Brisbane on the live host, DST-free AEST).
 */
function godwit_time_in_window(array $window, \DateTimeImmutable $now): bool
{
    $day = (int) $now->format('w');
    $nowMin = ((int) $now->format('H')) * 60 + (int) $now->format('i');
    [$sh, $sm] = array_map('intval', explode(':', $window['start']));
    [$eh, $em] = array_map('intval', explode(':', $window['end']));
    $startMin = $sh * 60 + $sm;
    $endMin = $eh * 60 + $em;
    $days = $window['days'];

    if ($startMin < $endMin) {
        return in_array($day, $days, true) && $nowMin >= $startMin && $nowMin < $endMin;
    }
    if ($startMin === $endMin) {
        // Zero-width or full-day window (start == end) — treat as "all day" on a window day, matching a weekly-grid UI where a user drags a row across the whole day.
        return in_array($day, $days, true);
    }
    $prevDay = ($day + 6) % 7;
    return (in_array($day, $days, true) && $nowMin >= $startMin)
        || (in_array($prevDay, $days, true) && $nowMin < $endMin);
}

/**
 * The real start timestamp of $window's current occurrence, given it's
 * active at $now — handles the midnight-wrap case the same way
 * godwit_time_in_window()/godwit_seconds_to_window_end() do: if today's
 * start-of-day boundary is still in the future relative to $now, this
 * occurrence actually began yesterday. Used to seed godwitd's
 * $sessionStartTs correctly on a daemon restart that happens mid-window —
 * without this, a restart would reset the session clock to "now", making
 * every job that already completed earlier in the still-open window look
 * like it had never run this session, and become eligible to re-run ahead
 * of jobs that genuinely haven't started yet.
 */
function godwit_window_start_ts(array $window, \DateTimeImmutable $now): int
{
    [$sh, $sm] = array_map('intval', explode(':', $window['start']));
    $start = $now->setTime($sh, $sm, 0);
    if ($start > $now) {
        $start = $start->modify('-1 day');
    }
    return $start->getTimestamp();
}

/** First window (in list order) active at $now, or null if none is. */
function godwit_active_window(array $windows, \DateTimeImmutable $now): ?array
{
    foreach ($windows as $w) {
        if (godwit_time_in_window($w, $now)) {
            return $w;
        }
    }
    return null;
}

/**
 * Seconds remaining until $window's own end boundary, from $now — used to
 * set MaxDuration on a job started inside a window so it soft-stops at the
 * boundary (see godwit_build_sync_params()'s docblock) rather than being
 * hard-killed. Handles the midnight-wrap case the same way
 * godwit_time_in_window() classifies membership.
 */
function godwit_seconds_to_window_end(array $window, \DateTimeImmutable $now): int
{
    [$eh, $em] = array_map('intval', explode(':', $window['end']));
    $end = $now->setTime($eh, $em, 0);
    if ($end <= $now) {
        $end = $end->modify('+1 day');
    }
    return $end->getTimestamp() - $now->getTimestamp();
}

function godwit_line_profiles(): array
{
    return [
        '1000/400' => ['down_mbit' => 1000.0, 'up_mbit' => 400.0],
        '500/50' => ['down_mbit' => 500.0, 'up_mbit' => 50.0],
    ];
}

/** Warns when a window's upload limit is at or above 80% of the selected line profile's upstream — e.g. the D12 default 250 Mbit/s against the post-trip 500/50 profile (50 Mbit/s upstream: 250 ≥ 40). Null if the profile is unknown or the limit is comfortably under. */
function godwit_profile_warning(string $profileKey, float $windowLimitMbit): ?string
{
    $profiles = godwit_line_profiles();
    if (!isset($profiles[$profileKey])) {
        return null;
    }
    $upstream = $profiles[$profileKey]['up_mbit'];
    if ($upstream <= 0 || $windowLimitMbit < 0.8 * $upstream) {
        return null;
    }
    return sprintf(
        'Window limit %.0f Mbit/s is at or above 80%% of the "%s" profile\'s upstream (%.0f Mbit/s) — uploads may saturate the line.',
        $windowLimitMbit,
        $profileKey,
        $upstream
    );
}

// --- Version retention purge ---------------------------------------------

function godwit_default_retention_days(): int
{
    return 30;
}

/**
 * Lists the date-directories directly under $fs (a full "<remote>:godwit/_versions/<share>"
 * path) ahead of a retention purge. `operations/list` requires both `fs`
 * AND `remote` (the path within that fs, "" for the root) — omitting
 * `remote` makes rcd reject the call outright with "Didn't find key
 * \"remote\" in input", which silently produced an empty candidate list
 * forever (issue #1). $call defaults to godwit_rc_call_params so callers
 * can inject a fake response to test the error/absent paths without a
 * real rcd. Returns exactly one of:
 *   - ['dirs' => [...]] on success;
 *   - ['dirs' => [], 'absent' => true] if the directory doesn't exist yet
 *     (normal before the first versioned overwrite for that share — stay
 *     quiet, ground-truthed against the real bundled rclone binary as
 *     "error in ListJSON: directory not found");
 *   - ['dirs' => [], 'error' => '...'] on any other failure — the caller
 *     must log this rather than silently proceeding as "nothing to purge".
 */
function godwit_list_versions_dirs(array $listener, string $fs, ?callable $call = null): array
{
    $call = $call ?? 'godwit_rc_call_params';
    $resp = $call($listener, 'operations/list', ['fs' => $fs, 'remote' => '', 'opt' => json_encode(['dirsOnly' => true])], 30);
    if (is_array($resp['list'] ?? null)) {
        return ['dirs' => array_map(fn ($e) => (string) ($e['Name'] ?? ''), $resp['list'])];
    }
    $error = (string) ($resp['error'] ?? '');
    if (stripos($error, 'directory not found') !== false) {
        return ['dirs' => [], 'absent' => true];
    }
    return ['dirs' => [], 'error' => $error !== '' ? $error : ('operations/list returned neither a list nor an error: ' . json_encode($resp))];
}

/** Which of $dateDirs (leaf names listed under godwit/_versions/<share>/) are older than the retention window. Anything not shaped exactly like YYYY-MM-DD is silently skipped, never purged — this is the offline half of the safety guard; godwit_assert_purge_path() is the online half applied to each candidate before any rc call. */
function godwit_versions_purge_candidates(array $dateDirs, int $retainDays, \DateTimeImmutable $now): array
{
    $cutoff = $now->modify("-{$retainDays} days");
    $out = [];
    foreach ($dateDirs as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            continue;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d, $now->getTimezone());
        if ($dt === false || $dt >= $cutoff) {
            continue;
        }
        $out[] = $d;
    }
    return $out;
}

/**
 * The purge safety guard: throws unless the fully-assembled path is exactly
 * "godwit/_versions/<share>/<YYYY-MM-DD>" with no traversal, no extra
 * segments, nothing that could resolve outside _versions/. This is the only
 * function allowed to build a path for `operations/purge` — callers must
 * never hand rcd a hand-built string.
 */
function godwit_assert_purge_path(string $remote, string $share, string $dateDir): string
{
    if ($remote === '' || strpos($remote, ':') !== false || strpos($remote, '/') !== false) {
        throw new \InvalidArgumentException('invalid remote for purge');
    }
    if ($share === '' || $share === '.' || $share === '..' || strpos($share, '/') !== false || strpos($share, '..') !== false) {
        throw new \InvalidArgumentException('invalid share for purge');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDir)) {
        throw new \InvalidArgumentException('refusing to purge a non-date path: ' . var_export($dateDir, true));
    }
    $path = "godwit/_versions/$share/$dateDir";
    if (!preg_match('#^godwit/_versions/[^/]+/\d{4}-\d{2}-\d{2}$#', $path)) {
        throw new \InvalidArgumentException('purge path failed the safety check: ' . $path);
    }
    return $remote . ':' . $path;
}

// --- Job runs (SQLite) and requeue after restart --------------------------

function godwit_open_job_runs_table(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS job_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_name TEXT NOT NULL,
        remote TEXT NOT NULL,
        started_ts INTEGER NOT NULL,
        ended_ts INTEGER,
        bytes INTEGER NOT NULL DEFAULT 0,
        files INTEGER NOT NULL DEFAULT 0,
        errors INTEGER NOT NULL DEFAULT 0,
        outcome TEXT,
        eta_seconds INTEGER,
        speed_bps INTEGER
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_job_runs_job_started ON job_runs(job_name, started_ts)');
}

/** Updates a still-running run row's live progress (bytes/files/errors/eta/speed) — called every daemon tick while a job is active, so the status page reflects the run in progress rather than only its final result. */
function godwit_update_job_run_progress(SQLite3 $db, int $runId, int $bytes, int $files, int $errors, ?int $etaSeconds, ?int $speedBps = null): void
{
    $stmt = $db->prepare('UPDATE job_runs SET bytes = :bytes, files = :files, errors = :errors, eta_seconds = :eta, speed_bps = :speed WHERE id = :id');
    $stmt->bindValue(':bytes', $bytes, SQLITE3_INTEGER);
    $stmt->bindValue(':files', $files, SQLITE3_INTEGER);
    $stmt->bindValue(':errors', $errors, SQLITE3_INTEGER);
    $stmt->bindValue(':eta', $etaSeconds, SQLITE3_INTEGER);
    $stmt->bindValue(':speed', $speedBps, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $runId, SQLITE3_INTEGER);
    $stmt->execute();
}

/** The still-open run row for $jobName, if any — "currently running" per the status page is defined as "has a job_runs row with no ended_ts", which is also exactly what godwit_interrupt_open_runs() looks for on restart. */
function godwit_active_run(SQLite3 $db, string $jobName): ?array
{
    $stmt = $db->prepare('SELECT * FROM job_runs WHERE job_name = :job AND ended_ts IS NULL ORDER BY started_ts DESC LIMIT 1');
    $stmt->bindValue(':job', $jobName, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Full status payload for the Jobs page: each job with its enabled/mode/
 * transfers/excludes config, whether it's currently running (and live
 * progress if so), its last completed run, and queue position (index among
 * jobs that would still need to start, per godwit_select_next_jobs() against
 * remotes already active) — plus a per-remote budget/throttle summary.
 * Read-only; never mutates anything.
 */
function godwit_build_jobs_status(SQLite3 $db, array $jobs, array $settings, int $now): array
{
    // Queue position = index among this job's own remote's other enabled,
    // not-currently-running jobs, in jobs.json array order — "0" means
    // "starts as soon as whatever's currently using this remote finishes".
    // A running job has no queue position (it IS the one running); a job on
    // an idle remote still gets 0, since godwitd's next tick will pick it up
    // immediately regardless.
    $remoteCounters = [];
    $queuePositions = [];
    foreach ($jobs as $job) {
        if (empty($job['enabled']) || godwit_active_run($db, $job['name']) !== null) {
            continue;
        }
        $remote = $job['remote'];
        $remoteCounters[$remote] = ($remoteCounters[$remote] ?? -1) + 1;
        $queuePositions[$job['name']] = $remoteCounters[$remote];
    }

    $jobsOut = [];
    foreach ($jobs as $job) {
        $active = godwit_active_run($db, $job['name']);
        $last = godwit_last_job_run($db, $job['name']);
        $pos = $queuePositions[$job['name']] ?? null;
        $jobsOut[] = [
            'name' => $job['name'],
            'share' => $job['share'],
            'remote' => $job['remote'],
            'mode' => $job['mode'] ?? 'sync',
            'enabled' => !empty($job['enabled']),
            'transfers' => (int) ($job['transfers'] ?? 4),
            'max_delete' => (int) ($job['max_delete'] ?? 1000),
            'excludes' => $job['excludes'] ?? [],
            'running' => $active !== null,
            'progress' => $active !== null ? [
                'bytes' => (int) $active['bytes'],
                'files' => (int) $active['files'],
                'errors' => (int) $active['errors'],
                'eta_seconds' => $active['eta_seconds'] !== null ? (int) $active['eta_seconds'] : null,
                'speed_bps' => $active['speed_bps'] !== null ? (int) $active['speed_bps'] : null,
                'started_ts' => (int) $active['started_ts'],
            ] : null,
            'last_run' => $last !== null ? [
                'started_ts' => (int) $last['started_ts'],
                'ended_ts' => $last['ended_ts'] !== null ? (int) $last['ended_ts'] : null,
                'bytes' => (int) $last['bytes'],
                'files' => (int) $last['files'],
                'errors' => (int) $last['errors'],
                'outcome' => $last['outcome'],
            ] : null,
            'queue_position' => $pos,
        ];
    }

    $remotes = array_values(array_unique(array_column($jobs, 'remote')));
    $remotesOut = [];
    foreach ($remotes as $remote) {
        $cap = (int) ($settings['budget_caps'][$remote] ?? godwit_default_budget_cap_bytes());
        $used = godwit_ledger_used_24h($db, $remote, $now);
        $remotesOut[] = [
            'remote' => $remote,
            'cap_bytes' => $cap,
            'used_24h_bytes' => $used,
            'remaining_bytes' => godwit_remaining_budget($cap, $used),
            'throttled_until' => godwit_throttled_until($db, $remote, $now),
        ];
    }

    return ['jobs' => $jobsOut, 'remotes' => $remotesOut];
}

/**
 * All jobs/settings/queue-control actions posted from the page funnel
 * through here — mirrors godwit_handle_remote_action()'s shape. jobs_save
 * and settings_save both go through the same validation as the daemon
 * itself (godwit_validate_job_destinations(), and each job is round-tripped
 * through godwit_build_job_fs()+godwit_assert_job_direction() before being
 * accepted) so a bad save can never reach jobs.json in the first place —
 * the daemon's own per-tick checks are a second line of defence, not the
 * only one. run_now/pause/resume just drop or remove a marker file in
 * $runDir; godwitd's tick loop polls for them the same way it already polls
 * check-now (Phase 2).
 */
function godwit_handle_job_action(string $action, array $post, string $dbPath, string $runDir, string $cfgDir): array
{
    if ($action === 'jobs_status') {
        if (!is_file($dbPath)) {
            return ['error' => 'heartbeat database not found yet — is godwitd running?'];
        }
        try {
            // Not read-only: a fresh install's db has heartbeat/remotes_health
            // (created by godwit_open_db()) but the Phase 3 tables are only
            // guaranteed to exist once godwitd's own startup has run at least
            // once — ensure they're present here too (all idempotent
            // CREATE TABLE IF NOT EXISTS / ALTER-with-@, same as every other
            // godwit_open_*_table() call site) rather than 500ing on a
            // brand-new database before godwitd's first tick.
            $db = new SQLite3($dbPath);
            $db->busyTimeout(5000);
            godwit_open_job_runs_table($db);
            godwit_open_budget_table($db);
            godwit_open_throttle_table($db);
        } catch (\Throwable $e) {
            return ['error' => 'state database unavailable'];
        }
        $jobs = godwit_load_jobs($cfgDir);
        $settings = godwit_load_settings($cfgDir);
        $status = godwit_build_jobs_status($db, $jobs, $settings, time());
        $status['paused'] = file_exists($runDir . '/paused');
        $status['run_now'] = file_exists($runDir . '/run-now');
        $status['settings'] = $settings;
        return $status;
    }

    if ($action === 'jobs_save') {
        $raw = (string) ($post['jobs'] ?? '');
        $jobs = json_decode($raw, true);
        if (!is_array($jobs)) {
            return ['error' => 'jobs must be a JSON array'];
        }
        foreach ($jobs as $job) {
            if (!is_array($job) || empty($job['name']) || empty($job['share']) || empty($job['remote'])) {
                return ['error' => 'every job needs a name, share and remote'];
            }
            try {
                godwit_assert_job_direction(godwit_build_job_fs($job));
            } catch (\Throwable $e) {
                return ['error' => "job \"{$job['name']}\": " . $e->getMessage()];
            }
            if (!in_array($job['mode'] ?? 'sync', ['sync', 'copy'], true)) {
                return ['error' => "job \"{$job['name']}\": mode must be sync or copy"];
            }
        }
        $overlapError = godwit_validate_job_destinations($jobs);
        if ($overlapError !== null) {
            return ['error' => $overlapError];
        }
        godwit_save_jobs($cfgDir, $jobs);
        @touch($runDir . '/jobs-changed');
        return ['ok' => true];
    }

    if ($action === 'settings_save') {
        $raw = (string) ($post['settings'] ?? '');
        $settings = json_decode($raw, true);
        if (!is_array($settings) || !isset($settings['windows']) || !is_array($settings['windows'])) {
            return ['error' => 'settings must include a windows array'];
        }
        foreach ($settings['windows'] as $w) {
            if (!isset($w['start'], $w['end'], $w['limit_mbit'], $w['days']) || !is_array($w['days'])) {
                return ['error' => 'each window needs start, end, limit_mbit and a days array'];
            }
            if (count($w['days']) === 0) {
                return ['error' => 'each window needs at least one day'];
            }
            foreach ($w['days'] as $day) {
                if (!is_int($day) || $day < 0 || $day > 6) {
                    return ['error' => 'each day must be an integer 0-6 (0 = Sunday)'];
                }
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $w['start'])
                || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $w['end'])) {
                return ['error' => 'start and end must be HH:MM (00:00-23:59)'];
            }
            if (!is_numeric($w['limit_mbit']) || (float) $w['limit_mbit'] <= 0) {
                return ['error' => 'limit_mbit must be a positive number'];
            }
        }
        // Merged against what's already on disk, not just the defaults — a
        // partial save (the page only ever sends windows+profile) must not
        // silently reset budget_caps/retention_days back to their defaults.
        godwit_save_settings($cfgDir, array_merge(godwit_load_settings($cfgDir), $settings));
        @touch($runDir . '/jobs-changed');
        return ['ok' => true];
    }

    if ($action === 'run_now') {
        @touch($runDir . '/run-now');
        return ['ok' => true];
    }

    if ($action === 'pause') {
        @touch($runDir . '/paused');
        return ['ok' => true];
    }

    if ($action === 'resume') {
        @unlink($runDir . '/paused');
        return ['ok' => true];
    }

    return ['error' => 'unknown action'];
}

function godwit_start_job_run(SQLite3 $db, string $jobName, string $remote, int $ts): int
{
    $stmt = $db->prepare('INSERT INTO job_runs (job_name, remote, started_ts) VALUES (:job, :remote, :ts)');
    $stmt->bindValue(':job', $jobName, SQLITE3_TEXT);
    $stmt->bindValue(':remote', $remote, SQLITE3_TEXT);
    $stmt->bindValue(':ts', $ts, SQLITE3_INTEGER);
    $stmt->execute();
    return $db->lastInsertRowID();
}

function godwit_finish_job_run(SQLite3 $db, int $runId, int $ts, int $bytes, int $files, int $errors, string $outcome): void
{
    $stmt = $db->prepare('UPDATE job_runs SET ended_ts = :ts, bytes = :bytes, files = :files, errors = :errors, outcome = :outcome WHERE id = :id');
    $stmt->bindValue(':ts', $ts, SQLITE3_INTEGER);
    $stmt->bindValue(':bytes', $bytes, SQLITE3_INTEGER);
    $stmt->bindValue(':files', $files, SQLITE3_INTEGER);
    $stmt->bindValue(':errors', $errors, SQLITE3_INTEGER);
    $stmt->bindValue(':outcome', $outcome, SQLITE3_TEXT);
    $stmt->bindValue(':id', $runId, SQLITE3_INTEGER);
    $stmt->execute();
}

function godwit_last_job_run(SQLite3 $db, string $jobName): ?array
{
    $stmt = $db->prepare('SELECT * FROM job_runs WHERE job_name = :job ORDER BY started_ts DESC LIMIT 1');
    $stmt->bindValue(':job', $jobName, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row === false ? null : $row;
}

/**
 * On godwitd startup: closes any run row left open (ended_ts NULL) by a
 * prior process that died mid-job — a restart of godwitd/rcd always loses
 * whatever rc job was in flight, since rcd's job state lives in that
 * process's memory only. Marking them 'interrupted' rather than deleting
 * them keeps the run history honest. Returns the closed job names/remotes so
 * the caller can requeue them immediately — safe because sync is idempotent
 * (a re-run only transfers what's still actually missing/changed).
 */
function godwit_interrupt_open_runs(SQLite3 $db, int $ts): array
{
    $rows = [];
    $res = $db->query('SELECT id, job_name, remote FROM job_runs WHERE ended_ts IS NULL');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    foreach ($rows as $row) {
        godwit_finish_job_run($db, (int) $row['id'], $ts, 0, 0, 0, 'interrupted');
    }
    return $rows;
}

// --- Notifications ---------------------------------------------------------

function godwit_open_notify_state_table(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS notify_state (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
}

function godwit_get_notify_state(SQLite3 $db, string $key): ?string
{
    $stmt = $db->prepare('SELECT value FROM notify_state WHERE key = :key');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row === false ? null : (string) $row['value'];
}

function godwit_set_notify_state(SQLite3 $db, string $key, string $value): void
{
    $stmt = $db->prepare('INSERT INTO notify_state (key, value) VALUES (:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->execute();
}

/** A run ending in error notifies every time — unlike the health-check's 2-strikes rule, a job run is already a discrete, infrequent event (at most a few a day), so there is no steady-state spam to guard against. */
function godwit_job_error_notification(string $jobName, ?string $errorMessage): array
{
    return [
        'subject' => "Godwit: $jobName run failed",
        'description' => $errorMessage !== null && $errorMessage !== '' ? "$jobName: $errorMessage" : "$jobName's backup run ended in error",
        'importance' => 'alert',
    ];
}

/**
 * A `budget`/`window` outcome is by design (§4.3/§4.5), not a failure — it
 * reads as a normal stop that resumes at the next window, never as "run
 * failed". Notifies every run, same as godwit_job_error_notification(),
 * since these are already infrequent, discrete events.
 *
 * $errorCount > $baselineErrors is the signal that something beyond the
 * cutoff itself also went wrong. $baselineErrors is NOT a constant — it is
 * not even a constant *within* the 'budget' outcome. Ground-truthed
 * locally against the bundled binary at Transfers up to 8, repeated 20+
 * times per shape because the count is racy, not fixed:
 *   - A budget stop — whether rclone's own graceful MaxTransfer cutoff
 *     (fs/operations/copy.go's checkLimits(), CutoffMode CAUTIOUS) or
 *     godwitd's own mid-run job/stop ($stoppedForBudget, the ledger guard
 *     firing before rclone's own MaxTransfer) — costs *up to one error per
 *     transfer slot in flight when it trips*, not a fixed number: each
 *     worker independently discovers the cutoff/cancellation the next time
 *     it tries to start a file, and how many do so before the pipe drains
 *     depends on scheduling. Measured 1 through 4 at Transfers=8 across
 *     repeated runs of the exact same config — an earlier version of this
 *     fix assumed rclone's own cutoff was always exactly 1 (true only at
 *     Transfers=1, where this was first measured) and would have reported
 *     "N errors were also logged" on a perfectly clean multi-worker
 *     budget stop. The job's configured Transfers is therefore the
 *     correct upper-bound baseline for either budget path — conservative
 *     by construction (an occasional genuine extra error blended in below
 *     that ceiling goes unflagged, which is the safe side of this
 *     trade-off: never re-cry-wolf on a clean stop).
 *   - MaxDuration's cutoff (fatal, matched via text): exactly 0, including
 *     at Transfers=8 (repeated 10x) — fs/sync/sync.go's fatal path never
 *     calls fs.CountError, unlike the graceful path above.
 * The caller (godwitd) picks 0 for 'window' and the job's Transfers for
 * 'budget'.
 */
function godwit_cap_stop_notification(string $jobName, string $outcome, int $bytes, int $errorCount, int $baselineErrors): array
{
    $gib = $bytes / (1024 ** 3);
    $reason = $outcome === 'window' ? "tonight's backup window" : 'its daily upload cap';
    $subject = $outcome === 'window' ? "Godwit: $jobName stopped for tonight's window" : "Godwit: $jobName stopped at the daily cap";
    $description = sprintf('%s transferred %.2f GiB before hitting %s — it will resume at the next window.', $jobName, $gib, $reason);
    if ($errorCount > $baselineErrors) {
        $description .= " $errorCount errors were also logged this run — check /var/log/godwit.log.";
    }
    return [
        'subject' => $subject,
        'description' => $description,
        'importance' => 'normal',
    ];
}

/** At most once per calendar day (in $today's timezone) per remote — checked/set via notify_state so a restart doesn't re-notify within the same day. */
function godwit_budget_reached_notification(SQLite3 $db, string $remote, string $today): ?array
{
    $key = "budget_reached:$remote";
    if (godwit_get_notify_state($db, $key) === $today) {
        return null;
    }
    godwit_set_notify_state($db, $key, $today);
    return [
        'subject' => "Godwit: $remote daily upload budget reached",
        'description' => "$remote has used its rolling 24h upload budget — new transfers will wait for headroom to free up.",
        'importance' => 'warning',
    ];
}

/** Notifies once on entering the throttled state, not on every tick it remains throttled — mirrors the auth-expired transition pattern in godwit_health_notifications(). */
function godwit_throttled_notification(string $remote, bool $wasAlreadyThrottled): ?array
{
    if ($wasAlreadyThrottled) {
        return null;
    }
    return [
        'subject' => "Godwit: $remote throttled by Google for 24h",
        'description' => "$remote hit Google's daily upload limit — no further uploads to $remote until the throttle clears.",
        'importance' => 'alert',
    ];
}

function godwit_default_settings(): array
{
    return [
        'windows' => godwit_default_windows(),
        'budget_caps' => ['gdrive' => godwit_default_budget_cap_bytes()],
        'profile' => '1000/400',
        'retention_days' => godwit_default_retention_days(),
    ];
}

/** Loads settings.json (windows, per-remote budget caps, line profile, retention days), seeding D12 defaults on first run. Missing keys in an on-disk file fall back to their default individually, so a settings.json written by an older Phase 3 build still loads. */
function godwit_load_settings(string $cfgDir): array
{
    $path = $cfgDir . '/settings.json';
    $defaults = godwit_default_settings();
    if (!is_file($path)) {
        return $defaults;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    return array_merge($defaults, $decoded);
}

function godwit_save_settings(string $cfgDir, array $settings): void
{
    if (!is_dir($cfgDir)) {
        mkdir($cfgDir, 0755, true);
    }
    file_put_contents($cfgDir . '/settings.json', json_encode($settings, JSON_PRETTY_PRINT));
}

/**
 * Effective timezone for window evaluation. Honours an explicit override
 * first (GODWIT_TZ, for tests and for Kieren to force it if Unraid's own
 * setting is ever wrong), then PHP's own date.timezone ini setting if it's
 * been configured to anything other than the PHP default of UTC, then
 * /etc/timezone (what `timedatectl`-based distros, including current
 * Unraid, write). Falls back to Australia/Brisbane (DST-free AEST, matching
 * D12's plain "22:00-06:00" with no DST adjustment) only if none of those
 * resolved anything — host verification must confirm this actually matches
 * Unraid's configured timezone, since a silent UTC daemon would run the
 * window 10 hours off local time.
 */
function godwit_resolve_timezone(?string $override): string
{
    if ($override !== null && $override !== '') {
        return $override;
    }
    $ini = date_default_timezone_get();
    if ($ini !== 'UTC') {
        return $ini;
    }
    if (is_file('/etc/timezone')) {
        $t = trim((string) @file_get_contents('/etc/timezone'));
        if ($t !== '') {
            return $t;
        }
    }
    return 'Australia/Brisbane';
}

/** Params for `operations/purge` on one version-date directory, after running it through the full godwit_assert_purge_path() safety check — this is the only place allowed to build that rc call. */
function godwit_purge_call_params(string $remote, string $share, string $dateDir): array
{
    godwit_assert_purge_path($remote, $share, $dateDir); // throws on anything unsafe; the return value itself isn't needed here.
    return ['fs' => $remote . ':godwit/_versions/' . $share, 'remote' => $dateDir];
}

/** The bwlimit to apply while a "Run now" override (D15) is active — the override still honours the configured speed limit, so it borrows the first configured window's rate rather than running unlimited. */
function godwit_run_now_limit_mbit(array $windows): float
{
    return (float) ($windows[0]['limit_mbit'] ?? 250.0);
}

/** Notifies once ever per job (persisted in notify_state) the first time it completes a full run with outcome 'completed'. */
function godwit_first_seed_notification(SQLite3 $db, string $jobName): ?array
{
    $key = "first_seed_done:$jobName";
    if (godwit_get_notify_state($db, $key) !== null) {
        return null;
    }
    godwit_set_notify_state($db, $key, '1');
    return [
        'subject' => "Godwit: $jobName first full seed complete",
        'description' => "$jobName has completed its first full backup to Google Drive.",
        'importance' => 'normal',
    ];
}
