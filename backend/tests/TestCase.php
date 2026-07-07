<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Settings\Models\AppSetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // AppSetting's static per-request cache survives across tests in one
        // PHP process; without this, a value one test set would outlive
        // RefreshDatabase and bleed into the next test.
        AppSetting::flushRemembered();
    }
}
