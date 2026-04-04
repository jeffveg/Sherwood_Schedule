-- Sherwood Adventure Scheduling System
-- Database Schema
-- MySQL 5.7+ / MariaDB 10.2+

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

-- ============================================================
-- ATTRACTIONS
-- ============================================================
CREATE TABLE attractions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(50) NOT NULL UNIQUE,       -- 'archery-tag', 'hoverball', 'combo'
    description     TEXT,
    min_hours       DECIMAL(3,1) NOT NULL,             -- 2.0, 2.0, 3.0
    hour_increment  DECIMAL(3,1) NOT NULL,             -- 2.0, 2.0, 1.0
    deposit_amount  DECIMAL(8,2) NOT NULL,             -- 100.00, 50.00, 150.00
    active          TINYINT(1) NOT NULL DEFAULT 1,
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE attraction_images (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    attraction_id   INT NOT NULL,
    filename        VARCHAR(255) NOT NULL,
    alt_text        VARCHAR(255),
    sort_order      INT NOT NULL DEFAULT 0,
    FOREIGN KEY (attraction_id) REFERENCES attractions(id) ON DELETE CASCADE
);

-- ============================================================
-- ATTRACTION PRICING
-- Base price covers the first base_hours.
-- Each hour beyond base_hours adds additional_hourly_rate.
-- e.g. base_hours=2, base_price=400, additional_hourly_rate=150
--      => 2hrs=$400, 3hrs=$550 (combo only), 4hrs=$700, etc.
-- ============================================================
CREATE TABLE attraction_pricing (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    attraction_id           INT NOT NULL,
    base_hours              DECIMAL(3,1) NOT NULL,
    base_price              DECIMAL(8,2) NOT NULL,
    additional_hourly_rate  DECIMAL(8,2) NOT NULL,
    active                  TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (attraction_id) REFERENCES attractions(id) ON DELETE CASCADE
);

-- ============================================================
-- ADD-ONS
-- pricing_type='flat'     => price is a flat fee regardless of duration
-- pricing_type='per_hour' => price * hours, subject to min_charge
-- applicable_attractions: NULL = available for all attractions
--                         JSON array of attraction IDs otherwise
-- ============================================================
CREATE TABLE addons (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    name                     VARCHAR(100) NOT NULL,
    description              TEXT,
    pricing_type             ENUM('flat','per_hour') NOT NULL,
    price                    DECIMAL(8,2) NOT NULL,
    min_charge               DECIMAL(8,2) NULL,            -- per_hour minimum
    is_taxable               TINYINT(1) NOT NULL DEFAULT 1, -- t-shirts = 1
    applicable_attractions   JSON NULL,                    -- NULL = all
    active                   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order               INT NOT NULL DEFAULT 0,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE addon_images (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    addon_id    INT NOT NULL,
    filename    VARCHAR(255) NOT NULL,
    alt_text    VARCHAR(255),
    sort_order  INT NOT NULL DEFAULT 0,
    FOREIGN KEY (addon_id) REFERENCES addons(id) ON DELETE CASCADE
);

-- ============================================================
-- COUPONS / DISCOUNTS
-- discount_type='percent' => discount_amount = percent (e.g. 10 = 10% off)
-- discount_type='flat'    => discount_amount = dollars off
-- applies_to controls whether discount applies to attraction price,
--   add-on prices, or both. Travel fee is never discounted.
-- ============================================================
CREATE TABLE coupons (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(50) NOT NULL UNIQUE,
    description     VARCHAR(255),
    discount_type   ENUM('percent','flat') NOT NULL,
    discount_amount DECIMAL(8,2) NOT NULL,
    applies_to      ENUM('attraction','addons','both') NOT NULL DEFAULT 'attraction',
    nonprofit_only  TINYINT(1) NOT NULL DEFAULT 0,
    max_uses        INT NULL,                          -- NULL = unlimited
    use_count       INT NOT NULL DEFAULT 0,
    expires_at      DATE NULL,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- AVAILABILITY
-- availability_rules: repeating weekly schedule
-- close_time < open_time means the slot crosses midnight
--   (e.g. open=17:00, close=02:00 for Friday night)
-- ============================================================
CREATE TABLE availability_rules (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week     TINYINT NOT NULL,      -- 0=Sunday .. 6=Saturday
    open_time       TIME NOT NULL,
    close_time      TIME NOT NULL,
    crosses_midnight TINYINT(1) NOT NULL DEFAULT 0,
    active          TINYINT(1) NOT NULL DEFAULT 1
);

-- Per-date overrides: blackouts, holidays, adjusted hours
CREATE TABLE availability_exceptions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    exception_date  DATE NOT NULL UNIQUE,
    is_closed       TINYINT(1) NOT NULL DEFAULT 0,
    open_time       TIME NULL,             -- NULL if is_closed=1
    close_time      TIME NULL,
    crosses_midnight TINYINT(1) NOT NULL DEFAULT 0,
    note            VARCHAR(255)           -- e.g. "Holiday - adjusted hours"
);

-- ============================================================
-- CUSTOMERS
-- Guest checkout — no passwords. Identity verified via SMS (QUO).
-- ============================================================
CREATE TABLE customers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    first_name      VARCHAR(100) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(255) NOT NULL,
    phone           VARCHAR(20) NOT NULL,
    organization    VARCHAR(255) NULL,     -- org name for nonprofit discount
    is_nonprofit    TINYINT(1) NOT NULL DEFAULT 0,
    wave_customer_id VARCHAR(100) NULL,    -- set if synced to WaveApps
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- BOOKINGS
-- ============================================================
CREATE TABLE bookings (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    booking_ref         VARCHAR(20) NOT NULL UNIQUE,   -- e.g. SA-2025-001
    customer_id         INT NOT NULL,
    attraction_id       INT NOT NULL,

    -- Event timing
    event_date          DATE NOT NULL,
    start_time          TIME NOT NULL,
    end_time            TIME NOT NULL,                 -- stored for quick conflict check
    duration_hours      DECIMAL(3,1) NOT NULL,
    crosses_midnight    TINYINT(1) NOT NULL DEFAULT 0,

    -- Venue
    venue_name          VARCHAR(255) NOT NULL,
    venue_address       VARCHAR(500) NOT NULL,
    venue_city          VARCHAR(100) NOT NULL,
    venue_state         VARCHAR(50) NOT NULL DEFAULT 'AZ',
    venue_zip           VARCHAR(10) NULL,
    venue_lat           DECIMAL(10,7) NULL,
    venue_lng           DECIMAL(10,7) NULL,

    -- Travel fee
    travel_miles        DECIMAL(8,2) NULL,             -- one-way miles from base
    travel_fee          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    travel_fee_overridden TINYINT(1) NOT NULL DEFAULT 0,

    -- Pricing snapshot (stored at booking time)
    attraction_price    DECIMAL(8,2) NOT NULL,
    addons_subtotal     DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    coupon_id           INT NULL,
    coupon_code         VARCHAR(50) NULL,              -- snapshot in case coupon changes
    coupon_discount     DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    taxable_subtotal    DECIMAL(8,2) NOT NULL,         -- attraction + taxable addons - discount
    tax_rate            DECIMAL(6,4) NOT NULL,         -- 0.0880 stored at booking time
    tax_state           DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    tax_county          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    tax_city            DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    tax_total           DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    grand_total         DECIMAL(8,2) NOT NULL,

    -- Deposit & payment
    deposit_amount      DECIMAL(8,2) NOT NULL,
    payment_option      ENUM('deposit','full') NOT NULL,
    amount_paid         DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    balance_due         DECIMAL(8,2) NOT NULL DEFAULT 0.00,

    -- Status
    booking_status      ENUM('pending','confirmed','cancelled','completed','rescheduled')
                        NOT NULL DEFAULT 'pending',
    payment_status      ENUM('unpaid','deposit_paid','paid_in_full','refunded','collect_later')
                        NOT NULL DEFAULT 'unpaid',

    -- Admin
    is_admin_booking    TINYINT(1) NOT NULL DEFAULT 0,
    admin_notes         TEXT NULL,

    -- Event details (collected at booking)
    call_time_pref      VARCHAR(20) NULL,              -- Morning/Afternoon/Evening/Weekends/Anytime
    tournament_bracket  VARCHAR(30) NULL,              -- No/Single Elimination/Double Elimination/Round Robin/Other
    allow_publish       TINYINT(1)  NULL,              -- publish on upcoming events page
    allow_advertise     TINYINT(1)  NULL,              -- advertise services at event
    event_notes         TEXT        NULL,              -- free-text additional info

    -- Cancellation
    cancelled_at        TIMESTAMP NULL,
    cancellation_reason TEXT NULL,
    cancellation_fee    DECIMAL(8,2) NOT NULL DEFAULT 0.00,

    -- Rescheduling
    rescheduled_from_id INT NULL,                      -- original booking if rescheduled

    -- Notifications
    confirmation_sent   TINYINT(1) NOT NULL DEFAULT 0,
    balance_link_sent   TINYINT(1) NOT NULL DEFAULT 0,

    -- WaveApps
    wave_invoice_id     VARCHAR(100) NULL,

    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (customer_id)         REFERENCES customers(id),
    FOREIGN KEY (attraction_id)       REFERENCES attractions(id),
    FOREIGN KEY (coupon_id)           REFERENCES coupons(id),
    FOREIGN KEY (rescheduled_from_id) REFERENCES bookings(id)
);

-- Add-ons selected for a booking (snapshot of price at booking time)
CREATE TABLE booking_addons (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    booking_id  INT NOT NULL,
    addon_id    INT NOT NULL,
    addon_name  VARCHAR(100) NOT NULL,     -- snapshot
    quantity    INT NOT NULL DEFAULT 1,
    unit_price  DECIMAL(8,2) NOT NULL,
    total_price DECIMAL(8,2) NOT NULL,
    is_taxable  TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (addon_id)   REFERENCES addons(id)
);

-- ============================================================
-- PAYMENTS
-- One booking can have multiple payment records:
--   deposit, balance payment(s), cancellation fee, refunds
-- ============================================================
CREATE TABLE payments (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    booking_id              INT NOT NULL,
    payment_type            ENUM('deposit','balance','cancellation_fee','refund') NOT NULL,
    amount                  DECIMAL(8,2) NOT NULL,
    payment_method          ENUM('square_online','square_pos','check','wave_invoice','other')
                            NOT NULL,
    square_payment_id       VARCHAR(255) NULL,
    square_payment_link_id  VARCHAR(255) NULL,
    square_order_id         VARCHAR(255) NULL,
    status                  ENUM('pending','completed','failed','refunded')
                            NOT NULL DEFAULT 'pending',
    notes                   TEXT NULL,
    paid_at                 TIMESTAMP NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
);

-- ============================================================
-- GUEST VERIFICATION TOKENS
-- Used for guest access: view booking, cancel, reschedule,
-- or pay balance. Short-lived; single use.
-- ============================================================
CREATE TABLE booking_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    booking_id  INT NOT NULL,
    token       VARCHAR(64) NOT NULL UNIQUE,
    token_type  ENUM('view','cancel','reschedule','balance_payment') NOT NULL,
    phone_last4 VARCHAR(4) NOT NULL,   -- used to match SMS verification
    expires_at  TIMESTAMP NOT NULL,
    used_at     TIMESTAMP NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

-- ============================================================
-- CONFIGURATION TABLES
-- ============================================================

-- Tax breakdown (configurable — rates change over time)
CREATE TABLE tax_config (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    label       VARCHAR(100) NOT NULL,     -- 'Arizona State', 'Maricopa County', 'City of Goodyear'
    rate        DECIMAL(6,4) NOT NULL,     -- 0.0560, 0.0070, 0.0250
    sort_order  INT NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1
);

-- Travel fee configuration
CREATE TABLE travel_fee_config (
    id                   INT PRIMARY KEY DEFAULT 1,
    base_address         VARCHAR(500) NOT NULL,
    base_lat             DECIMAL(10,7) NOT NULL,
    base_lng             DECIMAL(10,7) NOT NULL,
    free_miles_threshold INT NOT NULL DEFAULT 50,
    rate_per_mile        DECIMAL(6,2) NOT NULL DEFAULT 1.00
);

-- Admin users (shared login for owner + co-owner)
CREATE TABLE admin_users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(100) NOT NULL,
    email         VARCHAR(255) NOT NULL,
    active        TINYINT(1) NOT NULL DEFAULT 1,
    last_login    TIMESTAMP NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- General key-value settings (terms content, reminder days, etc.)
CREATE TABLE settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    description   VARCHAR(255)
);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Tax rates (8.8% total — Goodyear, AZ as of Jan 2026)
INSERT INTO tax_config (label, rate, sort_order) VALUES
    ('State & County Tax', 0.0630, 1),
    ('City of Goodyear',   0.0250, 2);

-- Travel fee base (update lat/lng and address to your actual Goodyear address)
INSERT INTO travel_fee_config (id, base_address, base_lat, base_lng, free_miles_threshold, rate_per_mile)
VALUES (1, 'Goodyear, AZ 85338', 33.4353, -112.3576, 50, 1.00);

-- Attractions
INSERT INTO attractions (name, slug, description, min_hours, hour_increment, deposit_amount, sort_order) VALUES
    ('Archery Tag',          'archery-tag', 'Dodgeball meets archery — foam-tipped arrows, full-field action. Needs 40×80 ft with 20 ft clearance (indoors or outdoors).',                                                     2.0, 1.0, 100.00, 1),
    ('Hoverball',            'hoverball',   'S.A.F.E. Archery Hoverball — inflatable floating targets, ages 7 to 107. Needs 10×20 ft with 9 ft clearance (indoors or outdoors).',                                                2.0, 1.0,  50.00, 2),
    ('Archery Tag + Hoverball', 'combo',    'Both attractions — the ultimate Sherwood Adventure experience. Archery Tag: 40×80 ft / 20 ft tall. Hoverball: 10×20 ft / 9 ft tall.',                                               3.0, 1.0, 150.00, 3);

-- Default settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
    ('terms_travel_fee',
     'A travel fee of $1.00 per mile applies for events located more than 50 miles from our Goodyear, AZ base. The travel fee is calculated one-way and will be shown before you complete your booking.',
     'Travel fee terms shown to customer'),
    ('terms_cancellation',
     'Cancellations made 14 or more days before your event date will receive a full refund. Cancellations made within 14 days of the event will forfeit the deposit. If you paid in full and cancel within 14 days, a cancellation fee equal to the deposit amount will be charged.',
     'Cancellation policy shown to customer'),
    ('terms_rescheduling',
     'Events may be rescheduled online up to 14 days before the event date at no charge. Rescheduling requests within 14 days of the event must be made by contacting Sherwood Adventure directly, as a rescheduling fee may apply depending on commitments already made for your event.',
     'Rescheduling policy shown to customer'),
    ('terms_park_fees',
     'The customer is solely responsible for any fees charged by the event venue or municipality. This includes, but is not limited to, park reservation fees and city permit fees (for example, the City of Phoenix charges a fee for events with more than 50 attendees).',
     'Third-party/park fees disclaimer'),
    ('balance_reminder_days', '7', 'Days before event to send balance-due reminder email'),
    ('booking_ref_prefix', 'SA', 'Prefix for booking reference numbers (e.g. SA-2025-001)');
