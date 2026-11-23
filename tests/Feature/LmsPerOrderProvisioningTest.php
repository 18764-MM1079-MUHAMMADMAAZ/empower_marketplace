<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\ProvisionLmsAccount;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\MoodleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Gap 9 in lms-enrollment-plan.md: provisioning runs once per order ID and is recorded on the order (spec §5). */
class LmsPerOrderProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /** Whether Moodle refuses the enrolment. */
    private bool $enrolFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.moodle.token' => 'test-token', 'services.moodle.base_url' => 'https://lms.test']);
        Http::fake(fn ($request) => match ($request['wsfunction']) {
            'core_user_get_users_by_field' => Http::response([['id' => 900]]),
            'enrol_manual_enrol_users' => Http::response($this->enrolFails ? ['exception' => 'x', 'message' => 'Course is hidden'] : null),
            default => Http::response(null),
        });
    }

    private function orderFor(User $user, string $slug, array $attributes = []): Order
    {
        $package = Package::where('slug', $slug)->first() ?? Package::factory()->create(['slug' => $slug, 'is_active' => true]);

        return Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id, ...$attributes]);
    }

    public function test_a_successful_run_records_the_time_on_the_order(): void
    {
        $user = User::factory()->create();
        $order = $this->orderFor($user, 'professional');

        (new ProvisionLmsAccount($user, 'TX', [$order->id]))->handle(new MoodleClient);

        $this->assertNotNull($order->fresh()->lms_provisioned_at);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lms.provisioned', 'user_id' => $user->id]);
    }

    public function test_the_same_order_is_not_provisioned_twice(): void
    {
        $user = User::factory()->create();
        $order = $this->orderFor($user, 'professional', ['lms_provisioned_at' => now()->subDay()]);

        (new ProvisionLmsAccount($user, 'TX', [$order->id]))->handle(new MoodleClient);

        Http::assertNothingSent();
    }

    public function test_a_failed_enrolment_leaves_the_order_unrecorded_so_the_retry_runs_again(): void
    {
        $this->enrolFails = true;
        $user = User::factory()->create();
        $order = $this->orderFor($user, 'advanced');

        try {
            (new ProvisionLmsAccount($user, 'TX', [$order->id]))->handle(new MoodleClient);
            $this->fail('Expected the enrolment failure to be thrown for a retry.');
        } catch (RuntimeException) {
        }

        $this->assertNull($order->fresh()->lms_provisioned_at);
    }

    public function test_only_live_lms_orders_are_recorded(): void
    {
        $user = User::factory()->create();
        $professional = $this->orderFor($user, 'professional');
        $essential = $this->orderFor($user, 'essential');
        $cancelled = $this->orderFor($user, 'complete', ['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

        (new ProvisionLmsAccount($user, 'TX', [$professional->id]))->handle(new MoodleClient);

        $this->assertNotNull($professional->fresh()->lms_provisioned_at);
        $this->assertNull($essential->fresh()->lms_provisioned_at);
        $this->assertNull($cancelled->fresh()->lms_provisioned_at);
    }

    public function test_a_run_without_orders_still_runs_and_records_the_live_lms_orders(): void
    {
        $user = User::factory()->create();
        $order = $this->orderFor($user, 'professional', ['lms_provisioned_at' => now()->subDay()]);
        $newer = $this->orderFor($user, 'advanced');

        (new ProvisionLmsAccount($user, '12 Main St, Austin, TX 78701'))->handle(new MoodleClient);

        Http::assertSent(fn ($request) => $request['wsfunction'] === 'enrol_manual_enrol_users');
        $this->assertNotNull($newer->fresh()->lms_provisioned_at);
        $this->assertTrue($order->fresh()->lms_provisioned_at->lt(now()->subHours(12)), 'An earlier record is kept.');
    }
}
