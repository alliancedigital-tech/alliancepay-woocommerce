<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

use League\ISO3166\ISO3166;

if (!defined('ABSPATH')) exit;

class ALB_Country_Code_Provider
{
    private ISO3166 $country_code_provider;
    public function __construct()
    {
        $this->country_code_provider = new ISO3166();
    }

    public function getCountryNumericCodeByAlpha2(string $alpha2): string
    {
        $countryData = $this->country_code_provider->alpha2($alpha2);

        return $countryData['numeric'] ?? '';
    }
}
