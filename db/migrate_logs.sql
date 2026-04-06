-- Migration: Add webhook_events and email_log tables
-- Run once on the live database.

CREATE TABLE IF NOT EXISTS webhook_events (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    received_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_type      VARCHAR(100) NULL,
    square_event_id VARCHAR(100) NULL,
    payment_id      VARCHAR(100) NULL,
    booking_ref     VARCHAR(20) NULL,
    status          ENUM('processed','duplicate','ignored','sig_failed','error') NOT NULL,
    notes           VARCHAR(500) NULL,
    raw_payload     TEXT NULL,
    INDEX idx_received_at (received_at),
    INDEX idx_status (status)
);

CREATE TABLE IF NOT EXISTS email_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    sent_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    to_address  VARCHAR(255) NOT NULL,
    subject     VARCHAR(500) NOT NULL,
    status      ENUM('sent','failed') NOT NULL,
    error       TEXT NULL,
    INDEX idx_sent_at (sent_at),
    INDEX idx_status (status)
);
