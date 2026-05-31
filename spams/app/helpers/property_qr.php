<?php

function property_qr_normalize_scan_text(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

function property_qr_extract_labeled_value(string $raw, array $labels): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    foreach ($labels as $label) {
        $pattern = '/(?:^|[\|\n\r;,])\s*' . preg_quote($label, '/') . '\s*[:=#-]?\s*([A-Z0-9\/._-]+(?:\s+[A-Z0-9\/._-]+)*)/iu';
        if (preg_match($pattern, $raw, $matches)) {
            return trim((string) ($matches[1] ?? ''));
        }
    }

    return '';
}

function property_qr_looks_like_property_number(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    // Accept legacy trailing parentheses like "(...)", e.g. 2018-01-05.020-001(2)
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}\.\d{3}-\d+(?:\(\d+\))?$/', $value);
}

function property_qr_looks_like_serial_number(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    return (bool) preg_match('/^[A-Z0-9][A-Z0-9\/._-]{5,}$/i', $value);
}

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

function property_qr_build_payload(
    string $tagCode,
    string $propertyNumber = '',
    string $serialNumber = '',
    string $dateAcquired = '',
    string $itemName = '',
    string $officeName = '',
    string $employeeName = ''
): string
{
    $parts = [];
    $tagCode = trim($tagCode);
    $propertyNumber = trim($propertyNumber);
    $serialNumber = trim($serialNumber);
    $dateAcquired = trim($dateAcquired);
    $itemName = trim(preg_replace('/\s+/', ' ', $itemName) ?? '');
    $officeName = trim(preg_replace('/\s+/', ' ', $officeName) ?? '');
    $employeeName = trim(preg_replace('/\s+/', ' ', $employeeName) ?? '');

    if ($tagCode !== '') {
        $parts[] = 'TAG=' . $tagCode;
    }
    if ($propertyNumber !== '') {
        $parts[] = 'PN=' . $propertyNumber;
    }
    if ($serialNumber !== '') {
        $parts[] = 'SN=' . $serialNumber;
    }
    if ($dateAcquired !== '') {
        $parts[] = 'DA=' . $dateAcquired;
    }
    if ($itemName !== '') {
        $parts[] = 'IT=' . $itemName;
    }
    if ($officeName !== '') {
        $parts[] = 'OF=' . $officeName;
    }
    if ($employeeName !== '') {
        $parts[] = 'EM=' . $employeeName;
    }

    return implode('|', $parts);
}

function property_qr_parse_payload(string $raw): array
{
    $raw = property_qr_normalize_scan_text($raw);
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

    if ($payload['property_number'] === '') {
        $payload['property_number'] = property_qr_extract_labeled_value($raw, [
            'PN',
            'PROP NO',
            'PROPERTY NO',
            'PROPERTY NUMBER',
            'OLD PROPERTY NO',
            'OLD PROPERTY NUMBER',
            'PROPERTY #',
        ]);
    }

    if ($payload['serial_number'] === '') {
        $payload['serial_number'] = property_qr_extract_labeled_value($raw, [
            'SN',
            'SERIAL',
            'SERIAL NO',
            'SERIAL NUMBER',
            'SER NO',
        ]);
    }

    if ($payload['property_number'] === '' && $payload['serial_number'] === '' && strpos($raw, '|') !== false) {
        // Use a non-collapsing split so empty segments are preserved (legacy QR uses empty placeholders).
        $parts = array_map('property_qr_normalize_scan_text', preg_split('/\|/', $raw) ?: []);
        if (count($parts) >= 1) {
            $first = (string) ($parts[0] ?? '');

            // Prefer the legacy positional serial (index 3) when present and looks like a serial.
            $candidatePart3 = (string) ($parts[3] ?? '');

            // Find the first non-empty part after the first as a fallback.
            $firstNonEmptyAfterFirst = '';
            for ($i = 1; $i < count($parts); $i++) {
                if ($parts[$i] !== '') {
                    $firstNonEmptyAfterFirst = $parts[$i];
                    break;
                }
            }

            if ($first !== '' && property_qr_looks_like_property_number($first)) {
                $payload['property_number'] = $first;
            }

            if ($candidatePart3 !== '' && property_qr_looks_like_serial_number($candidatePart3)) {
                $payload['serial_number'] = $candidatePart3;
            } elseif ($firstNonEmptyAfterFirst !== '' && property_qr_looks_like_serial_number($firstNonEmptyAfterFirst)) {
                $payload['serial_number'] = $firstNonEmptyAfterFirst;
            }

            // As a last resort, if we still have no serial but have a non-empty second part, assign it.
            if ($payload['serial_number'] === '' && ($parts[1] ?? '') !== '') {
                $maybe = (string) ($parts[1] ?? '');
                if ($maybe !== '') {
                    $payload['serial_number'] = $maybe;
                }
            }
        }
    }

    // Normalize property number: strip simple trailing parentheses like (2)
    if ($payload['property_number'] !== '') {
        $payload['property_number'] = preg_replace('/\(\d+\)$/', '', $payload['property_number']);
        $payload['property_number'] = trim($payload['property_number']);
    }

    if ($payload['property_number'] === '' && strpos($raw, '|') !== false) {
        $parts = array_map('property_qr_normalize_scan_text', preg_split('/\|/', $raw) ?: []);
        $legacyProperty = (string) ($parts[0] ?? '');
        $legacySerial = (string) ($parts[3] ?? '');

        if (property_qr_looks_like_property_number($legacyProperty)) {
            $payload['property_number'] = $legacyProperty;
        }

        if ($payload['serial_number'] === '' && property_qr_looks_like_serial_number($legacySerial)) {
            $payload['serial_number'] = $legacySerial;
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
