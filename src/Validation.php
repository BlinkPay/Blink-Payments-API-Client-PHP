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
    // The D modifier stops `$` matching before a trailing newline, which would
    // otherwise let "12.50\n" through and on to the wire.
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    /** Spec pattern for monetary totals: up to 13 integer digits and 1-2 decimals. */
    private const AMOUNT_PATTERN = '/^\d{1,13}\.\d{1,2}$/D';

    /** RFC 9110 token: what a User-Agent product name or version may contain. */
    private const PRODUCT_TOKEN_PATTERN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    // Keep the User-Agent well inside nginx's 8 KB header line limit, beyond
    // which every request would be rejected.
    private const PRODUCT_TOKEN_MAX_LENGTH = 100;
    private const APP_URL_MAX_LENGTH = 2048;

    private function __construct()
    {
        // Static-only class: not meant to be instantiated.
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
        return self::nonEmpty($value, $label);
    }

    /**
     * Returns the trimmed value when it is non-empty and free of control
     * characters, else throws.
     *
     * @throws BlinkDebitApiException
     */
    public static function nonEmpty(string $value, string $label): string
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
     * Returns the value when it is an absolute https:// URL, else throws. For
     * callbacks BlinkPay or a bank will call from the internet.
     *
     * @throws BlinkDebitApiException
     */
    public static function httpsUrl(string $value, string $label): string
    {
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
            throw new BlinkDebitApiException(sprintf('Invalid %s: expected an absolute https:// URL.', $label));
        }

        return $value;
    }

    /**
     * Returns the value when it is an RFC 9110 token, the grammar of a
     * User-Agent product name and version.
     *
     * @throws BlinkDebitApiException
     */
    public static function productToken(string $value, string $label): string
    {
        if (strlen($value) > self::PRODUCT_TOKEN_MAX_LENGTH || !preg_match(self::PRODUCT_TOKEN_PATTERN, $value)) {
            throw new BlinkDebitApiException(sprintf(
                'Invalid %s: must be 1-%d characters of letters, digits and !#$%%&\'*+-.^_`|~ (no whitespace, "/", "(" or ")").',
                $label,
                self::PRODUCT_TOKEN_MAX_LENGTH
            ));
        }

        return $value;
    }

    /**
     * Returns the value when it is an absolute http:// or https:// URL that can
     * sit inside a User-Agent comment: no whitespace, control characters,
     * parentheses or backslashes. Userinfo is refused so credentials in a
     * caller's URL never reach BlinkPay's logs.
     *
     * @throws BlinkDebitApiException
     */
    public static function appUrl(string $value, string $label): string
    {
        if (
            strlen($value) > self::APP_URL_MAX_LENGTH
            || preg_match('/[\s\x00-\x1F\x7F()\\\\]/', $value)
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            || (string) parse_url($value, PHP_URL_HOST) === ''
            || parse_url($value, PHP_URL_USER) !== null
            || parse_url($value, PHP_URL_PASS) !== null
        ) {
            throw new BlinkDebitApiException(sprintf(
                'Invalid %s: expected an absolute http:// or https:// URL of at most %d characters, with no user info, whitespace, control characters, parentheses or backslashes.',
                $label,
                self::APP_URL_MAX_LENGTH
            ));
        }

        return $value;
    }

    /**
     * Returns the value when it is an absolute URI, else throws. For the
     * redirect URI the customer is sent back to: normally https://, a plain
     * http:// localhost address during development, or an app deep link such
     * as myapp://return when the flow sets redirect_to_app.
     *
     * @throws BlinkDebitApiException
     */
    public static function redirectUri(string $value, string $label): string
    {
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new BlinkDebitApiException(sprintf(
                'Invalid %s: expected an absolute URI such as https://shop.example/return or an app link such as myapp://return.',
                $label
            ));
        }

        return $value;
    }
}
