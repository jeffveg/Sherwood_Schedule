<?php
/**
 * Step 1 — Choose Attraction
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/wizard.php';

wizard_start();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attraction_id = (int)($_POST['attraction_id'] ?? 0);

    if ($attraction_id > 0) {
        $db   = get_db();
        $stmt = $db->prepare(
            'SELECT a.*, p.base_hours, p.base_price, p.additional_hourly_rate
             FROM attractions a
             JOIN attraction_pricing p ON p.attraction_id = a.id AND p.active = 1
             WHERE a.id = ? AND a.active = 1'
        );
        $stmt->execute([$attraction_id]);
        $attraction = $stmt->fetch();

        if ($attraction) {
            // If attraction changed, clear downstream wizard state
            if (wizard_get('attraction_id') !== $attraction_id) {
                wizard_clear();
            }
            wizard_set('attraction_id',  $attraction_id);
            wizard_set('attraction',     $attraction);
            // Reset hours to minimum whenever attraction changes
            wizard_set('hours', (float)$attraction['min_hours']);

            header('Location: ' . wizard_step_url(2));
            exit;
        }
    }
    $error = 'Please select an activity to continue.';
}

// Load attractions with pricing
$db   = get_db();
$stmt = $db->query(
    'SELECT a.*, p.base_hours, p.base_price, p.additional_hourly_rate
     FROM attractions a
     JOIN attraction_pricing p ON p.attraction_id = a.id AND p.active = 1
     WHERE a.active = 1
     ORDER BY a.sort_order'
);
$attractions = $stmt->fetchAll();

// Load attraction images (first image per attraction)
$images = [];
if ($attractions) {
    $ids        = implode(',', array_column($attractions, 'id'));
    $img_stmt   = $db->query(
        "SELECT attraction_id, filename, alt_text
         FROM attraction_images
         WHERE attraction_id IN ($ids)
         ORDER BY attraction_id, sort_order"
    );
    foreach ($img_stmt->fetchAll() as $img) {
        if (!isset($images[$img['attraction_id']])) {
            $images[$img['attraction_id']] = $img;
        }
    }
}

$selected_id = wizard_get('attraction_id', 0);

render_header('Choose Your Activity', 'book');
?>

<div class="container">
    <?php render_wizard_progress(1, array_values(WIZARD_STEPS)); ?>

    <div class="text-center mb-4">
        <h2>Choose Your Activity</h2>
        <p class="text-dim">Select the attraction you'd like to bring to your event.</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger mb-3"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="card-grid mb-4">
            <?php foreach ($attractions as $a): ?>
                <?php
                $is_selected = ($a['id'] == $selected_id);
                $hourly_note = 'then $' . number_format($a['additional_hourly_rate'], 0) . '/hr';
                $min_label   = $a['min_hours'] == 1 ? '1 hr minimum' : (int)$a['min_hours'] . ' hr minimum';
                ?>
                <label class="card<?= $is_selected ? ' selected' : '' ?>"
                       style="display:block; cursor:pointer;">
                    <input type="radio" name="attraction_id" value="<?= $a['id'] ?>"
                           <?= $is_selected ? 'checked' : '' ?> required>
                    <div class="card__selected-badge">Selected</div>

                    <?php if (isset($images[$a['id']])): ?>
                        <img class="card__image"
                             src="<?= h(APP_URL . '/assets/img/attractions/' . $images[$a['id']]['filename']) ?>"
                             alt="<?= h($images[$a['id']]['alt_text'] ?: $a['name']) ?>">
                    <?php else: ?>
                        <div class="card__image--placeholder">
                            <?= $a['slug'] === 'archery-tag' ? '🏹' : ($a['slug'] === 'hoverball' ? '🎯' : '⚔️') ?>
                        </div>
                    <?php endif; ?>

                    <div class="card__body">
                        <div class="card__title"><?= h($a['name']) ?></div>
                        <?php if ($a['description']): ?>
                            <div class="card__desc"><?= h($a['description']) ?></div>
                        <?php endif; ?>
                        <div class="card__price">
                            From $<?= number_format($a['base_price'], 0) ?>
                            &mdash; <?= h($min_label) ?>
                            <span class="text-dim text-xs"><?= h($hourly_note) ?> after</span>
                        </div>
                    </div>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="wizard-nav">
            <span></span>
            <button type="submit" class="btn btn-primary btn-lg">
                Continue &rarr;
            </button>
        </div>
    </form>
</div>

<?php render_footer(); ?>

<script>
// Visual card selection on click
document.querySelectorAll('.card').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('.card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
    });
});
</script>
