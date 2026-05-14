<?php
require_once __DIR__ . '/spams/app/config/constants.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

const SPAMS_BASELINE_BEFORE = 44;
const SPAMS_LEGACY_SEED_PREFIX = 900;

function out(string $message): void
{
    echo $message . PHP_EOL;
}

function connect_migration_db(): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS);
    $db->set_charset('utf8mb4');
    return $db;
}

function migration_files(string $databaseDir): array
{
    $files = glob($databaseDir . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]_*.sql') ?: [];
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

function ensure_migrations_table(mysqli $db): void
{
    $db->query("
        CREATE TABLE IF NOT EXISTS `_migrations` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `filename` VARCHAR(255) NOT NULL,
            `checksum` CHAR(64) NOT NULL,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_migrations_filename` (`filename`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function applied_migrations(mysqli $db): array
{
    ensure_migrations_table($db);
    $result = $db->query("SELECT filename FROM `_migrations`");
    $applied = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $applied[(string) $row['filename']] = true;
        }
        $result->free();
    }
    return $applied;
}

function record_migration(mysqli $db, string $filename, string $checksum): void
{
    ensure_migrations_table($db);
    $stmt = $db->prepare("
        INSERT INTO `_migrations` (`filename`, `checksum`, `applied_at`)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE `checksum` = VALUES(`checksum`)
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $filename, $checksum);
        $stmt->execute();
        $stmt->close();
    }
}

function database_selected(mysqli $db): bool
{
    $result = $db->query('SELECT DATABASE() AS db_name');
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    return !empty($row['db_name']);
}

function auto_baseline_existing(mysqli $db, array $files): void
{
    if (!database_selected($db)) {
        return;
    }

    ensure_migrations_table($db);
    $result = $db->query("SELECT COUNT(*) AS total FROM `_migrations`");
    $countRow = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $alreadyTracked = (int) ($countRow['total'] ?? 0);
    if ($alreadyTracked > 0) {
        return;
    }

    $hasExistingAppSchema = false;
    foreach (['users', 'purchase_orders', 'receivings', 'distributions'] as $table) {
        $stmt = $db->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $hasRow = (bool) ($stmt->get_result()->fetch_assoc());
            $stmt->close();
            if ($hasRow) {
                $hasExistingAppSchema = true;
                break;
            }
        }
    }

    if (!$hasExistingAppSchema) {
        return;
    }

    foreach ($files as $file) {
        $filename = basename($file);
        $prefix = (int) substr($filename, 0, 3);
        if ($prefix >= SPAMS_BASELINE_BEFORE) {
            continue;
        }
        record_migration($db, $filename, hash_file('sha256', $file) ?: '');
    }

    out('Detected existing SPAMS schema. Baseline recorded for migrations before ' . SPAMS_BASELINE_BEFORE . '.');
}

function baseline_legacy_seed_migrations(mysqli $db, array $files): void
{
    if (!database_selected($db)) {
        return;
    }

    $hasExistingAppSchema = false;
    foreach (['users', 'purchase_orders', 'receivings', 'distributions'] as $table) {
        $stmt = $db->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $hasRow = (bool) ($stmt->get_result()->fetch_assoc());
            $stmt->close();
            if ($hasRow) {
                $hasExistingAppSchema = true;
                break;
            }
        }
    }

    if (!$hasExistingAppSchema) {
        return;
    }

    $applied = applied_migrations($db);
    $recorded = 0;
    foreach ($files as $file) {
        $filename = basename($file);
        $prefix = (int) substr($filename, 0, 3);
        if ($prefix < SPAMS_LEGACY_SEED_PREFIX || isset($applied[$filename])) {
            continue;
        }
        record_migration($db, $filename, hash_file('sha256', $file) ?: '');
        $recorded++;
    }

    if ($recorded > 0) {
        out('Detected existing SPAMS schema. Baseline recorded for legacy seed migrations (' . $recorded . ').');
    }
}

function split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
                $buffer .= $char;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble) {
            if ($char === '-' && $next === '-' && ($i === 0 || preg_match('/\s/', $prev))) {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($char === '#') {
                $inLineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($char === "'" && !$inDouble && $prev !== '\\') {
            $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle && $prev !== '\\') {
            $inDouble = !$inDouble;
        }

        if ($char === ';' && !$inSingle && !$inDouble) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function apply_sql_file(mysqli $db, string $file): void
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Unable to read migration file: ' . basename($file));
    }

    foreach (split_sql_statements($sql) as $statement) {
        if (preg_match('/^\s*SOURCE\s+/i', $statement)) {
            throw new RuntimeException('SOURCE statements are not supported in migrate.php: ' . basename($file));
        }
        $db->query($statement);
    }
}

$databaseDir = __DIR__ . DIRECTORY_SEPARATOR . 'database';
$files = migration_files($databaseDir);

if (!$files) {
    out('No migration files found.');
    exit(0);
}

try {
    $db = connect_migration_db();
    @$db->select_db(DB_NAME);

    auto_baseline_existing($db, $files);
    baseline_legacy_seed_migrations($db, $files);
    $applied = database_selected($db) ? applied_migrations($db) : [];

    $appliedCount = 0;
    foreach ($files as $file) {
        $filename = basename($file);
        $checksum = hash_file('sha256', $file) ?: '';

        if (database_selected($db) && isset($applied[$filename])) {
            out('SKIP  ' . $filename);
            continue;
        }

        out('APPLY ' . $filename);
        apply_sql_file($db, $file);

        if (!database_selected($db)) {
            @$db->select_db(DB_NAME);
        }

        if (!database_selected($db)) {
            throw new RuntimeException('Migration applied but no database is selected after ' . $filename . '.');
        }

        record_migration($db, $filename, $checksum);
        $applied[$filename] = true;
        $appliedCount++;
    }

    out('Done. Applied ' . $appliedCount . ' migration(s).');
    $db->close();
    exit(0);
} catch (Throwable $e) {
    out('Migration failed: ' . $e->getMessage());
    exit(1);
}
