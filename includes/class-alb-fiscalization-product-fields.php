<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Fiscalization_Product_Fields. Поля фіскалізації у формі товару та варіації.
 * Порожнє значення = fallback на варіант → батьківський товар → дефолт з налаштувань.
 */
class ALB_Fiscalization_Product_Fields
{
    public static function init(): void
    {
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'render_product_fields']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_product_fields']);

        add_action('woocommerce_variation_options_pricing', [__CLASS__, 'render_variation_fields'], 20, 3);
        add_action('woocommerce_admin_process_variation_object', [__CLASS__, 'save_variation_fields'], 10, 2);
    }

    /**
     * @return array<string, array{label:string, desc:string}>
     */
    private static function fields(): array
    {
        return [
            ALB_Fiscalization_Resolver::META_TAX_CODE => [
                'label' => __('Tax group code', 'alliancepay'),
                'desc' => __('Integer. Empty — default from AlliancePay settings.', 'alliancepay'),
            ],
            ALB_Fiscalization_Resolver::META_CLASSIFICATION_CODE => [
                'label' => __('UKTZED code', 'alliancepay'),
                'desc' => __('Digits only. Required for tax codes 2, 7, 8 (here or in settings).', 'alliancepay'),
            ],
            ALB_Fiscalization_Resolver::META_EXCISE_LABELS => [
                'label' => __('Excise stamps', 'alliancepay'),
                'desc' => __('Comma-separated. For excise goods with tax codes 2, 7, 8.', 'alliancepay'),
            ],
            ALB_Fiscalization_Resolver::META_MEASUREMENT_CODE => [
                'label' => __('Unit of measure code', 'alliancepay'),
                'desc' => __('Empty — default from settings ("0001").', 'alliancepay'),
            ],
        ];
    }

    /**
     * @return void
     */
    public static function render_product_fields(): void
    {
        if (!ALB_Fiscalization_Settings::is_enabled()) {
            return;
        }

        echo '<div class="options_group alb-fiscal-fields">';
        echo '<p class="form-field"><strong>' . esc_html__('AlliancePay: fiscalization', 'alliancepay') . '</strong></p>';
        foreach (self::fields() as $key => $field) {
            woocommerce_wp_text_input([
                'id' => $key,
                'label' => $field['label'],
                'description' => $field['desc'],
                'desc_tip' => true,
            ]);
        }
        wp_nonce_field('alb_fiscal_product', 'alb_fiscal_nonce');
        echo '</div>';
    }

    /**
     * @param \WC_Product $product
     * @return void
     */
    public static function save_product_fields($product): void
    {
        if (!isset($_POST['alb_fiscal_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['alb_fiscal_nonce'])), 'alb_fiscal_product')
            || !current_user_can('edit_product', $product->get_id())
        ) {
            return;
        }

        self::save_from_source($product, $_POST, '');
    }

    /**
     * @param int $loop
     * @param array $variation_data
     * @param \WP_Post $variation
     * @return void
     */
    public static function render_variation_fields($loop, $variation_data, $variation): void
    {
        if (!ALB_Fiscalization_Settings::is_enabled()) {
            return;
        }

        $variationId = is_object($variation) ? (int)$variation->ID : 0;
        $product = $variationId ? wc_get_product($variationId) : null;

        echo '<div class="alb-fiscal-variation" style="clear:both">';
        echo '<p class="form-row form-row-full"><strong>' . esc_html__('AlliancePay: fiscalization', 'alliancepay') . '</strong> '
            . '<span class="description">' . esc_html__('Empty value — taken from the parent product.', 'alliancepay') . '</span></p>';
        foreach (self::fields() as $key => $field) {
            $value = $product ? (string)$product->get_meta($key, true) : '';
            printf(
                '<p class="form-row form-row-first"><label>%s</label><input type="text" class="short" name="%s[%d]" value="%s" /></p>',
                esc_html($field['label']),
                esc_attr($key),
                (int)$loop,
                esc_attr($value)
            );
        }
        wp_nonce_field('alb_fiscal_product', 'alb_fiscal_nonce_var', false);
        echo '</div>';
    }

    /**
     * @param \WC_Product_Variation $variation
     * @param int $i
     * @return void
     */
    public static function save_variation_fields($variation, $i): void
    {
        if (!isset($_POST['alb_fiscal_nonce_var'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['alb_fiscal_nonce_var'])), 'alb_fiscal_product')
            || !current_user_can('edit_product', $variation->get_id())
        ) {
            return;
        }

        self::save_from_source($variation, $_POST, (string)$i);
    }

    /**
     * @param \WC_Product $product
     * @param array $source
     * @param string $index '' — просте поле, інакше індекс варіації.
     * @return void
     */
    private static function save_from_source($product, array $source, string $index): void
    {
        $read = static function (string $key) use ($source, $index): string {
            $raw = $source[$key] ?? '';
            if ($index !== '') {
                $raw = is_array($raw) ? ($raw[$index] ?? '') : '';
            }

            return is_string($raw) ? trim(sanitize_textarea_field(wp_unslash($raw))) : '';
        };

        $tax = $read(ALB_Fiscalization_Resolver::META_TAX_CODE);
        $class = $read(ALB_Fiscalization_Resolver::META_CLASSIFICATION_CODE);

        $errors = ALB_Fiscalization_Resolver::validate_raw($tax, $class, false);
        if ($errors) {
            foreach ($errors as $message) {
                if (class_exists('\WC_Admin_Meta_Boxes')) {
                    \WC_Admin_Meta_Boxes::add_error($message);
                }
            }

            return;
        }

        $product->update_meta_data(ALB_Fiscalization_Resolver::META_TAX_CODE, $tax);
        $product->update_meta_data(ALB_Fiscalization_Resolver::META_CLASSIFICATION_CODE, $class);
        $product->update_meta_data(
            ALB_Fiscalization_Resolver::META_EXCISE_LABELS,
            implode(', ', ALB_Fiscalization_Resolver::normalize_excise_labels($read(ALB_Fiscalization_Resolver::META_EXCISE_LABELS)))
        );
        $product->update_meta_data(
            ALB_Fiscalization_Resolver::META_MEASUREMENT_CODE,
            $read(ALB_Fiscalization_Resolver::META_MEASUREMENT_CODE)
        );
    }
}
