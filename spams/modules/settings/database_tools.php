<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

function db_tools_project_root(): string
{
    return dirname(APP_ROOT);
}

function db_tools_backups_root(): string
{
    return db_tools_project_root() . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups';
}

function db_tools_generated_dir(): string
{
    return db_tools_backups_root() . DIRECTORY_SEPARATOR . 'generated';
}

function db_tools_uploaded_dir(): string
{
    return db_tools_backups_root() . DIRECTORY_SEPARATOR . 'uploaded';
}

function db_tools_ensure_directory(string $path): bool
{
    if (is_dir($path)) {
        return true;
    }

    return mkdir($path, 0775, true);
}

function db_tools_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes;
    $unitIndex = -1;

    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }

    return number_format($value, $value >= 10 ? 1 : 2) . ' ' . $units[$unitIndex];
}

function db_tools_relative_path(string $path): string
{
    $root = rtrim(db_tools_project_root(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (stripos($path, $root) === 0) {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
    }

    return str_replace(DIRECTORY_SEPARATOR, '/', $path);
}

function db_tools_detect_mysqldump(): ?string
{
    $candidates = [];

    $phpDir = dirname(PHP_BINARY);
    $xamppRoot = dirname($phpDir);

    $candidates[] = $xamppRoot . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe';
    $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    $candidates[] = 'F:\\xampp\\mysql\\bin\\mysqldump.exe';

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    $pathEnv = getenv('PATH');
    if (is_string($pathEnv) && $pathEnv !== '') {
        foreach (explode(PATH_SEPARATOR, $pathEnv) as $entry) {
            $candidate = rtrim($entry, "\\/") . DIRECTORY_SEPARATOR . 'mysqldump.exe';
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    return null;
}

function db_tools_exec_available(): bool
{
    $disabled = (string) ini_get('disable_functions');
    if ($disabled === '') {
        return function_exists('exec');
    }

    $disabledFunctions = array_map('trim', explode(',', $disabled));
    return function_exists('exec') && !in_array('exec', $disabledFunctions, true);
}

function db_tools_create_backup_file(array &$errors): ?array
{
    if (!db_tools_ensure_directory(db_tools_generated_dir())) {
        $errors[] = 'Unable to create the backup output directory.';
        return null;
    }

    if (!db_tools_exec_available()) {
        $errors[] = 'Backup creation is not available because PHP shell execution is disabled on this server.';
        return null;
    }

    $mysqldump = db_tools_detect_mysqldump();
    if ($mysqldump === null) {
        $errors[] = 'Unable to locate mysqldump.exe on this server.';
        return null;
    }

    $filename = sprintf('%s_backup_%s.sql', DB_NAME, date('Y-m-d_H-i-s'));
    $targetPath = db_tools_generated_dir() . DIRECTORY_SEPARATOR . $filename;

    $commandParts = [
        escapeshellarg($mysqldump),
        '--protocol=tcp',
        '--host=' . escapeshellarg(DB_HOST),
        '--port=3306',
        '--user=' . escapeshellarg(DB_USER),
        '--default-character-set=utf8mb4',
        '--routines',
        '--events',
        '--single-transaction',
        '--result-file=' . escapeshellarg($targetPath),
    ];

    if (DB_PASS !== '') {
        $commandParts[] = '--password=' . escapeshellarg(DB_PASS);
    } else {
        $commandParts[] = '--password=';
    }

    $commandParts[] = escapeshellarg(DB_NAME);

    $output = [];
    $exitCode = 0;
    exec(implode(' ', $commandParts) . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0 || !is_file($targetPath)) {
        if (is_file($targetPath) && filesize($targetPath) === 0) {
            @unlink($targetPath);
        }
        $errors[] = 'Backup creation failed.' . (!empty($output) ? ' ' . trim(implode(' ', $output)) : '');
        return null;
    }

    return [
        'path' => $targetPath,
        'filename' => $filename,
        'size' => (int) filesize($targetPath),
    ];
}

function db_tools_restore_from_file(string $sqlPath, array &$errors): bool
{
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') {
        $errors[] = 'Unable to read the SQL dump file.';
        return false;
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_errno) {
        $errors[] = 'Unable to connect to MySQL: ' . $conn->connect_error;
        return false;
    }

    $conn->set_charset('utf8mb4');

    $dropCreateSql = sprintf(
        'DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;',
        $conn->real_escape_string(DB_NAME),
        $conn->real_escape_string(DB_NAME)
    );

    if (!$conn->multi_query($dropCreateSql)) {
        $errors[] = 'Unable to recreate the database: ' . $conn->error;
        $conn->close();
        return false;
    }

    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());

    if (!$conn->select_db(DB_NAME)) {
        $errors[] = 'Unable to select the recreated database: ' . $conn->error;
        $conn->close();
        return false;
    }

    if (!$conn->multi_query($sql)) {
        $errors[] = 'Import failed: ' . $conn->error;
        $conn->close();
        return false;
    }

    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());

    if ($conn->errno) {
        $errors[] = 'Import failed: ' . $conn->error;
        $conn->close();
        return false;
    }

    $conn->close();
    return true;
}

function db_tools_collect_files(): array
{
    $files = [];

    $defaultAutoDir = db_tools_backups_root() . DIRECTORY_SEPARATOR . 'auto';

    $directories = [
        db_tools_backups_root(),
        db_tools_generated_dir(),
        db_tools_uploaded_dir(),
        $defaultAutoDir,
    ];

    $configPath = db_tools_backups_root() . DIRECTORY_SEPARATOR . 'auto_backup_settings.json';
    if (is_file($configPath)) {
        $raw = @file_get_contents($configPath);
        if (is_string($raw) && $raw !== '') {
            $decoded = @json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['output_dir']) && (string) $decoded['output_dir'] !== '') {
                $customDir = rtrim((string) $decoded['output_dir'], "\\/");
                if ($customDir !== '' && is_dir($customDir)) {
                    $directories[] = $customDir;
                }
            }
        }
    }

    $directories = array_unique($directories);

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $path) {
            $realPath = realpath($path);
            if ($realPath === false) {
                continue;
            }

            $files[$realPath] = [
                'path' => $realPath,
                'name' => basename($realPath),
                'size' => is_file($realPath) ? (int) filesize($realPath) : 0,
                'modified_at' => is_file($realPath) ? (int) filemtime($realPath) : 0,
                'relative_path' => db_tools_relative_path($realPath),
            ];
        }
    }

    usort($files, static function (array $a, array $b): int {
        return ($b['modified_at'] <=> $a['modified_at']) ?: strcmp($a['name'], $b['name']);
    });

    return array_values($files);
}

function db_tools_auto_backup_config_path(): string
{
    return db_tools_backups_root() . DIRECTORY_SEPARATOR . 'auto_backup_settings.json';
}

function db_tools_default_auto_backup_config(): array
{
    return [
        'task_name' => 'UASPMS-Auto-DB-Backup',
        'output_dir' => db_tools_backups_root() . DIRECTORY_SEPARATOR . 'auto',
        'keep_days' => 30,
        'schedule_type' => 'daily',
        'start_time' => '23:00',
        'weekly_day' => 'MON',
        'include_tripdb' => false,
    ];
}

function db_tools_load_auto_backup_config(): array
{
    $defaults = db_tools_default_auto_backup_config();
    $path = db_tools_auto_backup_config_path();
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $config = array_merge($defaults, $decoded);

    $config['task_name'] = trim((string) ($config['task_name'] ?? $defaults['task_name']));
    if ($config['task_name'] === '') {
        $config['task_name'] = $defaults['task_name'];
    }

    $config['output_dir'] = trim((string) ($config['output_dir'] ?? $defaults['output_dir']));
    if ($config['output_dir'] === '') {
        $config['output_dir'] = $defaults['output_dir'];
    }

    $config['keep_days'] = max(1, (int) ($config['keep_days'] ?? $defaults['keep_days']));

    $scheduleType = strtolower(trim((string) ($config['schedule_type'] ?? $defaults['schedule_type'])));
    $config['schedule_type'] = in_array($scheduleType, ['hourly', 'daily', 'weekly'], true) ? $scheduleType : $defaults['schedule_type'];

    $startTime = trim((string) ($config['start_time'] ?? $defaults['start_time']));
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
        $startTime = $defaults['start_time'];
    }
    $config['start_time'] = $startTime;

    $weeklyDay = strtoupper(trim((string) ($config['weekly_day'] ?? $defaults['weekly_day'])));
    if (!in_array($weeklyDay, ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'], true)) {
        $weeklyDay = $defaults['weekly_day'];
    }
    $config['weekly_day'] = $weeklyDay;

    $config['include_tripdb'] = (bool) ($config['include_tripdb'] ?? false);

    return $config;
}

function db_tools_save_auto_backup_config(array $config): bool
{
    $path = db_tools_auto_backup_config_path();
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    return file_put_contents($path, $json . PHP_EOL) !== false;
}

function db_tools_command_available(string $command): bool
{
    $output = [];
    $exitCode = 0;
    @exec('where ' . escapeshellarg($command) . ' 2>nul', $output, $exitCode);
    return $exitCode === 0 && !empty($output);
}

function db_tools_escape_for_task_arg(string $value): string
{
    return '"' . str_replace('"', '\\"', $value) . '"';
}

function db_tools_register_scheduled_backup(array $config, array &$errors): bool
{
    if (!db_tools_exec_available()) {
        $errors[] = 'Cannot register scheduled backup: PHP shell execution is disabled.';
        return false;
    }

    if (!db_tools_command_available('schtasks.exe')) {
        $errors[] = 'Cannot register scheduled backup: schtasks.exe is not available on this server.';
        return false;
    }

    $scriptPath = db_tools_project_root() . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup_database.ps1';
    if (!is_file($scriptPath)) {
        $errors[] = 'Cannot register scheduled backup: scripts/backup_database.ps1 was not found.';
        return false;
    }

    $taskName = trim((string) ($config['task_name'] ?? 'UASPMS-Auto-DB-Backup'));
    if ($taskName === '') {
        $taskName = 'UASPMS-Auto-DB-Backup';
    }

    $psExe = 'powershell.exe';
    $runArgs = [
        '-NoProfile',
        '-ExecutionPolicy',
        'Bypass',
        '-File',
        db_tools_escape_for_task_arg($scriptPath),
        '-KeepDays',
        (string) max(1, (int) ($config['keep_days'] ?? 30)),
        '-OutputDir',
        db_tools_escape_for_task_arg((string) $config['output_dir']),
    ];

    if (!empty($config['include_tripdb'])) {
        $runArgs[] = '-IncludeTripDb';
    }

    $taskRun = $psExe . ' ' . implode(' ', $runArgs);

    $baseParts = [
        'schtasks.exe',
        '/Create',
        '/F',
        '/TN',
        db_tools_escape_for_task_arg($taskName),
        '/TR',
        db_tools_escape_for_task_arg($taskRun),
    ];

    $scheduleType = strtolower((string) ($config['schedule_type'] ?? 'daily'));
    $startTime = (string) ($config['start_time'] ?? '23:00');

    if ($scheduleType === 'hourly') {
        $baseParts[] = '/SC';
        $baseParts[] = 'HOURLY';
        $baseParts[] = '/MO';
        $baseParts[] = '1';
        $baseParts[] = '/ST';
        $baseParts[] = $startTime;
    } elseif ($scheduleType === 'weekly') {
        $weeklyDay = strtoupper((string) ($config['weekly_day'] ?? 'MON'));
        $baseParts[] = '/SC';
        $baseParts[] = 'WEEKLY';
        $baseParts[] = '/D';
        $baseParts[] = $weeklyDay;
        $baseParts[] = '/ST';
        $baseParts[] = $startTime;
    } else {
        $baseParts[] = '/SC';
        $baseParts[] = 'DAILY';
        $baseParts[] = '/ST';
        $baseParts[] = $startTime;
    }

    $attempts = [
        array_merge($baseParts, ['/RU', db_tools_escape_for_task_arg('SYSTEM'), '/RL', 'HIGHEST']),
        $baseParts,
    ];

    $lastOutput = [];
    foreach ($attempts as $index => $parts) {
        $output = [];
        $exitCode = 0;
        $command = implode(' ', $parts);
        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode === 0) {
            return true;
        }

        $lastOutput = $output;
        $text = strtolower(trim(implode(' ', $output)));
        if ($index === 0 && strpos($text, 'access is denied') !== false) {
            continue;
        }

        break;
    }

    $errors[] = 'Unable to register Windows scheduled task. ' . trim(implode(' ', $lastOutput));
    return false;
}

function db_tools_get_task_status(string $taskName): ?array
{
    if (!db_tools_exec_available() || !db_tools_command_available('schtasks.exe')) {
        return null;
    }

    $output = [];
    $exitCode = 0;
    $command = 'schtasks.exe /Query /TN ' . db_tools_escape_for_task_arg($taskName) . ' /FO LIST /V';
    exec($command . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0 || empty($output)) {
        return null;
    }

    $status = [];
    foreach ($output as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }

        [$key, $value] = explode(':', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '') {
            $status[$key] = $value;
        }
    }

    return $status;
}

function db_tools_normalize_output_dir(string $path): string
{
    $path = trim($path);
    $path = trim($path, '"');
    if ($path === '') {
        return '';
    }

    $expanded = preg_replace_callback('/%([A-Za-z0-9_]+)%/', static function (array $matches): string {
        $value = getenv($matches[1]);
        return is_string($value) && $value !== '' ? $value : $matches[0];
    }, $path);

    if (!is_string($expanded)) {
        return $path;
    }

    return rtrim($expanded, " \\t\\n\\r\\0\\x0B\\/");
}

function db_tools_detect_php_cli(): ?string
{
    $candidates = [];

    if (defined('PHP_BINARY')) {
        $candidates[] = (string) PHP_BINARY;
        $candidates[] = dirname((string) PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe';
    }

    if (defined('PHP_BINDIR')) {
        $candidates[] = rtrim((string) PHP_BINDIR, "\\/") . DIRECTORY_SEPARATOR . 'php.exe';
    }

    $candidates[] = 'C:\\xampp\\php\\php.exe';
    $candidates[] = 'F:\\xampp\\php\\php.exe';

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }

        if (!is_file($candidate)) {
            continue;
        }

        $base = strtolower((string) basename($candidate));
        if ($base === 'php.exe' || $base === 'php') {
            return $candidate;
        }
    }

    if (db_tools_command_available('php.exe')) {
        $output = [];
        $exitCode = 0;
        @exec('where php.exe 2>nul', $output, $exitCode);
        if ($exitCode === 0) {
            foreach ($output as $line) {
                $line = trim((string) $line);
                if ($line !== '' && is_file($line)) {
                    return $line;
                }
            }
        }
    }

    return null;
}

function db_tools_run_backup_now(array $config, array &$errors): bool
{
    if (!db_tools_exec_available()) {
        $errors[] = 'Cannot run test backup: PHP shell execution is disabled.';
        return false;
    }

    $scriptPath = db_tools_project_root() . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup_database.php';
    if (!is_file($scriptPath)) {
        $errors[] = 'Cannot run test backup: scripts/backup_database.php was not found.';
        return false;
    }

    $phpBinary = db_tools_detect_php_cli();
    if ($phpBinary === null) {
        $errors[] = 'Cannot run test backup: php.exe (CLI) was not found.';
        return false;
    }

    $commandParts = [
        escapeshellarg($phpBinary),
        escapeshellarg($scriptPath),
        '--keep-days=' . (string) max(1, (int) ($config['keep_days'] ?? 30)),
        '--output-dir=' . escapeshellarg((string) ($config['output_dir'] ?? db_tools_backups_root() . DIRECTORY_SEPARATOR . 'auto')),
    ];

    if (!empty($config['include_tripdb'])) {
        $commandParts[] = '--include-tripdb';
    }

    $output = [];
    $exitCode = 0;
    exec(implode(' ', $commandParts) . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        $errors[] = 'Test backup failed. ' . trim(implode(' ', $output));
        return false;
    }

    return true;
}

function db_tools_validate_output_directory(string $outputDir, array &$errors): bool
{
    if ($outputDir === '') {
        $errors[] = 'Backup output directory is required.';
        return false;
    }

    if (!is_dir($outputDir) && !@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        $errors[] = 'Unable to create the backup output directory: ' . $outputDir;
        return false;
    }

    if (!is_writable($outputDir)) {
        $errors[] = 'Backup output directory is not writable: ' . $outputDir;
        return false;
    }

    $probeFile = rtrim($outputDir, "\\/") . DIRECTORY_SEPARATOR . '.uaspms_write_test_' . uniqid('', true) . '.tmp';
    $written = @file_put_contents($probeFile, 'ok');
    if ($written === false) {
        $errors[] = 'Backup output directory failed write test: ' . $outputDir;
        return false;
    }

    @unlink($probeFile);
    return true;
}

$db = db();
$page_title = 'Database Tools';
$flash = get_flash();
$errors = [];
$files = [];
$maxUploadBytes = 50 * 1024 * 1024;
$autoBackupConfig = db_tools_load_auto_backup_config();
$autoBackupTaskStatus = db_tools_get_task_status((string) $autoBackupConfig['task_name']);

db_tools_ensure_directory(db_tools_backups_root());
db_tools_ensure_directory(db_tools_generated_dir());
db_tools_ensure_directory(db_tools_uploaded_dir());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } elseif ($action === 'create_backup') {
        $backup = db_tools_create_backup_file($errors);
        if ($backup !== null) {
            if ($db instanceof mysqli) {
                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'database_backups',
                    'record_id' => $backup['filename'],
                    'module_name' => 'settings',
                    'record_type' => 'database_backup',
                    'action_name' => 'create_database_backup',
                    'new_values' => [
                        'filename' => $backup['filename'],
                        'size' => $backup['size'],
                    ],
                    'description' => 'Created a database backup file.',
                ]);
            }

            set_flash('success', 'Database backup created successfully: ' . $backup['filename']);
            redirect('modules/settings/database_tools.php');
        }
    } elseif ($action === 'upload_restore') {
        $file = $_FILES['sql_dump'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Choose a SQL dump file to upload.';
        } elseif (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed. Please try again.';
        } elseif (($file['size'] ?? 0) > $maxUploadBytes) {
            $errors[] = 'SQL dump exceeds the 50 MB upload limit.';
        } else {
            $originalName = trim((string) ($file['name'] ?? 'database.sql'));
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($extension !== 'sql') {
                $errors[] = 'Only .sql files can be uploaded.';
            } else {
                $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $baseName);
                $safeName = trim((string) $safeName, '._-');
                if ($safeName === '') {
                    $safeName = 'database_dump';
                }

                $storedName = $safeName . '_' . date('Y-m-d_H-i-s') . '.sql';
                $storedPath = db_tools_uploaded_dir() . DIRECTORY_SEPARATOR . $storedName;

                if (!move_uploaded_file((string) $file['tmp_name'], $storedPath)) {
                    $errors[] = 'Unable to save the uploaded SQL file.';
                } elseif (db_tools_restore_from_file($storedPath, $errors)) {
                    $auditDb = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                    if (!$auditDb->connect_errno) {
                        write_audit_log($auditDb, [
                            'action' => 'update',
                            'table_name' => 'database_backups',
                            'record_id' => $storedName,
                            'module_name' => 'settings',
                            'record_type' => 'database_restore',
                            'action_name' => 'upload_restore_database',
                            'new_values' => [
                                'source_file' => $storedName,
                            ],
                            'description' => 'Restored the database from an uploaded SQL dump.',
                        ]);
                        $auditDb->close();
                    }

                    set_flash('success', 'Database restored successfully from uploaded file: ' . $storedName);
                    redirect('modules/settings/database_tools.php');
                }
            }
        }
    } elseif ($action === 'save_auto_backup_settings') {
        $taskName = trim((string) ($_POST['task_name'] ?? 'UASPMS-Auto-DB-Backup'));
        $outputDir = db_tools_normalize_output_dir((string) ($_POST['output_dir'] ?? ''));
        $keepDays = (int) ($_POST['keep_days'] ?? 30);
        $scheduleType = strtolower(trim((string) ($_POST['schedule_type'] ?? 'daily')));
        $startTime = trim((string) ($_POST['start_time'] ?? '23:00'));
        $weeklyDay = strtoupper(trim((string) ($_POST['weekly_day'] ?? 'MON')));
        $includeTripDb = isset($_POST['include_tripdb']) && $_POST['include_tripdb'] === '1';

        if ($taskName === '') {
            $errors[] = 'Task name is required.';
        }

        if ($outputDir === '') {
            $errors[] = 'Backup output directory is required.';
        }

        if ($keepDays < 1) {
            $errors[] = 'Retention days must be at least 1.';
        }

        if (!in_array($scheduleType, ['hourly', 'daily', 'weekly'], true)) {
            $errors[] = 'Invalid schedule type selected.';
        }

        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
            $errors[] = 'Start time must use 24-hour format HH:MM.';
        }

        if ($scheduleType === 'weekly' && !in_array($weeklyDay, ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'], true)) {
            $errors[] = 'Invalid weekly day selected.';
        }

        if (empty($errors)) {
            $configToSave = [
                'task_name' => $taskName,
                'output_dir' => $outputDir,
                'keep_days' => $keepDays,
                'schedule_type' => $scheduleType,
                'start_time' => $startTime,
                'weekly_day' => $weeklyDay,
                'include_tripdb' => $includeTripDb,
            ];

            if (!db_tools_save_auto_backup_config($configToSave)) {
                $errors[] = 'Failed to save auto-backup settings.';
            } elseif (!db_tools_register_scheduled_backup($configToSave, $errors)) {
                $autoBackupConfig = $configToSave;
            } else {
                if ($db instanceof mysqli) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'database_backups',
                        'record_id' => $taskName,
                        'module_name' => 'settings',
                        'record_type' => 'database_backup_schedule',
                        'action_name' => 'save_database_backup_schedule',
                        'new_values' => $configToSave,
                        'description' => 'Updated automatic database backup schedule settings.',
                    ]);
                }

                set_flash('success', 'Automatic backup settings saved and scheduled task updated.');
                redirect('modules/settings/database_tools.php');
            }
        }

        if (empty($errors)) {
            $autoBackupConfig = db_tools_load_auto_backup_config();
        } else {
            $autoBackupConfig = [
                'task_name' => $taskName,
                'output_dir' => $outputDir,
                'keep_days' => $keepDays,
                'schedule_type' => $scheduleType,
                'start_time' => $startTime,
                'weekly_day' => $weeklyDay,
                'include_tripdb' => $includeTripDb,
            ];
        }
        $autoBackupTaskStatus = db_tools_get_task_status((string) $autoBackupConfig['task_name']);
    } elseif ($action === 'run_auto_backup_test') {
        $taskName = trim((string) ($_POST['task_name'] ?? 'UASPMS-Auto-DB-Backup'));
        $outputDir = db_tools_normalize_output_dir((string) ($_POST['output_dir'] ?? ''));
        $keepDays = (int) ($_POST['keep_days'] ?? 30);
        $scheduleType = strtolower(trim((string) ($_POST['schedule_type'] ?? 'daily')));
        $startTime = trim((string) ($_POST['start_time'] ?? '23:00'));
        $weeklyDay = strtoupper(trim((string) ($_POST['weekly_day'] ?? 'MON')));
        $includeTripDb = isset($_POST['include_tripdb']) && $_POST['include_tripdb'] === '1';

        if ($taskName === '') {
            $errors[] = 'Task name is required.';
        }

        if ($outputDir === '') {
            $errors[] = 'Backup output directory is required.';
        }

        if ($keepDays < 1) {
            $errors[] = 'Retention days must be at least 1.';
        }

        if (!in_array($scheduleType, ['hourly', 'daily', 'weekly'], true)) {
            $errors[] = 'Invalid schedule type selected.';
        }

        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
            $errors[] = 'Start time must use 24-hour format HH:MM.';
        }

        if ($scheduleType === 'weekly' && !in_array($weeklyDay, ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'], true)) {
            $errors[] = 'Invalid weekly day selected.';
        }

        $autoBackupConfig = [
            'task_name' => $taskName,
            'output_dir' => $outputDir,
            'keep_days' => $keepDays,
            'schedule_type' => $scheduleType,
            'start_time' => $startTime,
            'weekly_day' => $weeklyDay,
            'include_tripdb' => $includeTripDb,
        ];

        if (empty($errors) && db_tools_run_backup_now($autoBackupConfig, $errors)) {
            if ($db instanceof mysqli) {
                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'database_backups',
                    'record_id' => (string) $taskName,
                    'module_name' => 'settings',
                    'record_type' => 'database_backup_test',
                    'action_name' => 'run_database_backup_test',
                    'new_values' => [
                        'output_dir' => $outputDir,
                        'keep_days' => $keepDays,
                        'include_tripdb' => $includeTripDb,
                    ],
                    'description' => 'Ran an immediate test backup using auto-backup settings.',
                ]);
            }

            set_flash('success', 'Test backup completed successfully. Check the backup folder for the new SQL file.');
            redirect('modules/settings/database_tools.php');
        }

        $autoBackupTaskStatus = db_tools_get_task_status((string) $autoBackupConfig['task_name']);
    } elseif ($action === 'validate_auto_backup_path') {
        $taskName = trim((string) ($_POST['task_name'] ?? 'UASPMS-Auto-DB-Backup'));
        $outputDir = db_tools_normalize_output_dir((string) ($_POST['output_dir'] ?? ''));
        $keepDays = (int) ($_POST['keep_days'] ?? 30);
        $scheduleType = strtolower(trim((string) ($_POST['schedule_type'] ?? 'daily')));
        $startTime = trim((string) ($_POST['start_time'] ?? '23:00'));
        $weeklyDay = strtoupper(trim((string) ($_POST['weekly_day'] ?? 'MON')));
        $includeTripDb = isset($_POST['include_tripdb']) && $_POST['include_tripdb'] === '1';

        $autoBackupConfig = [
            'task_name' => $taskName,
            'output_dir' => $outputDir,
            'keep_days' => $keepDays,
            'schedule_type' => $scheduleType,
            'start_time' => $startTime,
            'weekly_day' => $weeklyDay,
            'include_tripdb' => $includeTripDb,
        ];

        if (db_tools_validate_output_directory($outputDir, $errors)) {
            set_flash('success', 'Backup location is valid and writable: ' . $outputDir);
            redirect('modules/settings/database_tools.php');
        }

        $autoBackupTaskStatus = db_tools_get_task_status((string) $autoBackupConfig['task_name']);
    }
}

$files = db_tools_collect_files();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="section">
    <div class="row g-4">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-lg-5">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                        <div>
                            <div class="text-uppercase small text-muted fw-semibold">Administration</div>
                            <h4 class="mb-2">Database Tools</h4>
                            <p class="text-muted mb-0">Create a fresh SQL backup of <strong><?php echo h(DB_NAME); ?></strong> or upload a SQL dump to rebuild the database.</p>
                        </div>
                        <div class="d-flex flex-column align-items-md-end">
                            <span class="badge text-bg-light">Database: <?php echo h(DB_NAME); ?></span>
                        </div>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $error): ?>
                                <div><?php echo h($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>">
                            <?php echo h($flash['message']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($flash): ?>
                        <div id="serverFlashToastPayload"
                             data-type="<?php echo h((string) $flash['type']); ?>"
                             data-message="<?php echo h((string) $flash['message']); ?>"
                             class="d-none"></div>
                    <?php endif; ?>

                    <div id="backupToastHost" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;"></div>

                    <div id="backupRunNotice" class="alert alert-info d-none" role="status" aria-live="polite"></div>

                    <div class="row g-4">
                        <div class="col-12">
                            <div class="border rounded-3 p-4">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="fs-2 text-success"><i class="bi bi-clock-history"></i></div>
                                    <div>
                                        <div class="text-uppercase small text-muted fw-semibold">Automation</div>
                                        <h5 class="mb-0">Automatic Backup Settings</h5>
                                    </div>
                                </div>
                                <p class="text-muted small mb-3">Choose where to save backups and when to run them. Saving this form updates a Windows Task Scheduler job.</p>

                                <form method="post" class="row g-3" id="autoBackupForm">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" id="auto_backup_action" value="save_auto_backup_settings">

                                    <div class="col-md-6">
                                        <label for="task_name" class="form-label">Task Name</label>
                                        <input type="text" class="form-control" id="task_name" name="task_name" value="<?php echo h((string) $autoBackupConfig['task_name']); ?>" required>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="output_dir" class="form-label">Backup Location</label>
                                        <input type="text" class="form-control" id="output_dir" name="output_dir" value="<?php echo h((string) $autoBackupConfig['output_dir']); ?>" placeholder="D:\\UASPMS-Backups" required>
                                        <div class="form-text">Use any local drive/folder path, including OneDrive (example: <code>%OneDrive%\UASPMS-Backups</code> or <code>C:\Users\YourName\OneDrive\UASPMS-Backups</code>).</div>
                                    </div>

                                    <div class="col-md-4">
                                        <label for="schedule_type" class="form-label">Repetition</label>
                                        <select class="form-select" id="schedule_type" name="schedule_type" required>
                                            <?php $selectedSchedule = (string) ($autoBackupConfig['schedule_type'] ?? 'daily'); ?>
                                            <option value="hourly" <?php echo $selectedSchedule === 'hourly' ? 'selected' : ''; ?>>Hourly</option>
                                            <option value="daily" <?php echo $selectedSchedule === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                            <option value="weekly" <?php echo $selectedSchedule === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label for="start_time" class="form-label">Start Time</label>
                                        <input type="time" class="form-control" id="start_time" name="start_time" value="<?php echo h((string) $autoBackupConfig['start_time']); ?>" required>
                                    </div>

                                    <div class="col-md-4" id="weeklyDayCol">
                                        <label for="weekly_day" class="form-label">Weekly Day</label>
                                        <?php $selectedWeeklyDay = (string) ($autoBackupConfig['weekly_day'] ?? 'MON'); ?>
                                        <select class="form-select" id="weekly_day" name="weekly_day">
                                            <option value="MON" <?php echo $selectedWeeklyDay === 'MON' ? 'selected' : ''; ?>>Monday</option>
                                            <option value="TUE" <?php echo $selectedWeeklyDay === 'TUE' ? 'selected' : ''; ?>>Tuesday</option>
                                            <option value="WED" <?php echo $selectedWeeklyDay === 'WED' ? 'selected' : ''; ?>>Wednesday</option>
                                            <option value="THU" <?php echo $selectedWeeklyDay === 'THU' ? 'selected' : ''; ?>>Thursday</option>
                                            <option value="FRI" <?php echo $selectedWeeklyDay === 'FRI' ? 'selected' : ''; ?>>Friday</option>
                                            <option value="SAT" <?php echo $selectedWeeklyDay === 'SAT' ? 'selected' : ''; ?>>Saturday</option>
                                            <option value="SUN" <?php echo $selectedWeeklyDay === 'SUN' ? 'selected' : ''; ?>>Sunday</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="keep_days" class="form-label">Retention (Days)</label>
                                        <input type="number" min="1" class="form-control" id="keep_days" name="keep_days" value="<?php echo h((string) ((int) $autoBackupConfig['keep_days'])); ?>" required>
                                    </div>

                                    <div class="col-md-6 d-flex align-items-center pt-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="include_tripdb" name="include_tripdb" value="1" <?php echo !empty($autoBackupConfig['include_tripdb']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="include_tripdb">Include TRIP database backup</label>
                                        </div>
                                    </div>

                                    <div class="col-12 d-flex flex-wrap gap-2">
                                        <button type="submit" class="btn btn-success" onclick="document.getElementById('auto_backup_action').value='save_auto_backup_settings';">
                                            <i class="bi bi-save me-1"></i>Save Auto Backup Settings
                                        </button>
                                        <button type="submit" class="btn btn-outline-secondary" onclick="document.getElementById('auto_backup_action').value='validate_auto_backup_path';">
                                            <i class="bi bi-folder-check me-1"></i>Validate Path
                                        </button>
                                        <button type="submit" class="btn btn-outline-primary" onclick="document.getElementById('auto_backup_action').value='run_auto_backup_test';">
                                            <i class="bi bi-play-circle me-1"></i>Run Test Backup Now
                                        </button>
                                    </div>
                                </form>

                                <?php if ($autoBackupTaskStatus): ?>
                                    <div class="alert alert-light border mt-3 mb-0">
                                        <div class="fw-semibold mb-2">Windows Task Status</div>
                                        <div class="small"><strong>Status:</strong> <?php echo h((string) ($autoBackupTaskStatus['Status'] ?? 'Unknown')); ?></div>
                                        <div class="small"><strong>Next Run:</strong> <?php echo h((string) ($autoBackupTaskStatus['Next Run Time'] ?? 'Not available')); ?></div>
                                        <div class="small"><strong>Last Run:</strong> <?php echo h((string) ($autoBackupTaskStatus['Last Run Time'] ?? 'Not available')); ?></div>
                                        <div class="small"><strong>Last Result:</strong> <?php echo h((string) ($autoBackupTaskStatus['Last Result'] ?? 'Not available')); ?></div>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-secondary mt-3 mb-0 small">
                                        No matching Windows scheduled task found yet for this task name.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="border rounded-3 p-4 h-100">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="fs-2 text-primary"><i class="bi bi-download"></i></div>
                                    <div>
                                        <div class="text-uppercase small text-muted fw-semibold">Backup</div>
                                        <h5 class="mb-0">Create SQL Backup</h5>
                                    </div>
                                </div>
                                <p class="text-muted small">Generate a timestamped `.sql` dump and save it under `database/backups/generated`.</p>
                                <form method="post" id="createBackupForm">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="create_backup">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-database-down me-1"></i>Create Backup
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="border rounded-3 p-4 h-100">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="fs-2 text-warning"><i class="bi bi-upload"></i></div>
                                    <div>
                                        <div class="text-uppercase small text-muted fw-semibold">Restore</div>
                                        <h5 class="mb-0">Upload SQL Dump</h5>
                                    </div>
                                </div>
                                <p class="text-muted small mb-3">Upload a `.sql` file to drop, recreate, and restore <strong><?php echo h(DB_NAME); ?></strong>. The uploaded file is kept in `database/backups/uploaded` for traceability.</p>
                                <div class="alert alert-warning small py-2">
                                    This action replaces the current database contents. Create a backup first if you need to keep the current data.
                                </div>
                                <form method="post" enctype="multipart/form-data">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="upload_restore">
                                    <div class="mb-3">
                                        <label for="sql_dump" class="form-label">SQL Dump File</label>
                                        <input type="file" class="form-control" id="sql_dump" name="sql_dump" accept=".sql,text/plain" required>
                                        <div class="form-text">Only `.sql` files up to <?php echo h(db_tools_format_bytes($maxUploadBytes)); ?> are accepted.</div>
                                    </div>
                                    <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Restore the database from this uploaded SQL file? This will overwrite the current database.');">
                                        <i class="bi bi-arrow-repeat me-1"></i>Upload And Restore
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div>
                                <h5 class="mb-0">Available SQL Files</h5>
                                <div class="text-muted small">Backups already stored inside the project.</div>
                            </div>
                            <span class="badge text-bg-light"><?php echo count($files); ?> file(s)</span>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>File</th>
                                        <th>Type</th>
                                        <th>Location</th>
                                        <th>Size</th>
                                        <th>Modified</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($files): ?>
                                        <?php foreach ($files as $file): ?>
                                            <?php
                                                $fname = (string) $file['name'];
                                                if (strpos($fname, '_auto_') !== false) {
                                                    $typeBadge = '<span class="badge text-bg-success">Auto</span>';
                                                } elseif (strpos(str_replace('\\', '/', (string) $file['path']), '/uploaded/') !== false) {
                                                    $typeBadge = '<span class="badge text-bg-warning text-dark">Uploaded</span>';
                                                } else {
                                                    $typeBadge = '<span class="badge text-bg-secondary">Manual</span>';
                                                }
                                            ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo h($file['name']); ?></td>
                                                <td><?php echo $typeBadge; ?></td>
                                                <td><code><?php echo h($file['relative_path']); ?></code></td>
                                                <td><?php echo h(db_tools_format_bytes((int) $file['size'])); ?></td>
                                                <td><?php echo h(date('M d, Y h:i A', (int) $file['modified_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">No SQL files found yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
    (function () {
        const scheduleType = document.getElementById('schedule_type');
        const weeklyDayCol = document.getElementById('weeklyDayCol');
        const notice = document.getElementById('backupRunNotice');
        const autoBackupForm = document.getElementById('autoBackupForm');
        const createBackupForm = document.getElementById('createBackupForm');
        const autoBackupAction = document.getElementById('auto_backup_action');

        if (!scheduleType || !weeklyDayCol) {
            return;
        }

        function syncWeeklyVisibility() {
            weeklyDayCol.style.display = scheduleType.value === 'weekly' ? '' : 'none';
        }

        function setRunningNotice(message) {
            if (!notice) {
                return;
            }
            notice.textContent = message;
            notice.classList.remove('d-none', 'alert-success', 'alert-danger');
            notice.classList.add('alert-info');
        }

        function showToast(kind, message) {
            const host = document.getElementById('backupToastHost');
            if (!host || !message) {
                return;
            }

            let cssClass = 'text-bg-info';
            if (kind === 'success') {
                cssClass = 'text-bg-success';
            } else if (kind === 'danger' || kind === 'error') {
                cssClass = 'text-bg-danger';
            }

            const toast = document.createElement('div');
            toast.className = 'toast align-items-center border-0 ' + cssClass;
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.setAttribute('aria-atomic', 'true');

            const body = document.createElement('div');
            body.className = 'd-flex';
            body.innerHTML = '<div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>';
            body.querySelector('.toast-body').textContent = message;
            toast.appendChild(body);
            host.appendChild(toast);

            if (window.bootstrap && window.bootstrap.Toast) {
                const instance = new window.bootstrap.Toast(toast, { delay: 4500 });
                instance.show();
                toast.addEventListener('hidden.bs.toast', function () {
                    toast.remove();
                });
                return;
            }

            toast.classList.add('show');
            setTimeout(function () {
                toast.remove();
            }, 4500);
        }

        function setButtonsDisabled(form, disabled) {
            if (!form) {
                return;
            }
            form.querySelectorAll('button[type="submit"]').forEach((btn) => {
                btn.disabled = disabled;
            });
        }

        if (createBackupForm) {
            createBackupForm.addEventListener('submit', function () {
                setRunningNotice('Backup is running. Please wait...');
                showToast('info', 'Backup is running. Please wait...');
                setButtonsDisabled(createBackupForm, true);
            });
        }

        if (autoBackupForm) {
            autoBackupForm.addEventListener('submit', function () {
                const action = autoBackupAction ? autoBackupAction.value : 'save_auto_backup_settings';
                if (action === 'run_auto_backup_test') {
                    setRunningNotice('Test backup is running. Please wait...');
                    showToast('info', 'Test backup is running. Please wait...');
                } else if (action === 'validate_auto_backup_path') {
                    setRunningNotice('Validating backup location...');
                    showToast('info', 'Validating backup location...');
                } else {
                    setRunningNotice('Saving schedule and updating Windows task...');
                    showToast('info', 'Saving schedule and updating Windows task...');
                }
                setButtonsDisabled(autoBackupForm, true);
            });
        }

        const serverFlashPayload = document.getElementById('serverFlashToastPayload');
        if (serverFlashPayload) {
            const type = serverFlashPayload.getAttribute('data-type') || 'info';
            const message = serverFlashPayload.getAttribute('data-message') || '';
            if (message) {
                showToast(type, message);
            }
        }

        scheduleType.addEventListener('change', syncWeeklyVisibility);
        syncWeeklyVisibility();
    })();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
