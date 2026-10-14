<?php
/**
 * Plugin Name: AlliancePay
 * Description: Payment via Hosted Payment Page of Alliance Bank.
 * Version: 1.8.0
 * Author: <a href="https://alb.ua/uk" target="_blank">AlliancePay</a>
 * Text Domain: alliancepay
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */

use ALB\AllianceConfig;

if (!defined('ABSPATH')) exit;

define('ALB_HPP_DIR', plugin_dir_path(__FILE__));

if (!defined('ALB_HPP_TEST_BASE')) define('ALB_HPP_TEST_BASE', 'https://api-ecom-prod.bankalliance.ua');
if (!defined('ALB_HPP_PROD_BASE')) define('ALB_HPP_PROD_BASE', 'https://api-ecom-prod.bankalliance.ua');
if (!defined('ALB_HPP_TEST_JWE')) define('ALB_HPP_TEST_JWE', 'https://api-ecom-release.develop.bankalliance.ua');

define('ALB_HPP_URL', plugin_dir_url(__FILE__));
define('ALB_HPP_OPT', 'alb_hpp_options');

$autoload_path = dirname(__FILE__) . '/vendor/autoload.php';

if (file_exists($autoload_path)) {
    require_once $autoload_path;
} else {
    add_action('admin_notices', function () {
        echo '<div class="error"><p>AlliancePay: Composer dependencies not found. Please run "composer install".</p></div>';
    });
}

// ===== Підключення класів =====
require_once ALB_HPP_DIR . 'includes/class-alb-payments.php';
require_once ALB_HPP_DIR . 'includes/class-alb-api-endpoints.php';
require_once ALB_HPP_DIR . 'includes/class-alb-config.php';
require_once ALB_HPP_DIR . 'includes/class-alb-authorize.php';
require_once ALB_HPP_DIR . 'includes/class-alb-generate-request-identification.php';
require_once ALB_HPP_DIR . 'includes/class-alb-jws-signer.php';
require_once ALB_HPP_DIR . 'includes/class-alb-jws-verifier.php';
require_once ALB_HPP_DIR . 'includes/class-alb-hpp-client.php';
require_once ALB_HPP_DIR . 'includes/class-alb-admin.php';
require_once ALB_HPP_DIR . 'includes/class-alb-gateway-refund.php';
require_once ALB_HPP_DIR . 'includes/class-alb-wc-custom-data-validator.php';
require_once ALB_HPP_DIR . 'includes/class-alb-counrty-code-provider.php';
require_once ALB_HPP_DIR . 'includes/class-alb-preauth-complete.php';

// ===== Subscription / Recurring =====
require_once ALB_HPP_DIR . 'includes/class-alb-subscription-plans.php';
require_once ALB_HPP_DIR . 'includes/class-alb-subscription-gateway.php';
require_once ALB_HPP_DIR . 'includes/class-alb-subscription-callback.php';
require_once ALB_HPP_DIR . 'includes/class-alb-subscription-cancel.php';
require_once ALB_HPP_DIR . 'includes/class-alb-subscription-recurring.php';
require_once ALB_HPP_DIR . 'includes/class-alb-subscription-reprice.php';
require_once ALB_HPP_DIR . 'includes/class-alb-subscription-templates.php';
require_once ALB_HPP_DIR . 'includes/class-alb-subscription-plans-list.php';

// ===== Автогенерація Success/Fail сторінок + створення таблиці платежів =====
register_activation_hook(__FILE__, function () {
    $opt = get_option(ALB_HPP_OPT, []);

    // хелпер створення сторінки (або повернення існуючої з метаданими)
    $ensure_page = function (string $title, string $slug, string $meta_key, string $content) {
        $existing = get_posts([
            'post_type' => 'page',
            'posts_per_page' => 1,
            'meta_key' => $meta_key,
            'meta_value' => '1',
            'post_status' => 'publish',
            'fields' => 'ids',
        ]);
        if ($existing) return (int)$existing[0];

        // якщо користувач уже має сторінку з таким слагом
        $by_slug = get_page_by_path($slug, OBJECT, 'page');
        if ($by_slug && $by_slug->post_status === 'publish') {
            $pid = $by_slug->ID;
        } else {
            $pid = wp_insert_post([
                'post_title' => $title,
                'post_name' => $slug,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_content' => $content,
            ]);
        }
        if (!is_wp_error($pid) && $pid) {
            update_post_meta($pid, $meta_key, '1');
            return (int)$pid;
        }
        return 0;
    };

    $success_id = $ensure_page(
        __('Payment successful', 'alliancepay'),
        'alliancepay-success',
        '_alb_hpp_success_page',
        "<h1>"
        . __('Thank you for your payment!', 'alliancepay')
        . "</h1>\n<p>"
        . __('Your payment status: <strong>successful</strong>.', 'alliancepay')
        . "</p>\n<p><a href='" . esc_url(home_url('/')) . "'>"
        . __('Return to homepage', 'alliancepay') . "</a></p>"
    );
    $fail_id = $ensure_page(
        __('Payment failed', 'alliancepay'),
        'alliancepay-fail',
        '_alb_hpp_fail_page',
        "<h1>"
        . __('Unfortunately, the payment failed', 'alliancepay')
        . "</h1>\n<p>"
        . __('Please try again or contact us.', 'alliancepay')
        . "</p>\n<p><a href='" . esc_url(home_url('/')) . "'>"
        . __('Return to homepage', 'alliancepay') . "</a></p>"
    );

    if ($success_id) $opt['successUrl'] = get_permalink($success_id);
    if ($fail_id) $opt['failUrl'] = get_permalink($fail_id);

    if (empty($opt['notificationUrl'])) {
        $opt['notificationUrl'] = AllianceConfig::get_notification_url();
    }
    $opt['baseUrl'] = $opt['baseUrl'] ?? ALB_HPP_PROD_BASE;
    $opt['apiVersion'] = $opt['apiVersion'] ?? AllianceConfig::DEFAULT_API_VERSION;
    $opt['language'] = $opt['language'] ?? AllianceConfig::DEFAULT_LANGUAGE;
    if (empty($opt['paymentMethods'])) {
        $opt['paymentMethods'] = 'CARD,APPLE_PAY,GOOGLE_PAY';
    }

    if (class_exists('ALB_Payments')) {
        ALB_Payments::create_table();
        ALB_Payments::maybe_upgrade_schema();
    }

    flush_rewrite_rules();

    update_option(ALB_HPP_OPT, $opt);
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('alb_hpp_reauth_cron');
    wp_clear_scheduled_hook('alb_check_archivable_plans');
});

add_action('rest_api_init', function () {
    register_rest_route('alb/v1', '/notify', [
        'methods' => 'POST',
        'callback' => 'alb_hpp_rest_notify_handler',
        'permission_callback' => function (WP_REST_Request $request) {
            return $request->get_body() !== '';
        },
    ]);
});

add_filter('rest_pre_dispatch', function ($result, $server, WP_REST_Request $request) {
    if ($request->get_method() === 'POST' && $request->get_route() === '/alb/v1/notify') {
        return alb_hpp_rest_notify_handler($request);
    }
    return $result;
}, 10, 3);


/**
 * REST API notification handler (alternative endpoint for bank callbacks).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function alb_hpp_rest_notify_handler(WP_REST_Request $request): WP_REST_Response
{
    $raw = $request->get_body();
    $opt = get_option(ALB_HPP_OPT, []);

    $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
    if (!is_dir($dir)) wp_mkdir_p($dir);
    @file_put_contents(
        $dir . '/debug.log',
        print_r($raw, true) . PHP_EOL,
        FILE_APPEND
    );

    try {
        $data = \ALB\ALB_JWS_Verifier::verify($raw, $opt);
    } catch (\Throwable $e) {
        if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
            $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
            if (!is_dir($dir)) wp_mkdir_p($dir);
            @file_put_contents(
                $dir . '/debug.log',
                date('c') . " REST callback JWS verification failed: " . $e->getMessage() . PHP_EOL,
                FILE_APPEND
            );
        }
        return new WP_REST_Response(['ok' => false, 'error' => 'JWS verification failed'], 400);
    }

    if (!is_array($data)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    if (class_exists('\ALB\ALB_Subscription_Callback')) {
        $handled = \ALB\ALB_Subscription_Callback::handle($data, $raw);
        if ($handled) {
            return new WP_REST_Response(['ok' => true], 200);
        }
    }

    if (class_exists('WC_Gateway_ALB_HPP')) {
        $gateway = new WC_Gateway_ALB_HPP();
        $gateway->callback($data, $raw);
    }

    return new WP_REST_Response(['ok' => true], 200);
}

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $n = get_transient('alb_hpp_notice');
    if (!$n) return;
    delete_transient('alb_hpp_notice');
    echo '<div class="notice notice-' . esc_attr($n['type']) . ' is-dismissible"><p>' . esc_html($n['text']) . '</p></div>';
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $links[] = '<a href="' . esc_url(admin_url('admin.php?page=alb-hpp-manual')) . '">' . esc_html__('Documentation', 'alliancepay') . '</a>';
    $links[] = '<a href="' . esc_url(admin_url('admin.php?page=alb-hpp')) . '">' . esc_html__('Settings', 'alliancepay') . '</a>';
    return $links;
});


add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce') || !class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once ALB_HPP_DIR . 'includes/class-wc-gateway-alb-hpp.php';

    add_filter('woocommerce_payment_gateways', function ($methods) {
        $methods[] = 'WC_Gateway_ALB_HPP';
        return $methods;
    });
}, 11);

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) return;

    ALB\ALB_Subscription_Callback::init();
    ALB\ALB_Subscription_Cancel::init();
    ALB\ALB_Subscription_Recurring::init();
    ALB\ALB_Subscription_Plans::init();
    ALB\ALB_Subscription_Gateway::init();
    ALB\ALB_Subscription_Reprice::init();
    ALB\ALB_Subscription_Templates::init();
    ALB\ALB_Subscription_Plans_List::init();
}, 12);

add_action('plugins_loaded', 'alb_hpp_init_test_column');

function alb_hpp_init_test_column()
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    if (class_exists('ALB_Payments')) {
        ALB_Payments::maybe_upgrade_schema();
    }

    if (is_admin()) {
        add_filter('manage_woocommerce_page_wc-orders_columns', 'alliance_pay_add_status_column', 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', 'alb_hpp_populate_test_column', 20, 2);

        add_filter('manage_edit-shop_order_columns', 'alliance_pay_add_status_column', 20);
        add_action('manage_shop_order_posts_custom_column', 'alb_hpp_populate_test_column_legacy', 20, 2);

        $refund_service = new ALB\AllianceRefund();
        $refund_service->init_hooks();
    }

    if (class_exists('ALB\ALB_Preauth_Complete')) {
        $opt = get_option(ALB_HPP_OPT, []);
        $client = new ALB\AlbHppClient($opt);
        $preauth_complete = new ALB\ALB_Preauth_Complete($client, $opt);
        $preauth_complete->init_hooks();
    }
}

/**
 * Додає нову колонку в список ордерів
 */
function alliance_pay_add_status_column($columns)
{
    $new_columns = array();

    foreach ($columns as $key => $column) {
        if ($key === 'wc-actions') {
            $new_columns['alb_test_column'] = __('AlliancePay payment status', 'alliancepay');
        }
        $new_columns[$key] = $column;
    }

    if (!isset($new_columns['alb_test_column'])) {
        $new_columns['alb_test_column'] = __('AlliancePay payment status', 'alliancepay');
    }

    return $new_columns;
}

/**
 * Заповнює колонку для нової структури ордерів (HPOS)
 */
function alb_hpp_populate_test_column($column_name, $order)
{
    global $wpdb;
    if ($column_name === 'alb_test_column') {
        if (is_object($order)) {
            $order_id = $order->get_id();
        } else {
            $order_id = $order;
        }

        $table_alliance_checkout_integration_order = $wpdb->prefix . 'alliance_checkout_integration_order';
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_alliance_checkout_integration_order} WHERE order_id = %d",
                $order_id
            )
        );

        if ($result) {
            $url = admin_url('admin.php?page=alliance-pay-status&order_id=' . $order_id);
            echo '<a href="' . esc_url($url) . '" class="button button-small" target="_blank">' . esc_html__('Payment status', 'alliancepay') . '</a>';
            if (!$result->is_callback_returned) {
                echo '<p style="font-size: 10px; color: red;">' . esc_html__('No callback from AlliancePay. Check "Payment status"', 'alliancepay') . '</p>';
            }
            return;
        }

        alb_hpp_render_subscription_status_link($order_id);
    }
}

/**
 * @param int $order_id
 * @return void
 */
function alb_hpp_render_subscription_status_link(int $order_id): void
{
    if (!class_exists('\ALB\ALB_Subscription_Recurring')) {
        return;
    }

    $subscription = \ALB\ALB_Subscription_Recurring::get_subscription_by_order_id($order_id);
    if (!$subscription) {
        return;
    }

    $url = admin_url('admin.php?page=alliance-pay-status&order_id=' . $order_id);
    echo '<a href="' . esc_url($url) . '" class="button button-small" target="_blank">' . esc_html__('Payment status', 'alliancepay') . '</a>';

    if (!$subscription->hpp_order_id) {
        echo '<p style="font-size: 10px; color: red;">' . esc_html__('No callback from AlliancePay. Check "Payment status"', 'alliancepay') . '</p>';
    }
}

/**
 * Заповнює колонку для старої структури ордерів (posts)
 */
function alb_hpp_populate_test_column_legacy($column_name, $order_id)
{
    global $wpdb;
    if ($column_name === 'alb_test_column') {
        $table_alliance_checkout_integration_order = $wpdb->prefix . 'alliance_checkout_integration_order';
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_alliance_checkout_integration_order} WHERE order_id = %d",
                $order_id
            )
        );

        if ($result) {
            $url = admin_url('admin.php?page=alliance-pay-status&order_id=' . $order_id);
            echo '<a href="' . esc_url($url) . '" class="button button-small" target="_blank">' . esc_html__('Payment status', 'alliancepay') . '</a>';
            if (!$result->is_callback_returned) {
                echo '<p style="font-size: 10px; color: red;">' . esc_html__('No callback from AlliancePay. Check "Payment status"', 'alliancepay') . '</p>';
            }
            return;
        }

        alb_hpp_render_subscription_status_link((int) $order_id);
    }
}

/**
 * Додає меню сторінку в адмін панель
 */
add_action('admin_menu', 'alliance_pay_add_status_page');

function alliance_pay_add_status_page()
{
    add_submenu_page(
        null, // Прихована сторінка (без меню)
        __('AlliancePay status', 'alliancepay'),
        __('AlliancePay status', 'alliancepay'),
        'manage_options',
        'alliance-pay-status',
        'alb_hpp_test_page_content'
    );
}

/**
 * order detail status page
 */
function alb_hpp_test_page_content()
{
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

    if (class_exists('\ALB\ALB_Subscription_Recurring')
        && \ALB\ALB_Subscription_Recurring::get_subscription_by_order_id($order_id)
    ) {
        \ALB\ALB_Subscription_Recurring::render_status_detail_page($order_id);
        return;
    }

    $page = new WC_Gateway_ALB_HPP();
    $page->dataForStatusDetail($order_id);
}

add_action('init', function () {
    if (isset($_GET['alliance_pay_callback_notify'])) {
        alliance_pay_callback_notify_handler();
        exit;
    }
});

/**
 * @return void
 */
function alliance_pay_callback_notify_handler()
{
    $raw = file_get_contents('php://input');
    $trimmed = trim($raw);
    $opt = get_option(ALB_HPP_OPT, []);

    try {
        $data = \ALB\ALB_JWS_Verifier::verify($trimmed, $opt);
    } catch (\Throwable $e) {
        if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
            $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
            if (!is_dir($dir)) wp_mkdir_p($dir);
            @file_put_contents(
                $dir . '/debug.log',
                date('c') . " JWS callback verification failed: " . $e->getMessage() . PHP_EOL,
                FILE_APPEND
            );
        }
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(['ok' => false, 'error' => 'JWS verification failed']);
        return;
    }

    if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
        $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
        if (!is_dir($dir)) wp_mkdir_p($dir);
        @file_put_contents(
            $dir . '/debug.log',
            date('c') . " JWS callback received: " . $trimmed . PHP_EOL,
            FILE_APPEND
        );
    }

    if (!is_array($data)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(['ok' => false, 'error' => 'Invalid payload']);
        return;
    }

    if (class_exists('\ALB\ALB_Subscription_Callback')) {
        $handled = \ALB\ALB_Subscription_Callback::handle($data, $raw);
        if ($handled) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode(['ok' => true]);
            return;
        }
    }

    $gateway = new WC_Gateway_ALB_HPP();
    $gateway->callback($data, $raw);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode(['ok' => true]);
}

/**
 * Add action to initialize WooCommerce block supporting.
 */
add_action('woocommerce_blocks_loaded', function () {
    if (class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
            require_once __DIR__ . '/includes/class-alb-payment-blocks-support.php';
            $registry->register(new \Alb_Hpp_Gateway_Blocks_Support());
        });
    }
});
