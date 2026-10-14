<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 *
 * Manual Recurring Payment — Merchant Dashboard.
 *
 * Adds a meta box on the WC order detail page (admin) for subscription orders.
 * Shows list of scheduled payments and allows merchant to manually trigger
 * a recurring payment via POST /payments/recurring_subscription_v1.
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Subscription_Recurring.
 */
class ALB_Subscription_Recurring
{
    public static function init(): void
    {
        // Meta box on order detail page (HPOS + classic)
        add_action('add_meta_boxes', [__CLASS__, 'register_meta_box']);

        // REST API endpoint for merchant manual recurring trigger
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    // =========================================================================
    // Meta Box
    // =========================================================================

    /**
     * Register meta box for WC orders (supports HPOS + classic post).
     */
    public static function register_meta_box(): void
    {
        // Classic orders (post type)
        add_meta_box(
            'alb-subscription-payments',
            __('AlliancePay Subscription Payments', 'alliancepay'),
            [__CLASS__, 'render_meta_box'],
            'shop_order',
            'normal',
            'default'
        );

        // HPOS (woocommerce_page_wc-orders)
        if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            add_meta_box(
                'alb-subscription-payments',
                __('AlliancePay Subscription Payments', 'alliancepay'),
                [__CLASS__, 'render_meta_box'],
                wc_get_page_screen_id('shop-order'),
                'normal',
                'default'
            );
        }
    }

    /**
     * Render meta box content.
     *
     * @param mixed $post_or_order  WP_Post or WC_Order depending on HPOS
     */
    public static function render_meta_box($post_or_order): void
    {
        $order_id = is_a($post_or_order, 'WC_Order')
            ? $post_or_order->get_id()
            : (int)$post_or_order->ID;

        $subscription = self::get_subscription_by_order_id($order_id);

        if (!$subscription) {
            echo '<p style="color:#6b7280">'
                . esc_html__('This order is not associated with a subscription.', 'alliancepay')
                . '</p>';
            return;
        }

        $scheduled_payments = self::get_scheduled_payments((int) $subscription->id);

        $plan      = ALB_Subscription_Plans::get_plan_by_id($subscription->plan_id);
        $plan_name = $plan ? $plan->name : $subscription->plan_id;

        echo '<p>';
        echo '<strong>' . esc_html__('Plan:', 'alliancepay') . '</strong> ' . esc_html($plan_name) . '<br>';
        echo '<strong>' . esc_html__('HPP Order ID:', 'alliancepay') . '</strong> ' . esc_html($subscription->hpp_order_id ?: '—') . '<br>';
        echo '<strong>' . esc_html__('Status:', 'alliancepay') . '</strong> ' . esc_html($subscription->status) . '<br>';
        echo '</p>';

        if (empty($scheduled_payments)) {
            echo '<p>' . esc_html__('No scheduled payments recorded yet. They will appear after the first callback.', 'alliancepay') . '</p>';
            return;
        }

        $nonce       = wp_create_nonce('wp_rest');
        $run_url     = rest_url('alb/v1/run-recurring');
        $merchant_id = get_option(ALB_HPP_OPT, [])['merchantId'] ?? '';

        echo '<table class="wp-list-table widefat striped" style="margin-top:8px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Scheduled Payment ID', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Scheduled Date', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Status', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Executed At', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Operation ID', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Retries', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Action', 'alliancepay') . '</th>';
        echo '</tr></thead><tbody>';

        $runnable_statuses = [
            AllianceConfig::SCHEDULED_STATUS_WAITING,
            AllianceConfig::SCHEDULED_STATUS_RETRY,
            AllianceConfig::SCHEDULED_STATUS_FAIL,
        ];

        foreach ($scheduled_payments as $sp) {
            $status_colors = [
                'WAITING'          => '#d97706',
                'PROCESSING'       => '#2ea2cc',
                'SUCCESS'          => '#16a34a',
                'FAIL_WITHOUT_RETRY'=> '#dc2626',
                'RETRY'            => '#d97706',
                'SKIPPED_PAUSED'   => '#9ca3af',
                'INVALIDATED'      => '#9ca3af',
            ];
            $color = $status_colors[$sp->status] ?? '#6b7280';

            echo '<tr>';
            echo '<td style="font-size:11px;word-break:break-all">' . esc_html($sp->scheduled_payment_id ?: '—') . '</td>';
            echo '<td>' . esc_html($sp->scheduled_date ? date_i18n(get_option('date_format'), strtotime($sp->scheduled_date)) : '—') . '</td>';
            echo '<td><span style="color:' . esc_attr($color) . ';font-weight:600">' . esc_html($sp->status) . '</span></td>';
            echo '<td>' . esc_html($sp->executed_at ?: '—') . '</td>';
            echo '<td style="font-size:11px">' . esc_html($sp->operation_id ?: '—') . '</td>';
            echo '<td>' . esc_html((string)$sp->retry_count) . '</td>';
            echo '<td>';

            if (in_array($sp->status, $runnable_statuses, true) && $sp->scheduled_payment_id) {
                echo '<button class="button button-small alb-run-recurring"'
                    . ' data-sp-id="' . esc_attr($sp->scheduled_payment_id) . '"'
                    . ' data-merchant-id="' . esc_attr($merchant_id) . '"'
                    . ' data-nonce="' . esc_attr($nonce) . '"'
                    . ' data-url="' . esc_url($run_url) . '">'
                    . esc_html__('Run Now', 'alliancepay')
                    . '</button>';
                echo '<span class="alb-run-result" style="margin-left:6px;font-size:11px;"></span>';
            } else {
                echo '—';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        ?>
        <script>
        (function(){
            document.querySelectorAll('.alb-run-recurring').forEach(function(btn){
                btn.addEventListener('click', function(){
                    if (!confirm('<?php echo esc_js(__('Run this recurring payment manually?', 'alliancepay')); ?>')) return;

                    btn.disabled = true;
                    var resultEl = btn.nextElementSibling;
                    resultEl.textContent = '<?php echo esc_js(__('Running...', 'alliancepay')); ?>';
                    resultEl.style.color = '#6b7280';

                    fetch(btn.dataset.url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': btn.dataset.nonce
                        },
                        body: JSON.stringify({
                            scheduled_payment_id: btn.dataset.spId,
                            merchant_id: btn.dataset.merchantId
                        })
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res && res.ok) {
                            resultEl.style.color = '#16a34a';
                            resultEl.textContent = '<?php echo esc_js(__('Done! Status: ', 'alliancepay')); ?>' + (res.status || '');
                            setTimeout(function(){ location.reload(); }, 1500);
                        } else {
                            resultEl.style.color = '#dc2626';
                            resultEl.textContent = res.error || '<?php echo esc_js(__('Error', 'alliancepay')); ?>';
                            btn.disabled = false;
                        }
                    })
                    .catch(function(e){
                        resultEl.style.color = '#dc2626';
                        resultEl.textContent = e.message || '<?php echo esc_js(__('Network error', 'alliancepay')); ?>';
                        btn.disabled = false;
                    });
                });
            });
        })();
        </script>
        <?php
    }

    // =========================================================================
    // Status detail page (orders grid "Payment status" button)
    // =========================================================================

    /**
     * Render the same "AlliancePay Order Status" page used for one-time
     * payments (see WC_Gateway_ALB_HPP::dataForStatusDetail()), but for a
     * subscription order — plan, hppOrderId, subscription status, and the
     * scheduled-payments history. All data is local (no bank call needed;
     * the merchant meta-box on the order edit screen already surfaces the
     * same data live).
     *
     * @param int $order_id
     * @return void
     */
    public static function render_status_detail_page(int $order_id): void
    {
        $subscription = self::get_subscription_by_order_id($order_id);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('AlliancePay Order Status', 'alliancepay') . '</h1>';
        echo '<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px;">';
        echo '<h2 style="color: #2ea2cc;">' . esc_html__('AlliancePay subscription details', 'alliancepay') . '</h2>';

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
                echo '<p><strong>' . esc_html__('Woocommerce Order Status:', 'alliancepay') . '</strong> ' . esc_html($order->get_status()) . '</p>';
                echo '<p><strong>' . esc_html__('Order Total:', 'alliancepay') . '</strong> ' . $order->get_formatted_order_total() . '</p>';
            }
        }

        if (!$subscription) {
            echo '<h2 style="color: firebrick">' . esc_html__('This order is not associated with a subscription.', 'alliancepay') . '</h2>';
            echo '</div></div>';
            return;
        }

        $plan      = ALB_Subscription_Plans::get_plan_by_id($subscription->plan_id);
        $plan_name = $plan ? $plan->name : $subscription->plan_id;

        echo '<p><strong>' . esc_html__('Plan:', 'alliancepay') . '</strong> ' . esc_html($plan_name) . '</p>';
        echo '<p><strong>' . esc_html__('HPP Order ID:', 'alliancepay') . '</strong> ' . esc_html($subscription->hpp_order_id ?: '—') . '</p>';
        echo '<p><strong>' . esc_html__('Subscription Status:', 'alliancepay') . '</strong> ' . esc_html($subscription->status) . '</p>';

        if (!$subscription->hpp_order_id) {
            echo '<p style="color: firebrick;">'
                . esc_html__('No callback from AlliancePay yet for the initial payment. Check back after the customer completes checkout on the bank page.', 'alliancepay')
                . '</p>';
        }

        self::render_operations_section((string) $subscription->hpp_order_id);

        $scheduled_payments = self::get_scheduled_payments((int) $subscription->id);

        if (empty($scheduled_payments)) {
            echo '<p>' . esc_html__('No scheduled payments recorded yet. They will appear after the first callback.', 'alliancepay') . '</p>';
            echo '</div></div>';
            return;
        }

        $status_colors = [
            'WAITING'            => '#d97706',
            'PROCESSING'         => '#2ea2cc',
            'SUCCESS'            => '#16a34a',
            'FAIL_WITHOUT_RETRY' => '#dc2626',
            'RETRY'              => '#d97706',
            'SKIPPED_PAUSED'     => '#9ca3af',
            'INVALIDATED'        => '#9ca3af',
        ];

        echo '<h3>' . esc_html__('Scheduled Payments', 'alliancepay') . '</h3>';
        echo '<table class="wp-list-table widefat striped" style="margin-top:8px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Scheduled Payment ID', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Scheduled Date', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Status', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Executed At', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Operation ID', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Retries', 'alliancepay') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($scheduled_payments as $sp) {
            $color = $status_colors[$sp->status] ?? '#6b7280';

            echo '<tr>';
            echo '<td style="font-size:11px;word-break:break-all">' . esc_html($sp->scheduled_payment_id ?: '—') . '</td>';
            echo '<td>' . esc_html($sp->scheduled_date ? date_i18n(get_option('date_format'), strtotime($sp->scheduled_date)) : '—') . '</td>';
            echo '<td><span style="color:' . esc_attr($color) . ';font-weight:600">' . esc_html($sp->status) . '</span></td>';
            echo '<td>' . esc_html($sp->executed_at ?: '—') . '</td>';
            echo '<td style="font-size:11px">' . esc_html($sp->operation_id ?: '—') . '</td>';
            echo '<td>' . esc_html((string) $sp->retry_count) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }

    /**
     * Render the "Operations" table — always fetched live from the bank
     * (operations_v1 via AlbHppClient::get_order()), never from local DB.
     *
     * @param string $hpp_order_id
     * @return void
     */
    private static function render_operations_section(string $hpp_order_id): void
    {
        echo '<h3>' . esc_html__('Operations', 'alliancepay') . '</h3>';

        if (!$hpp_order_id) {
            echo '<p>' . esc_html__('No operations recorded yet.', 'alliancepay') . '</p>';
            return;
        }

        try {
            $client     = new AlbHppClient(get_option(ALB_HPP_OPT, []));
            $res        = $client->get_order($hpp_order_id);
            $operations = AlbHppClient::extract_operations($res);
        } catch (\Throwable $e) {
            echo '<p style="color: firebrick;">'
                . esc_html(sprintf(
                    /* translators: %s: underlying error message */
                    __('Could not fetch live operations from AlliancePay: %s', 'alliancepay'),
                    $e->getMessage()
                ))
                . '</p>';
            return;
        }

        if (empty($operations)) {
            echo '<p>' . esc_html__('No operations recorded yet.', 'alliancepay') . '</p>';
            return;
        }

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
            echo '<td>' . esc_html((string) $op['type']) . '</td>';
            echo '<td>' . esc_html((string) $op['status']) . '</td>';
            echo '<td>' . (is_numeric($op['coinAmount']) ? esc_html(number_format(((float) $op['coinAmount']) / 100, 2)) . ' UAH' : '—') . '</td>';
            echo '<td style="font-size:11px;word-break:break-all">' . esc_html((string) $op['operationId']) . '</td>';
            echo '<td>' . ($op['receiptUrl']
                ? '<a href="' . esc_url($op['receiptUrl']) . '" target="_blank">' . esc_html__('View receipt', 'alliancepay') . '</a>'
                : '—') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================================
    // REST API
    // =========================================================================

    /**
     * Register REST routes.
     */
    public static function register_rest_routes(): void
    {
        register_rest_route('alb/v1', '/run-recurring', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_run_recurring'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);
    }

    /**
     * REST handler: manually trigger recurring payment.
     *
     * @param \WP_REST_Request $request
     * @return array
     */
    public static function rest_run_recurring(\WP_REST_Request $request): array
    {
        $scheduled_payment_id = sanitize_text_field($request->get_param('scheduled_payment_id') ?? '');
        $merchant_id          = sanitize_text_field($request->get_param('merchant_id') ?? '');

        if (!$scheduled_payment_id) {
            return ['ok' => false, 'error' => __('scheduledPaymentId is required.', 'alliancepay')];
        }

        $opt = get_option(ALB_HPP_OPT, []);
        if (!$merchant_id) {
            $merchant_id = $opt['merchantId'] ?? '';
        }

        $merchant_request_id = wp_generate_uuid4();
        $date                = (new \DateTime('now', wp_timezone()))->format('Y-m-d H:i:s.vP');

        $body = [
            'merchantRequestId'   => $merchant_request_id,
            'merchantId'          => $merchant_id,
            'date'                => $date,
            'scheduledPaymentId'  => $scheduled_payment_id,
        ];

        try {
            $client = new AlbHppClient($opt);
            $res    = $client->run_recurring_payment($body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // Update local scheduled payment status to PROCESSING
        self::update_scheduled_payment_status($scheduled_payment_id, AllianceConfig::SCHEDULED_STATUS_PROCESSING);

        return [
            'ok'               => true,
            'status'           => $res['status'] ?? '',
            'operationId'      => $res['operationId'] ?? '',
            'ecomOperationId'  => $res['ecomOperationId'] ?? '',
        ];
    }

    // =========================================================================
    // DB Helpers
    // =========================================================================

    /**
     * Get subscription associated with a WC order ID.
     *
     * @param int $order_id
     * @return object|null
     */
    public static function get_subscription_by_order_id(int $order_id): ?object
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscriptions();
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE wc_order_id = %d", $order_id)
        ) ?: null;
    }

    /**
     * Get all scheduled payments for a subscription.
     *
     * @param int $subscription_id
     * @return array
     */
    public static function get_scheduled_payments(int $subscription_id): array
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_scheduled_payments();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE subscription_id = %d ORDER BY scheduled_date ASC",
                $subscription_id
            )
        );
    }

    /**
     * Update scheduled payment status by bank scheduled_payment_id.
     *
     * @param string $scheduled_payment_id
     * @param string $status
     */
    private static function update_scheduled_payment_status(string $scheduled_payment_id, string $status): void
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_scheduled_payments();
        $wpdb->update($table, [
            'status'     => $status,
            'updated_at' => current_time('mysql'),
        ], ['scheduled_payment_id' => $scheduled_payment_id]);
    }
}
