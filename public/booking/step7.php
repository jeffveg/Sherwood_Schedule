<?php
/**
 * Step 7 — Payment
 * Creates the booking record, generates a Square Payment Link,
 * and redirects the customer to Square to complete payment.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/wizard.php';
require_once __DIR__ . '/../../includes/pricing.php';
require_once __DIR__ . '/../../includes/booking_ref.php';

// Set before any date/strtotime calls (end_time calc, date formatting)
date_default_timezone_set(APP_TIMEZONE);

wizard_start();
wizard_require_step(7);

$attraction      = wizard_get('attraction');
$hours           = (float)wizard_get('hours');
$event_date      = wizard_get('event_date');
$start_time      = wizard_get('start_time');
$selected_addons = wizard_get('selected_addons', []);
$travel_miles    = (float)wizard_get('travel_miles', 0);
$payment_option  = wizard_get('payment_option');
$coupon          = wizard_get('coupon', null);
$coupon_code     = wizard_get('coupon_code', '');

$db = get_db();

// ── Load pricing dependencies ─────────────────────────────────────────────
// Fetch the active pricing row for this attraction. If missing (e.g. pricing
// was deactivated after the customer started their session), we cannot build
// an accurate price — redirect to step 1 rather than producing a $0 booking.
$pricing_row = $db->prepare('SELECT * FROM attraction_pricing WHERE attraction_id = ? AND active = 1 LIMIT 1');
$pricing_row->execute([$attraction['id']]);
$pricing = $pricing_row->fetch();

if (!$pricing) {
    error_log('step7: No active pricing row found for attraction ID ' . $attraction['id'] . ' — redirecting to step1');
    header('Location: ' . APP_URL . '/booking/step1.php');
    exit;
}

// Active tax rates, ordered by sort_order so the breakdown is consistent.
$tax_rates = $db->query('SELECT * FROM tax_config WHERE active = 1 ORDER BY sort_order')->fetchAll();

// Travel fee config (single row). If somehow missing, fall back to safe
// defaults so the booking can still complete — log so ops can fix the table.
$travel_config = $db->query('SELECT * FROM travel_fee_config WHERE id = 1')->fetch();
if (!$travel_config) {
    error_log('step7: travel_fee_config row id=1 is missing — falling back to defaults');
    $travel_config = ['free_miles_threshold' => 0, 'rate_per_mile' => 0.00];
}

// Build full price summary
$summary = build_price_summary(
    $attraction, $pricing, $hours, $selected_addons,
    $coupon, $travel_miles, $travel_config, $tax_rates
);

$amount_due = ($payment_option === 'full')
    ? $summary['grand_total']
    : $summary['deposit_amount'];

$error = '';

// ── Create booking + Square link on first load (GET) ──────────────────────
// Check if we already have a booking_id in session (avoid double-insert on refresh)
if (!wizard_get('booking_id')) {

    $db->beginTransaction();
    try {
        // Upsert customer
        $stmt = $db->prepare(
            'SELECT id FROM customers WHERE email = ? AND phone = ? LIMIT 1'
        );
        $stmt->execute([wizard_get('customer_email'), wizard_get('customer_phone')]);
        $customer_id = $stmt->fetchColumn();

        if (!$customer_id) {
            $stmt = $db->prepare(
                'INSERT INTO customers (first_name, last_name, email, phone, organization)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                wizard_get('customer_first'),
                wizard_get('customer_last'),
                wizard_get('customer_email'),
                wizard_get('customer_phone'),
                wizard_get('customer_org') ?: null,
            ]);
            $customer_id = (int)$db->lastInsertId();
        }

        // Calculate end time
        $start_ts        = strtotime($event_date . ' ' . $start_time);
        $end_ts          = $start_ts + (int)round($hours * 3600);
        $end_time        = date('H:i:s', $end_ts);
        $crosses_midnight = (date('Y-m-d', $end_ts) !== $event_date) ? 1 : 0;

        $booking_ref = generate_booking_ref();

        $balance_due = ($payment_option === 'full') ? 0.00
            : round($summary['grand_total'] - $summary['deposit_amount'], 2);

        // ── Tax breakdown — split into state vs city buckets ───────────────
        // Arizona has separate state and city (municipal) tax rates.
        // We detect which bucket a rate belongs to by inspecting its label:
        // any rate labelled "city" or named after the city ("goodyear") goes
        // to tax_city; everything else (state, county) goes to tax_state.
        // Adjust the label-matching strings here if the tax_config labels change.
        $tax_state  = 0.0;
        $tax_county = 0.0;
        $tax_city   = 0.0;
        foreach ($tax_rates as $rate) {
            $amt = round($summary['taxable_subtotal'] * (float)$rate['rate'], 2);
            $lbl = strtolower($rate['label']);
            if (str_contains($lbl, 'city') || str_contains($lbl, 'goodyear')) {
                $tax_city += $amt;
            } else {
                $tax_state += $amt;
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO bookings (
                booking_ref, customer_id, attraction_id,
                event_date, start_time, end_time, duration_hours, crosses_midnight,
                venue_name, venue_address, venue_city, venue_state, venue_zip,
                travel_miles, travel_fee,
                attraction_price, addons_subtotal,
                coupon_id, coupon_code, coupon_discount,
                taxable_subtotal, tax_rate, tax_state, tax_county, tax_city, tax_total,
                grand_total, deposit_amount, payment_option, amount_paid, balance_due,
                booking_status, payment_status,
                call_time_pref, tournament_bracket, allow_publish, allow_advertise, event_notes
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, 0.00, ?,
                "pending", "unpaid",
                ?, ?, ?, ?, ?
            )'
        );
        $stmt->execute([
            $booking_ref, $customer_id, $attraction['id'],
            $event_date, $start_time, $end_time, $hours, $crosses_midnight,
            wizard_get('venue_name') ?: '', wizard_get('venue_address'), wizard_get('venue_city'),
            wizard_get('venue_state'), wizard_get('venue_zip') ?: null,
            $travel_miles ?: null, $summary['travel_fee'],
            $summary['attraction_price'], $summary['addons_subtotal'],
            $coupon ? $coupon['id'] : null, $coupon_code ?: null, $summary['coupon_discount'],
            $summary['taxable_subtotal'], $summary['tax_rate'],
            $tax_state, $tax_county, $tax_city, $summary['tax_total'],
            $summary['grand_total'], $summary['deposit_amount'], $payment_option,
            $balance_due,
            wizard_get('call_time_pref') ?: null,
            wizard_get('tournament_bracket') !== 'No' ? wizard_get('tournament_bracket') : null,
            wizard_get('allow_publish') !== '' ? (int)wizard_get('allow_publish') : null,
            wizard_get('allow_advertise') !== '' ? (int)wizard_get('allow_advertise') : null,
            wizard_get('event_notes') ?: null,
        ]);
        $booking_id = (int)$db->lastInsertId();

        // Save add-ons
        if ($selected_addons) {
            $addon_stmt = $db->prepare(
                'INSERT INTO booking_addons (booking_id, addon_id, addon_name, quantity, unit_price, total_price, is_taxable)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($summary['addon_lines'] as $line) {
                $addon_stmt->execute([
                    $booking_id, $line['addon_id'], $line['addon_name'],
                    $line['quantity'], $line['unit_price'], $line['total_price'],
                    $line['is_taxable'] ? 1 : 0,
                ]);
            }
        }

        // Increment coupon use count
        if ($coupon) {
            $db->prepare('UPDATE coupons SET use_count = use_count + 1 WHERE id = ?')
               ->execute([$coupon['id']]);
        }

        $db->commit();
        wizard_set('booking_id',  $booking_id);
        wizard_set('booking_ref', $booking_ref);

    } catch (Throwable $e) {
        $db->rollBack();
        $error = 'There was a problem saving your booking. Please try again or contact us.';
        error_log('Booking insert failed: ' . $e->getMessage());
    }
}

$booking_id  = wizard_get('booking_id');
$booking_ref = wizard_get('booking_ref');

// ── Create Square Payment Link ─────────────────────────────────────────────
if (!$error && $booking_id && !wizard_get('square_payment_url')) {

    $square_base = (SQUARE_ENVIRONMENT === 'production')
        ? 'https://connect.squareup.com'
        : 'https://connect.squareupsandbox.com';

    $amount_cents = (int)round($amount_due * 100);

    $description = $attraction['name'] . ' — ' . date('M j, Y', strtotime($event_date));
    if ($payment_option === 'deposit') {
        $description .= ' (deposit)';
    }

    $payload = [
        'idempotency_key' => 'sa-' . $booking_ref . '-' . $payment_option,
        'order' => [
            'location_id' => SQUARE_LOCATION_ID,
            'line_items'  => [[
                'name'     => $description,
                'quantity' => '1',
                'base_price_money' => [
                    'amount'   => $amount_cents,
                    'currency' => 'USD',
                ],
            ]],
            'reference_id' => $booking_ref,
        ],
        'checkout_options' => [
            // The confirm token is an HMAC of the booking_ref signed with APP_SECRET.
            // confirm.php verifies this token so that booking details are only visible
            // to someone who received the legitimate redirect URL — not an enumerator.
            'redirect_url' => APP_URL . '/booking/confirm.php?ref=' . urlencode($booking_ref)
                . '&t=' . hash_hmac('sha256', $booking_ref, APP_SECRET),
            'ask_for_shipping_address' => false,
        ],
        'pre_populated_data' => [
            'buyer_email' => wizard_get('customer_email'),
        ],
    ];

    $ch = curl_init($square_base . '/v2/online-checkout/payment-links');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Square-Version: 2024-01-17',
            'Authorization: Bearer ' . SQUARE_ACCESS_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 15,
    ]);

    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err || $http_code !== 200) {
        $error = 'We could not connect to our payment processor. Please try again or contact us.';
        error_log('Square Payment Link error (' . $http_code . '): ' . ($curl_err ?: $response));
    } else {
        // json_decode returns null on malformed JSON; guard before array access.
        $data = json_decode($response, true);
        $payment_url = is_array($data) ? ($data['payment_link']['url'] ?? '') : '';
        if (!$payment_url) {
            $error = 'Payment link creation failed. Please try again or contact us.';
            error_log('Square Payment Link missing URL: ' . $response);
        } else {
            // Save link details to payments table
            $pay_stmt = $db->prepare(
                'INSERT INTO payments (booking_id, payment_type, amount, payment_method,
                 square_payment_link_id, square_order_id, status)
                 VALUES (?, ?, ?, "square_online", ?, ?, "pending")'
            );
            $pay_stmt->execute([
                $booking_id,
                $payment_option === 'full' ? 'balance' : 'deposit',
                $amount_due,
                $data['payment_link']['id'] ?? null,
                $data['payment_link']['order_id'] ?? null,
            ]);

            wizard_set('square_payment_url', $payment_url);
        }
    }
}

// ── Redirect to Square if we have a URL ───────────────────────────────────
$payment_url = wizard_get('square_payment_url');
if (!$error && $payment_url) {
    header('Location: ' . $payment_url);
    exit;
}

// ── Show error page if something went wrong ───────────────────────────────
render_header('Payment', 'book');
?>

<div class="container container--narrow">
    <?php render_wizard_progress(7, array_values(WIZARD_STEPS)); ?>

    <div class="text-center mb-4">
        <h2>Payment</h2>
    </div>

    <div class="alert alert-danger">
        <div>
            <p><strong>Something went wrong.</strong></p>
            <p><?= h($error) ?></p>
            <p class="mt-2">
                <a href="<?= wizard_step_url(6) ?>" class="btn btn-ghost btn-sm">&larr; Back to Review</a>
                &nbsp;
                <a href="https://sherwoodadventure.com/contact.html" class="btn btn-primary btn-sm">Contact Us</a>
            </p>
        </div>
    </div>
</div>

<?php render_footer(); ?>
