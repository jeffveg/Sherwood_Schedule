<?php
/**
 * Admin — Calendar View
 * Monthly calendar showing bookings as colored blocks.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

admin_require_login();
date_default_timezone_set(APP_TIMEZONE);

$db = get_db();

// ── Month navigation ───────────────────────────────────────────────────────
$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

// Clamp
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$first_day   = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = (int)date('t', $first_day);
$start_dow   = (int)date('w', $first_day); // 0=Sun

$month_start = date('Y-m-d', $first_day);
$month_end   = date('Y-m-d', mktime(0, 0, 0, $month + 1, 0, $year));

$prev_year  = $month == 1  ? $year - 1 : $year;
$prev_month = $month == 1  ? 12 : $month - 1;
$next_year  = $month == 12 ? $year + 1 : $year;
$next_month = $month == 12 ? 1  : $month + 1;

// ── Load bookings for this month ───────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT b.id, b.booking_ref, b.event_date, b.start_time, b.duration_hours,
            b.booking_status, b.payment_status,
            a.name AS attraction_name, a.slug AS attraction_slug,
            c.first_name, c.last_name
     FROM bookings b
     JOIN attractions a ON a.id = b.attraction_id
     JOIN customers c ON c.id = b.customer_id
     WHERE b.event_date BETWEEN ? AND ?
       AND b.booking_status NOT IN ('cancelled','rescheduled')
     ORDER BY b.start_time ASC"
);
$stmt->execute([$month_start, $month_end]);
$all_bookings = $stmt->fetchAll();

// Group bookings by date
$by_date = [];
foreach ($all_bookings as $b) {
    $by_date[$b['event_date']][] = $b;
}

// ── Load availability exceptions ──────────────────────────────────────────
$exc_stmt = $db->prepare(
    'SELECT * FROM availability_exceptions WHERE exception_date BETWEEN ? AND ?'
);
$exc_stmt->execute([$month_start, $month_end]);
$exceptions = $exc_stmt->fetchAll();
$exc_map = [];
foreach ($exceptions as $e) { $exc_map[$e['exception_date']] = $e; }

// Selected day for detail panel
$selected = $_GET['day'] ?? null;
$selected_bookings = $selected ? ($by_date[$selected] ?? []) : [];

$today = date('Y-m-d');

render_admin_header(date('F Y', $first_day), 'calendar');

// Slug → CSS colour
function slug_color(string $slug): string {
    return match($slug) {
        'archery-tag' => '#fed611',
        'hoverball'   => '#ffa133',
        'combo'       => '#7ec89a',
        default       => '#888',
    };
}
?>

<!-- Month Nav -->
<div class="d-flex align-center justify-between mb-3">
    <a href="?year=<?= $prev_year ?>&month=<?= $prev_month ?>" class="btn btn-ghost btn-sm">&larr; <?= date('M', mktime(0,0,0,$prev_month,1,$prev_year)) ?></a>
    <h2 style="font-family:var(--font-heading);font-size:1.8rem;color:var(--gold);margin:0;">
        <?= date('F Y', $first_day) ?>
    </h2>
    <a href="?year=<?= $next_year ?>&month=<?= $next_month ?>" class="btn btn-ghost btn-sm"><?= date('M', mktime(0,0,0,$next_month,1,$next_year)) ?> &rarr;</a>
</div>

<!-- Calendar Grid -->
<div class="cal-grid">
    <!-- Day-of-week headers -->
    <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
    <div class="cal-dow"><?= $dow ?></div>
    <?php endforeach; ?>

    <!-- Empty cells before month start -->
    <?php for ($i = 0; $i < $start_dow; $i++): ?>
    <div class="cal-cell cal-cell--empty"></div>
    <?php endfor; ?>

    <!-- Day cells -->
    <?php for ($d = 1; $d <= $days_in_month; $d++):
        $date_str  = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $day_bkgs  = $by_date[$date_str] ?? [];
        $exc       = $exc_map[$date_str] ?? null;
        $is_today  = $date_str === $today;
        $is_sel    = $date_str === $selected;
        $is_closed = $exc && $exc['is_closed'];
    ?>
    <a href="?year=<?= $year ?>&month=<?= $month ?>&day=<?= $date_str ?>"
       class="cal-cell <?= $is_today ? 'cal-cell--today' : '' ?> <?= $is_sel ? 'cal-cell--selected' : '' ?> <?= $is_closed ? 'cal-cell--closed' : '' ?>">

        <div class="cal-cell__num"><?= $d ?></div>

        <?php if ($is_closed): ?>
            <div class="cal-cell__closed">Closed</div>
        <?php elseif ($exc): ?>
            <div class="cal-cell__exc">Adj. hrs</div>
        <?php endif; ?>

        <?php foreach (array_slice($day_bkgs, 0, 3) as $bk): ?>
        <div class="cal-event" style="border-left-color:<?= slug_color($bk['attraction_slug']) ?>;">
            <span class="cal-event__time"><?= date('g:i', strtotime($bk['start_time'])) ?></span>
            <?= htmlspecialchars($bk['last_name']) ?>
        </div>
        <?php endforeach; ?>

        <?php if (count($day_bkgs) > 3): ?>
        <div class="cal-more">+<?= count($day_bkgs) - 3 ?> more</div>
        <?php endif; ?>
    </a>
    <?php endfor; ?>
</div>

<!-- Legend -->
<div class="d-flex gap-2 flex-wrap mt-2 mb-3">
    <?php foreach ([['archery-tag','Archery Tag'],['hoverball','Hoverball'],['combo','Combo']] as [$slug, $label]): ?>
    <div class="d-flex align-center gap-1 text-sm">
        <span style="width:10px;height:10px;background:<?= slug_color($slug) ?>;border-radius:2px;display:inline-block;"></span>
        <?= $label ?>
    </div>
    <?php endforeach; ?>
    <div class="d-flex align-center gap-1 text-sm text-dim">
        <span style="width:10px;height:10px;background:rgba(192,57,43,0.25);border-radius:2px;display:inline-block;"></span>
        Closed
    </div>
</div>

<?php if ($selected): ?>
<!-- Day Detail Panel -->
<div class="admin-panel mt-3">
    <div class="admin-panel__header">
        <?= date('l, F j, Y', strtotime($selected)) ?>
        <?php if ($exc_map[$selected] ?? null): ?>
            <?php $e = $exc_map[$selected]; ?>
            — <?= $e['is_closed'] ? '<span class="badge badge-danger">Closed</span>' : '<span class="badge badge-warning">Adjusted Hours</span>' ?>
            <?= $e['note'] ? htmlspecialchars(' — ' . $e['note']) : '' ?>
        <?php endif; ?>
    </div>
    <div class="admin-panel__body">
        <?php if ($selected_bookings): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Ref</th>
                    <th>Customer</th>
                    <th>Activity</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($selected_bookings as $bk): ?>
                <tr>
                    <td><strong><?= date('g:i A', strtotime($bk['start_time'])) ?></strong></td>
                    <td><code class="text-gold"><?= htmlspecialchars($bk['booking_ref']) ?></code></td>
                    <td><?= htmlspecialchars($bk['first_name'] . ' ' . $bk['last_name']) ?></td>
                    <td class="text-sm"><?= htmlspecialchars($bk['attraction_name']) ?></td>
                    <td class="text-sm"><?= $bk['duration_hours'] ?>h</td>
                    <td>
                        <?= booking_status_badge($bk['booking_status']) ?>
                        <?= payment_status_badge($bk['payment_status']) ?>
                    </td>
                    <td>
                        <a href="<?= APP_URL ?>/admin/booking.php?id=<?= $bk['id'] ?>"
                           class="btn btn-ghost btn-sm">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="text-dim text-sm">No bookings on this date.</p>
        <?php endif; ?>

        <div class="mt-2">
            <a href="<?= APP_URL ?>/admin/availability.php" class="btn btn-ghost btn-sm">
                <?= isset($exc_map[$selected]) ? 'Edit Exception' : '+ Add Exception for This Date' ?>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php render_admin_footer(); ?>
