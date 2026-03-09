<?php

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

    public static function table_alliance_checkout_integration_order(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'alliance_checkout_integration_order';
    }

    public static function table_alliance_integration_order_refund(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'alliance_integration_order_refund';
    }
}
