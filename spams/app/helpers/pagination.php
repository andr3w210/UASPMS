<?php

function paginate(mysqli $db, string $countSql, string $dataSql, array $params, string $types, int $page, int $perPage = 20): array
{
    $page = max(1, $page);

    $bindToStmt = function (mysqli_stmt $stmt, string $types, array $values): void {
        if ($types === '') {
            return;
        }

        $bindValues = $values;
        $refs = [];
        foreach ($bindValues as $key => $val) {
            $refs[$key] = &$bindValues[$key];
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    };

    $total = 0;
    $countStmt = $db->prepare($countSql);
    if ($countStmt) {
        if ($types !== '') {
            $bindToStmt($countStmt, $types, $params);
        }
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $countRow = $countResult ? $countResult->fetch_assoc() : null;
        $countStmt->close();

        if ($countRow && isset($countRow['total'])) {
            $total = (int) $countRow['total'];
        } elseif ($countRow && isset($countRow['COUNT(*)'])) {
            $total = (int) $countRow['COUNT(*)'];
        }
    }

    $total_pages = $total > 0 ? (int) ceil($total / $perPage) : 0;
    if ($total_pages > 0) {
        $page = min($page, $total_pages);
    }

    $offset = ($page - 1) * $perPage;

    $data = [];
    $finalSql = $dataSql . " LIMIT ? OFFSET ?";
    $dataStmt = $db->prepare($finalSql);
    if ($dataStmt) {
        $finalTypes = $types . 'ii';
        $finalParams = $params;
        $finalParams[] = $perPage;
        $finalParams[] = $offset;

        if ($finalTypes !== '') {
            $bindToStmt($dataStmt, $finalTypes, $finalParams);
        }

        $dataStmt->execute();
        $result = $dataStmt->get_result();
        if ($result) {
            $data = $result->fetch_all(MYSQLI_ASSOC);
        }
        $dataStmt->close();
    }

    return [
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $total_pages,
    ];
}
