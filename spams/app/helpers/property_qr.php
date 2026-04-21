<?php

function property_qr_ensure_schema(?mysqli $db): void
{
    static $done = false;
    if ($done || !$db || !function_exists('schema_has_column')) {
        return;
    }

    if (!schema_has_column($db, 'distribution_item_details', 'qr_tag_code')) {
        $db->query("ALTER TABLE distribution_item_details ADD COLUMN qr_tag_code VARCHAR(80) NULL AFTER serial_no");
    }

    if (!schema_has_column($db, 'legacy_assets', 'qr_tag_code')) {
        $db->query("ALTER TABLE legacy_assets ADD COLUMN qr_tag_code VARCHAR(80) NULL AFTER serial_no");
    }

    $done = true;
}

function property_qr_generate_tag_code(string $sourceType, int $assetId): string
{
    $prefix = $sourceType === 'legacy' ? 'L' : 'S';
    return 'UASPMS-' . $prefix . '-' . str_pad((string) max(1, $assetId), 10, '0', STR_PAD_LEFT);
}

function property_qr_resolve_tag_code(?mysqli $db, string $sourceType, int $assetId, string $existingCode = ''): string
{
    $existingCode = trim($existingCode);
    if ($assetId <= 0) {
        return $existingCode;
    }

    if ($existingCode !== '') {
        return $existingCode;
    }

    $tagCode = property_qr_generate_tag_code($sourceType, $assetId);
    if (!$db) {
        return $tagCode;
    }

    property_qr_ensure_schema($db);
    $table = $sourceType === 'legacy' ? 'legacy_assets' : 'distribution_item_details';
    $stmt = $db->prepare("UPDATE {$table} SET qr_tag_code = ? WHERE id = ? AND (qr_tag_code IS NULL OR qr_tag_code = '')");
    if ($stmt) {
        $stmt->bind_param('si', $tagCode, $assetId);
        $stmt->execute();
        $stmt->close();
    }

    return $tagCode;
}

function property_qr_build_payload(string $tagCode, string $propertyNumber = '', string $serialNumber = ''): string
{
    $parts = [];
    $tagCode = trim($tagCode);
    $propertyNumber = trim($propertyNumber);
    $serialNumber = trim($serialNumber);

    if ($tagCode !== '') {
        $parts[] = 'TAG=' . $tagCode;
    }
    if ($propertyNumber !== '') {
        $parts[] = 'PN=' . $propertyNumber;
    }
    if ($serialNumber !== '') {
        $parts[] = 'SN=' . $serialNumber;
    }

    return implode('|', $parts);
}

function property_qr_parse_payload(string $raw): array
{
    $raw = trim($raw);
    $payload = [
        'raw' => $raw,
        'tag_code' => '',
        'property_number' => '',
        'serial_number' => '',
    ];

    if ($raw === '') {
        return $payload;
    }

    if (filter_var($raw, FILTER_VALIDATE_URL)) {
        $query = parse_url($raw, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $queryParams);
            if (!empty($queryParams['ref'])) {
                return property_qr_parse_payload((string) $queryParams['ref']);
            }
        }
    }

    $parts = preg_split('/\|+/', $raw) ?: [];
    foreach ($parts as $part) {
        $pieces = explode('=', $part, 2);
        if (count($pieces) !== 2) {
            continue;
        }

        $key = strtoupper(trim($pieces[0]));
        $value = trim($pieces[1]);
        if ($value === '') {
            continue;
        }

        if ($key === 'TAG') {
            $payload['tag_code'] = $value;
        } elseif ($key === 'PN') {
            $payload['property_number'] = $value;
        } elseif ($key === 'SN') {
            $payload['serial_number'] = $value;
        }
    }

    return $payload;
}

function property_qr_find_asset_by_tag_code(mysqli $db, string $tagCode): ?array
{
    property_qr_ensure_schema($db);
    $tagCode = trim($tagCode);
    if ($tagCode === '') {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT 'system' AS source_type, id
         FROM distribution_item_details
         WHERE qr_tag_code = ?
         UNION ALL
         SELECT 'legacy' AS source_type, id
         FROM legacy_assets
         WHERE qr_tag_code = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ss', $tagCode, $tagCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'source_type' => (string) ($row['source_type'] ?? ''),
        'id' => (int) ($row['id'] ?? 0),
    ];
}
