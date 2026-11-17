<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_format_trims_trailing_zeros(): void
    {
        $this->assertSame('1,200', Money::format(1200.0));
        $this->assertSame('1,200', Money::format('1200.00'));
        $this->assertSame('1.25', Money::format(1.25));
        $this->assertSame('1.2', Money::format(1.20));
        $this->assertSame('0', Money::format(0));
        $this->assertSame('0', Money::format(-0.0));
    }

    public function test_format_respects_precision(): void
    {
        $this->assertSame('1,234.567', Money::format(1234.567, 3));
        $this->assertSame('1,235', Money::format(1234.567, 0));
    }

    public function test_quantity_uses_three_decimals_and_trims(): void
    {
        $this->assertSame('4', Money::quantity(4.0));
        $this->assertSame('1.5', Money::quantity(1.5));
        $this->assertSame('1.234', Money::quantity(1.234));
        $this->assertSame('1.235', Money::quantity(1.2346));
    }

    public function test_plain_has_no_thousands_separator(): void
    {
        $this->assertSame('1200', Money::plain(1200.00));
        $this->assertSame('1200.5', Money::plain(1200.50));
    }
}
