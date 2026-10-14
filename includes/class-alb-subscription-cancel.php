<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 *
 * Customer Subscription Management — My Account tab.
 *
 * Adds a "My Subscriptions" endpoint to WooCommerce My Account page.
 * Allows customers to view active subscriptions and cancel them.
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Subscription_Cancel.
 */
class ALB_Subscription_Cancel
{
    public const ENDPOINT = 'my-subscriptions';

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'add_endpoint']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'add_menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'render_page']);
        add_filter('the_title', [__CLASS__, 'endpoint_title']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    /**
     * @return void
     */
    public static function add_endpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    /**
     * @param array $items
     * @return array
     */
    public static function add_menu_item(array $items): array
    {
        $logout = $items['customer-logout'] ?? null;
        unset($items['customer-logout']);

        $items[self::ENDPOINT] = __('My Subscriptions', 'alliancepay');

        if ($logout !== null) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    /**
     * @param string $title
     * @return string
     */
    public static function endpoint_title(string $title): string
    {
        global $wp_query;

        if (!is_null($wp_query) && !is_admin() && is_main_query() && in_the_loop() && is_account_page()) {
            if (isset($wp_query->query_vars[self::ENDPOINT])) {
                return __('My Subscriptions', 'alliancepay');
            }
        }

        return $title;
    }

    /**
     * @return void
     */
    public static function render_page(): void
    {
        $customer_id = get_current_user_id();
        if (!$customer_id) {
            echo '<p>' . esc_html__('You must be logged in to view subscriptions.', 'alliancepay') . '</p>';
            return;
        }

        $subscriptions = self::get_customer_subscriptions($customer_id);

        if (empty($subscriptions)) {
            echo '<p>' . esc_html__('You have no active subscriptions.', 'alliancepay') . '</p>';
            return;
        }

        $nonce = wp_create_nonce('wp_rest');
        $cancel_url = rest_url('alb/v1/cancel-subscription');

        echo '<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Plan', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Order', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Status', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Created', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Actions', 'alliancepay') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($subscriptions as $sub) {
            $plan = ALB_Subscription_Plans::get_plan_by_id($sub->plan_id);
            $plan_name = $plan ? esc_html($plan->name) : esc_html($sub->plan_id);
            $order_url = wc_get_order($sub->wc_order_id) ? get_permalink(wc_get_order($sub->wc_order_id)->get_id()) : '#';

            $status_labels = [
                    'PENDING' => __('Pending', 'alliancepay'),
                    'ACTIVE' => __('Active', 'alliancepay'),
                    'CANCELED' => __('Cancelled', 'alliancepay'),
                    'FAILED' => __('Failed', 'alliancepay'),
            ];
            $status_label = $status_labels[$sub->status] ?? $sub->status;

            $status_colors = [
                    'PENDING' => '#d97706',
                    'ACTIVE' => '#16a34a',
                    'CANCELED' => '#6b7280',
                    'FAILED' => '#dc2626',
            ];
            $color = $status_colors[$sub->status] ?? '#6b7280';

            echo '<tr>';
            echo '<td>' . $plan_name . '</td>';
            echo '<td><a href="' . esc_url(wc_get_account_endpoint_url('view-order') . '/' . $sub->wc_order_id) . '">#' . esc_html((string)$sub->wc_order_id) . '</a></td>';
            echo '<td><span style="color:' . esc_attr($color) . ';font-weight:600">' . esc_html($status_label) . '</span></td>';
            echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($sub->created_at))) . '</td>';
            echo '<td>';

            if (!in_array($sub->status, ['CANCELED', 'FAILED'], true) && $sub->hpp_order_id) {
                echo '<button class="button alb-cancel-subscription"'
                        . ' data-id="' . esc_attr((string)$sub->id) . '"'
                        . ' data-hpp-order-id="' . esc_attr($sub->hpp_order_id) . '"'
                        . ' data-nonce="' . esc_attr($nonce) . '"'
                        . ' data-url="' . esc_url($cancel_url) . '">'
                        . esc_html__('Cancel Subscription', 'alliancepay')
                        . '</button>';
            } else {
                echo '—';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        ?>
        <script>
            (function () {
                document.querySelectorAll('.alb-cancel-subscription').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm('<?php echo esc_js(__('Are you sure you want to cancel this subscription?', 'alliancepay')); ?>')) return;

                        btn.disabled = true;
                        btn.textContent = '<?php echo esc_js(__('Cancelling...', 'alliancepay')); ?>';

                        fetch(btn.dataset.url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': btn.dataset.nonce
                            },
                            body: JSON.stringify({subscription_id: btn.dataset.id})
                        })
                            .then(function (r) {
                                return r.json();
                            })
                            .then(function (res) {
                                if (res && res.ok) {
                                    location.reload();
                                } else {
                                    alert(res.error || '<?php echo esc_js(__('Error. Please try again.', 'alliancepay')); ?>');
                                    btn.disabled = false;
                                    btn.textContent = '<?php echo esc_js(__('Cancel Subscription', 'alliancepay')); ?>';
                                }
                            })
                            .catch(function (e) {
                                alert(e.message || '<?php echo esc_js(__('Network error.', 'alliancepay')); ?>');
                                btn.disabled = false;
                                btn.textContent = '<?php echo esc_js(__('Cancel Subscription', 'alliancepay')); ?>';
                            });
                    });
                });
            })();
        </script>
        <?php
    }

    /**
     * Register REST routes.
     */
    public static function register_rest_routes(): void
    {
        register_rest_route('alb/v1', '/cancel-subscription', [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'rest_cancel_subscription'],
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
        ]);
    }

    /**
     * REST handler: cancel subscription.
     *
     * @param \WP_REST_Request $request
     * @return array
     */
    public static function rest_cancel_subscription(\WP_REST_Request $request): array
    {
        $subscription_id = (int)$request->get_param('subscription_id');
        $customer_id = get_current_user_id();

        if (!$subscription_id || !$customer_id) {
            return ['ok' => false, 'error' => __('Invalid request.', 'alliancepay')];
        }

        $subscription = self::get_subscription_by_id($subscription_id);

        if (!$subscription || (int)$subscription->customer_id !== $customer_id) {
            return ['ok' => false, 'error' => __('Subscription not found.', 'alliancepay')];
        }

        if (in_array($subscription->status, ['CANCELED', 'FAILED'], true)) {
            return ['ok' => false, 'error' => __('Subscription is already cancelled.', 'alliancepay')];
        }

        if (!$subscription->hpp_order_id) {
            return ['ok' => false, 'error' => __('Subscription order ID is missing.', 'alliancepay')];
        }

        try {
            $opt = get_option(ALB_HPP_OPT, []);
            $client = new AlbHppClient($opt);
            $client->cancel_subscription_order($subscription->hpp_order_id);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscriptions();
        $wpdb->update($table, [
                'status' => AllianceConfig::SUB_ORDER_STATUS_CANCELED,
                'updated_at' => current_time('mysql'),
        ], ['id' => $subscription_id]);

        ALB_Subscription_Plans::adjust_active_subscriptions_count($subscription->plan_id, -1);

        $wc_order = wc_get_order($subscription->wc_order_id);
        if ($wc_order) {
            $wc_order->update_status('cancelled', __('Subscription cancelled by customer.', 'alliancepay'));
        }

        return ['ok' => true];
    }

    /**
     * @param int $customer_id
     * @return array
     */
    public static function get_customer_subscriptions(int $customer_id): array
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscriptions();
        return $wpdb->get_results(
                $wpdb->prepare(
                        "SELECT * FROM {$table} WHERE customer_id = %d ORDER BY created_at DESC",
                        $customer_id
                )
        );
    }

    /**
     * @param int $id
     * @return object|array|\stdClass|null
     */
    public static function get_subscription_by_id(int $id): ?object
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscriptions();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)) ?: null;
    }
}
