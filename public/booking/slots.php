<?php
/**
 * AJAX endpoint — returns available time slots for a given date + duration.
 * GET /booking/slots.php?date=YYYY-MM-DD&hours=2
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/wizard.php';

header('Content-Type: application/json');

wizard_start();

$date  = $_GET['date']  ?? '';
$hours = (float)($_GET['hours'] ?? 0);

// Basic validation
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $hours <= 0) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

// Don't serve slots for past or too-soon dates server-side
$lead_cutoff = strtotime('+' . BOOKING_LEAD_DAYS . ' days', strtotime('today midnight'));
if (strtotime($date) < $lead_cutoff) {
    echo json_encode(['slots' => [], 'message' => 'Date not available for online booking.']);
    exit;
}

$slots = get_available_slots($date, $hours);

// Format for display: 'H:i' => '10:00 AM'
$formatted = [];
foreach ($slots as $slot) {
    $formatted[] = [
        'value'   => $slot,
        'display' => date('g:i A', strtotime('2000-01-01 ' . $slot)),
    ];
}

echo json_encode(['slots' => $formatted]);
