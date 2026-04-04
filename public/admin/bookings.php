<?php
/**
 * Admin — All Bookings list with filter/search.
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
$status    = $_GET['status']    ?? 'all';
$search    = trim($_GET['q']    ?? '');
$from_date = $_GET['from']      ?? '';
$to_date   = $_GET['to']        ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 25;

$where  = ['1=1'];
$params = [];

if ($status !== 'all') {
    $where[]  = 'b.booking_status = ?';
    $params[] = $status;
}

if ($search !== '') {
    $where[]  = '(b.booking_ref LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

if ($from_date) {
    $where[]  = 'b.event_date >= ?';
    $params[] = $from_date;
}

if ($to_date) {
    $where[]  = 'b.event_date <= ?';
    $params[] = $to_date;
}

$where_sql = implode(' AND ', $where);

// Total count for pagination
$count_stmt = $db->prepare(
    "SELECT COUNT(*) FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE {$where_sql}"
);
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
$offset = ($page - 1) * $per_page;

// Fetch page
$list_stmt = $db->prepare(
    "SELECT b.id, b.booking_ref, b.event_date, b.start_time, b.duration_hours,
            b.booking_status, b.payment_status, b.grand_total, b.amount_paid, b.balance_due,
            b.created_at, b.travel_fee,
            a.name AS attraction_name,
            c.first_name, c.last_name, c.email, c.phone
     FROM bookings b
     JOIN attractions a ON a.id = b.attraction_id
     JOIN customers c ON c.id = b.customer_id
     WHERE {$where_sql}
     ORDER BY b.event_date DESC, b.start_time DESC
     LIMIT {$per_page} OFFSET {$offset}"
);
$list_stmt->execute($params);
$bookings = $list_stmt->fetchAll();

// Status tab counts
$tab_counts = [];
foreach (['all','pending','confirmed','cancelled','completed'] as $s) {
    $cstmt = $db->prepare(
        "SELECT COUNT(*) FROM bookings b JOIN customers c ON c.id = b.customer_id
         WHERE " . ($s === 'all' ? '1=1' : 'b.booking_status = ?')
    );
    $cstmt->execute($s === 'all' ? [] : [$s]);
    $tab_counts[$s] = (int)$cstmt->fetchColumn();
}

// Build query string helper
function qs(array $overrides = []): string {
    $base = array_merge($_GET, $overrides);
    unset($base['page']);
    return http_build_query(array_filter($base, fn($v) => $v !== '' && $v !== null));
}

render_admin_header('Bookings', 'bookings');
?>

<!-- Filter Bar -->
<form method="GET" class="admin-filter-bar">
    <input type="text" name="q" class="form-input admin-search"
           placeholder="Search ref, name, email, phone…"
           value="<?= htmlspecialchars($search) ?>">
    <label class="d-flex align-center gap-1 text-sm text-dim" style="white-space:nowrap;">
        From <input type="date" name="from" class="form-input" value="<?= htmlspecialchars($from_date) ?>" style="width:auto;">
    </label>
    <label class="d-flex align-center gap-1 text-sm text-dim" style="white-space:nowrap;">
        To <input type="date" name="to" class="form-input" value="<?= htmlspecialchars($to_date) ?>" style="width:auto;">
    </label>
    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <a href="<?= APP_URL ?>/admin/bookings.php" class="btn btn-ghost btn-sm">Reset</a>
</form>

<!-- Status Tabs -->
<div class="admin-status-tabs">
    <?php foreach (['all' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled', 'completed' => 'Completed'] as $val => $label): ?>
    <a href="?<?= qs(['status' => $val]) ?>"
       class="admin-status-tab <?= $status === $val ? 'active' : '' ?>">
        <?= $label ?>
        <span class="admin-status-tab__count"><?= $tab_counts[$val] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Results -->
<div class="text-dim text-sm mb-2">
    <?= number_format($total) ?> booking<?= $total !== 1 ? 's' : '' ?> found
    <?php if ($page > 1 || $total > $per_page): ?>
        &mdash; page <?= $page ?> of <?= $total_pages ?>
    <?php endif; ?>
</div>

<?php if ($bookings): ?>
<div class="card" style="cursor:default;overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Ref</th>
                <th>Event Date</th>
                <th>Customer</th>
                <th>Activity</th>
                <th>Duration</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Status</th>
                <th>Payment</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($bookings as $row): ?>
            <tr>
                <td><code class="text-gold"><?= htmlspecialchars($row['booking_ref']) ?></code></td>
                <td>
                    <strong><?= date('M j, Y', strtotime($row['event_date'])) ?></strong>
                    <span class="text-dim d-block text-xs"><?= date('g:i A', strtotime($row['start_time'])) ?></span>
                </td>
                <td>
                    <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                    <span class="text-dim d-block text-xs"><?= htmlspecialchars($row['email']) ?></span>
                </td>
                <td class="text-sm"><?= htmlspecialchars($row['attraction_name']) ?></td>
                <td class="text-sm"><?= $row['duration_hours'] ?>h</td>
                <td>$<?= number_format($row['grand_total'], 2) ?></td>
                <td>
                    $<?= number_format($row['amount_paid'], 2) ?>
                    <?php if ($row['balance_due'] > 0): ?>
                    <span class="text-dim d-block text-xs">bal: $<?= number_format($row['balance_due'], 2) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= booking_status_badge($row['booking_status']) ?></td>
                <td><?= payment_status_badge($row['payment_status']) ?></td>
                <td>
                    <a href="<?= APP_URL ?>/admin/booking.php?id=<?= $row['id'] ?>"
                       class="btn btn-ghost btn-sm">View</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="admin-pagination">
    <?php if ($page > 1): ?>
    <a href="?<?= qs(['page' => $page - 1]) ?>" class="btn btn-ghost btn-sm">&larr; Prev</a>
    <?php endif; ?>

    <span class="text-dim text-sm">Page <?= $page ?> / <?= $total_pages ?></span>

    <?php if ($page < $total_pages): ?>
    <a href="?<?= qs(['page' => $page + 1]) ?>" class="btn btn-ghost btn-sm">Next &rarr;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<div class="alert alert-info">No bookings found matching your filters.</div>
<?php endif; ?>

<?php render_admin_footer(); ?>
