<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 *
 * Subscription Templates — Level 1 of the target three-level model
 * (template → CMS plan → bank plan). A template carries the RULE STRUCTURE
 * (payment number ranges, interval, and which rules are priced) but no
 * price and no product — it's reusable across many products.
 *
 * Applying a template to a product (done in ALB_Subscription_Plans, product
 * page) merges this structure with an admin-supplied price per priced rule
 * to produce an actual subscription_plan (Scenario 2).
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Subscription_Templates.
 */
class ALB_Subscription_Templates
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    public static function add_admin_menu(): void
    {
        add_submenu_page(
                'alb-hpp',
                __('Subscription Templates', 'alliancepay'),
                __('Subscription Templates', 'alliancepay'),
                'manage_woocommerce',
                'alb-hpp-templates',
                [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to view this page.', 'alliancepay'));
        }

        $templates = self::get_all_templates();

        echo '<div class="wrap"><h1>' . esc_html__('AlliancePay — Subscription Templates', 'alliancepay') . '</h1>';
        echo '<p class="description">'
                . esc_html__('A template defines the payment-rule STRUCTURE (intervals, which rules are free/paid) without a price or a product. Apply it to a product from the product edit page to create an actual priced plan.', 'alliancepay')
                . '</p>';

        echo '<p><button type="button" id="alb-tpl-open" class="button button-primary">'
                . esc_html__('+ Add New Template', 'alliancepay') . '</button></p>';

        self::render_form();

        echo '<table class="wp-list-table widefat striped" id="alb-tpl-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Code', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Name', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Category', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Rules', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Status', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Used by plans', 'alliancepay') . '</th>';
        echo '<th>' . esc_html__('Actions', 'alliancepay') . '</th>';
        echo '</tr></thead><tbody id="alb-tpl-tbody">';

        foreach ($templates as $tpl) {
            $used_count = self::count_plans_using_template((int)$tpl->id);
            $rules = json_decode($tpl->rules_template, true) ?: [];

            echo '<tr data-id="' . esc_attr((string)$tpl->id) . '">';
            echo '<td>' . esc_html($tpl->code) . '</td>';
            echo '<td>' . esc_html($tpl->name) . '</td>';
            echo '<td>' . esc_html($tpl->category_indicator) . '</td>';
            echo '<td>' . esc_html(self::format_template_rules_summary($rules)) . '</td>';
            echo '<td>' . esc_html($tpl->status) . '</td>';
            echo '<td>' . esc_html((string)$used_count) . '</td>';
            echo '<td>';
            if ($used_count === 0) {
                printf(
                        '<a href="#" class="alb-tpl-edit" data-id="%s" data-payload="%s">%s</a> | ',
                        esc_attr((string)$tpl->id),
                        esc_attr(wp_json_encode($tpl)),
                        esc_html__('Edit', 'alliancepay')
                );
            } else {
                echo '<span title="'
                        . esc_attr__('Templates already used by plans can\'t be edited — create a new template instead.', 'alliancepay')
                        . '">' . esc_html__('In use', 'alliancepay') . '</span> | ';
            }
            printf(
                    '<a href="#" class="alb-tpl-toggle-status" data-id="%s" data-status="%s">%s</a>',
                    esc_attr((string)$tpl->id),
                    esc_attr($tpl->status === self::STATUS_ACTIVE ? self::STATUS_ARCHIVED : self::STATUS_ACTIVE),
                    $tpl->status === self::STATUS_ACTIVE
                            ? esc_html__('Archive', 'alliancepay')
                            : esc_html__('Restore', 'alliancepay')
            );
            echo '</td>';
            echo '</tr>';
        }

        if (empty($templates)) {
            echo '<tr><td colspan="7">' . esc_html__('No templates yet.', 'alliancepay') . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        self::render_inline_script();
    }

    private static function render_form(): void
    {
        ?>
        <div id="alb-tpl-wrap"
             style="display:none;margin:0 0 16px;padding:16px;border:1px solid #c3c4c7;border-radius:4px;background:#fafafa;max-width:900px;">
            <h3 id="alb-tpl-heading" style="margin-top:0;"><?php esc_html_e('Add New Template', 'alliancepay'); ?></h3>

            <input type="hidden" id="alb-tpl-id" value="">

            <table class="form-table" style="margin-bottom:0;">
                <tr>
                    <th style="width:180px;"><label
                                for="alb-tpl-code"><?php esc_html_e('Code *', 'alliancepay'); ?></label></th>
                    <td><input type="text" id="alb-tpl-code" class="regular-text" maxlength="50" placeholder="M12"
                               required></td>
                </tr>
                <tr>
                    <th><label for="alb-tpl-name"><?php esc_html_e('Name *', 'alliancepay'); ?></label></th>
                    <td><input type="text" id="alb-tpl-name" class="regular-text" maxlength="150" required></td>
                </tr>
                <tr>
                    <th><label for="alb-tpl-category"><?php esc_html_e('Category *', 'alliancepay'); ?></label></th>
                    <td>
                        <select id="alb-tpl-category">
                            <option value="C101">C101 — Subscription (Consumer)</option>
                            <option value="C102">C102 — Instalment (Consumer)</option>
                            <option value="C103">C103 — Unscheduled (Consumer)</option>
                            <option value="M101">M101 — Subscription (Merchant)</option>
                            <option value="M102">M102 — Instalment (Merchant)</option>
                            <option value="M103">M103 — Unscheduled (Merchant)</option>
                        </select>
                    </td>
                </tr>
            </table>

            <h4 style="margin:12px 0 6px;"><?php esc_html_e('Rule Structure (no prices — set per product)', 'alliancepay'); ?></h4>
            <table class="wp-list-table widefat alb-rules-table" id="alb-tpl-rules-table" style="max-width:760px;">
                <thead>
                <tr>
                    <th><?php esc_html_e('Pay # From', 'alliancepay'); ?></th>
                    <th><?php esc_html_e('Pay # To', 'alliancepay'); ?></th>
                    <th><?php esc_html_e('Interval', 'alliancepay'); ?></th>
                    <th><?php esc_html_e('Unit', 'alliancepay'); ?></th>
                    <th><?php esc_html_e('Priced?', 'alliancepay'); ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="alb-tpl-rules-body">
                <tr class="alb-tpl-rule-row">
                    <td><input type="number" class="alb-tpl-f small-text" value="1" min="1"></td>
                    <td><input type="number" class="alb-tpl-t small-text" value="999" min="1"></td>
                    <td><input type="number" class="alb-tpl-iv small-text" value="1" min="1"></td>
                    <td>
                        <select class="alb-tpl-iu">
                            <option value="DAY">DAY</option>
                            <option value="WEEK">WEEK</option>
                            <option value="MONTH" selected>MONTH</option>
                        </select>
                    </td>
                    <td><input type="checkbox" class="alb-tpl-priced" checked></td>
                    <td>
                        <button type="button" class="button alb-tpl-remove-rule" disabled>&times;</button>
                    </td>
                </tr>
                </tbody>
            </table>
            <p>
                <button type="button" id="alb-tpl-add-rule" class="button">
                    + <?php esc_html_e('Add Rule', 'alliancepay'); ?></button>
            </p>

            <div id="alb-tpl-message" style="display:none;margin-top:8px;padding:8px 12px;border-radius:3px;"></div>

            <p style="margin-top:12px;">
                <button type="button" id="alb-tpl-submit"
                        class="button button-primary"><?php esc_html_e('Save Template', 'alliancepay'); ?></button>
                &nbsp;
                <button type="button" id="alb-tpl-cancel"
                        class="button"><?php esc_html_e('Cancel', 'alliancepay'); ?></button>
            </p>
        </div>
        <?php
    }

    private static function render_inline_script(): void
    {
        $nonce = wp_create_nonce('wp_rest');
        $rest_url = rest_url('alb/v1/templates');
        ?>
        <script>
            (function () {
                'use strict';
                var nonce = <?php echo wp_json_encode($nonce); ?>;
                var restUrl = <?php echo wp_json_encode($rest_url); ?>;

                var wrap = document.getElementById('alb-tpl-wrap');
                var body = document.getElementById('alb-tpl-rules-body');
                var msg = document.getElementById('alb-tpl-message');
                var idEl = document.getElementById('alb-tpl-id');
                var heading = document.getElementById('alb-tpl-heading');

                function showMsg(text, isError) {
                    msg.textContent = text;
                    msg.style.display = 'block';
                    msg.style.background = isError ? '#fef2f2' : '#f0fdf4';
                    msg.style.border = '1px solid ' + (isError ? '#fca5a5' : '#86efac');
                    msg.style.color = isError ? '#991b1b' : '#166534';
                }

                function addRow(rule) {
                    rule = rule || {};
                    var tr = document.createElement('tr');
                    tr.className = 'alb-tpl-rule-row';
                    tr.innerHTML =
                        '<td><input type="number" class="alb-tpl-f small-text" value="' + (rule.paymentNumberFrom || 1) + '" min="1"></td>' +
                        '<td><input type="number" class="alb-tpl-t small-text" value="' + (rule.paymentNumberTo || 999) + '" min="1"></td>' +
                        '<td><input type="number" class="alb-tpl-iv small-text" value="' + (rule.intervalValue || 1) + '" min="1"></td>' +
                        '<td><select class="alb-tpl-iu">' +
                        '<option value="DAY"' + (rule.intervalUnit === 'DAY' ? ' selected' : '') + '>DAY</option>' +
                        '<option value="WEEK"' + (rule.intervalUnit === 'WEEK' ? ' selected' : '') + '>WEEK</option>' +
                        '<option value="MONTH"' + (!rule.intervalUnit || rule.intervalUnit === 'MONTH' ? ' selected' : '') + '>MONTH</option>' +
                        '</select></td>' +
                        '<td><input type="checkbox" class="alb-tpl-priced"' + (rule.priced === false ? '' : ' checked') + '></td>' +
                        '<td><button type="button" class="button alb-tpl-remove-rule">&times;</button></td>';
                    body.appendChild(tr);
                }

                function collectRules() {
                    var rules = [];
                    body.querySelectorAll('.alb-tpl-rule-row').forEach(function (row) {
                        rules.push({
                            paymentNumberFrom: parseInt(row.querySelector('.alb-tpl-f').value, 10) || 1,
                            paymentNumberTo: parseInt(row.querySelector('.alb-tpl-t').value, 10) || 999,
                            intervalValue: parseInt(row.querySelector('.alb-tpl-iv').value, 10) || 1,
                            intervalUnit: row.querySelector('.alb-tpl-iu').value || 'MONTH',
                            priced: row.querySelector('.alb-tpl-priced').checked,
                        });
                    });
                    return rules;
                }

                function resetForm() {
                    idEl.value = '';
                    heading.textContent = 'Add New Template';
                    document.getElementById('alb-tpl-code').value = '';
                    document.getElementById('alb-tpl-code').disabled = false;
                    document.getElementById('alb-tpl-name').value = '';
                    document.getElementById('alb-tpl-category').value = 'C101';
                    body.innerHTML = '';
                    addRow({});
                    msg.style.display = 'none';
                }

                document.getElementById('alb-tpl-open').addEventListener('click', function () {
                    resetForm();
                    wrap.style.display = 'block';
                });
                document.getElementById('alb-tpl-cancel').addEventListener('click', function () {
                    wrap.style.display = 'none';
                });
                document.getElementById('alb-tpl-add-rule').addEventListener('click', function () {
                    addRow({});
                });
                body.addEventListener('click', function (e) {
                    if (e.target.classList.contains('alb-tpl-remove-rule')) {
                        var row = e.target.closest('tr');
                        if (body.querySelectorAll('.alb-tpl-rule-row').length > 1 && row) row.remove();
                    }
                });

                document.getElementById('alb-tpl-table').addEventListener('click', function (e) {
                    var editLink = e.target.closest('.alb-tpl-edit');
                    if (editLink) {
                        e.preventDefault();
                        var data = JSON.parse(editLink.getAttribute('data-payload'));
                        idEl.value = data.id;
                        heading.textContent = 'Edit Template';
                        document.getElementById('alb-tpl-code').value = data.code;
                        document.getElementById('alb-tpl-code').disabled = true;
                        document.getElementById('alb-tpl-name').value = data.name;
                        document.getElementById('alb-tpl-category').value = data.category_indicator;
                        body.innerHTML = '';
                        (JSON.parse(data.rules_template) || []).forEach(addRow);
                        wrap.style.display = 'block';
                        return;
                    }

                    var toggleLink = e.target.closest('.alb-tpl-toggle-status');
                    if (toggleLink) {
                        e.preventDefault();
                        var id = toggleLink.getAttribute('data-id');
                        var status = toggleLink.getAttribute('data-status');
                        fetch(restUrl + '/' + id + '/status', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-WP-Nonce': nonce},
                            body: JSON.stringify({status: status}),
                        }).then(function () {
                            location.reload();
                        });
                    }
                });

                document.getElementById('alb-tpl-submit').addEventListener('click', function () {
                    var code = document.getElementById('alb-tpl-code').value.trim();
                    var name = document.getElementById('alb-tpl-name').value.trim();
                    var category = document.getElementById('alb-tpl-category').value;
                    var rules = collectRules();

                    if (!code || !name || !rules.length) {
                        showMsg('Code, Name and at least one rule are required.', true);
                        return;
                    }

                    var isEdit = !!idEl.value;
                    var url = isEdit ? (restUrl + '/' + idEl.value) : restUrl;

                    fetch(url, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'X-WP-Nonce': nonce},
                        body: JSON.stringify({
                            code: code,
                            name: name,
                            category_indicator: category,
                            rules_template: rules
                        }),
                    })
                        .then(function (r) {
                            return r.json();
                        })
                        .then(function (data) {
                            if (!data.ok) {
                                showMsg(data.message || 'Error saving template.', true);
                                return;
                            }
                            location.reload();
                        })
                        .catch(function (e) {
                            showMsg(e.message || 'Network error', true);
                        });
                });
            }());
        </script>
        <?php
    }

    public static function register_rest_routes(): void
    {
        register_rest_route('alb/v1', '/templates', [
                [
                        'methods' => 'GET',
                        'callback' => [__CLASS__, 'rest_list_templates'],
                        'permission_callback' => fn() => current_user_can('manage_woocommerce'),
                ],
                [
                        'methods' => \WP_REST_Server::CREATABLE,
                        'callback' => [__CLASS__, 'rest_create_template'],
                        'permission_callback' => fn() => current_user_can('manage_woocommerce'),
                ],
        ]);

        register_rest_route('alb/v1', '/templates/(?P<id>\d+)', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [__CLASS__, 'rest_update_template'],
                'permission_callback' => fn() => current_user_can('manage_woocommerce'),
        ]);

        register_rest_route('alb/v1', '/templates/(?P<id>\d+)/status', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [__CLASS__, 'rest_set_template_status'],
                'permission_callback' => fn() => current_user_can('manage_woocommerce'),
        ]);
    }

    public static function rest_list_templates(): \WP_REST_Response
    {
        return rest_ensure_response(['ok' => true, 'templates' => self::get_all_templates()]);
    }

    public static function rest_create_template(\WP_REST_Request $request)
    {
        [$code, $name, $category, $rules, $error] = self::extract_and_validate($request);
        if ($error) return $error;

        if (self::get_template_by_code($code)) {
            return new \WP_Error('duplicate_code', __('A template with this code already exists.', 'alliancepay'), ['status' => 409]);
        }

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_templates();
        $wpdb->insert($table, [
                'code' => $code,
                'name' => $name,
                'category_indicator' => $category,
                'rules_template' => wp_json_encode($rules, JSON_UNESCAPED_UNICODE),
                'status' => self::STATUS_ACTIVE,
                'created_at' => current_time('mysql'),
        ]);

        return rest_ensure_response(['ok' => true, 'id' => $wpdb->insert_id]);
    }

    public static function rest_update_template(\WP_REST_Request $request)
    {
        $id = (int)$request->get_param('id');
        $template = self::get_template_by_id($id);
        if (!$template) {
            return new \WP_Error('not_found', __('Template not found.', 'alliancepay'), ['status' => 404]);
        }

        if (self::count_plans_using_template($id) > 0) {
            return new \WP_Error(
                    'template_in_use',
                    __('This template is already used by one or more plans and can no longer be edited — create a new template instead.', 'alliancepay'),
                    ['status' => 409]
            );
        }

        [$code, $name, $category, $rules, $error] = self::extract_and_validate($request);
        if ($error) return $error;

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_templates();
        $wpdb->update($table, [
                'name' => $name,
                'category_indicator' => $category,
                'rules_template' => wp_json_encode($rules, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql'),
        ], ['id' => $id]);

        return rest_ensure_response(['ok' => true, 'id' => $id]);
    }

    public static function rest_set_template_status(\WP_REST_Request $request)
    {
        $id = (int)$request->get_param('id');
        $status = sanitize_text_field((string)$request->get_param('status'));

        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
            return new \WP_Error('bad_status', __('Invalid status.', 'alliancepay'), ['status' => 400]);
        }

        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_templates();
        $wpdb->update($table, ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id]);

        return rest_ensure_response(['ok' => true]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return array{0:string,1:string,2:string,3:array,4:\WP_Error|null}
     */
    private static function extract_and_validate(\WP_REST_Request $request): array
    {
        $code = sanitize_text_field((string)($request->get_param('code') ?? ''));
        $name = sanitize_text_field((string)($request->get_param('name') ?? ''));
        $category = sanitize_text_field((string)($request->get_param('category_indicator') ?? ''));
        $rules_raw = $request->get_param('rules_template');

        if (!$name || !$category) {
            return ['', '', '', [], new \WP_Error('missing_fields', __('Name and Category are required.', 'alliancepay'), ['status' => 400])];
        }
        if (mb_strlen($name) > 150) {
            return ['', '', '', [], new \WP_Error('name_too_long', __('Name must be at most 150 characters.', 'alliancepay'), ['status' => 400])];
        }

        $rules = [];
        foreach (is_array($rules_raw) ? $rules_raw : [] as $rule) {
            if (!is_array($rule)) continue;
            $rules[] = [
                    'paymentNumberFrom' => (int)($rule['paymentNumberFrom'] ?? 1),
                    'paymentNumberTo' => (int)($rule['paymentNumberTo'] ?? 999),
                    'intervalValue' => (int)($rule['intervalValue'] ?? 1),
                    'intervalUnit' => in_array($rule['intervalUnit'] ?? '', ['DAY', 'WEEK', 'MONTH'], true) ? $rule['intervalUnit'] : 'MONTH',
                    'priced' => !isset($rule['priced']) || (bool)$rule['priced'],
            ];
        }

        if (empty($rules)) {
            return ['', '', '', [], new \WP_Error('missing_rules', __('At least one rule is required.', 'alliancepay'), ['status' => 400])];
        }

        return [$code, $name, $category, $rules, null];
    }

    public static function get_all_templates(): array
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_templates();
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");
    }

    public static function get_active_templates(): array
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_templates();
        return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY name ASC",
                self::STATUS_ACTIVE
        ));
    }

    public static function get_template_by_id(int $id): ?object
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_templates();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)) ?: null;
    }

    public static function get_template_by_code(string $code): ?object
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_templates();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE code = %s", $code)) ?: null;
    }

    public static function count_plans_using_template(int $template_id): int
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_subscription_plans();
        return (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE template_id = %d",
                $template_id
        ));
    }

    /**
     * Human-readable structure summary, e.g. "14 DAY free, then 6× MONTH, then 9× MONTH".
     *
     * @param array $rules
     * @return string
     */
    public static function format_template_rules_summary(array $rules): string
    {
        $parts = [];
        foreach ($rules as $rule) {
            $count = (int)($rule['paymentNumberTo'] ?? 0) - (int)($rule['paymentNumberFrom'] ?? 0) + 1;
            $unit = $rule['intervalUnit'] ?? 'MONTH';
            $free = empty($rule['priced']) ? ' (free)' : '';
            $parts[] = sprintf('%d× %s%s', max($count, 0), $unit, $free);
        }
        return implode(', then ', $parts);
    }
}
