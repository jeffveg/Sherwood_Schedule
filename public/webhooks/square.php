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

// ── Verify Square signature ────────────────────────────────────────────────
$raw_body  = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '';
$url        = APP_URL . '/webhooks/square.php';

$expected = base64_encode(hash_hmac('sha256', $url . $raw_body, SQUARE_WEBHOOK_SIGNATURE_KEY, true));

if (!hash_equals($expected, $sig_header)) {
    http_response_code(403);
    exit('Forbidden');
}

// ── Parse event ───────────────────────────────────────────────────────────
$event = json_decode($raw_body, true);
if (!$event) {
    http_response_code(400);
    exit('Bad Request');
}

$event_type = $event['type'] ?? '';
$db = get_db();

// Helper: update booking payment status
function update_booking_payment(PDO $db, string $order_id, string $status, float $amount): void {
    // Find the payment record by Square order ID
    $stmt = $db->prepare(
        'SELECT p.id, p.booking_id, p.payment_type, b.grand_total, b.deposit_amount, b.payment_option
         FROM payments p
         JOIN bookings b ON b.id = p.booking_id
         WHERE p.square_order_id = ?
         LIMIT 1'
    );
    $stmt->execute([$order_id]);
    $payment = $stmt->fetch();
    if (!$payment) return;

    $booking_id = $payment['booking_id'];

    // Update payment record
    $db->prepare(
        'UPDATE payments SET status = ?, amount = ?, paid_at = NOW() WHERE id = ?'
    )->execute([$status, $amount, $payment['id']]);

    if ($status !== 'completed') return;

    // Determine new payment_status for booking
    $grand_total    = (float)$payment['grand_total'];
    $deposit_amount = (float)$payment['deposit_amount'];

    if ($payment['payment_option'] === 'full' || $amount >= $grand_total - 0.01) {
        $payment_status = 'paid_in_full';
        $amount_paid    = $grand_total;
        $balance_due    = 0.00;
    } else {
        $payment_status = 'deposit_paid';
        $amount_paid    = $deposit_amount;
        $balance_due    = round($grand_total - $deposit_amount, 2);
    }

    $db->prepare(
        'UPDATE bookings SET payment_status = ?, amount_paid = ?, balance_due = ?,
         booking_status = "confirmed", updated_at = NOW()
         WHERE id = ?'
    )->execute([$payment_status, $amount_paid, $balance_due, $booking_id]);
}

switch ($event_type) {
    case 'payment.completed':
    case 'payment.updated':
        $payment_obj = $event['data']['object']['payment'] ?? null;
        if (!$payment_obj) break;

        $order_id = $payment_obj['order_id'] ?? '';
        $status   = ($payment_obj['status'] ?? '') === 'COMPLETED' ? 'completed' : 'pending';
        $amount   = round(($payment_obj['amount_money']['amount'] ?? 0) / 100, 2);

        // Store Square payment ID
        if ($order_id) {
            $db->prepare(
                'UPDATE payments SET square_payment_id = ? WHERE square_order_id = ?'
            )->execute([$payment_obj['id'] ?? null, $order_id]);
        }

        update_booking_payment($db, $order_id, $status, $amount);
        break;

    case 'order.updated':
        $order = $event['data']['object']['order_updated'] ?? $event['data']['object']['order'] ?? null;
        if (!$order) break;
        $order_id = $order['order_id'] ?? $order['id'] ?? '';
        $state    = $order['state'] ?? '';
        if ($state === 'COMPLETED' && $order_id) {
            // Mark as completed if not already done by payment.completed
            $db->prepare(
                'UPDATE payments SET status = "completed", paid_at = COALESCE(paid_at, NOW())
                 WHERE square_order_id = ? AND status = "pending"'
            )->execute([$order_id]);
        }
        break;
}

http_response_code(200);
echo 'OK';
