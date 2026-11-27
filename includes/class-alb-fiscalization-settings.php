<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Fiscalization_Settings. Секція «Фіскалізація» на сторінці налаштувань модуля.
 */
class ALB_Fiscalization_Settings
{
    private const PAGE = 'alb-hpp';

    private const SECTION = 'alb_hpp_fiscal';

    public const KEYS = [
        'fiscalEnabled',
        'fiscalAuto',
        'fiscalTaxCode',
        'fiscalClassificationCode',
        'fiscalExciseLabels',
        'fiscalMeasurementCode',
    ];

    public static function init(): void
    {
        add_action('admin_init', [__CLASS__, 'register']);
    }

    /**
     * @return bool
     */
    public static function is_enabled(): bool
    {
        $opt = get_option(ALB_HPP_OPT, []);

        return is_array($opt) && ($opt['fiscalEnabled'] ?? 'no') === 'yes';
    }

    /**
     * @return bool
     */
    public static function is_auto(): bool
    {
        $opt = get_option(ALB_HPP_OPT, []);

        return self::is_enabled() && ($opt['fiscalAuto'] ?? 'no') === 'yes';
    }

    /**
     * @return void
     */
    public static function register(): void
    {
        add_settings_section(
            self::SECTION,
            __('Fiscalization', 'alliancepay'),
            static function () {
                echo '<p class="description">'
                    . esc_html__(
                        'Fiscalization of successful payments and refunds via Alliance Kasa. Default values below are used when a product has no own value.',
                        'alliancepay'
                    )
                    . '</p>';
            },
            self::PAGE
        );

        $fields = [
            'fiscalEnabled' => __('Enable fiscalization', 'alliancepay'),
            'fiscalAuto' => __('Automatic fiscalization', 'alliancepay'),
            'fiscalTaxCode' => __('Tax group code (default)', 'alliancepay'),
            'fiscalClassificationCode' => __('UKTZED code (default)', 'alliancepay'),
            'fiscalExciseLabels' => __('Excise stamps (default)', 'alliancepay'),
            'fiscalMeasurementCode' => __('Unit of measure code (default) *', 'alliancepay'),
        ];

        foreach ($fields as $key => $label) {
            add_settings_field(
                $key,
                esc_html($label),
                [__CLASS__, 'render_field'],
                self::PAGE,
                self::SECTION,
                ['field' => $key, 'label_for' => $key]
            );
        }
    }

    /**
     * @param array $args
     * @return void
     */
    public static function render_field(array $args): void
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $key = $args['field'];
        $name = esc_attr(ALB_HPP_OPT . '[' . $key . ']');

        switch ($key) {
            case 'fiscalEnabled':
            case 'fiscalAuto':
                printf('<input type="hidden" name="%s" value="no" />', $name);
                printf(
                    '<label><input type="checkbox" id="%s" name="%s" value="yes" %s /> %s</label>',
                    esc_attr($key),
                    $name,
                    checked(($opt[$key] ?? 'no'), 'yes', false),
                    esc_html($key === 'fiscalEnabled'
                        ? __('Send fiscalization requests and show the fiscalization block in orders', 'alliancepay')
                        : __('Fiscalize automatically after a successful payment or refund', 'alliancepay'))
                );
                if ($key === 'fiscalAuto') {
                    echo '<p class="description">' . esc_html__(
                            'If disabled, neither payments nor refunds are fiscalized automatically; use the "Fiscalize" button in the order.',
                            'alliancepay'
                        ) . '</p>';
                }
                break;

            case 'fiscalExciseLabels':
                printf(
                    '<textarea id="%s" name="%s" class="alb-field-textarea" rows="3">%s</textarea>',
                    esc_attr($key),
                    $name,
                    esc_textarea((string)($opt[$key] ?? ''))
                );
                echo '<p class="description">' . esc_html__(
                        'Comma-separated or one per line. Used only for tax codes 2, 7, 8 for excise goods.',
                        'alliancepay'
                    ) . '</p>';
                break;

            default:
                printf(
                    '<input type="text" id="%s" name="%s" value="%s" class="alb-field-text" />',
                    esc_attr($key),
                    $name,
                    esc_attr((string)($opt[$key] ?? ($key === 'fiscalMeasurementCode'
                        ? AllianceConfig::FISCAL_DEFAULT_MEASUREMENT_CODE
                        : '')))
                );
                $hints = [
                    'fiscalTaxCode' => __('Integer, optional. Leave empty if the default tax group is not needed.', 'alliancepay'),
                    'fiscalClassificationCode' => __('Digits only (usually 10). Required if the default tax code is 2, 7 or 8.', 'alliancepay'),
                    'fiscalMeasurementCode' => __('Required. Default "0001" (piece).', 'alliancepay'),
                ];
                if (isset($hints[$key])) {
                    echo '<p class="description">' . esc_html($hints[$key]) . '</p>';
                }
        }
    }

    /**
     * Валідація й нормалізація полів фіскалізації. При помилках лишаються раніше збережені значення.
     *
     * @param array $input Вхід sanitize_settings (за посиланням змінюється).
     * @param array $saved Раніше збережені налаштування.
     * @return array
     */
    public static function sanitize(array $input, array $saved): array
    {
        if (!array_intersect(self::KEYS, array_keys($input))) {
            return $input;
        }

        foreach (['fiscalEnabled', 'fiscalAuto'] as $flag) {
            $input[$flag] = (($input[$flag] ?? 'no') === 'yes') ? 'yes' : 'no';
        }

        $tax = trim((string)($input['fiscalTaxCode'] ?? ''));
        $class = trim((string)($input['fiscalClassificationCode'] ?? ''));
        $measure = trim((string)($input['fiscalMeasurementCode'] ?? ''));

        $errors = ALB_Fiscalization_Resolver::validate_raw($tax, $class, true);
        if ($measure === '') {
            $errors[] = __('Unit of measure code is required.', 'alliancepay');
        }

        $input['fiscalExciseLabels'] = implode(
            ', ',
            ALB_Fiscalization_Resolver::normalize_excise_labels($input['fiscalExciseLabels'] ?? '')
        );

        if ($errors) {
            foreach ($errors as $message) {
                add_settings_error(ALB_HPP_OPT, 'alb_fiscal_' . md5($message), $message, 'error');
            }
            foreach (['fiscalTaxCode', 'fiscalClassificationCode', 'fiscalExciseLabels', 'fiscalMeasurementCode'] as $k) {
                unset($input[$k]);
            }
            if (!isset($saved['fiscalMeasurementCode'])) {
                $input['fiscalMeasurementCode'] = AllianceConfig::FISCAL_DEFAULT_MEASUREMENT_CODE;
            }

            return $input;
        }

        $input['fiscalTaxCode'] = $tax;
        $input['fiscalClassificationCode'] = $class;
        $input['fiscalMeasurementCode'] = $measure;

        return $input;
    }
}

ALB_Fiscalization_Settings::init();
