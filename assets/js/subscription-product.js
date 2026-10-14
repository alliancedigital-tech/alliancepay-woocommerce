/**
 * AlliancePay — Subscription product page JS.
 * Handles the toggle between one-time purchase and subscription plan selection.
 */
(function ($) {
    'use strict';

    function init() {
        var $radios  = $('input[name="alb_purchase_type"]');
        var $wrapper = $('.alb-plan-select-wrapper');
        var $select  = $('#alb_plan_id');

        if (!$radios.length) {
            return;
        }

        $radios.on('change', function () {
            if ($(this).val() === 'subscribe') {
                $wrapper.slideDown(200);
                $select.prop('required', true);
            } else {
                $wrapper.slideUp(200);
                $select.prop('required', false);
            }
        });

        var $checked = $radios.filter(':checked');
        if ($checked.val() === 'subscribe') {
            $wrapper.show();
        } else {
            $wrapper.hide();
        }

        $('form.cart').on('submit', function (e) {
            var mode = $radios.filter(':checked').val();
            if (mode === 'subscribe' && !$select.val()) {
                e.preventDefault();
                alert(albSubscription.selectPlanText || 'Please select a subscription plan.');
                return false;
            }
        });
    }

    $(document).ready(init);

})(jQuery);
