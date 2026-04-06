<?php
/**
 * Admin — Customers list with search and booking summary.
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
$search   = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.organization LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like, $like]);
}

$where_sql = implode(' AND ', $where);

// Total count
$count_stmt = $db->prepare("SELECT COUNT(*) FROM customers c WHERE {$where_sql}");
$count_stmt->execute($params);
$total       = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
$offset      = ($page - 1) * $per_page;

// Fetch customers with booking summary
$list_stmt = $db->prepare(
    "SELECT c.*,
            COUNT(b.id)                                          AS booking_count,
            MAX(b.event_date)                                    AS last_event,
            SUM(CASE WHEN b.booking_status NOT IN ('cancelled','rescheduled') THEN b.grand_total ELSE 0 END) AS total_spent
     FROM customers c
     LEFT JOIN bookings b ON b.customer_id = c.id
     WHERE {$where_sql}
     GROUP BY c.id
     ORDER BY c.last_name ASC, c.first_name ASC
     LIMIT {$per_page} OFFSET {$offset}"
);
$list_stmt->execute($params);
$customers = $list_stmt->fetchAll();

// Query string helper
function cqs(array $overrides = []): string {
    $base = array_merge($_GET, $overrides);
    unset($base['page']);
    return http_build_query(array_filter($base, fn($v) => $v !== '' && $v !== null));
}

render_admin_header('Customers', 'customers');
?>

<!-- Filter Bar -->
<form method="GET" class="admin-filter-bar">
    <input type="text" name="q" class="form-input admin-search"
           placeholder="Search name, email, phone, organization…"
           value="<?= htmlspecialchars($search) ?>">
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= APP_URL ?>/admin/customers.php" class="btn btn-ghost btn-sm">Reset</a>
</form>

<div class="text-dim text-sm mb-2">
    <?= number_format($total) ?> customer<?= $total !== 1 ? 's' : '' ?> found
    <?php if ($page > 1 || $total > $per_page): ?>
        &mdash; page <?= $page ?> of <?= $total_pages ?>
    <?php endif; ?>
</div>

<?php if ($customers): ?>
<div class="card" style="cursor:default;overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Organization</th>
                <th class="text-right">Bookings</th>
                <th>Last Event</th>
                <th class="text-right">Total Spent</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($customers as $c): ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></strong>
                    <?php if ($c['is_nonprofit']): ?>
                        <span class="badge badge-info" style="margin-left:4px;">Nonprofit</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="mailto:<?= htmlspecialchars($c['email']) ?>"><?= htmlspecialchars($c['email']) ?></a>
                </td>
                <td>
                    <a href="tel:<?= htmlspecialchars($c['phone']) ?>"><?= htmlspecialchars($c['phone']) ?></a>
                </td>
                <td class="text-sm text-dim"><?= htmlspecialchars($c['organization'] ?? '—') ?></td>
                <td class="text-right"><?= (int)$c['booking_count'] ?></td>
                <td class="text-sm">
                    <?= $c['last_event'] ? date('M j, Y', strtotime($c['last_event'])) : '—' ?>
                </td>
                <td class="text-right">
                    <?= $c['total_spent'] > 0 ? '$' . number_format($c['total_spent'], 2) : '—' ?>
                </td>
                <td>
                    <a href="<?= APP_URL ?>/admin/customers.php?view=<?= $c['id'] ?>&<?= cqs() ?>"
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
    <a href="?<?= cqs(['page' => $page - 1]) ?>" class="btn btn-ghost btn-sm">&larr; Prev</a>
    <?php endif; ?>
    <span class="text-dim text-sm">Page <?= $page ?> / <?= $total_pages ?></span>
    <?php if ($page < $total_pages): ?>
    <a href="?<?= cqs(['page' => $page + 1]) ?>" class="btn btn-ghost btn-sm">Next &rarr;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<div class="alert alert-info">No customers found.</div>
<?php endif; ?>

<?php
// ── Customer detail panel ─────────────────────────────────────────────────
$view_id = (int)($_GET['view'] ?? 0);
if ($view_id):
    $cust_stmt = $db->prepare('SELECT * FROM customers WHERE id = ?');
    $cust_stmt->execute([$view_id]);
    $cust = $cust_stmt->fetch();

    if ($cust):
        $bk_stmt = $db->prepare(
            "SELECT b.id, b.booking_ref, b.event_date, b.start_time, b.duration_hours,
                    b.booking_status, b.payment_status, b.grand_total, b.amount_paid, b.balance_due,
                    a.name AS attraction_name
             FROM bookings b
             JOIN attractions a ON a.id = b.attraction_id
             WHERE b.customer_id = ?
             ORDER BY b.event_date DESC"
        );
        $bk_stmt->execute([$view_id]);
        $cust_bookings = $bk_stmt->fetchAll();
?>
<div class="admin-panel mt-4">
    <div class="admin-panel__header">
        <?= htmlspecialchars($cust['first_name'] . ' ' . $cust['last_name']) ?>
        <?php if ($cust['is_nonprofit']): ?>
            <span class="badge badge-info">Nonprofit</span>
        <?php endif; ?>
    </div>
    <div class="admin-panel__body">
        <div class="admin-detail-grid">
            <div>
                <table class="detail-table">
                    <tr><td>Email</td><td><a href="mailto:<?= htmlspecialchars($cust['email']) ?>"><?= htmlspecialchars($cust['email']) ?></a></td></tr>
                    <tr><td>Phone</td><td><a href="tel:<?= htmlspecialchars($cust['phone']) ?>"><?= htmlspecialchars($cust['phone']) ?></a></td></tr>
                    <?php if ($cust['organization']): ?>
                    <tr><td>Organization</td><td><?= htmlspecialchars($cust['organization']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td>Customer Since</td><td><?= date('M j, Y', strtotime($cust['created_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <?php if ($cust_bookings): ?>
        <h4 style="margin:1.25rem 0 0.5rem;font-size:0.875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--orange);">Booking History</h4>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Ref</th>
                    <th>Event Date</th>
                    <th>Activity</th>
                    <th>Duration</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cust_bookings as $bk): ?>
                <tr>
                    <td><code class="text-gold"><?= htmlspecialchars($bk['booking_ref']) ?></code></td>
                    <td>
                        <strong><?= date('M j, Y', strtotime($bk['event_date'])) ?></strong>
                        <span class="text-dim d-block text-xs"><?= date('g:i A', strtotime($bk['start_time'])) ?></span>
                    </td>
                    <td class="text-sm"><?= htmlspecialchars($bk['attraction_name']) ?></td>
                    <td class="text-sm"><?= $bk['duration_hours'] ?>h</td>
                    <td>$<?= number_format($bk['grand_total'], 2) ?></td>
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
        <p class="text-dim text-sm mt-3">No bookings yet.</p>
        <?php endif; ?>
    </div>
</div>
<?php
    endif;
endif;
?>

<?php render_admin_footer(); ?>
