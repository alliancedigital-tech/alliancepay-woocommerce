<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 *
 * Scenario 3 — mass price change for an existing plan's active subscribers.
 *
 * Guarded, per the audit's target model:
 *  - manage_woocommerce required (mass repricing is not a routine action).
 *  - Lead time: appliesDateTimeFrom must be far enough in the future.
 *  - Admin must explicitly confirm affected customers were notified.
 *  - Full old/new rules snapshot is recorded for audit history.
 *
 * Reuses ALB_Subscription_Plans::api_update_plan() / api_update_plan_version(),
 * which already wrap the correct bank endpoints (plan/update_v1 and
 * plan/version/update_v1) and — as of this Wave 2 fix — always make the new
 * version's schedule infinite.
 */

declare(strict_types=1);

namespace ALB;

use Exception;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Subscription_Reprice.
 */
class ALB_Subscription_Reprice
{
    /** Minimum notice period before a repriced version can take effect. */
    private const MIN_LEAD_TIME_DAYS = 30;

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    public static function register_rest_routes(): void
    {
        register_rest_route('alb/v1', '/plan/(?P<plan_id>[\w-]+)/subscribers-count', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'rest_active_subscribers_count'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
        ]);

        register_rest_route('alb/v1', '/mass-reprice-plan', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'rest_mass_reprice_plan'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
        ]);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function rest_active_subscribers_count(WP_REST_Request $request): WP_REST_Response
    {
        $plan_id = sanitize_text_field((string)$request->get_param('plan_id'));

        return rest_ensure_response([
            'ok' => true,
            'count' => self::count_active_subscriptions($plan_id),
        ]);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_Error|WP_HTTP_Response|WP_REST_Response
     * @throws Exception
     */
    public static function rest_mass_reprice_plan(WP_REST_Request $request)
    {
        $plan_id = sanitize_text_field((string)($request->get_param('plan_id') ?? ''));
        $rules_raw = $request->get_param('rules');
        $applies_from_raw = sanitize_text_field((string)($request->get_param('applies_date_time_from') ?? ''));
        $customers_notified = (bool)$request->get_param('customers_notified');
        $reason = sanitize_textarea_field((string)($request->get_param('reason') ?? ''));

        if (!$plan_id) {
            return new WP_Error('missing_plan', __('plan_id is required.', 'alliancepay'), ['status' => 400]);
        }

        $plan = ALB_Subscription_Plans::get_plan_by_id($plan_id);
        if (!$plan) {
            return new WP_Error('plan_not_found', __('Plan not found.', 'alliancepay'), ['status' => 404]);
        }

        $rules = ALB_Subscription_Plans::sanitize_rules_from_array(is_array($rules_raw) ? $rules_raw : []);
        if (empty($rules)) {
            return new WP_Error('missing_rules', __('The full new rules array is required.', 'alliancepay'), ['status' => 400]);
        }

        if (!$applies_from_raw) {
            return new WP_Error(
                'missing_lead_time',
                __('appliesDateTimeFrom is required for a mass price change.', 'alliancepay'),
                ['status' => 400]
            );
        }

        try {
            $applies_from = new \DateTime($applies_from_raw, wp_timezone());
        } catch (\Throwable $e) {
            return new WP_Error('bad_date', __('Invalid appliesDateTimeFrom.', 'alliancepay'), ['status' => 400]);
        }

        $min_allowed = (new \DateTime('now', wp_timezone()))->modify('+' . self::MIN_LEAD_TIME_DAYS . ' days');
        if ($applies_from < $min_allowed) {
            return new WP_Error(
                'lead_time_too_short',
                sprintf(
                    __(
                        'appliesDateTimeFrom must be at least %d days from now, '
                        . 'to give subscribers fair notice.',
                        'alliancepay'
                    ),
                    self::MIN_LEAD_TIME_DAYS
                ),
                ['status' => 422]
            );
        }

        if (!$customers_notified) {
            return new WP_Error(
                'customers_not_notified',
                __(
                    'You must confirm that affected customers have been (or will be) '
                    . 'notified before this change takes effect.',
                    'alliancepay'
                ),
                ['status' => 422]
            );
        }

        $affected_count = self::count_active_subscriptions($plan_id);
        $old_version_id = (int)$plan->version_id;
        $old_rules = $plan->rules;

        $body = [
            'planId' => $plan_id,
            'rules' => $rules,
            'appliesDateTimeFrom' => $applies_from->format('Y-m-d H:i:s.00P'),
        ];

        try {
            $res = ALB_Subscription_Plans::api_update_plan($body);
        } catch (\Throwable $e) {
            return new WP_Error('bank_error', $e->getMessage(), ['status' => 502]);
        }

        $new_version_id = (int)($res['versionId'] ?? 0);

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plan_version();
        $wpdb->insert($table, [
            'plan_id' => $plan_id,
            'old_version_id' => $old_version_id ?: null,
            'new_version_id' => $new_version_id ?: null,
            'old_rules' => $old_rules,
            'new_rules' => wp_json_encode($rules, JSON_UNESCAPED_UNICODE),
            'applies_from' => $applies_from->format('Y-m-d H:i:s'),
            'reason' => $reason ?: null,
            'created_by' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ]);

        ALB_Subscription_Plans::log_audit('plan_repriced', [
            'plan_id' => $plan_id,
            'product_id' => $plan->product_id ?? null,
            'old_version_id' => $old_version_id,
            'new_version_id' => $new_version_id,
            'affected_subscriptions' => $affected_count,
            'applies_from' => $applies_from->format('Y-m-d H:i:s'),
        ]);

        return rest_ensure_response([
            'ok' => true,
            'plan_id' => $plan_id,
            'new_version_id' => $new_version_id,
            'affected_subscriptions' => $affected_count,
            'rules_summary' => ALB_Subscription_Plans::format_rules_summary($rules),
        ]);
    }

    /**
     * @param string $plan_id
     * @return int
     */
    private static function count_active_subscriptions(string $plan_id): int
    {
        if (!$plan_id) return 0;

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscriptions();

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE plan_id = %s AND status NOT IN (%s, %s)",
            $plan_id,
            AllianceConfig::SUB_ORDER_STATUS_CANCELED,
            AllianceConfig::SUB_ORDER_STATUS_FAILED
        ));
    }
}
