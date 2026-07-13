<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DzPhone;
use PHPUnit\Framework\TestCase;

/**
 * DzPhone::normalize() is the app-input path (client model mutator): DZ national
 * numbers become "+213 …", everything else is left as typed, and the operation
 * is idempotent so re-saving never double-transforms.
 */
class DzPhoneTest extends TestCase
{
    public function test_dz_national_numbers_normalize_to_plus_213(): void
    {
        $this->assertSame('+213 555 12 34 56', DzPhone::normalize('0555123456'));
        $this->assertSame('+213 555 12 34 56', DzPhone::normalize('0555 12 34 56'));
        $this->assertSame('+213 555 12 34 56', DzPhone::normalize('555123456'));   // bare 9-digit
        $this->assertSame('+213 21 23 45 67', DzPhone::normalize('021234567'));    // landline
    }

    public function test_already_normalized_is_idempotent(): void
    {
        $this->assertSame('+213 555 12 34 56', DzPhone::normalize('+213 555 12 34 56'));
    }

    public function test_foreign_and_malformed_are_left_as_typed(): void
    {
        $this->assertSame('+33610289833', DzPhone::normalize('+33610289833'));      // foreign
        $this->assertSame('05411835449', DzPhone::normalize('05411835449'));        // 11 digits — junk
        $this->assertSame('0551799', DzPhone::normalize('0551799'));                // too short
    }

    public function test_empty_becomes_null(): void
    {
        $this->assertNull(DzPhone::normalize(''));
        $this->assertNull(DzPhone::normalize('   '));
        $this->assertNull(DzPhone::normalize(null));
    }
}
