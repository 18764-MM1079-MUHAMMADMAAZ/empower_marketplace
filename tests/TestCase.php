<?php

namespace Tests;

use App\Http\Controllers\CareCloudMsaController;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Checkout requires the CareCloud MSA to have been opened before the Terms & Conditions
        // are accepted. Most tests are about something else, so start as if it was; the tests for
        // that rule (PortalTest) clear this first.
        session()->put(CareCloudMsaController::SESSION_KEY, now()->toIso8601String());
    }
}
