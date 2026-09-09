<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\Pcr;
use PHPUnit\Framework\TestCase;

class PcrTest extends TestCase
{
    public function testBuildFollowsTheApiArgumentOrder(): void
    {
        $pcr = Pcr::build('Shop', '42', '1005');

        $this->assertSame(['particulars' => 'Shop', 'code' => '42', 'reference' => '1005'], $pcr);
    }

    public function testEmptyCodeAndReferenceAreOmitted(): void
    {
        $this->assertSame(['particulars' => 'Shop'], Pcr::build('Shop'));
        $this->assertSame(['particulars' => 'Shop', 'reference' => 'INV-1'], Pcr::build('Shop', '', 'INV-1'));
    }

    public function testBuildTrimsButNeverTruncates(): void
    {
        $this->assertSame(['particulars' => 'Twelve chars'], Pcr::build(' Twelve chars '));

        $this->expectException(BlinkDebitApiException::class);
        $this->expectExceptionMessage('Invalid PCR reference "Reference 123456"');
        Pcr::build('Shop', '', 'Reference 123456');
    }

    public function testBuildRejectsDisallowedCharacters(): void
    {
        $this->expectException(BlinkDebitApiException::class);
        $this->expectExceptionMessage('Invalid PCR particulars');
        Pcr::build('Shop! @Näme');
    }

    public function testBuildRejectsBlankParticulars(): void
    {
        $this->expectException(BlinkDebitApiException::class);
        $this->expectExceptionMessage('particulars is required');
        Pcr::build('   ');
    }

    public function testSanitiseStripsAndTruncates(): void
    {
        $pcr = Pcr::sanitise('A Very Long Shop Name', 'Shop! @Näme', 'Ref<>"; DROP 1234567890');

        $this->assertSame(
            ['particulars' => 'A Very Long', 'code' => 'Shop Nme', 'reference' => 'Ref DROP 123'],
            $pcr
        );
    }

    public function testSanitiseStillRejectsParticularsThatCleanToNothing(): void
    {
        $this->expectException(BlinkDebitApiException::class);
        $this->expectExceptionMessage('particulars is required');
        Pcr::sanitise('日本語 ★');
    }

    public function testIsValidMatchesTheApiRules(): void
    {
        $this->assertTrue(Pcr::isValid("A-Z 0-9 &#?:"));
        $this->assertTrue(Pcr::isValid(''));
        $this->assertFalse(Pcr::isValid('Thirteen char'));
        $this->assertFalse(Pcr::isValid('Shop!'));
    }
}
