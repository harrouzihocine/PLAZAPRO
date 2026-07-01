<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payments\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * The guide's named "versement rounding" rule: money is computed with bcmath on
 * decimal strings (never floats) and rounded half-up (away from zero) to 2 dp.
 */
class MoneyTest extends TestCase
{
    public function test_sum_is_exact_with_no_float_drift(): void
    {
        // 0.1 + 0.2 is 0.30000000000000004 in float; bcmath keeps it exact.
        $this->assertSame('0.30', Money::sum(['0.10', '0.20']));
        $this->assertSame('1000.00', Money::sum(['333.33', '333.33', '333.34']));
    }

    public function test_add_and_sub_stay_at_two_decimals(): void
    {
        $this->assertSame('1200.00', Money::add('1000.00', '200.00'));
        $this->assertSame('800.00', Money::sub('1000.00', '200.00'));
        $this->assertSame('0.00', Money::sub('200.00', '200.00'));
    }

    public function test_compare_and_equals(): void
    {
        $this->assertSame(0, Money::compare('10.00', '10.000'));
        $this->assertTrue(Money::equals('3000.00', '3000'));
        $this->assertTrue(Money::isZero('0.00'));
        $this->assertTrue(Money::isPositive('0.01'));
        $this->assertFalse(Money::isPositive('0.00'));
    }

    public function test_round_half_up_to_two_decimals(): void
    {
        $this->assertSame('2.01', Money::roundHalfUp('2.005'));
        $this->assertSame('2.00', Money::roundHalfUp('2.004'));
        $this->assertSame('-2.01', Money::roundHalfUp('-2.005'));
        $this->assertSame('0.00', Money::roundHalfUp('-0.0004'));
    }
}
