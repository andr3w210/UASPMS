<?php
// Application constants
define('BASE_URL', '/UASPMS/spams');
define('LOGO_PATH', BASE_URL . '/assets/img/ua-logo.png');
define('APP_ROOT', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);

// Database (update values for your environment)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'spamsdb');
define('DB_USER', 'root');
define('DB_PASS', '');

// Misc
define('TIMEZONE', 'Asia/Manila');

// Uploads
define('UPLOADS_DIR', APP_ROOT . 'uploads' . DIRECTORY_SEPARATOR);

// AI / OCR
define('GEMINI_API_KEY', 'AIzaSyDadI33A2rYeaDZyMdDKS2GAbiFBbVqkGc');
define('OPENAI_API_KEY', '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');
// When switching to Claude later, add:
// define('ANTHROPIC_API_KEY', 'sk-ant-your-key-here');

?>
