<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * Reads client settings from environment variables, with the same parsing
 * rules in plain PHP and in every framework integration.
 *
 * The sandbox flag is the security-relevant one: an unset or blank
 * BLINKPAY_SANDBOX must mean sandbox, never production. Native helpers get
 * that wrong in different ways (getenv() returns false when unset, Laravel's
 * env() returns an empty string for a blank value, and both cast to false),
 * so every entry point parses through {@see Env::bool()} instead.
 */
final class Env
{
    public const CLIENT_ID = 'BLINKPAY_CLIENT_ID';
    public const CLIENT_SECRET = 'BLINKPAY_CLIENT_SECRET';
    public const SANDBOX = 'BLINKPAY_SANDBOX';
    public const TIMEOUT = 'BLINKPAY_TIMEOUT';

    private function __construct()
    {
    }

    /**
     * The variable's value from $_SERVER, $_ENV or getenv(), or null when it
     * is unset. Checking all three covers every way a framework or process
     * manager exposes a dotenv file.
     */
    public static function get(string $name): ?string
    {
        foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)] as $candidate) {
            if (is_string($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Parses a boolean setting. Unset and blank values return the default;
     * true/false, 1/0, yes/no and on/off (any case) are accepted; anything
     * else throws rather than silently choosing an environment.
     *
     * A bare false is an explicit false, because Laravel's env() returns one
     * for the string "false"; read raw variables through {@see Env::get()},
     * which returns null for unset, rather than getenv(), which returns false.
     *
     * @param mixed $value Raw value from Env::get(), a framework env() helper or a config array.
     *
     * @throws BlinkDebitApiException
     */
    public static function bool($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        if (!is_scalar($value)) {
            throw new BlinkDebitApiException(
                sprintf('Invalid boolean setting of type %s: expected true or false.', gettype($value))
            );
        }

        $parsed = filter_var(trim((string) $value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new BlinkDebitApiException(
                sprintf('Invalid boolean setting "%s": expected true or false.', (string) $value)
            );
        }

        return $parsed;
    }
}
