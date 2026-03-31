<?php
require_once __DIR__ . '/app/config/init.php';

if (is_logged_in()) {
    redirect('dashboard/index.php');
}

redirect('auth/login.php');
