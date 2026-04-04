<?php
/**
 * Admin — Availability Rules & Exceptions.
 * Manage weekly schedule and per-date overrides (blackouts, holidays).
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

admin_require_login();
date_default_timezone_set(APP_TIMEZONE);

$db    = get_db();
$flash = '';
$flash_type = 'success';

$day_names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

// ── POST Actions ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save all weekly rules at once
    if ($action === 'save_rules') {
        // Process each submitted rule row
        $ids        = $_POST['rule_id']         ?? [];
        $days       = $_POST['rule_day']        ?? [];
        $opens      = $_POST['rule_open']       ?? [];
        $closes     = $_POST['rule_close']      ?? [];
        $crosses    = $_POST['rule_crosses']    ?? [];
        $actives    = $_POST['rule_active']     ?? [];

        foreach ($ids as $i => $rid) {
            $rid    = (int)$rid;
            $open   = $opens[$i] ?? '08:00';
            $close  = $closes[$i] ?? '21:00';
            $active = isset($actives[$i]) ? 1 : 0;
            $cross  = (int)($close < $open); // auto-detect midnight crossing

            if ($rid) {
                $db->prepare(
                    'UPDATE availability_rules SET open_time=?, close_time=?, crosses_midnight=?, active=? WHERE id=?'
                )->execute([$open, $close, $cross, $active, $rid]);
            }
        }

        // New rule row
        $new_day   = isset($_POST['new_day'])   ? (int)$_POST['new_day']   : null;
        $new_open  = trim($_POST['new_open']  ?? '');
        $new_close = trim($_POST['new_close'] ?? '');
        if ($new_day !== null && $new_open && $new_close) {
            $cross = ($new_close < $new_open) ? 1 : 0;
            $db->prepare(
                'INSERT INTO availability_rules (day_of_week, open_time, close_time, crosses_midnight, active)
                 VALUES (?,?,?,?,1)'
            )->execute([$new_day, $new_open, $new_close, $cross]);
        }
        $flash = 'Weekly schedule saved.';
    }

    // Delete a rule
    elseif ($action === 'delete_rule') {
        $rid = (int)($_POST['rule_id'] ?? 0);
        $db->prepare('DELETE FROM availability_rules WHERE id = ?')->execute([$rid]);
        $flash = 'Rule deleted.';
    }

    // Add / update an exception
    elseif ($action === 'save_exception') {
        $eid       = (int)($_POST['exception_id'] ?? 0);
        $date      = $_POST['exception_date'] ?? '';
        $is_closed = (int)!empty($_POST['is_closed']);
        $open      = $is_closed ? null : (trim($_POST['exc_open'] ?? '') ?: null);
        $close     = $is_closed ? null : (trim($_POST['exc_close'] ?? '') ?: null);
        $note      = trim($_POST['note'] ?? '') ?: null;
        $cross     = (!$is_closed && $open && $close && $close < $open) ? 1 : 0;

        if (!$date) {
            $flash = 'Date is required.'; $flash_type = 'danger';
        } else {
            if ($eid) {
                $db->prepare(
                    'UPDATE availability_exceptions SET is_closed=?, open_time=?, close_time=?,
                     crosses_midnight=?, note=? WHERE id=?'
                )->execute([$is_closed, $open, $close, $cross, $note, $eid]);
                $flash = 'Exception updated.';
            } else {
                $db->prepare(
                    'INSERT INTO availability_exceptions (exception_date, is_closed, open_time, close_time, crosses_midnight, note)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE is_closed=VALUES(is_closed), open_time=VALUES(open_time),
                     close_time=VALUES(close_time), crosses_midnight=VALUES(crosses_midnight), note=VALUES(note)'
                )->execute([$date, $is_closed, $open, $close, $cross, $note]);
                $flash = 'Exception saved.';
            }
        }
    }

    // Delete exception
    elseif ($action === 'delete_exception') {
        $eid = (int)($_POST['exception_id'] ?? 0);
        $db->prepare('DELETE FROM availability_exceptions WHERE id = ?')->execute([$eid]);
        $flash = 'Exception deleted.';
    }
}

// Load rules (one per day of week, or multiple if set that way)
$rules = $db->query(
    'SELECT * FROM availability_rules ORDER BY day_of_week ASC, id ASC'
)->fetchAll();

// Load upcoming exceptions (next 6 months + past 1 month)
$exceptions = $db->prepare(
    'SELECT * FROM availability_exceptions
     WHERE exception_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     ORDER BY exception_date ASC'
)->execute() ?: [];
$stmt = $db->prepare(
    'SELECT * FROM availability_exceptions
     WHERE exception_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     ORDER BY exception_date ASC'
);
$stmt->execute();
$exceptions = $stmt->fetchAll();

// Pre-load editing exception
$edit_exc = null;
if (isset($_GET['edit_exc'])) {
    $eid = (int)$_GET['edit_exc'];
    foreach ($exceptions as $e) {
        if ($e['id'] === $eid) { $edit_exc = $e; break; }
    }
}

render_admin_header('Availability', 'availability');
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash_type ?> mb-3"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Weekly Schedule -->
<div class="admin-panel mb-4">
    <div class="admin-panel__header">Weekly Schedule</div>
    <div class="admin-panel__body">
        <p class="text-dim text-sm mb-3">
            This is the repeating weekly availability. Exceptions below override individual dates.
            "Crosses midnight" is auto-detected when close time is earlier than open time.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="save_rules">
            <table class="detail-table">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Opens</th>
                        <th>Closes</th>
                        <th>Active</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rules as $rule): ?>
                <tr>
                    <td>
                        <input type="hidden" name="rule_id[]" value="<?= $rule['id'] ?>">
                        <strong><?= $day_names[$rule['day_of_week']] ?></strong>
                        <?php if ($rule['crosses_midnight']): ?>
                        <span class="badge badge-info" style="font-size:0.62rem;">+midnight</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input type="time" name="rule_open[]" class="form-input form-input--sm"
                               value="<?= substr($rule['open_time'], 0, 5) ?>" style="width:120px;">
                    </td>
                    <td>
                        <input type="time" name="rule_close[]" class="form-input form-input--sm"
                               value="<?= substr($rule['close_time'], 0, 5) ?>" style="width:120px;">
                    </td>
                    <td>
                        <label class="check-group" style="padding:0;">
                            <input type="checkbox" name="rule_active[<?= $rule['id'] ?>]" value="1"
                                   <?= $rule['active'] ? 'checked' : '' ?>>
                        </label>
                    </td>
                    <td>
                        <button type="submit" form="delRule<?= $rule['id'] ?>"
                                class="btn btn-danger btn-sm"
                                onclick="return confirm('Delete this rule?')">×</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <!-- Add new rule -->
                <tr style="border-top:2px solid var(--charcoal-lt);">
                    <td>
                        <input type="hidden" name="rule_id[]" value="0">
                        <select name="new_day" class="form-input form-input--sm">
                            <?php foreach ($day_names as $i => $n): ?>
                            <option value="<?= $i ?>"><?= $n ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="time" name="new_open"  class="form-input form-input--sm" style="width:120px;"></td>
                    <td><input type="time" name="new_close" class="form-input form-input--sm" style="width:120px;"></td>
                    <td colspan="2"><span class="text-dim text-xs">New rule</span></td>
                </tr>
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary btn-sm mt-3">Save Schedule</button>
        </form>

        <!-- Delete forms -->
        <?php foreach ($rules as $rule): ?>
        <form id="delRule<?= $rule['id'] ?>" method="POST" style="display:none;">
            <input type="hidden" name="action" value="delete_rule">
            <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
        </form>
        <?php endforeach; ?>
    </div>
</div>

<!-- Exceptions -->
<div class="admin-section-header">
    <h2 class="admin-section-title">Date Exceptions</h2>
    <span class="text-dim text-sm">Blackouts, holidays, and adjusted hours</span>
</div>

<div class="admin-panel mb-4">
    <div class="admin-panel__header"><?= $edit_exc ? 'Edit Exception — ' . date('M j, Y', strtotime($edit_exc['exception_date'])) : 'Add Exception' ?></div>
    <div class="admin-panel__body">
        <form method="POST">
            <input type="hidden" name="action" value="save_exception">
            <input type="hidden" name="exception_id" value="<?= $edit_exc['id'] ?? 0 ?>">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="exception_date" class="form-input" required
                           value="<?= htmlspecialchars($edit_exc['exception_date'] ?? '') ?>">
                </div>
                <div class="form-group" style="align-self:flex-end;padding-bottom:0.6rem;">
                    <label class="check-group" id="closedLabel">
                        <input type="checkbox" name="is_closed" value="1" id="isClosedCheck"
                               <?= ($edit_exc['is_closed'] ?? 0) ? 'checked' : '' ?>>
                        <span>Closed all day</span>
                    </label>
                </div>
            </div>

            <div id="adjustedHours" style="<?= ($edit_exc['is_closed'] ?? 0) ? 'display:none;' : '' ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Opens</label>
                        <input type="time" name="exc_open" class="form-input"
                               value="<?= htmlspecialchars(substr($edit_exc['open_time'] ?? '', 0, 5)) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Closes</label>
                        <input type="time" name="exc_close" class="form-input"
                               value="<?= htmlspecialchars(substr($edit_exc['close_time'] ?? '', 0, 5)) ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Note <span class="text-dim">(optional — e.g. "Holiday", "Private event")</span></label>
                <input type="text" name="note" class="form-input"
                       value="<?= htmlspecialchars($edit_exc['note'] ?? '') ?>">
            </div>

            <div class="d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm">Save Exception</button>
                <?php if ($edit_exc): ?>
                <a href="<?= APP_URL ?>/admin/availability.php" class="btn btn-ghost btn-sm">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($exceptions): ?>
<div class="card" style="cursor:default;overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Day</th>
                <th>Type</th>
                <th>Hours</th>
                <th>Note</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($exceptions as $exc):
            $is_past = $exc['exception_date'] < date('Y-m-d');
        ?>
            <tr <?= $is_past ? 'style="opacity:0.45;"' : '' ?>>
                <td>
                    <strong><?= date('M j, Y', strtotime($exc['exception_date'])) ?></strong>
                    <?php if ($is_past): ?><span class="badge badge-default">past</span><?php endif; ?>
                </td>
                <td class="text-dim"><?= $day_names[date('w', strtotime($exc['exception_date']))] ?></td>
                <td>
                    <?php if ($exc['is_closed']): ?>
                        <span class="badge badge-danger">Closed</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Adjusted Hours</span>
                    <?php endif; ?>
                </td>
                <td class="text-sm">
                    <?php if (!$exc['is_closed'] && $exc['open_time']): ?>
                        <?= date('g:i A', strtotime($exc['open_time'])) ?> – <?= date('g:i A', strtotime($exc['close_time'])) ?>
                        <?php if ($exc['crosses_midnight']): ?><span class="text-dim">(+midnight)</span><?php endif; ?>
                    <?php else: ?>
                        <span class="text-dim">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-sm"><?= htmlspecialchars($exc['note'] ?? '—') ?></td>
                <td class="actions">
                    <a href="?edit_exc=<?= $exc['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Delete this exception?')">
                        <input type="hidden" name="action" value="delete_exception">
                        <input type="hidden" name="exception_id" value="<?= $exc['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="alert alert-info">No exceptions scheduled.</div>
<?php endif; ?>

<script>
const closedCheck    = document.getElementById('isClosedCheck');
const adjustedHours  = document.getElementById('adjustedHours');
closedCheck.addEventListener('change', function () {
    adjustedHours.style.display = this.checked ? 'none' : '';
});
</script>

<?php render_admin_footer(); ?>
