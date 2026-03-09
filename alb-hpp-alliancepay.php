<?php
/**
 * Plugin Name: AlliancePay
 * Description: Payment via Hosted Payment Page of Alliance Bank.
 * Version: 1.3.1
 * Author: <a href="https://alb.ua/uk" target="_blank">AlliancePay</a>
 * Text Domain: alliancepay
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

define('ALB_HPP_DIR', plugin_dir_path(__FILE__));

// Environment base URLs
//if (!defined('ALB_HPP_TEST_BASE')) define('ALB_HPP_TEST_BASE', 'https://api-ecom-release.develop.bankalliance.ua');
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
require_once ALB_HPP_DIR.'includes/class-alb-payments.php';
require_once ALB_HPP_DIR.'includes/class-alb-hpp-client.php';
require_once ALB_HPP_DIR.'includes/class-alb-admin.php';
require_once ALB_HPP_DIR.'includes/class-alb-authorize.php';
require_once ALB_HPP_DIR.'includes/class-alb-api-endpoints.php';
require_once ALB_HPP_DIR.'includes/class-alb-config.php';
require_once ALB_HPP_DIR.'includes/class-alb-gateway-refund.php';

// ===== Автогенерація Success/Fail сторінок + створення таблиці платежів =====
register_activation_hook(__FILE__, function () {
    $opt = get_option(ALB_HPP_OPT, []);

    // хелпер створення сторінки (або повернення існуючої з метаданими)
    $ensure_page = function(string $title, string $slug, string $meta_key, string $content) {
        $existing = get_posts([
            'post_type'      => 'page',
            'posts_per_page' => 1,
            'meta_key'       => $meta_key,
            'meta_value'     => '1',
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ]);
        if ($existing) return (int)$existing[0];

        // якщо користувач уже має сторінку з таким слагом
        $by_slug = get_page_by_path($slug, OBJECT, 'page');
        if ($by_slug && $by_slug->post_status === 'publish') {
            $pid = $by_slug->ID;
        } else {
            $pid = wp_insert_post([
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_status'  => 'publish',
                'post_type'    => 'page',
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
        __( 'Payment successful', 'alliancepay' ),
        'alliancepay-success',
        '_alb_hpp_success_page',
        "<h1>" . __( 'Thank you for your payment!', 'alliancepay' ) . "</h1>\n<p>" . __( 'Your payment status: <strong>successful</strong>.', 'alliancepay' ) . "</p>\n<p><a href='".esc_url(home_url('/'))."'>" . __( 'Return to homepage', 'alliancepay' ) . "</a></p>"
    );
    $fail_id = $ensure_page(
        __( 'Payment failed', 'alliancepay' ),
        'alliancepay-fail',
        '_alb_hpp_fail_page',
        "<h1>" . __( 'Unfortunately, the payment failed', 'alliancepay' ) . "</h1>\n<p>" . __( 'Please try again or contact us.', 'alliancepay' ) . "</p>\n<p><a href='".esc_url(home_url('/'))."'>" . __( 'Return to homepage', 'alliancepay' ) . "</a></p>"
    );

    if ($success_id) $opt['successUrl'] = get_permalink($success_id);
    if ($fail_id)    $opt['failUrl']    = get_permalink($fail_id);

    if (empty($opt['notificationUrl'])) {
        $opt['notificationUrl'] = home_url('/wp-json/alb/v1/notify');
    }
    $opt['baseUrl']     = $opt['baseUrl']     ?? ALB_HPP_PROD_BASE;
    $opt['apiVersion']  = $opt['apiVersion']  ?? 'v1';
    $opt['language']    = $opt['language']    ?? 'uk';
    if (empty($opt['paymentMethods'])) {
        $opt['paymentMethods'] = 'CARD,APPLE_PAY,GOOGLE_PAY';
    }

    // створити таблицю платежів
    if (class_exists('ALB_Payments')) {
        ALB_Payments::create_table();
    }

    update_option(ALB_HPP_OPT, $opt);
});

// === Token auto reauth кожні 12 годин ===
// Плануємо подію на активації
register_activation_hook(__FILE__, function(){
    if (!wp_next_scheduled('alb_hpp_reauth_cron')) {
        // перший запуск через 5 хвилин (щоб уникнути навантаження відразу після активації)
        wp_schedule_event(time() + 300, 'twicedaily', 'alb_hpp_reauth_cron');
    }
});

// Очищаємо на деактивації
register_deactivation_hook(__FILE__, function(){
    wp_clear_scheduled_hook('alb_hpp_reauth_cron');
});

// Failsafe: якщо з якоїсь причини подія зникла — створюємо знову
add_action('init', function () {
    if (!wp_next_scheduled('alb_hpp_reauth_cron')) {
        wp_schedule_event(time() + 300, 'twicedaily', 'alb_hpp_reauth_cron');
    }
});

// Обробник: викликаємо повторну авторизацію раз на 12 годин
add_action('alb_hpp_reauth_cron', function () {
    $opt = get_option(ALB_HPP_OPT, []);
    $now = time();
    $exp = (int)($opt['alb_token_expires_at'] ?? 0);
    $iat = (int)($opt['alb_token_issued_at']  ?? 0);

    // Оновлюємо у будь-якому з випадків:
    // - токен відсутній;
    // - лишилось <= 12 год до закінчення;
    // - з моменту видачі пройшло >= 12 год (дублюючий запобіжник).
    if (!$exp || !$iat || ($exp - $now) <= 12 * HOUR_IN_SECONDS || ($now - $iat) >= 12 * HOUR_IN_SECONDS) {
        try {
            ALB_Authorize::reauthorize_device($opt);
        } catch (\Throwable $e) {
            error_log('ALB reauth failed: '.$e->getMessage());
        }
    }
});

// ===== REST: Переавторизувати зараз =====
function alb_hpp_rest_reauthorize_now( WP_REST_Request $r ){
    if ( ! current_user_can('manage_options') ) {
        return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'], 403);
    }
    $opt = get_option(ALB_HPP_OPT, []);
    try {
        \ALB\ALB_Authorize::reauthorize_device($opt);
        $opt = get_option(ALB_HPP_OPT, []);
        $exp = (int)($opt['alb_token_expires_at'] ?? 0);
        return ['ok'=>true,'expiresAt'=>$exp];
    } catch (Throwable $e) {
        return ['ok'=>false,'error'=>$e->getMessage()];
    }
}
add_action('rest_api_init', function(){
    register_rest_route('alb/v1', '/reauthorize-now', [
        'methods'  => 'POST',
        'callback' => 'alb_hpp_rest_reauthorize_now',
        'permission_callback' => '__return_true',
    ]);
});


// popup повідомлення, після редіректу
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $n = get_transient('alb_hpp_notice');
    if (!$n) return;
    delete_transient('alb_hpp_notice');
    echo '<div class="notice notice-' . esc_attr($n['type']) . ' is-dismissible"><p>' . esc_html($n['text']) . '</p></div>';
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $links[] = '<a href="' . esc_url(admin_url('admin.php?page=alb-hpp-manual')) . '">' . esc_html__( 'Documentation', 'alliancepay' ) . '</a>';
    $links[] = '<a href="' . esc_url(admin_url('admin.php?page=alb-hpp')) . '">' . esc_html__( 'Settings', 'alliancepay' ) . '</a>';
    return $links;
});


// === WooCommerce Gateway інтеграція ===
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

add_action('plugins_loaded', 'alb_hpp_init_test_column');

function alb_hpp_init_test_column()
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    if (is_admin()) {
        add_filter('manage_woocommerce_page_wc-orders_columns', 'alliance_pay_add_status_column', 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', 'alb_hpp_populate_test_column', 20, 2);

        add_filter('manage_edit-shop_order_columns', 'alliance_pay_add_status_column', 20);
        add_action('manage_shop_order_posts_custom_column', 'alb_hpp_populate_test_column_legacy', 20, 2);

        $refund_service = new ALB\AllianceRefund();
        $refund_service->init_hooks();
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
            $new_columns['alb_test_column'] = __( 'AlliancePay payment status', 'alliancepay' );
        }
        $new_columns[$key] = $column;
    }

    if (!isset($new_columns['alb_test_column'])) {
        $new_columns['alb_test_column'] = __( 'AlliancePay payment status', 'alliancepay' );
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
        $result = $wpdb->get_row("SELECT * FROM {$table_alliance_checkout_integration_order} WHERE order_id = {$order_id}");

        if ($result) {
            $url = admin_url('admin.php?page=alliance-pay-status&order_id=' . $order_id);
            echo '<a href="' . esc_url($url) . '" class="button button-small" target="_blank">' . esc_html__( 'Payment status', 'alliancepay' ) . '</a>';
            if (!$result->is_callback_returned){
                echo '<p style="font-size: 10px; color: red;">' . esc_html__( 'No callback from AlliancePay. Check "Payment status"', 'alliancepay' ) . '</p>';
            }
        }
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
        $result = $wpdb->get_row("SELECT * FROM {$table_alliance_checkout_integration_order} WHERE order_id = {$order_id}");

        if ($result){
            $url = admin_url('admin.php?page=alliance-pay-status&order_id=' . $order_id);
            echo '<a href="' . esc_url($url) . '" class="button button-small" target="_blank">' . esc_html__( 'Payment status', 'alliancepay' ) . '</a>';
            if (!$result->is_callback_returned){
                echo '<p style="font-size: 10px; color: red;">' . esc_html__( 'No callback from AlliancePay. Check "Payment status"', 'alliancepay' ) . '</p>';
            }
        }
    }
}

/**
 * Додає меню сторінку в адмін панель
 */
add_action('admin_menu', 'alliance_pay_add_status_page');

function alliance_pay_add_status_page() {
    add_submenu_page(
        null, // Прихована сторінка (без меню)
        __( 'AlliancePay status', 'alliancepay' ),
        __( 'AlliancePay status', 'alliancepay' ),
        'manage_options',
        'alliance-pay-status',
        'alb_hpp_test_page_content'
    );
}

/**
 * order detail status page
 */
function alb_hpp_test_page_content() {
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

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
 * callback
 */
function alliance_pay_callback_notify_handler() {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    $gateway = new WC_Gateway_ALB_HPP();
    $gateway->callback($data, $raw);
}

/**
 * Add action to initialize WooCommerce block supporting.
 */
add_action('woocommerce_blocks_loaded', function() {
    if (class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
            require_once __DIR__ . '/includes/class-alb-payment-blocks-support.php';
            $registry->register(new \Alb_Hpp_Gateway_Blocks_Support());
        });
    }
});
