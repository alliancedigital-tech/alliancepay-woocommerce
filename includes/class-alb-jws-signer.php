<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

use SimpleJWT\JWT;
use SimpleJWT\Keys\KeyFactory;
use SimpleJWT\Keys\KeySet;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_JWS_Signer.
 */
class ALB_JWS_Signer
{
    /**
     * JWS-алгоритм за типом ключа: EC P-256 → ES256, RSA → RS256 (зворотна сумісність).
     *
     * @param \SimpleJWT\Keys\KeyInterface $key
     * @return string
     * @throws \RuntimeException
     */
    public static function algorithm_for_key($key): string
    {
        $type = $key->getKeyType();
        if ($type === 'EC') {
            if (method_exists($key, 'getCurve') && $key->getCurve() !== 'P-256') {
                throw new \RuntimeException(sprintf(
                    /* translators: %s: curve name */
                    __('JWS Private Key must use the P-256 curve, got %s.', 'alliancepay'),
                    (string)$key->getCurve()
                ));
            }
            return 'ES256';
        }
        if ($type === 'RSA') {
            return 'RS256';
        }

        throw new \RuntimeException(
            __('Unsupported JWS Private Key type: EC P-256 key is required.', 'alliancepay')
        );
    }

    /**
     * @param string $privateKeyPem
     * @param string $kid
     * @param string $targetUrl
     * @param string $payloadJson
     * @return string
     */
    public static function sign(
        string $privateKeyPem,
        string $kid,
        string $targetUrl,
        string $payloadJson
    ): string
    {
        if (empty($privateKeyPem)) {
            throw new \RuntimeException(__('JWS Private Key (PEM) is not configured.', 'alliancepay'));
        }
        if (empty($kid)) {
            throw new \RuntimeException(__('JWS Key ID (kid) is not configured.', 'alliancepay'));
        }

        try {
            $key = KeyFactory::create($privateKeyPem, 'pem');
        } catch (\Exception $e) {
            throw new \RuntimeException(
                __('JWS Private Key is invalid: ', 'alliancepay') . $e->getMessage(),
                0,
                $e
            );
        }

        $keySet = new KeySet();
        $keySet->add($key);

        $headers = [
            'alg' => self::algorithm_for_key($key),
            'ts' => (string)(int)(microtime(true) * 1000),
            'targetUrl' => $targetUrl,
            'kid' => $kid,
            'jti' => ALB_Generate_Request_Identification::generateRequestId(),
        ];

        $claims = json_decode($payloadJson, true);
        if (!is_array($claims)) {
            throw new \RuntimeException(__('JWS: payload is not valid JSON.', 'alliancepay'));
        }

        try {
            $jwt = new JWT($headers, $claims);
            return $jwt->encode($keySet, kid: null, auto_complete: false);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                __('JWS signing failed: ', 'alliancepay') . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
