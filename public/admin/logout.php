<?php
/**
 * Admin logout — destroys session and redirects to login.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

admin_logout();
