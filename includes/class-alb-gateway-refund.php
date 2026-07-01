<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

use ALB\AlbHppClient;
use ALB\AllianceConfig;
use Automattic\WooCommerce\Enums\OrderInternalStatus;

if (!defined('ABSPATH')) exit;

/**
 * Class AllianceRefund.
 */
class AllianceRefund
{
    private AlbHppClient $httpClient;
    private ALB_Authorize $albAuthorize;
    private array $options;
    public function __construct()
    {
        $this->options = get_option(ALB_HPP_OPT, []);
        $this->httpClient = new AlbHppClient($this->options);
        $this->albAuthorize = new ALB_Authorize();
    }

    public function init_hooks(): void {
        add_action( 'woocommerce_create_refund', [ $this, 'on_refund_created' ], 10, 2 );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'on_order_refunded' ], 10, 1 );
    }

    /**
     * @param $refund_id
     * @param $args
     * @return void
     */
    public function on_refund_created($refund_id, $args): void
    {
        $order = wc_get_order($args['order_id']);
        $orderId = $args['order_id'];
        $refundAmount = (int) round( (float) $args['amount'] * 100 );

        if (!$order || $order->get_payment_method() !== AllianceConfig::ALLIANCE_PAYMENT_CODE) {
            return;
        }

        try {
            $response = $this->process_refund($orderId, $refundAmount);

            if (!$response['success']) {
                wp_delete_post($refund_id, true);
                set_transient(
                    'alb_hpp_notice',
                    [
                        'type' => 'error',
                        'text' => __( $response['message'] ?? 'Refund error via AlliancePay!', 'alliancepay' )
                    ],
                    60
                );
            }
        } catch (\Exception $e) {
            wp_delete_post($refund_id, true);
            error_log('[Refund error] ' . $e->getMessage());
        }
    }

    public function on_order_refunded(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!$order
            || $order->get_payment_method() !== AllianceConfig::ALLIANCE_PAYMENT_CODE
            || !wc_is_order_status('refunded')
        ) {
            return;
        }

        if ( $this->has_refund_record($orderId) ) {
            return;
        }

        $refundAmount = (int) round( (float) $order->get_total() * 100 );

        try {
            $response = $this->process_refund($orderId, $refundAmount);

            if (!$response['success']) {
                $order->update_status('processing', 'Refund failed on bank side');
            }
        } catch (\Exception $e) {
            $order->update_status('processing', 'Refund API exception');
            error_log('[Refund error] ' . $e->getMessage());
        }
    }

    /**
     * @param int $orderId
     * @param int $refundAmount
     * @return array
     */
    public function process_refund(int $orderId, int $refundAmount): array
    {
        $allianceOrderData = $this->getAllianceOrderData($orderId);
        $purchaseOperation = $this->getPurchaseOperation($allianceOrderData);

        if (empty($purchaseOperation)) {
            return ['success' => false, 'message' => 'Purchase operation not found'];
        } elseif (!empty($purchaseOperation['transactionType'])
            && $purchaseOperation['transactionType'] === AllianceConfig::DISALLOWED_TRANSACTION_TYPE_A2A
        ) {
            return ['success' => false, 'message' => 'A2A transaction type is not allowed for this refund', 'refundId' => $orderId];
        }

        $payload = $this->prepareRefundData($allianceOrderData, $refundAmount);

        if (!empty($this->options['serverPublicKey'])
            && !empty($payload)
            && !empty($this->options['privateJwk'])
        ) {
            try {
                $jwk = is_array($this->options['privateJwk'])
                    ? $this->options['privateJwk']
                    : json_decode((string)$this->options['privateJwk'], true);
                $serverPublicKey = json_decode($this->options['serverPublicKey'], true);
                $encryptedPayload = $this->albAuthorize->encrypt_with_simplejwt(
                    $serverPublicKey,
                    $payload
                );
                $refundResult = $this->httpClient->create_refund($encryptedPayload);
                if (!empty($refundResult['jwe'])) {
                    $decryptedData = $this->albAuthorize::decrypt_with_simplejwt(
                        $refundResult['jwe'],
                        $jwk
                    );
                    $this->updateRefundHistory($orderId, $decryptedData);
                }
            } catch (\Exception $exception) {
                return ['success' => false, 'message' => $exception->getMessage()];
            }
        }

        return ['success' => true, 'refundId' => $orderId];
    }

    /**
     * @param int $orderId
     * @param array $refundData
     * @return void
     */
    private function updateRefundHistory(int $orderId, array $refundData): void
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_integration_order_refund();
        $data = [];
        if ($orderId) {
            try {
                $data['order_id'] = $orderId;

                foreach ($refundData as $field => $value) {
                    if (!in_array($field, AllianceConfig::JSON_ENCODED_FIELDS_LIST)
                        && isset(AllianceConfig::REFUND_DATA_HISTORY_FIELDS_MAPPING[$field])
                        && !empty($value)
                    ) {
                        $data[AllianceConfig::REFUND_DATA_HISTORY_FIELDS_MAPPING[$field]] = $value;
                    } elseif (!empty($value)
                        && isset(AllianceConfig::REFUND_DATA_HISTORY_FIELDS_MAPPING[$field])
                    ) {
                        $data[AllianceConfig::REFUND_DATA_HISTORY_FIELDS_MAPPING[$field]] = json_encode($value);
                    }
                }

                $wpdb->insert($table, $data);
            } catch (\Exception $exception) {
                error_log($exception->getMessage());
            }
        }
    }

    /**
     * @param int $orderId
     * @return array
     */
    private function getAllianceOrderData(int $orderId): array
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_checkout_integration_order();
        $allianceOrderData = [];

        $allianceOrder = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_id = %d",
                $orderId
            )
        );

        if (!empty($allianceOrder->callback_data)) {
            $allianceOrderData = json_decode($allianceOrder->callback_data, true);
        }

        if (!empty($allianceOrder->hpp_order_id) && empty($allianceOrderData)) {
            $allianceOrderData = $this->httpClient->get_order($allianceOrder->hpp_order_id);
        }

        return $allianceOrderData;
    }

    /**
     * @param array $data
     * @param int $refundAmount
     * @return array
     */
    private function prepareRefundData(array $data, int $refundAmount): array
    {
        $preparedData = [];
        if (isset($data[AllianceConfig::REFUND_DATA_FIELD_MERCHANT_ID])) {
            $preparedData[AllianceConfig::REFUND_DATA_FIELD_MERCHANT_ID] =
                $data[AllianceConfig::REFUND_DATA_FIELD_MERCHANT_ID];
        }

        if (isset($data[AllianceConfig::REFUND_DATA_FIELD_MERCHANT_REQUEST_ID])) {
            $preparedData[AllianceConfig::REFUND_DATA_FIELD_MERCHANT_REQUEST_ID] = wp_generate_uuid4();
        }

        if (isset($data[AllianceConfig::REFUND_DATA_FIELD_COIN_AMOUNT])) {
            if ($data[AllianceConfig::REFUND_DATA_FIELD_COIN_AMOUNT] > $refundAmount) {
                $preparedData[AllianceConfig::REFUND_DATA_FIELD_COIN_AMOUNT] = $refundAmount;
            } else {
                $preparedData[AllianceConfig::REFUND_DATA_FIELD_COIN_AMOUNT] =
                    $data[AllianceConfig::REFUND_DATA_FIELD_COIN_AMOUNT];
            }
        }

        $purchaseOperation = $this->getPurchaseOperation($data);
        $preparedData[AllianceConfig::REFUND_DATA_FIELD_OPERATION_ID] = $purchaseOperation['operationId'] ?? '';
        $preparedData[AllianceConfig::REFUND_DATA_FIELD_NOTIFICATION_URL] = home_url('/?alliance_pay_callback_notify');
        $preparedData[AllianceConfig::REFUND_DATA_FIELD_DATE] = $this->getRefundDate();

        if (empty($preparedData[AllianceConfig::REFUND_DATA_FIELD_MERCHANT_ID])
            || empty($preparedData[AllianceConfig::REFUND_DATA_FIELD_MERCHANT_REQUEST_ID])
            || empty($preparedData[AllianceConfig::REFUND_DATA_FIELD_COIN_AMOUNT])
            || empty($preparedData[AllianceConfig::REFUND_DATA_FIELD_OPERATION_ID])
        ) {
            return [];
        }

        return $preparedData;
    }

    /**
     * @param int $orderId
     * @return bool
     */
    private function has_refund_record(int $orderId): bool
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_integration_order_refund();
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE order_id = %d",
                $orderId
            )
        );
        return (int) $count > 0;
    }

    /**
     * @return string
     */
    private function getRefundDate(): string
    {
        $date = wp_date('Y-m-d H:i:s.vP', null, wp_timezone());

        return preg_replace('/(\.\d{2})\d/', '$1',$date);
    }

    private function getPurchaseOperation(array $allianceOrderData): ?array
    {
        $operationTypes = [
            AllianceConfig::OPERATION_TYPE_PURCHASE,
            AllianceConfig::OPERATION_TYPE_A2A
        ];

        if (!empty($allianceOrderData['operations'])) {
            $operations = $allianceOrderData['operations'];
        } elseif (!empty($allianceOrderData['operation'])) {
            $operations = [$allianceOrderData['operation']];
        } else {
            $operations = [];
        }

        foreach ($operations as $operation) {
            if (!empty($operation['type'])
                && in_array($operation['type'], $operationTypes)
                && !empty($operation['operationId'])
            ) {
                return $operation;
            }
        }

        return [];
    }
}
