<?php
/**
 * Booking wizard session helpers.
 * All wizard state is stored in $_SESSION['booking'].
 */

define('WIZARD_STEPS', [
    1 => 'Activity',
    2 => 'Duration',
    3 => 'Date & Time',
    4 => 'Add-ons',
    5 => 'Venue',
    6 => 'Review',
    7 => 'Payment',
]);

function wizard_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('sa_booking');
        session_start();
    }
    if (!isset($_SESSION['booking'])) {
        $_SESSION['booking'] = [];
    }
}

function wizard_get(string $key, mixed $default = null): mixed {
    return $_SESSION['booking'][$key] ?? $default;
}

function wizard_set(string $key, mixed $value): void {
    $_SESSION['booking'][$key] = $value;
}

function wizard_clear(): void {
    $_SESSION['booking'] = [];
}

/**
 * Ensure the visitor has completed all prior steps before accessing $step.
 * Redirects back to the earliest incomplete step if not.
 */
function wizard_require_step(int $step): void {
    $required_keys = [
        2 => ['attraction_id'],
        3 => ['attraction_id', 'hours'],
        4 => ['attraction_id', 'hours', 'event_date', 'start_time'],
        5 => ['attraction_id', 'hours', 'event_date', 'start_time'],
        6 => ['attraction_id', 'hours', 'event_date', 'start_time',
              'customer_first', 'customer_email', 'customer_phone',
              'venue_name', 'venue_address', 'venue_city'],
        7 => ['attraction_id', 'hours', 'event_date', 'start_time',
              'customer_first', 'customer_email', 'customer_phone',
              'venue_name', 'venue_address', 'venue_city', 'payment_option'],
    ];

    if (!isset($required_keys[$step])) return;

    foreach ($required_keys[$step] as $key) {
        if (empty($_SESSION['booking'][$key])) {
            // Find the step that collects the missing key and redirect there
            $redirect_step = max(1, $step - 1);
            header('Location: ' . APP_URL . '/booking/step' . $redirect_step . '.php');
            exit;
        }
    }
}

function wizard_step_url(int $step): string {
    if ($step < 1) return APP_URL . '/booking/step1.php';
    if ($step > 7) return APP_URL . '/booking/confirm.php';
    return APP_URL . '/booking/step' . $step . '.php';
}
