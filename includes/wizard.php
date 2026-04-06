<?php
/**
 * Booking wizard session helpers.
 *
 * The multi-step booking flow stores all in-progress data inside
 * $_SESSION['booking'] (a flat associative array).  These helpers
 * provide a clean API over that array so individual step files never
 * access $_SESSION directly.
 *
 * Session name: 'sa_booking' — kept separate from the admin session
 * ('sa_admin') so both can coexist in the same browser without conflict.
 *
 * Step → data keys produced:
 *   Step 1  attraction_id, attraction (full DB row)
 *   Step 2  hours
 *   Step 3  event_date, start_time
 *   Step 4  selected_addons[]
 *   Step 5  customer_first, customer_last, customer_email, customer_phone,
 *           customer_org, call_time_pref, tournament_bracket,
 *           allow_publish, allow_advertise, event_notes,
 *           venue_name, venue_address, venue_city, venue_state, venue_zip,
 *           travel_miles
 *   Step 6  payment_option, coupon, coupon_code (review + coupon apply)
 *   Step 7  booking_id, booking_ref, square_payment_url (set after DB insert)
 */

/** Map of step number → human-readable label used by the progress indicator. */
define('WIZARD_STEPS', [
    1 => 'Activity',
    2 => 'Duration',
    3 => 'Date & Time',
    4 => 'Add-ons',
    5 => 'Venue',
    6 => 'Review',
    7 => 'Payment',
]);

/**
 * Start (or resume) the customer booking session.
 *
 * Must be called at the top of every wizard step file and every AJAX
 * endpoint that reads wizard state (e.g. slots.php, calendar.php).
 * Also initialises the $_SESSION['booking'] bucket on first visit.
 */
function wizard_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Security flags — secure (HTTPS only), httponly (no JS access),
        // samesite Lax (blocks cross-origin POST while allowing top-level nav).
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('sa_booking');
        session_start();
    }
    if (!isset($_SESSION['booking'])) {
        $_SESSION['booking'] = [];
    }
}

/**
 * Read a value from the wizard session bucket.
 *
 * @param string $key     The key to read (see file docblock for available keys).
 * @param mixed  $default Value to return when the key is not yet set.
 * @return mixed
 */
function wizard_get(string $key, mixed $default = null): mixed {
    return $_SESSION['booking'][$key] ?? $default;
}

/**
 * Write a value into the wizard session bucket.
 *
 * @param string $key   Key to set.
 * @param mixed  $value Value to store.
 */
function wizard_set(string $key, mixed $value): void {
    $_SESSION['booking'][$key] = $value;
}

/**
 * Wipe all wizard session data (called after a successful booking or
 * when the customer explicitly starts over).
 */
function wizard_clear(): void {
    $_SESSION['booking'] = [];
}

/**
 * Guard: ensure the visitor has completed all prior steps before allowing
 * access to the requested $step.
 *
 * Each step requires certain session keys to exist (set by earlier steps).
 * If any required key is empty the visitor is sent back to ($step - 1),
 * which will in turn check its own requirements, cascading the user back
 * to the earliest incomplete step.
 *
 * Steps 1 and 8+ have no prerequisites and always pass through.
 *
 * @param int $step  The step number the current page represents (1–7).
 */
function wizard_require_step(int $step): void {
    // Keys that must be non-empty in $_SESSION['booking'] before each step
    // can be accessed.  Each step's list is cumulative (later steps require
    // everything earlier steps required, plus the keys those steps produce).
    $required_keys = [
        2 => ['attraction_id'],
        3 => ['attraction_id', 'hours'],
        4 => ['attraction_id', 'hours', 'event_date', 'start_time'],
        5 => ['attraction_id', 'hours', 'event_date', 'start_time'],
        6 => ['attraction_id', 'hours', 'event_date', 'start_time',
              'customer_first', 'customer_email', 'customer_phone',
              'venue_address', 'venue_city'],
        7 => ['attraction_id', 'hours', 'event_date', 'start_time',
              'customer_first', 'customer_email', 'customer_phone',
              'venue_address', 'venue_city', 'payment_option'],
    ];

    if (!isset($required_keys[$step])) return;

    foreach ($required_keys[$step] as $key) {
        if (empty($_SESSION['booking'][$key])) {
            // Redirect back one step; that page will cascade further if needed.
            $redirect_step = max(1, $step - 1);
            header('Location: ' . APP_URL . '/booking/step' . $redirect_step . '.php');
            exit;
        }
    }
}

/**
 * Return the absolute URL for a given wizard step number.
 *
 * Step 0 or below → step1.php
 * Step 8 or above → confirm.php
 *
 * @param int $step  Step number (1–7).
 * @return string    Full URL.
 */
function wizard_step_url(int $step): string {
    if ($step < 1) return APP_URL . '/booking/step1.php';
    if ($step > 7) return APP_URL . '/booking/confirm.php';
    return APP_URL . '/booking/step' . $step . '.php';
}
