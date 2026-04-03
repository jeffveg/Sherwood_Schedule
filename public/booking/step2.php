<?php
/**
 * Step 2 — Choose Duration
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/wizard.php';
require_once __DIR__ . '/../../includes/pricing.php';

wizard_start();
wizard_require_step(2);

$attraction = wizard_get('attraction');
$min_hours  = (float)$attraction['min_hours'];
$increment  = (float)$attraction['hour_increment'];
$max_hours  = 6.0;  // over 6 hrs requires manual admin booking (helpers needed)

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hours = (float)($_POST['hours'] ?? 0);

    // Validate: must be >= min, must be valid increment
    $valid = ($hours >= $min_hours)
          && ($hours <= $max_hours)
          && (fmod($hours - $min_hours, $increment) < 0.001);

    if ($valid) {
        wizard_set('hours', $hours);
        header('Location: ' . wizard_step_url(3));
        exit;
    }
    $error = 'Please select a valid duration.';
}

// Ensure stored hours are valid for this attraction (guards against attraction change edge cases)
$current_hours = max($min_hours, (float)wizard_get('hours', $min_hours));

// Build list of valid hour options
$options = [];
for ($h = $min_hours; $h <= $max_hours; $h += $increment) {
    $options[] = $h;
}

// Load tax rates and travel config to show live price preview
$db        = get_db();
$tax_rates = $db->query('SELECT * FROM tax_config WHERE active = 1 ORDER BY sort_order')->fetchAll();
$travel    = $db->query('SELECT * FROM travel_fee_config WHERE id = 1')->fetch();

render_header('Choose Duration', 'book');
?>

<div class="container container--narrow">
    <?php render_wizard_progress(2, array_values(WIZARD_STEPS)); ?>

    <div class="text-center mb-4">
        <h2>How Long Is Your Event?</h2>
        <p class="text-dim">
            <?= h($attraction['name']) ?> &mdash;
            <?= (int)$min_hours ?> hr minimum
        </p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="" id="duration-form">
        <input type="hidden" name="hours" id="hours-input" value="<?= h($current_hours) ?>">

        <div class="panel">
            <!-- Stepper -->
            <div class="duration-selector">
                <button type="button" class="duration-btn" id="btn-minus"
                        <?= $current_hours <= $min_hours ? 'disabled' : '' ?>>
                    &minus;
                </button>
                <div class="duration-display" id="duration-display">
                    <?= $current_hours == 1 ? '1 hour' : (($current_hours == (int)$current_hours ? (int)$current_hours : $current_hours) . ' hours') ?>
                </div>
                <button type="button" class="duration-btn" id="btn-plus"
                        <?= $current_hours >= $max_hours ? 'disabled' : '' ?>>
                    &plus;
                </button>
            </div>

            <!-- Or select from list -->
            <div class="text-center mt-2 mb-3">
                <span class="text-dim text-sm">or pick from the list:</span>
            </div>
            <div class="time-slots" id="duration-grid">
                <?php foreach ($options as $opt): ?>
                    <button type="button"
                            class="time-slot<?= $opt == $current_hours ? ' selected' : '' ?>"
                            data-hours="<?= $opt ?>">
                        <?= $opt == (int)$opt ? (int)$opt : $opt ?>
                        <?= $opt == 1 ? 'hr' : 'hrs' ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Live price preview -->
        <div class="price-summary mb-3">
            <div class="price-summary__title">Price Preview</div>
            <div class="price-line">
                <span class="price-line__label" id="price-label">
                    <?= h($attraction['name']) ?>
                    (<?= $current_hours == (int)$current_hours ? (int)$current_hours : $current_hours ?> hrs)
                </span>
                <span id="price-attraction">
                    $<?= number_format((float)$attraction['base_price'] + max(0, $current_hours - (float)$attraction['base_hours']) * (float)$attraction['additional_hourly_rate'], 2) ?>
                </span>
            </div>
            <?php
            $combined_rate = array_sum(array_column($tax_rates, 'rate'));
            $sample_price  = (float)$attraction['base_price'] + max(0, $current_hours - (float)$attraction['base_hours']) * (float)$attraction['additional_hourly_rate'];
            $sample_tax    = round($sample_price * $combined_rate, 2);
            ?>
            <div class="price-line price-line--tax">
                <span class="price-line__label">Tax (<?= number_format($combined_rate * 100, 1) ?>%)</span>
                <span id="price-tax">$<?= number_format($sample_tax, 2) ?></span>
            </div>
            <hr class="price-divider">
            <div class="price-total">
                <span>Estimated Total</span>
                <span id="price-total">$<?= number_format($sample_price + $sample_tax, 2) ?></span>
            </div>
            <div class="price-deposit-note">
                Deposit due today: <strong>$<?= number_format((float)$attraction['deposit_amount'], 2) ?></strong>
            </div>
            <p class="text-sm text-center mt-1" style="color:var(--text);">Add-ons, travel fee, and any coupons applied at checkout.</p>
            <p class="text-sm text-center mt-1" style="color:var(--orange);">
                Need more than 6 hours?
                <a href="https://sherwoodadventure.com/contact-us.html" target="_blank">Contact us</a>
                to arrange a custom booking.
            </p>
        </div>

    </form>
</div>

<div id="mobile-continue-bar" class="mobile-continue-bar visible">
    <div class="mobile-continue-bar__inner">
        <span id="mobile-continue-label" class="mobile-continue-bar__label">
            <a href="<?= wizard_step_url(1) ?>" class="btn btn-ghost btn-sm">&larr;</a>
        </span>
        <span id="mobile-price-display" class="mobile-continue-bar__label text-right" style="font-size:0.9rem;">
            $<?= number_format($sample_price + $sample_tax, 2) ?>
        </span>
        <button type="submit" form="duration-form" class="btn btn-primary">Continue &rarr;</button>
    </div>
</div>

<?php render_footer(); ?>

<script>
(function () {
    const minHours   = <?= $min_hours ?>;
    const maxHours   = <?= $max_hours ?>;
    const increment  = <?= $increment ?>;
    const baseHours  = <?= (float)$attraction['base_hours'] ?>;
    const basePrice  = <?= (float)$attraction['base_price'] ?>;
    const hourlyRate = <?= (float)$attraction['additional_hourly_rate'] ?>;
    const taxRate    = <?= $combined_rate ?>;
    const attrName   = <?= json_encode($attraction['name']) ?>;

    let hours = <?= $current_hours ?>;

    const hoursInput   = document.getElementById('hours-input');
    const display      = document.getElementById('duration-display');
    const btnMinus     = document.getElementById('btn-minus');
    const btnPlus      = document.getElementById('btn-plus');
    const priceLabel      = document.getElementById('price-label');
    const priceAttr       = document.getElementById('price-attraction');
    const priceTax        = document.getElementById('price-tax');
    const priceTotal      = document.getElementById('price-total');
    const mobilePriceDisp = document.getElementById('mobile-price-display');
    const slots           = document.querySelectorAll('.time-slot');

    function calcPrice(h) {
        const extra = Math.max(0, h - baseHours);
        return basePrice + extra * hourlyRate;
    }

    function fmt(n) {
        return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function update() {
        hoursInput.value = hours;

        const hLabel = hours === 1 ? '1 hour' : (Number.isInteger(hours) ? hours + ' hours' : hours + ' hours');
        display.textContent = hLabel;

        const price = calcPrice(hours);
        const tax   = Math.round(price * taxRate * 100) / 100;
        priceLabel.textContent  = attrName + ' (' + (Number.isInteger(hours) ? hours : hours) + ' hrs)';
        priceAttr.textContent   = fmt(price);
        priceTax.textContent    = fmt(tax);
        priceTotal.textContent  = fmt(price + tax);

        btnMinus.disabled = hours <= minHours;
        btnPlus.disabled  = hours >= maxHours;
        if (mobilePriceDisp) mobilePriceDisp.textContent = fmt(price + tax);

        slots.forEach(s => {
            const v = parseFloat(s.dataset.hours);
            s.classList.toggle('selected', v === hours);
        });
    }

    btnMinus.addEventListener('click', () => {
        if (hours - increment >= minHours) { hours = Math.round((hours - increment) * 10) / 10; update(); }
    });

    btnPlus.addEventListener('click', () => {
        if (hours + increment <= maxHours) { hours = Math.round((hours + increment) * 10) / 10; update(); }
    });

    slots.forEach(slot => {
        slot.addEventListener('click', () => {
            hours = parseFloat(slot.dataset.hours);
            update();
        });
    });
})();
</script>
