<?php

namespace Tests\Feature;

use App\Enums\IntakeSubmissionStatus;
use App\Enums\UserRole;
use App\Jobs\ProvisionLmsAccount;
use App\Jobs\SyncLmsAccess;
use App\Models\IntakeSubmission;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\MoodleClient;
use App\Services\TrialBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Gap 6 in lms-enrollment-plan.md: losing LMS access suspends the Moodle account (records kept). */
class LmsAccessSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.moodle.token' => 'test-token', 'services.moodle.base_url' => 'https://lms.test']);
        Mail::fake();
        Http::fake(fn ($request) => Http::response(match ($request['wsfunction']) {
            'core_user_get_users_by_field' => [['id' => 900]],
            default => null,
        }));
    }

    /** A client with a Moodle account and one live order for the given package. */
    private function provisionedClient(string $slug = 'professional', array $user = []): Order
    {
        $client = User::factory()->create(['moodle_user_id' => 900, ...$user]);
        $package = Package::where('slug', $slug)->first() ?? Package::factory()->create(['slug' => $slug, 'is_active' => true]);

        return Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);
    }

    /** Each change looks the account up first (saved id, cross-checked), then sends one update. */
    private function assertSuspendSent(bool $suspended, int $times = 1): void
    {
        $this->assertSame($times, $this->suspendCalls());
        Http::assertSent(fn ($request) => $request['wsfunction'] === 'core_user_update_users'
            && $request['users'][0]['id'] === 900
            && ($request['users'][0]['suspended'] ?? null) === ($suspended ? 1 : 0));
    }

    private function suspendCalls(): int
    {
        return Http::recorded(fn ($request) => $request['wsfunction'] === 'core_user_update_users'
            && isset($request['users'][0]['suspended']))->count();
    }

    public function test_cancelling_the_only_lms_order_suspends_the_moodle_account(): void
    {
        $order = $this->provisionedClient();

        app(TrialBillingService::class)->cancel($order, 'term_ended');

        $this->assertSuspendSent(true);
        $this->assertNotNull($order->user->fresh()->lms_suspended_at);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lms.suspended', 'user_id' => $order->user_id]);
    }

    public function test_every_way_an_order_ends_suspends_the_account(): void
    {
        foreach (['client_requested', 'trial_expired', 'renewal_failed', 'term_ended'] as $reason) {
            $order = $this->provisionedClient(user: ['email' => "{$reason}@practice.com"]);

            app(TrialBillingService::class)->cancel($order, $reason);

            $this->assertNotNull($order->user->fresh()->lms_suspended_at, $reason);
        }
    }

    public function test_a_client_with_another_live_lms_order_keeps_access(): void
    {
        $order = $this->provisionedClient();
        Order::factory()->create(['user_id' => $order->user_id, 'package_id' => Package::factory()->create(['slug' => 'complete'])->id]);

        app(TrialBillingService::class)->cancel($order, 'client_requested');

        Http::assertNothingSent();
        $this->assertNull($order->user->fresh()->lms_suspended_at);
    }

    public function test_clients_without_a_moodle_account_are_left_alone(): void
    {
        $order = $this->provisionedClient(user: ['moodle_user_id' => null]);

        app(TrialBillingService::class)->cancel($order, 'client_requested');

        Http::assertNothingSent();
    }

    public function test_buying_again_turns_a_suspended_account_back_on(): void
    {
        $order = $this->provisionedClient();
        app(TrialBillingService::class)->cancel($order, 'term_ended');

        Order::factory()->create(['user_id' => $order->user_id, 'package_id' => $order->package_id]);

        $this->assertSuspendSent(false, times: 2);
        $this->assertNull($order->user->fresh()->lms_suspended_at);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lms.reactivated', 'user_id' => $order->user_id]);
    }

    public function test_deactivating_and_reactivating_the_user_follows_through_to_moodle(): void
    {
        $client = $this->provisionedClient()->user;

        $client->update(['is_active' => false]);
        $this->assertSuspendSent(true);

        $client->update(['is_active' => true]);
        $this->assertSuspendSent(false, times: 2);
    }

    public function test_running_the_sync_again_does_not_call_moodle_twice(): void
    {
        $order = $this->provisionedClient();
        app(TrialBillingService::class)->cancel($order, 'term_ended');

        $before = Http::recorded()->count();
        (new SyncLmsAccess($order->user))->handle(new MoodleClient);

        // Already suspended: no lookup and no update the second time.
        $this->assertSame($before, Http::recorded()->count());
        $this->assertSame(1, $this->suspendCalls());
    }

    public function test_unrelated_order_updates_do_not_touch_moodle(): void
    {
        $order = $this->provisionedClient();

        $order->update(['notes' => 'Called the client.', 'renewal_attempts' => 1]);

        Http::assertNothingSent();
    }

    public function test_reprovisioning_a_suspended_client_turns_the_account_back_on(): void
    {
        $order = $this->provisionedClient();
        $order->user->forceFill(['lms_suspended_at' => now()])->saveQuietly();

        (new ProvisionLmsAccount($order->user))->handle(new MoodleClient);

        Http::assertSent(fn ($request) => $request['wsfunction'] === 'core_user_update_users' && ($request['users'][0]['suspended'] ?? null) === 0);
        $this->assertNull($order->user->fresh()->lms_suspended_at);
    }

    public function test_the_admin_lms_card_shows_when_access_was_turned_off(): void
    {
        $order = $this->provisionedClient();
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id, 'status' => IntakeSubmissionStatus::Submitted, 'submitted_at' => now()]);

        app(TrialBillingService::class)->cancel($order, 'term_ended');

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertSee('Access off')
            ->assertSee('Training records are kept.');
    }
}
