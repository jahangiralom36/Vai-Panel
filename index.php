<?php
/**
 * ====================================================================
 * STOREFRONT & CLIENT CONSOLE — CENTRAL MULTI-TENANT ENGINE
 * VERSION: 22.0 (LIVE HISTORY BOTTOM FIX, NO FEEDBACK, SERIAL CATEGORIES)
 * ====================================================================
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (ob_get_level() === 0) {
    ob_start();
}

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Session Logout Handler
if (isset($_GET['logout']) || (isset($_GET['action']) && $_GET['action'] === 'logout')) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

// ====================================================================
// 2. SUBDOMAIN & TENANT RESOLUTION (STRICT DOMAIN ISOLATION)
// ====================================================================
$currentHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
$currentHost = preg_replace('/:\d+$/', '', $currentHost);
$currentHost = preg_replace('/^www\./', '', $currentHost);

$mainDomain = defined('MAIN_DOMAIN') ? MAIN_DOMAIN : 'vaipanel.shop';
$mainDomain = preg_replace('#^https?://#', '', rtrim($mainDomain, '/'));
$mainDomain = preg_replace('/:\d+$/', '', $mainDomain);
$mainDomain = preg_replace('/^www\./', '', $mainDomain);

$subSlug = '';
if (!empty($mainDomain) && strpos($currentHost, '.' . $mainDomain) !== false) {
    $extractedSlug = trim(str_replace('.' . $mainDomain, '', $currentHost));
    if ($extractedSlug !== '' && $extractedSlug !== 'www') {
        $subSlug = $extractedSlug;
    }
}

$tenant = null;
if (!empty($subSlug)) {
    try {
        $stmtT = $pdo->prepare("SELECT * FROM subdomains WHERE LOWER(TRIM(subdomain)) = LOWER(?) LIMIT 1");
        $stmtT->execute([$subSlug]);
        $tenant = $stmtT->fetch(PDO::FETCH_ASSOC);

        if (!$tenant || (isset($tenant['status']) && strtolower(trim((string)$tenant['status'])) === 'suspended')) {
            http_response_code(404);
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>404 Not Found</title>
                <style>
                    * { box-sizing: border-box; margin: 0; padding: 0; }
                    body { background-color: #04010a; color: #f1f5f9; font-family: -apple-system, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
                    .error-box { background: #0c051a; border: 1px solid rgba(244, 63, 94, 0.35); border-radius: 24px; padding: 40px 30px; max-width: 420px; width: 100%; text-align: center; }
                    .error-code { font-size: 64px; font-weight: 900; color: #f43f5e; margin-bottom: 15px; }
                    .error-title { font-size: 20px; font-weight: 800; color: #ffffff; margin-bottom: 10px; }
                    .error-msg { font-size: 13px; color: #94a3b8; line-height: 1.6; }
                </style>
            </head>
            <body>
                <div class="error-box">
                    <div class="error-code">404</div>
                    <div class="error-title">Store Not Found</div>
                    <div class="error-msg">The requested storefront does not exist or is suspended on this server.</div>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    } catch (Throwable $e) {
        $tenant = null;
    }
}

$currentSubdomainId   = $tenant ? (int)$tenant['id'] : 0;
$currentSubdomainSlug = $tenant ? strtolower(trim($tenant['subdomain'])) : 'main';

// ====================================================================
// 3. DATABASE SCHEMA AUTO-MIGRATIONS (DYNAMIC TABLES & FIXES)
// ====================================================================
if (!function_exists('safeAddCol')) {
    function safeAddCol(PDO $pdo, string $table, string $column, string $type) {
        try { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $type"); } catch (Throwable $e) {}
    }
}

// ⚡ SPEED: schema migrations (70+ CREATE/ALTER queries) now run only ONCE
// after this file changes, instead of on every single page load.
$__schemaFlagDir  = __DIR__ . '/uploads/';
if (!is_dir($__schemaFlagDir)) @mkdir($__schemaFlagDir, 0755, true);
$__schemaFlagFile = $__schemaFlagDir . '.schema_index_' . (int)@filemtime(__FILE__) . '.flag';
$runSchemaMigrations = !is_file($__schemaFlagFile) || isset($_GET['run_migrations']);

if ($runSchemaMigrations) try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
        `setting_key` VARCHAR(100) PRIMARY KEY,
        `setting_value` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `subdomain_settings` (
        `subdomain_id` INT NOT NULL,
        `setting_key` VARCHAR(100) NOT NULL,
        `setting_value` TEXT NULL,
        PRIMARY KEY (`subdomain_id`, `setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `subdomain_spin_segments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `subdomain_id` INT NOT NULL,
        `title` VARCHAR(150) NOT NULL,
        `spend_target` DECIMAL(12,2) NOT NULL DEFAULT 500,
        `bonus_amount` DECIMAL(12,2) NOT NULL DEFAULT 50,
        `probability` INT NOT NULL DEFAULT 20,
        `color` VARCHAR(30) NOT NULL DEFAULT '#d946ef',
        `is_active` TINYINT NOT NULL DEFAULT 1,
        `sort_order` INT NOT NULL DEFAULT 0
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `subdomain_deposit_bonuses` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `subdomain_id` INT NOT NULL,
        `min_deposit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `bonus_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `bonus_type` ENUM('fixed', 'percentage') NOT NULL DEFAULT 'fixed',
        `title` VARCHAR(191) NOT NULL DEFAULT 'Instant Bonus',
        `claim_limit` INT NOT NULL DEFAULT 0,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order` INT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_spin_offers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `segment_id` INT NOT NULL,
        `title` VARCHAR(191) NOT NULL,
        `spend_target` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `bonus_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `status` ENUM('active','claimed','expired') DEFAULT 'active',
        `expires_at` DATETIME NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_user_spin_active` (`user_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    safeAddCol($pdo, 'payment_methods', 'allow_add_fund', 'TINYINT(1) NOT NULL DEFAULT 1');
    safeAddCol($pdo, 'payment_methods', 'allow_checkout', 'TINYINT(1) NOT NULL DEFAULT 1');
    safeAddCol($pdo, 'payment_methods', 'banner_url', 'TEXT NULL');
    safeAddCol($pdo, 'payment_methods', 'qr_image_url', 'TEXT NULL');

    safeAddCol($pdo, 'products', 'mother_product_id', 'INT NULL DEFAULT 0');
    safeAddCol($pdo, 'products', 'sync_hash', 'VARCHAR(64) NULL');
    safeAddCol($pdo, 'products', 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'products', 'status', 'VARCHAR(50) DEFAULT "active"');
    safeAddCol($pdo, 'products', 'app_download_url', 'TEXT NULL');

    safeAddCol($pdo, 'product_plans', 'mother_plan_id', 'INT NULL DEFAULT 0');
    safeAddCol($pdo, 'product_plans', 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'product_plans', 'discount_percent', 'DECIMAL(5,2) NOT NULL DEFAULT 0.00');
    safeAddCol($pdo, 'product_plans', 'reseller_discount_percent', 'DECIMAL(5,2) NOT NULL DEFAULT 0.00');
    safeAddCol($pdo, 'product_plans', 'reward_coins', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'product_plans', 'coin_price', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'product_plans', 'sort_order', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'product_plans', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');

    safeAddCol($pdo, 'users', 'coins', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'users', 'firebase_uid', 'VARCHAR(191) NULL');
    safeAddCol($pdo, 'users', 'created_by', 'INT NULL DEFAULT 0');
    safeAddCol($pdo, 'users', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'users', 'created_by_subdomain', 'VARCHAR(100) NULL DEFAULT "main"');
    safeAddCol($pdo, 'users', 'telegram_username', 'VARCHAR(100) NULL');
    safeAddCol($pdo, 'users', 'custom_bdt_rate', 'DECIMAL(10,2) DEFAULT 0.00');
    safeAddCol($pdo, 'users', 'can_add_fund', 'TINYINT(1) DEFAULT 1');
    safeAddCol($pdo, 'users', 'is_product_restricted', 'TINYINT(1) DEFAULT 0');
    safeAddCol($pdo, 'users', 'avatar_url', 'TEXT NULL');
    safeAddCol($pdo, 'users', 'referral_code', 'VARCHAR(20) NULL');
    safeAddCol($pdo, 'users', 'referred_by', 'INT NULL DEFAULT 0');
    safeAddCol($pdo, 'users', 'referral_tokens', 'INT DEFAULT 0');
    safeAddCol($pdo, 'users', 'spin_spend_total', 'DECIMAL(12,2) DEFAULT 0.00');
    safeAddCol($pdo, 'users', 'spin_count_available', 'INT DEFAULT 0');

    safeAddCol($pdo, 'products', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'products', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    safeAddCol($pdo, 'products', 'sort_order', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'products', 'is_pinned', 'TINYINT(1) NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'products', 'category', 'VARCHAR(100) NOT NULL DEFAULT "APKMOD"');
    safeAddCol($pdo, 'products', 'logo_url', 'TEXT NULL');
    safeAddCol($pdo, 'products', 'banner_url', 'TEXT NULL');
    safeAddCol($pdo, 'products', 'image_url', 'TEXT NULL');
    safeAddCol($pdo, 'products', 'download_link', 'TEXT NULL');
    safeAddCol($pdo, 'products', 'setup_link', 'TEXT NULL');
    safeAddCol($pdo, 'products', 'tags', 'VARCHAR(255) NULL');
    safeAddCol($pdo, 'products', 'description', 'TEXT NULL');

    safeAddCol($pdo, 'product_plans', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'product_plans', 'plan_title', 'VARCHAR(150) NULL');
    safeAddCol($pdo, 'product_plans', 'validity', 'VARCHAR(150) NULL');
    safeAddCol($pdo, 'product_plans', 'user_price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    safeAddCol($pdo, 'product_plans', 'reseller_price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    safeAddCol($pdo, 'product_plans', 'price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    safeAddCol($pdo, 'product_plans', 'api_plan_id', 'INT NOT NULL DEFAULT 0');

    safeAddCol($pdo, 'stock_keys', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'banners', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'banners', 'status', 'VARCHAR(50) DEFAULT "active"');
    safeAddCol($pdo, 'system_notifications', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'transactions', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'transactions', 'bonus_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    safeAddCol($pdo, 'licenses', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'licenses', 'payment_type', 'VARCHAR(20) NOT NULL DEFAULT "balance"');
    safeAddCol($pdo, 'subdomains', 'apk_url', 'TEXT NULL');

    // Tenant support fields
    safeAddCol($pdo, 'subdomains', 'support_telegram', 'TEXT NULL');
    safeAddCol($pdo, 'subdomains', 'support_whatsapp', 'TEXT NULL');
    safeAddCol($pdo, 'licenses', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

    // Custom Popups Table
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `referral_settings` (
        `subdomain_id` INT NOT NULL PRIMARY KEY,
        `token_per_referral` INT DEFAULT 10,
        `referrer_bonus_bdt` DECIMAL(10,2) DEFAULT 0.00,
        `is_active` TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `live_purchase_feed` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `subdomain_id` INT NOT NULL DEFAULT 0,
        `masked_username` VARCHAR(50),
        `product_name` VARCHAR(191),
        `amount_bdt` DECIMAL(10,2),
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

    // ১. কুপন কোড টেবিল (Coupons Table)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `coupons` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `subdomain_id` INT NOT NULL DEFAULT 0,
        `code` VARCHAR(50) NOT NULL,
        `discount_type` ENUM('percent', 'fixed') NOT NULL DEFAULT 'percent',
        `discount_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `target_type` ENUM('all', 'specific') NOT NULL DEFAULT 'all',
        `product_ids` TEXT NULL,
        `min_spend` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `expiry_date` DATE NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");


    // 🎟️ COUPON ENGINE v2: সময়সীমা, ব্যবহার লিমিট, ইউজার প্রতি লিমিট, সর্বোচ্চ ছাড়, রোল
    safeAddCol($pdo, 'coupons', 'max_uses', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'coupons', 'used_count', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'coupons', 'per_user_limit', 'INT NOT NULL DEFAULT 1');
    safeAddCol($pdo, 'coupons', 'starts_at', 'DATETIME NULL');
    safeAddCol($pdo, 'coupons', 'expires_at', 'DATETIME NULL');
    safeAddCol($pdo, 'coupons', 'max_discount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    safeAddCol($pdo, 'coupons', 'user_role', "VARCHAR(20) NOT NULL DEFAULT 'all'");
    safeAddCol($pdo, 'coupons', 'title', 'VARCHAR(150) NULL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS `coupon_usages` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `coupon_id` INT NOT NULL,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `user_id` INT NOT NULL,
      `product_id` INT NULL,
      `discount_bdt` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `order_total_bdt` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_cu_coupon_user` (`coupon_id`, `user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // ২. প্রোডাক্ট কাস্টম মেইনটেনেন্স মেসেজ কলাম
    safeAddCol($pdo, 'products', 'maintenance_msg', 'TEXT NULL');
    safeAddCol($pdo, 'products', 'is_maintenance', 'TINYINT(1) NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'products', 'reset_enabled', 'TINYINT(1) NOT NULL DEFAULT 1');
    safeAddCol($pdo, 'products', 'km_api_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'licenses', 'plan_id', 'INT NULL');
    
    // ৩. প্রোফাইল পিকচার ও অবতার ডিরেক্টরি নিশ্চিত করা
    $avatarDir = __DIR__ . '/uploads/avatars/';
    if (!is_dir($avatarDir)) @mkdir($avatarDir, 0755, true);
    try { $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `avatar_url` TEXT NULL"); } catch (Throwable $e) {}

    // ⚡ SPEED: lookup indexes for the hottest storefront queries (added once, ignored if present)
    $__speedIndexes = [
        ['licenses', 'idx_vp_lic_sub_user', '`subdomain_id`, `user_id`'],
        ['licenses', 'idx_vp_lic_sub_created', '`subdomain_id`, `created_at`'],
        ['transactions', 'idx_vp_trx_user_sub', '`user_id`, `subdomain_id`'],
        ['products', 'idx_vp_prod_sub', '`subdomain_id`, `is_active`'],
        ['product_plans', 'idx_vp_plan_prod', '`product_id`'],
        ['live_purchase_feed', 'idx_vp_feed_sub', '`subdomain_id`, `id`'],
        ['system_notifications', 'idx_vp_notif_sub', '`subdomain_id`'],
        ['banners', 'idx_vp_banner_sub', '`subdomain_id`'],
    ];
    foreach ($__speedIndexes as $__ix) {
        try { $pdo->exec("ALTER TABLE `{$__ix[0]}` ADD INDEX `{$__ix[1]}` ({$__ix[2]})"); } catch (Throwable $e) {}
    }

    @file_put_contents($__schemaFlagFile, date('c'), LOCK_EX);
} catch (Throwable $e) {}

// Auto-seed Payment Methods if empty
try {
    $chkMethods = (int)$pdo->query("SELECT COUNT(*) FROM payment_methods WHERE subdomain_id = 0")->fetchColumn();
    if ($chkMethods === 0) {
        $bkashNum  = defined('BKASH_NUMBER') ? BKASH_NUMBER : '01700000000';
        $nagadNum  = defined('NAGAD_NUMBER') ? NAGAD_NUMBER : '01800000000';
        $rocketNum = defined('ROCKET_NUMBER') ? ROCKET_NUMBER : '01900000000';
        $stmtSeed = $pdo->prepare("INSERT INTO payment_methods (subdomain_id, method_key, name, account_number, account_type, is_active, allow_add_fund, allow_checkout, sort_order) VALUES (?, ?, ?, ?, ?, 1, 1, 1, ?)");
        $stmtSeed->execute([0, 'bkash', 'bKash', $bkashNum, 'Personal', 1]);
        $stmtSeed->execute([0, 'nagad', 'Nagad', $nagadNum, 'Personal', 2]);
        $stmtSeed->execute([0, 'rocket', 'Rocket', $rocketNum, 'Personal', 3]);
    }
} catch (Throwable $e) {}

// ====================================================================
// 3B. UNIVERSAL PRODUCT & PLAN SYNC HEALER ENGINE
// ⚡ SPEED: heavy UPDATE queries throttled to once every 2 minutes per store
// ====================================================================
$__healFlagFile = __DIR__ . '/uploads/.heal_' . (int)$currentSubdomainId . '.flag';
$__runHealer    = $runSchemaMigrations || !is_file($__healFlagFile) || (time() - (int)@filemtime($__healFlagFile)) > 120;
if ($__runHealer) { @touch($__healFlagFile); }
if ($__runHealer) try {
    $pdo->exec("UPDATE products SET is_deleted = 0, is_active = 1, status = 'active' 
                WHERE (subdomain_id = 0 OR subdomain_id IS NULL OR subdomain_id = '') 
                  AND (name IS NOT NULL AND TRIM(name) != '') 
                  AND (status != 'inactive')
                  AND (is_maintenance = 0 OR is_maintenance IS NULL)");

    $pdo->exec("UPDATE product_plans SET is_deleted = 0, is_active = 1 
                WHERE (subdomain_id = 0 OR subdomain_id IS NULL OR subdomain_id = '')");

    if ($tenant && $currentSubdomainId > 0) {
        $pdo->prepare("UPDATE products SET is_deleted = 0, is_active = 1, status = 'active' 
                       WHERE subdomain_id = ? 
                         AND (name IS NOT NULL AND TRIM(name) != '') 
                         AND (status != 'inactive')
                         AND (is_maintenance = 0 OR is_maintenance IS NULL)")->execute([$currentSubdomainId]);

        $pdo->prepare("UPDATE product_plans pl 
                       JOIN products p ON pl.product_id = p.id 
                       SET pl.subdomain_id = p.subdomain_id, pl.is_deleted = 0, pl.is_active = 1 
                       WHERE p.subdomain_id = ?")->execute([$currentSubdomainId]);
    }
} catch (Throwable $e) {}

// ====================================================================
// 4. CONFIGURATION ENGINE & SUBDOMAIN SETTINGS RESOLUTION
// ====================================================================
$defaultSupportLink = defined('SUPPORT_LINK') ? SUPPORT_LINK : (defined('TELEGRAM_SUPPORT') ? TELEGRAM_SUPPORT : '');
$defaultFirebaseUrl = defined('FIREBASE_URL') ? FIREBASE_URL : '';

$settings = [
    'site_name'                    => defined('SITE_NAME') ? SITE_NAME : 'Store',
    'site_logo'                    => '',
    'site_tagline'                 => 'Fastest Digital Licenses & Game Passes',
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
    'payment_instruction_note'     => 'Send money to the number above, then copy & paste the TrxID below to verify instantly.',
    'firebase_url'                 => $defaultFirebaseUrl,
    'auto_payment_on'              => '0',
    'snzpay_api_url'               => 'https://snzpay.digital/api/verify_payment.php',
    'snzpay_license_key'           => '',
    'snzpay_auto_verify'           => '0',
    'deposit_system_status'        => '1',
    'site_maintenance_mode'        => '0',
    'site_maintenance_msg'         => 'System upgrade in progress. We will be back online shortly.',
    'maintenance_telegram_link'    => '',
    'min_deposit_limit'            => '5.00',
    'max_deposit_limit'            => '500.00',
    'telegram_tutorial_link'       => $defaultSupportLink,
    'support_telegram'             => $defaultSupportLink,
    'support_whatsapp'             => '',
    'spin_wheel_enabled'           => '0',
    'spin_cost'                    => '0.00',
    'spin_daily_limit'             => '10',
    'spin_offer_duration_hours'    => '24',
    'spin_spend_per_turn'          => '500',
    'promo_popup_enabled'          => '1',
    'promo_popup_title'            => 'Special Recharge Bonus!',
    'promo_popup_subtitle'         => 'Grab today\'s exclusive store cashbacks!',
    'promo_popup_btn_text'         => 'Recharge Now',
    'firebase_api_key'             => '',
    'firebase_auth_domain'         => '',
    'firebase_project_id'          => '',
    'firebase_storage_bucket'      => '',
    'firebase_messaging_sender_id' => '',
    'firebase_app_id'              => '',
    'km_api_url'                   => defined('KM_DEFAULT_API_URL') ? KM_DEFAULT_API_URL : '',
    'km_api_key'                   => defined('KM_DEFAULT_API_KEY') ? KM_DEFAULT_API_KEY : '',
    'km_last_status'               => 'untested'
];

if (!$tenant) {
    try {
        $dbSettings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($dbSettings)) $settings = array_merge($settings, $dbSettings);
    } catch (Throwable $e) {}
} else {
    try {
        $stmtTS = $pdo->prepare("SELECT setting_key, setting_value FROM subdomain_settings WHERE subdomain_id = ?");
        $stmtTS->execute([$currentSubdomainId]);
        $tenantSettings = $stmtTS->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($tenantSettings)) $settings = array_merge($settings, $tenantSettings);

        // 💱 সাব-ডোমেইন নিজের রেট সেট না করলে মাদার প্যানেলের রেট (BDT / INR) ব্যবহার হবে
        try {
            $stmtMotherRates = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('usdt_rate', 'inr_rate', 'binance_market_rate')");
            $motherRates = $stmtMotherRates ? $stmtMotherRates->fetchAll(PDO::FETCH_KEY_PAIR) : [];
            foreach (['usdt_rate', 'inr_rate', 'binance_market_rate'] as $__rk) {
                if ((!isset($tenantSettings[$__rk]) || (float)$tenantSettings[$__rk] <= 0) && !empty($motherRates[$__rk])) {
                    $settings[$__rk] = $motherRates[$__rk];
                }
            }
        } catch (Throwable $e) {}

        // Ensure tenant support telegram/whatsapp overrides from subdomains table
        if (!empty($tenant['support_telegram'])) $settings['support_telegram'] = $tenant['support_telegram'];
        if (!empty($tenant['support_whatsapp'])) $settings['support_whatsapp'] = $tenant['support_whatsapp'];
    } catch (Throwable $e) {}
}

$isDepositOnline = (($settings['deposit_system_status'] ?? '1') === '1');
$isSiteMaintenance = (($settings['site_maintenance_mode'] ?? '0') === '1');

// Support links (Strict Isolation: Never fallback to hardcoded mother bot)
$tenantTelegram = '';
$tenantWhatsapp = '';
if ($tenant) {
    $tenantTelegram = trim($tenant['support_telegram'] ?? '');
    $tenantWhatsapp = trim($tenant['support_whatsapp'] ?? '');
}
if (empty($tenantTelegram) && !empty($settings['support_telegram'])) {
    $tenantTelegram = trim($settings['support_telegram']);
} elseif (empty($tenantTelegram) && !empty($settings['telegram_tutorial_link']) && stripos($settings['telegram_tutorial_link'], 'OfficialSupport') === false) {
    $tenantTelegram = trim($settings['telegram_tutorial_link']);
}

if (empty($tenantWhatsapp) && !empty($settings['support_whatsapp'])) {
    $tenantWhatsapp = trim($settings['support_whatsapp']);
}

// সাইট কালার: সাব-ডোমেইনের থিম কালার অথবা মাদার সেটিংস কালার
$siteThemeColor = !empty($tenant['theme_color']) ? $tenant['theme_color'] : ($settings['main_site_theme_color'] ?? '#00f5ff');
$inrExchangeRate = max(1.0, (float)($settings['inr_rate'] ?? 88.0));

$maintenanceTgLink = !empty($settings['maintenance_telegram_link']) 
    ? $settings['maintenance_telegram_link'] 
    : $tenantTelegram;

$effectiveTutorialLink = $tenantTelegram;

if ($tenant) {
    $hasTenantFirebase = !empty($settings['firebase_api_key']) && !empty($settings['firebase_project_id']);
    $isFirebaseEnabled = $hasTenantFirebase;

    $tenantSpinCount = 0;
    try {
        $stmtTSC = $pdo->prepare("SELECT COUNT(*) FROM subdomain_spin_segments WHERE subdomain_id = ? AND is_active = 1");
        $stmtTSC->execute([$currentSubdomainId]);
        $tenantSpinCount = (int)$stmtTSC->fetchColumn();
    } catch (Throwable $e) {}

    $isSpinWheelActive  = (($settings['spin_wheel_enabled'] ?? '0') === '1') || ($tenantSpinCount > 0);
    $isPromoPopupActive = (($settings['promo_popup_enabled'] ?? '0') === '1');

    $activeDepositBonuses = [];
    try {
        $stmtTDB = $pdo->prepare("SELECT * FROM subdomain_deposit_bonuses WHERE subdomain_id = ? AND is_active = 1 ORDER BY sort_order ASC, min_deposit ASC");
        $stmtTDB->execute([$currentSubdomainId]);
        $activeDepositBonuses = $stmtTDB->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
} else {
    $isFirebaseEnabled  = !empty($settings['firebase_api_key']) && !empty($settings['firebase_project_id']);
    $isSpinWheelActive  = ($settings['spin_wheel_enabled'] ?? '0') === '1';
    $isPromoPopupActive = ($settings['promo_popup_enabled'] ?? '1') === '1';

    $activeDepositBonuses = [];
    try {
        $activeDepositBonuses = $pdo->query("SELECT * FROM deposit_bonuses WHERE is_active = 1 ORDER BY sort_order ASC, min_deposit ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$storeTitleName = !empty($tenant['site_title']) 
    ? $tenant['site_title'] 
    : (!empty($settings['site_name']) ? $settings['site_name'] : 'Store');

$storeLogoUrl = !empty($tenant['site_logo']) 
    ? $tenant['site_logo'] 
    : (!empty($settings['site_logo']) ? $settings['site_logo'] : '');

$adminRate         = max(1.0, (float)$settings['usdt_rate']);
$binanceMarketRate = max(1.0, (float)($settings['binance_market_rate'] ?? 125.0));
$globalPaymentBanner = !empty($settings['payment_banner_url']) ? $settings['payment_banner_url'] : '';
$globalAppDownloadUrl = !empty($tenant['apk_url']) 
    ? $tenant['apk_url'] 
    : (!empty($settings['app_download_url']) ? $settings['app_download_url'] : '');


// ====================================================================
// 🎟️ COUPON ENGINE — প্রিভিউ এবং আসল পেমেন্ট দুই জায়গাতেই একই নিয়ম
// সব অ্যামাউন্ট BDT তে। রিটার্ন: ['ok'=>bool, 'message'=>..., 'discount_bdt'=>..., 'coupon'=>row]
// ====================================================================
if (!function_exists('vpValidateCoupon')) {
    function vpValidateCoupon(PDO $pdo, string $code, int $subId, int $userId, string $role, int $productId, float $amountBdt): array {
        $code = strtoupper(trim($code));
        if ($code === '') return ['ok' => false, 'message' => 'Please enter a coupon code!'];
        try {
            $st = $pdo->prepare("SELECT * FROM coupons WHERE UPPER(code) = ? AND subdomain_id = ? LIMIT 1");
            $st->execute([$code, $subId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $c = false; }
        if (!$c || (int)($c['is_active'] ?? 1) !== 1) return ['ok' => false, 'message' => 'Invalid coupon code!'];

        $now = time();
        if (!empty($c['starts_at']) && strtotime($c['starts_at']) > $now) {
            return ['ok' => false, 'message' => 'This coupon starts on ' . date('d M Y, h:i A', strtotime($c['starts_at'])) . '!'];
        }
        $expTs = null;
        if (!empty($c['expires_at']))      $expTs = strtotime($c['expires_at']);
        elseif (!empty($c['expiry_date'])) $expTs = strtotime($c['expiry_date'] . ' 23:59:59');
        if ($expTs !== null && $expTs !== false && $expTs < $now) return ['ok' => false, 'message' => 'This coupon has expired!'];

        $maxUses = (int)($c['max_uses'] ?? 0);
        if ($maxUses > 0 && (int)($c['used_count'] ?? 0) >= $maxUses) return ['ok' => false, 'message' => 'This coupon has reached its usage limit!'];

        $cRole = strtolower((string)($c['user_role'] ?? 'all'));
        if ($cRole !== 'all' && $cRole !== '' && $cRole !== strtolower($role)) {
            return ['ok' => false, 'message' => 'This coupon is only for ' . ucfirst($cRole) . 's!'];
        }

        $perUser = (int)($c['per_user_limit'] ?? 1);
        if ($perUser > 0 && $userId > 0) {
            try {
                $su = $pdo->prepare("SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ? AND user_id = ?");
                $su->execute([(int)$c['id'], $userId]);
                if ((int)$su->fetchColumn() >= $perUser) {
                    return ['ok' => false, 'message' => $perUser === 1 ? 'You have already used this coupon!' : "You can use this coupon only $perUser times!"];
                }
            } catch (Throwable $e) {}
        }

        if (($c['target_type'] ?? 'all') === 'specific') {
            $allowed = array_filter(array_map('trim', explode(',', (string)($c['product_ids'] ?? ''))));
            if (!in_array((string)$productId, $allowed, true)) return ['ok' => false, 'message' => 'This coupon is not valid for this product!'];
        }

        $minSpend = (float)($c['min_spend'] ?? 0);
        if ($minSpend > 0 && $amountBdt < $minSpend) {
            return ['ok' => false, 'message' => 'Minimum order ৳' . number_format($minSpend, 0) . ' BDT required for this coupon!'];
        }

        if (($c['discount_type'] ?? 'percent') === 'percent') {
            $disc = round($amountBdt * min(100.0, (float)$c['discount_value']) / 100, 2);
            $cap  = (float)($c['max_discount'] ?? 0);
            if ($cap > 0) $disc = min($disc, $cap);
        } else {
            $disc = (float)$c['discount_value'];
        }
        $disc = round(min(max(0.0, $disc), max(0.0, $amountBdt - 1)), 0);
        if ($disc <= 0) return ['ok' => false, 'message' => 'Coupon gives no discount on this order.'];

        $usesLeft = $maxUses > 0 ? max(0, $maxUses - (int)($c['used_count'] ?? 0)) : null;
        return ['ok' => true, 'message' => 'Coupon applied!', 'discount_bdt' => $disc, 'coupon' => $c, 'uses_left' => $usesLeft, 'expires_ts' => $expTs];
    }
}
if (!function_exists('vpRecordCouponUse')) {
    function vpRecordCouponUse(PDO $pdo, array $coupon, int $subId, int $userId, int $productId, float $discBdt, float $orderBdt): void {
        try {
            $pdo->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?")->execute([(int)$coupon['id']]);
            $pdo->prepare("INSERT INTO coupon_usages (coupon_id, subdomain_id, user_id, product_id, discount_bdt, order_total_bdt) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([(int)$coupon['id'], $subId, $userId, $productId, $discBdt, $orderBdt]);
        } catch (Throwable $e) {}
    }
}

// ====================================================================
// 4B. TELEGRAM BOT NOTIFICATION ENGINE (MAIN + TENANT)
// ====================================================================
if (!function_exists('sendTelegramBotNotification')) {
    function sendTelegramBotNotification(PDO $pdo, int $subdomainId, string $eventType, string $message): bool {
        try {
            $stmtBot = $pdo->prepare("SELECT * FROM telegram_bot_config 
                WHERE (subdomain_id = ? OR subdomain_id = 0) AND is_active = 1 
                ORDER BY subdomain_id DESC LIMIT 1");
            $stmtBot->execute([$subdomainId]);
            $bot = $stmtBot->fetch(PDO::FETCH_ASSOC);

            if (!$bot || empty($bot['bot_token']) || empty($bot['chat_id'])) return false;

            $flagField = null;
            if ($eventType === 'deposit') $flagField = 'notify_deposit';
            elseif ($eventType === 'purchase') $flagField = 'notify_purchase';
            elseif ($eventType === 'failed') $flagField = 'notify_failed';

            if ($flagField && (int)($bot[$flagField] ?? 1) === 0) return false;

            $url = "https://api.telegram.org/bot{$bot['bot_token']}/sendMessage";
            $payload = [
                'chat_id' => $bot['chat_id'],
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            return $res !== false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

// ====================================================================
// SNZPAY AUTO-PAYMENT VERIFICATION ENGINE
// ====================================================================
function verifyWithSnzPay(string $trxId, string $method, float $amountBdt, array $storeSettings): array {
    $apiUrl     = !empty($storeSettings['snzpay_api_url']) ? trim($storeSettings['snzpay_api_url']) : 'https://snzpay.digital/api/verify_payment.php';
    $licenseKey = trim($storeSettings['snzpay_license_key'] ?? '');

    if (empty($licenseKey)) {
        return ['status' => 'error', 'message' => 'Auto-payment gateway license key is not configured! Please contact admin.'];
    }

    $cleanTrx    = strtoupper(trim($trxId));
    $cleanMethod = strtoupper(trim($method));
    if (strpos($cleanMethod, 'BKASH') !== false) $cleanMethod = 'BKASH';
    elseif (strpos($cleanMethod, 'NAGAD') !== false) $cleanMethod = 'NAGAD';
    elseif (strpos($cleanMethod, 'ROCKET') !== false) $cleanMethod = 'ROCKET';
    elseif (strpos($cleanMethod, 'BINANCE') !== false) $cleanMethod = 'BINANCE';
    elseif (strpos($cleanMethod, 'QR') !== false) $cleanMethod = 'BANGLA_QR';

    $payload = [
        "license_key" => $licenseKey,
        "trx_id"      => $cleanTrx,
        "method"      => $cleanMethod,
        "amount"      => (float)$amountBdt
    ];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $res = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['status' => 'error', 'message' => 'Payment Gateway Timeout: ' . $curlErr];
    }

    $result = json_decode($res ?: '', true);
    if ($result && isset($result['status']) && $result['status'] === 'success') {
        return [
            'status'  => 'success',
            'amount'  => (float)($result['data']['amount'] ?? $amountBdt),
            'sender'  => $result['data']['sender'] ?? '',
            'trx_id'  => $result['data']['trx_id'] ?? $cleanTrx,
            'message' => $result['message'] ?? 'Payment verified successfully by gateway.'
        ];
    }

    return [
        'status'  => 'error',
        'message' => $result['message'] ?? ($result['error'] ?? 'Transaction ID verification failed at gateway! Please check your SMS.')
    ];
}

// ====================================================================
// 5. AUTHENTICATION CONTROLLER (DEFAULTS TO CUSTOMER ROLE)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if ($_POST['auth_action'] === 'firebase_auth' || $_POST['auth_action'] === 'google_auth') {
        $targetEmail = strtolower(trim($_POST['email'] ?? ''));
        $targetName  = trim($_POST['name'] ?? '');
        $firebaseUid = trim($_POST['firebase_uid'] ?? '');
        $credential  = trim($_POST['credential'] ?? '');
        $directEmail = strtolower(trim($_POST['direct_email'] ?? ''));
        $refCode     = trim($_POST['referral_code'] ?? '');

        if (empty($targetEmail) && !empty($credential)) {
            $parts = explode('.', $credential);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if ($payload && !empty($payload['email'])) {
                    $targetEmail = strtolower(trim($payload['email']));
                    $targetName  = trim($payload['name'] ?? explode('@', $targetEmail)[0]);
                }
            }
        } elseif (empty($targetEmail) && !empty($directEmail)) {
            $targetEmail = $directEmail;
            $targetName  = explode('@', $directEmail)[0];
        }

        if (empty($targetEmail) || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Valid Gmail address is required!']);
            exit;
        }

        $stmtChk = $pdo->prepare("SELECT * FROM users WHERE email = ? AND subdomain_id = ? LIMIT 1");
        $stmtChk->execute([$targetEmail, $currentSubdomainId]);
        $rowUser = $stmtChk->fetch(PDO::FETCH_ASSOC);

        if ($rowUser) {
            if (($rowUser['status'] ?? 'active') !== 'active') {
                echo json_encode(['status' => 'error', 'message' => 'Account is suspended on this store!']);
                exit;
            }

            if (!empty($firebaseUid)) {
                $pdo->prepare("UPDATE users SET firebase_uid = ? WHERE id = ?")->execute([$firebaseUid, $rowUser['id']]);
            }

            $_SESSION['auth_user_id']      = (int)$rowUser['id'];
            $_SESSION['user_id']           = (int)$rowUser['id'];
            $_SESSION['auth_username']     = $rowUser['username'];
            $_SESSION['auth_subdomain_id'] = $currentSubdomainId;

            echo json_encode(['status' => 'success', 'message' => 'Welcome back, ' . htmlspecialchars($rowUser['username']) . '!']);
            exit;
        } else {
            $cleanName = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower(!empty($targetName) ? $targetName : explode('@', $targetEmail)[0]));
            if (strlen($cleanName) < 3) {
                $cleanName = 'user_' . substr(md5(uniqid()), 0, 5);
            }

            $stmtUCheck = $pdo->prepare("SELECT id FROM users WHERE username = ? AND subdomain_id = ? LIMIT 1");
            $stmtUCheck->execute([$cleanName, $currentSubdomainId]);
            if ($stmtUCheck->fetchColumn()) {
                $cleanName .= '_' . rand(100, 999);
            }

            $randomPass = password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT);
            $assignedCreator = $tenant ? (int)$tenant['id'] : 0;
            $newReferralCode = strtoupper(substr(md5(uniqid($cleanName, true)), 0, 8));

            // Handle referral bonus
            $referredBy = 0;
            $refTokens = 0;
            $refBonusBdt = 0;
            if (!empty($refCode)) {
                try {
                    $stmtRef = $pdo->prepare("SELECT id FROM users WHERE referral_code = ? AND subdomain_id = ? LIMIT 1");
                    $stmtRef->execute([strtoupper($refCode), $currentSubdomainId]);
                    $referredBy = (int)$stmtRef->fetchColumn();

                    if ($referredBy > 0) {
                        $stmtRS = $pdo->prepare("SELECT * FROM referral_settings WHERE subdomain_id = ? LIMIT 1");
                        $stmtRS->execute([$currentSubdomainId]);
                        $refSettings = $stmtRS->fetch(PDO::FETCH_ASSOC);
                        if ($refSettings && (int)$refSettings['is_active'] === 1) {
                            $refTokens = (int)$refSettings['token_per_referral'];
                            $refBonusBdt = (float)$refSettings['referrer_bonus_bdt'];

                            if ($refTokens > 0) {
                                $pdo->prepare("UPDATE users SET coins = coins + ?, referral_tokens = referral_tokens + ? WHERE id = ?")
                                    ->execute([$refTokens, $refTokens, $referredBy]);
                            }
                            if ($refBonusBdt > 0) {
                                $bonusUsd = $refBonusBdt / max(1.0, (float)($settings['usdt_rate'] ?? 125));
                                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
                                    ->execute([$bonusUsd, $referredBy]);
                            }
                        }
                    }
                } catch (Throwable $e) {}
            }

            try {
                $stmtIns = $pdo->prepare("INSERT INTO users 
                    (username, email, password, role, balance, coins, status, created_by, subdomain_id, created_by_subdomain, firebase_uid, referral_code, referred_by, created_at) 
                    VALUES (?, ?, ?, 'customer', 0.00, 0, 'active', ?, ?, ?, ?, ?, ?, NOW())");
                $stmtIns->execute([$cleanName, $targetEmail, $randomPass, $assignedCreator, $currentSubdomainId, $currentSubdomainSlug, $firebaseUid ?: null, $newReferralCode, $referredBy]);
                $newId = (int)$pdo->lastInsertId();

                $_SESSION['auth_user_id']      = $newId;
                $_SESSION['user_id']           = $newId;
                $_SESSION['auth_username']     = $cleanName;
                $_SESSION['auth_subdomain_id'] = $currentSubdomainId;

                echo json_encode(['status' => 'success', 'message' => 'Account created on this store! Welcome, ' . $cleanName]);
                exit;
            } catch (Throwable $e) {
                echo json_encode(['status' => 'error', 'message' => 'Registration error: ' . $e->getMessage()]);
                exit;
            }
        }
    }

    if ($_POST['auth_action'] === 'user_login') {
        $loginInput = trim($_POST['login_identity'] ?? '');
        $password   = $_POST['login_password'] ?? '';

        if (empty($loginInput) || empty($password)) {
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Username/Email and Password are required!'];
            header('Location: index.php');
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND subdomain_id = ? LIMIT 1");
        $stmt->execute([$loginInput, $loginInput, $currentSubdomainId]);
        $rowUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($rowUser && (password_verify($password, $rowUser['password']) || $password === $rowUser['password'])) {
            if (($rowUser['status'] ?? 'active') !== 'active') {
                $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Account is suspended on this store!'];
                header('Location: index.php');
                exit;
            }

            $_SESSION['auth_user_id']      = (int)$rowUser['id'];
            $_SESSION['user_id']           = (int)$rowUser['id'];
            $_SESSION['auth_username']     = $rowUser['username'];
            $_SESSION['auth_subdomain_id'] = $currentSubdomainId;
            $_SESSION['flash_toast']       = ['type' => 'success', 'msg' => 'Welcome back, ' . htmlspecialchars($rowUser['username']) . '!'];
            header('Location: index.php');
            exit;
        } else {
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Invalid credentials for this store!'];
            header('Location: index.php');
            exit;
        }
    }

    if ($_POST['auth_action'] === 'user_register') {
        $username = trim($_POST['reg_username'] ?? '');
        $email    = strtolower(trim($_POST['reg_email'] ?? ''));
        $password = $_POST['reg_password'] ?? '';
        $refCode  = trim($_POST['reg_referral'] ?? '');

        if (strlen($username) < 3) {
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Username must be at least 3 characters!'];
            header('Location: index.php');
            exit;
        }
        if (strlen($password) < 6) {
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Password must be at least 6 characters!'];
            header('Location: index.php');
            exit;
        }

        $stmtChk = $pdo->prepare("SELECT id FROM users WHERE username = ? AND subdomain_id = ? LIMIT 1");
        $stmtChk->execute([$username, $currentSubdomainId]);
        if ($stmtChk->fetchColumn()) {
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Username is already taken on this store!'];
            header('Location: index.php');
            exit;
        }

        if (!empty($email)) {
            $stmtChkE = $pdo->prepare("SELECT id FROM users WHERE email = ? AND subdomain_id = ? LIMIT 1");
            $stmtChkE->execute([$email, $currentSubdomainId]);
            if ($stmtChkE->fetchColumn()) {
                $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Email already registered on this store! Please log in.'];
                header('Location: index.php');
                exit;
            }
        }

        $hashedPass      = password_hash($password, PASSWORD_DEFAULT);
        $assignedCreator = $tenant ? (int)$tenant['id'] : 0;
        $newReferralCode = strtoupper(substr(md5(uniqid($username, true)), 0, 8));

        $referredBy = 0;
        if (!empty($refCode)) {
            try {
                $stmtRef = $pdo->prepare("SELECT id FROM users WHERE referral_code = ? AND subdomain_id = ? LIMIT 1");
                $stmtRef->execute([strtoupper($refCode), $currentSubdomainId]);
                $referredBy = (int)$stmtRef->fetchColumn();

                if ($referredBy > 0) {
                    $stmtRS = $pdo->prepare("SELECT * FROM referral_settings WHERE subdomain_id = ? LIMIT 1");
                    $stmtRS->execute([$currentSubdomainId]);
                    $refSettings = $stmtRS->fetch(PDO::FETCH_ASSOC);
                    if ($refSettings && (int)$refSettings['is_active'] === 1) {
                        $refTokens = (int)$refSettings['token_per_referral'];
                        $refBonusBdt = (float)$refSettings['referrer_bonus_bdt'];
                        if ($refTokens > 0) {
                            $pdo->prepare("UPDATE users SET coins = coins + ?, referral_tokens = referral_tokens + ? WHERE id = ?")
                                ->execute([$refTokens, $refTokens, $referredBy]);
                        }
                        if ($refBonusBdt > 0) {
                            $bonusUsd = $refBonusBdt / max(1.0, (float)($settings['usdt_rate'] ?? 125));
                            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
                                ->execute([$bonusUsd, $referredBy]);
                        }
                    }
                }
            } catch (Throwable $e) {}
        }

        try {
            $stmtIns = $pdo->prepare("INSERT INTO users 
                (username, email, password, role, balance, coins, status, created_by, subdomain_id, created_by_subdomain, referral_code, referred_by, created_at) 
                VALUES (?, ?, ?, 'customer', 0.00, 0, 'active', ?, ?, ?, ?, ?, NOW())");
            $stmtIns->execute([$username, $email ?: null, $hashedPass, $assignedCreator, $currentSubdomainId, $currentSubdomainSlug, $newReferralCode, $referredBy]);
            $newId = (int)$pdo->lastInsertId();

            $_SESSION['auth_user_id']      = $newId;
            $_SESSION['user_id']           = $newId;
            $_SESSION['auth_username']     = $username;
            $_SESSION['auth_subdomain_id'] = $currentSubdomainId;
            $_SESSION['flash_toast']       = ['type' => 'success', 'msg' => 'Registration successful! Welcome to store.'];
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Registration error: ' . $e->getMessage()];
            header('Location: index.php');
            exit;
        }
    }
}

// ====================================================================
// 6. USER INTEGRITY & RATE RESOLUTION (STRICT DOMAIN SCOPE)
// ====================================================================
$userId     = (int)($_SESSION['auth_user_id'] ?? $_SESSION['user_id'] ?? 0);
$user       = null;
$isLoggedIn = false;

if ($userId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND subdomain_id = ? LIMIT 1");
        $stmt->execute([$userId, $currentSubdomainId]);
        $fetchedUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fetchedUser && ($fetchedUser['status'] ?? 'active') === 'active') {
            $user       = $fetchedUser;
            $isLoggedIn = true;

            // FIX: রেফার কোড খালি/N/A থাকলে অটো জেনারেট হয়ে ডাটাবেজে সেভ হবে
            if (empty($user['referral_code'])) {
                $user['referral_code'] = strtoupper(substr(md5(uniqid((string)$user['id'] . $user['username'], true)), 0, 8));
                try {
                    $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$user['referral_code'], $user['id']]);
                } catch (Throwable $e) {}
            }
        } else {
            unset($_SESSION['auth_user_id'], $_SESSION['user_id'], $_SESSION['auth_username'], $_SESSION['auth_subdomain_id']);
            $userId = 0;
        }
    } catch (Throwable $e) {
        $userId = 0;
    }
}

if (!$isLoggedIn || !$user) {
    $user = [
        'id'                     => 0,
        'username'               => 'Guest',
        'role'                   => 'customer',
        'balance'                => 0.00,
        'coins'                  => 0,
        'email'                  => '',
        'telegram_username'      => '',
        'can_add_fund'           => 1,
        'is_product_restricted'  => 0,
        'referral_code'          => '',
        'spin_spend_total'       => 0,
        'spin_count_available'   => 0
    ];
}

$rawRole  = strtolower(trim((string)($user['role'] ?? 'customer')));
$userRole = ($rawRole === 'reseller') ? 'reseller' : 'customer';

$canAddFund       = ($isDepositOnline && (int)($user['can_add_fund'] ?? 1) === 1) ? 1 : 0;
$userCustomRate   = (float)($user['custom_bdt_rate'] ?? 0);
$effectiveBdtRate = ($userCustomRate > 0) ? $userCustomRate : $adminRate;

$userCoins        = (int)($user['coins'] ?? 0);
$userBalanceBdt   = round((float)$user['balance'] * $effectiveBdtRate, 0);

$userCustomPrices    = [];
$userAllowedProducts = [];

if ($isLoggedIn && $userRole === 'reseller') {
    try {
        $stmtUCP = $pdo->prepare("SELECT plan_id, custom_price FROM user_custom_prices WHERE user_id = ?");
        $stmtUCP->execute([$user['id']]);
        $userCustomPrices = $stmtUCP->fetchAll(PDO::FETCH_KEY_PAIR);

        if ((int)($user['is_product_restricted'] ?? 0) === 1) {
            $stmtUAP = $pdo->prepare("SELECT product_id FROM user_allowed_products WHERE user_id = ?");
            $stmtUAP->execute([$user['id']]);
            $rawAllowed = $stmtUAP->fetchAll(PDO::FETCH_COLUMN);
            $userAllowedProducts = array_filter(array_map('intval', $rawAllowed));
        }
    } catch (Throwable $e) {}
}

$defaultCustomerPrices = [];
try {
    if ($tenant) {
        $stmtDCP = $pdo->prepare("SELECT ucp.plan_id, MIN(ucp.custom_price) 
            FROM user_custom_prices ucp
            JOIN users u ON u.id = ucp.user_id
            WHERE u.subdomain_id = ? AND (u.role = 'customer' OR u.role IS NULL OR u.role = '')
            GROUP BY ucp.plan_id");
        $stmtDCP->execute([$currentSubdomainId]);
        if ($stmtDCP) {
            $defaultCustomerPrices = $stmtDCP->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    } else {
        $stmtDCP = $pdo->query("SELECT ucp.plan_id, MIN(ucp.custom_price) 
            FROM user_custom_prices ucp
            JOIN users u ON u.id = ucp.user_id
            WHERE (u.subdomain_id = 0 OR u.subdomain_id IS NULL OR u.subdomain_id = '') 
              AND (u.role = 'customer' OR u.role IS NULL OR u.role = '')
            GROUP BY ucp.plan_id");
        if ($stmtDCP && is_object($stmtDCP)) {
            $defaultCustomerPrices = $stmtDCP->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    }
} catch (Throwable $e) {}

// ====================================================================
// 7. KEY MANAGER REST HANDLER
// ====================================================================
if (!function_exists('generateFromExternalProvider')) {
    function generateFromExternalProvider($apiPlanId, $quantity = 1, ?array $tenantContext = null) {
        global $pdo, $settings, $currentSubdomainId;
        
        $planIdInt = (int)$apiPlanId;
        $qtyInt    = max(1, min(100, (int)$quantity));

        $kmUrl = trim($settings['km_api_url'] ?? '');
        $kmKey = trim($settings['km_api_key'] ?? '');

        if (empty($kmUrl) || empty($kmKey)) {
            return ['status' => 'error', 'message' => 'Out of stock! This product is currently unavailable.'];
        }

        $targetEndpoint = rtrim($kmUrl, '/');
        $params = [
            'action'     => 'generate_key',
            'plan_id'    => $planIdInt,
            'product_id' => $planIdInt,
            'quantity'   => $qtyInt,
            'api_key'    => $kmKey
        ];

        if (stripos($targetEndpoint, '.php') === false) {
            $targetEndpoint .= '/api.php';
        }

        $headers = [
            'X-API-Token: ' . $kmKey,
            'X-API-KEY: ' . $kmKey,
            'Accept: application/json',
            'User-Agent: KeyManagerStorefront/22.0'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $targetEndpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['status' => 'error', 'message' => 'Key Manager Connection Timeout: ' . $curlErr];
        }

        $data = json_decode($body ?: '', true);
        if (!is_array($data)) {
            return ['status' => 'error', 'message' => 'Out of stock! This product is currently unavailable.'];
        }

        if ((isset($data['status']) && $data['status'] === 'success') || (isset($data['success']) && $data['success'] === true)) {
            $extractedKeys = [];
            if (!empty($data['keys']) && is_array($data['keys'])) {
                foreach ($data['keys'] as $item) {
                    if (is_array($item)) {
                        $kVal = $item['key'] ?? ($item['license_key'] ?? ($item['code'] ?? ''));
                        if (!empty($kVal)) $extractedKeys[] = trim((string)$kVal);
                    } elseif (is_string($item)) {
                        $extractedKeys[] = trim($item);
                    }
                }
            } elseif (!empty($data['key']) && is_string($data['key'])) {
                $extractedKeys[] = trim($data['key']);
            }

            if (!empty($extractedKeys)) {
                return [
                    'status'         => 'success',
                    'keys'           => $extractedKeys,
                    'amount_charged' => $data['amount_charged'] ?? 0,
                    'remaining_bal'  => $data['remaining_balance'] ?? ($data['new_balance'] ?? 0)
                ];
            }
        }

        $errorMsg = $data['message'] ?? ($data['error'] ?? 'Out of stock! This product is currently unavailable.');
        return ['status' => 'error', 'message' => $errorMsg];
    }
}

// Helper: Mask username for privacy
if (!function_exists('maskUsername')) {
    function maskUsername(string $name): string {
        $len = strlen($name);
        if ($len <= 3) return substr($name, 0, 1) . '***';
        return substr($name, 0, 3) . str_repeat('*', max(0, $len - 5)) . substr($name, -2);
    }
}

// ====================================================================
// 8. TOP-LEVEL AJAX HANDLERS
// ====================================================================
// --- AJAX: CHECK AND APPLY COUPON CODE ---
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'apply_coupon') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $couponCode = strtoupper(trim($_POST['coupon_code'] ?? ''));
    $productId  = (int)($_POST['product_id'] ?? 0);
    $planPrice  = (float)($_POST['plan_price_bdt'] ?? 0);

    if (empty($couponCode)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a coupon code!']);
        exit;
    }

    $cv = vpValidateCoupon($pdo, $couponCode, $currentSubdomainId, (int)($user['id'] ?? 0), $userRole, $productId, $planPrice);
    if (!$cv['ok']) {
        echo json_encode(['status' => 'error', 'message' => $cv['message']]);
        exit;
    }
    $discountAmount = (float)$cv['discount_bdt'];
    $finalPrice     = max(1.0, round($planPrice - $discountAmount, 0));
    $c = $cv['coupon'];
    $offLabel = ($c['discount_type'] === 'percent') ? rtrim(rtrim(number_format((float)$c['discount_value'], 2, '.', ''), '0'), '.') . '% OFF' : '৳' . number_format((float)$c['discount_value'], 0) . ' OFF';

    echo json_encode([
        'status'          => 'success',
        'message'         => 'Coupon applied successfully!',
        'coupon_code'     => $c['code'],
        'off_label'       => $offLabel,
        'discount_amount' => $discountAmount,
        'final_price_bdt' => $finalPrice,
        'base_price_bdt'  => $planPrice,
        'uses_left'       => $cv['uses_left'],
        'expires_at'      => $cv['expires_ts'] ? date('d M Y, h:i A', $cv['expires_ts']) : null
    ]);
    exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'verify_and_deliver') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if (!$isLoggedIn || $user['id'] <= 0) {
        echo json_encode(['status' => 'error', 'require_login' => true, 'message' => 'Please login or register to complete purchase!']);
        exit;
    }

    if ($isSiteMaintenance) {
        echo json_encode(['status' => 'error', 'message' => 'Store is currently under maintenance. Orders are temporarily paused.']);
        exit;
    }

    $planId    = (int)($_POST['plan_id'] ?? 0);
    $quantity  = max(1, min(50, (int)($_POST['quantity'] ?? 1)));
    $methodKey = strtolower(trim($_POST['method_key'] ?? 'bkash'));
    $methodId  = (int)($_POST['method_id'] ?? 0);
    $trxId     = trim(htmlspecialchars(strtoupper($_POST['trx_id'] ?? '')));
    $senderNum = trim(htmlspecialchars($_POST['sender'] ?? ''));

    if ($planId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a valid plan!']);
        exit;
    }

    if (empty($trxId) || strlen($trxId) < 4) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid Transaction ID (TrxID)!']);
        exit;
    }

    $stmtChkTrx = $pdo->prepare("SELECT id, status FROM transactions WHERE UPPER(trx_id) = ? AND subdomain_id = ? LIMIT 1");
    $stmtChkTrx->execute([$trxId, $currentSubdomainId]);
    $existingTrx = $stmtChkTrx->fetch(PDO::FETCH_ASSOC);

    if ($existingTrx) {
        echo json_encode([
            'status'  => 'error', 
            'message' => 'This Transaction ID (TrxID) has already been used or verified! Duplicate submissions are strictly prevented.'
        ]);
        exit;
    }

    $methodRow = null;
    if ($methodId > 0) {
        $stmtM = $pdo->prepare("SELECT * FROM payment_methods WHERE id = ? AND subdomain_id = ? AND is_active = 1 LIMIT 1");
        $stmtM->execute([$methodId, $currentSubdomainId]);
        $methodRow = $stmtM->fetch(PDO::FETCH_ASSOC);
    }
    if (!$methodRow) {
        $stmtM = $pdo->prepare("SELECT * FROM payment_methods WHERE LOWER(method_key) = ? AND subdomain_id = ? AND is_active = 1 LIMIT 1");
        $stmtM->execute([$methodKey, $currentSubdomainId]);
        $methodRow = $stmtM->fetch(PDO::FETCH_ASSOC);
    }

    $methodDisplayName = $methodRow ? $methodRow['name'] : ucfirst($methodKey);

    $stmt = $pdo->prepare("SELECT p.id as real_product_id, p.name as product_name, p.is_active, 
                                  COALESCE(NULLIF(pl.plan_title, ''), NULLIF(pl.validity, ''), 'Standard Plan') as plan_title,
                                  pl.reseller_price, pl.user_price, pl.price,
                                  pl.reseller_discount_percent, COALESCE(pl.discount_percent, 0) as discount_percent,
                                  pl.api_plan_id, pl.reward_coins, pl.coin_price 
                           FROM product_plans pl 
                           JOIN products p ON pl.product_id = p.id 
                           WHERE pl.id = ? 
                             AND p.subdomain_id = ?
                             AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                           LIMIT 1");
    $stmt->execute([$planId, $currentSubdomainId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan || (int)($plan['is_active'] ?? 1) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'This product plan is currently unavailable or under maintenance!']);
        exit;
    }

    if ($userRole === 'reseller' && !empty($userAllowedProducts) && !in_array((int)$plan['real_product_id'], array_map('intval', $userAllowedProducts))) {
        echo json_encode(['status' => 'error', 'message' => 'Access Restricted: You do not have permission to purchase this product.']);
        exit;
    }

    $productFullName    = $plan['product_name'] . ' (' . $plan['plan_title'] . ')';
    $rewardCoinsPerItem = max(0, (int)($plan['reward_coins'] ?? 0));
    $totalRewardCoins   = $rewardCoinsPerItem * $quantity;

    $rawRetailPrice = (float)(($plan['user_price'] ?? 0) ?: (($plan['price'] ?? 0) ?: ($plan['reseller_price'] ?? 0)));
    $rawResellPrice = (float)(($plan['reseller_price'] ?? 0) ?: (($plan['price'] ?? 0) ?: ($plan['user_price'] ?? 0)));
    $basePrice = ($userRole === 'reseller') ? ($rawResellPrice > 0 ? $rawResellPrice : $rawRetailPrice) : ($rawRetailPrice > 0 ? $rawRetailPrice : $rawResellPrice);

    $unitPrice = null;
    if (isset($userCustomPrices[$planId]) && (float)$userCustomPrices[$planId] > 0) {
        $unitPrice = (float)$userCustomPrices[$planId];
    } else {
        $explicitDiscount = ($userRole === 'reseller')
            ? (float)($plan['reseller_discount_percent'] ?? 0)
            : (float)($plan['discount_percent'] ?? 0);
        if ($explicitDiscount >= 0) {
            $unitPrice = round($basePrice * (1 - ($explicitDiscount / 100)), 2);
        } else {
            $unitPrice = $basePrice;
        }
    }

    $totalCostUsd = $unitPrice * $quantity;
    $totalCostBdt = round($totalCostUsd * $effectiveBdtRate, 0);

    // 🎟️ কুপন (আসল পেমেন্টে)
    $vpCouponApplied = null; $vpCouponDisc = 0.0; $vpOrderBdtBeforeCoupon = $totalCostBdt;
    if (trim($_POST['coupon_code'] ?? '') !== '') {
        $cv = vpValidateCoupon($pdo, (string)$_POST['coupon_code'], $currentSubdomainId, (int)$user['id'], $userRole, (int)$plan['real_product_id'], (float)$totalCostBdt);
        if (!$cv['ok']) { echo json_encode(['status' => 'error', 'message' => '🎟️ ' . $cv['message']]); exit; }
        $vpCouponApplied = $cv['coupon']; $vpCouponDisc = (float)$cv['discount_bdt'];
        $totalCostBdt = max(1, round($totalCostBdt - $vpCouponDisc, 0));
        $totalCostUsd = $totalCostBdt / max(1.0, $effectiveBdtRate);
        $unitPrice    = $totalCostUsd / max(1, $quantity);
    }

    $isAutoPaymentEnforced = (($settings['auto_payment_on'] ?? '0') === '1') || (($settings['snzpay_auto_verify'] ?? '0') === '1');
    $isVerified = false;
    $verifiedAmountBdt = $totalCostBdt;

    try {
        $stmtGW = $pdo->prepare("SELECT * FROM gateway_transactions 
            WHERE UPPER(trx_id) = ? AND subdomain_id = ? AND status = 'unclaimed' 
            ORDER BY id DESC LIMIT 1");
        $stmtGW->execute([$trxId, $currentSubdomainId]);
        $gwTrx = $stmtGW->fetch(PDO::FETCH_ASSOC);

        if ($gwTrx) {
            $gwAmount = (float)$gwTrx['amount'];
            if ($gwAmount >= ($totalCostBdt - 2.0)) {
                $isVerified = true;
                $verifiedAmountBdt = $gwAmount;
                if (!empty($gwTrx['sender']) && empty($senderNum)) {
                    $senderNum = $gwTrx['sender'];
                }
            } else {
                echo json_encode([
                    'status'  => 'error', 
                    'message' => 'Payment amount mismatch! Expected ৳' . number_format($totalCostBdt, 0) . ' BDT, but received ৳' . number_format($gwAmount, 0) . ' BDT.'
                ]);
                exit;
            }
        }
    } catch (Throwable $e) {}

    if (!$isVerified && $isAutoPaymentEnforced) {
        $snzRes = verifyWithSnzPay($trxId, $methodKey, $totalCostBdt, $settings);
        if ($snzRes['status'] === 'success') {
            $isVerified = true;
            $verifiedAmountBdt = (float)($snzRes['amount'] ?? $totalCostBdt);
            if (!empty($snzRes['sender']) && empty($senderNum)) {
                $senderNum = $snzRes['sender'];
            }
        } else {
            echo json_encode([
                'status'              => 'error',
                'verification_failed' => true,
                'message'             => $snzRes['message'] ?? 'Transaction ID verification failed at gateway! Please check your SMS and try again.'
            ]);
            exit;
        }
    }

    if (!$isVerified && !$isAutoPaymentEnforced) {
        $amountInUsd = round($totalCostBdt / $effectiveBdtRate, 2);
        try {
            $pdo->prepare("INSERT INTO transactions (user_id, method, amount, bonus_amount, sender_num, trx_id, status, subdomain_id) VALUES (?, ?, ?, 0.00, ?, ?, 'pending', ?)")
                ->execute([$user['id'], $methodDisplayName . ' (Manual Order)', $amountInUsd, $senderNum ?: 'N/A', $trxId, $currentSubdomainId]);

            echo json_encode([
                'status'        => 'pending_submitted',
                'auto_approved' => false,
                'message'       => 'Your order and payment TrxID have been submitted for manual verification! The license will be delivered once admin verifies your transaction.',
                'product_name'  => $productFullName,
                'amount'        => $totalCostBdt
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Order submission error: ' . $e->getMessage()]);
            exit;
        }
    }

    $generatedKeys = [];
    $cleanQty      = (int)$quantity;

    $stmtStock = $pdo->prepare("SELECT id, license_key FROM stock_keys WHERE plan_id = ? AND status = 'available' AND subdomain_id = ? ORDER BY id ASC LIMIT $cleanQty");
    $stmtStock->execute([$planId, $currentSubdomainId]);
    $stockRows = $stmtStock ? $stmtStock->fetchAll(PDO::FETCH_ASSOC) : [];

    if (count($stockRows) === $cleanQty) {
        $stockIds = array_column($stockRows, 'id');
        $inClause = implode(',', array_map('intval', $stockIds));
        $pdo->exec("UPDATE stock_keys SET status = 'used' WHERE id IN ($inClause)");
        $generatedKeys = array_column($stockRows, 'license_key');
    } else {
        $apiPlanId   = (int)($plan['api_plan_id'] ?? 0);
        $targetApiId = $apiPlanId > 0 ? $apiPlanId : $planId;

        $apiResult = generateFromExternalProvider($targetApiId, $quantity, $tenant);

        if ($apiResult['status'] === 'success' && !empty($apiResult['keys'])) {
            $generatedKeys = (array)$apiResult['keys'];
        } else {
            $amountToCreditUsd = round($verifiedAmountBdt / $effectiveBdtRate, 2);

            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ? AND subdomain_id = ?")
                    ->execute([$amountToCreditUsd, $user['id'], $currentSubdomainId]);

                $pdo->prepare("INSERT INTO transactions (user_id, method, amount, bonus_amount, sender_num, trx_id, status, subdomain_id) VALUES (?, ?, ?, 0.00, ?, ?, 'approved', ?)")
                    ->execute([$user['id'], $methodDisplayName . ' (Refund to Main Balance)', $amountToCreditUsd, $senderNum ?: 'Trx Verified', $trxId, $currentSubdomainId]);

                $pdo->commit();

                $stmtNewBal = $pdo->prepare("SELECT balance FROM users WHERE id = ? AND subdomain_id = ?");
                $stmtNewBal->execute([$user['id'], $currentSubdomainId]);
                $newBalBdt = round((float)$stmtNewBal->fetchColumn() * $effectiveBdtRate, 0);

                // Telegram alert
                sendTelegramBotNotification($pdo, $currentSubdomainId, 'failed',
                    "⚠️ <b>Delivery Failed (Out of Stock)</b>\n" .
                    "👤 User: <code>" . htmlspecialchars($user['username']) . "</code>\n" .
                    "📦 Product: " . htmlspecialchars($productFullName) . "\n" .
                    "💰 Refunded: ৳" . number_format($verifiedAmountBdt, 0) . " BDT\n" .
                    "🕐 " . date('Y-m-d H:i:s') .
                    ($tenant ? "\n🌐 Store: <code>" . htmlspecialchars($tenant['subdomain']) . "</code>" : "\n🌐 Main Store")
                );

                echo json_encode([
                    'status'            => 'out_of_stock_refunded',
                    'message'           => 'Payment verified successfully! However, this product is currently Out of Stock. Your payment of ৳' . number_format($verifiedAmountBdt, 0) . ' BDT has been safely credited to your Main Wallet Balance.',
                    'remaining_balance' => number_format($newBalBdt, 0)
                ]);
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Processing failed: ' . $e->getMessage()]);
                exit;
            }
        }
    }

    $pdo->beginTransaction();
    try {
        $amountInUsd = round($verifiedAmountBdt / $effectiveBdtRate, 2);

        $pdo->prepare("INSERT INTO transactions (user_id, method, amount, bonus_amount, sender_num, trx_id, status, subdomain_id) VALUES (?, ?, ?, 0.00, ?, ?, 'approved', ?)")
            ->execute([$user['id'], $methodDisplayName . ' (Trx Verified)', $amountInUsd, $senderNum ?: 'N/A', $trxId, $currentSubdomainId]);

        if ($totalRewardCoins > 0) {
            $pdo->prepare("UPDATE users SET coins = coins + ? WHERE id = ? AND subdomain_id = ?")
                ->execute([$totalRewardCoins, $user['id'], $currentSubdomainId]);
        }

        // Spin progress calculation (per 500 BDT)
        $spinThreshold = max(1, (float)($settings['spin_spend_per_turn'] ?? 500));
        $oldSpend = (float)($user['spin_spend_total'] ?? 0);
        $newSpend = $oldSpend + $totalCostBdt;
        $oldSpins = (int)floor($oldSpend / $spinThreshold);
        $newSpins = (int)floor($newSpend / $spinThreshold);
        $spinsEarned = max(0, $newSpins - $oldSpins);

        $pdo->prepare("UPDATE users SET spin_spend_total = spin_spend_total + ?, spin_count_available = spin_count_available + ? WHERE id = ?")
            ->execute([$totalCostBdt, $spinsEarned, $user['id']]);

        $sourceLabel = $tenant ? 'SUB:' . $tenant['subdomain'] : 'MAIN';
        $stmtInsert  = $pdo->prepare("INSERT INTO licenses (user_id, product_id, plan_id, license_key, validity, price, source, payment_type, subdomain_id) VALUES (?, ?, ?, ?, ?, ?, ?, 'direct_pay', ?)");

        foreach ($generatedKeys as $key) {
            $stmtInsert->execute([$user['id'], (int)$plan['real_product_id'], $planId, (string)$key, $productFullName, $unitPrice, $sourceLabel, $currentSubdomainId]);
        }

        // Live Purchase Feed Entry (masked)
        try {
            $pdo->prepare("INSERT INTO live_purchase_feed (subdomain_id, masked_username, product_name, amount_bdt) VALUES (?, ?, ?, ?)")
                ->execute([$currentSubdomainId, maskUsername($user['username']), $productFullName, $totalCostBdt]);
        } catch (Throwable $e) {}

        if ($vpCouponApplied) vpRecordCouponUse($pdo, $vpCouponApplied, $currentSubdomainId, (int)$user['id'], (int)$plan['real_product_id'], $vpCouponDisc, (float)$vpOrderBdtBeforeCoupon);

        $pdo->commit();

        $stmtNewBal = $pdo->prepare("SELECT balance, coins FROM users WHERE id = ? AND subdomain_id = ?");
        $stmtNewBal->execute([$user['id'], $currentSubdomainId]);
        $refreshed = $stmtNewBal->fetch(PDO::FETCH_ASSOC);

        $newBalBdt   = round((float)$refreshed['balance'] * $effectiveBdtRate, 0);
        $newCoinsVal = (int)($refreshed['coins'] ?? 0);

        sendTelegramBotNotification($pdo, $currentSubdomainId, 'purchase',
            "🛒 <b>New Purchase Completed</b>\n" .
            "👤 User: <code>" . htmlspecialchars($user['username']) . "</code>\n" .
            "📦 Product: " . htmlspecialchars($productFullName) . "\n" .
            "🔢 Quantity: " . $quantity . "\n" .
            "💰 Total: ৳" . number_format($totalCostBdt, 0) . " BDT\n" .
            "🔑 Keys Delivered: " . count($generatedKeys) . "\n" .
            "💳 Method: " . htmlspecialchars($methodDisplayName) . "\n" .
            "🕐 " . date('Y-m-d H:i:s') .
            ($tenant ? "\n🌐 Store: <code>" . htmlspecialchars($tenant['subdomain']) . "</code>" : "\n🌐 Main Store")
        );

        echo json_encode([
            'status'            => 'success',
            'message'           => 'Payment verified & License delivered successfully!',
            'keys'              => $generatedKeys,
            'product_name'      => $productFullName,
            'remaining_balance' => number_format($newBalBdt, 0),
            'remaining_coins'   => number_format($newCoinsVal),
            'reward_coins'      => $totalRewardCoins,
            'payment_type'      => 'direct_pay',
            'spins_earned'      => $spinsEarned
        ]);
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Settlement failed: ' . $e->getMessage()]);
        exit;
    }
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'verify_add_fund') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if (!$isLoggedIn || $user['id'] <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please login to deposit funds!']);
        exit;
    }

    if ($canAddFund !== 1) {
        echo json_encode(['status' => 'error', 'message' => 'Deposit system is currently closed on this store!']);
        exit;
    }

    $methodId  = (int)($_POST['method_id'] ?? 0);
    $methodKey = strtolower(trim($_POST['method_key'] ?? 'bkash'));
    $rawAmount = (float)($_POST['amount'] ?? 0);
    $trxId     = trim(htmlspecialchars(strtoupper($_POST['trx_id'] ?? '')));
    $senderNum = trim(htmlspecialchars($_POST['sender'] ?? ''));

    if ($rawAmount <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid deposit amount!']);
        exit;
    }

    if (empty($trxId) || strlen($trxId) < 4) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid Transaction ID (TrxID)!']);
        exit;
    }

    $stmtChk = $pdo->prepare("SELECT id FROM transactions WHERE UPPER(trx_id) = ? AND subdomain_id = ? LIMIT 1");
    $stmtChk->execute([$trxId, $currentSubdomainId]);
    if ($stmtChk->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'This Transaction ID (TrxID) is already recorded on this store!']);
        exit;
    }

    $methodRow = null;
    if ($methodId > 0) {
        $stmtM = $pdo->prepare("SELECT * FROM payment_methods WHERE id = ? AND subdomain_id = ? AND is_active = 1 AND allow_add_fund = 1 LIMIT 1");
        $stmtM->execute([$methodId, $currentSubdomainId]);
        $methodRow = $stmtM->fetch(PDO::FETCH_ASSOC);
    }
    if (!$methodRow) {
        $stmtM = $pdo->prepare("SELECT * FROM payment_methods WHERE LOWER(method_key) = ? AND subdomain_id = ? AND is_active = 1 AND allow_add_fund = 1 LIMIT 1");
        $stmtM->execute([$methodKey, $currentSubdomainId]);
        $methodRow = $stmtM->fetch(PDO::FETCH_ASSOC);
    }

    $methodDisplayName = $methodRow ? $methodRow['name'] : ucfirst($methodKey);

    $isCrypto = (strcasecmp($methodKey, 'binance') === 0 || strcasecmp($methodDisplayName, 'binance') === 0);
    $amountInUsd = $isCrypto
        ? round(($rawAmount * $binanceMarketRate) / $effectiveBdtRate, 2)
        : round($rawAmount / $effectiveBdtRate, 2);

    $calculatedBonus = 0.00;
    if (!empty($activeDepositBonuses)) {
        foreach ($activeDepositBonuses as $bTier) {
            if ($rawAmount >= (float)$bTier['min_deposit']) {
                if ((float)$bTier['bonus_amount'] > $calculatedBonus) {
                    $calculatedBonus = (float)$bTier['bonus_amount'];
                }
            }
        }
    }

    $isAutoPaymentEnforced = (($settings['auto_payment_on'] ?? '0') === '1') || (($settings['snzpay_auto_verify'] ?? '0') === '1');
    $autoApproved = false;

    try {
        $stmtGW = $pdo->prepare("SELECT * FROM gateway_transactions 
            WHERE UPPER(trx_id) = ? AND subdomain_id = ? AND status = 'unclaimed' 
            ORDER BY id DESC LIMIT 1");
        $stmtGW->execute([$trxId, $currentSubdomainId]);
        $gw = $stmtGW->fetch(PDO::FETCH_ASSOC);

        if ($gw) {
            $gwAmount = (float)$gw['amount'];
            if ($gwAmount >= ($rawAmount - 2.0)) {
                $autoApproved = true;
                if (!empty($gw['sender']) && empty($senderNum)) {
                    $senderNum = $gw['sender'];
                }
                $pdo->prepare("UPDATE gateway_transactions SET status = 'claimed' WHERE id = ?")->execute([$gw['id']]);
            }
        }
    } catch (Throwable $e) {}

    if (!$autoApproved && $isAutoPaymentEnforced) {
        $snzRes = verifyWithSnzPay($trxId, $methodKey, $rawAmount, $settings);
        if ($snzRes['status'] === 'success') {
            $autoApproved = true;
            if (!empty($snzRes['sender']) && empty($senderNum)) {
                $senderNum = $snzRes['sender'];
            }
        } else {
            // Telegram: failed deposit
            sendTelegramBotNotification($pdo, $currentSubdomainId, 'failed',
                "❌ <b>Deposit Failed</b>\n" .
                "👤 User: <code>" . htmlspecialchars($user['username']) . "</code>\n" .
                "💰 Amount: ৳" . number_format($rawAmount, 0) . " BDT\n" .
                "🔗 TrxID: <code>" . htmlspecialchars($trxId) . "</code>\n" .
                "⚠️ Reason: " . htmlspecialchars($snzRes['message'] ?? 'Verification failed') . "\n" .
                "🕐 " . date('Y-m-d H:i:s')
            );

            echo json_encode([
                'status'              => 'error',
                'verification_failed' => true,
                'message'             => $snzRes['message'] ?? 'Transaction ID verification failed at gateway! Please check your SMS and try again.'
            ]);
            exit;
        }
    }

    $status = $autoApproved ? 'approved' : 'pending';

    $pdo->beginTransaction();
    try {
        $bonusInUsd = $calculatedBonus > 0 ? round($calculatedBonus / $effectiveBdtRate, 2) : 0.00;
        $totalCreditUsd = $amountInUsd + $bonusInUsd;

        if ($autoApproved) {
            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ? AND subdomain_id = ?")
                ->execute([$totalCreditUsd, $user['id'], $currentSubdomainId]);
        }

        $stmtInsTrx = $pdo->prepare("INSERT INTO transactions (user_id, method, amount, bonus_amount, sender_num, trx_id, status, subdomain_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtInsTrx->execute([$user['id'], $methodDisplayName, $amountInUsd, $calculatedBonus, $senderNum ?: 'N/A', $trxId, $status, $currentSubdomainId]);

        $pdo->commit();

        $stmtNewBal = $pdo->prepare("SELECT balance FROM users WHERE id = ? AND subdomain_id = ?");
        $stmtNewBal->execute([$user['id'], $currentSubdomainId]);
        $newBalBdt = round((float)$stmtNewBal->fetchColumn() * $effectiveBdtRate, 0);

        // Telegram: deposit notification
        sendTelegramBotNotification($pdo, $currentSubdomainId, 'deposit',
            ($autoApproved ? "💰 <b>New Deposit (Auto-Verified)</b>\n" : "🕐 <b>New Deposit (Pending)</b>\n") .
            "👤 User: <code>" . htmlspecialchars($user['username']) . "</code>\n" .
            "💵 Amount: ৳" . number_format($rawAmount, 0) . " BDT\n" .
            ($calculatedBonus > 0 ? "🎁 Bonus: ৳" . number_format($calculatedBonus, 0) . " BDT\n" : "") .
            "💳 Method: " . htmlspecialchars($methodDisplayName) . "\n" .
            "🔗 TrxID: <code>" . htmlspecialchars($trxId) . "</code>\n" .
            "📊 Status: " . strtoupper($status) . "\n" .
            "🕐 " . date('Y-m-d H:i:s') .
            ($tenant ? "\n🌐 Store: <code>" . htmlspecialchars($tenant['subdomain']) . "</code>" : "\n🌐 Main Store")
        );

        if ($autoApproved) {
            echo json_encode([
                'status'            => 'success',
                'auto_approved'     => true,
                'message'           => 'Deposit verified & added to wallet instantly! Total: ৳' . number_format($rawAmount + $calculatedBonus, 0) . ' BDT',
                'amount'            => $rawAmount,
                'bonus'             => $calculatedBonus,
                'remaining_balance' => number_format($newBalBdt, 0)
            ]);
        } else {
            echo json_encode([
                'status'            => 'pending_submitted',
                'auto_approved'     => false,
                'message'           => 'Deposit request submitted successfully! Funds will be credited to your wallet once admin verifies the transaction.',
                'amount'            => $rawAmount,
                'bonus'             => $calculatedBonus,
                'remaining_balance' => number_format($newBalBdt, 0)
            ]);
        }
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Deposit submission error: ' . $e->getMessage()]);
        exit;
    }
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'live_generate') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if (!$isLoggedIn || $user['id'] <= 0) {
        echo json_encode(['status' => 'error', 'require_login' => true, 'message' => 'Please login or register to complete purchase!']);
        exit;
    }

    if ($isSiteMaintenance) {
        echo json_encode(['status' => 'error', 'message' => 'Store is currently under maintenance. Orders are temporarily paused.']);
        exit;
    }

    $planId      = (int)($_POST['plan_id'] ?? 0);
    $quantity    = max(1, min(50, (int)($_POST['quantity'] ?? 1)));
    $paymentType = trim($_POST['payment_type'] ?? 'balance');

    if ($planId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a valid plan!']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT p.id as real_product_id, p.name as product_name, p.is_active, 
                                  COALESCE(NULLIF(pl.plan_title, ''), NULLIF(pl.validity, ''), 'Standard Plan') as plan_title,
                                  pl.reseller_price, pl.user_price, pl.price,
                                  pl.reseller_discount_percent, COALESCE(pl.discount_percent, 0) as discount_percent,
                                  pl.api_plan_id, pl.reward_coins, pl.coin_price 
                           FROM product_plans pl 
                           JOIN products p ON pl.product_id = p.id 
                           WHERE pl.id = ? 
                             AND p.subdomain_id = ?
                             AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                           LIMIT 1");
    $stmt->execute([$planId, $currentSubdomainId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan || (int)($plan['is_active'] ?? 1) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'This product plan is currently unavailable!']);
        exit;
    }

    if ($userRole === 'reseller' && !empty($userAllowedProducts) && !in_array((int)$plan['real_product_id'], array_map('intval', $userAllowedProducts))) {
        echo json_encode(['status' => 'error', 'message' => 'Access Restricted: You do not have permission to purchase this product.']);
        exit;
    }

    $productFullName    = $plan['product_name'] . ' (' . $plan['plan_title'] . ')';
    $rewardCoinsPerItem = max(0, (int)($plan['reward_coins'] ?? 0));
    $coinPricePerItem   = max(0, (int)($plan['coin_price'] ?? 0));
    $totalRewardCoins   = $rewardCoinsPerItem * $quantity;

    $rawRetailPrice = (float)(($plan['user_price'] ?? 0) ?: (($plan['price'] ?? 0) ?: ($plan['reseller_price'] ?? 0)));
    $rawResellPrice = (float)(($plan['reseller_price'] ?? 0) ?: (($plan['price'] ?? 0) ?: ($plan['user_price'] ?? 0)));
    $basePrice = ($userRole === 'reseller') ? ($rawResellPrice > 0 ? $rawResellPrice : $rawRetailPrice) : ($rawRetailPrice > 0 ? $rawRetailPrice : $rawResellPrice);

    $unitPrice = null;
    if (isset($userCustomPrices[$planId]) && (float)$userCustomPrices[$planId] > 0) {
        $unitPrice = (float)$userCustomPrices[$planId];
    } else {
        $explicitDiscount = ($userRole === 'reseller')
            ? (float)($plan['reseller_discount_percent'] ?? 0)
            : (float)($plan['discount_percent'] ?? 0);
        if ($explicitDiscount >= 0) {
            $unitPrice = round($basePrice * (1 - ($explicitDiscount / 100)), 2);
        } else {
            $unitPrice = $basePrice;
        }
    }

    $totalCost = $unitPrice * $quantity;

    // 🎟️ কুপন ডিসকাউন্ট (BDT তে হিসাব, তারপর USD তে রূপান্তর — আগে BDT ছাড় সরাসরি USD থেকে বিয়োগ হত)
    $vpCouponApplied = null; $vpCouponDisc = 0.0; $vpOrderBdtBeforeCoupon = round($totalCost * $effectiveBdtRate, 0);
    $appliedCoupon = trim($_POST['coupon_code'] ?? '');
    if ($appliedCoupon !== '' && $paymentType !== 'coins') {
        $cv = vpValidateCoupon($pdo, $appliedCoupon, $currentSubdomainId, (int)$user['id'], $userRole, (int)$plan['real_product_id'], (float)$vpOrderBdtBeforeCoupon);
        if (!$cv['ok']) { echo json_encode(['status' => 'error', 'message' => '🎟️ ' . $cv['message']]); exit; }
        $vpCouponApplied = $cv['coupon']; $vpCouponDisc = (float)$cv['discount_bdt'];
        $totalCost = max(0.01, round(($vpOrderBdtBeforeCoupon - $vpCouponDisc) / max(1.0, $effectiveBdtRate), 4));
        $unitPrice = $totalCost / max(1, $quantity);
    }

    $directMethod = '';
    $directSender = '';
    $directTrxId  = '';
    $amountInUsd  = 0.00;

    if ($paymentType === 'direct_deposit') {
        $directMethod = htmlspecialchars($_POST['direct_method'] ?? 'Binance');
        $directSender = htmlspecialchars(trim($_POST['direct_sender'] ?? ''));
        $directTrxId  = trim(htmlspecialchars(strtoupper($_POST['direct_trx_id'] ?? '')));
        $rawAmount    = (float)($_POST['direct_amount'] ?? 0);

        if ($rawAmount <= 0 || empty($directTrxId)) {
            echo json_encode(['status' => 'error', 'message' => 'Please provide valid Amount and TrxID / Order ID!']);
            exit;
        }

        $stmtChkTrx = $pdo->prepare("SELECT id FROM transactions WHERE UPPER(trx_id) = ? AND subdomain_id = ? LIMIT 1");
        $stmtChkTrx->execute([$directTrxId, $currentSubdomainId]);
        if ($stmtChkTrx->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'This Transaction ID is already recorded on this store!']);
            exit;
        }

        $amountInUsd = ($directMethod === 'Binance') 
            ? round(($rawAmount * $binanceMarketRate) / $effectiveBdtRate, 2)
            : round($rawAmount / $effectiveBdtRate, 2);
    } elseif ($paymentType === 'coins') {
        if ($coinPricePerItem <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'This plan cannot be purchased with coins!']);
            exit;
        }

        $totalCoinsNeeded = $coinPricePerItem * $quantity;
        $stmtUserCoins = $pdo->prepare("SELECT coins FROM users WHERE id = ? AND subdomain_id = ? LIMIT 1");
        $stmtUserCoins->execute([$user['id'], $currentSubdomainId]);
        $currentCoins = (int)$stmtUserCoins->fetchColumn();

        if ($currentCoins < $totalCoinsNeeded) {
            echo json_encode([
                'status'  => 'error', 
                'message' => 'Insufficient coins! Required: 🪙 ' . number_format($totalCoinsNeeded) . ' Coins | Your balance: 🪙 ' . number_format($currentCoins)
            ]);
            exit;
        }
    } else {
        $stmtBal = $pdo->prepare("SELECT balance FROM users WHERE id = ? AND subdomain_id = ? LIMIT 1");
        $stmtBal->execute([$user['id'], $currentSubdomainId]);
        $currentBalance = (float)$stmtBal->fetchColumn();

        if ($currentBalance < $totalCost) {
            $costBdt   = round($totalCost * $effectiveBdtRate, 0);
            $walletBdt = round($currentBalance * $effectiveBdtRate, 0);
            echo json_encode([
                'status'  => 'error', 
                'insufficient_balance' => true,
                'message' => 'Insufficient wallet funds! Required: ৳' . number_format($costBdt, 0) . ' BDT | Wallet: ৳' . number_format($walletBdt, 0) . ' BDT. Please Add Fund or use Direct Payment.'
            ]);
            exit;
        }
    }

    $generatedKeys = [];
    $cleanQty      = (int)$quantity;

    $stmtStock = $pdo->prepare("SELECT id, license_key FROM stock_keys WHERE plan_id = ? AND status = 'available' AND subdomain_id = ? ORDER BY id ASC LIMIT $cleanQty");
    $stmtStock->execute([$planId, $currentSubdomainId]);
    $stockRows = $stmtStock ? $stmtStock->fetchAll(PDO::FETCH_ASSOC) : [];

    if (count($stockRows) === $cleanQty) {
        $stockIds = array_column($stockRows, 'id');
        $inClause = implode(',', array_map('intval', $stockIds));
        $pdo->exec("UPDATE stock_keys SET status = 'used' WHERE id IN ($inClause)");
        $generatedKeys = array_column($stockRows, 'license_key');
    } else {
        $apiPlanId   = (int)($plan['api_plan_id'] ?? 0);
        $targetApiId = $apiPlanId > 0 ? $apiPlanId : $planId;

        $apiResult = generateFromExternalProvider($targetApiId, $quantity, $tenant);

        if ($apiResult['status'] === 'success' && !empty($apiResult['keys'])) {
            $generatedKeys = (array)$apiResult['keys'];
        } else {
            if ($paymentType === 'direct_deposit') {
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ? AND subdomain_id = ?")
                    ->execute([$amountInUsd, $user['id'], $currentSubdomainId]);

                $pdo->prepare("INSERT INTO transactions (user_id, method, amount, bonus_amount, sender_num, trx_id, status, subdomain_id) VALUES (?, ?, ?, 0.00, ?, ?, 'approved', ?)")
                    ->execute([$user['id'], $directMethod . ' (Refund to Wallet)', $amountInUsd, $directSender, $directTrxId, $currentSubdomainId]);

                $stmtNewBal = $pdo->prepare("SELECT balance FROM users WHERE id = ? AND subdomain_id = ?");
                $stmtNewBal->execute([$user['id'], $currentSubdomainId]);
                $newBalBdt = round((float)$stmtNewBal->fetchColumn() * $effectiveBdtRate, 0);

                sendTelegramBotNotification($pdo, $currentSubdomainId, 'failed',
                    "⚠️ <b>Delivery Failed (USDT)</b>\n" .
                    "👤 User: <code>" . htmlspecialchars($user['username']) . "</code>\n" .
                    "📦 Product: " . htmlspecialchars($productFullName) . "\n" .
                    "💰 Refunded: ৳" . number_format($rawAmount, 0) . " BDT\n" .
                    "🕐 " . date('Y-m-d H:i:s')
                );

                echo json_encode([
                    'status'            => 'out_of_stock_refunded',
                    'message'           => 'Product is currently Out of Stock! Your payment of ৳' . number_format($rawAmount, 0) . ' BDT has been safely credited to your Main Wallet Balance.',
                    'remaining_balance' => number_format($newBalBdt, 0)
                ]);
                exit;
            } else {
                echo json_encode([
                    'status'  => 'error', 
                    'message' => 'Out of stock! This product is currently unavailable. No balance was deducted.'
                ]);
                exit;
            }
        }
    }

    $pdo->beginTransaction();
    try {
        if ($paymentType === 'direct_deposit') {
            $pdo->prepare("INSERT INTO transactions (user_id, method, amount, bonus_amount, sender_num, trx_id, status, subdomain_id) VALUES (?, ?, ?, 0.00, ?, ?, 'approved', ?)")
                ->execute([$user['id'], $directMethod . ' (Direct Live Pay)', $amountInUsd, $directSender, $directTrxId, $currentSubdomainId]);

            if ($totalRewardCoins > 0) {
                $pdo->prepare("UPDATE users SET coins = coins + ? WHERE id = ? AND subdomain_id = ?")
                    ->execute([$totalRewardCoins, $user['id'], $currentSubdomainId]);
            }
        } elseif ($paymentType === 'coins') {
            $stmtDeductCoins = $pdo->prepare("UPDATE users SET coins = coins - ? WHERE id = ? AND subdomain_id = ? AND coins >= ?");
            $stmtDeductCoins->execute([$totalCoinsNeeded, $user['id'], $currentSubdomainId, $totalCoinsNeeded]);

            if ($stmtDeductCoins->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Insufficient coins during checkout!']);
                exit;
            }
        } else {
            $stmtDeduct = $pdo->prepare("UPDATE users SET balance = balance - ?, coins = coins + ? WHERE id = ? AND subdomain_id = ? AND balance >= ?");
            $stmtDeduct->execute([$totalCost, $totalRewardCoins, $user['id'], $currentSubdomainId, $totalCost]);

            if ($stmtDeduct->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Insufficient balance during checkout!']);
                exit;
            }
        }

        // Spin progress increment
        $bdtSpent = ($paymentType === 'direct_deposit') ? $rawAmount : round($totalCost * $effectiveBdtRate, 0);
        $spinThreshold = max(1, (float)($settings['spin_spend_per_turn'] ?? 500));
        $oldSpend = (float)($user['spin_spend_total'] ?? 0);
        $newSpend = $oldSpend + $bdtSpent;
        $oldSpins = (int)floor($oldSpend / $spinThreshold);
        $newSpins = (int)floor($newSpend / $spinThreshold);
        $spinsEarned = max(0, $newSpins - $oldSpins);

        $pdo->prepare("UPDATE users SET spin_spend_total = spin_spend_total + ?, spin_count_available = spin_count_available + ? WHERE id = ?")
            ->execute([$bdtSpent, $spinsEarned, $user['id']]);

        $sourceLabel = $tenant ? 'SUB:' . $tenant['subdomain'] : 'MAIN';
        $payLabel    = ($paymentType === 'coins') ? 'coins' : (($paymentType === 'direct_deposit') ? 'direct_pay' : 'balance');
        $priceLogged = ($paymentType === 'coins') ? 0.00 : $unitPrice;

        $stmtInsert  = $pdo->prepare("INSERT INTO licenses (user_id, product_id, plan_id, license_key, validity, price, source, payment_type, subdomain_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($generatedKeys as $key) {
            $stmtInsert->execute([$user['id'], (int)$plan['real_product_id'], $planId, (string)$key, $productFullName, $priceLogged, $sourceLabel, $payLabel, $currentSubdomainId]);
        }

        // Live Purchase Feed
        try {
            $pdo->prepare("INSERT INTO live_purchase_feed (subdomain_id, masked_username, product_name, amount_bdt) VALUES (?, ?, ?, ?)")
                ->execute([$currentSubdomainId, maskUsername($user['username']), $productFullName, $bdtSpent]);
        } catch (Throwable $e) {}

        if ($vpCouponApplied) vpRecordCouponUse($pdo, $vpCouponApplied, $currentSubdomainId, (int)$user['id'], (int)$plan['real_product_id'], $vpCouponDisc, (float)$vpOrderBdtBeforeCoupon);

        $pdo->commit();

        $stmtNewBal = $pdo->prepare("SELECT balance, coins FROM users WHERE id = ? AND subdomain_id = ?");
        $stmtNewBal->execute([$user['id'], $currentSubdomainId]);
        $refreshed = $stmtNewBal->fetch(PDO::FETCH_ASSOC);

        $newBalBdt   = round((float)$refreshed['balance'] * $effectiveBdtRate, 0);
        $newCoinsVal = (int)($refreshed['coins'] ?? 0);

        sendTelegramBotNotification($pdo, $currentSubdomainId, 'purchase',
            "🛒 <b>New Purchase Completed</b>\n" .
            "👤 User: <code>" . htmlspecialchars($user['username']) . "</code>\n" .
            "📦 Product: " . htmlspecialchars($productFullName) . "\n" .
            "🔢 Quantity: " . $quantity . "\n" .
            "💰 Total: ৳" . number_format($bdtSpent, 0) . " BDT\n" .
            "💳 Pay Method: " . htmlspecialchars($payLabel) . "\n" .
            "🔑 Keys Delivered: " . count($generatedKeys) . "\n" .
            "🕐 " . date('Y-m-d H:i:s') .
            ($tenant ? "\n🌐 Store: <code>" . htmlspecialchars($tenant['subdomain']) . "</code>" : "\n🌐 Main Store")
        );

        echo json_encode([
            'status'            => 'success',
            'message'           => 'License generated successfully!',
            'keys'              => $generatedKeys,
            'product_name'      => $productFullName,
            'remaining_balance' => number_format($newBalBdt, 0),
            'remaining_coins'   => number_format($newCoinsVal),
            'reward_coins'      => $totalRewardCoins,
            'payment_type'      => $paymentType,
            'spins_earned'      => $spinsEarned
        ]);
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Order failed: ' . $e->getMessage()]);
        exit;
    }
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'reset_key') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if (!$isLoggedIn || $user['id'] <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please login to reset your key!']);
        exit;
    }

    $rawKey = trim($_POST['license_key'] ?? '');
    if (empty($rawKey)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid license key!']);
        exit;
    }

    // ── Key ownership check ──
    // (আগে p.km_api_id কলাম না থাকায় এই কুয়েরি ক্র্যাশ করত — রিসেট একদম কাজ করত না)
    $keyRow = false;
    try {
        $stmtKey = $pdo->prepare("SELECT l.*, COALESCE(p.km_api_id, 0) as api_id, COALESCE(p.reset_enabled, 1) as reset_enabled, p.name as product_name
                               FROM licenses l
                               LEFT JOIN products p ON p.id = l.product_id
                               WHERE l.user_id = ? AND l.license_key = ? AND l.subdomain_id = ? LIMIT 1");
        $stmtKey->execute([$user['id'], $rawKey, $currentSubdomainId]);
        $keyRow = $stmtKey->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $stmtKey = $pdo->prepare("SELECT l.*, 0 as api_id, 1 as reset_enabled FROM licenses l WHERE l.user_id = ? AND l.license_key = ? AND l.subdomain_id = ? LIMIT 1");
        $stmtKey->execute([$user['id'], $rawKey, $currentSubdomainId]);
        $keyRow = $stmtKey->fetch(PDO::FETCH_ASSOC);
    }

    if (!$keyRow) {
        echo json_encode(['status' => 'error', 'message' => 'License key not found in your account on this store!']);
        exit;
    }

    if ((int)($keyRow['reset_enabled'] ?? 1) !== 1) {
        echo json_encode(['status' => 'error', 'message' => '❌ ' . (($keyRow['product_name'] ?? '') ?: 'This product') . ' does not support HWID reset.']);
        exit;
    }

    // ── API Config ──
    $KM_API_KEY   = 'keymanager_live_0f18492c550d2e3a350c821604de9e7a';
    $KM_BASE_URL  = 'https://keymanager.shop/api.php';
    $PINXT_TOKEN  = 'Pinxt_X7mK9pR2vT5nL8qY3wZ6';

    $apiId = (int)($keyRow['api_id'] ?? 0);

    // Fallback: api_id না থাকলে settings থেকে নাও (পুরনো system compatibility)
    if ($apiId <= 0) {
        $kmUrl = trim($settings['km_api_url'] ?? '');
        $kmKey = trim($settings['km_api_key'] ?? '');

        if (!empty($kmUrl) && !empty($kmKey)) {
            $targetEndpoint = rtrim($kmUrl, '/');
            if (stripos($targetEndpoint, '.php') === false) {
                $targetEndpoint .= '/api.php';
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $targetEndpoint,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'action'      => 'reset_key',
                    'license_key' => $rawKey,
                    'key'         => $rawKey,
                    'api_key'     => $kmKey,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $resData = json_decode($response ?: '', true);
            if ($resData && (($resData['status'] ?? '') === 'success' || ($resData['success'] ?? false) === true)) {
                try { $pdo->prepare("UPDATE licenses SET updated_at = NOW() WHERE id = ?")->execute([$keyRow['id']]); } catch (Throwable $e) {}
                echo json_encode(['status' => 'success', 'message' => 'Hardware ID successfully reset! You can now bind it to a new device.']);
                exit;
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'Reset failed. API not configured for this product. Contact support.']);
        exit;
    }

    // ── Build GET params ──
    $params = [
        'action'  => 'reset_key',
        'api_id'  => $apiId,
        'key'     => $rawKey,
        'api_key' => $KM_API_KEY,
    ];

    // Angry Mod (Pinxt) — api_id=99 needs extra token
    if ($apiId === 99) {
        $params['token'] = $PINXT_TOKEN;
    }

    $finalUrl = $KM_BASE_URL . '?' . http_build_query($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $finalUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || empty($response)) {
        echo json_encode(['status' => 'error', 'message' => 'Reset API unreachable. Please try again or contact support.']);
        exit;
    }

    $resData = json_decode($response, true);

    $isSuccess = (
        ($resData['status']  ?? '') === 'success'  ||
        ($resData['success'] ?? false) === true     ||
        ($resData['message'] ?? '') === 'Key reset successfully' ||
        (isset($resData['code']) && $resData['code'] === 200)
    );

    if ($isSuccess) {
        try { $pdo->prepare("UPDATE licenses SET updated_at = NOW() WHERE id = ?")->execute([$keyRow['id']]); } catch (Throwable $e) {}
        echo json_encode(['status' => 'success', 'message' => 'Hardware ID successfully reset! You can now bind it to a new device.']);
        exit;
    }

    // API returned error — forward the message
    $errMsg = $resData['message'] ?? $resData['error'] ?? 'Reset failed. Please contact support.';
    echo json_encode(['status' => 'error', 'message' => $errMsg]);
    exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_spin_data') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if (!$isSpinWheelActive) {
        echo json_encode(['status' => 'error', 'message' => 'Spin Wheel is not available on this store.']);
        exit;
    }

    try {
        $segments = [];
        if ($tenant) {
            $stmtSeg = $pdo->prepare("SELECT id, title, bonus_amount, spend_target, probability, color 
                                      FROM subdomain_spin_segments 
                                      WHERE subdomain_id = ? AND is_active = 1 
                                      ORDER BY sort_order ASC, id ASC");
            $stmtSeg->execute([$currentSubdomainId]);
            $segments = $stmtSeg->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $segments = $pdo->query("SELECT id, title, bonus_amount, spend_target, probability, color 
                                     FROM spin_segments 
                                     WHERE (subdomain_id = 0 OR subdomain_id IS NULL) AND is_active = 1 
                                     ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($segments)) {
            $segments = [
                ['id' => 1, 'title' => '৳50 Bonus', 'bonus_amount' => 50, 'spend_target' => 500, 'probability' => 25, 'color' => '#f59e0b'],
                ['id' => 2, 'title' => 'Try Again', 'bonus_amount' => 0,  'spend_target' => 0,   'probability' => 30, 'color' => '#1e293b'],
                ['id' => 3, 'title' => '৳100 Bonus', 'bonus_amount' => 100, 'spend_target' => 1000, 'probability' => 15, 'color' => '#10b981'],
                ['id' => 4, 'title' => '৳30 Bonus', 'bonus_amount' => 30, 'spend_target' => 300, 'probability' => 20, 'color' => '#8b5cf6'],
                ['id' => 5, 'title' => '৳200 Bonus', 'bonus_amount' => 200, 'spend_target' => 2000, 'probability' => 10, 'color' => '#06b6d4'],
            ];
        }

        $spinThreshold = max(1, (float)($settings['spin_spend_per_turn'] ?? 500));
        $spinSpend = (float)($user['spin_spend_total'] ?? 0);
        $availableSpins = (int)($user['spin_count_available'] ?? 0);
        $progressInCycle = fmod($spinSpend, $spinThreshold);
        $progressPercent = min(100, ($progressInCycle / $spinThreshold) * 100);

        // Leaderboard (top 3 buyers)
        $topBuyers = [];
        try {
            $stmtLB = $pdo->prepare("SELECT u.username, SUM(l.price * ?) as spent_bdt
                FROM licenses l JOIN users u ON l.user_id = u.id 
                WHERE l.subdomain_id = ? GROUP BY u.id ORDER BY spent_bdt DESC LIMIT 3");
            $stmtLB->execute([$effectiveBdtRate, $currentSubdomainId]);
            $topBuyers = $stmtLB->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        echo json_encode([
            'status'            => 'success',
            'segments'          => $segments,
            'remaining_spins'   => $availableSpins,
            'spin_spend_total'  => $spinSpend,
            'spin_threshold'    => $spinThreshold,
            'progress_percent'  => round($progressPercent, 1),
            'leaderboard'       => $topBuyers,
            'today_winners'     => '3,149',
            'active_viewers'    => rand(35, 48)
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'spin_wheel') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if (!$isLoggedIn) {
        echo json_encode(['status' => 'error', 'require_login' => true, 'message' => 'Please login or register to spin!']);
        exit;
    }

    if (!$isSpinWheelActive) {
        echo json_encode(['status' => 'error', 'message' => 'Spin Wheel is disabled on this store!']);
        exit;
    }

    $availableSpins = (int)($user['spin_count_available'] ?? 0);
    if ($availableSpins <= 0) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'No spins available! Spend ৳500 more to unlock a spin.'
        ]);
        exit;
    }

    $segments = [];
    if ($tenant) {
        $stmtSeg = $pdo->prepare("SELECT * FROM subdomain_spin_segments WHERE subdomain_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC");
        $stmtSeg->execute([$currentSubdomainId]);
        $segments = $stmtSeg->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtMainSeg = $pdo->prepare("SELECT * FROM spin_segments WHERE (subdomain_id = 0 OR subdomain_id IS NULL) AND is_active = 1 ORDER BY sort_order ASC, id ASC");
        $stmtMainSeg->execute();
        $segments = $stmtMainSeg->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($segments)) {
        $segments = [
            ['id' => 1, 'title' => '৳50 Bonus', 'bonus_amount' => 50, 'spend_target' => 500, 'probability' => 25, 'color' => '#f59e0b'],
            ['id' => 2, 'title' => 'Try Again', 'bonus_amount' => 0,  'spend_target' => 0,   'probability' => 30, 'color' => '#1e293b'],
            ['id' => 3, 'title' => '৳100 Bonus', 'bonus_amount' => 100, 'spend_target' => 1000, 'probability' => 15, 'color' => '#10b981'],
            ['id' => 4, 'title' => '৳30 Bonus', 'bonus_amount' => 30, 'spend_target' => 300, 'probability' => 20, 'color' => '#8b5cf6'],
            ['id' => 5, 'title' => '৳200 Bonus', 'bonus_amount' => 200, 'spend_target' => 2000, 'probability' => 10, 'color' => '#06b6d4'],
        ];
    }

    $totalWeight = 0;
    foreach ($segments as $seg) {
        $totalWeight += max(1, (int)($seg['probability'] ?? 10));
    }

    $randWeight = mt_rand(1, $totalWeight);
    $currentAcc = 0;
    $wonSegment = $segments[0];
    $wonIndex   = 0;

    foreach ($segments as $idx => $seg) {
        $currentAcc += max(1, (int)($seg['probability'] ?? 10));
        if ($randWeight <= $currentAcc) {
            $wonSegment = $seg;
            $wonIndex   = $idx;
            break;
        }
    }

    $spendTarget = (float)($wonSegment['spend_target'] ?? 0);
    $bonusAmt    = (float)($wonSegment['bonus_amount'] ?? 0);
    $hours       = max(1, (int)($settings['spin_offer_duration_hours'] ?? 24));
    $isWin       = (strpos(strtolower($wonSegment['title']), 'again') === false && $bonusAmt > 0);

    $pdo->prepare("UPDATE users SET spin_count_available = GREATEST(0, spin_count_available - 1) WHERE id = ?")
        ->execute([$user['id']]);

    $bengaliNotice = '';
    if ($isWin) {
        if ($spendTarget > 0) {
            $bengaliNotice = "আমাদের শপ থেকে ৳" . number_format($spendTarget, 0) . " টাকার প্রোডাক্ট কিনলে সাথে সাথে ৳" . number_format($bonusAmt, 0) . " টাকা ইনস্ট্যান্ট বোনাস পাবেন!";
        } else {
            $bengaliNotice = "অভিনন্দন! আপনি জিতে নিয়েছেন ৳" . number_format($bonusAmt, 0) . " টাকা ইনস্ট্যান্ট ক্যাশ রিওয়ার্ড!";
        }

        try {
            $pdo->prepare("INSERT INTO user_spin_offers (user_id, segment_id, title, spend_target, bonus_amount, status, expires_at, created_at, subdomain_id) 
                           VALUES (?, ?, ?, ?, ?, 'active', DATE_ADD(NOW(), INTERVAL ? HOUR), NOW(), ?)")
                ->execute([$user['id'], $wonSegment['id'] ?? 0, $wonSegment['title'], $spendTarget, $bonusAmt, $hours, $currentSubdomainId]);
        } catch (Throwable $e) {}
    } else {
        $bengaliNotice = "মন খারাপ করবেন না! আবার চেষ্টা করুন!";
    }

    echo json_encode([
        'status'              => 'success',
        'winning_index'       => $wonIndex,
        'title'               => $wonSegment['title'],
        'spend_target'        => $spendTarget,
        'bonus_amount'        => $bonusAmt,
        'bengali_offer_text'  => $bengaliNotice,
        'is_win'              => $isWin,
        'remaining_spins'     => max(0, $availableSpins - 1)
    ]);
    exit;
}

// --- PROFILE PHOTO QUICK UPLOAD (AJAX) — আগে এই হ্যান্ডলার ছিল না, তাই ছবি সেভ হতো না ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_avatar_only') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if (!$isLoggedIn) {
        echo json_encode(['status' => 'error', 'message' => 'Please login first!']);
        exit;
    }
    if (empty($_FILES['avatar_file']['name']) || $_FILES['avatar_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'No image received!']);
        exit;
    }
    $ext = strtolower(pathinfo($_FILES['avatar_file']['name'], PATHINFO_EXTENSION));
    $isImage = @getimagesize($_FILES['avatar_file']['tmp_name']) !== false;
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !$isImage) {
        echo json_encode(['status' => 'error', 'message' => 'Only JPG, PNG or WEBP images allowed!']);
        exit;
    }
    if ((int)$_FILES['avatar_file']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['status' => 'error', 'message' => 'Image too large (max 5MB)!']);
        exit;
    }
    $avatarDir = __DIR__ . '/uploads/avatars/';
    if (!is_dir($avatarDir)) @mkdir($avatarDir, 0755, true);
    $avatarFileName = 'avatar_' . (int)$user['id'] . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['avatar_file']['tmp_name'], $avatarDir . $avatarFileName)) {
        echo json_encode(['status' => 'error', 'message' => 'Upload failed on server!']);
        exit;
    }
    $savedAvatarPath = 'uploads/avatars/' . $avatarFileName;
    try {
        $pdo->prepare("UPDATE users SET avatar_url = ? WHERE id = ? AND subdomain_id = ?")->execute([$savedAvatarPath, (int)$user['id'], $currentSubdomainId]);
        echo json_encode(['status' => 'success', 'avatar_url' => $savedAvatarPath]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Could not save photo!']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!$isLoggedIn) {
        $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Please login to perform this action!'];
        header('Location: index.php');
        exit;
    }

    if ($_POST['action'] === 'update_profile') {
        $_SESSION['active_page'] = 'profile';
        $newUsername = trim($_POST['username'] ?? '');
        $newEmail    = strtolower(trim($_POST['email'] ?? ''));
        $newTg       = trim(str_replace('@', '', $_POST['telegram_username'] ?? ''));
        $currPass    = $_POST['current_password'] ?? '';
        $newPass     = $_POST['new_password'] ?? '';

        // ডাটাবেজের কলাম LONGTEXT করা যাতে হাই-কোয়ালিটি ছবি সুন্দরভাবে সেভ হতে পারে
        try { $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `avatar_url` LONGTEXT NULL"); } catch (Throwable $e) {}

        // নতুন পাসওয়ার্ড না দিলে শুধু ছবি/প্রোফাইল আপডেটে কারেন্ট পাসওয়ার্ড বাধ্যতামূলক হবে না
        $canUpdate = true;
        if (!empty($newPass)) {
            if (!password_verify($currPass, $user['password']) && $currPass !== $user['password']) {
                $canUpdate = false;
                $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Current password incorrect to set new password!'];
            }
        }

        if ($canUpdate) {
            $updateSql = "UPDATE users SET username = ?, email = ?, telegram_username = ?";
            $params    = [$newUsername ?: $user['username'], $newEmail ?: null, $newTg ?: null];

            // সরাসরি ফাইল আপলোড করে ডিস্কে সেভ করা (স্থায়ী পিকচার ফিক্স)
        if (!empty($_FILES['avatar_file']['name']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['avatar_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $avatarDir = __DIR__ . '/uploads/avatars/';
                if (!is_dir($avatarDir)) @mkdir($avatarDir, 0755, true);
                
                $avatarFileName = 'avatar_' . $user['id'] . '_' . time() . '.' . $ext;
                $targetFilePath = $avatarDir . $avatarFileName;
                
                if (move_uploaded_file($_FILES['avatar_file']['tmp_name'], $targetFilePath)) {
                    $savedAvatarPath = 'uploads/avatars/' . $avatarFileName;
                    $updateSql .= ", avatar_url = ?";
                    $params[]   = $savedAvatarPath;
                    $user['avatar_url'] = $savedAvatarPath; // রিয়েলটাইম আপডেট
                }
            }
        }

            if (!empty($newPass) && strlen($newPass) >= 6) {
                $updateSql .= ", password = ?";
                $params[]   = password_hash($newPass, PASSWORD_DEFAULT);
            }

            $updateSql .= " WHERE id = ?";
            $params[]   = $user['id'];

            try {
                $pdo->prepare($updateSql)->execute($params);
                if (!empty($newUsername)) $_SESSION['auth_username'] = $newUsername;
                $_SESSION['flash_toast'] = ['type' => 'success', 'msg' => 'Profile photo & details updated!'];
            } catch (Throwable $e) {
                $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Update failed: ' . $e->getMessage()];
            }
        }

        header('Location: index.php');
        exit;
    }
}

// ====================================================================
// 10. CATALOG, DYNAMIC PAYMENT METHODS, CATEGORIES & BANNERS
// ====================================================================
$activeBanners = [];
try {
    if ($tenant) {
        $stmtB = $pdo->prepare("SELECT * FROM banners 
            WHERE subdomain_id = ? 
              AND (is_active = 1 OR is_active = '1' OR is_active IS NULL OR status = 'active' OR status = '1' OR status IS NULL) 
            ORDER BY sort_order ASC, id DESC");
        $stmtB->execute([$currentSubdomainId]);
        $activeBanners = $stmtB->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtB = $pdo->query("SELECT * FROM banners 
            WHERE (subdomain_id = 0 OR subdomain_id IS NULL OR subdomain_id = '' OR subdomain_id = '0') 
              AND (is_active = 1 OR is_active = '1' OR is_active IS NULL OR status = 'active' OR status = '1' OR status IS NULL) 
            ORDER BY sort_order ASC, id DESC");
        $activeBanners = ($stmtB && is_object($stmtB)) ? $stmtB->fetchAll(PDO::FETCH_ASSOC) : [];
    }
} catch (Throwable $e) {
    $activeBanners = [];
}

if (empty($activeBanners)) {
    $activeBanners = [
        [
            'id'          => 1,
            'image_url'   => !empty($globalPaymentBanner) ? $globalPaymentBanner : 'https://images.unsplash.com/photo-1542751371-adc38448a05e?w=1200&q=80',
            'target_link' => '#',
            'title'       => 'Fastest Digital Licenses & Game Passes'
        ],
        [
            'id'          => 2,
            'image_url'   => 'https://images.unsplash.com/photo-1511512578047-dfb367046420?w=1200&q=80',
            'target_link' => '#',
            'title'       => 'Instant Verification & Automated Delivery'
        ]
    ];
}

$activePaymentMethods = [];
try {
    if ($tenant) {
        $stmtPM = $pdo->prepare("SELECT * FROM payment_methods 
            WHERE subdomain_id = ? AND is_active = 1 
            ORDER BY sort_order ASC, id ASC");
        $stmtPM->execute([$currentSubdomainId]);
        $activePaymentMethods = $stmtPM->fetchAll(PDO::FETCH_ASSOC);

        if (empty($activePaymentMethods)) {
            $stmtPM_def = $pdo->prepare("SELECT * FROM payment_methods WHERE subdomain_id = 0 AND is_active = 1 ORDER BY sort_order ASC, id ASC");
            $stmtPM_def->execute();
            $activePaymentMethods = $stmtPM_def->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $stmtPM = $pdo->query("SELECT * FROM payment_methods 
            WHERE (subdomain_id = 0 OR subdomain_id IS NULL OR subdomain_id = '' OR subdomain_id = '0') AND is_active = 1 
            ORDER BY sort_order ASC, id ASC");
        $activePaymentMethods = ($stmtPM && is_object($stmtPM)) ? $stmtPM->fetchAll(PDO::FETCH_ASSOC) : [];
    }
} catch (Throwable $e) {}

if (empty($activePaymentMethods)) {
    $activePaymentMethods = [
        [
            'id'             => 1,
            'method_key'     => 'bkash',
            'name'           => 'bKash',
            'account_number' => $settings['bkash_number'] ?? '01700000000',
            'account_type'   => 'Personal',
            'instructions'   => 'Send Money to the Personal bKash number below.',
            'banner_url'     => $globalPaymentBanner,
            'qr_image_url'   => '',
            'allow_add_fund' => 1,
            'allow_checkout' => 1
        ],
        [
            'id'             => 2,
            'method_key'     => 'nagad',
            'name'           => 'Nagad',
            'account_number' => $settings['nagad_number'] ?? '01800000000',
            'account_type'   => 'Personal',
            'instructions'   => 'Send Money to the Personal Nagad number below.',
            'banner_url'     => $globalPaymentBanner,
            'qr_image_url'   => '',
            'allow_add_fund' => 1,
            'allow_checkout' => 1
        ],
        [
            'id'             => 3,
            'method_key'     => 'rocket',
            'name'           => 'Rocket',
            'account_number' => $settings['rocket_number'] ?? '01900000000',
            'account_type'   => 'Personal',
            'instructions'   => 'Send Money to the Personal Rocket number below.',
            'banner_url'     => $globalPaymentBanner,
            'qr_image_url'   => '',
            'allow_add_fund' => 1,
            'allow_checkout' => 1
        ]
    ];
}

$catalogProducts = [];

// ====================================================================
// EXACT SERIAL CATEGORY BUILDER: ALL -> NONROOT -> ROOT -> IPHONE -> PC -> OTHER
// ====================================================================
$preferredCategoryOrder = ['ALL', 'NONROOT', 'ROOT', 'IPHONE', 'PC', 'OTHER'];
$dynamicCategoriesFound = [];

try {
    $stmtCatList = $pdo->prepare("SELECT name FROM product_categories 
        WHERE (subdomain_id = ? OR subdomain_id = 0) AND is_active = 1 
        ORDER BY sort_order ASC, id ASC");
    $stmtCatList->execute([$currentSubdomainId]);
    foreach ($stmtCatList->fetchAll(PDO::FETCH_COLUMN) as $catName) {
        $catUpper = strtoupper(trim($catName));
        if ($catUpper === 'NOONROOT') $catUpper = 'NONROOT';
        if ($catUpper === 'I PHONE' || $catUpper === 'IOS') $catUpper = 'IPHONE';
        if (!empty($catUpper) && !in_array($catUpper, $dynamicCategoriesFound)) {
            $dynamicCategoriesFound[] = $catUpper;
        }
    }
} catch (Throwable $e) {}

$availableCategories = ['ALL'];
$discoveredFromProducts = [];

try {
    if ($tenant) {
        $sql = "SELECT p.id as product_id, p.name as product_name, p.image_url, p.logo_url, p.banner_url, 
                       p.download_link, p.setup_link, p.description, p.tags, p.category, p.is_pinned, p.is_active, p.is_maintenance, p.maintenance_msg, p.reset_enabled, 
                       p.app_download_url,
                       pl.id as plan_id, 
                       COALESCE(NULLIF(pl.plan_title, ''), NULLIF(pl.validity, ''), 'Standard Plan') as validity, 
                       pl.reseller_price, pl.user_price, pl.price, pl.reseller_discount_percent, 
                       COALESCE(pl.discount_percent, 0) as discount_percent, pl.api_plan_id,
                       pl.reward_coins, pl.coin_price 
                FROM products p
                LEFT JOIN product_plans pl ON pl.product_id = p.id 
                     AND (pl.is_deleted = 0 OR pl.is_deleted IS NULL)
                WHERE p.subdomain_id = ?
                  AND (p.is_active = 1 OR p.is_active IS NULL OR p.is_active = '1' OR p.is_active = 'active' OR p.is_active = '' OR p.status = 'active' OR p.status IS NULL OR p.is_maintenance = 1) 
                  AND (p.is_deleted = 0 OR p.is_deleted IS NULL OR p.is_deleted = '0')
                  AND (p.status != 'deleted' OR p.status IS NULL)
                ORDER BY p.is_pinned DESC, p.sort_order ASC, p.id ASC, pl.user_price ASC, pl.price ASC, pl.id ASC";
        $stmtProds = $pdo->prepare($sql);
        $stmtProds->execute([$currentSubdomainId]);
    } else {
        $sql = "SELECT p.id as product_id, p.name as product_name, p.image_url, p.logo_url, p.banner_url, 
                       p.download_link, p.setup_link, p.description, p.tags, p.category, p.is_pinned, p.is_active, p.is_maintenance, p.maintenance_msg, p.reset_enabled, 
                       p.app_download_url,
                       pl.id as plan_id, 
                       COALESCE(NULLIF(pl.plan_title, ''), NULLIF(pl.validity, ''), 'Standard Plan') as validity, 
                       pl.reseller_price, pl.user_price, pl.price, pl.reseller_discount_percent, 
                       COALESCE(pl.discount_percent, 0) as discount_percent, pl.api_plan_id,
                       pl.reward_coins, pl.coin_price 
                FROM products p
                LEFT JOIN product_plans pl ON pl.product_id = p.id 
                     AND (pl.is_deleted = 0 OR pl.is_deleted IS NULL)
                WHERE (p.subdomain_id = 0 OR p.subdomain_id IS NULL OR p.subdomain_id = '' OR p.subdomain_id = '0')
                  AND (p.is_active = 1 OR p.is_active IS NULL OR p.is_active = '1' OR p.is_active = 'active' OR p.is_active = '' OR p.status = 'active' OR p.status IS NULL OR p.is_maintenance = 1)
                  AND (p.is_deleted = 0 OR p.is_deleted IS NULL OR p.is_deleted = '0')
                  AND (p.status != 'deleted' OR p.status IS NULL)
                ORDER BY p.is_pinned DESC, p.sort_order ASC, p.id ASC, pl.user_price ASC, pl.price ASC, pl.id ASC";
        $stmtProds = $pdo->query($sql);
    }
    
    $dbProducts = ($stmtProds && is_object($stmtProds)) ? $stmtProds->fetchAll(PDO::FETCH_ASSOC) : [];
    
    foreach ($dbProducts as $dp) {
        $pId    = (int)$dp['product_id'];
        $planId = (int)($dp['plan_id'] ?? 0);

        if ($userRole === 'reseller' && (int)($user['is_product_restricted'] ?? 0) === 1 && !empty($userAllowedProducts)) {
            if (!in_array($pId, array_map('intval', $userAllowedProducts), true)) {
                continue;
            }
        }

        $pName = trim($dp['product_name']);
        if (empty($pName)) continue;

        $rawCat = !empty($dp['category']) ? strtoupper(trim($dp['category'])) : 'APKMOD';
        if ($rawCat === 'NOONROOT') $rawCat = 'NONROOT';
        if ($rawCat === 'I PHONE' || $rawCat === 'IOS') $rawCat = 'IPHONE';

        if (!in_array($rawCat, $discoveredFromProducts)) {
            $discoveredFromProducts[] = $rawCat;
        }

        $finalName        = $pName;
        $logo             = !empty($dp['logo_url']) ? $dp['logo_url'] : ($dp['image_url'] ?? '');
        $banner           = !empty($dp['banner_url']) ? $dp['banner_url'] : ($dp['image_url'] ?? '');
        $tags             = !empty($dp['tags']) ? $dp['tags'] : '#APKMOD, #SAFE, #INSTANT';
        $desc             = !empty($dp['description']) ? $dp['description'] : 'Verified fast license delivery.';
        $dLink            = !empty($dp['download_link']) ? $dp['download_link'] : '';
        $sLink            = !empty($dp['setup_link']) ? $dp['setup_link'] : '';
        $prodActiveStatus = (int)($dp['is_active'] ?? 1);
        $prodAppUrl       = !empty($dp['app_download_url']) ? $dp['app_download_url'] : (!empty($dp['download_link']) ? $dp['download_link'] : '');

        $rawRetailUSD = (float)(($dp['user_price'] ?? 0) ?: (($dp['price'] ?? 0) ?: ($dp['reseller_price'] ?? 0)));
        $rawResellUSD = (float)(($dp['reseller_price'] ?? 0) ?: (($dp['price'] ?? 0) ?: ($dp['user_price'] ?? 0)));

        $regularBaseUSD = ($userRole === 'reseller')
            ? (($rawResellUSD > 0) ? $rawResellUSD : $rawRetailUSD)
            : (($rawRetailUSD > 0) ? $rawRetailUSD : $rawResellUSD);

        $hasCustomPrice = false;
        if ($userRole === 'reseller') {
            if ($planId > 0 && isset($userCustomPrices[$planId]) && (float)$userCustomPrices[$planId] > 0) {
                $finalPrice = (float)$userCustomPrices[$planId];
                $hasCustomPrice = true;
            } else {
                $finalPrice = $regularBaseUSD;
            }
        } else {
            if ($isLoggedIn) {
                if ($planId > 0 && isset($userCustomPrices[$planId]) && (float)$userCustomPrices[$planId] > 0) {
                    $finalPrice = (float)$userCustomPrices[$planId];
                    $hasCustomPrice = true;
                } else {
                    $finalPrice = $regularBaseUSD;
                }
            } else {
                if ($planId > 0 && isset($defaultCustomerPrices[$planId]) && (float)$defaultCustomerPrices[$planId] > 0) {
                    $finalPrice = (float)$defaultCustomerPrices[$planId];
                    $hasCustomPrice = true;
                } else {
                    $finalPrice = $regularBaseUSD;
                }
            }
        }

        $explicitDiscount = 0.0;
        if ($userRole === 'reseller') {
            $explicitDiscount = (float)($dp['reseller_discount_percent'] ?? 0);
        } else {
            $explicitDiscount = (float)($dp['discount_percent'] ?? 0);
        }

        $origRetailUSD = $regularBaseUSD;
        $planDiscount  = 0;

        if ($explicitDiscount >= 0 && $explicitDiscount <= 100) {
            $planDiscount = (int)round($explicitDiscount);
            if (!$hasCustomPrice) {
                $origRetailUSD = $regularBaseUSD;
                $finalPrice    = round($regularBaseUSD * (1 - ($planDiscount / 100)), 2);
            } else {
                $origRetailUSD = max($regularBaseUSD, $finalPrice);
            }
        } else {
            $planDiscount  = 0;
            $origRetailUSD = $finalPrice;
        }

        $planRewardCoins = max(0, (int)($dp['reward_coins'] ?? 0));
        $planCoinPrice   = max(0, (int)($dp['coin_price'] ?? 0));

        if (!isset($catalogProducts[$pId])) {
            $catalogProducts[$pId] = [
                'id'               => $pId,
                'name'             => $finalName,
                'category'         => $rawCat,
                'is_pinned'        => (int)($dp['is_pinned'] ?? 0),
                'is_active'        => $prodActiveStatus,
                'is_maintenance'   => (int)($dp['is_maintenance'] ?? 0),
                'maintenance_msg'  => trim((string)($dp['maintenance_msg'] ?? '')),
                'reset_enabled'    => (int)($dp['reset_enabled'] ?? 1),
                'logo_url'         => $logo,
                'banner_url'       => $banner,
                'description'      => $desc,
                'tags'             => $tags,
                'download_link'    => $dLink,
                'setup_link'       => $sLink,
                'app_download_url' => $prodAppUrl,
                'max_discount'     => 0,
                'plans'            => []
            ];
        }

        if ($planId > 0) {
            if ($planDiscount > $catalogProducts[$pId]['max_discount']) {
                $catalogProducts[$pId]['max_discount'] = $planDiscount;
            }

            $catalogProducts[$pId]['plans'][] = [
                'plan_id'          => $planId,
                'api_plan_id'      => (int)($dp['api_plan_id'] ?? 0),
                'validity'         => $dp['validity'] ?? 'Standard',
                'price'            => $finalPrice,
                'price_bdt'        => round($finalPrice * $effectiveBdtRate, 0),
                'original_bdt'     => round($origRetailUSD * $effectiveBdtRate, 0),
                'discount_percent' => $planDiscount,
                'reward_coins'     => $planRewardCoins,
                'coin_price'       => $planCoinPrice
            ];
        }
    }

    foreach ($catalogProducts as &$cp) {
        if (!empty($cp['plans'])) {
            usort($cp['plans'], function($a, $b) {
                return (float)$a['price_bdt'] <=> (float)$b['price_bdt'];
            });
        }
    }
    unset($cp);

    // BUILD ORDERED CATEGORY LIST: ALL -> NONROOT -> ROOT -> IPHONE -> PC -> OTHER -> (others)
    $allDiscoveredCats = array_unique(array_merge($dynamicCategoriesFound, $discoveredFromProducts));
    $orderedCategories = [];

    foreach ($preferredCategoryOrder as $pCat) {
        if ($pCat === 'ALL') {
            $orderedCategories[] = 'ALL';
        } elseif (in_array($pCat, $allDiscoveredCats, true)) {
            $orderedCategories[] = $pCat;
        }
    }

    foreach ($allDiscoveredCats as $oCat) {
        if (!in_array($oCat, $orderedCategories, true)) {
            $orderedCategories[] = $oCat;
        }
    }

    $availableCategories = $orderedCategories;

} catch (Throwable $e) {
    $catalogProducts = [];
    $availableCategories = ['ALL', 'NONROOT', 'ROOT', 'IPHONE', 'PC', 'OTHER'];
}

$userNotifications = [];
if ($isLoggedIn) {
    try {
        $targetRole = ($userRole === 'reseller') ? 'resellers' : 'customers';
        $stmtNotif = $pdo->prepare("SELECT id, title, message, created_at FROM system_notifications 
            WHERE subdomain_id = ? AND (target_type = 'all' OR target_type = ?) 
            ORDER BY id DESC LIMIT 25");
        $stmtNotif->execute([$currentSubdomainId, $targetRole]);
        $userNotifications = $stmtNotif ? $stmtNotif->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}
}
$notifCount = count($userNotifications);

$toast = $_SESSION['flash_toast'] ?? null;
unset($_SESSION['flash_toast']);

$activePage = $_SESSION['active_page'] ?? 'dashboard';
unset($_SESSION['active_page']);

$keys             = [];
$deposits         = [];
$userAllResetKeys = [];

if ($isLoggedIn) {
    try {
        $stmtKeys = $pdo->prepare("SELECT * FROM licenses WHERE user_id = ? AND subdomain_id = ? ORDER BY id DESC LIMIT 40");
        $stmtKeys->execute([$user['id'], $currentSubdomainId]);
        $keys = $stmtKeys ? $stmtKeys->fetchAll(PDO::FETCH_ASSOC) : [];

        $stmtTrx = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? AND subdomain_id = ? ORDER BY id DESC LIMIT 20");
        $stmtTrx->execute([$user['id'], $currentSubdomainId]);
        $deposits = $stmtTrx ? $stmtTrx->fetchAll(PDO::FETCH_ASSOC) : [];

        $stmtAllKeys = $pdo->prepare("SELECT license_key, validity, product_id, created_at FROM licenses WHERE user_id = ? AND subdomain_id = ? ORDER BY id DESC");
        $stmtAllKeys->execute([$user['id'], $currentSubdomainId]);
        $userAllResetKeys = $stmtAllKeys ? $stmtAllKeys->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}
}

// LIVE FEED & TOP BUYERS (FOR BOTTOM LIVE HISTORY WIDGET)
$livePurchaseFeed = [];
try {
    $stmtLF = $pdo->prepare("SELECT * FROM live_purchase_feed WHERE subdomain_id = ? ORDER BY id DESC LIMIT 25");
    $stmtLF->execute([$currentSubdomainId]);
    $livePurchaseFeed = $stmtLF->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// অটো-সিঙ্কের জন্য AJAX রিকোয়েস্ট হ্যান্ডলার
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_live_history_data') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $syncTop = [];
    $syncRecent = [];
    try {
        $stmtS1 = $pdo->prepare("SELECT u.username, u.avatar_url, SUM(l.price) as spent 
            FROM licenses l JOIN users u ON l.user_id = u.id 
            WHERE l.subdomain_id = ? GROUP BY u.id, u.username, u.avatar_url ORDER BY spent DESC LIMIT 15");
        $stmtS1->execute([$currentSubdomainId]);
        $syncTop = $stmtS1->fetchAll(PDO::FETCH_ASSOC);

        $stmtS2 = $pdo->prepare("SELECT l.validity, l.price, l.created_at, u.username, u.avatar_url 
            FROM licenses l JOIN users u ON l.user_id = u.id 
            WHERE l.subdomain_id = ? ORDER BY l.id DESC LIMIT 15");
        $stmtS2->execute([$currentSubdomainId]);
        $syncRecent = $stmtS2->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    echo json_encode([
        'status' => 'success',
        'top_buyers' => $syncTop,
        'recent_orders' => $syncRecent,
        'rate' => (float)$effectiveBdtRate
    ]);
    exit;
}

$topBuyers = [];
try {
    $stmtLB = $pdo->prepare("SELECT u.username, u.avatar_url, SUM(l.price) as spent 
        FROM licenses l JOIN users u ON l.user_id = u.id 
        WHERE l.subdomain_id = ? GROUP BY u.id, u.username, u.avatar_url ORDER BY spent DESC LIMIT 15");
    $stmtLB->execute([$currentSubdomainId]);
    $topBuyers = $stmtLB->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$recentOrdersList = [];
try {
    $stmtRO = $pdo->prepare("SELECT l.validity, l.price, l.created_at, u.username, u.avatar_url 
        FROM licenses l 
        JOIN users u ON l.user_id = u.id 
        WHERE l.subdomain_id = ? 
        ORDER BY l.id DESC LIMIT 15");
    $stmtRO->execute([$currentSubdomainId]);
    $recentOrdersList = $stmtRO->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Custom Popups
$activeCustomPopups = [];
try {
    if ($tenant) {
        $stmtPop = $pdo->prepare("SELECT * FROM custom_popups WHERE subdomain_id = ? AND is_active = 1 ORDER BY sort_order ASC");
        $stmtPop->execute([$currentSubdomainId]);
    } else {
        $stmtPop = $pdo->query("SELECT * FROM custom_popups WHERE subdomain_id = 0 AND is_active = 1 ORDER BY sort_order ASC");
    }
    $activeCustomPopups = $stmtPop ? $stmtPop->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {}

$initialDeepLinkProdId = isset($_GET['prod']) ? (int)$_GET['prod'] : (isset($_GET['product']) ? (int)$_GET['product'] : 0);

$firebaseClientConfig = [];
if ($isFirebaseEnabled) {
    $firebaseClientConfig = [
        'apiKey'            => $settings['firebase_api_key'] ?? '',
        'authDomain'        => $settings['firebase_auth_domain'] ?? '',
        'projectId'         => $settings['firebase_project_id'] ?? '',
        'storageBucket'     => $settings['firebase_storage_bucket'] ?? '',
        'messagingSenderId' => $settings['firebase_messaging_sender_id'] ?? '',
        'appId'             => $settings['firebase_app_id'] ?? ''
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#04010a">
    <meta name="description" content="<?= htmlspecialchars($settings['site_tagline'] ?? 'Fastest Digital Licenses & Passes') ?>">
    
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars(!empty($storeLogoUrl) ? $storeLogoUrl : 'https://cdn-icons-png.flaticon.com/512/266/266033.png') ?>">
    
    <title><?= htmlspecialchars($storeTitleName) ?> — Client Portal</title>
    
    <!-- ⚡ SPEED: preconnect to every CDN so DNS/TLS happen in parallel -->
    <link rel="preconnect" href="https://cdn.tailwindcss.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">

    <script src="https://cdn.tailwindcss.com"></script>

    <!-- ⚡ SPEED: non-critical scripts no longer block page rendering -->
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php if ($isFirebaseEnabled): ?>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js" defer></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js" defer></script>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js" defer></script>

    <!-- ⚡ SPEED: icon + font stylesheets load without blocking first paint -->
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700;800&family=Orbitron:wght@700;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700;800&family=Orbitron:wght@700;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700;800&family=Orbitron:wght@700;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    </noscript>
    
    <style>
        :root {
            --primary-cyan: <?= htmlspecialchars($siteThemeColor) ?>;
            --secondary-magenta: #d946ef;
            --accent-gold: #facc15;
            --bright-emerald: #00ff88;
            --bg-deep: #04010a;
            --card-glass: #0c051a;
            --border-purple: #1f0f35;
        }

        *, *::before, *::after { box-sizing: border-box; }

        body { 
            background-color: var(--bg-deep); 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: #f1f5f9; 
            overflow-x: hidden; 
            -webkit-tap-highlight-color: transparent;
        }

        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .font-cyber { font-family: 'Orbitron', sans-serif; }
        .font-bengali { font-family: 'Hind Siliguri', sans-serif; }

        .glass-card { 
            background: var(--card-glass); 
            border: 1px solid var(--border-purple); 
        }

        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        #siteSplashPreloader {
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at center, #1b0933 0%, #070210 65%, #020005 100%);
            z-index: 9999999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: opacity 0.5s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.5s ease;
        }

        #siteSplashPreloader.splash-fade-out {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .splash-ring-container {
            position: relative;
            width: 125px; height: 125px;
            display: flex; align-items: center; justify-content: center;
        }

        .splash-pulsing-circle {
            position: absolute; inset: 0; border-radius: 50%;
            border: 3px solid transparent; border-top-color: var(--primary-cyan); border-bottom-color: var(--secondary-magenta);
            animation: splashSpinRing 1.3s linear infinite;
        }

        .splash-pulsing-circle-outer {
            position: absolute; inset: -9px; border-radius: 50%;
            border: 2px dashed rgba(250, 204, 21, 0.55);
            animation: splashSpinRingReverse 4s linear infinite;
        }

        @keyframes splashSpinRing { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes splashSpinRingReverse { 0% { transform: rotate(360deg); } 100% { transform: rotate(0deg); } }

        .splash-logo-core {
            width: 82px; height: 82px; border-radius: 50%; background: #0a0316;
            border: 2.5px solid rgba(217, 70, 239, 0.65); display: flex; align-items: center; justify-content: center;
            overflow: hidden; box-shadow: 0 0 25px rgba(0, 245, 255, 0.45); animation: splashLogoPulse 1.8s ease-in-out infinite;
        }

        @keyframes splashLogoPulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 20px rgba(0, 245, 255, 0.4); }
            50% { transform: scale(1.05); box-shadow: 0 0 35px rgba(217, 70, 239, 0.75); }
        }

        .splash-loader-bar {
            width: 165px; height: 4px; background: rgba(255, 255, 255, 0.08);
            border-radius: 999px; overflow: hidden; margin-top: 20px; position: relative;
        }

        .splash-loader-progress {
            position: absolute; top: 0; left: 0; bottom: 0; width: 0%;
            background: linear-gradient(90deg, var(--primary-cyan), var(--secondary-magenta), var(--bright-emerald));
            border-radius: 999px; box-shadow: 0 0 12px var(--bright-emerald);
            animation: splashProgressFill 1.5s ease-in-out forwards;
        }

        @keyframes splashProgressFill { 0% { width: 0%; } 45% { width: 68%; } 100% { width: 100%; } }

        #installShopBanner {
            position: fixed; top: 0; left: 0; right: 0; z-index: 99999;
            transform: translateY(-100%); transition: transform 0.45s cubic-bezier(0.16, 1, 0.3, 1);
            background: rgba(10, 4, 22, 0.96); backdrop-filter: blur(14px);
            border-bottom: 1.5px solid var(--primary-cyan); box-shadow: 0 10px 30px rgba(0, 245, 255, 0.25), 0 4px 15px rgba(0,0,0,0.8);
        }
        #installShopBanner.show-install { transform: translateY(0); }

        .promo-popup-card {
            background: radial-gradient(circle at 50% 0%, #220e40 0%, #0d041c 65%, #05010b 100%);
            border: 2px solid var(--accent-gold); box-shadow: 0 0 50px rgba(250, 204, 21, 0.4), inset 0 0 25px rgba(217, 70, 239, 0.22);
            animation: promoPopupScaleIn 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes promoPopupScaleIn { 0% { transform: scale(0.85); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }

        .promo-shimmer-badge {
            background: linear-gradient(90deg, #facc15 0%, #fb923c 50%, #facc15 100%);
            background-size: 200% auto; color: #04010a; animation: shimmerSlide 2.5s linear infinite;
        }

        @keyframes shimmerSlide { to { background-position: 200% center; } }

        .deposit-bonus-container {
            background: linear-gradient(135deg, #130728 0%, #080214 100%); border: 1.5px solid var(--primary-cyan);
            border-radius: 18px; box-shadow: 0 0 25px rgba(0, 245, 255, 0.22), inset 0 0 15px rgba(0, 245, 255, 0.08);
            position: relative; overflow: hidden;
        }

        .babu88-modal-bg {
            background: radial-gradient(circle at center, #1b162b 0%, #0d091a 60%, #05020c 100%);
            border: 2px solid var(--accent-gold); box-shadow: 0 0 50px rgba(250, 204, 21, 0.35), inset 0 0 20px rgba(250, 204, 21, 0.15);
        }

        .babu88-gold-header {
            font-family: 'Orbitron', sans-serif; font-weight: 900; letter-spacing: 2px;
            background: linear-gradient(180deg, #fff3a8 0%, #facc15 50%, #ca8a04 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            text-shadow: 0 0 15px rgba(250, 204, 21, 0.5);
        }

        .babu88-rim-frame {
            position: relative; width: 290px; height: 290px; border-radius: 50%;
            background: radial-gradient(circle, #5b3d0c 0%, #d97706 40%, #fbbf24 60%, #78350f 100%);
            padding: 10px; box-shadow: 0 0 25px rgba(245, 158, 11, 0.8), inset 0 0 15px rgba(0, 0, 0, 0.8);
            display: flex; align-items: center; justify-content: center;
        }

        .babu88-needle-top {
            position: absolute; top: -2px; left: 50%; transform: translateX(-50%); width: 0; height: 0;
            border-left: 15px solid transparent; border-right: 15px solid transparent; border-top: 24px solid #fef08a;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.8)) drop-shadow(0 0 8px #f59e0b); z-index: 30;
        }

        .babu88-center-btn {
            position: absolute; width: 72px; height: 72px; border-radius: 50%;
            background: radial-gradient(circle, #1e1b29 0%, #0d0a17 100%); border: 3.5px solid #fbbf24;
            box-shadow: 0 0 15px rgba(251, 191, 36, 0.8), inset 0 0 8px rgba(251, 191, 36, 0.5);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            z-index: 25; cursor: pointer; transition: transform 0.15s ease;
        }
        .babu88-center-btn:active { transform: scale(0.92); }

        .story-avatar-ring {
            width: 58px; height: 58px; min-width: 58px; min-height: 58px; padding: 2.5px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-cyan), var(--secondary-magenta), var(--bright-emerald));
            display: flex; align-items: center; justify-content: center; box-shadow: 0 0 10px rgba(217, 70, 239, 0.4);
            transition: transform 0.2s ease;
        }
        .story-avatar-ring:active { transform: scale(0.92); }

        .product-card {
            background: linear-gradient(180deg, #110724 0%, #06020c 100%); border-radius: 20px;
            border: 1px solid rgba(217, 70, 239, 0.35); transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
            will-change: transform; display: flex; flex-direction: column; justify-content: space-between; position: relative;
        }
        .product-card:hover {
            border-color: var(--primary-cyan); box-shadow: 0 6px 20px rgba(0, 245, 255, 0.2); transform: translateY(-2px);
        }

        .price-badge-buy-style {
            background: linear-gradient(90deg, #d946ef 0%, #c026d3 50%, #a855f7 100%);
            border-radius: 12px; padding: 4px 8px; box-shadow: 0 2px 10px rgba(217, 70, 239, 0.35);
            display: flex; align-items: center; gap: 4px;
        }

        .category-pill { background: #0a0414; border: 1px solid #1c0d30; color: #c4b5fd; transition: all 0.2s ease; }
        .category-pill.active {
            background: linear-gradient(90deg, #d946ef 0%, #a855f7 100%); color: #ffffff;
            border-color: #d946ef; box-shadow: 0 2px 10px rgba(217, 70, 239, 0.35); font-weight: 800;
        }

        .btn-buy-compact {
            background: linear-gradient(90deg, #d946ef 0%, #c026d3 50%, #a855f7 100%);
            box-shadow: 0 2px 12px rgba(217, 70, 239, 0.4); transition: transform 0.15s ease;
        }
        .btn-buy-compact:active { transform: scale(0.95); }

        .btn-magenta-glow {
            background: linear-gradient(90deg, #d946ef 0%, #c026d3 50%, #a855f7 100%);
            box-shadow: 0 3px 14px rgba(217, 70, 239, 0.4);
        }

        .btn-google-login {
            background: #ffffff; color: #1f2937; font-weight: 700; border-radius: 14px;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            box-shadow: 0 4px 15px rgba(255, 255, 255, 0.15); transition: all 0.2s ease; cursor: pointer;
        }
        .btn-google-login:active { transform: scale(0.97); }

        .nav-bottom-bar {
            background: rgba(10, 4, 20, 0.95); border: 1px solid rgba(217, 70, 239, 0.3);
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.9), 0 0 15px rgba(217, 70, 239, 0.2);
            backdrop-filter: blur(16px); border-radius: 24px;
        }
        .bottom-nav-btn { color: #94a3b8; transition: all 0.2s ease; }
        .bottom-nav-btn.active { color: #d946ef; transform: translateY(-1px); }

        .center-fund-btn {
            background: linear-gradient(135deg, #00ff88 0%, #00f3ff 100%);
            box-shadow: 0 3px 15px rgba(0, 255, 136, 0.4); color: #040813;
            transform: translateY(-6px); transition: transform 0.2s ease;
        }

        .floating-chat-btn {
            position: fixed; bottom: 74px; right: 12px; width: 50px; height: 50px; border-radius: 50%;
            background: linear-gradient(135deg, #00f3ff 0%, #0077b6 100%); color: #020617;
            display: flex; align-items: center; justify-content: center; font-size: 22px;
            box-shadow: 0 5px 22px rgba(0, 245, 255, 0.6); z-index: 45; cursor: pointer; transition: transform 0.2s ease;
        }
        .floating-chat-btn:active { transform: scale(0.92); }

        .floating-spin-btn {
            position: fixed; bottom: 134px; right: 12px; width: 52px; height: 52px; border-radius: 50%;
            background: radial-gradient(circle, #2a1b02 0%, #060112 100%); border: 2.5px solid #fbbf24;
            color: #fbbf24; display: flex; flex-direction: column; align-items: center; justify-content: center;
            z-index: 45; cursor: pointer; box-shadow: 0 0 18px rgba(251, 191, 36, 0.7); transition: transform 0.2s ease;
        }
        .floating-spin-btn:active { transform: scale(0.92); }

        .chat-dialog {
            position: fixed; bottom: 134px; right: 12px; width: 310px; max-width: 90vw;
            background: #0d061c; border: 1px solid #00f3ff; border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.95), 0 0 16px rgba(0, 245, 255, 0.25);
            z-index: 50; overflow: hidden;
        }

        .product-card.product-card-maint {
            background: linear-gradient(180deg, #2a0710 0%, #12030a 100%) !important;
            border: 1.5px solid #f43f5e !important;
            box-shadow: 0 0 14px rgba(244, 63, 94, 0.45), inset 0 0 18px rgba(244, 63, 94, 0.12) !important;
        }
        .product-card.product-card-maint:hover { transform: none; }
        .maint-icon-pulse { animation: maintPulse 1.4s ease-in-out infinite; }
        @keyframes maintPulse { 0%,100% { opacity: 1; } 50% { opacity: .45; } }
        .maint-overlay {
            position: absolute; inset: 0; background: rgba(40, 4, 14, 0.82); backdrop-filter: blur(3px);
            z-index: 20; display: flex; flex-direction: column; align-items: center; justify-content: center; border-radius: 20px;
        }
        .maint-watermark-text { color: #f43f5e; font-weight: 900; font-size: 13px; letter-spacing: 2px; }

        .pay-method-choice-card {
            background: linear-gradient(135deg, #0e051e 0%, #070212 100%);
            border: 1.5px solid #220e3a;
            border-radius: 16px;
            padding: 12px 14px;
            cursor: pointer;
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .pay-method-choice-card:hover {
            border-color: var(--primary-cyan);
            transform: translateY(-2px);
            box-shadow: 0 4px 18px rgba(0, 245, 255, 0.25);
        }
        .pay-method-choice-card:active { transform: scale(0.98); }

        .admin-payment-item-card {
            background: #080214;
            border: 1.5px solid #1e0e33;
            border-radius: 14px;
            padding: 10px 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .admin-payment-item-card:hover { border-color: #d946ef; background: #110522; }

        .custom-plan-dropdown {
            background: #05010b;
            border: 1.5px solid #2a1245;
            border-radius: 14px;
            padding: 8px;
            max-height: 250px;
            overflow-y: auto;
            box-shadow: 0 8px 25px rgba(0,0,0,0.8), inset 0 0 15px rgba(217, 70, 239, 0.05);
        }
        
        .custom-plan-item {
            background: linear-gradient(135deg, #0c0418 0%, #07020e 100%);
            border: 1px solid #1c0a30;
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .custom-plan-item:last-child { margin-bottom: 0; }
        .custom-plan-item:hover { border-color: #d946ef; background: #140726; }
        .custom-plan-item.selected {
            border-color: #00ff88;
            background: linear-gradient(135deg, #061a10 0%, #04140b 100%);
            box-shadow: 0 0 15px rgba(0, 255, 136, 0.15);
        }
        
        .plan-radio-circle {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 2px solid #475569;
            transition: all 0.2s ease;
        }

        .plan-details-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
        }

        .plan-badge-discount { background: rgba(0, 255, 136, 0.15); color: #00ff88; border: 1px solid rgba(0, 255, 136, 0.3); padding: 1px 6px; border-radius: 6px; font-size: 8px; font-weight: 800; }
        .plan-badge-gift { background: rgba(250, 204, 21, 0.15); color: #facc15; border: 1px solid rgba(250, 204, 21, 0.3); padding: 1px 6px; border-radius: 6px; font-size: 8px; font-weight: 800; }
        .plan-badge-coinpay { background: rgba(6, 182, 212, 0.15); color: #06b6d4; border: 1px solid rgba(6, 182, 212, 0.3); padding: 1px 6px; border-radius: 6px; font-size: 8px; font-weight: 800; }

        /* DARK CYBERPUNK LIVE HISTORY WIDGET */
        .bottom-history-box {
            background: linear-gradient(180deg, #100624 0%, #06020c 100%);
            border: 1.5px solid rgba(217, 70, 239, 0.35);
            border-radius: 24px;
            padding: 16px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.8), 0 0 20px rgba(217, 70, 239, 0.15);
            color: #f1f5f9;
        }
        .b-tab-btn {
            padding: 8px 18px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.25s ease;
            color: #94a3b8;
            background: #080312;
            border: 1px solid #1f0f35;
        }
        .b-tab-btn:hover {
            color: #ffffff;
            border-color: rgba(217, 70, 239, 0.5);
        }
        .b-tab-btn.active {
            background: linear-gradient(90deg, #d946ef 0%, #a855f7 100%);
            color: #ffffff;
            border-color: #d946ef;
            box-shadow: 0 3px 15px rgba(217, 70, 239, 0.45);
        }
        .b-member-card {
            background: #090317;
            border: 1px solid #1a0c2c;
            border-radius: 18px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            transition: all 0.2s ease;
        }
        .b-member-card:hover {
            border-color: var(--primary-cyan);
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(0, 245, 255, 0.15);
        }
        .b-member-card:last-child { margin-bottom: 0; }
        .b-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #14072b;
            border: 1.5px solid rgba(217, 70, 239, 0.5);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 15px;
            position: relative;
            box-shadow: 0 0 10px rgba(217, 70, 239, 0.25);
        }
        .b-medal-badge {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: linear-gradient(135deg, #facc15 0%, #eab308 100%);
            color: #04010a;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1.5px solid #06020c;
            box-shadow: 0 0 8px rgba(250, 204, 21, 0.6);
        }

        .spin-progress-outer {
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(250, 204, 21, 0.4);
            border-radius: 999px;
            height: 14px;
            overflow: hidden;
            position: relative;
        }
        .spin-progress-inner {
            background: linear-gradient(90deg, #facc15, #fb923c, #facc15);
            background-size: 200% auto;
            height: 100%;
            transition: width 0.8s ease;
            animation: progressShimmer 2s linear infinite;
        }
        @keyframes progressShimmer { to { background-position: 200% center; } }

        .custom-popup-bg {
            background: radial-gradient(circle at center, #1e0b38 0%, #0a0416 60%, #03010a 100%);
            border: 2px solid #d946ef;
            box-shadow: 0 0 60px rgba(217, 70, 239, 0.5);
        }

        :root { color-scheme: dark; }
        select, option { background-color: #070210; color: #e5e7eb; }
        /* ===== HEADER COLOR SYSTEM ===== */
        .hdr-store-name { color: #ffffff; text-shadow: 0 0 4px rgba(255,255,255,.55), 0 0 10px rgba(0,245,255,.45), 0 0 18px rgba(217,70,239,.30); }
        .hdr-portal-label { color: #22d3ee; text-shadow: 0 0 6px rgba(34,211,238,.55); }
        .hdr-chip { box-shadow: 0 1px 2px rgba(0,0,0,.4); white-space: nowrap; }
        .hdr-currency-chip { background: #071026; border: 1.5px solid #3b82f6; color: #ffffff; }
        .hdr-currency-chip:hover { border-color: #60a5fa; box-shadow: 0 0 8px rgba(59,130,246,.45); }
        .hdr-coin-chip { background: #171003; border: 1.5px solid #fbbf24; color: #facc15; }
        .hdr-coin-chip i, .hdr-coin-chip span { color: #facc15; text-shadow: 0 0 6px rgba(250,204,21,.45); }
        .hdr-balance-chip { background: #03140b; border: 1.5px solid #00ff88; }
        .hdr-balance-chip span, .hdr-balance-chip i { color: #00ff88; text-shadow: 0 0 6px rgba(0,255,136,.55); }
        .hdr-balance-chip:hover { box-shadow: 0 0 10px rgba(0,255,136,.35); }
        .hdr-bell-chip { background: #0f061e; border: 1.5px solid #a855f7; color: #22d3ee; }
        .hdr-bell-chip i { color: #22d3ee; text-shadow: 0 0 6px rgba(34,211,238,.6); }
        .hdr-bell-chip:hover { box-shadow: 0 0 10px rgba(168,85,247,.45); }
        @media (max-width: 380px) {
            .hdr-chip { padding-left: 6px !important; padding-right: 6px !important; }
            .hdr-currency-chip #currFlagIcon { display: none; }
            .hdr-store-name { max-width: 84px !important; }
        }
    </style>
</head>
<body class="min-h-screen w-full flex flex-col justify-between relative pb-24">

    <!-- 0. TOP SLIDE-DOWN INSTALL SHOP BANNER -->
    <div id="installShopBanner" class="px-3 py-2.5 flex items-center justify-between font-mono" data-app-url="<?= htmlspecialchars($globalAppDownloadUrl) ?>">
        <div class="flex items-center space-x-2.5 overflow-hidden">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-cyan-400 to-blue-600 flex items-center justify-center text-slate-950 font-black flex-shrink-0 shadow-md">
                <?php if (!empty($storeLogoUrl)): ?>
                    <img src="<?= htmlspecialchars($storeLogoUrl) ?>" class="w-full h-full object-cover rounded-xl" alt="App">
                <?php else: ?>
                    <i class="fa-solid fa-cloud-arrow-down text-sm"></i>
                <?php endif; ?>
            </div>
            <div class="overflow-hidden">
                <div class="flex items-center space-x-1.5">
                    <strong class="text-xs font-black text-white uppercase tracking-wider font-sans truncate">
                        <?= htmlspecialchars($storeTitleName) ?>
                    </strong>
                    <span class="px-1.5 py-0.2 rounded text-[7px] font-black uppercase bg-emerald-500 text-slate-950">OFFICIAL APP</span>
                </div>
                <span class="text-[9px] text-cyan-300 block truncate font-sans">Install shop on your phone screen for instant 1-tap access!</span>
            </div>
        </div>

        <div class="flex items-center space-x-2 flex-shrink-0 ml-2">
            <button type="button" onclick="triggerInstallShop()" class="px-3 py-1.5 rounded-xl bg-gradient-to-r from-cyan-400 to-blue-500 text-slate-950 font-black text-[10px] uppercase tracking-wider flex items-center gap-1 shadow-md hover:opacity-95 active:scale-95 transition">
                <i class="fa-solid fa-download text-[9px]"></i> <span>DOWNLOAD</span>
            </button>
            <button type="button" onclick="dismissInstallShop()" class="w-6 h-6 rounded-lg bg-white/10 text-slate-400 hover:text-white flex items-center justify-center text-xs active:scale-90 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>

    <!-- 1. PRELOADER -->
    <div id="siteSplashPreloader">
        <div class="splash-ring-container">
            <div class="splash-pulsing-circle-outer"></div>
            <div class="splash-pulsing-circle"></div>
            <div class="splash-logo-core">
                <?php if (!empty($storeLogoUrl)): ?>
                    <img src="<?= htmlspecialchars($storeLogoUrl) ?>" alt="Logo" class="w-full h-full object-cover">
                <?php else: ?>
                    <i class="fa-solid fa-gem text-3xl text-fuchsia-400"></i>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-5 space-y-1">
            <h2 class="text-sm font-black tracking-widest text-white uppercase font-cyber">
                <?= htmlspecialchars($storeTitleName) ?>
            </h2>
            <p class="text-[10px] text-cyan-400 font-mono tracking-wider">
                <?= htmlspecialchars($settings['site_tagline'] ?? 'LOADING SECURE SYSTEM...') ?>
            </p>
        </div>

        <div class="splash-loader-bar">
            <div class="splash-loader-progress"></div>
        </div>
    </div>

    <!-- 1B. CUSTOM POPUPS -->
    <?php foreach ($activeCustomPopups as $idx => $popup): ?>
    <div id="customPopup<?= (int)$popup['id'] ?>" class="fixed inset-0 bg-black/90 z-[99998] hidden flex items-center justify-center p-4">
        <div class="custom-popup-bg rounded-[28px] w-full max-w-sm p-5 space-y-3 relative text-center">
            <button onclick="document.getElementById('customPopup<?= (int)$popup['id'] ?>').classList.add('hidden')" 
                    class="absolute top-3 right-3 w-8 h-8 rounded-full bg-white/10 text-gray-300 hover:text-white">✕</button>
            <?php if (!empty($popup['image_url'])): ?>
                <img loading="lazy" decoding="async" src="<?= htmlspecialchars($popup['image_url']) ?>" class="w-full rounded-xl mb-2">
            <?php endif; ?>
            <?php if (!empty($popup['title'])): ?>
                <h3 class="text-base font-black text-white font-sans"><?= htmlspecialchars($popup['title']) ?></h3>
            <?php endif; ?>
            <?php if (!empty($popup['message'])): ?>
                <p class="text-xs text-gray-300 font-sans leading-relaxed"><?= nl2br(htmlspecialchars($popup['message'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($popup['link_url'])): ?>
                <a href="<?= htmlspecialchars($popup['link_url']) ?>" target="_blank" 
                   class="block w-full py-3 rounded-xl bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-bold text-xs uppercase tracking-wider">
                    <?= htmlspecialchars($popup['link_text'] ?: 'Open Link') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function(){
        const popupId = <?= (int)$popup['id'] ?>;
        const showOnce = <?= !empty($popup['show_once_per_session']) ? 'true' : 'false' ?>;
        const key = 'custom_popup_' + popupId + '_shown';
        const el = document.getElementById('customPopup' + popupId);
        if (!el) return;
        window.addEventListener('load', function() {
            setTimeout(function() {
                if (showOnce && sessionStorage.getItem(key)) return;
                el.classList.remove('hidden');
                if (showOnce) sessionStorage.setItem(key, '1');
            }, 2000 + (<?= (int)$idx ?> * 800));
        });
    })();
    </script>
    <?php endforeach; ?>

    <!-- 2. FIRST-TIME PROMO POPUP -->
    <?php if ($isPromoPopupActive && empty($activeCustomPopups)): 
        $topBonus = $activeDepositBonuses[0] ?? ['min_deposit' => 500, 'bonus_amount' => 50];
    ?>
    <div id="firstVisitPromoModal" class="fixed inset-0 bg-black/90 backdrop-blur-md z-[99999] hidden flex items-center justify-center p-4 select-none">
        <div class="promo-popup-card rounded-[28px] w-full max-w-sm p-5 space-y-4 relative text-center">
            <button type="button" onclick="closePromoModal()" class="absolute top-3.5 right-3.5 w-7 h-7 rounded-full bg-white/10 text-gray-300 hover:text-white flex items-center justify-center transition active:scale-95">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
            <div class="w-16 h-16 mx-auto rounded-full bg-gradient-to-tr from-yellow-400 to-amber-500 flex items-center justify-center text-gray-950 text-3xl shadow-[0_0_25px_rgba(250,204,21,0.8)] border-2 border-white animate-bounce mt-1">
                <i class="fa-solid fa-gift"></i>
            </div>
            <div class="space-y-1">
                <span class="promo-shimmer-badge px-3 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest font-mono">
                    SPECIAL OFFER ALERT
                </span>
                <h3 class="text-base sm:text-lg font-black text-white pt-1 font-bengali">
                    <?= htmlspecialchars($settings['promo_popup_title']) ?>
                </h3>
                <p class="text-[11px] text-gray-300 font-bengali">
                    <?= htmlspecialchars($settings['promo_popup_subtitle']) ?>
                </p>
            </div>
            <div class="bg-[#0b0318] p-3 rounded-2xl border border-yellow-500/50 space-y-1 text-left font-bengali">
                <div class="flex items-center justify-between text-xs font-mono">
                    <span class="text-yellow-400 font-bold">ইনস্ট্যান্ট ডিপোজিট রিওয়ার্ড:</span>
                    <span class="text-emerald-400 font-bold">+৳<?= number_format((float)$topBonus['bonus_amount'], 0) ?> টাকা বোনাস</span>
                </div>
                <p class="text-[11px] text-gray-300 leading-snug">
                    আজই আমাদের শপে <b>৳<?= number_format((float)$topBonus['min_deposit'], 0) ?> টাকা</b> বা তার বেশি রিচার্জ করলেই সাথে সাথে <b>৳<?= number_format((float)$topBonus['bonus_amount'], 0) ?> টাকা ইনস্ট্যান্ট ক্যাশব্যাক</b> ওয়ালেটে যুক্ত হবে!
                </p>
            </div>
            <div class="pt-1">
                <button type="button" onclick="handlePromoClaimClick()" class="w-full py-3 rounded-xl bg-gradient-to-r from-yellow-400 via-amber-400 to-yellow-500 text-gray-950 font-black text-xs uppercase tracking-wider shadow-lg active:scale-95 transition font-mono">
                    <?= htmlspecialchars($settings['promo_popup_btn_text']) ?>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 2B. UNIVERSAL PAYMENT & DEPOSIT RESULT MODAL -->
    <div id="paymentResultModal" class="fixed inset-0 bg-black/95 backdrop-blur-md z-[99999] hidden flex items-center justify-center p-4 select-none">
        <div id="paymentResultCard" class="promo-popup-card rounded-[28px] w-full max-w-sm p-6 space-y-4 relative text-center">
            <button type="button" onclick="closePaymentResultModal()" class="absolute top-3.5 right-3.5 w-7 h-7 rounded-full bg-white/10 text-gray-300 hover:text-white flex items-center justify-center transition active:scale-95">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
            
            <div id="resultModalIconContainer" class="w-16 h-16 mx-auto rounded-full flex items-center justify-center text-3xl border-2 border-white shadow-2xl animate-bounce mt-1"></div>

            <div class="space-y-1">
                <span id="resultModalBadge" class="promo-shimmer-badge px-3 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest font-mono">STATUS</span>
                <h3 id="resultModalHeading" class="text-base sm:text-lg font-black text-white pt-1 font-bengali">Result Heading</h3>
            </div>

            <div id="resultModalBodyBox" class="p-3.5 rounded-2xl border space-y-2 text-left font-bengali"></div>

            <button type="button" id="resultModalActionBtn" onclick="closePaymentResultModal()" class="w-full py-3 rounded-xl text-gray-950 font-black text-xs uppercase tracking-wider shadow-lg active:scale-95 transition font-mono">
                বুঝে পেয়েছি &bull; ধন্যবাদ
            </button>
        </div>
    </div>

    <!-- 3. TOAST NOTIFICATION -->
    <div id="toastNotification" class="fixed top-3 left-1/2 transform -translate-x-1/2 z-[999999] transition-all duration-300 pointer-events-none opacity-0 translate-y-[-20px] w-[90%] max-w-sm">
        <div id="toastInner" class="bg-[#140826] border border-fuchsia-500/50 shadow-2xl rounded-2xl p-3 flex items-center space-x-2.5 text-xs font-semibold text-fuchsia-200 backdrop-blur-md font-mono">
            <i id="toastIcon" class="fa-solid fa-circle-check text-fuchsia-400 text-sm"></i>
            <span id="toastMsg">Notification</span>
        </div>
    </div>

    <!-- 4. TOP STICKY HEADER -->
    <header class="w-full flex items-center justify-between py-2.5 px-3 border-b border-[#180c2a] sticky top-0 z-30 bg-[#04010a]/95 backdrop-blur-md font-mono">
        <div class="flex items-center space-x-2 cursor-pointer" onclick="goToPage('dashboard')">
            <div class="w-9 h-9 rounded-2xl bg-gradient-to-tr from-fuchsia-600 to-purple-600 flex items-center justify-center text-white text-base font-bold shadow-md overflow-hidden flex-shrink-0 border border-fuchsia-500/40">
                <?php if (!empty($storeLogoUrl)): ?>
                    <img src="<?= htmlspecialchars($storeLogoUrl) ?>" alt="Logo" class="w-full h-full object-cover">
                <?php else: ?>
                    <i class="fa-solid fa-gem text-sm"></i>
                <?php endif; ?>
            </div>
            <div>
                <h1 class="hdr-store-name text-xs sm:text-sm font-black tracking-wider leading-none uppercase font-sans truncate max-w-[110px] sm:max-w-none">
                    <?= htmlspecialchars($storeTitleName) ?>
                </h1>
                <span class="hdr-portal-label text-[8px] sm:text-[9px] font-bold uppercase tracking-widest block mt-0.5">
                    <?= $isLoggedIn ? strtoupper($userRole) . ' PORTAL' : 'OFFICIAL STORE' ?>
                </span>
            </div>
        </div>

        <div class="flex items-center gap-1 sm:gap-1.5 flex-shrink-0">
            <!-- 🇧🇩 💲 🇮🇳 CUSTOM DROPDOWN CURRENCY SELECTOR -->
            <div class="relative font-mono">
                <button type="button" onclick="toggleCurrencyDropdown(event)" id="currentCurrencyBtn" class="hdr-chip hdr-currency-chip flex items-center space-x-1 px-2 py-1.5 rounded-xl text-xs font-bold transition active:scale-95">
                    <span id="currFlagIcon">🇧🇩</span>
                    <span id="currCodeLabel">BDT</span>
                    <i class="fa-solid fa-chevron-down text-[9px] ml-0.5" style="color:#60a5fa"></i>
                </button>

                <!-- Dropdown Menu -->
                <div id="currencyDropdownMenu" class="hidden absolute right-0 mt-2 w-28 bg-[#0c051a] border border-cyan-500/40 rounded-xl shadow-2xl z-50 overflow-hidden py-1">
                    <button type="button" onclick="selectCurrencyOption('BDT')" data-curr="BDT" class="currency-opt-btn w-full text-left px-3 py-2 text-xs font-bold text-gray-300 hover:text-white hover:bg-cyan-500/20 flex items-center space-x-2 transition">
                        <span>🇧🇩</span> <span>BDT (৳)</span>
                    </button>
                    <button type="button" onclick="selectCurrencyOption('USDT')" data-curr="USDT" class="currency-opt-btn w-full text-left px-3 py-2 text-xs font-bold text-gray-300 hover:text-white hover:bg-cyan-500/20 flex items-center space-x-2 transition">
                        <span>💲</span> <span>USDT ($)</span>
                    </button>
                    <button type="button" onclick="selectCurrencyOption('INR')" data-curr="INR" class="currency-opt-btn w-full text-left px-3 py-2 text-xs font-bold text-gray-300 hover:text-white hover:bg-cyan-500/20 flex items-center space-x-2 transition">
                        <span>🇮🇳</span> <span>INR (₹)</span>
                    </button>
                </div>
            </div>

            <?php if ($isLoggedIn): ?>
                <div class="hdr-chip hdr-coin-chip flex items-center space-x-1 px-2 py-1.5 rounded-xl" title="Your Reward Coins">
                    <i class="fa-solid fa-coins text-[11px]"></i>
                    <span id="liveHeaderCoins" class="text-xs font-black font-mono leading-none">
                        <?= number_format($userCoins) ?>
                    </span>
                </div>

                <div onclick="<?= $canAddFund === 1 ? "goToPage('addfund')" : "showToast('Deposits are currently offline', 'error')" ?>" class="hdr-chip hdr-balance-chip cursor-pointer flex items-center space-x-1 px-2 py-1.5 rounded-xl transition" id="headerBalanceChip" title="Your BDT Balance">
                    <span id="liveHeaderBalance" class="dynamic-price-el live-user-balance-el text-xs font-black font-mono leading-none" data-bdt="<?= (float)$userBalanceBdt ?>" data-cur="short">৳<?= number_format($userBalanceBdt, 0) ?></span>
                    <?php if ($canAddFund === 1): ?>
                        <i class="fa-solid fa-plus text-[8px] font-bold ml-0.5"></i>
                    <?php endif; ?>
                </div>

                <button type="button" onclick="openNotificationsModal()" class="hdr-chip hdr-bell-chip relative w-8 h-8 rounded-xl flex items-center justify-center active:scale-95 transition flex-shrink-0" aria-label="Notifications">
                    <i class="fa-solid fa-bell text-xs"></i>
                    <?php if ($notifCount > 0): ?>
                        <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-rose-500 text-white font-mono font-bold text-[8px] flex items-center justify-center shadow-[0_0_8px_#f43f5e] animate-pulse">
                            <?= $notifCount > 9 ? '9+' : $notifCount ?>
                        </span>
                    <?php endif; ?>
                </button>
            <?php else: ?>
                <button type="button" onclick="openAuthModal('login')" class="px-3.5 py-1.5 rounded-xl btn-magenta-glow text-white text-xs font-black uppercase tracking-wider flex items-center gap-1.5 shadow-md active:scale-95 transition">
                    <i class="fa-solid fa-right-to-bracket text-xs"></i>
                    <span>Log In</span>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <!-- 5. MAINTENANCE OVERLAY -->
    <?php if ($isSiteMaintenance): ?>
    <main class="w-full flex-grow flex items-center justify-center px-4 py-12 relative z-20">
        <div class="glass-card rounded-[28px] max-w-md w-full p-6 text-center space-y-4 border border-amber-500/50 shadow-[0_0_50px_rgba(245,158,11,0.25)]">
            <div class="w-16 h-16 mx-auto rounded-full bg-amber-500/20 text-amber-400 border-2 border-amber-400 flex items-center justify-center text-3xl shadow-[0_0_20px_rgba(245,158,11,0.4)]">
                <i class="fa-solid fa-gear fa-spin"></i>
            </div>
            
            <div class="space-y-1">
                <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest bg-amber-500/20 text-amber-300 border border-amber-400/40 font-mono">
                    STORE MAINTENANCE MODE
                </span>
                <h2 class="text-base sm:text-lg font-black text-white uppercase font-cyber tracking-wider pt-2">
                    Maintenance in Progress
                </h2>
                <p class="text-xs text-gray-300 leading-relaxed font-sans pt-1">
                    <?= nl2br(htmlspecialchars($settings['site_maintenance_msg'] ?? 'System upgrade in progress. We will be back online shortly.')) ?>
                </p>
            </div>

            <?php if (!empty($maintenanceTgLink)): ?>
                <a href="<?= htmlspecialchars($maintenanceTgLink) ?>" target="_blank" class="w-full py-3 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 text-gray-950 font-black text-xs uppercase tracking-wider flex items-center justify-center gap-2 shadow-lg active:scale-95 transition font-mono">
                    <i class="fa-brands fa-telegram text-base"></i> <span>Join Updates Channel</span>
                </a>
            <?php endif; ?>
        </div>
    </main>
    <?php else: ?>

    <!-- 6. MAIN VIEWPORT CONTAINER -->
    <main class="w-full flex-grow px-2 sm:px-4 pt-2 max-w-none md:max-w-5xl mx-auto space-y-3.5">

        <!-- 6A. STOREFRONT TAB -->
        <section id="page-dashboard" class="page-section w-full space-y-3">
            
            <!-- 1. CAROUSEL BANNER SLIDER -->
            <?php if (!empty($activeBanners)): ?>
            <div class="relative w-full rounded-2xl overflow-hidden border border-[#1d0d33] shadow-md">
                <div id="carouselTrack" class="flex transition-transform duration-500 ease-out">
                    <?php foreach ($activeBanners as $idx => $bn): ?>
                        <div class="min-w-full h-40 sm:h-52 relative flex-shrink-0">
                            <a href="<?= htmlspecialchars($bn['target_link'] ?: '#') ?>" target="_blank" class="block w-full h-full">
                                <img src="<?= htmlspecialchars($bn['image_url']) ?>" alt="Banner" class="w-full h-full object-cover" <?= $idx === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async">
                                <div class="absolute inset-0 bg-gradient-to-t from-[#04010a] via-transparent to-transparent"></div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="absolute bottom-2 left-1/2 -translate-x-1/2 flex space-x-1 z-10">
                    <?php foreach ($activeBanners as $idx => $bn): ?>
                        <button type="button" onclick="goToSlide(<?= $idx ?>)" class="carousel-dot w-2 h-2 rounded-full transition-all <?= $idx === 0 ? 'bg-[#d946ef] w-4 shadow-[0_0_6px_#d946ef]' : 'bg-gray-500/70' ?>" aria-label="Go to slide <?= $idx + 1 ?>"></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 2. STORIES PROFILE STRIP -->
            <?php if (!empty($catalogProducts)): ?>
            <div class="w-full bg-[#0a0414]/90 p-2 rounded-xl border border-[#1a0c2c]">
                <div class="flex items-center space-x-3 overflow-x-auto no-scrollbar py-0.5 px-0.5">
                    <?php foreach ($catalogProducts as $prStory): 
                        $avatarImg = !empty($prStory['logo_url']) ? $prStory['logo_url'] : $prStory['banner_url'];
                        $shortName = explode(' ', trim($prStory['name']))[0] ?? $prStory['name'];
                    ?>
                        <div class="flex flex-col items-center space-y-1 flex-shrink-0 cursor-pointer"
                             style="width: 58px;"
                             onclick="openProductModal(<?= $prStory['id'] ?>)">
                            <div class="story-avatar-ring">
                                <div class="w-full h-full rounded-full overflow-hidden bg-black flex items-center justify-center">
                                    <?php if (!empty($avatarImg)): ?>
                                        <img loading="lazy" decoding="async" src="<?= htmlspecialchars($avatarImg) ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fa-solid fa-gamepad text-fuchsia-400 text-xs"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="text-[9px] font-mono font-bold text-gray-300 w-full text-center truncate">
                                <?= htmlspecialchars($shortName) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 3. CATEGORY PILLS FILTER (EXACT SERIAL: ALL -> NONROOT -> ROOT -> IPHONE -> PC -> OTHER) -->
            <div class="flex items-center space-x-1.5 overflow-x-auto pb-0.5 no-scrollbar text-xs font-mono font-bold">
                <?php foreach ($availableCategories as $idx => $cat): ?>
                    <button type="button" onclick="filterByCategory('<?= $cat ?>', this)" class="category-pill <?= $idx === 0 ? 'active' : '' ?> px-3.5 py-1.5 rounded-xl flex items-center space-x-1 flex-shrink-0 text-[11px]">
                        <span><?= $cat ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- 4. PRODUCTS GRID -->
            <?php if (empty($catalogProducts)): ?>
                <div class="glass-card rounded-2xl p-8 text-center space-y-2 border border-dashed border-[#1f0f35]">
                    <i class="fa-solid fa-box-open text-3xl text-gray-600 block"></i>
                    <p class="text-xs text-gray-400 font-bold">No products assigned or currently available on this store.</p>
                </div>
            <?php else: ?>
                <div id="productsGrid" class="grid grid-cols-2 gap-2.5 sm:gap-3.5 pt-0.5">
                    <?php foreach ($catalogProducts as $prod): 
                        $planCount      = count($prod['plans']);
                        $minPriceBDT    = $planCount > 0 ? min(array_column($prod['plans'], 'price_bdt')) : 0;
                        $minOriginalBDT = $planCount > 0 ? min(array_column($prod['plans'], 'original_bdt')) : 0;
                        $maxRewardCoins = $planCount > 0 ? max(array_column($prod['plans'], 'reward_coins')) : 0;
                        
                        $coinPricesList = array_filter(array_column($prod['plans'], 'coin_price'));
                        $minCoinPrice   = !empty($coinPricesList) ? min($coinPricesList) : 0;

                        $bannerImg      = !empty($prod['banner_url']) ? $prod['banner_url'] : $prod['logo_url'];
                        $isProdMaint    = (int)($prod['is_active'] ?? 1) === 0 || (int)($prod['is_maintenance'] ?? 0) === 1;
                        $hasDiscount    = (int)($prod['max_discount'] ?? 0) > 0;
                    ?>
                        <div class="product-card <?= $isProdMaint ? 'product-card-maint' : '' ?> p-3 sm:p-3.5 cursor-pointer prod-item-box space-y-2.5"
                             data-category="<?= $prod['category'] ?>"
                             onclick="<?= $isProdMaint ? htmlspecialchars("showToast(" . json_encode('🔧 ' . (($prod['maintenance_msg'] ?? '') ?: 'This product is under maintenance')) . ", 'error')", ENT_QUOTES) : "openProductModal({$prod['id']})" ?>">
                            
                            <?php if ($isProdMaint): ?>
                                <div class="maint-overlay p-2 text-center">
                                    <i class="fa-solid fa-screwdriver-wrench text-rose-400 text-lg mb-1 maint-icon-pulse"></i>
                                    <div class="maint-watermark-text font-mono text-[11px] text-rose-400">MAINTENANCE</div>
                                    <?php if (!empty($prod['maintenance_msg'])): ?>
                                        <p class="text-[9px] text-amber-300 font-sans font-bold mt-1 px-1 line-clamp-2 leading-tight">
                                            <?= htmlspecialchars($prod['maintenance_msg']) ?>
                                        </p>
                                    <?php endif; ?>
                                    <span class="text-[8px] font-mono font-bold text-rose-300 mt-1 bg-black/80 px-2 py-0.5 rounded border border-rose-500/40">OFFLINE</span>
                                </div>
                            <?php endif; ?>

                            <div class="space-y-2">
                                <div class="flex items-center space-x-2">
                                    <div class="w-8 h-8 rounded-full bg-[#080312] border border-fuchsia-500/60 flex items-center justify-center text-fuchsia-400 font-bold overflow-hidden flex-shrink-0 shadow-sm">
                                        <?php if (!empty($prod['logo_url'])): ?>
                                            <img loading="lazy" decoding="async" src="<?= htmlspecialchars($prod['logo_url']) ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                        <?php else: ?>
                                            <i class="fa-solid fa-gamepad text-[10px]"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="overflow-hidden">
                                        <h3 class="font-extrabold text-white text-xs leading-none truncate">
                                            <?= htmlspecialchars($prod['name']) ?>
                                        </h3>
                                        <span class="text-[8px] text-fuchsia-400/80 font-mono block truncate mt-0.5"><?= htmlspecialchars($prod['tags'] ?: 'Verified Pass') ?></span>
                                    </div>
                                </div>

                                <div class="w-full h-28 sm:h-32 rounded-xl bg-[#06020c] border border-[#1b0c2c] overflow-hidden relative flex items-center justify-center shadow-inner">
                                    <?php if (!empty($bannerImg)): ?>
                                        <img loading="lazy" decoding="async" src="<?= htmlspecialchars($bannerImg) ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="w-full h-full bg-gradient-to-tr from-purple-950 to-fuchsia-950 flex items-center justify-center text-fuchsia-400 text-lg font-black">
                                            <i class="fa-solid fa-gem"></i>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($hasDiscount): ?>
                                        <span class="absolute top-1.5 left-1.5 px-2 py-0.5 rounded-lg text-[8px] font-black uppercase bg-gradient-to-r from-emerald-500 to-teal-400 text-gray-950 shadow-lg animate-pulse z-10 font-mono">
                                            <?= (int)$prod['max_discount'] ?>% OFF
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($maxRewardCoins > 0): ?>
                                        <span class="absolute bottom-1.5 left-1.5 px-2 py-0.5 rounded-lg text-[8px] font-black uppercase bg-gradient-to-r from-amber-500 to-yellow-400 text-gray-950 shadow-lg z-10 font-mono flex items-center gap-1">
                                            <i class="fa-solid fa-gift text-[8px]"></i> +<?= $maxRewardCoins ?> Coins Gift
                                        </span>
                                    <?php elseif ($minCoinPrice > 0): ?>
                                        <span class="absolute bottom-1.5 left-1.5 px-2 py-0.5 rounded-lg text-[8px] font-black uppercase bg-gradient-to-r from-cyan-500 to-blue-500 text-gray-950 shadow-lg z-10 font-mono flex items-center gap-1">
                                            <i class="fa-solid fa-coins text-[8px]"></i> 🪙 <?= number_format($minCoinPrice) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($prod['is_pinned']): ?>
                                        <span class="absolute top-1.5 right-1.5 px-1.5 py-0.2 rounded text-[7px] font-black uppercase bg-fuchsia-600 text-white z-10 font-mono">
                                            PINNED
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center justify-between text-[10px] font-mono">
                                    <span class="text-emerald-400 font-bold"><?= $planCount ?> Plan(s)</span>
                                    <span class="text-cyan-300 font-bold bg-[#100620] px-1.5 py-0.2 rounded border border-cyan-500/30">#<?= htmlspecialchars($prod['category']) ?></span>
                                </div>
                            </div>

                            <div class="pt-2 border-t border-[#180b26] flex items-center justify-between gap-1.5">
                                <div class="price-badge-buy-style flex-col items-start !gap-0">
                                    <?php if ($hasDiscount && $minOriginalBDT > $minPriceBDT): ?>
                                        <span class="text-[8px] text-white/70 line-through font-mono leading-none dynamic-price-el" data-cur="short" data-bdt="<?= $minOriginalBDT ?>">
                                            ৳<?= number_format($minOriginalBDT, 0) ?>
                                        </span>
                                    <?php endif; ?>
                                    <div class="flex items-center gap-1 leading-none mt-0.5">
                                        <span class="text-[7px] text-white/80 font-mono font-bold uppercase leading-none">FROM</span>
                                        <span class="text-xs sm:text-sm font-black text-white font-mono leading-none dynamic-price-el" data-bdt="<?= $minPriceBDT ?>">
                                            ৳<?= number_format((float)$minPriceBDT, 0) ?>
                                        </span>
                                    </div>
                                </div>

                                <?php if ($isProdMaint): ?>
                                    <button type="button" disabled class="py-1.5 px-3 rounded-xl bg-gray-800 text-gray-400 text-[10px] font-extrabold uppercase tracking-wider flex items-center gap-1 cursor-not-allowed font-mono">
                                        <i class="fa-solid fa-lock text-[8px]"></i> <span>OFFLINE</span>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="py-1.5 px-3 rounded-xl btn-buy-compact text-white text-[10px] font-extrabold uppercase tracking-wider flex items-center gap-1 shadow-md font-mono">
                                        <i class="fa-solid fa-cart-shopping text-[8px]"></i> <span>BUY</span>
                                    </button>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- 5. NEW: BOTTOM LIVE HISTORY WIDGET (3 ITEMS + MORE BUTTON + REAL PHOTO) -->
            <div class="bottom-history-box mt-5 font-sans">
                <!-- TABS (TOP BUYERS ON LEFT, RECENT ORDERS ON RIGHT) -->
                <div class="flex items-center justify-between border-b border-[#180c2a] pb-3">
                    <button type="button" onclick="switchBottomHistoryTab('top_buyers', this)" class="b-tab-btn">
                        <i class="fa-solid fa-trophy text-amber-400"></i>
                        <span>TOP BUYERS</span>
                    </button>
                    <button type="button" onclick="switchBottomHistoryTab('recent_orders', this)" class="b-tab-btn active">
                        <i class="fa-solid fa-bolt text-cyan-400"></i>
                        <span>RECENT ORDERS</span>
                    </button>
                </div>

                <!-- TAB 1: TOP BUYERS (HIDDEN BY DEFAULT) -->
                <div id="bTabTopBuyers" class="pt-3 space-y-2 hidden">
                    <div id="topBuyersListContainer" class="space-y-2">
                    <?php if (empty($topBuyers)): ?>
                        <div class="text-center py-6 text-gray-500 text-xs">
                            <i class="fa-solid fa-trophy text-2xl mb-1 text-gray-600 block"></i>
                            <p>No buyers recorded yet on this store.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($topBuyers as $i => $tb): 
                            $letter = strtoupper(substr($tb['username'], 0, 1));
                            $spentVal = round((float)$tb['spent'] * $effectiveBdtRate, 0);
                            $isHidden = ($i >= 3) ? 'hidden extra-top-buyer' : '';
                            $hasPhoto = (!empty($tb['avatar_url']) && strlen($tb['avatar_url']) > 15);
                        ?>
                            <div class="b-member-card <?= $isHidden ?>">
                                <div class="flex items-center space-x-3">
                                    <div class="b-avatar overflow-hidden">
                                        <?php if ($hasPhoto): ?>
                                            <img loading="lazy" decoding="async" src="<?= $tb['avatar_url'] ?>" class="w-full h-full object-cover rounded-full" alt="Avatar">
                                        <?php else: ?>
                                            <span><?= $letter ?></span>
                                        <?php endif; ?>
                                        <div class="b-medal-badge">
                                            <i class="fa-solid fa-medal"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-extrabold text-white leading-tight">
                                            <?= htmlspecialchars(maskUsername($tb['username'])) ?>
                                        </h4>
                                        <span class="inline-block px-2 py-0.5 rounded-full text-[9px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 mt-0.5">
                                            Top Member
                                        </span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <strong class="text-sm sm:text-base font-black text-[#00ff88] font-mono">
                                        <span class="dynamic-price-el" data-bdt="<?= (float)$spentVal ?>" data-cur="short">৳<?= number_format($spentVal, 0) ?></span>
                                    </strong>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>

                    <?php if (count($topBuyers) > 3): ?>
                        <div class="flex justify-end pt-1">
                            <button type="button" onclick="toggleMoreHistory('top_buyers', this)" class="px-3 py-1 rounded-xl bg-[#090317] border border-cyan-500/40 text-cyan-300 hover:text-white text-[10px] font-black uppercase tracking-wider flex items-center gap-1.5 transition active:scale-95 shadow-sm">
                                <span>More</span> <i class="fa-solid fa-chevron-down text-[9px]"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- TAB 2: RECENT ORDERS (SHOWN BY DEFAULT) -->
                <div id="bTabRecentOrders" class="pt-3 space-y-2">
                    <div id="recentOrdersListContainer" class="space-y-2">
                    <?php if (empty($recentOrdersList)): ?>
                        <div class="text-center py-6 text-gray-500 text-xs">
                            <i class="fa-solid fa-bag-shopping text-2xl mb-1 text-gray-600 block"></i>
                            <p>No recent orders found.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentOrdersList as $i => $ro): 
                            $letter = strtoupper(substr($ro['username'], 0, 1));
                            $paidVal = round((float)$ro['price'] * $effectiveBdtRate, 0);
                            $isHidden = ($i >= 3) ? 'hidden extra-recent-order' : '';
                            $hasPhoto = (!empty($ro['avatar_url']) && strlen($ro['avatar_url']) > 15);
                        ?>
                            <div class="b-member-card <?= $isHidden ?>">
                                <div class="flex items-center space-x-3">
                                    <div class="b-avatar border-cyan-500/60 shadow-[0_0_10px_rgba(0,245,255,0.25)] overflow-hidden">
                                        <?php if ($hasPhoto): ?>
                                            <img loading="lazy" decoding="async" src="<?= $ro['avatar_url'] ?>" class="w-full h-full object-cover rounded-full" alt="Avatar">
                                        <?php else: ?>
                                            <span class="text-cyan-300"><?= $letter ?></span>
                                        <?php endif; ?>
                                        <div class="b-medal-badge bg-gradient-to-tr from-cyan-400 to-blue-500 text-slate-950 shadow-[0_0_8px_rgba(0,245,255,0.6)]">
                                            <i class="fa-solid fa-bolt"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <h4 class="text-xs sm:text-sm font-extrabold text-white leading-tight">
                                            <?= htmlspecialchars(maskUsername($ro['username'])) ?>
                                        </h4>
                                        <span class="text-[9px] text-cyan-300/80 font-mono block mt-0.5 truncate max-w-[150px] sm:max-w-none">
                                            <?= htmlspecialchars($ro['validity']) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <strong class="text-sm font-black text-cyan-400 font-mono">
                                        <span class="dynamic-price-el" data-bdt="<?= (float)$paidVal ?>" data-cur="short">৳<?= number_format($paidVal, 0) ?></span>
                                    </strong>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>

                    <?php if (count($recentOrdersList) > 3): ?>
                        <div class="flex justify-end pt-1">
                            <button type="button" onclick="toggleMoreHistory('recent_orders', this)" class="px-3 py-1 rounded-xl bg-[#090317] border border-cyan-500/40 text-cyan-300 hover:text-white text-[10px] font-black uppercase tracking-wider flex items-center gap-1.5 transition active:scale-95 shadow-sm">
                                <span>More</span> <i class="fa-solid fa-chevron-down text-[9px]"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            </section>

        <!-- 6B. ORDERS HISTORY TAB -->
        <section id="page-history" class="page-section hidden w-full space-y-3 font-mono">
            <!-- KEY SEARCH BOX -->
            <div class="glass-card rounded-2xl p-3 border border-cyan-500/30 space-y-2">
                <label class="text-[10px] font-bold text-gray-300 flex items-center gap-1.5">
                    <i class="fa-solid fa-magnifying-glass text-cyan-400"></i> Search Your Key
                </label>
                <input type="text" id="orderKeySearch" oninput="searchUserKey(this.value)" 
                       placeholder="Paste or type license key to verify..." 
                       class="w-full bg-[#070210] border border-[#180c2a] px-3 py-2 rounded-xl text-xs font-mono text-cyan-300 outline-none focus:border-cyan-400">
                <div id="orderKeySearchResult" class="hidden p-3 rounded-xl text-xs"></div>
            </div>

            <div class="glass-card rounded-2xl p-4 sm:p-5 border border-[#1f0f35] space-y-3 shadow-xl">
                <div class="flex justify-between items-center pb-2 border-b border-[#180c2a]">
                    <h3 class="text-xs sm:text-sm font-bold text-white flex items-center gap-2 font-sans">
                        <i class="fa-solid fa-bag-shopping text-fuchsia-400"></i> My Purchased Licenses
                    </h3>
                    <span class="text-xs text-cyan-300 font-bold bg-[#100620] px-2 py-0.5 rounded-lg border border-cyan-500/30">
                        Total: <?= count($keys) ?>
                    </span>
                </div>

                <div class="space-y-2">
                    <?php if (!$isLoggedIn): ?>
                        <div class="text-center py-12 space-y-2.5 text-xs">
                            <i class="fa-solid fa-lock text-3xl mb-1 text-fuchsia-400 block"></i>
                            <p class="text-gray-300 font-bold font-sans">Please login to view your purchased license keys</p>
                            <button onclick="openAuthModal('login')" class="px-5 py-2.5 rounded-xl btn-magenta-glow text-white text-xs font-bold font-mono active:scale-95 transition">
                                Login to Account
                            </button>
                        </div>
                    <?php elseif (empty($keys)): ?>
                        <div class="text-center py-12 text-gray-500 text-xs font-sans space-y-1">
                            <i class="fa-solid fa-key text-3xl mb-1 text-gray-600 block"></i>
                            <p>No licenses purchased on this store yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($keys as $k): ?>
                            <div class="bg-[#080312] p-3 rounded-xl border border-[#1a0c2c] space-y-2 text-xs shadow-sm">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <strong class="text-white text-xs block font-sans"><?= htmlspecialchars($k['validity'] ?? 'Digital Key') ?></strong>
                                        <span class="text-[9px] text-gray-400 font-mono"><?= $k['created_at'] ?></span>
                                    </div>
                                    <div class="flex items-center space-x-1.5">
                                        <?php if (($k['payment_type'] ?? '') === 'coins'): ?>
                                            <span class="text-[8px] text-amber-400 bg-amber-500/15 px-2 py-0.5 rounded-md font-bold border border-amber-500/30 flex items-center gap-1">
                                                <i class="fa-solid fa-coins text-[8px]"></i> COINS PAY
                                            </span>
                                        <?php elseif (($k['payment_type'] ?? '') === 'direct_pay'): ?>
                                            <span class="text-[8px] text-cyan-400 bg-cyan-500/15 px-2 py-0.5 rounded-md font-bold border border-cyan-500/30 flex items-center gap-1">
                                                <i class="fa-solid fa-bolt text-[8px]"></i> DIRECT PAY
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[8px] text-emerald-400 bg-emerald-500/15 px-2 py-0.5 rounded-md font-bold border border-emerald-500/30 flex items-center gap-1">
                                                <i class="fa-solid fa-wallet text-[8px]"></i> WALLET
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="bg-[#0e061c] p-2 rounded-lg border border-[#200f38] flex justify-between items-center shadow-inner">
                                    <span class="text-fuchsia-300 font-bold select-all text-xs truncate mr-2 font-mono"><?= htmlspecialchars($k['license_key']) ?></span>
                                    <button onclick="copyToClipboard('<?= htmlspecialchars($k['license_key']) ?>', 'License key')" class="px-2.5 py-1 rounded-md btn-buy-compact text-white text-[9px] font-bold flex-shrink-0 active:scale-95 transition">
                                        Copy
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 6C. RESET HWID TAB -->
        <section id="page-resetkey" class="page-section hidden w-full space-y-3 font-mono">
            <div class="glass-card rounded-2xl p-4 sm:p-5 space-y-3.5 border border-cyan-500/40 shadow-xl">
                <div class="text-center pb-1">
                    <h2 class="text-xs sm:text-sm font-black tracking-wider text-white uppercase flex items-center justify-center gap-2 font-cyber">
                        <i class="fa-solid fa-arrows-rotate text-cyan-400"></i> RESET LICENSE HARDWARE ID
                    </h2>
                    <p class="text-[10px] text-purple-300/70 mt-0.5 font-sans">Unlink active license key from previous device</p>
                </div>

                <?php
                // 🔄 FF-PANEL STYLE: কোন কোন প্রোডাক্ট রিসেট দেওয়া যাবে — সামনে দেখাবে
                $resetKeyCountByProd = [];
                foreach ($userAllResetKeys as $__rk) {
                    $__pid = (int)($__rk['product_id'] ?? 0);
                    $resetKeyCountByProd[$__pid] = ($resetKeyCountByProd[$__pid] ?? 0) + 1;
                }
                $resettableProducts = [];
                $nonResettableNames = [];
                foreach ($catalogProducts as $__cp) {
                    if ((int)($__cp['reset_enabled'] ?? 1) === 1) $resettableProducts[] = $__cp;
                    else $nonResettableNames[] = $__cp['name'];
                }
                ?>
                <?php if (!empty($resettableProducts)): ?>
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-black text-cyan-300 uppercase tracking-wider flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Reset Available For</span>
                        <span class="text-[9px] text-gray-500 font-sans">Tap a product</span>
                    </div>
                    <div class="grid grid-cols-3 gap-2" id="resetProdGrid">
                        <?php foreach ($resettableProducts as $rp):
                            $rpCount = (int)($resetKeyCountByProd[(int)$rp['id']] ?? 0);
                            $rpLogo  = !empty($rp['logo_url']) ? $rp['logo_url'] : ($rp['banner_url'] ?? '');
                        ?>
                            <button type="button" onclick="selectResetProduct(<?= (int)$rp['id'] ?>)" data-reset-prod="<?= (int)$rp['id'] ?>" data-name="<?= htmlspecialchars($rp['name']) ?>" class="reset-prod-card relative flex flex-col items-center gap-1.5 p-2 rounded-2xl bg-[#0b0418] border border-cyan-500/25 hover:border-cyan-400 active:scale-95 transition text-center">
                                <div class="w-11 h-11 rounded-xl overflow-hidden bg-gradient-to-tr from-cyan-600 to-fuchsia-600 flex items-center justify-center text-white font-black text-sm border border-cyan-400/40">
                                    <?php if (!empty($rpLogo)): ?>
                                        <img loading="lazy" decoding="async" src="<?= htmlspecialchars($rpLogo) ?>" alt="" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <?= htmlspecialchars(strtoupper(mb_substr($rp['name'], 0, 1))) ?>
                                    <?php endif; ?>
                                </div>
                                <span class="text-[9px] font-bold text-white leading-tight line-clamp-2 font-sans w-full"><?= htmlspecialchars($rp['name']) ?></span>
                                <span class="text-[8px] font-mono font-bold px-1.5 py-0.5 rounded-full <?= $rpCount > 0 ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-cyan-500/10 text-cyan-400 border border-cyan-500/20' ?>">
                                    <?= $isLoggedIn ? ($rpCount > 0 ? $rpCount . ' KEY' . ($rpCount > 1 ? 'S' : '') : 'RESET ✓') : 'RESET ✓' ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($nonResettableNames)): ?>
                        <p class="text-[9px] text-gray-500 font-sans leading-snug">⛔ Reset not available: <?= htmlspecialchars(implode(', ', $nonResettableNames)) ?></p>
                    <?php endif; ?>
                    <div id="resetProdKeysBox" class="hidden rounded-2xl border border-cyan-500/30 bg-[#070210] p-2.5 space-y-1.5"></div>
                </div>
                <?php endif; ?>

                <?php if (!$isLoggedIn): ?>
                    <div class="text-center py-10 space-y-2.5 text-xs">
                        <i class="fa-solid fa-lock text-3xl mb-1 text-cyan-400 block"></i>
                        <p class="text-gray-300 font-bold font-sans">Please login to manage and reset your keys</p>
                        <button onclick="openAuthModal('login')" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 text-gray-950 text-xs font-black font-mono active:scale-95 transition">
                            Login Now
                        </button>
                    </div>
                <?php else: ?>
                    <form id="liveResetForm" onsubmit="handleLiveKeyReset(event)" class="space-y-3.5 text-xs">
                        <!-- ১. ড্রপডাউন সিলেক্ট -->
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold text-cyan-300 flex items-center gap-1 font-sans">
                                <i class="fa-solid fa-list-check"></i> 1. Select Key from Your Orders:
                            </label>
                            <select id="resetKeyDropdown" onchange="onResetDropdownSelect(this.value)" class="w-full bg-[#070210] border border-[#180c2a] text-xs text-white rounded-xl p-2.5 outline-none focus:border-cyan-400 font-mono">
                                <option value="">-- Choose Purchased Key --</option>
                                <?php foreach ($userAllResetKeys as $rk): ?>
                                    <option value="<?= htmlspecialchars($rk['license_key']) ?>">
                                        <?= htmlspecialchars($rk['validity'] ?? 'Key') ?> — <?= htmlspecialchars($rk['license_key']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- ২. ম্যানুয়াল টাইপ বা পেস্ট -->
                        <div class="relative space-y-1">
                            <label class="text-[10px] font-bold text-gray-300 flex items-center gap-1 font-sans">
                                <i class="fa-solid fa-keyboard"></i> 2. Or Type / Paste Key Manually:
                            </label>
                            <input type="text" id="resetKeySearchInput" autocomplete="off" placeholder="Paste license key here..." oninput="onResetSearchInput(this.value)" class="w-full bg-[#070210] border border-[#180c2a] text-xs text-cyan-300 rounded-xl p-2.5 outline-none focus:border-cyan-400 uppercase font-mono shadow-inner font-bold">
                            <div id="resetSearchResultsBox" class="hidden absolute left-0 right-0 top-full mt-1 bg-[#0d061c] border border-cyan-500/40 rounded-xl max-h-48 overflow-y-auto z-30 shadow-2xl p-1.5 space-y-1 no-scrollbar"></div>
                        </div>

                        <button type="submit" id="resetSubmitBtn" class="w-full py-3 rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 text-gray-950 font-black text-xs uppercase tracking-wider transition active:scale-95 shadow-lg">
                            Submit HWID Reset
                        </button>
                    </form>

                    <div id="resetResultBox" class="hidden p-3 rounded-xl border text-xs leading-relaxed font-sans shadow-md"></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- 6D. PROFILE & WALLET LEDGER TAB -->
        <section id="page-profile" class="page-section hidden w-full space-y-3 font-mono">
            <?php if (!$isLoggedIn): ?>
                <div class="glass-card rounded-2xl p-8 text-center space-y-3 border border-fuchsia-500/40 shadow-xl">
                    <div class="w-16 h-16 mx-auto rounded-full bg-fuchsia-500/20 text-fuchsia-400 flex items-center justify-center text-3xl">
                        <i class="fa-solid fa-user-lock"></i>
                    </div>
                    <h3 class="text-sm font-black text-white uppercase tracking-wider font-cyber">Authentication Required</h3>
                    <p class="text-xs text-gray-400 font-sans">Please sign in or create an account to access profile, wallet ledger, and order history on this store.</p>
                    <div class="flex justify-center gap-2 pt-2">
                        <button type="button" onclick="openAuthModal('login')" class="px-5 py-2.5 rounded-xl btn-magenta-glow text-white text-xs font-black uppercase font-mono active:scale-95 transition shadow-lg">Log In</button>
                        <button type="button" onclick="openAuthModal('register')" class="px-5 py-2.5 rounded-xl bg-[#0f061e] border border-cyan-400 text-cyan-300 text-xs font-black uppercase font-mono active:scale-95 transition shadow-lg">Register</button>
                    </div>
                </div>
            <?php else: ?>
                <!-- PROFILE HERO CARD -->
                <div class="glass-card rounded-3xl p-5 text-center border border-fuchsia-500/40 relative shadow-xl">
                                        <button type="button" onclick="openEditProfileModal()" class="absolute top-4 right-4 w-9 h-9 rounded-full bg-fuchsia-500/20 border border-fuchsia-500/50 text-fuchsia-300 hover:bg-fuchsia-500/30 flex items-center justify-center transition active:scale-95 shadow-md" title="Edit Profile">
                        <i class="fa-solid fa-pen text-xs"></i>
                    </button>

                    <!-- Profile Photo — tap pencil to change -->
                    <input type="file" id="avatarFileInput" name="avatar_file" accept="image/jpeg,image/png,image/webp" class="hidden">
                    <div class="w-24 h-24 mx-auto rounded-full bg-[#0a0316] flex items-center justify-center text-white text-4xl font-black border-4 border-fuchsia-500/60 shadow-[0_0_25px_rgba(217,70,239,0.4)] relative overflow-hidden cursor-pointer group" onclick="document.getElementById('avatarFileInput').click()" title="Tap to change photo">
                        <?php if (!empty($user['avatar_url']) && strlen($user['avatar_url']) > 15): ?>
                            <img id="avatarPreviewImg" src="<?= $user['avatar_url'] ?>" class="w-full h-full object-cover rounded-full" alt="Profile">
                        <?php else: ?>
                            <div id="avatarInitialDiv" class="w-full h-full bg-gradient-to-tr from-fuchsia-600 to-purple-600 flex items-center justify-center">
                                <?= strtoupper(substr($user['username'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div class="absolute bottom-0 right-0 w-7 h-7 bg-fuchsia-600 rounded-full flex items-center justify-center border-2 border-[#0a0316] shadow-lg">
    <i class="fa-solid fa-camera text-white text-[10px]"></i>
</div>
                    </div>
                    
                    <h2 class="text-base sm:text-lg font-black text-white mt-3 uppercase font-sans"><?= htmlspecialchars($user['username']) ?></h2>
                    <span class="inline-block px-2.5 py-0.5 rounded-md text-[9px] font-bold uppercase bg-cyan-950 text-cyan-300 border border-cyan-500/30 font-mono mt-1"><?= $userRole ?></span>
                    
                    <div class="grid grid-cols-2 gap-2 mt-4">
                        <div class="bg-[#0f061e] p-3 rounded-2xl border border-emerald-500/30">
                            <span class="text-[9px] text-gray-400 block font-sans">Wallet Balance</span>
                            <strong class="text-[#00ff88] text-base font-mono profile-wallet-balance-el" data-bdt="<?= $userBalanceBdt ?>">৳<?= number_format($userBalanceBdt, 0) ?></strong>
                        </div>
                        <div class="bg-[#0f061e] p-3 rounded-2xl border border-amber-500/30">
                            <span class="text-[9px] text-gray-400 block font-sans">Tokens</span>
                            <strong class="text-amber-400 text-base font-mono">🪙 <?= number_format($userCoins) ?></strong>
                        </div>
                    </div>
                </div>

                <!-- REFERRAL CARD -->
                <?php 
                    $shareProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                    $shareHost = $_SERVER['HTTP_HOST'] ?? 'vaipanel.shop';
                    $userRefCode = !empty($user['referral_code']) ? $user['referral_code'] : 'VIP'.rand(1000,9999);
                    $fullReferLink = $shareProtocol . $shareHost . "/?ref=" . $userRefCode;
                ?>
                <div class="glass-card rounded-2xl p-4 border border-cyan-500/30 space-y-2.5">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-bold text-white flex items-center gap-1.5 font-sans">
                            <i class="fa-solid fa-users text-cyan-400"></i> Invite &amp; Earn Tokens
                        </h3>
                        <span class="text-[9px] text-amber-300 bg-amber-500/15 px-2 py-0.5 rounded-full border border-amber-500/30 font-mono">
                            Code: <?= htmlspecialchars($userRefCode) ?>
                        </span>
                    </div>

                    <div class="flex gap-1.5">
                        <input type="text" readonly value="<?= htmlspecialchars($fullReferLink) ?>" 
                               id="userReferralLinkInput"
                               class="flex-1 bg-[#070210] border border-[#180c2a] px-3 py-2 rounded-xl text-cyan-300 text-[10px] font-mono select-all truncate">
                        <button type="button" onclick="copyToClipboard('<?= htmlspecialchars($fullReferLink) ?>', 'Referral Link')" 
                                class="px-3 rounded-xl btn-buy-compact text-white text-[11px] font-bold flex-shrink-0 active:scale-95 transition">
                            Copy Link
                        </button>
                    </div>
                    <p class="text-[10px] text-gray-400 font-sans">Share your link — earn tokens &amp; cash bonus per referral signup!</p>
                </div>

                <!-- STATS SUMMARY -->
                <div class="glass-card rounded-2xl p-4 border border-[#180c2a] space-y-2">
                    <h3 class="text-xs font-bold text-white mb-2 flex items-center gap-1.5 font-sans">
                        <i class="fa-solid fa-chart-line text-emerald-400"></i> Account Summary
                    </h3>
                    <div class="grid grid-cols-2 gap-2 text-[10px]">
                        <div class="bg-[#070210] p-2.5 rounded-xl">
                            <span class="text-gray-400 font-sans">Total Deposits</span>
                            <?php $__totalDepBdt = array_sum(array_map(fn($d)=> (float)$d['amount'], $deposits)) * $effectiveBdtRate; ?><strong class="block text-emerald-400 text-sm mt-0.5 font-mono dynamic-price-el" data-cur="short" data-bdt="<?= (float)$__totalDepBdt ?>">৳<?= number_format($__totalDepBdt, 0) ?></strong>
                        </div>
                        <div class="bg-[#070210] p-2.5 rounded-xl">
                            <span class="text-gray-400 font-sans">Keys Purchased</span>
                            <strong class="block text-fuchsia-400 text-sm mt-0.5 font-mono"><?= count($keys) ?></strong>
                        </div>
                        <div class="bg-[#070210] p-2.5 rounded-xl">
                            <span class="text-gray-400 font-sans">Tokens Earned</span>
                            <strong class="block text-amber-400 text-sm mt-0.5 font-mono">🪙 <?= number_format($userCoins) ?></strong>
                        </div>
                        <div class="bg-[#070210] p-2.5 rounded-xl">
                            <span class="text-gray-400 font-sans">Total Orders</span>
                            <strong class="block text-cyan-400 text-sm mt-0.5 font-mono"><?= count($deposits) ?></strong>
                        </div>
                    </div>
                </div>

                <!-- TRANSACTION LEDGER -->
                <div class="glass-card rounded-2xl p-4 border border-[#180c2a] space-y-2.5 shadow-xl">
                    <div class="flex justify-between items-center pb-1 border-b border-[#180c2a]">
                        <h3 class="text-xs font-bold text-white flex items-center gap-1.5 font-sans">
                            <i class="fa-solid fa-money-bill-transfer text-emerald-400"></i> Transaction History
                        </h3>
                        <span class="text-[9px] text-gray-400 font-mono">Recent 20</span>
                    </div>
                    <div class="space-y-1.5 max-h-48 overflow-y-auto no-scrollbar">
                        <?php if (empty($deposits)): ?>
                            <p class="text-xs text-gray-500 text-center py-4 font-sans">No deposits or transactions logged on this store yet.</p>
                        <?php else: ?>
                            <?php foreach($deposits as $d): ?>
                                <div class="bg-[#070210] p-2.5 rounded-xl border border-[#180c2a] flex justify-between items-center text-xs shadow-inner">
                                    <div>
                                        <span class="font-bold text-white block text-[10px] font-sans">
                                            <?= htmlspecialchars($d['method']) ?> • <span class="dynamic-price-el" data-bdt="<?= (float)$d['amount'] * $effectiveBdtRate ?>">৳<?= number_format((float)$d['amount'] * $effectiveBdtRate, 0) ?> BDT</span>
                                            <?php if ((float)($d['bonus_amount'] ?? 0) > 0): ?>
                                                <span class="text-amber-400 font-mono font-bold">(<span class="dynamic-price-el" data-cur="short" data-prefix="+" data-bdt="<?= (float)$d['bonus_amount'] ?>">+৳<?= number_format((float)$d['bonus_amount'], 0) ?></span> Bonus)</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="text-[8px] text-gray-400 font-mono">Trx: <?= htmlspecialchars($d['trx_id']) ?></span>
                                    </div>
                                    <span class="text-[8px] font-bold px-2 py-0.5 rounded font-mono <?= $d['status'] === 'approved' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/40' : ($d['status'] === 'pending' ? 'bg-amber-500/20 text-amber-400 border border-amber-500/40' : 'bg-red-500/20 text-red-400 border border-red-500/40') ?>">
                                        <?= strtoupper($d['status']) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pt-1 text-center">
                    <a href="index.php?logout=1" class="inline-flex items-center space-x-1.5 px-5 py-2.5 rounded-xl bg-red-950/40 border border-red-500/30 text-red-400 text-xs font-bold hover:bg-red-900/40 transition font-mono active:scale-95 shadow-md">
                        <i class="fa-solid fa-power-off"></i>
                        <span>Log Out</span>
                    </a>
                </div>
            <?php endif; ?>
        </section>

        <!-- 6E. DYNAMIC ADD FUND SECTION -->
        <section id="page-addfund" class="page-section hidden w-full space-y-3 font-mono">
            <?php if (!$isLoggedIn): ?>
                <div class="glass-card rounded-2xl p-8 text-center space-y-3 border border-emerald-500/40 shadow-xl">
                    <div class="w-16 h-16 mx-auto rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-3xl">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                    <h3 class="text-sm font-black text-white uppercase tracking-wider font-cyber">Login to Deposit</h3>
                    <p class="text-xs text-gray-400 font-sans">You must be logged in to deposit balance into your store wallet.</p>
                    <button type="button" onclick="openAuthModal('login')" class="px-5 py-2.5 rounded-xl btn-buy-compact text-white text-xs font-black uppercase font-mono active:scale-95 transition shadow-lg">
                        Sign In Now
                    </button>
                </div>
            <?php elseif ($canAddFund !== 1): ?>
                <div class="glass-card rounded-2xl p-8 text-center space-y-2 border border-rose-500/40 shadow-xl">
                    <div class="w-14 h-14 mx-auto rounded-full bg-rose-500/20 text-rose-400 flex items-center justify-center text-2xl">
                        <i class="fa-solid fa-ban"></i>
                    </div>
                    <h3 class="text-sm font-black text-white uppercase tracking-wider font-cyber">Deposit System Offline</h3>
                    <p class="text-xs text-gray-400 font-sans">The Add Fund system is currently disabled by admin on this store.</p>
                </div>
            <?php else: ?>
                <!-- CONTAINER 1: PAYMENT METHOD CHANNELS LIST VIEW -->
                <div id="addFundMethodListContainer" class="glass-card rounded-2xl p-4 sm:p-5 space-y-3.5 border border-fuchsia-500/40 shadow-xl">
                    <div class="flex justify-between items-center pb-2 border-b border-[#180c2a]">
                        <div>
                            <h3 class="text-xs sm:text-sm font-extrabold text-white flex items-center gap-1.5 font-sans">
                                <i class="fa-solid fa-wallet text-fuchsia-400"></i> Deposit Balance Gateway
                            </h3>
                            <span class="text-[9px] text-gray-400 font-mono">Select a payment method to open its dedicated payment page</span>
                        </div>
                    </div>

                    <?php if (!empty($activeDepositBonuses)): ?>
                    <div class="deposit-bonus-container p-3 sm:p-3.5 space-y-2">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <span class="w-7 h-7 rounded-lg bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-xs shadow-[0_0_10px_#10b981]">
                                    <i class="fa-solid fa-gift"></i>
                                </span>
                                <div>
                                    <h4 class="text-xs font-black text-white font-sans flex items-center gap-1">
                                        Instant Deposit Booster!
                                    </h4>
                                    <span class="text-[8px] text-cyan-300 font-mono">Automated wallet cashback rewards on every deposit</span>
                                </div>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-[8px] font-black uppercase bg-emerald-500 text-gray-950 font-mono shadow-[0_0_8px_#10b981] animate-pulse">
                                ACTIVE
                            </span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-1.5 pt-1">
                            <?php foreach ($activeDepositBonuses as $bTier): ?>
                                <div class="bg-[#0b0318]/90 border border-emerald-500/40 p-2 rounded-xl flex items-center justify-between shadow-inner">
                                    <div>
                                        <span class="text-[9px] text-gray-400 font-mono block">Deposit <span class="dynamic-price-el" data-cur="short" data-bdt="<?= (float)$bTier['min_deposit'] ?>">৳<?= number_format((float)$bTier['min_deposit'], 0) ?></span>+</span>
                                        <strong class="text-xs font-black text-[#00ff88] font-mono"><span class="dynamic-price-el" data-cur="short" data-prefix="+" data-bdt="<?= (float)$bTier['bonus_amount'] ?>">+৳<?= number_format((float)$bTier['bonus_amount'], 0) ?></span> Bonus</strong>
                                    </div>
                                    <span class="text-[7px] text-cyan-300 bg-cyan-950/60 px-1.5 py-0.5 rounded font-bold border border-cyan-500/30">
                                        <?= htmlspecialchars($bTier['title'] ?? 'Bonus') ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Dynamic Methods Grid -->
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold text-gray-300 uppercase block font-sans">Available Deposit Channels:</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs font-sans">
                            <?php 
                            $addFundMethodsCount = 0;
                            foreach ($activePaymentMethods as $pm): 
                                if (isset($pm['allow_add_fund']) && (int)$pm['allow_add_fund'] === 0) continue;
                                $addFundMethodsCount++;
                            ?>
                                <div onclick="openDedicatedAddFundScreen(<?= (int)$pm['id'] ?>)" class="pay-method-choice-card">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 rounded-xl bg-purple-950/80 border border-fuchsia-500/40 flex items-center justify-center text-sm font-bold text-white overflow-hidden flex-shrink-0 shadow-sm">
                                            <?php if (!empty($pm['logo_url'])): ?>
                                                <img loading="lazy" decoding="async" src="<?= htmlspecialchars($pm['logo_url']) ?>" class="w-full h-full object-cover" alt="<?= htmlspecialchars($pm['name']) ?>">
                                            <?php else: ?>
                                                <i class="fa-solid fa-money-bill-wave text-fuchsia-400"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <strong class="text-white block text-xs font-sans"><?= htmlspecialchars($pm['name']) ?></strong>
                                            <span class="text-[9px] text-gray-400 font-mono"><?= htmlspecialchars($pm['account_type']) ?> • Instant Verification</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-1.5">
                                        <span class="text-[9px] text-fuchsia-400 font-bold font-mono">Deposit</span>
                                        <i class="fa-solid fa-chevron-right text-xs text-gray-400"></i>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div onclick="openDedicatedAddFundBinance()" class="pay-method-choice-card">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 rounded-xl bg-yellow-950/80 border border-yellow-500/40 flex items-center justify-center text-sm font-bold text-yellow-400 flex-shrink-0 shadow-sm">
                                        <i class="fa-brands fa-bitcoin text-base"></i>
                                    </div>
                                    <div>
                                        <strong class="text-white block text-xs font-sans">Binance Pay (USDT)</strong>
                                        <span class="text-[9px] text-gray-400 font-mono">Crypto Pay via Binance UID</span>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-1.5">
                                    <span class="text-[9px] text-yellow-400 font-bold font-mono">Deposit USDT</span>
                                    <i class="fa-solid fa-chevron-right text-xs text-gray-400"></i>
                                </div>
                            </div>
                        </div>

                        <?php if ($addFundMethodsCount === 0): ?>
                            <p class="text-xs text-gray-500 text-center py-4 font-sans">No BDT deposit channels are currently enabled on this store.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- CONTAINER 2: DEDICATED ADD FUND PAYMENT SCREEN -->
                <div id="addFundDedicatedScreen" class="hidden glass-card rounded-2xl p-4 sm:p-5 space-y-3.5 border border-fuchsia-500/50 shadow-2xl">
                    <div class="flex items-center justify-between pb-1 border-b border-[#180c2a]">
                        <button type="button" onclick="closeDedicatedAddFundScreen()" class="px-2.5 py-1 rounded-lg bg-white/10 text-gray-300 hover:text-white text-[10px] font-bold flex items-center gap-1 transition active:scale-95">
                            <i class="fa-solid fa-arrow-left"></i> <span>Choose Another Channel</span>
                        </button>
                        <span id="addFundDedicatedMethodTitle" class="text-xs font-black text-fuchsia-400 font-sans uppercase">bKash Deposit</span>
                    </div>

                    <div class="w-full h-28 sm:h-36 rounded-xl bg-[#090317] border border-fuchsia-500/40 overflow-hidden relative shadow-inner">
                        <img loading="lazy" decoding="async" id="addFundBannerImg" src="<?= htmlspecialchars(!empty($globalPaymentBanner) ? $globalPaymentBanner : 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&q=80') ?>" alt="Deposit Banner" class="w-full h-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-t from-[#04010a] via-transparent to-transparent"></div>
                        <span id="addFundBannerBadge" class="absolute bottom-1.5 left-2 px-2 py-0.5 rounded text-[8px] font-black uppercase bg-fuchsia-600/90 text-white font-mono shadow">
                            OFFICIAL DEPOSIT GATEWAY
                        </span>
                    </div>

                    <div class="bg-[#070210] p-3 rounded-2xl border border-[#1f0f35] space-y-2 shadow-inner text-xs">
                        <div class="flex items-center justify-between">
                            <div>
                                <span id="addFundTypeLabel" class="text-[8px] text-gray-400 block uppercase font-sans">Personal Account Number:</span>
                                <strong id="addFundNumberVal" class="text-white text-sm font-mono tracking-wider">01700000000</strong>
                            </div>
                            <button type="button" onclick="copyToClipboard(document.getElementById('addFundNumberVal').innerText, 'Account Number')" class="px-3 py-1.5 rounded-xl btn-buy-compact text-white text-[9px] font-bold active:scale-95 transition shadow-sm">
                                <i class="fa-regular fa-copy mr-1"></i> Copy
                            </button>
                        </div>

                        <div id="addFundInstructionBox" class="bg-[#05010b] p-2.5 rounded-xl border border-white/5 text-[10px] text-gray-300 font-sans leading-relaxed">
                            Send Money to the number above, then enter the deposit amount and Transaction ID (TrxID) below to verify instantly.
                        </div>
                    </div>

                    <div class="space-y-1 text-xs">
                        <div class="flex justify-between items-center mb-0.5 font-mono">
                            <label class="text-[9px] text-gray-400 font-bold font-sans">Amount to Deposit (BDT / USDT) <span class="text-fuchsia-400">*</span></label>
                            <span id="addFundLiveConversionTag" class="text-[8px] text-emerald-400 font-bold font-mono">≈ ৳0 BDT</span>
                        </div>
                        <input type="number" step="any" id="addFundAmountInput" oninput="calculateAddFundLiveConversion()" required placeholder="e.g. 500" class="w-full bg-[#05010b] border border-fuchsia-500/40 p-2.5 rounded-xl text-white outline-none focus:border-fuchsia-400 text-xs font-mono shadow-inner transition">
                    </div>

                    <div class="space-y-1 text-xs">
                        <label class="text-[10px] font-bold text-gray-300 flex items-center justify-between font-sans">
                            <span>Transaction ID (TrxID) <span class="text-fuchsia-400">*</span></span>
                            <span class="text-[8px] text-cyan-400 font-mono">Instant Verification</span>
                        </label>
                        <input type="text" id="addFundTrxIdInput" placeholder="Enter SMS TrxID (e.g. 9A8B7C6D)" class="w-full bg-[#05010b] border border-fuchsia-500/40 p-2.5 rounded-xl text-fuchsia-300 uppercase outline-none focus:border-fuchsia-400 text-xs font-mono shadow-inner tracking-wider transition">
                    </div>

                    <div class="space-y-0.5 text-xs">
                        <label class="text-[9px] text-gray-400 block font-sans">Sender Mobile No / UID (Optional)</label>
                        <input type="text" id="addFundSenderInput" placeholder="Sender Number or UID (Optional)" class="w-full bg-[#05010b] border border-[#180c2a] p-2 rounded-xl text-white outline-none focus:border-fuchsia-400 text-xs font-mono shadow-inner transition">
                    </div>

                    <div id="addFundOrderError" class="hidden p-2.5 rounded-xl bg-red-950/90 border border-red-500 text-red-300 text-[10px] font-bold text-center leading-relaxed font-sans shadow-md"></div>
                    <div id="addFundOrderSuccess" class="hidden p-2.5 rounded-xl bg-emerald-950/90 border border-emerald-500 text-emerald-300 text-[10px] font-bold text-center leading-relaxed font-sans shadow-md"></div>

                    <button type="button" id="btnVerifyAddFund" onclick="submitAddFundVerification()" class="w-full py-3 rounded-xl btn-magenta-glow text-white font-black text-xs uppercase tracking-wider flex items-center justify-center gap-1.5 shadow-md transition font-mono active:scale-95">
                        <i class="fa-solid fa-shield-check text-xs"></i> <span>Verify &amp; Add Balance</span>
                    </button>
                </div>
            <?php endif; ?>
        </section>

    </main>
    <?php endif; ?>

    <!-- 7. AUTHENTICATION MODAL -->
    <div id="authModal" class="fixed inset-0 bg-black/90 backdrop-blur-md z-[999] hidden flex items-center justify-center p-3 sm:p-4 font-mono select-none overflow-y-auto">
        <div class="glass-card rounded-[28px] w-full max-w-sm p-5 sm:p-6 space-y-4 border border-fuchsia-500/60 shadow-[0_0_45px_rgba(217,70,239,0.35)] relative my-auto">
            <button type="button" onclick="closeAuthModal()" class="absolute top-4 right-4 w-8 h-8 rounded-full bg-white/10 text-gray-400 hover:text-white flex items-center justify-center transition active:scale-95 shadow-sm">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>

            <div class="text-center space-y-1 pt-1">
                <div class="w-12 h-12 mx-auto rounded-2xl bg-gradient-to-tr from-fuchsia-600 to-purple-600 flex items-center justify-center text-white text-xl shadow-lg border border-fuchsia-400/40">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <h3 class="text-sm sm:text-base font-black text-white uppercase tracking-wider font-cyber">
                    <?= htmlspecialchars($storeTitleName) ?>
                </h3>
                <p class="text-[10px] text-gray-400 font-sans">
                    Fast &amp; Secure Digital Client Portal
                </p>
            </div>

            <div class="flex bg-[#06020c] p-1.5 rounded-xl border border-[#180c2a] text-xs font-bold shadow-inner">
                <button type="button" id="tabAuthLogin" onclick="switchAuthTab('login')" class="flex-1 py-2 rounded-lg bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-black transition-all font-mono shadow-md">
                    Log In
                </button>
                <button type="button" id="tabAuthRegister" onclick="switchAuthTab('register')" class="flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition-all font-mono">
                    Register
                </button>
            </div>

            <form id="formAuthLogin" method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="auth_action" value="user_login">
                <div class="space-y-1">
                    <label class="text-[10px] text-gray-300 font-bold flex items-center gap-1 font-sans">
                        <i class="fa-solid fa-user text-fuchsia-400"></i> Username or Email
                    </label>
                    <input type="text" name="login_identity" required autocomplete="username" placeholder="Username or Gmail" class="w-full bg-[#070210] border border-[#180c2a] px-3 py-2.5 rounded-xl text-white outline-none focus:border-fuchsia-400 text-xs font-mono transition shadow-inner">
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] text-gray-300 font-bold flex items-center gap-1 font-sans">
                        <i class="fa-solid fa-lock text-fuchsia-400"></i> Password
                    </label>
                    <input type="password" name="login_password" required autocomplete="current-password" placeholder="••••••••" class="w-full bg-[#070210] border border-[#180c2a] px-3 py-2.5 rounded-xl text-white outline-none focus:border-fuchsia-400 text-xs font-mono transition shadow-inner">
                </div>
                <button type="submit" class="w-full py-3 rounded-xl btn-magenta-glow text-white font-black text-xs uppercase tracking-wider shadow-lg font-mono active:scale-95 transition">
                    Sign In to Portal
                </button>
            </form>

            <form id="formAuthRegister" method="POST" class="hidden space-y-2.5 text-xs">
                <input type="hidden" name="auth_action" value="user_register">
                <div class="space-y-1">
                    <label class="text-[10px] text-gray-300 font-bold flex items-center gap-1 font-sans">
                        <i class="fa-solid fa-id-badge text-cyan-400"></i> Choose Username
                    </label>
                    <input type="text" name="reg_username" required autocomplete="username" placeholder="e.g. gamer_pro" class="w-full bg-[#070210] border border-[#180c2a] px-3 py-2 rounded-xl text-white outline-none focus:border-cyan-400 text-xs font-mono transition shadow-inner">
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] text-gray-300 font-bold flex items-center gap-1 font-sans">
                        <i class="fa-solid fa-envelope text-cyan-400"></i> Gmail Address (Optional)
                    </label>
                    <input type="email" name="reg_email" autocomplete="email" placeholder="gamer@gmail.com" class="w-full bg-[#070210] border border-[#180c2a] px-3 py-2 rounded-xl text-white outline-none focus:border-cyan-400 text-xs font-mono transition shadow-inner">
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] text-gray-300 font-bold flex items-center gap-1 font-sans">
                        <i class="fa-solid fa-key text-cyan-400"></i> Create Password
                    </label>
                    <input type="password" name="reg_password" required autocomplete="new-password" placeholder="Minimum 6 characters" class="w-full bg-[#070210] border border-[#180c2a] px-3 py-2 rounded-xl text-white outline-none focus:border-cyan-400 text-xs font-mono transition shadow-inner">
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] text-gray-300 font-bold flex items-center gap-1 font-sans">
                        <i class="fa-solid fa-users text-amber-400"></i> Referral Code (Optional)
                    </label>
                    <input type="text" name="reg_referral" value="<?= htmlspecialchars($_GET['ref'] ?? '') ?>" placeholder="Enter referral code" class="w-full bg-[#070210] border border-[#180c2a] px-3 py-2 rounded-xl text-amber-300 uppercase outline-none focus:border-amber-400 text-xs font-mono transition shadow-inner">
                </div>
                <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 text-gray-950 font-black text-xs uppercase tracking-wider shadow-lg mt-1 font-mono active:scale-95 transition">
                    Create New Account
                </button>
            </form>

            <?php if ($isFirebaseEnabled): ?>
            <div class="relative flex py-1 items-center">
                <div class="flex-grow border-t border-[#1f0f35]"></div>
                <span class="flex-shrink mx-2 text-[9px] text-gray-500 uppercase font-bold font-mono tracking-widest">OR QUICK LOGIN</span>
                <div class="flex-grow border-t border-[#1f0f35]"></div>
            </div>

            <div class="w-full flex flex-col items-center space-y-1.5">
                <button type="button" id="customGoogleBtn" onclick="handleUnifiedGoogleLogin()" class="w-full py-3 btn-google-login text-xs font-bold font-sans shadow-lg">
                    <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.8-2.4 3.66v3.05h3.87c2.26-2.09 3.67-5.17 3.67-9.15z"/>
                        <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.87-3.05c-1.08.72-2.45 1.16-4.06 1.16-3.13 0-5.78-2.11-6.73-4.96H1.26v3.15C3.25 21.36 7.31 24 12 24z"/>
                        <path fill="#FBBC05" d="M5.27 14.24c-.25-.72-.38-1.49-.38-2.24s.13-1.52.38-2.24V6.61H1.26C.46 8.23 0 10.06 0 12s.46 3.77 1.26 5.39l4.01-3.15z"/>
                        <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.25 2.64 1.26 6.61l4.01 3.15c.95-2.85 3.6-4.96 6.73-4.96z"/>
                    </svg>
                    <span>Continue with Google</span>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 8. SPIN WHEEL MODAL -->
    <?php if ($isSpinWheelActive): ?>
    <div id="spinWheelModal" class="fixed inset-0 bg-black/95 backdrop-blur-md z-[85] hidden flex items-center justify-center p-2 sm:p-4 overflow-y-auto select-none">
        <div class="babu88-modal-bg rounded-[32px] w-full max-w-sm p-4 sm:p-5 space-y-3 relative my-auto shadow-2xl">
            <button type="button" onclick="closeSpinWheelModal()" class="absolute top-3.5 right-3.5 w-8 h-8 rounded-full bg-white/10 text-gray-300 hover:text-white flex items-center justify-center p-1 z-30 transition active:scale-95 shadow-sm">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>

            <div class="text-center pt-0.5">
                <div class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full bg-black/60 border border-yellow-500/40 text-[10px] text-yellow-300 font-bold font-sans shadow-md">
                    <span>⏳ Limited Time — Ends In:</span>
                    <span id="babuUrgencyTimer" class="font-mono text-white text-xs bg-yellow-600/30 px-1.5 py-0.2 rounded border border-yellow-400">00:47</span>
                </div>
            </div>
            <div class="text-center space-y-0.5">
                <h2 class="text-2xl sm:text-3xl babu88-gold-header uppercase tracking-wider">
                    <?= !empty($tenant['site_title']) ? htmlspecialchars($tenant['site_title']) : 'BABU88' ?>
                </h2>
                <p class="text-[10px] text-yellow-200/80 font-sans tracking-wide">
                    Spin the lucky wheel for exclusive cashback discounts
                </p>
            </div>

            <!-- SPIN PROGRESS BAR -->
            <div class="bg-black/50 rounded-xl p-2.5 space-y-1.5 border border-yellow-500/30">
                <div class="flex justify-between items-center text-[10px] text-gray-300 font-sans">
                    <span>Spend Progress</span>
                    <span class="text-yellow-400 font-mono font-bold">
                        ৳<span id="spinSpendTotal">0</span> / ৳<span id="spinSpendThreshold">500</span>
                    </span>
                </div>
                <div class="spin-progress-outer">
                    <div id="spinProgressBar" class="spin-progress-inner" style="width: 0%"></div>
                </div>
                <p id="spinProgressHint" class="text-[9px] text-gray-400 text-center font-sans">
                    Spend ৳500 to unlock 1 free spin
                </p>
            </div>

            <div class="relative w-[285px] h-[285px] mx-auto flex items-center justify-center my-1">
                <div class="babu88-needle-top"></div>
                <div class="babu88-rim-frame">
                    <canvas id="wheelCanvas" width="265" height="265" class="rounded-full shadow-inner"></canvas>
                </div>
                <div onclick="triggerWheelSpin()" id="centerSpinKnob" class="babu88-center-btn shadow-lg">
                    <span class="text-[12px] font-extrabold text-yellow-300 leading-tight text-center font-cyber">SPIN<br>NOW</span>
                </div>
            </div>

            <div class="flex items-center justify-between px-2 text-[10px] text-gray-300 font-bold font-sans bg-black/40 py-1 rounded-xl border border-yellow-500/20">
                <div class="flex items-center space-x-1">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>Live <span id="liveWinTodayText" class="text-white font-mono">3,149</span> Winners Today</span>
                </div>
                <div class="flex items-center space-x-1">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span><span id="liveViewerCount" class="text-white font-mono">38</span> Watching</span>
                </div>
            </div>

            <div id="spinLeaderboardBox" class="bg-black/40 rounded-xl p-2 border border-yellow-500/20">
                <p class="text-[9px] text-yellow-300 font-bold mb-1 font-sans">🏆 Top Buyers Leaderboard</p>
                <div id="spinLeaderboardList" class="space-y-0.5 text-[9px] font-mono text-gray-300"></div>
            </div>

            <div class="pt-1 flex items-center justify-between text-[10px] text-gray-400 font-sans border-t border-yellow-500/20">
                <span>Remaining Spins: <strong id="spinRemainingCount" class="text-yellow-400 font-mono text-xs">0</strong></span>
                <span class="text-emerald-400 font-bold flex items-center gap-1">
                    <i class="fa-solid fa-circle-check"></i> 100% Free
                </span>
            </div>
        </div>
    </div>

    <!-- 8B. SPIN WINNING MODAL -->
    <div id="spinWinModal" class="fixed inset-0 bg-black/90 backdrop-blur-md z-[90] hidden flex items-center justify-center p-4 font-mono select-none overflow-y-auto">
        <div class="promo-popup-card rounded-[28px] w-full max-w-sm p-6 space-y-4 relative text-center my-auto shadow-2xl">
            <button type="button" onclick="closeSpinWinModal()" class="absolute top-3.5 right-3.5 w-7 h-7 rounded-full bg-white/10 text-gray-300 hover:text-white flex items-center justify-center transition active:scale-95 shadow-sm">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
            <div id="spinWinIconBox" class="w-16 h-16 mx-auto rounded-full bg-gradient-to-tr from-amber-400 to-yellow-500 flex items-center justify-center text-gray-950 text-3xl shadow-[0_0_25px_rgba(250,204,21,0.8)] border-2 border-white animate-bounce mt-1">
                <i class="fa-solid fa-gift"></i>
            </div>
            <div class="space-y-1">
                <span id="spinWinBadgeText" class="promo-shimmer-badge px-3 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest font-mono">
                    🎉 CONGRATULATIONS!
                </span>
                <h3 id="spinWinHeading" class="text-base sm:text-lg font-black text-white pt-1 font-bengali">
                    You Won Reward!
                </h3>
                <h4 id="spinWinResultText" class="text-xl sm:text-2xl font-black text-amber-400 font-cyber">৳100 Bonus</h4>
            </div>
            <div class="bg-[#0b0318] p-3.5 rounded-2xl border border-yellow-500/50 space-y-2 text-left font-bengali">
                <p id="spinWinBengaliNotice" class="text-xs text-yellow-200 font-medium leading-relaxed"></p>
                <p id="spinWinDescText" class="text-[10px] text-gray-400 leading-snug"></p>
            </div>
            <div class="pt-1 flex flex-col gap-2">
                <button type="button" onclick="closeSpinWinModal(); goToPage('addfund');" class="w-full py-3 rounded-xl bg-gradient-to-r from-yellow-400 via-amber-400 to-yellow-500 text-gray-950 font-black text-xs uppercase tracking-wider shadow-lg active:scale-95 transition font-mono">
                    Claim Offer &amp; Deposit Now
                </button>
                <button type="button" onclick="closeSpinWinModal()" class="w-full py-2.5 rounded-xl bg-[#0f061e] border border-white/10 text-gray-400 hover:text-white text-xs font-bold transition">
                    Later
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 9. NOTIFICATIONS MODAL -->
    <div id="notificationsModal" class="fixed inset-0 bg-black/85 backdrop-blur-sm z-[70] hidden flex items-center justify-center p-3 sm:p-4 font-mono select-none">
        <div class="glass-card rounded-2xl w-full max-w-sm p-4 sm:p-5 space-y-3.5 border border-cyan-500/50 shadow-2xl relative my-auto">
            <div class="flex justify-between items-center pb-2.5 border-b border-[#180c2a]">
                <div class="flex items-center space-x-2">
                    <span class="w-8 h-8 rounded-xl bg-cyan-500/20 text-cyan-300 flex items-center justify-center text-sm shadow-md border border-cyan-500/40">
                        <i class="fa-solid fa-bell"></i>
                    </span>
                    <div>
                        <h3 class="text-xs sm:text-sm font-black text-white uppercase tracking-wider font-cyber">Notifications</h3>
                        <span class="text-[9px] text-cyan-400 font-mono">Store Broadcasts</span>
                    </div>
                </div>
                <button type="button" onclick="closeNotificationsModal()" class="w-7 h-7 rounded-lg bg-white/5 text-gray-400 hover:text-white flex items-center justify-center p-1 transition">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>
            <div class="space-y-2 max-h-80 overflow-y-auto pr-1 no-scrollbar">
                <?php if (empty($userNotifications)): ?>
                    <div class="text-center py-10 text-gray-500 text-xs font-sans space-y-1">
                        <i class="fa-regular fa-bell-slash text-2xl mb-1 text-gray-600 block"></i>
                        <p>No new notifications on this store right now.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($userNotifications as $nt): ?>
                        <div class="bg-[#070210] p-3 rounded-xl border border-cyan-500/30 space-y-1.5 shadow-sm">
                            <div class="flex justify-between items-start">
                                <strong class="text-cyan-300 text-xs leading-snug font-sans"><?= htmlspecialchars($nt['title']) ?></strong>
                                <span class="text-[8px] text-gray-500 font-mono flex-shrink-0 ml-2"><?= date('M d, H:i', strtotime($nt['created_at'])) ?></span>
                            </div>
                            <p class="text-[11px] text-gray-300 font-sans leading-relaxed"><?= nl2br(htmlspecialchars($nt['message'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" onclick="closeNotificationsModal()" class="w-full py-2.5 rounded-xl bg-[#0f061e] border border-cyan-500/40 text-cyan-300 font-bold text-xs uppercase hover:bg-cyan-950/40 transition">
                Close
            </button>
        </div>
    </div>

    <!-- 10. PRODUCT PURCHASE MODAL -->
    <div id="productPurchaseModal" class="fixed inset-0 bg-black/90 backdrop-blur-md z-50 hidden flex items-center justify-center p-3 sm:p-4 overflow-y-auto font-mono select-none">
        <div class="glass-card rounded-[28px] w-full max-w-md p-4 sm:p-5 space-y-3.5 border border-fuchsia-500/50 relative my-auto shadow-2xl">
            
            <button type="button" onclick="copyProductDirectLink()" class="absolute top-3.5 left-3.5 px-3 py-1.5 rounded-xl bg-cyan-500/20 border border-cyan-400/40 text-cyan-300 hover:text-white flex items-center gap-1.5 text-[10px] font-bold transition active:scale-95 shadow-md z-10" title="Copy Product Link">
                <i class="fa-solid fa-link text-[10px]"></i> <span>Copy Link</span>
            </button>

            <button type="button" onclick="closeProductPurchaseModal()" class="absolute top-3.5 right-3.5 w-8 h-8 rounded-full bg-white/10 text-gray-400 hover:text-white flex items-center justify-center p-1 z-10 active:scale-95 transition">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>

            <div class="text-center space-y-1 pt-6 pb-2 border-b border-[#180c2a]">
                <div id="modalProdThumb" class="w-14 h-14 mx-auto rounded-2xl bg-[#070210] border-2 border-fuchsia-400 flex items-center justify-center text-fuchsia-400 text-xl font-bold overflow-hidden shadow-md">
                    <i class="fa-solid fa-gamepad"></i>
                </div>
                <div>
                    <h3 id="modalProdName" class="text-xs sm:text-sm font-black text-white uppercase tracking-wider font-sans">Product Name</h3>
                    <p id="modalProdDesc" class="text-[9px] text-gray-400 max-w-xs mx-auto line-clamp-2 mt-0.5 font-sans">Product details...</p>
                </div>
            </div>

            <!-- STEP 1: PLAN & QUANTITY -->
            <div id="modalViewPlanSelect" class="space-y-3">
                <div class="space-y-1">
                    <label class="text-[10px] font-bold text-gray-300 uppercase block font-sans">Select Validity Plan:</label>
                    <div id="customPlanListContainer" class="custom-plan-dropdown"></div>
                    <input type="hidden" id="modalPlanSelect" value="">
                </div>

                <div id="planDetailsInfoCard" class="bg-[#090317] border border-[#1f0f35] p-3 rounded-2xl space-y-2 shadow-inner hidden">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] text-gray-400 font-sans">Plan Unit Price:</span>
                        <div class="text-right">
                            <span id="planOrigPriceStrike" class="hidden text-[10px] text-gray-500 line-through font-mono mr-1"></span>
                            <strong id="planCurrentPriceText" class="text-xs sm:text-sm font-black text-[#00ff88] font-mono">৳0 BDT</strong>
                            <span id="planDiscountBadge" class="hidden px-1.5 py-0.2 rounded text-[8px] font-black uppercase bg-emerald-500 text-slate-950 font-mono ml-1"></span>
                        </div>
                    </div>

                    <div id="planRewardCoinRow" class="hidden flex items-center justify-between border-t border-[#180c2a] pt-1.5 text-[10px]">
                        <span class="text-gray-300 font-sans flex items-center gap-1">
                            <i class="fa-solid fa-gift text-amber-400"></i> Reward Cashback:
                        </span>
                        <span id="planRewardCoinVal" class="text-amber-300 font-bold font-mono">+0 Coins Gift</span>
                    </div>

                    <div id="planCoinPriceRow" class="hidden flex items-center justify-between border-t border-[#180c2a] pt-1.5 text-[10px]">
                        <span class="text-gray-300 font-sans flex items-center gap-1">
                            <i class="fa-solid fa-coins text-amber-400"></i> Coin Pay Option:
                        </span>
                        <span id="planCoinPriceVal" class="text-amber-400 font-black font-mono">🪙 0 Coins</span>
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-[9px] font-bold text-gray-300 uppercase block font-sans">Quantity:</label>
                    <div class="flex items-center space-x-2">
                        <button type="button" onclick="changePurchaseQty(-1)" class="w-8 h-8 rounded-lg btn-buy-compact text-white text-base font-bold transition flex items-center justify-center active:scale-90">-</button>
                        <input type="number" id="purchaseQtyInput" value="1" min="1" max="50" readonly class="flex-grow text-center bg-[#070210] border border-[#180c2a] text-xs font-black text-white rounded-lg py-1.5 font-mono shadow-inner">
                        <button type="button" onclick="changePurchaseQty(1)" class="w-8 h-8 rounded-lg btn-buy-compact text-white text-base font-bold transition flex items-center justify-center active:scale-90">+</button>
                    </div>
                </div>

                <!-- কুপন কোড বক্স -->
                <div class="bg-[#080214] p-2.5 rounded-xl border border-dashed border-fuchsia-500/40 space-y-1.5">
                    <label class="text-[10px] font-bold text-gray-300 flex items-center gap-1 font-sans">
                        <i class="fa-solid fa-ticket text-fuchsia-400"></i> Have a Coupon Code?
                    </label>
                    <div class="flex gap-1.5">
                        <input type="text" id="modalCouponInput" placeholder="Enter coupon code" class="flex-1 bg-[#04010a] border border-[#1f0f35] px-2.5 py-1.5 rounded-lg text-fuchsia-300 uppercase font-mono text-xs outline-none focus:border-fuchsia-400 tracking-wider">
                        <button type="button" onclick="applyModalCoupon()" class="px-3 py-1.5 rounded-lg btn-buy-compact text-white font-bold text-xs uppercase font-mono active:scale-95 transition">Apply</button>
                    </div>
                    <div id="couponStatusNotice" class="hidden text-[10px] font-bold pt-0.5"></div>
                </div>

                <div class="bg-[#070210] border border-[#180c2a] p-2.5 rounded-xl flex items-center justify-between text-xs shadow-inner">
                    <div>
                        <span class="text-[8px] text-gray-400 block font-sans">Total Payable Price:</span>
                        <strong id="step1TotalCostText" class="text-xs sm:text-sm font-black text-[#00ff88]">৳0 BDT</strong>
                    </div>
                    <div class="text-right">
                        <span class="text-[8px] text-gray-400 block font-sans">Wallet Funds:</span>
                        <strong class="text-cyan-300 font-bold text-xs dynamic-price-el live-user-balance-el" data-bdt="<?= (float)$userBalanceBdt ?>">৳<?= number_format($userBalanceBdt, 0) ?> BDT</strong>
                        <span class="text-[8px] text-amber-400 block font-mono">Coins: 🪙 <?= number_format($userCoins) ?></span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-1.5 text-xs font-bold">
                    <button type="button" onclick="openSafeLink(activeModalProduct?.setup_link, 'Setup guide link not available for this product')" class="py-2 px-2.5 rounded-xl bg-[#0f061e] border border-cyan-500/30 text-cyan-300 hover:bg-[#140828] flex items-center justify-center gap-1.5 truncate text-[10px] transition">
                        <i class="fa-solid fa-circle-play text-[10px]"></i> <span>Setup Video</span>
                    </button>
                    <button type="button" onclick="openSafeLink(activeModalProduct?.download_link, 'Download link not available for this product')" class="py-2 px-2.5 rounded-xl bg-[#0f061e] border border-emerald-500/30 text-emerald-300 hover:bg-[#140828] flex items-center justify-center gap-1.5 truncate text-[10px] transition">
                        <i class="fa-solid fa-file-arrow-down text-[10px]"></i> <span>File Download</span>
                    </button>
                </div>

                <button type="button" onclick="proceedToPaymentSelectScreen()" class="w-full py-3 rounded-xl btn-magenta-glow text-white font-black text-xs uppercase tracking-wider flex items-center justify-center gap-1.5 shadow-md transition font-mono active:scale-95">
                    <i class="fa-solid fa-arrow-right text-xs"></i> <span>Proceed to Payment</span>
                </button>
            </div>

            <!-- STEP 2: CHOOSE PAYMENT -->
            <div id="modalViewPaymentSelect" class="hidden space-y-3">
                <div class="flex items-center justify-between pb-1 border-b border-[#180c2a]">
                    <button type="button" onclick="goBackToPlanStep()" class="px-2.5 py-1 rounded-lg bg-white/10 text-gray-300 hover:text-white text-[10px] font-bold flex items-center gap-1 transition active:scale-95">
                        <i class="fa-solid fa-arrow-left"></i> <span>Back</span>
                    </button>
                    <span class="text-xs font-black text-white font-sans uppercase">Choose Payment</span>
                    <span id="step2SummaryCost" class="text-xs font-black text-[#00ff88] font-mono">৳0 BDT</span>
                </div>

                <p class="text-[10px] text-gray-400 font-sans">Select your preferred payment channel to proceed:</p>

                <div class="space-y-2 text-xs">
                    <div id="choiceCardBdt" onclick="openBdtMethodsListScreen()" class="pay-method-choice-card">
                        <div class="flex items-center space-x-3">
                            <span class="w-9 h-9 rounded-xl bg-pink-500/20 text-pink-400 flex items-center justify-center text-sm border border-pink-500/40 shadow-sm">
                                <i class="fa-solid fa-mobile-screen-button"></i>
                            </span>
                            <div>
                                <strong class="text-white block text-xs font-sans">BDT Mobile Banking</strong>
                                <span class="text-[9px] text-gray-400 font-mono">bKash, Nagad, Rocket &amp; others</span>
                            </div>
                        </div>
                        <div class="flex items-center space-x-1.5">
                            <span class="text-[9px] text-pink-400 font-bold font-mono">Select</span>
                            <i class="fa-solid fa-chevron-right text-xs text-gray-400"></i>
                        </div>
                    </div>

                    <div id="choiceCardUsdt" onclick="openDedicatedUsdtScreen()" class="pay-method-choice-card">
                        <div class="flex items-center space-x-3">
                            <span class="w-9 h-9 rounded-xl bg-yellow-500/20 text-yellow-400 flex items-center justify-center text-sm border border-yellow-500/40 shadow-sm">
                                <i class="fa-brands fa-bitcoin"></i>
                            </span>
                            <div>
                                <strong class="text-white block text-xs font-sans">USDT (Binance Pay)</strong>
                                <span class="text-[9px] text-gray-400 font-mono">Instant Crypto Pay via Binance UID</span>
                            </div>
                        </div>
                        <div class="flex items-center space-x-1.5">
                            <span class="text-[9px] text-yellow-400 font-bold font-mono">Pay USDT</span>
                            <i class="fa-solid fa-chevron-right text-xs text-gray-400"></i>
                        </div>
                    </div>

                    <div id="choiceCardBalance" onclick="openDedicatedBalanceScreen()" class="pay-method-choice-card">
                        <div class="flex items-center space-x-3">
                            <span class="w-9 h-9 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-sm border border-emerald-500/40 shadow-sm">
                                <i class="fa-solid fa-wallet"></i>
                            </span>
                            <div>
                                <strong class="text-white block text-xs font-sans">Main Balance (Wallet)</strong>
                                <span class="text-[9px] text-gray-400 font-mono">Balance: <span class="dynamic-price-el live-user-balance-el" data-bdt="<?= (float)$userBalanceBdt ?>">৳<?= number_format($userBalanceBdt, 0) ?> BDT</span></span>
                            </div>
                        </div>
                        <div class="flex items-center space-x-1.5">
                            <span id="balCardStateTag" class="text-[9px] text-[#00ff88] font-bold font-mono">Instant Deduct</span>
                            <i class="fa-solid fa-chevron-right text-xs text-gray-400"></i>
                        </div>
                    </div>

                    <div id="choiceCardCoins" onclick="openDedicatedCoinsScreen()" class="pay-method-choice-card hidden">
                        <div class="flex items-center space-x-3">
                            <span class="w-9 h-9 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-sm border border-amber-500/40 animate-pulse shadow-sm">
                                <i class="fa-solid fa-coins"></i>
                            </span>
                            <div>
                                <strong class="text-white block text-xs font-sans">Pay with Coins</strong>
                                <span class="text-[9px] text-amber-300 font-mono">Your Coins: 🪙 <?= number_format($userCoins) ?></span>
                            </div>
                        </div>
                        <div class="flex items-center space-x-1.5">
                            <span id="coinCardCostTag" class="text-[9px] text-amber-400 font-bold font-mono">🪙 0 Coins</span>
                            <i class="fa-solid fa-chevron-right text-xs text-amber-400"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STEP 2B: BDT METHODS LIST -->
            <div id="modalViewBdtMethodsList" class="hidden space-y-3">
                <div class="flex items-center justify-between pb-1 border-b border-[#180c2a]">
                    <button type="button" onclick="goBackToMethodSelectScreen()" class="px-2.5 py-1 rounded-lg bg-white/10 text-gray-300 hover:text-white text-[10px] font-bold flex items-center gap-1 transition active:scale-95">
                        <i class="fa-solid fa-arrow-left"></i> <span>Back</span>
                    </button>
                    <span class="text-xs font-black text-pink-400 font-sans uppercase">Select BDT Method</span>
                    <span id="bdtListTotalCostTag" class="text-xs font-black text-white font-mono">৳0 BDT</span>
                </div>

                <p class="text-[10px] text-gray-400 font-sans">Choose an admin-approved BDT gateway to open payment screen:</p>

                <div id="adminMethodsDynamicList" class="space-y-2 max-h-60 overflow-y-auto no-scrollbar pr-0.5">
                    <?php foreach ($activePaymentMethods as $pm): 
                        if (isset($pm['allow_checkout']) && (int)$pm['allow_checkout'] === 0) continue;
                    ?>
                        <div onclick="openDedicatedBdtMethodScreen(<?= (int)$pm['id'] ?>)" class="admin-payment-item-card">
                            <div class="flex items-center space-x-2.5">
                                <div class="w-8 h-8 rounded-xl bg-purple-950/80 border border-fuchsia-500/40 flex items-center justify-center text-xs font-bold text-white overflow-hidden flex-shrink-0">
                                    <?php if (!empty($pm['logo_url'])): ?>
                                        <img loading="lazy" decoding="async" src="<?= htmlspecialchars($pm['logo_url']) ?>" class="w-full h-full object-cover" alt="<?= htmlspecialchars($pm['name']) ?>">
                                    <?php else: ?>
                                        <i class="fa-solid fa-money-bill-wave text-fuchsia-400"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <strong class="text-white block text-xs font-sans"><?= htmlspecialchars($pm['name']) ?></strong>
                                    <span class="text-[8px] text-gray-400 font-mono"><?= htmlspecialchars($pm['account_type']) ?> • <?= htmlspecialchars($pm['account_number']) ?></span>
                                </div>
                            </div>
                            <div class="flex items-center space-x-1">
                                <span class="text-[9px] text-emerald-400 font-bold font-mono">Pay Now</span>
                                <i class="fa-solid fa-chevron-right text-[10px] text-gray-400"></i>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- STEP 3A: BDT DEDICATED -->
            <div id="paySubViewBdtDedicated" class="hidden space-y-3">
                <div class="flex items-center justify-between pb-1 border-b border-[#180c2a]">
                    <button type="button" onclick="goBackToBdtMethodsList()" class="px-2.5 py-1 rounded-lg bg-white/10 text-gray-300 hover:text-white text-[10px] font-bold flex items-center gap-1 transition active:scale-95">
                        <i class="fa-solid fa-arrow-left"></i> <span>Back</span>
                    </button>
                    <span id="bdtDedicatedTitle" class="text-xs font-black text-pink-400 font-sans uppercase">bKash</span>
                    <span id="bdtDedicatedPayableAmount" class="text-xs font-black text-[#00ff88] font-mono">৳0 BDT</span>
                </div>

                <div id="bdtMethodBannerContainer" class="w-full h-24 sm:h-28 rounded-xl bg-[#090317] border border-fuchsia-500/40 overflow-hidden relative shadow-inner">
                    <img loading="lazy" decoding="async" id="bdtDedicatedBannerImg" src="<?= htmlspecialchars(!empty($globalPaymentBanner) ? $globalPaymentBanner : 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&q=80') ?>" alt="Payment Banner" class="w-full h-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-t from-[#04010a] via-transparent to-transparent"></div>
                    <span id="bdtDedicatedBannerBadge" class="absolute bottom-1.5 left-2 px-2 py-0.5 rounded text-[8px] font-black uppercase bg-fuchsia-600/90 text-white font-mono shadow">
                        OFFICIAL GATEWAY
                    </span>
                </div>

                <div class="bg-[#070210] p-3 rounded-2xl border border-[#1f0f35] space-y-2 shadow-inner text-xs">
                    <div class="flex items-center justify-between">
                        <div>
                            <span id="bdtDedicatedTypeLabel" class="text-[8px] text-gray-400 block uppercase font-sans">Personal Account Number:</span>
                            <strong id="bdtDedicatedNumberVal" class="text-white text-sm font-mono tracking-wider">01700000000</strong>
                        </div>
                        <button type="button" onclick="copyToClipboard(document.getElementById('bdtDedicatedNumberVal').innerText, 'Account Number')" class="px-3 py-1.5 rounded-xl btn-buy-compact text-white text-[9px] font-bold active:scale-95 transition shadow-sm">
                            <i class="fa-regular fa-copy mr-1"></i> Copy
                        </button>
                    </div>

                    <div id="bdtDedicatedInstructionBox" class="bg-[#05010b] p-2 rounded-xl border border-white/5 text-[10px] text-gray-300 font-sans leading-relaxed">
                        Send Money to the number above, then copy &amp; paste the TrxID below to verify instantly.
                    </div>
                </div>

                <div class="space-y-1 text-xs">
                    <label class="text-[10px] font-bold text-gray-300 flex items-center justify-between font-sans">
                        <span>Transaction ID (TrxID) <span class="text-fuchsia-400">*</span></span>
                        <span class="text-[8px] text-cyan-400 font-mono">Instant Verification</span>
                    </label>
                    <input type="text" id="bdtTrxIdInputOnly" placeholder="Enter SMS TrxID" class="w-full bg-[#05010b] border border-fuchsia-500/40 p-2.5 rounded-xl text-fuchsia-300 uppercase outline-none focus:border-fuchsia-400 text-xs font-mono shadow-inner tracking-wider">
                </div>

                <div id="bdtDedicatedOrderError" class="hidden p-2 rounded-xl bg-red-950/90 border border-red-500 text-red-300 text-[10px] font-bold text-center leading-relaxed font-sans shadow-md"></div>

                <button type="button" id="btnVerifyBdtDedicated" onclick="submitDedicatedBdtVerification()" class="w-full py-3 rounded-xl btn-magenta-glow text-white font-black text-xs uppercase tracking-wider flex items-center justify-center gap-1.5 shadow-md transition font-mono active:scale-95">
                    <i class="fa-solid fa-shield-check text-xs"></i> <span>Verify &amp; Deliver Key</span>
                </button>
            </div>

            <!-- STEP 3B: USDT DEDICATED -->
            <div id="paySubViewUsdtDedicated" class="hidden space-y-3">
                <div class="flex items-center justify-between pb-1 border-b border-[#180c2a]">
                    <button type="button" onclick="goBackToMethodSelectScreen()" class="px-2.5 py-1 rounded-lg bg-white/10 text-gray-300 hover:text-white text-[10px] font-bold flex items-center gap-1 transition active:scale-95">
                        <i class="fa-solid fa-arrow-left"></i> <span>Back</span>
                    </button>
                    <span class="text-xs font-black text-yellow-400 font-sans uppercase">Binance Pay (USDT)</span>
                    <span id="usdtDedicatedPayableTag" class="text-xs font-black text-yellow-400 font-mono">$0.00 USDT</span>
                </div>

                <div class="bg-[#070210] p-3 rounded-2xl border border-yellow-500/30 space-y-2 shadow-inner text-xs">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-[8px] text-gray-400 block uppercase font-sans">Binance Pay ID / UID:</span>
                            <strong id="binanceIdValDedicated" class="text-white text-sm font-mono tracking-wider"><?= htmlspecialchars($settings['binance_id']) ?></strong>
                        </div>
                        <button type="button" onclick="copyToClipboard(document.getElementById('binanceIdValDedicated').innerText, 'Binance ID')" class="px-3 py-1.5 rounded-xl btn-buy-compact text-white text-[9px] font-bold active:scale-95 transition shadow-sm">
                            <i class="fa-regular fa-copy mr-1"></i> Copy
                        </button>
                    </div>

                    <div class="bg-[#05010b] p-2 rounded-xl border border-white/5 text-[10px] text-gray-300 font-sans leading-relaxed">
                        Open Binance App &gt; Pay &gt; Send USDT to the UID above, then enter your Order / Transaction ID below.
                    </div>
                </div>

                <div class="space-y-1 text-xs">
                    <label class="text-[9px] text-gray-400 block font-sans">Binance Order ID / TrxID <span class="text-yellow-400">*</span></label>
                    <input type="text" id="usdtTrxIdInputOnly" placeholder="Enter Binance Transaction ID" class="w-full bg-[#05010b] border border-yellow-500/40 p-2.5 rounded-xl text-yellow-300 uppercase outline-none focus:border-yellow-400 text-xs font-mono shadow-inner tracking-wider">
                </div>

                <div id="usdtDedicatedOrderError" class="hidden p-2 rounded-xl bg-red-950/90 border border-red-500 text-red-300 text-[10px] font-bold text-center leading-relaxed font-sans shadow-md"></div>

                <button type="button" id="btnConfirmUsdtDedicated" onclick="submitDedicatedUsdtOrder()" class="w-full py-3 rounded-xl bg-gradient-to-r from-yellow-500 to-amber-600 text-gray-950 font-black text-xs uppercase tracking-wider flex items-center justify-center gap-1.5 shadow-md transition font-mono active:scale-95">
                    <i class="fa-solid fa-bolt text-xs"></i> <span>Confirm USDT &amp; Get Key</span>
                </button>
            </div>

            <!-- STEP 3C: BALANCE DEDICATED -->
            <div id="paySubViewBalanceDedicated" class="hidden space-y-3">
                <div class="flex items-center justify-between pb-1 border-b border-[#180c2a]">
                    <button type="button" onclick="goBackToMethodSelectScreen()" class="px-2.5 py-1 rounded-lg bg-white/10 text-gray-300 hover:text-white text-[10px] font-bold flex items-center gap-1 transition active:scale-95">
                        <i class="fa-solid fa-arrow-left"></i> <span>Back</span>
                    </button>
                    <span class="text-xs font-black text-emerald-400 font-sans uppercase">Pay from Wallet</span>
                    <span id="balDedicatedCostTag" class="text-xs font-black text-[#00ff88] font-mono">৳0 BDT</span>
                </div>

                <div class="bg-[#05010b] p-3 rounded-2xl border border-emerald-500/30 space-y-2 shadow-inner font-mono text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400 font-sans text-[11px]">Current Wallet Balance:</span>
                        <strong class="text-white dynamic-price-el live-user-balance-el" data-bdt="<?= (float)$userBalanceBdt ?>">৳<?= number_format($userBalanceBdt, 0) ?> BDT</strong>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400 font-sans text-[11px]">Order Payable Cost:</span>
                        <strong id="balDeductCostText" class="text-rose-400">-৳0 BDT</strong>
                    </div>
                    <div class="border-t border-white/10 pt-1.5 flex items-center justify-between font-bold">
                        <span class="text-gray-300 font-sans text-[11px]">After Purchase:</span>
                        <strong id="balRemainingAfterText" class="text-[#00ff88]">৳0 BDT</strong>
                    </div>
                </div>

                <div id="balLowNoticeBoxDedicated" class="hidden p-2.5 rounded-xl bg-red-950/80 border border-red-500 text-rose-300 text-[10px] font-sans leading-relaxed text-center space-y-2">
                    <p>⚠️ Insufficient balance! Please recharge via Add Fund or use BDT Mobile Banking.</p>
                    <button type="button" onclick="closeProductPurchaseModal(); goToPage('addfund');" class="px-4 py-1.5 rounded-lg btn-buy-compact text-white font-bold text-[9px]">
                        Deposit Now
                    </button>
                </div>

                <div id="balDedicatedOrderError" class="hidden p-2 rounded-xl bg-red-950/90 border border-red-500 text-red-300 text-[10px] font-bold text-center leading-relaxed font-sans shadow-md"></div>

                <button type="button" id="btnConfirmBalDedicated" onclick="submitDedicatedBalanceOrder()" class="w-full py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 text-gray-950 font-black text-xs uppercase tracking-wider flex items-center justify-center gap-1.5 shadow-md transition font-mono active:scale-95">
                    <i class="fa-solid fa-circle-check text-xs"></i> <span>Instant Deduct &amp; Deliver Key</span>
                </button>
            </div>

            <!-- STEP 3D: COINS DEDICATED -->
            <div id="paySubViewCoinsDedicated" class="hidden space-y-3">
                <div class="flex items-center justify-between pb-1 border-b border-[#180c2a]">
                    <button type="button" onclick="goBackToMethodSelectScreen()" class="px-2.5 py-1 rounded-lg bg-white/10 text-gray-300 hover:text-white text-[10px] font-bold flex items-center gap-1 transition active:scale-95">
                        <i class="fa-solid fa-arrow-left"></i> <span>Back</span>
                    </button>
                    <span class="text-xs font-black text-amber-400 font-sans uppercase">Redeem with Coins</span>
                    <span id="coinDedicatedCostTag" class="text-xs font-black text-amber-300 font-mono">🪙 0</span>
                </div>

                <div class="bg-[#05010b] p-3 rounded-2xl border border-amber-500/40 space-y-2 shadow-inner font-mono text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400 font-sans text-[11px]">Your Coins:</span>
                        <strong class="text-white">🪙 <?= number_format($userCoins) ?></strong>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400 font-sans text-[11px]">Required:</span>
                        <strong id="coinDeductCostText" class="text-amber-400">-🪙 0</strong>
                    </div>
                    <div class="border-t border-white/10 pt-1.5 flex items-center justify-between font-bold">
                        <span class="text-gray-300 font-sans text-[11px]">Remaining:</span>
                        <strong id="coinRemainingAfterText" class="text-amber-300">🪙 0</strong>
                    </div>
                </div>

                <div id="coinDedicatedOrderError" class="hidden p-2 rounded-xl bg-red-950/90 border border-red-500 text-red-300 text-[10px] font-bold text-center leading-relaxed font-sans shadow-md"></div>

                <button type="button" id="btnConfirmCoinsDedicated" onclick="submitDedicatedCoinsOrder()" class="w-full py-3 rounded-xl bg-gradient-to-r from-amber-400 via-yellow-400 to-amber-500 text-gray-950 font-black text-xs uppercase tracking-wider flex items-center justify-center gap-1.5 shadow-md transition font-mono active:scale-95">
                    <i class="fa-solid fa-coins text-xs"></i> <span>Redeem Coins &amp; Get Key</span>
                </button>
            </div>

        </div>
    </div>

    <!-- 11. KEY DELIVERY SUCCESS MODAL -->
    <div id="keySuccessModal" class="fixed inset-0 bg-black/90 backdrop-blur-md z-[60] hidden flex items-center justify-center p-3 sm:p-4 font-mono select-none overflow-y-auto">
        <div class="glass-card rounded-[28px] w-full max-w-sm p-5 sm:p-6 space-y-4 border-2 border-emerald-500/60 shadow-[0_0_40px_rgba(0,255,136,0.4)] relative my-auto">
            <div class="text-center space-y-1.5">
                <div class="w-14 h-14 mx-auto rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-2xl mb-1 border-2 border-emerald-400 shadow-[0_0_20px_rgba(0,255,136,0.5)]">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <h3 class="text-sm sm:text-base font-black text-white uppercase tracking-wider font-cyber">PURCHASE SUCCESSFUL!</h3>
                <p id="modalProductTitle" class="text-xs text-emerald-300 font-bold font-mono"></p>
                <div id="modalRewardCoinNotify" class="hidden text-xs text-amber-300 font-bold bg-amber-500/20 py-1 px-2 rounded-lg border border-amber-500/40"></div>
                <div id="modalSpinEarnedNotify" class="hidden text-xs text-yellow-300 font-bold bg-yellow-500/20 py-1 px-2 rounded-lg border border-yellow-500/40"></div>
            </div>

            <div class="space-y-1.5">
                <div id="modalKeysContainer" class="space-y-2.5 max-h-60 overflow-y-auto pr-1 no-scrollbar"></div>
            </div>

            <div class="grid grid-cols-2 gap-2 text-xs font-bold pt-1">
                <button type="button" onclick="openSafeLink(activeModalProduct?.setup_link, 'Setup guide not available')" class="py-2.5 px-2.5 rounded-xl bg-[#0f061e] border border-cyan-500/30 text-cyan-300 hover:bg-[#150a2b] flex items-center justify-center gap-1.5 text-[10px] transition">
                    <i class="fa-solid fa-circle-play text-xs"></i> <span>Setup Video</span>
                </button>
                <button type="button" onclick="openSafeLink(activeModalProduct?.download_link, 'Download not available')" class="py-2.5 px-2.5 rounded-xl bg-[#0f061e] border border-emerald-500/30 text-emerald-300 hover:bg-[#150a2b] flex items-center justify-center gap-1.5 text-[10px] transition">
                    <i class="fa-solid fa-file-arrow-down text-xs"></i> <span>File Download</span>
                </button>
            </div>

            <button type="button" onclick="closeKeySuccessModal()" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 text-gray-950 font-black text-xs uppercase tracking-wider shadow-lg font-mono active:scale-95 transition">
                Done &amp; Close
            </button>
        </div>
    </div>

    <!-- 12. EDIT PROFILE & PASSWORD MODAL -->
    <div id="editProfileModal" class="fixed inset-0 bg-black/85 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 font-mono select-none">
        <div class="glass-card rounded-2xl w-full max-w-sm p-4 sm:p-5 space-y-3.5 border border-fuchsia-500/50 relative shadow-2xl my-auto">
            <div class="flex justify-between items-center pb-2 border-b border-[#180c2a]">
                <h3 class="text-xs sm:text-sm font-bold text-white flex items-center gap-1.5 font-sans">
                    <i class="fa-solid fa-user-pen text-fuchsia-400"></i> Edit Profile &amp; Password
                </h3>
                <button onclick="closeModal('editProfileModal')" class="text-gray-400 hover:text-white p-1">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-2.5 text-xs">
                <input type="hidden" name="action" value="update_profile">
                <div class="space-y-0.5">
                    <label class="text-gray-300 block text-[10px] font-sans">Username</label>
                    <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required class="w-full bg-[#070210] border border-[#180c2a] px-3 py-2 rounded-xl text-white outline-none focus:border-cyan-400 text-xs shadow-inner">
                </div>
                <div class="space-y-0.5">
                    <label class="text-gray-300 block text-[10px] font-sans">Gmail Address</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="yourname@gmail.com" class="w-full bg-[#070210] border border-[#180c2a] px-3 py-2 rounded-xl text-white outline-none focus:border-cyan-400 text-xs shadow-inner">
                </div>
                <div class="space-y-0.5">
                    <label class="text-gray-300 block text-[10px] font-sans">Telegram Username</label>
                    <input type="text" name="telegram_username" value="<?= htmlspecialchars($user['telegram_username'] ?? '') ?>" placeholder="@yourtelegram" class="w-full bg-[#070210] border border-[#180c2a] px-3 py-2 rounded-xl text-white outline-none focus:border-cyan-400 text-xs shadow-inner">
                </div>
                <div class="pt-1 border-t border-[#180c2a] space-y-0.5">
                    <label class="text-gray-300 block text-[10px] font-sans">New Password (Optional)</label>
                    <input type="password" name="new_password" placeholder="••••••••" class="w-full bg-[#070210] border border-[#180c2a] px-3 py-2 rounded-xl text-white outline-none focus:border-fuchsia-400 text-xs shadow-inner">
                </div>
                <div class="space-y-0.5">
                    <label class="text-amber-400 block text-[10px] font-bold font-sans">Current Password (Required)</label>
                    <input type="password" name="current_password" required placeholder="••••••••" class="w-full bg-[#070210] border border-amber-500/40 px-3 py-2 rounded-xl text-white outline-none focus:border-amber-400 text-xs shadow-inner">
                </div>
                <button type="submit" class="w-full py-2.5 rounded-xl btn-magenta-glow text-white font-black uppercase text-[11px] tracking-wider mt-1 font-mono active:scale-95 transition">
                    Save Profile
                </button>
            </form>
        </div>
    </div>

    <!-- 13. FLOATING ACTION BUTTONS & AI ASSISTANT -->
    <?php if ($isSpinWheelActive): ?>
    <div class="floating-spin-btn" onclick="handleSpinFloatingClick()" title="Lucky Spin &amp; Win Reward">
        <i class="fa-solid fa-dharmachakra fa-spin text-xl text-yellow-400"></i>
        <span class="text-[8px] font-bold text-yellow-300 mt-0.5 font-mono">SPIN</span>
    </div>
    <?php endif; ?>

    <div class="floating-chat-btn" onclick="toggleChatDialog()" title="Live Customer Support &amp; AI">
        <i class="fa-solid fa-headset"></i>
    </div>

    <div id="chatDialogBox" class="chat-dialog hidden font-mono">
        <div class="p-3 bg-[#0f071f] border-b border-cyan-500/40 flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <span class="w-7 h-7 rounded-full bg-cyan-400 text-gray-950 flex items-center justify-center text-xs font-black shadow-md shadow-cyan-400/50">
                    <i class="fa-solid fa-headset"></i>
                </span>
                <div>
                    <h4 class="text-xs font-black text-white leading-none font-sans">Support Assistant</h4>
                    <span class="text-[8px] text-cyan-300">Live 24/7 AI &amp; Admin</span>
                </div>
            </div>
            <button onclick="toggleChatDialog()" class="text-gray-400 hover:text-white p-1">
                <i class="fa-solid fa-xmark text-xs"></i>
            </button>
        </div>

        <div class="flex bg-[#06020c] p-1 border-b border-[#180c2a] text-[10px] font-bold">
            <button onclick="switchChatTab('ai')" id="chatTabBtnAi" class="flex-1 py-1 rounded-lg bg-cyan-500 text-gray-950 font-bold transition">Instant AI Help</button>
            <button onclick="switchChatTab('tg')" id="chatTabBtnTg" class="flex-1 py-1 rounded-lg text-gray-400 hover:text-white transition">Contact Support</button>
        </div>

        <div id="chatSectionAi" class="p-2.5 space-y-2 text-xs">
            <div id="chatMessageLog" class="space-y-1.5 max-h-52 overflow-y-auto pr-1 no-scrollbar">
                <div class="bg-[#120824] p-2 rounded-xl text-gray-200 leading-relaxed text-[10px] border border-cyan-500/30 font-sans shadow-sm">
                    👋 <b>Hello &amp; Welcome!</b> How can I assist you today? You can ask about <i>"Hi"</i>, product prices, how to deposit, or reset license keys!
                </div>
            </div>

            <div class="flex items-center space-x-1.5 overflow-x-auto pt-0.5 no-scrollbar text-[8px]">
                <button onclick="askAiQuestion('hi')" class="px-2.5 py-1 rounded-full bg-[#15092a] border border-cyan-500/40 text-cyan-300 flex-shrink-0">👋 Hi</button>
                <button onclick="askAiQuestion('balance')" class="px-2.5 py-1 rounded-full bg-[#15092a] border border-emerald-500/40 text-emerald-300 flex-shrink-0">💰 Balance</button>
                <?php if ($canAddFund === 1): ?>
                    <button onclick="askAiQuestion('deposit')" class="px-2.5 py-1 rounded-full bg-[#15092a] border border-fuchsia-500/40 text-fuchsia-300 flex-shrink-0">💳 Deposit</button>
                <?php endif; ?>
                <button onclick="askAiQuestion('reset key')" class="px-2.5 py-1 rounded-full bg-[#15092a] border border-purple-500/40 text-purple-300 flex-shrink-0">🔄 Reset</button>
            </div>

            <form onsubmit="handleAiMessageSubmit(event)" class="flex items-center space-x-1 pt-0.5">
                <input type="text" id="aiUserInput" placeholder="Type hi, price, deposit..." class="flex-grow bg-[#06020c] border border-[#180c2a] px-2.5 py-1.5 rounded-lg text-white text-[10px] outline-none focus:border-cyan-400 font-sans shadow-inner">
                <button type="submit" class="w-7 h-7 rounded-lg bg-cyan-400 text-gray-950 font-bold flex items-center justify-center hover:bg-cyan-300 transition shadow">
                    <i class="fa-solid fa-paper-plane text-[10px]"></i>
                </button>
            </form>
        </div>

        <div id="chatSectionTg" class="hidden p-3 text-center space-y-2.5 text-xs">
            <p class="text-gray-300 text-[11px] font-sans">Need official support, key assistance or community updates?</p>
            <?php if (!empty($tenantTelegram)): ?>
                <a href="<?= htmlspecialchars($tenantTelegram) ?>" target="_blank" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-blue-500 to-blue-600 text-white font-black uppercase tracking-wider flex items-center justify-center gap-1.5 text-[11px] font-sans shadow-md">
                    <i class="fa-brands fa-telegram text-sm"></i> Telegram Support
                </a>
            <?php endif; ?>
            <?php if (!empty($tenantWhatsapp)): ?>
                <a href="https://wa.me/<?= htmlspecialchars(preg_replace('/[^0-9]/', '', $tenantWhatsapp)) ?>" target="_blank" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-green-500 to-green-600 text-white font-black uppercase tracking-wider flex items-center justify-center gap-1.5 text-[11px] font-sans shadow-md">
                    <i class="fa-brands fa-whatsapp text-sm"></i> WhatsApp Support
                </a>
            <?php endif; ?>
            <?php if (empty($tenantTelegram) && empty($tenantWhatsapp)): ?>
                <p class="text-[10px] text-gray-500 italic">Support contact not configured yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- 14. BOTTOM NAVIGATION DOCK -->
    <nav class="fixed bottom-2.5 left-1/2 -translate-x-1/2 w-[94%] max-w-sm z-40 nav-bottom-bar px-2.5 py-1.5 flex items-center justify-between font-mono">
        <button onclick="goToPage('dashboard')" id="bnav-dashboard" class="bottom-nav-btn active flex flex-col items-center flex-1 space-y-0.5">
            <div class="nav-icon-wrap w-7 h-7 rounded-lg flex items-center justify-center text-xs">
                <i class="fa-solid fa-house"></i>
            </div>
            <span class="text-[8px] font-bold">Store</span>
        </button>

        <button onclick="handleNavClick('history')" id="bnav-history" class="bottom-nav-btn flex flex-col items-center flex-1 space-y-0.5">
            <div class="nav-icon-wrap w-7 h-7 rounded-lg flex items-center justify-center text-xs">
                <i class="fa-solid fa-bag-shopping"></i>
            </div>
            <span class="text-[8px] font-bold">Orders</span>
        </button>

        <button onclick="handleNavClick('addfund')" id="bnav-addfund" class="flex flex-col items-center flex-1">
            <div class="center-fund-btn w-10 h-10 rounded-xl flex items-center justify-center text-sm font-black shadow-md">
                <i class="fa-solid fa-plus"></i>
            </div>
            <span class="text-[8px] font-black text-emerald-400 mt-[-2px]">Add Fund</span>
        </button>

        <button onclick="handleNavClick('resetkey')" id="bnav-resetkey" class="bottom-nav-btn flex flex-col items-center flex-1 space-y-0.5">
            <div class="nav-icon-wrap w-7 h-7 rounded-lg flex items-center justify-center text-xs">
                <i class="fa-solid fa-arrows-rotate"></i>
            </div>
            <span class="text-[8px] font-bold">Reset</span>
        </button>

        <button onclick="handleNavClick('profile')" id="bnav-profile" class="bottom-nav-btn flex flex-col items-center flex-1 space-y-0.5">
            <div class="nav-icon-wrap w-7 h-7 rounded-lg flex items-center justify-center text-xs overflow-hidden">
                <?php if (!empty($user['avatar_url']) && strlen($user['avatar_url']) > 15): ?>
                    <img src="<?= $user['avatar_url'] ?>" class="w-5 h-5 rounded-full object-cover border border-fuchsia-400 shadow-sm" alt="Avatar">
                <?php else: ?>
                    <i class="fa-solid fa-user-astronaut"></i>
                <?php endif; ?>
            </div>
            <span class="text-[8px] font-bold">Profile</span>
        </button>
        </nav>

    <!-- 15. CLIENT JAVASCRIPT ENGINE -->
    <script>
        const allProductsCatalog       = <?= json_encode($catalogProducts, JSON_UNESCAPED_UNICODE) ?>;
        const allActivePaymentMethods  = <?= json_encode($activePaymentMethods, JSON_UNESCAPED_UNICODE) ?>;
        const globalPaymentBannerUrl   = <?= json_encode($globalPaymentBanner) ?>;
        const globalAppDownloadUrl     = <?= json_encode($globalAppDownloadUrl) ?>;
        const currentBdtRate           = <?= (float)$effectiveBdtRate ?>;
        const currentMarketRate        = <?= (float)$binanceMarketRate ?>;
        const currentInrRate           = <?= (float)$inrExchangeRate ?>;
        let globalSelectedCurrency     = (function(){ try { return localStorage.getItem('site_currency') || 'BDT'; } catch (e) { return 'BDT'; } })();
        let appliedModalCoupon         = null;
        const allUserResetKeys         = <?= json_encode($userAllResetKeys, JSON_UNESCAPED_UNICODE) ?>;
        const totalBannersCount        = <?= (int)count($activeBanners) ?>;
        let   userCurrentBalanceBdt    = <?= (float)$userBalanceBdt ?>;
        const userCurrentCoins         = <?= (int)$userCoins ?>;
        const isDepositSystemOnline    = <?= $canAddFund === 1 ? 'true' : 'false' ?>;
        const requestedInitialProdId   = <?= (int)$initialDeepLinkProdId ?>;
        const isSpinWheelActiveGlobal  = <?= $isSpinWheelActive ? 'true' : 'false' ?>;
        const isUserLoggedIn           = <?= $isLoggedIn ? 'true' : 'false' ?>;
        const googleClientIdConfig     = '<?= htmlspecialchars($settings['google_client_id'] ?? '', ENT_QUOTES) ?>';
        const firebaseClientConfig     = <?= json_encode($firebaseClientConfig, JSON_UNESCAPED_UNICODE) ?>;
        const liveFeedData             = <?= json_encode($livePurchaseFeed, JSON_UNESCAPED_UNICODE) ?>;

        let activeModalProduct = null;
        let activeSelectedPlan = null;
        let activeBdtMethodObj = null;
        let activeAddFundMethodObj = null;
        let activeAddFundCurrency = 'BDT';
        
        let currentSlideIdx = 0;
        let carouselTimer = null;
        let latestKeys = [];

        let spinSegments = [];
        let isWheelSpinning = false;
        let currentWheelAngle = 0;
        let urgencyTimerSeconds = 47;
        let firebaseAuthInitialized = false;
        let deferredPrompt = null;

        // PWA Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('service-worker.js').catch(err => console.log('SW registration note:', err));
            });
        }

        // ===================== BOTTOM LIVE HISTORY & AUTO-SYNC ENGINE =====================
        let isTopBuyersExpanded = false;
        let isRecentOrdersExpanded = false;

        function switchBottomHistoryTab(tab, btn) {
            document.querySelectorAll('.b-tab-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            const tb = document.getElementById('bTabTopBuyers');
            const ro = document.getElementById('bTabRecentOrders');
            if (tab === 'top_buyers') {
                if (tb) tb.classList.remove('hidden');
                if (ro) ro.classList.add('hidden');
            } else {
                if (tb) tb.classList.add('hidden');
                if (ro) ro.classList.remove('hidden');
            }
        }

        function toggleMoreHistory(type, btn) {
            if (type === 'top_buyers') {
                isTopBuyersExpanded = !isTopBuyersExpanded;
                document.querySelectorAll('.extra-top-buyer').forEach(el => {
                    if (isTopBuyersExpanded) el.classList.remove('hidden');
                    else el.classList.add('hidden');
                });
                btn.innerHTML = isTopBuyersExpanded 
                    ? `<span>Less</span> <i class="fa-solid fa-chevron-up text-[9px]"></i>` 
                    : `<span>More</span> <i class="fa-solid fa-chevron-down text-[9px]"></i>`;
            } else {
                isRecentOrdersExpanded = !isRecentOrdersExpanded;
                document.querySelectorAll('.extra-recent-order').forEach(el => {
                    if (isRecentOrdersExpanded) el.classList.remove('hidden');
                    else el.classList.add('hidden');
                });
                btn.innerHTML = isRecentOrdersExpanded 
                    ? `<span>Less</span> <i class="fa-solid fa-chevron-up text-[9px]"></i>` 
                    : `<span>More</span> <i class="fa-solid fa-chevron-down text-[9px]"></i>`;
            }
        }

        // লাইভ অটো-সিঙ্ক: প্রতি ৭ সেকেন্ড পর ব্যাকগ্রাউন্ডে চেক করে নতুন অর্ডার সরাসরি আপডেট করবে
        function maskNameJS(name) {
            if (!name) return 'Customer';
            const len = name.length;
            if (len <= 3) return name.substring(0, 1) + '***';
            return name.substring(0, 3) + '*'.repeat(Math.max(0, len - 5)) + name.substring(len - 2);
        }

        async function autoSyncLiveHistory() {
            try {
                const fd = new FormData();
                fd.append('ajax_action', 'get_live_history_data');
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    const rate = data.rate || currentBdtRate;

                    // আপডেট রিসেন্ট অর্ডার লিস্ট
                    const roContainer = document.getElementById('recentOrdersListContainer');
                    if (roContainer && data.recent_orders && data.recent_orders.length > 0) {
                        let roHtml = '';
                        data.recent_orders.forEach((ro, i) => {
                            const isHidden = (!isRecentOrdersExpanded && i >= 3) ? 'hidden extra-recent-order' : 'extra-recent-order';
                            const paidVal = Math.round((parseFloat(ro.price) || 0) * rate);
                            const letter = (ro.username || 'U').charAt(0).toUpperCase();
                            const hasPhoto = (ro.avatar_url && ro.avatar_url.length > 15);
                            
                            roHtml += `
                                <div class="b-member-card ${isHidden}">
                                    <div class="flex items-center space-x-3">
                                        <div class="b-avatar border-cyan-500/60 shadow-[0_0_10px_rgba(0,245,255,0.25)] overflow-hidden">
                                            ${hasPhoto ? `<img loading="lazy" src="${ro.avatar_url}" class="w-full h-full object-cover rounded-full" alt="Avatar">` : `<span class="text-cyan-300">${letter}</span>`}
                                            <div class="b-medal-badge bg-gradient-to-tr from-cyan-400 to-blue-500 text-slate-950 shadow-[0_0_8px_rgba(0,245,255,0.6)]">
                                                <i class="fa-solid fa-bolt"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h4 class="text-xs sm:text-sm font-extrabold text-white leading-tight">
                                                ${maskNameJS(ro.username)}
                                            </h4>
                                            <span class="text-[9px] text-cyan-300/80 font-mono block mt-0.5 truncate max-w-[150px] sm:max-w-none">
                                                ${ro.validity || 'Digital Pass'}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <strong class="text-sm font-black text-cyan-400 font-mono">
                                            <span class="dynamic-price-el" data-bdt="${paidVal}" data-cur="short">${formatDisplayShort(paidVal)}</span>
                                        </strong>
                                    </div>
                                </div>
                            `;
                        });
                        roContainer.innerHTML = roHtml;
                    }

                    // আপডেট টপ বায়ার লিস্ট
                    const tbContainer = document.getElementById('topBuyersListContainer');
                    if (tbContainer && data.top_buyers && data.top_buyers.length > 0) {
                        let tbHtml = '';
                        data.top_buyers.forEach((tb, i) => {
                            const isHidden = (!isTopBuyersExpanded && i >= 3) ? 'hidden extra-top-buyer' : 'extra-top-buyer';
                            const spentVal = Math.round((parseFloat(tb.spent) || 0) * rate);
                            const letter = (tb.username || 'U').charAt(0).toUpperCase();
                            const hasPhoto = (tb.avatar_url && tb.avatar_url.length > 15);

                            tbHtml += `
                                <div class="b-member-card ${isHidden}">
                                    <div class="flex items-center space-x-3">
                                        <div class="b-avatar overflow-hidden">
                                            ${hasPhoto ? `<img loading="lazy" src="${tb.avatar_url}" class="w-full h-full object-cover rounded-full" alt="Avatar">` : `<span>${letter}</span>`}
                                            <div class="b-medal-badge">
                                                <i class="fa-solid fa-medal"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <h4 class="text-sm font-extrabold text-white leading-tight">
                                                ${maskNameJS(tb.username)}
                                            </h4>
                                            <span class="inline-block px-2 py-0.5 rounded-full text-[9px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 mt-0.5">
                                                Top Member
                                            </span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <strong class="text-sm sm:text-base font-black text-[#00ff88] font-mono">
                                            <span class="dynamic-price-el" data-bdt="${spentVal}" data-cur="short">${formatDisplayShort(spentVal)}</span>
                                        </strong>
                                    </div>
                                </div>
                            `;
                        });
                        tbContainer.innerHTML = tbHtml;
                    }
                }
            } catch (e) {}
        }

        // অটো-সিঙ্ক প্রতি ৭ সেকেন্ড পর পর ব্যাকগ্রাউন্ডে চেক করবে
        // ⚡ SPEED: tab hidden থাকলে সার্ভারে রিকোয়েস্ট যাবে না
        setInterval(() => { if (!document.hidden) autoSyncLiveHistory(); }, 7000);

        // ===================== PAYMENT RESULT POPUP =====================
        function showPaymentResultPopup(type, data) {
            const modal = document.getElementById('paymentResultModal');
            const iconBox = document.getElementById('resultModalIconContainer');
            const badge = document.getElementById('resultModalBadge');
            const heading = document.getElementById('resultModalHeading');
            const bodyBox = document.getElementById('resultModalBodyBox');
            const actionBtn = document.getElementById('resultModalActionBtn');

            if (!modal) return;

            if (type === 'success') {
                iconBox.className = "w-16 h-16 mx-auto rounded-full bg-gradient-to-tr from-emerald-400 to-teal-500 text-gray-950 flex items-center justify-center text-3xl border-2 border-white shadow-[0_0_25px_rgba(0,255,136,0.8)] animate-bounce mt-1";
                iconBox.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
                badge.className = "promo-shimmer-badge px-3 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest font-mono";
                badge.innerText = "সফল • ভেরিফাইড";
                heading.innerText = "সফলভাবে সম্পন্ন হয়েছে!";

                let bodyHtml = `<div class="flex items-center justify-between text-xs"><span class="text-gray-300">পরিমাণ:</span><strong class="text-white font-mono">৳${parseFloat(data.amount || 0).toLocaleString()} BDT</strong></div>`;
                if (parseFloat(data.bonus || 0) > 0) {
                    bodyHtml += `<div class="flex items-center justify-between text-xs"><span class="text-yellow-400 font-bold">বোনাস:</span><strong class="text-[#00ff88] font-mono">+${formatDisplayPrice(parseFloat(data.bonus) || 0)}</strong></div>`;
                }
                if (data.remaining_balance) {
                    bodyHtml += `<div class="flex items-center justify-between text-xs pt-1 border-t border-white/5"><span class="text-gray-400">ব্যালেন্স:</span><strong class="text-cyan-300 font-mono">${formatDisplayPrice(parseFloat(String(data.remaining_balance).replace(/[^0-9.\-]/g, '')) || 0)}</strong></div>`;
                }
                bodyBox.className = "bg-[#0b0318] p-3.5 rounded-2xl border border-emerald-500/50 space-y-2 text-left font-bengali";
                bodyBox.innerHTML = bodyHtml;
                actionBtn.className = "w-full py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 text-gray-950 font-black text-xs uppercase tracking-wider shadow-lg active:scale-95 transition font-mono";
                actionBtn.innerText = "বুঝে পেয়েছি • ধন্যবাদ";
                launchBajiFireworks();

            } else if (type === 'pending') {
                iconBox.className = "w-16 h-16 mx-auto rounded-full bg-gradient-to-tr from-amber-400 to-yellow-500 text-gray-950 flex items-center justify-center text-3xl border-2 border-white shadow-[0_0_25px_rgba(250,204,21,0.8)] animate-pulse mt-1";
                iconBox.innerHTML = '<i class="fa-solid fa-clock"></i>';
                badge.className = "px-3 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest font-mono bg-amber-500 text-gray-950";
                badge.innerText = "পেন্ডিং • অপেক্ষারত";
                heading.innerText = "রিকোয়েস্ট জমা হয়েছে!";
                bodyBox.className = "bg-[#0b0318] p-3.5 rounded-2xl border border-yellow-500/50 space-y-2 text-left font-bengali";
                bodyBox.innerHTML = `
                    <div class="flex items-center justify-between text-xs"><span class="text-gray-300">পরিমাণ:</span><strong class="text-white font-mono">৳${parseFloat(data.amount || 0).toLocaleString()} BDT</strong></div>
                    <p class="text-[11px] text-gray-300 pt-1 leading-snug">আপনার ট্রানজেকশন অ্যাডমিন রিভিউতে আছে। ভেরিফাই হলেই বোনাসসহ ওয়ালেটে ক্রেডিট হবে।</p>
                `;
                actionBtn.className = "w-full py-3 rounded-xl bg-gradient-to-r from-amber-500 to-yellow-500 text-gray-950 font-black text-xs uppercase tracking-wider shadow-lg active:scale-95 transition font-mono";
                actionBtn.innerText = "ঠিক আছে • অপেক্ষা করছি";

            } else {
                iconBox.className = "w-16 h-16 mx-auto rounded-full bg-gradient-to-tr from-rose-500 to-red-600 text-white flex items-center justify-center text-3xl border-2 border-white shadow-[0_0_25px_rgba(244,63,94,0.8)] mt-1";
                iconBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
                badge.className = "px-3 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest font-mono bg-rose-600 text-white";
                badge.innerText = "ব্যর্থ হয়েছে";
                heading.innerText = "পেমেন্ট নিশ্চিত করা যায়নি!";
                bodyBox.className = "bg-[#18050b] p-3.5 rounded-2xl border border-rose-500/50 space-y-2 text-left font-bengali";
                bodyBox.innerHTML = `
                    <p class="text-xs text-rose-300 font-bold leading-relaxed">${data.message || 'ট্রানজেকশন আইডি গেটওয়েতে খুঁজে পাওয়া যায়নি বা অ্যামাউন্ট মেলেনি।'}</p>
                    <p class="text-[10px] text-gray-400 leading-snug pt-1">সঠিক TrxID কপি করে আবার চেষ্টা করুন অথবা সাপোর্টে যোগাযোগ করুন।</p>
                `;
                actionBtn.className = "w-full py-3 rounded-xl bg-gradient-to-r from-rose-600 to-red-700 text-white font-black text-xs uppercase tracking-wider shadow-lg active:scale-95 transition font-mono";
                actionBtn.innerText = "আবার চেষ্টা করুন";
            }
            modal.classList.remove('hidden');
        }
        function closePaymentResultModal() {
            document.getElementById('paymentResultModal')?.classList.add('hidden');
        }

        // ===================== OPEN SAFE LINK =====================
        function openSafeLink(url, fallbackMsg) {
            if (url && typeof url === 'string' && url.trim() !== '' && url !== '#' && url !== 'null' && url !== 'undefined') {
                window.open(url, '_blank');
            } else {
                showToast(fallbackMsg || 'Link not available yet', 'error');
            }
        }

        // ===================== PWA INSTALL BANNER =====================
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            showInstallShopBanner();
        });
        function showInstallShopBanner() {
            const hasDismissed = localStorage.getItem('dismissed_install_banner');
            if (!hasDismissed) {
                const banner = document.getElementById('installShopBanner');
                if (banner) setTimeout(() => banner.classList.add('show-install'), 1800);
            }
        }
        function triggerInstallShop() {
            const banner = document.getElementById('installShopBanner');
            const directAppUrl = (banner && banner.getAttribute('data-app-url')) ? banner.getAttribute('data-app-url').trim() : (globalAppDownloadUrl || '').trim();
            if (directAppUrl && directAppUrl !== '#' && directAppUrl !== '') {
                showToast('Starting app download...', 'success');
                const a = document.createElement('a');
                a.href = directAppUrl;
                a.setAttribute('download', '');
                a.target = '_blank';
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                if (banner) banner.classList.remove('show-install');
                return;
            }
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') showToast('Thank you for installing!', 'success');
                    deferredPrompt = null;
                    if (banner) banner.classList.remove('show-install');
                });
                return;
            }
            showToast('Tap browser menu (⋮) and select "Add to Home screen"!', 'info');
            if (banner) banner.classList.remove('show-install');
        }
        function dismissInstallShop() {
            document.getElementById('installShopBanner')?.classList.remove('show-install');
            localStorage.setItem('dismissed_install_banner', 'true');
        }

        // ===================== FIREBASE & GOOGLE AUTH =====================
        function initFirebaseClientEngine() {
            if (firebaseClientConfig && firebaseClientConfig.apiKey && firebaseClientConfig.projectId && typeof firebase !== 'undefined') {
                try {
                    if (!firebase.apps.length) firebase.initializeApp(firebaseClientConfig);
                    firebaseAuthInitialized = true;
                } catch(e) {}
            }
            if (googleClientIdConfig && !googleClientIdConfig.includes('example') && googleClientIdConfig.trim() !== '') {
                if (typeof google !== 'undefined' && google.accounts && google.accounts.id) {
                    try {
                        google.accounts.id.initialize({
                            client_id: googleClientIdConfig.trim(),
                            callback: handleGoogleAuthCallback,
                            auto_select: false,
                            cancel_on_tap_outside: true
                        });
                    } catch(e) {}
                }
            }
        }
        async function handleUnifiedGoogleLogin() {
            if (firebaseAuthInitialized && typeof firebase !== 'undefined' && firebase.auth) {
                try {
                    const provider = new firebase.auth.GoogleAuthProvider();
                    provider.addScope('email'); provider.addScope('profile');
                    provider.setCustomParameters({ prompt: 'select_account' });
                    const result = await firebase.auth().signInWithPopup(provider);
                    const user = result.user;
                    if (user && user.email) {
                        showToast('Verifying Google credentials...', 'success');
                        const formData = new FormData();
                        formData.append('auth_action', 'firebase_auth');
                        formData.append('email', user.email.toLowerCase().trim());
                        formData.append('name', user.displayName || '');
                        formData.append('firebase_uid', user.uid || '');
                        const res = await fetch('index.php', { method: 'POST', body: formData });
                        const data = await res.json();
                        if (data.status === 'success') {
                            showToast(data.message, 'success');
                            setTimeout(() => window.location.reload(), 600);
                        } else showToast(data.message || 'Login failed!', 'error');
                        return;
                    }
                } catch(err) {
                    if (err.code === 'auth/popup-blocked') { showToast('Popup was blocked by browser!', 'error'); return; }
                }
            }
            promptDirectGmailLogin();
        }
        function promptDirectGmailLogin() {
            const userEmail = prompt("Enter your Gmail address to login/register instantly:");
            if (!userEmail) return;
            if (!userEmail.includes('@') || !userEmail.toLowerCase().includes('.com')) {
                showToast("Please enter a valid Gmail address!", "error"); return;
            }
            const formData = new FormData();
            formData.append('auth_action', 'google_auth');
            formData.append('direct_email', userEmail.trim().toLowerCase());
            fetch('index.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        setTimeout(() => window.location.reload(), 700);
                    } else showToast(data.message || 'Login failed!', 'error');
                })
                .catch(() => showToast('Network error!', 'error'));
        }
        async function handleGoogleAuthCallback(response) {
            if (!response || !response.credential) return;
            const formData = new FormData();
            formData.append('auth_action', 'google_auth');
            formData.append('credential', response.credential);
            try {
                const res = await fetch('index.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    setTimeout(() => window.location.reload(), 700);
                } else showToast(data.message || 'Login failed!', 'error');
            } catch (err) { showToast('Network error!', 'error'); }
        }

        // ===================== NAVIGATION & MODALS =====================
        function openAuthModal(defaultTab = 'login') { switchAuthTab(defaultTab); document.getElementById('authModal').classList.remove('hidden'); }
        function closeAuthModal() { document.getElementById('authModal').classList.add('hidden'); }
        function switchAuthTab(tab) {
            const btnLog = document.getElementById('tabAuthLogin');
            const btnReg = document.getElementById('tabAuthRegister');
            const formLog = document.getElementById('formAuthLogin');
            const formReg = document.getElementById('formAuthRegister');
            if (tab === 'login') {
                btnLog.className = 'flex-1 py-2 rounded-lg bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-black transition-all font-mono shadow-md';
                btnReg.className = 'flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition-all font-mono';
                formLog.classList.remove('hidden'); formReg.classList.add('hidden');
            } else {
                btnReg.className = 'flex-1 py-2 rounded-lg bg-gradient-to-r from-cyan-500 to-blue-600 text-gray-950 font-black transition-all font-mono shadow-md';
                btnLog.className = 'flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition-all font-mono';
                formReg.classList.remove('hidden'); formLog.classList.add('hidden');
            }
        }
        function handleNavClick(pageId) {
            if (!isUserLoggedIn && pageId !== 'dashboard') { openAuthModal('login'); return; }
            goToPage(pageId);
        }
        function goToPage(pageId) {
            if (pageId === 'addfund' && !isDepositSystemOnline) { showToast('Deposit system is currently offline!', 'error'); return; }
            document.querySelectorAll('.page-section').forEach(p => p.classList.add('hidden'));
            const activeEl = document.getElementById('page-' + pageId);
            if (activeEl) activeEl.classList.remove('hidden');
            document.querySelectorAll('.bottom-nav-btn').forEach(btn => btn.classList.remove('active'));
            const curNav = document.getElementById('bnav-' + pageId);
            if (curNav) curNav.classList.add('active');
            sessionStorage.setItem('dashboard_active_page', pageId);
            window.scrollTo({ top: 0, behavior: 'smooth' });
            if (pageId === 'addfund') closeDedicatedAddFundScreen();
        }

        function openNotificationsModal() { document.getElementById('notificationsModal').classList.remove('hidden'); }
        function closeNotificationsModal() { document.getElementById('notificationsModal').classList.add('hidden'); }
        function openEditProfileModal() { document.getElementById('editProfileModal').classList.remove('hidden'); }
        // Avatar instant upload
document.addEventListener('DOMContentLoaded', function() {
    const avatarInput = document.getElementById('avatarFileInput');
    if (!avatarInput) return;
    avatarInput.addEventListener('change', function() {
        const file = this.files[0];
        if (!file) return;
        const allowed = ['image/jpeg','image/png','image/webp'];
        if (!allowed.includes(file.type)) { showToast('Only JPG, PNG, WEBP allowed!', 'error'); return; }
        if (file.size > 3 * 1024 * 1024) { showToast('Max 3MB allowed!', 'error'); return; }
        const reader = new FileReader();
        reader.onload = function(e) {
            let img = document.getElementById('avatarPreviewImg');
            if (!img) {
                img = document.createElement('img');
                img.id = 'avatarPreviewImg';
                img.className = 'w-full h-full object-cover rounded-full';
                img.alt = 'Profile';
                const initDiv = document.getElementById('avatarInitialDiv');
                if (initDiv) initDiv.replaceWith(img);
            }
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
        const fd = new FormData();
        fd.append('action', 'update_avatar_only');
        fd.append('avatar_file', file);
        showToast('Uploading...', 'success');
        fetch('index.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { showToast(d.status === 'success' ? 'Photo updated!' : (d.message || 'Failed!'), d.status === 'success' ? 'success' : 'error'); })
            .catch(() => showToast('Upload failed!', 'error'));
    });
});
        function closeModal(id) { document.getElementById(id)?.classList.add('hidden'); }

        function handleSplashDismissal() {
            const preloader = document.getElementById('siteSplashPreloader');
            if (preloader) {
                // ⚡ SPEED: short splash only on the first visit of a session, instant afterwards
                let seenSplash = false;
                try { seenSplash = sessionStorage.getItem('splash_seen') === '1'; sessionStorage.setItem('splash_seen', '1'); } catch (e) {}
                const holdMs = seenSplash ? 0 : 350;
                setTimeout(() => {
                    preloader.classList.add('splash-fade-out');
                    setTimeout(() => {
                        preloader.style.display = 'none';
                        showInstallShopBanner();
                        checkFirstTimePromoOffer();
                    }, seenSplash ? 0 : 250);
                }, holdMs);
            } else {
                showInstallShopBanner(); checkFirstTimePromoOffer();
            }
        }

        function checkFirstTimePromoOffer() {
            const promoModal = document.getElementById('firstVisitPromoModal');
            if (!promoModal) return;
            const lastShown = localStorage.getItem('last_promo_shown_time');
            const now = new Date().getTime();
            const oneHour = 60 * 60 * 1000;
            if (!lastShown || (now - parseInt(lastShown)) > oneHour) {
                setTimeout(() => {
                    promoModal.classList.remove('hidden');
                    localStorage.setItem('last_promo_shown_time', now.toString());
                }, 1000);
            }
        }
        function closePromoModal() {
            document.getElementById('firstVisitPromoModal')?.classList.add('hidden');
        }
        function handlePromoClaimClick() {
            closePromoModal();
            if (!isUserLoggedIn) openAuthModal('login');
            else goToPage('addfund');
        }

        // ===================== CAROUSEL =====================
        function updateCarousel() {
            const track = document.getElementById('carouselTrack');
            if (!track || totalBannersCount <= 1) return;
            track.style.transform = `translateX(-${currentSlideIdx * 100}%)`;
            document.querySelectorAll('.carousel-dot').forEach((dot, idx) => {
                dot.className = (idx === currentSlideIdx) 
                    ? 'carousel-dot w-4 h-2 rounded-full bg-[#d946ef] shadow-[0_0_6px_#d946ef] transition-all'
                    : 'carousel-dot w-2 h-2 rounded-full bg-gray-500/70 transition-all';
            });
        }
        function goToSlide(idx) { currentSlideIdx = idx; updateCarousel(); resetCarouselTimer(); }
        function resetCarouselTimer() {
            if (carouselTimer) clearInterval(carouselTimer);
            if (totalBannersCount > 1) {
                carouselTimer = setInterval(() => {
                    currentSlideIdx = (currentSlideIdx + 1) % totalBannersCount;
                    updateCarousel();
                }, 3500);
            }
        }
        function filterByCategory(category, btn) {
            document.querySelectorAll('.category-pill').forEach(el => {
                el.className = 'category-pill px-3.5 py-1.5 rounded-xl flex items-center space-x-1 flex-shrink-0 text-[11px] font-mono';
            });
            btn.className = 'category-pill active px-3.5 py-1.5 rounded-xl flex items-center space-x-1 flex-shrink-0 text-[11px] font-mono';
            document.querySelectorAll('.prod-item-box').forEach(c => {
                const cat = (c.getAttribute('data-category') || '').toUpperCase();
                if (category === 'ALL' || cat === category.toUpperCase()) c.classList.remove('hidden');
                else c.classList.add('hidden');
            });
        }

        // ===================== ADD FUND =====================
        function openDedicatedAddFundScreen(methodId) {
            const methodObj = allActivePaymentMethods.find(m => parseInt(m.id) === parseInt(methodId));
            if (!methodObj) { showToast('Payment method unavailable!', 'error'); return; }
            activeAddFundMethodObj = methodObj;
            activeAddFundCurrency = 'BDT';
            const bannerImg = document.getElementById('addFundBannerImg');
            bannerImg.src = methodObj.banner_url || globalPaymentBannerUrl || 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&q=80';
            document.getElementById('addFundDedicatedMethodTitle').innerText = `${methodObj.name} Deposit`;
            document.getElementById('addFundTypeLabel').innerText = `${methodObj.account_type || 'Personal'} Account Number:`;
            document.getElementById('addFundNumberVal').innerText = methodObj.account_number;
            document.getElementById('addFundInstructionBox').innerText = methodObj.instructions || `Send Money to the ${methodObj.account_type || 'Personal'} number above, then enter the deposit amount and Transaction ID (TrxID) below to verify instantly.`;
            document.getElementById('addFundAmountInput').value = '';
            document.getElementById('addFundTrxIdInput').value = '';
            document.getElementById('addFundSenderInput').value = '';
            document.getElementById('addFundOrderError').classList.add('hidden');
            document.getElementById('addFundOrderSuccess').classList.add('hidden');
            calculateAddFundLiveConversion();
            document.getElementById('addFundMethodListContainer').classList.add('hidden');
            document.getElementById('addFundDedicatedScreen').classList.remove('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        function openDedicatedAddFundBinance() {
            activeAddFundMethodObj = {
                id: 0, method_key: 'binance', name: 'Binance',
                account_number: '<?= htmlspecialchars($settings['binance_id']) ?>',
                account_type: 'Binance Pay ID (USDT)',
                instructions: 'Open Binance App > Pay > Send USDT to the Pay ID above, then enter amount in USDT and Transaction ID below.',
                banner_url: globalPaymentBannerUrl
            };
            activeAddFundCurrency = 'USDT';
            const bannerImg = document.getElementById('addFundBannerImg');
            bannerImg.src = globalPaymentBannerUrl || 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&q=80';
            document.getElementById('addFundDedicatedMethodTitle').innerText = `Binance Pay (USDT) Deposit`;
            document.getElementById('addFundTypeLabel').innerText = `Binance Pay ID / UID:`;
            document.getElementById('addFundNumberVal').innerText = activeAddFundMethodObj.account_number;
            document.getElementById('addFundInstructionBox').innerText = activeAddFundMethodObj.instructions;
            document.getElementById('addFundAmountInput').value = '';
            document.getElementById('addFundTrxIdInput').value = '';
            document.getElementById('addFundSenderInput').value = '';
            document.getElementById('addFundOrderError').classList.add('hidden');
            document.getElementById('addFundOrderSuccess').classList.add('hidden');
            calculateAddFundLiveConversion();
            document.getElementById('addFundMethodListContainer').classList.add('hidden');
            document.getElementById('addFundDedicatedScreen').classList.remove('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        function closeDedicatedAddFundScreen() {
            document.getElementById('addFundDedicatedScreen')?.classList.add('hidden');
            document.getElementById('addFundMethodListContainer')?.classList.remove('hidden');
        }
        function calculateAddFundLiveConversion() {
            const val = parseFloat(document.getElementById('addFundAmountInput').value) || 0;
            const tag = document.getElementById('addFundLiveConversionTag');
            let bdtEquivalent = (activeAddFundCurrency === 'BDT') ? val : (val > 0 ? (val * currentMarketRate) : 0);
            tag.innerText = `≈ ৳${bdtEquivalent.toFixed(0)} BDT`;
        }
        async function submitAddFundVerification() {
            if (!activeAddFundMethodObj) return;
            const amount = parseFloat(document.getElementById('addFundAmountInput').value) || 0;
            const trxId = document.getElementById('addFundTrxIdInput').value.trim();
            const sender = document.getElementById('addFundSenderInput').value.trim();
            const errBox = document.getElementById('addFundOrderError');
            const sucBox = document.getElementById('addFundOrderSuccess');
            errBox.classList.add('hidden'); sucBox.classList.add('hidden');
            if (amount <= 0) { errBox.innerText = 'Please enter a valid deposit amount!'; errBox.classList.remove('hidden'); showToast('Please enter deposit amount!', 'error'); return; }
            if (!trxId || trxId.length < 4) { errBox.innerText = 'Please enter a valid TrxID!'; errBox.classList.remove('hidden'); showToast('TrxID is required!', 'error'); return; }
            const btn = document.getElementById('btnVerifyAddFund');
            const btnOriginal = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Verifying...';
            const formData = new FormData();
            formData.append('ajax_action', 'verify_add_fund');
            formData.append('method_id', activeAddFundMethodObj.id);
            formData.append('method_key', activeAddFundMethodObj.method_key || activeAddFundMethodObj.name.toLowerCase());
            formData.append('amount', amount);
            formData.append('trx_id', trxId);
            formData.append('sender', sender);
            try {
                const res = await fetch('index.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.status === 'success') {
                    updateLiveBalance(data.remaining_balance);
                    showPaymentResultPopup('success', data);
                    document.getElementById('addFundAmountInput').value = '';
                    document.getElementById('addFundTrxIdInput').value = '';
                    document.getElementById('addFundSenderInput').value = '';
                } else if (data.status === 'pending_submitted') {
                    showPaymentResultPopup('pending', data);
                    document.getElementById('addFundAmountInput').value = '';
                    document.getElementById('addFundTrxIdInput').value = '';
                    document.getElementById('addFundSenderInput').value = '';
                } else {
                    showPaymentResultPopup('error', data);
                    errBox.innerText = data.message || 'Deposit failed!';
                    errBox.classList.remove('hidden');
                }
            } catch(e) {
                showPaymentResultPopup('error', { message: 'Network timeout!' });
            } finally {
                btn.disabled = false; btn.innerHTML = btnOriginal;
            }
        }

        // ===================== PRODUCT PURCHASE =====================
        function openProductModal(productId) {
            const prod = allProductsCatalog[productId];
            if (!prod) return;
            if (parseInt(prod.is_active) === 0 || parseInt(prod.is_maintenance || 0) === 1) { showToast('🔧 ' + (prod.maintenance_msg || 'This product is under maintenance!'), 'error'); return; }
            activeModalProduct = prod;
            if (typeof clearModalCoupon === 'function') clearModalCoupon(true);
            document.getElementById('modalProdName').innerText = prod.name;
            document.getElementById('modalProdDesc').innerText = prod.description || 'Verified fast license delivery.';
            const thumb = document.getElementById('modalProdThumb');
            if (prod.logo_url) {
                thumb.innerHTML = `<img src="${prod.logo_url}" alt="" style="width: 100%; height: 100%; object-fit: cover;">`;
            } else {
                thumb.innerHTML = `<i class="fa-solid fa-gamepad text-fuchsia-400 text-lg"></i>`;
            }
            const listContainer = document.getElementById('customPlanListContainer');
            listContainer.innerHTML = '';
            document.getElementById('planDetailsInfoCard').classList.add('hidden');
            if (prod.plans && prod.plans.length > 0) {
                prod.plans.forEach((pl, idx) => {
                    const isSelected = (idx === 0);
                    let badgesHtml = '';
                    if (pl.discount_percent && parseInt(pl.discount_percent) > 0) badgesHtml += `<span class="plan-badge-discount">${pl.discount_percent}% OFF</span>`;
                    if (pl.reward_coins && parseInt(pl.reward_coins) > 0) badgesHtml += `<span class="plan-badge-gift">🎁 +${pl.reward_coins} Coins</span>`;
                    if (pl.coin_price && parseInt(pl.coin_price) > 0) badgesHtml += `<span class="plan-badge-coinpay">🪙 ${pl.coin_price} Coin Pay</span>`;
                    const itemDiv = document.createElement('div');
                    itemDiv.className = `custom-plan-item ${isSelected ? 'selected' : ''}`;
                    itemDiv.setAttribute('data-plan-id', pl.plan_id);
                    itemDiv.innerHTML = `
                        <div class="flex-1">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[11px] font-bold text-white font-sans">${pl.validity}</span>
                                <span class="text-[11px] font-black text-[#00ff88] font-mono plan-price-tag" data-bdt="${pl.price_bdt}">${formatDisplayPrice(pl.price_bdt)}</span>
                            </div>
                            <div class="plan-details-badges">${badgesHtml}</div>
                        </div>
                        <div class="ml-3 flex-shrink-0"><div class="plan-radio-circle ${isSelected ? 'bg-[#00ff88] border-[#00ff88]' : ''}"></div></div>
                    `;
                    itemDiv.onclick = () => {
                        listContainer.querySelectorAll('.custom-plan-item').forEach(el => {
                            el.classList.remove('selected');
                            el.querySelector('.plan-radio-circle').classList.remove('bg-[#00ff88]', 'border-[#00ff88]');
                        });
                        itemDiv.classList.add('selected');
                        itemDiv.querySelector('.plan-radio-circle').classList.add('bg-[#00ff88]', 'border-[#00ff88]');
                        activeSelectedPlan = pl;
                        document.getElementById('modalPlanSelect').value = pl.plan_id;
                        updatePlanDetailsDisplay(pl);
                        updateStep1TotalCost();
                    };
                    listContainer.appendChild(itemDiv);
                });
                activeSelectedPlan = prod.plans[0];
                document.getElementById('modalPlanSelect').value = activeSelectedPlan.plan_id;
                updatePlanDetailsDisplay(activeSelectedPlan);
            } else {
                listContainer.innerHTML = '<div class="text-center text-xs text-gray-500 py-4 font-sans">No plans available</div>';
                activeSelectedPlan = null;
            }
            document.getElementById('purchaseQtyInput').value = 1;
            goBackToPlanStep();
            updateStep1TotalCost();
            document.getElementById('productPurchaseModal').classList.remove('hidden');
        }
        function closeProductPurchaseModal() { document.getElementById('productPurchaseModal').classList.add('hidden'); }
        function updatePlanDetailsDisplay(plan) {
            const infoCard = document.getElementById('planDetailsInfoCard');
            if (!plan) { infoCard.classList.add('hidden'); return; }
            infoCard.classList.remove('hidden');
            setCurrencyAmount('planCurrentPriceText', plan.price_bdt);
            const discount = parseInt(plan.discount_percent || 0);
            const origStrike = document.getElementById('planOrigPriceStrike');
            const discountBadge = document.getElementById('planDiscountBadge');
            if (discount > 0 && plan.original_bdt > plan.price_bdt) {
                setCurrencyAmount(origStrike, plan.original_bdt, { short: true });
                origStrike.classList.remove('hidden');
                discountBadge.innerText = `${discount}% OFF`;
                discountBadge.classList.remove('hidden');
            } else if (discount > 0) {
                discountBadge.innerText = `${discount}% OFF`;
                discountBadge.classList.remove('hidden');
                origStrike.classList.add('hidden');
            } else {
                origStrike.classList.add('hidden');
                discountBadge.classList.add('hidden');
            }
            const rewardCoins = parseInt(plan.reward_coins || 0);
            const rewardRow = document.getElementById('planRewardCoinRow');
            if (rewardCoins > 0) {
                document.getElementById('planRewardCoinVal').innerText = `+${rewardCoins} Coins Gift`;
                rewardRow.classList.remove('hidden');
            } else rewardRow.classList.add('hidden');
            const coinPrice = parseInt(plan.coin_price || 0);
            const coinPriceRow = document.getElementById('planCoinPriceRow');
            if (coinPrice > 0) {
                document.getElementById('planCoinPriceVal').innerText = `🪙 ${coinPrice.toLocaleString()} Coins`;
                coinPriceRow.classList.remove('hidden');
            } else coinPriceRow.classList.add('hidden');
        }
        function changePurchaseQty(val) {
            const input = document.getElementById('purchaseQtyInput');
            let current = parseInt(input.value) || 1;
            current = Math.max(1, Math.min(50, current + val));
            input.value = current;
            updateStep1TotalCost();
        }
        function updateStep1TotalCost() {
            if (!activeSelectedPlan) return;
            const qty = parseInt(document.getElementById('purchaseQtyInput').value) || 1;
            revalidateModalCoupon();
            let totalBdt = getModalPayableBdt();
            // টোটাল পেবল প্রাইস সিলেক্ট করা কারেন্সিতে রেন্ডার হবে
            document.getElementById('step1TotalCostText').innerText = formatDisplayPrice(totalBdt);
        }

        // মোডাল খোলার পর বা কারেন্সি বদলালে প্ল্যান লিস্টের দামগুলো আপডেট করার জন্য
        function updateModalPlansDisplayCurrency() {
            if (!activeModalProduct || !activeModalProduct.plans) return;
            const listContainer = document.getElementById('customPlanListContainer');
            if (!listContainer) return;
            
            // প্ল্যান লিস্টের ভেতরে প্রতিটি প্ল্যানের দাম কারেন্সি অনুযায়ী রি-রেন্ডার হবে
            const planItems = listContainer.querySelectorAll('.custom-plan-item');
            planItems.forEach((item, idx) => {
                const planObj = activeModalProduct.plans[idx];
                if (planObj) {
                    const priceSpan = item.querySelector('.plan-price-tag');
                    if (priceSpan) {
                        priceSpan.innerText = formatDisplayPrice(planObj.price_bdt);
                    }
                }
            });

            if (activeSelectedPlan) {
                setCurrencyAmount('planCurrentPriceText', activeSelectedPlan.price_bdt);
            }
        }
        function proceedToPaymentSelectScreen() {
            if (!isUserLoggedIn) { closeProductPurchaseModal(); openAuthModal('login'); showToast('Please login to buy!', 'error'); return; }
            if (!activeSelectedPlan) { showToast('Please select a valid plan!', 'error'); return; }
            const qty = parseInt(document.getElementById('purchaseQtyInput').value) || 1;
            const totalCostBdt = getModalPayableBdt();
            setCurrencyAmount('step2SummaryCost', totalCostBdt);
            const balStateTag = document.getElementById('balCardStateTag');
            if (userCurrentBalanceBdt >= totalCostBdt) { balStateTag.innerText = 'Instant Deduct'; balStateTag.className = 'text-[9px] text-[#00ff88] font-bold font-mono'; }
            else { balStateTag.innerText = 'Low Balance'; balStateTag.className = 'text-[9px] text-rose-400 font-bold font-mono'; }
            const coinCard = document.getElementById('choiceCardCoins');
            const coinPriceUnit = parseInt(activeSelectedPlan.coin_price || 0);
            const totalCoinsReq = coinPriceUnit * qty;
            if (coinPriceUnit > 0 && userCurrentCoins >= totalCoinsReq) {
                document.getElementById('coinCardCostTag').innerText = `🪙 ${totalCoinsReq.toLocaleString()} Coins`;
                coinCard.classList.remove('hidden');
            } else coinCard.classList.add('hidden');
            hideAllModalStepViews();
            document.getElementById('modalViewPaymentSelect').classList.remove('hidden');
        }
        function goBackToPlanStep() { hideAllModalStepViews(); document.getElementById('modalViewPlanSelect').classList.remove('hidden'); }
        function goBackToMethodSelectScreen() { hideAllModalStepViews(); document.getElementById('modalViewPaymentSelect').classList.remove('hidden'); }
        function goBackToBdtMethodsList() { hideAllModalStepViews(); document.getElementById('modalViewBdtMethodsList').classList.remove('hidden'); }
        function hideAllModalStepViews() {
            ['modalViewPlanSelect','modalViewPaymentSelect','modalViewBdtMethodsList','paySubViewBdtDedicated','paySubViewUsdtDedicated','paySubViewBalanceDedicated','paySubViewCoinsDedicated'].forEach(id => document.getElementById(id)?.classList.add('hidden'));
            ['bdtDedicatedOrderError','usdtDedicatedOrderError','balDedicatedOrderError','coinDedicatedOrderError'].forEach(id => document.getElementById(id)?.classList.add('hidden'));
        }
        function openBdtMethodsListScreen() {
            hideAllModalStepViews();
            const qty = parseInt(document.getElementById('purchaseQtyInput').value) || 1;
            const totalCostBdt = getModalPayableBdt();
            document.getElementById('bdtListTotalCostTag').innerText = `৳${totalCostBdt.toLocaleString()} BDT`;
            document.getElementById('modalViewBdtMethodsList').classList.remove('hidden');
        }
        function openDedicatedBdtMethodScreen(methodId) {
            const methodObj = allActivePaymentMethods.find(m => parseInt(m.id) === parseInt(methodId));
            if (!methodObj) { showToast('Payment method unavailable!', 'error'); return; }
            activeBdtMethodObj = methodObj;
            hideAllModalStepViews();
            const qty = parseInt(document.getElementById('purchaseQtyInput').value) || 1;
            const totalCostBdt = getModalPayableBdt();
            const bannerImgEl = document.getElementById('bdtDedicatedBannerImg');
            bannerImgEl.src = methodObj.banner_url || globalPaymentBannerUrl || 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&q=80';
            document.getElementById('bdtDedicatedTitle').innerText = `${methodObj.name}`;
            document.getElementById('bdtDedicatedPayableAmount').innerText = `৳${totalCostBdt.toLocaleString()} BDT`;
            document.getElementById('bdtDedicatedTypeLabel').innerText = `${methodObj.account_type || 'Personal'} Account Number:`;
            document.getElementById('bdtDedicatedNumberVal').innerText = methodObj.account_number;
            document.getElementById('bdtDedicatedInstructionBox').innerText = methodObj.instructions || `Send Money to the number above, then copy & paste the TrxID below to verify instantly.`;
            document.getElementById('bdtTrxIdInputOnly').value = '';
            document.getElementById('paySubViewBdtDedicated').classList.remove('hidden');
        }
        async function submitDedicatedBdtVerification() {
            if (!activeBdtMethodObj || !activeSelectedPlan) { showToast('Please select plan and payment method!', 'error'); return; }
            const trxId = document.getElementById('bdtTrxIdInputOnly').value.trim();
            const errBox = document.getElementById('bdtDedicatedOrderError');
            errBox.classList.add('hidden');
            if (!trxId || trxId.length < 4) { errBox.innerText = 'Please enter a valid TrxID!'; errBox.classList.remove('hidden'); showToast('TrxID is required!', 'error'); return; }
            const btn = document.getElementById('btnVerifyBdtDedicated');
            const btnOriginalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Verifying...';
            const qty = document.getElementById('purchaseQtyInput').value;
            const formData = new FormData();
            formData.append('ajax_action', 'verify_and_deliver');
            if (getModalCouponCode()) formData.append('coupon_code', getModalCouponCode());
            formData.append('plan_id', activeSelectedPlan.plan_id);
            formData.append('quantity', qty);
            formData.append('method_id', activeBdtMethodObj.id);
            formData.append('method_key', activeBdtMethodObj.method_key || activeBdtMethodObj.name.toLowerCase());
            formData.append('trx_id', trxId);
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 25000);
            try {
                const res = await fetch('index.php', { method: 'POST', body: formData, signal: controller.signal });
                clearTimeout(timeoutId);
                const data = await res.json();
                if (data.status === 'out_of_stock_refunded') {
                    updateLiveBalance(data.remaining_balance);
                    closeProductPurchaseModal();
                    showPaymentResultPopup('success', { amount: data.amount || 0, bonus: 0, message: data.message });
                    return;
                }
                if (data.status === 'success') renderSuccessModalWithKeys(data);
                else if (data.status === 'pending_submitted') { closeProductPurchaseModal(); showPaymentResultPopup('pending', data); }
                else {
                    if (data.require_login) { closeProductPurchaseModal(); openAuthModal('login'); }
                    showPaymentResultPopup('error', data);
                    errBox.innerText = data.message || 'Payment failed!';
                    errBox.classList.remove('hidden');
                }
            } catch(err) {
                clearTimeout(timeoutId);
                showPaymentResultPopup('error', { message: 'Connection error!' });
            } finally {
                btn.disabled = false; btn.innerHTML = btnOriginalText;
            }
        }
        function openDedicatedUsdtScreen() {
            hideAllModalStepViews();
            const qty = parseInt(document.getElementById('purchaseQtyInput').value) || 1;
            const totalCostBdt = getModalPayableBdt();
            const usdtEquivalent = totalCostBdt > 0 ? (totalCostBdt / currentMarketRate).toFixed(2) : '0.00';
            document.getElementById('usdtDedicatedPayableTag').innerText = `$${usdtEquivalent} USDT`;
            document.getElementById('usdtTrxIdInputOnly').value = '';
            document.getElementById('paySubViewUsdtDedicated').classList.remove('hidden');
        }
        async function submitDedicatedUsdtOrder() {
            const trxId = document.getElementById('usdtTrxIdInputOnly').value.trim();
            const errBox = document.getElementById('usdtDedicatedOrderError');
            errBox.classList.add('hidden');
            if (!trxId) { errBox.innerText = 'Please enter Binance Order ID!'; errBox.classList.remove('hidden'); return; }
            const btn = document.getElementById('btnConfirmUsdtDedicated');
            const btnOriginalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Processing...';
            const qty = document.getElementById('purchaseQtyInput').value;
            const totalCostBdt = getModalPayableBdt();
            const formData = new FormData();
            formData.append('ajax_action', 'live_generate');
            formData.append('plan_id', activeSelectedPlan.plan_id);
            formData.append('quantity', qty);
            formData.append('payment_type', 'direct_deposit');
            if (getModalCouponCode()) formData.append('coupon_code', getModalCouponCode());
            formData.append('direct_method', 'Binance');
            formData.append('direct_trx_id', trxId);
            formData.append('direct_amount', totalCostBdt);
            try {
                const res = await fetch('index.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.status === 'out_of_stock_refunded') {
                    updateLiveBalance(data.remaining_balance);
                    closeProductPurchaseModal();
                    showPaymentResultPopup('success', { amount: totalCostBdt, bonus: 0, message: data.message });
                    return;
                }
                if (data.status === 'success') renderSuccessModalWithKeys(data);
                else { errBox.innerText = data.message || 'Failed!'; errBox.classList.remove('hidden'); showToast(data.message || 'Failed!', 'error'); }
            } catch(e) {
                errBox.innerText = 'Network error!'; errBox.classList.remove('hidden');
            } finally {
                btn.disabled = false; btn.innerHTML = btnOriginalText;
            }
        }
        function openDedicatedBalanceScreen() {
            hideAllModalStepViews();
            const qty = parseInt(document.getElementById('purchaseQtyInput').value) || 1;
            const totalCostBdt = getModalPayableBdt();
            setCurrencyAmount('balDedicatedCostTag', totalCostBdt);
            setCurrencyAmount('balDeductCostText', totalCostBdt, { prefix: '-' });
            const remaining = userCurrentBalanceBdt - totalCostBdt;
            const remEl = document.getElementById('balRemainingAfterText');
            const lowNotice = document.getElementById('balLowNoticeBoxDedicated');
            const confirmBtn = document.getElementById('btnConfirmBalDedicated');
            if (remaining >= 0) {
                setCurrencyAmount(remEl, remaining);
                remEl.className = 'text-[#00ff88] dynamic-price-el';
                lowNotice.classList.add('hidden');
                confirmBtn.disabled = false;
                confirmBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            } else {
                setCurrencyAmount(remEl, Math.abs(remaining), { prefix: 'Short by ' });
                remEl.className = 'text-rose-400 dynamic-price-el';
                lowNotice.classList.remove('hidden');
                confirmBtn.disabled = true;
                confirmBtn.classList.add('opacity-50', 'cursor-not-allowed');
            }
            document.getElementById('paySubViewBalanceDedicated').classList.remove('hidden');
        }
        async function submitDedicatedBalanceOrder() {
            const btn = document.getElementById('btnConfirmBalDedicated');
            const errBox = document.getElementById('balDedicatedOrderError');
            errBox.classList.add('hidden');
            const btnOriginalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Generating...';
            const qty = document.getElementById('purchaseQtyInput').value;
            const formData = new FormData();
            formData.append('ajax_action', 'live_generate');
            formData.append('plan_id', activeSelectedPlan.plan_id);
            formData.append('quantity', qty);
            formData.append('payment_type', 'balance');
            if (getModalCouponCode()) formData.append('coupon_code', getModalCouponCode());
            try {
                const res = await fetch('index.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.status === 'success') renderSuccessModalWithKeys(data);
                else { errBox.innerText = data.message || 'Checkout failed!'; errBox.classList.remove('hidden'); showToast(data.message || 'Failed!', 'error'); }
            } catch(e) {
                errBox.innerText = 'Network timeout!'; errBox.classList.remove('hidden');
            } finally {
                btn.disabled = false; btn.innerHTML = btnOriginalText;
            }
        }
        function openDedicatedCoinsScreen() {
            hideAllModalStepViews();
            const qty = parseInt(document.getElementById('purchaseQtyInput').value) || 1;
            const totalCoinsReq = (parseInt(activeSelectedPlan.coin_price || 0) * qty);
            document.getElementById('coinDedicatedCostTag').innerText = `🪙 ${totalCoinsReq.toLocaleString()}`;
            document.getElementById('coinDeductCostText').innerText = `-🪙 ${totalCoinsReq.toLocaleString()}`;
            const remCoins = userCurrentCoins - totalCoinsReq;
            document.getElementById('coinRemainingAfterText').innerText = `🪙 ${Math.max(0, remCoins).toLocaleString()}`;
            document.getElementById('paySubViewCoinsDedicated').classList.remove('hidden');
        }
        async function submitDedicatedCoinsOrder() {
            const btn = document.getElementById('btnConfirmCoinsDedicated');
            const errBox = document.getElementById('coinDedicatedOrderError');
            errBox.classList.add('hidden');
            const btnOriginalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Redeeming...';
            const qty = document.getElementById('purchaseQtyInput').value;
            const formData = new FormData();
            formData.append('ajax_action', 'live_generate');
            formData.append('plan_id', activeSelectedPlan.plan_id);
            formData.append('quantity', qty);
            formData.append('payment_type', 'coins');
            try {
                const res = await fetch('index.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.status === 'success') renderSuccessModalWithKeys(data);
                else { errBox.innerText = data.message || 'Failed!'; errBox.classList.remove('hidden'); showToast(data.message || 'Failed!', 'error'); }
            } catch(e) {
                errBox.innerText = 'Network error!'; errBox.classList.remove('hidden');
            } finally {
                btn.disabled = false; btn.innerHTML = btnOriginalText;
            }
        }
        function renderSuccessModalWithKeys(data) {
            updateLiveBalance(data.remaining_balance);
            if (document.getElementById('liveHeaderCoins')) document.getElementById('liveHeaderCoins').innerText = data.remaining_coins;
            document.getElementById('modalProductTitle').innerText = data.product_name;
            const coinNotifyBox = document.getElementById('modalRewardCoinNotify');
            if (data.reward_coins && data.reward_coins > 0) {
                coinNotifyBox.innerText = `🎁 Congratulations! You earned +${data.reward_coins} Reward Coins!`;
                coinNotifyBox.classList.remove('hidden');
            } else coinNotifyBox.classList.add('hidden');
            const spinNotifyBox = document.getElementById('modalSpinEarnedNotify');
            if (spinNotifyBox) {
                if (data.spins_earned && data.spins_earned > 0) {
                    spinNotifyBox.innerText = `🎰 You earned ${data.spins_earned} FREE SPIN(S)!`;
                    spinNotifyBox.classList.remove('hidden');
                } else spinNotifyBox.classList.add('hidden');
            }
            const container = document.getElementById('modalKeysContainer');
            container.innerHTML = '';
            latestKeys = data.keys || [];
            data.keys.forEach((k) => {
                const card = document.createElement('div');
                card.className = "bg-[#060111] p-3.5 rounded-2xl border-2 border-emerald-500/60 shadow-[0_0_15px_rgba(0,255,136,0.15)] flex flex-col space-y-2.5";
                card.innerHTML = `
                    <div class="text-[9px] font-bold text-gray-400 uppercase tracking-widest flex items-center justify-between">
                        <span><i class="fa-solid fa-key text-emerald-400 mr-1"></i> LICENSE KEY</span>
                        <span class="text-emerald-400 font-mono">100% ACTIVE</span>
                    </div>
                    <div class="bg-[#090317] p-2.5 rounded-xl border border-[#211242] text-center">
                        <span class="text-sm sm:text-base font-black text-[#00ff88] font-mono tracking-wider select-all break-all">${k}</span>
                    </div>
                    <button type="button" onclick="copySuccessKey('${k}', this)" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-gray-950 font-black text-xs uppercase tracking-wider flex items-center justify-center gap-2 shadow-md hover:opacity-95 active:scale-98 transition font-mono">
                        <i class="fa-regular fa-copy text-sm"></i> <span>COPY LICENSE KEY</span>
                    </button>
                `;
                container.appendChild(card);
            });
            closeProductPurchaseModal();
            document.getElementById('keySuccessModal').classList.remove('hidden');
            launchBajiFireworks();
            showToast(data.message, 'success');
        }
        function copySuccessKey(keyText, btnEl) {
            navigator.clipboard.writeText(keyText);
            const origHtml = btnEl.innerHTML;
            btnEl.innerHTML = `<i class="fa-solid fa-check"></i> <span>COPIED!</span>`;
            btnEl.classList.remove('from-emerald-500', 'to-teal-500');
            btnEl.classList.add('bg-white', 'text-black');
            showToast('License Key copied!', 'success');
            setTimeout(() => {
                btnEl.innerHTML = origHtml;
                btnEl.classList.remove('bg-white', 'text-black');
                btnEl.classList.add('from-emerald-500', 'to-teal-500');
            }, 2500);
        }
        function closeKeySuccessModal() { document.getElementById('keySuccessModal').classList.add('hidden'); }
        function copyProductDirectLink() {
            if (activeModalProduct) {
                const directShareLink = window.location.origin + window.location.pathname + '?prod=' + activeModalProduct.id;
                copyToClipboard(directShareLink, 'Product link');
            }
        }

        // ===================== SPIN WHEEL =====================
        function handleSpinFloatingClick() { openSpinWheelModal(); }
        function openSpinWheelModal() {
            const modal = document.getElementById('spinWheelModal');
            if (!modal) return;
            modal.classList.remove('hidden');
            loadSpinWheelData();
            startBabuUrgencyTimer();
        }
        function closeSpinWheelModal() { document.getElementById('spinWheelModal')?.classList.add('hidden'); }
        function startBabuUrgencyTimer() {
            const timerEl = document.getElementById('babuUrgencyTimer');
            if (!timerEl) return;
            clearInterval(window.babuTimerInterval);
            window.babuTimerInterval = setInterval(() => {
                urgencyTimerSeconds--;
                if (urgencyTimerSeconds < 0) urgencyTimerSeconds = 59;
                const secStr = urgencyTimerSeconds < 10 ? '0' + urgencyTimerSeconds : urgencyTimerSeconds;
                timerEl.innerText = `00:${secStr}`;
            }, 1000);
        }
        async function loadSpinWheelData() {
            const fd = new FormData();
            fd.append('ajax_action', 'get_spin_data');
            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    spinSegments = data.segments || [];
                    document.getElementById('spinRemainingCount').innerText = data.remaining_spins || 0;
                    if (data.today_winners) document.getElementById('liveWinTodayText').innerText = data.today_winners;
                    if (data.active_viewers) document.getElementById('liveViewerCount').innerText = data.active_viewers;
                    
                    const spendTotal = parseFloat(data.spin_spend_total || 0);
                    const threshold = parseFloat(data.spin_threshold || 500);
                    const progress = parseFloat(data.progress_percent || 0);
                    document.getElementById('spinSpendTotal').innerText = spendTotal.toLocaleString();
                    document.getElementById('spinSpendThreshold').innerText = threshold.toLocaleString();
                    document.getElementById('spinProgressBar').style.width = progress + '%';
                    const hint = document.getElementById('spinProgressHint');
                    if (progress >= 100) hint.innerText = `🎉 You have ${data.remaining_spins} spin(s) available!`;
                    else hint.innerText = `Spend ৳${(threshold - (spendTotal % threshold)).toFixed(0)} more to unlock next spin`;
                    
                    if (data.leaderboard && data.leaderboard.length > 0) {
                        const lbList = document.getElementById('spinLeaderboardList');
                        const icons = ['👑', '🥈', '🥉'];
                        lbList.innerHTML = '';
                        data.leaderboard.forEach((lb, i) => {
                            const spent = parseFloat(lb.spent_bdt || 0).toLocaleString();
                            lbList.innerHTML += `<div class="flex justify-between"><span>${icons[i]} ${(lb.username || '').substring(0, 5)}***</span><span class="text-yellow-400">৳${spent}</span></div>`;
                        });
                    }
                    drawBabu88CasinoWheel(spinSegments);
                }
            } catch(e) {}
        }
        function drawBabu88CasinoWheel(segments) {
            const canvas = document.getElementById('wheelCanvas');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            const totalSegs = segments.length;
            if (totalSegs === 0) return;
            const radius = canvas.width / 2;
            const angleStep = (2 * Math.PI) / totalSegs;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            const casinoColors = ['#f59e0b', '#10b981', '#1d4ed8', '#1e293b', '#059669', '#2563eb', '#10b981', '#334155'];
            segments.forEach((seg, i) => {
                const startAngle = i * angleStep;
                const endAngle = startAngle + angleStep;
                const segColor = seg.color || casinoColors[i % casinoColors.length];
                ctx.beginPath();
                ctx.moveTo(radius, radius);
                ctx.arc(radius, radius, radius - 4, startAngle, endAngle);
                ctx.fillStyle = segColor;
                ctx.fill();
                ctx.lineWidth = 1.5;
                ctx.strokeStyle = '#281a02';
                ctx.stroke();
                ctx.save();
                ctx.translate(radius, radius);
                ctx.rotate(startAngle + angleStep / 2);
                ctx.textAlign = 'right';
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 12px "Plus Jakarta Sans", sans-serif';
                ctx.shadowColor = 'rgba(0,0,0,0.8)';
                ctx.shadowBlur = 4;
                ctx.fillText(seg.title, radius - 16, 4);
                ctx.restore();
            });
            const numBulbs = 24;
            const bulbRadius = radius - 2;
            for (let b = 0; b < numBulbs; b++) {
                const bAngle = (b * (2 * Math.PI)) / numBulbs;
                const bx = radius + bulbRadius * Math.cos(bAngle);
                const by = radius + bulbRadius * Math.sin(bAngle);
                ctx.beginPath();
                ctx.arc(bx, by, 2.5, 0, 2 * Math.PI);
                ctx.fillStyle = (b % 2 === 0) ? '#fef08a' : '#ffffff';
                ctx.shadowColor = '#f59e0b';
                ctx.shadowBlur = 6;
                ctx.fill();
            }
        }
        async function triggerWheelSpin() {
            if (isWheelSpinning) return;
            if (!isUserLoggedIn) { closeSpinWheelModal(); openAuthModal('login'); showToast('Please login to spin!', 'error'); return; }
            const fd = new FormData();
            fd.append('ajax_action', 'spin_wheel');
            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    isWheelSpinning = true;
                    const totalSegs = spinSegments.length;
                    const segAngle = 360 / totalSegs;
                    const wonIdx = data.winning_index;
                    const extraSpins = 360 * 6;
                    const targetAngle = extraSpins + (360 - (wonIdx * segAngle) - (segAngle / 2)) - 90;
                    currentWheelAngle = targetAngle;
                    const canvas = document.getElementById('wheelCanvas');
                    canvas.style.transition = 'transform 5.2s cubic-bezier(0.12, 0.8, 0.15, 1)';
                    canvas.style.transform = `rotate(${currentWheelAngle}deg)`;
                    setTimeout(() => {
                        isWheelSpinning = false;
                        if (data.is_win) {
                            launchBajiFireworks();
                            document.getElementById('spinWinIconBox').innerHTML = '<i class="fa-solid fa-gift"></i>';
                            document.getElementById('spinWinBadgeText').innerText = '🎉 Congratulations!';
                            document.getElementById('spinWinHeading').innerText = 'You Won Reward!';
                            document.getElementById('spinWinResultText').innerText = data.title;
                            document.getElementById('spinWinBengaliNotice').innerText = data.bengali_offer_text || '';
                            document.getElementById('spinWinDescText').innerText = 'Your lucky coupon reward is ready!';
                        } else {
                            document.getElementById('spinWinIconBox').innerHTML = '<i class="fa-solid fa-face-smile"></i>';
                            document.getElementById('spinWinBadgeText').innerText = 'Better Luck Next Time';
                            document.getElementById('spinWinHeading').innerText = 'Almost Got It!';
                            document.getElementById('spinWinResultText').innerText = 'Try Again';
                            document.getElementById('spinWinBengaliNotice').innerText = 'মন খারাপ করবেন না! আবার চেষ্টা করুন!';
                            document.getElementById('spinWinDescText').innerText = 'Come back tomorrow and spin again!';
                        }
                        closeSpinWheelModal();
                        document.getElementById('spinWinModal').classList.remove('hidden');
                        loadSpinWheelData();
                    }, 5300);
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Spin failed! Try again.', 'error');
            }
        }
        function closeSpinWinModal() { document.getElementById('spinWinModal')?.classList.add('hidden'); }
        function launchBajiFireworks() {
            if (typeof confetti !== 'function') return;
            var duration = 3.5 * 1000;
            var animationEnd = Date.now() + duration;
            var defaults = { startVelocity: 35, spread: 360, ticks: 70, zIndex: 999999 };
            function randomInRange(min, max) { return Math.random() * (max - min) + min; }
            var interval = setInterval(function() {
                var timeLeft = animationEnd - Date.now();
                if (timeLeft <= 0) return clearInterval(interval);
                var particleCount = 60 * (timeLeft / duration);
                confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 } }));
                confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 } }));
                confetti(Object.assign({}, defaults, { particleCount, origin: { x: 0.5, y: 0.5 } }));
            }, 250);
        }


        // ===================== 🔄 RESET: PRODUCT GRID (FF PANEL STYLE) =====================
        function escapeHtmlRs(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
        function selectResetProduct(prodId) {
            if (!isUserLoggedIn) { openAuthModal('login'); showToast('Please login to reset your key!', 'error'); return; }
            document.querySelectorAll('.reset-prod-card').forEach(c => {
                const on = parseInt(c.dataset.resetProd) === prodId;
                c.classList.toggle('border-cyan-400', on);
                c.classList.toggle('shadow-[0_0_12px_rgba(0,245,255,0.35)]', on);
            });
            const card = document.querySelector('.reset-prod-card[data-reset-prod="' + prodId + '"]');
            const prodName = card ? card.dataset.name : 'Product';
            const box = document.getElementById('resetProdKeysBox');
            const keys = allUserResetKeys.filter(k => parseInt(k.product_id || 0) === prodId);
            let html = `<div class="flex items-center justify-between text-[10px] font-bold"><span class="text-cyan-300 uppercase">${escapeHtmlRs(prodName)} — Your Keys</span><button type="button" onclick="document.getElementById('resetProdKeysBox').classList.add('hidden')" class="text-gray-500 hover:text-white">✕</button></div>`;
            if (!keys.length) {
                html += `<p class="text-[10px] text-gray-400 font-sans py-2 text-center">এই প্রোডাক্টের কোনো কি আপনার অর্ডারে নেই। নিচে ম্যানুয়ালি কি পেস্ট করেও রিসেট দিতে পারেন।</p>`;
            } else {
                keys.forEach((k, i) => {
                    html += `<div class="flex items-center justify-between gap-2 p-2 rounded-xl bg-[#0d061c] border border-[#241040]">
                        <div class="min-w-0"><span class="block text-[11px] text-white font-mono font-bold truncate">${escapeHtmlRs(k.license_key)}</span><span class="text-[9px] text-gray-400">${escapeHtmlRs(k.validity || 'Key')}</span></div>
                        <button type="button" data-key-idx="${i}" class="reset-one-btn flex-shrink-0 px-3 py-1.5 rounded-lg bg-gradient-to-r from-cyan-500 to-blue-600 text-gray-950 text-[10px] font-black uppercase active:scale-95">Reset</button>
                    </div>`;
                });
            }
            box.innerHTML = html;
            box.classList.remove('hidden');
            box.querySelectorAll('.reset-one-btn').forEach(b => b.addEventListener('click', () => {
                const k = keys[parseInt(b.dataset.keyIdx)];
                if (!k) return;
                document.getElementById('resetKeySearchInput').value = k.license_key;
                document.getElementById('liveResetForm').requestSubmit();
            }));
            box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        // ===================== KEY RESET =====================
        function onResetSearchInput(query) {
            const resultsBox = document.getElementById('resetSearchResultsBox');
            const q = query.trim().toUpperCase();
            resultsBox.innerHTML = '';
            const matches = allUserResetKeys.filter(item => {
                const k = (item.license_key || '').toUpperCase();
                const v = (item.validity || '').toUpperCase();
                return k.includes(q) || v.includes(q);
            });
            if (matches.length === 0) {
                resultsBox.innerHTML = '<div class="p-2 text-center text-gray-500 text-[10px]">No key found</div>';
                resultsBox.classList.remove('hidden');
                return;
            }
            matches.slice(0, 15).forEach(item => {
                const row = document.createElement('div');
                row.className = "p-1.5 rounded-lg hover:bg-[#140826] cursor-pointer transition flex flex-col border border-transparent hover:border-cyan-500/30";
                row.innerHTML = `<span class="font-mono font-bold text-cyan-300 text-[11px]">${item.license_key}</span><span class="text-[8px] text-gray-400 mt-0.5">${item.validity || 'Active'}</span>`;
                row.onclick = () => { document.getElementById('resetKeySearchInput').value = item.license_key; resultsBox.classList.add('hidden'); };
                resultsBox.appendChild(row);
            });
            resultsBox.classList.remove('hidden');
        }
        async function handleLiveKeyReset(e) {
            e.preventDefault();
            const targetKey = document.getElementById('resetKeySearchInput').value.trim();
            if (!targetKey) { showToast('Please enter a license key!', 'error'); return; }
            const btn = document.getElementById('resetSubmitBtn');
            btn.disabled = true;
            const formData = new FormData();
            formData.append('ajax_action', 'reset_key');
            formData.append('license_key', targetKey);
            try {
                const res = await fetch('index.php', { method: 'POST', body: formData });
                const data = await res.json();
                const resultBox = document.getElementById('resetResultBox');
                resultBox.classList.remove('hidden');
                if (data.status === 'success') {
                    resultBox.className = "p-2.5 rounded-lg border border-emerald-500/40 bg-emerald-950/20 text-emerald-300 text-[11px] leading-relaxed font-sans";
                    resultBox.innerHTML = `<b>Success:</b> ${data.message}`;
                    showToast(data.message, 'success');
                } else {
                    resultBox.className = "p-2.5 rounded-lg border border-pink-500/40 bg-pink-950/20 text-pink-300 text-[11px] leading-relaxed font-sans";
                    resultBox.innerHTML = `<b>Notice:</b> ${data.message}`;
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Reset timeout!', 'error');
            } finally {
                btn.disabled = false;
            }
        }

        // ===================== ORDER KEY SEARCH =====================
        function searchUserKey(query) {
            const resultBox = document.getElementById('orderKeySearchResult');
            const q = query.trim().toUpperCase();
            if (q.length < 3) { resultBox.classList.add('hidden'); return; }
            const found = allUserResetKeys.find(k => (k.license_key || '').toUpperCase().includes(q));
            resultBox.classList.remove('hidden');
            if (found) {
                resultBox.className = "p-3 rounded-xl bg-emerald-950/40 border border-emerald-500/40 text-emerald-300 text-xs";
                resultBox.innerHTML = `
                    <p class="font-bold mb-1">✅ Key Found in Your Account</p>
                    <p class="font-mono text-[10px] break-all">${found.license_key}</p>
                    <p class="text-[9px] mt-1 text-gray-400">Validity: ${found.validity || 'Active'}</p>
                `;
            } else {
                resultBox.className = "p-3 rounded-xl bg-rose-950/40 border border-rose-500/40 text-rose-300 text-xs";
                resultBox.innerHTML = `<p>❌ This key was NOT purchased from your account.</p>`;
            }
        }

        // ===================== CHAT & AI ASSISTANT =====================
        function toggleChatDialog() { document.getElementById('chatDialogBox')?.classList.toggle('hidden'); }
        function switchChatTab(tab) {
            const btnAi = document.getElementById('chatTabBtnAi');
            const btnTg = document.getElementById('chatTabBtnTg');
            const secAi = document.getElementById('chatSectionAi');
            const secTg = document.getElementById('chatSectionTg');
            if (tab === 'ai') {
                btnAi.className = 'flex-1 py-1 rounded-lg bg-cyan-500 text-gray-950 font-bold transition';
                btnTg.className = 'flex-1 py-1 rounded-lg text-gray-400 hover:text-white transition';
                secAi.classList.remove('hidden'); secTg.classList.add('hidden');
            } else {
                btnTg.className = 'flex-1 py-1 rounded-lg bg-cyan-500 text-gray-950 font-bold transition';
                btnAi.className = 'flex-1 py-1 rounded-lg text-gray-400 hover:text-white transition';
                secTg.classList.remove('hidden'); secAi.classList.add('hidden');
            }
        }
        function handleAiMessageSubmit(e) {
            e.preventDefault();
            const input = document.getElementById('aiUserInput');
            const val = input.value.trim();
            if (!val) return;
            askAiQuestion(val);
            input.value = '';
        }
        function askAiQuestion(query) {
            const log = document.getElementById('chatMessageLog');
            const userMsg = document.createElement('div');
            userMsg.className = "bg-gradient-to-r from-cyan-500 to-blue-600 text-gray-950 p-1.5 rounded-lg text-right font-bold text-[9px] ml-5 shadow font-sans";
            userMsg.innerText = query;
            log.appendChild(userMsg);
            setTimeout(() => {
                const aiMsg = document.createElement('div');
                aiMsg.className = "bg-[#160b2e] border border-cyan-500/30 p-1.5 rounded-lg text-gray-200 leading-relaxed text-[9px] mr-3 space-y-1 font-sans";
                const q = query.toLowerCase();
                if (q.includes('hi') || q.includes('hello') || q.includes('hey')) {
                    aiMsg.innerHTML = `👋 <b>Hello!</b> Ask me about <b>balance</b>, <b>coins</b>, <b>deposit</b>, <b>reset key</b>!`;
                } else if (q.includes('balance') || q.includes('wallet') || q.includes('fund') || q.includes('coin')) {
                    if (!isUserLoggedIn) {
                        aiMsg.innerHTML = `🔒 <button onclick="openAuthModal('login'); toggleChatDialog();" class="text-cyan-400 font-bold underline">Login</button> to check balance!`;
                    } else {
                        aiMsg.innerHTML = `💰 <b>Balances:</b><br>• <b>${formatDisplayPrice(userCurrentBalanceBdt)}</b><br>• <b>🪙 ${userCurrentCoins.toLocaleString()} Coins</b>`;
                    }
                } else if (q.includes('deposit') || q.includes('load') || q.includes('bkash')) {
                    if (!isUserLoggedIn) aiMsg.innerHTML = `🔒 <button onclick="openAuthModal('login'); toggleChatDialog();" class="text-cyan-400 font-bold underline">Login</button> to deposit!`;
                    else if (isDepositSystemOnline) aiMsg.innerHTML = `💳 Tap <b>Add Fund</b> below, choose bKash/Nagad/Binance!`;
                    else aiMsg.innerHTML = `⚠️ Deposit gateway paused.`;
                } else if (q.includes('reset') || q.includes('hwid')) {
                    if (!isUserLoggedIn) aiMsg.innerHTML = `🔒 <button onclick="openAuthModal('login'); toggleChatDialog();" class="text-cyan-400 font-bold underline">Login</button> to reset!`;
                    else aiMsg.innerHTML = `🔄 <button onclick="handleNavClick('resetkey'); toggleChatDialog();" class="text-cyan-400 font-bold underline">Reset Key</button>`;
                } else {
                    let matches = [];
                    for (let id in allProductsCatalog) {
                        const p = allProductsCatalog[id];
                        if (p.name.toLowerCase().includes(q) || q.includes(p.name.toLowerCase())) matches.push(p);
                    }
                    if (matches.length > 0) {
                        let html = `🎯 <b>Found ${matches.length}:</b><div class="space-y-0.5 mt-0.5">`;
                        matches.slice(0, 2).forEach(p => {
                            const minP_BDT = p.plans.length > 0 ? p.plans[0].price_bdt : 0;
                            html += `<div class="p-1 bg-[#090314] rounded border border-[#261345] flex justify-between items-center"><div><strong class="text-white text-[9px] block truncate">${p.name}</strong><span class="text-[8px] text-emerald-400">From ${formatDisplayShort(minP_BDT)}</span></div><button onclick="openProductModal(${p.id}); toggleChatDialog();" class="px-1.5 py-0.2 rounded btn-buy-compact text-white text-[8px] font-bold">Buy</button></div>`;
                        });
                        html += `</div>`;
                        aiMsg.innerHTML = html;
                    } else {
                        aiMsg.innerHTML = `🤖 No match for "<i>${query}</i>". Try Telegram support!`;
                    }
                }
                log.appendChild(aiMsg);
                log.scrollTop = log.scrollHeight;
            }, 200);
        }

        // ===================== UTILS =====================
        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toastNotification');
            const toastInner = document.getElementById('toastInner');
            document.getElementById('toastMsg').innerText = msg;
            toastInner.className = (type === 'error')
                ? "bg-[#140826] border border-pink-500/60 shadow-2xl rounded-2xl p-3 flex items-center space-x-2.5 text-xs font-semibold text-pink-200 backdrop-blur-md font-mono"
                : "bg-[#140826] border border-fuchsia-500/50 shadow-2xl rounded-2xl p-3 flex items-center space-x-2.5 text-xs font-semibold text-fuchsia-200 backdrop-blur-md font-mono";
            toast.classList.remove('opacity-0', 'translate-y-[-20px]');
            toast.classList.add('opacity-100', 'translate-y-0');
            setTimeout(() => {
                toast.classList.remove('opacity-100', 'translate-y-0');
                toast.classList.add('opacity-0', 'translate-y-[-20px]');
            }, 2500);
        }
        function copyToClipboard(text, label = 'Copied') {
            navigator.clipboard.writeText(text);
            showToast(`${label} copied!`, 'success');
        }

        // =====================================================================
        // --- MULTI-CURRENCY GLOBAL ENGINE (BDT | USDT | INR) — SINGLE SOURCE ---
        // সব দাম/ব্যালেন্স BDT তে রাখা থাকে (data-bdt), আর সিলেক্ট করা কারেন্সিতে দেখানো হয়।
        // Rate: 1 USDT = currentBdtRate BDT, 1 USDT = currentInrRate INR (Mother / Sub admin থেকে সেট)
        // =====================================================================
        const CURRENCY_META = {
            BDT:  { flag: '🇧🇩', symbol: '৳', code: 'BDT',  decimals: 0 },
            USDT: { flag: '💲',  symbol: '$', code: 'USDT', decimals: 2 },
            INR:  { flag: '🇮🇳', symbol: '₹', code: 'INR',  decimals: 0 }
        };
        function normalizeCurrency(curr) {
            curr = String(curr || 'BDT').toUpperCase();
            if (curr === 'USD') curr = 'USDT';
            return CURRENCY_META[curr] ? curr : 'BDT';
        }
        globalSelectedCurrency = normalizeCurrency(globalSelectedCurrency);

        function convertFromBdt(bdtAmount, curr) {
            bdtAmount = parseFloat(bdtAmount) || 0;
            curr = curr || globalSelectedCurrency;
            const bdtRate = currentBdtRate > 0 ? currentBdtRate : 1;
            if (curr === 'USDT') return bdtAmount / bdtRate;
            if (curr === 'INR')  return (bdtAmount / bdtRate) * currentInrRate;
            return bdtAmount;
        }
        // শুধু সংখ্যা (চিহ্ন ছাড়া) — যেমন 1,250 / 10.00
        function formatDisplayNumber(bdtAmount) {
            const meta = CURRENCY_META[globalSelectedCurrency];
            const val = convertFromBdt(bdtAmount);
            return val.toLocaleString('en-US', { minimumFractionDigits: meta.decimals, maximumFractionDigits: meta.decimals });
        }
        // চিহ্ন + সংখ্যা — যেমন ৳1,250 / $10.00 / ₹880
        function formatDisplayShort(bdtAmount) {
            return CURRENCY_META[globalSelectedCurrency].symbol + formatDisplayNumber(bdtAmount);
        }
        // পুরো ফরম্যাট — যেমন ৳1,250 BDT / $10.00 USDT / ₹880 INR
        function formatDisplayPrice(bdtAmount) {
            return formatDisplayShort(bdtAmount) + ' ' + CURRENCY_META[globalSelectedCurrency].code;
        }
        // কোনো এলিমেন্টে BDT অ্যামাউন্ট বসানো (পরে কারেন্সি বদলালে অটো আপডেট হবে)
        function setCurrencyAmount(elOrId, bdtAmount, opts) {
            const el = (typeof elOrId === 'string') ? document.getElementById(elOrId) : elOrId;
            if (!el) return;
            opts = opts || {};
            el.classList.add('dynamic-price-el');
            el.setAttribute('data-bdt', parseFloat(bdtAmount) || 0);
            if (opts.short) el.setAttribute('data-cur', 'short'); else el.removeAttribute('data-cur');
            if (opts.prefix) el.setAttribute('data-prefix', opts.prefix); else el.removeAttribute('data-prefix');
            renderCurrencyElement(el);
        }
        function renderCurrencyElement(el) {
            const bdtVal = parseFloat(el.getAttribute('data-bdt')) || 0;
            const prefix = el.getAttribute('data-prefix') || '';
            const mode = el.getAttribute('data-cur');
            const text = (mode === 'short') ? formatDisplayShort(bdtVal)
                       : (mode === 'number') ? formatDisplayNumber(bdtVal)
                       : formatDisplayPrice(bdtVal);
            el.textContent = prefix + text;
        }
        function refreshAllCurrencyDisplays() {
            document.querySelectorAll('.dynamic-price-el, .profile-wallet-balance-el, .plan-price-tag').forEach(renderCurrencyElement);
            const meta = CURRENCY_META[globalSelectedCurrency];
            const flagEl = document.getElementById('currFlagIcon');
            const codeEl = document.getElementById('currCodeLabel');
            if (flagEl) flagEl.textContent = meta.flag;
            if (codeEl) codeEl.textContent = meta.code;
            const balWrap = document.getElementById('headerBalanceChip');
            if (balWrap) balWrap.title = 'Your ' + meta.code + ' Balance';
            document.querySelectorAll('.currency-opt-btn').forEach(btn => {
                btn.classList.toggle('bg-cyan-500/20', btn.getAttribute('data-curr') === globalSelectedCurrency);
                btn.classList.toggle('text-white', btn.getAttribute('data-curr') === globalSelectedCurrency);
            });
            if (typeof updateStep1TotalCost === 'function') updateStep1TotalCost();
        }
        function setGlobalCurrency(curr, silent) {
            globalSelectedCurrency = normalizeCurrency(curr);
            try { localStorage.setItem('site_currency', globalSelectedCurrency); } catch (e) {}
            refreshAllCurrencyDisplays();
            if (!silent) showToast('Currency switched to ' + globalSelectedCurrency, 'success');
        }
        // সার্ভার থেকে আসা নতুন ব্যালেন্স ("1,250" BDT) — হেডার, প্রোফাইল, মোডাল সব জায়গায় আপডেট
        function updateLiveBalance(remainingBdt) {
            if (remainingBdt === undefined || remainingBdt === null || remainingBdt === '') return;
            const val = parseFloat(String(remainingBdt).replace(/[^0-9.\-]/g, ''));
            if (isNaN(val)) return;
            userCurrentBalanceBdt = val;
            document.querySelectorAll('.live-user-balance-el, #liveHeaderBalance, .profile-wallet-balance-el').forEach(el => {
                el.setAttribute('data-bdt', val);
                renderCurrencyElement(el);
            });
        }

        // --- DROPDOWN TOGGLE ---
        function toggleCurrencyDropdown(e) {
            e.stopPropagation();
            const menu = document.getElementById('currencyDropdownMenu');
            if (menu) menu.classList.toggle('hidden');
        }
        // বাইরে ক্লিক করলে ড্রপডাউন বন্ধ হয়ে যাবে
        window.addEventListener('click', () => {
            document.getElementById('currencyDropdownMenu')?.classList.add('hidden');
        });
        function selectCurrencyOption(currCode) {
            document.getElementById('currencyDropdownMenu')?.classList.add('hidden');
            setGlobalCurrency(currCode);
        }

        // --- COUPON APPLY LOGIC ---
        // ===================== 🎟️ COUPON (MODAL) =====================
        function modalCouponKey() {
            const qty = parseInt(document.getElementById('purchaseQtyInput')?.value) || 1;
            return (activeSelectedPlan ? activeSelectedPlan.plan_id : 0) + 'x' + qty;
        }
        // বর্তমান প্ল্যান × পরিমাণ এর জন্য আসল পরিশোধযোগ্য অ্যামাউন্ট (কুপন থাকলে কুপন বাদ দিয়ে)
        function getModalPayableBdt() {
            if (!activeSelectedPlan) return 0;
            const qty = parseInt(document.getElementById('purchaseQtyInput')?.value) || 1;
            const base = activeSelectedPlan.price_bdt * qty;
            if (appliedModalCoupon && appliedModalCoupon._key === modalCouponKey() && appliedModalCoupon.final_price_bdt) {
                return parseFloat(appliedModalCoupon.final_price_bdt) || base;
            }
            return base;
        }
        function getModalCouponCode() {
            return (appliedModalCoupon && appliedModalCoupon._key === modalCouponKey()) ? appliedModalCoupon.coupon_code : '';
        }
        function clearModalCoupon(clearInput) {
            appliedModalCoupon = null;
            const notice = document.getElementById('couponStatusNotice');
            if (notice) { notice.classList.add('hidden'); notice.innerText = ''; }
            if (clearInput && document.getElementById('modalCouponInput')) document.getElementById('modalCouponInput').value = '';
        }
        // প্ল্যান/পরিমাণ বদলালে কুপন আবার যাচাই হবে
        function revalidateModalCoupon() {
            if (appliedModalCoupon && appliedModalCoupon._key !== modalCouponKey()) applyModalCoupon(true);
        }
        async function applyModalCoupon(silent) {
            const input = document.getElementById('modalCouponInput');
            const notice = document.getElementById('couponStatusNotice');
            const code = input.value.trim().toUpperCase();
            if (!code) { if (!silent) showToast('Please enter coupon code!', 'error'); return; }
            if (!activeSelectedPlan || !activeModalProduct) return;
            if (!isUserLoggedIn) { if (!silent) { showToast('Please login to use a coupon!', 'error'); openAuthModal('login'); } return; }

            const qty = parseInt(document.getElementById('purchaseQtyInput').value) || 1;
            const originalTotalBdt = (activeSelectedPlan.price_bdt * qty);
            const key = modalCouponKey();

            const fd = new FormData();
            fd.append('ajax_action', 'apply_coupon');
            fd.append('coupon_code', code);
            fd.append('product_id', activeModalProduct.id);
            fd.append('plan_price_bdt', originalTotalBdt);

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const data = await res.json();
                notice.classList.remove('hidden');
                if (data.status === 'success') {
                    data._key = key;
                    appliedModalCoupon = data;
                    notice.className = "text-[10px] font-bold text-emerald-400 block pt-0.5 leading-relaxed";
                    let info = `✅ ${data.coupon_code} (${data.off_label || ''}) — You save ${formatDisplayPrice(parseFloat(data.discount_amount) || 0)}`;
                    const extra = [];
                    if (data.uses_left !== null && data.uses_left !== undefined) extra.push(`${data.uses_left} uses left`);
                    if (data.expires_at) extra.push(`valid till ${data.expires_at}`);
                    if (extra.length) info += `\n⏳ ${extra.join(' • ')}`;
                    notice.innerText = info;
                    if (!silent) showToast('Coupon discount applied!', 'success');
                } else {
                    appliedModalCoupon = null;
                    notice.className = "text-[10px] font-bold text-rose-400 block pt-0.5";
                    notice.innerText = `❌ ${data.message}`;
                    if (!silent) showToast(data.message, 'error');
                }
                if (typeof updateStep1TotalCost === 'function') updateStep1TotalCost();
            } catch(e) {
                if (!silent) showToast('Coupon check failed!', 'error');
            }
        }

        // --- RESET DROPDOWN SELECT ---
        function onResetDropdownSelect(val) {
            if (val) {
                document.getElementById('resetKeySearchInput').value = val;
            }
        }

        // ===================== INITIALIZATION =====================
        window.addEventListener('DOMContentLoaded', () => {
            resetCarouselTimer();
            initFirebaseClientEngine();
            handleSplashDismissal();
            
            // কারেন্সি সেটআপ ট্রিগার (পেজ খোলার সাথেই সিলেক্ট করা কারেন্সি দেখাবে, টোস্ট ছাড়া)
            setGlobalCurrency(globalSelectedCurrency, true);

            if (requestedInitialProdId > 0 && allProductsCatalog[requestedInitialProdId]) {
                openProductModal(requestedInitialProdId);
            } else {
                const savedPage = sessionStorage.getItem('dashboard_active_page') || 'dashboard';
                goToPage(savedPage);
            }
            <?php if (!empty($toast)): ?>
                showToast("<?= addslashes($toast['msg']) ?>", "<?= $toast['type'] ?>");
            <?php endif; ?>
        });
    </script>
</body>
</html>