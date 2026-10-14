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
 * Class ALB_JWS_Verifier.
 */
class ALB_JWS_Verifier
{
    /**
     * @param string $jws
     * @param array $opt
     * @return array
     */
    public static function verify(string $jws, array $opt): array
    {
        try {
            $deserialised = JWT::deserialise($jws);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf(
                    __('JWS callback parse error: %s', 'alliancepay'),
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
                __('JWS callback is missing kid or alg in header.', 'alliancepay')
            );
        }

        $baseUrl = rtrim($opt['baseUrl'] ?? ALB_HPP_PROD_BASE, '/');
        $apiVersion = $opt['apiVersion'] ?? AllianceConfig::DEFAULT_API_VERSION;

        $jwk = self::fetch_public_key($kid, $baseUrl, $apiVersion);

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
                    date('c') . " ALB_JWS_Verifier: signature invalid kid={$kid}: "
                    . $e->getMessage() . PHP_EOL,
                    FILE_APPEND
                );
            }
            throw new \RuntimeException(
                sprintf(
                    __('JWS callback verification failed: %s', 'alliancepay'),
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
     * @param string $baseUrl
     * @param string $apiVersion
     * @return array
     */
    private static function fetch_public_key(
        string $kid,
        string $baseUrl,
        string $apiVersion
    ): array {
        $cacheKey = 'alb_bank_pubkey_' . md5($kid);

        $cached = get_transient($cacheKey);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $url = $baseUrl . ApiEndpoints::ENDPOINT_PUBLIC_KEY;
        $body = wp_json_encode(['kid' => $kid], JSON_UNESCAPED_UNICODE);

        $resp = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'x-api_version' => $apiVersion,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($resp)) {
            throw new \RuntimeException(
                sprintf(
                    __('Cannot fetch bank public key for JWS callback (kid=%1$s): %2$s', 'alliancepay'),
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
                date('c') . " ALB_JWS_Verifier::fetch_public_key kid={$kid}"
                . " HTTP {$code}: " . $rawBody . PHP_EOL,
                FILE_APPEND
            );
        }

        if ($code !== 200) {
            throw new \RuntimeException(
                sprintf(
                    __('Cannot fetch bank public key for JWS callback (kid=%1$s): HTTP %2$d %3$s', 'alliancepay'),
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
                    __('Invalid public key response from bank for JWS callback (kid=%s)', 'alliancepay'),
                    $kid
                )
            );
        }

        set_transient($cacheKey, $jwk, HOUR_IN_SECONDS);

        return $jwk;
    }
}
