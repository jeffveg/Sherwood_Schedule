<?php
/**
 * AJAX endpoint — returns day availability status for a given month.
 * GET /booking/calendar.php?month=YYYY-MM
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/availability.php';

// Must be set before any date/strtotime calls so availability windows
// are calculated in the business timezone, not the server default.
date_default_timezone_set(APP_TIMEZONE);

header('Content-Type: application/json');

$month = $_GET['month'] ?? '';

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['error' => 'Invalid month']);
    exit;
}

// Don't allow browsing more than 12 months ahead
$max = date('Y-m', strtotime('+12 months'));
if ($month > $max || $month < date('Y-m')) {
    echo json_encode(['days' => []]);
    exit;
}

echo json_encode(['days' => get_month_day_status($month)]);
