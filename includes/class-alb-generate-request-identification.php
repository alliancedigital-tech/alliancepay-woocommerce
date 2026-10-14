<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

use Exception;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Generate_Request_Identification.
 */
class ALB_Generate_Request_Identification
{
    /**
     * @return string
     */
    public static function generateRequestId(): string
    {
        $requestId = null;

        try {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

            $requestId = vsprintf(
                '%s%s-%s-%s-%s-%s%s%s',
                str_split(
                    bin2hex($data),
                    4
                )
            );
        } catch (Exception $exception) {
            //Do nothing, just return uniqid() generated value.
        }

        return $requestId ?? uniqid();
    }
}
