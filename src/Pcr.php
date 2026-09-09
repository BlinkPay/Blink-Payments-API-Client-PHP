<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * Builds the particulars/code/reference block shown on the customer's bank
 * statement. Bank rules allow up to 12 characters per field from a restricted
 * character set, and the API rejects longer or foreign values with a 400
 * rather than truncating them.
 *
 * {@see Pcr::build()} validates and passes values through unchanged, so what
 * the merchant reconciles on is exactly what they asked for. Platforms whose
 * statement text comes from free text (a shop name, a product title) can opt
 * into lossy clean-up with {@see Pcr::sanitise()}.
 */
final class Pcr
{
    public const MAX_LENGTH = 12;

    /** Characters the banks accept in every PCR field. */
    private const ALLOWED_PATTERN = "/^[a-zA-Z0-9\\- &#?:_\\/,.']*$/";

    private function __construct()
    {
        // Static-only class: not meant to be instantiated.
    }

    /**
     * Validates and builds a PCR block. Arguments follow the API's own order
     * (particulars, code, reference), matching the Java and Node SDKs.
     *
     * @return array{particulars: string, code?: string, reference?: string}
     *
     * @throws BlinkDebitApiException When particulars is blank or any field is too long or has disallowed characters.
     *                               Surrounding spaces are legal per the spec and are kept, not trimmed.
     */
    public static function build(string $particulars, string $code = '', string $reference = ''): array
    {
        $particulars = self::validateField($particulars, 'particulars');
        if (trim($particulars) === '') {
            throw new BlinkDebitApiException('Invalid PCR: particulars is required.');
        }

        $pcr = ['particulars' => $particulars];

        $code = self::validateField($code, 'code');
        if ($code !== '') {
            $pcr['code'] = $code;
        }

        $reference = self::validateField($reference, 'reference');
        if ($reference !== '') {
            $pcr['reference'] = $reference;
        }

        return $pcr;
    }

    /**
     * Builds a PCR block from free text, stripping disallowed characters and
     * truncating each field to 12 characters. Lossy by design: an invoice
     * number longer than 12 characters loses its tail, so prefer build() with
     * values that already fit. Throws when particulars is blank after
     * clean-up, so a name made entirely of unsupported characters cannot
     * silently become an empty statement line.
     *
     * @return array{particulars: string, code?: string, reference?: string}
     *
     * @throws BlinkDebitApiException
     */
    public static function sanitise(string $particulars, string $code = '', string $reference = ''): array
    {
        return self::build(
            self::sanitiseField($particulars),
            self::sanitiseField($code),
            self::sanitiseField($reference)
        );
    }

    /**
     * Whether a single field would be accepted by the API as-is.
     */
    public static function isValid(string $value): bool
    {
        return strlen($value) <= self::MAX_LENGTH && preg_match(self::ALLOWED_PATTERN, $value) === 1;
    }

    /**
     * Strips disallowed characters and truncates to the bank limit.
     */
    public static function sanitiseField(string $value): string
    {
        $value = (string) preg_replace("/[^a-zA-Z0-9\\- &#?:_\\/,.']/", '', $value);

        return trim(substr($value, 0, self::MAX_LENGTH));
    }

    /**
     * @throws BlinkDebitApiException
     */
    private static function validateField(string $value, string $label): string
    {
        if (!self::isValid($value)) {
            throw new BlinkDebitApiException(sprintf(
                'Invalid PCR %s "%s": up to %d characters from letters, digits, space and - & # ? : _ / , . \' only. '
                . 'Use Pcr::sanitise() to clean free text.',
                $label,
                $value,
                self::MAX_LENGTH
            ));
        }

        return $value;
    }
}
