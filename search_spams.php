<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/spams/app/config/constants.php';

$dbName = DB_NAME;

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
if ($mysqli->connect_error) {
    die("Connect Error: " . $mysqli->connect_error);
}

$searchValues = [
    "2511DTJ25AH06500771",
    "2601DTJ25CH08401220",
    "2511DTJ25AH06500582",
    "2511DTJ25AH06500498",
    "63907/106100011937"
];

$escapedValues = array_map(function($val) use ($mysqli) {
    return "'" . $mysqli->real_escape_string($val) . "'";
}, $searchValues);
$inClause = implode(",", $escapedValues);

$query = "SELECT TABLE_NAME, COLUMN_NAME 
          FROM information_schema.COLUMNS 
          WHERE TABLE_SCHEMA = '" . $mysqli->real_escape_string($dbName) . "' 
          AND DATA_TYPE IN ('char', 'varchar', 'text', 'mediumtext', 'longtext')";

$result = $mysqli->query($query);
$tables = [];
while ($row = $result->fetch_assoc()) {
    $tables[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
}

$totalHits = 0;
foreach ($tables as $table => $columns) {
    $tableHits = 0;
    
    // Find ID column using information_schema
    $idCol = null;
    $idQuery = "SELECT COLUMN_NAME FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = '" . $mysqli->real_escape_string($dbName) . "' AND TABLE_NAME = '" . $mysqli->real_escape_string($table) . "' 
                AND (COLUMN_NAME = 'id' OR COLUMN_NAME LIKE '%_id') 
                ORDER BY (COLUMN_NAME = 'id') DESC LIMIT 1";
    $idRes = $mysqli->query($idQuery);
    if ($idRes && $idRes->num_rows > 0) {
        $idRow = $idRes->fetch_assoc();
        $idCol = $idRow['COLUMN_NAME'];
    }

    foreach ($columns as $column) {
        $selectId = $idCol ? "`$idCol`" : "'N/A'";
        $searchQuery = "SELECT $selectId as id_val, `$column` as found_val FROM `$table` WHERE `$column` IN ($inClause)";
        $searchRes = $mysqli->query($searchQuery);
        
        if ($searchRes && $searchRes->num_rows > 0) {
            while ($hit = $searchRes->fetch_assoc()) {
                echo "$table|$column|" . $hit['id_val'] . "|" . $hit['found_val'] . "\n";
                $tableHits++;
                $totalHits++;
            }
        }
    }
    
    if ($tableHits > 0) {
        echo "--- Table $table: $tableHits hits ---\n";
    }
}

if ($totalHits === 0) {
    echo "No matches found.\n";
}

$mysqli->close();
?>
