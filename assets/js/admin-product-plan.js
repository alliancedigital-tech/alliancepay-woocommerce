/**
 * AlliancePay — inline subscription plan creation on the WooCommerce product edit page.
 *
 * Reads configuration from `albPlanAdmin` (injected via wp_localize_script):
 *   restUrl, nonce, priceCoins, productName, i18n
 */
(function () {
    'use strict';

    var cfg = window.albPlanAdmin || {};
    var i18n = cfg.i18n || {};
    var ruleIdx = 1;
    var btnOpen, btnCancel, btnSubmit, btnAddRule;
    var wrap, rulesBody, message, planSelect;
    var oldPlanIdEl, heading, replaceNotice;
    var repricePlanIdEl, notifyRow, notifiedCheckbox, affectedCountEl;
    var nameFieldRow, categoryFieldRow;
    var templateSelect, previewEl;


    function showMessage(text, isError) {
        if (!message) return;
        message.textContent = text;
        message.style.display = 'block';
        message.style.background = isError ? '#fef2f2' : '#f0fdf4';
        message.style.border = '1px solid ' + (isError ? '#fca5a5' : '#86efac');
        message.style.color = isError ? '#991b1b' : '#166534';
    }

    function hideMessage() {
        if (!message) return;
        message.style.display = 'none';
        message.textContent = '';
    }

    function addRuleRow(idx, coins, first) {
        var tr = document.createElement('tr');
        tr.className = 'alb-cp-rule-row';
        tr.innerHTML =
            '<td><input type="number" class="alb-cp-f small-text" value="1" min="1"></td>' +
            '<td><input type="number" class="alb-cp-t small-text" value="999" min="1"></td>' +
            '<td><input type="number" class="alb-cp-iv small-text" value="1" min="1"></td>' +
            '<td>' +
            '<select class="alb-cp-iu">' +
            '<option value="DAY">DAY</option>' +
            '<option value="WEEK">WEEK</option>' +
            '<option value="MONTH" selected>MONTH</option>' +
            '</select>' +
            '</td>' +
            '<td><input type="number" class="alb-cp-ca small-text" value="' + (coins || 0) + '" min="0"></td>' +
            '<td><input type="text" class="alb-cp-cu small-text" value="980" maxlength="3" readonly></td>' +
            '<td><button type="button" class="button alb-cp-remove-rule"' + (first ? ' disabled' : '') + '>&times;</button></td>';
        rulesBody.appendChild(tr);
    }

    function collectRules() {
        var rules = [];
        var rows = rulesBody.querySelectorAll('.alb-cp-rule-row');
        rows.forEach(function (row) {
            rules.push({
                paymentNumberFrom: parseInt(row.querySelector('.alb-cp-f').value, 10) || 1,
                paymentNumberTo: parseInt(row.querySelector('.alb-cp-t').value, 10) || 999,
                intervalValue: parseInt(row.querySelector('.alb-cp-iv').value, 10) || 1,
                intervalUnit: row.querySelector('.alb-cp-iu').value || 'MONTH',
                coinAmount: parseInt(row.querySelector('.alb-cp-ca').value, 10) || 0,
                currency: '980',
            });
        });
        return rules;
    }

    function appendPlanOption(planId, planName, rulesSummary) {
        if (!planSelect) return;
        var option = document.createElement('option');
        option.value = planId;
        option.selected = true;
        option.textContent = planName + ' — ' + rulesSummary;
        planSelect.appendChild(option);
    }

    function updatePreview() {
        if (!previewEl) return;
        var rules = collectRules();
        var parts = rules.map(function (rule) {
            var count = Math.max((rule.paymentNumberTo || 0) - (rule.paymentNumberFrom || 0) + 1, 1);
            var interval = rule.intervalValue + ' ' + rule.intervalUnit;
            if (!rule.coinAmount) {
                return count + '× free / ' + interval;
            }
            return count + '× ' + (rule.coinAmount / 100).toFixed(2) + ' UAH / ' + interval;
        });
        previewEl.textContent = parts.length ? parts.join(', then ') : '';
    }
    function applyTemplate(templateId) {
        var tpl = (cfg.templates || []).find(function (t) {
            return String(t.id) === String(templateId);
        });
        var catEl = document.getElementById('alb-cp-category');

        rulesBody.innerHTML = '';

        if (!tpl) {
            addRuleRow(0, cfg.priceCoins || 0, true);
            ruleIdx = 1;
            updatePreview();
            return;
        }

        if (catEl) catEl.value = tpl.category;

        (tpl.rules || []).forEach(function (rule, i) {
            addRuleRow(i, rule.priced === false ? 0 : (cfg.priceCoins || 0), i === 0);
            var row = rulesBody.querySelectorAll('.alb-cp-rule-row')[i];
            if (row) {
                row.querySelector('.alb-cp-f').value = rule.paymentNumberFrom || 1;
                row.querySelector('.alb-cp-t').value = rule.paymentNumberTo || 999;
                row.querySelector('.alb-cp-iv').value = rule.intervalValue || 1;
                row.querySelector('.alb-cp-iu').value = rule.intervalUnit || 'MONTH';
                if (rule.priced === false) {
                    var ca = row.querySelector('.alb-cp-ca');
                    ca.value = 0;
                    ca.disabled = true;
                    ca.title = 'This rule is free per the template.';
                }
            }
        });
        ruleIdx = (tpl.rules || []).length;
        updatePreview();
    }

    function startReplacePlan(link) {
        var nameEl = document.getElementById('alb-cp-name');
        var catEl = document.getElementById('alb-cp-category');

        oldPlanIdEl.value = link.getAttribute('data-plan-id') || '';
        if (nameEl) nameEl.value = (link.getAttribute('data-plan-name') || '') + ' (new price)';
        if (catEl) catEl.value = link.getAttribute('data-plan-category') || catEl.value;

        var rules = [];
        try {
            rules = JSON.parse(link.getAttribute('data-plan-rules') || '[]');
        } catch (e) {
            rules = [];
        }

        rulesBody.innerHTML = '';
        if (rules.length) {
            rules.forEach(function (rule, i) {
                addRuleRow(i, rule.coinAmount, i === 0);
                var row = rulesBody.querySelectorAll('.alb-cp-rule-row')[i];
                if (row && rule) {
                    row.querySelector('.alb-cp-f').value = rule.paymentNumberFrom || 1;
                    row.querySelector('.alb-cp-t').value = rule.paymentNumberTo || 999;
                    row.querySelector('.alb-cp-iv').value = rule.intervalValue || 1;
                    row.querySelector('.alb-cp-iu').value = rule.intervalUnit || 'MONTH';
                }
            });
            ruleIdx = rules.length;
        } else {
            addRuleRow(0, cfg.priceCoins || 0, true);
            ruleIdx = 1;
        }

        if (heading) heading.textContent = i18n.replacePlanHeading || 'Replace Plan (new price for new customers)';
        if (replaceNotice) replaceNotice.style.display = 'block';
        if (btnSubmit) btnSubmit.textContent = i18n.replacePlan || 'Create Replacement Plan';

        wrap.style.display = 'block';
        hideMessage();
        updatePreview();
    }

    function startRepricePlan(link) {
        repricePlanIdEl.value = link.getAttribute('data-plan-id') || '';

        var rules = [];
        try {
            rules = JSON.parse(link.getAttribute('data-plan-rules') || '[]');
        } catch (e) {
            rules = [];
        }

        rulesBody.innerHTML = '';
        if (rules.length) {
            rules.forEach(function (rule, i) {
                addRuleRow(i, rule.coinAmount, i === 0);
                var row = rulesBody.querySelectorAll('.alb-cp-rule-row')[i];
                if (row && rule) {
                    row.querySelector('.alb-cp-f').value = rule.paymentNumberFrom || 1;
                    row.querySelector('.alb-cp-t').value = rule.paymentNumberTo || 999;
                    row.querySelector('.alb-cp-iv').value = rule.intervalValue || 1;
                    row.querySelector('.alb-cp-iu').value = rule.intervalUnit || 'MONTH';
                }
            });
            ruleIdx = rules.length;
        } else {
            addRuleRow(0, cfg.priceCoins || 0, true);
            ruleIdx = 1;
        }

        if (heading) {
            heading.textContent = i18n.repricePlanHeading || 'Reprice Plan for All Subscribers';
        }

        if (notifyRow) {
            notifyRow.style.display = 'block';
        }

        if (notifiedCheckbox) {
            notifiedCheckbox.checked = false;
        }

        if (nameFieldRow) {
            nameFieldRow.style.display = 'none';
        }

        if (categoryFieldRow) {
            categoryFieldRow.style.display = 'none';
        }

        if (btnSubmit) {
            btnSubmit.textContent = i18n.repricePlan || 'Apply New Price to All Subscribers';
        }

        if (affectedCountEl) {
            affectedCountEl.textContent = i18n.loadingCount || 'Loading affected subscribers…';
            let url = (cfg.subscribersCountUrlBase || '')
                + encodeURIComponent(repricePlanIdEl.value)
                + '/subscribers-count';

            fetch(url, {
                headers: {'X-WP-Nonce': cfg.nonce || ''},
            })
                .then(function (r) {
                    return r.json();
                }).then(function (res) {
                var count = (res && res.ok) ? res.count : '?';
                affectedCountEl.textContent = (i18n.affectedCountPrefix || 'This will affect ') + count
                    + (i18n.affectedCountSuffix || ' active subscription(s).');
            }).catch(function () {
                affectedCountEl.textContent = '';
            });
        }

        wrap.style.display = 'block';
        hideMessage();
        updatePreview();
    }

    function resetReplaceMode() {
        if (oldPlanIdEl) {
            oldPlanIdEl.value = '';
        }
        if (repricePlanIdEl) {
            repricePlanIdEl.value = '';
        }
        if (heading) {
            heading.textContent = i18n.createPlanHeading || 'Create New Subscription Plan';
        }
        if (replaceNotice) {
            replaceNotice.style.display = 'none';
        }
        if (notifyRow) {
            notifyRow.style.display = 'none';
        }
        if (nameFieldRow) {
            nameFieldRow.style.display = '';
        }
        if (categoryFieldRow) {
            categoryFieldRow.style.display = '';
        }
        if (templateSelect) {
            templateSelect.value = '';
        }
        if (btnSubmit) {
            btnSubmit.textContent = i18n.createPlan || 'Create Plan';
        }
    }


    async function submitCreatePlan() {
        hideMessage();

        var productId = document.getElementById('alb-cp-product-id');
        var nameEl = document.getElementById('alb-cp-name');
        var descEl = document.getElementById('alb-cp-description');
        var catEl = document.getElementById('alb-cp-category');
        var fromEl = document.getElementById('alb-cp-from');
        var toEl = document.getElementById('alb-cp-to');
        var isReplace = !!(oldPlanIdEl && oldPlanIdEl.value);
        var isReprice = !!(repricePlanIdEl && repricePlanIdEl.value);

        if (!isReprice && (!nameEl || !nameEl.value.trim())) {
            showMessage((i18n.errorPrefix || 'Error: ') + 'Plan Name is required.', true);
            nameEl && nameEl.focus();
            return;
        }

        var rules = collectRules();
        if (!rules.length) {
            showMessage((i18n.errorPrefix || 'Error: ') + 'At least one payment rule is required.', true);
            return;
        }

        if (isReprice && (!notifiedCheckbox || !notifiedCheckbox.checked)) {
            showMessage(
                (i18n.errorPrefix || 'Error: ')
                + (i18n.mustConfirmNotified || 'Please confirm affected customers have been notified.'),
                true
            );
            return;
        }

        if (isReprice && (!fromEl || !fromEl.value)) {
            showMessage(
                (i18n.errorPrefix || 'Error: ') + (i18n.appliesFromRequired || '"Applies From" is required for a price change.'),
                true
            );
            return;
        }

        var payload, url;

        if (isReprice) {
            payload = {
                plan_id: repricePlanIdEl.value,
                rules: rules,
                applies_date_time_from: fromEl.value,
                customers_notified: true,
                reason: descEl ? descEl.value.trim() : '',
            };
            url = cfg.repriceRestUrl;
        } else {
            payload = {
                product_id: parseInt(productId ? productId.value : '0', 10),
                plan_name: nameEl.value.trim(),
                plan_description: descEl ? descEl.value.trim() : '',
                category_subcategory_indicator: catEl ? catEl.value : 'C101',
                applies_date_time_from: fromEl ? fromEl.value : '',
                applies_date_time_to: toEl ? toEl.value : '',
                rules: rules,
                template_id: templateSelect ? (templateSelect.value || null) : null,
            };
            if (isReplace) {
                payload.old_plan_id = oldPlanIdEl.value;
            }
            url = isReplace ? (cfg.replaceRestUrl || cfg.restUrl) : cfg.restUrl;
        }

        btnSubmit.disabled = true;
        btnSubmit.textContent = i18n.creating || 'Working...';

        try {
            var response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cfg.nonce || '',
                },
                body: JSON.stringify(payload),
            });

            var data = await response.json();

            if (!response.ok || !data.ok) {
                var msg = (data && data.message) ? data.message : ('HTTP ' + response.status);
                showMessage((i18n.errorPrefix || 'Error: ') + msg, true);
                return;
            }

            if (isReprice) {
                showMessage(
                    (i18n.planRepriced || 'New price applied! Affected subscriptions: ') + (data.affected_subscriptions || 0),
                    false
                );
            } else {
                appendPlanOption(data.plan_id, data.plan_name, data.rules_summary || '');
                showMessage(
                    isReplace
                        ? (i18n.planReplaced || 'Replacement plan created! The original plan is now deactivated for new customers.')
                        : (i18n.planCreated || 'Plan created and attached to product!'),
                    false
                );
            }

            if (nameEl) nameEl.value = cfg.productName || '';
            if (descEl) descEl.value = '';
            if (fromEl) fromEl.value = '';
            if (toEl) toEl.value = '';
            resetReplaceMode();

            rulesBody.innerHTML = '';
            ruleIdx = 1;
            addRuleRow(0, cfg.priceCoins || 0, true);

        } catch (err) {
            showMessage((i18n.errorPrefix || 'Error: ') + (err.message || 'Network error'), true);
        } finally {
            btnSubmit.disabled = false;
            btnSubmit.textContent = isReprice
                ? (i18n.repricePlan || 'Apply New Price to All Subscribers')
                : (isReplace ? (i18n.replacePlan || 'Create Replacement Plan') : (i18n.createPlan || 'Create Plan'));
        }
    }


    document.addEventListener('DOMContentLoaded', function () {
        btnOpen = document.getElementById('alb-open-create-plan');
        btnCancel = document.getElementById('alb-cp-cancel');
        btnSubmit = document.getElementById('alb-cp-submit');
        btnAddRule = document.getElementById('alb-cp-add-rule');
        wrap = document.getElementById('alb-create-plan-wrap');
        rulesBody = document.getElementById('alb-cp-rules-body');
        message = document.getElementById('alb-cp-message');
        planSelect = document.getElementById('_alb_subscription_plan_ids');
        oldPlanIdEl = document.getElementById('alb-cp-old-plan-id');
        heading = document.getElementById('alb-cp-heading');
        replaceNotice = document.getElementById('alb-cp-replace-notice');
        repricePlanIdEl = document.getElementById('alb-cp-reprice-plan-id');
        notifyRow = document.getElementById('alb-cp-notify-row');
        notifiedCheckbox = document.getElementById('alb-cp-notified');
        affectedCountEl = document.getElementById('alb-cp-affected-count');

        var nameEl0 = document.getElementById('alb-cp-name');
        var catEl0 = document.getElementById('alb-cp-category');
        nameFieldRow = nameEl0 ? nameEl0.closest('tr') : null;
        categoryFieldRow = catEl0 ? catEl0.closest('tr') : null;
        templateSelect = document.getElementById('alb-cp-template');
        previewEl = document.getElementById('alb-cp-preview');

        if (!btnOpen || !wrap) return;

        btnOpen.addEventListener('click', function () {
            resetReplaceMode();
            wrap.style.display = wrap.style.display === 'none' ? 'block' : 'none';
            hideMessage();
        });

        templateSelect && templateSelect.addEventListener('change', function () {
            applyTemplate(templateSelect.value);
        });

        rulesBody && rulesBody.addEventListener('input', updatePreview);
        rulesBody && rulesBody.addEventListener('change', updatePreview);

        btnCancel && btnCancel.addEventListener('click', function () {
            wrap.style.display = 'none';
            resetReplaceMode();
            hideMessage();
        });

        document.addEventListener('click', function (e) {
            var replaceLink = e.target.closest('.alb-replace-plan');
            if (replaceLink) {
                e.preventDefault();
                startReplacePlan(replaceLink);
                return;
            }

            var repriceLink = e.target.closest('.alb-reprice-plan');
            if (repriceLink) {
                e.preventDefault();
                startRepricePlan(repriceLink);
            }
        });

        btnAddRule && btnAddRule.addEventListener('click', function () {
            addRuleRow(ruleIdx, cfg.priceCoins || 0, false);
            ruleIdx++;
            updatePreview();
        });

        rulesBody && rulesBody.addEventListener('click', function (e) {
            if (e.target.classList.contains('alb-cp-remove-rule') && !e.target.disabled) {
                var row = e.target.closest('tr');
                if (row) row.remove();
                updatePreview();
            }
        });

        btnSubmit && btnSubmit.addEventListener('click', function () {
            submitCreatePlan();
        });
    });
}());
