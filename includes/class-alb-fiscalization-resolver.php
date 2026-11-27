<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Fiscalization_Resolver.
 *
 * Правило вибору значень taxCode / classificationCode / exciseLabels / measurementCode:
 * атрибут товару (варіант → батьківський товар) → дефолт з налаштувань → поле не передається.
 * Кожне поле вирішується окремо. Використовується і авто-, і ручною фіскалізацією.
 */
class ALB_Fiscalization_Resolver
{
    public const META_TAX_CODE = '_alb_fiscal_tax_code';

    public const META_CLASSIFICATION_CODE = '_alb_fiscal_classification_code';

    public const META_EXCISE_LABELS = '_alb_fiscal_excise_labels';

    public const META_MEASUREMENT_CODE = '_alb_fiscal_measurement_code';

    /** @var array */
    private array $defaults;

    public function __construct(?array $options = null)
    {
        $opt = $options ?? get_option(ALB_HPP_OPT, []);
        $opt = is_array($opt) ? $opt : [];

        $this->defaults = [
            'taxCode' => self::normalize_tax_code((string)($opt['fiscalTaxCode'] ?? '')),
            'classificationCode' => self::normalize_classification_code((string)($opt['fiscalClassificationCode'] ?? '')),
            'exciseLabels' => self::normalize_excise_labels($opt['fiscalExciseLabels'] ?? ''),
            'measurementCode' => ($m = trim((string)($opt['fiscalMeasurementCode'] ?? ''))) !== ''
                ? $m
                : AllianceConfig::FISCAL_DEFAULT_MEASUREMENT_CODE,
        ];
    }

    /**
     * @param \WC_Product|null $product Товар або варіація.
     * @return array{taxCode:?int, classificationCode:?string, exciseLabels:string[], measurementCode:string}
     */
    public function resolve(?\WC_Product $product): array
    {
        return [
            'taxCode' => $this->pick_scalar($product, self::META_TAX_CODE, 'taxCode', [__CLASS__, 'normalize_tax_code']),
            'classificationCode' => $this->pick_scalar(
                $product,
                self::META_CLASSIFICATION_CODE,
                'classificationCode',
                [__CLASS__, 'normalize_classification_code']
            ),
            'exciseLabels' => $this->pick_scalar(
                $product,
                self::META_EXCISE_LABELS,
                'exciseLabels',
                [__CLASS__, 'normalize_excise_labels'],
                []
            ),
            'measurementCode' => (string)$this->pick_scalar(
                $product,
                self::META_MEASUREMENT_CODE,
                'measurementCode',
                static fn($v) => trim((string)$v) !== '' ? trim((string)$v) : null
            ),
        ];
    }

    /**
     * Локальна валідація умовної обов'язковості для taxCode 2, 7, 8.
     *
     * @param array $resolved Результат resolve().
     * @param string $productName Для тексту помилки.
     * @return string|null Текст помилки (uk/en через i18n) або null.
     */
    public static function validate(array $resolved, string $productName): ?string
    {
        if (!in_array($resolved['taxCode'], AllianceConfig::FISCAL_EXCISE_TAX_CODES, true)) {
            return null;
        }

        if (empty($resolved['classificationCode'])) {
            return sprintf(
                /* translators: 1: product name, 2: tax code list */
                __('Product "%1$s": classification code (UKTZED) is required for tax codes %2$s.', 'alliancepay'),
                $productName,
                implode(', ', AllianceConfig::FISCAL_EXCISE_TAX_CODES)
            );
        }

        return null;
    }

    /**
     * Значення з товару; для варіації — фолбек на батьківський товар; далі дефолт.
     *
     * @param \WC_Product|null $product
     * @param string $metaKey
     * @param string $defaultKey
     * @param callable $normalizer
     * @param mixed $empty Значення "порожнього" результату нормалізатора.
     * @return mixed
     */
    private function pick_scalar(?\WC_Product $product, string $metaKey, string $defaultKey, callable $normalizer, $empty = null)
    {
        foreach ($this->product_chain($product) as $candidate) {
            $value = $normalizer($candidate->get_meta($metaKey, true));
            if ($value !== null && $value !== $empty && $value !== []) {
                return $value;
            }
        }

        $default = $this->defaults[$defaultKey];

        return ($default === null || $default === '') ? $empty : $default;
    }

    /**
     * @param \WC_Product|null $product
     * @return \WC_Product[]
     */
    private function product_chain(?\WC_Product $product): array
    {
        if (!$product) {
            return [];
        }

        $chain = [$product];
        $parentId = $product->get_parent_id();
        if ($parentId) {
            $parent = wc_get_product($parentId);
            if ($parent) {
                $chain[] = $parent;
            }
        }

        return $chain;
    }

    /**
     * @param mixed $value
     * @return int|null null — порожнє або невалідне.
     */
    public static function normalize_tax_code($value): ?int
    {
        $value = trim((string)$value);

        return ($value !== '' && ctype_digit($value)) ? (int)$value : null;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    public static function normalize_classification_code($value): ?string
    {
        $value = trim((string)$value);

        return ($value !== '' && ctype_digit($value)) ? $value : null;
    }

    /**
     * Рядок (через кому/нові рядки) або масив → список без порожніх і пробілів.
     *
     * @param mixed $value
     * @return string[]
     */
    public static function normalize_excise_labels($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        $labels = [];
        foreach ($value as $label) {
            $label = trim((string)$label);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Спільна валідація значень (налаштування і форма товару). Порожнє значення — допустиме.
     *
     * @param string $taxCode
     * @param string $classificationCode
     * @param bool $requireClassificationForExcise Чи вимагати УКТЗЕД для taxCode 2,7,8.
     * @return string[] Повідомлення про помилки.
     */
    public static function validate_raw(string $taxCode, string $classificationCode, bool $requireClassificationForExcise): array
    {
        $errors = [];
        $taxCode = trim($taxCode);
        $classificationCode = trim($classificationCode);

        if ($taxCode !== '' && !ctype_digit($taxCode)) {
            $errors[] = __('Tax code must be an integer.', 'alliancepay');
        }
        if ($classificationCode !== '' && !ctype_digit($classificationCode)) {
            $errors[] = __('UKTZED code must contain digits only.', 'alliancepay');
        }
        if ($requireClassificationForExcise
            && $classificationCode === ''
            && in_array(self::normalize_tax_code($taxCode), AllianceConfig::FISCAL_EXCISE_TAX_CODES, true)
        ) {
            $errors[] = sprintf(
                /* translators: %s: tax codes */
                __('UKTZED code is required when the tax code is one of: %s.', 'alliancepay'),
                implode(', ', AllianceConfig::FISCAL_EXCISE_TAX_CODES)
            );
        }

        return $errors;
    }
}
