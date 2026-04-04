<?php
/**
 * Admin Dashboard
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

admin_require_login();
date_default_timezone_set(APP_TIMEZONE);

$db = get_db();

// ── Stats ──────────────────────────────────────────────────────────────────
$today    = date('Y-m-d');
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');

// Upcoming confirmed bookings (today + future)
$upcoming_count = (int)$db->prepare(
    "SELECT COUNT(*) FROM bookings WHERE event_date >= ? AND booking_status NOT IN ('cancelled','rescheduled')"
)->execute([$today]) ?: 0;
$stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE event_date >= ? AND booking_status NOT IN ('cancelled','rescheduled')");
$stmt->execute([$today]);
$upcoming_count = (int)$stmt->fetchColumn();

// This month's revenue (paid)
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(amount_paid),0) FROM bookings
     WHERE event_date BETWEEN ? AND ? AND payment_status IN ('deposit_paid','paid_in_full')"
);
$stmt->execute([$month_start, $month_end]);
$month_revenue = (float)$stmt->fetchColumn();

// Pending payment (confirmed but not paid in full)
$stmt = $db->query(
    "SELECT COUNT(*) FROM bookings WHERE booking_status = 'confirmed' AND payment_status = 'deposit_paid'"
);
$balance_due_count = (int)$stmt->fetchColumn();

// New this month
$stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE created_at >= ?");
$stmt->execute([$month_start . ' 00:00:00']);
$new_this_month = (int)$stmt->fetchColumn();

// ── Upcoming bookings (next 10) ────────────────────────────────────────────
$upcoming = $db->prepare(
    "SELECT b.id, b.booking_ref, b.event_date, b.start_time, b.duration_hours,
            b.booking_status, b.payment_status, b.grand_total, b.amount_paid,
            a.name AS attraction_name,
            c.first_name, c.last_name, c.phone
     FROM bookings b
     JOIN attractions a ON a.id = b.attraction_id
     JOIN customers c ON c.id = b.customer_id
     WHERE b.event_date >= ? AND b.booking_status NOT IN ('cancelled','rescheduled')
     ORDER BY b.event_date ASC, b.start_time ASC
     LIMIT 10"
);
$upcoming->execute([$today]);
$upcoming_rows = $upcoming->fetchAll();

// ── Recent bookings (last 5 created) ──────────────────────────────────────
$recent = $db->query(
    "SELECT b.id, b.booking_ref, b.event_date, b.booking_status, b.payment_status,
            b.grand_total, b.created_at,
            a.name AS attraction_name,
            c.first_name, c.last_name
     FROM bookings b
     JOIN attractions a ON a.id = b.attraction_id
     JOIN customers c ON c.id = b.customer_id
     ORDER BY b.created_at DESC
     LIMIT 5"
)->fetchAll();

render_admin_header('Dashboard', 'dashboard');
?>

<!-- Stat Cards -->
<div class="admin-stats">
    <div class="admin-stat-card">
        <div class="admin-stat-card__value"><?= $upcoming_count ?></div>
        <div class="admin-stat-card__label">Upcoming Events</div>
    </div>
    <div class="admin-stat-card admin-stat-card--gold">
        <div class="admin-stat-card__value">$<?= number_format($month_revenue, 0) ?></div>
        <div class="admin-stat-card__label">Revenue This Month</div>
    </div>
    <div class="admin-stat-card <?= $balance_due_count > 0 ? 'admin-stat-card--warning' : '' ?>">
        <div class="admin-stat-card__value"><?= $balance_due_count ?></div>
        <div class="admin-stat-card__label">Balances Pending</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__value"><?= $new_this_month ?></div>
        <div class="admin-stat-card__label">New This Month</div>
    </div>
</div>

<!-- Upcoming Events -->
<div class="admin-section-header">
    <h2 class="admin-section-title">Upcoming Events</h2>
    <a href="<?= APP_URL ?>/admin/bookings.php" class="btn btn-ghost btn-sm">View All</a>
</div>

<?php if ($upcoming_rows): ?>
<div class="card" style="cursor:default;overflow:hidden;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Ref</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Activity</th>
                <th>Duration</th>
                <th>Total</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($upcoming_rows as $row): ?>
            <tr>
                <td><code class="text-gold"><?= htmlspecialchars($row['booking_ref']) ?></code></td>
                <td>
                    <strong><?= date('M j', strtotime($row['event_date'])) ?></strong>
                    <span class="text-dim"> <?= date('g:i A', strtotime($row['start_time'])) ?></span>
                </td>
                <td>
                    <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                    <span class="text-dim text-xs d-block"><?= htmlspecialchars($row['phone']) ?></span>
                </td>
                <td class="text-sm"><?= htmlspecialchars($row['attraction_name']) ?></td>
                <td class="text-sm"><?= $row['duration_hours'] ?>h</td>
                <td>$<?= number_format($row['grand_total'], 2) ?></td>
                <td>
                    <?= booking_status_badge($row['booking_status']) ?>
                    <?= payment_status_badge($row['payment_status']) ?>
                </td>
                <td>
                    <a href="<?= APP_URL ?>/admin/booking.php?id=<?= $row['id'] ?>"
                       class="btn btn-ghost btn-sm">View</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="alert alert-info">No upcoming events.</div>
<?php endif; ?>

<!-- Recent Bookings -->
<div class="admin-section-header mt-4">
    <h2 class="admin-section-title">Recently Created</h2>
</div>

<?php if ($recent): ?>
<div class="card" style="cursor:default;overflow:hidden;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Ref</th>
                <th>Created</th>
                <th>Customer</th>
                <th>Activity</th>
                <th>Event Date</th>
                <th>Total</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $row): ?>
            <tr>
                <td><code class="text-gold"><?= htmlspecialchars($row['booking_ref']) ?></code></td>
                <td class="text-sm text-dim"><?= date('M j, g:i A', strtotime($row['created_at'])) ?></td>
                <td><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                <td class="text-sm"><?= htmlspecialchars($row['attraction_name']) ?></td>
                <td><?= date('M j, Y', strtotime($row['event_date'])) ?></td>
                <td>$<?= number_format($row['grand_total'], 2) ?></td>
                <td>
                    <?= booking_status_badge($row['booking_status']) ?>
                    <?= payment_status_badge($row['payment_status']) ?>
                </td>
                <td>
                    <a href="<?= APP_URL ?>/admin/booking.php?id=<?= $row['id'] ?>"
                       class="btn btn-ghost btn-sm">View</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php render_admin_footer(); ?>
