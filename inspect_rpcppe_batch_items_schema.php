<?php
$m = new mysqli('127.0.0.1','root','','spamsdb');
$r = $m->query('DESCRIBE rpcppe_batch_items');
while($row=$r->fetch_assoc()){
    echo $row['Field'] . ' | ' . $row['Type'] . ' | Null=' . $row['Null'] . ' | Default=' . ($row['Default'] ?? 'NULL') . "\n";
}
$m->close();
