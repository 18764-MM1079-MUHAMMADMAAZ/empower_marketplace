<?php

namespace Tests\Feature;

use App\Enums\IntakeSubmissionStatus;
use App\Enums\OrderStatus;
use App\Enums\PackageTier;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\ProvisionLmsAccount;
use App\Mail\ClientLmsAccessMail;
use App\Models\ActivityLog;
use App\Models\IntakeSubmission;
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
use RuntimeException;
use Tests\TestCase;

class MoodleProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.moodle.token' => 'test-token', 'services.moodle.base_url' => 'https://lms.test']);
    }

    public function test_only_non_essential_tiers_include_lms_access(): void
    {
        $this->assertFalse(PackageTier::Essential->includesLmsAccess());
        $this->assertTrue(PackageTier::Professional->includesLmsAccess());
        $this->assertTrue(PackageTier::Advanced->includesLmsAccess());
        $this->assertTrue(PackageTier::Complete->includesLmsAccess());
    }

    public function test_state_is_read_from_an_address_or_a_state_name(): void
    {
        $moodle = new MoodleClient;

        $this->assertSame('TX', $moodle->stateOf('12 Main St, Austin, TX 78701'));
        $this->assertSame('NY', $moodle->stateOf('NY'));
        $this->assertSame('CO', $moodle->stateOf('Denver, Colorado'));
        $this->assertNull($moodle->stateOf('1 Clyde Rd, Somerset, NJ 08873'));
        $this->assertNull($moodle->stateOf(null));
    }

    public function test_courses_are_the_shared_set_plus_the_practices_state_course(): void
    {
        $moodle = new MoodleClient;

        $this->assertSame([6, 12, 13, 14, 15, 16, 18, 7], $moodle->courseIdsFor('Austin, TX 78701'));
        $this->assertSame([6, 12, 13, 14, 15, 16, 18], $moodle->courseIdsFor('Somerset, NJ 08873'));
    }

    public function test_job_creates_the_moodle_user_enrols_them_and_records_it(): void
    {
        Http::fake(fn ($request) => Http::response(match ($request['wsfunction']) {
            'core_user_get_users_by_field' => [],
            'core_user_create_users' => [['id' => 4321]],
            default => null,
        }));
        $user = User::factory()->create(['name' => 'Jane Provider', 'email' => 'Jane@Practice.com']);

        (new ProvisionLmsAccount($user, 'Austin, TX 78701'))->handle(new MoodleClient);

        $this->assertSame(4321, $user->fresh()->moodle_user_id);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lms.provisioned', 'user_id' => $user->id]);

        Http::assertSent(fn ($request) => $request['wsfunction'] === 'core_user_create_users'
            && $request['users'][0]['username'] === 'jane@practice.com'
            && $request['users'][0]['firstname'] === 'Jane'
            && $request['users'][0]['lastname'] === 'Provider'
            && $request['users'][0]['createpassword'] === 1);
        Http::assertSentCount(2 + 8);
        Http::assertSent(fn ($request) => $request['wsfunction'] === 'enrol_manual_enrol_users'
            && $request['enrolments'][0]['courseid'] === 7
            && $request['enrolments'][0]['userid'] === 4321);
    }

    public function test_one_course_failing_does_not_block_the_others_but_fails_the_job(): void
    {
        Http::fake(fn ($request) => Http::response(match (true) {
            $request['wsfunction'] === 'core_user_get_users_by_field' => [['id' => 5]],
            ($request['enrolments'][0]['courseid'] ?? null) === 13 => ['exception' => 'moodle_exception', 'errorcode' => 'wsnoinstance', 'message' => 'no manual enrolment'],
            default => null,
        }));
        $user = User::factory()->create();

        try {
            (new ProvisionLmsAccount($user))->handle(new MoodleClient);
            $this->fail('Expected the job to fail so it is retried.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('course(s) 13', $e->getMessage());
        }

        Http::assertSentCount(1 + 7);
        $this->assertSame(5, $user->fresh()->moodle_user_id);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lms.provisioned', 'user_id' => $user->id]);
    }

    public function test_job_reuses_an_existing_moodle_user(): void
    {
        Http::fake(fn ($request) => Http::response($request['wsfunction'] === 'core_user_get_users_by_field' ? [['id' => 99]] : null));
        $user = User::factory()->create();

        (new ProvisionLmsAccount($user))->handle(new MoodleClient);

        Http::assertNotSent(fn ($request) => $request['wsfunction'] === 'core_user_create_users');
        $this->assertSame(99, $user->fresh()->moodle_user_id);
    }

    public function test_a_moodle_error_fails_the_job_so_it_is_retried(): void
    {
        Http::fake(['lms.test/*' => Http::response(['exception' => 'moodle_exception', 'errorcode' => 'invalidtoken', 'message' => 'Invalid token'])]);

        $this->expectExceptionMessage('invalidtoken');

        (new ProvisionLmsAccount(User::factory()->create()))->handle(new MoodleClient);
    }

    public function test_job_does_nothing_when_no_token_is_configured(): void
    {
        config(['services.moodle.token' => '']);
        Http::fake();

        (new ProvisionLmsAccount(User::factory()->create()))->handle(new MoodleClient);

        Http::assertNothingSent();
    }

    private function submitIntakeFor(string $slug): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'address' => '12 Main St, Austin, TX 78701']);
        $package = Package::factory()->create(['slug' => $slug, 'annual_price' => 1299, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'done',
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('certifiedByName', 'Jane Provider')
            ->set('certifiedByTitle', 'Owner')
            ->set('certifiedSignature', 'Jane Provider')
            ->set('certifyChecked', true)
            ->call('finalizeIntake');
    }

    public function test_submitting_a_professional_intake_dispatches_lms_provisioning_with_the_practice_address(): void
    {
        Bus::fake([ProvisionLmsAccount::class]);

        $this->submitIntakeFor('professional');

        Bus::assertDispatched(ProvisionLmsAccount::class, fn (ProvisionLmsAccount $job) => $job->addressOrState === '12 Main St, Austin, TX 78701');
    }

    public function test_submitting_an_essential_intake_does_not_provision_the_lms(): void
    {
        Bus::fake([ProvisionLmsAccount::class]);

        $this->submitIntakeFor('essential');

        Bus::assertNotDispatched(ProvisionLmsAccount::class);
    }

    private function submissionFor(string $slug): IntakeSubmission
    {
        $user = User::factory()->create([]);
        Practice::factory()->create(['user_id' => $user->id, 'address' => '12 Main St, Austin, TX 78701']);
        $package = Package::factory()->create(['slug' => $slug, 'annual_price' => 1299, 'is_active' => true]);
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);

        return IntakeSubmission::factory()->create(['order_id' => $order->id, 'status' => IntakeSubmissionStatus::Submitted, 'submitted_at' => now()]);
    }

    public function test_admin_sees_the_lms_card_only_for_packages_with_lms_access(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $this->submissionFor('professional')])
            ->assertSee('Empower LMS (Moodle)')
            ->assertSee('Not provisioned');

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $this->submissionFor('essential')])
            ->assertDontSee('Empower LMS (Moodle)');
    }

    public function test_admin_can_provision_lms_access_from_the_submission_page(): void
    {
        Http::fake(fn ($request) => Http::response(match ($request['wsfunction']) {
            'core_user_get_users_by_field' => [['id' => 77]],
            default => null,
        }));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->submissionFor('professional');

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->call('provisionLms')
            ->assertSee('Provisioned')
            ->assertSee('8 of 8 courses enrolled');

        $this->assertSame(77, $submission->order->user->fresh()->moodle_user_id);
    }

    public function test_the_lms_card_shows_a_partial_enrolment_and_failures(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $submission = $this->submissionFor('professional');
        $client = $submission->order->user;

        ActivityLog::record('lms.provisioned', 'Empower LMS account for client@practice.com: 6 of 8 courses enrolled.', user: $client, metadata: ['failed_course_ids' => [13, 7]]);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertSee('Partly enrolled')
            ->assertSee('Not enrolled: course 13, 7.');

        ActivityLog::record('lms.failed', 'Empower LMS provisioning failed for client@practice.com: Invalid token', user: $client);

        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $submission])
            ->assertSee('Failed')
            ->assertSee('Invalid token');
    }

    public function test_an_exhausted_job_is_logged_for_admins(): void
    {
        $user = User::factory()->create(['email' => 'client@practice.com']);

        (new ProvisionLmsAccount($user))->failed(new RuntimeException('Moodle core_user_create_users failed: invalidtoken'));

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lms.failed', 'user_id' => $user->id]);
    }

    public function test_client_gets_one_welcome_email_on_first_provisioning_only(): void
    {
        Mail::fake();
        Http::fake(fn ($request) => Http::response($request['wsfunction'] === 'core_user_get_users_by_field' ? [] : ($request['wsfunction'] === 'core_user_create_users' ? [['id' => 50]] : null)));
        $user = User::factory()->create(['email' => 'client@practice.com']);

        (new ProvisionLmsAccount($user, 'Austin, TX 78701'))->handle(new MoodleClient);

        Mail::assertSent(ClientLmsAccessMail::class, fn ($mail) => $mail->hasTo('client@practice.com')
            && $mail->newAccount && $mail->courseCount === 8
            && str_contains($mail->render(), 'set your password'));

        (new ProvisionLmsAccount($user->fresh(), 'Austin, TX 78701'))->handle(new MoodleClient);

        Mail::assertSent(ClientLmsAccessMail::class, 1);
    }

    public function test_no_welcome_email_when_a_course_failed_to_enrol(): void
    {
        Mail::fake();
        Http::fake(fn ($request) => Http::response(match (true) {
            $request['wsfunction'] === 'core_user_get_users_by_field' => [['id' => 5]],
            ($request['enrolments'][0]['courseid'] ?? null) === 13 => ['exception' => 'moodle_exception', 'errorcode' => 'wsnoinstance', 'message' => 'no manual enrolment'],
            default => null,
        }));

        try {
            (new ProvisionLmsAccount(User::factory()->create()))->handle(new MoodleClient);
        } catch (RuntimeException) {
        }

        Mail::assertNothingSent();
    }
}
