-- Migration: Add confirmation_sent flag to bookings
-- Run once: mysql -h db5020145248.hosting-data.io -u dbu3468033 -p dbs15509806 < db/migrate_confirmation_sent.sql

ALTER TABLE bookings
    ADD COLUMN confirmation_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER wave_invoice_id;
