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
    public const ENDPOINT_CREATE_ORDER = '/ecom/execute_request/hpp/v1/create-order';

    public const ENDPOINT_OPERATIONS = '/ecom/execute_request/hpp/v1/operations';

    public const ENDPOINT_CREATE_REFUND = '/ecom/execute_request/payments/v3/refund';
}
