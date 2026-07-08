<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

set_flash('info', 'Categories have been consolidated into Inventory Classes.');
redirect('modules/classifications/index.php');
