<?php
/**
 * ====================================================================
 * SUB-ADMIN SYSTEM — TENANT & STORE MANAGER CONSOLE
 * VERSION: 14.0 (DYNAMIC GATEWAYS, SNZPAY ENGINE & ANTI-FREEZE CORE)
 * ====================================================================
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

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
// CORE DATABASE AUTO-MIGRATIONS FOR ISOLATED TENANT
// ====================================================================
// ⚡ SPEED: migrations run only once after this file changes (not on every click)
$__schemaFlagDir  = __DIR__ . '/uploads/';
if (!is_dir($__schemaFlagDir)) @mkdir($__schemaFlagDir, 0755, true);
$__schemaFlagFile = $__schemaFlagDir . '.schema_subadmin_' . (int)@filemtime(__FILE__) . '.flag';
$runSchemaMigrations = !is_file($__schemaFlagFile) || isset($_GET['run_migrations']);

if ($runSchemaMigrations) try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `subdomain_settings` (
      `subdomain_id` INT NOT NULL,
      `setting_key` VARCHAR(100) NOT NULL,
      `setting_value` TEXT NULL,
      PRIMARY KEY (`subdomain_id`, `setting_key`)
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

    // সাব-ডোমেইন কুপন টেবিল
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

    safeAddCol($pdo, 'products', 'maintenance_msg', 'TEXT NULL');
    safeAddCol($pdo, 'products', 'is_maintenance', 'TINYINT(1) NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'products', 'reset_enabled', 'TINYINT(1) NOT NULL DEFAULT 1');
    safeAddCol($pdo, 'products', 'km_api_id', 'INT NOT NULL DEFAULT 0');
    // 🎟️ COUPON ENGINE v2 কলাম
    safeAddCol($pdo, 'coupons', 'max_uses', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'coupons', 'used_count', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'coupons', 'per_user_limit', 'INT NOT NULL DEFAULT 1');
    safeAddCol($pdo, 'coupons', 'starts_at', 'DATETIME NULL');
    safeAddCol($pdo, 'coupons', 'expires_at', 'DATETIME NULL');
    safeAddCol($pdo, 'coupons', 'max_discount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    safeAddCol($pdo, 'coupons', 'user_role', "VARCHAR(20) NOT NULL DEFAULT 'all'");
    safeAddCol($pdo, 'coupons', 'title', 'VARCHAR(150) NULL');
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `coupon_usages` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `coupon_id` INT NOT NULL,
      `subdomain_id` INT NOT NULL DEFAULT 0,
      `user_id` INT NOT NULL,
      `product_id` INT NULL,
      `discount_bdt` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `order_total_bdt` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_cu_coupon_user` (`coupon_id`, `user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"); } catch (Throwable $e) {}

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `subdomain_admins` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `subdomain_id` INT NOT NULL,
      `telegram_id` VARCHAR(50) NOT NULL,
      `admin_name` VARCHAR(150) DEFAULT 'Admin',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY `sub_tg_unique` (`subdomain_id`, `telegram_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_custom_prices` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `plan_id` INT NOT NULL,
      `custom_price` DECIMAL(10,2) NOT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_user_plan` (`user_id`, `plan_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Dynamic Payment Methods Table (Full Subdomain Isolation)
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

    // Dynamic Schema Columns
    safeAddCol($pdo, 'payment_methods', 'qr_image_url', 'TEXT NULL');
    safeAddCol($pdo, 'payment_methods', 'banner_url', 'TEXT NULL');
    safeAddCol($pdo, 'payment_methods', 'allow_add_fund', 'TINYINT(1) DEFAULT 1');
    safeAddCol($pdo, 'payment_methods', 'allow_checkout', 'TINYINT(1) DEFAULT 1');

    safeAddCol($pdo, 'products', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'products', 'app_download_url', 'TEXT NULL');

    safeAddCol($pdo, 'product_plans', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'product_plans', 'regular_price', 'DECIMAL(10,2) DEFAULT 0.00');
    safeAddCol($pdo, 'product_plans', 'discount_percent', 'DECIMAL(5,2) DEFAULT 0.00');
    safeAddCol($pdo, 'product_plans', 'reseller_discount_percent', 'DECIMAL(5,2) DEFAULT 0.00');
    safeAddCol($pdo, 'product_plans', 'reward_coins', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'product_plans', 'coin_price', 'INT NOT NULL DEFAULT 0');

    safeAddCol($pdo, 'stock_keys', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'banners', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'system_notifications', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'users', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'users', 'discount_percent', 'DECIMAL(5,2) DEFAULT 0.00');
    safeAddCol($pdo, 'users', 'created_by_subdomain', 'VARCHAR(100) NULL');
    safeAddCol($pdo, 'licenses', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'licenses', 'payment_type', "VARCHAR(20) DEFAULT 'balance'");
    safeAddCol($pdo, 'transactions', 'subdomain_id', 'INT NOT NULL DEFAULT 0');
    safeAddCol($pdo, 'transactions', 'bonus_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');

    safeAddCol($pdo, 'subdomains', 'apk_url', 'TEXT NULL');

    // Auto populate regular_price for plans if zero
        try {
            $pdo->exec("UPDATE product_plans SET regular_price = user_price WHERE (regular_price IS NULL OR regular_price <= 0) AND user_price > 0");
            $pdo->exec("UPDATE product_plans SET regular_price = reseller_price WHERE (regular_price IS NULL OR regular_price <= 0) AND reseller_price > 0");
        } catch (Throwable $e) {}

        // --- SUBDOMAIN ISOLATED TABLES FOR BOT, POPUPS, CATEGORIES & SUPPORT ---
        safeAddCol($pdo, 'subdomains', 'support_telegram', 'TEXT NULL');
        safeAddCol($pdo, 'subdomains', 'support_whatsapp', 'TEXT NULL');

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

// ====================================================================
// SUBDOMAIN TENANT IDENTIFIER & LOGIN AUTHENTICATION
// ====================================================================
$currentHost = $_SERVER['HTTP_HOST'] ?? (defined('MAIN_DOMAIN') ? MAIN_DOMAIN : 'vaipanel.shop');
$hostParts = explode('.', $currentHost);
$detectedSubdomain = '';
if (count($hostParts) >= 3 && $hostParts[0] !== 'www') {
    $detectedSubdomain = strtolower($hostParts[0]);
}

$activeTenant = null;
if (!empty($detectedSubdomain)) {
    try {
        $st = $pdo->prepare("SELECT * FROM subdomains WHERE subdomain = ? LIMIT 1");
        $st->execute([$detectedSubdomain]);
        $activeTenant = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$loginError = '';

// Logout Handler
if (isset($_GET['logout'])) {
    unset($_SESSION['subadmin_auth'], $_SESSION['subadmin_id'], $_SESSION['subadmin_user'], $_SESSION['subadmin_subdomain']);
    header('Location: sub_admin.php');
    exit;
}

// Login Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'subadmin_login') {
    $uname = trim($_POST['username'] ?? '');
    $upass = $_POST['password'] ?? '';

    if (empty($uname) || empty($upass)) {
        $loginError = 'Please enter both Username and Password!';
    } else {
        $subRow = null;
        if (!empty($detectedSubdomain)) {
            $stmt = $pdo->prepare("SELECT * FROM subdomains WHERE subdomain = ? AND admin_username = ? LIMIT 1");
            $stmt->execute([$detectedSubdomain, $uname]);
            $subRow = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$subRow) {
            $stmt = $pdo->prepare("SELECT * FROM subdomains WHERE admin_username = ? LIMIT 1");
            $stmt->execute([$uname]);
            $subRow = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($subRow) {
            if ($subRow['status'] === 'suspended') {
                $loginError = 'This store console is suspended. Contact Root Admin!';
            } elseif (password_verify($upass, (string)$subRow['admin_password']) || $upass === $subRow['admin_password']) {
                if ($upass === $subRow['admin_password']) {
                    try {
                        $hash = password_hash($upass, PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE subdomains SET admin_password = ? WHERE id = ?")->execute([$hash, $subRow['id']]);
                    } catch (Throwable $e) {}
                }

                $_SESSION['subadmin_auth'] = true;
                $_SESSION['subadmin_id'] = (int)$subRow['id'];
                $_SESSION['subadmin_user'] = $subRow['admin_username'];
                $_SESSION['subadmin_subdomain'] = $subRow['subdomain'];
                header('Location: sub_admin.php');
                exit;
            } else {
                $loginError = 'Invalid Password! Please try again.';
            }
        } else {
            $loginError = 'Invalid Sub-Admin Username for this domain!';
        }
    }
}

// Render Login Gate
if (empty($_SESSION['subadmin_auth'])) {
    $displayTitle = !empty($activeTenant['site_title']) ? $activeTenant['site_title'] : 'SUB-ADMIN PANEL';
    $displaySub = !empty($detectedSubdomain) ? $detectedSubdomain . '.' . $currentHost : $currentHost;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>Sub-Admin Login — <?= htmlspecialchars($displayTitle) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800;900&family=JetBrains+Mono:wght@600;800&display=swap" rel="stylesheet">
        <style>
            body { background: #030612; color: #00f5ff; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 16px; }
            .font-mono { font-family: 'JetBrains Mono', monospace; }
            .auth-card { background: #070d20; border: 1px solid #16203a; border-radius: 24px; padding: 32px 28px; width: 100%; max-width: 380px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.85); }
            .btn-cyan { background: linear-gradient(135deg, #00f5ff 0%, #0088ff 100%); color: #020617; font-weight: 800; box-shadow: 0 4px 20px rgba(0, 245, 255, 0.3); }
        </style>
    </head>
    <body>
        <div class="auth-card space-y-6">
            <div class="text-center space-y-2">
                <div class="w-14 h-14 mx-auto rounded-2xl bg-cyan-500/10 border border-cyan-500/30 flex items-center justify-center text-cyan-400 text-2xl shadow-inner">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <h2 class="text-lg font-black text-white uppercase tracking-wider"><?= htmlspecialchars($displayTitle) ?></h2>
                    <p class="text-[11px] text-slate-400 font-mono mt-0.5"><?= htmlspecialchars($displaySub) ?></p>
                </div>
            </div>

            <?php if (!empty($loginError)): ?>
                <div class="p-3 rounded-xl bg-red-950/50 border border-red-500/40 text-red-400 text-xs font-mono flex items-center space-x-2">
                    <i class="fa-solid fa-triangle-exclamation flex-shrink-0"></i>
                    <span><?= htmlspecialchars($loginError) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="sub_admin.php" class="space-y-4 text-xs font-mono">
                <input type="hidden" name="action" value="subadmin_login">

                <div>
                    <label class="text-slate-300 block mb-1.5 font-bold">Sub-Admin Username</label>
                    <div class="relative">
                        <input type="text" name="username" required autocomplete="username" placeholder="admin_..." class="w-full bg-[#030612] border border-slate-800 p-3 pl-10 rounded-xl text-white outline-none focus:border-cyan-400 font-mono font-bold transition">
                        <i class="fa-solid fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500"></i>
                    </div>
                </div>

                <div>
                    <label class="text-slate-300 block mb-1.5 font-bold">Password</label>
                    <div class="relative">
                        <input type="password" id="loginPassInput" name="password" required autocomplete="current-password" placeholder="••••••••" class="w-full bg-[#030612] border border-slate-800 p-3 pl-10 pr-10 rounded-xl text-white outline-none focus:border-cyan-400 font-mono transition">
                        <i class="fa-solid fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500"></i>
                        <button type="button" onclick="togglePassVisibility()" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white">
                            <i id="eyeIcon" class="fa-solid fa-eye text-xs"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full py-3.5 btn-cyan rounded-xl uppercase tracking-wider font-black text-xs transition active:scale-95 shadow-md">
                    LOGIN TO CONSOLE
                </button>
            </form>

            <div class="text-center pt-2 border-t border-slate-800/80">
                <span class="text-[10px] text-slate-500 font-mono">Secured Sub-Admin Dashboard &copy; <?= date('Y') ?></span>
            </div>
        </div>

        <script>
            function togglePassVisibility() {
                const p = document.getElementById('loginPassInput');
                const icon = document.getElementById('eyeIcon');
                if (p.type === 'password') {
                    p.type = 'text';
                    icon.className = 'fa-solid fa-eye-slash text-xs';
                } else {
                    p.type = 'password';
                    icon.className = 'fa-solid fa-eye text-xs';
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Logged-in Tenant Context
$subadminTenantId = (int)($_SESSION['subadmin_id'] ?? 0);
$subadminSlug     = $_SESSION['subadmin_subdomain'] ?? $detectedSubdomain;

if (!$activeTenant && $subadminTenantId > 0) {
    $activeTenant = $pdo->query("SELECT * FROM subdomains WHERE id = $subadminTenantId LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

// Auto-seed Payment Methods for this Subdomain if completely empty
try {
    $chkMethods = (int)$pdo->query("SELECT COUNT(*) FROM payment_methods WHERE subdomain_id = $subadminTenantId")->fetchColumn();
    if ($chkMethods === 0) {
        $stmtSeed = $pdo->prepare("INSERT INTO payment_methods (subdomain_id, method_key, name, account_number, account_type, instructions, is_active, allow_add_fund, allow_checkout, sort_order) VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1, ?)");
        $stmtSeed->execute([$subadminTenantId, 'bkash', 'bKash', '01700000000', 'Personal', 'Send Money to the Personal number above, then enter the deposit amount and Transaction ID (TrxID) below to verify instantly.', 1]);
        $stmtSeed->execute([$subadminTenantId, 'nagad', 'Nagad', '01800000000', 'Personal', 'Send Money to the Personal number above, then enter the deposit amount and Transaction ID (TrxID) below to verify instantly.', 2]);
        $stmtSeed->execute([$subadminTenantId, 'rocket', 'Rocket', '01900000000', 'Personal', 'Send Money to the Personal number above, then enter the deposit amount and Transaction ID (TrxID) below to verify instantly.', 3]);
    }
} catch (Throwable $e) {}

// ====================================================================
// FRESH & ISOLATED TENANT SETTINGS
// ====================================================================
$settings = [
    'site_name'                    => !empty($activeTenant['site_title']) ? $activeTenant['site_title'] : 'My Store',
    'site_logo'                    => $activeTenant['site_logo'] ?? '',
    'loading_logo'                 => $activeTenant['loading_logo'] ?? '',
    'main_site_theme_color'        => $activeTenant['theme_color'] ?? '#00f5ff',
    'usdt_rate'                    => '125',
    'inr_rate'                     => '88',
    'binance_market_rate'          => '125',
    'bkash_number'                 => '01700000000',
    'nagad_number'                 => '01800000000',
    'rocket_number'                => '01900000000',
    'binance_id'                   => '12345678',
    'payment_banner_url'           => '',
    'app_download_url'             => $activeTenant['apk_url'] ?? '',
    'firebase_url'                 => '',
    'auto_payment_on'              => '0',
    'snzpay_api_url'               => 'https://snzpay.digital/api/verify_payment.php',
    'snzpay_license_key'           => '',
    'snzpay_auto_verify'           => '0',
    'deposit_system_status'        => '1',
    'site_maintenance_mode'        => '0',
    'site_maintenance_msg'         => 'Store upgrade in progress.',
    'min_deposit_limit'            => '5.00',
    'max_deposit_limit'            => '500.00',
    'telegram_tutorial_link'       => $activeTenant['support_link'] ?? '',
    'update_channel_popup_enabled' => '0',
    'update_channel_title'         => 'Join Channel',
    'update_channel_msg'           => '',
    'update_channel_link'          => $activeTenant['channel_link'] ?? '',
    'spin_wheel_enabled'           => '0',
    'spin_cost'                    => '0.00',
    'spin_daily_limit'             => '5',
    'spin_offer_duration_hours'    => '24',
    'footer_copyright_text'        => '',
    'footer_telegram_link'         => $activeTenant['support_link'] ?? '',
    'footer_whatsapp_link'         => '',
    'footer_youtube_link'          => '',
    'km_api_url'                   => '',
    'km_api_key'                   => '',
    'km_last_status'               => 'untested',
    'km_cached_balance'            => '$0.00 USD',
    'km_cached_balance_time'       => '0',
    'firebase_api_key'             => '',
    'firebase_auth_domain'         => '',
    'firebase_project_id'          => '',
    'firebase_storage_bucket'      => '',
    'firebase_messaging_sender_id' => '',
    'firebase_app_id'              => '',
    'support_telegram'             => $activeTenant['support_telegram'] ?? '',
    'support_whatsapp'             => $activeTenant['support_whatsapp'] ?? '',
    'maintenance_telegram_link'    => ''
];

try {
    $stmtTenantSet = $pdo->prepare("SELECT setting_key, setting_value FROM subdomain_settings WHERE subdomain_id = ?");
    $stmtTenantSet->execute([$subadminTenantId]);
    $tenantDbSettings = $stmtTenantSet->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($tenantDbSettings)) {
        $settings = array_merge($settings, $tenantDbSettings);
    }
    if (!empty($activeTenant['support_telegram'])) $settings['support_telegram'] = $activeTenant['support_telegram'];
    if (!empty($activeTenant['support_whatsapp'])) $settings['support_whatsapp'] = $activeTenant['support_whatsapp'];
} catch (Throwable $e) {}

// 💱 সাব-ডোমেইন নিজের রেট সেট না করলে মাদার প্যানেলের রেট (BDT / INR) ব্যবহার হবে
try {
    $stmtMotherRates = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('usdt_rate', 'inr_rate', 'binance_market_rate')");
    $motherRates = $stmtMotherRates ? $stmtMotherRates->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    foreach (['usdt_rate', 'inr_rate', 'binance_market_rate'] as $__rk) {
        if ((!isset($tenantDbSettings[$__rk]) || (float)$tenantDbSettings[$__rk] <= 0) && !empty($motherRates[$__rk])) {
            $settings[$__rk] = $motherRates[$__rk];
        }
    }
} catch (Throwable $e) {}

$effectiveBdtRate = max(1.0, (float)($settings['usdt_rate'] ?? 125.0));
$effectiveInrRate = max(1.0, (float)($settings['inr_rate'] ?? 88.0));

// --- LOAD ISOLATED SUBDOMAIN DATA ---
$stmtB = $pdo->prepare("SELECT * FROM telegram_bot_config WHERE subdomain_id = ? LIMIT 1");
$stmtB->execute([$subadminTenantId]);
$botConfig = $stmtB->fetch(PDO::FETCH_ASSOC) ?: [
    'bot_token' => '', 'chat_id' => '', 'notify_deposit' => 1, 'notify_purchase' => 1, 'notify_failed' => 1, 'is_active' => 1
];

$stmtR = $pdo->prepare("SELECT * FROM referral_settings WHERE subdomain_id = ? LIMIT 1");
$stmtR->execute([$subadminTenantId]);
$referralConfig = $stmtR->fetch(PDO::FETCH_ASSOC) ?: [
    'token_per_referral' => 10, 'referrer_bonus_bdt' => 0.00, 'is_active' => 1
];

$stmtP = $pdo->prepare("SELECT * FROM custom_popups WHERE subdomain_id = ? ORDER BY sort_order ASC, id DESC");
$stmtP->execute([$subadminTenantId]);
$customPopupsList = $stmtP->fetchAll(PDO::FETCH_ASSOC);

$stmtC = $pdo->prepare("SELECT * FROM product_categories WHERE subdomain_id = ? ORDER BY sort_order ASC, id ASC");
$stmtC->execute([$subadminTenantId]);
$productCategoriesList = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// ====================================================================
// SNZPAY VERIFICATION & GATEWAY ENGINE (SUB-ADMIN)
// ====================================================================
function subadmin_snzpay_verify_payment(PDO $pdo, int $subId, string $trxId, string $method = 'BKASH', float $amount = 0.0): array {
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
// ANTI-FREEZE KEY MANAGER CACHED BALANCE FETCHER (SUB-ADMIN)
// ====================================================================
function subadmin_km_cached_balance(PDO $pdo, int $subId): string {
    global $settings;

    $now = time();
    $cachedTime = (int)($settings['km_cached_balance_time'] ?? 0);
    $cachedVal  = $settings['km_cached_balance'] ?? '$0.00 USD';

    if (($now - $cachedTime) < 90 && !empty($cachedVal) && $cachedVal !== 'API Error' && $cachedVal !== 'Connection Failed') {
        return $cachedVal;
    }

    $kmUrl = trim($settings['km_api_url'] ?? '');
    $kmKey = trim($settings['km_api_key'] ?? '');
    if (empty($kmUrl) || empty($kmKey)) return '$0.00 USD';

    $target = rtrim($kmUrl, '/');
    if (stripos($target, '.php') === false) $target .= '/api.php';

    $ch = curl_init();
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
            $stmt = $pdo->prepare("INSERT INTO subdomain_settings (subdomain_id, setting_key, setting_value) VALUES (?, 'km_cached_balance', ?), (?, 'km_cached_balance_time', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$subId, $formatted, $subId, (string)$now]);
            $settings['km_cached_balance'] = $formatted;
            $settings['km_cached_balance_time'] = (string)$now;
        } catch (Throwable $e) {}
        return $formatted;
    }

    return $cachedVal ?: '$0.00 USD';
}

// ====================================================================
// SUBDOMAIN STACKING DISCOUNT CALCULATOR & SYNCHRONIZER
// ====================================================================

// ====================================================================
// 🛡️ MANUAL ORDER DELIVERY — অ্যাডমিন Approve করলে কি ডেলিভারি হয় (ব্যালেন্সে টাকা যোগ হয় না)
// ====================================================================
if (!function_exists('vpIsOrderTrx')) {
    function vpIsOrderTrx(array $t): bool {
        return (($t['tx_type'] ?? '') === 'order') || stripos((string)($t['method'] ?? ''), 'Manual Order') !== false;
    }
}
if (!function_exists('vpAdminDeliverOrder')) {
    function vpAdminDeliverOrder(PDO $pdo, array $trx, int $subId, array $storeSettings, string $sourceLabel): array {
        $planId = (int)($trx['order_plan_id'] ?? 0);
        $qty    = max(1, min(50, (int)($trx['order_qty'] ?? 1)));
        if ($planId <= 0) return ['ok' => false, 'message' => 'This old order has no plan saved. Use "Credit Wallet" or "Reject".'];

        $st = $pdo->prepare("SELECT pl.*, p.id AS real_product_id, p.name AS product_name,
                                    COALESCE(NULLIF(pl.plan_title, ''), NULLIF(pl.validity, ''), 'Standard Plan') AS plan_label
                             FROM product_plans pl JOIN products p ON p.id = pl.product_id
                             WHERE pl.id = ? AND p.subdomain_id = ? LIMIT 1");
        $st->execute([$planId, $subId]);
        $plan = $st->fetch(PDO::FETCH_ASSOC);
        if (!$plan) return ['ok' => false, 'message' => 'Ordered plan no longer exists.'];

        $keys = []; $stockIds = [];
        $sk = $pdo->prepare("SELECT id, license_key FROM stock_keys WHERE plan_id = ? AND status = 'available' AND subdomain_id = ? ORDER BY id ASC LIMIT " . (int)$qty);
        $sk->execute([$planId, $subId]);
        $rows = $sk->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === $qty) {
            $stockIds = array_map('intval', array_column($rows, 'id'));
            $pdo->exec("UPDATE stock_keys SET status = 'used' WHERE id IN (" . implode(',', $stockIds) . ")");
            $keys = array_column($rows, 'license_key');
        } else {
            $kmUrl = trim((string)($storeSettings['km_api_url'] ?? ''));
            $kmKey = trim((string)($storeSettings['km_api_key'] ?? ''));
            if ($kmUrl === '' || $kmKey === '') return ['ok' => false, 'message' => 'Out of stock and Key Manager not connected. Add stock first.'];
            $endpoint = rtrim($kmUrl, '/');
            if (stripos($endpoint, '.php') === false) $endpoint .= '/api.php';
            $apiPlan = (int)($plan['api_plan_id'] ?? 0) ?: $planId;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint, CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_POSTFIELDS => http_build_query(['action' => 'generate_key', 'plan_id' => $apiPlan, 'product_id' => $apiPlan, 'quantity' => $qty, 'api_key' => $kmKey]),
                CURLOPT_HTTPHEADER => ['X-API-Token: ' . $kmKey, 'X-API-KEY: ' . $kmKey, 'Accept: application/json'],
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $body = curl_exec($ch); curl_close($ch);
            $data = json_decode((string)$body, true);
            if (is_array($data) && (($data['status'] ?? '') === 'success' || ($data['success'] ?? false) === true)) {
                foreach ((array)($data['keys'] ?? []) as $it) {
                    $k = is_array($it) ? ($it['key'] ?? ($it['license_key'] ?? ($it['code'] ?? ''))) : $it;
                    if (is_string($k) && trim($k) !== '') $keys[] = trim($k);
                }
                if (!$keys && !empty($data['key']) && is_string($data['key'])) $keys[] = trim($data['key']);
            }
            if (!$keys) return ['ok' => false, 'message' => 'Key generation failed: ' . (is_array($data) ? ($data['message'] ?? ($data['error'] ?? 'out of stock')) : 'Key Manager not reachable')];
        }

        $restore = function () use ($pdo, $stockIds) {
            if ($stockIds) { try { $pdo->exec("UPDATE stock_keys SET status = 'available' WHERE id IN (" . implode(',', $stockIds) . ")"); } catch (Throwable $e) {} }
        };
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare("UPDATE transactions SET status = 'approved' WHERE id = ? AND subdomain_id = ? AND status = 'pending'");
            $up->execute([(int)$trx['id'], $subId]);
            if ($up->rowCount() !== 1) { $pdo->rollBack(); $restore(); return ['ok' => false, 'message' => 'This order was already processed.']; }

            $fullName  = $plan['product_name'] . ' (' . $plan['plan_label'] . ')';
            $unitPrice = round((float)$trx['amount'] / $qty, 4);
            $ins = $pdo->prepare("INSERT INTO licenses (user_id, product_id, plan_id, license_key, validity, price, source, payment_type, subdomain_id) VALUES (?, ?, ?, ?, ?, ?, ?, 'direct_pay', ?)");
            foreach ($keys as $k) $ins->execute([(int)$trx['user_id'], (int)$plan['real_product_id'], $planId, (string)$k, $fullName, $unitPrice, $sourceLabel, $subId]);

            $coins = max(0, (int)($plan['reward_coins'] ?? 0)) * $qty;
            if ($coins > 0) $pdo->prepare("UPDATE users SET coins = coins + ? WHERE id = ? AND subdomain_id = ?")->execute([$coins, (int)$trx['user_id'], $subId]);

            if (!empty($trx['order_coupon'])) {
                $cp = $pdo->prepare("SELECT id FROM coupons WHERE UPPER(code) = ? AND subdomain_id = ? LIMIT 1");
                $cp->execute([strtoupper((string)$trx['order_coupon']), $subId]);
                if ($cpId = (int)$cp->fetchColumn()) {
                    $pdo->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?")->execute([$cpId]);
                    $pdo->prepare("INSERT INTO coupon_usages (coupon_id, subdomain_id, user_id, product_id, discount_bdt, order_total_bdt) VALUES (?, ?, ?, ?, 0, 0)")
                        ->execute([$cpId, $subId, (int)$trx['user_id'], (int)$plan['real_product_id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $restore();
            return ['ok' => false, 'message' => 'Delivery error: ' . $e->getMessage()];
        }
        return ['ok' => true, 'keys' => $keys, 'product' => $fullName];
    }
}

function syncTenantStackedPricesForUser(PDO $pdo, int $userId, int $subId) {
    try {
        $stmtUser = $pdo->prepare("SELECT id, role, discount_percent FROM users WHERE id = ? AND subdomain_id = ? LIMIT 1");
        $stmtUser->execute([$userId, $subId]);
        $u = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if (!$u) return;

        $userDisc = (float)($u['discount_percent'] ?? 0);
        $role     = strtolower(trim((string)($u['role'] ?? 'customer')));

        $plansStmt = $pdo->prepare("SELECT pp.id, pp.user_price, pp.reseller_price, pp.regular_price, pp.discount_percent, pp.reseller_discount_percent 
                                    FROM product_plans pp 
                                    JOIN products p ON pp.product_id = p.id 
                                    WHERE p.subdomain_id = ?");
        $plansStmt->execute([$subId]);
        $plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);
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

function syncTenantStackedPricesForAllUsers(PDO $pdo, int $subId, ?string $onlyRole = null) {
    try {
        $sql = "SELECT id FROM users WHERE subdomain_id = ?";
        if ($onlyRole === 'customer') {
            $sql .= " AND (LOWER(TRIM(COALESCE(role, 'customer'))) = 'customer' OR role IS NULL OR role = '')";
        } elseif ($onlyRole === 'reseller') {
            $sql .= " AND LOWER(TRIM(role)) = 'reseller'";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$subId]);
        $uIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($uIds as $uId) {
            syncTenantStackedPricesForUser($pdo, (int)$uId, $subId);
        }
    } catch (Throwable $e) {}
}

// ====================================================================
// KEY MANAGER API HANDLER FOR SUB-ADMIN (FAST TIMEOUT)
// ====================================================================
function subadmin_km_request(string $apiUrl, string $apiKey, string $endpoint, array $params = [], string $method = 'POST'): array {
    $apiUrl = trim($apiUrl);
    $apiKey = trim($apiKey);
    if (empty($apiUrl) || empty($apiKey)) {
        return ['status' => 'error', 'message' => 'API URL and API Key are required!'];
    }

    $isPhpFile = (stripos($apiUrl, '.php') !== false);
    if ($isPhpFile) {
        $url = $apiUrl;
        $params['action'] = $endpoint;
    } else {
        $url = rtrim($apiUrl, '/') . '/' . ltrim($endpoint, '/');
    }

    $params['api_key'] = $apiKey;

    $ch = curl_init();
    if (strtoupper($method) === 'GET') {
        $query = http_build_query($params);
        $sep = (strpos($url, '?') !== false) ? '&' : '?';
        curl_setopt($ch, CURLOPT_URL, $url . $sep . $query);
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['status' => 'error', 'message' => 'Connection Error: ' . $err];
    $json = json_decode($res, true);
    if (!is_array($json)) {
        if (strtoupper($method) === 'POST' && $isPhpFile) {
            return subadmin_km_request($apiUrl, $apiKey, $endpoint, $params, 'GET');
        }
        return ['status' => 'error', 'message' => 'Invalid Response from Key Manager'];
    }
    return $json;
}

function fetchSubadminKmCatalog(array $settings): ?array {
    $kmUrl = trim($settings['km_api_url'] ?? '');
    $kmKey = trim($settings['km_api_key'] ?? '');
    if (empty($kmUrl) || empty($kmKey)) return null;

    $res = subadmin_km_request($kmUrl, $kmKey, 'products', [], 'GET');
    if (empty($res) || (isset($res['status']) && $res['status'] === 'error')) {
        $res = subadmin_km_request($kmUrl, $kmKey, 'products', [], 'POST');
    }

    $raw = $res['products'] ?? ($res['data']['products'] ?? ($res['data'] ?? ($res['items'] ?? [])));
    if (empty($raw) && is_array($res) && isset($res[0])) {
        $raw = $res;
    }

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
    return null;
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_km_catalog') {
    header('Content-Type: application/json');
    $items = fetchSubadminKmCatalog($settings);
    if (is_array($items) && !empty($items)) {
        echo json_encode(['success' => true, 'items' => $items]);
    } else {
        $err = empty($settings['km_api_key']) ? 'API Key is empty in Key Manager tab.' : 'Failed to retrieve catalog from Key Manager.';
        echo json_encode(['success' => false, 'error' => $err]);
    }
    exit;
}

$toast = null;
$activeTab = $_POST['active_tab'] ?? 'tab-overview';
$activeDiscSubTab = $_POST['disc_sub_tab'] ?? 'disc-view-products';

function saveTenantSetting(PDO $pdo, int $subId, string $key, string $val) {
    $stmt = $pdo->prepare("INSERT INTO subdomain_settings (subdomain_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$subId, $key, $val, $val]);
}

// ====================================================================
// SUB-ADMIN ACTIONS ROUTER (STRICTLY CONFINED TO THIS SUBDOMAIN)
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
        saveTenantSetting($pdo, $subadminTenantId, 'usdt_rate', (string)$newRate);
        saveTenantSetting($pdo, $subadminTenantId, 'inr_rate', (string)$newInrRate);
        $settings['usdt_rate'] = (string)$newRate;
        $settings['inr_rate']  = (string)$newInrRate;
        $effectiveBdtRate = $newRate;
        $effectiveInrRate = $newInrRate;
        $toast = ['type' => 'success', 'msg' => "Store Rates saved: 1 USDT = ৳$newRate BDT | ₹$newInrRate INR"];
    }

    if ($act === 'update_site_theme_color') {
        $newColor = trim($_POST['main_site_theme_color'] ?? '#00f5ff');
        if (!preg_match('/^#[a-f0-9]{6}$/i', $newColor) && !preg_match('/^#[a-f0-9]{3}$/i', $newColor)) {
            $newColor = '#00f5ff';
        }
        $pdo->prepare("UPDATE subdomains SET theme_color = ? WHERE id = ?")->execute([$newColor, $subadminTenantId]);
        saveTenantSetting($pdo, $subadminTenantId, 'main_site_theme_color', $newColor);
        $settings['main_site_theme_color'] = $newColor;
        $toast = ['type' => 'success', 'msg' => "Store theme color updated!"];
    }

    if ($act === 'save_km_credentials') {
        $kmUrl = trim($_POST['km_api_url'] ?? '');
        $kmKey = trim($_POST['km_api_key'] ?? '');

        if (!empty($kmUrl) && !empty($kmKey)) {
            saveTenantSetting($pdo, $subadminTenantId, 'km_api_url', $kmUrl);
            saveTenantSetting($pdo, $subadminTenantId, 'km_api_key', $kmKey);
            $settings['km_api_url'] = $kmUrl;
            $settings['km_api_key'] = $kmKey;

            $testRes = subadmin_km_request($kmUrl, $kmKey, 'balance');
            if (isset($testRes['status']) && $testRes['status'] === 'success') {
                $bal = number_format((float)($testRes['balance'] ?? 0), 2);
                saveTenantSetting($pdo, $subadminTenantId, 'km_last_status', 'connected');
                saveTenantSetting($pdo, $subadminTenantId, 'km_cached_balance', '$' . $bal . ' USD');
                saveTenantSetting($pdo, $subadminTenantId, 'km_cached_balance_time', (string)time());
                $settings['km_last_status'] = 'connected';
                $settings['km_cached_balance'] = '$' . $bal . ' USD';
                $toast = ['type' => 'success', 'msg' => "Connected! Balance: $$bal USD"];
            } else {
                $errMsg = $testRes['message'] ?? 'Could not authenticate.';
                saveTenantSetting($pdo, $subadminTenantId, 'km_last_status', 'failed');
                $settings['km_last_status'] = 'failed';
                $toast = ['type' => 'error', 'msg' => "Connection Failed: $errMsg"];
            }
        } else {
            $toast = ['type' => 'error', 'msg' => 'API URL and Secret Key are required!'];
        }
    }

    if ($act === 'test_km_connection') {
        $testRes = subadmin_km_request($settings['km_api_url'] ?? '', $settings['km_api_key'] ?? '', 'balance');
        if (isset($testRes['status']) && $testRes['status'] === 'success') {
            $bal = number_format((float)($testRes['balance'] ?? 0), 2);
            saveTenantSetting($pdo, $subadminTenantId, 'km_last_status', 'connected');
            saveTenantSetting($pdo, $subadminTenantId, 'km_cached_balance', '$' . $bal . ' USD');
            saveTenantSetting($pdo, $subadminTenantId, 'km_cached_balance_time', (string)time());
            $settings['km_last_status'] = 'connected';
            $settings['km_cached_balance'] = '$' . $bal . ' USD';
            $toast = ['type' => 'success', 'msg' => "Operational! Balance: $$bal USD"];
        } else {
            $errMsg = $testRes['message'] ?? 'Connection error.';
            saveTenantSetting($pdo, $subadminTenantId, 'km_last_status', 'failed');
            $settings['km_last_status'] = 'failed';
            $toast = ['type' => 'error', 'msg' => "Failed: $errMsg"];
        }
    }

    if ($act === 'sync_km_products') {
        $items = fetchSubadminKmCatalog($settings);
        if (is_array($items) && !empty($items)) {
            $pdo->beginTransaction();
            $syncedCount = 0;
            try {
                foreach ($items as $p) {
                    $extId       = (int)$p['id'];
                    $productName = trim($p['product_name']);
                    $planTitle   = trim($p['plan_title']);
                    $basePrice   = (float)$p['price'];
                    $code        = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $productName)) . '_sub_' . $subadminTenantId;

                    $stmtProd = $pdo->prepare("SELECT id FROM products WHERE name = ? AND subdomain_id = ? LIMIT 1");
                    $stmtProd->execute([$productName, $subadminTenantId]);
                    $prodId = $stmtProd->fetchColumn();

                    if (!$prodId) {
                        $stmtIns = $pdo->prepare("INSERT INTO products (name, code, sort_order, is_active, category, subdomain_id) VALUES (?, ?, 0, 1, 'APKMOD', ?)");
                        $stmtIns->execute([$productName, $code, $subadminTenantId]);
                        $prodId = (int)$pdo->lastInsertId();
                    }

                    $stmtPlan = $pdo->prepare("SELECT id FROM product_plans WHERE api_plan_id = ? AND product_id = ? AND subdomain_id = ? LIMIT 1");
                    $stmtPlan->execute([$extId, $prodId, $subadminTenantId]);
                    $planExists = $stmtPlan->fetchColumn();

                    $resellerPrice = $basePrice;
                    $userPrice     = round($basePrice * 1.25, 2);

                    if (!$planExists) {
                        $stmtPlanIns = $pdo->prepare("INSERT INTO product_plans (product_id, duration_days, plan_title, reseller_price, user_price, regular_price, discount_percent, reseller_discount_percent, api_plan_id, reward_coins, coin_price, subdomain_id) VALUES (?, 30, ?, ?, ?, ?, 0.00, 20.00, ?, 0, 0, ?)");
                        $stmtPlanIns->execute([$prodId, $planTitle, $resellerPrice, $userPrice, $userPrice, $extId, $subadminTenantId]);
                    } else {
                        $stmtPlanUp = $pdo->prepare("UPDATE product_plans SET plan_title = ?, reseller_price = ?, user_price = ?, regular_price = ? WHERE id = ? AND subdomain_id = ?");
                        $stmtPlanUp->execute([$planTitle, $resellerPrice, $userPrice, $userPrice, $planExists, $subadminTenantId]);
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
            $toast = ['type' => 'error', 'msg' => 'Could not fetch catalog. Connect Key Manager API first!'];
        }
    }

    if ($act === 'sync_selected_km_products') {
        $selectedExtIds = $_POST['selected_api_ids'] ?? [];
        if (empty($selectedExtIds)) {
            $toast = ['type' => 'error', 'msg' => 'No plans selected!'];
        } else {
            $items = fetchSubadminKmCatalog($settings);
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
                        $code        = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $productName)) . '_sub_' . $subadminTenantId;

                        $stmtProd = $pdo->prepare("SELECT id FROM products WHERE name = ? AND subdomain_id = ? LIMIT 1");
                        $stmtProd->execute([$productName, $subadminTenantId]);
                        $prodId = $stmtProd->fetchColumn();

                        if (!$prodId) {
                            $stmtIns = $pdo->prepare("INSERT INTO products (name, code, sort_order, is_active, category, subdomain_id) VALUES (?, ?, 0, 1, 'APKMOD', ?)");
                            $stmtIns->execute([$productName, $code, $subadminTenantId]);
                            $prodId = (int)$pdo->lastInsertId();
                        }

                        $stmtPlan = $pdo->prepare("SELECT id FROM product_plans WHERE api_plan_id = ? AND product_id = ? AND subdomain_id = ? LIMIT 1");
                        $stmtPlan->execute([$extId, $prodId, $subadminTenantId]);
                        $planExists = $stmtPlan->fetchColumn();

                        $resellerPrice = $basePrice;
                        $userPrice     = round($basePrice * 1.25, 2);

                        if (!$planExists) {
                            $stmtPlanIns = $pdo->prepare("INSERT INTO product_plans (product_id, duration_days, plan_title, reseller_price, user_price, regular_price, discount_percent, reseller_discount_percent, api_plan_id, reward_coins, coin_price, subdomain_id) VALUES (?, 30, ?, ?, ?, ?, 0.00, 20.00, ?, 0, 0, ?)");
                            $stmtPlanIns->execute([$prodId, $planTitle, $resellerPrice, $userPrice, $userPrice, $extId, $subadminTenantId]);
                        } else {
                            $stmtPlanUp = $pdo->prepare("UPDATE product_plans SET plan_title = ?, reseller_price = ?, user_price = ?, regular_price = ? WHERE id = ? AND subdomain_id = ?");
                            $stmtPlanUp->execute([$planTitle, $resellerPrice, $userPrice, $userPrice, $planExists, $subadminTenantId]);
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
            $pdo->prepare("DELETE sk FROM stock_keys sk JOIN products p ON sk.product_id = p.id WHERE p.subdomain_id = ?")->execute([$subadminTenantId]);
            $pdo->prepare("DELETE pp FROM product_plans pp JOIN products p ON pp.product_id = p.id WHERE p.subdomain_id = ?")->execute([$subadminTenantId]);
            $pdo->prepare("DELETE FROM products WHERE subdomain_id = ?")->execute([$subadminTenantId]);
            $toast = ['type' => 'success', 'msg' => 'Store catalog purged successfully!'];
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Wipe failed: ' . $e->getMessage()];
        }
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
            $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $name)) . '_sub_' . $subadminTenantId;
            $logoUrl = null;
            $bannerUrl = null;

            if (!empty($_FILES['product_logo']['name']) && $_FILES['product_logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['product_logo']['name'], PATHINFO_EXTENSION));
                $fileName = 'prod_logo_' . $subadminTenantId . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['product_logo']['tmp_name'], $prodUploadDir . $fileName)) {
                    $logoUrl = 'uploads/products/' . $fileName;
                }
            }
            if (!empty($_FILES['product_banner']['name']) && $_FILES['product_banner']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['product_banner']['name'], PATHINFO_EXTENSION));
                $fileName = 'prod_banner_' . $subadminTenantId . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['product_banner']['tmp_name'], $prodUploadDir . $fileName)) {
                    $bannerUrl = 'uploads/products/' . $fileName;
                }
            }

            $stmt = $pdo->prepare("INSERT INTO products (name, code, category, logo_url, banner_url, image_url, download_link, setup_link, app_download_url, is_active, is_pinned, subdomain_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $code, $category, $logoUrl, $bannerUrl, $bannerUrl, $downloadLink, $setupLink, $appDownloadUrl, $isActive, $isPinned, $subadminTenantId]);
            $toast = ['type' => 'success', 'msg' => "Product created for your store!"];
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

        $curr = $pdo->query("SELECT * FROM products WHERE id = $prodId AND subdomain_id = $subadminTenantId")->fetch();
        if ($curr) {
            $logoUrl = $curr['logo_url'] ?? null;
            $bannerUrl = $curr['banner_url'] ?? ($curr['image_url'] ?? null);

            if (!empty($_FILES['product_logo']['name']) && $_FILES['product_logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['product_logo']['name'], PATHINFO_EXTENSION));
                $fileName = 'prod_logo_' . $subadminTenantId . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['product_logo']['tmp_name'], $prodUploadDir . $fileName)) {
                    $logoUrl = 'uploads/products/' . $fileName;
                }
            }
            if (!empty($_FILES['product_banner']['name']) && $_FILES['product_banner']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['product_banner']['name'], PATHINFO_EXTENSION));
                $fileName = 'prod_banner_' . $subadminTenantId . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['product_banner']['tmp_name'], $prodUploadDir . $fileName)) {
                    $bannerUrl = 'uploads/products/' . $fileName;
                }
            }

            $pdo->prepare("UPDATE products SET name = ?, sort_order = ?, image_url = ?, banner_url = ?, logo_url = ?, download_link = ?, setup_link = ?, app_download_url = ?, description = ?, tags = ?, category = ?, is_pinned = ?, is_active = ? WHERE id = ? AND subdomain_id = ?")
                ->execute([$name, $sortOrder, $bannerUrl, $bannerUrl, $logoUrl, $downloadLink, $setupLink, $appDownloadUrl, $description, $tags, $category, $isPinned, $isActive, $prodId, $subadminTenantId]);

        // 🔧 Maintenance: Online আনচেক = মেইনটেনেন্স (স্টোরে লাল কার্ড + নোটিশ দেখাবে)
        try {
            $maintMsgEdit = trim($_POST['maintenance_msg'] ?? '');
            $pdo->prepare("UPDATE products SET is_maintenance = ?, maintenance_msg = ? WHERE id = ? AND subdomain_id = ?")
                ->execute([$isActive ? 0 : 1, $maintMsgEdit !== '' ? $maintMsgEdit : null, $prodId, $subadminTenantId]);
        } catch (Throwable $e) {}
        // 🔄 Reset permission + Reset API ID
        try {
            if (isset($_POST['reset_form_present'])) {
                $pdo->prepare("UPDATE products SET reset_enabled = ?, km_api_id = ? WHERE id = ? AND subdomain_id = ?")
                    ->execute([isset($_POST['reset_enabled']) ? 1 : 0, max(0, (int)($_POST['km_api_id'] ?? 0)), $prodId, $subadminTenantId]);
            }
        } catch (Throwable $e) {}

            if (!empty($_POST['plan_user_price']) && is_array($_POST['plan_user_price'])) {
                $upPlan = $pdo->prepare("UPDATE product_plans SET user_price = ?, regular_price = ?, reseller_price = ?, reward_coins = ?, coin_price = ? WHERE id = ? AND product_id = ? AND subdomain_id = ?");
                foreach ($_POST['plan_user_price'] as $planId => $uPrice) {
                    $uP = max(0.01, (float)$uPrice);
                    // রিসেলার প্রাইস ফাঁকা/না থাকলে আগের রিসেলার প্রাইস রাখা হবে (আগে প্রতি সেভে Customer এর 80% বসে যেত)
                $rPRaw = trim((string)($_POST['plan_reseller_price'][$planId] ?? ''));
                if (is_numeric($rPRaw) && (float)$rPRaw > 0) { $rP = round((float)$rPRaw, 2); }
                else { $stOldR = $pdo->prepare("SELECT reseller_price FROM product_plans WHERE id = ? LIMIT 1"); $stOldR->execute([(int)$planId]); $rP = (float)($stOldR->fetchColumn() ?: 0); }
                    $rCoins = isset($_POST['plan_reward_coins'][$planId]) ? max(0, (int)$_POST['plan_reward_coins'][$planId]) : 0;
                    $cPrice = isset($_POST['plan_coin_price'][$planId]) ? max(0, (int)$_POST['plan_coin_price'][$planId]) : 0;
                    $upPlan->execute([$uP, $uP, $rP, $rCoins, $cPrice, (int)$planId, $prodId, $subadminTenantId]);
                }
            }
            $toast = ['type' => 'success', 'msg' => 'Product updated successfully!'];
        }
    }

    if ($act === 'add_single_plan') {
        $prodId = (int)$_POST['product_id'];
        $title = trim($_POST['plan_title'] ?? '1 Day');
        $uPrice = (float)($_POST['user_price'] ?? 1.00);
        $rPrice = (float)($_POST['reseller_price'] ?? round($uPrice * 0.8, 2));
        $apiId = (int)($_POST['api_plan_id'] ?? 0);
        $rewardCoins = max(0, (int)($_POST['reward_coins'] ?? 0));
        $coinPrice = max(0, (int)($_POST['coin_price'] ?? 0));

        $pdo->prepare("INSERT INTO product_plans (product_id, duration_days, plan_title, reseller_price, user_price, regular_price, api_plan_id, reward_coins, coin_price, subdomain_id) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$prodId, $title, $rPrice, $uPrice, $uPrice, $apiId, $rewardCoins, $coinPrice, $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => 'Plan with Coins attached!'];
    }

    if ($act === 'save_stock_keys') {
        $productId = (int)$_POST['stock_product_id'];
        $planId    = (int)$_POST['stock_plan_id'];
        $rawKeys   = trim($_POST['bulk_keys'] ?? '');
        if ($productId > 0 && $planId > 0 && !empty($rawKeys)) {
            $lines = preg_split('/\r\n|\r|\n/', $rawKeys);
            $inserted = 0;
            $stmtStock = $pdo->prepare("INSERT INTO stock_keys (product_id, plan_id, license_key, status, subdomain_id) VALUES (?, ?, ?, 'available', ?)");
            foreach ($lines as $line) {
                $cleanKey = trim($line);
                if (!empty($cleanKey)) {
                    $stmtStock->execute([$productId, $planId, $cleanKey, $subadminTenantId]);
                    $inserted++;
                }
            }
            $toast = ['type' => 'success', 'msg' => "Added $inserted keys to vault!"];
        }
    }

    if ($act === 'delete_single_key') {
        $keyId = (int)$_POST['key_id'];
        $pdo->prepare("DELETE FROM stock_keys WHERE id = ? AND subdomain_id = ?")->execute([$keyId, $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => 'Key deleted!'];
    }

    // সাব-স্টোর কুপন সেভ
    // 🎟️ COUPON SAVE (CREATE / EDIT) — সময়সীমা, কতজন ব্যবহার করতে পারবে, ইউজার প্রতি লিমিট
    if ($act === 'save_coupon') {
        $cpScope    = $subadminTenantId;
        $cpId       = (int)($_POST['coupon_id'] ?? 0);
        $code       = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', trim($_POST['coupon_code'] ?? '')));
        $discType   = in_array($_POST['discount_type'] ?? '', ['percent', 'fixed'], true) ? $_POST['discount_type'] : 'percent';
        $discVal    = max(0.01, (float)($_POST['discount_value'] ?? 0));
        if ($discType === 'percent') $discVal = min(100.0, $discVal);
        $targetType = in_array($_POST['target_type'] ?? '', ['all', 'specific'], true) ? $_POST['target_type'] : 'all';
        $prodIds    = ($targetType === 'specific' && !empty($_POST['product_ids']) && is_array($_POST['product_ids'])) ? implode(',', array_map('intval', $_POST['product_ids'])) : '';
        $minSpend   = max(0.0, (float)($_POST['min_spend'] ?? 0));
        $maxDisc    = max(0.0, (float)($_POST['max_discount'] ?? 0));
        $maxUses    = max(0, (int)($_POST['max_uses'] ?? 0));
        $perUser    = max(0, (int)($_POST['per_user_limit'] ?? 1));
        $userRoleC  = in_array($_POST['user_role'] ?? 'all', ['all', 'customer', 'reseller'], true) ? $_POST['user_role'] : 'all';
        $title      = trim($_POST['title'] ?? '');
        $startsAt   = !empty($_POST['starts_at']) ? date('Y-m-d H:i:s', strtotime($_POST['starts_at'])) : null;
        $expiresAt  = !empty($_POST['expires_at']) ? date('Y-m-d H:i:s', strtotime($_POST['expires_at'])) : null;
        $expiryDate = $expiresAt ? substr($expiresAt, 0, 10) : null;
        $isActiveC  = isset($_POST['is_active']) ? 1 : 0;

        if ($code === '') {
            $toast = ['type' => 'error', 'msg' => 'Coupon code is required!'];
        } elseif ($startsAt && $expiresAt && strtotime($expiresAt) <= strtotime($startsAt)) {
            $toast = ['type' => 'error', 'msg' => 'End time must be after start time!'];
        } else {
            try {
                $dup = $pdo->prepare("SELECT id FROM coupons WHERE UPPER(code) = ? AND subdomain_id = ? AND id != ? LIMIT 1");
                $dup->execute([$code, $cpScope, $cpId]);
                if ($dup->fetch()) {
                    $toast = ['type' => 'error', 'msg' => "Coupon '$code' already exists!"];
                } elseif ($cpId > 0) {
                    $pdo->prepare("UPDATE coupons SET code = ?, title = ?, discount_type = ?, discount_value = ?, max_discount = ?, target_type = ?, product_ids = ?, min_spend = ?, max_uses = ?, per_user_limit = ?, user_role = ?, starts_at = ?, expires_at = ?, expiry_date = ?, is_active = ? WHERE id = ? AND subdomain_id = ?")
                        ->execute([$code, $title ?: null, $discType, $discVal, $maxDisc, $targetType, $prodIds, $minSpend, $maxUses, $perUser, $userRoleC, $startsAt, $expiresAt, $expiryDate, $isActiveC, $cpId, $cpScope]);
                    $toast = ['type' => 'success', 'msg' => "Coupon '$code' updated!"];
                } else {
                    $pdo->prepare("INSERT INTO coupons (subdomain_id, code, title, discount_type, discount_value, max_discount, target_type, product_ids, min_spend, max_uses, used_count, per_user_limit, user_role, starts_at, expires_at, expiry_date, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)")
                        ->execute([$cpScope, $code, $title ?: null, $discType, $discVal, $maxDisc, $targetType, $prodIds, $minSpend, $maxUses, $perUser, $userRoleC, $startsAt, $expiresAt, $expiryDate, $isActiveC]);
                    $toast = ['type' => 'success', 'msg' => "Coupon '$code' created!"];
                }
            } catch (Throwable $e) {
                $toast = ['type' => 'error', 'msg' => 'Coupon error: ' . $e->getMessage()];
            }
        }
    }

    if ($act === 'toggle_coupon') {
        $cpId = (int)($_POST['coupon_id'] ?? 0);
        if ($cpId > 0) {
            $pdo->prepare("UPDATE coupons SET is_active = IF(is_active = 1, 0, 1) WHERE id = ? AND subdomain_id = ?")->execute([$cpId, $subadminTenantId]);
            $toast = ['type' => 'success', 'msg' => 'Coupon status changed!'];
        }
    }

    if ($act === 'delete_coupon') {
        $cpId = (int)($_POST['coupon_id'] ?? 0);
        if ($cpId > 0) {
            $pdo->prepare("DELETE FROM coupons WHERE id = ? AND subdomain_id = ?")->execute([$cpId, $subadminTenantId]);
            try { $pdo->prepare("DELETE FROM coupon_usages WHERE coupon_id = ?")->execute([$cpId]); } catch (Throwable $e) {}
            $toast = ['type' => 'success', 'msg' => 'Coupon deleted!'];
        }
    }

    // 🔄 PRODUCT RESET PERMISSION TOGGLE
    if ($act === 'toggle_product_reset') {
        $prodId = (int)($_POST['product_id'] ?? 0);
        $turnOn = ($_POST['reset_state'] ?? '') === '1';
        if ($prodId > 0) {
            $pdo->prepare("UPDATE products SET reset_enabled = ? WHERE id = ? AND subdomain_id = ?")->execute([$turnOn ? 1 : 0, $prodId, $subadminTenantId]);
            $toast = ['type' => 'success', 'msg' => $turnOn ? '🔄 HWID Reset ENABLED for this product' : '⛔ HWID Reset DISABLED for this product'];
        }
    }
    // 💲 QUICK PLAN PRICES (Products → প্রোডাক্টের ভেতরে Customer $ / Reseller $)
    if ($act === 'save_plan_prices_quick') {
        $prodId = (int)($_POST['product_id'] ?? 0);
        $userPrices = $_POST['qp_user'] ?? []; $resPrices = $_POST['qp_res'] ?? [];
        $cnt = 0;
        if ($prodId > 0 && is_array($userPrices)) {
            $upQ = $pdo->prepare("UPDATE product_plans SET user_price = ?, regular_price = ?, reseller_price = ? WHERE id = ? AND product_id = ? AND subdomain_id = ?");
            foreach ($userPrices as $plId => $uRaw) {
                if (!is_numeric($uRaw) || (float)$uRaw <= 0) continue;
                $uP = round((float)$uRaw, 2);
                $rRaw = trim((string)($resPrices[$plId] ?? ''));
                $rP = (is_numeric($rRaw) && (float)$rRaw > 0) ? round((float)$rRaw, 2) : 0.00;
                $upQ->execute([$uP, $uP, $rP, (int)$plId, $prodId, $subadminTenantId]);
                $cnt++;
            }
        }
        $toast = ['type' => 'success', 'msg' => "Prices saved for $cnt plan(s) — Customer & Reseller!"];
    }

    // 🔧 QUICK PRODUCT MAINTENANCE TOGGLE (প্রোডাক্ট লিস্ট থেকে এক ক্লিকে)
    if ($act === 'toggle_product_maintenance') {
        $prodId   = (int)($_POST['product_id'] ?? 0);
        $turnOn   = ($_POST['maint_state'] ?? '') === '1';
        $maintMsg = trim($_POST['maintenance_msg'] ?? '');
        if ($prodId > 0) {
            $pdo->prepare("UPDATE products SET is_maintenance = ?, is_active = ?, maintenance_msg = ? WHERE id = ? AND subdomain_id = ?")
                ->execute([$turnOn ? 1 : 0, $turnOn ? 0 : 1, $maintMsg !== '' ? $maintMsg : null, $prodId, $subadminTenantId]);
            $toast = ['type' => 'success', 'msg' => $turnOn ? '🔧 Product is now in MAINTENANCE (shows red on store)' : '✅ Product is back ONLINE'];
        }
    }

    // সাব-স্টোর প্রোডাক্ট মেইনটেনেন্স মেসেজ আপডেট
    if ($act === 'update_product_maintenance_msg') {
        $prodId = (int)($_POST['product_id'] ?? 0);
        $maintMsg = trim($_POST['maintenance_msg'] ?? '');
        if ($prodId > 0) {
            $pdo->prepare("UPDATE products SET maintenance_msg = ? WHERE id = ? AND subdomain_id = ?")->execute([$maintMsg ?: null, $prodId, $subadminTenantId]);
            $toast = ['type' => 'success', 'msg' => 'Product notice updated!'];
        }
    }

    if ($act === 'delete_product_entire') {
        $prodId = (int)$_POST['product_id'];
        $pdo->prepare("DELETE FROM stock_keys WHERE product_id = ? AND subdomain_id = ?")->execute([$prodId, $subadminTenantId]);
        $pdo->prepare("DELETE FROM product_plans WHERE product_id = ? AND subdomain_id = ?")->execute([$prodId, $subadminTenantId]);
        $pdo->prepare("DELETE FROM products WHERE id = ? AND subdomain_id = ?")->execute([$prodId, $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => 'Product removed!'];
    }

    // ====================================================================
    // SUBDOMAIN ADVANCED DISCOUNT ENGINE (ALL PRODUCTS / CUSTOMERS / RESELLERS)
    // ====================================================================
    if ($act === 'apply_bulk_product_discount') {
        $discPercent = max(0.0, min(99.0, (float)($_POST['discount_percent'] ?? 0)));
        try {
            $plansStmt = $pdo->prepare("SELECT pp.id, pp.regular_price, pp.user_price 
                                        FROM product_plans pp 
                                        JOIN products p ON pp.product_id = p.id 
                                        WHERE p.subdomain_id = ?");
            $plansStmt->execute([$subadminTenantId]);
            $plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);

            $stmtUp = $pdo->prepare("UPDATE product_plans SET user_price = ?, discount_percent = ? WHERE id = ? AND subdomain_id = ?");
            foreach ($plans as $pl) {
                $regular = (float)$pl['regular_price'] > 0 ? (float)$pl['regular_price'] : (float)$pl['user_price'];
                $newPrice = ($discPercent > 0) ? max(0.01, round($regular * (1 - ($discPercent / 100)), 2)) : $regular;
                $stmtUp->execute([$newPrice, $discPercent, (int)$pl['id'], $subadminTenantId]);
            }

            syncTenantStackedPricesForAllUsers($pdo, $subadminTenantId);
            $toast = ['type' => 'success', 'msg' => ($discPercent > 0) ? "Applied $discPercent% discount to all products!" : "Product discounts reset to 0%!"];
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'reset_all_product_discounts') {
        try {
            $pdo->prepare("UPDATE product_plans pp 
                           JOIN products p ON pp.product_id = p.id 
                           SET pp.user_price = pp.regular_price, pp.discount_percent = 0.00 
                           WHERE p.subdomain_id = ? AND pp.regular_price > 0")->execute([$subadminTenantId]);
            syncTenantStackedPricesForAllUsers($pdo, $subadminTenantId);
            $toast = ['type' => 'success', 'msg' => "All product discounts turned off & regular prices restored!"];
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'apply_single_plan_discount') {
        $planId      = (int)($_POST['plan_id'] ?? 0);
        $discPercent = max(0.0, min(99.0, (float)($_POST['discount_percent'] ?? 0)));

        if ($planId > 0) {
            $plStmt = $pdo->prepare("SELECT id, regular_price, user_price FROM product_plans WHERE id = ? AND subdomain_id = ?");
            $plStmt->execute([$planId, $subadminTenantId]);
            $pl = $plStmt->fetch(PDO::FETCH_ASSOC);

            if ($pl) {
                $regular = (float)$pl['regular_price'] > 0 ? (float)$pl['regular_price'] : (float)$pl['user_price'];
                
                // নিশ্চিত করা যে regular_price সঠিক মূল্যে সেভ আছে
                if ((float)$pl['regular_price'] <= 0) {
                    $pdo->prepare("UPDATE product_plans SET regular_price = ? WHERE id = ? AND subdomain_id = ?")->execute([$regular, $planId, $subadminTenantId]);
                }

                // ০% ডিসকাউন্ট দিলে আসল রেগুলার প্রাইস রিস্টোর করবে
                $newPrice = ($discPercent > 0) ? max(0.01, round($regular * (1 - ($discPercent / 100)), 2)) : $regular;
                $pdo->prepare("UPDATE product_plans SET user_price = ?, discount_percent = ? WHERE id = ? AND subdomain_id = ?")
                    ->execute([$newPrice, $discPercent, $planId, $subadminTenantId]);

                syncTenantStackedPricesForAllUsers($pdo, $subadminTenantId);
                $toast = ['type' => 'success', 'msg' => ($discPercent > 0 ? "Plan discount updated to $discPercent%!" : "Discount reset to 0%! Regular price ($$regular USD) restored.")];
            }
        }
    }

    if ($act === 'apply_bulk_user_discount') {
        $targetRole  = in_array($_POST['target_role'] ?? '', ['customer', 'reseller']) ? $_POST['target_role'] : 'customer';
        $discPercent = max(0.0, min(99.0, (float)($_POST['discount_percent'] ?? 0)));

        try {
            if ($targetRole === 'customer') {
                $stmt = $pdo->prepare("UPDATE users SET discount_percent = ? WHERE subdomain_id = ? AND (LOWER(TRIM(COALESCE(role, 'customer'))) = 'customer' OR role IS NULL OR role = '')");
            } else {
                $stmt = $pdo->prepare("UPDATE users SET discount_percent = ? WHERE subdomain_id = ? AND LOWER(TRIM(role)) = 'reseller'");
            }
            $stmt->execute([$discPercent, $subadminTenantId]);

            syncTenantStackedPricesForAllUsers($pdo, $subadminTenantId, $targetRole);
            $roleLabel = ucfirst($targetRole);
            $toast = ['type' => 'success', 'msg' => ($discPercent > 0) ? "Applied $discPercent% extra discount to all {$roleLabel}s (Stacked)!" : "Discounts reset to 0% for all {$roleLabel}s!"];
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'reset_all_user_discounts') {
        $targetRole = in_array($_POST['target_role'] ?? '', ['customer', 'reseller']) ? $_POST['target_role'] : 'customer';
        try {
            if ($targetRole === 'customer') {
                $pdo->prepare("UPDATE users SET discount_percent = 0.00 WHERE subdomain_id = ? AND (LOWER(TRIM(COALESCE(role, 'customer'))) = 'customer' OR role IS NULL OR role = '')")->execute([$subadminTenantId]);
            } else {
                $pdo->prepare("UPDATE users SET discount_percent = 0.00 WHERE subdomain_id = ? AND LOWER(TRIM(role)) = 'reseller'")->execute([$subadminTenantId]);
            }
            syncTenantStackedPricesForAllUsers($pdo, $subadminTenantId, $targetRole);
            $toast = ['type' => 'success', 'msg' => "All {$targetRole} discounts turned off successfully!"];
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'apply_single_user_discount') {
        $targetUid   = (int)($_POST['target_user_id'] ?? 0);
        $discPercent = max(0.0, min(99.0, (float)($_POST['discount_percent'] ?? 0)));

        if ($targetUid > 0) {
            $pdo->prepare("UPDATE users SET discount_percent = ? WHERE id = ? AND subdomain_id = ?")->execute([$discPercent, $targetUid, $subadminTenantId]);
            syncTenantStackedPricesForUser($pdo, $targetUid, $subadminTenantId);
            $toast = ['type' => 'success', 'msg' => "User discount set to $discPercent% (Stacked with plan discount)!"];
        }
    }

    if ($act === 'reset_single_user_discount') {
        $targetUid = (int)($_POST['target_user_id'] ?? 0);
        if ($targetUid > 0) {
            $pdo->prepare("UPDATE users SET discount_percent = 0.00 WHERE id = ? AND subdomain_id = ?")->execute([$targetUid, $subadminTenantId]);
            $pdo->prepare("DELETE FROM user_custom_prices WHERE user_id = ?")->execute([$targetUid]);
            $toast = ['type' => 'success', 'msg' => "Discount removed for this user!"];
        }
    }

    // ====================================================================
    // SUBDOMAIN DYNAMIC PAYMENT METHODS CRUD (BANNER, QR, TEXTS & TOGGLES)
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
                $stmtExt = $pdo->prepare("SELECT * FROM payment_methods WHERE id = ? AND subdomain_id = ? LIMIT 1");
                $stmtExt->execute([$methodId, $subadminTenantId]);
                $existing = $stmtExt->fetch(PDO::FETCH_ASSOC);
            }

            $logoUrl   = $existing['logo_url'] ?? null;
            $bannerUrl = $existing['banner_url'] ?? null;
            $qrUrl     = $existing['qr_image_url'] ?? null;

            if (!empty($_FILES['logo_file']['name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) {
                    $fileName = 'pm_sub_' . $subadminTenantId . '_logo_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $gatewayUploadDir . $fileName)) {
                        $logoUrl = 'uploads/gateways/' . $fileName;
                    }
                }
            }

            if (!empty($_FILES['banner_file']['name']) && $_FILES['banner_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['banner_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $fileName = 'pm_sub_' . $subadminTenantId . '_banner_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['banner_file']['tmp_name'], $gatewayUploadDir . $fileName)) {
                        $bannerUrl = 'uploads/gateways/' . $fileName;
                    }
                }
            }

            if (!empty($_FILES['qr_file']['name']) && $_FILES['qr_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['qr_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) {
                    $fileName = 'pm_sub_' . $subadminTenantId . '_qr_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['qr_file']['tmp_name'], $gatewayUploadDir . $fileName)) {
                        $qrUrl = 'uploads/gateways/' . $fileName;
                    }
                }
            }

            try {
                if ($methodId > 0 && $existing) {
                    $stmt = $pdo->prepare("UPDATE payment_methods SET name = ?, method_key = ?, account_number = ?, account_type = ?, instructions = ?, logo_url = ?, banner_url = ?, qr_image_url = ?, is_active = ?, allow_add_fund = ?, allow_checkout = ?, sort_order = ? WHERE id = ? AND subdomain_id = ?");
                    $stmt->execute([$name, $methodKey, $accountNumber, $accountType, $instructions, $logoUrl, $bannerUrl, $qrUrl, $isActive, $allowAddFund, $allowCheckout, $sortOrder, $methodId, $subadminTenantId]);
                    $toast = ['type' => 'success', 'msg' => "$name channel updated for your store!"];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO payment_methods (subdomain_id, name, method_key, account_number, account_type, instructions, logo_url, banner_url, qr_image_url, is_active, allow_add_fund, allow_checkout, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$subadminTenantId, $name, $methodKey, $accountNumber, $accountType, $instructions, $logoUrl, $bannerUrl, $qrUrl, $isActive, $allowAddFund, $allowCheckout, $sortOrder]);
                    $toast = ['type' => 'success', 'msg' => "$name payment channel created!"];
                }
            } catch (Throwable $e) {
                $toast = ['type' => 'error', 'msg' => 'Database error: ' . $e->getMessage()];
            }
        }
    }

    if ($act === 'delete_payment_method') {
        $methodId = (int)$_POST['method_id'];
        $pdo->prepare("DELETE FROM payment_methods WHERE id = ? AND subdomain_id = ?")->execute([$methodId, $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => 'Payment Method deleted!'];
    }

    if ($act === 'toggle_payment_method_status') {
        $methodId = (int)$_POST['method_id'];
        $curr = (int)$_POST['current_status'];
        $new = ($curr === 1) ? 0 : 1;
        $pdo->prepare("UPDATE payment_methods SET is_active = ? WHERE id = ? AND subdomain_id = ?")->execute([$new, $methodId, $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => "Channel status set to " . ($new === 1 ? 'ACTIVE' : 'OFFLINE')];
    }

    // ====================================================================
    // SUBDOMAIN SNZPAY AUTO-PAYMENT API RULES & TEST PING
    // ====================================================================
    if ($act === 'save_snzpay_rules') {
        $apiUrl     = trim($_POST['snzpay_api_url'] ?? 'https://snzpay.digital/api/verify_payment.php');
        $licenseKey = trim($_POST['snzpay_license_key'] ?? '');
        $autoVerify = isset($_POST['snzpay_auto_verify']) ? '1' : '0';
        $autoPayOn  = isset($_POST['auto_payment_on']) ? '1' : '0';

        saveTenantSetting($pdo, $subadminTenantId, 'snzpay_api_url', $apiUrl);
        saveTenantSetting($pdo, $subadminTenantId, 'snzpay_license_key', $licenseKey);
        saveTenantSetting($pdo, $subadminTenantId, 'snzpay_auto_verify', $autoVerify);
        saveTenantSetting($pdo, $subadminTenantId, 'auto_payment_on', $autoPayOn);

        $settings['snzpay_api_url']     = $apiUrl;
        $settings['snzpay_license_key'] = $licenseKey;
        $settings['snzpay_auto_verify'] = $autoVerify;
        $settings['auto_payment_on']    = $autoPayOn;

        $toast = ['type' => 'success', 'msg' => 'Store SNZPay & Auto Payment configuration saved!'];
    }

    if ($act === 'test_snzpay_connection') {
        $testRes = subadmin_snzpay_verify_payment($pdo, $subadminTenantId, "TEST_PING_" . rand(1000, 9999), "BKASH", 10.0);
        if ($testRes['status'] === 'success') {
            $toast = ['type' => 'success', 'msg' => "SNZPay Gateway Connected! " . $testRes['message']];
        } else {
            $toast = ['type' => 'error', 'msg' => "SNZPay Status: " . $testRes['message']];
        }
    }

    // Spin segments actions
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
                $stmt = $pdo->prepare("UPDATE subdomain_spin_segments SET title = ?, spend_target = ?, bonus_amount = ?, probability = ?, color = ?, sort_order = ?, is_active = ? WHERE id = ? AND subdomain_id = ?");
                $stmt->execute([$title, $spendTarget, $bonusAmount, $probability, $color, $sortOrder, $isActive, $segId, $subadminTenantId]);
                $toast = ['type' => 'success', 'msg' => 'Segment updated!'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO subdomain_spin_segments (subdomain_id, title, spend_target, bonus_amount, probability, color, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$subadminTenantId, $title, $spendTarget, $bonusAmount, $probability, $color, $sortOrder, $isActive]);
                $toast = ['type' => 'success', 'msg' => 'Segment added!'];
            }
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'delete_spin_segment') {
        $segId = (int)$_POST['segment_id'];
        $pdo->prepare("DELETE FROM subdomain_spin_segments WHERE id = ? AND subdomain_id = ?")->execute([$segId, $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => 'Segment deleted!'];
    }

    if ($act === 'update_spin_wheel_settings') {
        $spinEnabled = isset($_POST['spin_wheel_enabled']) ? '1' : '0';
        $spinCost    = max(0.00, (float)($_POST['spin_cost'] ?? 0));
        $dailyLimit  = max(1, (int)($_POST['spin_daily_limit'] ?? 5));
        $expiryHours = max(1, (int)($_POST['spin_offer_duration_hours'] ?? 24));

        saveTenantSetting($pdo, $subadminTenantId, 'spin_wheel_enabled', $spinEnabled);
        saveTenantSetting($pdo, $subadminTenantId, 'spin_cost', (string)$spinCost);
        saveTenantSetting($pdo, $subadminTenantId, 'spin_daily_limit', (string)$dailyLimit);
        saveTenantSetting($pdo, $subadminTenantId, 'spin_offer_duration_hours', (string)$expiryHours);
        $toast = ['type' => 'success', 'msg' => 'Spin rules saved!'];
    }

    if ($act === 'add_bot_admin') {
        $tgId = trim($_POST['telegram_id'] ?? '');
        $adminName = trim($_POST['admin_name'] ?? 'Admin');
        if (!empty($tgId)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO subdomain_admins (subdomain_id, telegram_id, admin_name) VALUES (?, ?, ?)");
                $stmt->execute([$subadminTenantId, $tgId, $adminName]);
                $toast = ['type' => 'success', 'msg' => "Staff admin added!"];
            } catch (Throwable $e) {
                $toast = ['type' => 'error', 'msg' => "Already registered."];
            }
        }
    }

    if ($act === 'remove_bot_admin') {
        $tgId = trim($_POST['telegram_id'] ?? '');
        $pdo->prepare("DELETE FROM subdomain_admins WHERE telegram_id = ? AND subdomain_id = ?")->execute([$tgId, $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => "Admin removed!"];
    }

    if ($act === 'save_firebase_settings') {
        $fbKeys = ['firebase_api_key', 'firebase_auth_domain', 'firebase_project_id', 'firebase_storage_bucket', 'firebase_messaging_sender_id', 'firebase_app_id'];
        foreach ($fbKeys as $k) {
            $val = trim($_POST[$k] ?? '');
            saveTenantSetting($pdo, $subadminTenantId, $k, $val);
            $settings[$k] = $val;
        }
        $toast = ['type' => 'success', 'msg' => 'Firebase Web SDK Configuration Saved!'];
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
                $stmt = $pdo->prepare("UPDATE subdomain_deposit_bonuses SET min_deposit = ?, bonus_amount = ?, bonus_type = ?, title = ?, claim_limit = ?, sort_order = ?, is_active = ? WHERE id = ? AND subdomain_id = ?");
                $stmt->execute([$minDeposit, $bonusAmount, $bonusType, $title, $claimLimit, $sortOrder, $isActive, $bonusId, $subadminTenantId]);
                $toast = ['type' => 'success', 'msg' => 'Reward tier updated!'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO subdomain_deposit_bonuses (subdomain_id, min_deposit, bonus_amount, bonus_type, title, claim_limit, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$subadminTenantId, $minDeposit, $bonusAmount, $bonusType, $title, $claimLimit, $sortOrder, $isActive]);
                $toast = ['type' => 'success', 'msg' => 'Reward tier added!'];
            }
        } catch (Throwable $e) {
            $toast = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    }

    if ($act === 'delete_deposit_bonus') {
        $bonusId = (int)$_POST['bonus_id'];
        $pdo->prepare("DELETE FROM subdomain_deposit_bonuses WHERE id = ? AND subdomain_id = ?")->execute([$bonusId, $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => 'Reward tier deleted!'];
    }

    if ($act === 'manage_trx') {
        $trxId  = (int)($_POST['trx_db_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $stT = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND subdomain_id = ? LIMIT 1");
        $stT->execute([$trxId, $subadminTenantId]);
        $trx = $stT->fetch(PDO::FETCH_ASSOC);
        if ($trx && $trx['status'] === 'pending') {
            $isOrder = vpIsOrderTrx($trx);
            if ($status === 'approved' && $isOrder) {
                // 🛡️ অর্ডার: কি ডেলিভারি হবে — ব্যালেন্সে টাকা যোগ হবে না
                $res = vpAdminDeliverOrder($pdo, $trx, $subadminTenantId, $settings, 'SUB:' . ($subadminSlug ?? 'store'));
                $toast = $res['ok']
                    ? ['type' => 'success', 'msg' => '✅ Order approved — ' . count($res['keys']) . ' key(s) delivered to the customer (' . $res['product'] . ')']
                    : ['type' => 'error', 'msg' => '⚠️ ' . $res['message']];
            } elseif ($status === 'approved' || ($status === 'credit_wallet' && $isOrder)) {
                // ডিপোজিট (অথবা অ্যাডমিন নিজে "Credit Wallet" বাছলে) → ব্যালেন্সে যোগ
                $up = $pdo->prepare("UPDATE transactions SET status = 'approved' WHERE id = ? AND subdomain_id = ? AND status = 'pending'");
                $up->execute([$trxId, $subadminTenantId]);
                if ($up->rowCount() === 1) {
                    $bonusFloat = (!empty($trx['bonus_amount']) && (float)$trx['bonus_amount'] > 0) ? round((float)$trx['bonus_amount'] / $effectiveBdtRate, 2) : 0.00;
                    $totalCredit = max(0.0, (float)$trx['amount'] + ($isOrder ? 0.0 : $bonusFloat));
                    $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ? AND subdomain_id = ?")->execute([$totalCredit, $trx['user_id'], $subadminTenantId]);
                    $toast = ['type' => 'success', 'msg' => $isOrder ? 'Order amount credited to customer wallet.' : 'Deposit approved & balance added!'];
                }
            } elseif ($status === 'rejected') {
                $pdo->prepare("UPDATE transactions SET status = 'rejected' WHERE id = ? AND subdomain_id = ? AND status = 'pending'")->execute([$trxId, $subadminTenantId]);
                $toast = ['type' => 'success', 'msg' => ($isOrder ? 'Order' : 'Deposit') . ' rejected.'];
            }
        }
    }

    if ($act === 'update_gateway_rules') {
        $keys = [
            'usdt_rate', 'inr_rate', 'binance_market_rate', 'binance_id', 'payment_banner_url',
            'deposit_system_status', 'min_deposit_limit', 'max_deposit_limit',
            'telegram_tutorial_link', 'auto_payment_on', 'snzpay_api_url', 'snzpay_license_key', 'snzpay_auto_verify'
        ];

        foreach ($keys as $k) {
            if (!array_key_exists($k, $_POST) && in_array($k, ['usdt_rate', 'inr_rate', 'binance_market_rate'], true)) continue;
            $val = trim($_POST[$k] ?? '0');
            if (in_array($k, ['usdt_rate', 'inr_rate', 'binance_market_rate'], true)) $val = (string)max(1.0, (float)$val);
            saveTenantSetting($pdo, $subadminTenantId, $k, $val);
            $settings[$k] = $val;
        }

        // Subdomain Specific Payment Banner Upload
        if (!empty($_FILES['payment_banner_file']['name']) && $_FILES['payment_banner_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['payment_banner_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $fileName = 'pm_sub_' . $subadminTenantId . '_mainbanner_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['payment_banner_file']['tmp_name'], $bannerUploadDir . $fileName)) {
                    $bannerPath = 'uploads/banners/' . $fileName;
                    saveTenantSetting($pdo, $subadminTenantId, 'payment_banner_url', $bannerPath);
                    $settings['payment_banner_url'] = $bannerPath;
                }
            }
        }

        $effectiveBdtRate = max(1.0, (float)($settings['usdt_rate'] ?? 125.0));
        $effectiveInrRate = max(1.0, (float)($settings['inr_rate'] ?? 88.0));
        $toast = ['type' => 'success', 'msg' => 'Store payment gateways & rules saved!'];
    }

    if ($act === 'update_branding_settings') {
        $newSiteName = trim($_POST['site_name'] ?? '') ?: ($activeTenant['site_title'] ?? 'My Store');
        $siteLogoPath = $settings['site_logo'] ?? '';
        $loadingLogoPath = $settings['loading_logo'] ?? '';
        $appDownloadUrl = trim($_POST['app_download_url'] ?? '');

        if (!empty($_POST['delete_site_logo'])) $siteLogoPath = '';
        if (!empty($_POST['delete_loading_logo'])) $loadingLogoPath = '';

        if (!empty($_FILES['site_logo_file']['name']) && $_FILES['site_logo_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['site_logo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) {
                $fileName = 'site_logo_sub_' . $subadminTenantId . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['site_logo_file']['tmp_name'], $siteUploadDir . $fileName)) {
                    $siteLogoPath = 'uploads/site/' . $fileName;
                }
            }
        }

        if (!empty($_FILES['loading_logo_file']['name']) && $_FILES['loading_logo_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['loading_logo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) {
                $fileName = 'loading_logo_sub_' . $subadminTenantId . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['loading_logo_file']['tmp_name'], $siteUploadDir . $fileName)) {
                    $loadingLogoPath = 'uploads/site/' . $fileName;
                }
            }
        }

        $supTg   = trim($_POST['support_telegram'] ?? '');
        $supWa   = trim($_POST['support_whatsapp'] ?? '');
        $maintTg = trim($_POST['maintenance_telegram_link'] ?? '');

        // সাব-ডোমেইনের নিজস্ব টেলিগ্রাম ও হোয়াটসঅ্যাপ নম্বর সরাসরি subdomains টেবিলে আপডেট করা
        $pdo->prepare("UPDATE subdomains SET site_title = ?, site_logo = ?, loading_logo = ?, apk_url = ?, support_telegram = ?, support_whatsapp = ? WHERE id = ?")
            ->execute([$newSiteName, $siteLogoPath, $loadingLogoPath, $appDownloadUrl, $supTg, $supWa, $subadminTenantId]);
        
        $brandArray = [
            'site_name'                    => $newSiteName,
            'app_download_url'             => $appDownloadUrl,
            'site_logo'                    => $siteLogoPath,
            'loading_logo'                 => $loadingLogoPath,
            'support_telegram'             => $supTg,
            'support_whatsapp'             => $supWa,
            'maintenance_telegram_link'    => $maintTg,
            'site_maintenance_mode'        => isset($_POST['site_maintenance_mode']) ? '1' : '0',
            'site_maintenance_msg'         => trim($_POST['site_maintenance_msg'] ?? 'Store upgrade in progress.'),
            'update_channel_popup_enabled' => isset($_POST['update_channel_popup_enabled']) ? '1' : '0',
            'update_channel_title'         => trim($_POST['update_channel_title'] ?? 'Join Channel'),
            'update_channel_msg'           => trim($_POST['update_channel_msg'] ?? ''),
            'update_channel_link'          => trim($_POST['update_channel_link'] ?? ''),
            'footer_copyright_text'        => trim($_POST['footer_copyright_text'] ?? ''),
            'footer_telegram_link'         => $supTg ?: trim($_POST['footer_telegram_link'] ?? ''),
            'footer_whatsapp_link'         => $supWa ?: trim($_POST['footer_whatsapp_link'] ?? ''),
            'footer_youtube_link'          => trim($_POST['footer_youtube_link'] ?? '')
        ];

        foreach ($brandArray as $k => $v) {
            saveTenantSetting($pdo, $subadminTenantId, $k, $v);
            $settings[$k] = $v;
        }
        $toast = ['type' => 'success', 'msg' => 'Store branding & support settings saved!'];
    }

    // --- SUB-ADMIN TELEGRAM NOTIFICATION BOT CONFIG SAVE ---
    if ($act === 'save_telegram_bot_config') {
        $botToken   = trim($_POST['bot_token'] ?? '');
        $chatId     = trim($_POST['chat_id'] ?? '');
        $notifDep   = isset($_POST['notify_deposit']) ? 1 : 0;
        $notifBuy   = isset($_POST['notify_purchase']) ? 1 : 0;
        $notifFail  = isset($_POST['notify_failed']) ? 1 : 0;
        $isActive   = isset($_POST['is_active']) ? 1 : 0;

        $stmtBot = $pdo->prepare("INSERT INTO telegram_bot_config (subdomain_id, bot_token, chat_id, notify_deposit, notify_purchase, notify_failed, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE bot_token = VALUES(bot_token), chat_id = VALUES(chat_id), notify_deposit = VALUES(notify_deposit), notify_purchase = VALUES(notify_purchase), notify_failed = VALUES(notify_failed), is_active = VALUES(is_active)");
        $stmtBot->execute([$subadminTenantId, $botToken, $chatId, $notifDep, $notifBuy, $notifFail, $isActive]);
        $toast = ['type' => 'success', 'msg' => 'Your Telegram Notification Bot configured successfully!'];
    }

    // --- TEST TELEGRAM BOT PING (SUB-ADMIN) ---
    if ($act === 'test_telegram_bot_ping') {
        $stmtB = $pdo->prepare("SELECT bot_token, chat_id FROM telegram_bot_config WHERE subdomain_id = ? LIMIT 1");
        $stmtB->execute([$subadminTenantId]);
        $subBot = $stmtB->fetch(PDO::FETCH_ASSOC);

        if ($subBot && !empty($subBot['bot_token']) && !empty($subBot['chat_id'])) {
            $storeName = htmlspecialchars($panelTitle);
            $msg = "⚡ <b>{$storeName} — Telegram Bot Test</b>\n\n✅ Your Telegram Bot is successfully connected with your Sub-Admin store!\nYou will now receive live alerts for deposits and sales.";
            $ch = curl_init("https://api.telegram.org/bot{$subBot['bot_token']}/sendMessage");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $subBot['chat_id'], 'text' => $msg, 'parse_mode' => 'HTML']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            $resData = json_decode($res ?: '', true);
            if ($resData && ($resData['ok'] ?? false)) {
                $toast = ['type' => 'success', 'msg' => 'Test message sent to your Telegram bot!'];
            } else {
                $toast = ['type' => 'error', 'msg' => 'Failed to send! Please check your Bot Token & Chat ID.'];
            }
        } else {
            $toast = ['type' => 'error', 'msg' => 'Please save Bot Token & Chat ID first!'];
        }
    }

    // --- REFERRAL & TOKEN REWARDS SAVE (SUB-ADMIN) ---
    if ($act === 'save_referral_settings') {
        $tokensPerRef = max(0, (int)($_POST['token_per_referral'] ?? 10));
        $bonusBdt     = max(0.0, (float)($_POST['referrer_bonus_bdt'] ?? 0));
        $isActive     = isset($_POST['is_active']) ? 1 : 0;

        $stmtRef = $pdo->prepare("INSERT INTO referral_settings (subdomain_id, token_per_referral, referrer_bonus_bdt, is_active)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE token_per_referral = VALUES(token_per_referral), referrer_bonus_bdt = VALUES(referrer_bonus_bdt), is_active = VALUES(is_active)");
        $stmtRef->execute([$subadminTenantId, $tokensPerRef, $bonusBdt, $isActive]);
        $toast = ['type' => 'success', 'msg' => 'Store referral token & reward rules saved!'];
    }

    // --- CATEGORY SEQUENCE SAVE (SUB-ADMIN) ---
    if ($act === 'save_product_category') {
        $catName  = strtoupper(trim($_POST['category_name'] ?? ''));
        $catOrder = (int)($_POST['sort_order'] ?? 0);
        if (!empty($catName)) {
            $pdo->prepare("INSERT INTO product_categories (subdomain_id, name, sort_order, is_active) VALUES (?, ?, ?, 1)")
                ->execute([$subadminTenantId, $catName, $catOrder]);
            $toast = ['type' => 'success', 'msg' => "Category '$catName' added with sequence order $catOrder!"];
        }
    }
    if ($act === 'delete_product_category') {
        $catId = (int)$_POST['category_id'];
        $pdo->prepare("DELETE FROM product_categories WHERE id = ? AND subdomain_id = ?")->execute([$catId, $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => 'Category deleted!'];
    }

    // --- CUSTOM POPUP SAVE (SUB-ADMIN) ---
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
            $fileName = 'popup_sub_' . $subadminTenantId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['popup_image']['tmp_name'], $siteUploadDir . $fileName)) {
                $imagePath = 'uploads/site/' . $fileName;
            }
        }
        $pdo->prepare("INSERT INTO custom_popups (subdomain_id, title, message, image_url, link_url, link_text, show_once_per_session, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)")
            ->execute([$subadminTenantId, $title, $message, $imagePath, $linkUrl, $linkText, $showOnce, $sort]);
        $toast = ['type' => 'success', 'msg' => 'Custom Entry Pop-up created for your store!'];
    }
    if ($act === 'delete_custom_popup') {
        $pdo->prepare("DELETE FROM custom_popups WHERE id = ? AND subdomain_id = ?")->execute([(int)$_POST['popup_id'], $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => 'Popup deleted!'];
    }

    if ($act === 'add_banner') {
        $targetLink = trim($_POST['target_link'] ?? '');
        $title = trim($_POST['title'] ?? 'Promo Banner');
        $sort = (int)($_POST['sort_order'] ?? 0);
        $bannerPath = null;

        if (!empty($_FILES['banner_photo']['name']) && $_FILES['banner_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['banner_photo']['name'], PATHINFO_EXTENSION));
            $fileName = 'banner_sub_' . $subadminTenantId . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
            if (move_uploaded_file($_FILES['banner_photo']['tmp_name'], $bannerUploadDir . $fileName)) {
                $bannerPath = 'uploads/banners/' . $fileName;
            }
        }
        if (!empty($bannerPath)) {
            $pdo->prepare("INSERT INTO banners (image_url, target_link, title, sort_order, is_active, subdomain_id) VALUES (?, ?, ?, ?, 1, ?)")
                ->execute([$bannerPath, $targetLink ?: null, $title, $sort, $subadminTenantId]);
            $toast = ['type' => 'success', 'msg' => 'Banner added!'];
        }
    }

    if ($act === 'delete_banner') {
        $pdo->prepare("DELETE FROM banners WHERE id = ? AND subdomain_id = ?")->execute([(int)$_POST['banner_id'], $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => 'Banner deleted!'];
    }

    if ($act === 'send_push_notification') {
        $target = $_POST['target_type'] ?? 'all';
        $title = trim($_POST['push_title'] ?? '');
        $msg = trim($_POST['push_message'] ?? '');
        $specificUid = !empty($_POST['specific_user_id']) ? (int)$_POST['specific_user_id'] : null;

        if (!empty($title) && !empty($msg)) {
            $pdo->prepare("INSERT INTO system_notifications (target_type, target_user_id, title, message, scope, subdomain_id) VALUES (?, ?, ?, ?, 'sub_only', ?)")
                ->execute([$target, $specificUid, $title, $msg, $subadminTenantId]);
            $toast = ['type' => 'success', 'msg' => "Notification broadcasted!"];
        }
    }

    if ($act === 'delete_push_notification') {
        $notifId = (int)($_POST['notif_id'] ?? 0);
        $pdo->prepare("DELETE FROM system_notifications WHERE id = ? AND subdomain_id = ?")->execute([$notifId, $subadminTenantId]);
        $toast = ['type' => 'success', 'msg' => "Notification deleted!"];
    }

    if ($act === 'modify_balance') {
        $userId = (int)$_POST['user_id'];
        $amount = (float)$_POST['amount'];
        if ($_POST['type'] === 'deduct') $amount = -$amount;
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ? AND (subdomain_id = ? OR created_by_subdomain = ?)")->execute([$amount, $userId, $subadminTenantId, $subadminSlug]);
        $toast = ['type' => 'success', 'msg' => "Balance updated!"];
    }

    if ($act === 'modify_coins') {
        $userId = (int)$_POST['user_id'];
        $amount = (int)$_POST['coins_amount'];
        if ($_POST['type'] === 'deduct') $amount = -$amount;
        $pdo->prepare("UPDATE users SET coins = GREATEST(0, coins + ?) WHERE id = ? AND (subdomain_id = ? OR created_by_subdomain = ?)")->execute([$amount, $userId, $subadminTenantId, $subadminSlug]);
        $toast = ['type' => 'success', 'msg' => "Coins updated!"];
    }

    if ($act === 'toggle_ban') {
        $userId = (int)$_POST['user_id'];
        $newStatus = $_POST['status'] === 'active' ? 'banned' : 'active';
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND (subdomain_id = ? OR created_by_subdomain = ?)")->execute([$newStatus, $userId, $subadminTenantId, $subadminSlug]);
        $toast = ['type' => 'success', 'msg' => "Status changed to $newStatus!"];
    }


    // 💰 RESELLER PRICE MANAGER — প্রতিটা প্রোডাক্টের প্রতিটা ভেরিয়েন্টের রিসেলার প্রাইস একসাথে সেভ
    if ($act === 'bulk_save_reseller_prices') {
        $rPrices = $_POST['rp_price'] ?? [];
        $rDiscs  = $_POST['rp_disc'] ?? [];
        $updStmt = $pdo->prepare("UPDATE product_plans SET reseller_price = ?, reseller_discount_percent = ? WHERE id = ? AND subdomain_id = ?");
        $changed = 0;
        if (is_array($rPrices)) {
            foreach ($rPrices as $plId => $rawPrice) {
                $plId = (int)$plId;
                $rawPrice = trim((string)$rawPrice);
                if ($plId <= 0 || $rawPrice === '' || !is_numeric($rawPrice)) continue;
                $price = max(0.01, round((float)$rawPrice, 2));
                $disc  = max(0.0, min(99.0, (float)($rDiscs[$plId] ?? 0)));
                $updStmt->execute([$price, $disc, $plId, $subadminTenantId]);
                $changed += $updStmt->rowCount();
            }
        }
        // যেসব রিসেলারের নিজস্ব % ডিসকাউন্ট আছে তাদের ক্যাশ করা দাম নতুন রিসেলার প্রাইস থেকে আবার হিসাব
        try {
            $rsStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'reseller' AND subdomain_id = ? AND discount_percent > 0");
            $rsStmt->execute([$subadminTenantId]);
            foreach ($rsStmt->fetchAll(PDO::FETCH_COLUMN) as $rsId) { syncTenantStackedPricesForUser($pdo, (int)$rsId, $subadminTenantId); }
        } catch (Throwable $e) {}
        $toast = ['type' => 'success', 'msg' => "Reseller prices saved ($changed variants updated)!"];
    }

    if ($act === 'save_user_custom_pricing_and_access') {
        $userId = (int)$_POST['target_user_id'];
        $role = in_array($_POST['user_role'] ?? '', ['customer', 'reseller']) ? $_POST['user_role'] : 'customer';
        $canAddFund = isset($_POST['can_add_fund']) ? 1 : 0;

        $pdo->prepare("UPDATE users SET role = ?, can_add_fund = ? WHERE id = ? AND (subdomain_id = ? OR created_by_subdomain = ?)")->execute([$role, $canAddFund, $userId, $subadminTenantId, $subadminSlug]);

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
        foreach ($customPrices as $plId => $cPrice) {
            $cleanPrice = trim((string)$cPrice);
            if ($cleanPrice !== '' && is_numeric($cleanPrice)) {
                $insPrice->execute([$userId, (int)$plId, (float)$cleanPrice]);
            }
        }
        $toast = ['type' => 'success', 'msg' => "Permissions saved!"];
    }
}

// ====================================================================
// STRICTLY FILTERED TENANT DATA (ZERO MOTHER DATA VISIBLE)
// ====================================================================
$tenantUserFilter = "(subdomain_id = $subadminTenantId OR created_by_subdomain = " . $pdo->quote($subadminSlug) . ")";

$totalUsers = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE $tenantUserFilter")->fetchColumn() ?: 0);
$totalCustomers = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE $tenantUserFilter AND (role = 'customer' OR role IS NULL OR role = '')")->fetchColumn() ?: 0);
$totalResellers = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE $tenantUserFilter AND role = 'reseller'")->fetchColumn() ?: 0);
$totalFunds = (float)($pdo->query("SELECT SUM(balance) FROM users WHERE $tenantUserFilter")->fetchColumn() ?: 0.00);

$todaySales = (float)($pdo->query("SELECT SUM(l.price) FROM licenses l WHERE l.subdomain_id = $subadminTenantId AND DATE(l.created_at) = CURDATE()")->fetchColumn() ?: 0.00);
$monthSales = (float)($pdo->query("SELECT SUM(l.price) FROM licenses l WHERE l.subdomain_id = $subadminTenantId AND MONTH(l.created_at) = MONTH(CURDATE()) AND YEAR(l.created_at) = YEAR(CURDATE())")->fetchColumn() ?: 0.00);
$allTimeSales = (float)($pdo->query("SELECT SUM(l.price) FROM licenses l WHERE l.subdomain_id = $subadminTenantId")->fetchColumn() ?: 0.00);
$totalLicenses = (int)($pdo->query("SELECT COUNT(l.id) FROM licenses l WHERE l.subdomain_id = $subadminTenantId")->fetchColumn() ?: 0);

$pendingTrxCount = (int)($pdo->query("SELECT COUNT(t.id) FROM transactions t WHERE t.subdomain_id = $subadminTenantId AND t.status = 'pending'")->fetchColumn() ?: 0);

$usersList = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM licenses WHERE user_id = u.id AND subdomain_id = $subadminTenantId) AS total_sold_keys FROM users u WHERE $tenantUserFilter ORDER BY u.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Products catalog for this tenant ONLY
$productsData = [];
$allProductPlansFlat = [];
try {
    $productsQuery = $pdo->prepare("SELECT * FROM products WHERE subdomain_id = ? ORDER BY sort_order ASC, id DESC");
    $productsQuery->execute([$subadminTenantId]);
    $rawProds = $productsQuery->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rawProds as $pr) {
        $plansQuery = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM stock_keys s WHERE s.plan_id = p.id AND s.status = 'available' AND s.subdomain_id = ?) AS stock_count FROM product_plans p WHERE p.product_id = ? AND p.subdomain_id = ? ORDER BY p.user_price ASC, p.id ASC");
        $plansQuery->execute([$subadminTenantId, $pr['id'], $subadminTenantId]);
        $pr['plans'] = $plansQuery->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pr['plans'] as $pl) {
            $pl['product_name'] = $pr['name'];
            $allProductPlansFlat[] = $pl;
        }
        $productsData[] = $pr;
    }
} catch (Throwable $e) {}

$kmImportedPlans = [];
if (!empty($settings['km_api_key'])) {
    $kmImportedPlans = $pdo->prepare("
        SELECT pl.*, p.name as product_name 
        FROM product_plans pl 
        JOIN products p ON pl.product_id = p.id 
        WHERE pl.api_plan_id > 0 AND pl.subdomain_id = ?
        ORDER BY pl.id DESC
    ");
    $kmImportedPlans->execute([$subadminTenantId]);
    $kmImportedPlans = $kmImportedPlans->fetchAll(PDO::FETCH_ASSOC);
}
$kmImportedCount = count($kmImportedPlans);

// Fetch Subdomain Isolated Payment Methods
$paymentMethodsList = [];
try {
    $stmtPM = $pdo->prepare("SELECT * FROM payment_methods WHERE subdomain_id = ? ORDER BY sort_order ASC, id ASC");
    $stmtPM->execute([$subadminTenantId]);
    $paymentMethodsList = $stmtPM->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Isolated Spin, Bonus & Staff
$spinSegments = $pdo->prepare("SELECT * FROM subdomain_spin_segments WHERE subdomain_id = ? ORDER BY sort_order ASC, id ASC");
$spinSegments->execute([$subadminTenantId]);
$spinSegments = $spinSegments->fetchAll(PDO::FETCH_ASSOC);

$depositBonusesList = $pdo->prepare("SELECT * FROM subdomain_deposit_bonuses WHERE subdomain_id = ? ORDER BY sort_order ASC, min_deposit ASC");
$depositBonusesList->execute([$subadminTenantId]);
$depositBonusesList = $depositBonusesList->fetchAll(PDO::FETCH_ASSOC);

$botAdminsList = $pdo->prepare("SELECT * FROM subdomain_admins WHERE subdomain_id = ? ORDER BY id DESC");
$botAdminsList->execute([$subadminTenantId]);
$botAdminsList = $botAdminsList->fetchAll(PDO::FETCH_ASSOC);

$bannersList = $pdo->prepare("SELECT * FROM banners WHERE subdomain_id = ? ORDER BY sort_order ASC, id DESC");
$bannersList->execute([$subadminTenantId]);
$bannersList = $bannersList->fetchAll(PDO::FETCH_ASSOC);

$sentNotifications = $pdo->prepare("SELECT * FROM system_notifications WHERE subdomain_id = ? ORDER BY id DESC LIMIT 30");
$sentNotifications->execute([$subadminTenantId]);
$sentNotifications = $sentNotifications->fetchAll(PDO::FETCH_ASSOC);

$transactions = $pdo->prepare("SELECT t.*, u.username, u.email FROM transactions t JOIN users u ON t.user_id = u.id WHERE t.subdomain_id = ? ORDER BY t.id DESC LIMIT 50");
$transactions->execute([$subadminTenantId]);
$transactions = $transactions->fetchAll(PDO::FETCH_ASSOC);

$sales = $pdo->prepare("SELECT l.*, u.username, u.email FROM licenses l JOIN users u ON l.user_id = u.id WHERE l.subdomain_id = ? ORDER BY l.id DESC LIMIT 50");
$sales->execute([$subadminTenantId]);
$sales = $sales->fetchAll(PDO::FETCH_ASSOC);

$totalStockKeys = 0;
if (!empty($productsData)) {
    $totalStockKeys = (int)($pdo->query("SELECT COUNT(id) FROM stock_keys WHERE subdomain_id = $subadminTenantId AND status = 'available'")->fetchColumn() ?: 0);
}

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

// Anti-freeze Cached Provider Balance for Sub-Admin
$providerBalance = subadmin_km_cached_balance($pdo, $subadminTenantId);
$mainThemeColor = $settings['main_site_theme_color'] ?? '#00f5ff';
$panelTitle = !empty($settings['site_name']) ? $settings['site_name'] : 'Sub-Admin Store';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>SUB-ADMIN CONSOLE — <?= htmlspecialchars($panelTitle) ?></title>
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

        /* =====================================================================
           📱 MOBILE / PHONE FIXES — পুরো প্যানেল ফোনে ঠিকঠাক দেখাবে
           ===================================================================== */
        :root { color-scheme: dark; }               /* ফোনের সাদা select/date পিকার ডার্ক হবে */
        html, body { max-width: 100%; overflow-x: hidden; }
        select, option, optgroup { background-color: #0b1224; color: #e2e8f0; }
        select {
            -webkit-appearance: none; appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2322d3ee' stroke-width='3'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 0.75rem center; background-size: 12px;
            padding-right: 2.2rem !important;
        }
        input, select, textarea, button { max-width: 100%; }
        img { max-width: 100%; }
        input[type="date"], input[type="datetime-local"], input[type="time"] { min-height: 2.6rem; }
        .prod-maint-admin { border-color: rgba(239, 68, 68, 0.6) !important; background: linear-gradient(180deg, #1f0a10 0%, #0b0610 100%) !important; box-shadow: 0 0 14px rgba(239, 68, 68, 0.18); }
        /* মোডাল ফোনে স্ক্রিনের বাইরে যাবে না, ভেতরে স্ক্রল হবে */
        .fixed.inset-0 > .box-panel, .fixed.inset-0 > div > .box-panel { max-height: calc(100vh - 2rem); max-height: calc(100dvh - 2rem); overflow-y: auto; }
        @media (max-width: 640px) {
            main { padding-left: 0.75rem !important; padding-right: 0.75rem !important; margin-top: 1rem !important; }
            .box-panel { padding: 1rem; border-radius: 16px; }
            .stat-card { padding: 0.9rem 1rem; border-radius: 14px; }
            .table-roomy th { padding: 0.7rem 0.8rem; font-size: 10px; }
            .table-roomy td { padding: 0.75rem 0.8rem; }
            h2.text-3xl { font-size: 1.5rem; line-height: 2rem; }
            .stat-card.h-32 { height: auto; min-height: 6.5rem; }
            .whitespace-nowrap.btn-purple, .whitespace-nowrap.btn-cyan { white-space: normal; }
            #adminDrawer { width: min(310px, 86vw); }
            .overview-stat-grid .stat-card { padding: 0.8rem 0.85rem; min-width: 0; }
            .overview-stat-grid .stat-card h2 { font-size: 1.2rem; line-height: 1.6rem; overflow-wrap: anywhere; }
            .overview-stat-grid .stat-card span { font-size: 9px; }
            header .text-sm.uppercase { max-width: 42vw; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        }
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

    <!-- Drawer Overlay -->
    <div id="drawerOverlay" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-40 hidden" onclick="toggleAdminDrawer()"></div>

    <!-- Sidebar Drawer Navigation -->
    <aside id="adminDrawer" class="fixed top-0 right-0 w-[310px] h-full bg-[#050814] border-l border-slate-800 z-50 transform translate-x-full transition-transform duration-300 flex flex-col justify-between p-5 overflow-y-auto shadow-2xl">
        <div class="space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                <div class="flex items-center space-x-2.5">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-slate-950 font-black shadow-md">
                        <i class="fa-solid fa-store text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-xs font-black text-white uppercase tracking-wider"><?= htmlspecialchars($panelTitle) ?></h3>
                        <span class="text-[9px] text-cyan-400 font-mono">SUB-ADMIN CONSOLE</span>
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

                <!-- DEDICATED DISCOUNT HUB BUTTON IN DRAWER -->
                <button id="btn-nav-tab-discounts" onclick="switchAdminTab('tab-discounts', 'Discount Management Hub', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-tags w-4 text-purple-400"></i><span>Discount Hub</span>
                </button>

                <button id="btn-nav-tab-connectkm" onclick="switchAdminTab('tab-connectkm', 'Key Manager Integration', this)" class="drawer-nav-btn">
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
                <button id="btn-nav-tab-gateways" onclick="switchAdminTab('tab-gateways', 'Payment Gateway Rules', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-building-columns w-4 text-emerald-400"></i><span>Payment Gateways</span>
                </button>
                <button id="btn-nav-tab-themecolor" onclick="switchAdminTab('tab-themecolor', 'Store Theme Color', this)" class="drawer-nav-btn">
                    <i class="fa-solid fa-palette w-4 text-emerald-400"></i><span>Store Theme Color</span>
                </button>
            </nav>
        </div>

        <div class="pt-3 border-t border-slate-800">
            <a href="sub_admin.php?logout=1" class="w-full flex items-center justify-center space-x-2 py-2.5 rounded-xl bg-red-950/30 border border-red-500/20 text-red-400 hover:bg-red-900/30 text-xs font-bold font-mono transition">
                <i class="fa-solid fa-power-off"></i><span>TERMINATE SESSION</span>
            </a>
        </div>
    </aside>

    <!-- Header Bar -->
    <header class="w-full bg-[#050814]/90 backdrop-blur-md border-b border-slate-800 px-4 py-3.5 sticky top-0 z-30">
        <div class="max-w-6xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-slate-950 text-base font-black shadow-md shadow-cyan-950/40">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <div class="flex items-center space-x-2">
                        <h1 class="text-sm font-black text-white uppercase tracking-wider"><?= htmlspecialchars($panelTitle) ?></h1>
                        <span class="px-2 py-0.5 rounded text-[8px] font-black uppercase tracking-widest bg-cyan-950 text-cyan-300 border border-cyan-500/30 font-mono">
                            SUB-ADMIN
                        </span>
                    </div>
                    <span id="headerSectionTitle" class="text-[10px] text-cyan-400 font-mono block mt-0.5">Dashboard Overview</span>
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
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 font-mono overview-stat-grid">
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

            <!-- Discount Engine Overview Card -->
            <div class="box-panel flex flex-col sm:flex-row items-center justify-between gap-4 border-purple-500/30 bg-gradient-to-r from-[#17092e] to-[#070d20]">
                <div class="flex items-center space-x-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-purple-500/10 text-purple-400 flex items-center justify-center text-xl border border-purple-500/20 shadow-inner">
                        <i class="fa-solid fa-tags"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-black text-white uppercase tracking-wider font-mono">Store Discount Engine</h4>
                        <div class="text-xs text-slate-400 font-mono mt-1">
                            Set your store products, customers &amp; resellers stacked discounts with 1-click controls.
                        </div>
                    </div>
                </div>

                <button type="button" onclick="switchAdminTab('tab-discounts', 'Discount Management Hub', document.getElementById('btn-nav-tab-discounts'))" class="px-4 py-2.5 rounded-xl btn-purple text-xs uppercase tracking-wider whitespace-nowrap">
                    Manage Discounts
                </button>
            </div>

            <!-- Gateways Overview Card -->
            <div class="box-panel flex flex-col sm:flex-row items-center justify-between gap-4 border-teal-500/30 bg-gradient-to-r from-[#041d1a] to-[#070d20]">
                <div class="flex items-center space-x-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-teal-500/10 text-emerald-400 flex items-center justify-center text-xl border border-teal-500/20 shadow-inner">
                        <i class="fa-solid fa-building-columns"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-black text-white uppercase tracking-wider font-mono">Dynamic Payment Gateways &amp; SNZPay</h4>
                        <div class="text-xs text-slate-400 font-mono mt-1">
                            Configure bKash, Nagad, Rocket, QR codes, banners, and SNZPay auto-verification for this store.
                        </div>
                    </div>
                </div>

                <button type="button" onclick="switchAdminTab('tab-gateways', 'Payment Gateway Rules', document.getElementById('btn-nav-tab-gateways'))" class="px-4 py-2.5 rounded-xl btn-emerald text-xs uppercase tracking-wider whitespace-nowrap">
                    Manage Gateways
                </button>
            </div>
        </section>

        <!-- =============================================================== -->
        <!-- 2. CENTRAL DISCOUNT MANAGEMENT HUB (3 SUB-SECTIONS)             -->
        <!-- =============================================================== -->
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
</div> <!-- পিল বাটন বক্সের শেষ div -->

            <!-- SUB-SECTION 4: 🎟️ COUPON MANAGER (v2) -->
            <div id="disc-view-coupons" class="hidden space-y-4">
                <?php
                $cpScopeId = (int)$subadminTenantId;
                $cpList = [];
                try {
                    $stCp = $pdo->prepare("SELECT * FROM coupons WHERE subdomain_id = ? ORDER BY id DESC");
                    $stCp->execute([$cpScopeId]);
                    $cpList = $stCp->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}
                $cpProdNames = [];
                foreach ($productsData as $__pd) { $cpProdNames[(int)$__pd['id']] = $__pd['name']; }
                ?>
                <div class="box-panel p-5 space-y-4 border-fuchsia-500/30">
                    <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-slate-800">
                        <h3 class="text-xs font-black text-fuchsia-300 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-ticket text-fuchsia-400"></i> STORE COUPON CODE MANAGER
                        </h3>
                        <button type="button" onclick="cpResetForm()" class="px-3 py-1.5 rounded-lg bg-fuchsia-600 hover:bg-fuchsia-500 text-white text-[11px] font-black uppercase">+ New Coupon</button>
                    </div>

                    <form method="POST" id="couponForm" class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-3 text-xs font-mono">
                        <input type="hidden" name="admin_action" value="save_coupon">
                        <input type="hidden" name="active_tab" value="tab-discounts">
                        <input type="hidden" name="disc_sub_tab" value="disc-view-coupons">
                        <input type="hidden" name="coupon_id" id="cpFormId" value="0">

                        <div id="cpFormModeLabel" class="text-[11px] font-black text-fuchsia-300 uppercase">➕ Create New Coupon</div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">Coupon Code *</label>
                                <div class="flex gap-2">
                                    <input type="text" name="coupon_code" id="cpFormCode" required placeholder="e.g. EID50" class="w-full min-w-0 bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none uppercase font-bold tracking-wider">
                                    <button type="button" onclick="cpGenerateCode()" class="px-3 rounded-xl bg-slate-800 text-cyan-300 font-bold text-[10px] uppercase whitespace-nowrap">Random</button>
                                </div>
                            </div>
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">Title / Note (optional)</label>
                                <input type="text" name="title" id="cpFormTitle" placeholder="e.g. Eid Special Offer" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">Discount Type</label>
                                <select name="discount_type" id="cpFormType" onchange="cpTypeChanged()" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-cyan-300 font-bold outline-none">
                                    <option value="percent">Percent (%)</option>
                                    <option value="fixed">Fixed (৳ BDT)</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold"><span id="cpValueLabel">Discount %</span> *</label>
                                <input type="number" step="0.01" min="0.01" name="discount_value" id="cpFormValue" required placeholder="10" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-emerald-400 font-black outline-none">
                            </div>
                            <div id="cpMaxDiscWrap" class="col-span-2 sm:col-span-1">
                                <label class="text-slate-300 block mb-1 font-bold">Max Discount ৳ <span class="text-slate-500 font-normal">(0 = no cap)</span></label>
                                <input type="number" step="1" min="0" name="max_discount" id="cpFormMaxDisc" value="0" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-amber-300 font-bold outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">👥 Total Uses <span class="text-slate-500 font-normal">(0 = ∞)</span></label>
                                <input type="number" min="0" step="1" name="max_uses" id="cpFormMaxUses" value="100" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white font-bold outline-none">
                            </div>
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">👤 Per User <span class="text-slate-500 font-normal">(0 = ∞)</span></label>
                                <input type="number" min="0" step="1" name="per_user_limit" id="cpFormPerUser" value="1" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white font-bold outline-none">
                            </div>
                            <div class="col-span-2 sm:col-span-1">
                                <label class="text-slate-300 block mb-1 font-bold">Min Order ৳ <span class="text-slate-500 font-normal">(0 = none)</span></label>
                                <input type="number" min="0" step="1" name="min_spend" id="cpFormMinSpend" value="0" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white font-bold outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">⏰ Starts At <span class="text-slate-500 font-normal">(empty = now)</span></label>
                                <input type="datetime-local" name="starts_at" id="cpFormStarts" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                            </div>
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">⌛ Ends At <span class="text-slate-500 font-normal">(empty = never)</span></label>
                                <input type="datetime-local" name="expires_at" id="cpFormEnds" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                                <div class="flex flex-wrap gap-1.5 mt-1.5">
                                    <?php foreach ([['1 Hour', 1], ['24 Hours', 24], ['3 Days', 72], ['7 Days', 168], ['30 Days', 720]] as $__q): ?>
                                        <button type="button" onclick="cpQuickExpiry(<?= $__q[1] ?>)" class="px-2 py-1 rounded-md bg-slate-800 hover:bg-slate-700 text-[10px] text-cyan-300 font-bold"><?= $__q[0] ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">Who Can Use</label>
                                <select name="user_role" id="cpFormRole" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-purple-300 font-bold outline-none">
                                    <option value="all">Everyone</option>
                                    <option value="customer">Customers only</option>
                                    <option value="reseller">Resellers only</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-slate-300 block mb-1 font-bold">Products</label>
                                <select name="target_type" id="cpFormTarget" onchange="document.getElementById('cpProdBox').classList.toggle('hidden', this.value !== 'specific')" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-amber-300 font-bold outline-none">
                                    <option value="all">All Products</option>
                                    <option value="specific">Specific Products Only</option>
                                </select>
                            </div>
                        </div>

                        <div id="cpProdBox" class="hidden">
                            <div class="max-h-36 overflow-y-auto bg-[#030612] p-2.5 rounded-xl border border-slate-800 grid grid-cols-2 gap-2">
                                <?php foreach ($productsData as $pd): ?>
                                    <label class="flex items-center gap-2 text-slate-300 text-xs cursor-pointer min-w-0">
                                        <input type="checkbox" name="product_ids[]" value="<?= (int)$pd['id'] ?>" class="cp-prod-cb rounded text-cyan-500">
                                        <span class="truncate"><?= htmlspecialchars($pd['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <label class="flex items-center gap-2 text-emerald-300 font-bold cursor-pointer">
                            <input type="checkbox" name="is_active" id="cpFormActive" value="1" checked class="rounded scale-110"> Active
                        </label>

                        <div id="cpPreview" class="p-2.5 rounded-xl bg-fuchsia-950/30 border border-fuchsia-500/30 text-[11px] text-fuchsia-200 font-sans leading-relaxed"></div>

                        <button type="submit" id="cpFormSubmit" class="w-full py-3 rounded-xl bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white uppercase font-black text-xs shadow-md">
                            + CREATE COUPON
                        </button>
                    </form>

                    <!-- Coupon cards -->
                    <div class="space-y-2.5">
                        <?php if (empty($cpList)): ?>
                            <p class="py-6 text-center text-slate-500 font-sans text-xs">No coupon codes created yet.</p>
                        <?php endif; ?>
                        <?php foreach ($cpList as $cp):
                            $nowTs   = time();
                            $stTs    = !empty($cp['starts_at']) ? strtotime($cp['starts_at']) : null;
                            $exTs    = !empty($cp['expires_at']) ? strtotime($cp['expires_at']) : (!empty($cp['expiry_date']) ? strtotime($cp['expiry_date'] . ' 23:59:59') : null);
                            $maxU    = (int)($cp['max_uses'] ?? 0);
                            $usedU   = (int)($cp['used_count'] ?? 0);
                            if ((int)($cp['is_active'] ?? 1) !== 1)       { $cpState = ['PAUSED', 'bg-slate-800 text-slate-400 border-slate-700']; }
                            elseif ($exTs && $exTs < $nowTs)              { $cpState = ['EXPIRED', 'bg-red-950 text-red-400 border-red-500/40']; }
                            elseif ($maxU > 0 && $usedU >= $maxU)         { $cpState = ['USED UP', 'bg-orange-950 text-orange-300 border-orange-500/40']; }
                            elseif ($stTs && $stTs > $nowTs)              { $cpState = ['SCHEDULED', 'bg-sky-950 text-sky-300 border-sky-500/40']; }
                            else                                          { $cpState = ['LIVE', 'bg-emerald-950 text-emerald-300 border-emerald-500/40']; }
                            $offTxt  = ($cp['discount_type'] === 'percent') ? rtrim(rtrim(number_format((float)$cp['discount_value'], 2, '.', ''), '0'), '.') . '% OFF' : '৳' . number_format((float)$cp['discount_value'], 0) . ' OFF';
                            $pct     = $maxU > 0 ? min(100, round($usedU / $maxU * 100)) : 0;
                            $targets = [];
                            if (($cp['target_type'] ?? 'all') === 'specific') {
                                foreach (array_filter(explode(',', (string)$cp['product_ids'])) as $__pid) { if (isset($cpProdNames[(int)$__pid])) $targets[] = $cpProdNames[(int)$__pid]; }
                            }
                            $cpJson = [
                                'id' => (int)$cp['id'], 'code' => $cp['code'], 'title' => $cp['title'] ?? '', 'discount_type' => $cp['discount_type'],
                                'discount_value' => (float)$cp['discount_value'], 'max_discount' => (float)($cp['max_discount'] ?? 0),
                                'max_uses' => $maxU, 'per_user_limit' => (int)($cp['per_user_limit'] ?? 1), 'min_spend' => (float)($cp['min_spend'] ?? 0),
                                'starts_at' => $stTs ? date('Y-m-d\TH:i', $stTs) : '', 'expires_at' => $exTs ? date('Y-m-d\TH:i', $exTs) : '',
                                'user_role' => $cp['user_role'] ?? 'all', 'target_type' => $cp['target_type'] ?? 'all',
                                'product_ids' => array_values(array_filter(array_map('intval', explode(',', (string)$cp['product_ids'])))), 'is_active' => (int)($cp['is_active'] ?? 1),
                            ];
                        ?>
                            <div class="rounded-2xl border border-slate-800 bg-[#050a1a] p-3 space-y-2 text-xs font-mono">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="px-2.5 py-1 rounded-lg bg-fuchsia-950/60 border border-dashed border-fuchsia-500/60 text-fuchsia-200 font-black tracking-widest truncate"><?= htmlspecialchars($cp['code']) ?></span>
                                        <span class="text-emerald-400 font-black whitespace-nowrap"><?= $offTxt ?></span>
                                    </div>
                                    <span class="px-2 py-0.5 rounded-md border text-[9px] font-black <?= $cpState[1] ?>"><?= $cpState[0] ?></span>
                                </div>
                                <?php if (!empty($cp['title'])): ?><p class="text-[11px] text-slate-300 font-sans"><?= htmlspecialchars($cp['title']) ?></p><?php endif; ?>
                                <div>
                                    <div class="flex justify-between text-[10px] text-slate-400"><span>👥 Used: <b class="text-white"><?= $usedU ?></b> / <?= $maxU > 0 ? $maxU : '∞' ?></span><span>👤 Per user: <?= (int)($cp['per_user_limit'] ?? 1) > 0 ? (int)$cp['per_user_limit'] : '∞' ?></span></div>
                                    <?php if ($maxU > 0): ?><div class="h-1.5 rounded-full bg-slate-800 mt-1 overflow-hidden"><div class="h-full bg-gradient-to-r from-fuchsia-500 to-purple-500" style="width: <?= $pct ?>%"></div></div><?php endif; ?>
                                </div>
                                <div class="flex flex-wrap gap-x-3 gap-y-1 text-[10px] text-slate-400">
                                    <span>⏰ <?= $stTs ? date('d M y, h:i A', $stTs) : 'Now' ?> → <?= $exTs ? date('d M y, h:i A', $exTs) : 'No end' ?></span>
                                    <?php if ((float)($cp['min_spend'] ?? 0) > 0): ?><span>Min ৳<?= number_format((float)$cp['min_spend'], 0) ?></span><?php endif; ?>
                                    <?php if ($cp['discount_type'] === 'percent' && (float)($cp['max_discount'] ?? 0) > 0): ?><span>Max ৳<?= number_format((float)$cp['max_discount'], 0) ?></span><?php endif; ?>
                                    <span><?= ($cp['user_role'] ?? 'all') === 'all' ? 'Everyone' : ucfirst($cp['user_role']) . 's' ?></span>
                                    <span><?= empty($targets) ? 'All products' : htmlspecialchars(implode(', ', $targets)) ?></span>
                                </div>
                                <div class="flex flex-wrap gap-2 pt-1">
                                    <button type="button" onclick='cpEdit(<?= json_encode($cpJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="px-3 py-1.5 rounded-lg bg-cyan-950/70 border border-cyan-500/30 text-cyan-300 font-bold text-[10px] uppercase">✏️ Edit</button>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="admin_action" value="toggle_coupon">
                                        <input type="hidden" name="active_tab" value="tab-discounts">
                                        <input type="hidden" name="disc_sub_tab" value="disc-view-coupons">
                                        <input type="hidden" name="coupon_id" value="<?= (int)$cp['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-800 border border-slate-700 text-slate-200 font-bold text-[10px] uppercase"><?= (int)($cp['is_active'] ?? 1) === 1 ? '⏸ Pause' : '▶ Resume' ?></button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete coupon <?= htmlspecialchars($cp['code'], ENT_QUOTES) ?>?');">
                                        <input type="hidden" name="admin_action" value="delete_coupon">
                                        <input type="hidden" name="active_tab" value="tab-discounts">
                                        <input type="hidden" name="disc_sub_tab" value="disc-view-coupons">
                                        <input type="hidden" name="coupon_id" value="<?= (int)$cp['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-950/50 border border-red-500/30 text-red-400 font-bold text-[10px] uppercase">🗑 Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <script>
                    function cpTypeChanged() {
                        const t = document.getElementById('cpFormType').value;
                        document.getElementById('cpValueLabel').textContent = t === 'percent' ? 'Discount %' : 'Discount ৳';
                        document.getElementById('cpMaxDiscWrap').style.opacity = t === 'percent' ? '1' : '.35';
                        document.getElementById('cpFormMaxDisc').disabled = t !== 'percent';
                        cpPreview();
                    }
                    function cpPad(n) { return String(n).padStart(2, '0'); }
                    function cpLocal(d) { return d.getFullYear() + '-' + cpPad(d.getMonth() + 1) + '-' + cpPad(d.getDate()) + 'T' + cpPad(d.getHours()) + ':' + cpPad(d.getMinutes()); }
                    function cpQuickExpiry(hours) {
                        const startVal = document.getElementById('cpFormStarts').value;
                        const base = startVal ? new Date(startVal) : new Date();
                        document.getElementById('cpFormEnds').value = cpLocal(new Date(base.getTime() + hours * 3600 * 1000));
                        cpPreview();
                    }
                    function cpGenerateCode() {
                        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; let c = '';
                        for (let i = 0; i < 8; i++) c += chars[Math.floor(Math.random() * chars.length)];
                        document.getElementById('cpFormCode').value = c; cpPreview();
                    }
                    function cpPreview() {
                        const g = id => document.getElementById(id);
                        const t = g('cpFormType').value, v = parseFloat(g('cpFormValue').value) || 0;
                        const maxU = parseInt(g('cpFormMaxUses').value) || 0, perU = parseInt(g('cpFormPerUser').value) || 0;
                        const minS = parseFloat(g('cpFormMinSpend').value) || 0, cap = parseFloat(g('cpFormMaxDisc').value) || 0;
                        const st = g('cpFormStarts').value, en = g('cpFormEnds').value;
                        const fmt = s => s ? new Date(s).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
                        let txt = `🎟️ <b>${(g('cpFormCode').value || 'CODE').toUpperCase()}</b> দিলে <b>${t === 'percent' ? v + '%' : '৳' + v}</b> ছাড়`;
                        if (t === 'percent' && cap > 0) txt += ` (সর্বোচ্চ ৳${cap})`;
                        txt += `। মোট <b>${maxU > 0 ? maxU + ' বার' : 'আনলিমিটেড'}</b> ব্যবহার করা যাবে, প্রতি ইউজার <b>${perU > 0 ? perU + ' বার' : 'আনলিমিটেড'}</b>।`;
                        if (minS > 0) txt += ` মিনিমাম অর্ডার ৳${minS}।`;
                        txt += ` সময়: <b>${st ? fmt(st) : 'এখন থেকে'}</b> → <b>${en ? fmt(en) : 'কোনো শেষ নেই'}</b>।`;
                        g('cpPreview').innerHTML = txt;
                    }
                    function cpResetForm() {
                        const f = document.getElementById('couponForm'); f.reset();
                        document.getElementById('cpFormId').value = '0';
                        document.getElementById('cpFormModeLabel').textContent = '➕ Create New Coupon';
                        document.getElementById('cpFormSubmit').textContent = '+ CREATE COUPON';
                        document.getElementById('cpProdBox').classList.add('hidden');
                        cpTypeChanged(); f.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    function cpEdit(c) {
                        const g = id => document.getElementById(id);
                        g('cpFormId').value = c.id; g('cpFormCode').value = c.code; g('cpFormTitle').value = c.title || '';
                        g('cpFormType').value = c.discount_type; g('cpFormValue').value = c.discount_value; g('cpFormMaxDisc').value = c.max_discount || 0;
                        g('cpFormMaxUses').value = c.max_uses; g('cpFormPerUser').value = c.per_user_limit; g('cpFormMinSpend').value = c.min_spend || 0;
                        g('cpFormStarts').value = c.starts_at || ''; g('cpFormEnds').value = c.expires_at || '';
                        g('cpFormRole').value = c.user_role || 'all'; g('cpFormTarget').value = c.target_type || 'all';
                        g('cpProdBox').classList.toggle('hidden', c.target_type !== 'specific');
                        document.querySelectorAll('.cp-prod-cb').forEach(cb => { cb.checked = (c.product_ids || []).includes(parseInt(cb.value)); });
                        g('cpFormActive').checked = parseInt(c.is_active) === 1;
                        g('cpFormModeLabel').textContent = '✏️ Editing ' + c.code;
                        g('cpFormSubmit').textContent = '💾 UPDATE COUPON';
                        cpTypeChanged(); document.getElementById('couponForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    document.getElementById('couponForm').addEventListener('input', cpPreview);
                    cpTypeChanged();
                </script>
            </div>

    <!-- SUB-SECTION 1: ALL PRODUCTS DISCOUNT -->
            <!-- SUB-SECTION 1: ALL PRODUCTS DISCOUNT -->
            <div id="disc-view-products" class="space-y-4">
                <div class="box-panel p-5 space-y-4 border-purple-500/25">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-3">
                        <div>
                            <h3 class="text-xs font-black text-purple-300 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-boxes-stacked text-purple-400"></i> STORE PRODUCTS DISCOUNT CONTROL
                            </h3>
                            <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                                Set store-wide discount across all product plans or turn off all product discounts anytime.
                            </span>
                        </div>
                    </div>

                    <!-- Bulk Apply or Reset to 0% for All Products -->
                    <div class="bg-slate-900/90 p-4 rounded-2xl border border-slate-800 space-y-3">
                        <span class="text-xs font-bold text-slate-300 uppercase block font-sans">⚡ Quick Action: All Products Together</span>
                        <div class="flex flex-col sm:flex-row items-center gap-2.5">
                            <form method="POST" class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
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

                    <!-- Search Filter for Product Plans -->
                    <div class="relative pt-1">
                        <input type="text" id="discPlanSearchInput" onkeyup="filterDiscProductPlans()" placeholder="Search product or plan title to set individual discount..." class="w-full bg-[#030612] border border-slate-800 text-xs text-white rounded-xl p-3 pl-10 outline-none focus:border-purple-400">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none mt-0.5"></i>
                    </div>

                    <!-- Plans List with Individual Discount Input -->
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
                                    <tr><td colspan="5" class="py-8 text-center text-slate-500 font-sans">No products/plans registered in your store.</td></tr>
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

            <!-- SUB-SECTION 2: CUSTOMERS DISCOUNT (WITH SEARCH & STACKING) -->
            <div id="disc-view-customers" class="hidden space-y-4">
                <div class="box-panel p-5 space-y-4 border-sky-500/25">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-3">
                        <div>
                            <h3 class="text-xs font-black text-sky-300 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-user-group text-sky-400"></i> CUSTOMERS STACKED DISCOUNT CONTROL
                            </h3>
                            <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                                Extra customer discount stacks with product plan discount (e.g. 10% Plan + 5% Customer = 15% Total)!
                            </span>
                        </div>
                    </div>

                    <!-- Bulk Customer Controls -->
                    <div class="bg-slate-900/90 p-4 rounded-2xl border border-slate-800 space-y-3">
                        <span class="text-xs font-bold text-slate-300 uppercase block font-sans">⚡ Quick Action: All Store Customers</span>
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

                    <!-- Search Filter for Customers -->
                    <div class="relative pt-1">
                        <input type="text" id="discCustomerSearchInput" onkeyup="filterDiscUsers('customer')" placeholder="Search customer by username or email..." class="w-full bg-[#030612] border border-slate-800 text-xs text-white rounded-xl p-3 pl-10 outline-none focus:border-sky-400">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none mt-0.5"></i>
                    </div>

                    <!-- Customers List -->
                    <div class="space-y-2.5" id="discCustomerListContainer">
                        <?php if (empty($usersList)): ?>
                            <p class="text-center py-6 text-slate-500 text-xs italic font-sans font-mono">No customers registered on this store yet.</p>
                        <?php else: ?>
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
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- SUB-SECTION 3: RESELLERS DISCOUNT (WITH SEARCH & STACKING) -->
            <div id="disc-view-resellers" class="hidden space-y-4">
                <div class="box-panel p-5 space-y-4 border-amber-500/25">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-3">
                        <div>
                            <h3 class="text-xs font-black text-amber-300 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-users-gear text-amber-400"></i> RESELLERS STACKED DISCOUNT CONTROL
                            </h3>
                            <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                                Set wholesale tier discounts. Automatically stacks with each product's base discount!
                            </span>
                        </div>
                    </div>

                    <!-- Bulk Reseller Controls -->
                    <div class="bg-slate-900/90 p-4 rounded-2xl border border-slate-800 space-y-3">
                        <span class="text-xs font-bold text-slate-300 uppercase block font-sans">⚡ Quick Action: All Store Resellers</span>
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

                    <!-- Search Filter for Resellers -->
                    <div class="relative pt-1">
                        <input type="text" id="discResellerSearchInput" onkeyup="filterDiscUsers('reseller')" placeholder="Search reseller by username or email..." class="w-full bg-[#030612] border border-slate-800 text-xs text-white rounded-xl p-3 pl-10 outline-none focus:border-amber-400">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none mt-0.5"></i>
                    </div>

                    <!-- Resellers List -->
                    <div class="space-y-2.5" id="discResellerListContainer">
                        <?php if ($totalResellers === 0): ?>
                            <p class="text-center py-6 text-slate-500 text-xs italic font-sans font-mono">No resellers registered on this store yet.</p>
                        <?php else: ?>
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
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- 3. PRODUCTS CATALOG TAB (FULL CATALOG + ADD PRODUCT + STOCK VAULT) -->
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
                        <p class="font-sans">No products linked to your store. Connect Key Manager to sync or click <strong>+ Add Product</strong>.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($productsData as $prod): 
                        $planCount = count($prod['plans']);
                        $isProdActive = (int)($prod['is_active'] ?? 1) === 1 && (int)($prod['is_maintenance'] ?? 0) === 0;
                    ?>
                        <div class="box-panel p-4 space-y-3 transition <?= $isProdActive ? 'hover:border-cyan-500/30' : 'prod-maint-admin' ?>">
                            <div class="flex flex-wrap items-center justify-between gap-2">
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

                            <!-- 🔧 QUICK MAINTENANCE CONTROL -->
                            <form method="POST" class="flex items-center gap-2 p-2 rounded-xl <?= $isProdActive ? 'bg-slate-900/60 border border-slate-800' : 'bg-red-950/40 border border-red-500/40' ?>">
                                <input type="hidden" name="admin_action" value="toggle_product_maintenance">
                                <input type="hidden" name="active_tab" value="tab-products">
                                <input type="hidden" name="product_id" value="<?= (int)$prod['id'] ?>">
                                <input type="hidden" name="maint_state" value="<?= $isProdActive ? '1' : '0' ?>">
                                <input type="text" name="maintenance_msg" value="<?= htmlspecialchars($prod['maintenance_msg'] ?? '') ?>" placeholder="Notice: e.g. Update চলছে, ৬টায় চালু" class="flex-1 min-w-0 bg-[#030612] border border-slate-800 p-2 rounded-lg text-amber-300 outline-none text-xs">
                                <button type="submit" class="py-2 px-3 rounded-lg font-black text-[11px] uppercase tracking-wider whitespace-nowrap transition <?= $isProdActive ? 'bg-red-600 hover:bg-red-500 text-white' : 'bg-emerald-500 hover:bg-emerald-400 text-slate-950' ?>">
                                    <?= $isProdActive ? '🔧 Maintenance' : '✅ Go Online' ?>
                                </button>
                            </form>

                            <?php $isResetOn = (int)($prod['reset_enabled'] ?? 1) === 1; ?>
                            <form method="POST" class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-xl bg-slate-900/60 border <?= $isResetOn ? 'border-cyan-500/30' : 'border-slate-800' ?>">
                                <input type="hidden" name="admin_action" value="toggle_product_reset">
                                <input type="hidden" name="active_tab" value="tab-products">
                                <input type="hidden" name="product_id" value="<?= (int)$prod['id'] ?>">
                                <input type="hidden" name="reset_state" value="<?= $isResetOn ? '0' : '1' ?>">
                                <span class="text-[11px] font-bold <?= $isResetOn ? 'text-cyan-300' : 'text-slate-500' ?>">🔄 HWID Reset: <?= $isResetOn ? 'ALLOWED' : 'OFF' ?></span>
                                <button type="submit" class="py-1 px-2.5 rounded-lg text-[10px] font-black uppercase <?= $isResetOn ? 'bg-slate-800 text-slate-300 hover:bg-slate-700' : 'bg-cyan-500 text-slate-950 hover:bg-cyan-400' ?>"><?= $isResetOn ? 'Turn Off' : 'Allow Reset' ?></button>
                            </form>

                            <div id="plan-accordion-<?= $prod['id'] ?>" class="hidden pt-3 border-t border-slate-800 space-y-2">
                                <?php if ($planCount === 0): ?>
                                    <p class="text-xs text-slate-500 py-1.5 font-sans">No validity plans attached. Click "+ Plan" above.</p>
                                <?php else: ?>
                                    <form method="POST" class="space-y-2">
                                    <input type="hidden" name="admin_action" value="save_plan_prices_quick">
                                    <input type="hidden" name="active_tab" value="tab-products">
                                    <input type="hidden" name="product_id" value="<?= (int)$prod['id'] ?>">
                                    <div class="flex items-center justify-between text-[10px] font-bold px-1">
                                        <span class="text-slate-400 uppercase">💲 Plan Prices (USD)</span>
                                        <span class="text-emerald-400">1 USD = ৳<?= htmlspecialchars((string)$effectiveBdtRate) ?></span>
                                    </div>
                                    <?php foreach ($prod['plans'] as $pl): 
                                        $bdtP = round((float)$pl['user_price'] * $effectiveBdtRate, 0);
                                        $plResP = (float)($pl['reseller_price'] ?? 0);
                                        $rCoins = (int)($pl['reward_coins'] ?? 0);
                                        $cPrice = (int)($pl['coin_price'] ?? 0);
                                        $plDisc = (float)($pl['discount_percent'] ?? 0);
                                    ?>
                                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 p-3 rounded-xl bg-slate-900/80 border border-slate-800/80 text-xs">
                                            <div class="min-w-0">
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
                                            <div class="grid grid-cols-2 gap-2 sm:w-64 flex-shrink-0">
                                                <label class="block">
                                                    <span class="text-[9px] text-cyan-300 font-bold block mb-0.5">👤 Customer $</span>
                                                    <input type="number" step="0.01" min="0.01" name="qp_user[<?= (int)$pl['id'] ?>]" value="<?= number_format((float)$pl['user_price'], 2, '.', '') ?>" oninput="qpBdt(this)" data-rate="<?= (float)$effectiveBdtRate ?>" class="w-full bg-[#030612] border border-cyan-500/30 p-1.5 rounded-lg text-cyan-300 font-mono font-bold text-xs outline-none text-right">
                                                    <span class="qp-bdt text-[9px] text-slate-500 font-mono block text-right">≈ ৳<?= number_format($bdtP, 0) ?></span>
                                                </label>
                                                <label class="block">
                                                    <span class="text-[9px] text-emerald-300 font-bold block mb-0.5">🏪 Reseller $</span>
                                                    <input type="number" step="0.01" min="0.01" name="qp_res[<?= (int)$pl['id'] ?>]" value="<?= $plResP > 0 ? number_format($plResP, 2, '.', '') : '' ?>" placeholder="<?= number_format((float)$pl['user_price'], 2, '.', '') ?>" oninput="qpBdt(this)" data-rate="<?= (float)$effectiveBdtRate ?>" class="w-full bg-[#030612] border border-emerald-500/30 p-1.5 rounded-lg text-emerald-300 font-mono font-bold text-xs outline-none text-right">
                                                    <span class="qp-bdt text-[9px] text-slate-500 font-mono block text-right"><?= $plResP > 0 ? '≈ ৳' . number_format($plResP * $effectiveBdtRate, 0) : 'same as customer' ?></span>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <button type="submit" class="w-full py-2.5 rounded-xl btn-cyan uppercase text-[11px] font-black tracking-wider">💾 Save Prices</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Add Product View -->
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

            <!-- Stock Vault View -->
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
                        <i class="fa-solid fa-bolt-lightning text-amber-400"></i> KEY MANAGER INTEGRATION
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
                            <input type="url" name="km_api_url" value="<?= htmlspecialchars($settings['km_api_url'] ?? '') ?>" placeholder="https://keymanager.shop/api.php" required class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-white outline-none focus:border-cyan-400">
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
                                    <tr><td colspan="6" class="py-8 text-center text-slate-500 italic font-sans">No plans linked. Connect your Key Manager API to sync.</td></tr>
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
                        <i class="fa-solid fa-dharmachakra text-emerald-400"></i> LUCKY SPIN REWARD ENGINE
                    </h3>
                    <span class="px-3 py-1 rounded-xl text-[10px] font-black uppercase <?= ($settings['spin_wheel_enabled'] ?? '0') === '1' ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/25' : 'bg-red-500/15 text-red-400 border border-red-500/25' ?>">
                        <?= ($settings['spin_wheel_enabled'] ?? '0') === '1' ? 'ONLINE' : 'HIDDEN' ?>
                    </span>
                </div>

                <form method="POST" class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-4 text-xs">
                    <input type="hidden" name="admin_action" value="update_spin_wheel_settings">
                    <input type="hidden" name="active_tab" value="tab-spinwheel">

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3.5">
                        <div class="bg-[#030612] p-3 rounded-xl border border-slate-800 flex items-center justify-between">
                            <span class="text-slate-300 font-bold text-xs">Visibility</span>
                            <input type="checkbox" name="spin_wheel_enabled" value="1" <?= ($settings['spin_wheel_enabled'] ?? '0') === '1' ? 'checked' : '' ?> class="rounded text-emerald-500 scale-125">
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
                                <?php if (empty($spinSegments)): ?>
                                    <tr><td colspan="7" class="py-8 text-center text-slate-500 italic font-sans">No prize segments set. Click "+ ADD SEGMENT".</td></tr>
                                <?php else: ?>
                                    <?php foreach ($spinSegments as $seg): ?>
                                        <tr>
                                            <td><div class="w-5 h-5 rounded-full border border-white/30" style="background-color: <?= htmlspecialchars($seg['color']) ?>;"></div></td>
                                            <td class="font-bold text-white font-sans"><?= htmlspecialchars($seg['title']) ?></td>
                                            <td class="text-cyan-300">৳<?= number_format((float)$seg['spend_target'], 0) ?></td>
                                            <td class="text-emerald-400">৳<?= number_format((float)$seg['bonus_amount'], 0) ?></td>
                                            <td class="text-amber-300 font-bold"><?= (int)$seg['probability'] ?>%</td>
                                            <td><span class="text-[10px] font-bold uppercase <?= (int)$seg['is_active'] === 1 ? 'text-emerald-400' : 'text-slate-500' ?>"><?= (int)$seg['is_active'] === 1 ? 'ACTIVE' : 'OFF' ?></span></td>
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
                                <?php endif; ?>
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
                    <span>Authorized Staff Admins</span>
                </button>
                <button type="button" onclick="switchAdminAccessSubView('adminaccess-firebase-sec')" id="pill-admin-firebase" class="py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition">
                    <i class="fa-solid fa-fire text-sm text-amber-400"></i>
                    <span>Firebase Setup</span>
                </button>
            </div>

            <div id="adminaccess-bot-sec" class="box-panel p-5 space-y-4 border-amber-500/20">
                <div class="pb-2 border-b border-slate-800">
                    <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-user-shield"></i> STORE STAFF &amp; BOT CLEARANCE
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
                    <?php if (empty($botAdminsList)): ?>
                        <p class="text-slate-500 text-center py-4 text-xs font-sans">No extra staff admins added for this store.</p>
                    <?php else: ?>
                        <?php foreach ($botAdminsList as $adm): ?>
                            <div class="bg-slate-900/60 p-3 rounded-xl border border-slate-800 flex items-center justify-between text-xs">
                                <div class="flex items-center space-x-2.5">
                                    <i class="fa-solid fa-shield text-cyan-400"></i>
                                    <span class="text-white font-sans font-bold"><?= htmlspecialchars($adm['admin_name']) ?></span>
                                    <span class="text-slate-400 text-xs">#<?= htmlspecialchars($adm['telegram_id']) ?></span>
                                </div>
                                <form method="POST" onsubmit="return confirm('Remove admin?');">
                                    <input type="hidden" name="admin_action" value="remove_bot_admin">
                                    <input type="hidden" name="active_tab" value="tab-adminaccess">
                                    <input type="hidden" name="telegram_id" value="<?= htmlspecialchars($adm['telegram_id']) ?>">
                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-600/20 text-red-400 border border-red-500/30 text-xs font-bold hover:bg-red-600 hover:text-white transition">
                                        REMOVE
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div id="adminaccess-firebase-sec" class="hidden box-panel p-5 space-y-5 border-cyan-500/30">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-black text-amber-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-fire text-amber-500 text-base"></i> FIREBASE CONFIGURATION (THIS STORE)
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                            Configure your store's Firebase credentials to enable Google 1-Tap / Popup Login on your storefront.
                        </span>
                    </div>
                </div>

                <form method="POST" class="bg-slate-900/80 p-5 rounded-2xl border border-slate-800 space-y-4 text-xs">
                    <input type="hidden" name="admin_action" value="save_firebase_settings">
                    <input type="hidden" name="active_tab" value="tab-adminaccess">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">apiKey</label>
                            <input type="text" name="firebase_api_key" value="<?= htmlspecialchars($settings['firebase_api_key'] ?? '') ?>" placeholder="AIzaSy..." class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-amber-300 font-mono outline-none focus:border-amber-400">
                        </div>
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">authDomain</label>
                            <input type="text" name="firebase_auth_domain" value="<?= htmlspecialchars($settings['firebase_auth_domain'] ?? '') ?>" placeholder="your-project.firebaseapp.com" class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-white font-mono outline-none focus:border-cyan-400">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">projectId</label>
                            <input type="text" name="firebase_project_id" value="<?= htmlspecialchars($settings['firebase_project_id'] ?? '') ?>" placeholder="your-project-id" class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-cyan-300 font-mono outline-none focus:border-cyan-400">
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
                            <input type="text" name="firebase_app_id" value="<?= htmlspecialchars($settings['firebase_app_id'] ?? '') ?>" placeholder="1:123456789012:web:abcdef..." class="w-full bg-[#030612] border border-slate-800 p-3 rounded-xl text-amber-300 font-mono outline-none focus:border-amber-400">
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
                        <i class="fa-solid fa-receipt"></i> THIS STORE SALES FEED
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
                                <th class="text-right">PRICE</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 text-[11px]">
                            <?php if (empty($sales)): ?>
                                <tr><td colspan="6" class="py-8 text-center text-slate-500 italic font-sans">No licenses issued on this store yet.</td></tr>
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
                                        <td class="text-right font-bold text-emerald-400">
                                            $<?= number_format((float)$s['price'], 2) ?>
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
                        <i class="fa-solid fa-file-invoice-dollar"></i> THIS STORE REVENUE TELEMETRY
                    </h3>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="stat-card flex flex-col justify-between h-32 border-emerald-500/30">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">TODAY REVENUE</span>
                        <h2 class="text-3xl font-black text-emerald-400 mt-1">$<?= number_format($todaySales, 2) ?></h2>
                        <span class="text-[10px] text-slate-500 font-sans">24h Completed Sales</span>
                    </div>

                    <div class="stat-card flex flex-col justify-between h-32 border-cyan-500/30">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">THIS MONTH REVENUE</span>
                        <h2 class="text-3xl font-black text-cyan-400 mt-1">$<?= number_format($monthSales, 2) ?></h2>
                        <span class="text-[10px] text-slate-500 font-sans">Monthly Rolling Cycle</span>
                    </div>

                    <div class="stat-card flex flex-col justify-between h-32 border-purple-500/30">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">ALL TIME REVENUE</span>
                        <h2 class="text-3xl font-black text-purple-400 mt-1">$<?= number_format($allTimeSales, 2) ?></h2>
                        <span class="text-[10px] text-slate-500 font-sans">Gross Store Settlement</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- 9. DEPOSITS & REWARDS TAB -->
        <section id="tab-deposits" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-2 grid grid-cols-2 gap-2 text-xs font-bold">
                <button type="button" onclick="switchDepositSubView('dep-verify-sec')" id="pill-dep-verify" class="py-3 px-3 rounded-xl bg-teal-500 text-slate-950 font-black flex items-center justify-center gap-2 shadow-sm transition">
                    <i class="fa-solid fa-receipt text-sm"></i>
                    <span>STORE CUSTOMER DEPOSITS</span>
                </button>
                <button type="button" onclick="switchDepositSubView('dep-rewards-sec')" id="pill-dep-rewards" class="py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition">
                    <i class="fa-solid fa-gift text-sm text-amber-400"></i>
                    <span>STORE DEPOSIT REWARDS</span>
                </button>
            </div>

            <!-- SECTION A: VERIFY DEPOSITS -->
            <div id="dep-verify-sec" class="box-panel p-5 space-y-4 border-teal-500/20">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-bold text-teal-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-wallet"></i> VERIFY CUSTOMER DEPOSITS
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">Review store customer deposits and wallet allocations.</span>
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
                                        No deposit transactions recorded on this store yet.
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
                                            <span class="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-cyan-500/15 text-cyan-300 border border-cyan-500/30">
                                                <?= htmlspecialchars($t['method']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong class="text-emerald-400 font-black text-xs">$<?= number_format((float)$t['amount'], 2) ?> USD</strong>
                                            <?php if (vpIsOrderTrx($t)): ?><span class="block mt-1 text-[9px] font-black text-fuchsia-300 uppercase">🛒 Product Order<?= (int)($t['order_qty'] ?? 0) > 1 ? ' ×' . (int)$t['order_qty'] : '' ?></span><?php endif; ?>
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
                                                    <?php $tIsOrder = vpIsOrderTrx($t); $tHasPlan = (int)($t['order_plan_id'] ?? 0) > 0; ?>
                                                    <?php if ($tIsOrder && $tHasPlan): ?>
                                                        <button type="submit" name="status" value="approved" onclick="return confirm('Verify the payment first. Approve = deliver the license key to this customer (balance will NOT change).');" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg font-bold text-[10px] tracking-wider uppercase transition shadow">
                                                            🔑 Deliver Key
                                                        </button>
                                                    <?php elseif ($tIsOrder): ?>
                                                        <button type="submit" name="status" value="credit_wallet" onclick="return confirm('Old order without plan info. Add this amount to the customer wallet so they can buy again?');" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-500 text-white rounded-lg font-bold text-[10px] tracking-wider uppercase transition shadow">
                                                            💰 Credit Wallet
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="submit" name="status" value="approved" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg font-bold text-[10px] tracking-wider uppercase transition shadow">
                                                            Approve
                                                        </button>
                                                    <?php endif; ?>
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

            <!-- SECTION B: DEPOSIT REWARDS / BONUS TIERS -->
            <div id="dep-rewards-sec" class="hidden box-panel p-5 space-y-4 border-amber-500/30">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-gift text-base"></i> STORE DEPOSIT REWARDS (BONUS TIERS &amp; USER LIMITS)
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">Configure automatic cashbacks when users deposit balance on your store and set usage limits per user.</span>
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
                                        No deposit reward tiers configured for this store yet. Click "+ ADD REWARD TIER".
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
                            <i class="fa-solid fa-user-group"></i> CUSTOMERS DIRECTORY (<?= $totalCustomers ?>)
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">Direct registered customers on your store platform. Manage balance, coins &amp; access.</span>
                    </div>
                </div>

                <div class="relative">
                    <input type="text" id="customerSearchInput" onkeyup="filterUserList('customer')" placeholder="Search by Username, Gmail or Telegram UID..." class="w-full bg-slate-900 border border-slate-800 text-xs text-white rounded-xl p-3 pl-10 outline-none focus:border-cyan-400 font-mono">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none"></i>
                </div>

                <div class="space-y-2.5" id="customerListContainer">
                    <?php if (empty($usersList)): ?>
                        <p class="text-center py-8 text-slate-500 text-xs italic font-sans font-mono">No customers registered on your store yet.</p>
                    <?php else: ?>
                        <?php foreach ($usersList as $u): 
                            $uRole = strtolower(trim((string)($u['role'] ?? 'customer')));
                            if ($uRole === 'reseller' || $uRole === 'admin') continue;
                            $uDisc = (float)($u['discount_percent'] ?? 0);
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
                                        <?php if ($uDisc > 0): ?>
                                            <span class="text-[9px] px-2 py-0.5 rounded font-black font-mono uppercase bg-purple-950 text-purple-300 border border-purple-500/30">
                                                +<?= $uDisc ?>% Stacked Disc
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
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 11. RESELLERS DIRECTORY TAB -->
        <section id="tab-resellers" class="tab-section hidden space-y-5">
            <!-- 💰 RESELLER PRICE MANAGER: প্রতিটা প্রোডাক্ট × প্রতিটা ভেরিয়েন্ট -->
            <div class="box-panel p-5 space-y-4 border-emerald-500/30">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-800 font-mono">
                    <div>
                        <h3 class="text-xs font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-tags"></i> RESELLER PRICE MANAGER
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">প্রতিটা প্রোডাক্টের প্রতিটা ভেরিয়েন্টের রিসেলার প্রাইস এখান থেকে এডিট করুন। সব রিসেলারের জন্য প্রযোজ্য (কারো কাস্টম প্রাইস থাকলে সেটাই আগে)।</span>
                    </div>
                    <span class="text-[10px] text-emerald-300 font-bold whitespace-nowrap">1 USD = ৳<?= htmlspecialchars((string)$effectiveBdtRate) ?></span>
                </div>

                <?php if (empty($productsData)): ?>
                    <p class="text-center py-6 text-slate-500 text-xs font-sans">No products yet.</p>
                <?php else: ?>
                <div class="flex flex-wrap items-center gap-2 bg-slate-900/80 p-3 rounded-xl border border-slate-800 text-xs font-mono">
                    <span class="text-slate-300 font-bold">⚡ Quick fill:</span>
                    <span class="text-slate-400">Reseller =</span>
                    <input type="number" id="rpQuickPercent" min="1" max="100" step="1" value="80" class="w-16 bg-[#030612] border border-slate-700 p-1.5 rounded-lg text-emerald-300 font-bold text-center outline-none">
                    <span class="text-slate-400">% of customer price</span>
                    <button type="button" onclick="rpQuickFill()" class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-[11px] uppercase">Fill All</button>
                    <div class="relative w-full sm:w-auto sm:ml-auto">
                        <input type="text" id="rpSearch" oninput="rpFilter(this.value)" placeholder="Search product..." class="w-full sm:w-48 bg-[#030612] border border-slate-700 p-1.5 pl-7 rounded-lg text-white outline-none text-xs">
                        <i class="fa-solid fa-magnifying-glass absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-500 text-[10px]"></i>
                    </div>
                </div>

                <form method="POST" class="space-y-3" id="resellerPriceForm">
                    <input type="hidden" name="admin_action" value="bulk_save_reseller_prices">
                    <input type="hidden" name="active_tab" value="tab-resellers">
                    <div class="space-y-3 max-h-[60vh] overflow-y-auto pr-1">
                        <?php foreach ($productsData as $rpProd): if (empty($rpProd['plans'])) continue; ?>
                            <div class="rp-prod-block rounded-2xl border border-slate-800 bg-[#050a1a] overflow-hidden" data-name="<?= htmlspecialchars(strtolower($rpProd['name'])) ?>">
                                <div class="px-3 py-2 bg-slate-900/80 flex items-center justify-between gap-2">
                                    <strong class="text-cyan-300 text-xs uppercase truncate"><?= htmlspecialchars($rpProd['name']) ?></strong>
                                    <span class="text-[9px] text-slate-500 font-mono whitespace-nowrap"><?= count($rpProd['plans']) ?> variants</span>
                                </div>
                                <div class="divide-y divide-slate-800/70">
                                    <?php foreach ($rpProd['plans'] as $rpPl):
                                        $rpUser = (float)($rpPl['user_price'] ?? 0);
                                        $rpRes  = (float)($rpPl['reseller_price'] ?? 0);
                                        $rpDisc = (float)($rpPl['reseller_discount_percent'] ?? 0);
                                        $rpId   = (int)$rpPl['id'];
                                    ?>
                                    <div class="rp-row p-2.5 grid grid-cols-2 sm:grid-cols-12 gap-2 items-center text-xs" data-user="<?= $rpUser ?>" data-plan="<?= $rpId ?>">
                                        <div class="col-span-2 sm:col-span-3 min-w-0">
                                            <span class="text-white font-bold block truncate"><?= htmlspecialchars($rpPl['plan_title'] ?: ($rpPl['validity'] ?? 'Plan')) ?></span>
                                            <span class="text-[10px] text-slate-400 font-mono">Customer: $<?= number_format($rpUser, 2) ?> <span class="text-slate-500">(৳<?= number_format($rpUser * $effectiveBdtRate, 0) ?>)</span></span>
                                        </div>
                                        <label class="sm:col-span-3 flex items-center bg-slate-900 border border-emerald-500/30 rounded-lg px-2 py-1">
                                            <span class="text-[10px] text-emerald-400 font-bold mr-1 whitespace-nowrap">Reseller $</span>
                                            <input type="number" step="0.01" min="0.01" name="rp_price[<?= $rpId ?>]" value="<?= $rpRes > 0 ? number_format($rpRes, 2, '.', '') : '' ?>" placeholder="<?= number_format($rpUser, 2, '.', '') ?>" oninput="rpCalc(<?= $rpId ?>)" id="rp_price_<?= $rpId ?>" class="w-full min-w-0 bg-transparent text-emerald-300 font-black text-right outline-none">
                                        </label>
                                        <label class="sm:col-span-2 flex items-center bg-slate-900 border border-purple-500/30 rounded-lg px-2 py-1">
                                            <span class="text-[10px] text-purple-400 font-bold mr-1">Disc%</span>
                                            <input type="number" step="0.5" min="0" max="99" name="rp_disc[<?= $rpId ?>]" value="<?= $rpDisc > 0 ? rtrim(rtrim(number_format($rpDisc, 2, '.', ''), '0'), '.') : '0' ?>" oninput="rpCalc(<?= $rpId ?>)" id="rp_disc_<?= $rpId ?>" class="w-full min-w-0 bg-transparent text-purple-300 font-bold text-right outline-none">
                                        </label>
                                        <div class="col-span-2 sm:col-span-4 text-right font-mono">
                                            <span class="text-[10px] text-slate-400">Reseller pays:</span>
                                            <strong id="rp_final_<?= $rpId ?>" class="text-amber-300 text-xs">—</strong>
                                            <span id="rp_margin_<?= $rpId ?>" class="block text-[9px] text-slate-500"></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="w-full py-3.5 btn-emerald rounded-xl uppercase text-xs font-black tracking-wider shadow-md">💾 Save All Reseller Prices</button>
                </form>
                <script>
                    const RP_BDT_RATE = <?= (float)$effectiveBdtRate ?>;
                    function rpCalc(id) {
                        const row = document.querySelector('.rp-row[data-plan="' + id + '"]');
                        if (!row) return;
                        const userP = parseFloat(row.dataset.user) || 0;
                        const pIn = document.getElementById('rp_price_' + id);
                        const dIn = document.getElementById('rp_disc_' + id);
                        const base = parseFloat(pIn.value) > 0 ? parseFloat(pIn.value) : userP;
                        const disc = Math.min(99, Math.max(0, parseFloat(dIn.value) || 0));
                        const fin = Math.max(0.01, base * (1 - disc / 100));
                        document.getElementById('rp_final_' + id).textContent = '$' + fin.toFixed(2) + ' (৳' + Math.round(fin * RP_BDT_RATE).toLocaleString() + ')';
                        const m = document.getElementById('rp_margin_' + id);
                        if (userP > 0) {
                            const off = Math.round((1 - fin / userP) * 100);
                            m.textContent = off > 0 ? off + '% cheaper than customer' : (off < 0 ? '⚠ costlier than customer price!' : 'same as customer');
                            m.className = 'block text-[9px] ' + (off < 0 ? 'text-rose-400' : 'text-slate-500');
                        }
                    }
                    function rpQuickFill() {
                        const pct = Math.min(100, Math.max(1, parseFloat(document.getElementById('rpQuickPercent').value) || 80));
                        if (!confirm('সব ভেরিয়েন্টের Reseller $ = Customer price এর ' + pct + '% বসানো হবে (Disc% = 0)। Save চাপলে সেভ হবে। Continue?')) return;
                        document.querySelectorAll('.rp-row').forEach(row => {
                            if (row.closest('.rp-prod-block').style.display === 'none') return;
                            const id = row.dataset.plan, userP = parseFloat(row.dataset.user) || 0;
                            if (userP <= 0) return;
                            document.getElementById('rp_price_' + id).value = (userP * pct / 100).toFixed(2);
                            document.getElementById('rp_disc_' + id).value = 0;
                            rpCalc(id);
                        });
                    }
                    function rpFilter(q) {
                        q = (q || '').toLowerCase().trim();
                        document.querySelectorAll('.rp-prod-block').forEach(b => { b.style.display = (!q || b.dataset.name.includes(q)) ? '' : 'none'; });
                    }
                    document.querySelectorAll('.rp-row').forEach(r => rpCalc(r.dataset.plan));
                </script>
                <?php endif; ?>
            </div>
            <div class="box-panel p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-3 font-mono">
                    <div>
                        <h3 class="text-xs font-bold text-purple-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-users-gear"></i> RESELLERS DIRECTORY (<?= $totalResellers ?>)
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">Wholesale resellers registered under your store with custom pricing and permissions. Manage balance &amp; coins.</span>
                    </div>
                </div>

                <div class="relative">
                    <input type="text" id="resellerSearchInput" onkeyup="filterUserList('reseller')" placeholder="Search Resellers by Username or UID..." class="w-full bg-slate-900 border border-slate-800 text-xs text-white rounded-xl p-3 pl-10 outline-none focus:border-cyan-400 font-mono">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none"></i>
                </div>

                <div class="space-y-2.5" id="resellerListContainer">
                    <?php if ($totalResellers === 0): ?>
                        <p class="text-center py-8 text-slate-500 text-xs italic font-sans font-mono">No resellers assigned under your store yet.</p>
                    <?php else: ?>
                        <?php foreach ($usersList as $u): 
                            $uRole = strtolower(trim((string)($u['role'] ?? '')));
                            if ($uRole !== 'reseller') continue; 
                            $uDisc = (float)($u['discount_percent'] ?? 0);
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
                                    <?php if ($uDisc > 0): ?>
                                        <span class="text-[9px] px-2 py-0.5 rounded font-black font-mono uppercase bg-purple-950 text-purple-300 border border-purple-500/30">
                                            +<?= $uDisc ?>% Stacked Disc
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
                <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 12. STORE SETTINGS TAB (WITH BOT, SUPPORT, REFERRAL & POPUPS) -->
        <section id="tab-settings" class="tab-section hidden space-y-6">
            
            <!-- A. BRANDING, MAINTENANCE & SUPPORT CONTACTS -->
            <div class="box-panel p-5 space-y-5">
                <div class="pb-3 border-b border-slate-800">
                    <h3 class="text-xs font-bold text-yellow-400 uppercase tracking-wider font-mono">
                        <i class="fa-solid fa-sliders text-yellow-400 mr-1.5"></i> STORE BRANDING, SUPPORT &amp; MAINTENANCE
                    </h3>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs font-mono">
                    <input type="hidden" name="admin_action" value="update_branding_settings">
                    <input type="hidden" name="active_tab" value="tab-settings">

                    <!-- Maintenance Mode with Dedicated Telegram Channel Link -->
                    <div class="p-4 rounded-2xl border border-amber-500/30 bg-amber-950/20 space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-amber-300 font-bold text-xs block">Store-Wide Maintenance Mode</span>
                                <span class="text-[10px] text-slate-400 font-sans">Blocks direct store access and redirects/reminds users to your Telegram channel.</span>
                            </div>
                            <input type="checkbox" name="site_maintenance_mode" value="1" <?= ($settings['site_maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?> class="rounded text-amber-500 scale-125 cursor-pointer">
                        </div>
                        <input type="text" name="site_maintenance_msg" value="<?= htmlspecialchars($settings['site_maintenance_msg'] ?? 'Store upgrade in progress.') ?>" placeholder="Maintenance Notice Message" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none font-sans text-xs">
                        <div>
                            <label class="text-amber-400 block mb-1 font-bold">Maintenance Telegram Channel/Group Link:</label>
                            <input type="url" name="maintenance_telegram_link" value="<?= htmlspecialchars($settings['maintenance_telegram_link'] ?? '') ?>" placeholder="https://t.me/YourStoreChannel" class="w-full bg-[#030612] border border-amber-500/40 p-2.5 rounded-xl text-amber-300 outline-none text-xs">
                        </div>
                    </div>

                    <!-- Store Support Settings (Direct Telegram & WhatsApp - NO REDIRECT TO MOTHER BOT) -->
                    <div class="p-4 rounded-2xl border border-cyan-500/30 bg-cyan-950/20 space-y-3">
                        <span class="text-xs font-bold text-cyan-300 uppercase block font-sans">
                            <i class="fa-solid fa-headset mr-1"></i> Dedicated Store Support (Your Own Telegram &amp; WhatsApp)
                        </span>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-cyan-400 block mb-1 font-bold">Your Telegram Username / Support Link</label>
                                <input type="text" name="support_telegram" value="<?= htmlspecialchars($settings['support_telegram'] ?? '') ?>" placeholder="https://t.me/your_telegram_username" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none focus:border-cyan-400">
                                <span class="text-[9px] text-slate-400 mt-1 block">Users will contact you directly instead of Mother Bot.</span>
                            </div>
                            <div>
                                <label class="text-emerald-400 block mb-1 font-bold">Your WhatsApp Number / Link</label>
                                <input type="text" name="support_whatsapp" value="<?= htmlspecialchars($settings['support_whatsapp'] ?? '') ?>" placeholder="+8801700000000 or WhatsApp link" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none focus:border-emerald-400">
                                <span class="text-[9px] text-slate-400 mt-1 block">Direct WhatsApp chat button for your store customers.</span>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-slate-300 block mb-1 font-bold">Store / Site Name</label>
                            <input type="text" name="site_name" value="<?= htmlspecialchars($panelTitle) ?>" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none font-bold text-xs">
                        </div>
                        <div>
                            <label class="text-emerald-400 block mb-1 font-bold">Official App APK Download URL</label>
                            <input type="url" name="app_download_url" value="<?= htmlspecialchars($settings['app_download_url'] ?? '') ?>" placeholder="https://example.com/downloads/app.apk" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-emerald-300 font-mono text-xs outline-none focus:border-emerald-400">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-2">
                            <label class="text-cyan-300 block font-bold">Header Store Logo</label>
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
                        <strong class="text-cyan-300 text-xs block font-bold uppercase">Store Social Media Channels</strong>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <input type="url" name="footer_telegram_link" value="<?= htmlspecialchars($settings['footer_telegram_link'] ?? '') ?>" placeholder="Telegram Channel Link" class="bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                            <input type="url" name="footer_whatsapp_link" value="<?= htmlspecialchars($settings['footer_whatsapp_link'] ?? '') ?>" placeholder="WhatsApp Link" class="bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                            <input type="url" name="footer_youtube_link" value="<?= htmlspecialchars($settings['footer_youtube_link'] ?? '') ?>" placeholder="YouTube Channel Link" class="bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Footer Copyright Notice</label>
                        <input type="text" name="footer_copyright_text" value="<?= htmlspecialchars($settings['footer_copyright_text'] ?? '') ?>" placeholder="&copy; 2026 My Store. All rights reserved." class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none">
                    </div>

                    <button type="submit" class="w-full py-3 btn-cyan rounded-xl uppercase font-bold text-xs shadow-md">
                        SAVE BRANDING &amp; SUPPORT SETTINGS
                    </button>
                </form>
            </div>

            <!-- B. TELEGRAM BOT NOTIFICATION INTEGRATION (FOR THIS SUB-STORE) -->
            <div class="box-panel p-5 space-y-4 border-cyan-500/30 bg-gradient-to-r from-[#031525] to-[#070d20]">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-black text-cyan-300 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-brands fa-telegram text-cyan-400 text-base"></i> YOUR STORE TELEGRAM BOT LIVE NOTIFICATIONS
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                            Connect your own Telegram Bot to receive live real-time alerts for your store's Deposits, Purchases &amp; Failed Orders.
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
                            <label class="text-slate-300 block mb-1 font-bold">Your Telegram Chat ID / Channel ID</label>
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

            <!-- C. REFERRAL & TOKEN REWARDS SYSTEM (SUB-ADMIN) -->
            <div class="box-panel p-5 space-y-4 border-amber-500/30 bg-gradient-to-r from-[#211202] to-[#070d20]">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-black text-amber-300 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-users text-amber-400 text-base"></i> REFERRAL &amp; TOKEN REWARD SYSTEM
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                            Configure how many reward tokens or cash bonus users receive for sharing referral link on your store.
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
                            Set display sequence on your store client view (e.g. 1=NONROOT, 2=ROOT, 3=IPHONE, 4=PC, 5=OTHER).
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
                                <tr><td colspan="3" class="py-4 text-center text-slate-500 font-sans">No custom categories added yet. Default sequence (ALL -> NONROOT -> ROOT -> IPHONE -> PC -> OTHER) applies.</td></tr>
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
                            Create custom promotional popup modals for your store with custom image, text, and Telegram/YouTube link.
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
                        <p class="text-slate-500 text-xs italic font-sans py-2">No custom popups created for this store yet.</p>
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
                    <?php if (empty($bannersList)): ?>
                        <p class="text-slate-500 text-xs italic col-span-4 py-3">No promotional banners uploaded for your store yet.</p>
                    <?php else: ?>
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
                    <?php endif; ?>
                </div>
            </div>

        </section>

        <!-- 13. PUSH NOTIFICATION TAB -->
        <section id="tab-push" class="tab-section hidden space-y-5">
            <div class="box-panel p-5 space-y-4">
                <div class="pb-3 border-b border-slate-800">
                    <h3 class="text-xs font-bold text-rose-400 uppercase tracking-wider font-mono flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> PUSH NOTIFICATION BROADCAST (THIS STORE)
                    </h3>
                </div>

                <form method="POST" class="space-y-3.5 text-xs font-mono">
                    <input type="hidden" name="admin_action" value="send_push_notification">
                    <input type="hidden" name="active_tab" value="tab-push">

                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Target Audience</label>
                        <select name="target_type" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-cyan-300 outline-none">
                            <option value="all">Broadcast to All Store Users</option>
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
                    <span>Store Deposit Channels (<?= count($paymentMethodsList) ?>)</span>
                </button>
                <button type="button" onclick="switchGatewaySubView('view-pm-rules')" id="pill-gw-rules" class="py-3 px-3 rounded-xl text-slate-400 hover:text-white flex items-center justify-center gap-2 transition">
                    <i class="fa-solid fa-sliders text-sm text-cyan-400"></i>
                    <span>SNZPay &amp; Gateway Rules</span>
                </button>
            </div>

            <!-- SUB-VIEW 1: DYNAMIC PAYMENT METHODS (bKash, Nagad, Rocket, QR, Banner, Texts) -->
            <div id="view-pm-methods" class="space-y-4">
                <div class="box-panel p-5 space-y-4 border-emerald-500/25">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-3">
                        <div>
                            <h3 class="text-xs font-black text-emerald-400 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-building-columns"></i> STORE PAYMENT CHANNELS MANAGER
                            </h3>
                            <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                                Configure this store's personal bKash, Nagad, Rocket, Binance, or custom channels with QR codes, banners &amp; notes.
                            </span>
                        </div>
                        <button type="button" onclick="openAddPaymentMethodModal()" class="px-4 py-2.5 rounded-xl btn-emerald text-xs uppercase tracking-wider flex items-center gap-2 shadow-sm transition active:scale-95">
                            <i class="fa-solid fa-plus text-xs"></i> <span>Add Payment Channel</span>
                        </button>
                    </div>

                    <!-- Payment Methods Table -->
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
                                            No payment methods registered for this store. Click "+ Add Payment Channel" to add one.
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

            <!-- SUB-VIEW 2: SNZPAY AUTO-PAYMENT API & STORE GATEWAY RULES -->
            <div id="view-pm-rules" class="hidden space-y-4">
                <div class="box-panel p-5 space-y-4 border-cyan-500/30 bg-gradient-to-r from-[#041a24] to-[#070d20]">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                        <div>
                            <h3 class="text-xs font-black text-cyan-300 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-bolt-lightning text-amber-400 text-sm"></i> STORE SNZPAY AUTO-VERIFICATION API
                            </h3>
                            <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                                Connect snzpay.digital license key for 100% automated instant verification of your store's bKash, Nagad &amp; Rocket TrxIDs!
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

                <!-- Store Deposit Limits & Currency Rates Form -->
                <div class="box-panel p-5 space-y-4 border-slate-800">
                    <div class="pb-2 border-b border-slate-800">
                        <h3 class="text-xs font-bold text-orange-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-scale-balanced"></i> STORE DEPOSIT LIMITS &amp; BANNER
                        </h3>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
                        <input type="hidden" name="admin_action" value="update_gateway_rules">
                        <input type="hidden" name="active_tab" value="tab-gateways">

                        <div class="stat-card flex items-center justify-between">
                            <div>
                                <span class="text-slate-200 font-bold text-xs block">Store Deposit System Master Switch</span>
                                <span class="text-[10px] text-slate-400 font-sans">Open or temporarily close the Add Fund system for your customers</span>
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

                        <div>
                            <label class="text-cyan-400 block mb-1 font-bold">Tutorial / Support Channel Link</label>
                            <input type="url" name="telegram_tutorial_link" value="<?= htmlspecialchars($settings['telegram_tutorial_link']) ?>" class="w-full bg-slate-900 border border-slate-800 p-3 rounded-xl text-white outline-none">
                        </div>

                        <div class="bg-slate-900/80 p-4 rounded-2xl border border-slate-800 space-y-3">
                            <label class="text-cyan-300 block font-bold">Store Default Payment Banner Photo</label>
                            <?php if (!empty($settings['payment_banner_url'])): ?>
                                <div class="w-full h-24 rounded-xl overflow-hidden border border-slate-800 relative mb-2">
                                    <img src="<?= htmlspecialchars($settings['payment_banner_url']) ?>" class="w-full h-full object-cover" alt="Banner">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="payment_banner_file" accept="image/*" class="w-full text-slate-400 file:mr-2 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:bg-cyan-500 file:text-black">
                            <span class="text-[9px] text-slate-500 block">Shown at the top of your store deposit screens.</span>
                        </div>

                        <button type="submit" class="w-full py-3.5 btn-emerald rounded-xl uppercase tracking-wider text-xs font-black shadow-md">
                            SAVE LIMITS &amp; EXCHANGE RATES
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <!-- 15. STORE THEME COLOR TAB -->
        <section id="tab-themecolor" class="tab-section hidden space-y-5 font-mono">
            <div class="box-panel p-5 space-y-5 border-emerald-500/30">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div>
                        <h3 class="text-xs font-black text-emerald-400 uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-palette text-sm"></i> STORE THEME COLOR CUSTOMIZER
                        </h3>
                        <span class="text-[10px] text-slate-400 font-sans mt-0.5 block">
                            Pick an accent brand color specifically for this storefront.
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
                        SAVE STORE COLOR
                    </button>
                </form>
            </div>
        </section>

    </main>

    <!-- =============================================================== -->
    <!-- ALL MODALS SECTION                                              -->
    <!-- =============================================================== -->

    <!-- MODAL: ADD / EDIT DYNAMIC PAYMENT METHOD (FOR THIS SUBDOMAIN) -->
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

                <!-- Custom Instructions / Note -->
                <div>
                    <label class="text-slate-300 block mb-1 font-bold">Payment Instructions (Shown to User)</label>
                    <textarea id="editPmInstructions" name="instructions" rows="2" placeholder="Send Money to the Personal number above, then enter the deposit amount and Transaction ID (TrxID) below to verify instantly." class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-white outline-none font-sans text-xs"></textarea>
                </div>

                <!-- Media Uploads: Logo, Banner, QR Image -->
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

                <!-- Toggles & Sort Order -->
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
            <p class="text-xs text-slate-400">All products and plans created under this store will be purged.</p>
            <form method="POST" class="space-y-2.5">
                <input type="hidden" name="admin_action" value="delete_all_products_confirmed">
                <input type="hidden" name="active_tab" value="tab-products">
                <button type="submit" class="w-full py-3 rounded-xl btn-rose uppercase text-xs">DELETE ALL</button>
                <button type="button" onclick="closeModal('allDeleteModal')" class="w-full py-2.5 rounded-xl bg-slate-900 text-slate-400 text-xs">Cancel</button>
            </form>
        </div>
    </div>

    <!-- MODAL 3: PRODUCT EDITOR (WITH COINS REWARD & COIN PRICE PER PLAN) -->
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

                <input type="hidden" name="reset_form_present" value="1">
                <div class="p-3 rounded-xl bg-[#030612] border border-cyan-500/20 grid grid-cols-1 sm:grid-cols-2 gap-2.5 items-center">
                    <label class="flex items-center justify-between gap-2 cursor-pointer">
                        <span class="text-white font-bold text-xs">🔄 HWID Reset Allowed</span>
                        <input type="checkbox" id="editModalResetEnabled" name="reset_enabled" value="1" class="rounded scale-110">
                    </label>
                    <div class="flex items-center gap-2">
                        <span class="text-slate-400 text-[11px] whitespace-nowrap">Reset API ID</span>
                        <input type="number" min="0" id="editModalKmApiId" name="km_api_id" placeholder="0 = default KM" class="w-full min-w-0 bg-slate-900 border border-slate-800 p-2 rounded-lg text-cyan-300 outline-none text-xs">
                    </div>
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
                    <label class="text-amber-400 block mb-1 font-bold">Maintenance Notice Message</label>
                    <input type="text" id="editModalMaintMsg" name="maintenance_msg" placeholder="e.g. V2.5 Update In Progress" class="w-full bg-[#030612] border border-slate-800 p-2.5 rounded-xl text-amber-300 outline-none text-xs">
                </div>

                <!-- Plan Prices and Coins Configuration -->
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
                        <input type="number" id="bonusClaimLimitInput" name="claim_limit" value="0" min="0" placeholder="0 = Unlimited" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-white font-bold outline-none">
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

    <!-- MODAL 7: USER ACCESS & PRICING -->
    <div id="userAccessModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4 font-mono">
        <div class="box-panel p-5 w-full max-w-lg space-y-4 border-cyan-500/40 max-h-[90vh] overflow-y-auto text-xs">
            <div class="flex justify-between items-center pb-2 border-b border-slate-800">
                <h3 class="text-xs font-bold text-white uppercase flex items-center gap-2">
                    <i class="fa-solid fa-user-gear text-cyan-400"></i> <span id="modalAccessUserName">User Settings</span>
                </h3>
                <button onclick="closeModal('userAccessModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="admin_action" value="save_user_custom_pricing_and_access">
                <input type="hidden" id="modalAccessUserId" name="target_user_id">
                <input type="hidden" id="modalAccessActiveTab" name="active_tab" value="tab-customers">

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-slate-300 block mb-1 font-bold">Account Role</label>
                        <select id="modalAccessUserRole" name="user_role" class="w-full bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-cyan-300 font-bold outline-none">
                            <option value="customer">Customer</option>
                            <option value="reseller">Reseller</option>
                        </select>
                    </div>

                    <div class="bg-[#030712] p-2.5 rounded-xl border border-slate-800 flex items-center justify-between">
                        <span class="text-white block text-xs font-bold">Add Fund Button</span>
                        <input type="checkbox" id="modalAccessCanAddFund" name="can_add_fund" value="1" class="rounded text-emerald-400 scale-125">
                    </div>
                </div>

                <div>
                    <label class="text-slate-300 block mb-1 font-bold">Product Permissions</label>
                    <div class="grid grid-cols-2 gap-2.5 bg-slate-900 p-3.5 rounded-2xl border border-slate-800 max-h-40 overflow-y-auto">
                        <?php foreach ($productsData as $pd): ?>
                            <label class="flex items-center space-x-2 text-[11px] text-slate-300 cursor-pointer">
                                <input type="checkbox" name="allowed_products[]" value="<?= $pd['id'] ?>" class="user-prod-checkbox rounded text-cyan-500 scale-110">
                                <span class="truncate"><?= htmlspecialchars($pd['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="text-emerald-400 block mb-1 font-bold">Custom Plan Prices ($)</label>
                    <div class="space-y-2 max-h-48 overflow-y-auto bg-slate-900 p-3.5 rounded-2xl border border-slate-800">
                        <?php foreach ($productsData as $pd): ?>
                            <div class="text-[10px] text-slate-400 font-bold uppercase mt-1"><?= htmlspecialchars($pd['name']) ?>:</div>
                            <?php foreach ($pd['plans'] as $pl): ?>
                                <div class="flex items-center justify-between gap-2.5 py-1.5 border-b border-slate-800">
                                    <span class="text-slate-300 text-xs truncate"><?= htmlspecialchars($pl['plan_title']) ?> (Def: $<?= number_format((float)$pl['user_price'], 2) ?>)</span>
                                    <input type="number" step="0.01" name="custom_plan_price[<?= $pl['id'] ?>]" id="custom_price_input_<?= $pl['id'] ?>" placeholder="Def: <?= $pl['user_price'] ?>" class="w-24 bg-[#030612] border border-slate-800 p-2 rounded-xl text-emerald-400 font-bold text-right outline-none">
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" class="w-full py-3 btn-cyan rounded-xl uppercase text-xs">SAVE CONFIGURATION</button>
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

            <!-- Modify Wallet Balance -->
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

            <!-- Modify Coins -->
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

            <!-- Ban Toggle -->
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

        function toggleAdminDrawer() {
            document.getElementById('adminDrawer').classList.toggle('translate-x-full');
            document.getElementById('drawerOverlay').classList.toggle('hidden');
        }

        function qpBdt(inp) {
            const rate = parseFloat(inp.dataset.rate) || 0, v = parseFloat(inp.value) || 0;
            const out = inp.parentElement.querySelector('.qp-bdt');
            if (out) out.textContent = v > 0 ? '≈ ৳' + Math.round(v * rate).toLocaleString() : 'same as customer';
        }
        function switchAdminTab(sectionId, titleName = '', btnEl = null) {
            document.querySelectorAll('.tab-section').forEach(sec => sec.classList.add('hidden'));
            const target = document.getElementById(sectionId);
            if (target) target.classList.remove('hidden');

            if (titleName) document.getElementById('headerSectionTitle').innerText = titleName;

            document.querySelectorAll('.drawer-nav-btn').forEach(btn => btn.classList.remove('active'));
            const activeBtn = btnEl || document.getElementById('btn-nav-' + sectionId);
            if (activeBtn) activeBtn.classList.add('active');

            sessionStorage.setItem('subadmin_active_tab', sectionId);
            sessionStorage.setItem('subadmin_active_title', titleName);

            if (!document.getElementById('adminDrawer').classList.contains('translate-x-full')) {
                toggleAdminDrawer();
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // GATEWAYS SUB-TAB SWITCHER (STORE CHANNELS & SNZPAY RULES)
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

        // DISCOUNT HUB SUB-TABS (ALL PRODUCTS / CUSTOMERS / RESELLERS / COUPONS)
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

            sessionStorage.setItem('sub_disc_active_subtab', viewId);
        }

        // Search Filter for Product Plans in Discount Hub
        function filterDiscProductPlans() {
            const query = document.getElementById('discPlanSearchInput').value.trim().toLowerCase();
            const rows = document.querySelectorAll('.disc-plan-row');
            rows.forEach(r => {
                const kw = r.getAttribute('data-keywords') || '';
                if (kw.includes(query)) r.classList.remove('hidden');
                else r.classList.add('hidden');
            });
        }

        // Search Filter for Users in Discount Hub
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

            fetch('sub_admin.php?action=fetch_km_catalog')
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
                    container.innerHTML = `<div class="p-4 text-center text-red-400 text-xs">${data.error || 'No items received from Key Manager.'}</div>`;
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
            document.getElementById('editModalIsPinned').checked = (parseInt(prod.is_pinned) === 1);
            if (document.getElementById('editModalMaintMsg')) document.getElementById('editModalMaintMsg').value = prod.maintenance_msg || '';
            if (document.getElementById('editModalResetEnabled')) document.getElementById('editModalResetEnabled').checked = (prod.reset_enabled === undefined || prod.reset_enabled === null) ? true : (parseInt(prod.reset_enabled) === 1);
            if (document.getElementById('editModalKmApiId')) document.getElementById('editModalKmApiId').value = parseInt(prod.km_api_id || 0) || '';

            const isActive = (parseInt(prod.is_active) === 1) && (parseInt(prod.is_maintenance || 0) !== 1);
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
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1 border-t border-slate-800/60">
                            <div>
                                <label class="text-[9px] text-emerald-300 block font-bold">🏪 Reseller $</label>
                                <input type="number" step="0.01" min="0.01" name="plan_reseller_price[${pl.id}]" value="${parseFloat(pl.reseller_price) > 0 ? parseFloat(pl.reseller_price).toFixed(2) : ''}" placeholder="${parseFloat(pl.user_price).toFixed(2)}" class="w-full bg-[#030612] border border-emerald-500/30 p-1.5 rounded-lg text-emerald-300 font-mono font-bold text-xs outline-none mt-0.5">
                                <span class="text-[9px] text-slate-500 font-mono">≈ ৳${Math.round((parseFloat(pl.reseller_price) || 0) * currentBdtRate)} BDT</span>
                            </div>
                            <div>
                                <label class="text-[9px] text-cyan-300 block font-bold">👤 Customer $</label>
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
            document.getElementById('modalAccessUserId').value = u.id;
            document.getElementById('modalAccessUserName').innerText = u.username;
            document.getElementById('modalAccessUserRole').value = u.role || 'customer';
            document.getElementById('modalAccessActiveTab').value = (u.role === 'reseller') ? 'tab-resellers' : 'tab-customers';
            document.getElementById('modalAccessCanAddFund').checked = (parseInt(u.can_add_fund !== undefined ? u.can_add_fund : 1) === 1);

            const allowed = userAllowedProdsMap[u.id] || [];
            document.querySelectorAll('.user-prod-checkbox').forEach(cb => {
                cb.checked = allowed.includes(parseInt(cb.value));
            });

            const cPrices = userCustomPricesMap[u.id] || {};
            globalProducts.forEach(p => {
                if (p.plans) {
                    p.plans.forEach(pl => {
                        const inp = document.getElementById('custom_price_input_' + pl.id);
                        if (inp) {
                            if (cPrices[pl.id] !== undefined && cPrices[pl.id] !== null && cPrices[pl.id] !== '') {
                                inp.value = parseFloat(cPrices[pl.id]).toFixed(2);
                            } else {
                                inp.value = '';
                            }
                        }
                    });
                }
            });

            document.getElementById('userAccessModal').classList.remove('hidden');
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

        window.addEventListener('DOMContentLoaded', () => {
            const postedTab = <?= json_encode(preg_replace('/[^a-z0-9_-]/i', '', (string)$activeTab)) ?>;
            let initialTab = postedTab !== 'tab-overview' ? postedTab : (sessionStorage.getItem('subadmin_active_tab') || 'tab-overview');
            if (!document.getElementById(initialTab)) initialTab = 'tab-overview';
            // টাইটেল সবসময় যে ট্যাব খোলা সেই বাটনের নাম থেকে আসবে (আগে পুরনো ট্যাবের নাম দেখাত)
            const navBtnForTitle = document.getElementById('btn-nav-' + initialTab);
            const initialTitle = navBtnForTitle ? navBtnForTitle.textContent.trim().replace(/\s+/g, ' ') : 'Dashboard Overview';
            sessionStorage.setItem('subadmin_active_tab', initialTab);
            
            document.querySelectorAll('.tab-section').forEach(sec => sec.classList.add('hidden'));
            const targetSection = document.getElementById(initialTab);
            if (targetSection) targetSection.classList.remove('hidden');
            document.getElementById('headerSectionTitle').innerText = initialTitle;

            document.querySelectorAll('.drawer-nav-btn').forEach(btn => btn.classList.remove('active'));
            const activeBtn = document.getElementById('btn-nav-' + initialTab);
            if (activeBtn) activeBtn.classList.add('active');

            if (initialTab === 'tab-discounts') {
                const discSub = <?= json_encode(preg_replace('/[^a-z0-9_-]/i', '', (string)($activeDiscSubTab ?? ''))) ?> || sessionStorage.getItem('sub_disc_active_subtab') || 'disc-view-products';
                switchDiscountSubTab(discSub);
            }

            <?php if ($toast): ?>
                showToast("<?= addslashes($toast['msg']) ?>", "<?= $toast['type'] ?>");
            <?php endif; ?>
        });
    </script>
</body>
</html>