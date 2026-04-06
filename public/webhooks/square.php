<?php
/**
 * Square Webhook Handler
 * Receives payment events from Square and updates booking payment status.
 *
 * Register this URL in the Square Developer Dashboard:
 *   https://schedule.sherwoodadventure.com/webhooks/square.php
 *
 * Subscribe to these event types:
 *   - payment.completed
 *   - payment.updated
 *   - order.updated
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/email_templates.php';

// ── Logging helper ────────────────────────────────────────────────────────────
function log_webhook(string $status, string $event_type = '', string $event_id = '',
                     string $payment_id = '', string $booking_ref = '',
                     string $notes = '', string $payload = ''): void {
    try {
        $db = get_db();
        $db->prepare(
            'INSERT INTO webhook_events
             (event_type, square_event_id, payment_id, booking_ref, status, notes, raw_payload)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $event_type  ?: null,
            $event_id    ?: null,
            $payment_id  ?: null,
            $booking_ref ?: null,
            $status,
            $notes       ?: null,
            $payload ? substr($payload, 0, 5000) : null,
        ]);
    } catch (Throwable $e) {
        error_log('Webhook log failed: ' . $e->getMessage());
    }
}

// ── Verify Square signature ────────────────────────────────────────────────
$raw_body   = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '';
$url        = APP_URL . '/webhooks/square.php';

$expected = base64_encode(hash_hmac('sha256', $url . $raw_body, SQUARE_WEBHOOK_SIGNATURE_KEY, true));

if (!hash_equals($expected, $sig_header)) {
    log_webhook('sig_failed', '', '', '', '', 'Signature mismatch', substr($raw_body, 0, 500));
    http_response_code(403);
    exit('Forbidden');
}

// ── Parse event ───────────────────────────────────────────────────────────
$event = json_decode($raw_body, true);
if (!$event) {
    log_webhook('error', '', '', '', '', 'Invalid JSON payload', substr($raw_body, 0, 500));
    http_response_code(400);
    exit('Bad Request');
}

$event_type     = $event['type'] ?? '';
$square_event_id = $event['event_id'] ?? '';
$db = get_db();

// ── Duplicate detection ───────────────────────────────────────────────────
if ($square_event_id) {
    $dup = $db->prepare(
        "SELECT id FROM webhook_events WHERE square_event_id = ? AND status = 'processed' LIMIT 1"
    );
    $dup->execute([$square_event_id]);
    if ($dup->fetch()) {
        log_webhook('duplicate', $event_type, $square_event_id, '', '', 'Already processed');
        http_response_code(200);
        echo 'OK';
        exit;
    }
}

// ── Helper: update booking payment status ─────────────────────────────────
function update_booking_payment(PDO $db, string $order_id, string $status, float $amount): ?string {
    // Find the payment record by Square order ID
    $stmt = $db->prepare(
        'SELECT p.id, p.booking_id, p.payment_type, b.grand_total, b.booking_ref
         FROM payments p
         JOIN bookings b ON b.id = p.booking_id
         WHERE p.square_order_id = ?
         LIMIT 1'
    );
    $stmt->execute([$order_id]);
    $payment = $stmt->fetch();
    if (!$payment) return null;

    $booking_id  = $payment['booking_id'];
    $booking_ref = $payment['booking_ref'];

    // ── Wrap all DB mutations in a transaction ────────────────────────────
    // Without this, a crash mid-way could leave the payment marked 'completed'
    // but the booking still 'pending' (or vice versa), requiring manual cleanup.
    $db->beginTransaction();
    try {
        // Update the individual payment record
        $db->prepare(
            'UPDATE payments SET status = ?, amount = ?, paid_at = NOW() WHERE id = ?'
        )->execute([$status, $amount, $payment['id']]);

        if ($status === 'completed') {
            // Recalculate totals from ALL completed payments for this booking
            // (handles partial deposits + balance payments correctly)
            $sum_stmt = $db->prepare(
                "SELECT COALESCE(SUM(CASE WHEN payment_type='refund' THEN -amount ELSE amount END), 0)
                 FROM payments WHERE booking_id = ? AND status = 'completed'"
            );
            $sum_stmt->execute([$booking_id]);
            $amount_paid = round((float)$sum_stmt->fetchColumn(), 2);

            $grand_total    = (float)$payment['grand_total'];
            $balance_due    = max(0, round($grand_total - $amount_paid, 2));
            $payment_status = $balance_due <= 0.01 ? 'paid_in_full' : 'deposit_paid';

            $db->prepare(
                'UPDATE bookings SET payment_status = ?, amount_paid = ?, balance_due = ?,
                 booking_status = "confirmed", updated_at = NOW()
                 WHERE id = ?'
            )->execute([$payment_status, $amount_paid, $balance_due, $booking_id]);
        }

        $db->commit();

    } catch (Throwable $e) {
        $db->rollBack();
        error_log('update_booking_payment transaction failed for order ' . $order_id . ': ' . $e->getMessage());
        return null;
    }

    // ── Send confirmation email (outside transaction — email failure should
    //    not roll back the already-committed payment record) ────────────────
    if ($status === 'completed') {
        $chk = $db->prepare('SELECT confirmation_sent, customer_id, attraction_id FROM bookings WHERE id = ?');
        $chk->execute([$booking_id]);
        $brow = $chk->fetch();

        if ($brow && !$brow['confirmation_sent']) {
            $bstmt = $db->prepare(
                'SELECT b.*, a.name AS attraction_name
                 FROM bookings b JOIN attractions a ON a.id = b.attraction_id
                 WHERE b.id = ?'
            );
            $bstmt->execute([$booking_id]);
            $full_booking = $bstmt->fetch();

            $cstmt = $db->prepare('SELECT * FROM customers WHERE id = ?');
            $cstmt->execute([$brow['customer_id']]);
            $customer = $cstmt->fetch();

            $addon_stmt = $db->prepare('SELECT * FROM booking_addons WHERE booking_id = ?');
            $addon_stmt->execute([$booking_id]);
            $addon_lines = $addon_stmt->fetchAll();

            if ($full_booking && $customer) {
                $sent = send_booking_confirmation($full_booking, $customer, $addon_lines, $full_booking['attraction_name']);
                if ($sent) {
                    $db->prepare('UPDATE bookings SET confirmation_sent = 1 WHERE id = ?')->execute([$booking_id]);
                }
                send_admin_booking_notification($full_booking, $customer, $full_booking['attraction_name']);
            }
        }
    }

    return $booking_ref;
}

// ── Route event ───────────────────────────────────────────────────────────
switch ($event_type) {
    case 'payment.completed':
    case 'payment.updated':
        $payment_obj = $event['data']['object']['payment'] ?? null;
        if (!$payment_obj) {
            log_webhook('error', $event_type, $square_event_id, '', '', 'No payment object in payload', $raw_body);
            break;
        }

        $order_id   = $payment_obj['order_id'] ?? '';
        $payment_id = $payment_obj['id'] ?? '';
        $sq_status  = ($payment_obj['status'] ?? '') === 'COMPLETED' ? 'completed' : 'pending';
        $amount     = round(($payment_obj['amount_money']['amount'] ?? 0) / 100, 2);

        if ($order_id) {
            $db->prepare('UPDATE payments SET square_payment_id = ? WHERE square_order_id = ?')
               ->execute([$payment_id, $order_id]);
        }

        $booking_ref = update_booking_payment($db, $order_id, $sq_status, $amount);
        log_webhook(
            'processed', $event_type, $square_event_id, $payment_id, $booking_ref ?? '',
            '$' . number_format($amount, 2) . ' — ' . strtoupper($sq_status)
              . ($booking_ref ? '' : ' (no matching order)')
        );
        break;

    case 'order.updated':
        $order      = $event['data']['object']['order_updated'] ?? $event['data']['object']['order'] ?? null;
        $order_id   = $order['order_id'] ?? $order['id'] ?? '';
        $state      = $order['state'] ?? '';
        if ($state === 'COMPLETED' && $order_id) {
            $db->prepare(
                'UPDATE payments SET status = "completed", paid_at = COALESCE(paid_at, NOW())
                 WHERE square_order_id = ? AND status = "pending"'
            )->execute([$order_id]);
        }
        log_webhook('processed', $event_type, $square_event_id, '', '', 'Order state: ' . ($state ?: 'unknown'));
        break;

    default:
        log_webhook('ignored', $event_type, $square_event_id, '', '', 'Unhandled event type');
        break;
}

http_response_code(200);
echo 'OK';
