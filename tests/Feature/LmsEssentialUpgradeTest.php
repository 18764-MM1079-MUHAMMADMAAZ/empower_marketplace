<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\ProvisionLmsAccount;
use App\Mail\ClientLmsAccessMail;
use App\Mail\WelcomeCredentialsMail;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use App\Services\MoodleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Gap 4 in lms-enrollment-plan.md: upgrading from Essential creates the LMS account and emails
 *  "LMS access is now on", with the same login and no new password (spec §4). */
class LmsEssentialUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function essentialClient(): User
    {
        $user = User::factory()->create(['email' => 'jane@practice.com']);
        Practice::factory()->create(['user_id' => $user->id, 'address' => '12 Main St, Austin, TX 78701', 'billable_providers_count' => 2]);
        $essential = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $essential->id,
            'status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => now()->subMonth(),
        ]);

        return $user;
    }

    public function test_buying_an_lms_package_as_an_essential_client_provisions_without_a_new_password(): void
    {
        Bus::fake([ProvisionLmsAccount::class]);
        Http::fake([
            config('services.clover_mtbc.base_url') => Http::response([
                'status' => true,
                'message' => 'Payment Successful',
                'data' => ['id' => 'TEST_TXN_ID', 'amount' => 1, 'paid' => true, 'status' => 'succeeded'],
            ]),
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/tokenize' => Http::response([
                'status' => true,
                'data' => ['token' => '4111114281501111', 'firstSix' => '411111', 'lastFour' => '1111', 'referenceNumber' => 'REF123'],
            ]),
        ]);
        $user = $this->essentialClient();
        $passwordHash = $user->password;
        Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);

        Livewire::actingAs($user)
            ->withQueryParams(['package' => 'professional'])
            ->test('portal')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        Bus::assertDispatched(ProvisionLmsAccount::class, fn (ProvisionLmsAccount $job) => $job->user->is($user)
            && $job->welcomePassword === null && $job->addressOrState === '12 Main St, Austin, TX 78701');
        Mail::assertNotQueued(WelcomeCredentialsMail::class);
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertTrue($user->fresh()->hasLmsAccess());
    }

    public function test_the_upgrade_email_says_lms_access_is_now_on_with_the_same_login(): void
    {
        config(['services.moodle.token' => 'test-token', 'services.moodle.base_url' => 'https://lms.test']);
        Http::fake(fn ($request) => match ($request['wsfunction']) {
            'core_user_get_users_by_field' => Http::response([]),
            'core_user_create_users' => Http::response([['id' => 900]]),
            default => Http::response(null),
        });
        $user = $this->essentialClient();
        $professional = Package::factory()->create(['slug' => 'professional', 'is_active' => true]);
        Order::factory()->create(['user_id' => $user->id, 'package_id' => $professional->id]);

        (new ProvisionLmsAccount($user, 'TX'))->handle(new MoodleClient);

        Mail::assertSent(ClientLmsAccessMail::class, function (ClientLmsAccessMail $mail) {
            $html = $mail->render();

            return $mail->upgrade && $mail->envelope()->subject === 'Your Empower LMS access is now on'
                && str_contains($html, 'Your LMS access is now on') && str_contains($html, 'no new password');
        });
        Mail::assertNotSent(WelcomeCredentialsMail::class);
    }

    public function test_a_client_without_essential_gets_the_usual_wording(): void
    {
        config(['services.moodle.token' => 'test-token', 'services.moodle.base_url' => 'https://lms.test']);
        Http::fake(fn ($request) => match ($request['wsfunction']) {
            'core_user_get_users_by_field' => Http::response([]),
            'core_user_create_users' => Http::response([['id' => 900]]),
            default => Http::response(null),
        });
        $user = User::factory()->create();
        $professional = Package::factory()->create(['slug' => 'professional', 'is_active' => true]);
        Order::factory()->create(['user_id' => $user->id, 'package_id' => $professional->id]);

        (new ProvisionLmsAccount($user, 'TX'))->handle(new MoodleClient);

        Mail::assertSent(ClientLmsAccessMail::class, fn (ClientLmsAccessMail $mail) => ! $mail->upgrade
            && $mail->envelope()->subject === 'Your Empower LMS training access is ready');
    }
}
