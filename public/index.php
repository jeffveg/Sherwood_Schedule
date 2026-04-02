<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/layout.php';

render_header('Book Your Adventure', 'book');
?>

<div class="container" style="padding-top:2rem;">

    <!-- Hero -->
    <div class="text-center mb-4">
        <h1>Book Your Adventure</h1>
        <p class="text-dim" style="font-size:1.1rem; max-width:560px; margin:0.75rem auto 0;">
            Archery Tag &amp; Hoverball — brought to your event, anywhere in the Phoenix metro area.
        </p>
    </div>

    <!-- Wizard progress preview -->
    <?php render_wizard_progress(1, ['Activity', 'Duration', 'Date & Time', 'Add-ons', 'Venue', 'Review', 'Payment']); ?>

    <!-- Attraction cards -->
    <div class="card-grid mb-4">

        <label class="card selected" style="display:block;">
            <input type="radio" name="attraction" value="1" checked>
            <div class="card__selected-badge">Selected</div>
            <div class="card__image--placeholder">🏹</div>
            <div class="card__body">
                <div class="card__title">Archery Tag</div>
                <div class="card__desc">Dodgeball meets archery — foam-tipped arrows, full-field action for all ages.</div>
                <div class="card__price">From $400 &middot; 2 hr minimum</div>
            </div>
        </label>

        <label class="card" style="display:block;">
            <input type="radio" name="attraction" value="2">
            <div class="card__selected-badge">Selected</div>
            <div class="card__image--placeholder">🎯</div>
            <div class="card__body">
                <div class="card__title">Hoverball</div>
                <div class="card__desc">S.A.F.E. Archery Hoverball — inflatable floating targets, ages 7 to 107.</div>
                <div class="card__price">From $300 &middot; 2 hr minimum</div>
            </div>
        </label>

        <label class="card" style="display:block;">
            <input type="radio" name="attraction" value="3">
            <div class="card__selected-badge">Selected</div>
            <div class="card__image--placeholder">⚔️</div>
            <div class="card__body">
                <div class="card__title">Archery Tag + Hoverball</div>
                <div class="card__desc">The ultimate Sherwood Adventure experience — both attractions at one event.</div>
                <div class="card__price">From $600 &middot; 3 hr minimum</div>
            </div>
        </label>

    </div>

    <!-- Sample booking layout with price summary -->
    <div class="booking-layout">
        <div class="booking-layout__main">

            <div class="panel">
                <div class="panel__title">Sample Form Elements</div>

                <div class="form-row">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" placeholder="Robin">
                    </div>
                    <div class="form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" placeholder="Hood">
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address <span class="required">*</span></label>
                    <input type="email" placeholder="robin@sherwoodforest.com">
                </div>

                <div class="form-group">
                    <label>Event Venue Name <span class="required">*</span></label>
                    <input type="text" placeholder="Goodyear Ballpark">
                </div>

                <div class="form-group">
                    <div class="check-group">
                        <input type="checkbox" id="lights" checked>
                        <label for="lights">Add event lighting (flat fee per attraction)</label>
                    </div>
                    <div class="check-group">
                        <input type="checkbox" id="shirts">
                        <label for="shirts">Tournament t-shirts for winners</label>
                    </div>
                </div>

                <div class="alert alert-warning">
                    <span>&#9888;</span>
                    Reminder: customers are responsible for any venue or city park permit fees.
                </div>

                <div class="alert alert-success">
                    <span>&#10003;</span>
                    Nonprofit discount applied — 10% off the attraction price.
                </div>
            </div>

            <!-- Duration selector sample -->
            <div class="panel">
                <div class="panel__title">Event Duration</div>
                <div class="duration-selector">
                    <button class="duration-btn" disabled>&minus;</button>
                    <div class="duration-display">2 hours</div>
                    <button class="duration-btn">&plus;</button>
                </div>
            </div>

            <div class="wizard-nav">
                <button class="btn btn-ghost" disabled>&larr; Back</button>
                <button class="btn btn-primary btn-lg">Continue &rarr;</button>
            </div>

        </div>

        <!-- Price Summary Sidebar -->
        <aside class="booking-layout__aside">
            <div class="price-summary">
                <div class="price-summary__title">Booking Summary</div>

                <div class="price-line">
                    <span class="price-line__label">Archery Tag (2 hrs)</span>
                    <span>$400.00</span>
                </div>
                <div class="price-line price-line--indent">
                    <span class="price-line__label">Event lighting</span>
                    <span>$75.00</span>
                </div>
                <div class="price-line price-line--discount">
                    <span class="price-line__label">Nonprofit discount (10%)</span>
                    <span class="price-line__amount">&minus;$40.00</span>
                </div>

                <hr class="price-divider">

                <div class="price-line price-line--tax">
                    <span class="price-line__label">Arizona State Tax (5.6%)</span>
                    <span>$22.40</span>
                </div>
                <div class="price-line price-line--tax">
                    <span class="price-line__label">Maricopa County (0.7%)</span>
                    <span>$2.80</span>
                </div>
                <div class="price-line price-line--tax">
                    <span class="price-line__label">City of Goodyear (2.5%)</span>
                    <span>$10.00</span>
                </div>

                <hr class="price-divider">

                <div class="price-total">
                    <span>Total</span>
                    <span>$470.20</span>
                </div>

                <div class="price-deposit-note">
                    Deposit due today: <strong>$100.00</strong><br>
                    Balance due before event: <strong>$370.20</strong>
                </div>

                <!-- Coupon -->
                <div class="mt-2">
                    <div class="coupon-row">
                        <input type="text" placeholder="COUPON CODE">
                        <button class="btn btn-secondary btn-sm">Apply</button>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <!-- Badge samples -->
    <div class="panel mt-4">
        <div class="panel__title">Status Badges</div>
        <div class="d-flex gap-1 flex-wrap">
            <span class="badge badge-confirmed">Confirmed</span>
            <span class="badge badge-pending">Pending</span>
            <span class="badge badge-cancelled">Cancelled</span>
            <span class="badge badge-completed">Completed</span>
            <span class="badge badge-deposit">Deposit Paid</span>
            <span class="badge badge-paid">Paid in Full</span>
            <span class="badge badge-collect">Collect Later</span>
        </div>
    </div>

</div>

<?php render_footer(); ?>

<script>
// Card selection demo
document.querySelectorAll('.card').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('.card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
    });
});
</script>
