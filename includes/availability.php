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
 * Return true if a date already has an active booking (not cancelled/rescheduled).
 * One booking per day is the business rule.
 */
function date_is_booked(string $date): bool {
    $db   = get_db();
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM bookings
         WHERE event_date = ? AND booking_status NOT IN ('cancelled','rescheduled')"
    );
    $stmt->execute([$date]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Get available start-time slots for a date.
 * Returns empty array if the day already has any active booking.
 * Otherwise returns 'H:i' strings at 1-hour intervals within the availability window.
 */
function get_available_slots(string $date): array {
    // One booking per day — if anything is booked, the whole day is unavailable
    if (date_is_booked($date)) return [];

    $window = get_day_window($date);
    if (!$window) return [];

    $open_ts  = strtotime($date . ' ' . $window['open']);

    if ($window['crosses_midnight']) {
        $next_date = date('Y-m-d', strtotime($date . ' +1 day'));
        $close_ts  = strtotime($next_date . ' ' . $window['close']);
    } else {
        $close_ts = strtotime($date . ' ' . $window['close']);
    }

    // Lead-time cutoff
    $earliest_allowed = strtotime('+' . BOOKING_LEAD_DAYS . ' days', strtotime('today'));

    $slots   = [];
    $current = $open_ts;

    while ($current < $close_ts) {
        if ($current >= $earliest_allowed) {
            $slots[] = date('H:i', $current);
        }
        $current += 3600;
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

    // Dates that already have an active booking this month (one booking per day rule)
    $stmt = $db->prepare(
        "SELECT DISTINCT event_date FROM bookings
         WHERE event_date LIKE ? AND booking_status NOT IN ('cancelled','rescheduled')"
    );
    $stmt->execute([$year_month . '-%']);
    $booked_dates = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $lead_cutoff    = strtotime('+' . BOOKING_LEAD_DAYS . ' days', strtotime('today midnight'));
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
        } elseif (isset($booked_dates[$date])) {
            $status = 'closed';   // already booked — whole day unavailable
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
