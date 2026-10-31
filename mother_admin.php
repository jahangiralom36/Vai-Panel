<?php
/**
 * ====================================================================
 * MOTHER ROOT SYSTEM — ENTERPRISE MULTI-TENANT & KEY MANAGER CONSOLE
 * VERSION: 14.0 (STRICT DOMAIN ISOLATION — MAIN DOMAIN ONLY)
 * ====================================================================
 */
// PHP notices/warnings আর প্যানেলের ভেতরে এলোমেলোভাবে প্রিন্ট হবে না — mother_admin_error.log এ জমা হবে।
// (Fatal error হলে নিচের handler আগের মতোই স্ক্রিনে দেখাবে।)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/mother_admin_error.log');
error_reporting(E_ALL);

// --- ERROR LOGGER: writes mother_admin_error.log (same folder) + shows fatal errors on screen ---
if (!function_exists('ma_log_error')) {
    function ma_log_error(string $msg): void {
        @file_put_contents(__DIR__ . '/mother_admin_error.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
set_exception_handler(function ($e) {
    $msg = 'Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
    ma_log_error($msg);
    if (!headers_sent()) { http_response_code(500); }
    echo '<pre style="background:#1e0a0a;color:#fca5a5;padding:14px;margin:10px;border-radius:10px;white-space:pre-wrap;font-size:12px;">' . htmlspecialchars($msg) . '</pre>';
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $msg = 'Fatal error: ' . $err['message'] . ' in ' . $err['file'] . ' on line ' . $err['line'];
        ma_log_error($msg);
        if (!headers_sent()) { http_response_code(500); }
        echo '<pre style="background:#1e0a0a;color:#fca5a5;padding:14px;margin:10px;border-radius:10px;white-space:pre-wrap;font-size:12px;">' . htmlspecialchars($msg) . '</pre>';
    }
});

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Media Storage Directory Provisioning
$prodUploadDir    = __DIR__ . '/uploads/products/';
$bannerUploadDir  = __DIR__ . '/uploads/banners/';
$siteUploadDir    = __DIR__ . '/uploads/site/';
$gatewayUploadDir = __DIR__ . '/uploads/gateways/';

if (!is_dir($prodUploadDir))    @mkdir($prodUploadDir, 0755, true);
if (!is_dir($bannerUploadDir))  @mkdir($bannerUploadDir, 0755, true);
if (!is_dir($siteUploadDir))    @mkdir($siteUploadDir, 0755, true);
if (!is_dir($gatewayUploadDir)) @mkdir($gatewayUploadDir, 0755, true);

function safeAddCol(PDO $pdo, string $table, string $column, string $type) {
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $type");
    } catch (Throwable $e) {}
}

// ====================================================================
// CORE DATABASE AUTO-MIGRATIONS & INDEX INTEGRITY
// ====================================================================
// ⚡ SPEED: migrations run only once after this file changes (not on every click)
$__schemaFlagDir  = __DIR__ . '/uploads/';
if (!is_dir($__schemaFlagDir)) @mkdir($__schemaFlagDir, 0755, true);
$__schemaFlagFile = $__schemaFlagDir . '.schema_mother_' . (int)@filemtime(__FILE__) . '.flag';
$runSchemaMigrations = !is_file($__schemaFlagFile) || isset($_GET['run_migrations']);

if ($runSchemaMigrations) try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `products` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `name` VARCHAR(191) NOT NULL,
      `code` VARCHAR(100) NOT NULL,
      `sort_order` INT DEFAULT 0,
      `image_url` VARCHAR(255) NULL,
      `logo_url` VARCHAR(255) NULL,
      `banner_url` VARCHAR(255) NULL,
      `download_link` VARCHAR(255) NULL,
      `setup_link` VARCHAR(255) NULL,
      `app_download_url` TEXT NULL,
      `tags` VARCHAR(255) NULL,
      `category` VARCHAR(50) DEFAULT 'APKMOD',
      `description` TEXT NULL,
      `is_pinned` TINYINT(1) DEFAULT 0,
      `is_active` TINYINT(1) DEFAULT 1,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'products', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'products', 'app_download_url', 'TEXT NULL');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_media_cache` (
      `product_name` VARCHAR(191) PRIMARY KEY,
      `logo_url` VARCHAR(255) NULL,
      `banner_url` VARCHAR(255) NULL,
      `download_link` VARCHAR(255) NULL,
      `setup_link` VARCHAR(255) NULL,
      `tags` VARCHAR(255) NULL,
      `category` VARCHAR(50) DEFAULT 'APKMOD',
      `description` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_plans` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `product_id` INT NOT NULL,
      `duration_days` INT DEFAULT 1,
      `plan_title` VARCHAR(100) NOT NULL,
      `reseller_price` DECIMAL(10,2) DEFAULT 0.00,
      `user_price` DECIMAL(10,2) DEFAULT 0.00,
      `regular_price` DECIMAL(10,2) DEFAULT 0.00,
      `discount_percent` DECIMAL(5,2) DEFAULT 0.00,
      `reseller_discount_percent` DECIMAL(5,2) DEFAULT 0.00,
      `duration_unit` VARCHAR(20) DEFAULT 'days',
      `duration_value` INT DEFAULT 1,
      `api_plan_id` INT DEFAULT 0,
      `reward_coins` INT DEFAULT 0,
      `coin_price` INT DEFAULT 0,
      `is_active` TINYINT(1) DEFAULT 1,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'product_plans', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'product_plans', 'regular_price', 'DECIMAL(10,2) DEFAULT 0.00');
    safeAddCol($pdo, 'product_plans', 'discount_percent', 'DECIMAL(5,2) DEFAULT 0.00');
    safeAddCol($pdo, 'product_plans', 'reseller_discount_percent', 'DECIMAL(5,2) DEFAULT 0.00');
    safeAddCol($pdo, 'product_plans', 'reward_coins', 'INT DEFAULT 0');
    safeAddCol($pdo, 'product_plans', 'coin_price', 'INT DEFAULT 0');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `payment_methods` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `method_key` VARCHAR(50) NOT NULL,
      `name` VARCHAR(100) NOT NULL,
      `account_number` VARCHAR(100) NOT NULL,
      `account_type` VARCHAR(50) NOT NULL DEFAULT 'Personal',
      `logo_url` TEXT NULL,
      `banner_url` TEXT NULL,
      `qr_image_url` TEXT NULL,
      `instructions` TEXT NULL,
      `is_active` TINYINT(1) NOT NULL DEFAULT 1,
      `allow_add_fund` TINYINT(1) NOT NULL DEFAULT 1,
      `allow_checkout` TINYINT(1) NOT NULL DEFAULT 1,
      `sort_order` INT NOT NULL DEFAULT 0,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'payment_methods', 'qr_image_url', 'TEXT NULL');
    safeAddCol($pdo, 'payment_methods', 'banner_url', 'TEXT NULL');
    safeAddCol($pdo, 'payment_methods', 'allow_add_fund', 'TINYINT(1) NOT NULL DEFAULT 1');
    safeAddCol($pdo, 'payment_methods', 'allow_checkout', 'TINYINT(1) NOT NULL DEFAULT 1');

    safeAddCol($pdo, 'users', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'users', 'email', 'VARCHAR(191) NULL');
    safeAddCol($pdo, 'users', 'role', "VARCHAR(20) DEFAULT 'customer'");
    safeAddCol($pdo, 'users', 'coins', 'INT DEFAULT 0');
    safeAddCol($pdo, 'users', 'discount_percent', 'DECIMAL(5,2) DEFAULT 0.00');
    safeAddCol($pdo, 'users', 'telegram_username', 'VARCHAR(100) NULL');
    safeAddCol($pdo, 'users', 'telegram_chat_id', 'BIGINT NULL');
    safeAddCol($pdo, 'users', 'can_add_fund', 'TINYINT(1) DEFAULT 1');
    safeAddCol($pdo, 'users', 'status', "VARCHAR(20) DEFAULT 'active'");
    safeAddCol($pdo, 'users', 'custom_bdt_rate', 'DECIMAL(10,2) DEFAULT 0.00');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_keys` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `product_id` INT NOT NULL,
      `plan_id` INT NOT NULL,
      `license_key` VARCHAR(255) NOT NULL,
      `status` ENUM('available','used') DEFAULT 'available',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'stock_keys', 'subdomain_id', 'INT NOT NULL DEFAULT 0');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `licenses` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `user_id` INT NOT NULL,
      `product_id` INT NULL,
      `license_key` VARCHAR(255) NOT NULL,
      `validity` VARCHAR(100) NULL,
      `price` DECIMAL(10,2) DEFAULT 0.00,
      `source` VARCHAR(50) DEFAULT 'PANEL',
      `status` VARCHAR(20) DEFAULT 'active',
      `payment_type` VARCHAR(20) DEFAULT 'balance',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'licenses', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'licenses', 'payment_type', "VARCHAR(20) DEFAULT 'balance'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_custom_prices` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `plan_id` INT NOT NULL,
      `custom_price` DECIMAL(10,2) NOT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_user_plan` (`user_id`, `plan_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_allowed_products` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `product_id` INT NOT NULL,
      UNIQUE KEY `user_prod_unique` (`user_id`, `product_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
      `setting_key` VARCHAR(100) PRIMARY KEY,
      `setting_value` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `subdomains` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NULL DEFAULT 0,
      `subdomain` VARCHAR(100) NOT NULL UNIQUE,
      `site_title` VARCHAR(150) DEFAULT 'Key Store',
      `theme_color` VARCHAR(30) DEFAULT '#00f3ff',
      `site_logo` VARCHAR(255) NULL,
      `loading_logo` VARCHAR(255) NULL,
      `apk_url` TEXT NULL,
      `admin_username` VARCHAR(100) NULL,
      `admin_password` VARCHAR(255) NULL,
      `support_link` VARCHAR(255) NULL,
      `channel_link` VARCHAR(255) NULL,
      `status` ENUM('active','suspended') DEFAULT 'active',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'subdomains', 'apk_url', 'TEXT NULL');
    safeAddCol($pdo, 'subdomains', 'admin_username', 'VARCHAR(100) NULL');
    safeAddCol($pdo, 'subdomains', 'admin_password', 'VARCHAR(255) NULL');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_admins` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `telegram_id` VARCHAR(50) NOT NULL UNIQUE,
      `admin_name` VARCHAR(150) DEFAULT 'Admin',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `spin_segments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `subdomain_id` INT NOT NULL DEFAULT 0,
        `title` VARCHAR(150) NOT NULL,
        `spend_target` DECIMAL(12,2) NOT NULL DEFAULT 500,
        `bonus_amount` DECIMAL(12,2) NOT NULL DEFAULT 50,
        `probability` INT NOT NULL DEFAULT 20,
        `color` VARCHAR(30) NOT NULL DEFAULT '#d946ef',
        `is_active` TINYINT NOT NULL DEFAULT 1,
        `sort_order` INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'spin_segments', 'subdomain_id', 'INT NOT NULL DEFAULT 0');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `deposit_bonuses` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `min_deposit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `bonus_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `bonus_type` ENUM('fixed', 'percentage') NOT NULL DEFAULT 'fixed',
      `title` VARCHAR(191) NOT NULL DEFAULT 'Instant Bonus',
      `claim_limit` INT NOT NULL DEFAULT 0,
      `is_active` TINYINT(1) NOT NULL DEFAULT 1,
      `sort_order` INT NOT NULL DEFAULT 0,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'deposit_bonuses', 'subdomain_id', 'INT NOT NULL DEFAULT 0');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `banners` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `image_url` VARCHAR(255) NOT NULL,
      `target_link` VARCHAR(255) NULL,
      `title` VARCHAR(150) DEFAULT 'Banner',
      `sort_order` INT DEFAULT 0,
      `is_active` TINYINT(1) DEFAULT 1,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'banners', 'subdomain_id', 'INT NOT NULL DEFAULT 0');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `system_notifications` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `target_type` VARCHAR(20) DEFAULT 'all',
      `target_user_id` INT NULL,
      `title` VARCHAR(255) NOT NULL,
      `message` TEXT NOT NULL,
      `scope` VARCHAR(30) DEFAULT 'main_only',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'system_notifications', 'subdomain_id', 'INT NOT NULL DEFAULT 0');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `transactions` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `user_id` INT NOT NULL,
      `method` VARCHAR(50) NOT NULL,
      `amount` DECIMAL(10,2) NOT NULL,
      `bonus_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `sender_num` VARCHAR(100) NULL,
      `trx_id` VARCHAR(100) NOT NULL,
      `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'transactions', 'subdomain_id', 'INT NOT NULL DEFAULT 0');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `gateway_transactions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `subdomain_id` INT NOT NULL DEFAULT 0,
        `method` VARCHAR(50) NOT NULL,
        `trx_id` VARCHAR(100) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `sender` VARCHAR(100) NULL,
        `status` ENUM('unclaimed', 'claimed', 'expired') DEFAULT 'unclaimed',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_trx_status` (`trx_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // --- NEW TABLES FOR TELEGRAM BOT, POPUPS, CATEGORIES & REFERRAL ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS `telegram_bot_config` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `bot_token` VARCHAR(255) NULL,
      `chat_id` VARCHAR(100) NULL,
      `notify_deposit` TINYINT(1) DEFAULT 1,
      `notify_purchase` TINYINT(1) DEFAULT 1,
      `notify_failed` TINYINT(1) DEFAULT 1,
      `is_active` TINYINT(1) DEFAULT 1,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY `uniq_subdomain` (`subdomain_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `custom_popups` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `title` VARCHAR(191) NULL,
      `message` TEXT NULL,
      `image_url` TEXT NULL,
      `link_url` TEXT NULL,
      `link_text` VARCHAR(100) DEFAULT 'Open Link',
      `is_active` TINYINT(1) DEFAULT 1,
      `show_once_per_session` TINYINT(1) DEFAULT 1,
      `sort_order` INT DEFAULT 0,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_categories` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `name` VARCHAR(100) NOT NULL,
      `icon` VARCHAR(50) NULL,
      `sort_order` INT DEFAULT 0,
      `is_active` TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `referral_settings` (
      `subdomain_id` INT NOT NULL PRIMARY KEY,
      `token_per_referral` INT DEFAULT 10,
      `referrer_bonus_bdt` DECIMAL(10,2) DEFAULT 0.00,
      `is_active` TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    @file_put_contents($__schemaFlagFile, date('c'), LOCK_EX);
} catch (Throwable $e) {}

// ০% ডিসকাউন্ট ফিক্স: regular_price যদি ০ থাকে তা ঠিক করা (cheap, runs every request)
try {
    $pdo->exec("UPDATE product_plans SET regular_price = user_price WHERE (regular_price IS NULL OR regular_price <= 0) AND user_price > 0 AND subdomain_id = 0");
} catch (Throwable $e) {}

// Seed default payment methods if empty
try {
    $chkMethods = (int)$pdo->query("SELECT COUNT(*) FROM payment_methods WHERE subdomain_id = 0")->fetchColumn();
    if ($chkMethods === 0) {
        $bkashNum  = defined('BKASH_NUMBER') ? BKASH_NUMBER : '01700000000';
        $nagadNum  = defined('NAGAD_NUMBER') ? NAGAD_NUMBER : '01800000000';
        $rocketNum = defined('ROCKET_NUMBER') ? ROCKET_NUMBER : '01900000000';

        $stmtSeed = $pdo->prepare("INSERT INTO payment_methods (subdomain_id, method_key, name, account_number, account_type, instructions, is_active, allow_add_fund, allow_checkout, sort_order) VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1, ?)");
        $stmtSeed->execute([0, 'bkash', 'bKash', $bkashNum, 'Personal', 'Send Money to the Personal number above, then enter the deposit amount and Transaction ID (TrxID) below to verify instantly.', 1]);
        $stmtSeed->execute([0, 'nagad', 'Nagad', $nagadNum, 'Personal', 'Send Money to the Personal number above, then enter the deposit amount and Transaction ID (TrxID) below to verify instantly.', 2]);
        $stmtSeed->execute([0, 'rocket', 'Rocket', $rocketNum, 'Personal', 'Send Money to the Personal number above, then enter the deposit amount and Transaction ID (TrxID) below to verify instantly.', 3]);
    }
} catch (Throwable $e) {}

// Global System Settings Defaults
$settings = [
    'site_name'                    => defined('SITE_NAME') ? SITE_NAME : 'VAI PANEL',
    'site_logo'                    => '',
    'loading_logo'                 => '',
    'main_site_theme_color'        => '#00f5ff',
    'usdt_rate'                    => '125',
    'inr_rate'                     => '88',
    'binance_market_rate'          => '125',
    'bkash_number'                 => '01700000000',
    'nagad_number'                 => '01800000000',
    'rocket_number'                => '01900000000',
    'binance_id'                   => '12345678',
    'payment_banner_url'           => '',
    'app_download_url'             => '',
    'firebase_url'                 => '',
    'auto_payment_on'              => '0',
    'snzpay_api_url'               => 'https://snzpay.digital/api/verify_payment.php',
    'snzpay_license_key'           => '',
    'snzpay_auto_verify'           => '0',
    'deposit_system_status'        => '1',
    'site_maintenance_mode'        => '0',
    'site_maintenance_msg'         => 'System upgrade in progress.',
    'min_deposit_limit'            => '5.00',
    'max_deposit_limit'            => '500.00',
    'telegram_tutorial_link'       => defined('SUPPORT_LINK') ? SUPPORT_LINK : 'https://t.me/OfficialSupport',
    'update_channel_popup_enabled' => '0',
    'update_channel_title'         => 'Join Community',
    'update_channel_msg'           => 'Stay updated with latest passes.',
    'update_channel_link'          => '',
    'spin_wheel_enabled'           => '1',
    'spin_cost'                    => '0.00',
    'spin_daily_limit'             => '5',
    'spin_offer_duration_hours'    => '24',
    'footer_copyright_text'        => '',
    'footer_telegram_link'         => defined('SUPPORT_LINK') ? SUPPORT_LINK : '',
    'footer_whatsapp_link'         => '',
    'footer_youtube_link'          => '',
    'km_api_url'                   => defined('KM_DEFAULT_API_URL') ? KM_DEFAULT_API_URL : '',
    'km_api_key'                   => defined('KM_DEFAULT_API_KEY') ? KM_DEFAULT_API_KEY : '',
    'km_last_status'               => 'untested',
    'km_cached_balance'            => '$0.00 USD',
    'km_cached_balance_time'       => '0',
    'firebase_api_key'             => '',
    'firebase_auth_domain'         => '',
    'firebase_project_id'          => '',
    'firebase_storage_bucket'      => '',
    'firebase_messaging_sender_id' => '',
    'firebase_app_id'              => '',
    'maintenance_telegram_link'    => ''
];

try {
    $dbSettings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($dbSettings)) $settings = array_merge($settings, $dbSettings);
} catch (Throwable $e) {}

$effectiveBdtRate = max(1.0, (float)($settings['usdt_rate'] ?? 125.0));
$effectiveInrRate = max(1.0, (float)($settings['inr_rate'] ?? 88.0));

// --- LOAD NEW CONFIGURATIONS FOR MOTHER PANEL ---
$botConfig = $pdo->query("SELECT * FROM telegram_bot_config WHERE subdomain_id = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [
    'bot_token' => '', 'chat_id' => '', 'notify_deposit' => 1, 'notify_purchase' => 1, 'notify_failed' => 1, 'is_active' => 1
];

$referralConfig = $pdo->query("SELECT * FROM referral_settings WHERE subdomain_id = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [
    'token_per_referral' => 10, 'referrer_bonus_bdt' => 0.00, 'is_active' => 1
];

$customPopupsList = $pdo->query("SELECT * FROM custom_popups WHERE subdomain_id = 0 ORDER BY sort_order ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$productCategoriesList = $pdo->query("SELECT * FROM product_categories WHERE subdomain_id = 0 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// ====================================================================
// SNZPAY VERIFICATION & GATEWAY ENGINE
// ====================================================================
function snzpay_verify_payment(PDO $pdo, string $trxId, string $method = 'BKASH', float $amount = 0.0): array {
    global $settings;

    $apiUrl = !empty($settings['snzpay_api_url']) ? trim($settings['snzpay_api_url']) : "https://snzpay.digital/api/verify_payment.php";
    $licenseKey = !empty($settings['snzpay_license_key']) ? trim($settings['snzpay_license_key']) : "";

    if (empty($licenseKey)) {
        return ['status' => 'error', 'message' => 'SNZPay License Key is not configured in Gateway Rules.'];
    }

    $cleanTrx = strtoupper(trim($trxId));
    $cleanMethod = strtoupper(trim($method));
    if ($cleanMethod === 'BINANCE PAY (USDT)' || $cleanMethod === 'BINANCE') $cleanMethod = 'BINANCE';
    elseif (strpos($cleanMethod, 'BKASH') !== false) $cleanMethod = 'BKASH';
    elseif (strpos($cleanMethod, 'NAGAD') !== false) $cleanMethod = 'NAGAD';
    elseif (strpos($cleanMethod, 'ROCKET') !== false) $cleanMethod = 'ROCKET';

    $payload = [
        "license_key" => $licenseKey,
        "trx_id"      => $cleanTrx,
        "method"      => $cleanMethod,
        "amount"      => (float)$amount
    ];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $res = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['status' => 'error', 'message' => 'SNZPay Gateway Timeout: ' . $curlErr];
    }

    $result = json_decode($res ?: '', true);
    if ($result && isset($result['status']) && $result['status'] === 'success') {
        return [
            'status'  => 'success',
            'amount'  => (float)($result['data']['amount'] ?? $amount),
            'sender'  => $result['data']['sender'] ?? '',
            'trx_id'  => $result['data']['trx_id'] ?? $cleanTrx,
            'message' => $result['message'] ?? 'Payment Verified Successfully'
        ];
    }

    return ['status' => 'error', 'message' => $result['message'] ?? 'Payment verification failed at gateway.'];
}

// ====================================================================
// ANTI-FREEZE KEY MANAGER CACHED BALANCE FETCHER
// ====================================================================
function km_get_cached_provider_balance(PDO $pdo): string {
    global $settings;

    $now = time();
    $cachedTime = (int)($settings['km_cached_balance_time'] ?? 0);
    $cachedVal  = $settings['km_cached_balance'] ?? '$0.00 USD';

    if (($now - $cachedTime) < 90 && !empty($cachedVal) && $cachedVal !== 'API Error' && $cachedVal !== 'Connection Failed') {
        return $cachedVal;
    }

    $ch = curl_init();
    $kmUrl = trim($settings['km_api_url'] ?? '');
    $kmKey = trim($settings['km_api_key'] ?? '');

    if (empty($kmUrl) || empty($kmKey)) return '$0.00 USD';

    $target = rtrim($kmUrl, '/');
    if (stripos($target, '.php') === false) $target .= '/api.php';

    curl_setopt_array($ch, [
        CURLOPT_URL            => $target . '?action=balance&api_key=' . urlencode($kmKey),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body ?: '', true);
    if ($data && isset($data['status']) && $data['status'] === 'success') {
        $formatted = '$' . number_format((float)($data['balance'] ?? 0), 2) . ' USD';
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('km_cached_balance', ?), ('km_cached_balance_time', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([$formatted, (string)$now]);
            $settings['km_cached_balance'] = $formatted;
            $settings['km_cached_balance_time'] = (string)$now;
        } catch (Throwable $e) {}
        return $formatted;
    }

    return $cachedVal ?: '$0.00 USD';
}

// ====================================================================
// ROBUST STACKING DISCOUNT CALCULATOR & SYNCHRONIZER
// ====================================================================
function syncStackedPricesForUser(PDO $pdo, int $userId) {
    try {
        $stmtUser = $pdo->prepare("SELECT id, role, discount_percent FROM users WHERE id = ? AND subdomain_id = 0 LIMIT 1");
        $stmtUser->execute([$userId]);
        $u = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if (!$u) return;

        $userDisc = (float)($u['discount_percent'] ?? 0);
        $role     = strtolower(trim((string)($u['role'] ?? 'customer')));

        $plans = $pdo->query("SELECT id, user_price, reseller_price, regular_price, discount_percent, reseller_discount_percent FROM product_plans WHERE subdomain_id = 0")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($plans)) return;

        $pdo->prepare("DELETE FROM user_custom_prices WHERE user_id = ?")->execute([$userId]);
        $insPrice = $pdo->prepare("INSERT INTO user_custom_prices (user_id, plan_id, custom_price) VALUES (?, ?, ?)");

        foreach ($plans as $pl) {
            $planDisc = (float)($pl['discount_percent'] ?? 0);
            if ($role === 'reseller' && (float)($pl['reseller_discount_percent'] ?? 0) > $planDisc) {
                $planDisc = (float)$pl['reseller_discount_percent'];
            }

            $totalDisc = min(99.0, max(0.0, $planDisc + $userDisc));

            $regPrice = (float)($pl['regular_price'] ?? 0);
            if ($regPrice <= 0) $regPrice = (float)($pl['user_price'] ?? 0);

            $resellBase = (float)($pl['reseller_price'] ?? 0);
            if ($resellBase <= 0) $resellBase = $regPrice;

            $baseRegular = ($role === 'reseller') ? $resellBase : $regPrice;
            if ($baseRegular <= 0) $baseRegular = 1.00;

            if ($totalDisc > 0) {
                $calcPrice = max(0.01, round($baseRegular * (1 - ($totalDisc / 100)), 2));
                $insPrice->execute([$userId, (int)$pl['id'], $calcPrice]);
            }
        }
    } catch (Throwable $e) {}
}

function syncStackedPricesForAllUsers(PDO $pdo, ?string $onlyRole = null) {
    try {
        $sql = "SELECT id FROM users WHERE subdomain_id = 0";
        if ($onlyRole === 'customer') {
            $sql .= " AND (LOWER(TRIM(COALESCE(role, 'customer'))) = 'customer' OR role IS NULL OR role = '')";
        } elseif ($onlyRole === 'reseller') {
            $sql .= " AND LOWER(TRIM(role)) = 'reseller'";
        }
        $uIds = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($uIds as $uId) {
            syncStackedPricesForUser($pdo, (int)$uId);
        }
    } catch (Throwable $e) {}
}

// Key Manager Catalog Fetcher
function fetchKeyManagerCatalog(PDO $pdo): ?array {
    global $settings;
    $kmUrl = trim($settings['km_api_url'] ?? '');
    $kmKey = trim($settings['km_api_key'] ?? '');

    if (empty($kmUrl) || empty($kmKey)) return null;

    $target = rtrim($kmUrl, '/');
    if (stripos($target, '.php') === false) $target .= '/api.php';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $target . '?action=products&api_key=' . urlencode($kmKey),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $res = json_decode($body ?: '', true);
    if (isset($res['status']) && $res['status'] === 'success') {
        $raw = $res['products'] ?? ($res['data']['products'] ?? ($res['data'] ?? []));
        $normalizedPlans = [];

        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (!empty($item['plans']) && is_array($item['plans'])) {
                    $prodName = $item['product_name'] ?? ($item['name'] ?? 'License Product');
                    foreach ($item['plans'] as $pl) {
                        $normalizedPlans[] = [
                            'id'            => (int)($pl['plan_id'] ?? ($pl['id'] ?? 0)),
                            'product_id'    => (int)($item['product_id'] ?? 0),
                            'product_name'  => $prodName,
                            'plan_title'    => $pl['validity'] ?? ($pl['plan_title'] ?? '30 Days'),
                            'price'         => (float)($pl['price'] ?? ($pl['user_price'] ?? 5.00)),
                            'regular_price' => (float)($pl['regular_price'] ?? ($pl['price'] ?? 5.00)),
                            'discount'      => (int)($pl['discount_percent'] ?? 0)
                        ];
                    }
                } elseif (isset($item['id']) || isset($item['plan_id'])) {
                    $planId = (int)($item['plan_id'] ?? $item['id']);
                    $prodName = $item['product_name'] ?? ($item['name'] ?? 'License Product');
                    $planTitle = $item['validity'] ?? ($item['plan_title'] ?? ($item['name'] ?? 'Plan'));
                    $normalizedPlans[] = [
                        'id'            => $planId,
                        'product_id'    => (int)($item['product_id'] ?? 0),
                        'product_name'  => $prodName,
                        'plan_title'    => $planTitle,
                        'price'         => (float)($item['price'] ?? ($item['user_price'] ?? 5.00)),
                        'regular_price' => (float)($item['regular_price'] ?? ($item['price'] ?? 5.00)),
                        'discount'      => (int)($item['discount_percent'] ?? 0)
                    ];
                }
            }
            return $normalizedPlans;
        }
    }
    return null;
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_km_catalog') {
    header('Content-Type: application/json');
    $items = fetchKeyManagerCatalog($pdo);
    if (is_array($items) && !empty($items)) {
        echo json_encode(['success' => true, 'items' => $items]);
    } else {
        $err = empty($settings['km_api_key']) ? 'API Key is empty in Key Manager tab.' : 'Failed to retrieve catalog from Key Manager.';
        echo json_encode(['success' => false, 'error' => $err]);
    }
    exit;
}

// Telegram Admin Authorization Checks
function isAuthorizedAdmin(PDO $pdo, $chatId): bool {
    if (empty($chatId)) return false;
    $adminId = defined('ADMIN_TELEGRAM_ID') ? (string)ADMIN_TELEGRAM_ID : '';
    if (!empty($adminId) && (string)$chatId === $adminId) return true;
    try {
        $stmt = $pdo->prepare("SELECT id FROM bot_admins WHERE telegram_id = ? LIMIT 1");
        $stmt->execute([(string)$chatId]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function verifyTelegramWebAppData(string $initData, string $botToken): ?array {
    parse_str($initData, $data);
    if (empty($data['hash'])) return null;
    $hash = $data['hash'];
    unset($data['hash']);
    ksort($data);
    $dataCheckArr = [];
    foreach ($data as $k => $v) $dataCheckArr[] = "$k=$v";
    $secretKey = hash_hmac('sha256', $botToken, "WebAppData", true);
    $calcHash = bin2hex(hash_hmac('sha256', implode("\n", $dataCheckArr), $secretKey, true));
    if (hash_equals($calcHash, $hash) && !empty($data['user'])) {
        return json_decode($data['user'], true);
    }
    return null;
}

$botToken = defined('BOT_TOKEN') ? BOT_TOKEN : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tg_webapp_auth') {
    header('Content-Type: application/json');
    $initData = trim($_POST['init_data'] ?? '');
    $userData = verifyTelegramWebAppData($initData, $botToken);

    if ($userData && !empty($userData['id'])) {
        $tgUid = $userData['id'];
        if (isAuthorizedAdmin($pdo, $tgUid)) {
            $_SESSION['admin_auth'] = true;
            $_SESSION['admin_tg_id'] = $tgUid;
            $_SESSION['admin_user'] = $userData['username'] ?? ($userData['first_name'] ?? 'Admin');
            session_write_close();
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => "Unauthorized Account (#$tgUid)"]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Invalid WebApp signature.']);
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_auth'], $_SESSION['admin_tg_id'], $_SESSION['admin_user']);
    header('Location: mother_admin.php');
    exit;
}

if (empty($_SESSION['admin_auth']) || empty($_SESSION['admin_tg_id']) || !isAuthorizedAdmin($pdo, $_SESSION['admin_tg_id'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>NOC Console — Gateway</title>
        <script src="https://telegram.org/js/telegram-web-app.js"></script>
        <style>
            body { background: #030612; color: #00f5ff; font-family: monospace; display:flex; justify-content:center; align-items:center; min-height:100vh; margin:0; }
            .auth-card { background: #070d20; border: 1px solid #16203a; border-radius: 20px; padding: 32px; text-align: center; max-width: 360px; width:90%; box-shadow: 0 10px 40px rgba(0,0,0,0.8); }
            .loader { margin: 20px auto; width: 40px; height: 40px; border: 3px solid #16203a; border-top-color: #00f5ff; border-radius: 50%; animation: spin 1s infinite linear; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        </style>
    </head>
    <body>
        <div class="auth-card">
            <div id="waitBox">
                <div class="loader"></div>
                <h3 style="color:#fff; font-size:13px; margin-bottom:5px; text-transform:uppercase;">AUTHENTICATING</h3>
                <p style="font-size:11px; color:#64748b; margin:0;">Verifying Telegram Credentials...</p>
            </div>
            <div id="errBox" style="display:none;">
                <div style="font-size:32px; color:#ef4444; margin-bottom:10px;">⚠️</div>
                <h3 style="color:#ef4444; font-size:14px; margin:0 0 8px 0; text-transform:uppercase;">ACCESS DENIED</h3>
                <p id="errMsg" style="font-size:11px; color:#94a3b8; line-height:1.4;"></p>
                <button onclick="window.location.reload()" style="margin-top:16px; background:#00f5ff; color:#040817; border:none; padding:8px 20px; border-radius:12px; font-weight:800; cursor:pointer;">RETRY</button>
            </div>
        </div>
        <script>
            (function() {
                try {
                    const tg = window.Telegram?.WebApp;
                    if (tg && tg.initData && tg.initData.length > 0) {
                        tg.ready(); tg.expand();
                        const fd = new FormData();
                        fd.append('action', 'tg_webapp_auth');
                        fd.append('init_data', tg.initData);
                        fetch('mother_admin.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                window.location.reload();
                            } else {
                                document.getElementById('waitBox').style.display = 'none';
                                document.getElementById('errBox').style.display = 'block';
                                document.getElementById('errMsg').innerText = res.error || 'Account not authorized.';
                            }
                        })
                        .catch(() => {
                            document.getElementById('waitBox').style.display = 'none';
                            document.getElementById('errBox').style.display = 'block';
                            document.getElementById('errMsg').innerText = 'Connection error.';
                        });
                        return;
                    }
                    document.getElementById('waitBox').style.display = 'none';
                    document.getElementById('errBox').style.display = 'block';
                    document.getElementById('errMsg').innerText = 'Launch console from your Telegram Bot!';
                } catch(e) {
                    document.getElementById('waitBox').style.display = 'none';
                    document.getElementById('errBox').style.display = 'block';
                    document.getElementById('errMsg').innerText = 'Exception: ' + e.message;
                }
            })();
        </script>
    </body>
    </html>
    <?php exit;
}

$toast = null;
$createdTenantData = null;
$activeTab = $_POST['active_tab'] ?? 'tab-overview';
$activeDiscSubTab = $_POST['disc_sub_tab'] ?? 'disc-view-products';
$currentHost = $_SERVER['HTTP_HOST'] ?? (defined('MAIN_DOMAIN') ? MAIN_DOMAIN : 'vaipanel.shop');

// ====================================================================
// POST ACTIONS ROUTER
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
    $act = $_POST['admin_action'];
    $activeTab = $_POST['active_tab'] ?? 'tab-overview';
    if (!empty($_POST['disc_sub_tab'])) {
        $activeDiscSubTab = $_POST['disc_sub_tab'];
    }

    if ($act === 'update_usdt_rate_quick') {
        // 1 USDT = X BDT  এবং  1 USDT = Y INR — এক জায়গা থেকেই সেট হয়
        $newRate    = max(1.0, (float)($_POST['usdt_rate'] ?? 125));
        $newInrRate = max(1.0, (float)($_POST['inr_rate'] ?? $effectiveInrRate));
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute(['usdt_rate', (string)$newRate, (string)$newRate]);
        $stmt->execute(['inr_rate', (string)$newInrRate, (string)$newInrRate]);
        $settings['usdt_rate'] = (string)$newRate;
        $settings['inr_rate']  = (string)$newInrRate;
        $effectiveBdtRate = $newRate;
        $effectiveInrRate = $newInrRate;
        $toast = ['type' => 'success', 'msg' => "Rates saved: 1 USDT = ৳$newRate BDT | ₹$newInrRate INR"];
    }

    if ($act === 'update_site_theme_color') {
        $newColor = trim($_POST['main_site_theme_color'] ?? '#00f5ff');
        if (!preg_match('/^#[a-f0-9]{6}$/i', $newColor) && !preg_match('/^#[a-f0-9]{3}$/i', $newColor)) {
            $newColor = '#00f5ff';
        }
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('main_site_theme_color', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$newColor, $newColor]);
        $settings['main_site_theme_color'] = $newColor;
        $toast = ['type' => 'success', 'msg' => "Main site theme color updated!"];
    }

    if ($act === 'save_km_credentials') {
        $kmUrl = trim($_POST['km_api_url'] ?? '');
        $kmKey = trim($_POST['km_api_key'] ?? '');

        if (!empty($kmUrl) && !empty($kmKey)) {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute(['km_api_url', $kmUrl, $kmUrl]);
            $stmt->execute(['km_api_key', $kmKey, $kmKey]);
            $settings['km_api_url'] = $kmUrl;
            $settings['km_api_key'] = $kmKey;

            $ch = curl_init();
            $target = rtrim($kmUrl, '/');
            if (stripos($target, '.php') === false) $target .= '/api.php';
            curl_setopt_array($ch, [
                CURLOPT_URL            => $target . '?action=balance&api_key=' . urlencode($kmKey),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $testBody = curl_exec($ch);
            curl_close($ch);

            $testRes = json_decode($testBody ?: '', true);
            if (isset($testRes['status']) && $testRes['status'] === 'success') {
                $bal = number_format((float)($testRes['balance'] ?? 0), 2);
                $stmt->execute(['km_last_status', 'connected', 'connected']);
                $stmt->execute(['km_cached_balance', '$' . $bal . ' USD', '$' . $bal . ' USD']);
                $stmt->execute(['km_cached_balance_time', (string)time(), (string)time()]);
                $settings['km_last_status'] = 'connected';
                $settings['km_cached_balance'] = '$' . $bal . ' USD';
                $toast = ['type' => 'success', 'msg' => "Connected! Balance: $$bal USD"];
            } else {
                $errMsg = $testRes['message'] ?? 'Could not authenticate.';
                $stmt->execute(['km_last_status', 'failed', 'failed']);
                $settings['km_last_status'] = 'failed';
                $toast = ['type' => 'error', 'msg' => "Connection Failed: $errMsg"];
            }
        } else {
            $toast = ['type' => 'error', 'msg' => 'API URL and Secret Key are required!'];
        }
    }

    if ($act === 'test_km_connection') {
        $ch = curl_init();
        $kmUrl = trim($settings['km_api_url'] ?? '');
        $kmKey = trim($settings['km_api_key'] ?? '');
        $target = rtrim($kmUrl, '/');
        if (stripos($target, '.php') === false) $target .= '/api.php';

        curl_setopt_array($ch, [
            CURLOPT_URL            => $target . '?action=balance&api_key=' . urlencode($kmKey),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $testBody = curl_exec($ch);
        curl_close($ch);

        $testRes = json_decode($testBody ?: '', true);
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        if (isset($testRes['status']) && $testRes['status'] === 'success') {
            $bal = number_format((float)($testRes['balance'] ?? 0), 2);
            $stmt->execute(['km_last_status', 'connected', 'connected']);
            $stmt->execute(['km_cached_balance', '$' . $bal . ' USD', '$' . $bal . ' USD']);
            $stmt->execute(['km_cached_balance_time', (string)time(), (string)time()]);
            $settings['km_last_status'] = 'connected';
            $settings['km_cached_balance'] = '$' . $bal . ' USD';
            $toast = ['type' => 'success', 'msg' => "Operational! Balance: $$bal USD"];
        } else {
            $errMsg = $testRes['message'] ?? 'Connection error.';
            $stmt->execute(['km_last_status', 'failed', 'failed']);
            $settings['km_last_status'] = 'failed';
            $toast = ['type' => 'error', 'msg' => "Failed: $errMsg"];
        }
    }

    if ($act === 'sync_km_products') {
        $items = fetchKeyManagerCatalog($pdo);
        if (is_array($items) && !empty($items)) {
            $pdo->beginTransaction();
            $syncedCount = 0;
            try {
                foreach ($items as $p) {
                    $extId       = (int)$p['id'];
                    $productName = trim($p['product_name']);
                    $planTitle   = trim($p['plan_title']);
                    $basePrice   = (float)$p['price'];

                    $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $productName));
                    $stmtProd = $pdo->prepare("SELECT id FROM products WHERE name = ? AND subdomain_id = 0 LIMIT 1");
                    $stmtProd->execute([$productName]);
                    $prodId = $stmtProd->fetchColumn();

                    $stmtCacheGet = $pdo->prepare("SELECT * FROM product_media_cache WHERE product_name = ? LIMIT 1");
                    $stmtCacheGet->execute([$productName]);
                    $cachedMedia = $stmtCacheGet->fetch();

                    $logoUrl      = $cachedMedia['logo_url'] ?? null;
                    $bannerUrl    = $cachedMedia['banner_url'] ?? null;
                    $downloadLink = $cachedMedia['download_link'] ?? null;
                    $setupLink    = $cachedMedia['setup_link'] ?? null;
                    $tags         = $cachedMedia['tags'] ?? '#VERIFIED';
                    $cat          = $cachedMedia['category'] ?? 'APKMOD';
                    $desc         = $cachedMedia['description'] ?? 'Instant Delivery';

                    if (!$prodId) {
                        $stmtIns = $pdo->prepare("INSERT INTO products (subdomain_id, name, code, sort_order, is_active, category, logo_url, banner_url, image_url, download_link, setup_link, tags, description) VALUES (0, ?, ?, 0, 1, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmtIns->execute([$productName, $code, $cat, $logoUrl, $bannerUrl, $bannerUrl, $downloadLink, $setupLink, $tags, $desc]);
                        $prodId = (int)$pdo->lastInsertId();
                    }

                    $stmtPlan = $pdo->prepare("SELECT id FROM product_plans WHERE api_plan_id = ? AND subdomain_id = 0 LIMIT 1");
                    $stmtPlan->execute([$extId]);
                    $planExists = $stmtPlan->fetchColumn();

                    $resellerPrice = $basePrice;
                    $userPrice     = round($basePrice * 1.25, 2);

                    if (!$planExists) {
                        $stmtPlanIns = $pdo->prepare("INSERT INTO product_plans (subdomain_id, product_id, duration_days, plan_title, reseller_price, user_price, regular_price, discount_percent, reseller_discount_percent, api_plan_id, reward_coins, coin_price) VALUES (0, ?, 30, ?, ?, ?, ?, 0.00, 20.00, ?, 0, 0)");
                        $stmtPlanIns->execute([$prodId, $planTitle, $resellerPrice, $userPrice, $userPrice, $extId]);
                    } else {
                        $stmtPlanUp = $pdo->prepare("UPDATE product_plans SET product_id = ?, plan_title = ?, reseller_price = ?, user_price = ?, regular_price = ? WHERE api_plan_id = ? AND subdomain_id = 0");
                        $stmtPlanUp->execute([$prodId, $planTitle, $resellerPrice, $userPrice, $userPrice, $extId]);
                    }
                    $syncedCount++;
                }
                $pdo->commit();
                $toast = ['type' => 'success', 'msg' => "Synced $syncedCount plans successfully!"];
            } catch (Throwable $e) {
                $pdo->rollBack();
                $toast = ['type' => 'error', 'msg' => 'Sync Error: ' . $e->getMessage()];
            }
        } else {
            $toast = ['type' => 'error', 'msg' => 'Could not fetch catalog. Test connection!'];
        }
    }

    if ($act === 'sync_selected_km_products') {
        $selectedExtIds = $_POST['selected_api_ids'] ?? [];
        if (empty($selectedExtIds)) {
            $toast = ['type' => 'error', 'msg' => 'No plans selected!'];
        } else {
            $items = fetchKeyManagerCatalog($pdo);
            if (is_array($items) && !empty($items)) {
                $pdo->beginTransaction();
                $syncedCount = 0;
                try {
                    foreach ($items as $p) {
                        $extId = (int)$p['id'];
                        if (!in_array((string)$extId, $selectedExtIds)) continue;

                        $productName = trim($p['product_name']);
                        $planTitle   = trim($p['plan_title']);
                        $basePrice   = (float)$p['price'];

                        $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $productName));
                        $stmtProd = $pdo->prepare("SELECT id FROM products WHERE name = ? AND subdomain_id = 0 LIMIT 1");
                        $stmtProd->execute([$productName]);
                        $prodId = $stmtProd->fetchColumn();

                        $stmtCacheGet = $pdo->prepare("SELECT * FROM product_media_cache WHERE product_name = ? LIMIT 1");
                        $stmtCacheGet->execute([$productName]);
                        $cachedMedia = $stmtCacheGet->fetch();

                        $logoUrl      = $cachedMedia['logo_url'] ?? null;
                        $bannerUrl    = $cachedMedia['banner_url'] ?? null;
                        $downloadLink = $cachedMedia['download_link'] ?? null;
                        $setupLink    = $cachedMedia['setup_link'] ?? null;
                        $tags         = $cachedMedia['tags'] ?? '#VERIFIED';
                        $cat          = $cachedMedia['category'] ?? 'APKMOD';
                        $desc         = $cachedMedia['description'] ?? 'Instant Delivery';

                        if (!$prodId) {
                            $stmtIns = $pdo->prepare("INSERT INTO products (subdomain_id, name, code, sort_order, is_active, category, logo_url, banner_url, image_url, download_link, setup_link, tags, description) VALUES (0, ?, ?, 0, 1, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmtIns->execute([$productName, $code, $cat, $logoUrl, $bannerUrl, $bannerUrl, $downloadLink, $setupLink, $tags, $desc]);
                            $prodId = (int)$pdo->lastInsertId();
                        }

                        $stmtPlan = $pdo->prepare("SELECT id FROM product_plans WHERE api_plan_id = ? AND subdomain_id = 0 LIMIT 1");
                        $stmtPlan->execute([$extId]);
                        $planExists = $stmtPlan->fetchColumn();

                        $resellerPrice = $basePrice;
                        $userPrice     = round($basePrice * 1.25, 2);

                        if (!$planExists) {
                            $stmtPlanIns = $pdo->prepare("INSERT INTO product_plans (subdomain_id, product_id, duration_days, plan_title, reseller_price, user_price, regular_price, discount_percent, reseller_discount_percent, api_plan_id, reward_coins, coin_price) VALUES (0, ?, 30, ?, ?, ?, ?, 0.00, 20.00, ?, 0, 0)");
                            $stmtPlanIns->execute([$prodId, $planTitle, $resellerPrice, $userPrice, $userPrice, $extId]);
                        } else {
                            $stmtPlanUp = $pdo->prepare("UPDATE product_plans SET product_id = ?, plan_title = ?, reseller_price = ?, user_price = ?, regular_price = ? WHERE api_plan_id = ? AND subdomain_id = 0");
                            $stmtPlanUp->execute([$prodId, $planTitle, $resellerPrice, $userPrice, $userPrice, $extId]);
                        }
                        $syncedCount++;
                    }
                    $pdo->commit();
                    $toast = ['type' => 'success', 'msg' => "Imported $syncedCount plans!"];
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $toast = ['type' => 'error', 'msg' => 'Sync Error: ' . $e->getMessage()];
                }
            } else {
                $toast = ['type' => 'error', 'msg' => 'Empty catalog received.'];
            }
        }
    }

    if ($act === 'delete_all_products_confirmed') {
        try {
            $pdo->exec("DELETE FROM stock_keys WHERE subdomain_id = 0");
            $pdo->exec("DELETE FROM product_plans WHERE subdomain_id = 0");
            $pdo->exec("DELETE FROM products WHERE subdomain_id = 0");
            $toast = ['type' => 'success', 'msg' => 'Main Domain catalog purged successfully!'];
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Wipe failed: ' . $e->getMessage()];
        }
    }

    if ($act === 'edit_product_full') {
        $prodId = (int)$_POST['product_id'];
        $name = trim($_POST['name'] ?? '');
        $category = strtoupper(trim($_POST['category'] ?? 'APKMOD'));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isPinned = isset($_POST['is_pinned']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $downloadLink = trim($_POST['download_link'] ?? '');
        $setupLink = trim($_POST['setup_link'] ?? '');
        $appDownloadUrl = trim($_POST['app_download_url'] ?? '');
        $tags = trim($_POST['tags'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $curr = $pdo->query("SELECT * FROM products WHERE id = $prodId AND subdomain_id = 0")->fetch();
        $logoUrl = $curr['logo_url'] ?? null;
        $bannerUrl = $curr['banner_url'] ?? ($curr['image_url'] ?? null);

        if (!empty($_FILES['product_logo']['name']) && $_FILES['product_logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['product_logo']['name'], PATHINFO_EXTENSION));
            $fileName = 'prod_logo_' . $prodId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['product_logo']['tmp_name'], $prodUploadDir . $fileName)) {
                $logoUrl = 'uploads/products/' . $fileName;
            }
        }
        if (!empty($_FILES['product_banner']['name']) && $_FILES['product_banner']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['product_banner']['name'], PATHINFO_EXTENSION));
            $fileName = 'prod_banner_' . $prodId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['product_banner']['tmp_name'], $prodUploadDir . $fileName)) {
                $bannerUrl = 'uploads/products/' . $fileName;
            }
        }

        $pdo->prepare("UPDATE products SET name = ?, sort_order = ?, image_url = ?, banner_url = ?, logo_url = ?, download_link = ?, setup_link = ?, app_download_url = ?, description = ?, tags = ?, category = ?, is_pinned = ?, is_active = ? WHERE id = ? AND subdomain_id = 0")
            ->execute([$name, $sortOrder, $bannerUrl, $bannerUrl, $logoUrl, $downloadLink, $setupLink, $appDownloadUrl, $description, $tags, $category, $isPinned, $isActive, $prodId]);

        try {
            $stmtCache = $pdo->prepare("INSERT INTO product_media_cache (product_name, logo_url, banner_url, download_link, setup_link, tags, category, description) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                logo_url = IF(VALUES(logo_url) IS NOT NULL AND VALUES(logo_url) != '', VALUES(logo_url), logo_url),
                banner_url = IF(VALUES(banner_url) IS NOT NULL AND VALUES(banner_url) != '', VALUES(banner_url), banner_url),
                download_link = VALUES(download_link),
                setup_link = VALUES(setup_link),
                tags = VALUES(tags),
                category = VALUES(category),
                description = VALUES(description)");
            $stmtCache->execute([$name, $logoUrl, $bannerUrl, $downloadLink, $setupLink, $tags, $category, $description]);
        } catch (Throwable $e) {}

        if (!empty($_POST['plan_user_price']) && is_array($_POST['plan_user_price'])) {
            $upPlan = $pdo->prepare("UPDATE product_plans SET user_price = ?, regular_price = ?, reseller_price = ?, reward_coins = ?, coin_price = ? WHERE id = ? AND product_id = ? AND subdomain_id = 0");
            foreach ($_POST['plan_user_price'] as $planId => $uPrice) {
                $uP = max(0.01, (float)$uPrice);
                $rP = isset($_POST['plan_reseller_price'][$planId]) ? max(0.01, (float)$_POST['plan_reseller_price'][$planId]) : round($uP * 0.8, 2);
                $rCoins = isset($_POST['plan_reward_coins'][$planId]) ? max(0, (int)$_POST['plan_reward_coins'][$planId]) : 0;
                $cPrice = isset($_POST['plan_coin_price'][$planId]) ? max(0, (int)$_POST['plan_coin_price'][$planId]) : 0;
                
                $upPlan->execute([$uP, $uP, $rP, $rCoins, $cPrice, (int)$planId, $prodId]);
            }
        }
        $toast = ['type' => 'success', 'msg' => 'Product and Plan Settings updated!'];
    }

    // ====================================================================
    // ADVANCED DISCOUNT ENGINE: ALL PRODUCTS
    // ====================================================================
    if ($act === 'apply_bulk_product_discount') {
        $discPercent = max(0.0, min(99.0, (float)($_POST['discount_percent'] ?? 0)));
        try {
            $plans = $pdo->query("SELECT id, regular_price, user_price FROM product_plans WHERE subdomain_id = 0")->fetchAll(PDO::FETCH_ASSOC);
            $stmtUp = $pdo->prepare("UPDATE product_plans SET user_price = ?, discount_percent = ? WHERE id = ? AND subdomain_id = 0");
            
            foreach ($plans as $pl) {
                $regular = (float)$pl['regular_price'] > 0 ? (float)$pl['regular_price'] : (float)$pl['user_price'];
                $newPrice = ($discPercent > 0) ? max(0.01, round($regular * (1 - ($discPercent / 100)), 2)) : $regular;
                $stmtUp->execute([$newPrice, $discPercent, (int)$pl['id']]);
            }

            syncStackedPricesForAllUsers($pdo);
            $toast = ['type' => 'success', 'msg' => ($discPercent > 0) ? "Applied $discPercent% discount to all main domain product plans!" : "All product discounts reset to 0%!"];
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'reset_all_product_discounts') {
        try {
            $pdo->exec("UPDATE product_plans SET user_price = regular_price, discount_percent = 0.00 WHERE regular_price > 0 AND subdomain_id = 0");
            syncStackedPricesForAllUsers($pdo);
            $toast = ['type' => 'success', 'msg' => "All product discounts turned off & regular prices restored!"];
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'apply_single_plan_discount') {
        $planId      = (int)($_POST['plan_id'] ?? 0);
        $discPercent = max(0.0, min(99.0, (float)($_POST['discount_percent'] ?? 0)));

        if ($planId > 0) {
            $pl = $pdo->query("SELECT id, regular_price, user_price FROM product_plans WHERE id = $planId AND subdomain_id = 0")->fetch(PDO::FETCH_ASSOC);
            if ($pl) {
                $regular = (float)$pl['regular_price'] > 0 ? (float)$pl['regular_price'] : (float)$pl['user_price'];
                
                // যদি রেগুলার প্রাইস আগে ০ সেভ করা থাকে, তবে বর্তমান আসল প্রাইসটি সেভ করবে
                if ((float)$pl['regular_price'] <= 0) {
                    $pdo->prepare("UPDATE product_plans SET regular_price = ? WHERE id = ?")->execute([$regular, $planId]);
                }

                // ০% ডিসকাউন্ট দিলে সরাসরি আসল রেগুলার প্রাইস রিস্টোর করবে
                $newPrice = ($discPercent > 0) ? max(0.01, round($regular * (1 - ($discPercent / 100)), 2)) : $regular;
                $pdo->prepare("UPDATE product_plans SET user_price = ?, discount_percent = ? WHERE id = ? AND subdomain_id = 0")->execute([$newPrice, $discPercent, $planId]);
                syncStackedPricesForAllUsers($pdo);
                $toast = ['type' => 'success', 'msg' => ($discPercent > 0 ? "Plan discount updated to $discPercent%!" : "Discount reset to 0%! Regular price ($$regular USD) restored.")];
            }
        }
    }

    // ====================================================================
    // ADVANCED DISCOUNT ENGINE: CUSTOMERS & RESELLERS
    // ====================================================================
    if ($act === 'apply_bulk_user_discount') {
        $targetRole  = in_array($_POST['target_role'] ?? '', ['customer', 'reseller']) ? $_POST['target_role'] : 'customer';
        $discPercent = max(0.0, min(99.0, (float)($_POST['discount_percent'] ?? 0)));

        try {
            if ($targetRole === 'customer') {
                $stmt = $pdo->prepare("UPDATE users SET discount_percent = ? WHERE (LOWER(TRIM(COALESCE(role, 'customer'))) = 'customer' OR role IS NULL OR role = '') AND subdomain_id = 0");
            } else {
                $stmt = $pdo->prepare("UPDATE users SET discount_percent = ? WHERE LOWER(TRIM(role)) = 'reseller' AND subdomain_id = 0");
            }
            $stmt->execute([$discPercent]);

            syncStackedPricesForAllUsers($pdo, $targetRole);
            $roleLabel = ucfirst($targetRole);
            $toast = ['type' => 'success', 'msg' => ($discPercent > 0) ? "Applied $discPercent% extra discount to all main domain {$roleLabel}s (Stacked)!" : "Discounts reset to 0% for all {$roleLabel}s!"];
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'reset_all_user_discounts') {
        $targetRole = in_array($_POST['target_role'] ?? '', ['customer', 'reseller']) ? $_POST['target_role'] : 'customer';
        try {
            if ($targetRole === 'customer') {
                $pdo->exec("UPDATE users SET discount_percent = 0.00 WHERE (LOWER(TRIM(COALESCE(role, 'customer'))) = 'customer' OR role IS NULL OR role = '') AND subdomain_id = 0");
            } else {
                $pdo->exec("UPDATE users SET discount_percent = 0.00 WHERE LOWER(TRIM(role)) = 'reseller' AND subdomain_id = 0");
            }
            syncStackedPricesForAllUsers($pdo, $targetRole);
            $toast = ['type' => 'success', 'msg' => "All {$targetRole} discounts turned off successfully!"];
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'apply_single_user_discount') {
        $targetUid   = (int)($_POST['target_user_id'] ?? 0);
        $discPercent = max(0.0, min(99.0, (float)($_POST['discount_percent'] ?? 0)));

        if ($targetUid > 0) {
            $pdo->prepare("UPDATE users SET discount_percent = ? WHERE id = ? AND subdomain_id = 0")->execute([$discPercent, $targetUid]);
            syncStackedPricesForUser($pdo, $targetUid);
            $toast = ['type' => 'success', 'msg' => "User discount set to $discPercent% (Stacked with plan discount)!"];
        }
    }

    if ($act === 'reset_single_user_discount') {
        $targetUid = (int)($_POST['target_user_id'] ?? 0);
        if ($targetUid > 0) {
            $pdo->prepare("UPDATE users SET discount_percent = 0.00 WHERE id = ? AND subdomain_id = 0")->execute([$targetUid]);
            $pdo->prepare("DELETE FROM user_custom_prices WHERE user_id = ?")->execute([$targetUid]);
            $toast = ['type' => 'success', 'msg' => "Discount removed and reset for this user!"];
        }
    }

    if ($act === 'save_user_custom_pricing_and_access') {
        $userId = (int)$_POST['target_user_id'];
        $role = in_array($_POST['user_role'] ?? '', ['customer', 'reseller']) ? $_POST['user_role'] : 'customer';
        $canAddFund = isset($_POST['can_add_fund']) ? 1 : 0;

        $pdo->prepare("UPDATE users SET role = ?, can_add_fund = ? WHERE id = ? AND subdomain_id = 0")->execute([$role, $canAddFund, $userId]);

        $allowedProds = $_POST['allowed_products'] ?? [];
        $pdo->prepare("DELETE FROM user_allowed_products WHERE user_id = ?")->execute([$userId]);
        if (!empty($allowedProds)) {
            $insProd = $pdo->prepare("INSERT INTO user_allowed_products (user_id, product_id) VALUES (?, ?)");
            foreach ($allowedProds as $pId) {
                $insProd->execute([$userId, (int)$pId]);
            }
        }

        $customPrices = $_POST['custom_plan_price'] ?? [];
        $pdo->prepare("DELETE FROM user_custom_prices WHERE user_id = ?")->execute([$userId]);
        $insPrice = $pdo->prepare("INSERT INTO user_custom_prices (user_id, plan_id, custom_price) VALUES (?, ?, ?)");
        $savedCount = 0;

        foreach ($customPrices as $plId => $cPrice) {
            $cleanPrice = trim((string)$cPrice);
            if ($cleanPrice !== '' && is_numeric($cleanPrice)) {
                $fPrice = (float)$cleanPrice;
                if ($fPrice > 0) {
                    $insPrice->execute([$userId, (int)$plId, $fPrice]);
                    $savedCount++;
                }
            }
        }
        $toast = ['type' => 'success', 'msg' => "User permissions & custom pricing updated ($savedCount plan prices saved)!"];
    }

    // ====================================================================
    // DYNAMIC PAYMENT METHODS CRUD (MAIN DOMAIN ONLY)
    // ====================================================================
    if ($act === 'save_payment_method') {
        $methodId      = (int)($_POST['method_id'] ?? 0);
        $name          = trim($_POST['name'] ?? '');
        $methodKey     = strtolower(preg_replace('/[^a-z0-9_]/i', '', trim($_POST['method_key'] ?? '')));
        $accountNumber = trim($_POST['account_number'] ?? '');
        $accountType   = trim($_POST['account_type'] ?? 'Personal');
        $instructions  = trim($_POST['instructions'] ?? '');
        $sortOrder     = (int)($_POST['sort_order'] ?? 0);
        $isActive      = isset($_POST['is_active']) ? 1 : 0;
        $allowAddFund  = isset($_POST['allow_add_fund']) ? 1 : 0;
        $allowCheckout = isset($_POST['allow_checkout']) ? 1 : 0;

        if (empty($name) || empty($accountNumber)) {
            $toast = ['type' => 'error', 'msg' => 'Method Name and Account Number are required!'];
        } else {
            if (empty($methodKey)) {
                $methodKey = strtolower(preg_replace('/[^a-z0-9_]/i', '', $name));
            }

            $existing = null;
            if ($methodId > 0) {
                $existing = $pdo->query("SELECT * FROM payment_methods WHERE id = $methodId AND subdomain_id = 0")->fetch(PDO::FETCH_ASSOC);
            }

            $logoUrl   = $existing['logo_url'] ?? null;
            $bannerUrl = $existing['banner_url'] ?? null;
            $qrUrl     = $existing['qr_image_url'] ?? null;

            if (!empty($_FILES['logo_file']['name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) {
                    $fileName = 'pm_logo_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $gatewayUploadDir . $fileName)) {
                        $logoUrl = 'uploads/gateways/' . $fileName;
                    }
                }
            }

            if (!empty($_FILES['banner_file']['name']) && $_FILES['banner_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['banner_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $fileName = 'pm_banner_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['banner_file']['tmp_name'], $gatewayUploadDir . $fileName)) {
                        $bannerUrl = 'uploads/gateways/' . $fileName;
                    }
                }
            }

            if (!empty($_FILES['qr_file']['name']) && $_FILES['qr_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['qr_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) {
                    $fileName = 'pm_qr_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['qr_file']['tmp_name'], $gatewayUploadDir . $fileName)) {
                        $qrUrl = 'uploads/gateways/' . $fileName;
                    }
                }
            }

            try {
                if ($methodId > 0) {
                    $stmt = $pdo->prepare("UPDATE payment_methods SET name = ?, method_key = ?, account_number = ?, account_type = ?, instructions = ?, logo_url = ?, banner_url = ?, qr_image_url = ?, is_active = ?, allow_add_fund = ?, allow_checkout = ?, sort_order = ? WHERE id = ? AND subdomain_id = 0");
                    $stmt->execute([$name, $methodKey, $accountNumber, $accountType, $instructions, $logoUrl, $bannerUrl, $qrUrl, $isActive, $allowAddFund, $allowCheckout, $sortOrder, $methodId]);
                    $toast = ['type' => 'success', 'msg' => "$name details & graphics updated successfully!"];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO payment_methods (subdomain_id, name, method_key, account_number, account_type, instructions, logo_url, banner_url, qr_image_url, is_active, allow_add_fund, allow_checkout, sort_order) VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $methodKey, $accountNumber, $accountType, $instructions, $logoUrl, $bannerUrl, $qrUrl, $isActive, $allowAddFund, $allowCheckout, $sortOrder]);
                    $toast = ['type' => 'success', 'msg' => "Payment method $name created successfully!"];
                }
            } catch (Throwable $e) {
                $toast = ['type' => 'error', 'msg' => 'Database error: ' . $e->getMessage()];
            }
        }
    }

    if ($act === 'delete_payment_method') {
        $methodId = (int)$_POST['method_id'];
        $pdo->prepare("DELETE FROM payment_methods WHERE id = ? AND subdomain_id = 0")->execute([$methodId]);
        $toast = ['type' => 'success', 'msg' => 'Payment Method deleted!'];
    }

    if ($act === 'toggle_payment_method_status') {
        $methodId = (int)$_POST['method_id'];
        $curr = (int)$_POST['current_status'];
        $new = ($curr === 1) ? 0 : 1;
        $pdo->prepare("UPDATE payment_methods SET is_active = ? WHERE id = ? AND subdomain_id = 0")->execute([$new, $methodId]);
        $toast = ['type' => 'success', 'msg' => "Gateway status set to " . ($new === 1 ? 'ACTIVE' : 'OFFLINE')];
    }

    // ====================================================================
    // SNZPAY AUTO-PAYMENT API RULES & TEST
    // ====================================================================
    if ($act === 'save_snzpay_rules') {
        $apiUrl     = trim($_POST['snzpay_api_url'] ?? 'https://snzpay.digital/api/verify_payment.php');
        $licenseKey = trim($_POST['snzpay_license_key'] ?? '');
        $autoVerify = isset($_POST['snzpay_auto_verify']) ? '1' : '0';
        $autoPayOn  = isset($_POST['auto_payment_on']) ? '1' : '0';

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute(['snzpay_api_url', $apiUrl, $apiUrl]);
        $stmt->execute(['snzpay_license_key', $licenseKey, $licenseKey]);
        $stmt->execute(['snzpay_auto_verify', $autoVerify, $autoVerify]);
        $stmt->execute(['auto_payment_on', $autoPayOn, $autoPayOn]);

        $settings['snzpay_api_url']     = $apiUrl;
        $settings['snzpay_license_key'] = $licenseKey;
        $settings['snzpay_auto_verify'] = $autoVerify;
        $settings['auto_payment_on']    = $autoPayOn;

        $toast = ['type' => 'success', 'msg' => 'SNZPay & Auto Payment configuration saved!'];
    }

    if ($act === 'test_snzpay_connection') {
        $testRes = snzpay_verify_payment($pdo, "TEST_PING_" . rand(1000, 9999), "BKASH", 10.0);
        if ($testRes['status'] === 'success') {
            $toast = ['type' => 'success', 'msg' => "SNZPay Gateway Connected! " . $testRes['message']];
        } else {
            $toast = ['type' => 'error', 'msg' => "SNZPay Gateway Status: " . $testRes['message']];
        }
    }

    // ====================================================================
    // SUBDOMAIN TENANT ACTIONS (Creating Subdomains Only)
    // ====================================================================
    if ($act === 'assign_subdomain') {
        $sub           = strtolower(preg_replace('/[^a-z0-9_-]/', '', trim($_POST['subdomain_slug'] ?? '')));
        $title         = trim($_POST['site_title'] ?? '') ?: 'Key Store';
        $adminUsername = trim($_POST['subadmin_username'] ?? '');
        $adminPassword = $_POST['subadmin_password'] ?? '';
        $apkUrl        = trim($_POST['apk_url'] ?? '');

        if (empty($sub) || strlen($sub) < 2) {
            $toast = ['type' => 'error', 'msg' => 'Please provide a valid Subdomain slug (minimum 2 chars)!'];
        } elseif (empty($adminUsername) || strlen($adminUsername) < 3) {
            $toast = ['type' => 'error', 'msg' => 'Sub-Admin Username must be at least 3 characters!'];
        } elseif (empty($adminPassword) || strlen($adminPassword) < 6) {
            $toast = ['type' => 'error', 'msg' => 'Sub-Admin Password must be at least 6 characters!'];
        } else {
            try {
                try {
                    $fks = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subdomains' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($fks as $fk) {
                        $pdo->exec("ALTER TABLE `subdomains` DROP FOREIGN KEY `$fk`");
                    }
                } catch (Throwable $e) {}

                try {
                    $pdo->exec("ALTER TABLE `subdomains` MODIFY `user_id` INT NULL DEFAULT NULL");
                } catch (Throwable $e) {}

                $stmtChk = $pdo->prepare("SELECT id FROM subdomains WHERE subdomain = ? LIMIT 1");
                $stmtChk->execute([$sub]);
                if ($stmtChk->fetchColumn()) {
                    $toast = ['type' => 'error', 'msg' => "Subdomain '$sub' is already taken!"];
                } else {
                    $stmtChkUname = $pdo->prepare("SELECT id FROM subdomains WHERE admin_username = ? LIMIT 1");
                    $stmtChkUname->execute([$adminUsername]);
                    if ($stmtChkUname->fetchColumn()) {
                        $toast = ['type' => 'error', 'msg' => "Admin Username '$adminUsername' is already used by another store!"];
                    } else {
                        $hashedPass = password_hash($adminPassword, PASSWORD_DEFAULT);

                        $targetUserId = null;
                        try {
                            $col = $pdo->query("SHOW COLUMNS FROM `subdomains` LIKE 'user_id'")->fetch(PDO::FETCH_ASSOC);
                            if ($col && strtoupper($col['Null']) === 'NO') {
                                $uId = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
                                $targetUserId = $uId ? (int)$uId : 1;
                            }
                        } catch (Throwable $e) {}

                        $stmtIns = $pdo->prepare("INSERT INTO subdomains (user_id, subdomain, site_title, admin_username, admin_password, apk_url, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                        $stmtIns->execute([$targetUserId, $sub, $title, $adminUsername, $hashedPass, $apkUrl]);

                        $fullDomain = $sub . '.' . $currentHost;
                        $panelUrl   = 'https://' . $fullDomain . '/sub_admin.php';

                        $createdTenantData = [
                            'subdomain' => $fullDomain,
                            'store_url' => 'https://' . $fullDomain,
                            'panel_url' => $panelUrl,
                            'username'  => $adminUsername,
                            'password'  => $adminPassword
                        ];

                        $toast = ['type' => 'success', 'msg' => "Deployed '$sub' with secure login credentials!"];
                    }
                }
            } catch (Throwable $e) {
                $toast = ['type' => 'error', 'msg' => 'Subdomain deployment error: ' . $e->getMessage()];
            }
        }
    }

    if ($act === 'edit_subdomain_details') {
        $subId         = (int)($_POST['subdomain_id'] ?? 0);
        $sub           = strtolower(preg_replace('/[^a-z0-9_-]/', '', trim($_POST['subdomain_slug'] ?? '')));
        $title         = trim($_POST['site_title'] ?? '') ?: 'Key Store';
        $adminUsername = trim($_POST['subadmin_username'] ?? '');
        $newPassword   = $_POST['subadmin_password'] ?? '';
        $apkUrl        = trim($_POST['apk_url'] ?? '');
        $status        = in_array($_POST['status'] ?? '', ['active', 'suspended']) ? $_POST['status'] : 'active';

        if ($subId <= 0 || empty($sub)) {
            $toast = ['type' => 'error', 'msg' => 'Invalid parameters!'];
        } else {
            try {
                $stmtChk = $pdo->prepare("SELECT id FROM subdomains WHERE subdomain = ? AND id != ? LIMIT 1");
                $stmtChk->execute([$sub, $subId]);
                if ($stmtChk->fetchColumn()) {
                    $toast = ['type' => 'error', 'msg' => "Slug '$sub' already in use!"];
                } else {
                    $updateSql = "UPDATE subdomains SET subdomain = ?, site_title = ?, admin_username = ?, apk_url = ?, status = ?";
                    $params = [$sub, $title, $adminUsername ?: null, $apkUrl, $status];

                    if (!empty($newPassword) && strlen($newPassword) >= 6) {
                        $updateSql .= ", admin_password = ?";
                        $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
                    }

                    $updateSql .= " WHERE id = ?";
                    $params[] = $subId;

                    $pdo->prepare($updateSql)->execute($params);

                    $toast = ['type' => 'success', 'msg' => "Updated subdomain & credentials for '$sub'!"];
                }
            } catch (Throwable $e) {
                $toast = ['type' => 'error', 'msg' => 'Update error: ' . $e->getMessage()];
            }
        }
    }

    if ($act === 'toggle_subdomain_status') {
        $subId = (int)$_POST['subdomain_id'];
        $curr = $_POST['status'] ?? 'active';
        $new = $curr === 'active' ? 'suspended' : 'active';
        $pdo->prepare("UPDATE subdomains SET status = ? WHERE id = ?")->execute([$new, $subId]);
        $toast = ['type' => 'success', 'msg' => "Status set to $new!"];
    }

    if ($act === 'delete_subdomain') {
        $pdo->prepare("DELETE FROM subdomains WHERE id = ?")->execute([(int)$_POST['subdomain_id']]);
        $toast = ['type' => 'success', 'msg' => 'Subdomain deleted!'];
    }

    if ($act === 'send_push_notification') {
        $target = $_POST['target_type'] ?? 'all';
        $title = trim($_POST['push_title'] ?? '');
        $msg = trim($_POST['push_message'] ?? '');
        $specificUid = !empty($_POST['specific_user_id']) ? (int)$_POST['specific_user_id'] : null;

        if (!empty($title) && !empty($msg)) {
            $pdo->prepare("INSERT INTO system_notifications (subdomain_id, target_type, target_user_id, title, message, scope) VALUES (0, ?, ?, ?, ?, 'main_only')")
                ->execute([$target, $specificUid, $title, $msg]);
            $toast = ['type' => 'success', 'msg' => "Notification broadcasted to Main Domain!"];
        }
    }

    if ($act === 'delete_push_notification') {
        $notifId = (int)($_POST['notif_id'] ?? 0);
        $pdo->prepare("DELETE FROM system_notifications WHERE id = ? AND subdomain_id = 0")->execute([$notifId]);
        $toast = ['type' => 'success', 'msg' => "Notification deleted!"];
    }

    if ($act === 'update_branding_settings') {
        $newSiteName     = trim($_POST['site_name'] ?? '') ?: 'VAI PANEL';
        $appDownloadUrl  = trim($_POST['app_download_url'] ?? '');
        $siteLogoPath    = $settings['site_logo'] ?? '';
        $loadingLogoPath = $settings['loading_logo'] ?? '';

        if (!empty($_POST['delete_site_logo'])) $siteLogoPath = '';
        if (!empty($_POST['delete_loading_logo'])) $loadingLogoPath = '';

        if (!empty($_FILES['site_logo_file']['name']) && $_FILES['site_logo_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['site_logo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) {
                $fileName = 'site_logo_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['site_logo_file']['tmp_name'], $siteUploadDir . $fileName)) {
                    $siteLogoPath = 'uploads/site/' . $fileName;
                }
            }
        }

        if (!empty($_FILES['loading_logo_file']['name']) && $_FILES['loading_logo_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['loading_logo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) {
                $fileName = 'loading_logo_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['loading_logo_file']['tmp_name'], $siteUploadDir . $fileName)) {
                    $loadingLogoPath = 'uploads/site/' . $fileName;
                }
            }
        }

        $popupEnabled = isset($_POST['update_channel_popup_enabled']) ? '1' : '0';
        $popupTitle   = trim($_POST['update_channel_title'] ?? 'Join Community');
        $popupMsg     = trim($_POST['update_channel_msg'] ?? '');
        $popupLink    = trim($_POST['update_channel_link'] ?? '');
        $maintMode    = isset($_POST['site_maintenance_mode']) ? '1' : '0';
        $maintMsg     = trim($_POST['site_maintenance_msg'] ?? 'System upgrade in progress.');

        $copyrightText = trim($_POST['footer_copyright_text'] ?? '');
        $tgSocialLink  = trim($_POST['footer_telegram_link'] ?? '');
        $wsSocialLink  = trim($_POST['footer_whatsapp_link'] ?? '');
        $ytSocialLink  = trim($_POST['footer_youtube_link'] ?? '');

        $maintTgLink   = trim($_POST['maintenance_telegram_link'] ?? '');

        $saveArray = [
            'site_name'                    => $newSiteName,
            'app_download_url'             => $appDownloadUrl,
            'site_logo'                    => $siteLogoPath,
            'loading_logo'                 => $loadingLogoPath,
            'site_maintenance_mode'        => $maintMode,
            'site_maintenance_msg'         => $maintMsg,
            'maintenance_telegram_link'    => $maintTgLink,
            'update_channel_popup_enabled' => $popupEnabled,
            'update_channel_title'         => $popupTitle,
            'update_channel_msg'           => $popupMsg,
            'update_channel_link'          => $popupLink,
            'footer_copyright_text'        => $copyrightText,
            'footer_telegram_link'         => $tgSocialLink,
            'footer_whatsapp_link'         => $wsSocialLink,
            'footer_youtube_link'          => $ytSocialLink
        ];

        $stmtSet = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($saveArray as $k => $v) {
            $stmtSet->execute([$k, $v, $v]);
            $settings[$k] = $v;
        }
        $toast = ['type' => 'success', 'msg' => 'Main Domain Settings saved!'];
    }

    // --- TELEGRAM NOTIFICATION BOT CONFIG SAVE ---
    if ($act === 'save_telegram_bot_config') {
        $botToken   = trim($_POST['bot_token'] ?? '');
        $chatId     = trim($_POST['chat_id'] ?? '');
        $notifDep   = isset($_POST['notify_deposit']) ? 1 : 0;
        $notifBuy   = isset($_POST['notify_purchase']) ? 1 : 0;
        $notifFail  = isset($_POST['notify_failed']) ? 1 : 0;
        $isActive   = isset($_POST['is_active']) ? 1 : 0;

        $stmtBot = $pdo->prepare("INSERT INTO telegram_bot_config (subdomain_id, bot_token, chat_id, notify_deposit, notify_purchase, notify_failed, is_active)
            VALUES (0, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE bot_token = VALUES(bot_token), chat_id = VALUES(chat_id), notify_deposit = VALUES(notify_deposit), notify_purchase = VALUES(notify_purchase), notify_failed = VALUES(notify_failed), is_active = VALUES(is_active)");
        $stmtBot->execute([$botToken, $chatId, $notifDep, $notifBuy, $notifFail, $isActive]);
        $toast = ['type' => 'success', 'msg' => 'Telegram Notification Bot configured successfully!'];
    }

    // --- TEST TELEGRAM BOT PING ---
    if ($act === 'test_telegram_bot_ping') {
        $stmtB = $pdo->query("SELECT bot_token, chat_id FROM telegram_bot_config WHERE subdomain_id = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($stmtB && !empty($stmtB['bot_token']) && !empty($stmtB['chat_id'])) {
            $msg = "⚡ <b>Vai Panel — Notification Bot Test</b>\n\n✅ Your Telegram Bot is successfully connected with Mother Root System!\nYou will now receive live alerts for deposits and sales.";
            $ch = curl_init("https://api.telegram.org/bot{$stmtB['bot_token']}/sendMessage");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $stmtB['chat_id'], 'text' => $msg, 'parse_mode' => 'HTML']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            $resData = json_decode($res ?: '', true);
            if ($resData && ($resData['ok'] ?? false)) {
                $toast = ['type' => 'success', 'msg' => 'Test message sent to your Telegram successfully!'];
            } else {
                $toast = ['type' => 'error', 'msg' => 'Failed to send! Please check your Bot Token & Chat ID.'];
            }
        } else {
            $toast = ['type' => 'error', 'msg' => 'Please save Bot Token & Chat ID first!'];
        }
    }

    // --- REFERRAL & TOKEN SETTINGS SAVE ---
    if ($act === 'save_referral_settings') {
        $tokensPerRef = max(0, (int)($_POST['token_per_referral'] ?? 10));
        $bonusBdt     = max(0.0, (float)($_POST['referrer_bonus_bdt'] ?? 0));
        $isActive     = isset($_POST['is_active']) ? 1 : 0;

        $stmtRef = $pdo->prepare("INSERT INTO referral_settings (subdomain_id, token_per_referral, referrer_bonus_bdt, is_active)
            VALUES (0, ?, ?, ?)
            ON DUPLICATE KEY UPDATE token_per_referral = VALUES(token_per_referral), referrer_bonus_bdt = VALUES(referrer_bonus_bdt), is_active = VALUES(is_active)");
        $stmtRef->execute([$tokensPerRef, $bonusBdt, $isActive]);
        $toast = ['type' => 'success', 'msg' => 'Referral Token & Reward rules saved!'];
    }

    // --- CATEGORY SEQUENCE SAVE ---
    if ($act === 'save_product_category') {
        $catName  = strtoupper(trim($_POST['category_name'] ?? ''));
        $catOrder = (int)($_POST['sort_order'] ?? 0);
        if (!empty($catName)) {
            $pdo->prepare("INSERT INTO product_categories (subdomain_id, name, sort_order, is_active) VALUES (0, ?, ?, 1)")
                ->execute([$catName, $catOrder]);
            $toast = ['type' => 'success', 'msg' => "Category '$catName' added with order $catOrder!"];
        }
    }
    if ($act === 'delete_product_category') {
        $catId = (int)$_POST['category_id'];
        $pdo->prepare("DELETE FROM product_categories WHERE id = ? AND subdomain_id = 0")->execute([$catId]);
        $toast = ['type' => 'success', 'msg' => 'Category deleted!'];
    }

    // --- CUSTOM POPUP SAVE ---
    if ($act === 'save_custom_popup') {
        $title    = trim($_POST['popup_title'] ?? '');
        $message  = trim($_POST['popup_message'] ?? '');
        $linkUrl  = trim($_POST['link_url'] ?? '');
        $linkText = trim($_POST['link_text'] ?? 'Open Link') ?: 'Open Link';
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $showOnce = isset($_POST['show_once_per_session']) ? 1 : 0;
        $imagePath = null;

        if (!empty($_FILES['popup_image']['name']) && $_FILES['popup_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['popup_image']['name'], PATHINFO_EXTENSION));
            $fileName = 'popup_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['popup_image']['tmp_name'], $siteUploadDir . $fileName)) {
                $imagePath = 'uploads/site/' . $fileName;
            }
        }
        $pdo->prepare("INSERT INTO custom_popups (subdomain_id, title, message, image_url, link_url, link_text, show_once_per_session, sort_order, is_active) VALUES (0, ?, ?, ?, ?, ?, ?, ?, 1)")
            ->execute([$title, $message, $imagePath, $linkUrl, $linkText, $showOnce, $sort]);
        $toast = ['type' => 'success', 'msg' => 'Entry Pop-up created!'];
    }
    if ($act === 'delete_custom_popup') {
        $pdo->prepare("DELETE FROM custom_popups WHERE id = ? AND subdomain_id = 0")->execute([(int)$_POST['popup_id']]);
        $toast = ['type' => 'success', 'msg' => 'Popup deleted!'];
    }

    if ($act === 'modify_balance') {
        $userId = (int)$_POST['user_id'];
        $amount = (float)$_POST['amount'];
        if ($_POST['type'] === 'deduct') $amount = -$amount;
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ? AND subdomain_id = 0")->execute([$amount, $userId]);
        $toast = ['type' => 'success', 'msg' => "Balance updated!"];
    }

    if ($act === 'modify_coins') {
        $userId = (int)$_POST['user_id'];
        $amount = (int)$_POST['coins_amount'];
        if ($_POST['type'] === 'deduct') $amount = -$amount;
        $pdo->prepare("UPDATE users SET coins = GREATEST(0, coins + ?) WHERE id = ? AND subdomain_id = 0")->execute([$amount, $userId]);
        $toast = ['type' => 'success', 'msg' => "Coins updated!"];
    }

    if ($act === 'toggle_ban') {
        $userId = (int)$_POST['user_id'];
        $newStatus = $_POST['status'] === 'active' ? 'banned' : 'active';
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND subdomain_id = 0")->execute([$newStatus, $userId]);
        $toast = ['type' => 'success', 'msg' => "Status changed to $newStatus!"];
    }

    if ($act === 'update_gateway_rules') {
        $keys = [
            'usdt_rate', 'inr_rate', 'binance_market_rate', 'binance_id', 'payment_banner_url',
            'deposit_system_status', 'min_deposit_limit', 'max_deposit_limit',
            'telegram_tutorial_link', 'auto_payment_on', 'snzpay_api_url', 'snzpay_license_key', 'snzpay_auto_verify'
        ];

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($keys as $k) {
            if (!array_key_exists($k, $_POST) && in_array($k, ['usdt_rate', 'inr_rate', 'binance_market_rate'], true)) continue;
            $val = trim($_POST[$k] ?? '0');
            if (in_array($k, ['usdt_rate', 'inr_rate', 'binance_market_rate'], true)) $val = (string)max(1.0, (float)$val);
            $stmt->execute([$k, $val, $val]);
            $settings[$k] = $val;
        }

        if (!empty($_FILES['payment_banner_file']['name']) && $_FILES['payment_banner_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['payment_banner_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $fileName = 'payment_banner_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['payment_banner_file']['tmp_name'], $bannerUploadDir . $fileName)) {
                    $bannerPath = 'uploads/banners/' . $fileName;
                    $stmt->execute(['payment_banner_url', $bannerPath, $bannerPath]);
                    $settings['payment_banner_url'] = $bannerPath;
                }
            }
        }

        $effectiveBdtRate = max(1.0, (float)($settings['usdt_rate'] ?? 125.0));
        $effectiveInrRate = max(1.0, (float)($settings['inr_rate'] ?? 88.0));
        $toast = ['type' => 'success', 'msg' => 'Main Domain gateway rules & settings saved successfully!'];
    }

    if ($act === 'save_deposit_bonus') {
        $bonusId     = (int)($_POST['bonus_id'] ?? 0);
        $minDeposit  = max(1.0, (float)($_POST['min_deposit'] ?? 500));
        $bonusAmount = max(0.01, (float)($_POST['bonus_amount'] ?? 50));
        $bonusType   = in_array($_POST['bonus_type'] ?? '', ['fixed', 'percentage']) ? $_POST['bonus_type'] : 'fixed';
        $title       = trim($_POST['title'] ?? '');
        $claimLimit  = max(0, (int)($_POST['claim_limit'] ?? 0));
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if (empty($title)) {
            $title = number_format($minDeposit) . " BDT Deposit = " . number_format($bonusAmount) . ($bonusType === 'percentage' ? '%' : ' BDT') . " Bonus";
        }

        try {
            if ($bonusId > 0) {
                $stmt = $pdo->prepare("UPDATE deposit_bonuses SET min_deposit = ?, bonus_amount = ?, bonus_type = ?, title = ?, claim_limit = ?, sort_order = ?, is_active = ? WHERE id = ? AND subdomain_id = 0");
                $stmt->execute([$minDeposit, $bonusAmount, $bonusType, $title, $claimLimit, $sortOrder, $isActive, $bonusId]);
                $toast = ['type' => 'success', 'msg' => 'Deposit Reward tier updated!'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO deposit_bonuses (subdomain_id, min_deposit, bonus_amount, bonus_type, title, claim_limit, sort_order, is_active) VALUES (0, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$minDeposit, $bonusAmount, $bonusType, $title, $claimLimit, $sortOrder, $isActive]);
                $toast = ['type' => 'success', 'msg' => 'Deposit Reward tier added!'];
            }
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'delete_deposit_bonus') {
        $bonusId = (int)$_POST['bonus_id'];
        $pdo->prepare("DELETE FROM deposit_bonuses WHERE id = ? AND subdomain_id = 0")->execute([$bonusId]);
        $toast = ['type' => 'success', 'msg' => 'Deposit Reward tier deleted!'];
    }

    // --- COUPON SAVE & DELETE ACTIONS ---
    if ($act === 'save_coupon') {
        $code       = strtoupper(trim($_POST['coupon_code'] ?? ''));
        $discType   = in_array($_POST['discount_type'] ?? '', ['percent', 'fixed']) ? $_POST['discount_type'] : 'percent';
        $discVal    = max(0.01, (float)($_POST['discount_value'] ?? 0));
        $targetType = in_array($_POST['target_type'] ?? '', ['all', 'specific']) ? $_POST['target_type'] : 'all';
        $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

        if (!empty($code)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO coupons (subdomain_id, code, discount_type, discount_value, target_type, expiry_date) VALUES (0, ?, ?, ?, ?, ?)");
                $stmt->execute([$code, $discType, $discVal, $targetType, $expiryDate]);
                $toast = ['type' => 'success', 'msg' => "Coupon '$code' created successfully!"];
            } catch (Throwable $e) {
                $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
            }
        }
    }

    if ($act === 'delete_coupon') {
        $couponId = (int)($_POST['coupon_id'] ?? 0);
        if ($couponId > 0) {
            $pdo->prepare("DELETE FROM coupons WHERE id = ? AND subdomain_id = 0")->execute([$couponId]);
            $toast = ['type' => 'success', 'msg' => 'Coupon deleted successfully!'];
        }
    }

    if ($act === 'update_spin_wheel_settings') {
        $spinEnabled = isset($_POST['spin_wheel_enabled']) ? '1' : '0';
        $spinCost    = max(0.00, (float)($_POST['spin_cost'] ?? 0));
        $dailyLimit  = max(1, (int)($_POST['spin_daily_limit'] ?? 5));
        $expiryHours = max(1, (int)($_POST['spin_offer_duration_hours'] ?? 24));

        $saveArray = [
            'spin_wheel_enabled'        => $spinEnabled,
            'spin_cost'                 => (string)$spinCost,
            'spin_daily_limit'          => (string)$dailyLimit,
            'spin_offer_duration_hours' => (string)$expiryHours
        ];

        $stmtSet = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($saveArray as $k => $v) {
            $stmtSet->execute([$k, $v, $v]);
            $settings[$k] = $v;
        }
        $toast = ['type' => 'success', 'msg' => 'Spin rules saved!'];
    }

    if ($act === 'save_spin_segment') {
        $segId       = (int)($_POST['segment_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $spendTarget = max(1, (float)($_POST['spend_target'] ?? 500));
        $bonusAmount = max(1, (float)($_POST['bonus_amount'] ?? 50));
        $probability = max(1, (int)($_POST['probability'] ?? 20));
        $color       = trim($_POST['color'] ?? '#d946ef');
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if (empty($title)) {
            $title = number_format($spendTarget) . " TK → " . number_format($bonusAmount) . " TK Bonus";
        }

        try {
            if ($segId > 0) {
                $stmt = $pdo->prepare("UPDATE spin_segments SET title = ?, spend_target = ?, bonus_amount = ?, probability = ?, color = ?, sort_order = ?, is_active = ? WHERE id = ? AND subdomain_id = 0");
                $stmt->execute([$title, $spendTarget, $bonusAmount, $probability, $color, $sortOrder, $isActive, $segId]);
                $toast = ['type' => 'success', 'msg' => 'Segment updated!'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO spin_segments (subdomain_id, title, spend_target, bonus_amount, probability, color, sort_order, is_active) VALUES (0, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $spendTarget, $bonusAmount, $probability, $color, $sortOrder, $isActive]);
                $toast = ['type' => 'success', 'msg' => 'Segment added!'];
            }
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'delete_spin_segment') {
        $segId = (int)$_POST['segment_id'];
        $pdo->prepare("DELETE FROM spin_segments WHERE id = ? AND subdomain_id = 0")->execute([$segId]);
        $toast = ['type' => 'success', 'msg' => 'Segment deleted!'];
    }

    if ($act === 'save_firebase_settings') {
        $fbKeys = ['firebase_api_key', 'firebase_auth_domain', 'firebase_project_id', 'firebase_storage_bucket', 'firebase_messaging_sender_id', 'firebase_app_id'];
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($fbKeys as $k) {
            $val = trim($_POST[$k] ?? '');
            $stmt->execute([$k, $val, $val]);
            $settings[$k] = $val;
        }
        $toast = ['type' => 'success', 'msg' => 'Firebase Web SDK Configuration Saved!'];
    }

    if ($act === 'manage_trx') {
        $trxId = (int)$_POST['trx_db_id'];
        $status = $_POST['status'];
        $trx = $pdo->query("SELECT * FROM transactions WHERE id = $trxId AND subdomain_id = 0")->fetch();
        if ($trx && $trx['status'] === 'pending') {
            $pdo->prepare("UPDATE transactions SET status = ? WHERE id = ? AND subdomain_id = 0")->execute([$status, $trxId]);
            if ($status === 'approved') {
                $bonusFloat = 0.00;
                if (!empty($trx['bonus_amount']) && (float)$trx['bonus_amount'] > 0) {
                    $bonusFloat = round((float)$trx['bonus_amount'] / $effectiveBdtRate, 2);
                }
                $totalCredit = (float)$trx['amount'] + $bonusFloat;
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ? AND subdomain_id = 0")->execute([$totalCredit, $trx['user_id']]);
            }
            $toast = ['type' => 'success', 'msg' => "Deposit marked $status!"];
        }
    }

    if ($act === 'save_stock_keys') {
        $productId = (int)$_POST['stock_product_id'];
        $planId    = (int)$_POST['stock_plan_id'];
        $rawKeys   = trim($_POST['bulk_keys'] ?? '');
        if ($productId > 0 && $planId > 0 && !empty($rawKeys)) {
            $lines = preg_split('/\r\n|\r|\n/', $rawKeys);
            $inserted = 0;
            $stmtStock = $pdo->prepare("INSERT INTO stock_keys (subdomain_id, product_id, plan_id, license_key, status) VALUES (0, ?, ?, ?, 'available')");
            foreach ($lines as $line) {
                $cleanKey = trim($line);
                if (!empty($cleanKey)) {
                    $stmtStock->execute([$productId, $planId, $cleanKey]);
                    $inserted++;
                }
            }
            $toast = ['type' => 'success', 'msg' => "Added $inserted keys to Main Domain vault!"];
        }
    }

    if ($act === 'delete_single_key') {
        $keyId = (int)$_POST['key_id'];
        $pdo->prepare("DELETE FROM stock_keys WHERE id = ? AND subdomain_id = 0")->execute([$keyId]);
        $toast = ['type' => 'success', 'msg' => 'Key deleted!'];
    }

    if ($act === 'add_single_plan') {
        $prodId = (int)$_POST['product_id'];
        $title = trim($_POST['plan_title'] ?? '1 Day');
        $uPrice = (float)($_POST['user_price'] ?? 1.00);
        $rPrice = (float)($_POST['reseller_price'] ?? round($uPrice * 0.8, 2));
        $apiId = (int)($_POST['api_plan_id'] ?? 0);
        $rewardCoins = max(0, (int)($_POST['reward_coins'] ?? 0));
        $coinPrice = max(0, (int)($_POST['coin_price'] ?? 0));

        $pdo->prepare("INSERT INTO product_plans (subdomain_id, product_id, duration_days, plan_title, reseller_price, user_price, regular_price, api_plan_id, reward_coins, coin_price) VALUES (0, ?, 1, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$prodId, $title, $rPrice, $uPrice, $uPrice, $apiId, $rewardCoins, $coinPrice]);
        $toast = ['type' => 'success', 'msg' => 'Plan with Coins added to Main Domain!'];
    }

    if ($act === 'delete_product_entire') {
        $prodId = (int)$_POST['product_id'];
        $pdo->prepare("DELETE FROM stock_keys WHERE product_id = ? AND subdomain_id = 0")->execute([$prodId]);
        $pdo->prepare("DELETE FROM product_plans WHERE product_id = ? AND subdomain_id = 0")->execute([$prodId]);
        $pdo->prepare("DELETE FROM products WHERE id = ? AND subdomain_id = 0")->execute([$prodId]);
        $toast = ['type' => 'success', 'msg' => 'Product removed from Main Domain!'];
    }

    if ($act === 'add_banner') {
        $targetLink = trim($_POST['target_link'] ?? '');
        $title = trim($_POST['title'] ?? 'Promo Banner');
        $sort = (int)($_POST['sort_order'] ?? 0);
        $bannerPath = null;

        if (!empty($_FILES['banner_photo']['name']) && $_FILES['banner_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['banner_photo']['name'], PATHINFO_EXTENSION));
            $fileName = 'banner_' . time() . '_' . rand(100, 999) . '.' . $ext;
            if (move_uploaded_file($_FILES['banner_photo']['tmp_name'], $bannerUploadDir . $fileName)) {
                $bannerPath = 'uploads/banners/' . $fileName;
            }
        }
        if (!empty($bannerPath)) {
            $pdo->prepare("INSERT INTO banners (subdomain_id, image_url, target_link, title, sort_order, is_active) VALUES (0, ?, ?, ?, ?, 1)")
                ->execute([$bannerPath, $targetLink ?: null, $title, $sort]);
            $toast = ['type' => 'success', 'msg' => 'Banner added to Main Domain!'];
        }
    }

    if ($act === 'delete_banner') {
        $pdo->prepare("DELETE FROM banners WHERE id = ? AND subdomain_id = 0")->execute([(int)$_POST['banner_id']]);
        $toast = ['type' => 'success', 'msg' => 'Banner deleted!'];
    }

    if ($act === 'add_bot_admin') {
        $tgId = trim($_POST['telegram_id'] ?? '');
        $adminName = trim($_POST['admin_name'] ?? 'Admin');
        if (!empty($tgId)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO bot_admins (telegram_id, admin_name) VALUES (?, ?)");
                $stmt->execute([$tgId, $adminName]);
                $toast = ['type' => 'success', 'msg' => "Admin added!"];
            } catch (Throwable $e) {
                $toast = ['type' => 'error', 'msg' => "Already an admin."];
            }
        }
    }

    if ($act === 'remove_bot_admin') {
        $tgId = trim($_POST['telegram_id'] ?? '');
        $pdo->prepare("DELETE FROM bot_admins WHERE telegram_id = ?")->execute([$tgId]);
        $toast = ['type' => 'success', 'msg' => "Admin removed!"];
    }

    if ($act === 'add_new_product') {
        $name = trim($_POST['name'] ?? '');
        $category = strtoupper(trim($_POST['category'] ?? 'APKMOD'));
        $downloadLink = trim($_POST['download_link'] ?? '');
        $setupLink = trim($_POST['setup_link'] ?? '');
        $appDownloadUrl = trim($_POST['app_download_url'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $isPinned = isset($_POST['is_pinned']) ? 1 : 0;

        if (!empty($name)) {
            $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $name));
            $logoUrl = null;
            $bannerUrl = null;

            if (!empty($_FILES['product_logo']['name']) && $_FILES['product_logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['product_logo']['name'], PATHINFO_EXTENSION));
                $fileName = 'prod_logo_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['product_logo']['tmp_name'], $prodUploadDir . $fileName)) {
                    $logoUrl = 'uploads/products/' . $fileName;
                }
            }
            if (!empty($_FILES['product_banner']['name']) && $_FILES['product_banner']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['product_banner']['name'], PATHINFO_EXTENSION));
                $fileName = 'prod_banner_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['product_banner']['tmp_name'], $prodUploadDir . $fileName)) {
                    $bannerUrl = 'uploads/products/' . $fileName;
                }
            }

            $stmt = $pdo->prepare("INSERT INTO products (subdomain_id, name, code, category, logo_url, banner_url, image_url, download_link, setup_link, app_download_url, is_active, is_pinned) VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $code, $category, $logoUrl, $bannerUrl, $bannerUrl, $downloadLink, $setupLink, $appDownloadUrl, $isActive, $isPinned]);
            $toast = ['type' => 'success', 'msg' => "Product created on Main Domain!"];
        }
    }
}

// ====================================================================
// CORE METRICS & ANALYTICS QUERIES (STRICT MAIN DOMAIN)
// ====================================================================
$totalUsers = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE subdomain_id = 0")->fetchColumn() ?: 0);
$totalCustomers = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE (role = 'customer' OR role IS NULL OR role = '') AND subdomain_id = 0")->fetchColumn() ?: 0);
$totalResellers = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE role = 'reseller' AND subdomain_id = 0")->fetchColumn() ?: 0);
$totalFunds = (float)($pdo->query("SELECT SUM(balance) FROM users WHERE subdomain_id = 0")->fetchColumn() ?: 0.00);

$totalLicenses = (int)($pdo->query("SELECT COUNT(*) FROM licenses WHERE subdomain_id = 0")->fetchColumn() ?: 0);
$todaySales = (float)($pdo->query("SELECT SUM(price) FROM licenses WHERE DATE(created_at) = CURDATE() AND subdomain_id = 0")->fetchColumn() ?: 0.00);
$monthSales = (float)($pdo->query("SELECT SUM(price) FROM licenses WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND subdomain_id = 0")->fetchColumn() ?: 0.00);
$allTimeSales = (float)($pdo->query("SELECT SUM(price) FROM licenses WHERE subdomain_id = 0")->fetchColumn() ?: 0.00);

$totalStockKeys = (int)($pdo->query("SELECT COUNT(*) FROM stock_keys WHERE status = 'available' AND subdomain_id = 0")->fetchColumn() ?: 0);
$pendingTrxCount = (int)($pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending' AND subdomain_id = 0")->fetchColumn() ?: 0);

$usersList = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM licenses WHERE user_id = u.id AND subdomain_id = 0) AS total_sold_keys FROM users u WHERE u.subdomain_id = 0 ORDER BY u.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$productsData = [];
$allProductPlansFlat = [];
try {
    $productsQuery = $pdo->query("SELECT * FROM products WHERE subdomain_id = 0 ORDER BY sort_order ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($productsQuery as $pr) {
        $plansQuery = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM stock_keys s WHERE s.plan_id = p.id AND s.status = 'available' AND s.subdomain_id = 0) AS stock_count FROM product_plans p WHERE p.product_id = ? AND p.subdomain_id = 0 ORDER BY p.user_price ASC, p.id ASC");
        $plansQuery->execute([$pr['id']]);
        $pr['plans'] = $plansQuery->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pr['plans'] as $pl) {
            $pl['product_name'] = $pr['name'];
            $allProductPlansFlat[] = $pl;
        }
        $productsData[] = $pr;
    }
} catch (Throwable $e) {}

$kmImportedPlans = $pdo->query("
    SELECT pl.*, p.name as product_name 
    FROM product_plans pl 
    JOIN products p ON pl.product_id = p.id 
    WHERE pl.api_plan_id > 0 AND pl.subdomain_id = 0
    ORDER BY pl.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
$kmImportedCount = count($kmImportedPlans);

$paymentMethodsList = $pdo->query("SELECT * FROM payment_methods WHERE subdomain_id = 0 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$spinSegments = $pdo->query("SELECT * FROM spin_segments WHERE subdomain_id = 0 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$depositBonusesList = $pdo->query("SELECT * FROM deposit_bonuses WHERE subdomain_id = 0 ORDER BY sort_order ASC, min_deposit ASC")->fetchAll(PDO::FETCH_ASSOC);

$subdomainsList = $pdo->query("SELECT * FROM subdomains ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$transactions = $pdo->query("SELECT t.*, u.username, u.email FROM transactions t JOIN users u ON t.user_id = u.id WHERE t.subdomain_id = 0 ORDER BY t.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$sales = $pdo->query("SELECT l.*, u.username, u.email, u.created_by, (SELECT subdomain FROM subdomains WHERE user_id = u.created_by LIMIT 1) as sub_domain_name FROM licenses l JOIN users u ON l.user_id = u.id WHERE l.subdomain_id = 0 ORDER BY l.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$botAdminsList = $pdo->query("SELECT * FROM bot_admins ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$bannersList = $pdo->query("SELECT * FROM banners WHERE subdomain_id = 0 ORDER BY sort_order ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$sentNotifications = $pdo->query("SELECT * FROM system_notifications WHERE subdomain_id = 0 ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

$userCustomPricesMap = [];
$cPriceRows = $pdo->query("SELECT user_id, plan_id, custom_price FROM user_custom_prices")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cPriceRows as $cp) {
    $userCustomPricesMap[$cp['user_id']][$cp['plan_id']] = (float)$cp['custom_price'];
}

$userAllowedProdsMap = [];
$aProdRows = $pdo->query("SELECT user_id, product_id FROM user_allowed_products")->fetchAll(PDO::FETCH_ASSOC);
foreach ($aProdRows as $ap) {
    $userAllowedProdsMap[$ap['user_id']][] = (int)$ap['product_id'];
}

$providerBalance = km_get_cached_provider_balance($pdo);
$mainOwnerId = defined('ADMIN_TELEGRAM_ID') ? (string)ADMIN_TELEGRAM_ID : '';
$mainThemeColor = $settings['main_site_theme_color'] ?? '#00f5ff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>NOC CONSOLE — ROOT PRO</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --site-accent: <?= htmlspecialchars($mainThemeColor) ?>;
        }
        body { 
            background-color: #030612; 
            color: #e2e8f0; 
            font-family: 'Plus Jakarta Sans', sans-serif; 
        }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        
        .stat-card { 
            background: #070d20; 
            border: 1px solid #16203a; 
            border-radius: 18px; 
            padding: 1.25rem 1.5rem;
            transition: all 0.2s ease;
        }
        .box-panel { 
            background: #070d20; 
            border: 1px solid #16203a; 
            border-radius: 20px; 
            padding: 1.5rem;
        }
        
        .btn-cyan { 
            background: linear-gradient(135deg, var(--site-accent) 0%, #0099ff 100%); 
            color: #020617; 
            font-weight: 800; 
            box-shadow: 0 4px 18px rgba(0, 245, 255, 0.25);
        }
        .btn-emerald { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #020617; font-weight: 800; }
        .btn-magenta { background: linear-gradient(135deg, #d946ef 0%, #9333ea 100%); color: #ffffff; font-weight: 800; }
        .btn-purple  { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); color: #ffffff; font-weight: 800; }
        .btn-rose { background: #ef4444; color: #ffffff; font-weight: 800; }

        .table-roomy {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table-roomy th {
            padding: 1rem 1.25rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            border-bottom: 1px solid #1e293b;
            white-space: nowrap;
        }
        .table-roomy td {
            padding: 1.15rem 1.25rem;
            border-bottom: 1px solid #142038;
            white-space: nowrap;
            vertical-align: middle;
        }
        .table-roomy tr:hover td {
            background-color: rgba(15, 23, 42, 0.6);
        }

        .drawer-nav-btn {
            width: 100%; 
            display: flex; 
            align-items: center; 
            gap: 0.85rem; 
            padding: 0.75rem 1rem;
            border-radius: 0.85rem; 
            color: #94a3b8; 
            font-size: 0.8rem; 
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700; 
            transition: all 0.2s ease; 
            text-align: left; 
            border: 1px solid transparent;
        }
        .drawer-nav-btn:hover { 
            background: #0f1730; 
            color: var(--site-accent); 
            border-color: rgba(0,245,255,0.25); 
        }
        .drawer-nav-btn.active {
            background: linear-gradient(90deg, rgba(0,245,255,0.18) 0%, rgba(7,13,32,0.98) 100%);
            color: #ffffff; 
            border-left: 4px solid var(--site-accent); 
            border-color: rgba(0,245,255,0.35);
        }
        .drawer-nav-btn.active i { color: var(--site-accent) !important; }

        .pulse-ring { animation: pulse-ring 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes pulse-ring { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(1.1); } }
        
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: #030612; }
        ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 6px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--site-accent); }
    </style>
</head>
<body class="min-h-screen pb-16 antialiased">

    <!-- Toast Notification -->
    <div id="toastNotification" class="fixed top-5 left-1/2 transform -translate-x-1/2 z-[999999] transition-all duration-300 pointer-events-none opacity-0 translate-y-[-20px] w-[90%] max-w-md">
        <div id="toastInner" class="bg-[#0b1329] border border-cyan-500/50 shadow-2xl rounded-2xl p-4 flex items-center space-x-3 text-xs font-bold text-cyan-200">
            <i id="toastIcon" class="fa-solid fa-circle-check text-cyan-400 text-base"></i>
            <span id="toastMsg" class="font-mono truncate">Notification</span>
        </div>
    </div>

    <!-- SUCCESS POPUP: SUBDOMAIN CREDENTIALS MODAL -->
    <?php if ($createdTenantData): ?>
    <div id="tenantSuccessModal" class="fixed inset-0 bg-black/90 backdrop-blur-md z-[99999] flex items-center justify-center p-4 font-mono">
        <div class="box-panel p-6 w-full max-w-md space-y-4 border-cyan-400 shadow-2xl relative text-xs">
            <div class="text-center space-y-1.5 pb-2 border-b border-slate-800">
                <div class="w-12 h-12 mx-auto rounded-2xl bg-cyan-500/10 border border-cyan-400 flex items-center justify-center text-cyan-400 text-xl">
                    <i class="fa-solid fa-check-circle"></i>
                </div>
                <h3 class="text-sm font-black text-white uppercase tracking-wider">TENANT DEPLOYED SUCCESSFULLY!</h3>
                <p class="text-[11px] text-slate-400 font-sans">Save these credentials. Share with the sub-admin.</p>
            </div>

            <div class="space-y-3 bg-slate-900/90 p-4 rounded-xl border border-slate-800">
                <div>
                    <span class="text-[10px] text-slate-400 font-bold block mb-1 uppercase">Storefront URL:</span>
                    <div class="flex items-center justify-between bg-black/60 p-2.5 rounded-lg border border-slate-800">
                        <span id="cpStoreUrl" class="text-cyan-300 truncate text-[11px] select-all"><?= htmlspecialchars($createdTenantData['store_url']) ?></span>
                        <button type="button" onclick="copyText('cpStoreUrl')" class="text-slate-400 hover:text-white ml-2 text-xs font-bold"><i class="fa-solid fa-copy"></i></button>
                    </div>
                </div>

                <div>
                    <span class="text-[10px] text-slate-400 font-bold block mb-1 uppercase">Sub-Admin Panel URL:</span>
                    <div class="flex items-center justify-between bg-black/60 p-2.5 rounded-lg border border-slate-800">
                        <span id="cpPanelUrl" class="text-emerald-400 truncate text-[11px] select-all"><?= htmlspecialchars($createdTenantData['panel_url']) ?></span>
                        <button type="button" onclick="copyText('cpPanelUrl')" class="text-slate-400 hover:text-white ml-2 text-xs font-bold"><i class="fa-solid fa-copy"></i></button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block mb-1 uppercase">Username:</span>
                        <div class="flex items-center justify-between bg-black/60 p-2 rounded-lg border border-slate-800">
                            <span id="cpUsername" class="text-amber-300 font-bold text-xs select-all"><?= htmlspecialchars($createdTenantData['username']) ?></span>
                            <button type="button" onclick="copyText('cpUsername')" class="text-slate-400 hover:text-white ml-1 text-xs"><i class="fa-solid fa-copy"></i></button>
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block mb-1 uppercase">Password:</span>
                        <div class="flex items-center justify-between bg-black/60 p-2 rounded-lg border border-slate-800">
                            <span id="cpPassword" class="text-amber-300 font-bold text-xs select-all"><?= htmlspecialchars($createdTenantData['password']) ?></span>
                            <button type="button" onclick="copyText('cpPassword')" class="text-slate-400 hover:text-white ml-1 text-xs"><i class="fa-solid fa-copy"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex gap-2 pt-1">
                <button type="button" onclick="copyAllTenantDetails()" class="flex-1 py-3 btn-cyan rounded-xl uppercase font-black text-xs flex items-center justify-center gap-2">
                    <i class="fa-solid fa-copy"></i> Copy All Details
                </button>
                <button type="button" onclick="document.getElementById('tenantSuccessModal').classList.add('hidden')" class="px-5 py-3 bg-slate-800 text-slate-300 hover:text-white rounded-xl uppercase font-bold text-xs">
                    Done
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Drawer Overlay -->
    <div id="drawerOverlay" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-40 hidden" onclick="toggleAdminDrawer()"></div>

    <!-- Sidebar Drawer Navigation -->
    <aside id="adminDrawer" class="fixed top-0 right-0 w-[310px] h-full bg-[#050814] border-l border-slate-800 z-50 transform translate-x-full transition-transform duration-300 flex flex-col justify-between p-5 overflow-y-auto shadow-2xl">
        <div class="space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                <div class="flex items-center space-x-2.5">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-slate-950 font-black shadow-md">
                        <i class="fa-solid fa-microchip text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-xs font-black text-white uppercase tracking-wider">ROOT SYSTEM</h3>
                        <span class="text-[9px] text-cyan-400 font-mono">MOTHER PRO</span>
                    </div>
                </div>
                <button type="button" onclick="toggleAdminDrawer()" class="text-slate-400 hover:text-white p-1">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <nav class="space-y-1">
                <button id="btn-nav-tab-overview" onclick="switchAdminTab('tab-overview', 'Dashboard Overview', this)" class="drawer-nav-btn active">
                    <i class="fa-solid fa-chart-pie w-4 text-blue-400"></i><span>Dashboard</span>
                </button>
                <button id="btn-nav-tab-products" onclick="switchAdminTab('tab-products', 'Products Catalog', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-boxes-stacked w-4 text-cyan-400"></i><span>Products Catalog</span>
                </button>

                <button id="btn-nav-tab-discounts" onclick="switchAdminTab('tab-discounts', 'Discount Management Hub', this)" class="drawer-nav-btn border-purple-500/25 bg-purple-950/20 text-purple-300">
                    <i class="fa-solid fa-tags w-4 text-purple-400"></i><span>Discount Hub</span>
                </button>

                <button id="btn-nav-tab-gateways" onclick="switchAdminTab('tab-gateways', 'Payment Gateway Rules & SNZPay', this)" class="drawer-nav-btn border-teal-500/25 bg-teal-950/20 text-teal-300">
                    <i class="fa-solid fa-building-columns w-4 text-emerald-400"></i><span>Payment Gateways</span>
                </button>

                <button id="btn-nav-tab-connectkm" onclick="switchAdminTab('tab-connectkm', 'Key Manager Integration', this)" class="drawer-nav-btn border-cyan-500/20 bg-cyan-950/20 text-cyan-300">
                    <i class="fa-solid fa-bolt-lightning w-4 text-amber-400"></i><span>Connect Key Manager</span>
                </button>
                <button id="btn-nav-tab-spinwheel" onclick="switchAdminTab('tab-spinwheel', 'Spin Control & Rewards', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-dharmachakra w-4 text-emerald-400"></i><span>Spin Control</span>
                </button>
                <button id="btn-nav-tab-adminaccess" onclick="switchAdminTab('tab-adminaccess', 'Admin Panel Access', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-user-shield w-4 text-amber-400"></i><span>Admin Panel Access</span>
                </button>
                <button id="btn-nav-tab-keys" onclick="switchAdminTab('tab-keys', 'License Sales Feed', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-key w-4 text-amber-400"></i><span>Keys &amp; History</span>
                </button>
                <button id="btn-nav-tab-revenue" onclick="switchAdminTab('tab-revenue', 'Revenue Report', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-file-invoice-dollar w-4 text-emerald-400"></i><span>Revenue Report</span>
                </button>
                <button id="btn-nav-tab-deposits" onclick="switchAdminTab('tab-deposits', 'Deposits & Rewards', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-wallet w-4 text-teal-400"></i><span>Deposits (<?= $pendingTrxCount ?>)</span>
                </button>
                <button id="btn-nav-tab-customers" onclick="switchAdminTab('tab-customers', 'Customers Management', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-user-group w-4 text-sky-400"></i><span>Customers</span>
                </button>
                <button id="btn-nav-tab-resellers" onclick="switchAdminTab('tab-resellers', 'Resellers Hub', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-users-gear w-4 text-purple-400"></i><span>Resellers</span>
                </button>
                <button id="btn-nav-tab-settings" onclick="switchAdminTab('tab-settings', 'Site Settings & Branding', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-sliders w-4 text-yellow-400"></i><span>Site Settings</span>
                </button>
                <button id="btn-nav-tab-push" onclick="switchAdminTab('tab-push', 'Push Notifications', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-paper-plane w-4 text-rose-400"></i><span>Push Notification</span>
                </button>
                <button id="btn-nav-tab-subdomains" onclick="switchAdminTab('tab-subdomains', 'Tenants Subdomains', this)" class="drawer-nav-btn border-blue-500/25 bg-blue-950/20 text-blue-300">
                    <i class="fa-solid fa-network-wired w-4 text-blue-400"></i><span>Sub Domain (Tenants)</span>
                </button>
                <button id="btn-nav-tab-themecolor" onclick="switchAdminTab('tab-themecolor', 'Main Site Theme Color', this)" class="drawer-nav-btn border-emerald-500/25 bg-emerald-950/20 text-emerald-300">
                    <i class="fa-solid fa-palette w-4 text-emerald-400"></i><span>Main Site Theme Color</span>
                </button>
            </nav>
        </div>

        <div class="pt-3 border-t border-slate-800">
            <a href="mother_admin.php?logout=1" class="w-full flex items-center justify-center space-x-2 py-2.5 rounded-xl bg-red-950/30 border border-red-500/20 text-red-400 hover:bg-red-900/30 text-xs font-bold font-mono transition">
                <i class="fa-solid fa-power-off"></i><span>TERMINATE SESSION</span>
            </a>
        </div>
    </aside>

    <!-- Header Bar -->
    <header class="w-full bg-[#050814]/90 backdrop-blur-md border-b border-slate-800 px-4 py-3.5 sticky top-0 z-30">
        <div class="max-w-6xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-slate-950 text-base font-black shadow-md shadow-cyan-950/40">
                    <i class="fa-solid fa-cloud-bolt"></i>
                </div>
                <div>
                    <div class="flex items-center space-x-2">
                        <h1 class="text-sm font-black text-white uppercase tracking-wider"><?= htmlspecialchars($settings['site_name']) ?></h1>
                        <span class="px-2 py-0.5 rounded text-[8px] font-black uppercase tracking-widest bg-cyan-950 text-cyan-300 border border-cyan-500/30 font-mono">
                            MOTHER PRO
                        </span>
                    </div>
                    <span id="headerSectionTitle" class="text-[10px] text-cyan-400 font-mono block mt-0.5">Overview</span>
                </div>
            </div>

            <div class="flex items-center space-x-2.5">
                <div class="hidden sm:flex items-center px-3 py-1.5 rounded-xl bg-[#091124] border border-slate-800 text-[11px] font-mono cursor-pointer hover:border-cyan-500/40 transition" onclick="switchAdminTab('tab-connectkm', 'Key Manager Integration', document.getElementById('btn-nav-tab-connectkm'))">
                    <span class="text-slate-400 mr-1.5 font-bold">KM WALLET:</span>
                    <strong class="text-emerald-400 font-extrabold"><?= $providerBalance ?></strong>
                </div>
                <button type="button" onclick="toggleAdminDrawer()" class="w-10 h-10 flex items-center justify-center bg-[#091124] border border-cyan-500/30 text-cyan-300 rounded-xl active:scale-95 transition">
                    <i class="fa-solid fa-bars text-sm"></i>
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 mt-6 space-y-6">

        <!-- 1. DASHBOARD OVERVIEW -->
        <section id="tab-overview" class="tab-section space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 font-mono">
                <div class="stat-card flex flex-col justify-between h-32">
                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">TODAY REVENUE</span>
                    <h2 class="text-3xl font-black text-emerald-400 mt-1">$<?= number_format($todaySales, 2) ?></h2>
                    <span class="text-[10px] text-slate-500 font-sans">Live Completed Sales</span>
                </div>
                <div class="stat-card flex flex-col justify-between h-32">
                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">TOTAL CUSTOMERS</span>
                    <h2 class="text-3xl font-black text-sky-400 mt-1"><?= $totalCustomers ?></h2>
                    <span class="text-[10px] text-slate-500 font-sans">Resellers: <?= $totalResellers ?></span>
                </div>
                <div class="stat-card flex flex-col justify-between h-32">
                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">DELIVERED LICENSES</span>
                    <h2 class="text-3xl font-black text-white mt-1"><?= $totalLicenses ?></h2>
                    <span class="text-[10px] text-slate-500 font-sans">In Stock: <?= $totalStockKeys ?></span>
                </div>
                <div class="stat-card flex flex-col justify-between h-32">
                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">PENDING DEPOSITS</span>
                    <h2 class="text-3xl font-black text-amber-400 mt-1"><?= $pendingTrxCount ?></h2>
                    <span class="text-[10px] text-slate-500 font-sans">Total Float: $<?= number_format($totalFunds, 2) ?></span>
                </div>
            </div>

            <div class="box-panel flex flex-col sm:flex-row items-center justify-between gap-4 border-purple-500/30 bg-gradient-to-r from-[#17092e] to-[#070d20]">
                <div class="flex items-center space-x-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-purple-500/10 text-purple-400 flex items-center justify-center text-xl border border-purple-500/20 shadow-inner">
                        <i class="fa-solid fa-tags"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-black text-white uppercase tracking-wider font-mono">Central Discount Engine</h4>
                        <div class="text-xs text-slate-400 font-mono mt-1">
                            Set global products, customers &amp; resellers stacked discounts for MAIN DOMAIN.
                        </div>
                    </div>
                </div>

                <button type="button" onclick="switchAdminTab('tab-discounts', 'Discount Management Hub', document.getElementById('btn-nav-tab-discounts'))" class="px-4 py-2.5 rounded-xl btn-purple text-xs uppercase tracking-wider whitespace-nowrap">
                    Manage Discounts
                </button>
            </div>

            <div class="box-panel flex flex-col sm:flex-row items-center justify-between gap-4 border-teal-500/30 bg-gradient-to-r from-[#041d1a] to-[#070d20]">
                <div class="flex items-center space-x-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-teal-500/10 text-emerald-400 flex items-center justify-center text-xl border border-teal-500/20 shadow-inner">
                        <i class="fa-solid fa-building-columns"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-black text-white uppercase tracking-wider font-mono">Dynamic Payment Gateways &amp; SNZPay</h4>
                        <div class="text-xs text-slate-400 font-mono mt-1">
                            Configure bKash, Nagad, Rocket, QR codes, banners, and 1-click SNZPay auto-verify for MAIN DOMAIN.
                        </div>
                    </div>
                </div>

                <button type="button" onclick="switchAdminTab('tab-gateways', 'Payment Gateway Rules & SNZPay', document.getElementById('btn-nav-tab-gateways'))" class="px-4 py-2.5 rounded-xl btn-emerald text-xs uppercase tracking-wider whitespace-nowrap">
                    Manage Gateways
                </button>
            </div>
        </section>

        <!-- 2. CENTRAL DISCOUNT MANAGEMENT HUB -->
        <section id="tab-discounts" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-2 grid grid-cols-4 gap-2 text-xs font-bold font-mono">
                <button type="button" onclick="switchDiscountSubTab('disc-view-products')" id="pill-disc-prods" class="py-3 px-2 rounded-xl bg-purple-600 text-white font-black flex items-center justify-center gap-1.5 shadow-sm transition">
                    <i class="fa-solid fa-boxes-stacked text-sm"></i><span>Products</span>
                </button>
                <button type="button" onclick="switchDiscountSubTab('disc-view-customers')" id="pill-disc-custs" class="py-3 px-2 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-1.5 transition">
                    <i class="fa-solid fa-user-group text-sm text-sky-400"></i><span>Customers</span>
                </button>
                <button type="button" onclick="switchDiscountSubTab('disc-view-resellers')" id="pill-disc-resells" class="py-3 px-2 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-1.5 transition">
                    <i class="fa-solid fa-users-gear text-sm text-amber-400"></i><span>Resellers</span>
                </button>
                <button type="button" onclick="switchDiscountSubTab('disc-view-coupons')" id="pill-disc-coupons" class="py-3 px-2 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-1.5 transition">
                    <i class="fa-solid fa-ticket text-sm text-fuchsia-400"></i><span>Coupons</span>
                </button>
            </div>

            <!-- SUB-SECTION 1: ALL PRODUCTS DISCOUNT -->
            <div id="disc-view-products" class="space-y-4">
                <div class="box-panel p-5 space-y-4 border-purple-500/25">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-3">
                        <div>
                            <h3 class="text-xs font-black text-purple-300 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-boxes-stacked text-purple-400"></i> GLOBAL PRODUCTS DISCOUNT CONTROL (MAIN DOMAIN ONLY)
                            </h3>
                            <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                                Set store-wide discount across all main domain product plans or turn off all product discounts anytime.
                            </span>
                        </div>
                    </div>

                    <div class="bg-slate-900/90 p-4 rounded-2xl border border-slate-800 space-y-3">
                        <span class="text-xs font-bold text-slate-300 uppercase block font-sans">⚡ Quick Action: All Products Together</span>
                        <div class="flex flex-col sm:flex-row items-center gap-2.5">
                            <form method="POST" class="flex items-center gap-2 w-full sm:w-auto">
                                <input type="hidden" name="admin_action" value="apply_bulk_product_discount">
                                <input type="hidden" name="active_tab" value="tab-discounts">
                                <input type="hidden" name="disc_sub_tab" value="disc-view-products">
                                <div class="flex items-center bg-[#030612] px-3 py-2 rounded-xl border border-slate-800">
                                    <span class="text-purple-400 font-bold text-xs mr-2">% Discount:</span>
                                    <input type="number" step="0.5" min="0" max="99" name="discount_percent" required placeholder="e.g. 10" class="w-20 bg-transparent text-emerald-400 font-black text-sm outline-none">
                                </div>
                                <button type="submit" class="py-2.5 px-4 rounded-xl btn-purple text-xs uppercase font-black whitespace-nowrap">
                                    Apply To All Products
                                </button>
                            </form>

                            <form method="POST" onsubmit="return confirm('Turn off all product discounts and restore regular prices?');" class="w-full sm:w-auto">
                                <input type="hidden" name="admin_action" value="reset_all_product_discounts">
                                <input type="hidden" name="active_tab" value="tab-discounts">
                                <input type="hidden" name="disc_sub_tab" value="disc-view-products">
                                <button type="submit" class="w-full sm:w-auto py-2.5 px-4 rounded-xl bg-red-950/40 border border-red-500/30 text-red-400 hover:bg-red-900/40 text-xs uppercase font-bold transition">
                                    <i class="fa-solid fa-power-off mr-1"></i> Turn Off All Product Discounts (0%)
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="relative pt-1">
                        <input type="text" id="discPlanSearchInput" onkeyup="filterDiscProductPlans()" placeholder="Search product or plan title to set individual discount..." class="w-full bg-[#030612] border border-slate-800 text-xs text-white rounded-xl p-3 pl-10 outline-none focus:border-purple-400">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none mt-0.5"></i>
                    </div>

                    <div class="overflow-x-auto rounded-2xl border border-slate-800 bg-slate-900/40">
                        <table class="table-roomy min-w-[750px] text-left text-xs font-mono">
                            <thead>
                                <tr>
                                    <th>PRODUCT &amp; PLAN</th>
                                    <th>REGULAR PRICE</th>
                                    <th>CURRENT DISCOUNT</th>
                                    <th>SALE PRICE</th>
                                    <th class="text-right">UPDATE DISCOUNT</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/70 text-[11px]" id="discProductPlansTableBody">
                                <?php if (empty($allProductPlansFlat)): ?>
                                    <tr><td colspan="5" class="py-8 text-center text-slate-500 font-sans">No products/plans registered in catalog.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($allProductPlansFlat as $pl): 
                                        $regP = (float)$pl['regular_price'] > 0 ? (float)$pl['regular_price'] : (float)$pl['user_price'];
                                        $curP = (float)$pl['user_price'];
                                        $dPercent = (float)($pl['discount_percent'] ?? 0);
                                        $bdtPrice = round($curP * $effectiveBdtRate, 0);
                                    ?>
                                        <tr class="disc-plan-row hover:bg-slate-900/70 transition" data-keywords="<?= strtolower(htmlspecialchars($pl['product_name'] . ' ' . $pl['plan_title'])) ?>">
                                            <td>
                                                <strong class="font-bold text-white font-sans text-xs block"><?= htmlspecialchars($pl['product_name']) ?></strong>
                                                <span class="text-cyan-300 text-[10px] block mt-0.5"><?= htmlspecialchars($pl['plan_title']) ?></span>
                                            </td>
                                            <td class="text-slate-400 font-bold">
                                                $<?= number_format($regP, 2) ?> USD
                                            </td>
                                            <td>
                                                <?php if ($dPercent > 0): ?>
                                                    <span class="px-2 py-0.5 rounded text-[9px] font-black uppercase bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                                        <?= $dPercent ?>% OFF
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-slate-500 font-bold">0% (Regular)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong class="text-emerald-400 font-black text-xs">$<?= number_format($curP, 2) ?> USD</strong>
                                                <span class="text-[9px] text-slate-400 block font-mono">≈ ৳<?= number_format($bdtPrice, 0) ?> BDT</span>
                                            </td>
                                            <td class="text-right">
                                                <form method="POST" class="inline-flex items-center space-x-1.5">
                                                    <input type="hidden" name="admin_action" value="apply_single_plan_discount">
                                                    <input type="hidden" name="active_tab" value="tab-discounts">
                                                    <input type="hidden" name="disc_sub_tab" value="disc-view-products">
                                                    <input type="hidden" name="plan_id" value="<?= $pl['id'] ?>">
                                                    <input type="number" step="0.5" min="0" max="99" name="discount_percent" value="<?= $dPercent > 0 ? $dPercent : '' ?>" placeholder="0" class="w-14 bg-[#030612] border border-slate-800 p-1 rounded-lg text-purple-300 font-bold text-center text-xs outline-none">
                                                    <button type="submit" class="px-2.5 py-1 rounded-lg btn-purple text-[10px] uppercase font-bold">Save</button>
                                                    <?php if ($dPercent > 0): ?>
                                                        <button type="submit" onclick="this.form.discount_percent.value='0'" class="px-2 py-1 rounded-lg bg-red-950/40 text-red-400 border border-red-500/20 text-[10px] font-bold" title="Turn off discount">Off</button>
                                                    <?php endif; ?>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SUB-SECTION 2: CUSTOMERS DISCOUNT -->
            <div id="disc-view-customers" class="hidden space-y-4">
                <div class="box-panel p-5 space-y-4 border-sky-500/25">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-3">
                        <div>
                            <h3 class="text-xs font-black text-sky-300 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-user-group text-sky-400"></i> CUSTOMERS STACKED DISCOUNT CONTROL
                            </h3>
                            <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                                When you give customer discount (e.g. 5%), it automatically stacks with product plan discount for MAIN DOMAIN.
                            </span>
                        </div>
                    </div>

                    <div class="bg-slate-900/90 p-4 rounded-2xl border border-slate-800 space-y-3">
                        <span class="text-xs font-bold text-slate-300 uppercase block font-sans">⚡ Quick Action: All Customers Together</span>
                        <div class="flex flex-col sm:flex-row items-center gap-2.5">
                            <form method="POST" class="flex items-center gap-2 w-full sm:w-auto">
                                <input type="hidden" name="admin_action" value="apply_bulk_user_discount">
                                <input type="hidden" name="active_tab" value="tab-discounts">
                                <input type="hidden" name="disc_sub_tab" value="disc-view-customers">
                                <input type="hidden" name="target_role" value="customer">
                                <div class="flex items-center bg-[#030612] px-3 py-2 rounded-xl border border-slate-800">
                                    <span class="text-sky-400 font-bold text-xs mr-2">Extra % Disc:</span>
                                    <input type="number" step="0.5" min="0" max="99" name="discount_percent" required placeholder="e.g. 5" class="w-20 bg-transparent text-emerald-400 font-black text-sm outline-none">
                                </div>
                                <button type="submit" class="py-2.5 px-4 rounded-xl bg-gradient-to-r from-sky-500 to-blue-600 text-slate-950 text-xs uppercase font-black whitespace-nowrap shadow">
                                    Apply To All Customers
                                </button>
                            </form>

                            <form method="POST" onsubmit="return confirm('Turn off all customer extra discounts?');" class="w-full sm:w-auto">
                                <input type="hidden" name="admin_action" value="reset_all_user_discounts">
                                <input type="hidden" name="active_tab" value="tab-discounts">
                                <input type="hidden" name="disc_sub_tab" value="disc-view-customers">
                                <input type="hidden" name="target_role" value="customer">
                                <button type="submit" class="w-full sm:w-auto py-2.5 px-4 rounded-xl bg-red-950/40 border border-red-500/30 text-red-400 hover:bg-red-900/40 text-xs uppercase font-bold transition">
                                    <i class="fa-solid fa-power-off mr-1"></i> Turn Off All Customer Discounts (0%)
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="relative pt-1">
                        <input type="text" id="discCustomerSearchInput" onkeyup="filterDiscUsers('customer')" placeholder="Search customer by username or email..." class="w-full bg-[#030612] border border-slate-800 text-xs text-white rounded-xl p-3 pl-10 outline-none focus:border-sky-400">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none mt-0.5"></i>
                    </div>

                    <div class="space-y-2.5" id="discCustomerListContainer">
                        <?php foreach ($usersList as $u): 
                            $uRole = strtolower(trim((string)($u['role'] ?? 'customer')));
                            if ($uRole === 'reseller' || $uRole === 'admin') continue;
                            $uDisc = (float)($u['discount_percent'] ?? 0);
                        ?>
                            <div class="stat-card flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs disc-user-card"
                                 data-username="<?= strtolower(htmlspecialchars($u['username'] ?? '')) ?>"
                                 data-email="<?= strtolower(htmlspecialchars($u['email'] ?? '')) ?>">
                                <div>
                                    <div class="flex items-center space-x-2.5">
                                        <h4 class="font-bold text-white text-sm"><?= htmlspecialchars($u['username']) ?></h4>
                                        <?php if ($uDisc > 0): ?>
                                            <span class="text-[9px] px-2 py-0.5 rounded font-black font-mono uppercase bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                                +<?= $uDisc ?>% Stacked Disc
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[9px] px-2 py-0.5 rounded font-bold font-mono uppercase bg-slate-800 text-slate-400">
                                                Regular (0%)
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center space-x-3 mt-1 font-mono text-[11px] text-slate-400">
                                        <span><?= htmlspecialchars($u['email'] ?: 'No Gmail') ?></span>
                                        <span>Wallet: <strong class="text-emerald-400">$<?= number_format((float)$u['balance'], 2) ?></strong></span>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2 font-mono flex-shrink-0">
                                    <form method="POST" class="inline-flex items-center space-x-1.5">
                                        <input type="hidden" name="admin_action" value="apply_single_user_discount">
                                        <input type="hidden" name="active_tab" value="tab-discounts">
                                        <input type="hidden" name="disc_sub_tab" value="disc-view-customers">
                                        <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                                        <input type="number" step="0.5" min="0" max="99" name="discount_percent" value="<?= $uDisc > 0 ? $uDisc : '' ?>" placeholder="0" class="w-14 bg-[#030612] border border-slate-800 p-1.5 rounded-lg text-sky-300 font-bold text-center text-xs outline-none">
                                        <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-sky-500/20 text-sky-300 border border-sky-500/30 font-bold hover:bg-sky-500/30 text-xs">Set %</button>
                                    </form>

                                    <?php if ($uDisc > 0): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="admin_action" value="reset_single_user_discount">
                                            <input type="hidden" name="active_tab" value="tab-discounts">
                                            <input type="hidden" name="disc_sub_tab" value="disc-view-customers">
                                            <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-red-950/40 text-red-400 border border-red-500/20 font-bold hover:bg-red-900/40 text-xs" title="Turn off discount">Off</button>
                                        </form>
                                    <?php endif; ?>

                                    <button type="button" onclick="openUserAccessModal(<?= htmlspecialchars(json_encode($u)) ?>)" class="px-2.5 py-1.5 rounded-lg bg-slate-800 text-slate-300 hover:text-white text-xs font-bold transition">
                                        Plan Custom $
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- SUB-SECTION 3: RESELLERS DISCOUNT -->
            <div id="disc-view-resellers" class="hidden space-y-4">
                <div class="box-panel p-5 space-y-4 border-amber-500/25">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-3">
                        <div>
                            <h3 class="text-xs font-black text-amber-300 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-users-gear text-amber-400"></i> RESELLERS STACKED DISCOUNT CONTROL
                            </h3>
                            <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                                Set wholesale tier discounts for MAIN DOMAIN. Automatically stacks with each product's base discount!
                            </span>
                        </div>
                    </div>

                    <div class="bg-slate-900/90 p-4 rounded-2xl border border-slate-800 space-y-3">
                        <span class="text-xs font-bold text-slate-300 uppercase block font-sans">⚡ Quick Action: All Resellers Together</span>
                        <div class="flex flex-col sm:flex-row items-center gap-2.5">
                            <form method="POST" class="flex items-center gap-2 w-full sm:w-auto">
                                <input type="hidden" name="admin_action" value="apply_bulk_user_discount">
                                <input type="hidden" name="active_tab" value="tab-discounts">
                                <input type="hidden" name="disc_sub_tab" value="disc-view-resellers">
                                <input type="hidden" name="target_role" value="reseller">
                                <div class="flex items-center bg-[#030612] px-3 py-2 rounded-xl border border-slate-800">
                                    <span class="text-amber-400 font-bold text-xs mr-2">Extra % Disc:</span>
                                    <input type="number" step="0.5" min="0" max="99" name="discount_percent" required placeholder="e.g. 10" class="w-20 bg-transparent text-emerald-400 font-black text-sm outline-none">
                                </div>
                                <button type="submit" class="py-2.5 px-4 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 text-slate-950 text-xs uppercase font-black whitespace-nowrap shadow">
                                    Apply To All Resellers
                                </button>
                            </form>

                            <form method="POST" onsubmit="return confirm('Turn off all reseller extra discounts?');" class="w-full sm:w-auto">
                                <input type="hidden" name="admin_action" value="reset_all_user_discounts">
                                <input type="hidden" name="active_tab" value="tab-discounts">
                                <input type="hidden" name="disc_sub_tab" value="disc-view-resellers">
                                <input type="hidden" name="target_role" value="reseller">
                                <button type="submit" class="w-full sm:w-auto py-2.5 px-4 rounded-xl bg-red-950/40 border border-red-500/30 text-red-400 hover:bg-red-900/40 text-xs uppercase font-bold transition">
                                    <i class="fa-solid fa-power-off mr-1"></i> Turn Off All Reseller Discounts (0%)
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="relative pt-1">
                        <input type="text" id="discResellerSearchInput" onkeyup="filterDiscUsers('reseller')" placeholder="Search reseller by username or email..." class="w-full bg-[#030612] border border-slate-800 text-xs text-white rounded-xl p-3 pl-10 outline-none focus:border-amber-400">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none mt-0.5"></i>
                    </div>

                    <div class="space-y-2.5" id="discResellerListContainer">
                        <?php foreach ($usersList as $u): 
                            $uRole = strtolower(trim((string)($u['role'] ?? '')));
                            if ($uRole !== 'reseller') continue;
                            $uDisc = (float)($u['discount_percent'] ?? 0);
                        ?>
                            <div class="stat-card flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs disc-user-card"
                                 data-username="<?= strtolower(htmlspecialchars($u['username'] ?? '')) ?>"
                                 data-email="<?= strtolower(htmlspecialchars($u['email'] ?? '')) ?>">
                                <div>
                                    <div class="flex items-center space-x-2.5">
                                        <h4 class="font-bold text-white text-sm"><?= htmlspecialchars($u['username']) ?></h4>
                                        <?php if ($uDisc > 0): ?>
                                            <span class="text-[9px] px-2 py-0.5 rounded font-black font-mono uppercase bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                                +<?= $uDisc ?>% Stacked Disc
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[9px] px-2 py-0.5 rounded font-bold font-mono uppercase bg-slate-800 text-slate-400">
                                                Regular (0%)
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center space-x-3 mt-1 font-mono text-[11px] text-slate-400">
                                        <span><?= htmlspecialchars($u['email'] ?: 'No Gmail') ?></span>
                                        <span>Wallet: <strong class="text-emerald-400">$<?= number_format((float)$u['balance'], 2) ?></strong></span>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2 font-mono flex-shrink-0">
                                    <form method="POST" class="inline-flex items-center space-x-1.5">
                                        <input type="hidden" name="admin_action" value="apply_single_user_discount">
                                        <input type="hidden" name="active_tab" value="tab-discounts">
                                        <input type="hidden" name="disc_sub_tab" value="disc-view-resellers">
                                        <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                                        <input type="number" step="0.5" min="0" max="99" name="discount_percent" value="<?= $uDisc > 0 ? $uDisc : '' ?>" placeholder="0" class="w-14 bg-[#030612] border border-slate-800 p-1.5 rounded-lg text-amber-300 font-bold text-center text-xs outline-none">
                                        <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-amber-500/20 text-amber-300 border border-amber-500/30 font-bold hover:bg-amber-500/30 text-xs">Set %</button>
                                    </form>

                                    <?php if ($uDisc > 0): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="admin_action" value="reset_single_user_discount">
                                            <input type="hidden" name="active_tab" value="tab-discounts">
                                            <input type="hidden" name="disc_sub_tab" value="disc-view-resellers">
                                            <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-red-950/40 text-red-400 border border-red-500/20 font-bold hover:bg-red-900/40 text-xs" title="Turn off discount">Off</button>
                                        </form>
                                    <?php endif; ?>

                                    <button type="button" onclick="openUserAccessModal(<?= htmlspecialchars(json_encode($u)) ?>)" class="px-2.5 py-1.5 rounded-lg bg-slate-800 text-slate-300 hover:text-white text-xs font-bold transition">
                                        Plan Custom $
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>

            <!-- SUB-SECTION 4: COUPON MANAGER HUB -->
            <div id="disc-view-coupons" class="hidden space-y-4">
                <div class="box-panel p-5 space-y-4 border-fuchsia-500/30">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                        <h3 class="text-xs font-black text-fuchsia-300 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-ticket text-fuchsia-400"></i> COUPON CODE MANAGER (MAIN DOMAIN)
                        </h3>
                    </div>

                    <!-- Create Coupon Form -->
                    <form method="POST" class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-3 text-xs font-mono">
                        <input type="hidden" name="admin_action" value="save_coupon">
                        <input type="hidden" name="active_tab" value="tab-discounts">
                        <input type="hidden" name="disc_sub_tab" value="disc-view-coupons">

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">Coupon Code</label>
                                <input type="text" name="coupon_code" required placeholder="e.g. VAI50" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none uppercase font-bold">
                            </div>
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">Discount Type</label>
                                <select name="discount_type" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-cyan-300 font-bold outline-none">
                                    <option value="percent">Percentage (%)</option>
                                    <option value="fixed">Fixed Amount (BDT)</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">Discount Value</label>
                                <input type="number" step="0.01" name="discount_value" required placeholder="10" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-emerald-400 font-bold outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">Target Products</label>
                                <select name="target_type" onchange="document.getElementById('couponProdSelectBoxMain').classList.toggle('hidden', this.value !== 'specific')" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-amber-300 font-bold outline-none">
                                    <option value="all">All Products (Global)</option>
                                    <option value="specific">Specific Products Only</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">Expiry Date (Optional)</label>
                                <input type="date" name="expiry_date" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                            </div>
                        </div>

                        <div id="couponProdSelectBoxMain" class="hidden space-y-1">
                            <label class="text-slate-400 text-[10px] block font-bold">Select Eligible Products:</label>
                            <div class="max-h-32 overflow-y-auto bg-[#030612] p-2.5 rounded-xl border border-slate-800 grid grid-cols-2 gap-2">
                                <?php foreach ($productsData as $pd): ?>
                                    <label class="flex items-center space-x-2 text-slate-300 text-xs cursor-pointer">
                                        <input type="checkbox" name="product_ids[]" value="<?= $pd['id'] ?>" class="rounded text-cyan-500">
                                        <span class="truncate"><?= htmlspecialchars($pd['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white uppercase font-black text-xs shadow-md">
                            + CREATE COUPON CODE
                        </button>
                    </form>

                    <!-- Coupons Table -->
                    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900/40">
                        <table class="table-roomy min-w-[600px] text-left text-xs font-mono">
                            <thead>
                                <tr>
                                    <th>CODE</th>
                                    <th>DISCOUNT</th>
                                    <th>TARGET</th>
                                    <th>EXPIRY</th>
                                    <th class="text-right">ACTION</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60 text-[11px]">
                                <?php 
                                $couponsList = $pdo->query("SELECT * FROM coupons WHERE subdomain_id = 0 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
                                if (empty($couponsList)): 
                                ?>
                                    <tr><td colspan="5" class="py-6 text-center text-slate-500 font-sans">No coupon codes created yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($couponsList as $cp): ?>
                                        <tr>
                                            <td class="font-bold text-fuchsia-300 tracking-wider"><?= htmlspecialchars($cp['code']) ?></td>
                                            <td class="text-emerald-400 font-bold">
                                                <?= $cp['discount_type'] === 'percent' ? $cp['discount_value'] . '%' : '৳' . $cp['discount_value'] ?>
                                            </td>
                                            <td class="text-cyan-300 uppercase"><?= htmlspecialchars($cp['target_type']) ?></td>
                                            <td class="text-slate-400"><?= htmlspecialchars($cp['expiry_date'] ?? 'Lifetime') ?></td>
                                            <td class="text-right">
                                                <form method="POST" class="inline" onsubmit="return confirm('Delete this coupon?');">
                                                    <input type="hidden" name="admin_action" value="delete_coupon">
                                                    <input type="hidden" name="active_tab" value="tab-discounts">
                                                    <input type="hidden" name="disc_sub_tab" value="disc-view-coupons">
                                                    <input type="hidden" name="coupon_id" value="<?= $cp['id'] ?>">
                                                    <button type="submit" class="text-red-400 hover:text-red-300 p-1"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
</section>

<!-- 3. PRODUCTS CATALOG TAB -->
        <section id="tab-products" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-2 grid grid-cols-3 gap-2 text-xs font-bold font-mono">
                <button type="button" onclick="switchProductSubView('view-catalog')" id="pill-catalog" class="py-3 px-3 rounded-xl bg-cyan-400 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition">
                    <i class="fa-solid fa-boxes-stacked text-sm"></i><span>Catalog (<?= count($productsData) ?>)</span>
                </button>
                <button type="button" onclick="switchProductSubView('view-add')" id="pill-add" class="py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition">
                    <i class="fa-solid fa-plus text-sm"></i><span>+ Add Product</span>
                </button>
                <button type="button" onclick="switchProductSubView('view-stock')" id="pill-stock" class="py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition">
                    <i class="fa-solid fa-cubes-stacked text-amber-400 text-sm"></i><span>Stock Vault (<?= $totalStockKeys ?>)</span>
                </button>
            </div>

            <div id="catalogActionsBox" class="box-panel p-4 flex flex-col sm:flex-row items-center justify-between gap-4 border border-cyan-500/20">
                <div class="flex items-center space-x-2.5 w-full sm:w-auto flex-wrap gap-y-2">
                    <form method="POST" class="inline">
                        <input type="hidden" name="admin_action" value="sync_km_products">
                        <input type="hidden" name="active_tab" value="tab-products">
                        <button type="submit" class="px-4 py-2.5 rounded-xl btn-cyan uppercase text-xs flex items-center gap-2 shadow-sm transition active:scale-95">
                            <i class="fa-solid fa-rotate text-xs"></i><span>Sync All (KM)</span>
                        </button>
                    </form>
                    <button type="button" onclick="openSelectSyncModal()" class="px-4 py-2.5 rounded-xl bg-slate-800 text-cyan-300 hover:bg-slate-700 font-bold uppercase text-xs flex items-center gap-2 border border-slate-700 shadow-sm transition active:scale-95">
                        <i class="fa-solid fa-list-check text-xs"></i><span>Select Items</span>
                    </button>
                    <button type="button" onclick="confirmDeleteAllProducts()" class="px-4 py-2.5 rounded-xl bg-red-950/40 border border-red-500/30 text-red-400 font-bold uppercase text-xs flex items-center gap-2 hover:bg-red-900/40 shadow-sm transition active:scale-95">
                        <i class="fa-solid fa-trash text-xs"></i><span>Delete All</span>
                    </button>
                </div>

                <!-- 💱 CURRENCY RATES: 1 USDT = ৳ BDT = ₹ INR (স্টোরফ্রন্টের সব কারেন্সি কনভার্শন এখান থেকে) -->
                <form method="POST" class="flex flex-wrap items-center gap-2 bg-slate-900/90 px-3.5 py-2 rounded-xl border border-emerald-500/30 w-full sm:w-auto justify-between shadow-inner">
                    <input type="hidden" name="admin_action" value="update_usdt_rate_quick">
                    <input type="hidden" name="active_tab" value="tab-products">
                    <span class="text-xs text-slate-400 font-bold font-mono">1 USDT =</span>
                    <label class="flex items-center gap-1 bg-slate-950/70 border border-emerald-500/30 rounded-lg px-2 py-1" title="BDT rate">
                        <span class="text-xs text-emerald-400 font-black font-mono">৳</span>
                        <input type="number" step="0.01" min="1" name="usdt_rate" value="<?= htmlspecialchars((string)$effectiveBdtRate) ?>" required class="w-16 bg-transparent text-emerald-400 font-mono font-black text-sm outline-none text-center">
                    </label>
                    <label class="flex items-center gap-1 bg-slate-950/70 border border-sky-500/30 rounded-lg px-2 py-1" title="INR rate">
                        <span class="text-xs text-sky-400 font-black font-mono">₹</span>
                        <input type="number" step="0.01" min="1" name="inr_rate" value="<?= htmlspecialchars((string)$effectiveInrRate) ?>" required class="w-16 bg-transparent text-sky-400 font-mono font-black text-sm outline-none text-center">
                    </label>
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-black text-[11px] uppercase tracking-wider transition">
                        SET RATES
                    </button>
                </form>
            </div>

            <div id="view-catalog" class="space-y-3.5">
                <?php if (empty($productsData)): ?>
                    <div class="box-panel p-10 text-center text-slate-500 text-xs space-y-2.5">
                        <i class="fa-solid fa-box-open text-3xl text-slate-600 block"></i>
                        <p class="font-sans">No products registered in catalog. Use <strong>Sync All</strong> or <strong>Select Items</strong> to import.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($productsData as $prod): 
                        $planCount = count($prod['plans']);
                        $isProdActive = (int)($prod['is_active'] ?? 1) === 1;
                    ?>
                        <div class="box-panel p-4 space-y-3 hover:border-cyan-500/30 transition">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3.5 overflow-hidden">
                                    <span class="w-8 h-8 rounded-xl bg-slate-900 border border-cyan-500/30 flex items-center justify-center text-xs text-cyan-300 font-bold flex-shrink-0">
                                        #<?= $prod['sort_order'] ?>
                                    </span>
                                    <div class="overflow-hidden">
                                        <div class="flex items-center space-x-2.5">
                                            <h4 class="text-sm font-black text-white truncate"><?= htmlspecialchars($prod['name']) ?></h4>
                                            <button type="button" onclick='openProductEditor(<?= json_encode($prod, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="text-slate-400 hover:text-cyan-300 p-1">
                                                <i class="fa-solid fa-pen-to-square text-xs"></i>
                                            </button>
                                            <?php if (!$isProdActive): ?>
                                                <span class="px-2 py-0.5 rounded text-[8px] font-black uppercase bg-red-950 text-red-400 border border-red-500/30">MAINTENANCE</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-[11px] text-slate-400 font-sans block mt-0.5"><?= $planCount ?> Validity Plans Linked</span>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2 flex-shrink-0">
                                    <button type="button" onclick="openAddPlanModal(<?= $prod['id'] ?>, '<?= htmlspecialchars(addslashes($prod['name'])) ?>')" class="py-1.5 px-3 rounded-xl bg-cyan-950/70 text-cyan-300 border border-cyan-500/30 font-bold text-xs hover:bg-cyan-900/60 transition">
                                        + Plan
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Delete this product and its plans?');" class="inline">
                                        <input type="hidden" name="admin_action" value="delete_product_entire">
                                        <input type="hidden" name="active_tab" value="tab-products">
                                        <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                        <button type="submit" class="w-8 h-8 rounded-xl bg-red-950/40 text-red-400 border border-red-500/20 text-xs hover:bg-red-900/40 flex items-center justify-center transition">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                    <button type="button" onclick="togglePlanAccordion(<?= $prod['id'] ?>)" class="text-slate-400 hover:text-white p-1.5">
                                        <i id="accordion-arrow-<?= $prod['id'] ?>" class="fa-solid fa-chevron-down text-xs transition-transform duration-200"></i>
                                    </button>
                                </div>
                            </div>

                            <div id="plan-accordion-<?= $prod['id'] ?>" class="hidden pt-3 border-t border-slate-800 space-y-2">
                                <?php if ($planCount === 0): ?>
                                    <p class="text-xs text-slate-500 py-1.5 font-sans">No validity plans attached. Click "+ Plan" above.</p>
                                <?php else: ?>
                                    <?php foreach ($prod['plans'] as $pl): 
                                        $bdtP = round((float)$pl['user_price'] * $effectiveBdtRate, 0);
                                        $rCoins = (int)($pl['reward_coins'] ?? 0);
                                        $cPrice = (int)($pl['coin_price'] ?? 0);
                                        $plDisc = (float)($pl['discount_percent'] ?? 0);
                                    ?>
                                        <div class="flex items-center justify-between p-3 rounded-xl bg-slate-900/80 border border-slate-800/80 text-xs">
                                            <div>
                                                <strong class="text-white"><?= htmlspecialchars($pl['plan_title']) ?></strong>
                                                <div class="flex items-center space-x-2 mt-1">
                                                    <span class="text-[10px] text-cyan-400 font-mono">KM ID: #<?= $pl['api_plan_id'] ?: 'None' ?></span>
                                                    <?php if ($plDisc > 0): ?>
                                                        <span class="text-[9px] px-1.5 py-0.2 rounded bg-purple-500/20 text-purple-300 font-bold border border-purple-500/30"><?= $plDisc ?>% OFF</span>
                                                    <?php endif; ?>
                                                    <?php if ($rCoins > 0): ?>
                                                        <span class="text-[9px] px-1.5 py-0.2 rounded bg-amber-500/20 text-amber-300 font-bold border border-amber-500/30">🪙 +<?= $rCoins ?> Gift</span>
                                                    <?php endif; ?>
                                                    <?php if ($cPrice > 0): ?>
                                                        <span class="text-[9px] px-1.5 py-0.2 rounded bg-yellow-500/20 text-yellow-300 font-bold border border-yellow-500/30">🪙 <?= $cPrice ?> Buy</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <span class="text-emerald-400 font-bold text-xs">৳<?= number_format($bdtP, 0) ?> BDT</span>
                                                <span class="text-[10px] text-slate-400 block font-mono">($<?= number_format((float)$pl['user_price'], 2) ?> USD)</span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="view-add" class="hidden box-panel p-5 space-y-4 font-mono text-xs">
                <h3 class="text-xs font-black text-white uppercase tracking-wider">+ Create Product</h3>
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="admin_action" value="add_new_product">
                    <input type="hidden" name="active_tab" value="tab-products">

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-2">
                            <label class="text-slate-400 block mb-1 font-semibold">Product Title</label>
                            <input type="text" name="name" required placeholder="e.g. VIP Mod Pass" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none focus:border-cyan-400 font-sans">
                        </div>
                        <div>
                            <label class="text-slate-400 block mb-1 font-semibold">Category</label>
                            <input type="text" name="category" value="APKMOD" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-fuchsia-300 outline-none uppercase font-bold">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-cyan-300 block mb-1 font-semibold">Logo File</label>
                            <input type="file" name="product_logo" accept="image/*" class="w-full text-slate-400 file:mr-2 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:bg-cyan-500 file:text-black">
                        </div>
                        <div>
                            <label class="text-fuchsia-300 block mb-1 font-semibold">Banner File</label>
                            <input type="file" name="product_banner" accept="image/*" class="w-full text-slate-400 file:mr-2 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:bg-fuchsia-500 file:text-white">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <input type="url" name="download_link" placeholder="Download File URL" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none focus:border-cyan-400">
                        <input type="url" name="setup_link" placeholder="Setup Tutorial URL" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none focus:border-cyan-400">
                        <input type="url" name="app_download_url" placeholder="Direct App APK Download URL" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-emerald-300 outline-none focus:border-emerald-400" title="Direct APK download URL for official app button">
                    </div>

                    <div class="flex items-center space-x-6 pt-1">
                        <label class="flex items-center space-x-2 cursor-pointer text-cyan-300 text-xs font-semibold">
                            <input type="checkbox" name="is_active" value="1" checked class="rounded text-cyan-500 scale-110">
                            <span>Status: Online</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer text-amber-300 text-xs font-semibold">
                            <input type="checkbox" name="is_pinned" value="1" class="rounded text-amber-500 scale-110">
                            <span>Pin to Top</span>
                        </label>
                    </div>

                    <button type="submit" class="w-full py-3.5 btn-cyan rounded-xl uppercase tracking-wider text-xs">Create Product</button>
                </form>
            </div>

            <div id="view-stock" class="hidden box-panel p-5 space-y-4 font-mono text-xs">
                <h3 class="text-xs font-black text-amber-400 uppercase tracking-wider">📦 Add Inventory Keys</h3>
                <form method="POST" class="space-y-3.5">
                    <input type="hidden" name="admin_action" value="save_stock_keys">
                    <input type="hidden" name="active_tab" value="tab-products">
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <select name="stock_product_id" id="stockProdSelect" onchange="onStockProdChange()" required class="bg-slate-900 border border-slate-800 p-3 rounded-xl text-cyan-300 outline-none focus:border-cyan-400">
                            <option value="">-- Choose Product --</option>
                            <?php foreach ($productsData as $pd): ?>
                                <option value="<?= $pd['id'] ?>"><?= htmlspecialchars($pd['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="stock_plan_id" id="stockPlanSelect" required class="bg-slate-900 border border-slate-800 p-3 rounded-xl text-amber-300 outline-none focus:border-cyan-400">
                            <option value="">-- Select Product First --</option>
                        </select>
                    </div>

                    <textarea name="bulk_keys" rows="6" placeholder="Paste keys here (one key per line)..." required class="w-full bg-slate-900 border border-slate-800 p-3.5 rounded-xl text-emerald-300 outline-none font-mono text-xs leading-relaxed focus:border-amber-400"></textarea>
                    
                    <button type="submit" class="w-full py-3.5 bg-gradient-to-r from-amber-500 to-orange-500 text-slate-950 font-black rounded-xl uppercase tracking-wider text-xs">Save to Vault</button>
                </form>
            </div>
        </section>

        <!-- 4. CONNECT KEY MANAGER TERMINAL -->
        <section id="tab-connectkm" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-5 space-y-5 border-cyan-500/20">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <h3 class="text-xs font-black text-cyan-300 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-bolt-lightning text-amber-400"></i> KEY MANAGER INTEGRATION (MAIN DOMAIN)
                    </h3>
                    <span class="px-3 py-1 rounded-xl text-[10px] font-bold uppercase flex items-center gap-2 <?= ($settings['km_last_status'] ?? '') === 'connected' ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/25' : 'bg-red-500/15 text-red-400 border border-red-500/25' ?>">
                        <span class="w-2 h-2 rounded-full <?= ($settings['km_last_status'] ?? '') === 'connected' ? 'bg-emerald-400 pulse-ring' : 'bg-red-500' ?>"></span>
                        <span><?= strtoupper($settings['km_last_status'] ?? 'UNTESTED') ?></span>
                    </span>
                </div>

                <form method="POST" class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-3.5 text-xs">
                    <input type="hidden" name="admin_action" value="save_km_credentials">
                    <input type="hidden" name="active_tab" value="tab-connectkm">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">API Endpoint URL</label>
                            <input type="url" name="km_api_url" value="<?= htmlspecialchars($settings['km_api_url'] ?? '') ?>" placeholder="https://your-domain.com/api.php" required class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-white outline-none focus:border-cyan-400">
                        </div>
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">Secret API Token</label>
                            <div class="relative">
                                <input type="password" id="kmApiKeyInput" name="km_api_key" value="<?= htmlspecialchars($settings['km_api_key'] ?? '') ?>" placeholder="keymanager_live_..." required class="w-full bg-[#030612] border border-slate-800 p-3 pr-11 rounded-xl text-amber-300 outline-none focus:border-amber-400">
                                <button type="button" onclick="toggleKmKeyVisibility()" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white">
                                    <i id="kmKeyEyeIcon" class="fa-solid fa-eye text-xs"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3 btn-cyan rounded-xl uppercase text-xs">
                        SAVE &amp; CONNECT KEY MANAGER
                    </button>
                </form>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5 text-xs">
                    <div class="bg-slate-900/80 p-4 rounded-2xl border border-emerald-500/20 flex flex-col justify-between">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">WALLET BALANCE</span>
                        <h3 class="text-2xl font-black text-emerald-400 mt-1"><?= $providerBalance ?></h3>
                    </div>

                    <div class="bg-slate-900/80 p-4 rounded-2xl border border-cyan-500/20 flex flex-col justify-between">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">LINKED PLANS</span>
                        <h3 class="text-2xl font-black text-cyan-300 mt-1"><?= $kmImportedCount ?> Plans</h3>
                    </div>

                    <div class="bg-slate-900/80 p-4 rounded-2xl border border-amber-500/20 flex items-center gap-2.5">
                        <form method="POST" class="flex-1">
                            <input type="hidden" name="admin_action" value="test_km_connection">
                            <input type="hidden" name="active_tab" value="tab-connectkm">
                            <button type="submit" class="w-full py-2.5 rounded-xl bg-amber-500/20 text-amber-300 border border-amber-500/30 text-[11px] font-bold uppercase hover:bg-amber-500/30 transition">
                                TEST PING
                            </button>
                        </form>
                        <form method="POST" class="flex-1">
                            <input type="hidden" name="admin_action" value="sync_km_products">
                            <input type="hidden" name="active_tab" value="tab-connectkm">
                            <button type="submit" class="w-full py-2.5 rounded-xl btn-cyan text-[11px] uppercase transition">
                                SYNC ALL
                            </button>
                        </form>
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-800 space-y-3">
                    <h4 class="text-xs font-black text-white uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-table-list text-cyan-400"></i> IMPORTED PLANS LEDGER (<?= $kmImportedCount ?>)
                    </h4>

                    <div class="overflow-x-auto rounded-2xl border border-slate-800 bg-slate-900/40">
                        <table class="table-roomy min-w-[750px] text-left text-xs font-mono">
                            <thead>
                                <tr>
                                    <th>PLAN ID</th>
                                    <th>PRODUCT NAME</th>
                                    <th>VALIDITY</th>
                                    <th>WHOLESALE</th>
                                    <th>RETAIL</th>
                                    <th class="text-right">STATUS</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60 text-[11px]">
                                <?php if (empty($kmImportedPlans)): ?>
                                    <tr><td colspan="6" class="py-8 text-center text-slate-500 italic font-sans">No plans linked. Connect API and click Sync All.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($kmImportedPlans as $kp): 
                                        $costBdt = round((float)$kp['reseller_price'] * $effectiveBdtRate, 0);
                                        $userBdt = round((float)$kp['user_price'] * $effectiveBdtRate, 0);
                                    ?>
                                        <tr>
                                            <td class="text-amber-300 font-bold">#<?= $kp['api_plan_id'] ?></td>
                                            <td class="font-bold text-white font-sans"><?= htmlspecialchars($kp['product_name']) ?></td>
                                            <td class="text-cyan-300"><?= htmlspecialchars($kp['plan_title']) ?></td>
                                            <td class="text-emerald-400 font-bold">
                                                $<?= number_format((float)$kp['reseller_price'], 2) ?>
                                                <span class="text-[9px] text-slate-400 block font-normal">(৳<?= $costBdt ?>)</span>
                                            </td>
                                            <td class="text-sky-300 font-bold">
                                                $<?= number_format((float)$kp['user_price'], 2) ?>
                                                <span class="text-[9px] text-slate-400 block font-normal">(৳<?= $userBdt ?>)</span>
                                            </td>
                                            <td class="text-right">
                                                <span class="px-2.5 py-1 rounded-lg text-[9px] font-bold bg-emerald-500/15 text-emerald-300 border border-emerald-500/25">LINKED</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- 5. SPIN THE WHEEL TAB -->
        <section id="tab-spinwheel" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-5 space-y-5 border-fuchsia-500/20">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <h3 class="text-xs font-black text-fuchsia-300 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-dharmachakra text-emerald-400"></i> LUCKY SPIN REWARD ENGINE (MAIN DOMAIN)
                    </h3>
                    <span class="px-3 py-1 rounded-xl text-[10px] font-black uppercase <?= ($settings['spin_wheel_enabled'] ?? '1') === '1' ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/25' : 'bg-red-500/15 text-red-400 border border-red-500/25' ?>">
                        <?= ($settings['spin_wheel_enabled'] ?? '1') === '1' ? 'ONLINE' : 'HIDDEN' ?>
                    </span>
                </div>

                <form method="POST" class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-4 text-xs">
                    <input type="hidden" name="admin_action" value="update_spin_wheel_settings">
                    <input type="hidden" name="active_tab" value="tab-spinwheel">

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3.5">
                        <div class="bg-[#030612] p-3 rounded-xl border border-slate-800 flex items-center justify-between">
                            <span class="text-slate-300 font-bold text-xs">Visibility</span>
                            <input type="checkbox" name="spin_wheel_enabled" value="1" <?= ($settings['spin_wheel_enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded text-emerald-500 scale-125">
                        </div>

                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">Spin Cost ($)</label>
                            <input type="number" step="0.01" name="spin_cost" value="<?= htmlspecialchars($settings['spin_cost'] ?? '0.00') ?>" placeholder="0.00" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-cyan-300 font-bold outline-none">
                        </div>

                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">Daily Limit / User</label>
                            <input type="number" name="spin_daily_limit" value="<?= htmlspecialchars($settings['spin_daily_limit'] ?? '5') ?>" min="1" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-emerald-300 font-bold outline-none">
                        </div>

                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">Expiry (Hours)</label>
                            <input type="number" name="spin_offer_duration_hours" value="<?= htmlspecialchars($settings['spin_offer_duration_hours'] ?? '24') ?>" min="1" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-amber-300 font-bold outline-none">
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3 btn-magenta rounded-xl uppercase text-xs">
                        SAVE SETTINGS
                    </button>
                </form>

                <div class="space-y-3.5 pt-1">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-black text-white uppercase tracking-wider">PRIZE SEGMENTS</h4>
                        <button type="button" onclick="openAddSpinSegmentModal()" class="px-3.5 py-2 rounded-xl bg-emerald-500 text-slate-950 font-black text-[10px] uppercase shadow-sm">
                            + ADD SEGMENT
                        </button>
                    </div>

                    <div class="overflow-x-auto rounded-2xl border border-slate-800 bg-slate-900/40">
                        <table class="table-roomy min-w-[750px] text-left text-xs font-mono">
                            <thead>
                                <tr>
                                    <th>COLOR</th>
                                    <th>TITLE</th>
                                    <th>TARGET</th>
                                    <th>BONUS</th>
                                    <th>CHANCE</th>
                                    <th>STATUS</th>
                                    <th class="text-right">ACTION</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                                <?php foreach ($spinSegments as $seg): ?>
                                    <tr>
                                        <td>
                                            <div class="w-5 h-5 rounded-full border border-white/30" style="background-color: <?= htmlspecialchars($seg['color']) ?>;"></div>
                                        </td>
                                        <td class="font-bold text-white font-sans"><?= htmlspecialchars($seg['title']) ?></td>
                                        <td class="text-cyan-300">৳<?= number_format((float)$seg['spend_target'], 0) ?></td>
                                        <td class="text-emerald-400">৳<?= number_format((float)$seg['bonus_amount'], 0) ?></td>
                                        <td class="text-amber-300 font-bold"><?= (int)$seg['probability'] ?>%</td>
                                        <td>
                                            <span class="text-[10px] font-bold uppercase <?= (int)$seg['is_active'] === 1 ? 'text-emerald-400' : 'text-slate-500' ?>">
                                                <?= (int)$seg['is_active'] === 1 ? 'ACTIVE' : 'OFF' ?>
                                            </span>
                                        </td>
                                        <td class="text-right space-x-2">
                                            <button type="button" onclick='openEditSpinSegmentModal(<?= json_encode($seg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="text-cyan-400 hover:text-cyan-300 p-1">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete segment?');">
                                                <input type="hidden" name="admin_action" value="delete_spin_segment">
                                                <input type="hidden" name="active_tab" value="tab-spinwheel">
                                                <input type="hidden" name="segment_id" value="<?= $seg['id'] ?>">
                                                <button type="submit" class="text-red-400 hover:text-red-300 p-1"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- 6. ADMIN ACCESS TAB -->
        <section id="tab-adminaccess" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-2 grid grid-cols-2 gap-2 text-xs font-bold">
                <button type="button" onclick="switchAdminAccessSubView('adminaccess-bot-sec')" id="pill-admin-bot" class="py-3 px-3 rounded-xl bg-amber-500 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition">
                    <i class="fa-solid fa-user-shield text-sm"></i>
                    <span>Authorized Admins</span>
                </button>
                <button type="button" onclick="switchAdminAccessSubView('adminaccess-firebase-sec')" id="pill-admin-firebase" class="py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition">
                    <i class="fa-solid fa-fire text-sm text-amber-400"></i>
                    <span>Firebase Setup</span>
                </button>
            </div>

            <div id="adminaccess-bot-sec" class="box-panel p-5 space-y-4 border-amber-500/20">
                <div class="pb-2 border-b border-slate-800">
                    <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-user-shield"></i> AUTHORIZED BOT &amp; WEBAPP ADMINS
                    </h3>
                </div>

                <form method="POST" class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                    <input type="hidden" name="admin_action" value="add_bot_admin">
                    <input type="hidden" name="active_tab" value="tab-adminaccess">

                    <div class="sm:col-span-2">
                        <label class="text-slate-300 block mb-1 font-bold">Telegram Chat ID (UID)</label>
                        <input type="text" name="telegram_id" required placeholder="e.g. 1234567890" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none focus:border-amber-400 font-bold">
                    </div>
                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Tag / Name</label>
                        <input type="text" name="admin_name" placeholder="Staff Name" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none focus:border-amber-400 font-sans">
                    </div>

                    <button type="submit" class="sm:col-span-3 py-3 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 text-slate-950 font-black uppercase text-xs mt-1 shadow-sm">
                        + GRANT CLEARANCE
                    </button>
                </form>

                <div class="space-y-2.5 pt-1">
                    <?php if (!empty($mainOwnerId)): ?>
                        <div class="bg-slate-900/90 p-3.5 rounded-xl border border-amber-500/40 flex items-center justify-between text-xs">
                            <div class="flex items-center space-x-3">
                                <i class="fa-solid fa-crown text-amber-400 text-base"></i>
                                <span class="text-white font-bold font-sans">ROOT OWNER</span>
                                <span class="text-cyan-300 text-xs font-mono">#<?= htmlspecialchars($mainOwnerId) ?></span>
                            </div>
                            <span class="px-2.5 py-1 rounded text-[9px] font-black uppercase bg-amber-500 text-slate-950">PROTECTED</span>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($botAdminsList as $adm): ?>
                        <div class="bg-slate-900/60 p-3 rounded-xl border border-slate-800 flex items-center justify-between text-xs">
                            <div class="flex items-center space-x-2.5">
                                <i class="fa-solid fa-shield text-cyan-400"></i>
                                <span class="text-white font-sans font-bold"><?= htmlspecialchars($adm['admin_name']) ?></span>
                                <span class="text-slate-400 text-xs">#<?= htmlspecialchars($adm['telegram_id']) ?></span>
                            </div>
                            <?php if ($adm['telegram_id'] === $mainOwnerId): ?>
                                <span class="text-slate-500 text-xs font-bold">OWNER</span>
                            <?php else: ?>
                                <form method="POST" onsubmit="return confirm('Remove admin?');">
                                    <input type="hidden" name="admin_action" value="remove_bot_admin">
                                    <input type="hidden" name="active_tab" value="tab-adminaccess">
                                    <input type="hidden" name="telegram_id" value="<?= htmlspecialchars($adm['telegram_id']) ?>">
                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-600/20 text-red-400 border border-red-500/30 text-xs font-bold hover:bg-red-600 hover:text-white transition">
                                        REMOVE
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="adminaccess-firebase-sec" class="hidden box-panel p-5 space-y-5 border-cyan-500/30">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-black text-amber-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-fire text-amber-500 text-base"></i> FIREBASE WEB SDK &amp; GOOGLE AUTH SETUP
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                            Configure Firebase credentials to enable Google 1-Tap / Popup Login on <strong>vaipanel.shop</strong> (Main Domain).
                        </span>
                    </div>
                    <span class="px-2.5 py-1 rounded-xl text-[9px] font-bold font-mono uppercase <?= !empty($settings['firebase_api_key']) ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-red-500/15 text-red-400 border border-red-500/30' ?>">
                        <?= !empty($settings['firebase_api_key']) ? 'CONFIGURED' : 'NOT SET' ?>
                    </span>
                </div>

                <form method="POST" class="bg-slate-900/80 p-5 rounded-2xl border border-slate-800 space-y-4 text-xs">
                    <input type="hidden" name="admin_action" value="save_firebase_settings">
                    <input type="hidden" name="active_tab" value="tab-adminaccess">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">apiKey (Web API Key)</label>
                            <input type="text" name="firebase_api_key" value="<?= htmlspecialchars($settings['firebase_api_key'] ?? '') ?>" placeholder="AIzaSy..." required class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-amber-300 font-mono outline-none focus:border-amber-400">
                        </div>
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">authDomain</label>
                            <input type="text" name="firebase_auth_domain" value="<?= htmlspecialchars($settings['firebase_auth_domain'] ?? '') ?>" placeholder="your-project.firebaseapp.com" required class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-white font-mono outline-none focus:border-cyan-400">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">projectId</label>
                            <input type="text" name="firebase_project_id" value="<?= htmlspecialchars($settings['firebase_project_id'] ?? '') ?>" placeholder="your-project-id" required class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-cyan-300 font-mono outline-none focus:border-cyan-400">
                        </div>
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">storageBucket (Optional)</label>
                            <input type="text" name="firebase_storage_bucket" value="<?= htmlspecialchars($settings['firebase_storage_bucket'] ?? '') ?>" placeholder="your-project.appspot.com" class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-white font-mono outline-none focus:border-cyan-400">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">messagingSenderId</label>
                            <input type="text" name="firebase_messaging_sender_id" value="<?= htmlspecialchars($settings['firebase_messaging_sender_id'] ?? '') ?>" placeholder="123456789012" class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-white font-mono outline-none focus:border-cyan-400">
                        </div>
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">appId</label>
                            <input type="text" name="firebase_app_id" value="<?= htmlspecialchars($settings['firebase_app_id'] ?? '') ?>" placeholder="1:123456789012:web:abcdef..." required class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-amber-300 font-mono outline-none focus:border-amber-400">
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3.5 btn-cyan rounded-xl uppercase tracking-wider text-xs font-black shadow-md mt-2">
                        SAVE FIREBASE CONFIGURATION
                    </button>
                </form>
            </div>
        </section>

        <!-- 7. KEYS & DISPATCH HISTORY TAB -->
        <section id="tab-keys" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-5 space-y-4">
                <div class="flex justify-between items-center pb-2 border-b border-slate-800">
                    <h3 class="text-xs font-bold text-cyan-400 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-receipt"></i> REAL-TIME LICENSE SALES FEED (MAIN DOMAIN)
                    </h3>
                    <span class="text-xs text-slate-400">Delivered: <?= count($sales) ?></span>
                </div>

                <div class="overflow-x-auto rounded-2xl border border-slate-800 bg-slate-900/40">
                    <table class="table-roomy min-w-[780px] text-left text-xs font-mono">
                        <thead>
                            <tr>
                                <th>TIME</th>
                                <th>BUYER</th>
                                <th>PLAN</th>
                                <th>LICENSE KEY</th>
                                <th>TYPE</th>
                                <th class="text-right">CHANNEL</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 text-[11px]">
                            <?php if (empty($sales)): ?>
                                <tr><td colspan="6" class="py-8 text-center text-slate-500 italic font-sans">No licenses issued yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($sales as $s): ?>
                                    <tr>
                                        <td class="text-slate-400 text-xs"><?= $s['created_at'] ?></td>
                                        <td class="font-bold text-white font-sans text-xs"><?= htmlspecialchars($s['username']) ?></td>
                                        <td class="text-slate-300"><?= htmlspecialchars($s['validity'] ?? '') ?></td>
                                        <td class="text-fuchsia-300 select-all font-bold tracking-wider"><?= $s['license_key'] ?></td>
                                        <td>
                                            <?php if (($s['payment_type'] ?? '') === 'coins'): ?>
                                                <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">🪙 COINS</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">BALANCE</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <?php if (!empty($s['sub_domain_name'])): ?>
                                                <span class="px-2.5 py-1 rounded-lg text-[9px] font-bold bg-purple-950 text-purple-300 border border-purple-500/30">
                                                    SUB: <?= htmlspecialchars($s['sub_domain_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-1 rounded-lg text-[9px] font-bold bg-emerald-950 text-emerald-300 border border-emerald-500/30">
                                                    MAIN
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- 8. REVENUE REPORT TAB -->
        <section id="tab-revenue" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-5 space-y-5 border-emerald-500/20">
                <div class="pb-2 border-b border-slate-800">
                    <h3 class="text-xs font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-file-invoice-dollar"></i> FINANCIAL TELEMETRY &amp; REVENUE AUDIT (MAIN DOMAIN)
                    </h3>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="stat-card flex flex-col justify-between h-32 border-emerald-500/30">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">TODAY REVENUE</span>
                        <h2 class="text-3xl font-black text-emerald-400 mt-1">$<?= number_format($todaySales, 2) ?></h2>
                        <span class="text-[10px] text-slate-500 font-sans">24h Sales Completed</span>
                    </div>

                    <div class="stat-card flex flex-col justify-between h-32 border-cyan-500/30">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">THIS MONTH REVENUE</span>
                        <h2 class="text-3xl font-black text-cyan-400 mt-1">$<?= number_format($monthSales, 2) ?></h2>
                        <span class="text-[10px] text-slate-500 font-sans">Monthly Rolling Cycle</span>
                    </div>

                    <div class="stat-card flex flex-col justify-between h-32 border-purple-500/30">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">ALL TIME REVENUE</span>
                        <h2 class="text-3xl font-black text-purple-400 mt-1">$<?= number_format($allTimeSales, 2) ?></h2>
                        <span class="text-[10px] text-slate-500 font-sans">Gross Platform Settlement</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- 9. DEPOSITS & REWARDS TAB -->
        <section id="tab-deposits" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-2 grid grid-cols-2 gap-2 text-xs font-bold">
                <button type="button" onclick="switchDepositSubView('dep-verify-sec')" id="pill-dep-verify" class="py-3 px-3 rounded-xl bg-teal-500 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition">
                    <i class="fa-solid fa-receipt text-sm"></i>
                    <span>VERIFY DEPOSITS &amp; REWARD TRANSACTIONS</span>
                </button>
                <button type="button" onclick="switchDepositSubView('dep-rewards-sec')" id="pill-dep-rewards" class="py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition">
                    <i class="fa-solid fa-gift text-sm text-amber-400"></i>
                    <span>DEPOSIT REWARDS (BONUS TIERS)</span>
                </button>
            </div>

            <div id="dep-verify-sec" class="box-panel p-5 space-y-4 border-teal-500/20">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-bold text-teal-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-wallet"></i> VERIFY DEPOSITS &amp; REWARD TRANSACTIONS (MAIN DOMAIN)
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">Review customer deposits, manual approvals, and automated wallet allocations.</span>
                    </div>
                    <span class="px-3 py-1 rounded-xl text-[11px] font-bold uppercase bg-amber-500/15 text-amber-300 border border-amber-500/30 font-mono">
                        QUEUE: <?= $pendingTrxCount ?> PENDING
                    </span>
                </div>

                <div class="overflow-x-auto rounded-2xl border border-slate-800 bg-slate-900/40">
                    <table class="table-roomy min-w-[850px] text-left text-xs font-mono">
                        <thead>
                            <tr>
                                <th>USER</th>
                                <th>METHOD</th>
                                <th>AMOUNT</th>
                                <th>BONUS (BDT)</th>
                                <th>SENDER</th>
                                <th>TRX ID</th>
                                <th class="text-right">ACTION</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/70 text-[11px]">
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="7" class="py-10 text-center text-slate-500 italic font-sans">
                                        No deposit transactions recorded yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $t): 
                                    $isReward = (stripos((string)$t['method'], 'SpinReward') !== false || stripos((string)$t['sender_num'], 'SPIN') !== false);
                                    $bonusVal = (float)($t['bonus_amount'] ?? 0);
                                ?>
                                    <tr class="hover:bg-slate-900/70 transition-colors">
                                        <td>
                                            <strong class="font-bold text-white font-sans text-xs block"><?= htmlspecialchars($t['username']) ?></strong>
                                            <?php if (!empty($t['email'])): ?>
                                                <span class="text-[10px] text-slate-400 font-mono block mt-0.5"><?= htmlspecialchars($t['email']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isReward): ?>
                                                <span class="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-fuchsia-500/15 text-fuchsia-300 border border-fuchsia-500/30">
                                                    <?= htmlspecialchars($t['method']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-cyan-500/15 text-cyan-300 border border-cyan-500/30">
                                                    <?= htmlspecialchars($t['method']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong class="text-emerald-400 font-black text-xs">$<?= number_format((float)$t['amount'], 2) ?> USD</strong>
                                        </td>
                                        <td>
                                            <?php if ($bonusVal > 0): ?>
                                                <span class="text-amber-300 font-bold font-mono">+৳<?= number_format($bonusVal, 0) ?></span>
                                            <?php else: ?>
                                                <span class="text-slate-500">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-slate-300">
                                            <?= htmlspecialchars((string)($t['sender_num'] ?? 'N/A')) ?>
                                        </td>
                                        <td>
                                            <span class="text-amber-300 font-mono font-bold select-all tracking-wider text-xs">
                                                <?= htmlspecialchars($t['trx_id']) ?>
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <?php if ($t['status'] === 'pending'): ?>
                                                <form method="POST" class="inline flex items-center justify-end space-x-1.5">
                                                    <input type="hidden" name="admin_action" value="manage_trx">
                                                    <input type="hidden" name="active_tab" value="tab-deposits">
                                                    <input type="hidden" name="trx_db_id" value="<?= $t['id'] ?>">
                                                    <button type="submit" name="status" value="approved" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg font-bold text-[10px] tracking-wider uppercase transition shadow">
                                                        Approve
                                                    </button>
                                                    <button type="submit" name="status" value="rejected" class="px-3 py-1.5 bg-red-600 hover:bg-red-500 text-white rounded-lg font-bold text-[10px] tracking-wider uppercase transition shadow">
                                                        Reject
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="px-3 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider inline-block <?= $t['status'] === 'approved' ? 'bg-emerald-950 text-emerald-400 border border-emerald-500/30' : 'bg-red-950 text-red-400 border border-red-500/30' ?>">
                                                    <?= strtoupper($t['status']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="dep-rewards-sec" class="hidden box-panel p-5 space-y-4 border-amber-500/30">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-gift text-base"></i> DEPOSIT REWARDS (BONUS TIERS &amp; USER LIMITS) — MAIN DOMAIN
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">Configure automatic cashbacks when users deposit balance (e.g. 500 BDT = 50 BDT Bonus) and set usage limits per user.</span>
                    </div>
                    <button type="button" onclick="openAddDepositBonusModal()" class="px-3.5 py-2 rounded-xl bg-gradient-to-r from-amber-500 to-yellow-500 text-slate-950 font-black text-xs uppercase shadow transition">
                        + ADD REWARD TIER
                    </button>
                </div>

                <div class="overflow-x-auto rounded-2xl border border-slate-800 bg-slate-900/40">
                    <table class="table-roomy min-w-[780px] text-left text-xs font-mono">
                        <thead>
                            <tr>
                                <th>TITLE / LABEL</th>
                                <th>MIN DEPOSIT (BDT)</th>
                                <th>BONUS CASH</th>
                                <th>TYPE</th>
                                <th>CLAIM LIMIT / USER</th>
                                <th>STATUS</th>
                                <th class="text-right">ACTION</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/70 text-[11px]">
                            <?php if (empty($depositBonusesList)): ?>
                                <tr>
                                    <td colspan="7" class="py-10 text-center text-slate-500 italic font-sans">
                                        No deposit reward tiers configured yet. Click "+ ADD REWARD TIER" above.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($depositBonusesList as $b): ?>
                                    <tr class="hover:bg-slate-900/70 transition-colors">
                                        <td>
                                            <strong class="font-bold text-white font-sans text-xs"><?= htmlspecialchars($b['title']) ?></strong>
                                        </td>
                                        <td class="text-cyan-300 font-bold">
                                            ৳<?= number_format((float)$b['min_deposit'], 0) ?>
                                        </td>
                                        <td class="text-emerald-400 font-black">
                                            +৳<?= number_format((float)$b['bonus_amount'], 0) ?>
                                        </td>
                                        <td>
                                            <span class="px-2 py-0.5 rounded text-[9px] uppercase font-bold bg-slate-800 text-slate-300 border border-slate-700">
                                                <?= strtoupper($b['bonus_type']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ((int)$b['claim_limit'] === 0): ?>
                                                <span class="text-emerald-400 font-bold">♾️ Unlimited</span>
                                            <?php else: ?>
                                                <span class="text-amber-400 font-bold"><?= (int)$b['claim_limit'] ?> Time(s) Only</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="text-[10px] font-bold uppercase <?= (int)$b['is_active'] === 1 ? 'text-emerald-400' : 'text-slate-500' ?>">
                                                <?= (int)$b['is_active'] === 1 ? 'ACTIVE' : 'OFF' ?>
                                            </span>
                                        </td>
                                        <td class="text-right space-x-2">
                                            <button type="button" onclick='openEditDepositBonusModal(<?= json_encode($b, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="text-cyan-400 hover:text-cyan-300 p-1">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete reward tier?');">
                                                <input type="hidden" name="admin_action" value="delete_deposit_bonus">
                                                <input type="hidden" name="active_tab" value="tab-deposits">
                                                <input type="hidden" name="bonus_id" value="<?= $b['id'] ?>">
                                                <button type="submit" class="text-red-400 hover:text-red-300 p-1"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- 10. CUSTOMERS DIRECTORY TAB -->
        <section id="tab-customers" class="tab-section hidden space-y-5">
            <div class="box-panel p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-3 font-mono">
                    <div>
                        <h3 class="text-xs font-bold text-sky-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-user-group"></i> CUSTOMERS DIRECTORY (<?= $totalCustomers ?>) — MAIN DOMAIN
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">Direct registered customers on the main platform. Manage balance, coins &amp; access.</span>
                    </div>
                </div>

                <div class="relative">
                    <input type="text" id="customerSearchInput" onkeyup="filterUserList('customer')" placeholder="Search by Username, Gmail or Telegram UID..." class="w-full bg-slate-900 border border-slate-800 text-xs text-white rounded-xl p-3 pl-10 outline-none focus:border-cyan-400 font-mono">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none"></i>
                </div>

                <div class="space-y-2.5" id="customerListContainer">
                    <?php foreach ($usersList as $u): 
                        $uRole = strtolower(trim((string)($u['role'] ?? 'customer')));
                        if ($uRole === 'reseller' || $uRole === 'admin') continue;
                    ?>
                        <div class="stat-card flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs user-item-card"
                             data-username="<?= strtolower(htmlspecialchars($u['username'] ?? '')) ?>"
                             data-email="<?= strtolower(htmlspecialchars($u['email'] ?? '')) ?>"
                             data-tg-id="<?= htmlspecialchars((string)($u['telegram_chat_id'] ?? ($u['telegram_id'] ?? ''))) ?>">
                            <div>
                                <div class="flex items-center space-x-2.5">
                                    <h4 class="font-bold text-white text-sm"><?= htmlspecialchars($u['username']) ?></h4>
                                    <span class="text-[9px] px-2 py-0.5 rounded font-bold font-mono uppercase <?= ($u['status'] ?? 'active') === 'active' ? 'bg-emerald-950 text-emerald-400 border border-emerald-500/20' : 'bg-red-950 text-red-400 border border-red-500/20' ?>">
                                        <?= $u['status'] ?? 'active' ?>
                                    </span>
                                    <?php if ((float)($u['discount_percent'] ?? 0) > 0): ?>
                                        <span class="text-[9px] px-2 py-0.5 rounded font-bold font-mono uppercase bg-purple-950 text-purple-300 border border-purple-500/30">
                                            +<?= (float)$u['discount_percent'] ?>% Extra Disc
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center space-x-3 mt-1 font-mono text-[11px] text-slate-400">
                                    <span><?= htmlspecialchars($u['email'] ?: 'No Gmail') ?></span>
                                    <span>Wallet: <strong class="text-emerald-400">$<?= number_format((float)$u['balance'], 2) ?></strong></span>
                                    <span>Coins: <strong class="text-amber-400">🪙 <?= number_format((int)($u['coins'] ?? 0)) ?></strong></span>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2 font-mono flex-shrink-0">
                                <button type="button" onclick="openBalanceModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>', '<?= htmlspecialchars(addslashes($u['email'] ?? '')) ?>', <?= (float)$u['balance'] ?>, <?= (int)($u['coins'] ?? 0) ?>, '<?= $u['status'] ?? 'active' ?>')" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-cyan-300 text-xs font-bold transition">
                                    +/- Float &amp; Coins
                                </button>
                                <button type="button" onclick="openUserAccessModal(<?= htmlspecialchars(json_encode($u)) ?>)" class="px-3 py-2 rounded-xl bg-sky-500/20 text-sky-300 border border-sky-500/30 hover:bg-sky-500/30 text-xs font-bold transition">
                                    Permissions &amp; Prices
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- 11. RESELLERS DIRECTORY TAB -->
        <section id="tab-resellers" class="tab-section hidden space-y-5">
            <div class="box-panel p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-3 font-mono">
                    <div>
                        <h3 class="text-xs font-bold text-purple-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-users-gear"></i> RESELLERS DIRECTORY (<?= $totalResellers ?>) — MAIN DOMAIN
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">Wholesale resellers with custom pricing and permissions. Manage balance &amp; coins.</span>
                    </div>
                </div>

                <div class="relative">
                    <input type="text" id="resellerSearchInput" onkeyup="filterUserList('reseller')" placeholder="Search Resellers by Username or UID..." class="w-full bg-slate-900 border border-slate-800 text-xs text-white rounded-xl p-3 pl-10 outline-none focus:border-cyan-400 font-mono">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none"></i>
                </div>

                <div class="space-y-2.5" id="resellerListContainer">
                    <?php foreach ($usersList as $u): 
                        $uRole = strtolower(trim((string)($u['role'] ?? '')));
                        if ($uRole !== 'reseller') continue; 
                    ?>
                        <div class="stat-card flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs user-item-card"
                             data-username="<?= strtolower(htmlspecialchars($u['username'] ?? '')) ?>"
                             data-email="<?= strtolower(htmlspecialchars($u['email'] ?? '')) ?>"
                             data-tg-id="<?= htmlspecialchars((string)($u['telegram_chat_id'] ?? ($u['telegram_id'] ?? ''))) ?>">
                            <div>
                                <div class="flex items-center space-x-2.5">
                                    <h4 class="font-bold text-white text-sm"><?= htmlspecialchars($u['username']) ?></h4>
                                    <span class="text-[9px] px-2 py-0.5 rounded font-bold font-mono uppercase <?= ($u['status'] ?? 'active') === 'active' ? 'bg-emerald-950 text-emerald-400 border border-emerald-500/20' : 'bg-red-950 text-red-400 border border-red-500/20' ?>">
                                        <?= $u['status'] ?? 'active' ?>
                                    </span>
                                    <?php if ((float)($u['discount_percent'] ?? 0) > 0): ?>
                                        <span class="text-[9px] px-2 py-0.5 rounded font-bold font-mono uppercase bg-purple-950 text-purple-300 border border-purple-500/30">
                                            +<?= (float)$u['discount_percent'] ?>% Extra Disc
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center space-x-3 mt-1 font-mono text-[11px] text-slate-400">
                                    <span><?= htmlspecialchars($u['email'] ?: 'No Gmail') ?></span>
                                    <span>Wallet: <strong class="text-emerald-400">$<?= number_format((float)$u['balance'], 2) ?></strong></span>
                                    <span>Coins: <strong class="text-amber-400">🪙 <?= number_format((int)($u['coins'] ?? 0)) ?></strong></span>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2 font-mono flex-shrink-0">
                                <button type="button" onclick="openBalanceModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>', '<?= htmlspecialchars(addslashes($u['email'] ?? '')) ?>', <?= (float)$u['balance'] ?>, <?= (int)($u['coins'] ?? 0) ?>, '<?= $u['status'] ?? 'active' ?>')" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-cyan-300 text-xs font-bold transition">
                                    +/- Float &amp; Coins
                                </button>
                                <button type="button" onclick="openUserAccessModal(<?= htmlspecialchars(json_encode($u)) ?>)" class="px-3 py-2 rounded-xl bg-purple-500/20 text-purple-300 border border-purple-500/30 hover:bg-purple-500/30 text-xs font-bold transition">
                                    Wholesale &amp; Access
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- 12. SITE SETTINGS TAB (WITH TELEGRAM BOT & REFERRAL) -->
        <section id="tab-settings" class="tab-section hidden space-y-6">
            
            <!-- A. BRANDING & GENERAL SETTINGS -->
            <div class="box-panel p-5 space-y-5">
                <div class="pb-3 border-b border-slate-800">
                    <h3 class="text-xs font-bold text-yellow-400 uppercase tracking-wider font-mono">
                        <i class="fa-solid fa-sliders text-yellow-400 mr-1.5"></i> SITE BRANDING, MAINTENANCE &amp; APP
                    </h3>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs font-mono">
                    <input type="hidden" name="admin_action" value="update_branding_settings">
                    <input type="hidden" name="active_tab" value="tab-settings">

                    <!-- Maintenance Mode with Dedicated Telegram Channel Link -->
                    <div class="p-4 rounded-2xl border border-amber-500/30 bg-amber-950/20 space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-amber-300 font-bold text-xs block">Site-Wide Maintenance Mode</span>
                                <span class="text-[10px] text-slate-400 font-sans">Blocks direct store access and redirects/reminds users to your Telegram channel.</span>
                            </div>
                            <input type="checkbox" name="site_maintenance_mode" value="1" <?= ($settings['site_maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?> class="rounded text-amber-500 scale-125 cursor-pointer">
                        </div>
                        <input type="text" name="site_maintenance_msg" value="<?= htmlspecialchars($settings['site_maintenance_msg'] ?? 'System upgrade in progress.') ?>" placeholder="Maintenance Notice Message" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none font-sans text-xs">
                        <div>
                            <label class="text-amber-400 block mb-1 font-bold">Maintenance Telegram Channel/Group Link:</label>
                            <input type="url" name="maintenance_telegram_link" value="<?= htmlspecialchars($settings['maintenance_telegram_link'] ?? '') ?>" placeholder="https://t.me/YourChannelLink" class="w-full bg-[#030612] border border-amber-500/40 p-2.5 rounded-xl text-amber-300 outline-none text-xs">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">Site / Store Name</label>
                            <input type="text" name="site_name" value="<?= htmlspecialchars($settings['site_name']) ?>" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none font-bold text-xs">
                        </div>
                        <div>
                            <label class="text-emerald-400 block mb-1 font-bold">Official App APK Download URL</label>
                            <input type="url" name="app_download_url" value="<?= htmlspecialchars($settings['app_download_url'] ?? '') ?>" placeholder="https://your-domain.com/downloads/app.apk" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-emerald-300 font-mono text-xs outline-none focus:border-emerald-400">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-2">
                            <label class="text-cyan-300 block font-bold">Header Site Logo</label>
                            <?php if (!empty($settings['site_logo'])): ?>
                                <div class="flex items-center space-x-2 pb-1">
                                    <img src="<?= htmlspecialchars($settings['site_logo']) ?>" class="w-8 h-8 rounded-lg object-cover border border-cyan-400">
                                    <label class="text-[10px] text-red-400 cursor-pointer flex items-center space-x-1">
                                        <input type="checkbox" name="delete_site_logo" value="1">
                                        <span>Remove Logo</span>
                                    </label>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="site_logo_file" accept="image/*" class="w-full text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:bg-cyan-500 file:text-black">
                        </div>

                        <div class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-2">
                            <label class="text-fuchsia-300 block font-bold">Center Loading Logo</label>
                            <?php if (!empty($settings['loading_logo'])): ?>
                                <div class="flex items-center space-x-2 pb-1">
                                    <img src="<?= htmlspecialchars($settings['loading_logo']) ?>" class="w-8 h-8 rounded-lg object-cover border border-fuchsia-400">
                                    <label class="text-[10px] text-red-400 cursor-pointer flex items-center space-x-1">
                                        <input type="checkbox" name="delete_loading_logo" value="1">
                                        <span>Remove Logo</span>
                                    </label>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="loading_logo_file" accept="image/*" class="w-full text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:bg-fuchsia-500 file:text-white">
                        </div>
                    </div>

                    <div class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-3">
                        <strong class="text-cyan-300 text-xs block font-bold uppercase">Official Social Media Channels</strong>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <input type="url" name="footer_telegram_link" value="<?= htmlspecialchars($settings['footer_telegram_link'] ?? '') ?>" placeholder="Telegram Channel Link" class="bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                            <input type="url" name="footer_whatsapp_link" value="<?= htmlspecialchars($settings['footer_whatsapp_link'] ?? '') ?>" placeholder="WhatsApp Link" class="bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                            <input type="url" name="footer_youtube_link" value="<?= htmlspecialchars($settings['footer_youtube_link'] ?? '') ?>" placeholder="YouTube Channel Link" class="bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Footer Copyright Notice</label>
                        <input type="text" name="footer_copyright_text" value="<?= htmlspecialchars($settings['footer_copyright_text'] ?? '') ?>" placeholder="&copy; 2026 VAI PANEL. All rights reserved." class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none">
                    </div>

                    <button type="submit" class="w-full py-3 btn-cyan rounded-xl uppercase font-bold text-xs shadow-md">
                        SAVE BRANDING SETTINGS
                    </button>
                </form>
            </div>

            <!-- B. TELEGRAM BOT NOTIFICATION INTEGRATION (INSIDE SITE SETTINGS) -->
            <div class="box-panel p-5 space-y-4 border-cyan-500/30 bg-gradient-to-r from-[#031525] to-[#070d20]">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-black text-cyan-300 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-brands fa-telegram text-cyan-400 text-base"></i> TELEGRAM BOT LIVE NOTIFICATION ENGINE
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                            Connect your Telegram Bot to receive live real-time alerts for Deposits, Purchases &amp; Failed Orders.
                        </span>
                    </div>
                    <span class="px-2.5 py-1 rounded-xl text-[9px] font-bold font-mono uppercase <?= !empty($botConfig['bot_token']) ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-red-500/20 text-red-400 border border-red-500/30' ?>">
                        <?= !empty($botConfig['bot_token']) ? 'CONNECTED' : 'NOT CONFIGURED' ?>
                    </span>
                </div>

                <form method="POST" class="space-y-4 text-xs font-mono">
                    <input type="hidden" name="admin_action" value="save_telegram_bot_config">
                    <input type="hidden" name="active_tab" value="tab-settings">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">Bot Token (From @BotFather)</label>
                            <input type="text" name="bot_token" value="<?= htmlspecialchars($botConfig['bot_token'] ?? '') ?>" placeholder="123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ" required class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-cyan-300 outline-none focus:border-cyan-400 font-bold">
                        </div>
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">Admin Telegram Chat ID / Channel ID</label>
                            <input type="text" name="chat_id" value="<?= htmlspecialchars($botConfig['chat_id'] ?? '') ?>" placeholder="e.g. 123456789 or -100123456789" required class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-amber-300 outline-none focus:border-amber-400 font-bold">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 bg-[#030612] p-3 rounded-xl border border-slate-800">
                        <label class="flex items-center space-x-2 text-emerald-300 font-bold cursor-pointer">
                            <input type="checkbox" name="notify_deposit" value="1" <?= ($botConfig['notify_deposit'] ?? 1) ? 'checked' : '' ?> class="rounded scale-110">
                            <span>Deposit Alerts</span>
                        </label>
                        <label class="flex items-center space-x-2 text-cyan-300 font-bold cursor-pointer">
                            <input type="checkbox" name="notify_purchase" value="1" <?= ($botConfig['notify_purchase'] ?? 1) ? 'checked' : '' ?> class="rounded scale-110">
                            <span>Key Buy Alerts</span>
                        </label>
                        <label class="flex items-center space-x-2 text-rose-300 font-bold cursor-pointer">
                            <input type="checkbox" name="notify_failed" value="1" <?= ($botConfig['notify_failed'] ?? 1) ? 'checked' : '' ?> class="rounded scale-110">
                            <span>Failed Orders</span>
                        </label>
                        <label class="flex items-center space-x-2 text-purple-300 font-bold cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" <?= ($botConfig['is_active'] ?? 1) ? 'checked' : '' ?> class="rounded scale-110">
                            <span>Bot Active</span>
                        </label>
                    </div>

                    <div class="flex flex-col sm:flex-row items-center gap-2.5">
                        <button type="submit" class="w-full sm:flex-1 py-3 btn-cyan rounded-xl uppercase text-xs font-black shadow-md">
                            SAVE TELEGRAM BOT SETTINGS
                        </button>
                </form>

                <form method="POST" class="w-full sm:w-auto">
                    <input type="hidden" name="admin_action" value="test_telegram_bot_ping">
                    <input type="hidden" name="active_tab" value="tab-settings">
                    <button type="submit" class="w-full sm:w-auto py-3 px-5 rounded-xl bg-amber-500/20 text-amber-300 border border-amber-500/30 hover:bg-amber-500/30 font-black uppercase text-xs transition whitespace-nowrap">
                        <i class="fa-solid fa-paper-plane mr-1"></i> TEST PING BOT
                    </button>
                </form>
                    </div>
            </div>

            <!-- C. REFERRAL & TOKEN REWARDS SYSTEM (INSIDE SITE SETTINGS) -->
            <div class="box-panel p-5 space-y-4 border-amber-500/30 bg-gradient-to-r from-[#211202] to-[#070d20]">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-black text-amber-300 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-users text-amber-400 text-base"></i> REFERRAL &amp; TOKEN REWARD SYSTEM
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                            Configure how many reward tokens or cash bonus users receive for sharing referral link on storefront.
                        </span>
                    </div>
                </div>

                <form method="POST" class="space-y-4 text-xs font-mono">
                    <input type="hidden" name="admin_action" value="save_referral_settings">
                    <input type="hidden" name="active_tab" value="tab-settings">

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                        <div>
                            <label class="text-amber-300 block mb-1 font-bold">Tokens Per Referral</label>
                            <input type="number" name="token_per_referral" value="<?= (int)($referralConfig['token_per_referral'] ?? 10) ?>" min="0" required class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-amber-400 font-bold outline-none">
                        </div>
                        <div>
                            <label class="text-emerald-400 block mb-1 font-bold">Referrer Bonus Cash (BDT)</label>
                            <input type="number" step="0.5" name="referrer_bonus_bdt" value="<?= (float)($referralConfig['referrer_bonus_bdt'] ?? 0) ?>" min="0" required class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-emerald-400 font-bold outline-none">
                        </div>
                        <div class="flex items-center bg-[#030612] p-3 rounded-xl border border-slate-800 justify-between sm:mt-6">
                            <span class="text-slate-300 font-bold">System Status:</span>
                            <label class="flex items-center space-x-1.5 cursor-pointer text-emerald-400 font-bold">
                                <input type="checkbox" name="is_active" value="1" <?= ($referralConfig['is_active'] ?? 1) ? 'checked' : '' ?> class="rounded scale-110">
                                <span>Active</span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3 bg-gradient-to-r from-amber-500 to-yellow-500 text-slate-950 font-black rounded-xl uppercase text-xs shadow-md">
                        SAVE REFERRAL RULES
                    </button>
                </form>
            </div>

            <!-- D. PRODUCT CATEGORIES SEQUENCE & ORDERING -->
            <div class="box-panel p-5 space-y-4 border-purple-500/30">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-black text-purple-300 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-layer-group text-purple-400"></i> PRODUCT CATEGORIES ORDER / SEQUENCE
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                            Set display sequence on client storefront (e.g. 1=NONROOT, 2=ROOT, 3=IPHONE, 4=PC, 5=OTHER).
                        </span>
                    </div>
                </div>

                <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs font-mono">
                    <input type="hidden" name="admin_action" value="save_product_category">
                    <input type="hidden" name="active_tab" value="tab-settings">

                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Category Name</label>
                        <input type="text" name="category_name" required placeholder="e.g. NONROOT" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-white outline-none uppercase font-bold">
                    </div>
                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Sort Order / Serial (1, 2, 3..)</label>
                        <input type="number" name="sort_order" value="1" min="0" required class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-cyan-300 outline-none font-bold">
                    </div>
                    <div class="sm:pt-6">
                        <button type="submit" class="w-full py-2.5 btn-purple rounded-xl uppercase font-black text-xs">
                            + ADD CATEGORY
                        </button>
                    </div>
                </form>

                <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900/40">
                    <table class="table-roomy min-w-[500px] text-left text-xs font-mono">
                        <thead>
                            <tr>
                                <th>SERIAL (ORDER)</th>
                                <th>CATEGORY NAME</th>
                                <th class="text-right">ACTION</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <?php if (empty($productCategoriesList)): ?>
                                <tr><td colspan="3" class="py-4 text-center text-slate-500 font-sans">No custom categories added yet. Default order (ALL -> NONROOT -> ROOT -> IPHONE -> PC -> OTHER) applies.</td></tr>
                            <?php else: ?>
                                <?php foreach ($productCategoriesList as $pc): ?>
                                    <tr>
                                        <td class="text-amber-400 font-black">#<?= $pc['sort_order'] ?></td>
                                        <td class="text-white font-bold"><?= htmlspecialchars($pc['name']) ?></td>
                                        <td class="text-right">
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete category?');">
                                                <input type="hidden" name="admin_action" value="delete_product_category">
                                                <input type="hidden" name="active_tab" value="tab-settings">
                                                <input type="hidden" name="category_id" value="<?= $pc['id'] ?>">
                                                <button type="submit" class="text-red-400 hover:text-red-300 p-1"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- E. CUSTOM ENTRY POP-UP MANAGER -->
            <div class="box-panel p-5 space-y-4 border-fuchsia-500/30">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-black text-fuchsia-300 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-window-maximize text-fuchsia-400"></i> CUSTOM ENTRY POP-UP MANAGER
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                            Create custom promotional popup modals with custom image, text, and Telegram/YouTube channel link.
                        </span>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-3 text-xs font-mono">
                    <input type="hidden" name="admin_action" value="save_custom_popup">
                    <input type="hidden" name="active_tab" value="tab-settings">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">Popup Title</label>
                            <input type="text" name="popup_title" placeholder="Special Offer!" required class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                        </div>
                        <div>
                            <label class="text-fuchsia-300 block mb-1 font-bold">Popup Image (Optional)</label>
                            <input type="file" name="popup_image" accept="image/*" class="w-full text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-[10px] file:bg-fuchsia-500 file:text-white">
                        </div>
                    </div>

                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Popup Notice Message</label>
                        <textarea name="popup_message" rows="2" placeholder="Write custom promotion text..." required class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none font-sans"></textarea>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="text-cyan-400 block mb-1 font-bold">Target Channel / Social Link</label>
                            <input type="url" name="link_url" placeholder="https://t.me/YourChannel" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-cyan-300 outline-none">
                        </div>
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">Button Text</label>
                            <input type="text" name="link_text" value="Join Telegram" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                        </div>
                        <div class="flex items-center space-x-2 pt-6">
                            <label class="flex items-center space-x-1.5 text-slate-300 cursor-pointer">
                                <input type="checkbox" name="show_once_per_session" value="1" checked class="rounded text-cyan-400 scale-110">
                                <span>Show Once / Session</span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3 btn-magenta rounded-xl uppercase font-black text-xs shadow-md">
                        + CREATE ENTRY POP-UP
                    </button>
                </form>

                <div class="space-y-2 pt-2">
                    <span class="text-[10px] text-slate-400 font-bold uppercase block">Active Pop-ups:</span>
                    <?php if (empty($customPopupsList)): ?>
                        <p class="text-slate-500 text-xs italic font-sans py-2">No custom popups created yet.</p>
                    <?php else: ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <?php foreach ($customPopupsList as $pop): ?>
                                <div class="bg-slate-900/90 p-3 rounded-xl border border-slate-800 flex items-start justify-between gap-3 text-xs">
                                    <div class="space-y-1 overflow-hidden">
                                        <strong class="text-fuchsia-300 block truncate"><?= htmlspecialchars($pop['title']) ?></strong>
                                        <p class="text-slate-300 text-[11px] font-sans line-clamp-2"><?= htmlspecialchars($pop['message']) ?></p>
                                        <?php if (!empty($pop['link_url'])): ?>
                                            <a href="<?= htmlspecialchars($pop['link_url']) ?>" target="_blank" class="text-cyan-400 text-[10px] hover:underline block truncate"><?= htmlspecialchars($pop['link_url']) ?></a>
                                        <?php endif; ?>
                                    </div>
                                    <form method="POST" onsubmit="return confirm('Delete popup?');">
                                        <input type="hidden" name="admin_action" value="delete_custom_popup">
                                        <input type="hidden" name="active_tab" value="tab-settings">
                                        <input type="hidden" name="popup_id" value="<?= $pop['id'] ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-300 p-1.5"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- F. PROMOTIONAL BANNERS CAROUSEL -->
            <div class="box-panel p-5 space-y-4 font-mono">
                <h4 class="text-xs font-bold text-fuchsia-400 uppercase tracking-wider">PROMOTIONAL BANNERS SLIDER</h4>
                <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 text-xs">
                    <input type="hidden" name="admin_action" value="add_banner">
                    <input type="hidden" name="active_tab" value="tab-settings">
                    <input type="file" name="banner_photo" accept="image/*" required class="text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:bg-fuchsia-500 file:text-white">
                    <input type="url" name="target_link" placeholder="Target Link (Optional)" class="bg-slate-900 border border-slate-800 p-2 rounded-xl text-white outline-none">
                    <button type="submit" class="py-2.5 btn-magenta rounded-xl uppercase">+ UPLOAD BANNER</button>
                </form>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2">
                    <?php foreach ($bannersList as $bn): ?>
                        <div class="relative bg-black rounded-xl border border-slate-800 overflow-hidden">
                            <img src="<?= htmlspecialchars($bn['image_url']) ?>" class="w-full h-20 object-cover">
                            <form method="POST" class="absolute top-2 right-2" onsubmit="return confirm('Delete banner?');">
                                <input type="hidden" name="admin_action" value="delete_banner">
                                <input type="hidden" name="active_tab" value="tab-settings">
                                <input type="hidden" name="banner_id" value="<?= $bn['id'] ?>">
                                <button type="submit" class="bg-red-600 text-white w-7 h-7 rounded-lg text-xs flex items-center justify-center shadow"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </section>

        <!-- 13. PUSH NOTIFICATION TAB -->
        <section id="tab-push" class="tab-section hidden space-y-5">
            <div class="box-panel p-5 space-y-4">
                <div class="pb-3 border-b border-slate-800">
                    <h3 class="text-xs font-bold text-rose-400 uppercase tracking-wider font-mono flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> PUSH NOTIFICATION BROADCAST (MAIN DOMAIN ONLY)
                    </h3>
                </div>

                <form method="POST" class="space-y-3.5 text-xs font-mono">
                    <input type="hidden" name="admin_action" value="send_push_notification">
                    <input type="hidden" name="active_tab" value="tab-push">

                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Target Audience</label>
                        <select name="target_type" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-cyan-300 outline-none">
                            <option value="all">Broadcast to All Users</option>
                            <option value="customers">Customers Only</option>
                            <option value="resellers">Resellers Only</option>
                        </select>
                    </div>

                    <div>
                        <input type="text" name="push_title" placeholder="Notification Title" required class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none font-bold">
                    </div>

                    <div>
                        <textarea name="push_message" rows="3" placeholder="Notification Message content..." required class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none font-sans"></textarea>
                    </div>

                    <button type="submit" class="w-full py-3 bg-rose-600 hover:bg-rose-500 text-white font-bold rounded-xl uppercase tracking-wider text-xs">
                        BROADCAST NOTIFICATION
                    </button>
                </form>

                <div class="space-y-3 pt-3 border-t border-slate-800 font-mono">
                    <h4 class="text-xs font-bold text-slate-400 uppercase">RECENT BROADCASTS:</h4>
                    <div class="space-y-2.5 max-h-64 overflow-y-auto">
                        <?php if (empty($sentNotifications)): ?>
                            <p class="text-center py-6 text-slate-500 text-xs italic font-sans">No broadcasts sent yet.</p>
                        <?php else: ?>
                            <?php foreach ($sentNotifications as $nt): ?>
                                <div class="stat-card flex items-start justify-between gap-3 text-xs">
                                    <div class="space-y-1">
                                        <div class="flex items-center space-x-2">
                                            <strong class="text-cyan-300 font-bold"><?= htmlspecialchars($nt['title']) ?></strong>
                                            <span class="text-[9px] px-2 py-0.5 rounded bg-rose-950 text-rose-300 border border-rose-500/25 uppercase font-bold">
                                                <?= htmlspecialchars($nt['target_type']) ?>
                                            </span>
                                        </div>
                                        <p class="text-slate-300 text-[11px] font-sans"><?= htmlspecialchars($nt['message']) ?></p>
                                        <span class="text-[9px] text-slate-500 block"><?= $nt['created_at'] ?></span>
                                    </div>
                                    <form method="POST" onsubmit="return confirm('Delete notification?');">
                                        <input type="hidden" name="admin_action" value="delete_push_notification">
                                        <input type="hidden" name="active_tab" value="tab-push">
                                        <input type="hidden" name="notif_id" value="<?= $nt['id'] ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-300 p-1.5">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- 14. DYNAMIC PAYMENT GATEWAYS & SNZPAY RULES TAB -->
        <section id="tab-gateways" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-2 grid grid-cols-2 gap-2 text-xs font-bold font-mono">
                <button type="button" onclick="switchGatewaySubView('view-pm-methods')" id="pill-gw-methods" class="py-3 px-3 rounded-xl bg-emerald-500 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition">
                    <i class="fa-solid fa-money-bill-wave text-sm"></i>
                    <span>Deposit Channels (<?= count($paymentMethodsList) ?>)</span>
                </button>
                <button type="button" onclick="switchGatewaySubView('view-pm-rules')" id="pill-gw-rules" class="py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition">
                    <i class="fa-solid fa-sliders text-sm text-cyan-400"></i>
                    <span>SNZPay &amp; Gateway Rules</span>
                </button>
            </div>

            <div id="view-pm-methods" class="space-y-4">
                <div class="box-panel p-5 space-y-4 border-emerald-500/25">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-3">
                        <div>
                            <h3 class="text-xs font-black text-emerald-400 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-building-columns"></i> DYNAMIC PAYMENT CHANNELS MANAGER (MAIN DOMAIN ONLY)
                            </h3>
                            <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                                Configure bKash, Nagad, Rocket, Binance, or create custom channels with QR codes, banners, numbers &amp; notes.
                            </span>
                        </div>
                        <button type="button" onclick="openAddPaymentMethodModal()" class="px-4 py-2.5 rounded-xl btn-emerald text-xs uppercase tracking-wider flex items-center gap-2 shadow-sm transition active:scale-95">
                            <i class="fa-solid fa-plus text-xs"></i> <span>Add Payment Channel</span>
                        </button>
                    </div>

                    <div class="overflow-x-auto rounded-2xl border border-slate-800 bg-slate-900/40">
                        <table class="table-roomy min-w-[850px] text-left text-xs font-mono">
                            <thead>
                                <tr>
                                    <th>METHOD</th>
                                    <th>ACCOUNT INFO</th>
                                    <th>GRAPHICS</th>
                                    <th>ADD FUND</th>
                                    <th>CHECKOUT</th>
                                    <th>STATUS</th>
                                    <th class="text-right">ACTION</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/70 text-[11px]">
                                <?php if (empty($paymentMethodsList)): ?>
                                    <tr>
                                        <td colspan="7" class="py-10 text-center text-slate-500 italic font-sans">
                                            No payment methods registered. Click "+ Add Payment Channel" to add one.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($paymentMethodsList as $pm): 
                                        $hasLogo   = !empty($pm['logo_url']);
                                        $hasBanner = !empty($pm['banner_url']);
                                        $hasQr     = !empty($pm['qr_image_url']);
                                        $isOnline  = (int)($pm['is_active'] ?? 1) === 1;
                                    ?>
                                        <tr class="hover:bg-slate-900/70 transition-colors">
                                            <td>
                                                <div class="flex items-center space-x-2.5">
                                                    <div class="w-8 h-8 rounded-xl bg-purple-950/80 border border-fuchsia-500/40 flex items-center justify-center text-white overflow-hidden flex-shrink-0 shadow-sm">
                                                        <?php if ($hasLogo): ?>
                                                            <img src="<?= htmlspecialchars($pm['logo_url']) ?>" class="w-full h-full object-cover" alt="Logo">
                                                        <?php else: ?>
                                                            <i class="fa-solid fa-money-bill-wave text-fuchsia-400 text-xs"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <strong class="font-bold text-white font-sans text-xs block"><?= htmlspecialchars($pm['name']) ?></strong>
                                                        <span class="text-[9px] text-slate-400 font-mono">Key: <?= htmlspecialchars($pm['method_key']) ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <strong class="text-cyan-300 font-bold block text-xs tracking-wider select-all"><?= htmlspecialchars($pm['account_number']) ?></strong>
                                                <span class="text-[9px] text-amber-400 uppercase font-mono"><?= htmlspecialchars($pm['account_type'] ?: 'Personal') ?></span>
                                            </td>
                                            <td>
                                                <div class="flex items-center space-x-1.5">
                                                    <?php if ($hasBanner): ?>
                                                        <span class="px-2 py-0.5 rounded text-[8px] font-bold bg-fuchsia-500/20 text-fuchsia-300 border border-fuchsia-500/30" title="Banner Uploaded">BANNER</span>
                                                    <?php endif; ?>
                                                    <?php if ($hasQr): ?>
                                                        <span class="px-2 py-0.5 rounded text-[8px] font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/30" title="QR Code Uploaded">QR CODE</span>
                                                    <?php endif; ?>
                                                    <?php if (!$hasBanner && !$hasQr): ?>
                                                        <span class="text-slate-500 text-[10px]">Standard</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?= (int)($pm['allow_add_fund'] ?? 1) === 1 ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400' ?>">
                                                    <?= (int)($pm['allow_add_fund'] ?? 1) === 1 ? 'YES' : 'NO' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?= (int)($pm['allow_checkout'] ?? 1) === 1 ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400' ?>">
                                                    <?= (int)($pm['allow_checkout'] ?? 1) === 1 ? 'YES' : 'NO' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="admin_action" value="toggle_payment_method_status">
                                                    <input type="hidden" name="active_tab" value="tab-gateways">
                                                    <input type="hidden" name="method_id" value="<?= $pm['id'] ?>">
                                                    <input type="hidden" name="current_status" value="<?= $isOnline ? 1 : 0 ?>">
                                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[9px] font-black uppercase tracking-wider transition <?= $isOnline ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 hover:bg-emerald-500/25' : 'bg-red-500/15 text-red-400 border border-red-500/30 hover:bg-red-500/25' ?>">
                                                        <?= $isOnline ? 'ONLINE' : 'OFFLINE' ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td class="text-right space-x-1.5">
                                                <button type="button" onclick='openEditPaymentMethodModal(<?= json_encode($pm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="px-2.5 py-1.5 rounded-lg bg-slate-800 text-cyan-300 hover:text-white border border-slate-700 font-bold transition">
                                                    <i class="fa-solid fa-pen mr-1"></i> Edit
                                                </button>
                                                <form method="POST" class="inline" onsubmit="return confirm('Delete this payment method?');">
                                                    <input type="hidden" name="admin_action" value="delete_payment_method">
                                                    <input type="hidden" name="active_tab" value="tab-gateways">
                                                    <input type="hidden" name="method_id" value="<?= $pm['id'] ?>">
                                                    <button type="submit" class="p-1.5 rounded-lg bg-red-950/40 text-red-400 border border-red-500/20 hover:bg-red-900/40 transition">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="view-pm-rules" class="hidden space-y-4">
                <div class="box-panel p-5 space-y-4 border-cyan-500/30 bg-gradient-to-r from-[#041a24] to-[#070d20]">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                        <div>
                            <h3 class="text-xs font-black text-cyan-300 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-bolt-lightning text-amber-400 text-sm"></i> SNZPAY AUTOMATED INSTANT VERIFICATION API (MAIN DOMAIN)
                            </h3>
                            <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                                Connect snzpay.digital license key for 100% automated instant verification of bKash, Nagad &amp; Rocket TrxIDs!
                            </span>
                        </div>
                        <span class="px-3 py-1 rounded-xl text-[10px] font-black uppercase tracking-wider <?= ($settings['auto_payment_on'] ?? '0') === '1' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-red-500/20 text-red-400 border border-red-500/30' ?>">
                            <?= ($settings['auto_payment_on'] ?? '0') === '1' ? 'AUTO-PAY ACTIVE' : 'MANUAL APPROVAL' ?>
                        </span>
                    </div>

                    <form method="POST" class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-4 text-xs">
                        <input type="hidden" name="admin_action" value="save_snzpay_rules">
                        <input type="hidden" name="active_tab" value="tab-gateways">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">SNZPay Verification Endpoint URL</label>
                                <input type="url" name="snzpay_api_url" value="<?= htmlspecialchars($settings['snzpay_api_url'] ?? 'https://snzpay.digital/api/verify_payment.php') ?>" required class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-white outline-none focus:border-cyan-400 font-mono text-xs">
                            </div>
                            <div>
                                <label class="text-amber-400 block mb-1 font-bold">SNZPay Active License Key</label>
                                <input type="text" name="snzpay_license_key" value="<?= htmlspecialchars($settings['snzpay_license_key'] ?? '') ?>" placeholder="Paste your active SNZPay license key" class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-amber-300 outline-none focus:border-amber-400 font-mono text-xs font-bold">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div class="bg-[#030612] p-3 rounded-xl border border-slate-800 flex items-center justify-between">
                                <div>
                                    <span class="text-white font-bold text-xs block">Enable Auto-Payment Gateway</span>
                                    <span class="text-[9px] text-slate-400 font-sans">Automatically deliver products &amp; approve add-funds when TrxID matches</span>
                                </div>
                                <input type="checkbox" name="auto_payment_on" value="1" <?= ($settings['auto_payment_on'] ?? '0') === '1' ? 'checked' : '' ?> class="rounded text-emerald-500 scale-125 cursor-pointer">
                            </div>
                            <div class="bg-[#030612] p-3 rounded-xl border border-slate-800 flex items-center justify-between">
                                <div>
                                    <span class="text-cyan-300 font-bold text-xs block">Enforce SNZPay Realtime Verification</span>
                                    <span class="text-[9px] text-slate-400 font-sans">Strictly reject invalid/duplicate TrxIDs directly through SNZPay API</span>
                                </div>
                                <input type="checkbox" name="snzpay_auto_verify" value="1" <?= ($settings['snzpay_auto_verify'] ?? '0') === '1' ? 'checked' : '' ?> class="rounded text-cyan-500 scale-125 cursor-pointer">
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row items-center gap-2.5 pt-1">
                            <button type="submit" class="w-full sm:flex-1 py-3 btn-cyan rounded-xl uppercase text-xs font-black tracking-wider shadow">
                                <i class="fa-solid fa-floppy-disk mr-1"></i> SAVE SNZPAY CONFIGURATION
                            </button>
                    </form>

                    <form method="POST" class="w-full sm:w-auto">
                        <input type="hidden" name="admin_action" value="test_snzpay_connection">
                        <input type="hidden" name="active_tab" value="tab-gateways">
                        <button type="submit" class="w-full sm:w-auto py-3 px-5 rounded-xl bg-amber-500/20 text-amber-300 border border-amber-500/30 hover:bg-amber-500/30 font-black uppercase text-xs transition whitespace-nowrap">
                            <i class="fa-solid fa-satellite-dish mr-1"></i> TEST PING API
                        </button>
                    </form>
                        </div>
                </div>

                <div class="box-panel p-5 space-y-4 border-slate-800">
                    <div class="pb-2 border-b border-slate-800">
                        <h3 class="text-xs font-bold text-orange-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-scale-balanced"></i> DEPOSIT LIMITS &amp; GLOBAL BANNER (MAIN DOMAIN)
                        </h3>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
                        <input type="hidden" name="admin_action" value="update_gateway_rules">
                        <input type="hidden" name="active_tab" value="tab-gateways">

                        <div class="stat-card flex items-center justify-between">
                            <div>
                                <span class="text-slate-200 font-bold text-xs block">Deposit System Master Switch</span>
                                <span class="text-[10px] text-slate-400 font-sans">Open or temporarily close the entire Add Fund system</span>
                            </div>
                            <select name="deposit_system_status" class="bg-[#030612] border border-slate-800 p-2 rounded-xl text-xs font-bold <?= ($settings['deposit_system_status'] ?? '1') === '1' ? 'text-emerald-400' : 'text-red-400' ?> outline-none">
                                <option value="1" <?= ($settings['deposit_system_status'] ?? '1') === '1' ? 'selected' : '' ?>>ONLINE (OPEN)</option>
                                <option value="0" <?= ($settings['deposit_system_status'] ?? '1') === '0' ? 'selected' : '' ?>>OFFLINE (PAUSED)</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                            <div>
                                <label class="text-emerald-400 block mb-1 font-bold">1 USDT = ৳ BDT (Store Rate)</label>
                                <input type="number" step="0.01" min="1" name="usdt_rate" value="<?= htmlspecialchars($settings['usdt_rate']) ?>" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none font-bold">
                            </div>
                            <div>
                                <label class="text-sky-400 block mb-1 font-bold">1 USDT = ₹ INR (Rupee Rate)</label>
                                <input type="number" step="0.01" min="1" name="inr_rate" value="<?= htmlspecialchars($settings['inr_rate'] ?? '88') ?>" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none font-bold">
                            </div>
                            <div>
                                <label class="text-yellow-400 block mb-1 font-bold">Binance Market Rate</label>
                                <input type="number" step="0.5" name="binance_market_rate" value="<?= htmlspecialchars($settings['binance_market_rate'] ?? '125') ?>" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none font-bold">
                            </div>
                            <div>
                                <label class="text-yellow-400 block mb-1 font-bold">Binance Pay ID / UID</label>
                                <input type="text" name="binance_id" value="<?= htmlspecialchars($settings['binance_id'] ?? '') ?>" placeholder="12345678" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="text-amber-400 block mb-1 font-bold">Min Deposit Limit (BDT / USD)</label>
                                <input type="number" step="0.01" name="min_deposit_limit" value="<?= htmlspecialchars($settings['min_deposit_limit']) ?>" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none font-bold">
                            </div>
                            <div>
                                <label class="text-amber-400 block mb-1 font-bold">Max Deposit Limit (BDT / USD)</label>
                                <input type="number" step="0.01" name="max_deposit_limit" value="<?= htmlspecialchars($settings['max_deposit_limit']) ?>" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none font-bold">
                            </div>
                        </div>

                        <div class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-3">
                            <label class="text-cyan-300 block font-bold">Default Global Payment Banner Photo</label>
                            <?php if (!empty($settings['payment_banner_url'])): ?>
                                <div class="w-full h-24 rounded-xl overflow-hidden border border-slate-800 relative mb-2">
                                    <img src="<?= htmlspecialchars($settings['payment_banner_url']) ?>" class="w-full h-full object-cover" alt="Banner">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="payment_banner_file" accept="image/*" class="w-full text-slate-400 file:mr-2 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:bg-cyan-500 file:text-black">
                            <span class="text-[9px] text-slate-500 block">Shown at the top of payment screens if a specific method does not have its own banner.</span>
                        </div>

                        <button type="submit" class="w-full py-3.5 btn-emerald rounded-xl uppercase tracking-wider text-xs font-black shadow-md">
                            SAVE LIMITS &amp; EXCHANGE RATES
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <!-- 15. SUB DOMAIN TENANTS TAB -->
        <section id="tab-subdomains" class="tab-section hidden space-y-5 font-mono">
            <div class="flex space-x-2 bg-slate-900/80 p-2 rounded-2xl border border-slate-800 text-xs font-bold">
                <button type="button" onclick="switchSubdomainSection('sub-add-sec')" id="subtab-btn-addsec" class="flex-1 py-2.5 rounded-xl btn-cyan uppercase">
                    + DEPLOY NEW SUB-DOMAIN
                </button>
                <button type="button" onclick="switchSubdomainSection('sub-manage-sec')" id="subtab-btn-managesec" class="flex-1 py-2.5 rounded-xl text-slate-400 hover:text-white uppercase">
                    MANAGE TENANTS (<?= count($subdomainsList) ?>)
                </button>
            </div>

            <div id="sub-add-sec" class="box-panel p-5 space-y-4">
                <h3 class="text-xs font-bold text-cyan-400 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-network-wired"></i> DEPLOY TENANT STOREFRONT (USER/PASS SECURED)
                </h3>

                <form method="POST" class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-3.5 text-xs">
                    <input type="hidden" name="admin_action" value="assign_subdomain">
                    <input type="hidden" name="active_tab" value="tab-subdomains">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">1. Subdomain Slug</label>
                            <input type="text" name="subdomain_slug" placeholder="e.g. fastvip" required class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none font-bold focus:border-cyan-400">
                            <span class="text-[9px] text-slate-500 mt-1 block">Live: https://[slug].<?= $currentHost ?></span>
                        </div>
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">2. Storefront Title</label>
                            <input type="text" name="site_title" placeholder="Store Title (e.g. Fast VIP Store)" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none focus:border-cyan-400">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-slate-900/90 p-3.5 rounded-xl border border-cyan-500/30">
                        <div>
                            <label class="text-cyan-300 block mb-1 font-bold">3. Sub-Admin Username</label>
                            <input type="text" name="subadmin_username" placeholder="e.g. admin_fastvip" required class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-cyan-300 font-mono text-xs outline-none focus:border-cyan-400">
                        </div>
                        <div>
                            <label class="text-cyan-300 block mb-1 font-bold">4. Sub-Admin Password</label>
                            <input type="password" name="subadmin_password" placeholder="Minimum 6 characters" required class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-amber-300 font-mono text-xs outline-none focus:border-amber-400">
                        </div>
                    </div>

                    <div>
                        <label class="text-emerald-400 block mb-1 font-bold">5. Official App APK Download URL (Optional)</label>
                        <input type="url" name="apk_url" placeholder="https://example.com/downloads/app.apk" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-emerald-300 font-mono text-xs outline-none focus:border-emerald-400">
                    </div>

                    <button type="submit" class="w-full py-3.5 btn-cyan rounded-xl uppercase tracking-wider text-xs font-black shadow-lg">
                        DEPLOY SUB-STORE &amp; GENERATE ACCESS
                    </button>
                </form>
            </div>

            <div id="sub-manage-sec" class="hidden box-panel p-5 space-y-4">
                <h3 class="text-xs font-bold text-white uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-list-check text-cyan-400"></i> DEPLOYED TENANTS (<?= count($subdomainsList) ?>)
                </h3>

                <div class="space-y-3 pt-1">
                    <?php if (empty($subdomainsList)): ?>
                        <p class="text-center py-8 text-slate-500 text-xs italic font-sans">No subdomains deployed yet.</p>
                    <?php else: ?>
                        <?php foreach ($subdomainsList as $sd): 
                            $loginLink = "https://" . htmlspecialchars($sd['subdomain']) . "." . $currentHost . "/sub_admin.php";
                        ?>
                            <div class="stat-card flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                                <div>
                                    <div class="flex items-center space-x-2">
                                        <a href="//<?= htmlspecialchars($sd['subdomain']) ?>.<?= $currentHost ?>" target="_blank" class="font-bold text-cyan-300 hover:underline text-sm">
                                            <?= htmlspecialchars($sd['subdomain']) ?>.<?= $currentHost ?>
                                        </a>
                                        <span class="text-[10px] text-slate-400 font-bold bg-slate-900 px-2 py-0.5 rounded border border-slate-800"><?= htmlspecialchars($sd['site_title']) ?></span>
                                    </div>
                                    <div class="flex items-center space-x-2 mt-1.5 font-mono text-[11px]">
                                        <span class="text-amber-400">User: <strong><?= htmlspecialchars($sd['admin_username'] ?? 'Not Set') ?></strong></span>
                                        <span class="text-slate-500">•</span>
                                        <a href="<?= $loginLink ?>" target="_blank" class="text-emerald-400 hover:underline flex items-center gap-1 font-bold">
                                            <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i> Panel Login Link
                                        </a>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2 flex-shrink-0 font-mono">
                                    <button type="button" onclick='openEditSubdomainModal(<?= json_encode($sd, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="px-3 py-1.5 rounded-lg bg-slate-800 text-cyan-300 text-[10px] font-bold hover:bg-slate-700 transition">
                                        <i class="fa-solid fa-key mr-1"></i> EDIT / PASS
                                    </button>

                                    <form method="POST">
                                        <input type="hidden" name="admin_action" value="toggle_subdomain_status">
                                        <input type="hidden" name="active_tab" value="tab-subdomains">
                                        <input type="hidden" name="subdomain_id" value="<?= $sd['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $sd['status'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase <?= $sd['status'] === 'active' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-red-500/20 text-red-400 border border-red-500/30' ?>">
                                            <?= $sd['status'] ?>
                                        </button>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('Delete this subdomain?');">
                                        <input type="hidden" name="admin_action" value="delete_subdomain">
                                        <input type="hidden" name="active_tab" value="tab-subdomains">
                                        <input type="hidden" name="subdomain_id" value="<?= $sd['id'] ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-300 p-2"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 16. MAIN SITE THEME COLOR TAB -->
        <section id="tab-themecolor" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-5 space-y-5 border-emerald-500/30">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-black text-emerald-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-palette text-sm"></i> MAIN SITE THEME COLOR CUSTOMIZER
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                            This setting applies strictly to <strong>vaipanel.shop</strong> (Main Domain) only. Subdomain tenants retain their independent branding colors from their own panel.
                        </span>
                    </div>
                    <span class="w-7 h-7 rounded-xl border border-white/20 shadow" style="background-color: <?= htmlspecialchars($mainThemeColor) ?>;"></span>
                </div>

                <form method="POST" class="bg-slate-900/80 p-5 rounded-2xl border border-slate-800 space-y-4 text-xs">
                    <input type="hidden" name="admin_action" value="update_site_theme_color">
                    <input type="hidden" name="active_tab" value="tab-themecolor">

                    <div>
                        <label class="text-slate-300 block mb-2 font-bold">Pick Primary Accent Color (HEX)</label>
                        <div class="flex items-center space-x-3">
                            <input type="color" id="themeColorPicker" value="<?= htmlspecialchars($mainThemeColor) ?>" onchange="document.getElementById('themeColorInput').value = this.value" class="w-14 h-12 bg-transparent border border-slate-800 rounded-xl cursor-pointer">
                            <input type="text" id="themeColorInput" name="main_site_theme_color" value="<?= htmlspecialchars($mainThemeColor) ?>" oninput="document.getElementById('themeColorPicker').value = this.value" placeholder="#00f5ff" required class="w-40 bg-[#030612] border border-slate-800 p-3 rounded-xl text-white font-mono font-bold text-sm outline-none focus:border-cyan-400">
                        </div>
                    </div>

                    <div class="space-y-1.5 pt-1">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block">Cyber Neon Presets:</span>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" onclick="setThemePreset('#00f5ff')" class="px-3 py-1.5 rounded-lg text-slate-950 font-black text-[11px]" style="background:#00f5ff;">Cyber Cyan</button>
                            <button type="button" onclick="setThemePreset('#10b981')" class="px-3 py-1.5 rounded-lg text-slate-950 font-black text-[11px]" style="background:#10b981;">Neon Emerald</button>
                            <button type="button" onclick="setThemePreset('#d946ef')" class="px-3 py-1.5 rounded-lg text-white font-black text-[11px]" style="background:#d946ef;">Electric Violet</button>
                            <button type="button" onclick="setThemePreset('#f59e0b')" class="px-3 py-1.5 rounded-lg text-slate-950 font-black text-[11px]" style="background:#f59e0b;">Amber Gold</button>
                            <button type="button" onclick="setThemePreset('#ef4444')" class="px-3 py-1.5 rounded-lg text-white font-black text-[11px]" style="background:#ef4444;">Crimson Red</button>
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3 btn-emerald rounded-xl uppercase tracking-wider text-xs font-black mt-2">
                        SAVE MAIN SITE COLOR
                    </button>
                </form>
            </div>
        </section>

    </main>

    <!-- =============================================================== -->
    <!-- ALL MODALS SECTION                                              -->
    <!-- =============================================================== -->

    <!-- MODAL: ADD / EDIT DYNAMIC PAYMENT METHOD -->
    <div id="paymentMethodModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4 font-mono overflow-y-auto">
        <div class="box-panel p-5 w-full max-w-lg space-y-4 border-emerald-500/40 my-6 text-xs">
            <div class="flex justify-between items-center pb-2 border-b border-slate-800">
                <h3 class="text-xs font-bold text-white uppercase flex items-center gap-1.5">
                    <i class="fa-solid fa-money-bill-wave text-emerald-400"></i>
                    <span id="paymentMethodModalTitle">CONFIGURE PAYMENT CHANNEL</span>
                </h3>
                <button type="button" onclick="closeModal('paymentMethodModal')" class="text-slate-400 hover:text-white p-1">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-3.5">
                <input type="hidden" name="admin_action" value="save_payment_method">
                <input type="hidden" name="active_tab" value="tab-gateways">
                <input type="hidden" id="editPmId" name="method_id" value="0">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Display Name <span class="text-emerald-400">*</span></label>
                        <input type="text" id="editPmName" name="name" required placeholder="e.g. bKash" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none focus:border-emerald-400 font-bold">
                    </div>
                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Method Key</label>
                        <input type="text" id="editPmKey" name="method_key" placeholder="bkash / nagad / rocket" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-purple-300 outline-none uppercase font-bold">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Account Number <span class="text-emerald-400">*</span></label>
                        <input type="text" id="editPmNumber" name="account_number" required placeholder="01700000000" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-cyan-300 outline-none focus:border-cyan-400 font-mono font-bold tracking-wider">
                    </div>
                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Account Type</label>
                        <select id="editPmType" name="account_type" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-amber-300 font-bold outline-none">
                            <option value="Personal">Personal</option>
                            <option value="Agent">Agent</option>
                            <option value="Merchant">Merchant</option>
                            <option value="Pay ID">Pay ID (Binance)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-slate-300 block mb-1 font-bold">Payment Instructions (Shown to User)</label>
                    <textarea id="editPmInstructions" name="instructions" rows="2" placeholder="Send Money to the Personal number above, then enter the deposit amount and Transaction ID (TrxID) below to verify instantly." class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none font-sans text-xs"></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 bg-slate-900/60 p-3 rounded-2xl border border-slate-800">
                    <div>
                        <label class="text-cyan-300 block mb-1 font-bold text-[10px]">Small Logo</label>
                        <input type="file" name="logo_file" accept="image/*" class="w-full text-slate-400 file:mr-1 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-[9px] file:bg-cyan-500 file:text-black">
                    </div>
                    <div>
                        <label class="text-fuchsia-300 block mb-1 font-bold text-[10px]">Payment Banner</label>
                        <input type="file" name="banner_file" accept="image/*" class="w-full text-slate-400 file:mr-1 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-[9px] file:bg-fuchsia-500 file:text-white">
                    </div>
                    <div>
                        <label class="text-amber-300 block mb-1 font-bold text-[10px]">QR Code Image</label>
                        <input type="file" name="qr_file" accept="image/*" class="w-full text-slate-400 file:mr-1 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-[9px] file:bg-amber-500 file:text-black">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-2 pt-1 border-t border-slate-800/80 items-center">
                    <label class="flex items-center space-x-1.5 cursor-pointer text-emerald-300 font-bold text-[11px]">
                        <input type="checkbox" id="editPmIsActive" name="is_active" value="1" checked class="rounded scale-110">
                        <span>Active</span>
                    </label>
                    <label class="flex items-center space-x-1.5 cursor-pointer text-cyan-300 font-bold text-[11px]">
                        <input type="checkbox" id="editPmAllowAddFund" name="allow_add_fund" value="1" checked class="rounded scale-110">
                        <span>Add Fund</span>
                    </label>
                    <label class="flex items-center space-x-1.5 cursor-pointer text-purple-300 font-bold text-[11px]">
                        <input type="checkbox" id="editPmAllowCheckout" name="allow_checkout" value="1" checked class="rounded scale-110">
                        <span>Checkout</span>
                    </label>
                    <div class="flex items-center space-x-1 justify-end">
                        <span class="text-slate-400 text-[10px]">Sort:</span>
                        <input type="number" id="editPmSort" name="sort_order" value="0" class="w-12 bg-[#030612] border border-slate-800 p-1.5 rounded-lg text-white font-mono text-center text-xs">
                    </div>
                </div>

                <button type="submit" class="w-full py-3 btn-emerald rounded-xl uppercase text-xs font-black shadow-lg">
                    SAVE PAYMENT METHOD
                </button>
            </form>
        </div>
    </div>

    <!-- MODAL 1: SELECT SYNC (KEY MANAGER) -->
    <div id="selectSyncModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4 font-mono">
        <div class="box-panel p-5 w-full max-w-md space-y-4 border-cyan-500/40 max-h-[90vh] overflow-y-auto text-xs">
            <div class="flex justify-between items-center pb-2 border-b border-slate-800">
                <h3 class="text-xs font-black text-white uppercase flex items-center gap-1.5">
                    <i class="fa-solid fa-list-check text-cyan-400"></i> SELECT PRODUCTS TO SYNC
                </h3>
                <button type="button" onclick="closeModal('selectSyncModal')" class="text-slate-400 hover:text-white p-1"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <form method="POST" class="space-y-3.5">
                <input type="hidden" name="admin_action" value="sync_selected_km_products">
                <input type="hidden" name="active_tab" value="tab-products">

                <div class="flex justify-between items-center text-[11px]">
                    <span class="text-slate-400">Select plans to import:</span>
                    <button type="button" onclick="toggleAllSyncCB(true)" class="text-cyan-400 underline font-bold">Select All</button>
                </div>

                <div id="selectSyncItemsContainer" class="space-y-2 max-h-64 overflow-y-auto bg-[#030612] p-3 rounded-2xl border border-slate-800">
                    <p class="text-center text-cyan-300 py-6"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Fetching from Key Manager...</p>
                </div>

                <div class="grid grid-cols-2 gap-2.5 pt-1">
                    <button type="button" onclick="loadProviderCatalogForSelect()" class="py-2.5 rounded-xl bg-slate-800 text-cyan-300 border border-slate-700 font-bold uppercase text-[10px]">
                        RE-FETCH
                    </button>
                    <button type="submit" class="py-2.5 rounded-xl btn-cyan uppercase text-[10px]">
                        IMPORT SELECTED
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: CONFIRM ALL DELETE -->
    <div id="allDeleteModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4 font-mono">
        <div class="box-panel p-6 w-full max-w-sm space-y-4 text-center border-red-500/40">
            <i class="fa-solid fa-triangle-exclamation text-3xl text-red-500"></i>
            <h3 class="text-sm font-black text-white uppercase">Wipe Catalog?</h3>
            <p class="text-xs text-slate-400">All products and plans will be permanently purged from the Main Domain.</p>
            <form method="POST" class="space-y-2.5">
                <input type="hidden" name="admin_action" value="delete_all_products_confirmed">
                <input type="hidden" name="active_tab" value="tab-products">
                <button type="submit" class="w-full py-3 rounded-xl btn-rose uppercase text-xs">DELETE ALL</button>
                <button type="button" onclick="closeModal('allDeleteModal')" class="w-full py-2.5 rounded-xl bg-slate-900 text-slate-400 text-xs">Cancel</button>
            </form>
        </div>
    </div>

    <!-- MODAL 3: PRODUCT EDITOR -->
    <div id="editProductModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4 font-mono overflow-y-auto">
        <div class="box-panel p-5 w-full max-w-xl space-y-4 border-cyan-500/40 my-6 text-xs">
            <div class="flex justify-between items-center pb-2 border-b border-slate-800">
                <h3 class="text-xs font-black text-white uppercase flex items-center gap-1.5">
                    <i class="fa-solid fa-pen text-cyan-400"></i> Edit Product &amp; Plans (With Coins)
                </h3>
                <button type="button" onclick="closeModal('editProductModal')" class="text-slate-400 hover:text-white p-1"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-3.5">
                <input type="hidden" name="admin_action" value="edit_product_full">
                <input type="hidden" name="active_tab" value="tab-products">
                <input type="hidden" id="editModalProdId" name="product_id">

                <div class="p-3 rounded-xl bg-[#030612] border border-slate-800 flex items-center justify-between">
                    <span class="text-white font-bold text-xs">Product Maintenance Mode</span>
                    <label class="flex items-center space-x-2 cursor-pointer font-bold text-xs">
                        <input type="checkbox" id="editModalIsActive" name="is_active" value="1" class="rounded scale-110">
                        <span id="editModalActiveText" class="text-emerald-400 font-mono">Online</span>
                    </label>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div class="sm:col-span-2">
                        <label class="text-slate-400 block mb-1">Product Title</label>
                        <input type="text" id="editModalName" name="name" required class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none font-bold">
                    </div>
                    <div>
                        <label class="text-slate-400 block mb-1">Category</label>
                        <input type="text" id="editModalCategory" name="category" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-fuchsia-300 outline-none uppercase font-bold">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="text-cyan-300 block mb-1">Replace Logo</label>
                        <input type="file" name="product_logo" accept="image/*" class="w-full text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-[11px] file:bg-cyan-500 file:text-black">
                    </div>
                    <div>
                        <label class="text-fuchsia-300 block mb-1">Replace Banner</label>
                        <input type="file" name="product_banner" accept="image/*" class="w-full text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-[11px] file:bg-fuchsia-500 file:text-white">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <input type="url" id="editModalDownload" name="download_link" placeholder="Download Link" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                    <input type="url" id="editModalSetup" name="setup_link" placeholder="Setup Tutorial Link" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                    <input type="url" id="editModalAppDownload" name="app_download_url" placeholder="Direct APK URL" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-emerald-300 outline-none font-mono">
                </div>

                <div>
                    <input type="text" id="editModalTags" name="tags" placeholder="Tags: #SAFE, #INSTANT" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-cyan-300 outline-none">
                </div>

                <div>
                    <textarea id="editModalDesc" name="description" rows="2" placeholder="Description..." class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none"></textarea>
                </div>

                <div>
                    <label class="text-amber-400 block mb-1 font-bold">Maintenance Notice Message (প্রোডাক্ট অফলাইন থাকলে যে নোটিশ দেখাবে)</label>
                    <input type="text" id="editModalMaintMsg" name="maintenance_msg" placeholder="e.g. V2.5 Update In Progress, Back at 6 PM" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-amber-300 outline-none text-xs">
                </div>

                <div class="space-y-2 pt-2 border-t border-slate-800">
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-400 font-bold uppercase">Plan Prices &amp; Coins (USD, Gift Coins, Coin Price):</span>
                        <span class="text-emerald-400">1 USD = <?= $effectiveBdtRate ?> BDT</span>
                    </div>
                    <div id="editModalPlansContainer" class="space-y-2 max-h-56 overflow-y-auto bg-[#030612] p-2.5 rounded-2xl border border-slate-800"></div>
                </div>

                <div class="flex items-center space-x-4 pt-2">
                    <label class="flex items-center space-x-2 cursor-pointer text-amber-300 text-xs">
                        <input type="checkbox" id="editModalIsPinned" name="is_pinned" value="1" class="rounded">
                        <span>Pin to Top</span>
                    </label>
                    <div class="flex-grow text-right">
                        <input type="number" id="editModalSortOrder" name="sort_order" class="w-16 bg-[#030612] border border-slate-800 p-2 rounded-xl text-cyan-300 text-center font-bold" title="Sort Order">
                    </div>
                </div>

                <button type="submit" class="w-full py-3 btn-cyan rounded-xl uppercase text-xs">SAVE CHANGES &amp; COINS</button>
            </form>
        </div>
    </div>

    <!-- MODAL 4: ADD SINGLE PLAN -->
    <div id="addPlanModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4 font-mono">
        <div class="box-panel p-5 w-full max-w-sm space-y-3.5 border-cyan-500/40 text-xs">
            <div class="flex justify-between items-center pb-2 border-b border-slate-800">
                <h3 class="text-xs font-black text-white uppercase">+ Attach Plan (With Coins)</h3>
                <button type="button" onclick="closeModal('addPlanModal')" class="text-slate-400 hover:text-white p-1"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="admin_action" value="add_single_plan">
                <input type="hidden" name="active_tab" value="tab-products">
                <input type="hidden" id="addPlanProdId" name="product_id">
                
                <div>
                    <label class="text-slate-400 block mb-1">Plan Title</label>
                    <input type="text" name="plan_title" required placeholder="e.g. 7 Days" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                </div>
                
                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="text-slate-400 block mb-1">Price ($ USD)</label>
                        <input type="number" step="0.01" name="user_price" required placeholder="1.50" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-emerald-400 font-bold outline-none">
                    </div>
                    <div>
                        <label class="text-slate-400 block mb-1">KM Plan ID</label>
                        <input type="number" name="api_plan_id" placeholder="0" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-amber-300 outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2.5 bg-slate-900/60 p-2.5 rounded-xl border border-slate-800">
                    <div>
                        <label class="text-amber-400 block mb-1 font-bold">🪙 Gift Coins</label>
                        <input type="number" name="reward_coins" value="0" placeholder="0" class="w-full bg-[#030612] border border-slate-800 p-2 rounded-lg text-amber-300 font-mono outline-none">
                    </div>
                    <div>
                        <label class="text-yellow-400 block mb-1 font-bold">🪙 Coin Price</label>
                        <input type="number" name="coin_price" value="0" placeholder="0" class="w-full bg-[#030612] border border-slate-800 p-2 rounded-lg text-yellow-300 font-mono outline-none">
                    </div>
                </div>

                <button type="submit" class="w-full py-3 btn-cyan rounded-xl uppercase text-xs">Attach Plan</button>
            </form>
        </div>
    </div>

    <!-- MODAL 5: EDIT SUBDOMAIN & UPDATE CREDENTIALS -->
    <div id="editSubdomainModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4 font-mono">
        <div class="box-panel p-5 w-full max-w-lg space-y-4 border-cyan-500/40 text-xs">
            <div class="flex justify-between items-center pb-2 border-b border-slate-800">
                <h3 class="text-xs font-bold text-white uppercase flex items-center gap-2">
                    <i class="fa-solid fa-network-wired text-cyan-400"></i> EDIT TENANT &amp; CREDENTIALS
                </h3>
                <button onclick="closeModal('editSubdomainModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <form method="POST" class="space-y-3.5">
                <input type="hidden" name="admin_action" value="edit_subdomain_details">
                <input type="hidden" name="active_tab" value="tab-subdomains">
                <input type="hidden" id="editSubdomainId" name="subdomain_id">

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Slug (Subdomain)</label>
                        <input type="text" id="editSubdomainSlug" name="subdomain_slug" required class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-white outline-none font-bold">
                    </div>
                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Store Title</label>
                        <input type="text" id="editSubdomainTitle" name="site_title" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2.5 bg-slate-900/90 p-3 rounded-xl border border-cyan-500/30">
                    <div>
                        <label class="text-cyan-300 block mb-1 font-bold">Sub-Admin Username</label>
                        <input type="text" id="editSubdomainAdminUsername" name="subadmin_username" required class="w-full bg-[#030612] border border-slate-800 p-2 rounded-xl text-cyan-300 font-mono text-xs outline-none">
                    </div>
                    <div>
                        <label class="text-amber-400 block mb-1 font-bold">New Password (Optional)</label>
                        <input type="password" name="subadmin_password" placeholder="Leave blank to keep" class="w-full bg-[#030612] border border-slate-800 p-2 rounded-xl text-amber-300 font-mono text-xs outline-none">
                    </div>
                </div>

                <div>
                    <label class="text-emerald-400 block mb-1 font-bold">App APK Download URL</label>
                    <input type="url" id="editSubdomainApkUrl" name="apk_url" placeholder="https://example.com/app.apk" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-emerald-300 font-mono text-xs outline-none">
                </div>

                <select id="editSubdomainStatus" name="status" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-cyan-300 font-bold outline-none">
                    <option value="active">Active</option>
                    <option value="suspended">Suspended</option>
                </select>

                <button type="submit" class="w-full py-3 btn-cyan rounded-xl uppercase text-xs font-black">UPDATE TENANT &amp; ACCESS</button>
            </form>
        </div>
    </div>

    <!-- MODAL 6: SPIN SEGMENT MODAL -->
    <div id="spinSegmentModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4 font-mono">
        <div class="box-panel p-5 w-full max-w-md space-y-4 border-fuchsia-500/40 text-xs">
            <div class="flex justify-between items-center pb-2 border-b border-slate-800">
                <h3 class="text-xs font-bold text-white uppercase flex items-center gap-2">
                    <i class="fa-solid fa-dharmachakra text-fuchsia-400"></i> <span id="spinSegmentModalTitle">SPIN SEGMENT</span>
                </h3>
                <button onclick="closeModal('spinSegmentModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <form method="POST" class="space-y-3.5">
                <input type="hidden" name="admin_action" value="save_spin_segment">
                <input type="hidden" name="active_tab" value="tab-spinwheel">
                <input type="hidden" id="editSegmentId" name="segment_id" value="0">

                <div>
                    <label class="text-slate-300 block mb-1 font-bold">Segment Title</label>
                    <input type="text" id="segTitleInput" name="title" placeholder="500 TK → 50 TK Bonus" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-cyan-300 block mb-1 font-bold">Spend Target (BDT)</label>
                        <input type="number" id="segTargetInput" name="spend_target" required placeholder="500" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-white font-bold outline-none">
                    </div>
                    <div>
                        <label class="text-emerald-400 block mb-1 font-bold">Bonus Amount (BDT)</label>
                        <input type="number" id="segBonusInput" name="bonus_amount" required placeholder="50" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-emerald-400 font-bold outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-amber-400 block mb-1 font-bold">Probability (%)</label>
                        <input type="number" id="segProbInput" name="probability" required placeholder="80" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-amber-300 font-bold outline-none">
                    </div>
                    <div>
                        <label class="text-fuchsia-300 block mb-1 font-bold">Color</label>
                        <input type="color" id="segColorInput" name="color" value="#d946ef" class="w-full h-11 bg-slate-900 border border-slate-800 p-1.5 rounded-xl cursor-pointer">
                    </div>
                </div>

                <button type="submit" class="w-full py-3 btn-magenta rounded-xl uppercase text-xs">SAVE SEGMENT</button>
            </form>
        </div>
    </div>

    <!-- MODAL 6B: DEPOSIT BONUS / REWARD TIER MODAL -->
    <div id="depositBonusModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4 font-mono">
        <div class="box-panel p-5 w-full max-w-md space-y-4 border-amber-500/40 text-xs">
            <div class="flex justify-between items-center pb-2 border-b border-slate-800">
                <h3 class="text-xs font-bold text-white uppercase flex items-center gap-2">
                    <i class="fa-solid fa-gift text-amber-400"></i> <span id="depositBonusModalTitle">DEPOSIT REWARD TIER</span>
                </h3>
                <button onclick="closeModal('depositBonusModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <form method="POST" class="space-y-3.5">
                <input type="hidden" name="admin_action" value="save_deposit_bonus">
                <input type="hidden" name="active_tab" value="tab-deposits">
                <input type="hidden" id="editBonusId" name="bonus_id" value="0">

                <div>
                    <label class="text-slate-300 block mb-1 font-bold">Tier Title / Description</label>
                    <input type="text" id="bonusTitleInput" name="title" placeholder="e.g. 500 BDT Deposit Bonus" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-cyan-300 block mb-1 font-bold">Min Deposit (BDT)</label>
                        <input type="number" id="bonusMinDepositInput" name="min_deposit" required placeholder="500" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-white font-bold outline-none">
                    </div>
                    <div>
                        <label class="text-emerald-400 block mb-1 font-bold">Bonus Cash (BDT)</label>
                        <input type="number" step="0.01" id="bonusAmountInput" name="bonus_amount" required placeholder="50" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-emerald-400 font-bold outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Bonus Type</label>
                        <select id="bonusTypeInput" name="bonus_type" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-amber-300 outline-none font-bold">
                            <option value="fixed">Fixed BDT Amount</option>
                            <option value="percentage">Percentage (%)</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-amber-400 block mb-1 font-bold">Claim Limit / User</label>
                        <input type="number" id="bonusClaimLimitInput" name="claim_limit" value="0" min="0" placeholder="0 = Unlimited" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-white font-bold outline-none" title="Set how many times a user can claim this bonus (0 = Unlimited)">
                        <span class="text-[9px] text-slate-500 block mt-0.5">0 = Unlimited claims</span>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-1">
                    <label class="flex items-center space-x-2 text-slate-300 cursor-pointer">
                        <input type="checkbox" id="bonusIsActiveInput" name="is_active" value="1" checked class="rounded text-emerald-500 scale-125">
                        <span class="font-bold">Active / Enabled</span>
                    </label>
                    <div class="flex items-center space-x-1">
                        <span class="text-[10px] text-slate-400 font-bold">Sort:</span>
                        <input type="number" id="bonusSortOrderInput" name="sort_order" value="0" class="w-16 bg-slate-900 border border-slate-800 p-1.5 rounded-lg text-white font-mono text-center">
                    </div>
                </div>

                <button type="submit" class="w-full py-3 bg-gradient-to-r from-amber-500 to-yellow-500 text-slate-950 font-black rounded-xl uppercase text-xs">SAVE REWARD TIER</button>
            </form>
        </div>
    </div>

    <!-- MODAL 7: USER ACCESS & INDIVIDUAL PRODUCT-WISE PRICING / DISCOUNT -->
    <div id="userAccessModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4 font-mono">
        <div class="box-panel p-5 w-full max-w-2xl space-y-4 border-cyan-500/40 max-h-[92vh] overflow-y-auto text-xs">
            <div class="flex justify-between items-center pb-2 border-b border-slate-800">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-user-gear text-cyan-400 text-sm"></i>
                    <div>
                        <h3 class="text-xs font-bold text-white uppercase"><span id="modalAccessUserName">User Settings</span></h3>
                        <span id="modalAccessUserSubtext" class="text-[10px] text-slate-400 font-sans block">Manage permissions &amp; custom plan prices</span>
                    </div>
                </div>
                <button onclick="closeModal('userAccessModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="admin_action" value="save_user_custom_pricing_and_access">
                <input type="hidden" id="modalAccessUserId" name="target_user_id">
                <input type="hidden" id="modalAccessActiveTab" name="active_tab" value="tab-customers">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Account Role</label>
                        <select id="modalAccessUserRole" name="user_role" onchange="refreshUserCustomPricesList()" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-cyan-300 font-bold outline-none">
                            <option value="customer">Customer</option>
                            <option value="reseller">Reseller</option>
                        </select>
                    </div>

                    <div class="bg-[#030712] p-2.5 rounded-xl border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-white block text-xs font-bold">Add Fund Button</span>
                            <span class="text-[9px] text-slate-500">Allow deposit screen</span>
                        </div>
                        <input type="checkbox" id="modalAccessCanAddFund" name="can_add_fund" value="1" class="rounded text-emerald-400 scale-125">
                    </div>
                </div>

                <div>
                    <label class="text-slate-300 block mb-1 font-bold">Product Permissions</label>
                    <div class="grid grid-cols-2 gap-2 bg-slate-900 p-3 rounded-2xl border border-slate-800 max-h-36 overflow-y-auto">
                        <?php foreach ($productsData as $pd): ?>
                            <label class="flex items-center space-x-2 text-[11px] text-slate-300 cursor-pointer">
                                <input type="checkbox" name="allowed_products[]" value="<?= $pd['id'] ?>" class="user-prod-checkbox rounded text-cyan-500 scale-110">
                                <span class="truncate"><?= htmlspecialchars($pd['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="text-emerald-400 block mb-1 font-bold flex items-center justify-between">
                        <span>Product-Wise Plans Pricing &amp; Discounts</span>
                        <span class="text-[10px] text-slate-400 font-normal">Discount % or Custom $</span>
                    </label>
                    <div id="userCustomPlansListContainer" class="space-y-3 max-h-60 overflow-y-auto bg-slate-900 p-3 rounded-2xl border border-slate-800"></div>
                </div>

                <button type="submit" class="w-full py-3.5 btn-cyan rounded-xl uppercase text-xs font-black shadow-lg">SAVE USER ACCESS &amp; PRICING</button>
            </form>
        </div>
    </div>

    <!-- MODAL 8: MANAGE BALANCE, COINS & BAN -->
    <div id="balanceModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4 font-mono">
        <div class="box-panel p-5 w-full max-w-sm space-y-4 border-cyan-500/40 text-xs">
            <div class="flex justify-between items-center pb-2 border-b border-slate-800">
                <h3 class="text-xs font-bold text-white uppercase flex items-center gap-1.5">
                    <i class="fa-solid fa-user-gear text-cyan-400"></i> Manage Float &amp; Coins
                </h3>
                <button type="button" onclick="closeModal('balanceModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div class="bg-[#030612] p-3.5 rounded-xl border border-slate-800 space-y-1.5">
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Username:</span>
                    <strong id="balanceModalUsername" class="text-white text-xs"></strong>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Float:</span>
                    <strong id="balanceModalCurrent" class="text-emerald-400 font-black text-sm"></strong>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Coins:</span>
                    <strong id="balanceModalCoinsCurrent" class="text-amber-400 font-black text-sm"></strong>
                </div>
            </div>

            <form method="POST" class="space-y-3">
                <input type="hidden" name="admin_action" value="modify_balance">
                <input type="hidden" id="balanceModalActiveTab" name="active_tab" value="tab-customers">
                <input type="hidden" id="balanceModalUserId" name="user_id">

                <div>
                    <label class="text-slate-300 block mb-1 font-bold">Wallet Amount ($ USD)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" placeholder="5.00" required class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-emerald-400 font-bold outline-none text-xs">
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <button type="submit" name="type" value="add" class="py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold transition">+ Add Float</button>
                    <button type="submit" name="type" value="deduct" class="py-2.5 rounded-xl bg-red-600 hover:bg-red-500 text-white font-bold transition">- Deduct Float</button>
                </div>
            </form>

            <form method="POST" class="pt-3 border-t border-slate-800 space-y-3">
                <input type="hidden" name="admin_action" value="modify_coins">
                <input type="hidden" id="coinsModalActiveTab" name="active_tab" value="tab-customers">
                <input type="hidden" id="coinsModalUserId" name="user_id">

                <div>
                    <label class="text-amber-400 block mb-1 font-bold">🪙 Coins Amount</label>
                    <input type="number" step="1" min="1" name="coins_amount" placeholder="100" required class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-amber-300 font-bold outline-none text-xs">
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <button type="submit" name="type" value="add" class="py-2.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-slate-950 font-black transition">+ Add Coins</button>
                    <button type="submit" name="type" value="deduct" class="py-2.5 rounded-xl bg-orange-600 hover:bg-orange-500 text-white font-bold transition">- Deduct Coins</button>
                </div>
            </form>

            <form method="POST" class="pt-3 border-t border-slate-800">
                <input type="hidden" name="admin_action" value="toggle_ban">
                <input type="hidden" id="banModalActiveTab" name="active_tab" value="tab-customers">
                <input type="hidden" id="banModalUserId" name="user_id">
                <input type="hidden" id="banModalStatus" name="status">
                <button type="submit" id="banModalBtn" class="w-full py-2.5 rounded-xl text-xs font-bold uppercase transition">Toggle Status</button>
            </form>
        </div>
    </div>

    <!-- =============================================================== -->
    <!-- COMPLETE JAVASCRIPT ENGINE                                      -->
    <!-- =============================================================== -->
    <script>
        const globalProducts = <?= json_encode($productsData) ?>;
        const allUsersList = <?= json_encode($usersList) ?>;
        const currentBdtRate = <?= $effectiveBdtRate ?>;
        const userCustomPricesMap = <?= json_encode($userCustomPricesMap) ?>;
        const userAllowedProdsMap = <?= json_encode($userAllowedProdsMap) ?>;

        let currentActiveUser = null;

        function toggleAdminDrawer() {
            document.getElementById('adminDrawer').classList.toggle('translate-x-full');
            document.getElementById('drawerOverlay').classList.toggle('hidden');
        }

        function switchAdminTab(sectionId, titleName = '', btnEl = null) {
            document.querySelectorAll('.tab-section').forEach(sec => sec.classList.add('hidden'));
            const target = document.getElementById(sectionId);
            if (target) target.classList.remove('hidden');

            if (titleName) document.getElementById('headerSectionTitle').innerText = titleName;

            document.querySelectorAll('.drawer-nav-btn').forEach(btn => btn.classList.remove('active'));
            const activeBtn = btnEl || document.getElementById('btn-nav-' + sectionId);
            if (activeBtn) activeBtn.classList.add('active');

            sessionStorage.setItem('admin_active_tab', sectionId);
            sessionStorage.setItem('admin_active_title', titleName);

            if (!document.getElementById('adminDrawer').classList.contains('translate-x-full')) {
                toggleAdminDrawer();
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function switchGatewaySubView(viewId) {
            document.getElementById('view-pm-methods').classList.add('hidden');
            document.getElementById('view-pm-rules').classList.add('hidden');

            document.getElementById('pill-gw-methods').className = "py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition";
            document.getElementById('pill-gw-rules').className = "py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition";

            document.getElementById(viewId).classList.remove('hidden');

            if (viewId === 'view-pm-methods') {
                document.getElementById('pill-gw-methods').className = "py-3 px-3 rounded-xl bg-emerald-500 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition";
            } else {
                document.getElementById('pill-gw-rules').className = "py-3 px-3 rounded-xl bg-cyan-400 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition";
            }
        }

        function openAddPaymentMethodModal() {
            document.getElementById('paymentMethodModalTitle').innerText = 'ADD PAYMENT CHANNEL';
            document.getElementById('editPmId').value = '0';
            document.getElementById('editPmName').value = '';
            document.getElementById('editPmKey').value = '';
            document.getElementById('editPmNumber').value = '';
            document.getElementById('editPmType').value = 'Personal';
            document.getElementById('editPmInstructions').value = 'Send Money to the Personal number above, then enter the deposit amount and Transaction ID (TrxID) below to verify instantly.';
            document.getElementById('editPmIsActive').checked = true;
            document.getElementById('editPmAllowAddFund').checked = true;
            document.getElementById('editPmAllowCheckout').checked = true;
            document.getElementById('editPmSort').value = '0';
            document.getElementById('paymentMethodModal').classList.remove('hidden');
        }

        function openEditPaymentMethodModal(pm) {
            document.getElementById('paymentMethodModalTitle').innerText = 'EDIT PAYMENT CHANNEL: ' + (pm.name || '');
            document.getElementById('editPmId').value = pm.id || 0;
            document.getElementById('editPmName').value = pm.name || '';
            document.getElementById('editPmKey').value = pm.method_key || '';
            document.getElementById('editPmNumber').value = pm.account_number || '';
            document.getElementById('editPmType').value = pm.account_type || 'Personal';
            document.getElementById('editPmInstructions').value = pm.instructions || '';
            document.getElementById('editPmIsActive').checked = (parseInt(pm.is_active) === 1);
            document.getElementById('editPmAllowAddFund').checked = (parseInt(pm.allow_add_fund !== undefined ? pm.allow_add_fund : 1) === 1);
            document.getElementById('editPmAllowCheckout').checked = (parseInt(pm.allow_checkout !== undefined ? pm.allow_checkout : 1) === 1);
            document.getElementById('editPmSort').value = pm.sort_order || 0;
            document.getElementById('paymentMethodModal').classList.remove('hidden');
        }

        function switchDiscountSubTab(viewId) {
            document.getElementById('disc-view-products').classList.add('hidden');
            document.getElementById('disc-view-customers').classList.add('hidden');
            document.getElementById('disc-view-resellers').classList.add('hidden');
            const cBox = document.getElementById('disc-view-coupons');
            if (cBox) cBox.classList.add('hidden');

            document.getElementById('pill-disc-prods').className = "py-3 px-2 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-1.5 transition";
            document.getElementById('pill-disc-custs').className = "py-3 px-2 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-1.5 transition";
            document.getElementById('pill-disc-resells').className = "py-3 px-2 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-1.5 transition";
            const cPill = document.getElementById('pill-disc-coupons');
            if (cPill) cPill.className = "py-3 px-2 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-1.5 transition";

            document.getElementById(viewId).classList.remove('hidden');

            if (viewId === 'disc-view-products') {
                document.getElementById('pill-disc-prods').className = "py-3 px-2 rounded-xl bg-purple-600 text-white font-black flex items-center justify-center gap-1.5 shadow-sm transition";
            } else if (viewId === 'disc-view-customers') {
                document.getElementById('pill-disc-custs').className = "py-3 px-2 rounded-xl bg-sky-500 text-slate-950 font-black flex items-center justify-center gap-1.5 shadow-sm transition";
            } else if (viewId === 'disc-view-resellers') {
                document.getElementById('pill-disc-resells').className = "py-3 px-2 rounded-xl bg-amber-500 text-slate-950 font-black flex items-center justify-center gap-1.5 shadow-sm transition";
            } else if (viewId === 'disc-view-coupons') {
                if (cPill) cPill.className = "py-3 px-2 rounded-xl bg-fuchsia-600 text-white font-black flex items-center justify-center gap-1.5 shadow-sm transition";
            }

            sessionStorage.setItem('disc_active_subtab', viewId);
        }

        function filterDiscProductPlans() {
            const query = document.getElementById('discPlanSearchInput').value.trim().toLowerCase();
            const rows = document.querySelectorAll('.disc-plan-row');
            rows.forEach(r => {
                const kw = r.getAttribute('data-keywords') || '';
                if (kw.includes(query)) r.classList.remove('hidden');
                else r.classList.add('hidden');
            });
        }

        function filterDiscUsers(role) {
            const inputId = (role === 'customer') ? 'discCustomerSearchInput' : 'discResellerSearchInput';
            const containerId = (role === 'customer') ? 'discCustomerListContainer' : 'discResellerListContainer';
            const query = document.getElementById(inputId).value.trim().toLowerCase();
            const cards = document.getElementById(containerId).querySelectorAll('.disc-user-card');

            cards.forEach(c => {
                const u = c.getAttribute('data-username') || '';
                const e = c.getAttribute('data-email') || '';
                if (u.includes(query) || e.includes(query)) c.classList.remove('hidden');
                else c.classList.add('hidden');
            });
        }

        function switchProductSubView(viewId) {
            document.getElementById('view-catalog').classList.add('hidden');
            document.getElementById('view-add').classList.add('hidden');
            document.getElementById('view-stock').classList.add('hidden');

            document.getElementById('pill-catalog').className = "py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition";
            document.getElementById('pill-add').className = "py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition";
            document.getElementById('pill-stock').className = "py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition";

            document.getElementById(viewId).classList.remove('hidden');

            if (viewId === 'view-catalog') {
                document.getElementById('pill-catalog').className = "py-3 px-3 rounded-xl bg-cyan-400 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition";
                document.getElementById('catalogActionsBox').classList.remove('hidden');
            } else if (viewId === 'view-add') {
                document.getElementById('pill-add').className = "py-3 px-3 rounded-xl bg-cyan-400 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition";
                document.getElementById('catalogActionsBox').classList.add('hidden');
            } else if (viewId === 'view-stock') {
                document.getElementById('pill-stock').className = "py-3 px-3 rounded-xl bg-amber-400 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition";
                document.getElementById('catalogActionsBox').classList.add('hidden');
            }
        }

        function switchDepositSubView(viewId) {
            document.getElementById('dep-verify-sec').classList.add('hidden');
            document.getElementById('dep-rewards-sec').classList.add('hidden');

            document.getElementById('pill-dep-verify').className = "py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition";
            document.getElementById('pill-dep-rewards').className = "py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition";

            document.getElementById(viewId).classList.remove('hidden');

            if (viewId === 'dep-verify-sec') {
                document.getElementById('pill-dep-verify').className = "py-3 px-3 rounded-xl bg-teal-500 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition";
            } else {
                document.getElementById('pill-dep-rewards').className = "py-3 px-3 rounded-xl bg-amber-500 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition";
            }
        }

        function switchAdminAccessSubView(viewId) {
            document.getElementById('adminaccess-bot-sec').classList.add('hidden');
            document.getElementById('adminaccess-firebase-sec').classList.add('hidden');

            document.getElementById('pill-admin-bot').className = "py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition";
            document.getElementById('pill-admin-firebase').className = "py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition";

            document.getElementById(viewId).classList.remove('hidden');

            if (viewId === 'adminaccess-bot-sec') {
                document.getElementById('pill-admin-bot').className = "py-3 px-3 rounded-xl bg-amber-500 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition";
            } else {
                document.getElementById('pill-admin-firebase').className = "py-3 px-3 rounded-xl btn-cyan text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition";
            }
        }

        function switchSubdomainSection(subId) {
            document.getElementById('sub-add-sec').classList.add('hidden');
            document.getElementById('sub-manage-sec').classList.add('hidden');
            document.getElementById(subId).classList.remove('hidden');
            document.getElementById('subtab-btn-addsec').className = subId === 'sub-add-sec' ? 'flex-1 py-2.5 rounded-xl btn-cyan uppercase' : 'flex-1 py-2.5 rounded-xl text-slate-400 hover:text-white uppercase';
            document.getElementById('subtab-btn-managesec').className = subId === 'sub-manage-sec' ? 'flex-1 py-2.5 rounded-xl btn-cyan uppercase' : 'flex-1 py-2.5 rounded-xl text-slate-400 hover:text-white uppercase';
        }

        function togglePlanAccordion(prodId) {
            const el = document.getElementById('plan-accordion-' + prodId);
            const arrow = document.getElementById('accordion-arrow-' + prodId);
            if (el) el.classList.toggle('hidden');
            if (arrow) arrow.classList.toggle('rotate-180');
        }

        function toggleKmKeyVisibility() {
            const input = document.getElementById('kmApiKeyInput');
            const icon = document.getElementById('kmKeyEyeIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fa-solid fa-eye-slash text-xs';
            } else {
                input.type = 'password';
                icon.className = 'fa-solid fa-eye text-xs';
            }
        }

        function openSelectSyncModal() {
            document.getElementById('selectSyncModal').classList.remove('hidden');
            loadProviderCatalogForSelect();
        }

        function loadProviderCatalogForSelect() {
            const container = document.getElementById('selectSyncItemsContainer');
            container.innerHTML = '<p class="text-center text-cyan-300 py-6"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Fetching from Key Manager...</p>';

            fetch('mother_admin.php?action=fetch_km_catalog')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.items && data.items.length > 0) {
                    container.innerHTML = '';
                    data.items.forEach(p => {
                        const itemTitle = p.product_name + ' (' + p.plan_title + ')';
                        const price = parseFloat(p.price || 0).toFixed(2);
                        const bdtPrice = Math.round(parseFloat(price) * currentBdtRate);

                        const row = document.createElement('label');
                        row.className = 'flex items-center justify-between p-3 rounded-xl bg-slate-900 hover:bg-slate-800 cursor-pointer text-xs transition';
                        row.innerHTML = `
                            <div class="flex items-center space-x-2.5">
                                <input type="checkbox" name="selected_api_ids[]" value="${p.id}" class="sync-item-cb rounded text-cyan-400 scale-110">
                                <div>
                                    <strong class="text-white">${itemTitle}</strong>
                                    <span class="text-[10px] text-slate-400 block font-mono">Plan ID: #${p.id}</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="text-emerald-400 font-bold block">৳${bdtPrice}</span>
                                <span class="text-[9px] text-slate-400 font-mono">($${price} USD)</span>
                            </div>
                        `;
                        container.appendChild(row);
                    });
                } else {
                    container.innerHTML = `<div class="p-4 text-center text-red-400 text-xs">${data.error || 'No items received.'}</div>`;
                }
            })
            .catch(() => {
                container.innerHTML = '<div class="p-4 text-center text-red-400 text-xs">Failed to connect to Key Manager API.</div>';
            });
        }

        function toggleAllSyncCB(status) {
            document.querySelectorAll('.sync-item-cb').forEach(cb => cb.checked = status);
        }

        function confirmDeleteAllProducts() {
            document.getElementById('allDeleteModal').classList.remove('hidden');
        }

        function openProductEditor(prod) {
            document.getElementById('editModalProdId').value = prod.id;
            document.getElementById('editModalName').value = prod.name || '';
            document.getElementById('editModalCategory').value = prod.category || 'APKMOD';
            document.getElementById('editModalSortOrder').value = prod.sort_order || 0;
            document.getElementById('editModalDownload').value = prod.download_link || '';
            document.getElementById('editModalSetup').value = prod.setup_link || '';
            document.getElementById('editModalAppDownload').value = prod.app_download_url || '';
            document.getElementById('editModalTags').value = prod.tags || '';
            document.getElementById('editModalDesc').value = prod.description || '';
            document.getElementById('editModalMaintMsg').value = prod.maintenance_msg || '';
            document.getElementById('editModalIsPinned').checked = (parseInt(prod.is_pinned) === 1);

            const isActive = (parseInt(prod.is_active) === 1);
            const activeCb = document.getElementById('editModalIsActive');
            activeCb.checked = isActive;
            document.getElementById('editModalActiveText').innerText = isActive ? 'Online' : 'In Maintenance';
            document.getElementById('editModalActiveText').className = isActive ? 'text-emerald-400 font-mono' : 'text-red-400 font-mono';

            activeCb.onchange = function() {
                document.getElementById('editModalActiveText').innerText = this.checked ? 'Online' : 'In Maintenance';
                document.getElementById('editModalActiveText').className = this.checked ? 'text-emerald-400 font-mono' : 'text-red-400 font-mono';
            };

            const plansBox = document.getElementById('editModalPlansContainer');
            plansBox.innerHTML = '';
            if (prod.plans && prod.plans.length > 0) {
                prod.plans.forEach(pl => {
                    const row = document.createElement('div');
                    row.className = "p-3 rounded-xl bg-slate-900 border border-slate-800 space-y-2 text-xs";
                    const bdt = Math.round(parseFloat(pl.user_price) * currentBdtRate);
                    const rewardCoins = pl.reward_coins || 0;
                    const coinPrice = pl.coin_price || 0;

                    row.innerHTML = `
                        <div class="flex items-center justify-between">
                            <strong class="text-white truncate text-xs">${pl.plan_title}</strong>
                            <span class="text-[9px] text-slate-500 font-mono">KM ID #${pl.api_plan_id || 0}</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-1 border-t border-slate-800/60">
                            <div>
                                <label class="text-[9px] text-slate-400 block font-bold">Price ($ USD)</label>
                                <div class="flex items-center space-x-1 mt-0.5">
                                    <input type="number" step="0.01" name="plan_user_price[${pl.id}]" value="${parseFloat(pl.user_price).toFixed(2)}" class="w-full bg-[#030612] border border-slate-800 p-1.5 rounded-lg text-emerald-400 font-mono font-bold text-xs outline-none">
                                </div>
                                <span class="text-[9px] text-slate-500 font-mono">≈ ৳${bdt} BDT</span>
                            </div>
                            <div>
                                <label class="text-[9px] text-amber-400 block font-bold">🪙 Gift Coins</label>
                                <input type="number" name="plan_reward_coins[${pl.id}]" value="${rewardCoins}" placeholder="0" class="w-full bg-[#030612] border border-slate-800 p-1.5 rounded-lg text-amber-300 font-mono font-bold text-xs outline-none mt-0.5">
                                <span class="text-[9px] text-slate-500 font-sans">Coins given on buy</span>
                            </div>
                            <div>
                                <label class="text-[9px] text-yellow-400 block font-bold">🪙 Coin Price</label>
                                <input type="number" name="plan_coin_price[${pl.id}]" value="${coinPrice}" placeholder="0" class="w-full bg-[#030612] border border-slate-800 p-1.5 rounded-lg text-yellow-300 font-mono font-bold text-xs outline-none mt-0.5">
                                <span class="text-[9px] text-slate-500 font-sans">Coins to buy plan</span>
                            </div>
                        </div>
                    `;
                    plansBox.appendChild(row);
                });
            } else {
                plansBox.innerHTML = '<p class="text-slate-500 text-center py-3 text-xs">No plans attached.</p>';
            }

            document.getElementById('editProductModal').classList.remove('hidden');
        }

        function openAddPlanModal(prodId, prodName) {
            document.getElementById('addPlanProdId').value = prodId;
            document.getElementById('addPlanModal').classList.remove('hidden');
        }

        function onStockProdChange() {
            const prodId = parseInt(document.getElementById('stockProdSelect').value);
            const planSelect = document.getElementById('stockPlanSelect');
            planSelect.innerHTML = '<option value="">-- Choose Plan --</option>';

            const matched = globalProducts.find(p => parseInt(p.id) === prodId);
            if (matched && matched.plans) {
                matched.plans.forEach(pl => {
                    const opt = document.createElement('option');
                    opt.value = pl.id;
                    opt.innerText = `${pl.plan_title} (Stock: ${pl.stock_count || 0})`;
                    planSelect.appendChild(opt);
                });
            }
        }

        function openEditSubdomainModal(sd) {
            document.getElementById('editSubdomainId').value = sd.id;
            document.getElementById('editSubdomainSlug').value = sd.subdomain;
            document.getElementById('editSubdomainTitle').value = sd.site_title || '';
            document.getElementById('editSubdomainAdminUsername').value = sd.admin_username || '';
            document.getElementById('editSubdomainApkUrl').value = sd.apk_url || '';
            document.getElementById('editSubdomainStatus').value = sd.status || 'active';
            document.getElementById('editSubdomainModal').classList.remove('hidden');
        }

        function filterUserList(role) {
            const inputId = (role === 'customer') ? 'customerSearchInput' : 'resellerSearchInput';
            const containerId = (role === 'customer') ? 'customerListContainer' : 'resellerListContainer';
            const val = document.getElementById(inputId).value.trim().toLowerCase();
            const cards = document.getElementById(containerId).querySelectorAll('.user-item-card');

            cards.forEach(c => {
                const u = c.getAttribute('data-username') || '';
                const e = c.getAttribute('data-email') || '';
                const tg = c.getAttribute('data-tg-id') || '';

                if (u.includes(val) || e.includes(val) || tg.includes(val)) {
                    c.classList.remove('hidden');
                } else {
                    c.classList.add('hidden');
                }
            });
        }

        function openUserAccessModal(u) {
            currentActiveUser = u;
            document.getElementById('modalAccessUserId').value = u.id;
            document.getElementById('modalAccessUserName').innerText = u.username + " (ID: #" + u.id + ")";
            document.getElementById('modalAccessUserRole').value = u.role || 'customer';
            document.getElementById('modalAccessActiveTab').value = (u.role === 'reseller') ? 'tab-resellers' : 'tab-customers';
            document.getElementById('modalAccessCanAddFund').checked = (parseInt(u.can_add_fund !== undefined ? u.can_add_fund : 1) === 1);

            const allowed = userAllowedProdsMap[u.id] || [];
            document.querySelectorAll('.user-prod-checkbox').forEach(cb => {
                cb.checked = allowed.includes(parseInt(cb.value));
            });

            refreshUserCustomPricesList();
            document.getElementById('userAccessModal').classList.remove('hidden');
        }

        function refreshUserCustomPricesList() {
            if (!currentActiveUser) return;
            const u = currentActiveUser;
            const role = document.getElementById('modalAccessUserRole').value;
            const container = document.getElementById('userCustomPlansListContainer');
            container.innerHTML = '';

            const cPrices = userCustomPricesMap[u.id] || {};

            globalProducts.forEach(prod => {
                if (prod.plans && prod.plans.length > 0) {
                    const prodHeader = document.createElement('div');
                    prodHeader.className = "text-[10px] text-cyan-300 font-bold uppercase tracking-wider pt-1 border-t border-slate-800/80 flex items-center justify-between";
                    prodHeader.innerHTML = `<span>${prod.name}</span><span class="text-[9px] text-slate-500">${prod.plans.length} Plans</span>`;
                    container.appendChild(prodHeader);

                    prod.plans.forEach(pl => {
                        const baseRegularPrice = (role === 'reseller' && parseFloat(pl.reseller_price) > 0) 
                                                    ? parseFloat(pl.reseller_price) 
                                                    : (parseFloat(pl.regular_price) > 0 ? parseFloat(pl.regular_price) : parseFloat(pl.user_price));
                        
                        const hasCustom = (cPrices[pl.id] !== undefined && cPrices[pl.id] !== null && cPrices[pl.id] !== '' && parseFloat(cPrices[pl.id]) > 0);
                        const curPrice = hasCustom ? parseFloat(cPrices[pl.id]) : baseRegularPrice;
                        
                        let calcDisc = 0;
                        if (baseRegularPrice > 0 && curPrice < baseRegularPrice) {
                            calcDisc = Math.round(((baseRegularPrice - curPrice) / baseRegularPrice) * 100);
                        }

                        const row = document.createElement('div');
                        row.className = "flex flex-col sm:flex-row sm:items-center justify-between gap-2 p-2.5 rounded-xl bg-[#030612] border border-slate-800";
                        row.innerHTML = `
                            <div class="overflow-hidden">
                                <span class="text-white font-bold text-xs block">${pl.plan_title}</span>
                                <span class="text-[10px] text-slate-400 font-mono">Regular: <strong class="text-slate-300">$${baseRegularPrice.toFixed(2)}</strong></span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <div class="flex items-center bg-slate-900 px-2 py-1 rounded-lg border border-slate-800">
                                    <span class="text-[10px] text-purple-400 font-bold mr-1">% Disc:</span>
                                    <input type="number" step="0.5" min="0" max="99" id="user_plan_disc_${pl.id}" 
                                           data-base="${baseRegularPrice}" data-planid="${pl.id}" 
                                           value="${calcDisc > 0 ? calcDisc : ''}" placeholder="0" 
                                           oninput="onUserPlanDiscInput(${pl.id})" 
                                           class="w-12 bg-transparent text-purple-300 font-bold text-center outline-none text-xs">
                                </div>
                                <div class="flex items-center bg-slate-900 px-2 py-1 rounded-lg border border-slate-800">
                                    <span class="text-[10px] text-emerald-400 font-bold mr-1">$ Price:</span>
                                    <input type="number" step="0.01" min="0" name="custom_plan_price[${pl.id}]" 
                                           id="custom_price_input_${pl.id}" 
                                           data-base="${baseRegularPrice}" data-planid="${pl.id}" 
                                           value="${hasCustom ? curPrice.toFixed(2) : ''}" 
                                           placeholder="${baseRegularPrice.toFixed(2)}" 
                                           oninput="onUserPlanPriceInput(${pl.id})" 
                                           class="w-16 bg-transparent text-emerald-400 font-bold text-right outline-none text-xs">
                                </div>
                                <button type="button" onclick="resetSinglePlanUserDiscount(${pl.id})" title="Reset to regular (0% discount)" class="w-7 h-7 flex items-center justify-center rounded-lg bg-slate-800 hover:bg-red-900/40 text-slate-400 hover:text-red-400 transition text-[10px]">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </button>
                            </div>
                        `;
                        container.appendChild(row);
                    });
                }
            });
        }

        function onUserPlanDiscInput(planId) {
            const discInput = document.getElementById('user_plan_disc_' + planId);
            const priceInput = document.getElementById('custom_price_input_' + planId);
            if (!discInput || !priceInput) return;

            const base = parseFloat(discInput.getAttribute('data-base')) || 0;
            const disc = parseFloat(discInput.value);

            if (isNaN(disc) || disc <= 0) {
                priceInput.value = '';
            } else {
                const finalP = Math.max(0.01, base * (1 - (disc / 100)));
                priceInput.value = finalP.toFixed(2);
            }
        }

        function onUserPlanPriceInput(planId) {
            const discInput = document.getElementById('user_plan_disc_' + planId);
            const priceInput = document.getElementById('custom_price_input_' + planId);
            if (!discInput || !priceInput) return;

            const base = parseFloat(priceInput.getAttribute('data-base')) || 0;
            const pVal = parseFloat(priceInput.value);

            if (isNaN(pVal) || pVal >= base || pVal <= 0) {
                discInput.value = '';
            } else {
                const disc = Math.round(((base - pVal) / base) * 100);
                discInput.value = disc > 0 ? disc : '';
            }
        }

        function resetSinglePlanUserDiscount(planId) {
            const discInput = document.getElementById('user_plan_disc_' + planId);
            const priceInput = document.getElementById('custom_price_input_' + planId);
            if (discInput) discInput.value = '';
            if (priceInput) priceInput.value = '';
        }

        function openBalanceModal(id, username, email, balance, coins, status) {
            document.getElementById('balanceModalUserId').value = id;
            document.getElementById('coinsModalUserId').value = id;
            document.getElementById('banModalUserId').value = id;
            document.getElementById('banModalStatus').value = status;
            document.getElementById('balanceModalUsername').innerText = username;
            document.getElementById('balanceModalCurrent').innerText = '$' + parseFloat(balance).toFixed(2);
            document.getElementById('balanceModalCoinsCurrent').innerText = '🪙 ' + parseInt(coins || 0).toLocaleString();
            
            const banBtn = document.getElementById('banModalBtn');
            if (status === 'active') {
                banBtn.className = "w-full py-2.5 rounded-xl text-xs font-bold uppercase transition bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500/30";
                banBtn.innerText = "Ban Account";
            } else {
                banBtn.className = "w-full py-2.5 rounded-xl text-xs font-bold uppercase transition bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/30";
                banBtn.innerText = "Activate Account";
            }

            document.getElementById('balanceModal').classList.remove('hidden');
        }

        function openAddSpinSegmentModal() {
            document.getElementById('spinSegmentModalTitle').innerText = 'ADD SPIN SEGMENT';
            document.getElementById('editSegmentId').value = '0';
            document.getElementById('segTitleInput').value = '';
            document.getElementById('segTargetInput').value = '500';
            document.getElementById('segBonusInput').value = '50';
            document.getElementById('segProbInput').value = '20';
            document.getElementById('segColorInput').value = '#d946ef';
            document.getElementById('spinSegmentModal').classList.remove('hidden');
        }

        function openEditSpinSegmentModal(seg) {
            document.getElementById('spinSegmentModalTitle').innerText = 'EDIT SPIN SEGMENT';
            document.getElementById('editSegmentId').value = seg.id;
            document.getElementById('segTitleInput').value = seg.title || '';
            document.getElementById('segTargetInput').value = seg.spend_target;
            document.getElementById('segBonusInput').value = seg.bonus_amount;
            document.getElementById('segProbInput').value = seg.probability;
            document.getElementById('segColorInput').value = seg.color || '#d946ef';
            document.getElementById('spinSegmentModal').classList.remove('hidden');
        }

        function openAddDepositBonusModal() {
            document.getElementById('depositBonusModalTitle').innerText = 'ADD DEPOSIT REWARD TIER';
            document.getElementById('editBonusId').value = '0';
            document.getElementById('bonusTitleInput').value = '';
            document.getElementById('bonusMinDepositInput').value = '500';
            document.getElementById('bonusAmountInput').value = '50';
            document.getElementById('bonusTypeInput').value = 'fixed';
            document.getElementById('bonusClaimLimitInput').value = '0';
            document.getElementById('bonusSortOrderInput').value = '0';
            document.getElementById('bonusIsActiveInput').checked = true;
            document.getElementById('depositBonusModal').classList.remove('hidden');
        }

        function openEditDepositBonusModal(b) {
            document.getElementById('depositBonusModalTitle').innerText = 'EDIT DEPOSIT REWARD TIER';
            document.getElementById('editBonusId').value = b.id;
            document.getElementById('bonusTitleInput').value = b.title || '';
            document.getElementById('bonusMinDepositInput').value = b.min_deposit;
            document.getElementById('bonusAmountInput').value = b.bonus_amount;
            document.getElementById('bonusTypeInput').value = b.bonus_type || 'fixed';
            document.getElementById('bonusClaimLimitInput').value = b.claim_limit !== undefined ? b.claim_limit : 0;
            document.getElementById('bonusSortOrderInput').value = b.sort_order || 0;
            document.getElementById('bonusIsActiveInput').checked = (parseInt(b.is_active) === 1);
            document.getElementById('depositBonusModal').classList.remove('hidden');
        }

        function setThemePreset(hexColor) {
            document.getElementById('themeColorPicker').value = hexColor;
            document.getElementById('themeColorInput').value = hexColor;
        }

        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
        }

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toastNotification');
            const toastInner = document.getElementById('toastInner');
            document.getElementById('toastMsg').innerText = msg;
            toastInner.className = (type === 'error') 
                ? "bg-slate-900 border border-red-500/60 shadow-2xl rounded-2xl p-4 flex items-center space-x-3 text-xs font-semibold text-red-200" 
                : "bg-slate-900 border border-cyan-400 shadow-2xl rounded-2xl p-4 flex items-center space-x-3 text-xs font-bold text-cyan-200";
            toast.classList.remove('opacity-0', 'translate-y-[-20px]');
            toast.classList.add('opacity-100', 'translate-y-0');
            setTimeout(() => {
                toast.classList.remove('opacity-100', 'translate-y-0');
                toast.classList.add('opacity-0', 'translate-y-[-20px]');
            }, 3200);
        }

        function copyText(elId) {
            const text = document.getElementById(elId).innerText;
            navigator.clipboard.writeText(text).then(() => {
                showToast("Copied: " + text, "success");
            });
        }

        function copyAllTenantDetails() {
            const store = document.getElementById('cpStoreUrl').innerText;
            const panel = document.getElementById('cpPanelUrl').innerText;
            const user  = document.getElementById('cpUsername').innerText;
            const pass  = document.getElementById('cpPassword').innerText;

            const allText = `🏪 Storefront: ${store}\n🔐 Admin Panel: ${panel}\n👤 Username: ${user}\n🔑 Password: ${pass}`;
            navigator.clipboard.writeText(allText).then(() => {
                showToast("All credentials copied successfully!", "success");
            });
        }

        window.addEventListener('DOMContentLoaded', () => {
            const initialTab = '<?= $activeTab ?>' !== 'tab-overview' ? '<?= $activeTab ?>' : (sessionStorage.getItem('admin_active_tab') || 'tab-overview');
            const initialTitle = sessionStorage.getItem('admin_active_title') || 'Dashboard Overview';
            
            document.querySelectorAll('.tab-section').forEach(sec => sec.classList.add('hidden'));
            const targetSection = document.getElementById(initialTab);
            if (targetSection) targetSection.classList.remove('hidden');
            document.getElementById('headerSectionTitle').innerText = initialTitle;

            document.querySelectorAll('.drawer-nav-btn').forEach(btn => btn.classList.remove('active'));
            const activeBtn = document.getElementById('btn-nav-' + initialTab);
            if (activeBtn) activeBtn.classList.add('active');

            if (initialTab === 'tab-discounts') {
                const discSub = '<?= $activeDiscSubTab ?>' || sessionStorage.getItem('disc_active_subtab') || 'disc-view-products';
                switchDiscountSubTab(discSub);
            }

            <?php if ($toast): ?>
                showToast("<?= addslashes($toast['msg']) ?>", "<?= $toast['type'] ?>");
            <?php endif; ?>
        });
    </script>
</body>
</html>