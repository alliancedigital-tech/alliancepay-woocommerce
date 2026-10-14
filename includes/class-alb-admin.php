<?php
declare(strict_types=1);

use ALB\AllianceConfig;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Admin.
 */
class ALB_Admin
{

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_styles']);
    }

    /**
     * @param $hook
     * @return void
     */
    public static function enqueue_admin_styles($hook)
    {
        if ('toplevel_page_alb-hpp' !== $hook) {
            return;
        }

        wp_enqueue_style(
                'alb-hpp-admin',
                plugins_url('../assets/css/admin.css', __FILE__),
                [],
                '1.0.0'
        );
    }

    /**
     * @return void
     */
    public static function menu()
    {
        add_menu_page(
                'AlliancePay',
                'AlliancePay',
                'manage_options',
                'alb-hpp',
                [__CLASS__, 'render_page'],
                'dashicons-money',
                56
        );

        add_submenu_page(
                'alb-hpp',
                __('Settings', 'alliancepay'),
                __('Settings', 'alliancepay'),
                'manage_options',
                'alb-hpp',
                [__CLASS__, 'render_page']
        );

        add_submenu_page(
                'alb-hpp',
                __('Documentation', 'alliancepay'),
                __('Documentation', 'alliancepay'),
                'manage_options',
                'alb-hpp-manual',
                [__CLASS__, 'render_page_manual']
        );
    }

    /**
     * @return void
     */
    public static function render_page_manual()
    {
        $manual_url = plugins_url('../docs/manual.html', __FILE__);
        echo '<div class="wrap"><h1>' . esc_html__('AlliancePay Plugin — Documentation', 'alliancepay') . '</h1>';
        echo '<iframe src="'
                . esc_url($manual_url)
                . '" style="width:100%;height:80vh;border:1px solid'
                . ' #ccd0d4;border-radius:8px;background:#fff"></iframe>';
        echo '</div>';
    }

    /**
     * @return void
     */
    public static function settings()
    {
        register_setting('alb-hpp', ALB_HPP_OPT, [
                'capability' => 'manage_options',
                'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
        ]);

        add_settings_section('alb_hpp_main', __('Main settings', 'alliancepay'), '__return_false', 'alb-hpp');

        $fields = [
                'baseUrl' => __('Base URL API (provided by the bank) *', 'alliancepay'),
                'merchantId' => __('Merchant ID (provided by the bank) *', 'alliancepay'),
                'jwsPrivateKey' => __('JWS Private Key *', 'alliancepay'),
                'jwsKeyId' => __('JWS Key ID (kid) *', 'alliancepay'),
                'privateJwk' => __('Private JWK (for JWE encryption) *', 'alliancepay'),
                'hppPayType' => __('HPP Payment Type *', 'alliancepay'),
                'preAuthExpDate' => __('PREAUTH: Pre-Auth Expiration', 'alliancepay'),
                'preAuthOnHoldStatus' => __('PREAUTH: On-Hold Status (after preauth creation)', 'alliancepay'),
                'preAuthCompleteStatus' => __('PREAUTH: Completion Trigger Status (admin confirms capture)', 'alliancepay'),
                'successUrl' => __('Success URL', 'alliancepay'),
                'failUrl' => __('Fail URL', 'alliancepay'),
        ];

        $field_callbacks = [
                'jwsPrivateKey' => 'render_field_jws_private_key',
                'privateJwk' => 'render_field_private_jwk',
                'hppPayType' => 'render_field_select',
                'preAuthExpDate' => 'render_field_preauth_exp',
                'preAuthOnHoldStatus' => 'render_field_wc_status_select',
                'preAuthCompleteStatus' => 'render_field_wc_status_select',
        ];

        foreach ($fields as $key => $label) {
            $callback = $field_callbacks[$key] ?? 'render_field_text';

            add_settings_field(
                    $key,
                    esc_html($label),
                    [__CLASS__, $callback],
                    'alb-hpp',
                    'alb_hpp_main',
                    [
                            'field' => $key,
                            'label_for' => $key,
                    ]
            );
        }
    }

    /**
     * @param $input
     * @return array
     */
    public static function sanitize_settings($input)
    {
        $saved = get_option(ALB_HPP_OPT, []);
        $input = is_array($input) ? $input : [];

        foreach (['baseUrl', 'merchantId', 'successUrl', 'failUrl'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = trim((string)$input[$key]);
            }
        }

        if (isset($input['jwsPrivateKey'])) {
            $input['jwsPrivateKey'] = trim((string)$input['jwsPrivateKey']);
        }

        if (isset($input['jwsKeyId'])) {
            $input['jwsKeyId'] = trim((string)$input['jwsKeyId']);
        }

        if (isset($input['privateJwk'])) {
            $input['privateJwk'] = trim((string)$input['privateJwk']);
        }

        $allowed_pay_types = [
                AllianceConfig::HPP_PAY_TYPE_PURCHASE,
                AllianceConfig::HPP_PAY_TYPE_A2A,
                AllianceConfig::HPP_PAY_TYPE_PREAUTH,
        ];
        $input['hppPayType'] = in_array($input['hppPayType'] ?? '', $allowed_pay_types, true)
                ? $input['hppPayType']
                : AllianceConfig::HPP_PAY_TYPE_PURCHASE;

        $allowed_preauth_exp = ['2h', '4h', '6h', '12h', '1d', '2d', '7d', '14d', '28d'];
        $input['preAuthExpDate'] = in_array($input['preAuthExpDate'] ?? '', $allowed_preauth_exp, true)
                ? $input['preAuthExpDate']
                : '24h';

        $wc_statuses = function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : [];
        $wc_statuses_clean = array_map(fn($s) => ltrim($s, 'wc-'), $wc_statuses);

        $input['preAuthOnHoldStatus'] = in_array($input['preAuthOnHoldStatus'] ?? '', $wc_statuses_clean, true)
                ? $input['preAuthOnHoldStatus']
                : 'on-hold';

        $input['preAuthCompleteStatus'] = in_array($input['preAuthCompleteStatus'] ?? '', $wc_statuses_clean, true)
                ? $input['preAuthCompleteStatus']
                : 'processing';

        $input['apiVersion'] = AllianceConfig::DEFAULT_API_VERSION;
        $input['language'] = AllianceConfig::DEFAULT_LANGUAGE;

        return array_merge($saved, $input);
    }

    /**
     * @param $args
     * @return void
     */
    public static function render_field_text($args)
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $key = $args['field'];
        $val = esc_attr($opt[$key] ?? '');
        $name = esc_attr(ALB_HPP_OPT . '[' . $key . ']');

        printf(
                '<input type="text" id="%s" name="%s" value="%s" class="alb-field-text" />',
                esc_attr($key),
                $name,
                $val
        );

        if (in_array($key, ['successUrl', 'failUrl'], true)) {
            echo '<p class="description">' . esc_html__('Page to return the user after payment', 'alliancepay') . '</p>';
        }
    }

    /**
     * Render a textarea for the JWS EC P-256 private key in PEM format.
     *
     * @param $args
     * @return void
     */
    public static function render_field_jws_private_key($args)
    {
        $opt  = get_option(ALB_HPP_OPT, []);
        $key  = $args['field'];
        $val  = esc_textarea($opt[$key] ?? '');
        $name = esc_attr(ALB_HPP_OPT . '[' . $key . ']');

        printf(
            '<textarea id="%s" name="%s" class="alb-field-textarea" rows="8" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----">%s</textarea>',
            esc_attr($key),
            $name,
            $val
        );

        echo '<p class="description">'
            . esc_html__('RSA private key in PEM format (PKCS#8) for signing JWS tokens (RS256). Keep this secret — never share it.', 'alliancepay')
            . '</p>';
    }

    /**
     * @param $args
     * @return void
     */
    public static function render_field_private_jwk($args)
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $key = $args['field'];
        $val_raw = $opt[$key] ?? '';
        $val = esc_textarea(is_array($val_raw) ? wp_json_encode($val_raw) : $val_raw);
        $name = esc_attr(ALB_HPP_OPT . '[' . $key . ']');

        printf(
                '<textarea id="%s" name="%s" class="alb-field-textarea" placeholder=\'{"kty":"EC","crv":"P-384","d":"...","x":"...","y":"...","alg":"ECDH-ES+A256KW","use":"enc"}\'>%s</textarea>',
                esc_attr($key),
                $name,
                $val
        );

        echo '<p class="description">' . esc_html__('Private key for decrypting JWE. Not public.', 'alliancepay') . '</p>';
    }

    /**
     * @param $args
     * @return void
     */
    public static function render_field_select($args)
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $key = $args['field'];
        $current = $opt[$key] ?? 'PURCHASE';
        $options = ['PURCHASE' => 'PURCHASE', 'A2A' => 'A2A', 'PREAUTH' => 'PREAUTH'];
        $name = esc_attr(ALB_HPP_OPT . '[' . $key . ']');

        printf('<select id="%s" name="%s">', esc_attr($key), $name);

        foreach ($options as $value => $label) {
            printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($value),
                    selected($current, $value, false),
                    esc_html($label)
            );
        }

        echo '</select>';
    }

    /**
     * Render select for PREAUTH expiration duration.
     *
     * @param $args
     * @return void
     */
    public static function render_field_preauth_exp($args)
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $key = $args['field'];
        $current = $opt[$key] ?? '24h';
        $name = esc_attr(ALB_HPP_OPT . '[' . $key . ']');

        $options = [
                '2h' => __('2 hours', 'alliancepay'),
                '4h' => __('4 hours', 'alliancepay'),
                '6h' => __('6 hours', 'alliancepay'),
                '12h' => __('12 hours', 'alliancepay'),
                '1d' => __('1 day', 'alliancepay'),
                '2d' => __('2 days', 'alliancepay'),
                '7d' => __('7 days', 'alliancepay'),
                '14d' => __('14 days', 'alliancepay'),
                '28d' => __('28 days', 'alliancepay'),
        ];

        printf('<select id="%s" name="%s">', esc_attr($key), $name);

        foreach ($options as $value => $label) {
            printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($value),
                    selected($current, $value, false),
                    esc_html($label)
            );
        }

        echo '</select>';
        echo '<p class="description">' . esc_html__('Pre-auth authorization validity period. Min: 2 hours, Max: 28 days. Only applies when HPP Payment Type is PREAUTH.', 'alliancepay') . '</p>';
    }

    /**
     * Render select with WooCommerce order statuses.
     *
     * @param $args
     * @return void
     */
    public static function render_field_wc_status_select($args)
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $key = $args['field'];
        $name = esc_attr(ALB_HPP_OPT . '[' . $key . ']');

        $defaults = [
                'preAuthOnHoldStatus' => 'on-hold',
                'preAuthCompleteStatus' => 'processing',
        ];
        $current = $opt[$key] ?? ($defaults[$key] ?? '');

        $wc_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];

        printf('<select id="%s" name="%s">', esc_attr($key), $name);

        foreach ($wc_statuses as $status_key => $status_label) {
            $value = ltrim($status_key, 'wc-');
            printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($value),
                    selected($current, $value, false),
                    esc_html($status_label)
            );
        }

        echo '</select>';

        if ($key === 'preAuthOnHoldStatus') {
            echo '<p class="description">' . esc_html__('Order status set after PREAUTH creation (bank authorized but not captured). Default: On hold.', 'alliancepay') . '</p>';
        } elseif ($key === 'preAuthCompleteStatus') {
            echo '<p class="description">' . esc_html__('When admin sets the order to this status, a capture request is sent to the bank. Default: Processing.', 'alliancepay') . '</p>';
        }
    }

    /**
     * @return void
     */
    public static function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view this page.', 'alliancepay'));
        }
        $opt = get_option(ALB_HPP_OPT, []);

        echo '<div class="wrap"><h1>' . esc_html__('AlliancePay — Settings', 'alliancepay') . '</h1>';

        echo '<form method="post" action="options.php">';

        settings_fields('alb-hpp');
        do_settings_sections('alb-hpp');
        submit_button();

        echo '<p><strong>' . esc_html__('How to use:', 'alliancepay') . '</strong> ' . __('The checkout page must contain the shortcode <code>[woocommerce_checkout]</code>. ', 'alliancepay');
        echo __('Make sure <code>Merchant ID</code>, <code>JWS Private Key</code>, and <code>JWS Key ID</code> are filled in.<br>', 'alliancepay');
        echo __('<code>JWS Private Key</code> — EC P-256 private key in PEM format used to sign every API request.<br>', 'alliancepay');
        echo __('<code>JWS Key ID (kid)</code> — UUID identifier of the key registered in AIM_Service.<br>', 'alliancepay');
        echo __('<code>Private JWK</code> — EC P-384 private key in JWK format, used only for JWE encryption of refund operations.<br>', 'alliancepay');
        echo __('Set up the <code>Success URL</code> and <code>Fail URL</code> pages for redirection after successful (or unsuccessful) payment from the bank website.<br>', 'alliancepay');
        echo '</p>';

        echo '</form></div>';
        ?>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const nonce = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
                const base = '<?php echo esc_js(rest_url('alb/v1')); ?>';

                const strSyncError = '<?php echo esc_js(__('Synchronization error', 'alliancepay')); ?>';
                const strUpdating = '<?php echo esc_js(__('Updating...', 'alliancepay')); ?>';

                async function post(url, body) {
                    const r = await fetch(url, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'X-WP-Nonce': nonce},
                        body: JSON.stringify(body || {})
                    });
                    return await r.json();
                }

                document.querySelectorAll('.alb-sync-one').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        btn.disabled = true;
                        const old = btn.textContent;
                        btn.textContent = '...';
                        const id = btn.getAttribute('data-id');
                        const res = await post(base + '/sync-order', {id: Number(id)});
                        btn.disabled = false;
                        btn.textContent = old;
                        if (res && res.ok) {
                            location.reload();
                        } else {
                            alert(res.error || strSyncError);
                        }
                    });
                });

                const bulk = document.querySelector('.alb-sync-bulk');
                if (bulk) {
                    bulk.addEventListener('click', async () => {
                        bulk.disabled = true;
                        const old = bulk.textContent;
                        bulk.textContent = strUpdating;
                        const res = await post(base + '/sync-pending', {limit: 50});
                        bulk.disabled = false;
                        bulk.textContent = old;
                        if (res && res.ok) {
                            location.reload();
                        } else {
                            alert(res.error || strSyncError);
                        }
                    });
                }
            });
        </script>
        <?php
    }
}

ALB_Admin::init();
