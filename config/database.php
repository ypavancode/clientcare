<?php
/**
 * Database connection settings.
 * On cPanel the database and user names are usually prefixed with your account name,
 * e.g. cpaneluser_crm / cpaneluser_crmuser
 */
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'client_website_crm');
if (!defined('DB_USER')) define('DB_USER', 'client_website_crm');
if (!defined('DB_PASS')) define('DB_PASS', 'client_website_crm');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
