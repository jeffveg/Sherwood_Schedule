<?php
/**
 * My Booking — customer lookup by phone with SMS verification.
 * Step 1: enter phone → send 6-digit code via OpenPhone
 * Step 2: enter code → show booking(s)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/sms.php';

session_start();

$CODE_TTL    = 600; // 10 minutes
$MAX_ATTEMPTS = 5;

$step    = 'phone';   // phone | code | bookings
$error   = '';
$phone   = '';

// ── POST: send code ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_code') {
    $raw   = trim($_POST['phone'] ?? '');
    $clean = preg_replace('/\D/', '', $raw);
    $last10 = substr($clean, -10); // always compare last 10 digits

    $db   = get_db();
    $stmt = $db->prepare(
        'SELECT id FROM customers WHERE REGEXP_REPLACE(phone, "[^0-9]", "") LIKE ?'
    );
    $stmt->execute(['%' . $last10]);
    $customer = $stmt->fetch();

    if (!$customer) {
        $error = 'No bookings found for that phone number.';
        $step  = 'phone';
    } else {
        $code    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $sent    = send_sms($raw, "Your Sherwood Adventure verification code is: {$code}. It expires in 10 minutes.");

        if (!$sent) {
            $error = 'Could not send verification code. Please try again or contact us.';
            $step  = 'phone';
        } else {
            $_SESSION['lookup_phone']    = $last10;
            $_SESSION['lookup_code']     = $code;
            $_SESSION['lookup_expires']  = time() + $CODE_TTL;
            $_SESSION['lookup_attempts'] = 0;
            $step = 'code';
            $phone = $raw;
        }
    }
}

// ── POST: verify code ─────────────────────────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_code') {
    $entered  = trim($_POST['code'] ?? '');
    $stored   = $_SESSION['lookup_code']     ?? '';
    $expires  = $_SESSION['lookup_expires']  ?? 0;
    $attempts = $_SESSION['lookup_attempts'] ?? 0;

    $_SESSION['lookup_attempts'] = $attempts + 1;

    if (time() > $expires) {
        $error = 'Your verification code has expired. Please request a new one.';
        unset($_SESSION['lookup_code'], $_SESSION['lookup_expires'], $_SESSION['lookup_attempts'], $_SESSION['lookup_phone']);
        $step = 'phone';
    } elseif ($attempts >= $MAX_ATTEMPTS) {
        $error = 'Too many incorrect attempts. Please request a new code.';
        unset($_SESSION['lookup_code'], $_SESSION['lookup_expires'], $_SESSION['lookup_attempts'], $_SESSION['lookup_phone']);
        $step = 'phone';
    } elseif ($entered !== $stored) {
        $error = 'Incorrect code. Please try again.';
        $step  = 'code';
    } else {
        // Code correct — load bookings
        unset($_SESSION['lookup_code'], $_SESSION['lookup_expires'], $_SESSION['lookup_attempts']);
        $step = 'bookings';
    }
}

// ── GET: already verified this session ───────────────────────────────────
elseif (isset($_SESSION['lookup_phone']) && !isset($_SESSION['lookup_code'])) {
    $step = 'bookings';
}

// ── Load bookings if verified ─────────────────────────────────────────────
$bookings    = [];
$customer    = null;
$addon_map   = [];

if ($step === 'bookings' && isset($_SESSION['lookup_phone'])) {
    $clean = $_SESSION['lookup_phone'];
    $db    = get_db();

    $cstmt = $db->prepare(
        'SELECT * FROM customers WHERE REGEXP_REPLACE(phone, "[^0-9]", "") LIKE ?'
    );
    $cstmt->execute(['%' . $clean]);
    $customer = $cstmt->fetch();

    if ($customer) {
        $bstmt = $db->prepare(
            "SELECT b.*, a.name AS attraction_name
             FROM bookings b
             JOIN attractions a ON a.id = b.attraction_id
             WHERE b.customer_id = ?
               AND b.booking_status NOT IN ('cancelled','rescheduled')
             ORDER BY b.event_date DESC"
        );
        $bstmt->execute([$customer['id']]);
        $bookings = $bstmt->fetchAll();

        if ($bookings) {
            $ids = implode(',', array_column($bookings, 'id'));
            $addon_rows = $db->query(
                "SELECT * FROM booking_addons WHERE booking_id IN ({$ids}) ORDER BY booking_id, id"
            );
            foreach ($addon_rows->fetchAll() as $row) {
                $addon_map[$row['booking_id']][] = $row;
            }
        }
    }
}

render_header('My Booking', 'book');
?>

<div class="container container--narrow">
    <div class="text-center mb-4 mt-2">
        <h2>My Booking</h2>
        <p class="text-dim">Look up your booking using the phone number you provided.</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger mb-3"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 'phone'): ?>
    <!-- Step 1: Enter phone -->
    <div class="panel">
        <form method="POST">
            <input type="hidden" name="action" value="send_code">
            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="tel" name="phone" class="form-input"
                       placeholder="(623) 555-1234" required autofocus
                       value="<?= h($phone) ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Send Verification Code</button>
        </form>
    </div>

    <?php elseif ($step === 'code'): ?>
    <!-- Step 2: Enter code -->
    <div class="panel">
        <p class="text-dim text-center mb-3">
            We sent a 6-digit code to your phone. It expires in 10 minutes.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="verify_code">
            <div class="form-group">
                <label class="form-label">Verification Code</label>
                <input type="text" name="code" class="form-input text-center"
                       placeholder="000000" maxlength="6" inputmode="numeric"
                       pattern="[0-9]{6}" required autofocus
                       style="font-size:1.5rem;letter-spacing:0.3em;">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Verify</button>
        </form>
        <p class="text-center mt-3 text-sm">
            <a href="<?= APP_URL ?>/booking/my-booking.php" class="text-dim">Start over</a>
        </p>
    </div>

    <?php elseif ($step === 'bookings'): ?>
    <!-- Step 3: Show bookings -->
    <?php if ($customer): ?>
    <p class="mb-3">
        Hi <strong><?= h($customer['first_name']) ?></strong>, here are your upcoming bookings.
        <a href="<?= APP_URL ?>/booking/my-booking.php?logout=1" class="text-dim text-sm" style="margin-left:1rem;">Not you?</a>
    </p>
    <?php endif; ?>

    <?php if ($bookings): ?>
        <?php foreach ($bookings as $b):
            $addons    = $addon_map[$b['id']] ?? [];
            $is_past   = $b['event_date'] < date('Y-m-d');
            $event_date = date('l, F j, Y', strtotime($b['event_date']));
            $event_time = date('g:i A', strtotime($b['start_time']));
        ?>
        <div class="panel mb-3 <?= $is_past ? 'opacity-50' : '' ?>">
            <div class="d-flex justify-between align-center flex-wrap gap-2 mb-3">
                <div>
                    <strong style="color:var(--gold);"><?= h($b['booking_ref']) ?></strong>
                    <span class="text-dim text-sm" style="margin-left:0.5rem;"><?= $event_date ?> at <?= $event_time ?></span>
                </div>
                <div class="d-flex gap-1">
                    <?php
                    $status_colors = [
                        'pending'   => 'var(--orange)',
                        'confirmed' => 'var(--green)',
                        'completed' => 'var(--text-dim)',
                    ];
                    $status_color = $status_colors[$b['booking_status']] ?? 'var(--text-dim)';
                    ?>
                    <span class="badge" style="background:<?= $status_color ?>;color:#111;">
                        <?= ucfirst($b['booking_status']) ?>
                    </span>
                </div>
            </div>

            <!-- Details -->
            <div class="review-grid mb-3">
                <div class="review-row">
                    <span class="review-label">Activity</span>
                    <span class="review-value"><?= h($b['attraction_name']) ?></span>
                </div>
                <div class="review-row">
                    <span class="review-label">Duration</span>
                    <span class="review-value"><?= $b['duration_hours'] ?> hour<?= $b['duration_hours'] != 1 ? 's' : '' ?></span>
                </div>
                <?php if ($b['venue_address']): ?>
                <div class="review-row">
                    <span class="review-label">Venue</span>
                    <span class="review-value">
                        <?php
                        $parts = array_filter([$b['venue_name'], $b['venue_address'],
                            $b['venue_city'] . ', ' . $b['venue_state']]);
                        echo h(implode(' · ', $parts));
                        ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Price summary -->
            <div class="price-line">
                <span class="price-line__label"><?= h($b['attraction_name']) ?></span>
                <span>$<?= number_format($b['attraction_price'], 2) ?></span>
            </div>
            <?php foreach ($addons as $a): ?>
            <div class="price-line price-line--indent">
                <span class="price-line__label"><?= h($a['addon_name']) ?></span>
                <span>$<?= number_format($a['total_price'], 2) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($b['travel_fee'] > 0): ?>
            <div class="price-line price-line--indent">
                <span class="price-line__label">Travel Fee</span>
                <span>$<?= number_format($b['travel_fee'], 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($b['coupon_discount'] > 0): ?>
            <div class="price-line" style="color:var(--green);">
                <span class="price-line__label">Discount</span>
                <span>&minus;$<?= number_format($b['coupon_discount'], 2) ?></span>
            </div>
            <?php endif; ?>
            <hr class="price-divider">
            <div class="price-line price-line--tax">
                <span class="price-line__label">Tax</span>
                <span>$<?= number_format($b['tax_total'], 2) ?></span>
            </div>
            <hr class="price-divider">
            <div class="price-total">
                <span>Total</span>
                <span>$<?= number_format($b['grand_total'], 2) ?></span>
            </div>
            <div class="price-line mt-1" style="color:var(--green);">
                <span class="price-line__label">Paid</span>
                <span>$<?= number_format($b['amount_paid'], 2) ?></span>
            </div>
            <?php if ($b['balance_due'] > 0): ?>
            <div class="price-line" style="color:var(--orange);">
                <span class="price-line__label"><strong>Balance Due</strong></span>
                <span><strong>$<?= number_format($b['balance_due'], 2) ?></strong></span>
            </div>
            <p class="text-sm text-dim mt-2">
                Balance is due before your event. Contact us if you need to arrange payment.
            </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="alert alert-info">No active bookings found for your account.</div>
    <?php endif; ?>

    <?php endif; ?>

    <p class="text-center mt-3 text-dim text-sm">
        Questions? <a href="https://sherwoodadventure.com/contact-us.html" style="color:var(--gold);">Contact us</a>
    </p>
</div>

<?php
// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION['lookup_phone'], $_SESSION['lookup_code'],
          $_SESSION['lookup_expires'], $_SESSION['lookup_attempts']);
    header('Location: ' . APP_URL . '/booking/my-booking.php');
    exit;
}

render_footer();
?>
