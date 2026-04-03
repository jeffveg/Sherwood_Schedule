-- Migration: Add event detail fields to bookings table
-- and update attraction descriptions with space requirements.
-- Run once: mysql -u USER -p sherwood_schedule < db/migrate_event_details.sql

-- ---- Bookings: new event detail columns ----
ALTER TABLE bookings
    ADD COLUMN call_time_pref    VARCHAR(20) NULL AFTER admin_notes,
    ADD COLUMN tournament_bracket VARCHAR(30) NULL AFTER call_time_pref,
    ADD COLUMN allow_publish     TINYINT(1)  NULL AFTER tournament_bracket,
    ADD COLUMN allow_advertise   TINYINT(1)  NULL AFTER allow_publish,
    ADD COLUMN event_notes       TEXT        NULL AFTER allow_advertise;

-- ---- Attraction descriptions with space requirements ----
UPDATE attractions SET description =
    'Dodgeball meets archery — foam-tipped arrows, full-field action. Needs 40×80 ft with 20 ft clearance (indoors or outdoors).'
WHERE slug = 'archery-tag';

UPDATE attractions SET description =
    'S.A.F.E. Archery Hoverball — inflatable floating targets, ages 7 to 107. Needs 10×20 ft with 9 ft clearance (indoors or outdoors).'
WHERE slug = 'hoverball';

UPDATE attractions SET description =
    'Both attractions — the ultimate Sherwood Adventure experience. Archery Tag: 40×80 ft / 20 ft tall. Hoverball: 10×20 ft / 9 ft tall.'
WHERE slug = 'combo';
