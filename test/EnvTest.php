<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\Env;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([Env::CLIENT_ID, Env::SANDBOX] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function testUnsetAndBlankValuesReturnTheDefault(): void
    {
        foreach ([null, '', '   '] as $value) {
            $this->assertTrue(Env::bool($value, true), var_export($value, true));
            $this->assertFalse(Env::bool($value, false), var_export($value, true));
        }
    }

    public function testRecognisedSpellingsAreParsed(): void
    {
        foreach (['true', 'TRUE', ' yes ', 'on', '1', true] as $value) {
            $this->assertTrue(Env::bool($value, false), var_export($value, true));
        }
        // A bare false is explicit, not "unset": Laravel's env() returns it for the string "false".
        foreach (['false', 'False', 'no', 'off', '0', false, 0] as $value) {
            $this->assertFalse(Env::bool($value, true), var_export($value, true));
        }
    }

    public function testGarbageIsRejectedRatherThanGuessed(): void
    {
        $this->expectException(BlinkDebitApiException::class);
        $this->expectExceptionMessage('Invalid boolean setting "maybe"');
        Env::bool('maybe', true);
    }

    public function testGetReadsServerEnvAndGetenvInThatOrder(): void
    {
        $this->assertNull(Env::get(Env::CLIENT_ID));

        putenv(Env::CLIENT_ID . '=from-getenv');
        $this->assertSame('from-getenv', Env::get(Env::CLIENT_ID));

        $_ENV[Env::CLIENT_ID] = 'from-env';
        $this->assertSame('from-env', Env::get(Env::CLIENT_ID));

        $_SERVER[Env::CLIENT_ID] = 'from-server';
        $this->assertSame('from-server', Env::get(Env::CLIENT_ID));
    }
}
