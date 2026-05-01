<?php
/**
 * Admin — Create New Booking
 * Single-page form; no Square wizard, no lead-time restriction.
 * Admin records payment method directly.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/pricing.php';
require_once __DIR__ . '/../../includes/booking_ref.php';
require_once __DIR__ . '/../../includes/email_templates.php';

admin_require_login();
date_default_timezone_set(APP_TIMEZONE);

$db = get_db();

// ── Load catalog data ──────────────────────────────────────────────────────
$attractions = $db->query(
    'SELECT * FROM attractions WHERE active = 1 ORDER BY sort_order'
)->fetchAll();

$all_pricing = $db->query(
    'SELECT * FROM attraction_pricing WHERE active = 1'
)->fetchAll();
$pricing_map = [];
foreach ($all_pricing as $p) { $pricing_map[$p['attraction_id']] = $p; }

$all_addons = $db->query(
    'SELECT * FROM addons WHERE active = 1 ORDER BY sort_order, name'
)->fetchAll();

$tax_rates     = $db->query('SELECT * FROM tax_config WHERE active = 1 ORDER BY sort_order')->fetchAll();
$travel_config = $db->query('SELECT * FROM travel_fee_config WHERE id = 1')->fetch();

$flash = '';
$flash_type = 'success';
$errors = [];
$post = $_POST;    // preserve form values on error

// ── POST: create booking ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // --- Collect & validate ---
    $attraction_id  = (int)($_POST['attraction_id'] ?? 0);
    $hours          = (float)($_POST['hours'] ?? 0);
    $event_date     = trim($_POST['event_date'] ?? '');
    $start_time     = trim($_POST['start_time'] ?? '');

    $first_name     = trim($_POST['first_name'] ?? '');
    $last_name      = trim($_POST['last_name']  ?? '');
    $email          = trim($_POST['email']       ?? '');
    $phone          = trim($_POST['phone']       ?? '');
    $organization   = trim($_POST['organization'] ?? '');
    $is_nonprofit   = (int)!empty($_POST['is_nonprofit']);

    $venue_name     = trim($_POST['venue_name']    ?? '');
    $venue_address  = trim($_POST['venue_address'] ?? '');
    $venue_city     = trim($_POST['venue_city']    ?? '');
    $venue_state    = trim($_POST['venue_state']   ?? 'AZ');
    $venue_zip      = trim($_POST['venue_zip']     ?? '');

    $travel_fee_raw = $_POST['travel_fee']  ?? '0';
    $travel_fee     = (float)$travel_fee_raw;
    $travel_miles   = (float)($_POST['travel_miles'] ?? 0);

    $coupon_code_input = strtoupper(trim($_POST['coupon_code'] ?? ''));
    $payment_option    = $_POST['payment_option'] ?? 'deposit';
    $payment_method    = $_POST['payment_method'] ?? 'other';
    $amount_paid_input = (float)($_POST['amount_paid'] ?? 0);
    $admin_notes       = trim($_POST['admin_notes'] ?? '');
    $send_email        = !empty($_POST['send_email']);

    $selected_addon_ids = array_map('intval', $_POST['addons'] ?? []);

    // Validation
    if (!$attraction_id)                             $errors[] = 'Select an attraction.';
    if ($hours < 1)                                  $errors[] = 'Duration must be at least 1 hour.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date)) $errors[] = 'Enter a valid event date.';
    if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) $errors[] = 'Enter a valid start time.';
    if (!$first_name)                                $errors[] = 'First name is required.';
    if (!$last_name)                                 $errors[] = 'Last name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Valid email is required.';
    if (!$phone)                                     $errors[] = 'Phone is required.';
    if (!$venue_address)                             $errors[] = 'Venue address is required.';
    if (!$venue_city)                                $errors[] = 'Venue city is required.';

    // Load attraction + pricing
    $attraction = null;
    $pricing    = null;
    if ($attraction_id) {
        foreach ($attractions as $a) {
            if ($a['id'] === $attraction_id) { $attraction = $a; break; }
        }
        $pricing = $pricing_map[$attraction_id] ?? null;
        if (!$attraction || !$pricing) $errors[] = 'Attraction pricing not found.';
    }

    // Coupon lookup
    $coupon = null;
    if ($coupon_code_input && !$errors) {
        $c_stmt = $db->prepare(
            "SELECT * FROM coupons WHERE code = ? AND active = 1
             AND (expires_at IS NULL OR expires_at >= CURDATE())
             AND (max_uses IS NULL OR use_count < max_uses)"
        );
        $c_stmt->execute([$coupon_code_input]);
        $coupon = $c_stmt->fetch() ?: null;
        if (!$coupon) $errors[] = "Coupon '{$coupon_code_input}' is invalid or expired.";
    }

    if (!$errors) {
        // Build selected addons
        $selected_addons = [];
        foreach ($all_addons as $addon) {
            if (in_array((int)$addon['id'], $selected_addon_ids, true)) {
                $selected_addons[] = ['addon' => $addon, 'quantity' => 1];
            }
        }

        // Build price summary using travel_fee as manual override
        // We pass travel_miles=0 then override travel_fee directly
        $summary = build_price_summary(
            $attraction, $pricing, $hours, $selected_addons,
            $coupon, $travel_miles, $travel_config, $tax_rates
        );
        // Override travel fee if manually set (or miles unknown)
        $summary['travel_fee'] = $travel_fee;
        // Recalc grand total with manual travel fee
        $summary['grand_total'] = round(
            $summary['taxable_subtotal']
            + ($summary['addons_subtotal'] - ($summary['taxable_subtotal'] - $summary['attraction_price'] + $summary['coupon_discount']))
            + $summary['tax_total']
            + $travel_fee,
            2
        );

        // Recalculate grand total properly
        // taxable_subtotal already has coupon applied; non-taxable addons added separately
        $non_taxable = 0.0;
        foreach ($selected_addons as $item) {
            if (!$item['addon']['is_taxable']) {
                $non_taxable += calc_addon_price($item['addon'], $hours);
            }
        }
        $summary['grand_total'] = round(
            $summary['taxable_subtotal'] + $non_taxable + $summary['tax_total'] + $travel_fee,
            2
        );

        // Determine payment status
        $deposit_amount = (float)$attraction['deposit_amount'];
        if ($payment_option === 'collect_later') {
            $payment_status = 'collect_later';
            $amount_paid    = 0.00;
            $balance_due    = $summary['grand_total'];
        } elseif ($payment_option === 'full') {
            $amount_paid    = $summary['grand_total'];
            $balance_due    = 0.00;
            $payment_status = 'paid_in_full';
        } else { // deposit
            $amount_paid    = $deposit_amount;
            $balance_due    = round($summary['grand_total'] - $deposit_amount, 2);
            $payment_status = 'deposit_paid';
        }

        // Use admin-entered amount if provided and non-zero
        if ($payment_option !== 'collect_later' && $amount_paid_input > 0) {
            $amount_paid = $amount_paid_input;
            $balance_due = max(0, round($summary['grand_total'] - $amount_paid, 2));
            $payment_status = $balance_due <= 0.01 ? 'paid_in_full' : 'deposit_paid';
        }

        // Calculate end time
        $start_ts        = strtotime($event_date . ' ' . $start_time);
        $end_ts          = $start_ts + (int)round($hours * 3600);
        $end_time        = date('H:i:s', $end_ts);
        $crosses_midnight = (date('Y-m-d', $end_ts) !== $event_date) ? 1 : 0;

        // Tax split
        $tax_state = $tax_county = $tax_city = 0.0;
        foreach ($tax_rates as $rate) {
            $amt = round($summary['taxable_subtotal'] * (float)$rate['rate'], 2);
            $lbl = strtolower($rate['label']);
            if (str_contains($lbl, 'city') || str_contains($lbl, 'goodyear')) {
                $tax_city += $amt;
            } elseif (str_contains($lbl, 'county')) {
                $tax_county += $amt;
            } else {
                $tax_state += $amt;
            }
        }

        $db->beginTransaction();
        try {
            // Upsert customer
            $c_check = $db->prepare('SELECT id FROM customers WHERE email = ? AND phone = ? LIMIT 1');
            $c_check->execute([$email, $phone]);
            $customer_id = $c_check->fetchColumn();

            if ($customer_id) {
                $db->prepare(
                    'UPDATE customers SET first_name=?, last_name=?, organization=?, is_nonprofit=? WHERE id=?'
                )->execute([$first_name, $last_name, $organization ?: null, $is_nonprofit, $customer_id]);
            } else {
                $db->prepare(
                    'INSERT INTO customers (first_name, last_name, email, phone, organization, is_nonprofit)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$first_name, $last_name, $email, $phone, $organization ?: null, $is_nonprofit]);
                $customer_id = (int)$db->lastInsertId();
            }

            $booking_ref = generate_booking_ref();

            $db->prepare(
                'INSERT INTO bookings (
                    booking_ref, customer_id, attraction_id,
                    event_date, start_time, end_time, duration_hours, crosses_midnight,
                    venue_name, venue_address, venue_city, venue_state, venue_zip,
                    travel_miles, travel_fee, travel_fee_overridden,
                    attraction_price, addons_subtotal,
                    coupon_id, coupon_code, coupon_discount,
                    taxable_subtotal, tax_rate, tax_state, tax_county, tax_city, tax_total,
                    grand_total, deposit_amount, payment_option, amount_paid, balance_due,
                    booking_status, payment_status, is_admin_booking, admin_notes
                ) VALUES (
                    ?,?,?,  ?,?,?,?,?,  ?,?,?,?,?,  ?,?,1,
                    ?,?,    ?,?,?,      ?,?,?,?,?,?,
                    ?,?,?,?,?,          ?,?,1,?
                )'
            )->execute([
                $booking_ref, $customer_id, $attraction_id,
                $event_date, $start_time, $end_time, $hours, $crosses_midnight,
                $venue_name, $venue_address, $venue_city, $venue_state, $venue_zip ?: null,
                $travel_miles ?: null, $travel_fee,
                $summary['attraction_price'], $summary['addons_subtotal'],
                $coupon ? $coupon['id'] : null, $coupon_code_input ?: null, $summary['coupon_discount'],
                $summary['taxable_subtotal'], $summary['tax_rate'],
                $tax_state, $tax_county, $tax_city, $summary['tax_total'],
                $summary['grand_total'], $deposit_amount, $payment_option,
                $amount_paid, $balance_due,
                'confirmed',          // booking_status — admin bookings are always confirmed
                $payment_status,      // payment_status
                $admin_notes ?: null, // admin_notes
            ]);
            $booking_id = (int)$db->lastInsertId();

            // Add-ons
            if ($summary['addon_lines']) {
                $ao = $db->prepare(
                    'INSERT INTO booking_addons (booking_id, addon_id, addon_name, quantity, unit_price, total_price, is_taxable)
                     VALUES (?,?,?,?,?,?,?)'
                );
                foreach ($summary['addon_lines'] as $line) {
                    $ao->execute([
                        $booking_id, $line['addon_id'], $line['addon_name'],
                        $line['quantity'], $line['unit_price'], $line['total_price'],
                        $line['is_taxable'] ? 1 : 0,
                    ]);
                }
            }

            // Payment record
            if ($amount_paid > 0) {
                $db->prepare(
                    "INSERT INTO payments (booking_id, payment_type, amount, payment_method, status, paid_at)
                     VALUES (?, ?, ?, ?, 'completed', NOW())"
                )->execute([
                    $booking_id,
                    $payment_option === 'full' ? 'balance' : 'deposit',
                    $amount_paid,
                    $payment_method,
                ]);
            }

            // Coupon use count
            if ($coupon) {
                $db->prepare('UPDATE coupons SET use_count = use_count + 1 WHERE id = ?')
                   ->execute([$coupon['id']]);
            }

            $db->commit();

            // Send confirmation email if requested
            if ($send_email) {
                $full_booking = $db->prepare(
                    'SELECT b.*, a.name AS attraction_name FROM bookings b
                     JOIN attractions a ON a.id = b.attraction_id WHERE b.id = ?'
                );
                $full_booking->execute([$booking_id]);
                $brow = $full_booking->fetch();

                $cust_row = $db->prepare('SELECT * FROM customers WHERE id = ?');
                $cust_row->execute([$customer_id]);
                $cust = $cust_row->fetch();

                $addon_rows = $db->prepare('SELECT * FROM booking_addons WHERE booking_id = ?');
                $addon_rows->execute([$booking_id]);
                $addon_lines = $addon_rows->fetchAll();

                if ($brow && $cust) {
                    $sent = send_booking_confirmation($brow, $cust, $addon_lines, $brow['attraction_name']);
                    if ($sent) {
                        $db->prepare('UPDATE bookings SET confirmation_sent = 1 WHERE id = ?')
                           ->execute([$booking_id]);
                    }
                }
            }

            header('Location: ' . APP_URL . '/admin/booking.php?id=' . $booking_id . '&created=1');
            exit;

        } catch (Throwable $e) {
            $db->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
            error_log('Admin booking insert failed: ' . $e->getMessage());
        }
    }
}

// ── Build JS data for live pricing ────────────────────────────────────────
$js_attractions = [];
foreach ($attractions as $a) {
    $p = $pricing_map[$a['id']] ?? null;
    $js_attractions[$a['id']] = [
        'name'          => $a['name'],
        'min_hours'     => (float)$a['min_hours'],
        'hour_increment'=> (float)$a['hour_increment'],
        'deposit'       => (float)$a['deposit_amount'],
        'base_hours'    => $p ? (float)$p['base_hours']             : 0,
        'base_price'    => $p ? (float)$p['base_price']             : 0,
        'hourly_rate'   => $p ? (float)$p['additional_hourly_rate'] : 0,
    ];
}

$js_addons = [];
foreach ($all_addons as $addon) {
    $js_addons[$addon['id']] = [
        'name'          => $addon['name'],
        'pricing_type'  => $addon['pricing_type'],
        'price'         => (float)$addon['price'],
        'min_charge'    => $addon['min_charge'] ? (float)$addon['min_charge'] : 0,
        'is_taxable'    => (bool)$addon['is_taxable'],
        'applicable'    => $addon['applicable_attractions']
                           ? json_decode($addon['applicable_attractions'], true)
                           : null,
    ];
}

$js_tax_rates = array_map(fn($r) => (float)$r['rate'], $tax_rates);

render_admin_header('New Booking', 'new-booking');
?>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
    <?php foreach ($errors as $e): ?>
    <div><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" id="new-booking-form">
<?= csrf_field() ?>

<div class="admin-detail-grid" style="gap:0 2rem;">
<!-- ════ LEFT COLUMN ════════════════════════════════════════════════ -->
<div>

    <!-- Event -->
    <div class="admin-panel mb-3">
        <div class="admin-panel__header">Event</div>
        <div class="admin-panel__body">
            <div class="form-group">
                <label class="form-label">Attraction</label>
                <select name="attraction_id" class="form-input" id="sel-attraction" required>
                    <option value="">— Select —</option>
                    <?php foreach ($attractions as $a): ?>
                    <option value="<?= $a['id'] ?>"
                            <?= ($post['attraction_id'] ?? '') == $a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Duration (hours)</label>
                    <input type="number" name="hours" id="inp-hours" class="form-input"
                           step="0.5" min="1" required
                           value="<?= htmlspecialchars($post['hours'] ?? '') ?>">
                    <p class="form-hint" id="hours-hint"></p>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="event_date" class="form-input" required
                           value="<?= htmlspecialchars($post['event_date'] ?? '') ?>"
                           id="inp-date">
                    <p class="form-hint text-orange" id="date-booked-warn" style="display:none;">
                        ⚠ Another booking exists on this date.
                    </p>
                </div>
                <div class="form-group">
                    <label class="form-label">Start Time</label>
                    <input type="time" name="start_time" class="form-input" required
                           value="<?= htmlspecialchars($post['start_time'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Customer -->
    <div class="admin-panel mb-3">
        <div class="admin-panel__header">Customer</div>
        <div class="admin-panel__body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-input" required
                           value="<?= htmlspecialchars($post['first_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-input" required
                           value="<?= htmlspecialchars($post['last_name'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-input" required
                           value="<?= htmlspecialchars($post['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="tel" name="phone" class="form-input" required
                           value="<?= htmlspecialchars($post['phone'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Organization <span class="text-dim">(optional)</span></label>
                    <input type="text" name="organization" class="form-input"
                           value="<?= htmlspecialchars($post['organization'] ?? '') ?>">
                </div>
                <div class="form-group" style="align-self:flex-end;padding-bottom:0.6rem;">
                    <label class="check-group">
                        <input type="checkbox" name="is_nonprofit" value="1"
                               <?= !empty($post['is_nonprofit']) ? 'checked' : '' ?>>
                        <span>Nonprofit</span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- Venue -->
    <div class="admin-panel mb-3">
        <div class="admin-panel__header">Venue</div>
        <div class="admin-panel__body">
            <div class="form-group">
                <label class="form-label">Venue Name <span class="text-dim">(optional)</span></label>
                <input type="text" name="venue_name" class="form-input"
                       value="<?= htmlspecialchars($post['venue_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Street Address</label>
                <input type="text" name="venue_address" class="form-input" required
                       value="<?= htmlspecialchars($post['venue_address'] ?? '') ?>">
            </div>
            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label class="form-label">City</label>
                    <input type="text" name="venue_city" class="form-input" required
                           value="<?= htmlspecialchars($post['venue_city'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">State</label>
                    <input type="text" name="venue_state" class="form-input" maxlength="2"
                           value="<?= htmlspecialchars($post['venue_state'] ?? 'AZ') ?>" style="width:70px;">
                </div>
                <div class="form-group">
                    <label class="form-label">Zip</label>
                    <input type="text" name="venue_zip" class="form-input"
                           value="<?= htmlspecialchars($post['venue_zip'] ?? '') ?>" style="width:100px;">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Travel Miles <span class="text-dim">(one-way, optional)</span></label>
                    <input type="number" name="travel_miles" id="inp-miles" class="form-input"
                           step="0.1" min="0"
                           value="<?= htmlspecialchars($post['travel_miles'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Travel Fee ($)</label>
                    <input type="number" name="travel_fee" id="inp-travel" class="form-input"
                           step="0.01" min="0"
                           value="<?= htmlspecialchars($post['travel_fee'] ?? '0') ?>">
                    <p class="form-hint">Free within <?= $travel_config['free_miles_threshold'] ?> mi, then $<?= number_format($travel_config['rate_per_mile'], 2) ?>/mi</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin Notes -->
    <div class="admin-panel mb-3">
        <div class="admin-panel__header">Admin Notes</div>
        <div class="admin-panel__body">
            <textarea name="admin_notes" class="form-input" rows="3"
                      placeholder="Internal notes — not visible to customer"><?= htmlspecialchars($post['admin_notes'] ?? '') ?></textarea>
        </div>
    </div>

</div><!-- /left -->

<!-- ════ RIGHT COLUMN ═══════════════════════════════════════════════ -->
<div>

    <!-- Add-ons -->
    <div class="admin-panel mb-3">
        <div class="admin-panel__header">Add-ons</div>
        <div class="admin-panel__body" id="addons-panel">
            <?php if ($all_addons): ?>
            <div id="addons-list">
                <?php foreach ($all_addons as $addon): ?>
                <label class="addon-admin-row" id="addon-row-<?= $addon['id'] ?>"
                       data-addon-id="<?= $addon['id'] ?>"
                       data-applicable="<?= htmlspecialchars($addon['applicable_attractions'] ?? '') ?>">
                    <input type="checkbox" name="addons[]" value="<?= $addon['id'] ?>"
                           <?= in_array($addon['id'], $post['addons'] ?? [], false) ? 'checked' : '' ?>>
                    <span class="addon-admin-row__name"><?= htmlspecialchars($addon['name']) ?></span>
                    <span class="addon-admin-row__price text-dim text-sm" id="addon-price-<?= $addon['id'] ?>"></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-dim text-sm">No add-ons available.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Coupon -->
    <div class="admin-panel mb-3">
        <div class="admin-panel__header">Coupon</div>
        <div class="admin-panel__body">
            <div class="form-group">
                <label class="form-label">Coupon Code <span class="text-dim">(optional)</span></label>
                <input type="text" name="coupon_code" class="form-input"
                       style="text-transform:uppercase;letter-spacing:.1em;"
                       value="<?= htmlspecialchars($post['coupon_code'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- Payment -->
    <div class="admin-panel mb-3">
        <div class="admin-panel__header">Payment</div>
        <div class="admin-panel__body">
            <div class="form-group">
                <label class="form-label">Payment Option</label>
                <select name="payment_option" class="form-input" id="sel-pay-option">
                    <option value="deposit"       <?= ($post['payment_option'] ?? 'deposit') === 'deposit'       ? 'selected' : '' ?>>Deposit only</option>
                    <option value="full"          <?= ($post['payment_option'] ?? '') === 'full'          ? 'selected' : '' ?>>Paid in full</option>
                    <option value="collect_later" <?= ($post['payment_option'] ?? '') === 'collect_later' ? 'selected' : '' ?>>Collect later</option>
                </select>
            </div>
            <div id="pay-method-row" <?= ($post['payment_option'] ?? '') === 'collect_later' ? 'style="display:none;"' : '' ?>>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-input">
                            <option value="square_pos"    <?= ($post['payment_method'] ?? '') === 'square_pos'    ? 'selected' : '' ?>>Square POS</option>
                            <option value="square_online" <?= ($post['payment_method'] ?? '') === 'square_online' ? 'selected' : '' ?>>Square Online</option>
                            <option value="check"         <?= ($post['payment_method'] ?? '') === 'check'         ? 'selected' : '' ?>>Check</option>
                            <option value="wave_invoice"  <?= ($post['payment_method'] ?? '') === 'wave_invoice'  ? 'selected' : '' ?>>Wave Invoice</option>
                            <option value="other"         <?= ($post['payment_method'] ?? 'other') === 'other'    ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount Received ($) <span class="text-dim">(0 = auto)</span></label>
                        <input type="number" name="amount_paid" class="form-input" step="0.01" min="0"
                               value="<?= htmlspecialchars($post['amount_paid'] ?? '0') ?>"
                               placeholder="Leave 0 to use deposit/full amount">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Price Summary -->
    <div class="admin-panel mb-3">
        <div class="admin-panel__header">Price Summary</div>
        <div class="admin-panel__body">
            <table class="detail-table" id="price-summary-table">
                <tr id="ps-attraction"><td class="text-dim">Attraction</td><td class="text-right" id="ps-attr-val">—</td></tr>
                <tbody id="ps-addon-rows"></tbody>
                <tr id="ps-travel" style="display:none;"><td class="text-dim">Travel Fee</td><td class="text-right" id="ps-travel-val">—</td></tr>
                <tr id="ps-coupon" style="display:none;"><td class="text-dim" id="ps-coupon-label">Discount</td><td class="text-right text-success" id="ps-coupon-val">—</td></tr>
                <tr><td colspan="2" style="padding:4px 0;border-top:1px solid var(--charcoal-lt);"></td></tr>
                <tr><td class="text-dim" id="ps-tax-label">Tax</td><td class="text-right text-dim" id="ps-tax-val">—</td></tr>
                <tr><td colspan="2" style="padding:4px 0;border-top:1px solid var(--charcoal-lt);"></td></tr>
                <tr>
                    <td><strong>Total</strong></td>
                    <td class="text-right"><strong class="text-gold" id="ps-total">—</strong></td>
                </tr>
                <tr id="ps-deposit-row">
                    <td class="text-dim" id="ps-deposit-label">Deposit</td>
                    <td class="text-right" id="ps-deposit-val">—</td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Options -->
    <div class="admin-panel mb-3">
        <div class="admin-panel__header">Options</div>
        <div class="admin-panel__body">
            <label class="check-group">
                <input type="checkbox" name="send_email" value="1"
                       <?= !empty($post['send_email']) ? 'checked' : '' ?> checked>
                <span>Send confirmation email to customer</span>
            </label>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Create Booking</button>

</div><!-- /right -->
</div><!-- /grid -->
</form>

<script>
(function () {
    const attractions = <?= json_encode($js_attractions) ?>;
    const addons      = <?= json_encode($js_addons) ?>;
    const taxRates    = <?= json_encode($js_tax_rates) ?>;
    const APP_URL     = <?= json_encode(APP_URL) ?>;

    function fmt(n) {
        return '$' + Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    const selAttraction = document.getElementById('sel-attraction');
    const inpHours      = document.getElementById('inp-hours');
    const inpTravel     = document.getElementById('inp-travel');
    const inpDate       = document.getElementById('inp-date');
    const inpMiles      = document.getElementById('inp-miles');
    const hoursHint     = document.getElementById('hours-hint');
    const dateWarn      = document.getElementById('date-booked-warn');
    const selPayOpt     = document.getElementById('sel-pay-option');
    const payMethodRow  = document.getElementById('pay-method-row');
    const inpCoupon     = document.querySelector('input[name="coupon_code"]');

    // ── Coupon state ──────────────────────────────────────────────────────────
    // Populated by validateCoupon(); reset to null when code is cleared.
    let activeCoupon = null;  // { type, value, label, code } or null

    // Show/hide payment method row
    selPayOpt.addEventListener('change', function () {
        payMethodRow.style.display = this.value === 'collect_later' ? 'none' : '';
    });

    // Auto-calc travel fee from miles
    inpMiles.addEventListener('input', function () {
        const miles     = parseFloat(this.value) || 0;
        const threshold = <?= (int)$travel_config['free_miles_threshold'] ?>;
        const rate      = <?= (float)$travel_config['rate_per_mile'] ?>;
        const fee       = miles > threshold ? Math.round((miles - threshold) * rate * 100) / 100 : 0;
        inpTravel.value = fee.toFixed(2);
        recalc();
    });

    inpTravel.addEventListener('input', recalc);

    // Warn if date already booked
    inpDate.addEventListener('change', function () {
        const date = this.value;
        if (!date) return;
        fetch(APP_URL + '/booking/calendar.php?month=' + date.substring(0, 7))
            .then(r => r.json())
            .then(data => {
                const status = (data.days || {})[date];
                dateWarn.style.display = status === 'closed' ? '' : 'none';
            })
            .catch(() => {});
    });

    // ── Coupon validation ─────────────────────────────────────────────────────
    // Validate on blur (when leaving the field) or when the user presses Enter.
    function validateCoupon() {
        const code = (inpCoupon.value || '').trim().toUpperCase();
        if (!code) {
            activeCoupon = null;
            recalc();
            return;
        }
        fetch(APP_URL + '/admin/coupon-validate.php?code=' + encodeURIComponent(code))
            .then(r => r.json())
            .then(data => {
                activeCoupon = data.valid ? data : null;
                recalc();
            })
            .catch(() => {
                activeCoupon = null;
                recalc();
            });
    }

    if (inpCoupon) {
        inpCoupon.addEventListener('blur',  validateCoupon);
        inpCoupon.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); validateCoupon(); }
        });
        // Clear coupon state when field is emptied
        inpCoupon.addEventListener('input', function () {
            if (!this.value.trim()) { activeCoupon = null; recalc(); }
        });
    }

    // Attraction change → update hours hint, filter addons, recalc
    selAttraction.addEventListener('change', updateAttractionUI);
    inpHours.addEventListener('input', recalc);

    function updateAttractionUI() {
        const aid  = parseInt(selAttraction.value);
        const attr = attractions[aid];
        if (!attr) {
            hoursHint.textContent = '';
            document.querySelectorAll('.addon-admin-row').forEach(r => r.style.display = '');
            recalc();
            return;
        }

        hoursHint.textContent = 'Min ' + attr.min_hours + 'h, +' + attr.hour_increment + 'h increments';
        if (!inpHours.value || parseFloat(inpHours.value) < attr.min_hours) {
            inpHours.value = attr.min_hours;
        }

        // Filter add-on rows by applicable attractions
        document.querySelectorAll('.addon-admin-row').forEach(row => {
            const addonId  = parseInt(row.dataset.addonId);
            const applicable = addons[addonId]?.applicable;
            row.style.display = (!applicable || applicable.includes(aid)) ? '' : 'none';
            if (row.style.display === 'none') {
                const cb = row.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = false;
            }
        });

        recalc();
    }

    // Add-on checkboxes
    document.querySelectorAll('#addons-list input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', recalc);
    });

    function recalc() {
        const aid    = parseInt(selAttraction.value);
        const attr   = attractions[aid];
        const hours  = parseFloat(inpHours.value) || 0;
        const tFee   = parseFloat(inpTravel.value) || 0;

        if (!attr || hours <= 0) {
            document.getElementById('ps-attr-val').textContent = '—';
            document.getElementById('ps-total').textContent    = '—';
            return;
        }

        // Attraction price
        const extra      = Math.max(0, hours - attr.base_hours);
        const attrPrice  = attr.base_price + extra * attr.hourly_rate;

        document.getElementById('ps-attr-val').textContent = fmt(attrPrice);

        // Add-ons
        const addonRowsEl = document.getElementById('ps-addon-rows');
        addonRowsEl.innerHTML = '';
        let addonsTaxable    = 0;
        let addonsNonTaxable = 0;

        document.querySelectorAll('#addons-list input[type="checkbox"]:checked').forEach(cb => {
            const adid = parseInt(cb.value);
            const ad   = addons[adid];
            if (!ad) return;
            let price = ad.pricing_type === 'flat'
                ? ad.price
                : Math.max(ad.min_charge || 0, ad.price * hours);
            price = Math.round(price * 100) / 100;

            if (ad.is_taxable) addonsTaxable    += price;
            else               addonsNonTaxable += price;

            // Update inline price label
            const priceEl = document.getElementById('addon-price-' + adid);
            if (priceEl) priceEl.textContent = fmt(price);

            const tr = document.createElement('tr');
            tr.innerHTML = '<td class="text-dim" style="padding-left:1rem;">' + ad.name + '</td>'
                         + '<td class="text-right">' + fmt(price) + '</td>';
            addonRowsEl.appendChild(tr);
        });

        // Travel fee
        const travelRow = document.getElementById('ps-travel');
        if (tFee > 0) {
            document.getElementById('ps-travel-val').textContent = fmt(tFee);
            travelRow.style.display = '';
        } else {
            travelRow.style.display = 'none';
        }

        // Add-on subtotals (matches PHP $addons_subtotal which sums BOTH taxable + non-taxable)
        const addonsSubtotal = addonsTaxable + addonsNonTaxable;

        // ── Coupon discount ───────────────────────────────────────────────────
        // Mirrors includes/pricing.php :: calc_coupon_discount() and the discount
        // distribution in build_price_summary(). The discount base depends on
        // applies_to ('attraction' | 'addons' | 'both'); the discount is then
        // applied first to the attraction price, then to taxable add-ons.
        let couponDiscount = 0;
        const couponRow = document.getElementById('ps-coupon');
        if (activeCoupon) {
            let base = 0;
            if      (activeCoupon.applies_to === 'attraction') base = attrPrice;
            else if (activeCoupon.applies_to === 'addons')     base = addonsSubtotal;
            else /* 'both' */                                  base = attrPrice + addonsSubtotal;

            if (activeCoupon.type === 'percent') {
                couponDiscount = Math.round(base * (activeCoupon.value / 100) * 100) / 100;
            } else {
                // flat amount — capped at the base it applies to (server does the same)
                couponDiscount = Math.min(activeCoupon.value, base);
            }
            couponDiscount = Math.round(couponDiscount * 100) / 100;

            document.getElementById('ps-coupon-label').textContent = activeCoupon.label;
            document.getElementById('ps-coupon-val').textContent   = '−' + fmt(couponDiscount);
            couponRow.style.display = '';
        } else {
            couponRow.style.display = 'none';
        }

        // Distribute discount: attraction first, then taxable addons
        // (matches build_price_summary() lines 137-139)
        const discountOnAttraction = Math.min(couponDiscount, attrPrice);
        const discountOnAddons     = Math.max(0, couponDiscount - discountOnAttraction);
        const taxableSubtotal      = Math.max(0,
            (attrPrice - discountOnAttraction) + (addonsTaxable - discountOnAddons)
        );

        // Tax (applied to post-discount taxable subtotal)
        const totalTaxRate = taxRates.reduce((s, r) => s + r, 0);
        const taxAmt       = Math.round(taxableSubtotal * totalTaxRate * 100) / 100;
        document.getElementById('ps-tax-label').textContent = 'Tax (' + (totalTaxRate * 100).toFixed(2) + '%)';
        document.getElementById('ps-tax-val').textContent   = fmt(taxAmt);

        // Grand total
        const grandTotal = taxableSubtotal + addonsNonTaxable + taxAmt + tFee;
        document.getElementById('ps-total').textContent = fmt(grandTotal);

        // Deposit / payment option label
        const payOpt = selPayOpt.value;
        const depEl  = document.getElementById('ps-deposit-row');
        const depLbl = document.getElementById('ps-deposit-label');
        const depVal = document.getElementById('ps-deposit-val');
        if (payOpt === 'collect_later') {
            depEl.style.display = 'none';
        } else if (payOpt === 'full') {
            depLbl.textContent = 'Due today (full)';
            depVal.textContent = fmt(grandTotal);
            depEl.style.display = '';
        } else {
            depLbl.textContent = 'Deposit due today';
            depVal.textContent = fmt(attr.deposit);
            depEl.style.display = '';
        }
    }

    selPayOpt.addEventListener('change', recalc);

    // Init — if a coupon code is pre-filled (form re-display after error), validate it
    updateAttractionUI();
    recalc();
    if (inpCoupon && inpCoupon.value.trim()) { validateCoupon(); }
})();
</script>

<?php render_admin_footer(); ?>
