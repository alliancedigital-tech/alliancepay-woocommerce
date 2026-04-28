<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

/**
 * Class WC_Custom_Data_Validator.
 */
class WC_Custom_Data_Validator
{
    private const VALIDATION_RULES = [
        'senderCustomerId' => [
            'type'     => 'string',
            'max_len'  => 255,
            'required' => true,
        ],
        'senderFirstName' => [
            'type'           => 'string',
            'max_len'        => 30,
            'required'       => false,
            'no_only_digits' => true,
            'pattern'        => '/^[a-zA-Z0-9а-яА-ЯёЁіІїЇєЄґҐ]([a-zA-Z0-9а-яА-ЯёЁіІїЇєЄґҐ\s\-\']*[a-zA-Z0-9а-яА-ЯёЁіІїЇєЄґҐ])?$/u',
            'stop_words'     => ['NULL', '3D SECURE', 'SURNAME', 'CARDHOLDER', 'UNKNOWN']
        ],
        'senderLastName' => [
            'type'           => 'string',
            'max_len'        => 30,
            'required'       => false,
            'no_only_digits' => true,
            'pattern'        => '/^[a-zA-Z0-9а-яА-ЯёЁіІїЇєЄґҐ]([a-zA-Z0-9а-яА-ЯёЁіІїЇєЄґҐ\s\-\']*[a-zA-Z0-9а-яА-ЯёЁіІїЇєЄґҐ])?$/u'
        ],
        'senderEmail' => [
            'type'     => 'string',
            'max_len'  => 256,
            'required' => false
        ],
        'senderCountry' => [
            'type'     => 'string',
            'max_len'  => 3,
            'required' => false
        ],
        'senderRegion' => [
            'type'     => 'string',
            'max_len'  => 255,
            'required' => false
        ],
        'senderCity' => [
            'type'     => 'string',
            'max_len'  => 25,
            'required' => false
        ],
        'senderStreet' => [
            'type'     => 'string',
            'max_len'  => 35,
            'required' => false
        ],
        'senderAdditionalAddress' => [
            'type'     => 'string',
            'max_len'  => 255,
            'required' => false
        ],
        'senderIp' => [
            'type'     => 'string',
            'max_len'  => 50,
            'required' => false
        ],
        'senderPhone' => [
            'type'     => 'numeric_string',
            'max_len'  => 20,
            'required' => false
        ],
        'senderZipCode' => [
            'type'     => 'string',
            'max_len'  => 50,
            'required' => false
        ],
    ];

    private $errors = [];

    public function validateAndClear(array $data) {
        foreach (self::VALIDATION_RULES as $field => $config) {
            $value = isset($data[$field]) ? $data[$field] : null;

            if (!empty($config['required']) && (is_null($value) || $value === '')) {
                $this->errors[$field][] = "Поле $field є обов'язковим.";
                continue;
            }

            if (empty($value)) {
                unset($data[$field]);
                continue;
            }

            if ($config['type'] === 'numeric_string') {
                $value = preg_replace('/\D/', '', $value);
            }

            if (isset($config['max_len']) && mb_strlen((string)$value) > $config['max_len']) {
                $this->errors[$field][] = "Максимальна довжина $field — {$config['max_len']} символів.";
            }

            if (isset($config['pattern']) && !preg_match($config['pattern'], $value)) {
                $this->errors[$field][] = "Формат поля $field невірний.";
            }

            if (!empty($config['no_only_digits']) && ctype_digit((string)$value)) {
                $this->errors[$field][] = "Поле $field не може містити лише цифри.";
            }

            if (isset($config['stop_words'])) {
                foreach ($config['stop_words'] as $word) {
                    if (stripos($value, $word) !== false) {
                        $this->errors[$field][] = "Поле $field містить заборонене слово: $word.";
                    }
                }
            }

            if ($field === 'senderEmail' && !is_email($value)) {
                $this->errors[$field][] = "Невірний формат Email.";
            }
        }

        return $data;
    }

    public function get_errors() {
        return $this->errors;
    }
}
