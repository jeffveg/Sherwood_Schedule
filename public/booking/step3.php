<?php
/**
 * Step 3 — Choose Date & Time
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/wizard.php';
require_once __DIR__ . '/../../includes/availability.php';

// Set before any date/strtotime calls (lead-time cutoff, slot generation)
date_default_timezone_set(APP_TIMEZONE);

wizard_start();
wizard_require_step(3);

$attraction = wizard_get('attraction');
$hours      = (float)wizard_get('hours');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date       = trim($_POST['event_date']  ?? '');
    $start_time = trim($_POST['start_time']  ?? '');

    $errors = [];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = 'Please select a date.';
    } else {
        $lead_cutoff = strtotime('+' . BOOKING_LEAD_DAYS . ' days', strtotime('today midnight'));
        if (strtotime($date) < $lead_cutoff) {
            $errors[] = 'Please select a date at least ' . BOOKING_LEAD_DAYS . ' days from today.';
        }
    }

    if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) {
        $errors[] = 'Please select a start time.';
    } else {
        // Verify this slot is still available server-side
        $valid_slots = get_available_slots($date);
        if (!in_array($start_time, $valid_slots, true)) {
            $errors[] = 'That time slot is no longer available. Please choose another.';
        }
    }

    if (empty($errors)) {
        // Calculate end time (handle midnight crossing)
        $start_ts      = strtotime($date . ' ' . $start_time);
        $end_ts        = $start_ts + (int)round($hours * 3600);
        $end_time      = date('H:i', $end_ts);
        $crosses_midnight = (date('Y-m-d', $end_ts) !== $date);

        wizard_set('event_date',       $date);
        wizard_set('start_time',       $start_time);
        wizard_set('end_time',         $end_time);
        wizard_set('crosses_midnight', $crosses_midnight);

        header('Location: ' . wizard_step_url(4));
        exit;
    }
}

$saved_date = wizard_get('event_date', '');
$saved_time = wizard_get('start_time', '');

// Start calendar at the earliest bookable month
$min_date   = date('Y-m-d', strtotime('+' . BOOKING_LEAD_DAYS . ' days'));
$init_month = date('Y-m', strtotime($saved_date ?: $min_date));

render_header('Choose Date & Time', 'book');
?>

<div class="container container--narrow">
    <?php render_wizard_progress(3, array_values(WIZARD_STEPS)); ?>

    <div class="text-center mb-4">
        <h2>Choose Your Date &amp; Time</h2>
        <p class="text-dim">
            <?= h($attraction['name']) ?> &mdash;
            <?= (int)$hours ?> hour<?= $hours != 1 ? 's' : '' ?>
        </p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-3">
            <?php foreach ($errors as $e): ?>
                <div><?= h($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-info mb-3">
        <span>&#8505;</span>
        Bookings require at least <?= BOOKING_LEAD_DAYS ?> days advance notice.
        The earliest available date is <strong><?= date('F j, Y', strtotime($min_date)) ?></strong>.
    </div>

    <form method="post" action="" id="datetime-form">
        <input type="hidden" name="event_date"  id="event-date-input"  value="<?= h($saved_date) ?>">
        <input type="hidden" name="start_time"  id="start-time-input"  value="<?= h($saved_time) ?>">

        <div class="panel">
            <!-- Calendar -->
            <div class="calendar-wrapper">
                <div class="calendar-header">
                    <button type="button" class="cal-nav" id="cal-prev">&#8249;</button>
                    <h3 id="cal-month-label"></h3>
                    <button type="button" class="cal-nav" id="cal-next">&#8250;</button>
                </div>

                <div class="calendar-grid" id="cal-grid">
                    <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                        <div class="cal-day-name"><?= $d ?></div>
                    <?php endforeach; ?>
                </div>

                <div id="cal-loading" class="spinner" style="display:none;"></div>
            </div>

            <!-- Selected date display -->
            <div id="selected-date-display" class="text-center mb-2" style="display:none;">
                <span class="text-gold" style="font-family:var(--font-heading);font-size:1.2rem;"
                      id="selected-date-text"></span>
            </div>

            <!-- Time slots -->
            <div id="slots-section" style="display:none;">
                <p class="text-dim text-sm text-center mb-2">Select a start time:</p>
                <div id="slots-loading" class="spinner" style="display:none;"></div>
                <div class="time-slots" id="slots-grid"></div>
                <div id="slots-none" class="text-center text-dim text-sm" style="display:none;">
                    No times available on this date. Please choose another day.
                </div>
            </div>
        </div>

        <!-- Legend -->
        <div class="d-flex gap-2 flex-wrap mb-2" style="font-size:0.78rem; justify-content:center;">
            <span><span style="display:inline-block;width:12px;height:12px;background:var(--gold);border-radius:3px;margin-right:4px;vertical-align:middle;"></span>Selected</span>
            <span style="color:var(--orange)">
                <span style="display:inline-block;position:relative;width:12px;height:16px;vertical-align:middle;margin-right:4px;">
                    <span style="position:absolute;bottom:0;left:50%;transform:translateX(-50%);width:5px;height:5px;background:var(--orange);border-radius:50%;"></span>
                </span>Today</span>
            <span style="color:#c0392b"><span style="display:inline-block;width:12px;height:12px;background:rgba(192,57,43,0.2);border-radius:3px;margin-right:4px;vertical-align:middle;"></span>Not available</span>
            <span style="color:#555"><span style="display:inline-block;width:12px;height:12px;background:#333;border-radius:3px;margin-right:4px;vertical-align:middle;"></span>Past</span>
        </div>
        <p class="text-center text-xs mb-3" style="color:var(--text-dim);">
            Don't see the date or time you need?
            <a href="https://sherwoodadventure.com/contact.html" target="_blank">Contact us</a>
            — we may be able to accommodate special requests.
        </p>

    </form>
</div>

<div id="mobile-continue-bar" class="mobile-continue-bar<?= ($saved_date && $saved_time) ? ' visible' : '' ?>">
    <div class="mobile-continue-bar__inner">
        <a href="<?= wizard_step_url(2) ?>" class="btn btn-ghost btn-sm">&larr;</a>
        <span id="mobile-datetime-label" class="mobile-continue-bar__label" style="font-size:0.8rem; text-align:center;">
            <?php if ($saved_date && $saved_time): ?>
                <?= date('M j', strtotime($saved_date)) ?> at <?= date('g:i A', strtotime('2000-01-01 ' . $saved_time)) ?>
            <?php else: ?>
                Select a date &amp; time
            <?php endif; ?>
        </span>
        <button type="submit" form="datetime-form" class="btn btn-primary" id="mobile-continue-btn"
                <?= ($saved_date && $saved_time) ? '' : 'disabled' ?>>
            Continue &rarr;
        </button>
    </div>
</div>

<?php render_footer(); ?>

<script>
(function () {
    const APP_URL    = <?= json_encode(APP_URL) ?>;
    const hours      = <?= $hours ?>;
    const savedDate  = <?= json_encode($saved_date) ?>;
    const savedTime  = <?= json_encode($saved_time) ?>;
    const minDate    = <?= json_encode($min_date) ?>;
    const today      = <?= json_encode(date('Y-m-d')) ?>;

    const monthNames = ['January','February','March','April','May','June',
                        'July','August','September','October','November','December'];

    let currentYear, currentMonth;
    let dayStatus    = {};   // 'YYYY-MM-DD' => status string
    let selectedDate = savedDate || '';
    let selectedTime = savedTime || '';
    let fetching     = false;

    // Parse initial month
    const initParts  = <?= json_encode($init_month) ?>.split('-');
    currentYear      = parseInt(initParts[0]);
    currentMonth     = parseInt(initParts[1]) - 1; // 0-based

    // DOM refs
    const calGrid    = document.getElementById('cal-grid');
    const calLabel   = document.getElementById('cal-month-label');
    const calLoading = document.getElementById('cal-loading');
    const btnPrev    = document.getElementById('cal-prev');
    const btnNext    = document.getElementById('cal-next');
    const slotsSection = document.getElementById('slots-section');
    const slotsGrid  = document.getElementById('slots-grid');
    const slotsLoad  = document.getElementById('slots-loading');
    const slotsNone  = document.getElementById('slots-none');
    const dateInput  = document.getElementById('event-date-input');
    const timeInput  = document.getElementById('start-time-input');
    const continueBtn       = document.getElementById('mobile-continue-btn');
    const dateDisplay       = document.getElementById('selected-date-display');
    const dateText          = document.getElementById('selected-date-text');
    const mobileBar         = document.getElementById('mobile-continue-bar');
    const mobileDateLabel   = document.getElementById('mobile-datetime-label');
    const mobileContinueBtn = document.getElementById('mobile-continue-btn');

    function isoDate(y, m, d) {
        return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }

    // ---- Calendar rendering -----------------------------------------

    function renderCalendar() {
        const year  = currentYear;
        const month = currentMonth;
        const label = monthNames[month] + ' ' + year;
        calLabel.textContent = label;

        // Disable prev if we're at the earliest month
        const minParts = minDate.split('-');
        const atMin    = (year === parseInt(minParts[0]) && month === parseInt(minParts[1]) - 1);
        btnPrev.disabled = atMin;

        // Remove old day cells (keep the 7 day-name headers)
        const cells = calGrid.querySelectorAll('.cal-day, .cal-empty');
        cells.forEach(c => c.remove());

        const firstDow = new Date(year, month, 1).getDay(); // 0=Sun
        const daysIn   = new Date(year, month + 1, 0).getDate();

        // Empty cells before the 1st
        for (let i = 0; i < firstDow; i++) {
            const empty = document.createElement('div');
            empty.className = 'cal-day empty';
            calGrid.appendChild(empty);
        }

        for (let d = 1; d <= daysIn; d++) {
            const dateStr = isoDate(year, month, d);
            const status  = dayStatus[dateStr] || 'closed';
            const cell    = document.createElement('button');
            cell.type     = 'button';
            cell.textContent = d;

            let cls = 'cal-day';
            if (dateStr === today)         cls += ' today';
            if (dateStr === selectedDate)  cls += ' selected';
            if (status === 'past')         cls += ' past';
            else if (status === 'soon')    cls += ' past';       // too soon to book
            else if (status === 'closed')  cls += ' unavailable';

            cell.className = cls;
            cell.dataset.date = dateStr;

            if (status === 'available') {
                cell.addEventListener('click', () => selectDate(dateStr));
            }

            calGrid.appendChild(cell);
        }
    }

    // ---- Fetch month status -----------------------------------------

    function fetchMonth(yearMonth) {
        if (fetching) return;
        fetching = true;
        calLoading.style.display = 'block';

        fetch(APP_URL + '/booking/calendar.php?month=' + yearMonth)
            .then(r => r.json())
            .then(data => {
                dayStatus = data.days || {};
                renderCalendar();
            })
            .catch(() => {
                dayStatus = {};
                renderCalendar();
            })
            .finally(() => {
                fetching = false;
                calLoading.style.display = 'none';
            });
    }

    function yearMonth() {
        return currentYear + '-' + String(currentMonth + 1).padStart(2, '0');
    }

    // ---- Date selection ---------------------------------------------

    function selectDate(date) {
        selectedDate = date;
        selectedTime = '';
        dateInput.value = date;
        timeInput.value = '';
        continueBtn.disabled = true;
        if (mobileContinueBtn) mobileContinueBtn.disabled = true;

        // Build date object first — used for both labels
        const parts = date.split('-');
        const d     = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));

        // Update full date display (desktop)
        dateText.textContent = d.toLocaleDateString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric'});
        dateDisplay.style.display = 'block';

        // Update mobile sticky bar
        if (mobileBar) mobileBar.classList.add('visible');
        if (mobileDateLabel) mobileDateLabel.textContent = d.toLocaleDateString('en-US', {month:'short', day:'numeric'}) + ' — pick a time';

        renderCalendar(); // re-render to show selection
        fetchSlots(date);
    }

    // ---- Fetch time slots -------------------------------------------

    function fetchSlots(date) {
        slotsSection.style.display = 'block';
        slotsLoad.style.display    = 'block';
        slotsGrid.innerHTML        = '';
        slotsNone.style.display    = 'none';

        fetch(APP_URL + '/booking/slots.php?date=' + date)
            .then(r => r.json())
            .then(data => {
                slotsLoad.style.display = 'none';
                const slots = data.slots || [];

                if (slots.length === 0) {
                    slotsNone.style.display = 'block';
                    return;
                }

                slots.forEach(slot => {
                    const btn = document.createElement('button');
                    btn.type  = 'button';
                    btn.className = 'time-slot' + (slot.value === savedTime ? ' selected' : '');
                    btn.textContent = slot.display;
                    btn.dataset.value = slot.value;
                    btn.addEventListener('click', () => selectTime(slot.value));
                    slotsGrid.appendChild(btn);
                });

                // Re-select saved time if still available
                if (savedTime && date === savedDate) {
                    const match = slots.find(s => s.value === savedTime);
                    if (match) {
                        selectedTime = savedTime;
                        timeInput.value = savedTime;
                        continueBtn.disabled = false;
                    }
                }
            })
            .catch(() => {
                slotsLoad.style.display = 'none';
                slotsNone.style.display = 'block';
            });
    }

    function selectTime(value) {
        selectedTime = value;
        timeInput.value = value;
        continueBtn.disabled = false;

        document.querySelectorAll('.time-slot').forEach(b => {
            b.classList.toggle('selected', b.dataset.value === value);
        });

        // Update mobile sticky bar
        if (mobileContinueBtn) mobileContinueBtn.disabled = false;
        if (mobileDateLabel && selectedDate) {
            const parts = selectedDate.split('-');
            const d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
            const timeLabel = new Date('2000-01-01T' + value + ':00')
                .toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
            mobileDateLabel.textContent = d.toLocaleDateString('en-US', {month:'short', day:'numeric'}) + ' at ' + timeLabel;
        }
    }

    // ---- Month navigation -------------------------------------------

    btnPrev.addEventListener('click', () => {
        currentMonth--;
        if (currentMonth < 0) { currentMonth = 11; currentYear--; }
        fetchMonth(yearMonth());
    });

    btnNext.addEventListener('click', () => {
        currentMonth++;
        if (currentMonth > 11) { currentMonth = 0; currentYear++; }
        fetchMonth(yearMonth());
    });

    // ---- Init -------------------------------------------------------

    fetchMonth(yearMonth());

    // If we have a saved date, show slots immediately
    if (savedDate) {
        const parts = savedDate.split('-');
        const d     = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        dateText.textContent = d.toLocaleDateString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric'});
        dateDisplay.style.display = 'block';
        fetchSlots(savedDate);
        continueBtn.disabled = !savedTime;
    }

})();
</script>
