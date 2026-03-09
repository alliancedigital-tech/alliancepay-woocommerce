<?php
if (!defined('ABSPATH')) exit;

use ALB\AllianceConfig;

class ALB_Payments {

    /** Створення таблиці (викликати під час активації) */
    public static function create_table() {
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
    }
}
