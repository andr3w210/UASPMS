<?php
require_once __DIR__ . '/../spams/app/helpers/property_qr.php';

$sample = "2018-01-05.020-001(2)|SPMU||G618M550098|Photocopier|3285";
$result = property_qr_parse_payload($sample);
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
