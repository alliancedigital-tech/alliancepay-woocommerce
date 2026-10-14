<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 *
 * Subscription Plans — CRUD (local DB + bank API) and product meta hooks.
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Subscription_Plans.
 */
class ALB_Subscription_Plans
{
    public static function init(): void
    {
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'render_product_fields']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_product_meta']);
        add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'render_subscription_selector']);
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 10, 3);
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_after_shop_loop_item_title', [__CLASS__, 'render_archive_badge'], 15);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('alb_check_archivable_plans', [__CLASS__, 'check_archivable_plans']);
        if (!wp_next_scheduled('alb_check_archivable_plans')) {
            wp_schedule_event(time(), 'daily', 'alb_check_archivable_plans');
        }
    }

    /**
     * @return void
     */
    public static function check_archivable_plans(): void
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        $plans = $wpdb->get_results($wpdb->prepare(
                "SELECT plan_id, name FROM {$table} WHERE is_active = 0 AND active_subscriptions_count = 0 AND status = %s",
                AllianceConfig::PLAN_STATUS_ACTIVE
        ));

        if (empty($plans)) return;

        $labels = array_map(fn($p) => $p->name . ' (' . $p->plan_id . ')', $plans);

        set_transient('alb_hpp_notice', [
                'type' => 'warning',
                'text' => sprintf(
                        __('AlliancePay: %1$d deactivated plan(s) have no active subscriptions left and are safe to archive at the bank (plan/status_update_v1 → CANCELED — a manual action, not automatic): %2$s', 'alliancepay'),
                        count($plans),
                        implode(', ', $labels)
                ),
        ], DAY_IN_SECONDS);
    }

    /**
     * @return void
     */
    public static function render_product_fields(): void
    {
        global $post;

        $product = $post ? wc_get_product($post->ID) : null;
        $enabled = get_post_meta($post->ID, '_alb_subscription_enabled', true);
        $plan_ids = get_post_meta($post->ID, '_alb_subscription_plan_ids', true);
        $plan_ids = $plan_ids ? json_decode($plan_ids, true) : [];

        $price_coins = $product ? (int)round((float)$product->get_price() * 100) : 0;
        $product_name = $product ? esc_attr($product->get_name()) : '';

        $active_plans = self::get_active_plans();

        echo '<div class="options_group alb-subscription-product-options">';
        echo '<hr style="margin:10px 0;border-top:1px solid #e5e7eb;">';
        echo '<p style="padding:5px 12px;font-weight:600;font-size:13px;color:#2ea2cc;">'
                . esc_html__('AlliancePay Subscription', 'alliancepay') . '</p>';

        woocommerce_wp_checkbox([
                'id' => '_alb_subscription_enabled',
                'label' => __('Enable subscription purchase', 'alliancepay'),
                'description' => __('Allow customers to purchase this product via a recurring subscription.', 'alliancepay'),
                'value' => $enabled ? 'yes' : 'no',
        ]);

        echo '<p class="form-field _alb_subscription_plan_ids_field">';
        echo '<label for="_alb_subscription_plan_ids">'
                . esc_html__('Subscription Plans', 'alliancepay') . '</label>';
        echo '<select id="_alb_subscription_plan_ids" name="_alb_subscription_plan_ids[]" multiple style="width:100%;height:120px;">';
        foreach ($active_plans as $plan) {
            $selected = in_array($plan->plan_id, $plan_ids, true) ? 'selected' : '';
            printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($plan->plan_id),
                    $selected,
                    esc_html($plan->name . ' — ' . self::format_rules_summary($plan->rules))
            );
        }
        echo '</select>';
        echo '<span class="description">'
                . esc_html__('Hold Ctrl/Cmd to select multiple. Newly created plans are added automatically.', 'alliancepay')
                . '</span>';
        echo '</p>';

        $attached_plans = self::get_plans_by_ids($plan_ids);
        if ($attached_plans) {
            echo '<ul class="alb-attached-plans" style="margin:4px 0 8px;padding-left:16px;">';
            foreach ($attached_plans as $ap) {
                $plan_id_attr = esc_attr($ap->plan_id);
                $rules_attr = esc_attr($ap->rules);

                echo '<li style="font-size:12px;color:#555;">' . esc_html($ap->name) . ' — ';
                printf(
                        '<a href="#" class="alb-replace-plan" data-plan-id="%s" data-plan-name="%s" data-plan-category="%s" data-plan-rules="%s">%s</a>',
                        $plan_id_attr,
                        esc_attr($ap->name),
                        esc_attr($ap->category_subcategory_indicator),
                        $rules_attr,
                        esc_html__('Replace with new price for new customers →', 'alliancepay')
                );
                echo ' &nbsp;|&nbsp; ';
                printf(
                        '<a href="#" class="alb-reprice-plan" data-plan-id="%s" data-plan-rules="%s">%s</a>',
                        $plan_id_attr,
                        $rules_attr,
                        esc_html__('Reprice for all current subscribers →', 'alliancepay')
                );
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '<p style="padding:0 12px 8px;">';
        echo '<button type="button" id="alb-open-create-plan" class="button">'
                . esc_html__('+ Create New Plan', 'alliancepay') . '</button>';
        echo '</p>';

        $category_options = [
                'C101' => 'C101 — Subscription (Consumer)',
                'C102' => 'C102 — Instalment (Consumer)',
                'C103' => 'C103 — Unscheduled (Consumer)',
                'M101' => 'M101 — Subscription (Merchant)',
                'M102' => 'M102 — Instalment (Merchant)',
                'M103' => 'M103 — Unscheduled (Merchant)',
        ];
        ?>
        <div id="alb-create-plan-wrap"
             style="display:none;margin:0 12px 16px;padding:16px;border:1px solid #c3c4c7;border-radius:4px;background:#fafafa;">
            <h3 style="margin-top:0;"
                id="alb-cp-heading"><?php esc_html_e('Create New Subscription Plan', 'alliancepay'); ?></h3>
            <p id="alb-cp-replace-notice" style="display:none;color:#9a6700;font-size:12px;">
                <?php esc_html_e('This will create a new plan for new customers. The original plan (and its current subscribers) stays untouched.', 'alliancepay'); ?>
            </p>

            <input type="hidden" id="alb-cp-product-id" value="<?php echo esc_attr((string)$post->ID); ?>">
            <input type="hidden" id="alb-cp-old-plan-id" value="">
            <input type="hidden" id="alb-cp-reprice-plan-id" value="">

            <p class="form-field">
                <label for="alb-cp-template"><?php esc_html_e('Start from Template', 'alliancepay'); ?></label>
                <select id="alb-cp-template" style="width:100%;max-width:400px;">
                    <option value=""><?php esc_html_e('— Custom (no template) —', 'alliancepay'); ?></option>
                    <?php foreach (ALB_Subscription_Templates::get_active_templates() as $tpl): ?>
                        <option value="<?php echo esc_attr((string)$tpl->id); ?>">
                            <?php echo esc_html($tpl->code . ' — ' . $tpl->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description">
                    <?php esc_html_e('Picking a template fills in the rule structure — you only need to enter the price for each paid rule.', 'alliancepay'); ?>
                </span>
            </p>

            <p id="alb-cp-notify-row"
               style="display:none;background:#fff8e5;border:1px solid #f0d999;padding:8px 10px;border-radius:3px;">
                <label>
                    <input type="checkbox" id="alb-cp-notified">
                    <?php esc_html_e('I confirm affected customers have been (or will be) notified of this price change before it takes effect.', 'alliancepay'); ?>
                </label>
                <br>
                <span id="alb-cp-affected-count" style="font-size:12px;color:#555;"></span>
            </p>

            <table class="form-table" style="margin-bottom:0;">
                <tr>
                    <th style="width:180px;"><label
                                for="alb-cp-name"><?php esc_html_e('Plan Name *', 'alliancepay'); ?></label></th>
                    <td><input type="text" id="alb-cp-name" class="regular-text" value="<?php echo $product_name; ?>"
                               maxlength="150" required></td>
                </tr>
                <tr>
                    <th><label for="alb-cp-description"><?php esc_html_e('Description', 'alliancepay'); ?></label></th>
                    <td><textarea id="alb-cp-description" class="regular-text" maxlength="255" rows="2"></textarea></td>
                </tr>
                <tr>
                    <th><label for="alb-cp-category"><?php esc_html_e('Category *', 'alliancepay'); ?></label></th>
                    <td>
                        <select id="alb-cp-category">
                            <?php foreach ($category_options as $val => $label) : ?>
                                <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Applies From', 'alliancepay'); ?></th>
                    <td>
                        <input type="datetime-local" id="alb-cp-from">
                        <p class="description"><?php esc_html_e('Leave empty to apply immediately.', 'alliancepay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Applies To', 'alliancepay'); ?></th>
                    <td>
                        <input type="datetime-local" id="alb-cp-to">
                        <p class="description"><?php esc_html_e('Leave empty for indefinite.', 'alliancepay'); ?></p>
                    </td>
                </tr>
            </table>

            <h4 style="margin:12px 0 6px;"><?php esc_html_e('Payment Rules', 'alliancepay'); ?></h4>
            <p class="description" style="margin-bottom:8px;">
                <?php esc_html_e('Amount (coins) = price in kopecks (e.g. 10000 = 100.00 UAH). Add multiple rules for tiered pricing.', 'alliancepay'); ?>
            </p>
            <table class="wp-list-table widefat alb-rules-table" id="alb-cp-rules-table" style="max-width:860px;">
                <thead>
                <tr>
                    <th><?php esc_html_e('Pay # From', 'alliancepay'); ?></th>
                    <th><?php esc_html_e('Pay # To', 'alliancepay'); ?></th>
                    <th><?php esc_html_e('Interval', 'alliancepay'); ?></th>
                    <th><?php esc_html_e('Unit', 'alliancepay'); ?></th>
                    <th><?php esc_html_e('Amount (coins)', 'alliancepay'); ?></th>
                    <th><?php esc_html_e('Currency', 'alliancepay'); ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="alb-cp-rules-body">
                <tr class="alb-cp-rule-row">
                    <td><input type="number" class="alb-cp-f small-text" value="1" min="1"></td>
                    <td><input type="number" class="alb-cp-t small-text" value="999" min="1"></td>
                    <td><input type="number" class="alb-cp-iv small-text" value="1" min="1"></td>
                    <td>
                        <select class="alb-cp-iu">
                            <option value="DAY"><?php esc_html_e('DAY', 'alliancepay'); ?></option>
                            <option value="WEEK"><?php esc_html_e('WEEK', 'alliancepay'); ?></option>
                            <option value="MONTH" selected><?php esc_html_e('MONTH', 'alliancepay'); ?></option>
                        </select>
                    </td>
                    <td><input type="number" class="alb-cp-ca small-text"
                               value="<?php echo esc_attr((string)$price_coins); ?>" min="0"></td>
                    <td><input type="text" class="alb-cp-cu small-text" value="980" maxlength="3" readonly></td>
                    <td>
                        <button type="button" class="button alb-cp-remove-rule" disabled
                                title="<?php esc_attr_e('Remove', 'alliancepay'); ?>">&times;
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>

            <p style="margin-top:8px;">
                <button type="button" id="alb-cp-add-rule" class="button">
                    + <?php esc_html_e('Add Rule', 'alliancepay'); ?>
                </button>
            </p>

            <p id="alb-cp-preview" style="font-size:12px;color:#2271b1;font-style:italic;"></p>

            <div id="alb-cp-message" style="display:none;margin-top:8px;padding:8px 12px;border-radius:3px;"></div>

            <p style="margin-top:12px;">
                <button type="button" id="alb-cp-submit" class="button button-primary">
                    <?php esc_html_e('Create Plan', 'alliancepay'); ?>
                </button>
                &nbsp;
                <button type="button" id="alb-cp-cancel" class="button">
                    <?php esc_html_e('Cancel', 'alliancepay'); ?>
                </button>
            </p>
        </div>
        <?php

        echo '</div>';
    }

    /**
     * @param int $post_id
     * @return void
     */
    public static function save_product_meta(int $post_id): void
    {
        $enabled = isset($_POST['_alb_subscription_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_alb_subscription_enabled', $enabled);

        $plan_ids = isset($_POST['_alb_subscription_plan_ids']) && is_array($_POST['_alb_subscription_plan_ids'])
                ? array_map('sanitize_text_field', $_POST['_alb_subscription_plan_ids'])
                : [];

        update_post_meta($post_id, '_alb_subscription_plan_ids', wp_json_encode($plan_ids));
    }

    /**
     * @return void
     */
    public static function render_subscription_selector(): void
    {
        global $product;
        if (!$product) return;

        $enabled = get_post_meta($product->get_id(), '_alb_subscription_enabled', true);
        if ($enabled !== 'yes') return;

        $plan_ids_json = get_post_meta($product->get_id(), '_alb_subscription_plan_ids', true);
        $plan_ids = $plan_ids_json ? json_decode($plan_ids_json, true) : [];
        if (empty($plan_ids)) return;

        $plans = self::get_plans_by_ids($plan_ids);
        if (empty($plans)) return;

        if (!is_user_logged_in()) {
            printf(
                    '<p class="alb-subscription-login-notice" style="margin-bottom:16px;">%s <a href="%s">%s</a></p>',
                    esc_html__('Subscribing to this product requires an account.', 'alliancepay'),
                    esc_url(wp_login_url(get_permalink($product->get_id()))),
                    esc_html__('Log in or register to subscribe', 'alliancepay')
            );
            return;
        }

        wp_nonce_field('alb_subscription_nonce', 'alb_subscription_nonce');
        ?>
        <div class="alb-subscription-selector" style="margin-bottom:16px;">
            <p style="font-weight:600;margin-bottom:8px;">
                <?php esc_html_e('Purchase type:', 'alliancepay'); ?>
            </p>
            <label style="display:block;margin-bottom:6px;">
                <input type="radio" name="alb_purchase_type" value="onetime" checked>
                &nbsp;<?php esc_html_e('One-time purchase', 'alliancepay'); ?>
            </label>
            <label style="display:block;margin-bottom:6px;">
                <input type="radio" name="alb_purchase_type" value="subscribe">
                &nbsp;<?php esc_html_e('Subscribe', 'alliancepay'); ?>
            </label>

            <div class="alb-plan-select-wrapper" style="display:none;margin-top:10px;">
                <label for="alb_plan_id" style="display:block;margin-bottom:4px;font-weight:600;">
                    <?php esc_html_e('Select subscription plan:', 'alliancepay'); ?>
                </label>
                <select id="alb_plan_id" name="alb_plan_id" style="width:100%;max-width:400px;">
                    <?php foreach ($plans as $plan): ?>
                        <option value="<?php echo esc_attr($plan->plan_id); ?>">
                            <?php echo esc_html($plan->name . ' — ' . self::format_rules_summary($plan->rules)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <input type="hidden" name="alb_subscription_enabled" value="1">
        </div>
        <?php
    }

    /**
     * @return void
     */
    public static function render_archive_badge(): void
    {
        global $product;
        if (!$product) return;
        $enabled = get_post_meta($product->get_id(), '_alb_subscription_enabled', true);
        if ($enabled !== 'yes') return;

        echo '<span class="alb-subscription-badge" style="display:inline-block;background:#2ea2cc;color:#fff;'
                . 'font-size:11px;padding:2px 8px;border-radius:3px;margin-top:4px;">'
                . esc_html__('Subscription available', 'alliancepay')
                . '</span>';
    }

    /**
     * @param string $hook
     * @return void
     */
    public static function enqueue_admin_scripts(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') return;

        global $post;
        $product = $post ? wc_get_product($post->ID) : null;
        $price_coins = $product ? (int)round((float)$product->get_price() * 100) : 0;
        $product_name = $product ? $product->get_name() : '';

        wp_enqueue_style(
                'alb-product-plan-admin',
                plugins_url('../assets/css/subscription-admin.css', __FILE__),
                [],
                '1.1.0'
        );

        wp_enqueue_script(
                'alb-product-plan-admin',
                plugins_url('../assets/js/admin-product-plan.js', __FILE__),
                [],
                '1.1.0',
                true
        );

        $templates_for_js = array_map(function ($tpl) {
            return [
                    'id' => (int)$tpl->id,
                    'category' => $tpl->category_indicator,
                    'rules' => json_decode($tpl->rules_template, true) ?: [],
            ];
        }, ALB_Subscription_Templates::get_active_templates());

        wp_localize_script('alb-product-plan-admin', 'albPlanAdmin', [
                'restUrl' => rest_url('alb/v1/create-plan-for-product'),
                'replaceRestUrl' => rest_url('alb/v1/replace-plan-for-product'),
                'repriceRestUrl' => rest_url('alb/v1/mass-reprice-plan'),
                'subscribersCountUrlBase' => rest_url('alb/v1/plan/'),
                'templates' => $templates_for_js,
                'nonce' => wp_create_nonce('wp_rest'),
                'priceCoins' => $price_coins,
                'productName' => $product_name,
                'categoryOptions' => [
                        'C101' => 'C101 — Subscription (Consumer)',
                        'C102' => 'C102 — Instalment (Consumer)',
                        'C103' => 'C103 — Unscheduled (Consumer)',
                        'M101' => 'M101 — Subscription (Merchant)',
                        'M102' => 'M102 — Instalment (Merchant)',
                        'M103' => 'M103 — Unscheduled (Merchant)',
                ],
                'i18n' => [
                        'planCreated' => __('Plan created and attached to product!', 'alliancepay'),
                        'creating' => __('Creating...', 'alliancepay'),
                        'createPlan' => __('Create Plan', 'alliancepay'),
                ],
        ]);
    }

    /**
     * @return void
     */
    public static function register_rest_routes(): void
    {
        register_rest_route('alb/v1', '/create-plan-for-product', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [__CLASS__, 'rest_create_plan_for_product'],
                'permission_callback' => fn() => current_user_can('edit_products'),
        ]);

        register_rest_route('alb/v1', '/replace-plan-for-product', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [__CLASS__, 'rest_replace_plan_for_product'],
                'permission_callback' => fn() => current_user_can('edit_products'),
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_Error|\WP_HTTP_Response|\WP_REST_Response
     */
    public static function rest_create_plan_for_product(\WP_REST_Request $request)
    {
        $product_id = (int)$request->get_param('product_id');
        $product = wc_get_product($product_id);

        if (!$product || !current_user_can('edit_post', $product_id)) {
            return new \WP_Error('invalid_product', __('Invalid product.', 'alliancepay'), ['status' => 400]);
        }

        $name = sanitize_text_field((string)($request->get_param('plan_name') ?? ''));
        $description = sanitize_textarea_field((string)($request->get_param('plan_description') ?? ''));
        $category = sanitize_text_field((string)($request->get_param('category_subcategory_indicator') ?? ''));
        $from = sanitize_text_field((string)($request->get_param('applies_date_time_from') ?? ''));
        $to = sanitize_text_field((string)($request->get_param('applies_date_time_to') ?? ''));
        $rules_raw = $request->get_param('rules');
        $template_id = (int)($request->get_param('template_id') ?? 0) ?: null;

        if (!$name || !$category) {
            return new \WP_Error('missing_fields', __('Name and Category are required.', 'alliancepay'), ['status' => 400]);
        }
        if (mb_strlen($name) > 150) {
            return new \WP_Error('name_too_long', __('Plan name must be at most 150 characters.', 'alliancepay'), ['status' => 400]);
        }
        if ($description && mb_strlen($description) > 255) {
            return new \WP_Error('description_too_long', __('Description must be at most 255 characters.', 'alliancepay'), ['status' => 400]);
        }

        $rules = self::sanitize_rules_from_array(is_array($rules_raw) ? $rules_raw : []);
        if (empty($rules)) {
            return new \WP_Error('missing_rules', __('At least one payment rule is required.', 'alliancepay'), ['status' => 400]);
        }

        $body = [
                'name' => $name,
                'categorySubcategoryIndicator' => $category,
        ];

        if ($description) {
            $body['description'] = $description;
        }

        if ($from) {
            $body['appliesDateTimeFrom'] = self::format_datetime_for_api($from);
        }

        if ($to) {
            $body['appliesDateTimeTo'] = self::format_datetime_for_api($to);
        }

        $body['rules'] = $rules;

        $merchant_id = get_option(ALB_HPP_OPT, [])['merchantId'] ?? '';
        $plan_key = self::compute_plan_key(
                $merchant_id,
                $product_id,
                $template_id,
                $category,
                $rules
        );
        $existing = self::get_plan_by_key($plan_key);

        if ($existing && $existing->is_active) {
            $existing_raw = get_post_meta($product_id, '_alb_subscription_plan_ids', true);
            $existing_ids = $existing_raw ? (array)json_decode($existing_raw, true) : [];
            if (!in_array($existing->plan_id, $existing_ids, true)) {
                $existing_ids[] = $existing->plan_id;
                update_post_meta(
                        $product_id,
                        '_alb_subscription_plan_ids',
                        wp_json_encode($existing_ids)
                );
            }
            update_post_meta($product_id, '_alb_subscription_enabled', 'yes');

            return rest_ensure_response([
                    'ok' => true,
                    'plan_id' => $existing->plan_id,
                    'plan_name' => $existing->name,
                    'rules_summary' => self::format_rules_summary($existing->rules),
                    'reused' => true,
            ]);
        }

        try {
            $res = self::api_create_plan($body, $product_id, $template_id);
        } catch (\Throwable $e) {
            return new \WP_Error('bank_error', $e->getMessage(), ['status' => 502]);
        }

        $plan_id = $res['planId'] ?? '';
        if (!$plan_id) {
            return new \WP_Error('no_plan_id', __('Bank did not return a plan ID.', 'alliancepay'), ['status' => 502]);
        }

        $existing_raw = get_post_meta($product_id, '_alb_subscription_plan_ids', true);
        $existing_ids = $existing_raw ? (array)json_decode($existing_raw, true) : [];
        if (!in_array($plan_id, $existing_ids, true)) {
            $existing_ids[] = $plan_id;
            update_post_meta($product_id, '_alb_subscription_plan_ids', wp_json_encode($existing_ids));
        }
        update_post_meta($product_id, '_alb_subscription_enabled', 'yes');

        self::log_audit('plan_created', [
                'plan_id' => $plan_id,
                'product_id' => $product_id,
                'name' => $name,
        ]);

        return rest_ensure_response([
                'ok' => true,
                'plan_id' => $plan_id,
                'plan_name' => $res['name'] ?? $name,
                'rules_summary' => self::format_rules_summary($res['rules'] ?? $rules),
        ]);
    }

    /**
     * @param array $body
     * @param int $product_id
     * @param int|null $template_id
     * @return array
     */
    public static function api_create_plan(array $body, int $product_id = 0, ?int $template_id = null): array
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $client = new AlbHppClient($opt);

        $body['merchantId'] = $opt['merchantId'] ?? '';

        $plan_key = $product_id
                ? self::compute_plan_key(
                        $body['merchantId'],
                        $product_id,
                        $template_id,
                        $body['categorySubcategoryIndicator'] ?? '',
                        $body['rules'] ?? []
                )
                : null;

        $res = $client->create_plan($body);

        self::upsert_plan($res, $product_id ?: null, $template_id, $plan_key);

        $plan_id = $res['planId'] ?? '';
        $version_id = (int)($res['versionId'] ?? 1);

        if ($plan_id) {
            try {
                self::api_update_plan_version([
                        'planId' => $plan_id,
                        'versionId' => $version_id,
                        'infiniteVersion' => true,
                ]);
            } catch (\Throwable $e) {
                global $wpdb;
                $table = AllianceConfig::table_alliance_subscription_plans();
                $wpdb->update(
                        $table,
                        [
                                'status' => AllianceConfig::PLAN_STATUS_ERROR,
                                'sync_status' => 'error',
                                'sync_error' => $e->getMessage(),
                                'updated_at' => current_time('mysql'),
                        ],
                        ['plan_id' => $plan_id]
                );

                self::log_audit('plan_create_failed', [
                        'plan_id' => $plan_id,
                        'error' => $e->getMessage(),
                ]);

                throw new \RuntimeException(
                        sprintf(
                                __(
                                        'Plan %1$s (version %2$d) was created in the bank, but setting '
                                        . 'its infinite schedule failed: %3$s.'
                                        . 'Fix this manually via plan/version/update_v1 before customers '
                                        . 'subscribe — otherwise the plan will expire after ~1 year.',
                                        'alliancepay'
                                ),
                                $plan_id,
                                $version_id,
                                $e->getMessage()
                        ),
                        0,
                        $e
                );
            }
        }

        return $res;
    }

    /**
     * @param string $action
     * @param array $context
     * @return void
     */
    public static function log_audit(string $action, array $context = []): void
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_audit_log();
        $wpdb->insert($table, [
                'action' => $action,
                'plan_id' => $context['plan_id'] ?? null,
                'product_id' => $context['product_id'] ?? null,
                'user_id' => get_current_user_id() ?: null,
                'details' => wp_json_encode($context, JSON_UNESCAPED_UNICODE),
                'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_Error|\WP_HTTP_Response|\WP_REST_Response
     */
    public static function rest_replace_plan_for_product(\WP_REST_Request $request)
    {
        $old_plan_id = sanitize_text_field((string)($request->get_param('old_plan_id') ?? ''));
        if (!$old_plan_id) {
            return new \WP_Error('missing_old_plan', __('old_plan_id is required.', 'alliancepay'), ['status' => 400]);
        }

        $old_plan = self::get_plan_by_id($old_plan_id);
        if (!$old_plan) {
            return new \WP_Error('old_plan_not_found', __('Original plan not found.', 'alliancepay'), ['status' => 404]);
        }

        $result = self::rest_create_plan_for_product($request);
        if (is_wp_error($result)) {
            return $result;
        }

        if ($old_plan_id === (is_array($result->get_data()) ? ($result->get_data()['plan_id'] ?? null) : null)) {
            return $result;
        }

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        $wpdb->update(
                $table,
                ['is_active' => 0, 'updated_at' => current_time('mysql')],
                ['plan_id' => $old_plan_id]
        );

        self::log_audit('plan_replaced', [
                'old_plan_id' => $old_plan_id,
                'product_id' => $old_plan->product_id ?? null,
                'new_plan_id' => is_array($result->get_data()) ? ($result->get_data()['plan_id'] ?? null) : null,
        ]);

        $data = $result->get_data();
        $data['old_plan_id'] = $old_plan_id;
        $data['old_plan_deactivated'] = true;

        return rest_ensure_response($data);
    }

    /**
     * @param string $plan_id
     * @param int $delta
     * @return void
     */
    public static function adjust_active_subscriptions_count(string $plan_id, int $delta): void
    {
        if (!$plan_id || !$delta) return;

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET active_subscriptions_count = GREATEST(0, active_subscriptions_count + %d) WHERE plan_id = %s",
                $delta,
                $plan_id
        ));
    }

    /**
     * @param array $rules_raw
     * @return array
     */
    public static function sanitize_rules_from_array(array $rules_raw): array
    {
        $rules = [];
        foreach ($rules_raw as $rule) {
            if (!is_array($rule)) continue;
            $rules[] = [
                    'paymentNumberFrom' => (int)($rule['paymentNumberFrom'] ?? 1),
                    'paymentNumberTo' => (int)($rule['paymentNumberTo'] ?? 999),
                    'intervalValue' => (int)($rule['intervalValue'] ?? 1),
                    'intervalUnit' => in_array($rule['intervalUnit'] ?? '', ['DAY', 'WEEK', 'MONTH'], true)
                            ? $rule['intervalUnit']
                            : 'MONTH',
                    'coinAmount' => (int)($rule['coinAmount'] ?? 0),
                    'currency' => '980',
            ];
        }
        return $rules;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function format_datetime_for_api(string $value): string
    {
        if (empty($value)) return '';
        try {
            $tz = wp_timezone();
            $dt = new \DateTime($value, $tz);
            return $dt->format('Y-m-d H:i:s.00P');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    /**
     * @return void
     */
    public static function enqueue_scripts(): void
    {
        if (!is_product()) return;

        global $product;
        if (!$product) return;
        $enabled = get_post_meta($product->get_id(), '_alb_subscription_enabled', true);
        if ($enabled !== 'yes') return;

        wp_enqueue_style(
                'alb-subscription-frontend',
                plugins_url('../assets/css/subscription-admin.css', __FILE__),
                [],
                '1.0.0'
        );

        wp_enqueue_script(
                'alb-subscription-product',
                plugins_url('../assets/js/subscription-product.js', __FILE__),
                ['jquery'],
                '1.0.0',
                true
        );

        wp_localize_script('alb-subscription-product', 'albSubscription', [
                'selectPlanText' => __('Please select a subscription plan.', 'alliancepay'),
        ]);
    }

    /**
     * @param bool $passed
     * @param int $product_id
     * @param int $quantity
     * @return bool
     */
    public static function validate_add_to_cart(bool $passed, int $product_id, int $quantity): bool
    {
        if (!$passed || !function_exists('WC') || !WC()->cart) {
            return $passed;
        }

        $adding_subscription = isset($_POST['alb_purchase_type'])
                && $_POST['alb_purchase_type'] === 'subscribe'
                && !empty($_POST['alb_plan_id']);

        $cart = WC()->cart;
        $cart_has_subscription = false;
        foreach ($cart->get_cart() as $item) {
            if (!empty($item['alb_subscription_mode']) && $item['alb_subscription_mode'] === 'subscribe') {
                $cart_has_subscription = true;
                break;
            }
        }

        if ($adding_subscription && !$cart->is_empty()) {
            wc_add_notice(
                    __(
                            'Please complete or empty your cart first — a subscription must be '
                            . 'ordered separately from other items.',
                            'alliancepay'
                    ),
                    'error'
            );
            return false;
        }

        if (!$adding_subscription && $cart_has_subscription) {
            wc_add_notice(
                    __('Your cart already contains a subscription. Please complete that order '
                            . 'separately before adding other items.',
                            'alliancepay'
                    ),
                    'error'
            );
            return false;
        }

        return $passed;
    }

    /**
     * @param array $cart_item_data
     * @param int $product_id
     * @param int $variation_id
     * @return array
     */
    public static function add_cart_item_data(array $cart_item_data, int $product_id, int $variation_id): array
    {
        if (
                isset($_POST['alb_purchase_type'])
                && $_POST['alb_purchase_type'] === 'subscribe'
                && !empty($_POST['alb_plan_id'])
                && isset($_POST['alb_subscription_enabled'])
        ) {
            if (!is_user_logged_in()) {
                return $cart_item_data;
            }
            if (!wp_verify_nonce($_POST['alb_subscription_nonce'] ?? '', 'alb_subscription_nonce')) {
                return $cart_item_data;
            }
            $cart_item_data['alb_subscription_mode'] = 'subscribe';
            $cart_item_data['alb_plan_id'] = sanitize_text_field($_POST['alb_plan_id']);
        }

        return $cart_item_data;
    }

    /**
     * @param array $item_data
     * @param array $cart_item
     * @return array
     */
    public static function display_cart_item_data(array $item_data, array $cart_item): array
    {
        if (!empty($cart_item['alb_subscription_mode']) && $cart_item['alb_subscription_mode'] === 'subscribe') {
            $plan_id = $cart_item['alb_plan_id'] ?? '';
            $plan = self::get_plan_by_id($plan_id);
            $plan_name = $plan ? $plan->name : $plan_id;

            $item_data[] = [
                    'key' => __('Subscription Plan', 'alliancepay'),
                    'value' => $plan_name,
            ];
        }

        return $item_data;
    }

    /**
     * @return array
     */
    public static function get_active_plans(): array
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        return $wpdb->get_results(
                $wpdb->prepare(
                        "SELECT * FROM {$table} WHERE status = %s AND is_active = 1 ORDER BY name ASC",
                        AllianceConfig::PLAN_STATUS_ACTIVE
                )
        );
    }

    /**
     * @param string $plan_key
     * @return object|array|\stdClass|null
     */
    public static function get_plan_by_key(string $plan_key): ?object
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE plan_key = %s", $plan_key)) ?: null;
    }

    /**
     * @param string $merchant_id
     * @param int $product_id
     * @param int|null $template_id
     * @param string $category_indicator
     * @param array $rules
     * @return string
     */
    public static function compute_plan_key(
            string $merchant_id,
            int    $product_id,
            ?int   $template_id,
            string $category_indicator,
            array  $rules
    ): string
    {
        $normalized = self::normalize_rules_for_hash($rules);

        return hash(
                'sha256',
                $merchant_id . '|' . $product_id . '|' . ($template_id ?? '') . '|' . $category_indicator . '|' . $normalized
        );
    }

    /**
     * @param array $rules
     * @return string
     */
    private static function normalize_rules_for_hash(array $rules): string
    {
        $sorted = $rules;
        usort($sorted, fn($a, $b) => ($a['paymentNumberFrom'] ?? 0) <=> ($b['paymentNumberFrom'] ?? 0));

        return (string)wp_json_encode($sorted, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array
     */
    public static function get_all_plans(): array
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");
    }

    /**
     * @param string $plan_id
     * @return object|array|\stdClass|null
     */
    public static function get_plan_by_id(string $plan_id): ?object
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE plan_id = %s", $plan_id)) ?: null;
    }

    /**
     * @param array $plan_ids
     * @return array
     */
    public static function get_plans_by_ids(array $plan_ids): array
    {
        if (empty($plan_ids)) return [];
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        $placeholders = implode(',', array_fill(0, count($plan_ids), '%s'));

        return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE plan_id IN ({$placeholders}) AND status = %s AND is_active = 1 ORDER BY name ASC",
                array_merge($plan_ids, [AllianceConfig::PLAN_STATUS_ACTIVE])
        ));
    }

    /**
     * @param array $data
     * @param int|null $product_id
     * @param int|null $template_id
     * @param string|null $plan_key
     * @return void
     */
    public static function upsert_plan(
            array   $data,
            ?int    $product_id = null,
            ?int    $template_id = null,
            ?string $plan_key = null
    ): void {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        $plan_id = $data['planId'] ?? '';
        if (!$plan_id) return;

        $existing = self::get_plan_by_id($plan_id);

        $rules = $data['rules'] ?? [];
        $row = [
                'plan_id' => $plan_id,
                'version_id' => (int)($data['versionId'] ?? 1),
                'merchant_id' => $data['merchantId'] ?? '',
                'name' => $data['name'] ?? '',
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? AllianceConfig::PLAN_STATUS_ACTIVE,
                'category_subcategory_indicator' => $data['categorySubcategoryIndicator'] ?? '',
                'rules' => is_array($rules) ? wp_json_encode($rules, JSON_UNESCAPED_UNICODE) : $rules,
                'applies_date_time_from' => !empty($data['appliesDateTimeFrom'])
                        ? date('Y-m-d H:i:s', strtotime($data['appliesDateTimeFrom']))
                        : null,
                'applies_date_time_to' => !empty($data['appliesDateTimeTo'])
                        ? date('Y-m-d H:i:s', strtotime($data['appliesDateTimeTo']))
                        : null,
                'updated_at' => current_time('mysql'),
        ];

        if ($product_id !== null) $row['product_id'] = $product_id;
        if ($template_id !== null) $row['template_id'] = $template_id;
        if ($plan_key !== null) $row['plan_key'] = $plan_key;

        if ($existing) {
            $wpdb->update($table, $row, ['plan_id' => $plan_id]);
        } else {
            $row['created_at'] = current_time('mysql');
            $row['is_active'] = 1;
            $wpdb->insert($table, $row);
        }
    }

    /**
     * @param array $body
     * @return array
     */
    public static function api_update_plan(array $body): array
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $client = new AlbHppClient($opt);

        $body['merchantId'] = $opt['merchantId'] ?? '';

        $res = $client->update_plan($body);

        self::upsert_plan($res);

        $plan_id = $res['planId'] ?? ($body['planId'] ?? '');
        $version_id = (int)($res['versionId'] ?? 0);

        if ($plan_id && $version_id) {
            try {
                self::api_update_plan_version([
                        'planId' => $plan_id,
                        'versionId' => $version_id,
                        'infiniteVersion' => true,
                ]);
            } catch (\Throwable $e) {
                global $wpdb;
                $table = AllianceConfig::table_alliance_subscription_plans();
                $wpdb->update(
                        $table,
                        ['status' => AllianceConfig::PLAN_STATUS_ERROR, 'updated_at' => current_time('mysql')],
                        ['plan_id' => $plan_id]
                );

                throw new \RuntimeException(
                        sprintf(
                                __(
                                        'Plan %1$s was repriced (new version %2$d), but setting its infinite schedule '
                                        . 'failed: %3$s. Fix this manually via plan/version/update_v1 — otherwise this '
                                        . 'version will expire after ~1 year.',
                                        'alliancepay'
                                ),
                                $plan_id,
                                $version_id,
                                $e->getMessage()
                        ),
                        0,
                        $e
                );
            }
        }

        return $res;
    }

    /**
     * @param string $plan_id
     * @param string $status
     * @return array
     */
    public static function api_plan_status_update(string $plan_id, string $status): array
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $client = new AlbHppClient($opt);

        $res = $client->plan_status_update([
                'planId' => $plan_id,
                'status' => $status,
        ]);

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        $wpdb->update($table, ['status' => $status, 'updated_at' => current_time('mysql')], ['plan_id' => $plan_id]);

        return $res;
    }

    /**
     * @param array $body
     * @return array
     */
    public static function api_update_plan_version(array $body): array
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $client = new AlbHppClient($opt);

        $res = $client->update_plan_version($body);

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        $update = ['updated_at' => current_time('mysql')];
        if (!empty($res['appliesDateTimeFrom'])) {
            $update['applies_date_time_from'] = date('Y-m-d H:i:s', strtotime($res['appliesDateTimeFrom']));
        }
        if (!empty($res['appliesDateTimeTo'])) {
            $update['applies_date_time_to'] = date('Y-m-d H:i:s', strtotime($res['appliesDateTimeTo']));
        }
        $wpdb->update($table, $update, ['plan_id' => $body['planId']]);

        return $res;
    }

    /**
     * @param string $plan_id
     * @param int $version_id
     * @return array
     */
    public static function api_delete_plan_version(string $plan_id, int $version_id): array
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $client = new AlbHppClient($opt);

        $res = $client->delete_plan_version([
                'planId' => $plan_id,
                'versionId' => $version_id,
        ]);

        return $res;
    }

    /**
     * @param $rules_raw
     * @return string
     */
    public static function format_rules_summary($rules_raw): string
    {
        $rules = is_string($rules_raw) ? json_decode($rules_raw, true) : $rules_raw;
        if (empty($rules) || !is_array($rules)) return '';

        usort($rules, fn($a, $b) => ($a['paymentNumberFrom'] ?? 0) <=> ($b['paymentNumberFrom'] ?? 0));

        $parts = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;

            $from = (int)($rule['paymentNumberFrom'] ?? 0);
            $to = (int)($rule['paymentNumberTo'] ?? 0);
            $count = max($to - $from + 1, 1);
            $coin = (float)($rule['coinAmount'] ?? 0);
            $unit = $rule['intervalUnit'] ?? '';
            $interval = ($rule['intervalValue'] ?? '?') . ' ' . $unit;

            if ($coin <= 0.0) {
                $parts[] = sprintf('%d× free / %s', $count, $interval);
            } else {
                $parts[] = sprintf('%d× %s UAH / %s', $count, number_format($coin / 100, 2), $interval);
            }
        }

        return implode(', then ', $parts);
    }
}
