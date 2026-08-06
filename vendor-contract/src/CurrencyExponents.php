<?php

namespace NitroSearch\AdapterKit;

/**
 * How many minor units make one major unit, per currency.
 *
 * GENERATED — do not edit. This file is emitted from the same table the search
 * backend uses, so the kit cannot disagree with the system it sends to.
 *
 * Only the exceptions are listed; anything not here is 2. The table follows CLDR
 * rather than ISO 4217 where they disagree (IQD is 3 under ISO and 0 under CLDR),
 * because storefront prices are ultimately formatted by Intl.NumberFormat, which
 * is CLDR-backed and cannot be told otherwise.
 */
final class CurrencyExponents
{
    /** @var array<string, int> */
    const EXCEPTIONS = [
        'ADP' => 0,
        'AFN' => 0,
        'ALL' => 0,
        'BHD' => 3,
        'BIF' => 0,
        'BYR' => 0,
        'CLF' => 4,
        'CLP' => 0,
        'DJF' => 0,
        'ESP' => 0,
        'GNF' => 0,
        'IQD' => 0,
        'IRR' => 0,
        'ISK' => 0,
        'ITL' => 0,
        'JOD' => 3,
        'JPY' => 0,
        'KMF' => 0,
        'KPW' => 0,
        'KRW' => 0,
        'KWD' => 3,
        'LAK' => 0,
        'LBP' => 0,
        'LUF' => 0,
        'LYD' => 3,
        'MGA' => 0,
        'MGF' => 0,
        'MMK' => 0,
        'MRO' => 0,
        'OMR' => 3,
        'PYG' => 0,
        'RSD' => 0,
        'RWF' => 0,
        'SLL' => 0,
        'SOS' => 0,
        'STD' => 0,
        'SYP' => 0,
        'TMM' => 0,
        'TND' => 3,
        'TRL' => 0,
        'UGX' => 0,
        'UYI' => 0,
        'UYW' => 4,
        'VND' => 0,
        'VUV' => 0,
        'XAF' => 0,
        'XOF' => 0,
        'XPF' => 0,
        'YER' => 0,
        'ZMK' => 0,
        'ZWD' => 0,
    ];

    /** The default: a minor unit is a hundredth. */
    const DEFAULT_EXPONENT = 2;

    public static function for(string $currency): int
    {
        $code = strtoupper(trim($currency));

        return isset(self::EXCEPTIONS[$code]) ? self::EXCEPTIONS[$code] : self::DEFAULT_EXPONENT;
    }
}
