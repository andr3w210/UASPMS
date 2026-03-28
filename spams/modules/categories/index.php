<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

set_flash('info', 'Categories have been consolidated into Inventory Classes.');
redirect('modules/classifications/index.php');
