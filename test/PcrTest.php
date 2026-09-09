<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\Pcr;
use PHPUnit\Framework\TestCase;

class PcrTest extends TestCase
{
    public function testFieldsAreTruncatedToTwelveCharacters(): void
    {
        $pcr = Pcr::build('A Very Long Shop Name', 'Reference 123456');

        $this->assertSame('A Very Long ', $pcr['particulars']);
        $this->assertSame('Reference 12', $pcr['reference']);
    }

    public function testDisallowedCharactersAreStripped(): void
    {
        $pcr = Pcr::build('Shop! @Näme', 'Ref<>"; DROP');

        $this->assertSame('Shop Nme', $pcr['particulars']);
        $this->assertSame('Ref DROP', $pcr['reference']);
    }

    public function testBlankParticularsFallBackToOrder(): void
    {
        $this->assertSame('Order', Pcr::build('')['particulars']);
    }

    public function testEmptyCodeAndReferenceAreOmitted(): void
    {
        $pcr = Pcr::build('Shop');

        $this->assertSame(['particulars' => 'Shop'], $pcr);
    }

    public function testCodeIsIncludedWhenProvided(): void
    {
        $pcr = Pcr::build('Shop', '1005', '42');

        $this->assertSame(['particulars' => 'Shop', 'code' => '42', 'reference' => '1005'], $pcr);
    }
}
