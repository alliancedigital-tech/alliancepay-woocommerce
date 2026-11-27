<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class AllianceConfig.
 */
class AllianceConfig
{
    public const ALLIANCE_PAYMENT_CODE = 'alliance_pay';

    public const REFUND_DATA_FIELD_MERCHANT_REQUEST_ID = 'merchantRequestId';

    public const REFUND_DATA_FIELD_OPERATION_ID = 'operationId';

    public const REFUND_DATA_FIELD_MERCHANT_ID = 'merchantId';

    public const REFUND_DATA_FIELD_COIN_AMOUNT = 'coinAmount';

    public const REFUND_DATA_FIELD_NOTIFICATION_URL = 'notificationUrl';

    public const REFUND_DATA_FIELD_DATE = 'date';

    // HPP Payment Types
    public const HPP_PAY_TYPE_PURCHASE = 'PURCHASE';

    public const HPP_PAY_TYPE_A2A = 'A2A';

    public const HPP_PAY_TYPE_PREAUTH = 'PREAUTH';

    // Order statuses (від банку)
    public const ORDER_STATUS_SUCCESS = 'SUCCESS';

    public const ORDER_STATUS_FAIL = 'FAIL';

    public const ORDER_STATUS_PENDING = 'PENDING';

    // Типи операцій
    public const OPERATION_TYPE_A2A = 'ACCOUNT_2_ACCOUNT';

    public const OPERATION_TYPE_PURCHASE = 'PURCHASE';

    public const OPERATION_TYPE_REFUND = 'REFUND';

    public const OPERATION_TYPE_PREAUTH = 'PREAUTH';

    public const OPERATION_TYPE_COMPLETION = 'COMPLETION';

    // ===== Subscription Plan Statuses =====
    public const PLAN_STATUS_ACTIVE = 'ACTIVE';

    public const PLAN_STATUS_PAUSED = 'PAUSED';

    public const PLAN_STATUS_CANCELED = 'CANCELED';

    public const PLAN_STATUS_ERROR = 'ERROR';

    public const SUB_ORDER_STATUS_PENDING = 'PENDING';

    public const SUB_ORDER_STATUS_SUCCESS = 'SUCCESS';

    public const SUB_ORDER_STATUS_CANCELED = 'CANCELED';

    public const SUB_ORDER_STATUS_FAILED = 'FAILED';

    // ===== Scheduled Payment Statuses =====
    public const SCHEDULED_STATUS_WAITING = 'WAITING';

    public const SCHEDULED_STATUS_PROCESSING = 'PROCESSING';

    public const SCHEDULED_STATUS_SUCCESS = 'SUCCESS';

    public const SCHEDULED_STATUS_FAIL = 'FAIL_WITHOUT_RETRY';

    public const SCHEDULED_STATUS_RETRY = 'RETRY';

    public const SCHEDULED_STATUS_SKIPPED_PAUSED = 'SKIPPED_PAUSED';

    public const SCHEDULED_STATUS_INVALIDATED = 'INVALIDATED';

    // ===== Recurring Transaction Types =====
    public const RECURRING_TYPE_INITIAL = 'O';

    public const RECURRING_TYPE_MERCHANT = 'M';

    // ===== Fiscalization =====
    public const FISCAL_STATUS_PENDING = 'PENDING';

    public const FISCAL_STATUS_SUCCESS = 'SUCCESS';

    public const FISCAL_STATUS_FAIL = 'FAIL';

    /** Локальний статус: запит не дійшов / відхилений (400, 5xx, таймаут, підпис). */
    public const FISCAL_STATUS_ERROR = 'ERROR';

    public const FISCAL_DEFAULT_MEASUREMENT_CODE = '0001';

    /** taxCode, для яких обов'язкові classificationCode (і exciseLabels для підакцизних). */
    public const FISCAL_EXCISE_TAX_CODES = [2, 7, 8];

    public const WC_STATUS_SUBSCRIBED = 'alb-subscribed';

    public const DISALLOWED_TRANSACTION_TYPE_A2A = 102;

    // Параметри запиту
    public const DIRECT_TYPE_BANK_LINK = 'BANK_LINK';

    public const STATUS_PAGE_TYPE = 'STATUS_TIMER_PAGE';

    public const DEFAULT_LANGUAGE = 'uk';

    public const DEFAULT_API_VERSION = 'v1';

    public const DEFAULT_PAYMENT_METHODS = ['CARD', 'APPLE_PAY', 'GOOGLE_PAY'];

    public const CURRENCY_CODES = [
        'UAH' => 980,
        'USD' => 840,
        'EUR' => 978,
    ];

    public const REFUND_DATA_HISTORY_FIELDS_MAPPING = [
        'type' => 'type',
        'rrn' => 'rrn',
        'purpose' => 'purpose',
        'comment' => 'comment',
        'coinAmount' => 'coin_amount',
        'merchantId' => 'merchant_id',
        'operationId' => 'operation_id',
        'ecomOperationId' => 'ecom_operation_id',
        'merchantName' => 'merchant_name',
        'approvalCode' => 'approval_code',
        'status' => 'status',
        'transactionType' => 'transaction_type',
        'merchantRequestId' => 'merchant_request_id',
        'transactionCurrency' => 'transaction_currency',
        'merchantCommission' => 'merchant_commission',
        'createDateTime' => 'create_date_time',
        'modificationDateTime' => 'modification_date_time',
        'actionCode' => 'action_code',
        'responseCode' => 'response_code',
        'description' => 'description',
        'processingMerchantId' => 'processing_merchant_id',
        'processingTerminalId' => 'processing_terminal_id',
        'transactionResponseInfo' => 'transaction_response_info',
        'bankCode' => 'bank_code',
        'paymentSystem' => 'payment_system',
        'productType' => 'product_type',
        'notificationUrl' => 'notification_url',
        'paymentServiceType' => 'payment_service_type',
        'notificationEncryption' => 'notification_encryption',
        'originalOperationId' => 'original_operation_id',
        'originalCoinAmount' => 'original_coin_amount',
        'originalEcomOperationId' => 'original_ecom_operation_id',
        'rrnOriginal' => 'rrn_original',
    ];

    public const JSON_ENCODED_FIELDS_LIST = [
        'transactionResponseInfo'
    ];

    /**
     * @param string $code
     * @return int
     * @throws \InvalidArgumentException
     */
    public static function getCurrencyCode(string $code): int
    {
        if (!array_key_exists($code, self::CURRENCY_CODES)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'AlliancePay: currency "%s" is not supported for multi-currency payments.'
                    . 'Supported currencies: %s.',
                    $code,
                    implode(', ', array_keys(self::CURRENCY_CODES))
                )
            );
        }

        return self::CURRENCY_CODES[$code];
    }

    /**
     * @return string
     */
    public static function table_alliance_checkout_integration_order(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'alliance_checkout_integration_order';
    }

    /**
     * @return string
     */
    public static function table_alliance_integration_order_refund(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'alliance_integration_order_refund';
    }

    /**
     * @return string
     */
    public static function table_alliance_fiscalization(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'alliance_fiscalization';
    }

    /**
     * @param string $route
     * @return string
     */
    private static function callback_url(string $route): string
    {
        if (defined('ALB_HPP_CALLBACK_BASE') && ALB_HPP_CALLBACK_BASE) {
            return rtrim((string)ALB_HPP_CALLBACK_BASE, '/') . '/index.php?rest_route=/' . ltrim($route, '/');
        }

        return rest_url($route);
    }

    /**
     * @return string
     */
    public static function get_fiscalization_notification_url(): string
    {
        return self::callback_url('alb/v1/fiscalization-notify');
    }

    /**
     * @return string
     */
    public static function get_notification_url(): string
    {
        if (!defined('ALB_HPP_CALLBACK_BASE') || !ALB_HPP_CALLBACK_BASE) {
            $opt = get_option(ALB_HPP_OPT, []);
            if (!empty($opt['notificationUrl'])) {
                return $opt['notificationUrl'];
            }
        }

        return self::callback_url('alb/v1/notify');
    }

    /**
     * @return string
     */
    public static function table_alliance_subscription_plans(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'alliance_subscription_plans';
    }

    /**
     * @return string
     */
    public static function table_alliance_subscriptions(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'alliance_subscriptions';
    }

    /**
     * @return string
     */
    public static function table_alliance_scheduled_payments(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'alliance_scheduled_payments';
    }

    /**
     * @return string
     */
    public static function table_alliance_subscription_plan_version(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'alliance_subscription_plan_version';
    }

    /**
     * @return string
     */
    public static function table_alliance_subscription_templates(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'alliance_subscription_templates';
    }

    /**
     * @return string
     */
    public static function table_alliance_subscription_audit_log(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'alliance_subscription_audit_log';
    }
}
