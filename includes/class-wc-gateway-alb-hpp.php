<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

use ALB\ALB_Authorize;
use ALB\AlbHppClient;
use ALB\ApiEndpoints;
use Automattic\WooCommerce\Enums\OrderInternalStatus;
use ALB\AllianceConfig;

if (!defined('ABSPATH')) exit;

/**
 * Class WC_Gateway_ALB_HPP.
 */
class WC_Gateway_ALB_HPP extends WC_Payment_Gateway
{
    private ALB_Authorize $albAuthorize;

    public function __construct()
    {
        $this->id = 'alliance_pay';
        $this->method_title = 'AlliancePay';
        $this->method_description = __('Payment via Hosted Payment Page of Alliance Bank', 'alliancepay');
        $this->has_fields = true;
        $this->supports = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', 'AlliancePay');
        $this->description = $this->get_option(
            'description',
            __('Pay with AlliancePay using Visa, Mastercard, ApplePay, GooglePay', 'alliancepay')
        );
        $this->albAuthorize = new ALB_Authorize();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    /**
     * @return void
     */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'alliancepay'),
                'type' => 'checkbox',
                'label' => __('Enable this payment method', 'alliancepay'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Title', 'woocommerce'),
                'type' => 'safe_text',
                'description' => __('AlliancePay', 'woocommerce'),
                'default' => __('AlliancePay', 'woocommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('Payment via Alliance Bank.', 'alliancepay'),
                'default' => __('Payment via Alliance Bank.', 'alliancepay'),
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * @return void
     */
    public function payment_fields()
    {
        parent::payment_fields();

        $opt = get_option(ALB_HPP_OPT, []);

        if (($opt['hppPayType'] ?? '') === AllianceConfig::HPP_PAY_TYPE_PREAUTH) {
            echo '<p class="alb-preauth-notice">'
                . esc_html__(
                    'Funds will be reserved and captured after order confirmation by the administrator.',
                    'alliancepay'
                )
                . '</p>';
        }
    }

    /**
     * @param $order_id
     * @return array|string[]
     */
    public function process_payment($order_id)
    {
        if (class_exists('\ALB\ALB_Subscription_Gateway')) {
            $sub_result = \ALB\ALB_Subscription_Gateway::process_subscription_payment((int)$order_id);
            if ($sub_result !== null) {
                return $sub_result;
            }
        }

        global $wpdb;
        $order = wc_get_order($order_id);

        $opt = get_option(ALB_HPP_OPT, []);

        $opt['baseUrl'] = $opt['baseUrl'] ?? ALB_HPP_PROD_BASE;
        $opt['apiVersion'] = $opt['apiVersion'] ?? AllianceConfig::DEFAULT_API_VERSION;
        $opt['language'] = $opt['language'] ?? AllianceConfig::DEFAULT_LANGUAGE;
        $opt['hppPayType'] = $opt['hppPayType'] ?? AllianceConfig::HPP_PAY_TYPE_PURCHASE;

        try {
            $client = new AlbHppClient($opt);

            $coinAmount = (int)round((float)$order->get_total() * 100);

            $merchantRequestId = wp_generate_uuid4();
            $current_user_id = get_current_user_id();
            $sender_customer_id = (string)$current_user_id;

            if (!$sender_customer_id) {
                $sender_customer_id = 'not_auth_' . wp_generate_uuid4();
            }

            $customer_data = [
                'senderCustomerId' => $sender_customer_id,
                'senderFirstName' => $order->get_billing_first_name() ?? $order->get_shipping_first_name() ?? '',
                'senderLastName' => $order->get_billing_last_name() ?? $order->get_shipping_last_name() ?? '',
                'senderEmail' => $order->get_billing_email() ?? '',
                'senderCountry' => $this->get_country_code(
                        $order->get_billing_country() ?? $order->get_shipping_country()
                    ) ?? '',
                'senderRegion' => $this->get_state_name(
                        $order->get_billing_country(),
                        $order->get_billing_state() ?? $order->get_shipping_state() ?? ''
                    ) ?? '',
                'senderCity' => $order->get_billing_city() ?? $order->get_shipping_city() ?? '',
                'senderStreet' => $order->get_billing_address_1() ?? $order->get_shipping_address_1() ?? '',
                'senderAdditionalAddress' => $order->get_billing_address_2() ?? $order->get_shipping_address_2() ?? '',
                'senderIp' => $order->get_customer_ip_address() ?? '',
                'senderPhone' => $order->get_billing_phone() ?? $order->get_shipping_phone() ?? '',
                'senderZipCode' => $order->get_billing_postcode() ?? $order->get_shipping_postcode() ?? '',
            ];

            $params = [
                'coinAmount' => $coinAmount,
                'statusPageType' => AllianceConfig::STATUS_PAGE_TYPE,
                'hppPayType' => $opt['hppPayType'],
                'paymentMethods' => AllianceConfig::DEFAULT_PAYMENT_METHODS,
                'language' => AllianceConfig::DEFAULT_LANGUAGE,
                'successUrl' => $opt['successUrl'] ?? home_url('/'),
                'failUrl' => $opt['failUrl'] ?? home_url('/'),
                'notificationUrl' => AllianceConfig::get_notification_url(),
                'merchantId' => $opt['merchantId'] ?? '',
                'merchantRequestId' => $merchantRequestId,
                'customerData' => $this->validate_and_clear_customer_data($customer_data)
            ];

            if ($opt['hppPayType'] === AllianceConfig::HPP_PAY_TYPE_A2A) {
                $params['directType'] = AllianceConfig::DIRECT_TYPE_BANK_LINK;
                $params['priorityBankCode'] = 'ALL_BANKS';
                $params['merchantComment'] = 'Payment for order #' . ($order_id ?? '');
            }

            if ($opt['hppPayType'] === AllianceConfig::HPP_PAY_TYPE_PREAUTH) {
                $preAuthExpDate = $opt['preAuthExpDate'] ?? '24h';
                $params['preAuthExpDate'] = $this->calc_preauth_exp_date($preAuthExpDate);
                $params['directType'] = 'REDIRECT';
            }

            if (
                in_array($opt['hppPayType'], [
                    AllianceConfig::HPP_PAY_TYPE_PURCHASE,
                    AllianceConfig::HPP_PAY_TYPE_PREAUTH,
                ], true)
            ) {
                $wpCurrency = get_woocommerce_currency();
                if ($wpCurrency !== 'UAH') {
                    $params['currencyCode'] = AllianceConfig::getCurrencyCode($wpCurrency);
                }
            }

            $res = $client->create_hpp_order($params, ApiEndpoints::ENDPOINT_CREATE_ORDER);
        } catch (\Throwable $e) {
            wc_add_notice($e->getMessage(), 'error');

            return ['result' => 'failure'];
        }

        $table = AllianceConfig::table_alliance_checkout_integration_order();
        $paymentMethods = json_encode($res['paymentMethods']);

        $isPreauth = ($opt['hppPayType'] === AllianceConfig::HPP_PAY_TYPE_PREAUTH);

        $wpdb->insert($table, [
            'order_id' => $order_id,
            'merchant_request_id' => $merchantRequestId,
            'hpp_order_id' => $res['hppOrderId'] ?? null,
            'merchant_id' => $res['merchantId'],
            'coin_amount' => $res['coinAmount'] ?? null,
            'currency_code' => $params['currencyCode'] ?? null,
            'hpp_pay_type' => $res['hppPayType'] ?? null,
            'order_status' => $res['orderStatus'] ?? null,
            'payment_methods' => $paymentMethods,
            'create_date' => $res['createDate'] ?? null,
            'expired_order_date' => $res['expiredOrderDate'] ?? null,
            'original_authorized_amount' => $isPreauth ? $coinAmount : null,
        ]);

        if (!empty($res['redirectUrl'])) {
            if ($isPreauth) {
                $onHoldStatus = $opt['preAuthOnHoldStatus'] ?? 'on-hold';
                $order->update_status(
                    $onHoldStatus,
                    __(
                        'PREAUTH authorization created via AlliancePay.'
                        . ' Awaiting admin confirmation to capture funds.',
                        'alliancepay'
                    )
                );
            } else {
                $order->payment_complete();
            }
            WC()->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $res['redirectUrl'],
            ];
        }

        wc_add_notice(__('A technical error occurred', 'alliancepay'), 'error');

        return ['result' => 'failure'];
    }

    /**
     * @param $data
     * @param $raw
     * @return false|void
     */
    public function callback($data, $raw)
    {
        if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
            $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
            if (!is_dir($dir)) wp_mkdir_p($dir);
            @file_put_contents($dir . '/debug.log', print_r($data, true), FILE_APPEND);
        }

        global $wpdb;
        $table = AllianceConfig::table_alliance_checkout_integration_order();

        $alliance_order = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE hpp_order_id = %s",
                $data['hppOrderId']
            )
        );

        if (!$alliance_order) {
            return false;
        }

        $wp_order = wc_get_order($alliance_order->order_id);

        if (!$wp_order) {
            return false;
        }

        $incoming_operation = $data['operation'] ?? null;
        $incoming_operation_type = $incoming_operation['type'] ?? '';

        if ($incoming_operation_type === AllianceConfig::OPERATION_TYPE_REFUND) {
            $operation_id = $incoming_operation['operationId'] ?? '';

            $stored_data = json_decode($alliance_order->callback_data, true) ?? [];
            $stored_operations = $stored_data['operations'] ?? [];

            foreach ($stored_operations as $stored_op) {
                if (($stored_op['operationId'] ?? '') === $operation_id) {
                    return;
                }
            }

            $wpdb->update(
                $table,
                [
                    'callback_data' => $this->merge_callback_data($alliance_order->callback_data, $data),
                    'updated_at' => current_time('mysql'),
                ],
                ['hpp_order_id' => $data['hppOrderId']]
            );

            $refund_table = AllianceConfig::table_alliance_integration_order_refund();
            $refund_row = ['order_id' => $alliance_order->order_id];

            foreach ($incoming_operation as $field => $value) {
                if (!empty($value)
                    && isset(AllianceConfig::REFUND_DATA_HISTORY_FIELDS_MAPPING[$field])
                ) {
                    $col = AllianceConfig::REFUND_DATA_HISTORY_FIELDS_MAPPING[$field];

                    $refund_row[$col] = in_array($field, AllianceConfig::JSON_ENCODED_FIELDS_LIST, true)
                        ? wp_json_encode($value, JSON_UNESCAPED_UNICODE)
                        : $value;
                }
            }

            $wpdb->update($refund_table, $refund_row, ['operation_id' => $refund_row['operation_id']]);

            return;
        }


        $incoming_operation_id = $incoming_operation['operationId'] ?? null;

        // De-dupe strictly by operationId: an earlier delivery with no operation
        // node (e.g. an order-status-only ping) must not permanently block a
        // later delivery that carries the real operation/operationId — only a
        // repeat of an operation we've already recorded should be dropped.
        if ($alliance_order->is_callback_returned
            && !empty($alliance_order->operation_id)
            && $incoming_operation_id !== null
            && $incoming_operation_id === $alliance_order->operation_id
        ) {
            return false;
        }

        $order_payment_status = '';

        if ($data['hppPayType'] === AllianceConfig::HPP_PAY_TYPE_PURCHASE && isset($data['orderStatus'])) {
            $order_payment_status = $data['orderStatus'];
        } elseif ($data['hppPayType'] === AllianceConfig::HPP_PAY_TYPE_A2A) {
            $order_payment_status = $this->getA2AOperationStatus($data);
        }

        if ($data['hppPayType'] === AllianceConfig::HPP_PAY_TYPE_PREAUTH) {
            $conversionRate = isset($incoming_operation['conversionRate'])
                ? (float)$incoming_operation['conversionRate']
                : null;

            $updateData = [
                'ecom_order_id' => $data['ecomOrderId'] ?? null,
                'is_callback_returned' => true,
                'callback_data' => $this->merge_callback_data($alliance_order->callback_data, $data),
                'order_status' => $data['orderStatus'] ?? null,
                'operation_id' => $incoming_operation['operationId'] ?? $alliance_order->operation_id,
                'updated_at' => current_time('mysql'),
            ];

            if ($conversionRate !== null && (float)$conversionRate > 1.0) {
                $updateData['conversion_rate'] = (float)$conversionRate;
            }

            $wpdb->update(
                $table,
                $updateData,
                ['hpp_order_id' => $data['hppOrderId']]
            );

            if (isset($data['orderStatus']) && $data['orderStatus'] === AllianceConfig::ORDER_STATUS_FAIL) {
                $wp_order->update_status(
                    OrderInternalStatus::FAILED,
                    __('PREAUTH authorization failed via AlliancePay.', 'alliancepay')
                );
            } else {
                $wp_order->add_order_note(
                    __(
                        'AlliancePay PREAUTH callback received.'
                        . ' operation_id stored. Awaiting admin capture confirmation.',
                        'alliancepay'
                    )
                );
            }

            return;
        }

        if ($order_payment_status === AllianceConfig::ORDER_STATUS_SUCCESS) {
            $wp_order->update_status(
                OrderInternalStatus::COMPLETED,
                __('Payment received via AlliancePay', 'alliancepay')
            );
        }

        if ($order_payment_status === AllianceConfig::ORDER_STATUS_FAIL) {
            $wp_order->update_status(
                OrderInternalStatus::FAILED,
                __('Payment failed via AlliancePay', 'alliancepay')
            );
        }

        $conversionRate = isset($incoming_operation['conversionRate'])
            ? (float)$incoming_operation['conversionRate']
            : null;

        $wpdb->update(
            $table,
            [
                'ecom_order_id' => $data['ecomOrderId'] ?? null,
                'is_callback_returned' => true,
                'callback_data' => $this->merge_callback_data($alliance_order->callback_data, $data),
                'order_status' => $data['orderStatus'],
                'operation_id' => $incoming_operation['operationId'] ?? $alliance_order->operation_id,
                'conversion_rate' => ($conversionRate !== null && $conversionRate > 0) ? $conversionRate : $alliance_order->conversion_rate,
                'updated_at' => current_time('mysql'),
            ],
            [
                'hpp_order_id' => $data['hppOrderId'],
            ],
        );
    }

    /**
     * @param array $data
     * @return string
     */
    private function getA2AOperationStatus(array $data): string
    {
        if (!empty($data['operation'])
            && isset($data['operation']['type'], $data['operation']['status'])
            && $data['operation']['type'] === AllianceConfig::OPERATION_TYPE_A2A
        ) {
            return $data['operation']['status'];
        }

        if (!empty($data['operations']) && is_array($data['operations'])) {
            foreach ($data['operations'] as $operation) {
                if (isset($operation['type'], $operation['status'])
                    && $operation['type'] === AllianceConfig::OPERATION_TYPE_A2A
                ) {
                    return $operation['status'];
                }
            }
        }

        return '';
    }

    private function merge_callback_data(?string $existing_json, array $incoming): string
    {
        $stored = !empty($existing_json) ? (json_decode($existing_json, true) ?? []) : [];

        foreach ($incoming as $key => $value) {
            if ($key !== 'operation') {
                $stored[$key] = $value;
            }
        }

        $incoming_op = $incoming['operation'] ?? null;

        if (is_array($incoming_op) && !empty($incoming_op['operationId'])) {
            $incoming_op_id = $incoming_op['operationId'];

            if (!isset($stored['operations']) || !is_array($stored['operations'])) {
                $stored['operations'] = [];
            }

            $already_exists = false;
            foreach ($stored['operations'] as $stored_op) {
                if (($stored_op['operationId'] ?? '') === $incoming_op_id) {
                    $already_exists = true;
                    break;
                }
            }

            if (!$already_exists) {
                $stored['operations'][] = $incoming_op;
            }
        }

        return wp_json_encode($stored, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param $order_id
     * @return false|void
     */
    public function dataForStatusDetail($order_id)
    {
        global $wpdb;

        $is_call_to_ecom = false;
        $table_alliance_checkout_integration_order = AllianceConfig::table_alliance_checkout_integration_order();
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_alliance_checkout_integration_order} WHERE order_id = %d",
                $order_id
            )
        );

        if (!$result) {
            echo '<h2>' . esc_html__('This order was not paid via AlliancePay', 'alliancepay') . '</h2>';
            return false;
        }

        if ($result->is_callback_returned) {
            $callback_data = json_decode($result->callback_data);
            $status_url = $callback_data->statusUrl ?? null;
            $ecom_order_id = $result->ecom_order_id;
            $merchant_id = $result->merchant_id;
            $hpp_order_id = $result->hpp_order_id;
            $hpp_pay_type = $result->hpp_pay_type;
            $merchant_request_id = $result->merchant_request_id;
            $order_status = $result->order_status;
        } else {
            $is_call_to_ecom = true;
            try {
                $client = new AlbHppClient(get_option(ALB_HPP_OPT, []));
                $res = $client->get_order($result->hpp_order_id);
                $ecom_order_id = $res['ecomOrderId'];
                $status_url = $res['statusUrl'];
                $merchant_id = $res['merchantId'];
                $hpp_order_id = $res['hppOrderId'];
                $hpp_pay_type = $res['hppPayType'];
                $merchant_request_id = $res['merchantRequestId'];
                $order_status = $res['orderStatus'];
            } catch (\Throwable $e) {
                echo '<h2>' . esc_html__('An error occurred while contacting AlliancePay', 'alliancepay') . '</h2>';

                return;
            }
        }

        $operations = [];
        $operations_error = '';
        try {
            $operations_response = $res ?? (new AlbHppClient(get_option(ALB_HPP_OPT, [])))->get_order($hpp_order_id);
            $operations = AlbHppClient::extract_operations($operations_response);
        } catch (\Throwable $e) {
            $operations_error = $e->getMessage();
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('AlliancePay Order Status', 'alliancepay') . '</h1>';
        echo '<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px;">';
        echo '<h2 style="color: #2ea2cc;">' . esc_html__('AlliancePay payment details', 'alliancepay') . '</h2>';

        echo '<p style="margin-top:20px;">';
        echo '<a href="'
            . esc_url(admin_url('edit.php?post_type=shop_order'))
            . '" class="button button-primary">'
            . esc_html__('⬅ Back to order list', 'alliancepay')
            . '</a>';
        echo '</p>';

        if ($order_id) {
            echo '<p><strong>' . esc_html__('Order ID:', 'alliancepay') . '</strong> ' . $order_id . '</p>';

            $order = wc_get_order($order_id);
            if ($order) {
                echo '<p><strong>'
                    . esc_html__('Woocommerce Order Status:', 'alliancepay')
                    . '</strong> '
                    . $order->get_status()
                    . '</p>';
                echo '<p><strong>'
                    . esc_html__('Order Total:', 'alliancepay')
                    . '</strong> '
                    . $order->get_formatted_order_total()
                    . '</p>';
            }
        }

        if ($is_call_to_ecom) {
            echo '<h2 style="color: firebrick">'
                . esc_html__(
                    'Callback from AllianceBank not received. The data below is taken directly from AlliancePay',
                    'alliancepay'
                ) . '</h2>';
        } else {
            echo '<h2 style="color: green">'
                . esc_html__(
                    'Callback from AllianceBank received. The data below is received from the callback and loaded into the DB',
                    'alliancepay'
                ) . '</h2>';
        }

        echo '<p><strong>ecomOrderId:</strong> ' . esc_html($ecom_order_id) . '</p>';
        echo '<p><strong>merchantId:</strong> ' . esc_html($merchant_id) . '</p>';
        echo '<p><strong>hppOrderId:</strong> ' . esc_html($hpp_order_id) . '</p>';
        echo '<p><strong>hppPayType:</strong> ' . esc_html($hpp_pay_type) . '</p>';
        echo '<p><strong>merchantRequestId:</strong> ' . esc_html($merchant_request_id) . '</p>';
        echo '<p><strong>AlliancePay orderStatus:</strong> ' . esc_html($order_status) . '</p>';
        echo '<p><strong>statusUrl:</strong> <a href="'
            . esc_url($status_url)
            . '" target="_blank">'
            . esc_html($status_url)
            . '</a></p>';

        echo '<h3>' . esc_html__('Operations', 'alliancepay') . '</h3>';
        if ($operations_error) {
            echo '<p style="color: firebrick;">'
                . esc_html(sprintf(
                /* translators: %s: underlying error message */
                    __('Could not fetch live operations from AlliancePay: %s', 'alliancepay'),
                    $operations_error
                ))
                . '</p>';
        } elseif (empty($operations)) {
            echo '<p>' . esc_html__('No operations recorded yet.', 'alliancepay') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat striped" style="margin-top:8px;max-width:900px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Type', 'alliancepay') . '</th>';
            echo '<th>' . esc_html__('Status', 'alliancepay') . '</th>';
            echo '<th>' . esc_html__('Amount', 'alliancepay') . '</th>';
            echo '<th>' . esc_html__('Operation ID', 'alliancepay') . '</th>';
            echo '<th>' . esc_html__('Receipt', 'alliancepay') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($operations as $op) {
                echo '<tr>';
                echo '<td>' . esc_html((string)$op['type']) . '</td>';
                echo '<td>' . esc_html((string)$op['status']) . '</td>';
                echo '<td>' . (is_numeric($op['coinAmount']) ? esc_html(number_format(((float)$op['coinAmount']) / 100, 2)) . ' UAH' : '—') . '</td>';
                echo '<td style="font-size:11px;word-break:break-all">' . esc_html((string)$op['operationId']) . '</td>';
                echo '<td>' . ($op['receiptUrl']
                        ? '<a href="' . esc_url($op['receiptUrl']) . '" target="_blank">' . esc_html__('View receipt', 'alliancepay') . '</a>'
                        : '—') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * @param array $data
     * @return array
     */
    public function validate_and_clear_customer_data(array $data)
    {
        $validator = new WC_Custom_Data_Validator();
        $validatedData = $validator->validateAndClear($data);

        if (!empty($validator->get_errors())) {
            error_log('[Validation errors: ] ' . implode(', ', $validator->get_errors()));
        }

        return $validatedData;
    }

    /**
     * @param string $value
     * @return string
     * @throws Exception
     */
    private function calc_preauth_exp_date(string $value): string
    {
        $map = [
            '2h' => 2 * HOUR_IN_SECONDS,
            '4h' => 4 * HOUR_IN_SECONDS,
            '6h' => 6 * HOUR_IN_SECONDS,
            '12h' => 12 * HOUR_IN_SECONDS,
            '1d' => 1 * DAY_IN_SECONDS,
            '2d' => 2 * DAY_IN_SECONDS,
            '7d' => 7 * DAY_IN_SECONDS,
            '14d' => 14 * DAY_IN_SECONDS,
            '28d' => 28 * DAY_IN_SECONDS,
        ];

        $offset = $map[$value] ?? DAY_IN_SECONDS;
        $expTime = time() + $offset + 30;
        $tz = wp_timezone();
        $dt = new \DateTime('@' . $expTime);
        $dt->setTimezone($tz);
        $ms2 = substr($dt->format('v'), 0, 2);

        return $dt->format('Y-m-d H:i:s.') . $ms2 . $dt->format('P');
    }

    /**
     * Get state name by country code and state code
     *
     * @param string $country_code
     * @param string $state_code
     * @return string
     */
    public function get_state_name(string $country_code, string $state_code)
    {
        $countries = new WC_Countries();
        $states = $countries->get_states($country_code);

        return $states[$state_code] ?? $state_code;
    }

    /**
     * Get country numeric code by alpha-2 country code
     *
     * @param string $country_code
     * @return string
     */
    public function get_country_code(string $country_code)
    {
        if (class_exists(\ALB\ALB_Country_Code_Provider::class)
            && method_exists(\ALB\ALB_Country_Code_Provider::class, 'getCountryNumericCodeByAlpha2')
        ) {
            $country_code_provider = new \ALB\ALB_Country_Code_Provider();

            return $country_code_provider->getCountryNumericCodeByAlpha2($country_code);
        }

        return '';
    }

    /**
     * @param $order_id
     * @param $amount
     * @param $reason
     * @return true|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $refundAmount = (int)round((float)$amount * 100);
        $allianceRefund = new \ALB\AllianceRefund();
        $response = $allianceRefund->process_refund((int)$order_id, $refundAmount);

        if (!$response['success']) {
            return new \WP_Error(
                'alb_refund_error',
                $response['message'] ?? __('Refund error via AlliancePay', 'alliancepay')
            );
        }

        return true;
    }
}
