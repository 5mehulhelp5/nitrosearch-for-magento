<?php

namespace NitroSearch\AdapterKit;

use InvalidArgumentException;

/**
 * Money for the NitroSearch ingest wire: an integer number of minor units, plus the
 * currency and the exponent that says what a minor unit is.
 *
 * THIS CLASS EXISTS TO DELETE ONE LINE OF CODE from every adapter ever written:
 *
 *     'price' => (int) round($price * 100),
 *
 * That line is correct for dollars, euros and pounds, and wrong for about fifty
 * currencies. It sends a ¥1,000 product as 100000 and a 19.995 KWD product as 2000.
 * It has already happened in production, in both directions at once — the producer
 * multiplied by 100 and the consumer divided by 100, so the two bugs cancelled and
 * nobody noticed until a Japanese store was onboarded.
 *
 * So: you cannot construct a price here without a currency, and the exponent is
 * looked up rather than assumed.
 *
 *     Money::ofMinor(1999, 'USD')          // $19.99
 *     Money::ofMinor(1000, 'JPY')          // ¥1,000  — not 100000
 *     Money::fromDecimalString('19.99', 'USD')
 *     Money::fromDecimalString('1000', 'JPY')
 *
 * TARGETS PHP 7.4. Deliberately, and not as an endorsement — 7.4 is end-of-life. A
 * kit is VENDORED into modules that run on merchants' own hosting, across four
 * frameworks with four different minimums, and it must never be the reason a module
 * cannot ship. Modern syntax here would buy nothing and cost reach.
 */
final class Money
{
    /** @var int */
    private $minor;

    /** @var string */
    private $currency;

    /** @var int */
    private $exponent;

    private function __construct(int $minor, string $currency, int $exponent)
    {
        $this->minor = $minor;
        $this->currency = $currency;
        $this->exponent = $exponent;
    }

    /**
     * A price already in minor units: 1999 for $19.99, 1000 for ¥1,000.
     *
     * `$minor` is deliberately UNTYPED, which looks like a mistake and is not. A
     * `int $minor` signature only rejects a float when the CALLING file declares
     * `strict_types=1` — and this is vendored into other people's code, where it
     * usually does not. In weak mode `Money::ofMinor(19.99, 'USD')` silently becomes
     * 19, which is the exact class of error this class exists to prevent. So the check
     * is explicit and cannot be switched off by the caller's file header.
     *
     * @param  mixed  $minor
     */
    public static function ofMinor($minor, string $currency): self
    {
        if (is_float($minor) || (is_string($minor) && strpos($minor, '.') !== false)) {
            throw new InvalidArgumentException(
                'Money::ofMinor() takes MINOR units as an integer — 19.99 USD is 1999, not 19.99. '
                .'Got '.var_export($minor, true).'. If you have a decimal amount, use '
                .'Money::fromDecimalString() instead; it scales by the right power of ten for the currency.'
            );
        }

        if (! is_int($minor) && ! (is_string($minor) && preg_match('/^-?\d+$/', $minor) === 1)) {
            throw new InvalidArgumentException(
                'Money::ofMinor() expects an integer number of minor units, got '.gettype($minor).'.'
            );
        }

        $minor = (int) $minor;

        if ($minor < 0) {
            throw new InvalidArgumentException('A price may not be negative, got '.$minor.'.');
        }

        return new self($minor, self::normaliseCurrency($currency), CurrencyExponents::for($currency));
    }

    /**
     * A price as the decimal string your platform stores, converted exactly.
     *
     * STRING ARITHMETIC, NOT FLOATING POINT. `(int) round(19.99 * 100)` happens to give
     * 1999, and `(int) (19.99 * 100)` gives 1998, because 19.99 is not representable in
     * binary. Scaling by digits sidesteps the question entirely.
     *
     * Truncation is refused rather than performed: '19.999' in USD would have to drop a
     * digit, and silently dropping a digit of someone's price is not a rounding policy
     * this kit is entitled to choose.
     */
    public static function fromDecimalString(string $amount, string $currency): self
    {
        $amount = trim($amount);

        if (preg_match('/^\d+(\.\d+)?$/', $amount) !== 1) {
            throw new InvalidArgumentException(
                'Money::fromDecimalString() expects a plain non-negative decimal like "19.99", got '
                .var_export($amount, true).'. Strip currency symbols, spaces and thousands separators first.'
            );
        }

        $exponent = CurrencyExponents::for($currency);

        $parts = explode('.', $amount, 2);
        $whole = $parts[0];
        $fraction = isset($parts[1]) ? $parts[1] : '';

        if (strlen($fraction) > $exponent) {
            $dropped = substr($fraction, $exponent);
            if (rtrim($dropped, '0') !== '') {
                throw new InvalidArgumentException(sprintf(
                    '"%s" has more decimal places than %s uses (%d). Round it yourself before '
                    .'converting — this kit will not silently drop "%s" from a merchant\'s price.',
                    $amount,
                    self::normaliseCurrency($currency),
                    $exponent,
                    $dropped
                ));
            }
            $fraction = substr($fraction, 0, $exponent);
        }

        $fraction = str_pad($fraction, $exponent, '0');

        return new self(
            (int) ($whole.$fraction),
            self::normaliseCurrency($currency),
            $exponent
        );
    }

    /** The integer to put in the wire's `price` field. */
    public function minor(): int
    {
        return $this->minor;
    }

    /** The ISO 4217 code to put in the wire's `currency` field. */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * The value for the wire's `price_exponent` field.
     *
     * Send it. Omitting it tells the backend "this producer scaled by 100 whatever the
     * currency", which is what pre-1.x modules did and is why the field exists.
     */
    public function exponent(): int
    {
        return $this->exponent;
    }

    private static function normaliseCurrency(string $currency): string
    {
        $code = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException(
                'Currency must be a three-letter ISO 4217 code, got '.var_export($currency, true).'.'
            );
        }

        return $code;
    }
}
