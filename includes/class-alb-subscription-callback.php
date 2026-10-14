<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 *
 * Subscription Callback Handler.
 *
 * Responsibilities:
 *  - Register custom WC order status 'wc-alb-subscribed' (Active Subscription).
 *  - Route callbacks that belong to subscription orders.
 *  - Handle initial subscription success → set order to 'alb-subscribed'.
 *  - Handle recurring payment callbacks → add order notes, track scheduled payments.
 *  - Handle cancellation/failure → update subscription and WC order status.
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Subscription_Callback.
 */
class ALB_Subscription_Callback
{
    public static function init(): void
    {
        add_action('init', [__CLASS__, 'register_order_status']);
        add_filter('wc_order_statuses', [__CLASS__, 'add_order_status_to_list']);

        add_filter('woocommerce_order_is_paid_statuses', [__CLASS__, 'add_to_paid_statuses']);
        add_filter('woocommerce_valid_order_statuses_for_payment', [__CLASS__, 'add_to_payment_statuses']);

        add_filter('bulk_actions-woocommerce_page_wc-orders', [__CLASS__, 'add_bulk_action']);
        add_filter('bulk_actions-edit-shop_order', [__CLASS__, 'add_bulk_action']);
    }

    /**
     * Register 'alb-subscribed' status.
     */
    public static function register_order_status(): void
    {
        register_post_status('wc-alb-subscribed', [
            'label' => _x('Active Subscription', 'Order status', 'alliancepay'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count' => _n_noop(
                'Active Subscription <span class="count">(%s)</span>',
                'Active Subscriptions <span class="count">(%s)</span>',
                'alliancepay'
            ),
        ]);
    }

    /**
     * Add to WooCommerce order statuses list.
     *
     * @param array $statuses
     * @return array
     */
    public static function add_order_status_to_list(array $statuses): array
    {
        $statuses['wc-alb-subscribed'] = _x('Active Subscription', 'Order status', 'alliancepay');
        return $statuses;
    }

    /**
     * @param array $statuses
     * @return array
     */
    public static function add_to_paid_statuses(array $statuses): array
    {
        $statuses[] = 'alb-subscribed';
        return $statuses;
    }

    /**
     * @param array $statuses
     * @return array
     */
    public static function add_to_payment_statuses(array $statuses): array
    {
        $statuses[] = 'alb-subscribed';
        return $statuses;
    }

    /**
     * Add to admin bulk actions list.
     *
     * @param array $actions
     * @return array
     */
    public static function add_bulk_action(array $actions): array
    {
        return $actions;
    }

    /**
     * @param array $data
     * @param string $raw
     * @return bool
     */
    public static function handle(array $data, string $raw): bool
    {
        $hpp_order_id = $data['hppOrderId'] ?? '';
        if (!$hpp_order_id) return false;

        $subscription = self::get_subscription_by_hpp_order_id($hpp_order_id);
        if (!$subscription) return false;

        $wc_order = wc_get_order($subscription->wc_order_id);
        if (!$wc_order) return false;

        $recurring_type = $data['recurringTransactionType'] ?? null;
        $order_status = $data['orderStatus'] ?? '';
        $operation = $data['operation'] ?? [];

        if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
            $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
            if (!is_dir($dir)) wp_mkdir_p($dir);
            @file_put_contents(
                $dir . '/subscription-callback.log',
                date('c') . " SUB CALLBACK hppOrderId={$hpp_order_id} type={$recurring_type} status={$order_status}\n" . $raw . "\n\n",
                FILE_APPEND
            );
        }

        if ($recurring_type === AllianceConfig::RECURRING_TYPE_INITIAL || $recurring_type === null) {
            self::handle_initial_callback($subscription, $wc_order, $data, $raw);
        } else {
            self::handle_recurring_callback($subscription, $wc_order, $data, $raw);
        }

        return true;
    }

    /**
     * @param object $subscription
     * @param $wc_order
     * @param array $data
     * @param string $raw
     * @return void
     */
    private static function handle_initial_callback(object $subscription, $wc_order, array $data, string $raw): void
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscriptions();
        $order_status = $data['orderStatus'] ?? '';

        $update = [
            'callback_data' => self::merge_callback_data($subscription->callback_data, $data),
            'updated_at' => current_time('mysql'),
        ];

        $operation = $data['operation'] ?? [];
        $operation_status = is_array($operation) ? ($operation['status'] ?? '') : '';
        $initial_paid = $operation_status === AllianceConfig::ORDER_STATUS_SUCCESS
            || $order_status === AllianceConfig::ORDER_STATUS_SUCCESS;

        if ($initial_paid) {
            if ($wc_order->has_status(AllianceConfig::WC_STATUS_SUBSCRIBED)) {
                $wpdb->update($table, $update, ['id' => $subscription->id]);
                return;
            }

            $update['status'] = AllianceConfig::SUB_ORDER_STATUS_PENDING;

            $wc_order->update_status(
                AllianceConfig::WC_STATUS_SUBSCRIBED,
                __('Initial subscription payment successful via AlliancePay.', 'alliancepay')
            );

            self::store_scheduled_payments_from_callback((int) $subscription->id, $subscription->hpp_order_id, $data);

            ALB_Subscription_Plans::adjust_active_subscriptions_count($subscription->plan_id, 1);

        } elseif ($order_status === AllianceConfig::ORDER_STATUS_FAIL
            && !$wc_order->has_status(AllianceConfig::WC_STATUS_SUBSCRIBED)
        ) {
            $update['status'] = AllianceConfig::SUB_ORDER_STATUS_FAILED;
            $wc_order->update_status(
                'failed',
                __('Initial subscription payment failed via AlliancePay.', 'alliancepay')
            );
        }

        if ($operation_status === AllianceConfig::ORDER_STATUS_FAIL
            && $order_status !== AllianceConfig::ORDER_STATUS_FAIL
            && is_array($operation)
            && !self::operation_already_stored($subscription->callback_data, $operation)
        ) {
            $info = $operation['transactionResponseInfo'] ?? [];
            $wc_order->add_order_note(sprintf(
                __('AlliancePay initial subscription operation FAILED. operationId: %1$s, code: %2$s/%3$s %4$s', 'alliancepay'),
                $operation['operationId'] ?? '—',
                $info['actionCode'] ?? '—',
                $info['responseCode'] ?? '—',
                $info['description'] ?? ''
            ));
            self::log_failed_operation((string) $subscription->hpp_order_id, $data);
        }

        $wpdb->update($table, $update, ['id' => $subscription->id]);
    }

    /**
     * @param object $subscription
     * @param $wc_order
     * @param array $data
     * @param string $raw
     * @return void
     */
    private static function handle_recurring_callback(object $subscription, $wc_order, array $data, string $raw): void
    {
        global $wpdb;
        $sub_table = AllianceConfig::table_alliance_subscriptions();
        $scheduled_table = AllianceConfig::table_alliance_scheduled_payments();

        $order_status = $data['orderStatus'] ?? '';
        $operation = $data['operation'] ?? [];
        $scheduled_id = $data['scheduledPaymentId'] ?? ($operation['scheduledPaymentId'] ?? null);
        $operation_id = $operation['operationId'] ?? null;
        $ecom_operation_id = $data['ecomOperationId'] ?? null;

        $operation_status = $operation['status'] ?? null;
        $result_status = in_array($operation_status, [
            AllianceConfig::ORDER_STATUS_SUCCESS,
            AllianceConfig::ORDER_STATUS_FAIL,
        ], true) ? $operation_status : $order_status;

        $callback_merged = self::merge_callback_data($subscription->callback_data, $data);

        $status_map = [
            AllianceConfig::ORDER_STATUS_SUCCESS => AllianceConfig::SCHEDULED_STATUS_SUCCESS,
            AllianceConfig::ORDER_STATUS_FAIL => AllianceConfig::SCHEDULED_STATUS_FAIL,
            AllianceConfig::SCHEDULED_STATUS_SKIPPED_PAUSED => AllianceConfig::SCHEDULED_STATUS_SKIPPED_PAUSED,
            AllianceConfig::SCHEDULED_STATUS_INVALIDATED => AllianceConfig::SCHEDULED_STATUS_INVALIDATED,
            AllianceConfig::SCHEDULED_STATUS_RETRY => AllianceConfig::SCHEDULED_STATUS_RETRY,
            'READY_FOR_RETRY' => AllianceConfig::SCHEDULED_STATUS_RETRY,
        ];
        $mapped_status = $status_map[$result_status] ?? null;

        $scheduled = null;

        if ($scheduled_id) {
            $scheduled = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$scheduled_table} WHERE scheduled_payment_id = %s",
                    $scheduled_id
                )
            );

            if ($scheduled && $operation_id !== null
                && $scheduled->status === AllianceConfig::SCHEDULED_STATUS_SUCCESS
                && $scheduled->operation_id === $operation_id
            ) {
                $wpdb->update($sub_table, [
                    'callback_data' => $callback_merged,
                    'updated_at' => current_time('mysql'),
                ], ['id' => $subscription->id]);
                return;
            }

            $scheduled_update = [
                'operation_id' => $operation_id,
                'ecom_operation_id' => $ecom_operation_id,
                'updated_at' => current_time('mysql'),
            ];
            if ($mapped_status !== null) {
                $scheduled_update['status'] = $mapped_status;
                if ($mapped_status === AllianceConfig::SCHEDULED_STATUS_SUCCESS) {
                    $scheduled_update['executed_at'] = current_time('mysql');
                }
            }

            if ($scheduled) {
                $wpdb->update($scheduled_table, $scheduled_update, ['id' => $scheduled->id]);
            } else {
                $wpdb->insert($scheduled_table, array_merge($scheduled_update, [
                    'subscription_id' => $subscription->id,
                    'hpp_order_id' => $subscription->hpp_order_id,
                    'scheduled_payment_id' => $scheduled_id,
                    'status' => $scheduled_update['status'] ?? AllianceConfig::SCHEDULED_STATUS_WAITING,
                    'created_at' => current_time('mysql'),
                ]));
            }

            $scheduled = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$scheduled_table} WHERE scheduled_payment_id = %s",
                    $scheduled_id
                )
            );
        }

        if ($result_status === AllianceConfig::ORDER_STATUS_SUCCESS) {
            $note = sprintf(
                __('AlliancePay recurring payment SUCCESS. scheduledPaymentId: %s, operationId: %s', 'alliancepay'),
                $scheduled_id ?: '—',
                $operation_id ?: '—'
            );
            $wc_order->add_order_note($note);

            self::verify_and_flag_amount($subscription, $scheduled, $data, $operation, $wc_order);
        } elseif ($result_status === AllianceConfig::ORDER_STATUS_FAIL) {
            $note = sprintf(
                __('AlliancePay recurring payment FAILED. scheduledPaymentId: %s', 'alliancepay'),
                $scheduled_id ?: '—'
            );
            $wc_order->add_order_note($note);
            self::log_failed_operation((string) $subscription->hpp_order_id, $data);
        } elseif (in_array($order_status, [
            AllianceConfig::SCHEDULED_STATUS_SKIPPED_PAUSED,
            AllianceConfig::SCHEDULED_STATUS_INVALIDATED,
        ], true)) {
            $wc_order->add_order_note(sprintf(
                __('AlliancePay: scheduled payment %1$s marked %2$s by the bank.', 'alliancepay'),
                $scheduled_id ?: '—',
                $order_status
            ));
        }

        $wpdb->update($sub_table, [
            'callback_data' => $callback_merged,
            'updated_at' => current_time('mysql'),
        ], ['id' => $subscription->id]);

        if (($data['hppPayType'] ?? '') === 'SUBSCRIPTION' && $order_status === 'CANCELED') {
            $wpdb->update($sub_table, [
                'status' => AllianceConfig::SUB_ORDER_STATUS_CANCELED,
                'updated_at' => current_time('mysql'),
            ], ['id' => $subscription->id]);

            ALB_Subscription_Plans::adjust_active_subscriptions_count($subscription->plan_id, -1);

            $wc_order->update_status(
                'cancelled',
                __('Subscription cancelled via AlliancePay callback.', 'alliancepay')
            );
        }
    }

    /**
     * @param object $subscription
     * @param object|null $scheduled
     * @param array $data
     * @param array $operation
     * @param $wc_order
     * @return void
     */
    private static function verify_and_flag_amount(
        object  $subscription,
        ?object $scheduled,
        array   $data,
        array   $operation,
                $wc_order
    ): void
    {
        if (empty($subscription->rules_snapshot)) {
            return;
        }

        $rules = json_decode($subscription->rules_snapshot, true);
        if (!is_array($rules) || empty($rules)) {
            return;
        }

        $charged = $data['coinAmount'] ?? $operation['coinAmount'] ?? $operation['amount'] ?? null;
        if ($charged === null) {
            return;
        }

        $payment_number = self::resolve_payment_number((int) $subscription->id, $scheduled);
        if ($payment_number === null) {
            return;
        }

        $expected = null;
        foreach ($rules as $rule) {
            $from = (int)($rule['paymentNumberFrom'] ?? 0);
            $to = (int)($rule['paymentNumberTo'] ?? 0);
            if ($payment_number >= $from && $payment_number <= $to) {
                $expected = (int)($rule['coinAmount'] ?? 0);
                break;
            }
        }

        if ($expected === null || (int)$charged === $expected) {
            return;
        }

        $wc_order->add_order_note(sprintf(
            __(
                'AlliancePay: charged amount %1$d does not match the expected amount %2$d for'
                . 'payment #%3$d (per plan snapshot). Needs manual review.', 'alliancepay'
            ),
            (int)$charged,
            $expected,
            $payment_number
        ));
    }

    /**
     * @param int $subscription_id
     * @param object|null $scheduled
     * @return int|null
     */
    private static function resolve_payment_number(int $subscription_id, ?object $scheduled): ?int
    {
        if (!$scheduled || empty($scheduled->scheduled_date)) {
            return null;
        }

        global $wpdb;
        $table = AllianceConfig::table_alliance_scheduled_payments();
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE subscription_id = %d AND scheduled_date <= %s",
            $subscription_id,
            $scheduled->scheduled_date
        ));

        return $count !== null ? (int)$count : null;
    }

    /**
     * @param int $subscription_id
     * @param string $hpp_order_id
     * @param array $data
     * @return void
     */
    private static function store_scheduled_payments_from_callback(int $subscription_id, string $hpp_order_id, array $data): void
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_scheduled_payments();

        $scheduled_payments = $data['scheduledPayments'] ?? [];
        if (empty($scheduled_payments) || !is_array($scheduled_payments)) return;

        foreach ($scheduled_payments as $sp) {
            $sp_id = $sp['scheduledPaymentId'] ?? ($sp['id'] ?? null);
            if (!$sp_id) continue;

            $existing = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE scheduled_payment_id = %s", $sp_id)
            );
            if ($existing) continue;

            $scheduled_date = null;
            if (!empty($sp['scheduledDate'])) {
                $scheduled_date = date('Y-m-d H:i:s', strtotime($sp['scheduledDate']));
            }

            $wpdb->insert($table, [
                'subscription_id' => $subscription_id,
                'hpp_order_id' => $hpp_order_id,
                'scheduled_payment_id' => $sp_id,
                'status' => $sp['status'] ?? AllianceConfig::SCHEDULED_STATUS_WAITING,
                'scheduled_date' => $scheduled_date,
                'created_at' => current_time('mysql'),
            ]);
        }
    }

    /**
     * Get subscription record by hppOrderId.
     *
     * @param string $hpp_order_id
     * @return object|null
     */
    public static function get_subscription_by_hpp_order_id(string $hpp_order_id): ?object
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscriptions();
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE hpp_order_id = %s", $hpp_order_id)
        ) ?: null;
    }

    /**
     * @param string|null $existing_json
     * @param array $incoming
     * @return string
     */
    private static function merge_callback_data(?string $existing_json, array $incoming): string
    {
        $stored = !empty($existing_json) ? (json_decode($existing_json, true) ?? []) : [];
        foreach ($incoming as $key => $value) {
            if ($key !== 'operation') {
                $stored[$key] = $value;
            }
        }
        
        $incoming_op = $incoming['operation'] ?? null;
        if (is_array($incoming_op) && !empty($incoming_op['operationId'])) {
            if (!isset($stored['operations']) || !is_array($stored['operations'])) {
                $stored['operations'] = [];
            }
            $replaced = false;
            foreach ($stored['operations'] as $i => $stored_op) {
                if (($stored_op['operationId'] ?? '') === $incoming_op['operationId']) {
                    $stored['operations'][$i] = $incoming_op;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $stored['operations'][] = $incoming_op;
            }
        }

        return wp_json_encode($stored, JSON_UNESCAPED_UNICODE);
    }

    /**
     * True when the operation with this id is already stored with the same status.
     *
     * @param string|null $existing_json
     * @param array $operation
     * @return bool
     */
    private static function operation_already_stored(?string $existing_json, array $operation): bool
    {
        $id = $operation['operationId'] ?? null;
        if (!$id || empty($existing_json)) return false;
        $stored = json_decode($existing_json, true);
        foreach (($stored['operations'] ?? []) as $op) {
            if (($op['operationId'] ?? '') === $id && ($op['status'] ?? '') === ($operation['status'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Always-on log of failed operations (independent of ALB_HPP_DEBUG).
     *
     * @param string $hpp_order_id
     * @param array $data
     * @return void
     */
    private static function log_failed_operation(string $hpp_order_id, array $data): void
    {
        $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $op = $data['operation'] ?? [];
        @file_put_contents(
            $dir . '/subscription-callback.log',
            sprintf(
                "%s SUB OPERATION FAIL hppOrderId=%s orderStatus=%s operationId=%s actionCode=%s responseCode=%s description=%s\n",
                date('c'),
                $hpp_order_id,
                $data['orderStatus'] ?? '',
                $op['operationId'] ?? '',
                $op['transactionResponseInfo']['actionCode'] ?? '',
                $op['transactionResponseInfo']['responseCode'] ?? '',
                $op['transactionResponseInfo']['description'] ?? ''
            ),
            FILE_APPEND
        );
    }
}
