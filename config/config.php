<?php
/**
 * Application configuration.
 * Copy this file's values to suit your server. Database settings are in database.php.
 */

define('APP_NAME', 'Outline Media CRM');
define('APP_VERSION', '4.0.0');

// Leave empty to auto-detect (works on localhost sub-folders and cPanel root/sub-folders).
// Or set explicitly, e.g. 'https://crm.yourdomain.com'
define('APP_URL', 'https://outlinestudio.in/clientcare');

// Random 32+ character string. Used to encrypt stored SMTP passwords and sign remember-me cookies.
// CHANGE THIS after installation.
define('APP_KEY', 'fe689b5df89e7353679c3a7446b70d8985f5d273b17240a27f3f4babb3852679');

// Secret used when cron scripts are triggered over HTTP (cron/check-websites.php?key=...).
define('CRON_KEY', '97d6528f271d1f0c9036e8656629b06ef48665ba1d7d89c4');

// Environment: 'production' hides error details, 'development' shows them.
define('APP_ENV', 'production');

// Default timezone (can be overridden from Settings > General).
define('APP_TIMEZONE', 'Asia/Kolkata');

// Session
define('SESSION_NAME', 'omcrm_session');
define('SESSION_LIFETIME', 60 * 60 * 8);        // 8 hours of inactivity
define('REMEMBER_LIFETIME', 60 * 60 * 24 * 30);  // 30 days
// 'files' (default, single server) or 'database' (sessions table – required when several app servers sit behind a load balancer)
define('SESSION_DRIVER', 'files');
// 'auto' (APCu when available, else files in cache/ – per server) or 'database' (cache_store table – shared by every server)
define('CACHE_DRIVER', 'auto');

// Login protection
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// Paths
define('ROOT_PATH', dirname(__DIR__));
define('LOG_PATH', ROOT_PATH . '/logs');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
