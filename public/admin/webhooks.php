<?php
/**
 * Admin — Webhook Event Log
 * Shows inbound Square webhook events for debugging and audit.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

admin_require_login();
date_default_timezone_set(APP_TIMEZONE);

$db = get_db();

$filter = $_GET['status'] ?? 'all';
$allowed_filters = ['all','processed','ignored','duplicate','sig_failed','error'];
if (!in_array($filter, $allowed_filters, true)) $filter = 'all';

$where = $filter === 'all' ? '' : 'WHERE status = ' . $db->quote($filter);

$events = $db->query(
    "SELECT * FROM webhook_events {$where} ORDER BY received_at DESC LIMIT 200"
)->fetchAll();

// Counts per status for the filter bar
$counts = $db->query(
    "SELECT status, COUNT(*) AS n FROM webhook_events GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

render_admin_header('Webhook Log', 'webhooks');
?>

<!-- Filter bar -->
<div class="admin-filter-bar mb-3">
    <?php
    $labels = ['all'=>'All','processed'=>'Processed','ignored'=>'Ignored',
               'duplicate'=>'Duplicate','sig_failed'=>'Sig Failed','error'=>'Error'];
    foreach ($labels as $val => $label):
        $active = $filter === $val ? 'btn-secondary' : 'btn-ghost';
        $count  = $val === 'all' ? array_sum($counts) : ($counts[$val] ?? 0);
    ?>
    <a href="?status=<?= $val ?>" class="btn <?= $active ?> btn-sm">
        <?= $label ?> <span class="text-dim">(<?= number_format($count) ?>)</span>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($events): ?>
<div class="card" style="cursor:default;overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Received</th>
                <th>Event Type</th>
                <th>Booking</th>
                <th>Payment ID</th>
                <th>Status</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($events as $e): ?>
        <tr>
            <td class="text-sm text-dim" style="white-space:nowrap;">
                <?= date('M j, Y g:i:s A', strtotime($e['received_at'])) ?>
            </td>
            <td class="text-sm">
                <code><?= htmlspecialchars($e['event_type'] ?? '—') ?></code>
            </td>
            <td>
                <?php if ($e['booking_ref']): ?>
                    <?php
                    $bid = $db->prepare('SELECT id FROM bookings WHERE booking_ref = ?');
                    $bid->execute([$e['booking_ref']]);
                    $brow = $bid->fetch();
                    ?>
                    <?php if ($brow): ?>
                    <a href="<?= APP_URL ?>/admin/booking.php?id=<?= $brow['id'] ?>"
                       class="text-gold text-sm"><?= htmlspecialchars($e['booking_ref']) ?></a>
                    <?php else: ?>
                    <span class="text-sm"><?= htmlspecialchars($e['booking_ref']) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-dim">—</span>
                <?php endif; ?>
            </td>
            <td class="text-sm text-dim" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $e['payment_id'] ? htmlspecialchars($e['payment_id']) : '—' ?>
            </td>
            <td>
                <?php
                $badge = match($e['status']) {
                    'processed'  => 'badge-success',
                    'ignored'    => 'badge-default',
                    'duplicate'  => 'badge-warning',
                    'sig_failed' => 'badge-danger',
                    'error'      => 'badge-danger',
                    default      => 'badge-default',
                };
                ?>
                <span class="badge <?= $badge ?>"><?= ucfirst(str_replace('_',' ',$e['status'])) ?></span>
            </td>
            <td class="text-sm"><?= htmlspecialchars($e['notes'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="alert alert-info">No webhook events recorded yet.</div>
<?php endif; ?>

<?php render_admin_footer(); ?>
