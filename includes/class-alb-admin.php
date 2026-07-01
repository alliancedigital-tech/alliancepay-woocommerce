<?php
declare(strict_types=1);

use ALB\AllianceConfig;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class ALB_Admin.
 */
class ALB_Admin {

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_styles' ] );
    }

    /**
     * @param $hook
     * @return void
     */
    public static function enqueue_admin_styles( $hook ) {
        if ( 'toplevel_page_alb-hpp' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'alb-hpp-admin',
            plugins_url( '../assets/css/admin.css', __FILE__ ),
            [],
            '1.0.0'
        );
    }

    /**
     * @return void
     */
    public static function menu() {
        add_menu_page(
            'AlliancePay',
            'AlliancePay',
            'manage_options',
            'alb-hpp',
            [ __CLASS__, 'render_page' ],
            'dashicons-money',
            56
        );

        add_submenu_page(
            'alb-hpp',
            __( 'Settings', 'alliancepay' ),
            __( 'Settings', 'alliancepay' ),
            'manage_options',
            'alb-hpp',
            [ __CLASS__, 'render_page' ]
        );

        add_submenu_page(
            'alb-hpp',
            __( 'Documentation', 'alliancepay' ),
            __( 'Documentation', 'alliancepay' ),
            'manage_options',
            'alb-hpp-manual',
            [ __CLASS__, 'render_page_manual' ]
        );
    }

    /**
     * @return void
     */
    public static function render_page_manual() {
        $manual_url = plugins_url( '../docs/manual.html', __FILE__ );
        echo '<div class="wrap"><h1>' . esc_html__( 'AlliancePay Plugin — Documentation', 'alliancepay' ) . '</h1>';
        echo '<iframe src="'
                . esc_url( $manual_url )
                . '" style="width:100%;height:80vh;border:1px solid'
                . ' #ccd0d4;border-radius:8px;background:#fff"></iframe>';
        echo '</div>';
    }

    /**
     * @return void
     */
    public static function settings() {
        register_setting( 'alb-hpp', ALB_HPP_OPT, [
            'capability'        => 'manage_options',
            'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
        ] );

        add_settings_section( 'alb_hpp_main', __( 'Main settings', 'alliancepay' ), '__return_false', 'alb-hpp' );

        $fields = [
            'baseUrl'      => __( 'Base URL API (provided by the bank) *', 'alliancepay' ),
            'merchantId'   => __( 'Merchant ID (provided by the bank) *', 'alliancepay' ),
            'serviceCode'  => __( 'Service Code (provided by the bank) *', 'alliancepay' ),
            'privateJwk'   => __( 'Private JWK (provided by the bank) *', 'alliancepay' ),
            'hppPayType' => __( 'HPP Payment Type *', 'alliancepay' ),
            'deviceId'     => __( 'Device ID', 'alliancepay' ),
            'refreshToken' => __( 'Refresh Token', 'alliancepay' ),
            'successUrl'   => __( 'Success URL', 'alliancepay' ),
            'failUrl'      => __( 'Fail URL', 'alliancepay' ),
        ];

        $field_callbacks = [
            'privateJwk'   => 'render_field_private_jwk',
            'hppPayType' => 'render_field_select',
            'deviceId'     => 'render_field_readonly',
            'refreshToken' => 'render_field_readonly',
        ];

        foreach ( $fields as $key => $label ) {
            $callback = $field_callbacks[ $key ] ?? 'render_field_text';

            add_settings_field(
                $key,
                esc_html( $label ),
                [ __CLASS__, $callback ],
                'alb-hpp',
                'alb_hpp_main',
                [
                    'field'     => $key,
                    'label_for' => $key,
                ]
            );
        }
    }

    /**
     * @param $input
     * @return array
     */
    public static function sanitize_settings( $input ) {
        $saved = get_option( ALB_HPP_OPT, [] );
        $input = is_array( $input ) ? $input : [];

        foreach ( [ 'baseUrl', 'serviceCode', 'merchantId', 'successUrl', 'failUrl' ] as $key ) {
            if ( isset( $input[ $key ] ) ) {
                $input[ $key ] = trim( (string) $input[ $key ] );
            }
        }

        if ( isset( $input['privateJwk'] ) ) {
            $input['privateJwk'] = trim( (string) $input['privateJwk'] );
        }

        $allowed_pay_types    = [ 'PURCHASE', 'A2A' ];
        $input['hppPayType'] = in_array( $input['hppPayType'] ?? '', $allowed_pay_types, true )
            ? $input['hppPayType']
            : 'PURCHASE';

        $input['apiVersion'] = AllianceConfig::DEFAULT_API_VERSION;
        $input['language']   = AllianceConfig::DEFAULT_LANGUAGE;

        foreach ( [ 'deviceId', 'refreshToken', 'alb_token_issued_at', 'alb_token_expires_at' ] as $key ) {
            if ( ! array_key_exists( $key, $input ) && array_key_exists( $key, $saved ) ) {
                $input[ $key ] = $saved[ $key ];
            }
        }

        return array_merge( $saved, $input );
    }

    /**
     * @param $args
     * @return void
     */
    public static function render_field_text( $args ) {
        $opt  = get_option( ALB_HPP_OPT, [] );
        $key  = $args['field'];
        $val  = esc_attr( $opt[ $key ] ?? '' );
        $name = esc_attr( ALB_HPP_OPT . '[' . $key . ']' );

        printf(
            '<input type="text" id="%s" name="%s" value="%s" class="alb-field-text" />',
            esc_attr( $key ),
            $name,
            $val
        );

        if ( in_array( $key, [ 'successUrl', 'failUrl' ], true ) ) {
            echo '<p class="description">' . esc_html__( 'Page to return the user after payment', 'alliancepay' ) . '</p>';
        }
    }

    /**
     * @param $args
     * @return void
     */
    public static function render_field_private_jwk( $args ) {
        $opt     = get_option( ALB_HPP_OPT, [] );
        $key     = $args['field'];
        $val_raw = $opt[ $key ] ?? '';
        $val     = esc_textarea( is_array( $val_raw ) ? wp_json_encode( $val_raw ) : $val_raw );
        $name    = esc_attr( ALB_HPP_OPT . '[' . $key . ']' );

        printf(
            '<textarea id="%s" name="%s" class="alb-field-textarea" placeholder=\'{"kty":"EC","crv":"P-384","d":"...","x":"...","y":"...","alg":"ECDH-ES+A256KW","use":"enc"}\'>%s</textarea>',
            esc_attr( $key ),
            $name,
            $val
        );

        echo '<p class="description">' . esc_html__( 'Private key for decrypting JWE. Not public.', 'alliancepay' ) . '</p>';
    }

    /**
     * @param $args
     * @return void
     */
    public static function render_field_readonly( $args ) {
        $opt     = get_option( ALB_HPP_OPT, [] );
        $key     = $args['field'];
        $val_raw = $opt[ $key ] ?? '';
        $val     = esc_attr( is_array( $val_raw ) ? implode( ',', $val_raw ) : $val_raw );

        printf(
            '<input type="text" id="%s" value="%s" class="alb-field-readonly" readonly disabled />',
            esc_attr( $key ),
            $val
        );

        echo '<p class="description">' . esc_html__( 'Information field. Updates after authorization.', 'alliancepay' ) . '</p>';
    }

    /**
     * @param $args
     * @return void
     */
    public static function render_field_select( $args ) {
        $opt     = get_option( ALB_HPP_OPT, [] );
        $key     = $args['field'];
        $current = $opt[ $key ] ?? 'PURCHASE';
        $options = [ 'PURCHASE' => 'PURCHASE', 'A2A' => 'A2A' ];
        $name    = esc_attr( ALB_HPP_OPT . '[' . $key . ']' );

        printf( '<select id="%s" name="%s">', esc_attr( $key ), $name );

        foreach ( $options as $value => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $value ),
                selected( $current, $value, false ),
                esc_html( $label )
            );
        }

        echo '</select>';
    }

    /**
     * @return void
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to view this page.', 'alliancepay' ) );
        }
        $opt = get_option( ALB_HPP_OPT, [] );

        echo '<div class="wrap"><h1>' . esc_html__( 'AlliancePay — Settings', 'alliancepay' ) . '</h1>';

        $exp       = (int) ( $opt['alb_token_expires_at'] ?? 0 );
        $exp_hours = $exp ? floor( ( $exp - time() ) / HOUR_IN_SECONDS ) : null;
        $exp_print = $exp ? esc_html( wp_date( 'Y-m-d H:i:s', $exp, wp_timezone() ) ) : '—';

        echo '<div class="alb-info" style="margin:12px 0;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff">';
        echo '<p style="margin:4px 0 0 0"><strong>' . esc_html__( 'Token valid until:', 'alliancepay' ) . '</strong> ' . $exp_print;
        echo ' <i style="color:#6b7280">(' . esc_html__( 'Auto-update every 12 hours, ', 'alliancepay' );
        if ( $exp ) {
            $left_color = ( $exp_hours <= 2 ) ? '#dc2626' : ( ( $exp_hours <= 4 ) ? '#d97706' : '#6b7280' );
            echo '<span style="color:' . $left_color . '"> ' . sprintf( esc_html__( 'left ~%d hrs.', 'alliancepay' ), (int) $exp_hours ) . '</span>';
        }
        echo ')</i>';
        echo '</p>';
        echo '<p style="margin:10px 0 0 0"><button type="button" class="button button-primary" id="alb-reauthorize-now">' . esc_html__( 'Reauthorize now', 'alliancepay' ) . '</button> <span id="alb-reauth-msg" style="margin-left:8px;color:#6b7280"></span></p>';
        echo '</div>';
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const btn = document.getElementById('alb-reauthorize-now');
                if (!btn) return;
                const msg = document.getElementById('alb-reauth-msg');
                const url = '<?php echo esc_js( rest_url( 'alb/v1/reauthorize-now' ) ); ?>';
                const nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';

                const strReauthorizing = '<?php echo esc_js( __( 'Reauthorizing...', 'alliancepay' ) ); ?>';
                const strDone          = '<?php echo esc_js( __( 'Done', 'alliancepay' ) ); ?>';
                const strError         = '<?php echo esc_js( __( 'Error', 'alliancepay' ) ); ?>';
                const strNetworkError  = '<?php echo esc_js( __( 'Network error', 'alliancepay' ) ); ?>';

                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    const old = btn.textContent;
                    btn.textContent = strReauthorizing;
                    msg.textContent = '';
                    try {
                        const r = await fetch(url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }
                        });
                        const j = await r.json();
                        if (j && j.ok) {
                            msg.style.color = '#16a34a';
                            msg.textContent = strDone;
                            setTimeout(() => location.reload(), 800);
                        } else {
                            msg.style.color = '#dc2626';
                            msg.textContent = (j && j.error) ? j.error : strError;
                        }
                    } catch (e) {
                        msg.style.color = '#dc2626';
                        msg.textContent = e.message || strNetworkError;
                    } finally {
                        btn.disabled = false;
                        btn.textContent = old;
                    }
                });
            });
        </script>
        <?php

        echo '<form method="post" action="options.php">';

        settings_fields( 'alb-hpp' );
        do_settings_sections( 'alb-hpp' );
        submit_button();

        echo '<p><strong>' . esc_html__( 'How to use:', 'alliancepay' ) . '</strong> ' . __( 'The checkout page must contain the shortcode <code>[woocommerce_checkout]</code>. ', 'alliancepay' );
        echo __( 'Make sure <code>Merchant ID</code>, <code>Service Code</code>, and <code>Private JWK</code> are filled in.<br> ', 'alliancepay' );
        echo __( 'On the first connection, click "Reauthorize now", verify that the <code>Device ID</code> and <code>Refresh Token</code> fields are filled automatically.<br>', 'alliancepay' );
        echo __( 'Set up the <code>Success URL</code> and <code>Fail URL</code> pages for redirection after successful (or unsuccessful) payment from the bank website.<br>', 'alliancepay' );
        echo '</p>';

        echo '</form></div>';
        ?>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
                const base  = '<?php echo esc_js( rest_url( 'alb/v1' ) ); ?>';

                const strSyncError = '<?php echo esc_js( __( 'Synchronization error', 'alliancepay' ) ); ?>';
                const strUpdating  = '<?php echo esc_js( __( 'Updating...', 'alliancepay' ) ); ?>';

                async function post(url, body) {
                    const r = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                        body: JSON.stringify(body || {})
                    });
                    return await r.json();
                }

                document.querySelectorAll('.alb-sync-one').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        btn.disabled = true;
                        const old = btn.textContent;
                        btn.textContent = '...';
                        const id  = btn.getAttribute('data-id');
                        const res = await post(base + '/sync-order', { id: Number(id) });
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
                        const res = await post(base + '/sync-pending', { limit: 50 });
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
