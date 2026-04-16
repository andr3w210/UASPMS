<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Transport Officer');

$tripDb = trip_db();
$page_title = 'Trip Schedule Calendar';
$errors = [];
$tickets = [];

$monthParam = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}

[$selectedYear, $selectedMonth] = array_map('intval', explode('-', $monthParam));
$calendarStart = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $selectedYear, $selectedMonth));
if (!$calendarStart) {
    $calendarStart = new DateTimeImmutable(date('Y-m-01'));
}

$queryStart = $calendarStart->format('Y-m-01');
$queryEnd = $calendarStart->modify('last day of this month')->format('Y-m-d');
$today = date('Y-m-d');
$prevMonth = $calendarStart->modify('-1 month')->format('Y-m');
$nextMonth = $calendarStart->modify('+1 month')->format('Y-m');
$monthLabel = $calendarStart->format('F Y');

if (!$tripDb) {
    $errors[] = 'Unable to connect to the trip ticket database. Import `database/081_trip_ticket_module.sql` first.';
} else {
    $stmt = $tripDb->prepare("
        SELECT
            id,
            trip_ticket_no,
            ris_no,
            departure_date,
            return_date,
            departure_time,
            vehicle_plate_no,
            vehicle_name,
            driver_name,
            destination,
            liters_requested
        FROM trip_tickets
        WHERE departure_date <= ? AND (return_date IS NULL OR return_date = '' OR return_date >= ?)
        ORDER BY departure_date ASC, departure_time ASC, id ASC
    ");

    if ($stmt) {
        $stmt->bind_param('ss', $queryEnd, $queryStart);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $tickets = $result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    } else {
        $errors[] = 'Unable to load trip schedules.';
    }
}

$ticketsByDate = [];
foreach ($tickets as $ticket) {
    $spanStart = new DateTimeImmutable((string) $ticket['departure_date']);
    $spanEnd = !empty($ticket['return_date']) ? new DateTimeImmutable((string) $ticket['return_date']) : $spanStart;
    if ($spanEnd < $spanStart) {
        $spanEnd = $spanStart;
    }

    for ($cursor = $spanStart; $cursor <= $spanEnd; $cursor = $cursor->modify('+1 day')) {
        $dateKey = $cursor->format('Y-m-d');
        if ($dateKey < $queryStart || $dateKey > $queryEnd) {
            continue;
        }

        $ticketForDay = $ticket;
        $ticketForDay['is_span_start'] = $dateKey === $ticket['departure_date'];
        $ticketForDay['is_span_end'] = $dateKey === ($ticket['return_date'] ?: $ticket['departure_date']);
        $ticketForDay['span_label'] = !empty($ticket['return_date']) && $ticket['return_date'] !== $ticket['departure_date']
            ? ($ticketForDay['is_span_start'] ? 'Departure' : ($ticketForDay['is_span_end'] ? 'Return' : 'On trip'))
            : 'Trip day';

        $ticketsByDate[$dateKey][] = $ticketForDay;
    }
}

$firstGridDate = $calendarStart->modify('-' . ((int) $calendarStart->format('w')) . ' days');
$lastGridDate = $calendarStart->modify('last day of this month');
$daysToSaturday = 6 - (int) $lastGridDate->format('w');
$lastGridDate = $lastGridDate->modify('+' . $daysToSaturday . ' days');
$calendarDays = [];

for ($cursor = $firstGridDate; $cursor <= $lastGridDate; $cursor = $cursor->modify('+1 day')) {
    $dateKey = $cursor->format('Y-m-d');
    $calendarDays[] = [
        'date' => $dateKey,
        'day' => (int) $cursor->format('j'),
        'is_current_month' => $cursor->format('Y-m') === $calendarStart->format('Y-m'),
        'is_today' => $dateKey === $today,
        'tickets' => $ticketsByDate[$dateKey] ?? [],
    ];
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">Trip Schedule Calendar</h4>
            <div class="text-muted">Monthly view of saved trip ticket schedules.</div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo base_url('modules/trip_tickets/index.php'); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-list-ul me-1"></i> Trip Ticket List
            </a>
            <a href="<?php echo base_url('modules/trip_tickets/create.php'); ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> New Trip Ticket
            </a>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-2">
                    <a href="<?php echo base_url('modules/trip_tickets/schedules.php?month=' . rawurlencode($prevMonth)); ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                    <div class="fw-semibold fs-5"><?php echo h($monthLabel); ?></div>
                    <a href="<?php echo base_url('modules/trip_tickets/schedules.php?month=' . rawurlencode($nextMonth)); ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
                <form method="get" class="d-flex align-items-center gap-2">
                    <label for="month" class="form-label mb-0 text-muted">Jump to month</label>
                    <input type="month" id="month" name="month" class="form-control" value="<?php echo h($calendarStart->format('Y-m')); ?>" style="max-width: 180px;">
                    <button type="submit" class="btn btn-outline-primary">Go</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <style>
                .trip-schedule-grid {
                    display: grid;
                    grid-template-columns: repeat(7, minmax(0, 1fr));
                    gap: 0.75rem;
                }
                .trip-schedule-weekday {
                    font-size: 0.8rem;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.04em;
                    color: #64748b;
                    padding: 0.25rem 0.35rem;
                }
                .trip-schedule-day {
                    min-height: 170px;
                    border: 1px solid #dbe4f0;
                    border-radius: 0.85rem;
                    padding: 0.75rem;
                    background: #fff;
                    display: flex;
                    flex-direction: column;
                    gap: 0.5rem;
                }
                .trip-schedule-day.is-outside {
                    background: #f8fafc;
                    color: #94a3b8;
                }
                .trip-schedule-day.is-today {
                    border-color: #2563eb;
                    box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.08);
                }
                .trip-schedule-day-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 0.5rem;
                }
                .trip-schedule-day-number {
                    font-weight: 700;
                    font-size: 1rem;
                }
                .trip-schedule-count {
                    font-size: 0.72rem;
                    color: #475569;
                    background: #eef2ff;
                    border-radius: 999px;
                    padding: 0.12rem 0.5rem;
                }
                .trip-schedule-item {
                    display: block;
                    text-decoration: none;
                    color: inherit;
                    border: 1px solid #dbe4f0;
                    border-radius: 0.7rem;
                    padding: 0.55rem 0.6rem;
                    background: #f8fbff;
                }
                .trip-schedule-item:hover {
                    border-color: #93c5fd;
                    background: #eff6ff;
                }
                .trip-schedule-time {
                    font-size: 0.78rem;
                    font-weight: 700;
                    color: #1d4ed8;
                }
                .trip-schedule-title {
                    font-size: 0.86rem;
                    font-weight: 700;
                    margin-top: 0.2rem;
                    color: #0f172a;
                }
                .trip-schedule-meta {
                    font-size: 0.76rem;
                    color: #475569;
                    margin-top: 0.2rem;
                }
                .trip-schedule-empty {
                    margin-top: auto;
                    font-size: 0.78rem;
                    color: #94a3b8;
                }
                @media (max-width: 991.98px) {
                    .trip-schedule-grid {
                        grid-template-columns: repeat(2, minmax(0, 1fr));
                    }
                    .trip-schedule-weekday {
                        display: none;
                    }
                }
                @media (max-width: 575.98px) {
                    .trip-schedule-grid {
                        grid-template-columns: 1fr;
                    }
                }
            </style>

            <div class="trip-schedule-grid">
                <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $weekday): ?>
                    <div class="trip-schedule-weekday"><?php echo h($weekday); ?></div>
                <?php endforeach; ?>

                <?php foreach ($calendarDays as $day): ?>
                    <div class="trip-schedule-day<?php echo !$day['is_current_month'] ? ' is-outside' : ''; ?><?php echo $day['is_today'] ? ' is-today' : ''; ?>">
                        <div class="trip-schedule-day-header">
                            <div class="trip-schedule-day-number"><?php echo (int) $day['day']; ?></div>
                            <?php if ($day['tickets']): ?>
                                <div class="trip-schedule-count"><?php echo count($day['tickets']); ?> trip<?php echo count($day['tickets']) > 1 ? 's' : ''; ?></div>
                            <?php endif; ?>
                        </div>

                        <?php if (!$day['tickets']): ?>
                            <div class="trip-schedule-empty">No schedules</div>
                        <?php else: ?>
                            <?php foreach ($day['tickets'] as $ticket): ?>
                                <a href="<?php echo base_url('modules/trip_tickets/view.php?id=' . (int) $ticket['id']); ?>" class="trip-schedule-item">
                                    <div class="trip-schedule-time"><?php echo h(date('g:i A', strtotime((string) $ticket['departure_time']))); ?></div>
                                    <div class="trip-schedule-title"><?php echo h($ticket['vehicle_plate_no']); ?></div>
                                    <div class="trip-schedule-meta"><?php echo h((string) ($ticket['span_label'] ?? 'Trip day')); ?></div>
                                    <div class="trip-schedule-meta"><?php echo h($ticket['driver_name']); ?></div>
                                    <div class="trip-schedule-meta"><?php echo h($ticket['destination']); ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
