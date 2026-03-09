<?php

declare(strict_types=1);

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class Alb_Hpp_Gateway_Blocks_Support extends AbstractPaymentMethodType
{

    protected $name = 'alliance_pay';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_alliance_pay_settings', []);
    }

    public function is_active()
    {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->settings['title'] ?? '',
            'description' => $this->settings['description'] ?? '',
            'supports' => $this->settings['supports'] ?? '',
        ];
    }

    public function get_payment_method_script_handles() {
        $script_path = plugins_url('../assets/js/blocks-alb-hpp.js', __FILE__);
        wp_register_script(
            'alb-hpp-blocks',
            $script_path,
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities'],
            '1.0.0',
            true
        );

        $settings = [
            'id' => $this->get_name() ?? '',
            'name' => $this->settings['title'] ?? '',
            'description' => $this->get_payment_method_data()['description'] ?? '',
            'features' => $this->get_supported_features() ?? ''
        ];

        wp_add_inline_script(
            'alb-hpp-blocks',
            'window.albHppBlocksSettings = ' . wp_json_encode($settings) . ';',
            'before'
        );

        return ['alb-hpp-blocks'];
    }
}
