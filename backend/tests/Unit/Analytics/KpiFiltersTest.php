<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Modules\Analytics\Support\ResolveKpiFilters;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ResolveKpiFilters turns the shared query params into the period window plus
 * the immediately-preceding window for Δ comparisons. These assert the calendar
 * presets and a custom range resolve to the right boundaries (app timezone).
 */
class KpiFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 10:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function resolve(array $query): \App\Modules\Analytics\Support\KpiFilters
    {
        return (new ResolveKpiFilters)->handle(new Request($query));
    }

    public function test_month_preset_resolves_to_the_calendar_month_and_previous_month(): void
    {
        $f = $this->resolve(['period' => 'month']);

        $this->assertSame('2026-05-01', $f->start->toDateString());
        $this->assertSame('2026-05-31', $f->end->toDateString());
        $this->assertSame('2026-04-01', $f->prevStart->toDateString());
        $this->assertSame('2026-04-30', $f->prevEnd->toDateString());
    }

    public function test_year_preset_resolves_to_the_calendar_year_and_previous_year(): void
    {
        $f = $this->resolve(['period' => 'year']);

        $this->assertSame('2026-01-01', $f->start->toDateString());
        $this->assertSame('2026-12-31', $f->end->toDateString());
        $this->assertSame('2025-01-01', $f->prevStart->toDateString());
        $this->assertSame('2025-12-31', $f->prevEnd->toDateString());
    }

    public function test_custom_range_gets_an_equal_length_previous_window(): void
    {
        $f = $this->resolve(['period' => 'custom', 'from' => '2026-05-01', 'to' => '2026-05-10']);

        $this->assertSame('2026-05-01', $f->start->toDateString());
        $this->assertSame('2026-05-10', $f->end->toDateString());
        // 10-day window → the 10 days immediately before it.
        $this->assertSame('2026-04-21', $f->prevStart->toDateString());
        $this->assertSame('2026-04-30', $f->prevEnd->toDateString());
    }

    public function test_dimension_filters_are_parsed(): void
    {
        $f = $this->resolve(['location_id' => '5', 'unit_type' => '3', 'agent_id' => '7']);

        $this->assertSame(5, $f->locationId);
        $this->assertSame(3, $f->unitType);
        $this->assertSame(7, $f->agentId);
        $this->assertTrue($f->hasLocation());
    }
}
