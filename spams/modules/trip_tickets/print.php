<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Transport Officer');

$tripDb = trip_db();
$ticketId = (int) ($_GET['id'] ?? 0);
$ticket = null;
$passengers = [];

if ($tripDb && $ticketId > 0) {
    $stmt = $tripDb->prepare("SELECT * FROM trip_tickets WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if ($ticket) {
        $passengerStmt = $tripDb->prepare("SELECT passenger_name FROM trip_ticket_passengers WHERE trip_ticket_id = ? ORDER BY sort_order ASC, id ASC");
        if ($passengerStmt) {
            $passengerStmt->bind_param('i', $ticketId);
            $passengerStmt->execute();
            $passengers = $passengerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $passengerStmt->close();
        }
    }
}

if (!$ticket) {
    http_response_code(404);
    echo 'Trip ticket not found.';
    exit;
}

$passengerNames = array_map(static fn($row) => trim((string) ($row['passenger_name'] ?? '')), $passengers);
$passengerNames = array_values(array_filter($passengerNames, static fn($name) => $name !== ''));
$purpose = trim((string) ($ticket['purpose'] ?? ''));
$remarks = trim((string) ($ticket['remarks'] ?? ''));
$officeName = trim((string) ($ticket['office_name'] ?? ''));
$rcCode = trim((string) ($ticket['responsibility_code'] ?? ''));
$issuedByName = trim((string) ($ticket['issued_by_name'] ?? ''));
$issuedByTitle = trim((string) ($ticket['issued_by_title'] ?? ''));
$approvedByName = trim((string) ($ticket['approved_by_name'] ?? ''));
$approvedByTitle = trim((string) ($ticket['approved_by_title'] ?? ''));
$requestedByName = trim((string) ($ticket['requested_by_name'] ?? ''));
$requestedByTitle = trim((string) ($ticket['requested_by_title'] ?? ''));
$receivedByName = trim((string) ($ticket['received_by_name'] ?? ''));
$receivedByTitle = trim((string) ($ticket['received_by_title'] ?? ''));
$hasFuelRis = ((float) ($ticket['liters_requested'] ?? 0) > 0) && trim((string) ($ticket['ris_no'] ?? '')) !== '';

function render_ris_copy(array $ticket, string $officeName, string $rcCode, string $purpose, string $approvedByName, string $approvedByTitle, string $issuedByName, string $issuedByTitle, string $requestedByName, string $requestedByTitle, string $receivedByName, string $receivedByTitle): void
{
?>
    <div class="ris-copy">
        <div class="ris-title">REQUISITION AND ISSUE SLIP</div>
        <table class="ris-meta">
            <tr>
                <td><strong>Entity Name:</strong> University of Antique (Main Campus)</td>
                <td><strong>Fund Cluster:</strong></td>
            </tr>
            <tr>
                <td><strong>Division:</strong> <?php echo h($officeName); ?></td>
                <td><strong>Responsibility Center Code:</strong> <?php echo h($rcCode); ?></td>
            </tr>
            <tr>
                <td><strong>Office:</strong> <?php echo h($officeName); ?></td>
                <td><strong>RIS No.:</strong> <?php echo h((string) $ticket['ris_no']); ?></td>
            </tr>
        </table>

        <table class="ris-grid">
            <thead>
                <tr>
                    <th rowspan="2" style="width:10%;">Stock No.</th>
                    <th rowspan="2" style="width:8%;">Unit</th>
                    <th rowspan="2" style="width:40%;">Description</th>
                    <th colspan="2" style="width:14%;">Stocks Available?</th>
                    <th colspan="2" style="width:28%;">Issue</th>
                </tr>
                <tr>
                    <th style="width:7%;">Yes</th>
                    <th style="width:7%;">No</th>
                    <th style="width:10%;">Quantity</th>
                    <th style="width:18%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td></td>
                    <td>ltr/s</td>
                    <td><?php echo h((string) $ticket['fuel_type']); ?></td>
                    <td class="center-cell">/</td>
                    <td></td>
                    <td class="center-cell"><?php echo number_format((float) $ticket['liters_requested'], 2); ?></td>
                    <td></td>
                </tr>
                <?php for ($i = 0; $i < 7; $i++): ?>
                    <tr>
                        <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td>
                    </tr>
                <?php endfor; ?>
                <tr>
                    <td colspan="7" class="purpose-cell"><strong>Purpose:</strong> <?php echo h($purpose); ?></td>
                </tr>
            </tbody>
        </table>

        <table class="ris-signatures">
            <tr>
                <th>Requested by:</th>
                <th>Approved by:</th>
                <th>Issued by:</th>
                <th>Received by:</th>
            </tr>
            <tr>
                <td class="signature-block">
                    <div class="signature-space"></div>
                    <div class="signature-name"><?php echo h($requestedByName); ?></div>
                    <div class="signature-role"><?php echo h($requestedByTitle); ?></div>
                    <div class="signature-date"><?php echo h(format_date((string) $ticket['departure_date'], 'n/j/Y')); ?></div>
                </td>
                <td class="signature-block">
                    <div class="signature-space"></div>
                    <div class="signature-name"><?php echo h($approvedByName); ?></div>
                    <div class="signature-role"><?php echo h($approvedByTitle); ?></div>
                    <div class="signature-date"><?php echo h(format_date((string) $ticket['departure_date'], 'n/j/Y')); ?></div>
                </td>
                <td class="signature-block">
                    <div class="signature-space"></div>
                    <div class="signature-name"><?php echo h($issuedByName); ?></div>
                    <div class="signature-role"><?php echo h($issuedByTitle); ?></div>
                    <div class="signature-date"><?php echo h(format_date((string) $ticket['departure_date'], 'n/j/Y')); ?></div>
                </td>
                <td class="signature-block">
                    <div class="signature-space"></div>
                    <div class="signature-name"><?php echo h($receivedByName); ?></div>
                    <div class="signature-role"><?php echo h($receivedByTitle); ?></div>
                    <div class="signature-date"><?php echo h(format_date((string) $ticket['departure_date'], 'n/j/Y')); ?></div>
                </td>
            </tr>
        </table>
    </div>
<?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo h($ticket['trip_ticket_no']); ?> | Trip Ticket</title>
    <style>
        @page { size: 8.5in 13in; margin: 10mm 8mm; }
        body { font-family: "Times New Roman", serif; margin: 0; color: #000; font-size: 13px; }
        .sheet { page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }
        .trip-sheet { padding: 2mm 2mm 0; }
        .center { text-align: center; }
        .trip-header { line-height: 1.25; font-size: 14px; }
        .trip-title { margin: 8px 0 6px; font-size: 18px; font-weight: bold; text-decoration: underline; }
        .trip-no-row { width: 100%; border-collapse: collapse; margin: 10px 0 8px; }
        .trip-no-row td { padding: 0 4px 4px; vertical-align: bottom; }
        .line-cell { border-bottom: 1px solid #000; height: 22px; }
        .trip-body { width: 100%; border-collapse: collapse; }
        .trip-body td { padding: 2px 0; vertical-align: top; }
        .trip-body .num { width: 28px; }
        .trip-body .label { width: 310px; }
        .trip-body .fill { border-bottom: 1px solid #000; padding: 0 6px 3px; }
        .trip-section-label { margin-top: 10px; font-weight: bold; }
        .driver-log { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .driver-log td { padding: 3px 4px; vertical-align: top; }
        .driver-log .entry-line { border-bottom: 1px solid #000; min-height: 18px; }
        .driver-log .unit-col { width: 56px; }
        .remarks-box { min-height: 44px; border-bottom: 1px solid #000; }
        .driver-cert { margin-top: 14px; text-align: center; }
        .signature-area { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .signature-area td { vertical-align: top; }
        .signature-line { border-bottom: 1px solid #000; height: 28px; }
        .passenger-grid { width: 100%; border-collapse: collapse; margin-top: 14px; }
        .passenger-grid td { width: 50%; border-bottom: 1px solid #000; padding: 5px 6px 3px; }
        .footer-row { display: flex; justify-content: space-between; margin-top: 8px; font-size: 11px; }

        .ris-sheet { padding: 2mm 2mm 0; }
        .ris-copy { margin-bottom: 8mm; }
        .ris-title { text-align: center; font-weight: bold; font-size: 18px; margin-bottom: 2px; }
        .ris-meta, .ris-grid, .ris-signatures { width: 100%; border-collapse: collapse; }
        .ris-meta td { border: 1px solid #000; padding: 3px 6px; }
        .ris-grid th, .ris-grid td, .ris-signatures th, .ris-signatures td { border: 1px solid #000; padding: 4px 5px; vertical-align: top; }
        .ris-grid th, .ris-signatures th { text-align: center; font-weight: bold; }
        .center-cell { text-align: center; }
        .purpose-cell { min-height: 40px; }
        .signature-block { height: 92px; }
        .signature-space { height: 24px; }
        .signature-name { font-weight: bold; text-align: center; text-transform: uppercase; }
        .signature-role, .signature-date { text-align: center; font-size: 11px; }
    
            <?php echo print_page_number_css(); ?></style>
</head>
<body onload="window.print()">
    <div class="sheet trip-sheet">
        <div class="center trip-header">
            <div>Republic of the Philippines</div>
            <div><strong>UNIVERSITY OF ANTIQUE</strong></div>
            <div>Sibalom, Antique</div>
        </div>

        <table class="trip-no-row">
            <tr>
                <td style="width: 36px;">No.:</td>
                <td class="line-cell" style="width: 38%;"><?php echo h((string) $ticket['trip_ticket_no']); ?></td>
                <td style="width: 42px; text-align: right;">Date:</td>
                <td class="line-cell"><?php echo h(format_date((string) $ticket['departure_date'], 'F d, Y')); ?></td>
            </tr>
            <tr>
                <td colspan="2"></td>
                <td style="text-align: right;">Return:</td>
                <td class="line-cell"><?php echo !empty($ticket['return_date']) ? h(format_date((string) $ticket['return_date'], 'F d, Y')) : ''; ?></td>
            </tr>
        </table>

        <div class="center trip-title">DRIVER’S TRIP TICKET</div>
        <div>To be filled by the Administrative Official authorizing the travel:</div>

        <table class="trip-body">
            <tr>
                <td class="num">1.</td>
                <td class="label">Name of driver of the vehicle:</td>
                <td class="fill"><?php echo h((string) $ticket['driver_name']); ?></td>
            </tr>
            <tr>
                <td class="num">2.</td>
                <td class="label">Government car to be used, Plate No.:</td>
                <td class="fill"><?php echo h((string) $ticket['vehicle_plate_no']); ?></td>
            </tr>
            <tr>
                <td class="num">3.</td>
                <td class="label">Name of authorized passenger(s):</td>
                <td class="fill"><?php echo h(implode(', ', $passengerNames)); ?></td>
            </tr>
            <tr>
                <td class="num">4.</td>
                <td class="label">Place or places to be visited/inspected:</td>
                <td class="fill"><?php echo h((string) $ticket['destination']); ?></td>
            </tr>
            <tr>
                <td class="num">5.</td>
                <td class="label">Purpose:</td>
                <td class="fill"><?php echo h($purpose); ?></td>
            </tr>
        </table>

        <table class="signature-area">
            <tr>
                <td style="width: 62%;"></td>
                <td class="center">
                    <div class="signature-line"></div>
                    <div><?php echo h($approvedByName); ?></div>
                    <div><?php echo h($approvedByTitle); ?></div>
                </td>
            </tr>
        </table>

        <div class="trip-section-label">To be filled by the driver:</div>
        <table class="driver-log">
            <tr>
                <td style="width: 28px;">1.</td>
                <td>Time of departure from office/ garage</td>
                <td class="entry-line" style="width: 150px;"><?php echo h(date('g:i', strtotime((string) $ticket['departure_time']))); ?></td>
                <td class="unit-col">AM/PM</td>
            </tr>
            <tr><td>2.</td><td>Time of arrival (per no. 5 above)</td><td class="entry-line"></td><td>AM/PM</td></tr>
            <tr><td>3.</td><td>Time of departure from (per no. 4)</td><td class="entry-line"></td><td>AM/PM</td></tr>
            <tr><td>4.</td><td>Time of arrival back to office/ garage</td><td class="entry-line"></td><td>AM/PM</td></tr>
            <tr><td>5.</td><td>Approximate distance traveled (to/ fr)</td><td class="entry-line"></td><td>/kms</td></tr>
            <tr>
                <td>6.</td>
                <td>Gasoline/ Diesel issued, purchase and consumed:</td>
                <td></td>
                <td></td>
            </tr>
            <tr><td></td><td>a. Balance in tank before the trip</td><td class="entry-line"></td><td>liters</td></tr>
            <tr><td></td><td>b. Issued by office from stock</td><td class="entry-line"><?php echo number_format((float) $ticket['liters_requested'], 2); ?></td><td>liters</td></tr>
            <tr><td></td><td>c. Add purchased during the trip</td><td class="entry-line"></td><td>liters</td></tr>
            <tr><td></td><td>d. Deduct: Used during the trip (to/ fr)</td><td class="entry-line"></td><td>liters</td></tr>
            <tr><td></td><td>e. Balance in tank after trip</td><td class="entry-line"></td><td>liters</td></tr>
            <tr><td></td><td><strong>TOTAL</strong></td><td class="entry-line"></td><td>liters</td></tr>
            <tr><td>7.</td><td>Gear Oil issued</td><td class="entry-line"></td><td>liters</td></tr>
            <tr><td>8.</td><td>Grease issued</td><td class="entry-line"></td><td>liters</td></tr>
            <tr><td>9.</td><td>Lubrication Oil issued</td><td class="entry-line"></td><td>liters</td></tr>
            <tr><td>10.</td><td>Speedometer reading if any</td><td></td><td></td></tr>
            <tr><td></td><td>a. At the beginning of the trip</td><td class="entry-line"></td><td>/kms</td></tr>
            <tr><td></td><td>b. At the end of the trip</td><td class="entry-line"></td><td>/kms</td></tr>
            <tr>
                <td colspan="4">
                    <div><strong>Remarks</strong></div>
                    <div class="remarks-box"><?php echo nl2br(h($remarks)); ?></div>
                </td>
            </tr>
        </table>

        <div class="driver-cert">I hereby certify that I used this car on official business as stated.</div>
        <table class="signature-area">
            <tr>
                <td style="width: 52%;"></td>
                <td class="center">
                    <div class="signature-line"></div>
                    <div><?php echo h((string) $ticket['driver_name']); ?></div>
                    <div>Driver</div>
                </td>
            </tr>
        </table>

        <table class="passenger-grid">
            <?php
            $gridPassengers = $passengerNames;
            while (count($gridPassengers) < 4) {
                $gridPassengers[] = '';
            }
            for ($i = 0; $i < count($gridPassengers); $i += 2):
            ?>
                <tr>
                    <td><?php echo h($gridPassengers[$i] ?? ''); ?></td>
                    <td><?php echo h($gridPassengers[$i + 1] ?? ''); ?></td>
                </tr>
            <?php endfor; ?>
        </table>

        <div class="footer-row">
            <span>SO-FM-009</span>
            <span>Rev. 01/02-04-20</span>
        </div>
    </div>

    <?php if ($hasFuelRis): ?>
        <div class="sheet ris-sheet">
            <?php render_ris_copy($ticket, $officeName, $rcCode, $purpose, $approvedByName, $approvedByTitle, $issuedByName, $issuedByTitle, $requestedByName, $requestedByTitle, $receivedByName, $receivedByTitle); ?>
            <?php render_ris_copy($ticket, $officeName, $rcCode, $purpose, $approvedByName, $approvedByTitle, $issuedByName, $issuedByTitle, $requestedByName, $requestedByTitle, $receivedByName, $receivedByTitle); ?>
        </div>
    <?php endif; ?>

<?php render_print_page_number(); ?></body>
</html>
