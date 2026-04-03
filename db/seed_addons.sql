-- Default add-ons for Sherwood Adventure
-- Run once: mysql ... < db/seed_addons.sql

DELETE FROM addons;

INSERT INTO addons (name, description, pricing_type, price, min_charge, is_taxable, applicable_attractions, active, sort_order) VALUES
(
    'Event Lighting',
    'Professional lighting setup for evening or indoor events. Flat fee covers all required lighting equipment.',
    'flat',
    75.00,
    NULL,
    0,   -- non-taxable (service/equipment rental component)
    NULL, -- available for all attractions
    1,
    1
),
(
    'Tournament T-Shirts',
    'High-quality event t-shirts for tournament winners. Includes printing and design.',
    'flat',
    50.00,
    NULL,
    1,   -- taxable (tangible personal property)
    NULL,
    1,
    2
),
(
    'High-Quality Video',
    'Professional event video coverage. Edited highlight reel delivered after your event.',
    'per_hour',
    35.00,
    70.00, -- minimum charge (equivalent to 2 hrs)
    0,   -- non-taxable
    NULL,
    1,
    3
);
