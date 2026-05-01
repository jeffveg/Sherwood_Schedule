<?php
/**
 * Admin — Single Booking Detail
 * View, update status, record payments, add notes, cancel.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/email_templates.php';

admin_require_login();
date_default_timezone_set(APP_TIMEZONE);

$db = get_db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/admin/bookings.php'); exit; }

// Load booking
$stmt = $db->prepare(
    "SELECT b.*, a.name AS attraction_name, a.deposit_amount AS attraction_deposit,
            c.first_name, c.last_name, c.email, c.phone, c.organization, c.is_nonprofit,
            c.id AS customer_id
     FROM bookings b
     JOIN attractions a ON a.id = b.attraction_id
     JOIN customers c ON c.id = b.customer_id
     WHERE b.id = ?"
);
$stmt->execute([$id]);
$booking = $stmt->fetch();
if (!$booking) { header('Location: ' . APP_URL . '/admin/bookings.php'); exit; }

// Load add-ons
$addon_rows = $db->prepare('SELECT * FROM booking_addons WHERE booking_id = ?');
$addon_rows->execute([$id]);
$addons = $addon_rows->fetchAll();

// Load payment history
$pay_stmt = $db->prepare(
    'SELECT * FROM payments WHERE booking_id = ? ORDER BY created_at ASC'
);
$pay_stmt->execute([$id]);
$payments = $pay_stmt->fetchAll();

$flash   = '';
$flash_type = 'success';
$errors  = [];

// ── POST Actions ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Update admin notes
    if ($action === 'notes') {
        $notes = trim($_POST['admin_notes'] ?? '');
        $db->prepare('UPDATE bookings SET admin_notes = ?, updated_at = NOW() WHERE id = ?')
           ->execute([$notes ?: null, $id]);
        $flash = 'Notes saved.';
        $booking['admin_notes'] = $notes;
    }

    // Update booking status
    elseif ($action === 'status') {
        $new_status = $_POST['booking_status'] ?? '';
        $allowed    = ['pending','confirmed','cancelled','completed','rescheduled'];
        if (in_array($new_status, $allowed, true)) {
            $extra        = '';
            $extra_params = [];
            $cancel_reason = null;
            $cancel_fee    = 0;
            if ($new_status === 'cancelled') {
                $cancel_reason = trim($_POST['cancellation_reason'] ?? '') ?: null;
                $cancel_fee    = (float)($_POST['cancellation_fee'] ?? 0);
                $extra = ', cancelled_at = NOW(), cancellation_reason = ?';
                $extra_params[] = $cancel_reason;
                if ($cancel_fee > 0) {
                    $extra .= ', cancellation_fee = ?';
                    $extra_params[] = $cancel_fee;
                }
            }
            $db->prepare("UPDATE bookings SET booking_status = ?, updated_at = NOW(){$extra} WHERE id = ?")
               ->execute(array_merge([$new_status], $extra_params, [$id]));
            $flash = 'Booking status updated.';
            $booking['booking_status'] = $new_status;

            // Optionally notify customer of cancellation
            if ($new_status === 'cancelled' && !empty($_POST['notify_customer'])) {
                $cust_row = $db->prepare('SELECT * FROM customers WHERE id = ?');
                $cust_row->execute([$booking['customer_id']]);
                $cust = $cust_row->fetch();
                if ($cust) {
                    $booking['cancellation_reason'] = $cancel_reason;
                    $booking['cancellation_fee']    = $cancel_fee;
                    $sent = send_cancellation_confirmation($booking, $cust, $booking['attraction_name']);
                    $flash .= $sent ? ' Cancellation email sent to customer.' : ' (Email failed — check mail config.)';
                }
            }
        }
    }

    // Reschedule booking (admin-initiated)
    elseif ($action === 'reschedule') {
        $new_date = trim($_POST['new_event_date'] ?? '');
        $new_time = trim($_POST['new_start_time'] ?? '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date) && preg_match('/^\d{2}:\d{2}$/', $new_time)) {
            $end_ts  = strtotime($new_date . ' ' . $new_time) + (int)round($booking['duration_hours'] * 3600);
            $new_end = date('H:i:s', $end_ts);

            $db->prepare(
                "UPDATE bookings SET event_date = ?, start_time = ?, end_time = ?,
                 booking_status = 'confirmed', updated_at = NOW() WHERE id = ?"
            )->execute([$new_date, $new_time . ':00', $new_end, $id]);

            $flash = 'Booking rescheduled to '
                   . date('M j, Y', strtotime($new_date)) . ' at '
                   . date('g:i A', strtotime('2000-01-01 ' . $new_time)) . '.';

            // Reload booking
            $stmt->execute([$id]);
            $booking = $stmt->fetch();

            // Optionally notify customer
            if (!empty($_POST['notify_customer'])) {
                $cust_row = $db->prepare('SELECT * FROM customers WHERE id = ?');
                $cust_row->execute([$booking['customer_id']]);
                $cust = $cust_row->fetch();
                if ($cust) {
                    $sent = send_reschedule_confirmation($booking, $cust, $booking['attraction_name']);
                    $flash .= $sent ? ' Reschedule email sent to customer.' : ' (Email failed — check mail config.)';
                }
            }
        } else {
            $flash_type = 'danger';
            $flash = 'Please provide a valid date and time.';
        }
    }

    // Record manual payment
    elseif ($action === 'payment') {
        $amount  = (float)($_POST['pay_amount'] ?? 0);
        $method  = $_POST['pay_method'] ?? '';
        $type    = $_POST['pay_type'] ?? 'balance';
        $notes   = trim($_POST['pay_notes'] ?? '');
        $allowed_methods = ['square_online','square_pos','check','wave_invoice','other'];
        $allowed_types   = ['deposit','balance','cancellation_fee','refund'];

        if ($amount <= 0)                              $errors[] = 'Amount must be greater than 0.';
        if (!in_array($method, $allowed_methods, true)) $errors[] = 'Invalid payment method.';
        if (!in_array($type,   $allowed_types,   true)) $errors[] = 'Invalid payment type.';

        if (!$errors) {
            $db->prepare(
                "INSERT INTO payments (booking_id, payment_type, amount, payment_method, status, notes, paid_at)
                 VALUES (?, ?, ?, ?, 'completed', ?, NOW())"
            )->execute([$id, $type, $amount, $method, $notes ?: null]);

            // Update booking amount_paid / balance_due
            $new_paid    = round($booking['amount_paid'] + $amount, 2);
            $new_balance = round($booking['grand_total'] - $new_paid, 2);
            $pay_status  = $new_balance <= 0.01 ? 'paid_in_full' : 'deposit_paid';
            if ($type === 'refund') {
                $new_paid    = round($booking['amount_paid'] - $amount, 2);
                $new_balance = round($booking['grand_total'] - $new_paid, 2);
                $pay_status  = 'refunded';
            }

            $db->prepare(
                'UPDATE bookings SET amount_paid = ?, balance_due = ?, payment_status = ?, updated_at = NOW() WHERE id = ?'
            )->execute([$new_paid, max(0, $new_balance), $pay_status, $id]);

            $flash = 'Payment of $' . number_format($amount, 2) . ' recorded.';

            // Reload booking
            $stmt->execute([$id]);
            $booking = $stmt->fetch();
            $pay_stmt->execute([$id]);
            $payments = $pay_stmt->fetchAll();
        } else {
            $flash_type = 'danger';
            $flash = implode(' ', $errors);
        }
    }

    // Delete a manual payment record and recalc totals
    elseif ($action === 'delete_payment') {
        $pay_id = (int)($_POST['payment_id'] ?? 0);
        if ($pay_id) {
            $prow = $db->prepare('SELECT * FROM payments WHERE id = ? AND booking_id = ?');
            $prow->execute([$pay_id, $id]);
            $p = $prow->fetch();
            if ($p) {
                $db->prepare('DELETE FROM payments WHERE id = ?')->execute([$pay_id]);

                // Recalc from remaining payments
                $sum_stmt = $db->prepare(
                    "SELECT COALESCE(SUM(CASE WHEN payment_type='refund' THEN -amount ELSE amount END), 0)
                     FROM payments WHERE booking_id = ? AND status = 'completed'"
                );
                $sum_stmt->execute([$id]);
                $new_paid    = round((float)$sum_stmt->fetchColumn(), 2);
                $new_balance = max(0, round($booking['grand_total'] - $new_paid, 2));
                $pay_status  = $new_balance <= 0.01 ? 'paid_in_full' : ($new_paid > 0 ? 'deposit_paid' : 'unpaid');

                $db->prepare(
                    'UPDATE bookings SET amount_paid = ?, balance_due = ?, payment_status = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$new_paid, $new_balance, $pay_status, $id]);

                $flash = 'Payment record deleted.';
                $stmt->execute([$id]);
                $booking = $stmt->fetch();
                $pay_stmt->execute([$id]);
                $payments = $pay_stmt->fetchAll();
            }
        }
    }

    // Generate Square balance payment link and email it
    elseif ($action === 'balance_link') {
        $balance_due = (float)$booking['balance_due'];
        if ($balance_due <= 0) {
            $flash_type = 'danger';
            $flash = 'No balance due on this booking.';
        } else {
            $square_base = (SQUARE_ENVIRONMENT === 'production')
                ? 'https://connect.squareup.com'
                : 'https://connect.squareupsandbox.com';

            $amount_cents = (int)round($balance_due * 100);
            $description  = $booking['attraction_name'] . ' — Balance — ' . date('M j, Y', strtotime($booking['event_date']));

            $payload = [
                'idempotency_key' => 'bal-' . $booking['booking_ref'] . '-' . time(),
                'order' => [
                    'location_id' => SQUARE_LOCATION_ID,
                    'line_items'  => [[
                        'name'             => $description,
                        'quantity'         => '1',
                        'base_price_money' => ['amount' => $amount_cents, 'currency' => 'USD'],
                    ]],
                    'reference_id' => $booking['booking_ref'],
                ],
                'checkout_options' => [
                    'redirect_url'             => APP_URL . '/booking/confirm.php?ref=' . urlencode($booking['booking_ref']) . '&type=balance',
                    'ask_for_shipping_address' => false,
                ],
                'pre_populated_data' => ['buyer_email' => $booking['email']],
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
                $flash_type = 'danger';
                $flash = 'Could not create Square payment link. Check server logs.';
                error_log('Square balance link error (' . $http_code . '): ' . ($curl_err ?: $response));
            } else {
                $data        = json_decode($response, true);
                $payment_url = $data['payment_link']['url'] ?? '';

                if (!$payment_url) {
                    $flash_type = 'danger';
                    $flash = 'Square returned no payment URL.';
                } else {
                    // Save to payments table as pending
                    $db->prepare(
                        'INSERT INTO payments (booking_id, payment_type, amount, payment_method,
                         square_payment_link_id, square_order_id, status)
                         VALUES (?, "balance", ?, "square_online", ?, ?, "pending")'
                    )->execute([
                        $id, $balance_due,
                        $data['payment_link']['id'] ?? null,
                        $data['payment_link']['order_id'] ?? null,
                    ]);

                    // Email the customer
                    $cust_row = $db->prepare('SELECT * FROM customers WHERE id = ?');
                    $cust_row->execute([$booking['customer_id']]);
                    $cust = $cust_row->fetch();

                    $booking['balance_payment_url'] = $payment_url;
                    $sent = $cust ? send_balance_payment_link($booking, $cust, $booking['attraction_name'], $payment_url) : false;

                    $db->prepare('UPDATE bookings SET balance_link_sent = 1, updated_at = NOW() WHERE id = ?')->execute([$id]);

                    $flash = 'Payment link created' . ($sent ? ' and emailed to ' . htmlspecialchars($booking['email']) : ' (email failed — copy link below)') . '.';
                    $flash_type = $sent ? 'success' : 'warning';

                    // Store URL in session so we can display it after redirect
                    $_SESSION['balance_payment_url'] = $payment_url;

                    $stmt->execute([$id]);
                    $booking = $stmt->fetch();
                    $pay_stmt->execute([$id]);
                    $payments = $pay_stmt->fetchAll();
                }
            }
        }
    }

    // Resend confirmation email
    elseif ($action === 'resend_email') {
        $cust_row = $db->prepare('SELECT * FROM customers WHERE id = ?');
        $cust_row->execute([$booking['customer_id']]);
        $cust = $cust_row->fetch();

        $addon_rows2 = $db->prepare('SELECT * FROM booking_addons WHERE booking_id = ?');
        $addon_rows2->execute([$id]);
        $addon_lines = $addon_rows2->fetchAll();

        if ($cust && send_booking_confirmation($booking, $cust, $addon_lines, $booking['attraction_name'])) {
            $db->prepare('UPDATE bookings SET confirmation_sent = 1 WHERE id = ?')->execute([$id]);
            $flash = 'Confirmation email sent to ' . htmlspecialchars($cust['email']) . '.';
        } else {
            $flash_type = 'danger';
            $flash = 'Failed to send email. Check server mail configuration.';
        }
    }

    // Override travel fee
    elseif ($action === 'travel_fee') {
        $fee = (float)($_POST['travel_fee'] ?? 0);
        // Recalc totals
        $old_fee    = (float)$booking['travel_fee'];
        $diff       = round($fee - $old_fee, 2);
        $new_total  = round($booking['grand_total'] + $diff, 2);
        $new_balance = max(0, round($new_total - $booking['amount_paid'], 2));
        $db->prepare(
            'UPDATE bookings SET travel_fee = ?, travel_fee_overridden = 1,
             grand_total = ?, balance_due = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$fee, $new_total, $new_balance, $id]);
        $flash = 'Travel fee updated.';
        $stmt->execute([$id]);
        $booking = $stmt->fetch();
    }
}

$event_date  = date('l, F j, Y', strtotime($booking['event_date']));
$event_time  = date('g:i A', strtotime($booking['start_time']));
$end_time    = date('g:i A', strtotime($booking['end_time']));

// Pick up balance payment URL from session (set after link creation)
$balance_payment_url = $_SESSION['balance_payment_url'] ?? '';
unset($_SESSION['balance_payment_url']);

render_admin_header('Booking ' . $booking['booking_ref'], 'bookings');
?>

<!-- Back link -->
<p style="margin-bottom:1.25rem;">
    <a href="<?= APP_URL ?>/admin/bookings.php" class="text-dim text-sm">&larr; All Bookings</a>
</p>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash_type ?> mb-3"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Header row -->
<div class="d-flex align-center justify-between flex-wrap gap-2 mb-3">
    <div>
        <h2 style="font-size:1.5rem;margin:0;"><?= htmlspecialchars($booking['booking_ref']) ?></h2>
        <p class="text-dim text-sm mt-1">Created <?= date('M j, Y g:i A', strtotime($booking['created_at'])) ?></p>
    </div>
    <div class="d-flex gap-1 flex-wrap">
        <?= booking_status_badge($booking['booking_status']) ?>
        <?= payment_status_badge($booking['payment_status']) ?>
    </div>
</div>

<div class="admin-detail-grid">

    <!-- LEFT COLUMN -->
    <div>

        <!-- Event Details -->
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Event Details</div>
            <div class="admin-panel__body">
                <table class="detail-table">
                    <tr><td>Activity</td><td><?= htmlspecialchars($booking['attraction_name']) ?></td></tr>
                    <tr><td>Date</td><td><?= $event_date ?></td></tr>
                    <tr><td>Time</td><td><?= $event_time ?> – <?= $end_time ?></td></tr>
                    <tr><td>Duration</td><td><?= $booking['duration_hours'] ?> hour<?= $booking['duration_hours'] != 1 ? 's' : '' ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Venue -->
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Venue</div>
            <div class="admin-panel__body">
                <table class="detail-table">
                    <?php if ($booking['venue_name']): ?>
                    <tr><td>Name</td><td><?= htmlspecialchars($booking['venue_name']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td>Address</td><td><?= htmlspecialchars($booking['venue_address']) ?></td></tr>
                    <tr><td>City</td><td><?= htmlspecialchars($booking['venue_city'] . ', ' . $booking['venue_state'] . ' ' . $booking['venue_zip']) ?></td></tr>
                    <?php if ($booking['travel_miles']): ?>
                    <tr><td>Distance</td><td><?= number_format($booking['travel_miles'], 1) ?> mi (one-way)</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Customer -->
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Customer</div>
            <div class="admin-panel__body">
                <table class="detail-table">
                    <tr><td>Name</td><td><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></td></tr>
                    <tr><td>Email</td><td><a href="mailto:<?= htmlspecialchars($booking['email']) ?>"><?= htmlspecialchars($booking['email']) ?></a></td></tr>
                    <tr><td>Phone</td><td><a href="tel:<?= htmlspecialchars($booking['phone']) ?>"><?= htmlspecialchars($booking['phone']) ?></a></td></tr>
                    <?php if ($booking['organization']): ?>
                    <tr><td>Organization</td><td><?= htmlspecialchars($booking['organization']) ?><?= $booking['is_nonprofit'] ? ' <span class="badge badge-info">Nonprofit</span>' : '' ?></td></tr>
                    <?php endif; ?>
                    <?php if ($booking['call_time_pref']): ?>
                    <tr><td>Best Call Time</td><td><?= htmlspecialchars($booking['call_time_pref']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Event Notes -->
        <?php if ($booking['tournament_bracket'] || $booking['event_notes'] || $booking['allow_publish'] !== null): ?>
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Event Notes</div>
            <div class="admin-panel__body">
                <table class="detail-table">
                    <?php if ($booking['tournament_bracket'] && $booking['tournament_bracket'] !== 'No'): ?>
                    <tr><td>Tournament</td><td><?= htmlspecialchars($booking['tournament_bracket']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($booking['allow_publish'] !== null): ?>
                    <tr><td>Allow Publish</td><td><?= $booking['allow_publish'] ? 'Yes' : 'No' ?></td></tr>
                    <?php endif; ?>
                    <?php if ($booking['allow_advertise'] !== null): ?>
                    <tr><td>Allow Advertise</td><td><?= $booking['allow_advertise'] ? 'Yes' : 'No' ?></td></tr>
                    <?php endif; ?>
                    <?php if ($booking['event_notes']): ?>
                    <tr><td>Notes</td><td><?= nl2br(htmlspecialchars($booking['event_notes'])) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Pricing -->
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Pricing</div>
            <div class="admin-panel__body">
                <table class="detail-table">
                    <tr><td><?= htmlspecialchars($booking['attraction_name']) ?></td><td class="text-right">$<?= number_format($booking['attraction_price'], 2) ?></td></tr>
                    <?php foreach ($addons as $addon): ?>
                    <tr><td><?= htmlspecialchars($addon['addon_name']) ?> ×<?= $addon['quantity'] ?></td><td class="text-right">$<?= number_format($addon['total_price'], 2) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($booking['travel_fee'] > 0): ?>
                    <tr><td>Travel Fee<?= $booking['travel_fee_overridden'] ? ' <span class="badge badge-warning">overridden</span>' : '' ?></td><td class="text-right">$<?= number_format($booking['travel_fee'], 2) ?></td></tr>
                    <?php elseif (!$booking['travel_fee'] && !$booking['travel_miles']): ?>
                    <tr><td class="text-dim">Travel Fee</td><td class="text-right text-dim">TBD</td></tr>
                    <?php endif; ?>
                    <?php if ($booking['coupon_discount'] > 0): ?>
                    <tr><td class="text-success">Discount (<?= htmlspecialchars($booking['coupon_code']) ?>)</td><td class="text-right text-success">−$<?= number_format($booking['coupon_discount'], 2) ?></td></tr>
                    <?php endif; ?>
                    <tr style="border-top:1px solid var(--charcoal-lt);"><td class="text-dim">Tax (<?= round($booking['tax_rate'] * 100, 2) ?>%)</td><td class="text-right text-dim">$<?= number_format($booking['tax_total'], 2) ?></td></tr>
                    <tr><td><strong>Grand Total</strong></td><td class="text-right"><strong class="text-gold">$<?= number_format($booking['grand_total'], 2) ?></strong></td></tr>
                    <tr><td>Amount Paid</td><td class="text-right text-success">$<?= number_format($booking['amount_paid'], 2) ?></td></tr>
                    <?php if ($booking['balance_due'] > 0): ?>
                    <tr><td><strong>Balance Due</strong></td><td class="text-right text-orange"><strong>$<?= number_format($booking['balance_due'], 2) ?></strong></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Payment History -->
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Payment History</div>
            <div class="admin-panel__body">
                <?php if ($payments): ?>
                <table class="detail-table">
                    <thead><tr>
                        <th>Date</th><th>Type</th><th>Method</th><th>Status</th><th class="text-right">Amount</th><th></th>
                    </tr></thead>
                    <?php foreach ($payments as $p):
                        // Status badge: pending = orange (link sent, awaiting customer payment),
                        // completed = green, failed/refunded = gray/red
                        $status_class = match($p['status']) {
                            'completed' => 'badge-success',
                            'pending'   => 'badge-warning',
                            'failed'    => 'badge-danger',
                            default     => 'badge-default',
                        };
                        $is_pending = ($p['status'] !== 'completed');
                    ?>
                    <tr<?= $is_pending ? ' style="opacity:0.65;"' : '' ?>>
                        <td class="text-sm"><?= $p['paid_at'] ? date('M j, Y', strtotime($p['paid_at'])) : '—' ?></td>
                        <?php $type_labels = ['deposit'=>'Deposit','balance'=>'Payment','cancellation_fee'=>'Cancellation Fee','refund'=>'Refund']; ?>
                        <td><?= $type_labels[$p['payment_type']] ?? ucfirst(str_replace('_',' ',$p['payment_type'])) ?></td>
                        <td class="text-sm text-dim"><?= ucfirst(str_replace('_',' ',$p['payment_method'])) ?></td>
                        <td><span class="badge <?= $status_class ?>"><?= ucfirst($p['status']) ?></span></td>
                        <td class="text-right <?= $p['payment_type'] === 'refund' ? 'text-danger' : ($is_pending ? 'text-dim' : 'text-success') ?>">
                            <?= $p['payment_type'] === 'refund' ? '−' : '' ?>$<?= number_format($p['amount'], 2) ?>
                        </td>
                        <td>
                            <?php if (!$p['square_payment_id']): ?>
                            <form method="POST" onsubmit="return confirm('Delete this payment record?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_payment">
                                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:2px 6px;">✕</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($p['square_payment_id']): ?>
                    <tr>
                        <td colspan="6" class="text-xs text-dim" style="padding-bottom:6px;">
                            Square Txn: <code><?= htmlspecialchars($p['square_payment_id']) ?></code>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </table>
                <?php else: ?>
                <p class="text-dim text-sm">No payments recorded yet.</p>
                <?php endif; ?>

                <!-- Record Payment -->
                <details class="mt-3" <?= !empty($_POST['action']) && $_POST['action'] === 'payment' ? 'open' : '' ?>>
                    <summary class="text-gold" style="cursor:pointer;font-size:0.875rem;font-weight:700;">+ Record Payment</summary>
                    <form method="POST" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="payment">
                        <div class="form-row" style="gap:0.5rem;">
                            <div class="form-group">
                                <label class="form-label">Amount ($)</label>
                                <input type="number" name="pay_amount" class="form-input" step="0.01" min="0.01" required
                                       value="<?= htmlspecialchars($_POST['pay_amount'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Type</label>
                                <select name="pay_type" class="form-input">
                                    <option value="deposit">Deposit</option>
                                    <option value="balance">Payment</option>
                                    <option value="cancellation_fee">Cancellation Fee</option>
                                    <option value="refund">Refund</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Method</label>
                            <select name="pay_method" class="form-input">
                                <option value="square_pos">Square POS</option>
                                <option value="square_online">Square Online</option>
                                <option value="check">Check</option>
                                <option value="wave_invoice">Wave Invoice</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Notes (optional)</label>
                            <input type="text" name="pay_notes" class="form-input" value="<?= htmlspecialchars($_POST['pay_notes'] ?? '') ?>">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Record</button>
                    </form>
                </details>
            </div>
        </div>

    </div><!-- /left -->

    <!-- RIGHT COLUMN -->
    <div>

        <!-- Admin Notes -->
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Admin Notes</div>
            <div class="admin-panel__body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="notes">
                    <textarea name="admin_notes" class="form-input" rows="4"
                              placeholder="Internal notes — not visible to customer"><?= htmlspecialchars($booking['admin_notes'] ?? '') ?></textarea>
                    <button type="submit" class="btn btn-secondary btn-sm mt-2">Save Notes</button>
                </form>
            </div>
        </div>

        <!-- Booking Status -->
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Update Status</div>
            <div class="admin-panel__body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <div class="form-group">
                        <label class="form-label">Booking Status</label>
                        <select name="booking_status" class="form-input" id="statusSelect">
                            <?php foreach (['pending','confirmed','completed','cancelled','rescheduled'] as $s): ?>
                            <option value="<?= $s ?>" <?= $booking['booking_status'] === $s ? 'selected' : '' ?>>
                                <?= ucfirst($s) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="cancelFields" style="display:none;">
                        <div class="form-group">
                            <label class="form-label">Cancellation Reason</label>
                            <textarea name="cancellation_reason" class="form-input" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cancellation Fee ($) <span class="text-dim">(0 = none)</span></label>
                            <?php
                            // Only pre-fill the stored fee if the booking is actually
                            // cancelled — otherwise default to 0 so admin doesn't see
                            // a stale number from a prior (reverted) cancellation.
                            $cf_value = $booking['booking_status'] === 'cancelled'
                                ? number_format((float)$booking['cancellation_fee'], 2)
                                : '0.00';
                            ?>
                            <input type="number" name="cancellation_fee" class="form-input" step="0.01" min="0"
                                   value="<?= $cf_value ?>">
                        </div>
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.875rem;">
                                <input type="checkbox" name="notify_customer" value="1" checked>
                                Email cancellation notice to customer
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">Update Status</button>
                </form>
            </div>
        </div>

        <!-- Reschedule Booking -->
        <?php if (!in_array($booking['booking_status'], ['cancelled', 'rescheduled'])): ?>
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Reschedule Booking</div>
            <div class="admin-panel__body">
                <form method="POST"
                      onsubmit="return confirm('Reschedule this booking to the new date and time?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reschedule">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">New Date</label>
                            <input type="date" name="new_event_date" class="form-input" required
                                   value="<?= htmlspecialchars($booking['event_date']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Start Time</label>
                            <input type="time" name="new_start_time" class="form-input" step="300" required
                                   value="<?= htmlspecialchars(substr($booking['start_time'], 0, 5)) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.875rem;">
                            <input type="checkbox" name="notify_customer" value="1" checked>
                            Email reschedule confirmation to customer
                        </label>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">Reschedule Booking</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Travel Fee Override -->
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Travel Fee Override</div>
            <div class="admin-panel__body">
                <p class="text-dim text-sm mb-2">
                    Current: <?= $booking['travel_miles'] ? number_format($booking['travel_miles'], 1) . ' mi → ' : 'distance unknown → ' ?>
                    <strong>$<?= number_format($booking['travel_fee'], 2) ?></strong>
                    <?= $booking['travel_fee_overridden'] ? '<span class="badge badge-warning">manually set</span>' : '' ?>
                </p>
                <form method="POST" class="d-flex gap-1 align-center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="travel_fee">
                    <input type="number" name="travel_fee" class="form-input" step="0.01" min="0"
                           value="<?= number_format($booking['travel_fee'], 2) ?>" style="max-width:140px;">
                    <button type="submit" class="btn btn-secondary btn-sm">Set Fee</button>
                </form>
            </div>
        </div>

        <!-- Email -->
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Confirmation Email</div>
            <div class="admin-panel__body">
                <p class="text-dim text-sm mb-2">
                    <?php if ($booking['confirmation_sent']): ?>
                        <span class="text-success">&#10003; Email sent</span>
                    <?php else: ?>
                        <span class="text-orange">Not yet sent</span>
                    <?php endif; ?>
                    &mdash; will be sent to <strong><?= htmlspecialchars($booking['email']) ?></strong>
                </p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend_email">
                    <button type="submit" class="btn btn-ghost btn-sm">
                        <?= $booking['confirmation_sent'] ? 'Resend Email' : 'Send Email' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Balance Payment Link -->
        <?php if ($booking['balance_due'] > 0): ?>
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">Collect Balance — $<?= number_format($booking['balance_due'], 2) ?></div>
            <div class="admin-panel__body">
                <?php if ($balance_payment_url): ?>
                <div class="alert alert-success mb-2" style="word-break:break-all;">
                    <strong>Payment link:</strong><br>
                    <a href="<?= htmlspecialchars($balance_payment_url) ?>" target="_blank" style="color:var(--gold);">
                        <?= htmlspecialchars($balance_payment_url) ?>
                    </a>
                </div>
                <?php endif; ?>
                <p class="text-dim text-sm mb-2">
                    Creates a Square payment link for the remaining balance and emails it to
                    <strong><?= htmlspecialchars($booking['email']) ?></strong>.
                </p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="balance_link">
                    <button type="submit" class="btn btn-primary btn-sm">Send Balance Link</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- WaveApps -->
        <?php if ($booking['wave_invoice_id']): ?>
        <div class="admin-panel mb-3">
            <div class="admin-panel__header">WaveApps</div>
            <div class="admin-panel__body">
                <p class="text-sm">Invoice ID: <code class="text-gold"><?= htmlspecialchars($booking['wave_invoice_id']) ?></code></p>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /right -->

</div><!-- /grid -->

<script>
const sel = document.getElementById('statusSelect');
const cancelFields = document.getElementById('cancelFields');
function toggleCancel() {
    cancelFields.style.display = sel.value === 'cancelled' ? 'block' : 'none';
}
sel.addEventListener('change', toggleCancel);
toggleCancel();
</script>

<?php render_admin_footer(); ?>
