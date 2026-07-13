<?php

declare(strict_types=1);

namespace Tests\Unit\LegacyImport;

use App\Modules\LegacyImport\Support\Transform;
use PHPUnit\Framework\TestCase;

/**
 * Transform::phone() normalizes DZ numbers to the app convention
 * "+213 XXX XX XX XX" (the 2026-07-14 switch away from the old 0-form), keeps
 * foreign numbers as +E164, and flags garbage with ok=false. The stored
 * clients.phone_nsn column is the last 9 digits, so the format never affects
 * duplicate matching — hence nsn stays the significant digits.
 */
class TransformPhoneTest extends TestCase
{
    private Transform $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = new Transform(1.0);
    }

    public function test_dz_mobile_forms_all_land_as_plus_213_grouped_3_2_2_2(): void
    {
        foreach (['tel:+213-540-78-26-88', '0540 78 26 88', '0540782688', '540782688', '00213540782688', '+213540782688'] as $raw) {
            $r = $this->t->phone($raw);
            $this->assertSame('+213 540 78 26 88', $r['phone'], "raw: {$raw}");
            $this->assertSame('540782688', $r['nsn'], "raw: {$raw}");
            $this->assertTrue($r['ok']);
            $this->assertFalse($r['foreign']);
        }
    }

    public function test_landline_length_national_number_grouped_2_2_2_2(): void
    {
        $r = $this->t->phone('021234567'); // 0 + 8 significant digits
        $this->assertSame('+213 21 23 45 67', $r['phone']);
        $this->assertSame('21234567', $r['nsn']);
        $this->assertTrue($r['ok']);
    }

    public function test_foreign_number_kept_as_e164(): void
    {
        $r = $this->t->phone('+33 6 10 28 98 33');
        $this->assertSame('+33610289833', $r['phone']);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['foreign']);
    }

    public function test_empty_is_null_and_garbage_is_flagged(): void
    {
        $empty = $this->t->phone('');
        $this->assertNull($empty['phone']);
        $this->assertTrue($empty['ok']);

        $garbage = $this->t->phone('abc');
        $this->assertFalse($garbage['ok']);

        $truncated = $this->t->phone('0551799'); // too short for a DZ number
        $this->assertFalse($truncated['ok']);
        $this->assertSame('0551799', $truncated['phone']);
    }
}
