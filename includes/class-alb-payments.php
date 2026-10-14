<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

use ALB\AllianceConfig;

/**
 * Class ALB_Payments.
 */
class ALB_Payments
{

    private const SCHEMA_VERSION = 7;

    /** Створення таблиці (викликати під час активації) */
    public static function create_table()
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_alliance_checkout_integration_order = AllianceConfig::table_alliance_checkout_integration_order();
        $table_alliance_integration_order_refund = AllianceConfig::table_alliance_integration_order_refund();

        $sqlOrderIntegration = ("CREATE TABLE IF NOT EXISTS {$table_alliance_checkout_integration_order}
        (
            `order_id` INT(11) NOT NULL,
            `merchant_request_id` VARCHAR(255) NOT NULL,
            `hpp_order_id` VARCHAR(255) NOT NULL,
            `merchant_id` VARCHAR(255) NOT NULL,
            `coin_amount` INT(11) NOT NULL,
            `currency_code` INT(3) NULL,
            `conversion_rate` DECIMAL(10,4) NULL,
            `hpp_pay_type` VARCHAR(50) NOT NULL,
            `order_status` VARCHAR(50) NOT NULL,
            `payment_methods` TEXT NOT NULL,
            `create_date` DATETIME NOT NULL,
            `updated_at` DATETIME,
            `operation_id` VARCHAR(255),
            `ecom_order_id` VARCHAR(255),
            `is_callback_returned` BOOLEAN DEFAULT FALSE,
            `callback_data` LONGTEXT,
            `expired_order_date` DATETIME NOT NULL,
            `original_authorized_amount` INT(11) NULL,
            PRIMARY KEY (`order_id`),
            KEY `merchant_request_id` (`merchant_request_id`),
            KEY `hpp_order_id` (`hpp_order_id`),
            KEY `merchant_id` (`merchant_id`),
            KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $sqlOrderRefund = ("CREATE TABLE IF NOT EXISTS {$table_alliance_integration_order_refund}
        (
            `refund_id` INT(11) NOT NULL AUTO_INCREMENT,
            `order_id` INT(11) NOT NULL,
            `type` VARCHAR(255) NOT NULL,
            `rrn` VARCHAR(255) NOT NULL,
            `purpose` VARCHAR(255) NOT NULL,
            `comment` VARCHAR(255) NOT NULL,
            `coin_amount` INT(11) NOT NULL,
            `merchant_id` VARCHAR(255) NOT NULL,
            `operation_id` VARCHAR(255) NOT NULL,
            `ecom_operation_id` VARCHAR(255) NOT NULL,
            `merchant_name` VARCHAR(255) NULL,
            `approval_code` VARCHAR(255) NOT NULL,
            `status` VARCHAR(255) NOT NULL,
            `transaction_type` INT(11) NOT NULL,
            `merchant_request_id` VARCHAR(255) NOT NULL,
            `transaction_currency` VARCHAR(255) NOT NULL,
            `merchant_commission` INT(11) NULL,
            `create_date_time` DATETIME NOT NULL,
            `modification_date_time` DATETIME NOT NULL,
            `action_code` VARCHAR(255) NOT NULL,
            `response_code` VARCHAR(255) NOT NULL,
            `description` VARCHAR(255) NOT NULL,
            `processing_merchant_id` VARCHAR(255) NOT NULL,
            `processing_terminal_id` VARCHAR(255) NOT NULL,
            `transaction_response_info` TEXT NOT NULL,
            `bank_code` VARCHAR(255) NOT NULL,
            `payment_system` VARCHAR(255) NOT NULL,
            `product_type` VARCHAR(255) NOT NULL,
            `notification_url` VARCHAR(255) NOT NULL,
            `payment_service_type` VARCHAR(255) NOT NULL,
            `notification_encryption` VARCHAR(255) NOT NULL,
            `original_operation_id` VARCHAR(255) NOT NULL,
            `original_coin_amount` INT(11) NOT NULL,
            `original_ecom_operation_id` VARCHAR(255) NOT NULL,
            `rrn_original` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`refund_id`),
            KEY `merchant_request_id` (`merchant_request_id`),
            KEY `merchant_id` (`merchant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        dbDelta($sqlOrderIntegration);
        dbDelta($sqlOrderRefund);

        self::create_subscription_tables();
    }

    /**
     * @return void
     */
    public static function maybe_upgrade_schema(): void
    {
        if ((int)get_option('alb_schema_version', 0) >= self::SCHEMA_VERSION) {
            return;
        }

        $current = (int)get_option('alb_schema_version', 0);

        global $wpdb;
        $table = AllianceConfig::table_alliance_checkout_integration_order();

        if ($current < 2) {
            $col = $wpdb->get_results(
                "SHOW COLUMNS FROM `{$table}` LIKE 'original_authorized_amount'"
            );

            if (empty($col)) {
                $wpdb->query(
                    "ALTER TABLE `{$table}` ADD COLUMN `original_authorized_amount` INT(11) NULL"
                );
            }
        }

        if ($current < 3) {
            $col = $wpdb->get_results(
                "SHOW COLUMNS FROM `{$table}` LIKE 'currency_code'"
            );

            if (empty($col)) {
                $wpdb->query(
                    "ALTER TABLE `{$table}` ADD COLUMN `currency_code` INT(3) NULL AFTER `coin_amount`"
                );
            }
        }

        if ($current < 4) {
            $col = $wpdb->get_results(
                "SHOW COLUMNS FROM `{$table}` LIKE 'conversion_rate'"
            );

            if (empty($col)) {
                $wpdb->query(
                    "ALTER TABLE `{$table}` ADD COLUMN `conversion_rate` DECIMAL(10,4) NULL AFTER `currency_code`"
                );
            }
        }

        if ($current < 5) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            self::create_subscription_tables();
        }

        if ($current < 6) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            self::create_subscription_tables();
            self::add_subscription_plan_columns();
            self::add_subscription_rules_snapshot_column();
        }

        if ($current < 7) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            self::create_subscription_tables();
            self::add_subscription_sync_columns();
            self::widen_plan_id_columns();
        }

        update_option('alb_schema_version', self::SCHEMA_VERSION);
    }

    /**
     * @return void
     */
    private static function add_subscription_sync_columns(): void
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();

        $exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'sync_status'");
        if (empty($exists)) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN `sync_status` VARCHAR(20) NOT NULL DEFAULT 'synced' AFTER `active_subscriptions_count`"
            );
        }

        $exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'sync_error'");
        if (empty($exists)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `sync_error` TEXT NULL AFTER `sync_status`");
        }
    }

    /**
     * @return void
     */
    private static function widen_plan_id_columns(): void
    {
        global $wpdb;

        $plans_table = AllianceConfig::table_alliance_subscription_plans();
        $wpdb->query("ALTER TABLE `{$plans_table}` MODIFY COLUMN `plan_id` VARCHAR(64) NOT NULL");

        $subs_table = AllianceConfig::table_alliance_subscriptions();
        $wpdb->query("ALTER TABLE `{$subs_table}` MODIFY COLUMN `plan_id` VARCHAR(64) NOT NULL");
    }

    /**
     * @return void
     */
    private static function add_subscription_plan_columns(): void
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();

        $alters = [
            'product_id' => "ALTER TABLE `{$table}` ADD COLUMN `product_id` BIGINT(20) NULL AFTER `plan_id`",
            'template_id' => "ALTER TABLE `{$table}` ADD COLUMN `template_id` INT(11) NULL AFTER `product_id`",
            'plan_key' => "ALTER TABLE `{$table}` ADD COLUMN `plan_key` VARCHAR(64) NULL AFTER `template_id`",
            'is_active' => "ALTER TABLE `{$table}` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `plan_key`",
            'active_subscriptions_count' => "ALTER TABLE `{$table}` ADD COLUMN `active_subscriptions_count` INT(11) NOT NULL DEFAULT 0 AFTER `is_active`",
        ];

        foreach ($alters as $column => $sql) {
            $exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            if (empty($exists)) {
                $wpdb->query($sql);
            }
        }

        $has_unique = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = 'plan_key'");
        if (empty($has_unique)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `plan_key` (`plan_key`)");
        }
    }

    /**
     * @return void
     */
    private static function add_subscription_rules_snapshot_column(): void
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscriptions();

        $exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'rules_snapshot'");
        if (empty($exists)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `rules_snapshot` LONGTEXT NULL AFTER `plan_id`");
        }
    }

    /**
     */
    public static function create_subscription_tables(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_plans = AllianceConfig::table_alliance_subscription_plans();
        $sqlPlans = ("CREATE TABLE IF NOT EXISTS {$table_plans}
        (
            `id`                            INT(11) NOT NULL AUTO_INCREMENT,
            `plan_id`                       VARCHAR(64) NOT NULL,
            `product_id`                    BIGINT(20) NULL,
            `template_id`                   INT(11) NULL,
            `plan_key`                      VARCHAR(64) NULL,
            `is_active`                     TINYINT(1) NOT NULL DEFAULT 1,
            `active_subscriptions_count`    INT(11) NOT NULL DEFAULT 0,
            `sync_status`                   VARCHAR(20) NOT NULL DEFAULT 'synced',
            `sync_error`                    TEXT NULL,
            `version_id`                    INT(11) NOT NULL DEFAULT 1,
            `merchant_id`                   VARCHAR(36) NOT NULL,
            `name`                          VARCHAR(150) NOT NULL,
            `description`                   VARCHAR(255) NULL,
            `status`                        VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            `category_subcategory_indicator` VARCHAR(10) NOT NULL,
            `rules`                         LONGTEXT NOT NULL,
            `applies_date_time_from`        DATETIME NULL,
            `applies_date_time_to`          DATETIME NULL,
            `created_at`                    DATETIME NOT NULL,
            `updated_at`                    DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `plan_id` (`plan_id`),
            UNIQUE KEY `plan_key` (`plan_key`),
            KEY `status` (`status`),
            KEY `product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $table_subs = AllianceConfig::table_alliance_subscriptions();
        $sqlSubscriptions = ("CREATE TABLE IF NOT EXISTS {$table_subs}
        (
            `id`                  INT(11) NOT NULL AUTO_INCREMENT,
            `wc_order_id`         INT(11) NOT NULL,
            `plan_id`             VARCHAR(64) NOT NULL,
            `rules_snapshot`      LONGTEXT NULL,
            `hpp_order_id`        VARCHAR(64) NULL,
            `merchant_request_id` VARCHAR(50) NOT NULL,
            `status`              VARCHAR(30) NOT NULL DEFAULT 'PENDING',
            `customer_id`         BIGINT(20) NULL,
            `callback_data`       LONGTEXT NULL,
            `created_at`          DATETIME NOT NULL,
            `updated_at`          DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `wc_order_id` (`wc_order_id`),
            KEY `hpp_order_id` (`hpp_order_id`),
            KEY `plan_id` (`plan_id`),
            KEY `customer_id` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $table_scheduled = AllianceConfig::table_alliance_scheduled_payments();
        $sqlScheduled = ("CREATE TABLE IF NOT EXISTS {$table_scheduled}
        (
            `id`                   INT(11) NOT NULL AUTO_INCREMENT,
            `subscription_id`      INT(11) NOT NULL,
            `hpp_order_id`         VARCHAR(64) NOT NULL,
            `scheduled_payment_id` VARCHAR(64) NULL,
            `status`               VARCHAR(30) NOT NULL DEFAULT 'WAITING',
            `scheduled_date`       DATETIME NULL,
            `executed_at`          DATETIME NULL,
            `operation_id`         VARCHAR(64) NULL,
            `ecom_operation_id`    VARCHAR(64) NULL,
            `retry_count`          INT(11) NOT NULL DEFAULT 0,
            `created_at`           DATETIME NOT NULL,
            `updated_at`           DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `subscription_id` (`subscription_id`),
            KEY `hpp_order_id` (`hpp_order_id`),
            KEY `scheduled_payment_id` (`scheduled_payment_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $table_plan_version = AllianceConfig::table_alliance_subscription_plan_version();
        $sqlPlanVersion = ("CREATE TABLE IF NOT EXISTS {$table_plan_version}
        (
            `id`             INT(11) NOT NULL AUTO_INCREMENT,
            `plan_id`        VARCHAR(36) NOT NULL,
            `old_version_id` INT(11) NULL,
            `new_version_id` INT(11) NULL,
            `old_rules`      LONGTEXT NULL,
            `new_rules`      LONGTEXT NOT NULL,
            `applies_from`   DATETIME NULL,
            `reason`         VARCHAR(255) NULL,
            `created_by`     BIGINT(20) NULL,
            `created_at`     DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `plan_id` (`plan_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $table_templates = AllianceConfig::table_alliance_subscription_templates();
        $sqlTemplates = ("CREATE TABLE IF NOT EXISTS {$table_templates}
        (
            `id`                  INT(11) NOT NULL AUTO_INCREMENT,
            `code`                VARCHAR(50) NOT NULL,
            `name`                VARCHAR(150) NOT NULL,
            `category_indicator`  VARCHAR(10) NOT NULL,
            `rules_template`      LONGTEXT NOT NULL,
            `status`              VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_at`          DATETIME NOT NULL,
            `updated_at`          DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `code` (`code`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $table_audit = AllianceConfig::table_alliance_subscription_audit_log();
        $sqlAudit = ("CREATE TABLE IF NOT EXISTS {$table_audit}
        (
            `id`         INT(11) NOT NULL AUTO_INCREMENT,
            `action`     VARCHAR(50) NOT NULL,
            `plan_id`    VARCHAR(64) NULL,
            `product_id` BIGINT(20) NULL,
            `user_id`    BIGINT(20) NULL,
            `details`    LONGTEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `plan_id` (`plan_id`),
            KEY `action` (`action`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        dbDelta($sqlPlans);
        dbDelta($sqlSubscriptions);
        dbDelta($sqlScheduled);
        dbDelta($sqlPlanVersion);
        dbDelta($sqlTemplates);
        dbDelta($sqlAudit);
    }
}
