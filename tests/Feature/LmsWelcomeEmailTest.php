<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\ProvisionLmsAccount;
use App\Mail\ClientLmsAccessMail;
use App\Mail\WelcomeCredentialsMail;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\MoodleClient;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Gap 2 in lms-enrollment-plan.md: a new LMS client gets one welcome email, sent after the LMS
 *  account exists, with the login details and a "Go to LMS" button (spec §4, §7). */
class LmsWelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function fakeCheckout(): void
    {
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
    }

    private function guestPays(string $slug): void
    {
        $this->fakeCheckout();
        $package = Package::factory()->create(['slug' => $slug, 'annual_price' => 1299, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billableProviders', 1)
            ->set('accountName', 'Jane Provider')
            ->set('accountEmail', 'jane@practice.com')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();
    }

    private function fakeMoodle(bool $enrolFails = false): void
    {
        config(['services.moodle.token' => 'test-token', 'services.moodle.base_url' => 'https://lms.test']);
        Http::fake(fn ($request) => match ($request['wsfunction']) {
            'core_user_get_users_by_field' => Http::response([]),
            'core_user_create_users' => Http::response([['id' => 900]]),
            'enrol_manual_enrol_users' => Http::response($enrolFails ? ['exception' => 'x', 'message' => 'Course is hidden'] : null),
            default => Http::response(null),
        });
    }

    private function lmsClient(): User
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $package = Package::factory()->create(['slug' => 'professional', 'is_active' => true]);
        Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);

        return $user;
    }

    public function test_an_lms_sign_up_waits_for_the_lms_account_instead_of_emailing_at_checkout(): void
    {
        Bus::fake([ProvisionLmsAccount::class]);

        $this->guestPays('professional');

        Mail::assertNotQueued(WelcomeCredentialsMail::class);
        Bus::assertDispatched(ProvisionLmsAccount::class, fn (ProvisionLmsAccount $job) => filled($job->welcomePassword));
    }

    public function test_an_essential_sign_up_still_gets_the_welcome_email_at_checkout(): void
    {
        Bus::fake([ProvisionLmsAccount::class]);

        $this->guestPays('essential');

        Mail::assertQueued(WelcomeCredentialsMail::class, fn (WelcomeCredentialsMail $mail) => $mail->lmsCourseCount === null);
        Bus::assertNotDispatched(ProvisionLmsAccount::class);
    }

    public function test_after_provisioning_the_client_gets_one_email_with_login_details_and_go_to_lms(): void
    {
        $this->fakeMoodle();
        $user = $this->lmsClient();

        (new ProvisionLmsAccount($user, 'Austin, TX 78701', [], 'Temp-Pass-123'))->handle(new MoodleClient);

        Mail::assertSent(WelcomeCredentialsMail::class, 1);
        Mail::assertSent(WelcomeCredentialsMail::class, function (WelcomeCredentialsMail $mail) {
            $html = $mail->render();

            return $mail->password === 'Temp-Pass-123' && $mail->lmsCourseCount > 0
                && str_contains($html, 'Temp-Pass-123') && str_contains($html, 'Go to LMS') && str_contains($html, route('lms.launch'));
        });
        Mail::assertNotSent(ClientLmsAccessMail::class);
    }

    public function test_an_existing_client_still_gets_the_separate_lms_email_and_no_password(): void
    {
        $this->fakeMoodle();
        $user = $this->lmsClient();

        (new ProvisionLmsAccount($user, 'TX'))->handle(new MoodleClient);

        Mail::assertSent(ClientLmsAccessMail::class, 1);
        Mail::assertNotSent(WelcomeCredentialsMail::class);
    }

    public function test_when_provisioning_finally_fails_the_welcome_still_goes_out_without_the_lms_part_and_admins_are_told(): void
    {
        $this->fakeMoodle(enrolFails: true);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = $this->lmsClient();
        $job = new ProvisionLmsAccount($user, 'TX', [], 'Temp-Pass-123');

        try {
            $job->handle(new MoodleClient);
            $this->fail('Expected the enrolment failure to be thrown for a retry.');
        } catch (RuntimeException $e) {
        }

        Mail::assertNotSent(WelcomeCredentialsMail::class);

        $job->failed($e);

        Mail::assertSent(WelcomeCredentialsMail::class, fn (WelcomeCredentialsMail $mail) => $mail->lmsCourseCount === null && ! str_contains($mail->render(), 'Go to LMS'));
        $this->assertSame('LMS provisioning failed', $admin->notifications()->first()?->data['title']);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lms.failed', 'user_id' => $user->id]);
    }

    public function test_without_a_moodle_token_the_welcome_is_still_sent(): void
    {
        config(['services.moodle.token' => null]);
        $user = $this->lmsClient();

        (new ProvisionLmsAccount($user, 'TX', [], 'Temp-Pass-123'))->handle(new MoodleClient);

        Mail::assertSent(WelcomeCredentialsMail::class, fn (WelcomeCredentialsMail $mail) => $mail->lmsCourseCount === null);
    }

    public function test_the_job_carrying_the_password_is_encrypted_on_the_queue(): void
    {
        $this->assertInstanceOf(ShouldBeEncrypted::class, new ProvisionLmsAccount(User::factory()->make()));
    }
}
