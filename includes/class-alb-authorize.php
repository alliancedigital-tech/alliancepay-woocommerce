<?php

namespace ALB;

use SimpleJWT\JWE;
use SimpleJWT\Keys\KeySet;
use SimpleJWT\Keys\KeyFactory;
use Exception;
use RuntimeException;

if (!defined('ABSPATH')) exit;

class ALB_Authorize
{

    public const ALGORITHM = 'ECDH-ES+A256KW';

    public const ENCRYPTION = 'A256GCM';

    /** Викликає /api-gateway/authorize_virtual_device і повертає JWE */
    public static function authorize_virtual_device(string $baseUrl, string $serviceCode): string
    {
        $url = rtrim($baseUrl, '/') . '/api-gateway/authorize_virtual_device';
        $resp = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode(['serviceCode' => $serviceCode], JSON_UNESCAPED_UNICODE),
        ]);
        if (is_wp_error($resp)) throw new RuntimeException($resp->get_error_message());
        $code = wp_remote_retrieve_response_code($resp);
        $raw = wp_remote_retrieve_body($resp);
        // Корисно залогувати у wp-content/uploads/alb-logs/debug.log
        if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
            $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
            if (!is_dir($dir)) wp_mkdir_p($dir);
            @file_put_contents($dir . '/debug.log', date('c') . " authorize resp [{$code}]: " . $raw . PHP_EOL, FILE_APPEND);
        }
        if ($code >= 300) {
            // Фелбек: спробуємо query-параметр (деякі збірки приймають саме так)
            $url2 = $url . '?serviceCode=' . rawurlencode($serviceCode);
            $resp2 = wp_remote_post($url2, ['timeout' => 30, 'headers' => ['Accept' => 'application/json']]);
            $code2 = wp_remote_retrieve_response_code($resp2);
            $raw2 = wp_remote_retrieve_body($resp2);
            if ($code2 < 300) {
                $data2 = json_decode($raw2, true);
                if (isset($data2['jwe']) && is_string($data2['jwe']) && $data2['jwe'] !== '') return $data2['jwe'];
            }
            throw new RuntimeException("authorize HTTP {$code}: {$raw}");
        }
        // Нормальний шлях
        $body = json_decode($raw, true);

        if (isset($body['jwe']) && is_string($body['jwe']) && $body['jwe'] !== '') {
            return $body['jwe'];
        }
        // Інколи повертають незвичні ключі або рядок:
        if (is_string($raw) && preg_match('~^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_.-]+~', $raw)) {
            // схоже на JWE compact-формат
            return $raw;
        }
        throw new RuntimeException( __( 'JWE not received in the authorize_virtual_device response', 'alliancepay' ) );
    }

    public static function decrypt_with_simplejwt(string $jwe, array $jwk): array
    {
        $keySet = new \SimpleJWT\Keys\KeySet();
        $keyFactory = new \SimpleJWT\Keys\KeyFactory();
        // Побудувати Key із JWK (EC P-384, use: enc)
        $privateJwk = [
            'kty' => 'EC',
            'crv' => $jwk['crv'] ?? 'P-384',
            'd' => $jwk['d'] ?? null,
            'x' => $jwk['x'] ?? null,
            'y' => $jwk['y'] ?? null,
            'use' => $jwk['use'] ?? 'enc',
            'alg' => $jwk['alg'] ?? 'ECDH-ES+A256KW',
        ];
        if (empty($privateJwk['d']) || empty($privateJwk['x']) || empty($privateJwk['y'])) {
            throw new RuntimeException( __( 'Invalid Private JWK: missing d/x/y.', 'alliancepay' ) );
        }
//   $key = $keyFactory->create($privateJwk, 'json'); // створює \SimpleJWT\Keys\Key
        $jwkJson = wp_json_encode($privateJwk, JSON_UNESCAPED_UNICODE);
        $key = $keyFactory->create($jwkJson, 'json'); // <-- тепер рядок
        $keySet->add($key);
        // Розшифрування JWE (алгоритми, що вимагає банк)
        $jweObj = \SimpleJWT\JWE::decrypt(
            $jwe,
            $keySet,
            'ECDH-ES+A256KW',   // key management
            'A256GCM'           // content encryption
        );
        $payload = $jweObj->getPlaintext();
        $arr = json_decode($payload ?? '', true);
        if (!is_array($arr)) {
            throw new RuntimeException('Invalid decrypted payload (SimpleJWT)');
        }
        return $arr;
    }

    public function encrypt_with_simplejwt(array $publicServerKey, array $payload): string
    {
        $headers = [
            'alg' => self::ALGORITHM,
            'enc' => self::ENCRYPTION
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $key = KeyFactory::create($publicServerKey, alg: self::ALGORITHM);
        $keySet = new KeySet();
        $keySet->add($key);
        $jwe = new JWE($headers, $payloadJson);

        try {
            $token = $jwe->encrypt($keySet);
        } catch (Exception $e) {
            if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
                $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
                if (!is_dir($dir)) @wp_mkdir_p($dir);
                @file_put_contents(
                    $dir . '/debug.log',
                    $e->getMessage(),
                    FILE_APPEND
                );
            }
        }

        return $token;
    }



    // ============================
    // V 1.1 : підтримка tokenExpiration та авто‑реавторизації ⬇⬇⬇
    // ============================

    /**
     * Парсер tokenExpiration із payload: приймає ISO‑рядок, секунди або мілісекунди.
     */
    private static function parse_token_expiration($val): int
    {
        if (!$val) return 0;
        if (is_string($val)) {
            $t = strtotime($val);
            if ($t) return $t;
            $fmts = [
                'Y-m-d H:i:s.uP',   // ...+03:00
                'Y-m-d H:i:s.uO',   // ...+0000
                'Y-m-d\TH:i:s.uP',  // ISO T
                'Y-m-d\TH:i:sP',    // без мікросекунд
                'Y-m-d H:i:sP',
                'Y-m-d H:i:sO',
            ];
            foreach ($fmts as $fmt) {
                $dt = \DateTimeImmutable::createFromFormat($fmt, $val);
                if ($dt instanceof \DateTimeImmutable) {
                    return $dt->getTimestamp();
                }
            }
            return 0;
        }
        if (is_numeric($val)) {
            $n = (int)$val;
            return ($n > 20000000000) ? (int)round($n / 1000) : $n;
        }
        return 0;
    }

    /**
     * Зберегти креденшіали після успішного decrypt authorize‑payload (deviceId, refreshToken, tokenExpiration)
     * Викликати ЦЕЙ метод у місці, де в адмінці після authorize + decrypt ти наразі оновлюєш options.
     * Наприклад:
     * $payload = ALB_Authorize::decrypt_with_simplejwt($jwe, $jwk);
     * ALB_Authorize::store_authorized_credentials($payload);
     */
    public static function store_authorized_credentials(array $payload): void {
        $opt = get_option(ALB_HPP_OPT, []);
        $now = time();

        $exp = 0;
        if (isset($payload['tokenExpiration'])) {
            $exp = self::parse_token_expiration($payload['tokenExpiration']);
        }
//	if ($exp == 0 && isset($payload['tokenExpirationDateTime'])) {
//        $exp = self::parse_token_expiration($payload['tokenExpirationDateTime']);
//    }

        if (!$exp) $exp = $now + 1 * DAY_IN_SECONDS; // фолбек на 1 днів

        if (isset($payload['deviceId'])) $opt['deviceId'] = $payload['deviceId'];
        if (isset($payload['refreshToken'])) $opt['refreshToken'] = $payload['refreshToken'];

        $opt['alb_token_issued_at'] = $now;
        $opt['alb_token_expires_at'] = $exp;

        $opt['serverPublicKey'] = json_encode($payload['serverPublic']);

        update_option(ALB_HPP_OPT, $opt);

    }

    /**
     * Повторна авторизація пристрою (ре‑авторизація) із врахуванням обраного режиму decrypt.
     * Викликати з cron або кнопкою в адмінці.
     */
    public static function reauthorize_device(array $opt): void
    {
        $baseUrl = $opt['baseUrl'] ?? '';
        $serviceCode = $opt['serviceCode'] ?? '';
        $jwk_raw = $opt['privateJwk'] ?? '';

        if (!$baseUrl || !$serviceCode || !$jwk_raw) {
            throw new RuntimeException( __( 'Settings are incomplete for reauthorization.', 'alliancepay' ) );
        }

        $jwe = self::authorize_virtual_device($baseUrl, $serviceCode);

        $jwk = is_array($jwk_raw) ? $jwk_raw : json_decode((string)$jwk_raw, true);
        if (!is_array($jwk)) throw new RuntimeException( __( 'Private JWK must be valid JSON', 'alliancepay' ) );

        $payload = self::decrypt_with_simplejwt($jwe, $jwk);

        // Якщо банк повернув помилковий об'єкт замість JWE->payload
        if (isset($payload['msgCode']) && $payload['msgCode'] === 'b_auth_token_expired') {
            throw new RuntimeException( __( 'Token expired (200 days of inactivity). Contact bank support, then retry authorization.', 'alliancepay' ) );
        }

        self::store_authorized_credentials($payload);
    }

    /**
     * Зручний помічник: скільки днів лишилось до закінчення дії токена за збереженими опціями.
     */
    public static function days_left(): ?int
    {
        $opt = get_option(ALB_HPP_OPT, []);
        $exp = (int)($opt['alb_token_expires_at'] ?? 0);
        if (!$exp) return null;
        return (int)floor(($exp - time()) / DAY_IN_SECONDS);
    }
}

