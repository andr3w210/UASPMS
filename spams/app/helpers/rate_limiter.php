<?php
/**
 * IP-based rate limiting helper.
 *
 * Uses the application database to track attempts per IP address and action
 * key. The table is created automatically on first use.
 *
 * Usage:
 *   if (rate_limit_check('login')) { $error = 'Too many attempts...'; }
 *   else { rate_limit_record('login'); /* process form *\/ }
 */

/**
 * Ensure the rate_limit_attempts table exists.
 * Uses a static flag so the CREATE TABLE query runs at most once per request.
 */
function _rate_limit_ensure_table(mysqli $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db->query("
        CREATE TABLE IF NOT EXISTS rate_limit_attempts (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_address  VARCHAR(45)     NOT NULL,
            action_key  VARCHAR(64)     NOT NULL,
            attempted_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_rla_lookup (ip_address, action_key, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Return the client IP address.
 * Only REMOTE_ADDR is trusted to prevent header-spoofing attacks.
 */
function _rate_limit_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Check whether the current IP has exceeded the rate limit for an action.
 *
 * @param string $key           A short identifier for the action, e.g. 'login'.
 * @param int    $maxAttempts   Maximum allowed attempts within the window.
 * @param int    $windowMinutes Rolling time window in minutes.
 *
 * @return bool  True if the limit is exceeded (request should be blocked).
 *               Returns false (allow) if the database is unavailable.
 */
function rate_limit_check(string $key, int $maxAttempts = 5, int $windowMinutes = 15): bool
{
    $db = db();
    if (!$db) {
        return false; // fail open when DB is unavailable
    }

    _rate_limit_ensure_table($db);

    $ip   = _rate_limit_ip();
    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM rate_limit_attempts
        WHERE ip_address   = ?
          AND action_key   = ?
          AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ssi', $ip, $key, $windowMinutes);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int) ($row['cnt'] ?? 0)) >= $maxAttempts;
}

/**
 * Record one attempt for the current IP and action key.
 * Periodically prunes entries older than 24 hours (≈1 % of calls) to prevent
 * unbounded table growth without needing a scheduled job.
 *
 * @param string $key           The same key used in rate_limit_check().
 */
function rate_limit_record(string $key): void
{
    $db = db();
    if (!$db) {
        return;
    }

    _rate_limit_ensure_table($db);

    $ip   = _rate_limit_ip();
    $stmt = $db->prepare("
        INSERT INTO rate_limit_attempts (ip_address, action_key, attempted_at)
        VALUES (?, ?, NOW())
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ss', $ip, $key);
    $stmt->execute();
    $stmt->close();

    // Probabilistic cleanup — avoid a cron dependency
    if (random_int(1, 100) === 1) {
        $db->query("DELETE FROM rate_limit_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    }
}

/**
 * Return the number of seconds until the oldest attempt within the window
 * ages out and the IP is no longer blocked.
 *
 * @param string $key
 * @param int    $maxAttempts
 * @param int    $windowMinutes
 *
 * @return int  Seconds remaining, or 0 if not blocked / DB unavailable.
 */
function rate_limit_retry_after(string $key, int $maxAttempts = 5, int $windowMinutes = 15): int
{
    $db = db();
    if (!$db) {
        return 0;
    }

    _rate_limit_ensure_table($db);

    $ip   = _rate_limit_ip();
    $stmt = $db->prepare("
        SELECT MIN(attempted_at) AS oldest
        FROM (
            SELECT attempted_at
            FROM rate_limit_attempts
            WHERE ip_address = ?
              AND action_key = ?
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ORDER BY attempted_at DESC
            LIMIT ?
        ) sub
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('ssii', $ip, $key, $windowMinutes, $maxAttempts);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row['oldest'])) {
        return 0;
    }

    $resetAt = strtotime((string) $row['oldest']) + ($windowMinutes * 60);
    return max(0, $resetAt - time());
}
