<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
$r = $m->query('DESCRIBE rpcppe_batch_items');
while($row=$r->fetch_assoc()){
    echo $row['Field'] . ' | ' . $row['Type'] . ' | Null=' . $row['Null'] . ' | Default=' . ($row['Default'] ?? 'NULL') . "\n";
}
$m->close();
