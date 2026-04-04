<?php
/**
 * Booking confirmation page.
 * Square redirects here after payment with ?ref=SA-YYYY-NNN
 * The webhook separately updates payment status asynchronously.
 * This page simply shows a thank-you and clears the wizard session.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/wizard.php';

wizard_start();

$booking_ref = trim($_GET['ref'] ?? '');

// Load booking from DB
$booking     = null;
$customer    = null;
$addon_lines = [];

if ($booking_ref) {
    $db   = get_db();
    $stmt = $db->prepare(
        'SELECT b.*, a.name AS attraction_name, a.slug AS attraction_slug
         FROM bookings b
         JOIN attractions a ON a.id = b.attraction_id
         WHERE b.booking_ref = ?'
    );
    $stmt->execute([$booking_ref]);
    $booking = $stmt->fetch();

    if ($booking) {
        $cust_stmt = $db->prepare('SELECT * FROM customers WHERE id = ?');
        $cust_stmt->execute([$booking['customer_id']]);
        $customer = $cust_stmt->fetch();

        $addon_stmt = $db->prepare(
            'SELECT * FROM booking_addons WHERE booking_id = ? ORDER BY id'
        );
        $addon_stmt->execute([$booking['id']]);
        $addon_lines = $addon_stmt->fetchAll();
    }
}

// Clear wizard session now that booking is confirmed
wizard_clear();

$is_balance_payment = ($_GET['type'] ?? '') === 'balance';

render_header($is_balance_payment ? 'Payment Received' : 'Booking Confirmed', 'book');
?>

<div class="container container--narrow">

    <?php if (!$booking): ?>
        <div class="text-center mt-5 mb-5">
            <h2>Booking Not Found</h2>
            <p class="text-dim">We couldn't find a booking matching that reference.</p>
            <a href="<?= APP_URL ?>/" class="btn btn-primary mt-3">Start a New Booking</a>
        </div>
    <?php else: ?>

    <?php if ($is_balance_payment): ?>
    <div class="text-center mb-4 mt-2">
        <div style="font-size:3rem; margin-bottom:0.5rem;">✅</div>
        <h2>Payment Received!</h2>
        <p class="text-dim">
            Your balance payment for booking
            <strong style="color:var(--gold);"><?= h($booking['booking_ref']) ?></strong>
            has been received.
        </p>
        <p class="text-dim">A receipt will be sent to <strong><?= h($customer['email'] ?? '') ?></strong>.</p>
    </div>
    <?php else: ?>
    <div class="text-center mb-4 mt-2">
        <div style="font-size:3rem; margin-bottom:0.5rem;">🎉</div>
        <h2>You're Booked!</h2>
        <p class="text-dim">
            Your booking reference is
            <strong style="color:var(--gold);"><?= h($booking['booking_ref']) ?></strong>
        </p>
        <p class="text-dim">A confirmation email will be sent to <strong><?= h($customer['email'] ?? '') ?></strong>.</p>
    </div>
    <?php endif; ?>

    <!-- Payment status notice -->
    <?php
    if ($is_balance_payment) {
        $paid_label = (float)$booking['balance_due'] <= 0
            ? 'Payment received — your balance is now paid in full.'
            : 'Payment received — thank you!';
        $paid_class = 'alert-success';
    } else {
        $paid_label = match($booking['payment_status']) {
            'deposit_paid'  => 'Deposit received — balance due before your event.',
            'paid_in_full'  => 'Paid in full — no balance due.',
            default         => 'Payment pending — we\'ll confirm once processing is complete.',
        };
        $paid_class = match($booking['payment_status']) {
            'deposit_paid', 'paid_in_full' => 'alert-success',
            default => 'alert-warning',
        };
    }
    ?>
    <div class="alert <?= $paid_class ?> mb-3">
        <?= h($paid_label) ?>
    </div>

    <!-- Booking summary -->
    <div class="panel mb-3">
        <h3 class="panel__title">Booking Summary</h3>
        <div class="review-grid">
            <div class="review-row">
                <span class="review-label">Reference</span>
                <span class="review-value"><strong><?= h($booking['booking_ref']) ?></strong></span>
            </div>
            <div class="review-row">
                <span class="review-label">Activity</span>
                <span class="review-value"><?= h($booking['attraction_name']) ?></span>
            </div>
            <div class="review-row">
                <span class="review-label">Date</span>
                <span class="review-value">
                    <?= date('l, F j, Y', strtotime($booking['event_date'])) ?>
                    at <?= date('g:i A', strtotime($booking['start_time'])) ?>
                </span>
            </div>
            <div class="review-row">
                <span class="review-label">Duration</span>
                <span class="review-value"><?= $booking['duration_hours'] ?> hour<?= $booking['duration_hours'] != 1 ? 's' : '' ?></span>
            </div>
            <?php if ($booking['venue_address']): ?>
            <div class="review-row">
                <span class="review-label">Venue</span>
                <span class="review-value">
                    <?php
                    $parts = array_filter([
                        $booking['venue_name'],
                        $booking['venue_address'],
                        $booking['venue_city'] . ', ' . $booking['venue_state'] . ' ' . $booking['venue_zip'],
                    ]);
                    echo h(implode(' · ', $parts));
                    ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Price summary -->
    <div class="panel mb-3">
        <h3 class="panel__title">Price Summary</h3>

        <div class="price-line">
            <span class="price-line__label"><?= h($booking['attraction_name']) ?></span>
            <span>$<?= number_format($booking['attraction_price'], 2) ?></span>
        </div>

        <?php foreach ($addon_lines as $line): ?>
        <div class="price-line price-line--indent">
            <span class="price-line__label"><?= h($line['addon_name']) ?></span>
            <span>$<?= number_format($line['total_price'], 2) ?></span>
        </div>
        <?php endforeach; ?>

        <?php if ($booking['travel_fee'] > 0): ?>
        <div class="price-line price-line--indent">
            <span class="price-line__label">Travel Fee</span>
            <span>$<?= number_format($booking['travel_fee'], 2) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($booking['coupon_discount'] > 0): ?>
        <div class="price-line" style="color:#7ec89a;">
            <span class="price-line__label">Discount (<?= h($booking['coupon_code']) ?>)</span>
            <span>&minus;$<?= number_format($booking['coupon_discount'], 2) ?></span>
        </div>
        <?php endif; ?>

        <hr class="price-divider">

        <div class="price-line price-line--tax">
            <span class="price-line__label">Tax</span>
            <span>$<?= number_format($booking['tax_total'], 2) ?></span>
        </div>

        <hr class="price-divider">

        <div class="price-total">
            <span>Total</span>
            <span>$<?= number_format($booking['grand_total'], 2) ?></span>
        </div>

        <?php if (!$is_balance_payment && $booking['payment_option'] === 'deposit' && $booking['balance_due'] > 0): ?>
        <div class="price-deposit-note mt-2">
            Balance due before event: <strong>$<?= number_format($booking['balance_due'], 2) ?></strong>
        </div>
        <?php endif; ?>
    </div>

    <div class="text-center mb-4">
        <p class="text-dim mb-3">Questions? <a href="https://sherwoodadventure.com/contact-us.html" style="color:var(--gold);">Contact us</a> or reply to your confirmation email.</p>
        <a href="<?= APP_URL ?>/" class="btn btn-primary">Back to Home</a>
    </div>

    <?php endif; ?>
</div>

<?php render_footer(); ?>
