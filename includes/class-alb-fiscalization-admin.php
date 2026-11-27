<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Fiscalization_Admin. Блок «Фіскалізація» в замовленні, REST-кнопки та callback-ендпоінт.
 */
class ALB_Fiscalization_Admin
{
    public static function init(): void
    {
        add_action('add_meta_boxes', [__CLASS__, 'register_meta_box'], 20, 2);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);

        add_filter('rest_pre_dispatch', [__CLASS__, 'normalize_notify_content_type'], -10, 3);
    }

    /**
     * @param $result
     * @param $server
     * @param $request
     * @return mixed
     */
    public static function normalize_notify_content_type($result, $server, $request)
    {
        if ($request instanceof \WP_REST_Request && strpos($request->get_route(), '/alb/v1/fiscalization-notify') === 0) {
            $request->set_header('content_type', 'text/plain');
        }

        return $result;
    }

    /**
     * @return void
     */
    public static function register_routes(): void
    {
        $canManage = static fn() => current_user_can('manage_woocommerce');

        foreach (['send', 'refresh'] as $action) {
            register_rest_route('alb/v1', '/fiscalization/' . $action, [
                'methods' => 'POST',
                'permission_callback' => $canManage,
                'callback' => static fn(\WP_REST_Request $r) => self::handle_action($action, $r),
            ]);
        }

        // Публічний JWS-callback Ecom: без авторизації CMS, перевірка підпису всередині.
        register_rest_route('alb/v1', '/fiscalization-notify', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [__CLASS__, 'handle_notify'],
        ]);
    }

    /**
     * @param string $action
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    private static function handle_action(string $action, \WP_REST_Request $request): \WP_REST_Response
    {
        $orderId = (int)$request->get_param('order_id');
        $operationId = sanitize_text_field((string)$request->get_param('operation_id'));
        $type = strtoupper(sanitize_text_field((string)$request->get_param('operation_type')));

        if (!$orderId || $operationId === '' || !current_user_can('edit_shop_order', $orderId)) {
            return new \WP_REST_Response(['ok' => false, 'message' => __('Invalid request.', 'alliancepay')], 400);
        }

        $service = new ALB_Fiscalization_Service();
        try {
            $result = $action === 'send'
                ? $service->fiscalize($orderId, $operationId, $type)
                : $service->refresh($orderId, $operationId);
        } catch (\Throwable $e) {
            ALB_Fiscalization_Service::log('error', 'admin action failed: ' . $e->getMessage());
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function handle_notify(\WP_REST_Request $request): \WP_REST_Response
    {
        $raw = trim($request->get_body());
        if (strlen($raw) > 1 && $raw[0] === '"' && is_string($decoded = json_decode($raw))) {
            $raw = trim($decoded);
        }
        $opt = get_option(ALB_HPP_OPT, []);

        try {
            $data = ALB_JWS_Verifier::verify($raw, $opt);
        } catch (\Throwable $e) {
            ALB_Fiscalization_Service::log('warning', 'callback JWS verification failed: ' . $e->getMessage());

            return self::nocache(new \WP_REST_Response(['ok' => false, 'error' => 'JWS verification failed'], 400));
        }

        if (!is_array($data)) {
            return self::nocache(new \WP_REST_Response(['ok' => false, 'error' => 'Invalid payload'], 400));
        }

        ALB_Fiscalization_Service::log('info', 'callback', ['payload' => $data]);

        try {
            (new ALB_Fiscalization_Service())->handle_callback($data);
        } catch (\Throwable $e) {
            ALB_Fiscalization_Service::log('error', 'callback handling failed: ' . $e->getMessage());
        }

        // Як і платіжні callback-и модуля: 200 також для невідомого operationId.
        return self::nocache(new \WP_REST_Response(['ok' => true], 200));
    }

    private static function nocache(\WP_REST_Response $response): \WP_REST_Response
    {
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }

    // ------------------------------------------------------------------ meta box

    /**
     * @param string $postType
     * @param mixed $postOrOrder
     * @return void
     */
    public static function register_meta_box($postType, $postOrOrder = null): void
    {
        if (!ALB_Fiscalization_Settings::is_enabled() || !function_exists('wc_get_page_screen_id')) {
            return;
        }

        $screens = array_unique(['shop_order', wc_get_page_screen_id('shop-order')]);
        foreach ($screens as $screen) {
            add_meta_box(
                'alb-fiscalization',
                __('AlliancePay: fiscalization', 'alliancepay'),
                [__CLASS__, 'render_meta_box'],
                $screen,
                'normal',
                'default'
            );
        }
    }

    /**
     * @param \WP_Post|\WC_Order $postOrOrder
     * @return void
     */
    public static function render_meta_box($postOrOrder): void
    {
        $order = $postOrOrder instanceof \WC_Order ? $postOrOrder : wc_get_order($postOrOrder->ID);
        if (!$order || !ALB_Fiscalization_Service::is_order_eligible($order)) {
            echo '<p>' . esc_html__('Fiscalization is not available for this order.', 'alliancepay') . '</p>';

            return;
        }

        $service = new ALB_Fiscalization_Service();
        $repo = new ALB_Fiscalization_Repository();
        $canManage = current_user_can('manage_woocommerce');

        $operations = [];
        $purchase = $service->get_purchase_operation($order->get_id());
        if ($purchase) {
            $operations[] = [AllianceConfig::OPERATION_TYPE_PURCHASE, (string)$purchase['operationId'], true];
        }
        foreach ($service->get_refund_rows($order->get_id()) as $refund) {
            $operations[] = [
                AllianceConfig::OPERATION_TYPE_REFUND,
                (string)$refund->operation_id,
                strtoupper((string)$refund->status) === AllianceConfig::ORDER_STATUS_SUCCESS,
            ];
        }

        if (!$operations) {
            echo '<p>' . esc_html__('No successful AlliancePay payment or refund to fiscalize yet.', 'alliancepay') . '</p>';

            return;
        }

        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr>';
        foreach ([
                     __('Type', 'alliancepay'),
                     __('Status', 'alliancepay'),
                     __('Fiscal number', 'alliancepay'),
                     __('Receipt', 'alliancepay'),
                     __('Date', 'alliancepay'),
                     __('Error', 'alliancepay'),
                     '',
                 ] as $th) {
            echo '<th>' . esc_html($th) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($operations as [$type, $operationId, $ready]) {
            $row = $repo->find_by_operation_id($operationId);
            $status = $row ? (string)$row->status : '';

            echo '<tr>';
            echo '<td>' . esc_html($type) . '</td>';
            echo '<td>' . esc_html($status !== '' ? $status : '—') . '</td>';
            echo '<td>' . esc_html($row && $row->fiscal_number ? (string)$row->fiscal_number : '—') . '</td>';
            echo '<td>' . ($row && $row->receipt_url
                    ? '<a href="' . esc_url((string)$row->receipt_url) . '" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('View receipt', 'alliancepay') . '</a>'
                    : '—') . '</td>';
            echo '<td>' . esc_html($row && $row->fiscalization_date_time
                    ? get_date_from_gmt((string)$row->fiscalization_date_time, 'Y-m-d H:i:s')
                    : '—') . '</td>';
            echo '<td>' . esc_html($row && ($row->error_code || $row->error_description)
                    ? trim(($row->error_code ? $row->error_code . ': ' : '') . $row->error_description)
                    : '—') . '</td>';

            echo '<td>';
            if ($canManage) {
                $canSend = $ready && (!$row || $status === AllianceConfig::FISCAL_STATUS_ERROR);
                if ($canSend) {
                    self::button('send', $order->get_id(), $operationId, $type, __('Fiscalize', 'alliancepay'), true);
                }
                if ($row) {
                    self::button('refresh', $order->get_id(), $operationId, $type, __('Refresh status', 'alliancepay'), false);
                }
            }
            echo '</td></tr>';
        }

        echo '</tbody></table><p class="alb-fiscal-msg" style="margin-top:8px"></p>';

        self::print_script();
    }

    private static function button(string $action, int $orderId, string $operationId, string $type, string $label, bool $primary): void
    {
        printf(
            '<button type="button" class="button %s alb-fiscal-btn" data-action="%s" data-order="%d" data-operation="%s" data-type="%s">%s</button> ',
            $primary ? 'button-primary' : '',
            esc_attr($action),
            $orderId,
            esc_attr($operationId),
            esc_attr($type),
            esc_html($label)
        );
    }

    private static function print_script(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;
        ?>
        <script>
            (function () {
                const nonce = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
                const base = '<?php echo esc_js(rest_url('alb/v1/fiscalization')); ?>';
                document.querySelectorAll('.alb-fiscal-btn').forEach(function (btn) {
                    btn.addEventListener('click', async function () {
                        const msg = document.querySelector('.alb-fiscal-msg');
                        btn.disabled = true;
                        try {
                            const r = await fetch(base + '/' + btn.dataset.action, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json', 'X-WP-Nonce': nonce},
                                body: JSON.stringify({
                                    order_id: Number(btn.dataset.order),
                                    operation_id: btn.dataset.operation,
                                    operation_type: btn.dataset.type
                                })
                            });
                            const res = await r.json();
                            msg.textContent = res.message || '';
                            msg.style.color = res.ok ? 'green' : 'firebrick';
                            if (res.ok || res.status) {
                                setTimeout(function () { location.reload(); }, 1200);
                            }
                        } catch (e) {
                            msg.textContent = String(e);
                            msg.style.color = 'firebrick';
                        }
                        btn.disabled = false;
                    });
                });
            })();
        </script>
        <?php
    }
}
