<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 *
 * Read-only technical screen for support: every plan (any product, any
 * status), plus the admin-action audit log.
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Subscription_Plans_List.
 */
class ALB_Subscription_Plans_List
{
    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
    }

    public static function add_admin_menu(): void
    {
        add_submenu_page(
            'alb-hpp',
            __('Subscription Plans', 'alliancepay'),
            __('Subscription Plans', 'alliancepay'),
            'manage_woocommerce',
            'alb-hpp-plans',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to view this page.', 'alliancepay'));
        }

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        $plans = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 500");

        echo '<div class="wrap"><h1>' . esc_html__('AlliancePay — Subscription Plans', 'alliancepay') . '</h1>';
        echo '<p class="description">' . esc_html__('Read-only technical view of every plan the CMS knows about, for support.', 'alliancepay') . '</p>';

        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr>';
        foreach ([
            'Plan ID', 'Product', 'Name', 'Category', 'Rules', 'Bank status',
            'Active in CMS', 'Active subs', 'Sync', 'Created',
        ] as $col) {
            echo '<th>' . esc_html__($col, 'alliancepay') . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($plans as $plan) {
            $product_link = '—';
            if ($plan->product_id) {
                $edit_link = get_edit_post_link((int) $plan->product_id);
                $title     = get_the_title((int) $plan->product_id);
                if ($edit_link) {
                    $product_link = '<a href="' . esc_url($edit_link) . '">' . esc_html($title ?: ('#' . $plan->product_id)) . '</a>';
                }
            }

            $sync_label = esc_html($plan->sync_status);
            if ($plan->sync_status === 'error' && $plan->sync_error) {
                $sync_label = '<span title="' . esc_attr($plan->sync_error) . '" style="color:#dc2626;cursor:help;">'
                    . esc_html($plan->sync_status) . ' ⚠</span>';
            }

            echo '<tr>';
            echo '<td style="font-size:11px;word-break:break-all;max-width:160px;">' . esc_html($plan->plan_id) . '</td>';
            echo '<td>' . $product_link . '</td>';
            echo '<td>' . esc_html($plan->name) . '</td>';
            echo '<td>' . esc_html($plan->category_subcategory_indicator) . '</td>';
            echo '<td style="font-size:12px;">' . esc_html(ALB_Subscription_Plans::format_rules_summary($plan->rules)) . '</td>';
            echo '<td>' . esc_html($plan->status) . '</td>';
            echo '<td>' . ($plan->is_active ? esc_html__('Yes', 'alliancepay') : esc_html__('No', 'alliancepay')) . '</td>';
            echo '<td>' . esc_html((string) $plan->active_subscriptions_count) . '</td>';
            echo '<td>' . $sync_label . '</td>';
            echo '<td>' . esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($plan->created_at))) . '</td>';
            echo '</tr>';
        }

        if (empty($plans)) {
            echo '<tr><td colspan="10">' . esc_html__('No plans yet.', 'alliancepay') . '</td></tr>';
        }
        echo '</tbody></table>';

        self::render_audit_log();

        echo '</div>';
    }

    private static function render_audit_log(): void
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_audit_log();
        $logs  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100");

        echo '<h2 style="margin-top:32px;">' . esc_html__('Audit Log (last 100 admin actions)', 'alliancepay') . '</h2>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr>';
        foreach (['When', 'Action', 'Plan ID', 'Product', 'By', 'Details'] as $col) {
            echo '<th>' . esc_html__($col, 'alliancepay') . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($logs as $log) {
            $user = $log->user_id ? get_userdata((int) $log->user_id) : null;
            echo '<tr>';
            echo '<td>' . esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($log->created_at))) . '</td>';
            echo '<td>' . esc_html($log->action) . '</td>';
            echo '<td style="font-size:11px;word-break:break-all;max-width:160px;">' . esc_html((string) $log->plan_id) . '</td>';
            echo '<td>' . ($log->product_id ? esc_html(get_the_title((int) $log->product_id) ?: ('#' . $log->product_id)) : '—') . '</td>';
            echo '<td>' . esc_html($user ? $user->display_name : '—') . '</td>';
            echo '<td style="font-size:11px;">' . esc_html((string) $log->details) . '</td>';
            echo '</tr>';
        }

        if (empty($logs)) {
            echo '<tr><td colspan="6">' . esc_html__('No audit log entries yet.', 'alliancepay') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
