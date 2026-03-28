<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

redirect('modules/settings/thresholds.php');
