<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_formats_zero_satang(): void
    {
        $this->assertSame('0.00', Money::satangToBaht(0));
    }

    public function test_formats_single_digit_satang(): void
    {
        $this->assertSame('0.05', Money::satangToBaht(5));
    }

    public function test_formats_double_digit_satang(): void
    {
        $this->assertSame('0.50', Money::satangToBaht(50));
        $this->assertSame('0.99', Money::satangToBaht(99));
    }

    public function test_formats_standard_amounts(): void
    {
        $this->assertSame('1.00', Money::satangToBaht(100));
        $this->assertSame('10.00', Money::satangToBaht(1000));
        $this->assertSame('750.00', Money::satangToBaht(75000));
        $this->assertSame('1000.00', Money::satangToBaht(100000));
        $this->assertSame('1200.50', Money::satangToBaht(120050));
    }

    public function test_formats_large_amounts(): void
    {
        $this->assertSame('15000.00', Money::satangToBaht(1500000));
        $this->assertSame('18000.00', Money::satangToBaht(1800000));
        $this->assertSame('27000.00', Money::satangToBaht(2700000));
    }

    public function test_formats_negative_amounts(): void
    {
        $this->assertSame('-0.50', Money::satangToBaht(-50));
        $this->assertSame('-100.00', Money::satangToBaht(-10000));
    }
}
