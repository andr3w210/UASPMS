<?php

function dtr_default_schedule(): array
{
    return ['am_login' => '08:00:00', 'am_logout' => '12:00:00', 'pm_login' => '13:00:00', 'pm_logout' => '17:00:00', 'grace_minutes' => 15];
}

function dtr_load_schedule(mysqli $db): array
{
    $schedule = dtr_default_schedule();
    $stmt = $db->prepare('SELECT am_login, am_logout, pm_login, pm_logout, grace_minutes FROM dtr_schedule WHERE id = 1');
    if ($stmt) {
        $stmt->execute();
        $schedule = array_merge($schedule, $stmt->get_result()->fetch_assoc() ?: []);
        $stmt->close();
    }
    return $schedule;
}

function dtr_apply_grace(DateTime $actual, DateTime $scheduled, int $graceMinutes): DateTime
{
    $windowEnd = (clone $scheduled)->modify("+{$graceMinutes} minutes");
    if ($actual >= $scheduled && $actual <= $windowEnd) {
        return clone $scheduled;
    }
    return $actual;
}

function dtr_credited_arrival(string $timestamp, array $schedule): DateTime
{
    $actual = new DateTime($timestamp);
    $amScheduled = new DateTime($actual->format('Y-m-d') . ' ' . $schedule['am_login']);
    $pmScheduled = new DateTime($actual->format('Y-m-d') . ' ' . $schedule['pm_login']);
    $scheduled = $actual < $pmScheduled ? $amScheduled : $pmScheduled;
    return dtr_apply_grace($actual, $scheduled, (int) $schedule['grace_minutes']);
}

function dtr_split_day_logs(array $dayLogs, array $schedule): array
{
    $amLogout = new DateTime($schedule['am_logout']);
    $pmLogin = new DateTime($schedule['pm_login']);
    $boundarySeconds = (((int) $amLogout->format('H') * 3600 + (int) $amLogout->format('i') * 60)
        + ((int) $pmLogin->format('H') * 3600 + (int) $pmLogin->format('i') * 60)) / 2;
    $result = ['am_in' => null, 'am_out' => null, 'pm_in' => null, 'pm_out' => null];
    foreach ($dayLogs as $log) {
        $time = new DateTime($log['logged_at']);
        $seconds = (int) $time->format('H') * 3600 + (int) $time->format('i') * 60 + (int) $time->format('s');
        $slot = $log['log_type'] === 'time_in' ? ($seconds < $boundarySeconds ? 'am_in' : 'pm_in') : ($seconds < $boundarySeconds ? 'am_out' : 'pm_out');
        if ($log['log_type'] === 'time_in') { if ($result[$slot] === null) $result[$slot] = $time; } else { $result[$slot] = $time; }
    }
    return $result;
}

function dtr_compute_day_hours(array $splitLogs): float
{
    $hours = 0.0;
    foreach ([['am_in', 'am_out'], ['pm_in', 'pm_out']] as [$in, $out]) if ($splitLogs[$in] && $splitLogs[$out]) $hours += ($splitLogs[$out]->getTimestamp() - $splitLogs[$in]->getTimestamp()) / 3600;
    return round($hours, 2);
}
