<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 *
 * Subscription Gateway — handles process_payment for subscription orders.
 *
 * Flow:
 *  1. Detect subscription intent from WC cart item meta.
 *  2. Call bank API: POST /subscription-orders/create_v1
 *  3. Persist subscription record in wp_alliance_subscriptions.
 *  4. Redirect customer to HPP (redirectUrl) — bank handles card entry + 3DS + init payment.
 *  5. Bank sends callback to our notification URL after payment.
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Subscription_Gateway.
 */
class ALB_Subscription_Gateway
{
    public static function init(): void
    {
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'maybe_attach_subscription_meta'], 10, 3);
    }

    /**
     * @param int $order_id
     * @param array $posted_data
     * @param $order
     * @return void
     */
    public static function maybe_attach_subscription_meta(int $order_id, array $posted_data, $order): void
    {
        foreach (WC()->cart->get_cart() as $item) {
            if (
                !empty($item['alb_subscription_mode'])
                && $item['alb_subscription_mode'] === 'subscribe'
                && !empty($item['alb_plan_id'])
            ) {
                $order->update_meta_data('_alb_subscription_mode', 'subscribe');
                $order->update_meta_data('_alb_subscription_plan_id', sanitize_text_field($item['alb_plan_id']));
                $order->save();
                break;
            }
        }
    }

    /**
     * @param int $order_id
     * @return array|string[]|null
     */
    public static function process_subscription_payment(int $order_id): ?array
    {
        $order = wc_get_order($order_id);
        $plan_id = $order->get_meta('_alb_subscription_plan_id');

        if (!$plan_id || $order->get_meta('_alb_subscription_mode') !== 'subscribe') {
            return null;
        }

        $opt = get_option(ALB_HPP_OPT, []);
        $opt['baseUrl'] = $opt['baseUrl'] ?? ALB_HPP_PROD_BASE;
        $opt['apiVersion'] = $opt['apiVersion'] ?? AllianceConfig::DEFAULT_API_VERSION;
        $opt['merchantId'] = $opt['merchantId'] ?? '';

        $current_user_id = get_current_user_id();
        if (!$current_user_id) {
            wc_add_notice(
                __('Please log in to complete a subscription purchase.', 'alliancepay'),
                'error'
            );

            return ['result' => 'failure'];
        }

        $merchantRequestId = wp_generate_uuid4();
        $sender_customer_id = (string)$current_user_id;

        $customer_data = [
            'senderCustomerId' => $sender_customer_id,
            'senderFirstName' => $order->get_billing_first_name() ?: $order->get_shipping_first_name() ?: '',
            'senderLastName' => $order->get_billing_last_name() ?: $order->get_shipping_last_name() ?: '',
            'senderEmail' => $order->get_billing_email() ?: '',
            'senderPhone' => $order->get_billing_phone() ?: '',
            'senderCountry' => self::get_country_numeric($order->get_billing_country() ?: $order->get_shipping_country() ?: ''),
            'senderRegion' => $order->get_billing_state() ?: $order->get_shipping_state() ?: '',
            'senderCity' => $order->get_billing_city() ?: $order->get_shipping_city() ?: '',
            'senderStreet' => $order->get_billing_address_1() ?: $order->get_shipping_address_1() ?: '',
            'senderAdditionalAddress' => $order->get_billing_address_2() ?: $order->get_shipping_address_2() ?: '',
            'senderZipCode' => $order->get_billing_postcode() ?: $order->get_shipping_postcode() ?: '',
            'senderIp' => $order->get_customer_ip_address() ?: '',
        ];

        $body = [
            'planId' => $plan_id,
            'merchantId' => $opt['merchantId'],
            'merchantRequestId' => $merchantRequestId,
            'purpose' => 'Subscription order #' . $order_id,
            'notificationUrl' => AllianceConfig::get_notification_url(),
            'notificationEncryption' => false,
            'merchantComment' => 'WooCommerce subscription order #' . $order_id,
            'statusPageType' => AllianceConfig::STATUS_PAGE_TYPE,
            'successUrl' => $opt['successUrl'] ?? home_url('/'),
            'failUrl' => $opt['failUrl'] ?? home_url('/'),
            'hppPaymentMethods' => ['CARD', 'APPLE_PAY', 'GOOGLE_PAY'],
            'directType' => 'REDIRECT',
            'customerData' => $customer_data,
        ];

        try {
            $client = new AlbHppClient($opt);
            $res = $client->create_subscription_order($body);
        } catch (\Throwable $e) {
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'failure'];
        }

        $hpp_order_id = $res['hppOrderId'] ?? null;
        $redirect_url = $res['redirectUrl'] ?? null;

        $plan_row = ALB_Subscription_Plans::get_plan_by_id($plan_id);
        $rules_snapshot = $plan_row ? $plan_row->rules : null;

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscriptions();
        $wpdb->insert($table, [
            'wc_order_id' => $order_id,
            'plan_id' => $plan_id,
            'rules_snapshot' => $rules_snapshot,
            'hpp_order_id' => $hpp_order_id,
            'merchant_request_id' => $merchantRequestId,
            'status' => AllianceConfig::SUB_ORDER_STATUS_PENDING,
            'customer_id' => $current_user_id ?: null,
            'created_at' => current_time('mysql'),
        ]);

        $order->update_status('pending', __('Awaiting subscription HPP payment.', 'alliancepay'));
        WC()->cart->empty_cart();

        if ($redirect_url) {
            return [
                'result' => 'success',
                'redirect' => $redirect_url,
            ];
        }

        wc_add_notice(__('A technical error occurred during subscription setup.', 'alliancepay'), 'error');
        return ['result' => 'failure'];
    }

    /**
     * @param string $alpha2
     * @return string
     */
    private static function get_country_numeric(string $alpha2): string
    {
        if (class_exists(ALB_Country_Code_Provider::class)) {
            $provider = new ALB_Country_Code_Provider();
            if (method_exists($provider, 'getCountryNumericCodeByAlpha2')) {
                return (string)$provider->getCountryNumericCodeByAlpha2($alpha2);
            }
        }
        return '';
    }
}
