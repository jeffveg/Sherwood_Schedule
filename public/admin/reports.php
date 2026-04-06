<?php
/**
 * Admin — Revenue Reports with CSV export.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

admin_require_login();
date_default_timezone_set(APP_TIMEZONE);

$db = get_db();

// ── Filters ────────────────────────────────────────────────────────────────
$last_month_start = date('Y-m-01', strtotime('first day of last month'));
$last_month_end   = date('Y-m-t',  strtotime('last day of last month'));

$from_date  = $_GET['from']   ?? date('Y-m-01');
$to_date    = $_GET['to']     ?? date('Y-m-d');
$group_by   = in_array($_GET['group'] ?? '', ['day','month','attraction']) ? $_GET['group'] : 'month';
$csv        = isset($_GET['csv']);

// ── Query ──────────────────────────────────────────────────────────────────
$group_expr = match($group_by) {
    'day'        => "DATE(b.event_date)",
    'attraction' => "a.name",
    default      => "DATE_FORMAT(b.event_date, '%Y-%m')",
};

$label_expr = match($group_by) {
    'day'        => "DATE_FORMAT(b.event_date, '%b %e, %Y')",
    'attraction' => "a.name",
    default      => "DATE_FORMAT(b.event_date, '%M %Y')",
};

$stmt = $db->prepare(
    "SELECT
        {$label_expr}                         AS period,
        COUNT(b.id)                           AS bookings,
        SUM(b.attraction_price)               AS attraction_revenue,
        SUM(b.addons_subtotal)                AS addons_revenue,
        SUM(b.travel_fee)                     AS travel_revenue,
        SUM(b.coupon_discount)                AS discounts,
        SUM(b.taxable_subtotal)               AS taxable_amount,
        SUM(b.tax_state)                      AS tax_state,
        SUM(b.tax_county)                     AS tax_county,
        SUM(b.tax_city)                       AS tax_city,
        SUM(b.tax_total)                      AS tax_total,
        SUM(b.grand_total)                    AS grand_total,
        SUM(b.amount_paid)                    AS collected
     FROM bookings b
     JOIN attractions a ON a.id = b.attraction_id
     WHERE b.event_date BETWEEN ? AND ?
       AND b.booking_status NOT IN ('cancelled','rescheduled')
     GROUP BY {$group_expr}, period
     ORDER BY MIN(b.event_date) ASC"
);
$stmt->execute([$from_date, $to_date]);
$rows = $stmt->fetchAll();

// ── Totals ─────────────────────────────────────────────────────────────────
$totals = [
    'bookings'            => 0, 'attraction_revenue' => 0, 'addons_revenue' => 0,
    'travel_revenue'      => 0, 'discounts'          => 0, 'taxable_amount' => 0,
    'tax_state'           => 0, 'tax_county'         => 0, 'tax_city'       => 0,
    'tax_total'           => 0, 'grand_total'        => 0, 'collected'      => 0,
];
foreach ($rows as $r) {
    foreach ($totals as $k => $_) {
        $totals[$k] += (float)$r[$k];
    }
}

// ── CSV export ─────────────────────────────────────────────────────────────
if ($csv) {
    $period_label = match($group_by) {
        'day'        => 'Date',
        'attraction' => 'Attraction',
        default      => 'Month',
    };
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sherwood-revenue-' . $from_date . '-to-' . $to_date . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        $period_label, 'Bookings', 'Attraction Revenue', 'Add-ons Revenue',
        'Travel Revenue', 'Discounts', 'Taxable Amount',
        'State & County Tax', 'City Tax', 'Total Tax', 'Grand Total', 'Collected',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['period'],
            $r['bookings'],
            number_format($r['attraction_revenue'], 2),
            number_format($r['addons_revenue'],     2),
            number_format($r['travel_revenue'],     2),
            number_format($r['discounts'],          2),
            number_format($r['taxable_amount'],     2),
            number_format($r['tax_state'] + $r['tax_county'], 2),
            number_format($r['tax_city'],           2),
            number_format($r['tax_total'],          2),
            number_format($r['grand_total'],        2),
            number_format($r['collected'],          2),
        ]);
    }
    fputcsv($out, [
        'TOTAL',
        $totals['bookings'],
        number_format($totals['attraction_revenue'], 2),
        number_format($totals['addons_revenue'],     2),
        number_format($totals['travel_revenue'],     2),
        number_format($totals['discounts'],          2),
        number_format($totals['taxable_amount'],     2),
        number_format($totals['tax_state'] + $totals['tax_county'], 2),
        number_format($totals['tax_city'],           2),
        number_format($totals['tax_total'],          2),
        number_format($totals['grand_total'],        2),
        number_format($totals['collected'],          2),
    ]);
    fclose($out);
    exit;
}

// ── Render ─────────────────────────────────────────────────────────────────
render_admin_header('Reports', 'reports');

$period_label = match($group_by) {
    'day'        => 'Date',
    'attraction' => 'Attraction',
    default      => 'Month',
};
?>

<!-- Filter Bar -->
<form method="GET" class="admin-filter-bar">
    <label class="d-flex align-center gap-1 text-sm text-dim" style="white-space:nowrap;">
        From <input type="date" name="from" class="form-input" value="<?= htmlspecialchars($from_date) ?>" style="width:auto;">
    </label>
    <label class="d-flex align-center gap-1 text-sm text-dim" style="white-space:nowrap;">
        To <input type="date" name="to" class="form-input" value="<?= htmlspecialchars($to_date) ?>" style="width:auto;">
    </label>
    <select name="group" class="form-input" style="width:auto;">
        <option value="month"      <?= $group_by === 'month'      ? 'selected' : '' ?>>By Month</option>
        <option value="day"        <?= $group_by === 'day'        ? 'selected' : '' ?>>By Day</option>
        <option value="attraction" <?= $group_by === 'attraction' ? 'selected' : '' ?>>By Attraction</option>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm">Run</button>
    <a href="?from=<?= urlencode($last_month_start) ?>&to=<?= urlencode($last_month_end) ?>&group=day"
       class="btn btn-ghost btn-sm" title="<?= date('F Y', strtotime('last month')) ?>">
        Last Month
    </a>
    <a href="?from=<?= urlencode($from_date) ?>&to=<?= urlencode($to_date) ?>&group=<?= $group_by ?>&csv=1"
       class="btn btn-ghost btn-sm">&#8595; CSV</a>
</form>

<!-- Summary Stat Cards -->
<div class="admin-stats mb-3">
    <div class="admin-stat-card">
        <div class="admin-stat-card__value"><?= number_format($totals['bookings']) ?></div>
        <div class="admin-stat-card__label">Bookings</div>
    </div>
    <div class="admin-stat-card admin-stat-card--gold">
        <div class="admin-stat-card__value">$<?= number_format($totals['grand_total'], 0) ?></div>
        <div class="admin-stat-card__label">Gross Revenue</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__value">$<?= number_format($totals['tax_total'], 0) ?></div>
        <div class="admin-stat-card__label">Tax Collected</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__value">$<?= number_format($totals['collected'], 0) ?></div>
        <div class="admin-stat-card__label">Cash Collected</div>
    </div>
</div>

<?php if ($rows): ?>
<div class="card" style="cursor:default;overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th><?= $period_label ?></th>
                <th class="text-right">#</th>
                <th class="text-right">Attraction</th>
                <th class="text-right">Add-ons</th>
                <th class="text-right">Travel</th>
                <th class="text-right">Discounts</th>
                <th class="text-right">Taxable Amt</th>
                <th class="text-right">State &amp; County Tax</th>
                <th class="text-right">City Tax</th>
                <th class="text-right">Tax Total</th>
                <th class="text-right">Grand Total</th>
                <th class="text-right">Collected</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['period']) ?></strong></td>
                <td class="text-right text-sm"><?= (int)$r['bookings'] ?></td>
                <td class="text-right text-sm">$<?= number_format($r['attraction_revenue'], 2) ?></td>
                <td class="text-right text-sm">$<?= number_format($r['addons_revenue'],     2) ?></td>
                <td class="text-right text-sm">$<?= number_format($r['travel_revenue'],     2) ?></td>
                <td class="text-right text-sm <?= $r['discounts'] > 0 ? 'text-success' : 'text-dim' ?>">
                    <?= $r['discounts'] > 0 ? '−$' . number_format($r['discounts'], 2) : '—' ?>
                </td>
                <td class="text-right text-sm">$<?= number_format($r['taxable_amount'],    2) ?></td>
                <td class="text-right text-sm">$<?= number_format($r['tax_state'] + $r['tax_county'], 2) ?></td>
                <td class="text-right text-sm">$<?= number_format($r['tax_city'],          2) ?></td>
                <td class="text-right text-sm">$<?= number_format($r['tax_total'],         2) ?></td>
                <td class="text-right"><strong>$<?= number_format($r['grand_total'],       2) ?></strong></td>
                <td class="text-right text-success">$<?= number_format($r['collected'],    2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="border-top:2px solid var(--gold);">
                <td><strong>Total</strong></td>
                <td class="text-right"><strong><?= number_format($totals['bookings']) ?></strong></td>
                <td class="text-right"><strong>$<?= number_format($totals['attraction_revenue'], 2) ?></strong></td>
                <td class="text-right"><strong>$<?= number_format($totals['addons_revenue'],     2) ?></strong></td>
                <td class="text-right"><strong>$<?= number_format($totals['travel_revenue'],     2) ?></strong></td>
                <td class="text-right"><strong><?= $totals['discounts'] > 0 ? '−$' . number_format($totals['discounts'], 2) : '—' ?></strong></td>
                <td class="text-right"><strong>$<?= number_format($totals['taxable_amount'],     2) ?></strong></td>
                <td class="text-right"><strong>$<?= number_format($totals['tax_state'] + $totals['tax_county'], 2) ?></strong></td>
                <td class="text-right"><strong>$<?= number_format($totals['tax_city'],           2) ?></strong></td>
                <td class="text-right"><strong>$<?= number_format($totals['tax_total'],          2) ?></strong></td>
                <td class="text-right"><strong class="text-gold">$<?= number_format($totals['grand_total'], 2) ?></strong></td>
                <td class="text-right text-success"><strong>$<?= number_format($totals['collected'],        2) ?></strong></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php else: ?>
<div class="alert alert-info">No bookings found for the selected date range.</div>
<?php endif; ?>

<?php render_admin_footer(); ?>
