<?php
/**
 * Step 6 — Review, Coupon & Terms
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/wizard.php';
require_once __DIR__ . '/../../includes/pricing.php';

// Set before any date/strtotime calls (event date display, coupon expiry check)
date_default_timezone_set(APP_TIMEZONE);

wizard_start();
wizard_require_step(6);

$attraction      = wizard_get('attraction');
$hours           = (float)wizard_get('hours');
$event_date      = wizard_get('event_date');
$start_time      = wizard_get('start_time');
$selected_addons = wizard_get('selected_addons', []);
$travel_miles    = (float)wizard_get('travel_miles', 0);

$db = get_db();

// Load pricing deps
$pricing_row = $db->prepare('SELECT * FROM attraction_pricing WHERE attraction_id = ? AND active = 1 LIMIT 1');
$pricing_row->execute([$attraction['id']]);
$pricing = $pricing_row->fetch();

$tax_rates     = $db->query('SELECT * FROM tax_config WHERE active = 1 ORDER BY sort_order')->fetchAll();
$travel_config = $db->query('SELECT * FROM travel_fee_config WHERE id = 1')->fetch();

// Load terms from settings
$settings_stmt = $db->query(
    "SELECT setting_key, setting_value FROM settings
     WHERE setting_key IN ('terms_cancellation','terms_rescheduling','terms_travel_fee','terms_park_fees')"
);
$settings = [];
foreach ($settings_stmt->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$errors       = [];
$coupon       = wizard_get('coupon', null);       // persisted coupon row
$coupon_code  = wizard_get('coupon_code', '');    // persisted code string
$coupon_error = '';

// Handle AJAX coupon lookup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_coupon') {
    header('Content-Type: application/json');
    $code = strtoupper(trim($_POST['code'] ?? ''));
    if ($code === '') {
        echo json_encode(['ok' => false, 'message' => 'Please enter a coupon code.']);
        exit;
    }
    $stmt = $db->prepare(
        "SELECT * FROM coupons
         WHERE code = ? AND active = 1
           AND (expires_at IS NULL OR expires_at >= CURDATE())
           AND (max_uses IS NULL OR use_count < max_uses)"
    );
    $stmt->execute([$code]);
    $found = $stmt->fetch();
    if (!$found) {
        echo json_encode(['ok' => false, 'message' => 'Coupon not found or expired.']);
        exit;
    }
    // Check nonprofit restriction
    $is_nonprofit = (bool)wizard_get('customer_nonprofit', false);
    if ($found['nonprofit_only'] && !$is_nonprofit) {
        echo json_encode(['ok' => false, 'message' => 'This coupon is only available to nonprofit organizations.']);
        exit;
    }
    wizard_set('coupon', $found);
    wizard_set('coupon_code', $code);
    $coupon      = $found;
    $coupon_code = $code;

    // Build summary with coupon to return discount amount
    $summary = build_price_summary($attraction, $pricing, $hours, $selected_addons, $coupon, $travel_miles, $travel_config, $tax_rates);
    echo json_encode([
        'ok'       => true,
        'message'  => $found['description'] ?: 'Coupon applied!',
        'discount' => $summary['coupon_discount'],
        'summary'  => $summary,
    ]);
    exit;
}

// Handle coupon removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_coupon') {
    wizard_set('coupon', null);
    wizard_set('coupon_code', '');
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// Handle main form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    if (empty($_POST['agree_terms'])) {
        $errors[] = 'You must agree to the terms and conditions to continue.';
    }
    $payment_option = in_array($_POST['payment_option'] ?? '', ['deposit', 'full']) ? $_POST['payment_option'] : '';
    if (!$payment_option) {
        $errors[] = 'Please select a payment option.';
    }

    if (empty($errors)) {
        wizard_set('payment_option', $payment_option);
        header('Location: ' . wizard_step_url(7));
        exit;
    }
}

// Build full price summary
$summary = build_price_summary(
    $attraction, $pricing, $hours, $selected_addons,
    $coupon, $travel_miles, $travel_config, $tax_rates
);

render_header('Review Your Booking', 'book');
?>

<div class="container container--narrow">
    <?php render_wizard_progress(6, array_values(WIZARD_STEPS)); ?>

    <div class="text-center mb-4">
        <h2>Review Your Booking</h2>
        <p class="text-dim">Confirm everything looks right before payment.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger mb-3">
            <?php foreach ($errors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="" id="review-form">

        <!-- ── Event Summary ── -->
        <div class="panel mb-3">
            <h3 class="panel__title">Event Summary</h3>

            <div class="review-grid">
                <div class="review-row">
                    <span class="review-label">Activity</span>
                    <span class="review-value"><?= h($attraction['name']) ?></span>
                </div>
                <div class="review-row">
                    <span class="review-label">Date &amp; Time</span>
                    <span class="review-value">
                        <?= date('l, F j, Y', strtotime($event_date)) ?>
                        at <?= date('g:i A', strtotime('2000-01-01 ' . $start_time)) ?>
                    </span>
                </div>
                <div class="review-row">
                    <span class="review-label">Duration</span>
                    <span class="review-value"><?= $hours ?> hour<?= $hours != 1 ? 's' : '' ?></span>
                </div>
                <div class="review-row">
                    <span class="review-label">Venue</span>
                    <span class="review-value">
                        <?php
                        $venue_parts = array_filter([
                            wizard_get('venue_name'),
                            wizard_get('venue_address'),
                            wizard_get('venue_city') . ', ' . wizard_get('venue_state') . ' ' . wizard_get('venue_zip'),
                        ]);
                        echo h(implode(' · ', $venue_parts));
                        ?>
                    </span>
                </div>
                <?php if (wizard_get('tournament_bracket') && wizard_get('tournament_bracket') !== 'No'): ?>
                <div class="review-row">
                    <span class="review-label">Tournament</span>
                    <span class="review-value"><?= h(wizard_get('tournament_bracket')) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="text-right mt-2">
                <a href="<?= wizard_step_url(1) ?>" class="text-xs text-dim" style="color:var(--gold);">Edit booking details</a>
            </div>
        </div>

        <!-- ── Contact Summary ── -->
        <div class="panel mb-3">
            <h3 class="panel__title">Your Information</h3>

            <div class="review-grid">
                <div class="review-row">
                    <span class="review-label">Name</span>
                    <span class="review-value"><?= h(wizard_get('customer_first') . ' ' . wizard_get('customer_last')) ?></span>
                </div>
                <div class="review-row">
                    <span class="review-label">Email</span>
                    <span class="review-value"><?= h(wizard_get('customer_email')) ?></span>
                </div>
                <div class="review-row">
                    <span class="review-label">Phone</span>
                    <span class="review-value">
                        <?php
                        $d = preg_replace('/\D/', '', wizard_get('customer_phone'));
                        echo h(strlen($d) === 10 ? '(' . substr($d,0,3) . ') ' . substr($d,3,3) . '-' . substr($d,6) : $d);
                        ?>
                    </span>
                </div>
                <?php if (wizard_get('customer_org')): ?>
                <div class="review-row">
                    <span class="review-label">Organization</span>
                    <span class="review-value"><?= h(wizard_get('customer_org')) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="text-right mt-2">
                <a href="<?= wizard_step_url(5) ?>" class="text-xs" style="color:var(--gold);">Edit</a>
            </div>
        </div>

        <!-- ── Price Breakdown ── -->
        <div class="panel mb-3">
            <h3 class="panel__title">Price Breakdown</h3>

            <div class="price-line">
                <span class="price-line__label"><?= h($attraction['name']) ?></span>
                <span>$<?= number_format($summary['attraction_price'], 2) ?></span>
            </div>

            <?php foreach ($summary['addon_lines'] as $line): ?>
            <div class="price-line price-line--indent">
                <span class="price-line__label"><?= h($line['addon_name']) ?></span>
                <span>$<?= number_format($line['total_price'], 2) ?></span>
            </div>
            <?php endforeach; ?>

            <?php if ($summary['travel_fee'] > 0): ?>
            <div class="price-line price-line--indent">
                <span class="price-line__label">
                    Travel Fee
                    <span class="text-xs text-dim">(<?= number_format($summary['travel_miles'], 0) ?> mi)</span>
                </span>
                <span>$<?= number_format($summary['travel_fee'], 2) ?></span>
            </div>
            <?php elseif ($travel_miles == 0 && wizard_get('venue_address')): ?>
            <div class="price-line price-line--indent">
                <span class="price-line__label text-dim" style="font-style:italic;">
                    Travel fee — to be confirmed
                </span>
                <span class="text-dim">TBD</span>
            </div>
            <?php endif; ?>

            <?php if ($summary['coupon_discount'] > 0): ?>
            <div class="price-line" style="color:#7ec89a;">
                <span class="price-line__label">Discount (<?= h($coupon_code) ?>)</span>
                <span>&minus;$<?= number_format($summary['coupon_discount'], 2) ?></span>
            </div>
            <?php endif; ?>

            <hr class="price-divider">

            <?php foreach ($tax_rates as $rate): ?>
            <div class="price-line price-line--tax">
                <span class="price-line__label">
                    <?= h($rate['label']) ?> (<?= number_format($rate['rate'] * 100, 1) ?>%)
                </span>
                <span>$<?= number_format(round($summary['taxable_subtotal'] * (float)$rate['rate'], 2), 2) ?></span>
            </div>
            <?php endforeach; ?>

            <hr class="price-divider">

            <div class="price-total">
                <span>Total</span>
                <span id="grand-total">$<?= number_format($summary['grand_total'], 2) ?></span>
            </div>
        </div>

        <!-- ── Coupon Code ── -->
        <div class="panel mb-3">
            <h3 class="panel__title">Coupon Code</h3>

            <?php if ($coupon): ?>
                <div class="coupon-applied" id="coupon-applied">
                    <span class="coupon-applied__code"><?= h($coupon_code) ?></span>
                    <span class="coupon-applied__desc"><?= h($coupon['description'] ?: 'Discount applied') ?></span>
                    <button type="button" class="coupon-applied__remove" id="remove-coupon">&times; Remove</button>
                </div>
            <?php else: ?>
                <div id="coupon-form-wrap">
                    <div class="coupon-row">
                        <input type="text" id="coupon-input" class="form-input"
                               placeholder="Enter coupon code" autocomplete="off"
                               style="text-transform:uppercase;">
                        <button type="button" class="btn btn-ghost" id="apply-coupon">Apply</button>
                    </div>
                    <p id="coupon-msg" class="form-hint" style="display:none;"></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Payment Option ── -->
        <div class="panel mb-3">
            <h3 class="panel__title">Payment Option</h3>

            <div class="payment-options">
                <label class="payment-option<?= (wizard_get('payment_option') === 'deposit') ? ' selected' : '' ?>" id="opt-deposit">
                    <input type="radio" name="payment_option" value="deposit"
                           <?= (wizard_get('payment_option') === 'deposit') ? 'checked' : '' ?> required>
                    <div class="payment-option__body">
                        <div class="payment-option__title">Pay Deposit Now</div>
                        <div class="payment-option__amount">$<?= number_format($summary['deposit_amount'], 2) ?> due today</div>
                        <div class="payment-option__note text-dim">
                            Balance of $<?= number_format($summary['balance_if_deposit'], 2) ?> due before your event.
                        </div>
                    </div>
                </label>

                <label class="payment-option<?= (wizard_get('payment_option') === 'full') ? ' selected' : '' ?>" id="opt-full">
                    <input type="radio" name="payment_option" value="full"
                           <?= (wizard_get('payment_option') === 'full') ? 'checked' : '' ?>>
                    <div class="payment-option__body">
                        <div class="payment-option__title">Pay in Full</div>
                        <div class="payment-option__amount">$<?= number_format($summary['grand_total'], 2) ?> due today</div>
                        <div class="payment-option__note text-dim">No balance due — you're all set!</div>
                    </div>
                </label>
            </div>
        </div>

        <!-- ── Terms ── -->
        <div class="panel mb-3">
            <h3 class="panel__title">Terms &amp; Conditions</h3>

            <div class="terms-box">
                <div class="terms-section">
                    <strong>Cancellation Policy</strong>
                    <p><?= h($settings['terms_cancellation'] ?? '') ?></p>
                </div>
                <div class="terms-section">
                    <strong>Rescheduling</strong>
                    <p><?= h($settings['terms_rescheduling'] ?? '') ?></p>
                </div>
                <?php if ($summary['travel_fee'] > 0 || $travel_miles == 0): ?>
                <div class="terms-section">
                    <strong>Travel Fee</strong>
                    <p><?= h($settings['terms_travel_fee'] ?? '') ?></p>
                </div>
                <?php endif; ?>
                <div class="terms-section">
                    <strong>Venue &amp; Park Fees</strong>
                    <p><?= h($settings['terms_park_fees'] ?? '') ?></p>
                </div>
            </div>

            <label class="review-agree-wrap mt-3">
                <input type="checkbox" name="agree_terms" value="1"
                       id="agree-terms" <?= !empty($_POST['agree_terms']) ? 'checked' : '' ?>>
                <span>I have read and agree to the terms and conditions above.</span>
            </label>
        </div>

    </form><!-- form closes here; sticky bar submits it -->
</div>

<!-- Sticky nav bar -->
<div class="mobile-continue-bar visible">
    <div class="mobile-continue-bar__inner">
        <a href="<?= wizard_step_url(5) ?>" class="btn btn-ghost btn-sm">&larr;</a>
        <span class="mobile-continue-bar__label" style="font-size:0.9rem;">
            Total: $<?= number_format($summary['grand_total'], 2) ?>
        </span>
        <button type="submit" form="review-form" class="btn btn-primary">Continue &rarr;</button>
    </div>
</div>

<?php render_footer(); ?>

<script>
(function () {

    // ---- Payment option card toggle ----
    document.querySelectorAll('.payment-option input[type="radio"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.querySelectorAll('.payment-option').forEach(function (el) {
                el.classList.remove('selected');
            });
            radio.closest('.payment-option').classList.add('selected');
        });
    });

    // ---- Coupon apply ----
    const applyBtn = document.getElementById('apply-coupon');
    if (applyBtn) {
        applyBtn.addEventListener('click', function () {
            const input = document.getElementById('coupon-input');
            const msg   = document.getElementById('coupon-msg');
            const code  = input.value.trim().toUpperCase();
            if (!code) return;

            applyBtn.disabled = true;
            applyBtn.textContent = 'Checking…';

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=apply_coupon&code=' + encodeURIComponent(code),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                msg.style.display = 'block';
                if (data.ok) {
                    msg.style.color = '#7ec89a';
                    msg.textContent = data.message;
                    // Reload page to show applied coupon and updated totals
                    window.location.reload();
                } else {
                    msg.style.color = 'var(--danger)';
                    msg.textContent = data.message;
                    applyBtn.disabled = false;
                    applyBtn.textContent = 'Apply';
                }
            })
            .catch(function () {
                msg.style.display = 'block';
                msg.style.color = 'var(--danger)';
                msg.textContent = 'Network error. Please try again.';
                applyBtn.disabled = false;
                applyBtn.textContent = 'Apply';
            });
        });

        // Uppercase as user types
        document.getElementById('coupon-input').addEventListener('input', function () {
            this.value = this.value.toUpperCase();
        });

        // Apply on Enter
        document.getElementById('coupon-input').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); applyBtn.click(); }
        });
    }

    // ---- Coupon remove ----
    const removeBtn = document.getElementById('remove-coupon');
    if (removeBtn) {
        removeBtn.addEventListener('click', function () {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=remove_coupon',
            }).then(function () { window.location.reload(); });
        });
    }

})();
</script>
