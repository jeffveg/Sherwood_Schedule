<?php
// Copy this file to config.php and fill in your values.
// config.php is excluded from git — never commit real credentials.

// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'sherwood_schedule');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

// Square API
define('SQUARE_ACCESS_TOKEN', 'your_square_access_token');
define('SQUARE_LOCATION_ID', 'your_square_location_id');
define('SQUARE_ENVIRONMENT', 'sandbox'); // 'sandbox' or 'production'
define('SQUARE_WEBHOOK_SIGNATURE_KEY', 'your_webhook_signature_key');

// Google Maps API
define('GOOGLE_MAPS_API_KEY', 'your_google_maps_api_key');

// QUO SMS
define('QUO_API_KEY', 'your_quo_api_key');
define('QUO_FROM_NUMBER', 'your_quo_phone_number');

// WaveApps API
define('WAVE_ACCESS_TOKEN', 'your_wave_full_access_token');
define('WAVE_BUSINESS_ID', 'your_wave_business_id');

// Email / SMTP
define('SMTP_HOST', 'your_ionos_smtp_host');
define('SMTP_PORT', 587);
define('SMTP_USER', 'bookings@sherwoodadventure.com');
define('SMTP_PASS', 'your_smtp_password');
define('SMTP_FROM_NAME', 'Sherwood Adventure');
define('ADMIN_EMAIL', 'admin@sherwoodadventure.com');

// App
define('APP_URL', 'https://schedule.sherwoodadventure.com');
define('APP_NAME', 'Sherwood Adventure');
define('APP_TIMEZONE', 'America/Phoenix');
define('BOOKING_LEAD_DAYS', 14);       // minimum days in advance to book
define('CANCELLATION_DAYS', 14);       // days before event for penalty-free cancel

// Events intake — push draft events to events.sherwoodadventure.com
// when the customer has set allow_publish=1 on their booking. The API key
// must match INTAKE_API_KEY in the events app's config.php.
// See https://github.com/jeffveg/sherwood_events/blob/main/INTAKE.md
define('EVENTS_INTAKE_URL',     'https://events.sherwoodadventure.com/api/intake.php');
define('EVENTS_INTAKE_API_KEY', 'paste-the-same-value-as-events-INTAKE_API_KEY');
