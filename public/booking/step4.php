<?php
/**
 * Step 4 — Add-ons
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/wizard.php';
require_once __DIR__ . '/../../includes/pricing.php';

wizard_start();
wizard_require_step(4);

$attraction = wizard_get('attraction');
$hours      = (float)wizard_get('hours');
$event_date = wizard_get('event_date');
$start_time = wizard_get('start_time');

// Load available add-ons for this attraction
$db   = get_db();
$stmt = $db->prepare(
    "SELECT * FROM addons WHERE active = 1 ORDER BY sort_order, name"
);
$stmt->execute();
$all_addons = $stmt->fetchAll();

// Filter to addons applicable to this attraction
$addons = array_filter($all_addons, function($a) use ($attraction) {
    if ($a['applicable_attractions'] === null) return true;
    $ids = json_decode($a['applicable_attractions'], true);
    return in_array($attraction['id'], $ids);
});
$addons = array_values($addons);

// Load addon images
$addon_images = [];
if ($addons) {
    $ids      = implode(',', array_column($addons, 'id'));
    $img_stmt = $db->query(
        "SELECT addon_id, filename, alt_text FROM addon_images
         WHERE addon_id IN ($ids) ORDER BY addon_id, sort_order"
    );
    foreach ($img_stmt->fetchAll() as $img) {
        if (!isset($addon_images[$img['addon_id']])) {
            $addon_images[$img['addon_id']] = $img;
        }
    }
}

// Load tax + pricing for live summary
$tax_rates   = $db->query('SELECT * FROM tax_config WHERE active = 1 ORDER BY sort_order')->fetchAll();
$combined_rate = array_sum(array_column($tax_rates, 'rate'));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_ids = array_map('intval', $_POST['addons'] ?? []);

    // Build selected addons array with pricing snapshot
    $selected_addons = [];
    foreach ($addons as $addon) {
        if (in_array((int)$addon['id'], $selected_ids)) {
            $selected_addons[] = [
                'addon'    => $addon,
                'quantity' => 1,
            ];
        }
    }

    wizard_set('selected_addons', $selected_addons);
    header('Location: ' . wizard_step_url(5));
    exit;
}

// Pre-fill from session
$saved_addon_ids = [];
foreach (wizard_get('selected_addons', []) as $item) {
    $saved_addon_ids[] = (int)$item['addon']['id'];
}

// Pre-calculate base attraction price for summary
$pricing_row = $db->prepare(
    'SELECT * FROM attraction_pricing WHERE attraction_id = ? AND active = 1 LIMIT 1'
);
$pricing_row->execute([$attraction['id']]);
$pricing = $pricing_row->fetch();

$attraction_price = calc_attraction_price($pricing, $hours);

render_header('Add-ons', 'book');
?>

<div class="container container--narrow">
    <?php render_wizard_progress(4, array_values(WIZARD_STEPS)); ?>

    <div class="text-center mb-4">
        <h2>Enhance Your Event</h2>
        <p class="text-dim">Optional add-ons for your <?= h($attraction['name']) ?> event.</p>
    </div>

    <form method="post" action="" id="addons-form">
        <div class="booking-layout">
            <div class="booking-layout__main">

                <?php if (empty($addons)): ?>
                    <div class="panel text-center">
                        <p class="text-dim">No add-ons available at this time.</p>
                    </div>
                <?php else: ?>
                    <div class="card-grid mb-3">
                        <?php foreach ($addons as $addon):
                            $is_selected = in_array((int)$addon['id'], $saved_addon_ids);
                            $unit_price  = calc_addon_price($addon, $hours);
                            if ($addon['pricing_type'] === 'flat') {
                                $price_label = '$' . number_format($unit_price, 2) . ' flat fee';
                            } else {
                                $price_label = '$' . number_format($addon['price'], 2) . '/hr'
                                    . ($addon['min_charge'] ? ' (min $' . number_format($addon['min_charge'], 2) . ')' : '');
                            }
                        ?>
                            <label class="card addon-card<?= $is_selected ? ' selected' : '' ?>"
                                   style="display:block; cursor:pointer;">
                                <input type="checkbox" name="addons[]"
                                       value="<?= $addon['id'] ?>"
                                       <?= $is_selected ? 'checked' : '' ?>>
                                <div class="card__selected-badge">Added ✓</div>

                                <?php if (isset($addon_images[$addon['id']])): ?>
                                    <img class="card__image"
                                         src="<?= h(APP_URL . '/assets/img/addons/' . $addon_images[$addon['id']]['filename']) ?>"
                                         alt="<?= h($addon_images[$addon['id']]['alt_text'] ?: $addon['name']) ?>">
                                <?php else: ?>
                                    <div class="card__image--placeholder" style="aspect-ratio:3/1; font-size:1.8rem;">
                                        <?php
                                        $slug = strtolower($addon['name']);
                                        if (str_contains($slug, 'light'))       echo '💡';
                                        elseif (str_contains($slug, 'shirt'))   echo '👕';
                                        elseif (str_contains($slug, 'video'))   echo '🎥';
                                        else                                     echo '✨';
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <div class="card__body">
                                    <div class="card__title"><?= h($addon['name']) ?></div>
                                    <?php if ($addon['description']): ?>
                                        <div class="card__desc"><?= h($addon['description']) ?></div>
                                    <?php endif; ?>
                                    <div class="card__price"><?= h($price_label) ?></div>
                                    <?php if ($addon['pricing_type'] === 'per_hour'): ?>
                                        <div class="text-xs text-dim mt-1">
                                            <?= $hours ?> hrs &times; $<?= number_format($addon['price'], 2) ?>
                                            = $<?= number_format($unit_price, 2) ?> for your event
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <p class="text-xs text-dim text-center mb-3">
                    All add-ons are optional. You can skip this step if none apply.
                </p>

                <div class="wizard-nav desktop-nav">
                    <a href="<?= wizard_step_url(3) ?>" class="btn btn-ghost">&larr; Back</a>
                    <button type="submit" class="btn btn-primary btn-lg">Continue &rarr;</button>
                </div>
            </div>

            <!-- Price summary sidebar -->
            <aside class="booking-layout__aside">
                <div class="price-summary">
                    <div class="price-summary__title">Your Booking</div>

                    <div class="price-line">
                        <span class="price-line__label"><?= h($attraction['name']) ?></span>
                        <span><?= date('M j', strtotime($event_date)) ?> at <?= date('g:i A', strtotime('2000-01-01 ' . $start_time)) ?></span>
                    </div>
                    <div class="price-line price-line--indent">
                        <span class="price-line__label"><?= $hours ?> hour<?= $hours != 1 ? 's' : '' ?></span>
                        <span id="price-attraction">$<?= number_format($attraction_price, 2) ?></span>
                    </div>

                    <div id="addon-lines">
                        <?php foreach ($addons as $addon):
                            if (!in_array((int)$addon['id'], $saved_addon_ids)) continue;
                            $unit_price = calc_addon_price($addon, $hours);
                        ?>
                            <div class="price-line price-line--indent addon-summary-line"
                                 id="addon-line-<?= $addon['id'] ?>">
                                <span class="price-line__label"><?= h($addon['name']) ?></span>
                                <span>$<?= number_format($unit_price, 2) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <hr class="price-divider">

                    <?php foreach ($tax_rates as $rate): ?>
                    <div class="price-line price-line--tax tax-line"
                         data-rate="<?= $rate['rate'] ?>"
                         data-label="<?= h($rate['label']) ?>">
                        <span class="price-line__label">
                            <?= h($rate['label']) ?> (<?= number_format($rate['rate'] * 100, 1) ?>%)
                        </span>
                        <span class="tax-amount">—</span>
                    </div>
                    <?php endforeach; ?>

                    <hr class="price-divider">

                    <div class="price-total">
                        <span>Estimated Total</span>
                        <span id="price-total">—</span>
                    </div>

                    <div class="price-deposit-note">
                        Deposit due today: <strong>$<?= number_format((float)$attraction['deposit_amount'], 2) ?></strong>
                    </div>
                </div>
            </aside>
        </div>
    </form>
</div>

<!-- Mobile sticky bar -->
<div class="mobile-continue-bar visible" id="mobile-continue-bar">
    <div class="mobile-continue-bar__inner">
        <a href="<?= wizard_step_url(3) ?>" class="btn btn-ghost btn-sm">&larr;</a>
        <span class="mobile-continue-bar__label" id="mobile-total-label" style="font-size:0.9rem;">
            Total: <span id="mobile-total">—</span>
        </span>
        <button type="submit" form="addons-form" class="btn btn-primary">Continue &rarr;</button>
    </div>
</div>

<?php render_footer(); ?>

<script>
(function () {
    const attractionPrice = <?= $attraction_price ?>;
    const depositAmount   = <?= (float)$attraction['deposit_amount'] ?>;

    // Add-on price data keyed by addon ID
    const addonPrices = {
        <?php foreach ($addons as $addon): ?>
        <?= $addon['id'] ?>: {
            name:      <?= json_encode($addon['name']) ?>,
            price:     <?= calc_addon_price($addon, $hours) ?>,
            taxable:   <?= $addon['is_taxable'] ? 'true' : 'false' ?>,
        },
        <?php endforeach; ?>
    };

    function fmt(n) {
        return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function recalc() {
        let addonsSubtotal  = 0;
        let taxableSubtotal = attractionPrice; // attraction is always taxable

        // Remove all existing addon summary lines
        document.querySelectorAll('.addon-summary-line').forEach(el => el.remove());
        const addonLinesContainer = document.getElementById('addon-lines');

        document.querySelectorAll('.addon-card input[type="checkbox"]:checked').forEach(cb => {
            const id   = parseInt(cb.value);
            const data = addonPrices[id];
            if (!data) return;

            addonsSubtotal += data.price;
            if (data.taxable) taxableSubtotal += data.price;

            // Add line to sidebar
            const line = document.createElement('div');
            line.className = 'price-line price-line--indent addon-summary-line';
            line.id = 'addon-line-' + id;
            line.innerHTML = '<span class="price-line__label">' + data.name + '</span>'
                           + '<span>' + fmt(data.price) + '</span>';
            addonLinesContainer.appendChild(line);
        });

        // Tax applies to attraction + taxable add-ons only
        const checkedBoxes   = Array.from(document.querySelectorAll('.addon-card input[type="checkbox"]:checked'));
        const taxableAddons  = checkedBoxes.reduce((sum, cb) => {
            const d = addonPrices[parseInt(cb.value)];
            return d && d.taxable ? sum + d.price : sum;
        }, 0);
        const nonTaxableAddons = checkedBoxes.reduce((sum, cb) => {
            const d = addonPrices[parseInt(cb.value)];
            return d && !d.taxable ? sum + d.price : sum;
        }, 0);

        const taxableAmt = attractionPrice + taxableAddons;

        // Update each tax line separately
        let finalTax = 0;
        document.querySelectorAll('.tax-line').forEach(line => {
            const rate   = parseFloat(line.dataset.rate);
            const amount = Math.round(taxableAmt * rate * 100) / 100;
            finalTax += amount;
            line.querySelector('.tax-amount').textContent = fmt(amount);
        });

        const finalTotal = taxableAmt + nonTaxableAddons + finalTax;
        document.getElementById('price-total').textContent = fmt(finalTotal);

        const mobileTotal = document.getElementById('mobile-total');
        if (mobileTotal) mobileTotal.textContent = fmt(finalTotal);
    }

    // Card toggle behaviour
    document.querySelectorAll('.addon-card').forEach(card => {
        card.addEventListener('click', () => {
            card.classList.toggle('selected');
            const cb = card.querySelector('input[type="checkbox"]');
            if (cb) cb.checked = !cb.checked;
            recalc();
        });
    });

    // Initial calc
    recalc();
})();
</script>
