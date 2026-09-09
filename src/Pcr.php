<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * Builds the particulars/code/reference block shown on the customer's bank
 * statement. Bank rules limit each field to 12 characters from a restricted
 * character set, so values are sanitised rather than rejected.
 */
final class Pcr
{
    private function __construct()
    {
    }

    /**
     * Note the argument order is particulars, reference, code — reference is
     * used far more often than code, so it comes first despite the class name.
     * Both are free text, so a transposition would not be caught by types.
     *
     * @return array{particulars: string, code?: string, reference?: string}
     */
    public static function build(string $particulars, string $reference = '', string $code = ''): array
    {
        $particulars = self::sanitiseField($particulars);
        if ($particulars === '') {
            $particulars = 'Order';
        }

        $pcr = ['particulars' => $particulars];

        $code = self::sanitiseField($code);
        if ($code !== '') {
            $pcr['code'] = $code;
        }

        $reference = self::sanitiseField($reference);
        if ($reference !== '') {
            $pcr['reference'] = $reference;
        }

        return $pcr;
    }

    public static function sanitiseField(string $value): string
    {
        $value = (string) preg_replace("/[^a-zA-Z0-9\- &#?:_\/,.']/", '', $value);

        return substr($value, 0, 12);
    }
}
