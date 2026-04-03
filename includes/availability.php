<?php
/**
 * Availability checking functions.
 */

/**
 * Get the availability window for a specific date.
 * Checks exceptions first, then falls back to the weekly rule.
 * Returns array with open/close times, or null if the date is closed.
 */
function get_day_window(string $date): ?array {
    $db  = get_db();
    $dow = (int)date('w', strtotime($date)); // 0=Sun, 6=Sat

    // Exception overrides the weekly rule
    $stmt = $db->prepare('SELECT * FROM availability_exceptions WHERE exception_date = ?');
    $stmt->execute([$date]);
    $exc  = $stmt->fetch();

    if ($exc) {
        if ($exc['is_closed']) return null;
        return [
            'open'            => substr($exc['open_time'],  0, 5),
            'close'           => substr($exc['close_time'], 0, 5),
            'crosses_midnight'=> (bool)$exc['crosses_midnight'],
        ];
    }

    // Weekly rule
    $stmt = $db->prepare(
        'SELECT * FROM availability_rules WHERE day_of_week = ? AND active = 1 LIMIT 1'
    );
    $stmt->execute([$dow]);
    $rule = $stmt->fetch();

    if (!$rule) return null;

    return [
        'open'            => substr($rule['open_time'],  0, 5),
        'close'           => substr($rule['close_time'], 0, 5),
        'crosses_midnight'=> (bool)$rule['crosses_midnight'],
    ];
}

/**
 * Get available start-time slots for a date.
 * Windows define valid START times only — no end-time constraint applied.
 * Returns array of 'H:i' strings (1-hour intervals).
 */
function get_available_slots(string $date): array {
    $window = get_day_window($date);
    if (!$window) return [];

    $open_ts  = strtotime($date . ' ' . $window['open']);

    if ($window['crosses_midnight']) {
        $next_date = date('Y-m-d', strtotime($date . ' +1 day'));
        $close_ts  = strtotime($next_date . ' ' . $window['close']);
    } else {
        $close_ts = strtotime($date . ' ' . $window['close']);
    }

    $interval_sec = 3600; // 1-hour start-time slots

    // Block start times already taken by existing bookings on this date
    $prev = date('Y-m-d', strtotime($date . ' -1 day'));
    $db   = get_db();
    $stmt = $db->prepare(
        "SELECT event_date, start_time
         FROM bookings
         WHERE booking_status NOT IN ('cancelled','rescheduled')
           AND (event_date = ? OR (event_date = ? AND crosses_midnight = 1))"
    );
    $stmt->execute([$date, $prev]);

    $booked_starts = [];
    foreach ($stmt->fetchAll() as $b) {
        $booked_starts[] = strtotime($b['event_date'] . ' ' . $b['start_time']);
    }

    // Lead-time cutoff
    $earliest_allowed = strtotime('+' . BOOKING_LEAD_DAYS . ' days', strtotime('today'));

    $slots   = [];
    $current = $open_ts;

    while ($current < $close_ts) {
        if ($current >= $earliest_allowed && !in_array($current, $booked_starts, true)) {
            $slots[] = date('H:i', $current);
        }
        $current += $interval_sec;
    }

    return $slots;
}

/**
 * Get availability status for every day of a given month.
 * Returns ['YYYY-MM-DD' => 'available'|'closed'|'past'|'soon'] for each day.
 */
function get_month_day_status(string $year_month): array {
    $db = get_db();

    // Which days of week have active rules
    $rule_days = $db->query('SELECT day_of_week FROM availability_rules WHERE active = 1')
                    ->fetchAll(PDO::FETCH_COLUMN);
    $rule_map  = array_flip($rule_days);

    // Exceptions for this month
    $stmt = $db->prepare(
        "SELECT exception_date, is_closed FROM availability_exceptions WHERE exception_date LIKE ?"
    );
    $stmt->execute([$year_month . '-%']);
    $exc_map = [];
    foreach ($stmt->fetchAll() as $e) {
        $exc_map[$e['exception_date']] = (bool)$e['is_closed'];
    }

    $lead_cutoff = strtotime('+' . BOOKING_LEAD_DAYS . ' days', strtotime('today midnight'));
    $today_midnight = strtotime('today midnight');

    $days   = (int)date('t', strtotime($year_month . '-01'));
    $result = [];

    for ($d = 1; $d <= $days; $d++) {
        $date = sprintf('%s-%02d', $year_month, $d);
        $ts   = strtotime($date);
        $dow  = (int)date('w', $ts);

        if ($ts < $today_midnight) {
            $status = 'past';
        } elseif ($ts < $lead_cutoff) {
            $status = 'soon';
        } elseif (array_key_exists($date, $exc_map)) {
            $status = $exc_map[$date] ? 'closed' : 'available';
        } elseif (isset($rule_map[$dow])) {
            $status = 'available';
        } else {
            $status = 'closed';
        }

        $result[$date] = $status;
    }

    return $result;
}
