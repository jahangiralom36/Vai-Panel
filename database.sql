-- =====================================================================
--  VAI PANEL — DATABASE SCHEMA (index.php + mother_admin.php + sub_admin.php)
--  phpMyAdmin → আপনার ডাটাবেজ সিলেক্ট করুন → Import → এই ফাইল → Go
--
--  ✔ নতুন ডাটাবেজে: সব টেবিল তৈরি হবে
--  ✔ পুরনো (চালু) ডাটাবেজে: যে টেবিল/কলাম/ইনডেক্স নেই শুধু সেগুলো যোগ হবে —
--    কোনো ডাটা মুছবে না, যতবার খুশি আবার ইমপোর্ট করা যাবে
--  ✔ MySQL 5.7+ / 8.x এবং MariaDB 10.x দুটোতেই চলে
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1) TABLES (না থাকলে তৈরি হবে)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `banners` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `image_url` varchar(255) NOT NULL,
  `target_link` varchar(255) DEFAULT NULL,
  `title` varchar(150) DEFAULT 'Banner',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(50) DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `idx_vp_banner_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bot_admins` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `telegram_id` varchar(50) NOT NULL,
  `admin_name` varchar(150) DEFAULT 'Admin',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `telegram_id` (`telegram_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `coupon_usages` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `coupon_id` int(11) NOT NULL,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `discount_bdt` decimal(10,2) NOT NULL DEFAULT 0.00,
  `order_total_bdt` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cu_coupon_user` (`coupon_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `coupons` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `code` varchar(50) NOT NULL,
  `discount_type` enum('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `target_type` enum('all','specific') NOT NULL DEFAULT 'all',
  `product_ids` text DEFAULT NULL,
  `min_spend` decimal(10,2) NOT NULL DEFAULT 0.00,
  `expiry_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `max_uses` int(11) NOT NULL DEFAULT 0,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `per_user_limit` int(11) NOT NULL DEFAULT 1,
  `starts_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `max_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_role` varchar(20) NOT NULL DEFAULT 'all',
  `title` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `custom_popups` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `title` varchar(191) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `image_url` text DEFAULT NULL,
  `link_url` text DEFAULT NULL,
  `link_text` varchar(100) DEFAULT 'Open Link',
  `is_active` tinyint(1) DEFAULT 1,
  `show_once_per_session` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `deposit_bonuses` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `min_deposit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonus_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonus_type` enum('fixed','percentage') NOT NULL DEFAULT 'fixed',
  `title` varchar(191) NOT NULL DEFAULT 'Instant Bonus',
  `claim_limit` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gateway_transactions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `method` varchar(50) NOT NULL,
  `trx_id` varchar(100) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sender` varchar(100) DEFAULT NULL,
  `status` enum('unclaimed','claimed','expired') DEFAULT 'unclaimed',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_trx_status` (`trx_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `licenses` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `license_key` varchar(255) NOT NULL,
  `validity` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT 0.00,
  `source` varchar(50) DEFAULT 'PANEL',
  `status` varchar(20) DEFAULT 'active',
  `payment_type` varchar(20) DEFAULT 'balance',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `plan_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vp_lic_sub_user` (`subdomain_id`,`user_id`),
  KEY `idx_vp_lic_sub_created` (`subdomain_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `live_purchase_feed` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `masked_username` varchar(50) DEFAULT NULL,
  `product_name` varchar(191) DEFAULT NULL,
  `amount_bdt` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vp_feed_sub` (`subdomain_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_methods` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `method_key` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `account_number` varchar(100) NOT NULL,
  `account_type` varchar(50) NOT NULL DEFAULT 'Personal',
  `logo_url` text DEFAULT NULL,
  `banner_url` text DEFAULT NULL,
  `qr_image_url` text DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `allow_add_fund` tinyint(1) NOT NULL DEFAULT 1,
  `allow_checkout` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `product_categories` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `product_media_cache` (

  `product_name` varchar(191) NOT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `banner_url` varchar(255) DEFAULT NULL,
  `download_link` varchar(255) DEFAULT NULL,
  `setup_link` varchar(255) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `category` varchar(50) DEFAULT 'APKMOD',
  `description` text DEFAULT NULL,
  PRIMARY KEY (`product_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `product_plans` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `product_id` int(11) NOT NULL,
  `duration_days` int(11) DEFAULT 1,
  `plan_title` varchar(100) NOT NULL,
  `reseller_price` decimal(10,2) DEFAULT 0.00,
  `user_price` decimal(10,2) DEFAULT 0.00,
  `regular_price` decimal(10,2) DEFAULT 0.00,
  `discount_percent` decimal(5,2) DEFAULT 0.00,
  `reseller_discount_percent` decimal(5,2) DEFAULT 0.00,
  `duration_unit` varchar(20) DEFAULT 'days',
  `duration_value` int(11) DEFAULT 1,
  `api_plan_id` int(11) DEFAULT 0,
  `reward_coins` int(11) DEFAULT 0,
  `coin_price` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `mother_plan_id` int(11) DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `validity` varchar(150) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_vp_plan_prod` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `products` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `name` varchar(191) NOT NULL,
  `code` varchar(100) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `image_url` varchar(255) DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `banner_url` varchar(255) DEFAULT NULL,
  `download_link` varchar(255) DEFAULT NULL,
  `setup_link` varchar(255) DEFAULT NULL,
  `app_download_url` text DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `category` varchar(50) DEFAULT 'APKMOD',
  `description` text DEFAULT NULL,
  `is_pinned` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `maintenance_msg` text DEFAULT NULL,
  `maintenance_link` varchar(255) DEFAULT NULL,
  `is_maintenance` tinyint(1) NOT NULL DEFAULT 0,
  `reset_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `km_api_id` int(11) NOT NULL DEFAULT 0,
  `mother_product_id` int(11) DEFAULT 0,
  `sync_hash` varchar(64) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(50) DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `idx_vp_prod_sub` (`subdomain_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `referral_settings` (

  `subdomain_id` int(11) NOT NULL,
  `token_per_referral` int(11) DEFAULT 10,
  `referrer_bonus_bdt` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `reset_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `product_name` varchar(150) DEFAULT NULL,
  `license_key` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rl_user_time` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `spin_segments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `title` varchar(150) NOT NULL,
  `spend_target` decimal(12,2) NOT NULL DEFAULT 500.00,
  `bonus_amount` decimal(12,2) NOT NULL DEFAULT 50.00,
  `probability` int(11) NOT NULL DEFAULT 20,
  `color` varchar(30) NOT NULL DEFAULT '#d946ef',
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `stock_keys` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `product_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `license_key` varchar(255) NOT NULL,
  `status` enum('available','used') DEFAULT 'available',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `subdomain_admins` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL,
  `telegram_id` varchar(50) NOT NULL,
  `admin_name` varchar(150) DEFAULT 'Admin',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sub_tg_unique` (`subdomain_id`,`telegram_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `subdomain_deposit_bonuses` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL,
  `min_deposit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonus_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonus_type` enum('fixed','percentage') NOT NULL DEFAULT 'fixed',
  `title` varchar(191) NOT NULL DEFAULT 'Instant Bonus',
  `claim_limit` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `subdomain_settings` (

  `subdomain_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`subdomain_id`,`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `subdomain_spin_segments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `spend_target` decimal(12,2) NOT NULL DEFAULT 500.00,
  `bonus_amount` decimal(12,2) NOT NULL DEFAULT 50.00,
  `probability` int(11) NOT NULL DEFAULT 20,
  `color` varchar(30) NOT NULL DEFAULT '#d946ef',
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `subdomains` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `subdomain` varchar(100) NOT NULL,
  `site_title` varchar(150) DEFAULT 'Key Store',
  `theme_color` varchar(30) DEFAULT '#00f3ff',
  `site_logo` varchar(255) DEFAULT NULL,
  `loading_logo` varchar(255) DEFAULT NULL,
  `apk_url` text DEFAULT NULL,
  `admin_username` varchar(100) DEFAULT NULL,
  `admin_password` varchar(255) DEFAULT NULL,
  `support_link` varchar(255) DEFAULT NULL,
  `channel_link` varchar(255) DEFAULT NULL,
  `status` enum('active','suspended') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `support_telegram` text DEFAULT NULL,
  `support_whatsapp` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subdomain` (`subdomain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `system_notifications` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `target_type` varchar(20) DEFAULT 'all',
  `target_user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `scope` varchar(30) DEFAULT 'main_only',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vp_notif_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (

  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `telegram_bot_config` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `bot_token` varchar(255) DEFAULT NULL,
  `chat_id` varchar(100) DEFAULT NULL,
  `notify_deposit` tinyint(1) DEFAULT 1,
  `notify_purchase` tinyint(1) DEFAULT 1,
  `notify_failed` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_subdomain` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `transactions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `method` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `bonus_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sender_num` varchar(100) DEFAULT NULL,
  `trx_id` varchar(100) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `tx_type` varchar(20) NOT NULL DEFAULT 'deposit',
  `order_plan_id` int(11) DEFAULT NULL,
  `order_qty` int(11) DEFAULT NULL,
  `order_coupon` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vp_trx_user_sub` (`user_id`,`subdomain_id`),
  KEY `idx_vp_trx_type_status` (`subdomain_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_allowed_products` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_prod_unique` (`user_id`,`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_custom_prices` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `custom_price` decimal(10,2) NOT NULL,
  `is_manual` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_plan` (`user_id`,`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 💰 অ্যাডমিন Float/Coins অ্যাড-ডিডাক্ট লগ (প্রতিটা রিকোয়েস্ট একবারই চলবে — request_token UNIQUE)
CREATE TABLE IF NOT EXISTS `wallet_adjust_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `admin_name` varchar(100) DEFAULT NULL,
  `field` varchar(10) NOT NULL DEFAULT 'balance',
  `change_amount` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `before_value` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `after_value` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `request_token` varchar(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_request_token` (`request_token`),
  KEY `idx_sub_user` (`subdomain_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_spin_offers` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `segment_id` int(11) NOT NULL,
  `title` varchar(191) NOT NULL,
  `spend_target` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonus_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','claimed','expired') DEFAULT 'active',
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_spin_active` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `users` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `password` varchar(255) NOT NULL DEFAULT '',
  `role` varchar(20) NOT NULL DEFAULT 'customer',
  `balance` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `coins` int(11) NOT NULL DEFAULT 0,
  `firebase_uid` varchar(191) DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `subdomain_id` int(11) NOT NULL DEFAULT 0,
  `created_by_subdomain` varchar(100) DEFAULT 'main',
  `telegram_username` varchar(100) DEFAULT NULL,
  `custom_bdt_rate` decimal(10,2) DEFAULT 0.00,
  `can_add_fund` tinyint(1) DEFAULT 1,
  `is_product_restricted` tinyint(1) DEFAULT 0,
  `avatar_url` longtext DEFAULT NULL,
  `referral_code` varchar(20) DEFAULT NULL,
  `referred_by` int(11) DEFAULT 0,
  `referral_tokens` int(11) DEFAULT 0,
  `spin_spend_total` decimal(12,2) DEFAULT 0.00,
  `spin_count_available` int(11) DEFAULT 0,
  `discount_percent` decimal(5,2) DEFAULT 0.00,
  `telegram_chat_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
-- 2) পুরনো ডাটাবেজ আপডেট: যে কলাম / ইনডেক্স নেই সেগুলো যোগ করা
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS vp_add_column;
DROP PROCEDURE IF EXISTS vp_add_index;
DROP PROCEDURE IF EXISTS vp_drop_fk_on;

DELIMITER $$
CREATE PROCEDURE vp_add_column(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_def TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_col) THEN
        SET @vp_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_def);
        PREPARE vp_stmt FROM @vp_sql; EXECUTE vp_stmt; DEALLOCATE PREPARE vp_stmt;
    END IF;
END$$

CREATE PROCEDURE vp_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_def TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index) THEN
        SET @vp_sql = CONCAT('ALTER TABLE `', p_table, '` ', p_def);
        PREPARE vp_stmt FROM @vp_sql; EXECUTE vp_stmt; DEALLOCATE PREPARE vp_stmt;
    END IF;
END$$

CREATE PROCEDURE vp_drop_fk_on(IN p_table VARCHAR(64), IN p_col VARCHAR(64))
BEGIN
    DECLARE v_fk VARCHAR(64) DEFAULT NULL;
    SELECT CONSTRAINT_NAME INTO v_fk FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_col AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1;
    IF v_fk IS NOT NULL THEN
        SET @vp_sql = CONCAT('ALTER TABLE `', p_table, '` DROP FOREIGN KEY `', v_fk, '`');
        PREPARE vp_stmt FROM @vp_sql; EXECUTE vp_stmt; DEALLOCATE PREPARE vp_stmt;
    END IF;
END$$
DELIMITER ;



-- banners
CALL vp_add_column('banners', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('banners', 'image_url', '`image_url` varchar(255) NOT NULL');
CALL vp_add_column('banners', 'target_link', '`target_link` varchar(255) DEFAULT NULL');
CALL vp_add_column('banners', 'title', '`title` varchar(150) DEFAULT ''Banner''');
CALL vp_add_column('banners', 'sort_order', '`sort_order` int(11) DEFAULT 0');
CALL vp_add_column('banners', 'is_active', '`is_active` tinyint(1) DEFAULT 1');
CALL vp_add_column('banners', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');
CALL vp_add_column('banners', 'status', '`status` varchar(50) DEFAULT ''active''');

-- bot_admins
CALL vp_add_column('bot_admins', 'telegram_id', '`telegram_id` varchar(50) NOT NULL');
CALL vp_add_column('bot_admins', 'admin_name', '`admin_name` varchar(150) DEFAULT ''Admin''');
CALL vp_add_column('bot_admins', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- coupon_usages
CALL vp_add_column('coupon_usages', 'coupon_id', '`coupon_id` int(11) NOT NULL');
CALL vp_add_column('coupon_usages', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('coupon_usages', 'user_id', '`user_id` int(11) NOT NULL');
CALL vp_add_column('coupon_usages', 'product_id', '`product_id` int(11) DEFAULT NULL');
CALL vp_add_column('coupon_usages', 'discount_bdt', '`discount_bdt` decimal(10,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('coupon_usages', 'order_total_bdt', '`order_total_bdt` decimal(12,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('coupon_usages', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- coupons
CALL vp_add_column('coupons', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('coupons', 'code', '`code` varchar(50) NOT NULL');
CALL vp_add_column('coupons', 'discount_type', '`discount_type` enum(''percent'',''fixed'') NOT NULL DEFAULT ''percent''');
CALL vp_add_column('coupons', 'discount_value', '`discount_value` decimal(10,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('coupons', 'target_type', '`target_type` enum(''all'',''specific'') NOT NULL DEFAULT ''all''');
CALL vp_add_column('coupons', 'product_ids', '`product_ids` text DEFAULT NULL');
CALL vp_add_column('coupons', 'min_spend', '`min_spend` decimal(10,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('coupons', 'expiry_date', '`expiry_date` date DEFAULT NULL');
CALL vp_add_column('coupons', 'is_active', '`is_active` tinyint(1) NOT NULL DEFAULT 1');
CALL vp_add_column('coupons', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');
CALL vp_add_column('coupons', 'max_uses', '`max_uses` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('coupons', 'used_count', '`used_count` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('coupons', 'per_user_limit', '`per_user_limit` int(11) NOT NULL DEFAULT 1');
CALL vp_add_column('coupons', 'starts_at', '`starts_at` datetime DEFAULT NULL');
CALL vp_add_column('coupons', 'expires_at', '`expires_at` datetime DEFAULT NULL');
CALL vp_add_column('coupons', 'max_discount', '`max_discount` decimal(10,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('coupons', 'user_role', '`user_role` varchar(20) NOT NULL DEFAULT ''all''');
CALL vp_add_column('coupons', 'title', '`title` varchar(150) DEFAULT NULL');

-- custom_popups
CALL vp_add_column('custom_popups', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('custom_popups', 'title', '`title` varchar(191) DEFAULT NULL');
CALL vp_add_column('custom_popups', 'message', '`message` text DEFAULT NULL');
CALL vp_add_column('custom_popups', 'image_url', '`image_url` text DEFAULT NULL');
CALL vp_add_column('custom_popups', 'link_url', '`link_url` text DEFAULT NULL');
CALL vp_add_column('custom_popups', 'link_text', '`link_text` varchar(100) DEFAULT ''Open Link''');
CALL vp_add_column('custom_popups', 'is_active', '`is_active` tinyint(1) DEFAULT 1');
CALL vp_add_column('custom_popups', 'show_once_per_session', '`show_once_per_session` tinyint(1) DEFAULT 1');
CALL vp_add_column('custom_popups', 'sort_order', '`sort_order` int(11) DEFAULT 0');
CALL vp_add_column('custom_popups', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- deposit_bonuses
CALL vp_add_column('deposit_bonuses', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('deposit_bonuses', 'min_deposit', '`min_deposit` decimal(10,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('deposit_bonuses', 'bonus_amount', '`bonus_amount` decimal(10,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('deposit_bonuses', 'bonus_type', '`bonus_type` enum(''fixed'',''percentage'') NOT NULL DEFAULT ''fixed''');
CALL vp_add_column('deposit_bonuses', 'title', '`title` varchar(191) NOT NULL DEFAULT ''Instant Bonus''');
CALL vp_add_column('deposit_bonuses', 'claim_limit', '`claim_limit` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('deposit_bonuses', 'is_active', '`is_active` tinyint(1) NOT NULL DEFAULT 1');
CALL vp_add_column('deposit_bonuses', 'sort_order', '`sort_order` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('deposit_bonuses', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- gateway_transactions
CALL vp_add_column('gateway_transactions', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('gateway_transactions', 'method', '`method` varchar(50) NOT NULL');
CALL vp_add_column('gateway_transactions', 'trx_id', '`trx_id` varchar(100) NOT NULL');
CALL vp_add_column('gateway_transactions', 'amount', '`amount` decimal(12,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('gateway_transactions', 'sender', '`sender` varchar(100) DEFAULT NULL');
CALL vp_add_column('gateway_transactions', 'status', '`status` enum(''unclaimed'',''claimed'',''expired'') DEFAULT ''unclaimed''');
CALL vp_add_column('gateway_transactions', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- licenses
CALL vp_add_column('licenses', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('licenses', 'user_id', '`user_id` int(11) NOT NULL');
CALL vp_add_column('licenses', 'product_id', '`product_id` int(11) DEFAULT NULL');
CALL vp_add_column('licenses', 'license_key', '`license_key` varchar(255) NOT NULL');
CALL vp_add_column('licenses', 'validity', '`validity` varchar(100) DEFAULT NULL');
CALL vp_add_column('licenses', 'price', '`price` decimal(10,2) DEFAULT 0.00');
CALL vp_add_column('licenses', 'source', '`source` varchar(50) DEFAULT ''PANEL''');
CALL vp_add_column('licenses', 'status', '`status` varchar(20) DEFAULT ''active''');
CALL vp_add_column('licenses', 'payment_type', '`payment_type` varchar(20) DEFAULT ''balance''');
CALL vp_add_column('licenses', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');
CALL vp_add_column('licenses', 'updated_at', '`updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
CALL vp_add_column('licenses', 'plan_id', '`plan_id` int(11) DEFAULT NULL');

-- live_purchase_feed
CALL vp_add_column('live_purchase_feed', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('live_purchase_feed', 'masked_username', '`masked_username` varchar(50) DEFAULT NULL');
CALL vp_add_column('live_purchase_feed', 'product_name', '`product_name` varchar(191) DEFAULT NULL');
CALL vp_add_column('live_purchase_feed', 'amount_bdt', '`amount_bdt` decimal(10,2) DEFAULT NULL');
CALL vp_add_column('live_purchase_feed', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- payment_methods
CALL vp_add_column('payment_methods', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('payment_methods', 'method_key', '`method_key` varchar(50) NOT NULL');
CALL vp_add_column('payment_methods', 'name', '`name` varchar(100) NOT NULL');
CALL vp_add_column('payment_methods', 'account_number', '`account_number` varchar(100) NOT NULL');
CALL vp_add_column('payment_methods', 'account_type', '`account_type` varchar(50) NOT NULL DEFAULT ''Personal''');
CALL vp_add_column('payment_methods', 'logo_url', '`logo_url` text DEFAULT NULL');
CALL vp_add_column('payment_methods', 'banner_url', '`banner_url` text DEFAULT NULL');
CALL vp_add_column('payment_methods', 'qr_image_url', '`qr_image_url` text DEFAULT NULL');
CALL vp_add_column('payment_methods', 'instructions', '`instructions` text DEFAULT NULL');
CALL vp_add_column('payment_methods', 'is_active', '`is_active` tinyint(1) NOT NULL DEFAULT 1');
CALL vp_add_column('payment_methods', 'allow_add_fund', '`allow_add_fund` tinyint(1) NOT NULL DEFAULT 1');
CALL vp_add_column('payment_methods', 'allow_checkout', '`allow_checkout` tinyint(1) NOT NULL DEFAULT 1');
CALL vp_add_column('payment_methods', 'sort_order', '`sort_order` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('payment_methods', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- product_categories
CALL vp_add_column('product_categories', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('product_categories', 'name', '`name` varchar(100) NOT NULL');
CALL vp_add_column('product_categories', 'icon', '`icon` varchar(50) DEFAULT NULL');
CALL vp_add_column('product_categories', 'sort_order', '`sort_order` int(11) DEFAULT 0');
CALL vp_add_column('product_categories', 'is_active', '`is_active` tinyint(1) DEFAULT 1');

-- product_media_cache
CALL vp_add_column('product_media_cache', 'product_name', '`product_name` varchar(191) NOT NULL');
CALL vp_add_column('product_media_cache', 'logo_url', '`logo_url` varchar(255) DEFAULT NULL');
CALL vp_add_column('product_media_cache', 'banner_url', '`banner_url` varchar(255) DEFAULT NULL');
CALL vp_add_column('product_media_cache', 'download_link', '`download_link` varchar(255) DEFAULT NULL');
CALL vp_add_column('product_media_cache', 'setup_link', '`setup_link` varchar(255) DEFAULT NULL');
CALL vp_add_column('product_media_cache', 'tags', '`tags` varchar(255) DEFAULT NULL');
CALL vp_add_column('product_media_cache', 'category', '`category` varchar(50) DEFAULT ''APKMOD''');
CALL vp_add_column('product_media_cache', 'description', '`description` text DEFAULT NULL');

-- product_plans
CALL vp_add_column('product_plans', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('product_plans', 'product_id', '`product_id` int(11) NOT NULL');
CALL vp_add_column('product_plans', 'duration_days', '`duration_days` int(11) DEFAULT 1');
CALL vp_add_column('product_plans', 'plan_title', '`plan_title` varchar(100) NOT NULL');
CALL vp_add_column('product_plans', 'reseller_price', '`reseller_price` decimal(10,2) DEFAULT 0.00');
CALL vp_add_column('product_plans', 'user_price', '`user_price` decimal(10,2) DEFAULT 0.00');
CALL vp_add_column('product_plans', 'regular_price', '`regular_price` decimal(10,2) DEFAULT 0.00');
CALL vp_add_column('product_plans', 'discount_percent', '`discount_percent` decimal(5,2) DEFAULT 0.00');
CALL vp_add_column('product_plans', 'reseller_discount_percent', '`reseller_discount_percent` decimal(5,2) DEFAULT 0.00');
CALL vp_add_column('product_plans', 'duration_unit', '`duration_unit` varchar(20) DEFAULT ''days''');
CALL vp_add_column('product_plans', 'duration_value', '`duration_value` int(11) DEFAULT 1');
CALL vp_add_column('product_plans', 'api_plan_id', '`api_plan_id` int(11) DEFAULT 0');
CALL vp_add_column('product_plans', 'reward_coins', '`reward_coins` int(11) DEFAULT 0');
CALL vp_add_column('product_plans', 'coin_price', '`coin_price` int(11) DEFAULT 0');
CALL vp_add_column('product_plans', 'is_active', '`is_active` tinyint(1) DEFAULT 1');
CALL vp_add_column('product_plans', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');
CALL vp_add_column('product_plans', 'mother_plan_id', '`mother_plan_id` int(11) DEFAULT 0');
CALL vp_add_column('product_plans', 'is_deleted', '`is_deleted` tinyint(1) NOT NULL DEFAULT 0');
CALL vp_add_column('product_plans', 'sort_order', '`sort_order` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('product_plans', 'validity', '`validity` varchar(150) DEFAULT NULL');
CALL vp_add_column('product_plans', 'price', '`price` decimal(10,2) NOT NULL DEFAULT 0.00');

-- products
CALL vp_add_column('products', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('products', 'name', '`name` varchar(191) NOT NULL');
CALL vp_add_column('products', 'code', '`code` varchar(100) NOT NULL');
CALL vp_add_column('products', 'sort_order', '`sort_order` int(11) DEFAULT 0');
CALL vp_add_column('products', 'image_url', '`image_url` varchar(255) DEFAULT NULL');
CALL vp_add_column('products', 'logo_url', '`logo_url` varchar(255) DEFAULT NULL');
CALL vp_add_column('products', 'banner_url', '`banner_url` varchar(255) DEFAULT NULL');
CALL vp_add_column('products', 'download_link', '`download_link` varchar(255) DEFAULT NULL');
CALL vp_add_column('products', 'setup_link', '`setup_link` varchar(255) DEFAULT NULL');
CALL vp_add_column('products', 'app_download_url', '`app_download_url` text DEFAULT NULL');
CALL vp_add_column('products', 'tags', '`tags` varchar(255) DEFAULT NULL');
CALL vp_add_column('products', 'category', '`category` varchar(50) DEFAULT ''APKMOD''');
CALL vp_add_column('products', 'description', '`description` text DEFAULT NULL');
CALL vp_add_column('products', 'is_pinned', '`is_pinned` tinyint(1) DEFAULT 0');
CALL vp_add_column('products', 'is_active', '`is_active` tinyint(1) DEFAULT 1');
CALL vp_add_column('products', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');
CALL vp_add_column('products', 'maintenance_msg', '`maintenance_msg` text DEFAULT NULL');
CALL vp_add_column('products', 'maintenance_link', '`maintenance_link` varchar(255) DEFAULT NULL');
CALL vp_add_column('products', 'is_maintenance', '`is_maintenance` tinyint(1) NOT NULL DEFAULT 0');
CALL vp_add_column('products', 'reset_enabled', '`reset_enabled` tinyint(1) NOT NULL DEFAULT 1');
CALL vp_add_column('products', 'km_api_id', '`km_api_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('products', 'mother_product_id', '`mother_product_id` int(11) DEFAULT 0');
CALL vp_add_column('products', 'sync_hash', '`sync_hash` varchar(64) DEFAULT NULL');
CALL vp_add_column('products', 'is_deleted', '`is_deleted` tinyint(1) NOT NULL DEFAULT 0');
CALL vp_add_column('products', 'status', '`status` varchar(50) DEFAULT ''active''');

-- referral_settings
CALL vp_add_column('referral_settings', 'subdomain_id', '`subdomain_id` int(11) NOT NULL');
CALL vp_add_column('referral_settings', 'token_per_referral', '`token_per_referral` int(11) DEFAULT 10');
CALL vp_add_column('referral_settings', 'referrer_bonus_bdt', '`referrer_bonus_bdt` decimal(10,2) DEFAULT 0.00');
CALL vp_add_column('referral_settings', 'is_active', '`is_active` tinyint(1) DEFAULT 1');

-- reset_logs
CALL vp_add_column('reset_logs', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('reset_logs', 'user_id', '`user_id` int(11) NOT NULL');
CALL vp_add_column('reset_logs', 'product_name', '`product_name` varchar(150) DEFAULT NULL');
CALL vp_add_column('reset_logs', 'license_key', '`license_key` varchar(255) DEFAULT NULL');
CALL vp_add_column('reset_logs', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- spin_segments
CALL vp_add_column('spin_segments', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('spin_segments', 'title', '`title` varchar(150) NOT NULL');
CALL vp_add_column('spin_segments', 'spend_target', '`spend_target` decimal(12,2) NOT NULL DEFAULT 500.00');
CALL vp_add_column('spin_segments', 'bonus_amount', '`bonus_amount` decimal(12,2) NOT NULL DEFAULT 50.00');
CALL vp_add_column('spin_segments', 'probability', '`probability` int(11) NOT NULL DEFAULT 20');
CALL vp_add_column('spin_segments', 'color', '`color` varchar(30) NOT NULL DEFAULT ''#d946ef''');
CALL vp_add_column('spin_segments', 'is_active', '`is_active` tinyint(4) NOT NULL DEFAULT 1');
CALL vp_add_column('spin_segments', 'sort_order', '`sort_order` int(11) NOT NULL DEFAULT 0');

-- stock_keys
CALL vp_add_column('stock_keys', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('stock_keys', 'product_id', '`product_id` int(11) NOT NULL');
CALL vp_add_column('stock_keys', 'plan_id', '`plan_id` int(11) NOT NULL');
CALL vp_add_column('stock_keys', 'license_key', '`license_key` varchar(255) NOT NULL');
CALL vp_add_column('stock_keys', 'status', '`status` enum(''available'',''used'') DEFAULT ''available''');
CALL vp_add_column('stock_keys', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- subdomain_admins
CALL vp_add_column('subdomain_admins', 'subdomain_id', '`subdomain_id` int(11) NOT NULL');
CALL vp_add_column('subdomain_admins', 'telegram_id', '`telegram_id` varchar(50) NOT NULL');
CALL vp_add_column('subdomain_admins', 'admin_name', '`admin_name` varchar(150) DEFAULT ''Admin''');
CALL vp_add_column('subdomain_admins', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- subdomain_deposit_bonuses
CALL vp_add_column('subdomain_deposit_bonuses', 'subdomain_id', '`subdomain_id` int(11) NOT NULL');
CALL vp_add_column('subdomain_deposit_bonuses', 'min_deposit', '`min_deposit` decimal(10,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('subdomain_deposit_bonuses', 'bonus_amount', '`bonus_amount` decimal(10,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('subdomain_deposit_bonuses', 'bonus_type', '`bonus_type` enum(''fixed'',''percentage'') NOT NULL DEFAULT ''fixed''');
CALL vp_add_column('subdomain_deposit_bonuses', 'title', '`title` varchar(191) NOT NULL DEFAULT ''Instant Bonus''');
CALL vp_add_column('subdomain_deposit_bonuses', 'claim_limit', '`claim_limit` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('subdomain_deposit_bonuses', 'is_active', '`is_active` tinyint(1) NOT NULL DEFAULT 1');
CALL vp_add_column('subdomain_deposit_bonuses', 'sort_order', '`sort_order` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('subdomain_deposit_bonuses', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- subdomain_settings
CALL vp_add_column('subdomain_settings', 'subdomain_id', '`subdomain_id` int(11) NOT NULL');
CALL vp_add_column('subdomain_settings', 'setting_key', '`setting_key` varchar(100) NOT NULL');
CALL vp_add_column('subdomain_settings', 'setting_value', '`setting_value` text DEFAULT NULL');

-- subdomain_spin_segments
CALL vp_add_column('subdomain_spin_segments', 'subdomain_id', '`subdomain_id` int(11) NOT NULL');
CALL vp_add_column('subdomain_spin_segments', 'title', '`title` varchar(150) NOT NULL');
CALL vp_add_column('subdomain_spin_segments', 'spend_target', '`spend_target` decimal(12,2) NOT NULL DEFAULT 500.00');
CALL vp_add_column('subdomain_spin_segments', 'bonus_amount', '`bonus_amount` decimal(12,2) NOT NULL DEFAULT 50.00');
CALL vp_add_column('subdomain_spin_segments', 'probability', '`probability` int(11) NOT NULL DEFAULT 20');
CALL vp_add_column('subdomain_spin_segments', 'color', '`color` varchar(30) NOT NULL DEFAULT ''#d946ef''');
CALL vp_add_column('subdomain_spin_segments', 'is_active', '`is_active` tinyint(4) NOT NULL DEFAULT 1');
CALL vp_add_column('subdomain_spin_segments', 'sort_order', '`sort_order` int(11) NOT NULL DEFAULT 0');

-- subdomains
CALL vp_add_column('subdomains', 'user_id', '`user_id` int(11) DEFAULT NULL');
CALL vp_add_column('subdomains', 'subdomain', '`subdomain` varchar(100) NOT NULL');
CALL vp_add_column('subdomains', 'site_title', '`site_title` varchar(150) DEFAULT ''Key Store''');
CALL vp_add_column('subdomains', 'theme_color', '`theme_color` varchar(30) DEFAULT ''#00f3ff''');
CALL vp_add_column('subdomains', 'site_logo', '`site_logo` varchar(255) DEFAULT NULL');
CALL vp_add_column('subdomains', 'loading_logo', '`loading_logo` varchar(255) DEFAULT NULL');
CALL vp_add_column('subdomains', 'apk_url', '`apk_url` text DEFAULT NULL');
CALL vp_add_column('subdomains', 'admin_username', '`admin_username` varchar(100) DEFAULT NULL');
CALL vp_add_column('subdomains', 'admin_password', '`admin_password` varchar(255) DEFAULT NULL');
CALL vp_add_column('subdomains', 'support_link', '`support_link` varchar(255) DEFAULT NULL');
CALL vp_add_column('subdomains', 'channel_link', '`channel_link` varchar(255) DEFAULT NULL');
CALL vp_add_column('subdomains', 'status', '`status` enum(''active'',''suspended'') DEFAULT ''active''');
CALL vp_add_column('subdomains', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');
CALL vp_add_column('subdomains', 'support_telegram', '`support_telegram` text DEFAULT NULL');
CALL vp_add_column('subdomains', 'support_whatsapp', '`support_whatsapp` text DEFAULT NULL');

-- system_notifications
CALL vp_add_column('system_notifications', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('system_notifications', 'target_type', '`target_type` varchar(20) DEFAULT ''all''');
CALL vp_add_column('system_notifications', 'target_user_id', '`target_user_id` int(11) DEFAULT NULL');
CALL vp_add_column('system_notifications', 'title', '`title` varchar(255) NOT NULL');
CALL vp_add_column('system_notifications', 'message', '`message` text NOT NULL');
CALL vp_add_column('system_notifications', 'scope', '`scope` varchar(30) DEFAULT ''main_only''');
CALL vp_add_column('system_notifications', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- system_settings
CALL vp_add_column('system_settings', 'setting_key', '`setting_key` varchar(100) NOT NULL');
CALL vp_add_column('system_settings', 'setting_value', '`setting_value` text DEFAULT NULL');

-- telegram_bot_config
CALL vp_add_column('telegram_bot_config', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('telegram_bot_config', 'bot_token', '`bot_token` varchar(255) DEFAULT NULL');
CALL vp_add_column('telegram_bot_config', 'chat_id', '`chat_id` varchar(100) DEFAULT NULL');
CALL vp_add_column('telegram_bot_config', 'notify_deposit', '`notify_deposit` tinyint(1) DEFAULT 1');
CALL vp_add_column('telegram_bot_config', 'notify_purchase', '`notify_purchase` tinyint(1) DEFAULT 1');
CALL vp_add_column('telegram_bot_config', 'notify_failed', '`notify_failed` tinyint(1) DEFAULT 1');
CALL vp_add_column('telegram_bot_config', 'is_active', '`is_active` tinyint(1) DEFAULT 1');
CALL vp_add_column('telegram_bot_config', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- transactions
CALL vp_add_column('transactions', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('transactions', 'user_id', '`user_id` int(11) NOT NULL');
CALL vp_add_column('transactions', 'method', '`method` varchar(50) NOT NULL');
CALL vp_add_column('transactions', 'amount', '`amount` decimal(10,2) NOT NULL');
CALL vp_add_column('transactions', 'bonus_amount', '`bonus_amount` decimal(10,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('transactions', 'sender_num', '`sender_num` varchar(100) DEFAULT NULL');
CALL vp_add_column('transactions', 'trx_id', '`trx_id` varchar(100) NOT NULL');
CALL vp_add_column('transactions', 'status', '`status` enum(''pending'',''approved'',''rejected'') DEFAULT ''pending''');
CALL vp_add_column('transactions', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');
CALL vp_add_column('transactions', 'tx_type', '`tx_type` varchar(20) NOT NULL DEFAULT ''deposit''');
CALL vp_add_column('transactions', 'order_plan_id', '`order_plan_id` int(11) DEFAULT NULL');
CALL vp_add_column('transactions', 'order_qty', '`order_qty` int(11) DEFAULT NULL');
CALL vp_add_column('transactions', 'order_coupon', '`order_coupon` varchar(50) DEFAULT NULL');

-- user_allowed_products
CALL vp_add_column('user_allowed_products', 'user_id', '`user_id` int(11) NOT NULL');
CALL vp_add_column('user_allowed_products', 'product_id', '`product_id` int(11) NOT NULL');

-- user_custom_prices
CALL vp_add_column('user_custom_prices', 'user_id', '`user_id` int(11) NOT NULL');
CALL vp_add_column('user_custom_prices', 'plan_id', '`plan_id` int(11) NOT NULL');
CALL vp_add_column('user_custom_prices', 'custom_price', '`custom_price` decimal(10,2) NOT NULL');
CALL vp_add_column('user_custom_prices', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');
-- is_manual = 1: অ্যাডমিনের হাতে বসানো দাম; 0 = পুরনো অটো-হিসাব (স্টোর এখন লাইভ হিসাব করে, তাই এগুলো আর ব্যবহার হয় না)
-- price_locked = 1: অ্যাডমিন হাতে দাম/নাম বদলেছে → Key Manager সিঙ্ক আর ওভাররাইট করবে না
CALL vp_add_column('product_plans', 'price_locked', '`price_locked` tinyint(1) NOT NULL DEFAULT 0');
CALL vp_add_column('user_custom_prices', 'is_manual', '`is_manual` tinyint(1) NOT NULL DEFAULT 0');

-- user_spin_offers
CALL vp_add_column('user_spin_offers', 'user_id', '`user_id` int(11) NOT NULL');
CALL vp_add_column('user_spin_offers', 'segment_id', '`segment_id` int(11) NOT NULL');
CALL vp_add_column('user_spin_offers', 'title', '`title` varchar(191) NOT NULL');
CALL vp_add_column('user_spin_offers', 'spend_target', '`spend_target` decimal(10,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('user_spin_offers', 'bonus_amount', '`bonus_amount` decimal(10,2) NOT NULL DEFAULT 0.00');
CALL vp_add_column('user_spin_offers', 'status', '`status` enum(''active'',''claimed'',''expired'') DEFAULT ''active''');
CALL vp_add_column('user_spin_offers', 'expires_at', '`expires_at` datetime DEFAULT NULL');
CALL vp_add_column('user_spin_offers', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');

-- users
CALL vp_add_column('users', 'username', '`username` varchar(100) NOT NULL');
CALL vp_add_column('users', 'email', '`email` varchar(191) DEFAULT NULL');
CALL vp_add_column('users', 'password', '`password` varchar(255) NOT NULL DEFAULT ''''');
CALL vp_add_column('users', 'role', '`role` varchar(20) NOT NULL DEFAULT ''customer''');
CALL vp_add_column('users', 'balance', '`balance` decimal(14,4) NOT NULL DEFAULT 0.0000');
CALL vp_add_column('users', 'status', '`status` varchar(20) NOT NULL DEFAULT ''active''');
CALL vp_add_column('users', 'created_at', '`created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP');
CALL vp_add_column('users', 'coins', '`coins` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('users', 'firebase_uid', '`firebase_uid` varchar(191) DEFAULT NULL');
CALL vp_add_column('users', 'created_by', '`created_by` int(11) DEFAULT 0');
CALL vp_add_column('users', 'subdomain_id', '`subdomain_id` int(11) NOT NULL DEFAULT 0');
CALL vp_add_column('users', 'created_by_subdomain', '`created_by_subdomain` varchar(100) DEFAULT ''main''');
CALL vp_add_column('users', 'telegram_username', '`telegram_username` varchar(100) DEFAULT NULL');
CALL vp_add_column('users', 'custom_bdt_rate', '`custom_bdt_rate` decimal(10,2) DEFAULT 0.00');
CALL vp_add_column('users', 'can_add_fund', '`can_add_fund` tinyint(1) DEFAULT 1');
CALL vp_add_column('users', 'is_product_restricted', '`is_product_restricted` tinyint(1) DEFAULT 0');
CALL vp_add_column('users', 'avatar_url', '`avatar_url` longtext DEFAULT NULL');
CALL vp_add_column('users', 'referral_code', '`referral_code` varchar(20) DEFAULT NULL');
CALL vp_add_column('users', 'referred_by', '`referred_by` int(11) DEFAULT 0');
CALL vp_add_column('users', 'referral_tokens', '`referral_tokens` int(11) DEFAULT 0');
CALL vp_add_column('users', 'spin_spend_total', '`spin_spend_total` decimal(12,2) DEFAULT 0.00');
CALL vp_add_column('users', 'spin_count_available', '`spin_count_available` int(11) DEFAULT 0');
CALL vp_add_column('users', 'discount_percent', '`discount_percent` decimal(5,2) DEFAULT 0.00');
CALL vp_add_column('users', 'telegram_chat_id', '`telegram_chat_id` bigint(20) DEFAULT NULL');

-- indexes
CALL vp_add_index('banners', 'idx_vp_banner_sub', 'ADD INDEX `idx_vp_banner_sub` (`subdomain_id`)');
CALL vp_add_index('bot_admins', 'telegram_id', 'ADD UNIQUE INDEX `telegram_id` (`telegram_id`)');
CALL vp_add_index('coupon_usages', 'idx_cu_coupon_user', 'ADD INDEX `idx_cu_coupon_user` (`coupon_id`,`user_id`)');
CALL vp_add_index('gateway_transactions', 'idx_trx_status', 'ADD INDEX `idx_trx_status` (`trx_id`,`status`)');
CALL vp_add_index('licenses', 'idx_vp_lic_sub_user', 'ADD INDEX `idx_vp_lic_sub_user` (`subdomain_id`,`user_id`)');
CALL vp_add_index('licenses', 'idx_vp_lic_sub_created', 'ADD INDEX `idx_vp_lic_sub_created` (`subdomain_id`,`created_at`)');
CALL vp_add_index('live_purchase_feed', 'idx_vp_feed_sub', 'ADD INDEX `idx_vp_feed_sub` (`subdomain_id`,`id`)');
CALL vp_add_index('product_plans', 'idx_vp_plan_prod', 'ADD INDEX `idx_vp_plan_prod` (`product_id`)');
CALL vp_add_index('products', 'idx_vp_prod_sub', 'ADD INDEX `idx_vp_prod_sub` (`subdomain_id`,`is_active`)');
CALL vp_add_index('reset_logs', 'idx_rl_user_time', 'ADD INDEX `idx_rl_user_time` (`user_id`,`created_at`)');
CALL vp_add_index('subdomain_admins', 'sub_tg_unique', 'ADD UNIQUE INDEX `sub_tg_unique` (`subdomain_id`,`telegram_id`)');
CALL vp_add_index('subdomains', 'subdomain', 'ADD UNIQUE INDEX `subdomain` (`subdomain`)');
CALL vp_add_index('system_notifications', 'idx_vp_notif_sub', 'ADD INDEX `idx_vp_notif_sub` (`subdomain_id`)');
CALL vp_add_index('telegram_bot_config', 'uniq_subdomain', 'ADD UNIQUE INDEX `uniq_subdomain` (`subdomain_id`)');
CALL vp_add_index('transactions', 'idx_vp_trx_user_sub', 'ADD INDEX `idx_vp_trx_user_sub` (`user_id`,`subdomain_id`)');
CALL vp_add_index('transactions', 'idx_vp_trx_type_status', 'ADD INDEX `idx_vp_trx_type_status` (`subdomain_id`,`status`)');
CALL vp_add_index('user_allowed_products', 'user_prod_unique', 'ADD UNIQUE INDEX `user_prod_unique` (`user_id`,`product_id`)');
CALL vp_add_index('user_custom_prices', 'idx_user_plan', 'ADD INDEX `idx_user_plan` (`user_id`,`plan_id`)');
CALL vp_add_index('user_spin_offers', 'idx_user_spin_active', 'ADD INDEX `idx_user_spin_active` (`user_id`,`status`)');

-- ---------------------------------------------------------------------
-- 3) পুরনো কলাম ঠিক করা
-- ---------------------------------------------------------------------
ALTER TABLE `users` MODIFY `avatar_url` LONGTEXT NULL;          -- বড় প্রোফাইল ছবি
CALL vp_drop_fk_on('subdomains', 'user_id');                     -- সাব-ডোমেইন তৈরি আটকে যেত
ALTER TABLE `subdomains` MODIFY `user_id` INT NULL DEFAULT NULL;

DROP PROCEDURE IF EXISTS vp_add_column;
DROP PROCEDURE IF EXISTS vp_add_index;
DROP PROCEDURE IF EXISTS vp_drop_fk_on;

SET FOREIGN_KEY_CHECKS = 1;
-- ✅ শেষ
