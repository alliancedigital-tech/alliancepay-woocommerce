<?php

use Automattic\WooCommerce\Enums\OrderInternalStatus;
Use ALB\AllianceConfig;

if (!defined('ABSPATH')) exit;

class WC_Gateway_ALB_HPP extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'alliance_pay';
        $this->method_title       = 'AlliancePay';
        $this->method_description = __( 'Payment via Hosted Payment Page of Alliance Bank', 'alliancepay' );
        $this->has_fields         = true;

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title', 'AlliancePay');
        $this->description = $this->get_option(
            'description',
            __( 'Pay with AlliancePay using Visa, Mastercard, ApplePay, GooglePay', 'alliancepay' )
        );

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Enable/Disable', 'alliancepay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this payment method', 'alliancepay' ),
                'default' => 'yes',
            ],
            'title'        => [
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'safe_text',
                'description' => __( 'AlliancePay', 'woocommerce' ),
                'default'     => __( 'AlliancePay', 'woocommerce' ),
                'desc_tip'    => true,
            ],
            'description'  => [
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment via Alliance Bank.', 'alliancepay' ),
                'default'     => __( 'Payment via Alliance Bank.', 'alliancepay' ),
                'desc_tip'    => true,
            ],
        ];
    }

    public function payment_fields() {
        parent::payment_fields();
    }

    public function process_payment($order_id) {
        global $wpdb;
        $order = wc_get_order($order_id);

        $opt = get_option(ALB_HPP_OPT, []);

        $opt['baseUrl']    = $opt['baseUrl']    ?? ALB_HPP_PROD_BASE;
        $opt['apiVersion'] = $opt['apiVersion'] ?? 'v1';
        $opt['language']   = $opt['language']   ?? 'uk';

        try {
            $client = new \ALB\AlbHppClient($opt);

            $coinAmount = (int) round((float) $order->get_total() * 100);

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
                'coinAmount'       => $coinAmount,
                'paymentMethods'   => ['CARD','APPLE_PAY','GOOGLE_PAY'],
                'language'         => 'uk',
                'successUrl'       => $opt['successUrl'] ?? home_url('/'),
                'failUrl'          => $opt['failUrl']    ?? home_url('/'),
                'notificationUrl'  => home_url('/?alliance_pay_callback_notify'),
                'merchantRequestId'=> $merchantRequestId,
                'customerData'     => $this->validate_and_clear_customer_data($customer_data)
            ];

            $res = $client->create_hpp_order($params);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        $table = AllianceConfig::table_alliance_checkout_integration_order();
        $paymentMethods = json_encode($res['paymentMethods']);

        $wpdb->insert($table, [
            'order_id'            => $order_id,
            'merchant_request_id' => $merchantRequestId,
            'hpp_order_id'        => $res['hppOrderId'] ?? null,
            'merchant_id'         => $res['merchantId'],
            'coin_amount'         => $res['coinAmount'] ?? null,
            'hpp_pay_type'        => $res['hppPayType'] ?? null,
            'order_status'        => $res['orderStatus'] ?? null,
            'payment_methods'     => $paymentMethods,
            'create_date'         => $res['createDate'] ?? null,
            'expired_order_date'  => $res['expiredOrderDate'] ?? null,
        ]);

        if (!empty($res['redirectUrl'])) {
            $order->payment_complete();
            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $res['redirectUrl'],
            ];
        }

        return new \WP_REST_Response(['ok' => false, 'error' => __( 'A technical error occurred', 'alliancepay' )], 500);
    }

    public function callback($data, $raw)
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_checkout_integration_order();

        $alliance_order = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE hpp_order_id = %s",
                $data['hppOrderId']
            )
        );

        $wp_order = wc_get_order($alliance_order->order_id);

        if (!$wp_order || !$alliance_order){
            return false;
        }

        if ($alliance_order->is_callback_returned){
            return false;
        }

        if (isset($data['orderStatus']) && $data['orderStatus'] == 'SUCCESS'){
            $wp_order->update_status(
                OrderInternalStatus::COMPLETED,
                __( 'Payment received via AlliancePay', 'alliancepay' )
            );
        }

        if (isset($data['orderStatus']) && $data['orderStatus'] != 'SUCCESS'){
            $wp_order->update_status(
                OrderInternalStatus::FAILED,
                __( 'Payment failed via AlliancePay', 'alliancepay' )
            );
        }

        $wpdb->update(
            $table,
            [
                'ecom_order_id' => $data['ecomOrderId'] ?? null,
                'is_callback_returned' => true,
                'callback_data' => $raw,
                'order_status' => $data['orderStatus'],
                'updated_at'    => current_time('mysql'),
            ],
            [
                'hpp_order_id' => $data['hppOrderId'],
            ],
        );
    }

    public function dataForStatusDetail($order_id)
    {
        global $wpdb;

        $is_call_to_ecom = false;
        $table_alliance_checkout_integration_order = AllianceConfig::table_alliance_checkout_integration_order();
        $result = $wpdb->get_row("SELECT * FROM {$table_alliance_checkout_integration_order} WHERE order_id = {$order_id}");

        if (!$result) {
            echo '<h2>' . esc_html__( 'This order was not paid via AlliancePay', 'alliancepay' ) . '</h2>';
            return false;
        }

        // формуємо дані з БД, якщо отримали колбек від еКому
        if ($result->is_callback_returned){
            $callback_data = json_decode($result->callback_data);
            $status_url = $callback_data->statusUrl ?? null;
            $ecom_order_id = $result->ecom_order_id;
            $merchant_id = $result->merchant_id;
            $hpp_order_id = $result->hpp_order_id;
            $hpp_pay_type = $result->hpp_pay_type;
            $merchant_request_id = $result->merchant_request_id;
            $order_status = $result->order_status;
        }

        // формуємо дані прямим запитом до еКому, якщо дані не отримали
        if (!$result->is_callback_returned){
            $is_call_to_ecom = true;
            try {
                $client = new \ALB\AlbHppClient(get_option(ALB_HPP_OPT, []));
                $res = $client->get_order($result->hpp_order_id);
                $ecom_order_id = $res['ecomOrderId'];
                $status_url = $res['statusUrl'];
                $merchant_id = $res['merchantId'];
                $hpp_order_id = $res['hppOrderId'];
                $hpp_pay_type = $res['hppPayType'];
                $merchant_request_id = $res['merchantRequestId'];
                $order_status = $res['orderStatus'];
            } catch (\Throwable $e) {
                echo '<h2>' . esc_html__( 'An error occurred while contacting AlliancePay', 'alliancepay' ) . '</h2>';
                return new \WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 500);
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'AlliancePay Order Status', 'alliancepay' ) . '</h1>';
        echo '<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px;">';
        echo '<h2 style="color: #2ea2cc;">' . esc_html__( 'AlliancePay payment details', 'alliancepay' ) . '</h2>';

        echo '<p style="margin-top:20px;">';
        echo '<a href="' . esc_url(admin_url('edit.php?post_type=shop_order')) . '" class="button button-primary">' . esc_html__( '⬅ Back to order list', 'alliancepay' ) . '</a>';
        echo '</p>';

        if ($order_id) {
            echo '<p><strong>' . esc_html__( 'Order ID:', 'alliancepay' ) . '</strong> ' . $order_id . '</p>';

            $order = wc_get_order($order_id);
            if ($order) {
                echo '<p><strong>' . esc_html__( 'Woocommerce Order Status:', 'alliancepay' ) . '</strong> ' . $order->get_status() . '</p>';
                echo '<p><strong>' . esc_html__( 'Order Total:', 'alliancepay' ) . '</strong> ' . $order->get_formatted_order_total() . '</p>';
            }
        }

        if ($is_call_to_ecom) {
            echo '<h2 style="color: firebrick">' . esc_html__( 'Callback from AllianceBank not received. The data below is taken directly from AlliancePay', 'alliancepay' ) . '</h2>';
        } else {
            echo '<h2 style="color: green">' . esc_html__( 'Callback from AllianceBank received. The data below is received from the callback and loaded into the DB', 'alliancepay' ) . '</h2>';
        }

        echo '<p><strong>ecomOrderId:</strong> ' . esc_html($ecom_order_id) . '</p>';
        echo '<p><strong>merchantId:</strong> ' . esc_html($merchant_id) . '</p>';
        echo '<p><strong>hppOrderId:</strong> ' . esc_html($hpp_order_id) . '</p>';
        echo '<p><strong>hppPayType:</strong> ' . esc_html($hpp_pay_type) . '</p>';
        echo '<p><strong>merchantRequestId:</strong> ' . esc_html($merchant_request_id) . '</p>';
        echo '<p><strong>AlliancePay orderStatus:</strong> ' . esc_html($order_status) . '</p>';
        echo '<p><strong>statusUrl:</strong> <a href="'.  esc_url($status_url) .'" target="_blank">' . esc_html($status_url) . '</a></p>';

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
     * Get state name by country code and state code
     *
     * @param string $country_code
     * @param string $state_code
     * @return string
     */
    public function get_state_name(string $country_code, string $state_code)
    {
        $countries = new WC_Countries();
        $states = $countries->get_states( $country_code );

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
}
