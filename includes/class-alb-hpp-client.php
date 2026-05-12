<?php
namespace ALB;

use ALB\ApiEndpoints;
use ALB\ALB_Authorize;

if (!defined('ABSPATH')) exit;

class AlbHppClient {
    private string $baseUrl;
    private string $apiVersion;
    private string $merchantId;
    private ?string $deviceId;
    private ?string $refreshToken;

    public function __construct(array $opt)
    {
        $this->baseUrl      = rtrim($opt['baseUrl'] ?? ALB_HPP_PROD_BASE,'/');
        $this->apiVersion   = $opt['apiVersion'] ?? 'v1';
        $this->merchantId   = $opt['merchantId'] ?? '';
        $this->deviceId     = $opt['deviceId'] ?? null;
        $this->refreshToken = $opt['refreshToken'] ?? null;

        if (!$this->merchantId) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
                @file_put_contents(
                    $dir . '/debug.log',
                    __( 'MerchantId is not set in the plugin settings.', 'alliancepay' ) . PHP_EOL,
                    FILE_APPEND
                );
            }
            add_action('admin_notices', function () {
                echo '<div class="error"><p>AlliancePay: '
                    .  __( 'MerchantId is not set in the plugin settings.', 'alliancepay' )
                    . '</p></div>';
            });
        }
        if (!$this->deviceId || !$this->refreshToken) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
                @file_put_contents(
                    $dir . '/debug.log',
                    __( 'DeviceId/refreshToken are not set in the plugin settings.', 'alliancepay' ) . PHP_EOL,
                    FILE_APPEND
                );
            }
            add_action('admin_notices', function () {
                echo '<div class="error"><p>AlliancePay: '
                    .  __( 'DeviceId/refreshToken are not set in the plugin settings.', 'alliancepay' )
                    . '</p></div>';
            });
        }
    }

    /** Create HPP order */
    public function create_hpp_order(array $body): array {
        $body['merchantId']     = $this->merchantId;
        $body['hppPayType']     = 'PURCHASE';
        $body['statusPageType'] = 'STATUS_PAGE';

        return $this->http('POST', ApiEndpoints::ENDPOINT_CREATE_ORDER, $body, true);
    }

    public function create_refund(string $payload): array
    {
        $result = [];
        if (!empty($payload)) {
            $headers = [
                'Content-Type' => 'plain/text'
            ];

            $result = $this->http(
                'POST',
                ApiEndpoints::ENDPOINT_CREATE_REFUND,
                $payload,
                true,
                1,
                $headers
            );
        }

        return $result;
    }

    /** Get order info by hppOrderId */
    public function get_order(string $hppOrderId): array {
        return $this->http('POST', ApiEndpoints::ENDPOINT_OPERATIONS, ['hppOrderId' => $hppOrderId], true);
    }

    private function http(
        string $method,
        string $path,
        array|string $body,
        bool $auth,
        int $attempt = 1,
        array $headers = []
    ): array {
        $maxAttempts = 3;

        if ($attempt > $maxAttempts) {
            throw new \RuntimeException( sprintf( __( 'Maximum number of attempts (%1$d) exceeded for request %2$s %3$s', 'alliancepay' ), $maxAttempts, $method, $path ) );
        }

        $url = $this->baseUrl . $path;

        if (empty($headers)) {
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
        }
        $headers['x-api_version'] = $this->apiVersion;

        if ($auth) {
            $headers['x-device_id'] = $this->deviceId;
            $headers['x-refresh_token'] = $this->refreshToken;
        }

        if (is_array($body)) {
            $body = wp_json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        // === ALB_REQ_LOG: log outgoing request ===
        if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
            $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
            if (!is_dir($dir)) @wp_mkdir_p($dir);
            @file_put_contents(
                $dir . '/debug.log',
                date('c') . " BANK REQUEST (attempt {$attempt}) {$method} {$url} headers=" . wp_json_encode($headers, JSON_UNESCAPED_UNICODE) .
                " body=" . $body . PHP_EOL,
                FILE_APPEND
            );
        }

        $resp = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 20,
            'body' => $body,
        ]);

        // === ALB_CREATE_ORDER_LOG: extended logging of raw response ===
        if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
            $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
            if (!is_dir($dir)) @wp_mkdir_p($dir);
            $reqId = wp_remote_retrieve_header($resp, 'x-request_id');
            @file_put_contents(
                $dir . '/debug.log',
                date('c') . " HTTP (attempt {$attempt}) {$method} {$path} reqId={$reqId} resp=" . wp_json_encode($resp) . PHP_EOL,
                FILE_APPEND
            );
        }

        if (is_wp_error($resp)) {
            throw new \RuntimeException($resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);

        // Перевіряємо помилку авторизації
        if (
            isset($data['msgType']) && isset($data['msgCode']) &&
            $data['msgType'] == 'ERROR' && $data['msgCode'] == 'b_used_token'
        ) {
            if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
                $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
                @file_put_contents(
                    $dir . '/debug.log',
                    date('c') . " AUTH ERROR detected on attempt {$attempt}, reauthorizing..." . PHP_EOL,
                    FILE_APPEND
                );
            }

            // Переавторизація
            require_once ALB_HPP_DIR . 'includes/class-alb-authorize.php';
            $opt = get_option(ALB_HPP_OPT, []);
            ALB_Authorize::reauthorize_device($opt);

            // Оновлюємо токени після переавторизації
            $this->refreshToken = $opt['refresh_token'] ?? $this->refreshToken;
            $this->deviceId = $opt['device_id'] ?? $this->deviceId;

            // Рекурсивний виклик з інкрементованим лічильником
            return $this->http($method, $path, $json, $auth, $attempt + 1);
        }

        // Додаткова перевірка на 401 помилку
        if ($code === 401 && $auth) {
            if ($attempt < $maxAttempts) {
                if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
                    $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
                    @file_put_contents(
                        $dir . '/debug.log',
                        date('c') . " 401 Unauthorized on attempt {$attempt}, retrying..." . PHP_EOL,
                        FILE_APPEND
                    );
                }

                // Переавторизація і повторна спроба
                require_once ALB_HPP_DIR . 'includes/class-alb-authorize.php';
                $opt = get_option(ALB_HPP_OPT, []);
                ALB_Authorize::reauthorize_device($opt);

                $this->refreshToken = $opt['refresh_token'] ?? $this->refreshToken;
                $this->deviceId = $opt['device_id'] ?? $this->deviceId;

                return $this->http($method, $path, $json, $auth, $attempt + 1);
            } else {
                throw new \RuntimeException( sprintf( __( '401 Unauthorized from API after %d attempts. Check deviceId/refreshToken.', 'alliancepay' ), $maxAttempts ) );
            }
        }

        if ($code >= 300) {
            throw new \RuntimeException("API {$path} HTTP {$code}: {$body}");
        }

        return is_array($data) ? $data : [];
    }
}
