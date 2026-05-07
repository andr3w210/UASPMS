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
    $directories = [
        db_tools_backups_root(),
        db_tools_generated_dir(),
        db_tools_uploaded_dir(),
    ];

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

$db = db();
$page_title = 'Database Tools';
$flash = get_flash();
$errors = [];
$files = [];
$maxUploadBytes = 50 * 1024 * 1024;

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

                    <div class="row g-4">
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
                                <form method="post">
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
                                        <th>Location</th>
                                        <th>Size</th>
                                        <th>Modified</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($files): ?>
                                        <?php foreach ($files as $file): ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo h($file['name']); ?></td>
                                                <td><code><?php echo h($file['relative_path']); ?></code></td>
                                                <td><?php echo h(db_tools_format_bytes((int) $file['size'])); ?></td>
                                                <td><?php echo h(date('M d, Y h:i A', (int) $file['modified_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No SQL files found yet.</td>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
