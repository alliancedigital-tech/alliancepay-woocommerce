<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

use SimpleJWT\JWT;
use SimpleJWT\Keys\ECKey;
use SimpleJWT\Keys\KeySet;
use SimpleJWT\InvalidTokenException;

if (!defined('ABSPATH')) exit;

/**
 * Class AlbHppClient.
 */
class AlbHppClient
{
    private string $baseUrl;

    private string $apiVersion;

    private string $merchantId;

    private string $jwsPrivateKey;

    private string $jwsKeyId;

    public function __construct(array $opt)
    {
        $this->baseUrl = rtrim($opt['baseUrl'] ?? ALB_HPP_PROD_BASE, '/');
        $this->apiVersion = $opt['apiVersion'] ?? AllianceConfig::DEFAULT_API_VERSION;
        $this->merchantId = $opt['merchantId'] ?? '';
        $this->jwsPrivateKey = $opt['jwsPrivateKey'] ?? '';
        $this->jwsKeyId = $opt['jwsKeyId'] ?? '';

        if (!$this->merchantId) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
                @file_put_contents(
                    $dir . '/debug.log',
                    __('MerchantId is not set in the plugin settings.', 'alliancepay') . PHP_EOL,
                    FILE_APPEND
                );
            }
            add_action('admin_notices', function () {
                echo '<div class="error"><p>AlliancePay: '
                    . __('MerchantId is not set in the plugin settings.', 'alliancepay')
                    . '</p></div>';
            });
        }

        if (!$this->jwsPrivateKey || !$this->jwsKeyId) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
                @file_put_contents(
                    $dir . '/debug.log',
                    __('JWS Private Key or JWS Key ID are not set in the plugin settings.', 'alliancepay') . PHP_EOL,
                    FILE_APPEND
                );
            }
            add_action('admin_notices', function () {
                echo '<div class="error"><p>AlliancePay: '
                    . __('JWS Private Key or JWS Key ID are not set in the plugin settings.', 'alliancepay')
                    . '</p></div>';
            });
        }
    }

    /**
     * @param array $body
     * @return array
     */
    public function create_hpp_order(array $body): array
    {
        return $this->http('POST', ApiEndpoints::ENDPOINT_CREATE_ORDER, $body);
    }

    /**
     * @param array $body
     * @return array
     */
    public function create_preauth_order(array $body): array
    {
        return $this->http('POST', ApiEndpoints::ENDPOINT_PREAUTH_CREATE, $body);
    }

    /**
     * @param array $body
     * @return array
     */
    public function complete_preauth(array $body): array
    {
        return $this->http('POST', ApiEndpoints::ENDPOINT_PREAUTH_COMPLETION, $body);
    }

    /**
     * @param array $payload
     * @return array
     */
    public function create_refund(array $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        return $this->http('POST', ApiEndpoints::ENDPOINT_CREATE_REFUND, $payload);
    }

    /**
     * @param string $hppOrderId
     * @return array
     */
    public function get_order(string $hppOrderId): array
    {
        return $this->http(
            'POST',
            ApiEndpoints::ENDPOINT_OPERATIONS,
            ['hppOrderId' => $hppOrderId]
        );
    }

    /**
     * Extract the operations list from an operations_v1 response (get_order()).
     * The bank returns a single `operation` object when there's only one
     * transaction on the order, or an `operations` array when there are
     * several (e.g. a subscription with multiple recurring charges) — this
     * normalizes both shapes into a plain list of operations.
     *
     * Only the fields the status pages need are kept: type, status,
     * coinAmount, operationId, receiptUrl.
     *
     * @param array $response Response from get_order().
     * @return array<int, array{type:string, status:string, coinAmount:mixed, operationId:string, receiptUrl:string}>
     */
    public static function extract_operations(array $response): array
    {
        $operations = $response['operations'] ?? null;
        if (!is_array($operations) || empty($operations)) {
            $operations = isset($response['operation']) && is_array($response['operation'])
                ? [$response['operation']]
                : [];
        }

        return array_map(static function ($op) {
            $op = is_array($op) ? $op : [];
            return [
                'type'        => $op['type'] ?? '',
                'status'      => $op['status'] ?? '',
                'coinAmount'  => $op['coinAmount'] ?? null,
                'operationId' => $op['operationId'] ?? '',
                'receiptUrl'  => $op['receiptUrl'] ?? '',
            ];
        }, array_values($operations));
    }

    /**
     * @param array $body
     * @return array
     */
    public function create_plan(array $body): array
    {
        return $this->http_jws('POST', ApiEndpoints::ENDPOINT_PLAN_CREATE, $body);
    }

    /**
     * @param array $body
     * @return array
     */
    public function update_plan(array $body): array
    {
        return $this->http_jws('POST', ApiEndpoints::ENDPOINT_PLAN_UPDATE, $body);
    }

    /**
     * @param array $body
     * @return array
     */
    public function plan_status_update(array $body): array
    {
        return $this->http_jws('POST', ApiEndpoints::ENDPOINT_PLAN_STATUS_UPDATE, $body);
    }

    /**
     * @param array $body
     * @return array
     */
    public function update_plan_version(array $body): array
    {
        return $this->http_jws('POST', ApiEndpoints::ENDPOINT_PLAN_VERSION_UPDATE, $body);
    }

    /**
     * @param array $body
     * @return array
     */
    public function delete_plan_version(array $body): array
    {
        return $this->http_jws('POST', ApiEndpoints::ENDPOINT_VERSION_DELETE, $body);
    }

    /**
     * @param array $body
     * @return array
     */
    public function create_subscription_order(array $body): array
    {
        return $this->http_jws('POST', ApiEndpoints::ENDPOINT_SUB_ORDER_CREATE, $body);
    }

    /**
     * @param string $hppOrderId
     * @return array
     */
    public function cancel_subscription_order(string $hppOrderId): array
    {
        return $this->http_jws('POST', ApiEndpoints::ENDPOINT_SUB_ORDER_CANCEL, ['hppOrderId' => $hppOrderId]);
    }

    /**
     * @param array $body
     * @return array
     */
    public function run_recurring_payment(array $body): array
    {
        return $this->http_jws('POST', ApiEndpoints::ENDPOINT_RECURRING_SUBSCRIPTION, $body);
    }

    /**
     * @param string $method
     * @param string $path
     * @param array $body
     * @return array
     */
    private function http_jws(string $method, string $path, array $body): array
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $privateKeyPem = trim((string)($opt['jwsPrivateKey'] ?? ''));
        $kid = trim((string)($opt['jwsKeyId'] ?? ''));

        if (!$privateKeyPem || !$kid) {
            throw new \RuntimeException(
                __('JWS Private Key or Key ID not configured in AlliancePay settings.', 'alliancepay')
            );
        }

        $signedToken = ALB_Authorize::sign_jws($body, $privateKeyPem, $kid, $path);

        if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
            $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
            if (!is_dir($dir)) @wp_mkdir_p($dir);
            @file_put_contents(
                $dir . '/debug.log',
                date('c') . " JWS signed request {$method} {$path} kid={$kid}" . PHP_EOL,
                FILE_APPEND
            );
        }

        $headers = [
            'Content-Type' => 'application/jwt',
            'Accept' => 'application/json',
            'x-request_id' => ALB_Generate_Request_Identification::generateRequestId(),
        ];

        return $this->send_signed_request($method, $path, $signedToken, $headers);
    }

    /**
     * @param string $method
     * @param string $path
     * @param array $body
     * @return array
     */
    private function http(
        string $method,
        string $path,
        array  $body
    ): array {
        $payloadJson = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $jwsToken = ALB_JWS_Signer::sign(
            $this->jwsPrivateKey,
            $this->jwsKeyId,
            $path,
            $payloadJson
        );

        $headers = [
            'Content-Type' => 'application/jws',
            'Accept' => 'application/json',
            'x-api_version' => $this->apiVersion,
            'x-request_id' => ALB_Generate_Request_Identification::generateRequestId(),
        ];

        return $this->send_signed_request($method, $path, $jwsToken, $headers);
    }

    /**
     * @param string $method
     * @param string $path
     * @param string $signedBody
     * @param array $headers
     * @return array
     */
    private function send_signed_request(
        string $method,
        string $path,
        string $signedBody,
        array  $headers
    ): array {
        $url = $this->baseUrl . $path;

        if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
            $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
            if (!is_dir($dir)) @wp_mkdir_p($dir);
            @file_put_contents(
                $dir . '/debug.log',
                date('c')
                . " BANK REQUEST {$method} {$url}"
                . " headers=" . wp_json_encode($headers, JSON_UNESCAPED_UNICODE)
                . " payload=" . $signedBody
                . PHP_EOL,
                FILE_APPEND
            );
        }

        $resp = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 20,
            'body' => $signedBody,
        ]);

        if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
            $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
            $reqId = wp_remote_retrieve_header($resp, 'x-request_id');
            @file_put_contents(
                $dir . '/debug.log',
                date('c')
                . " HTTP {$method} {$path} reqId={$reqId}"
                . " resp=" . wp_json_encode($resp)
                . PHP_EOL,
                FILE_APPEND
            );
        }

        if (is_wp_error($resp)) {
            throw new \RuntimeException($resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $rawBody = wp_remote_retrieve_body($resp);

        if ($code === 401) {
            throw new \RuntimeException(
                sprintf(
                    __('401 Unauthorized from API for %s. Verify JWS Private Key and Key ID settings.', 'alliancepay'),
                    $path
                )
            );
        }

        if ($code >= 300) {
            throw new \RuntimeException("API {$path} HTTP {$code}: {$rawBody}");
        }

        $trimmed = trim($rawBody);
        $dotCount = substr_count($trimmed, '.');
        $isToken = !empty($trimmed) && strpos($trimmed, ' ') === false;

        if ($isToken && $dotCount === 2) {
            $data = $this->verify_jws_response($trimmed, $path);
        } else {
            $data = json_decode($rawBody, true);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param string $jws
     * @param string $path
     * @return array
     */
    private function verify_jws_response(string $jws, string $path): array
    {
        try {
            $deserialised = JWT::deserialise($jws);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf(
                    __('Bank JWS response parse error for %1$s: %2$s', 'alliancepay'),
                    $path,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }

        $headers = $deserialised['signatures'][0]['headers'] ?? [];
        $kid = $headers['kid'] ?? '';
        $alg = $headers['alg'] ?? '';

        if (empty($kid) || empty($alg)) {
            throw new \RuntimeException(
                sprintf(
                    __('Bank JWS response is missing kid or alg header for %s', 'alliancepay'),
                    $path
                )
            );
        }

        $jwk = $this->fetch_bank_public_key($kid, $path);

        try {
            $key = new ECKey($jwk, 'php');
            $key->setKeyId($kid);

            $keySet = new KeySet();
            $keySet->add($key);

            $jwt = JWT::decode($jws, $keySet, $alg);
        } catch (InvalidTokenException $e) {
            if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
                $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
                if (!is_dir($dir)) @wp_mkdir_p($dir);
                @file_put_contents(
                    $dir . '/debug.log',
                    date('c') . " JWS verify failed {$path} kid={$kid}: " . $e->getMessage() . PHP_EOL,
                    FILE_APPEND
                );
            }
            throw new \RuntimeException(
                sprintf(
                    __('Bank JWS response verification failed for %1$s: %2$s', 'alliancepay'),
                    $path,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }

        return $jwt->getClaims();
    }

    /**
     * @param string $kid
     * @param string $path
     * @return array
     */
    private function fetch_bank_public_key(string $kid, string $path = ''): array
    {
        $cacheKey = 'alb_bank_pubkey_' . md5($kid);

        $cached = get_transient($cacheKey);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $url = $this->baseUrl . ApiEndpoints::ENDPOINT_PUBLIC_KEY;
        $body = wp_json_encode(['kid' => $kid], JSON_UNESCAPED_UNICODE);

        $resp = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'x-api_version' => $this->apiVersion,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($resp)) {
            throw new \RuntimeException(
                sprintf(
                    __('Cannot fetch bank public key (kid=%1$s): %2$s', 'alliancepay'),
                    $kid,
                    $resp->get_error_message()
                )
            );
        }

        $code = wp_remote_retrieve_response_code($resp);
        $rawBody = wp_remote_retrieve_body($resp);

        if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
            $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
            if (!is_dir($dir)) @wp_mkdir_p($dir);
            @file_put_contents(
                $dir . '/debug.log',
                date('c') . " fetch_bank_public_key kid={$kid} HTTP {$code}: " . $rawBody . PHP_EOL,
                FILE_APPEND
            );
        }

        if ($code !== 200) {
            throw new \RuntimeException(
                sprintf(
                    __('Cannot fetch bank public key (kid=%1$s): HTTP %2$d %3$s', 'alliancepay'),
                    $kid,
                    $code,
                    $rawBody
                )
            );
        }

        $jwk = json_decode($rawBody, true);

        if (
            !is_array($jwk)
            || empty($jwk['kty'])
            || empty($jwk['crv'])
            || empty($jwk['x'])
            || empty($jwk['y'])
        ) {
            throw new \RuntimeException(
                sprintf(
                    __('Invalid public key response from bank for kid=%s', 'alliancepay'),
                    $kid
                )
            );
        }

        set_transient($cacheKey, $jwk, HOUR_IN_SECONDS);

        return $jwk;
    }
}
