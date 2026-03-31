<?php
require_once __DIR__ . '/../spams/app/config/init.php';

function meta_out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function meta_norm(string $value): string
{
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function meta_contains(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($haystack, $needle)) {
            return true;
        }
    }
    return false;
}

function infer_classification_metadata(string $classificationName, string $classificationGroup, ?int $accountCodeId): array
{
    $name = meta_norm($classificationName);
    $group = trim($classificationGroup);

    if (meta_contains($name, ['printer', 'photocopier', 'photocop', 'copier', 'copy_printer', 'copyprinter', 'plotter'])) {
        return ['family' => 'Printing Equipment', 'useful_life_years' => $group === 'semi_expendable' ? 3 : 5];
    }
    if (meta_contains($name, ['aircon', 'air_conditioner', 'airconditioner', 'ceiling_fan', 'industrial_ceiling_fan', 'fan_coil', 'cooling'])) {
        return ['family' => 'Climate Control Equipment', 'useful_life_years' => $group === 'semi_expendable' ? 3 : 10];
    }
    if (meta_contains($name, ['chair', 'table', 'desk', 'cabinet', 'shelf', 'bookshelf', 'sofa', 'furniture', 'fixture'])) {
        return ['family' => $group === 'semi_expendable' ? 'Semi-Expendable Office Equipment' : 'Office Furniture', 'useful_life_years' => $group === 'semi_expendable' ? 3 : 10];
    }
    if (meta_contains($name, ['software'])) {
        return ['family' => 'Software and Licenses', 'useful_life_years' => 10];
    }
    if (meta_contains($name, ['medical', 'autoclave', 'microscope', 'bp_monitor', 'patient', 'hospital'])) {
        return ['family' => 'Medical Equipment', 'useful_life_years' => $group === 'semi_expendable' ? 3 : 10];
    }
    if (meta_contains($name, ['laboratory', 'analyzer', 'incubator', 'spectro', 'ph_meter', 'texture', 'fume', 'chemistry', 'forensic', 'scientific', 'balance', 'viscometer'])) {
        return ['family' => $group === 'semi_expendable' ? 'Semi-Expendable Technical and Scientific Equipment' : 'Laboratory Equipment', 'useful_life_years' => $group === 'semi_expendable' ? 3 : 10];
    }
    if (meta_contains($name, ['telephone', 'phone', 'speaker', 'mixer', 'ippabx', 'radio'])) {
        return ['family' => $group === 'semi_expendable' ? 'Semi-Expendable Communication Equipment' : 'Communications Equipment', 'useful_life_years' => $group === 'semi_expendable' ? 3 : 10];
    }
    if (meta_contains($name, ['desktop', 'laptop', 'notebook', 'server', 'switch', 'router', 'nas', 'ups', 'digital_board', 'led_tv', 'smart_tv', 'tv', 'television', 'camera', 'cctv', 'scanner', 'kvm', 'ict', 'computer'])) {
        if (meta_contains($name, ['software'])) {
            return ['family' => 'Software and Licenses', 'useful_life_years' => 10];
        }
        return ['family' => $group === 'semi_expendable' ? 'Semi-Expendable ICT Equipment' : 'ICT Equipment', 'useful_life_years' => $group === 'semi_expendable' ? 3 : 5];
    }
    $accountBased = match ($accountCodeId) {
        20 => ['family' => 'Semi-Expendable Office Equipment', 'useful_life_years' => 3],
        21 => ['family' => 'Semi-Expendable ICT Equipment', 'useful_life_years' => 3],
        25 => ['family' => 'Machinery', 'useful_life_years' => 10],
        26 => ['family' => 'Office Equipment', 'useful_life_years' => 5],
        27 => ['family' => 'ICT Equipment', 'useful_life_years' => 5],
        28 => ['family' => 'Communications Equipment', 'useful_life_years' => 10],
        30 => ['family' => 'Other Machinery and Equipment', 'useful_life_years' => 10],
        31 => ['family' => 'Office Furniture', 'useful_life_years' => 10],
        34 => ['family' => 'Land', 'useful_life_years' => null],
        47 => ['family' => 'Buildings', 'useful_life_years' => 30],
        48 => ['family' => 'School Buildings', 'useful_life_years' => 30],
        53 => ['family' => 'Other Structures', 'useful_life_years' => 20],
        60 => ['family' => 'Medical Equipment', 'useful_life_years' => 10],
        61 => ['family' => 'Sports Equipment', 'useful_life_years' => 5],
        62 => ['family' => 'Laboratory Equipment', 'useful_life_years' => 10],
        63 => ['family' => 'Motor Vehicles', 'useful_life_years' => 7],
        72 => ['family' => 'Software and Licenses', 'useful_life_years' => 10],
        80 => ['family' => 'Food Supplies', 'useful_life_years' => null],
        default => null,
    };
    if (is_array($accountBased)) {
        return $accountBased;
    }

    if (meta_contains($name, ['building', 'hall', 'center', 'clinic', 'hostel', 'gym', 'library'])) {
        return ['family' => 'Buildings', 'useful_life_years' => 30];
    }
    if (meta_contains($name, ['walkway', 'canopy', 'stage', 'drilling', 'guard_house', 'motor_pool', 'park', 'pathwalk', 'pump_room', 'bleachers', 'structure'])) {
        return ['family' => 'Other Structures', 'useful_life_years' => 20];
    }
    if (meta_contains($name, ['vehicle', 'truck'])) {
        return ['family' => 'Motor Vehicles', 'useful_life_years' => 7];
    }

    return match ($group) {
            'semi_expendable' => ['family' => 'Semi-Expendable Items', 'useful_life_years' => 3],
            'supply' => ['family' => 'Supplies', 'useful_life_years' => null],
            default => ['family' => 'Equipment', 'useful_life_years' => 5],
        };
}

$db = db();
if (!$db) {
    fwrite(STDERR, "Unable to connect to database." . PHP_EOL);
    exit(1);
}

$sql = "
    SELECT id, classification_name, classification_group, classification_family, useful_life_years, account_code_id
    FROM classifications
    ORDER BY id ASC
";
$res = $db->query($sql);
if (!$res) {
    fwrite(STDERR, "Unable to load classifications for metadata backfill." . PHP_EOL);
    exit(1);
}

$update = $db->prepare("UPDATE classifications SET classification_family = ?, useful_life_years = NULLIF(?, 0) WHERE id = ?");
if (!$update) {
    fwrite(STDERR, "Unable to prepare metadata backfill update." . PHP_EOL);
    exit(1);
}

$updated = 0;
$db->begin_transaction();

try {
    while ($row = $res->fetch_assoc()) {
        $meta = infer_classification_metadata(
            (string) ($row['classification_name'] ?? ''),
            (string) ($row['classification_group'] ?? 'asset'),
            isset($row['account_code_id']) ? (int) $row['account_code_id'] : null
        );
        $family = (string) $meta['family'];
        $usefulLife = (int) ($meta['useful_life_years'] ?? 0);
        $recordId = (int) $row['id'];
        $update->bind_param('sii', $family, $usefulLife, $recordId);
        if (!$update->execute()) {
            throw new RuntimeException('Failed updating classification ID ' . $recordId . ': ' . $update->error);
        }
        $updated++;
    }

    $db->commit();
    meta_out('Updated: ' . $updated);
} catch (Throwable $e) {
    $db->rollback();
    $update->close();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$update->close();
exit(0);
