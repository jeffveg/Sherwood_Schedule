<?php
/**
 * Step 5 — Venue & Contact Info
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/wizard.php';
require_once __DIR__ . '/../../includes/pricing.php';

wizard_start();
wizard_require_step(5);

$attraction     = wizard_get('attraction');
$hours          = (float)wizard_get('hours');
$event_date     = wizard_get('event_date');
$start_time     = wizard_get('start_time');
$selected_addons = wizard_get('selected_addons', []);

$db = get_db();

// Load pricing + tax + travel config
$pricing_row = $db->prepare('SELECT * FROM attraction_pricing WHERE attraction_id = ? AND active = 1 LIMIT 1');
$pricing_row->execute([$attraction['id']]);
$pricing = $pricing_row->fetch();

$tax_rates    = $db->query('SELECT * FROM tax_config WHERE active = 1 ORDER BY sort_order')->fetchAll();
$travel_config = $db->query('SELECT * FROM travel_fee_config WHERE id = 1')->fetch();

$attraction_price = calc_attraction_price($pricing, $hours);

// Calculate addon subtotals for summary
$addons_subtotal   = 0.0;
$addons_taxable    = 0.0;
$addons_nontaxable = 0.0;
foreach ($selected_addons as $item) {
    $unit = calc_addon_price($item['addon'], $hours);
    $line = round($unit * max(1, (int)$item['quantity']), 2);
    $addons_subtotal += $line;
    if ($item['addon']['is_taxable']) {
        $addons_taxable += $line;
    } else {
        $addons_nontaxable += $line;
    }
}

$errors = [];
$error_tab = 0; // which tab to open if there are errors (0=contact, 1=venue, 2=details)
$travel_miles = 0.0;
$travel_fee   = 0.0;

// Pre-fill from session
$fields = [
    'first_name'        => wizard_get('customer_first', ''),
    'last_name'         => wizard_get('customer_last', ''),
    'email'             => wizard_get('customer_email', ''),
    'phone'             => wizard_get('customer_phone', ''),
    'organization'      => wizard_get('customer_org', ''),
    'venue_name'        => wizard_get('venue_name', ''),
    'venue_address'     => wizard_get('venue_address', ''),
    'venue_city'        => wizard_get('venue_city', ''),
    'venue_state'       => wizard_get('venue_state', 'AZ'),
    'venue_zip'         => wizard_get('venue_zip', ''),
    'call_time_pref'    => wizard_get('call_time_pref', ''),
    'tournament_bracket'=> wizard_get('tournament_bracket', 'No'),
    'allow_publish'     => wizard_get('allow_publish', ''),
    'allow_advertise'   => wizard_get('allow_advertise', ''),
    'event_notes'       => wizard_get('event_notes', ''),
];

// Restore saved travel data if available
$saved_miles = wizard_get('travel_miles', null);
$saved_fee   = wizard_get('travel_fee', null);
if ($saved_miles !== null) {
    $travel_miles = (float)$saved_miles;
    $travel_fee   = (float)$saved_fee;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    foreach ($fields as $k => $_) {
        $fields[$k] = trim($_POST[$k] ?? '');
    }

    // Validation — track which tab each error belongs to
    if ($fields['first_name'] === '') { $errors[] = 'First name is required.';  $error_tab = min($error_tab, 0); }
    if ($fields['last_name']  === '') { $errors[] = 'Last name is required.';   $error_tab = min($error_tab, 0); }
    if ($fields['email'] === '' || !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.'; $error_tab = min($error_tab, 0);
    }
    $phone_digits = preg_replace('/\D/', '', $fields['phone']);
    if (strlen($phone_digits) < 10) { $errors[] = 'A valid 10-digit phone number is required.'; $error_tab = min($error_tab, 0); }

    if ($fields['venue_name']    === '') { $errors[] = 'Venue name is required.';    if (empty($errors) || $error_tab > 1) $error_tab = 1; }
    if ($fields['venue_address'] === '') { $errors[] = 'Venue address is required.'; if (empty($errors) || $error_tab > 1) $error_tab = 1; }
    if ($fields['venue_city']    === '') { $errors[] = 'Venue city is required.';    if (empty($errors) || $error_tab > 1) $error_tab = 1; }
    if ($fields['venue_state']   === '') { $errors[] = 'Venue state is required.';   if (empty($errors) || $error_tab > 1) $error_tab = 1; }

    if (empty($errors)) {
        // Calculate travel fee via Google Maps Distance Matrix API
        $destination = urlencode(
            $fields['venue_address'] . ', ' . $fields['venue_city'] . ', ' . $fields['venue_state'] . ' ' . $fields['venue_zip']
        );
        $origin  = urlencode($travel_config['base_address']);
        $api_key = GOOGLE_MAPS_API_KEY;

        $maps_url = "https://maps.googleapis.com/maps/api/distancematrix/json"
                  . "?origins={$origin}&destinations={$destination}&units=imperial&key={$api_key}";

        $travel_miles = 0.0;
        $travel_error = null;

        $ch = curl_init($maps_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = curl_exec($ch);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            $travel_error = 'Could not calculate travel distance. Please continue and we will confirm any travel fee separately.';
        } else {
            $data = json_decode($response, true);
            if (
                isset($data['rows'][0]['elements'][0]['status']) &&
                $data['rows'][0]['elements'][0]['status'] === 'OK'
            ) {
                $meters = $data['rows'][0]['elements'][0]['distance']['value'];
                $travel_miles = round($meters / 1609.344, 2);
            } else {
                $travel_error = 'Could not find that venue address. Please check it and try again.';
                if (
                    isset($data['rows'][0]['elements'][0]['status']) &&
                    $data['rows'][0]['elements'][0]['status'] !== 'NOT_FOUND'
                ) {
                    $travel_error = 'Could not calculate travel distance. Please continue and we will confirm any travel fee separately.';
                }
            }
        }

        if ($travel_error && strpos($travel_error, 'check it') !== false) {
            $errors[]   = $travel_error;
            $error_tab  = 1;
        } else {
            $travel_fee = calc_travel_fee(
                $travel_miles,
                (float)$travel_config['free_miles_threshold'],
                (float)$travel_config['rate_per_mile']
            );

            wizard_set('customer_first',     $fields['first_name']);
            wizard_set('customer_last',      $fields['last_name']);
            wizard_set('customer_email',     $fields['email']);
            wizard_set('customer_phone',     $phone_digits);
            wizard_set('customer_org',       $fields['organization']);
            wizard_set('venue_name',         $fields['venue_name']);
            wizard_set('venue_address',      $fields['venue_address']);
            wizard_set('venue_city',         $fields['venue_city']);
            wizard_set('venue_state',        $fields['venue_state']);
            wizard_set('venue_zip',          $fields['venue_zip']);
            wizard_set('travel_miles',       $travel_miles);
            wizard_set('travel_fee',         $travel_fee);
            wizard_set('call_time_pref',     $fields['call_time_pref']);
            wizard_set('tournament_bracket', $fields['tournament_bracket']);
            wizard_set('allow_publish',      isset($_POST['allow_publish'])   ? (int)$_POST['allow_publish']   : null);
            wizard_set('allow_advertise',    isset($_POST['allow_advertise']) ? (int)$_POST['allow_advertise'] : null);
            wizard_set('event_notes',        $fields['event_notes']);

            header('Location: ' . wizard_step_url(6));
            exit;
        }
    }
}

// Build live summary numbers (no coupon yet — that's step 6)
$taxable_subtotal = $attraction_price + $addons_taxable;
$tax_total        = 0.0;
$tax_lines        = [];
foreach ($tax_rates as $rate) {
    $amount   = round($taxable_subtotal * (float)$rate['rate'], 2);
    $tax_total += $amount;
    $tax_lines[] = ['label' => $rate['label'], 'rate' => $rate['rate'], 'amount' => $amount];
}
$grand_total = $taxable_subtotal + $addons_nontaxable + $tax_total + $travel_fee;

render_header('Venue & Contact', 'book');
?>

<div class="container container--narrow">
    <?php render_wizard_progress(5, array_values(WIZARD_STEPS)); ?>

    <div class="text-center mb-4">
        <h2>Your Info & Venue</h2>
        <p class="text-dim">Tell us about yourself and where your event will be held.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger mb-3">
            <?php foreach ($errors as $e): ?>
                <p><?= h($e) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="" id="venue-form">
        <div class="booking-layout">
            <div class="booking-layout__main">

                <!-- Tab nav -->
                <div class="form-tabs" id="form-tabs">
                    <button type="button" class="form-tab active" data-tab="0">Contact</button>
                    <button type="button" class="form-tab" data-tab="1">Venue</button>
                    <button type="button" class="form-tab" data-tab="2">Event Details</button>
                </div>

                <!-- Tab 0: Contact -->
                <div class="tab-panel active" id="tab-0">
                    <div class="panel">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="first_name">First Name <span class="required">*</span></label>
                                <input type="text" id="first_name" name="first_name" class="form-input"
                                       value="<?= h($fields['first_name']) ?>" autocomplete="given-name">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="last_name">Last Name <span class="required">*</span></label>
                                <input type="text" id="last_name" name="last_name" class="form-input"
                                       value="<?= h($fields['last_name']) ?>" autocomplete="family-name">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="email">Email Address <span class="required">*</span></label>
                                <input type="email" id="email" name="email" class="form-input"
                                       value="<?= h($fields['email']) ?>" autocomplete="email">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="phone">Phone Number <span class="required">*</span></label>
                                <input type="tel" id="phone" name="phone" class="form-input"
                                       value="<?= h($fields['phone']) ?>" autocomplete="tel"
                                       placeholder="(555) 867-5309">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="organization">
                                Organization / School
                                <span class="text-dim" style="font-weight:400;">(optional — for nonprofit discount)</span>
                            </label>
                            <input type="text" id="organization" name="organization" class="form-input"
                                   value="<?= h($fields['organization']) ?>" autocomplete="organization">
                        </div>
                        <div class="tab-nav">
                            <span></span>
                            <button type="button" class="btn btn-primary tab-next" data-next="1">Venue &rarr;</button>
                        </div>
                    </div>
                </div>

                <!-- Tab 1: Venue -->
                <div class="tab-panel" id="tab-1">
                    <div class="panel">
                        <div class="form-group">
                            <label class="form-label" for="venue_name">Venue Name <span class="required">*</span></label>
                            <input type="text" id="venue_name" name="venue_name" class="form-input"
                                   value="<?= h($fields['venue_name']) ?>"
                                   placeholder="e.g. Goodyear Ballpark, Estrella Mountain Park">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="venue_address">Street Address <span class="required">*</span></label>
                            <input type="text" id="venue_address" name="venue_address" class="form-input"
                                   value="<?= h($fields['venue_address']) ?>" autocomplete="street-address"
                                   placeholder="123 Main St">
                        </div>
                        <div class="form-row form-row--3">
                            <div class="form-group">
                                <label class="form-label" for="venue_city">City <span class="required">*</span></label>
                                <input type="text" id="venue_city" name="venue_city" class="form-input"
                                       value="<?= h($fields['venue_city']) ?>" autocomplete="address-level2">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="venue_state">State <span class="required">*</span></label>
                                <input type="text" id="venue_state" name="venue_state" class="form-input"
                                       value="<?= h($fields['venue_state']) ?>" maxlength="2"
                                       autocomplete="address-level1" style="text-transform:uppercase;">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="venue_zip">ZIP</label>
                                <input type="text" id="venue_zip" name="venue_zip" class="form-input"
                                       value="<?= h($fields['venue_zip']) ?>" maxlength="10"
                                       autocomplete="postal-code" inputmode="numeric">
                            </div>
                        </div>
                        <p class="text-xs text-dim mt-1 mb-3">
                            Events beyond <?= (int)$travel_config['free_miles_threshold'] ?> miles from our Goodyear base
                            include a travel fee of $<?= number_format($travel_config['rate_per_mile'], 2) ?>/mile over that threshold.
                        </p>
                        <div class="tab-nav">
                            <button type="button" class="btn btn-ghost tab-prev" data-prev="0">&larr; Contact</button>
                            <button type="button" class="btn btn-primary tab-next" data-next="2">Event Details &rarr;</button>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Event Details -->
                <div class="tab-panel" id="tab-2">
                    <div class="panel">
                        <?php if (in_array($attraction['slug'], ['archery-tag', 'combo'])): ?>
                        <div class="alert alert-info mb-3" style="font-size:0.875rem;">
                            <strong>Waiver required:</strong> Each player must sign our standard liability waiver before participating in Archery Tag. We'll send waiver instructions with your confirmation email.
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label">Best time to call you</label>
                            <div class="radio-pill-group">
                                <?php foreach (['Morning','Afternoon','Evening','Weekends','Anytime'] as $opt): ?>
                                    <label class="radio-pill">
                                        <input type="radio" name="call_time_pref" value="<?= $opt ?>"
                                               <?= $fields['call_time_pref'] === $opt ? 'checked' : '' ?>>
                                        <?= $opt ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="tournament_bracket">Tournament bracket format</label>
                            <select id="tournament_bracket" name="tournament_bracket" class="form-input">
                                <?php foreach (['No','Single Elimination','Double Elimination','Round Robin','Other'] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $fields['tournament_bracket'] === $opt ? 'selected' : '' ?>>
                                        <?= $opt === 'No' ? 'No tournament bracket' : $opt ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Publish on our events page?</label>
                                <div class="radio-pill-group">
                                    <label class="radio-pill">
                                        <input type="radio" name="allow_publish" value="1"
                                               <?= $fields['allow_publish'] === '1' || $fields['allow_publish'] === 1 ? 'checked' : '' ?>>
                                        Yes
                                    </label>
                                    <label class="radio-pill">
                                        <input type="radio" name="allow_publish" value="0"
                                               <?= $fields['allow_publish'] === '0' || $fields['allow_publish'] === 0 ? 'checked' : '' ?>>
                                        No
                                    </label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Advertise our services at your event? <span class="text-dim" style="font-weight:400;">(flyers / video)</span></label>
                                <div class="radio-pill-group">
                                    <label class="radio-pill">
                                        <input type="radio" name="allow_advertise" value="1"
                                               <?= $fields['allow_advertise'] === '1' || $fields['allow_advertise'] === 1 ? 'checked' : '' ?>>
                                        Yes
                                    </label>
                                    <label class="radio-pill">
                                        <input type="radio" name="allow_advertise" value="0"
                                               <?= $fields['allow_advertise'] === '0' || $fields['allow_advertise'] === 0 ? 'checked' : '' ?>>
                                        No
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="event_notes">Anything else we should know?</label>
                            <textarea id="event_notes" name="event_notes" class="form-input"
                                      rows="3" placeholder="Special requests, accessibility needs, parking info…"><?= h($fields['event_notes']) ?></textarea>
                        </div>

                        <div class="tab-nav">
                            <button type="button" class="btn btn-ghost tab-prev" data-prev="1">&larr; Venue</button>
                            <button type="submit" class="btn btn-primary btn-lg">Continue &rarr;</button>
                        </div>
                    </div>
                </div>


            </div>

            <!-- Price Summary Sidebar -->
            <aside class="booking-layout__aside">
                <div class="price-summary collapsed" id="price-summary">
                    <div class="price-summary__title price-summary__toggle" id="price-summary-toggle">
                        <span>Your Booking</span>
                        <span class="toggle-icon">&#9660;</span>
                    </div>
                    <div class="price-summary__body">

                    <div class="price-line">
                        <span class="price-line__label"><?= h($attraction['name']) ?></span>
                        <span><?= date('M j', strtotime($event_date)) ?> at <?= date('g:i A', strtotime('2000-01-01 ' . $start_time)) ?></span>
                    </div>
                    <div class="price-line price-line--indent">
                        <span class="price-line__label"><?= $hours ?> hour<?= $hours != 1 ? 's' : '' ?></span>
                        <span>$<?= number_format($attraction_price, 2) ?></span>
                    </div>

                    <?php foreach ($selected_addons as $item):
                        $unit = calc_addon_price($item['addon'], $hours);
                    ?>
                        <div class="price-line price-line--indent">
                            <span class="price-line__label"><?= h($item['addon']['name']) ?></span>
                            <span>$<?= number_format($unit, 2) ?></span>
                        </div>
                    <?php endforeach; ?>

                    <div class="price-line price-line--indent" id="travel-line"
                         style="<?= $travel_fee == 0 ? 'display:none;' : '' ?>">
                        <span class="price-line__label">Travel Fee
                            <?php if ($travel_miles > 0): ?>
                                <span class="text-xs text-dim">(<?= number_format($travel_miles, 0) ?> mi)</span>
                            <?php endif; ?>
                        </span>
                        <span id="travel-amount">$<?= number_format($travel_fee, 2) ?></span>
                    </div>

                    <hr class="price-divider">

                    <?php foreach ($tax_lines as $tl): ?>
                    <div class="price-line price-line--tax">
                        <span class="price-line__label">
                            <?= h($tl['label']) ?> (<?= number_format($tl['rate'] * 100, 1) ?>%)
                        </span>
                        <span>$<?= number_format($tl['amount'], 2) ?></span>
                    </div>
                    <?php endforeach; ?>

                    <hr class="price-divider">

                    <div class="price-total">
                        <span>Estimated Total</span>
                        <span>$<?= number_format($grand_total, 2) ?></span>
                    </div>

                    <p class="text-xs mt-2" style="color:var(--text-dim); line-height:1.5;">
                        Tax applies to the attraction fee and any taxable add-ons.
                        Lighting, video, and travel fees are non-taxable.
                    </p>

                    <div class="price-deposit-note">
                        Deposit due today: <strong>$<?= number_format((float)$attraction['deposit_amount'], 2) ?></strong>
                    </div>

                    </div><!-- /.price-summary__body -->
                </div>
            </aside>
        </div>
    </form>
</div>

<!-- Desktop: floating price button -->
<button class="price-float-btn" id="price-float-btn" type="button">
    Your Booking &mdash; $<?= number_format($grand_total, 2) ?>
    <span class="price-float-btn__icon">&#9650;</span>
</button>

<!-- Price modal (desktop) -->
<div class="price-modal" id="price-modal">
    <div class="price-modal__backdrop" id="price-modal-backdrop"></div>
    <div class="price-modal__panel" id="price-modal-panel">
        <button class="price-modal__close" id="price-modal-close" type="button">&times;</button>
    </div>
</div>

<!-- Sticky nav bar -->
<div class="mobile-continue-bar visible" id="mobile-continue-bar">
    <div class="mobile-continue-bar__inner">
        <a href="<?= wizard_step_url(4) ?>" class="btn btn-ghost btn-sm">&larr;</a>
        <span class="mobile-continue-bar__label" style="font-size:0.9rem;">
            Total: <span>$<?= number_format($grand_total, 2) ?></span>
        </span>
        <button type="submit" form="venue-form" class="btn btn-primary">Continue &rarr;</button>
    </div>
</div>

<?php render_footer(); ?>

<script>
(function () {
    // ---- Tab switching ----
    const initialTab = <?= (int)$error_tab ?>;

    function switchTab(index) {
        document.querySelectorAll('.form-tab').forEach(function (btn) {
            btn.classList.toggle('active', parseInt(btn.dataset.tab) === index);
        });
        document.querySelectorAll('.tab-panel').forEach(function (panel) {
            panel.classList.toggle('active', panel.id === 'tab-' + index);
        });
    }

    document.querySelectorAll('.form-tab').forEach(function (btn) {
        btn.addEventListener('click', function () { switchTab(parseInt(btn.dataset.tab)); });
    });

    document.querySelectorAll('.tab-next').forEach(function (btn) {
        btn.addEventListener('click', function () { switchTab(parseInt(btn.dataset.next)); });
    });

    document.querySelectorAll('.tab-prev').forEach(function (btn) {
        btn.addEventListener('click', function () { switchTab(parseInt(btn.dataset.prev)); });
    });

    // Open the correct tab on load (error recovery or default)
    switchTab(initialTab);

    // ---- State uppercase ----
    document.getElementById('venue_state').addEventListener('input', function () {
        this.value = this.value.toUpperCase();
    });

    // ---- Phone formatting ----
    document.getElementById('phone').addEventListener('input', function () {
        let digits = this.value.replace(/\D/g, '').substring(0, 10);
        if (digits.length >= 7) {
            this.value = '(' + digits.substring(0,3) + ') ' + digits.substring(3,6) + '-' + digits.substring(6);
        } else if (digits.length >= 4) {
            this.value = '(' + digits.substring(0,3) + ') ' + digits.substring(3);
        } else if (digits.length > 0) {
            this.value = '(' + digits;
        }
    });

    // ---- Collapsible price summary (mobile) ----
    const collapseToggle = document.getElementById('price-summary-toggle');
    if (collapseToggle) {
        collapseToggle.addEventListener('click', function () {
            document.getElementById('price-summary').classList.toggle('collapsed');
        });
    }

    // ---- Float button → modal (desktop) ----
    const floatBtn   = document.getElementById('price-float-btn');
    const modal      = document.getElementById('price-modal');
    const modalPanel = document.getElementById('price-modal-panel');
    const aside      = document.querySelector('.booking-layout__aside');
    const summary    = document.getElementById('price-summary');

    function openPriceModal() {
        summary.classList.remove('collapsed');
        modalPanel.appendChild(summary);
        modal.classList.add('open');
    }

    function closePriceModal() {
        aside.appendChild(summary);
        modal.classList.remove('open');
    }

    if (floatBtn) floatBtn.addEventListener('click', openPriceModal);
    document.getElementById('price-modal-backdrop').addEventListener('click', closePriceModal);
    document.getElementById('price-modal-close').addEventListener('click', closePriceModal);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePriceModal();
    });

    // ---- Radio pill active state (fallback for browsers without :has()) ----
    document.querySelectorAll('.radio-pill input[type="radio"]').forEach(function (radio) {
        if (radio.checked) radio.closest('.radio-pill').classList.add('checked');
        radio.addEventListener('change', function () {
            document.querySelectorAll('input[name="' + radio.name + '"]').forEach(function (r) {
                r.closest('.radio-pill').classList.remove('checked');
            });
            radio.closest('.radio-pill').classList.add('checked');
        });
    });
})();
</script>
