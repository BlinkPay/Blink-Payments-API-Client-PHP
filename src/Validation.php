<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * Local input checks applied before anything is interpolated into a URL or
 * header line. They exist so a malformed or hostile value fails here, with a
 * developer-facing message, rather than reaching the wire.
 *
 * @internal Not part of the public API; signatures may change without notice.
 */
final class Validation
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Spec pattern for monetary totals: up to 13 integer digits and 1-2 decimals. */
    private const AMOUNT_PATTERN = '/^\d{1,13}\.\d{1,2}$/';

    private function __construct()
    {
    }

    /**
     * Returns the trimmed value when it is an RFC 4122 UUID, else throws.
     *
     * @throws BlinkDebitApiException
     */
    public static function uuid(string $value, string $label): string
    {
        $value = trim($value);
        if (!preg_match(self::UUID_PATTERN, $value)) {
            throw new BlinkDebitApiException(sprintf('Invalid %s: expected a UUID.', $label));
        }

        return $value;
    }

    /**
     * Returns the trimmed value when it is an IPv4 or IPv6 address, else throws.
     *
     * @throws BlinkDebitApiException
     */
    public static function ipAddress(string $value, string $label): string
    {
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new BlinkDebitApiException(sprintf('Invalid %s: expected an IPv4 or IPv6 address.', $label));
        }

        return $value;
    }

    /**
     * Returns the trimmed value when it is safe to place in an HTTP header:
     * non-empty and free of control characters, so a CR/LF cannot start a
     * second header line.
     *
     * @throws BlinkDebitApiException
     */
    public static function headerText(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new BlinkDebitApiException(
                sprintf('Invalid %s: must be non-empty and contain no control characters.', $label)
            );
        }

        return $value;
    }

    /**
     * Returns the value when it matches the API's amount format, e.g. "12.50".
     *
     * @throws BlinkDebitApiException
     */
    public static function amount(string $value, string $label): string
    {
        if (!preg_match(self::AMOUNT_PATTERN, $value)) {
            throw new BlinkDebitApiException(
                sprintf('Invalid %s "%s": expected a decimal string with 1-2 decimal places, e.g. "12.50".', $label, $value)
            );
        }

        return $value;
    }

    /**
     * Returns the value when it is an absolute https:// URL, else throws.
     *
     * @throws BlinkDebitApiException
     */
    public static function httpsUrl(string $value, string $label): string
    {
        $value = trim($value);
        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !is_string($scheme) || strtolower($scheme) !== 'https') {
            throw new BlinkDebitApiException(sprintf('Invalid %s: expected an absolute https:// URL.', $label));
        }

        return $value;
    }
}
