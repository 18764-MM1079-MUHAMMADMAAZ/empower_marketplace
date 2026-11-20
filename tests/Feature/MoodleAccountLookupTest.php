<?php

namespace Tests\Feature;

use App\Jobs\ProvisionLmsAccount;
use App\Models\User;
use App\Services\MoodleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Gap 8 in lms-enrollment-plan.md: the Moodle account is found by the IDs the two systems keep for
 * each other (saved Moodle id, cross-checked against its ID number), then ID number, username, and
 * email only for accounts made before single sign-on. Never a guess, never a duplicate.
 */
class MoodleAccountLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.moodle.token' => 'test-token', 'services.moodle.base_url' => 'https://lms.test']);
    }

    /** @param  array<string, array<int, array<string, mixed>>>  $byField  Moodle users returned per lookup field */
    private function moodleHas(array $byField): void
    {
        Http::fake(fn ($request) => Http::response(match ($request['wsfunction']) {
            'core_user_get_users_by_field' => $byField[$request['field']] ?? [],
            'core_user_create_users' => [['id' => 5000]],
            default => null,
        }));
    }

    private function lookups(): array
    {
        return Http::recorded(fn ($request) => $request['wsfunction'] === 'core_user_get_users_by_field')
            ->map(fn ($pair) => $pair[0]['field'])->values()->all();
    }

    public function test_the_saved_moodle_id_is_used_when_its_id_number_confirms_it(): void
    {
        $user = User::factory()->create(['moodle_user_id' => 900]);
        $this->moodleHas(['id' => [['id' => 900, 'idnumber' => (string) $user->id]]]);

        $this->assertSame(['id' => 900, 'warning' => null], (new MoodleClient)->findLinkedUser($user));
        $this->assertSame(['id'], $this->lookups());
    }

    public function test_a_saved_id_whose_id_number_belongs_to_someone_else_is_not_used(): void
    {
        $user = User::factory()->create(['moodle_user_id' => 900]);
        $this->moodleHas([
            'id' => [['id' => 900, 'idnumber' => '777']],
            'idnumber' => [['id' => 42, 'idnumber' => (string) $user->id]],
        ]);

        $found = (new MoodleClient)->findLinkedUser($user);

        $this->assertSame(42, $found['id']);
        $this->assertStringContainsString('has ID number 777', $found['warning']);
    }

    public function test_a_saved_id_that_no_longer_exists_falls_back_to_the_id_number(): void
    {
        $user = User::factory()->create(['moodle_user_id' => 900]);
        $this->moodleHas(['idnumber' => [['id' => 42, 'idnumber' => (string) $user->id]]]);

        $found = (new MoodleClient)->findLinkedUser($user);

        $this->assertSame(42, $found['id']);
        $this->assertStringContainsString('no longer exists', $found['warning']);
    }

    public function test_without_a_saved_id_the_id_number_is_checked_first(): void
    {
        $user = User::factory()->create();
        $this->moodleHas(['idnumber' => [['id' => 42, 'idnumber' => (string) $user->id]]]);

        $this->assertSame(42, (new MoodleClient)->findLinkedUser($user)['id']);
        $this->assertSame(['idnumber'], $this->lookups());
    }

    public function test_two_moodle_users_with_the_same_id_number_stop_provisioning(): void
    {
        $user = User::factory()->create();
        $this->moodleHas(['idnumber' => [['id' => 42], ['id' => 43]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2 Moodle users have ID number '.$user->id.' (Moodle users #42, #43)');

        (new MoodleClient)->findLinkedUser($user);
    }

    public function test_an_account_the_sign_in_plugin_created_is_found_by_username_and_linked(): void
    {
        $user = User::factory()->create(['name' => 'Jane Provider']);
        $this->moodleHas(['username' => [['id' => 61, 'idnumber' => '']]]);

        (new ProvisionLmsAccount($user))->handle(new MoodleClient);

        $this->assertSame(['idnumber', 'username'], $this->lookups());
        Http::assertNotSent(fn ($request) => $request['wsfunction'] === 'core_user_create_users');
        // Linked and updated: ID number written, plus the latest email and name.
        Http::assertSent(fn ($request) => $request['wsfunction'] === 'core_user_update_users'
            && $request['users'][0]['id'] === 61
            && $request['users'][0]['idnumber'] === (string) $user->id
            && $request['users'][0]['firstname'] === 'Jane');
        $this->assertSame(61, $user->fresh()->moodle_user_id);
    }

    public function test_an_email_match_that_belongs_to_another_marketplace_user_is_never_taken_over(): void
    {
        $user = User::factory()->create(['email' => 'shared@practice.com']);
        $this->moodleHas(['email' => [['id' => 70, 'idnumber' => '777']]]);

        try {
            (new ProvisionLmsAccount($user))->handle(new MoodleClient);
            $this->fail('Expected provisioning to stop.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('belongs to Marketplace user 777', $e->getMessage());
        }

        Http::assertNotSent(fn ($request) => in_array($request['wsfunction'], ['core_user_create_users', 'core_user_update_users'], true));
    }

    public function test_when_moodle_hides_the_id_number_the_saved_id_is_still_used(): void
    {
        $user = User::factory()->create(['moodle_user_id' => 900]);
        $this->moodleHas(['id' => [['id' => 900]]]);

        $this->assertSame(900, (new MoodleClient)->findLinkedUser($user)['id']);
    }

    public function test_provisioning_records_a_warning_when_the_saved_id_was_wrong(): void
    {
        $user = User::factory()->create(['moodle_user_id' => 900]);
        $this->moodleHas(['idnumber' => [['id' => 42, 'idnumber' => (string) $user->id]]]);

        (new ProvisionLmsAccount($user))->handle(new MoodleClient);

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lms.link_warning', 'user_id' => $user->id]);
        $this->assertSame(42, $user->fresh()->moodle_user_id);
    }
}
