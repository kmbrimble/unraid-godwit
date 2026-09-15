<?php
/**
 * Shared functions for godwitd (CLI) and godwit-api.php (web). Functions
 * reachable from both SAPIs must never reference STDOUT/STDERR/$argv — the
 * web SAPI has none of these (CLAUDE.md rule).
 */
declare(strict_types=1);

/**
 * Builds the argv for `rclone rcd`, given a listener spec. Never emits
 * --rc-no-auth (non-negotiable) and always emits --config. For a unix
 * socket listener no --rc-user/--rc-pass is added — the socket directory's
 * own permissions (0700, godwit-only) gate access. For a tcp listener,
 * user/pass are required (both null is a caller bug, not a valid state).
 */
function godwit_rcd_argv(string $rcloneBin, string $configPath, array $listener, string $rcdLogFile): array
{
    $argv = [$rcloneBin, 'rcd'];

    if ($listener['type'] === 'unix') {
        $argv[] = '--rc-addr=unix://' . $listener['path'];
    } elseif ($listener['type'] === 'tcp') {
        $argv[] = '--rc-addr=' . $listener['host'] . ':' . $listener['port'];
        $argv[] = '--rc-user=' . $listener['user'];
        $argv[] = '--rc-pass=' . $listener['pass'];
    } else {
        throw new \InvalidArgumentException('unknown listener type: ' . $listener['type']);
    }

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
