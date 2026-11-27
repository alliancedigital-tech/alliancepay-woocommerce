<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Fiscalization_Repository. Єдине місце з SQL для таблиці фіскалізації.
 */
class ALB_Fiscalization_Repository
{
    private const DATETIME_COLUMNS = ['fiscalization_date_time', 'fiscalization_modification_date_time'];

    /**
     * @param string $operationId
     * @return object|null
     */
    public function find_by_operation_id(string $operationId): ?object
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_fiscalization();

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE operation_id = %s", $operationId));

        return $row ?: null;
    }

    /**
     * @param string $merchantRequestId
     * @return object|null
     */
    public function find_by_merchant_request_id(string $merchantRequestId): ?object
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_fiscalization();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE merchant_request_id = %s", $merchantRequestId)
        );

        return $row ?: null;
    }

    /**
     * @param int $orderId
     * @return object[]
     */
    public function find_by_order_id(int $orderId): array
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_fiscalization();

        return (array)$wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $orderId)
        );
    }

    /**
     * Upsert за operation_id (без дублікатів).
     *
     * @param string $operationId
     * @param array $data Колонки таблиці (без id/created_at).
     * @return bool
     */
    public function upsert(string $operationId, array $data): bool
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_fiscalization();
        $now = current_time('mysql', true);

        foreach (self::DATETIME_COLUMNS as $col) {
            if (array_key_exists($col, $data)) {
                $data[$col] = self::to_mysql_datetime($data[$col]);
            }
        }

        $existing = $this->find_by_operation_id($operationId);
        if ($existing) {
            $data['updated_at'] = $now;

            return $wpdb->update($table, $data, ['id' => (int)$existing->id]) !== false;
        }

        $data['operation_id'] = $operationId;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $wpdb->insert($table, $data) !== false;
    }

    /**
     * ISO 8601 з зоною → UTC "Y-m-d H:i:s" (дати в таблиці зберігаються в UTC).
     *
     * @param mixed $value
     * @return string|null
     */
    public static function to_mysql_datetime($value): ?string
    {
        if (empty($value) || !is_string($value)) {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($value);
        } catch (\Throwable $e) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
