<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Request;

use BlinkPay\BlinkDebit\Enum\FlowType;
use BlinkPay\BlinkDebit\Validation;

/**
 * Builds the `flow` object shared by quick payments, single consents and
 * enduring consents. Bank and identifier-type values are not checked against
 * a fixed list, so a newly onboarded bank works without an SDK release; the
 * API rejects unknown values with a 400. Redirect URIs may be http:// for
 * local development; callback URLs, which BlinkPay or a bank call from the
 * internet, must be https://.
 *
 *   Flow::gateway($returnUrl)
 *   Flow::gateway($returnUrl, Flow::redirectHint(Bank::BNZ))
 *   Flow::redirect(Bank::ANZ, $returnUrl)
 *   Flow::decoupled(Bank::PNZ, IdentifierType::MOBILE_NUMBER, '+64-21-...', $callbackUrl)
 */
final class Flow
{
    private function __construct()
    {
        // Static-only class: not meant to be instantiated.
    }

    /**
     * Blink's hosted gateway chooses the bank (and card, where enabled). An
     * optional flow hint pre-selects a bank and flow for the customer.
     *
     * @param array<string, string>|null $flowHint From redirectHint() or decoupledHint().
     *
     * @return array{detail: array<string, mixed>}
     *
     * @throws \BlinkPay\BlinkDebit\BlinkDebitApiException
     */
    public static function gateway(string $redirectUri, ?array $flowHint = null): array
    {
        $detail = [
            'type' => FlowType::GATEWAY,
            'redirect_uri' => Validation::webUrl($redirectUri, 'redirect URI'),
        ];
        if ($flowHint !== null) {
            $detail['flow_hint'] = $flowHint;
        }

        return ['detail' => $detail];
    }

    /**
     * The customer is sent straight to the named bank and returned to the
     * redirect URI afterwards.
     *
     * @return array{detail: array<string, string>}
     *
     * @throws \BlinkPay\BlinkDebit\BlinkDebitApiException
     */
    public static function redirect(string $bank, string $redirectUri): array
    {
        return ['detail' => [
            'type' => FlowType::REDIRECT,
            'bank' => self::nonEmpty($bank, 'bank'),
            'redirect_uri' => Validation::webUrl($redirectUri, 'redirect URI'),
        ]];
    }

    /**
     * The bank pushes the authorisation to the customer's banking app; no
     * browser redirect. The bank notifies the callback URL when the customer
     * has responded, if one is given.
     *
     * @return array{detail: array<string, string>}
     *
     * @throws \BlinkPay\BlinkDebit\BlinkDebitApiException
     */
    public static function decoupled(
        string $bank,
        string $identifierType,
        string $identifierValue,
        ?string $callbackUrl = null
    ): array {
        $detail = [
            'type' => FlowType::DECOUPLED,
            'bank' => self::nonEmpty($bank, 'bank'),
            'identifier_type' => self::nonEmpty($identifierType, 'identifier type'),
            'identifier_value' => self::nonEmpty($identifierValue, 'identifier value'),
        ];
        if ($callbackUrl !== null) {
            $detail['callback_url'] = Validation::httpsUrl($callbackUrl, 'callback URL');
        }

        return ['detail' => $detail];
    }

    /**
     * Gateway hint: skip bank selection and use redirect flow with this bank.
     *
     * @return array{type: string, bank: string}
     *
     * @throws \BlinkPay\BlinkDebit\BlinkDebitApiException
     */
    public static function redirectHint(string $bank): array
    {
        return ['type' => FlowType::REDIRECT, 'bank' => self::nonEmpty($bank, 'bank')];
    }

    /**
     * Gateway hint: skip bank selection and use decoupled flow with this bank
     * and customer identifier.
     *
     * @return array{type: string, bank: string, identifier_type: string, identifier_value: string}
     *
     * @throws \BlinkPay\BlinkDebit\BlinkDebitApiException
     */
    public static function decoupledHint(string $bank, string $identifierType, string $identifierValue): array
    {
        return [
            'type' => FlowType::DECOUPLED,
            'bank' => self::nonEmpty($bank, 'bank'),
            'identifier_type' => self::nonEmpty($identifierType, 'identifier type'),
            'identifier_value' => self::nonEmpty($identifierValue, 'identifier value'),
        ];
    }

    /**
     * @throws \BlinkPay\BlinkDebit\BlinkDebitApiException
     */
    private static function nonEmpty(string $value, string $label): string
    {
        return Validation::nonEmpty($value, $label);
    }
}
