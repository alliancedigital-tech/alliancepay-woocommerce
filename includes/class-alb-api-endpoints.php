<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

/**
 * Class ApiEndpoints.
 */
class ApiEndpoints
{
    public const ENDPOINT_CREATE_ORDER = '/ecom/jws/payments/hpp/create_order_v1';

    public const ENDPOINT_OPERATIONS = '/ecom/jws/payments/hpp/operations_v1';

    public const ENDPOINT_CREATE_REFUND = '/ecom/jws/payments/refund_v1';

    public const ENDPOINT_PREAUTH_CREATE = '/ecom/jws/payments/preauth/create_v1';

    public const ENDPOINT_PREAUTH_COMPLETION = '/ecom/jws/payments/preauth/completion_v1';

    public const ENDPOINT_PUBLIC_KEY = '/ecom/keys/public_key/get_v1';

    public const ENDPOINT_PLAN_CREATE = '/ecom/jws/scheduled_payments/plan/create_v1';

    public const ENDPOINT_PLAN_UPDATE = '/ecom/jws/scheduled_payments/plan/update_v1';

    public const ENDPOINT_PLAN_STATUS_UPDATE = '/ecom/jws/scheduled_payments/plan/status_update_v1';

    public const ENDPOINT_PLAN_VERSION_UPDATE = '/ecom/jws/scheduled_payments/plan/version/update_v1';

    public const ENDPOINT_VERSION_DELETE = '/ecom/jws/scheduled_payments/version/delete_v1';

    public const ENDPOINT_SUB_ORDER_CREATE = '/ecom/jws/scheduled_payments/subscription-orders/create_v1';

    public const ENDPOINT_SUB_ORDER_CANCEL = '/ecom/jws/scheduled_payments/subscription-orders/cancel_v1';

    public const ENDPOINT_INIT_SUBSCRIPTION = '/ecom/jws/scheduled_payments/payments/initial_subscription_v1';

    public const ENDPOINT_RECURRING_SUBSCRIPTION = '/ecom/jws/scheduled_payments/payments/recurring_subscription_v1';
}
