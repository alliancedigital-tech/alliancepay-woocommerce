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
            'alg' => 'RS256',
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
