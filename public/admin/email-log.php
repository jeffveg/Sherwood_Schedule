<?php
/**
 * Admin — Email Log
 * Shows all email send attempts (sent and failed).
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
if (!in_array($filter, ['all','sent','failed'], true)) $filter = 'all';

$where  = $filter === 'all' ? '' : 'WHERE status = ' . $db->quote($filter);
$emails = $db->query(
    "SELECT * FROM email_log {$where} ORDER BY sent_at DESC LIMIT 300"
)->fetchAll();

$counts = $db->query(
    "SELECT status, COUNT(*) AS n FROM email_log GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

render_admin_header('Email Log', 'email-log');
?>

<!-- Filter bar -->
<div class="admin-filter-bar mb-3">
    <?php foreach (['all'=>'All','sent'=>'Sent','failed'=>'Failed'] as $val => $label):
        $active = $filter === $val ? 'btn-secondary' : 'btn-ghost';
        $count  = $val === 'all' ? array_sum($counts) : ($counts[$val] ?? 0);
    ?>
    <a href="?status=<?= $val ?>" class="btn <?= $active ?> btn-sm">
        <?= $label ?> <span class="text-dim">(<?= number_format($count) ?>)</span>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($emails): ?>
<div class="card" style="cursor:default;overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Sent At</th>
                <th>To</th>
                <th>Subject</th>
                <th>Status</th>
                <th>Error</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($emails as $e): ?>
        <tr>
            <td class="text-sm text-dim" style="white-space:nowrap;">
                <?= date('M j, Y g:i A', strtotime($e['sent_at'])) ?>
            </td>
            <td class="text-sm"><?= htmlspecialchars($e['to_address']) ?></td>
            <td class="text-sm" style="max-width:320px;">
                <?= htmlspecialchars($e['subject']) ?>
            </td>
            <td>
                <span class="badge <?= $e['status'] === 'sent' ? 'badge-success' : 'badge-danger' ?>">
                    <?= ucfirst($e['status']) ?>
                </span>
            </td>
            <td class="text-sm text-dim">
                <?= $e['error'] ? htmlspecialchars($e['error']) : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="alert alert-info">No emails logged yet.</div>
<?php endif; ?>

<?php render_admin_footer(); ?>
