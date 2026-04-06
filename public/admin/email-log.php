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

$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));

$where = $filter === 'all' ? '' : 'WHERE status = ' . $db->quote($filter);

// Total count for pagination
$total  = (int)$db->query("SELECT COUNT(*) FROM email_log {$where}")->fetchColumn();
$pages  = max(1, (int)ceil($total / $per_page));
$page   = min($page, $pages);
$offset = ($page - 1) * $per_page;

$emails = $db->query(
    "SELECT * FROM email_log {$where} ORDER BY sent_at DESC LIMIT {$per_page} OFFSET {$offset}"
)->fetchAll();

$counts = $db->query(
    "SELECT status, COUNT(*) AS n FROM email_log GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// Helper: build URL preserving current filters
function el_url(array $overrides): string {
    global $filter, $page;
    $params = array_merge(['status' => $filter, 'page' => $page], $overrides);
    return '?' . http_build_query($params);
}

render_admin_header('Email Log', 'email-log');
?>

<!-- Filter bar -->
<div class="admin-filter-bar mb-3">
    <?php foreach (['all'=>'All','sent'=>'Sent','failed'=>'Failed'] as $val => $label):
        $active = $filter === $val ? 'btn-secondary' : 'btn-ghost';
        $count  = $val === 'all' ? array_sum($counts) : ($counts[$val] ?? 0);
    ?>
    <a href="<?= el_url(['status' => $val, 'page' => 1]) ?>" class="btn <?= $active ?> btn-sm">
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

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="d-flex align-center gap-1 mt-3" style="justify-content:space-between;">
    <div class="text-dim text-sm">
        Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total)) ?>
        of <?= number_format($total) ?>
    </div>
    <div class="d-flex gap-1">
        <?php if ($page > 1): ?>
        <a href="<?= el_url(['page' => $page - 1]) ?>" class="btn btn-ghost btn-sm">&larr; Prev</a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 2);
        $end   = min($pages, $page + 2);
        for ($p = $start; $p <= $end; $p++):
        ?>
        <a href="<?= el_url(['page' => $p]) ?>"
           class="btn btn-sm <?= $p === $page ? 'btn-secondary' : 'btn-ghost' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($page < $pages): ?>
        <a href="<?= el_url(['page' => $page + 1]) ?>" class="btn btn-ghost btn-sm">Next &rarr;</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="alert alert-info">No emails logged yet.</div>
<?php endif; ?>

<?php render_admin_footer(); ?>
