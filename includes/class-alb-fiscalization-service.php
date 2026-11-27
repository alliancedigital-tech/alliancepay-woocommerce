<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Fiscalization_Service.
 *
 * Фіскалізація успішних PURCHASE і REFUND звичайних замовлень (рекурентні платежі підписок
 * та інші типи операцій не фіскалізуються).
 */
class ALB_Fiscalization_Service
{
    public const REFUND_MAP_META = '_alb_fiscal_refund_map';

    private ALB_Fiscalization_Repository $repository;

    private ALB_Fiscalization_Resolver $resolver;

    public function __construct(?ALB_Fiscalization_Repository $repository = null, ?ALB_Fiscalization_Resolver $resolver = null)
    {
        $this->repository = $repository ?? new ALB_Fiscalization_Repository();
        $this->resolver = $resolver ?? new ALB_Fiscalization_Resolver();
    }

    /**
     * Хуки авто-фіскалізації. Події генерують callback платежу та потік повернення.
     */
    public static function init(): void
    {
        add_action('alb_payment_succeeded', [__CLASS__, 'on_payment_succeeded'], 10, 2);
        add_action('alb_refund_operation_recorded', [__CLASS__, 'on_refund_recorded'], 10, 4);
    }

    // ------------------------------------------------------------------ eligibility

    /**
     * @param \WC_Order $order
     * @return bool
     */
    public static function is_order_eligible(\WC_Order $order): bool
    {
        if ($order->get_payment_method() !== AllianceConfig::ALLIANCE_PAYMENT_CODE) {
            return false;
        }

        if ($order->get_meta('_alb_subscription_mode') === 'subscribe') {
            return false;
        }

        if (class_exists('\ALB\ALB_Subscription_Recurring')
            && ALB_Subscription_Recurring::get_subscription_by_order_id($order->get_id())
        ) {
            return false;
        }

        return true;
    }

    /**
     * Успішна операція PURCHASE замовлення.
     *
     * @param int $orderId
     * @return array|null Операція (operationId, coinAmount, ...).
     */
    public function get_purchase_operation(int $orderId): ?array
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_checkout_integration_order();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d", $orderId));

        if (!$row || $row->hpp_pay_type !== AllianceConfig::HPP_PAY_TYPE_PURCHASE) {
            return null;
        }

        $data = json_decode((string)$row->callback_data, true);
        $operations = [];
        if (is_array($data)) {
            $operations = $data['operations'] ?? (isset($data['operation']) ? [$data['operation']] : []);
        }

        foreach ($operations as $op) {
            if (($op['type'] ?? '') === AllianceConfig::OPERATION_TYPE_PURCHASE
                && ($op['status'] ?? '') === AllianceConfig::ORDER_STATUS_SUCCESS
                && !empty($op['operationId'])
            ) {
                return $op;
            }
        }

        if ($row->order_status === AllianceConfig::ORDER_STATUS_SUCCESS && !empty($row->operation_id)) {
            return ['operationId' => $row->operation_id, 'coinAmount' => (int)$row->coin_amount, 'type' => 'PURCHASE'];
        }

        return null;
    }

    /**
     * Успішні операції повернення замовлення.
     *
     * @param int $orderId
     * @return object[] Рядки таблиці повернень.
     */
    public function get_refund_rows(int $orderId): array
    {
        global $wpdb;
        $table = AllianceConfig::table_alliance_integration_order_refund();

        $rows = (array)$wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d ORDER BY refund_id ASC", $orderId)
        );

        return array_values(array_filter($rows, static fn($r) => !empty($r->operation_id)));
    }

    // ------------------------------------------------------------------ auto triggers

    /**
     * @param int $orderId
     * @param string $operationId
     * @return void
     */
    public static function on_payment_succeeded($orderId, $operationId): void
    {
        if (!ALB_Fiscalization_Settings::is_auto()) {
            return;
        }

        self::run_after_response(static function () use ($orderId, $operationId) {
            $order = wc_get_order((int)$orderId);
            if ($order && self::is_order_eligible($order)) {
                (new self())->fiscalize((int)$orderId, (string)$operationId, AllianceConfig::OPERATION_TYPE_PURCHASE);
            }
        });
    }

    /**
     * @param int $orderId
     * @param string $operationId
     * @param string $status Статус операції повернення.
     * @param int $wcRefundId 0, якщо повернення без об'єкта WC (зміна статусу замовлення).
     * @return void
     */
    public static function on_refund_recorded($orderId, $operationId, $status, $wcRefundId = 0): void
    {
        $order = wc_get_order((int)$orderId);
        if (!$order || !self::is_order_eligible($order)) {
            return;
        }

        if ((int)$wcRefundId > 0) {
            $map = (array)json_decode((string)$order->get_meta(self::REFUND_MAP_META), true);
            $map[(string)$operationId] = (int)$wcRefundId;
            $order->update_meta_data(self::REFUND_MAP_META, wp_json_encode($map));
            $order->save_meta_data();
        }

        if ($status !== AllianceConfig::ORDER_STATUS_SUCCESS || !ALB_Fiscalization_Settings::is_auto()) {
            return;
        }

        self::run_after_response(static function () use ($orderId, $operationId) {
            (new self())->fiscalize((int)$orderId, (string)$operationId, AllianceConfig::OPERATION_TYPE_REFUND);
        });
    }

    /**
     * Виконати після відправки відповіді, щоб помилка/затримка фіскалізації не ламала платіж чи повернення.
     *
     * @param callable $job
     * @return void
     */
    private static function run_after_response(callable $job): void
    {
        add_action('shutdown', static function () use ($job) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                $job();
            } catch (\Throwable $e) {
                self::log('error', 'auto fiscalization failed: ' . $e->getMessage());
            }
        }, 20);
    }

    // ------------------------------------------------------------------ sending

    /**
     * Фіскалізувати операцію (авто або кнопка). Не відправляє повторно PENDING/SUCCESS.
     *
     * @param int $orderId
     * @param string $operationId
     * @param string $operationType PURCHASE|REFUND
     * @return array{ok:bool, message:string, status?:string}
     */
    public function fiscalize(int $orderId, string $operationId, string $operationType): array
    {
        if (!ALB_Fiscalization_Settings::is_enabled()) {
            return $this->result(false, __('Fiscalization is disabled in settings.', 'alliancepay'));
        }

        $order = wc_get_order($orderId);
        if (!$order || !self::is_order_eligible($order)) {
            return $this->result(false, __('This order cannot be fiscalized (not an AlliancePay one-time payment).', 'alliancepay'));
        }

        $existing = $this->repository->find_by_operation_id($operationId);
        if ($existing && in_array($existing->status, [AllianceConfig::FISCAL_STATUS_PENDING, AllianceConfig::FISCAL_STATUS_SUCCESS], true)) {
            return $this->result(true, __('Fiscalization already exists for this operation.', 'alliancepay'), $existing->status);
        }
        if ($existing && $existing->status === AllianceConfig::FISCAL_STATUS_FAIL) {
            return $this->result(
                false,
                __('Fiscalization of this operation has failed in Alliance Kasa; a repeated request returns the same result. Use "Refresh status".', 'alliancepay'),
                $existing->status
            );
        }

        try {
            $built = $this->build_request($order, $operationId, $operationType);
        } catch (\Throwable $e) {
            self::log('error', 'build request failed: ' . $e->getMessage(), ['order_id' => $orderId]);

            return $this->result(false, $e->getMessage());
        }

        return $this->send($order, $operationId, $operationType, $built['payload'], $built['comment']);
    }

    /**
     * «Оновити статус»: повторно надсилає той самий запит (Ecom ідемпотентний за operationId).
     *
     * @param int $orderId
     * @param string $operationId
     * @return array{ok:bool, message:string, status?:string}
     */
    public function refresh(int $orderId, string $operationId): array
    {
        $row = $this->repository->find_by_operation_id($operationId);
        $order = wc_get_order($orderId);

        if (!$row || !$order || (int)$row->order_id !== $orderId) {
            return $this->result(false, __('Fiscalization record not found.', 'alliancepay'));
        }

        $payload = json_decode((string)$row->request_payload, true);
        if (!is_array($payload) || empty($payload)) {
            return $this->result(false, __('Stored request is empty; use "Fiscalize".', 'alliancepay'));
        }

        return $this->send($order, $operationId, (string)$row->operation_type, $payload, (string)$row->receipt_comment);
    }

    /**
     * @param \WC_Order $order
     * @param string $operationId
     * @param string $operationType
     * @param array $payload
     * @param string $comment
     * @return array
     */
    private function send(\WC_Order $order, string $operationId, string $operationType, array $payload, string $comment): array
    {
        $base = [
            'order_id' => $order->get_id(),
            'store_id' => get_current_blog_id(),
            'operation_type' => $operationType,
            'merchant_request_id' => (string)$payload['merchantRequestId'],
            'receipt_comment' => $comment !== '' ? $comment : null,
            'request_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];

        self::log('info', 'request', ['operationId' => $operationId, 'payload' => $payload]);

        try {
            $client = new AlbHppClient($this->client_options());
            $response = $client->create_fiscalization($payload);
        } catch (\Throwable $e) {
            return $this->store_local_error($order, $operationId, $base, $e);
        }

        self::log('info', 'response', ['operationId' => $operationId, 'response' => $response]);

        if (empty($response['status'])) {
            $text = (string)($response['msgText'] ?? $response['errorDescription'] ?? __('Unexpected response from AlliancePay.', 'alliancepay'));

            return $this->store_local_error($order, $operationId, $base, new \RuntimeException($text), (string)($response['msgCode'] ?? ''));
        }

        $fields = $this->map_payload_fields($response);
        $fields['response_payload'] = wp_json_encode($response, JSON_UNESCAPED_UNICODE);
        $this->repository->upsert($operationId, array_merge($base, $fields));

        $this->add_status_note($order, $response);

        return $this->result(
            $fields['status'] !== AllianceConfig::FISCAL_STATUS_FAIL,
            $this->status_message($response),
            $fields['status']
        );
    }

    /**
     * Помилки 400/5xx/таймаут/підпис — зберегти як локальний ERROR, не кидати далі.
     *
     * @param \WC_Order $order
     * @param string $operationId
     * @param array $base
     * @param \Throwable $e
     * @param string $msgCode
     * @return array
     */
    private function store_local_error(\WC_Order $order, string $operationId, array $base, \Throwable $e, string $msgCode = ''): array
    {
        $parsed = $this->parse_error($e->getMessage());
        $code = $msgCode !== '' ? $msgCode : $parsed['code'];
        $message = $parsed['message'];

        self::log('error', 'request failed', ['operationId' => $operationId, 'code' => $code, 'error' => $e->getMessage()]);

        $this->repository->upsert($operationId, array_merge($base, [
            'status' => AllianceConfig::FISCAL_STATUS_ERROR,
            'error_code' => $code !== '' ? substr($code, 0, 32) : null,
            'error_description' => $message,
        ]));

        $order->add_order_note(sprintf(
            /* translators: %s: error text */
            __('AlliancePay fiscalization error: %s', 'alliancepay'),
            $message
        ));

        return $this->result(false, $message, AllianceConfig::FISCAL_STATUS_ERROR);
    }

    /**
     * Розбір винятку клієнта: "API {path} HTTP 400: {json}".
     *
     * @param string $raw
     * @return array{code:string, message:string}
     */
    private function parse_error(string $raw): array
    {
        $code = '';
        $message = $raw;

        if (preg_match('/HTTP (\d{3}): (.*)$/s', $raw, $m)) {
            $json = json_decode($m[2], true);
            if (is_array($json) && !empty($json['msgCode'])) {
                $code = (string)$json['msgCode'];
                $attrs = $json['msgAttrs'] ?? [];
                $attrs = is_array($attrs) ? implode(', ', array_map('strval', $attrs)) : (string)$attrs;
                $message = match ($code) {
                    'missing_required_field' => sprintf(
                        /* translators: %s: field name */
                        __('Required field is missing: %s', 'alliancepay'),
                        $attrs
                    ),
                    'invalid_format' => sprintf(
                        /* translators: %s: field name */
                        __('Invalid field format: %s', 'alliancepay'),
                        $attrs
                    ),
                    'payment_not_found' => __('Payment operation not found in AlliancePay.', 'alliancepay'),
                    'original_receipt_not_found' => __('Original receipt not found: fiscalize the purchase first, then the refund.', 'alliancepay'),
                    default => (string)($json['msgText'] ?? $raw),
                };
            } else {
                $code = 'HTTP_' . $m[1];
                $message = sprintf(
                    /* translators: %s: HTTP code */
                    __('AlliancePay returned HTTP %s. Try again later.', 'alliancepay'),
                    $m[1]
                );
            }
        }

        return ['code' => $code, 'message' => $message];
    }

    // ------------------------------------------------------------------ callback

    /**
     * Обробка розібраного (JWS-перевіреного) callback-а фіскалізації.
     *
     * @param array $data
     * @return bool true — запис знайдено й оброблено (або проігноровано як застарілий).
     */
    public function handle_callback(array $data): bool
    {
        $operationId = (string)($data['operationId'] ?? '');
        $requestId = (string)($data['merchantRequestId'] ?? '');

        $row = $operationId !== '' ? $this->repository->find_by_operation_id($operationId) : null;
        if (!$row && $requestId !== '') {
            $row = $this->repository->find_by_merchant_request_id($requestId);
        }

        if (!$row) {
            self::log('warning', 'callback for unknown operation', ['operationId' => $operationId, 'merchantRequestId' => $requestId]);

            return false;
        }

        $newStatus = (string)($data['status'] ?? '');
        if ($newStatus === '') {
            self::log('warning', 'callback without status', ['operationId' => $row->operation_id]);

            return true;
        }

        // SUCCESS — фінальний, не перезаписується.
        if ($row->status === AllianceConfig::FISCAL_STATUS_SUCCESS && $newStatus !== AllianceConfig::FISCAL_STATUS_SUCCESS) {
            return true;
        }

        // Застарілі дані.
        $known = $this->known_modification_time($row);
        $incoming = ALB_Fiscalization_Repository::to_mysql_datetime($data['modificationDateTime'] ?? null);
        if ($known !== null && $incoming !== null && $incoming < $known) {
            self::log('info', 'stale callback ignored', ['operationId' => $row->operation_id]);

            return true;
        }

        $fields = $this->map_payload_fields($data);
        $fields['callback_payload'] = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        // Не затирати наявні значення порожніми.
        foreach ($fields as $k => $v) {
            if ($v === null && isset($row->{$k}) && $row->{$k} !== null && !in_array($k, ['error_code', 'error_description'], true)) {
                unset($fields[$k]);
            }
        }
        $this->repository->upsert((string)$row->operation_id, $fields);

        $order = wc_get_order((int)$row->order_id);
        if ($order && $row->status !== $newStatus) {
            $this->add_status_note($order, $data);
        }

        return true;
    }

    /**
     * @param object $row
     * @return string|null UTC datetime
     */
    private function known_modification_time(object $row): ?string
    {
        foreach (['callback_payload', 'response_payload'] as $col) {
            $payload = json_decode((string)($row->{$col} ?? ''), true);
            if (is_array($payload) && !empty($payload['modificationDateTime'])) {
                return ALB_Fiscalization_Repository::to_mysql_datetime($payload['modificationDateTime']);
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array $p Відповідь/callback (стійко до відсутніх полів).
     * @return array
     */
    private function map_payload_fields(array $p): array
    {
        $status = strtoupper((string)($p['status'] ?? ''));
        $allowed = [
            AllianceConfig::FISCAL_STATUS_PENDING,
            AllianceConfig::FISCAL_STATUS_SUCCESS,
            AllianceConfig::FISCAL_STATUS_FAIL,
        ];

        $fields = [
            'status' => in_array($status, $allowed, true) ? $status : AllianceConfig::FISCAL_STATUS_ERROR,
            'bucket_id' => isset($p['bucketId']) ? (string)$p['bucketId'] : null,
            'fiscal_number' => isset($p['fiscalNumber']) ? (string)$p['fiscalNumber'] : null,
            'receipt_url' => isset($p['receiptUrl']) ? (string)$p['receiptUrl'] : null,
            'fiscalization_date_time' => $p['fiscalizationDateTime'] ?? null,
            'fiscalization_modification_date_time' => $p['fiscalizationModificationDateTime'] ?? null,
            'error_code' => isset($p['errorCode']) ? substr((string)$p['errorCode'], 0, 32) : null,
            'error_description' => isset($p['errorDescription']) ? (string)$p['errorDescription'] : null,
        ];

        if (!empty($p['operationType'])) {
            $fields['operation_type'] = (string)$p['operationType'];
        }

        return $fields;
    }

    /**
     * @param \WC_Order $order
     * @param array $p
     * @return void
     */
    private function add_status_note(\WC_Order $order, array $p): void
    {
        $status = strtoupper((string)($p['status'] ?? ''));

        if ($status === AllianceConfig::FISCAL_STATUS_SUCCESS) {
            $order->add_order_note(sprintf(
                /* translators: %s: fiscal number */
                __('Receipt fiscalized, fiscal number: %s', 'alliancepay'),
                (string)($p['fiscalNumber'] ?? '—')
            ));
        } elseif ($status === AllianceConfig::FISCAL_STATUS_FAIL) {
            $order->add_order_note(sprintf(
                /* translators: 1: error code, 2: error description */
                __('Fiscalization error: %1$s, %2$s', 'alliancepay'),
                (string)($p['errorCode'] ?? '—'),
                (string)($p['errorDescription'] ?? '—')
            ));
        } elseif ($status === AllianceConfig::FISCAL_STATUS_PENDING) {
            $order->add_order_note(__('Fiscalization request accepted, awaiting result from Alliance Kasa.', 'alliancepay'));
        }
    }

    /**
     * @param array $p
     * @return string
     */
    private function status_message(array $p): string
    {
        $status = strtoupper((string)($p['status'] ?? ''));

        return match ($status) {
            AllianceConfig::FISCAL_STATUS_SUCCESS => sprintf(
                /* translators: %s: fiscal number */
                __('Receipt fiscalized, fiscal number: %s', 'alliancepay'),
                (string)($p['fiscalNumber'] ?? '—')
            ),
            AllianceConfig::FISCAL_STATUS_FAIL => sprintf(
                /* translators: 1: error code, 2: error description */
                __('Fiscalization error: %1$s, %2$s', 'alliancepay'),
                (string)($p['errorCode'] ?? '—'),
                (string)($p['errorDescription'] ?? '—')
            ),
            default => __('Fiscalization request accepted, awaiting result from Alliance Kasa.', 'alliancepay'),
        };
    }

    /**
     * @return array
     */
    private function client_options(): array
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $opt = is_array($opt) ? $opt : [];
        $opt['baseUrl'] = $opt['baseUrl'] ?? ALB_HPP_PROD_BASE;
        $opt['apiVersion'] = $opt['apiVersion'] ?? AllianceConfig::DEFAULT_API_VERSION;

        return $opt;
    }

    /**
     * @param bool $ok
     * @param string $message
     * @param string|null $status
     * @return array
     */
    private function result(bool $ok, string $message, ?string $status = null): array
    {
        $r = ['ok' => $ok, 'message' => $message];
        if ($status !== null) {
            $r['status'] = $status;
        }

        return $r;
    }

    // ------------------------------------------------------------------ request building

    /**
     * Сума в копійках. Єдина точка розширення для інших валют (conversionRate).
     * TODO: підтримка не-UAH замовлень (потрібен conversionRate з рядка alliance_checkout_integration_order).
     *
     * @param float $amount
     * @param \WC_Order $order
     * @return int
     */
    public static function to_coins(float $amount, \WC_Order $order): int
    {
        $coins = (int)round($amount * 100);

        return (int)apply_filters('alb_fiscalization_to_coins', $coins, $amount, $order);
    }

    /**
     * @param \WC_Order $order
     * @param string $operationId
     * @param string $operationType
     * @return array{payload:array, comment:string}
     * @throws \RuntimeException Локальна помилка валідації (текст для адміністратора).
     */
    public function build_request(\WC_Order $order, string $operationId, string $operationType): array
    {
        if ($order->get_currency() !== 'UAH') {
            throw new \RuntimeException(
                __('Fiscalization is currently supported only for orders in UAH.', 'alliancepay')
            );
        }

        $opt = get_option(ALB_HPP_OPT, []);
        $merchantId = trim((string)($opt['merchantId'] ?? ''));
        if ($merchantId === '') {
            throw new \RuntimeException(__('MerchantId is not set in the plugin settings.', 'alliancepay'));
        }

        $notificationUrl = AllianceConfig::get_fiscalization_notification_url();
        if (!preg_match('#^https?://#i', $notificationUrl)) {
            throw new \RuntimeException(__('Fiscalization notification URL must be absolute.', 'alliancepay'));
        }

        if ($operationType === AllianceConfig::OPERATION_TYPE_PURCHASE) {
            $op = $this->get_purchase_operation($order->get_id());
            if (!$op || ($op['operationId'] ?? '') !== $operationId) {
                throw new \RuntimeException(__('Successful purchase operation not found for this order.', 'alliancepay'));
            }
            $target = (int)($op['coinAmount'] ?? 0);
            $positions = $this->positions_from_order($order);
            $comment = 'PAYMENT_FOR_ORDER_' . $order->get_order_number();
        } elseif ($operationType === AllianceConfig::OPERATION_TYPE_REFUND) {
            $refundRow = null;
            foreach ($this->get_refund_rows($order->get_id()) as $r) {
                if ($r->operation_id === $operationId) {
                    $refundRow = $r;
                    break;
                }
            }
            if (!$refundRow) {
                throw new \RuntimeException(__('Refund operation not found for this order.', 'alliancepay'));
            }
            if (strtoupper((string)$refundRow->status) !== AllianceConfig::ORDER_STATUS_SUCCESS) {
                throw new \RuntimeException(__('The refund operation is not successful yet.', 'alliancepay'));
            }
            $target = (int)$refundRow->coin_amount;
            $positions = $this->positions_for_refund($order, $operationId, $target);
            $comment = 'REFUND_FOR_ORDER_' . $order->get_order_number();
        } else {
            throw new \RuntimeException(__('Unsupported operation type for fiscalization.', 'alliancepay'));
        }

        if ($target <= 0) {
            throw new \RuntimeException(__('Operation amount is unknown.', 'alliancepay'));
        }

        $positions = $this->fit_to_amount($positions, $target);
        if (!$positions) {
            throw new \RuntimeException(__('The order has no items to fiscalize.', 'alliancepay'));
        }

        $products = [];
        $errors = [];
        $sum = 0;
        foreach ($positions as $pos) {
            $resolved = $pos['resolved'];
            $error = ALB_Fiscalization_Resolver::validate($resolved, $pos['name']);
            if ($error) {
                $errors[] = $error;
                continue;
            }

            $product = [
                'id' => (string)$pos['id'],
                'name' => (string)$pos['name'],
                'count' => round((float)$pos['count'], 3),
                'measurementCode' => $resolved['measurementCode'],
                // API приймає ціну в гривнях (float), а не в копійках; внутрішні розрахунки лишаються в копійках.
                'unitPrice' => round(((int)$pos['unitPrice']) / 100, 2),
            ];
            if ($resolved['taxCode'] !== null) {
                $product['taxCode'] = $resolved['taxCode'];
            }
            if (!empty($resolved['classificationCode'])) {
                $product['classificationCode'] = $resolved['classificationCode'];
            }
            if (!empty($resolved['exciseLabels'])
                && in_array($resolved['taxCode'], AllianceConfig::FISCAL_EXCISE_TAX_CODES, true)
            ) {
                $product['exciseLabels'] = array_values($resolved['exciseLabels']);
            }

            $sum += (int)round($product['count'] * (int)$pos['unitPrice']);
            $products[] = $product;
        }

        if ($errors) {
            throw new \RuntimeException(implode("\n", array_unique($errors)));
        }
        if ($sum !== $target) {
            throw new \RuntimeException(sprintf(
                /* translators: 1: items sum, 2: operation amount (kopecks) */
                __('Items total (%1$d) does not match the operation amount (%2$d).', 'alliancepay'),
                $sum,
                $target
            ));
        }

        return [
            'comment' => $comment,
            'payload' => [
                'merchantRequestId' => wp_generate_uuid4(),
                'merchantId' => $merchantId,
                'operationId' => $operationId,
                'receiptComment' => $comment,
                'notificationUrl' => $notificationUrl,
                'products' => $products,
            ],
        ];
    }

    /**
     * Позиції всього замовлення: товари, платна доставка і збори (за фактично сплаченими сумами).
     *
     * @param \WC_Order $order
     * @return array
     */
    private function positions_from_order(\WC_Order $order): array
    {
        $positions = [];

        foreach ($order->get_items('line_item') as $item) {
            /** @var \WC_Order_Item_Product $item */
            $total = (float)$item->get_total() + (float)$item->get_total_tax();
            $positions[] = $this->make_position(
                $order,
                (string)($item->get_variation_id() ?: $item->get_product_id()),
                $item->get_name(),
                (float)$item->get_quantity(),
                self::to_coins($total, $order),
                $item->get_product() ?: null
            );
        }

        foreach ($order->get_items('shipping') as $item) {
            $total = (float)$item->get_total() + (float)$item->get_total_tax();
            $positions[] = $this->make_position($order, 'shipping_' . $item->get_id(), $item->get_name(), 1.0, self::to_coins($total, $order), null);
        }

        foreach ($order->get_items('fee') as $item) {
            $total = (float)$item->get_total() + (float)$item->get_total_tax();
            $positions[] = $this->make_position($order, 'fee_' . $item->get_id(), $item->get_name(), 1.0, self::to_coins($total, $order), null);
        }

        return array_values(array_filter($positions));
    }

    /**
     * Позиції повернення: рядки об'єкта повернення WC; без нього — пропорційно всі позиції замовлення.
     *
     * @param \WC_Order $order
     * @param string $operationId
     * @param int $target
     * @return array
     */
    private function positions_for_refund(\WC_Order $order, string $operationId, int $target): array
    {
        $map = (array)json_decode((string)$order->get_meta(self::REFUND_MAP_META), true);
        $refund = isset($map[$operationId]) ? wc_get_order((int)$map[$operationId]) : null;

        if ($refund instanceof \WC_Order_Refund) {
            $positions = [];

            foreach ($refund->get_items('line_item') as $item) {
                $qty = abs((float)$item->get_quantity());
                $original = $order->get_item((int)$item->get_meta('_refunded_item_id'));
                $total = abs((float)$item->get_total() + (float)$item->get_total_tax());
                if ($qty <= 0 || !$original instanceof \WC_Order_Item_Product) {
                    continue;
                }
                $positions[] = $this->make_position(
                    $order,
                    (string)($original->get_variation_id() ?: $original->get_product_id()),
                    $original->get_name(),
                    $qty,
                    self::to_coins($total, $order),
                    $original->get_product() ?: null
                );
            }

            foreach (['shipping' => 'shipping_', 'fee' => 'fee_'] as $type => $prefix) {
                foreach ($refund->get_items($type) as $item) {
                    $total = abs((float)$item->get_total() + (float)$item->get_total_tax());
                    $positions[] = $this->make_position(
                        $order,
                        $prefix . (int)$item->get_meta('_refunded_item_id'),
                        $item->get_name(),
                        1.0,
                        self::to_coins($total, $order),
                        null
                    );
                }
            }

            $positions = array_values(array_filter($positions));
            if ($positions) {
                return $positions;
            }
        }

        // TODO: повернення «лише сумою» (без позицій) — розподіляємо суму пропорційно позиціям замовлення.
        $all = $this->positions_from_order($order);
        $sum = array_sum(array_map(static fn($p) => $p['total'], $all));
        if ($sum <= 0) {
            return [];
        }

        $scaled = [];
        foreach ($all as $p) {
            $coins = (int)round($p['total'] * $target / $sum);
            $scaled[] = $this->make_position($order, $p['id'], $p['name'], $p['count'], $coins, $p['product']);
        }

        return array_values(array_filter($scaled));
    }

    /**
     * @param \WC_Order $order
     * @param string $id
     * @param string $name
     * @param float $count
     * @param int $totalCoins Фактично оплачена сума позиції (копійки).
     * @param \WC_Product|null $product
     * @return array|null null для позицій з нульовою сумою.
     */
    private function make_position(\WC_Order $order, string $id, string $name, float $count, int $totalCoins, ?\WC_Product $product): ?array
    {
        if ($totalCoins <= 0 || $count <= 0) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'count' => round($count, 3),
            'total' => $totalCoins,
            'unitPrice' => (int)round($totalCoins / $count),
            'product' => $product,
            'resolved' => $this->resolver->resolve($product),
        ];
    }

    /**
     * Привести Σ(count × unitPrice) до суми операції. Ціле count ділиться без втрат на дві позиції
     * (n-r)×floor і r×(floor+1); решта розбіжності (округлення, збори) додається до останньої позиції.
     *
     * @param array $positions
     * @param int $target
     * @return array
     */
    private function fit_to_amount(array $positions, int $target): array
    {
        $expanded = [];
        foreach ($positions as $p) {
            $isInt = abs($p['count'] - round($p['count'])) < 0.0005;
            if ($isInt && $p['count'] > 1) {
                $n = (int)round($p['count']);
                $floor = intdiv($p['total'], $n);
                $r = $p['total'] - $floor * $n;
                if ($r === 0) {
                    $expanded[] = array_merge($p, ['unitPrice' => $floor]);
                } else {
                    $expanded[] = array_merge($p, ['count' => (float)($n - $r), 'unitPrice' => $floor]);
                    $expanded[] = array_merge($p, ['count' => (float)$r, 'unitPrice' => $floor + 1]);
                }
            } else {
                $expanded[] = $p;
            }
        }

        $sum = 0;
        foreach ($expanded as $p) {
            $sum += (int)round($p['count'] * $p['unitPrice']);
        }

        $diff = $target - $sum;
        if ($diff !== 0 && $expanded) {
            $lastKey = array_key_last($expanded);
            $last = $expanded[$lastKey];
            if (abs($last['count'] - 1.0) < 0.0005) {
                $expanded[$lastKey]['unitPrice'] = max(1, $last['unitPrice'] + $diff);
            } elseif ($last['count'] > 1 && abs($last['count'] - round($last['count'])) < 0.0005) {
                $expanded[$lastKey]['count'] = $last['count'] - 1.0;
                $expanded[] = array_merge($last, ['count' => 1.0, 'unitPrice' => max(1, $last['unitPrice'] + $diff)]);
            } else {
                $expanded[$lastKey]['unitPrice'] = max(1, (int)round(($last['count'] * $last['unitPrice'] + $diff) / $last['count']));
            }
        }

        return $expanded;
    }

    // ------------------------------------------------------------------ logging

    /**
     * Лог модуля з маскуванням чутливих даних (ключі, підписи, JWS-токени).
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $debug = defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG;
        if ($level === 'info' && !$debug) {
            return;
        }

        $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
        if (!is_dir($dir)) {
            @wp_mkdir_p($dir);
        }

        @file_put_contents(
            $dir . '/fiscalization.log',
            gmdate('c') . ' [' . strtoupper($level) . '] ' . $message
            . ($context ? ' ' . wp_json_encode(self::mask($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '')
            . PHP_EOL,
            FILE_APPEND
        );
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function mask($value)
    {
        $sensitive = '/key|secret|signature|token|authorization|password|jwk/i';

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = (is_string($k) && preg_match($sensitive, $k)) ? '***' : self::mask($v);
            }

            return $out;
        }

        if (is_string($value)) {
            return preg_replace('/[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]*/', '***jws***', $value);
        }

        return $value;
    }
}
