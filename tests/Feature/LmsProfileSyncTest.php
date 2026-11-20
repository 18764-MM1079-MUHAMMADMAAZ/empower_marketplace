<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\UpdateLmsProfile;
use App\Models\User;
use App\Services\MoodleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Gap 7 in lms-enrollment-plan.md: an email (or name) change is copied to the same Moodle user. */
class LmsProfileSyncTest extends TestCase
{
    use RefreshDatabase;

    /** ID number Moodle reports on the saved user 900 ('' = not set yet, so it's accepted). */
    private string $savedIdNumber = '';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.moodle.token' => 'test-token', 'services.moodle.base_url' => 'https://lms.test']);
        // The saved Moodle user 900 exists (no ID number yet, so it's accepted and linked).
        Http::fake(fn ($request) => Http::response(
            $request['wsfunction'] === 'core_user_get_users_by_field'
                ? ($request['field'] === 'id' ? [['id' => 900, 'idnumber' => $this->savedIdNumber]] : [])
                : null
        ));
    }

    private function updateCalls(): int
    {
        return Http::recorded(fn ($request) => $request['wsfunction'] === 'core_user_update_users')->count();
    }

    private function assertProfileSent(string $email, string $first, string $last): void
    {
        Http::assertSent(fn ($request) => $request['wsfunction'] === 'core_user_update_users'
            && $request['users'][0]['id'] === 900
            && $request['users'][0]['email'] === $email
            && $request['users'][0]['firstname'] === $first
            && $request['users'][0]['lastname'] === $last
            // Same account: never a new username, password or login method.
            && ! isset($request['users'][0]['username'], $request['users'][0]['password'], $request['users'][0]['auth']));
    }

    public function test_an_email_change_updates_the_same_moodle_user(): void
    {
        $user = User::factory()->create(['name' => 'Jane Provider', 'moodle_user_id' => 900]);

        $user->update(['email' => 'Jane.New@Practice.com']);

        $this->assertSame(1, $this->updateCalls());
        $this->assertProfileSent('jane.new@practice.com', 'Jane', 'Provider');
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lms.profile_updated', 'user_id' => $user->id]);
    }

    public function test_a_name_change_is_copied_too(): void
    {
        $user = User::factory()->create(['email' => 'jane@practice.com', 'moodle_user_id' => 900]);

        $user->update(['name' => 'Jane Q Provider']);

        $this->assertProfileSent('jane@practice.com', 'Jane', 'Q Provider');
    }

    public function test_an_admin_editing_the_users_email_reaches_moodle(): void
    {
        $user = User::factory()->create(['name' => 'Jane Provider', 'moodle_user_id' => 900]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->test('admin.user-form', ['user' => $user])
            ->set('email', 'jane.updated@practice.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertProfileSent('jane.updated@practice.com', 'Jane', 'Provider');
    }

    public function test_other_changes_and_users_without_a_moodle_account_are_left_alone(): void
    {
        $linked = User::factory()->create(['moodle_user_id' => 900]);
        $linked->update(['remember_token' => 'abc', 'notification_preferences' => ['x' => false]]);

        $unlinked = User::factory()->create();
        $unlinked->update(['email' => 'someone.else@practice.com']);

        Http::assertNothingSent();
    }

    public function test_the_job_sends_the_latest_details_and_skips_without_a_token(): void
    {
        $user = User::factory()->create(['name' => 'Jane Provider', 'moodle_user_id' => 900]);
        $job = new UpdateLmsProfile($user);
        $user->updateQuietly(['email' => 'latest@practice.com']);

        $job->handle(new MoodleClient);
        $this->assertProfileSent('latest@practice.com', 'Jane', 'Provider');

        config(['services.moodle.token' => '']);
        $job->handle(new MoodleClient);
        $this->assertSame(1, $this->updateCalls());
    }

    public function test_the_email_is_never_written_to_a_moodle_user_that_belongs_to_someone_else(): void
    {
        // The saved id now points at Marketplace user 777's account, and nothing else matches.
        $this->savedIdNumber = '777';
        $user = User::factory()->create(['moodle_user_id' => 900]);

        try {
            (new UpdateLmsProfile($user))->handle(new MoodleClient);
            $this->fail('Expected the update to stop.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No Moodle account found', $e->getMessage());
        }

        $this->assertSame(0, $this->updateCalls());
    }

    public function test_a_refused_update_is_retried_and_then_shown_to_admins(): void
    {
        Http::fake(fn () => Http::response(['exception' => 'invalid_parameter_exception', 'errorcode' => 'invalidparameter', 'message' => 'Email address already exists']));
        $user = User::factory()->create(['moodle_user_id' => 900]);
        $job = new UpdateLmsProfile($user);

        try {
            $job->handle(new MoodleClient);
            $this->fail('Expected Moodle to refuse the update.');
        } catch (RuntimeException|RequestException $e) {
            $job->failed($e);
        }

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lms.sync_failed', 'user_id' => $user->id]);
    }
}
