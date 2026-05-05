<?php

// Application constants
define('APP_VERSION', 'v1.1.0');
define('BASE_URL', '/UASPMS/spams');
define('LOGO_PATH', BASE_URL . '/assets/img/ua-logo.png');
define('APP_ROOT', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);

if (!function_exists('spams_load_env')) {
    function spams_load_env(string $envPath): void
    {
        static $loaded = false;

        if ($loaded || !is_file($envPath) || !is_readable($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || strpos($line, '=') === false) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if ($name === '') {
                continue;
            }

            if (
                (isset($_ENV[$name]) && $_ENV[$name] !== '') ||
                (isset($_SERVER[$name]) && $_SERVER[$name] !== '') ||
                getenv($name) !== false
            ) {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name . '=' . $value);
        }

        $loaded = true;
    }
}

if (!function_exists('spams_env')) {
    function spams_env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}

spams_load_env(APP_ROOT . '.env');

// Database
define('DB_HOST', spams_env('DB_HOST', '127.0.0.1'));
define('DB_NAME', spams_env('DB_NAME', 'spamsdb'));
define('DB_USER', spams_env('DB_USER', 'root'));
define('DB_PASS', spams_env('DB_PASS', ''));
define('TRIP_DB_HOST', spams_env('TRIP_DB_HOST', DB_HOST));
define('TRIP_DB_NAME', spams_env('TRIP_DB_NAME', 'uaspms_tripdb'));
define('TRIP_DB_USER', spams_env('TRIP_DB_USER', DB_USER));
define('TRIP_DB_PASS', spams_env('TRIP_DB_PASS', DB_PASS));

// Misc
define('APP_NAME', spams_env('APP_NAME', 'University of Antique'));
define('APP_URL', rtrim((string) spams_env('APP_URL', ''), '/'));
define('TIMEZONE', spams_env('TIMEZONE', 'Asia/Manila'));
define('LOW_STOCK_THRESHOLD', (int) spams_env('LOW_STOCK_THRESHOLD', '5'));

// Uploads
define('UPLOADS_DIR', APP_ROOT . 'uploads' . DIRECTORY_SEPARATOR);

// AI / OCR
define('GEMINI_API_KEY', spams_env('GEMINI_API_KEY', ''));
define('OPENAI_API_KEY', spams_env('OPENAI_API_KEY', ''));
define('ANTHROPIC_API_KEY', spams_env('ANTHROPIC_API_KEY', ''));

?>
