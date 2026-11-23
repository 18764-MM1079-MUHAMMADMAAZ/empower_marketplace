<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PackageTier;
use App\Jobs\ProvisionLmsAccount;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\MoodleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Gap 5 in lms-enrollment-plan.md: LMS content matches the purchased package (spec §9). */
class PackageCoursesTest extends TestCase
{
    use RefreshDatabase;

    private const GENERAL = [6, 12, 13, 14, 15, 16, 18];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.moodle.token' => 'test-token', 'services.moodle.base_url' => 'https://lms.test']);
    }

    private function clientWith(string ...$slugs): User
    {
        $user = User::factory()->create();

        foreach ($slugs as $slug) {
            $package = Package::where('slug', $slug)->first() ?? Package::factory()->create(['slug' => $slug, 'is_active' => true]);
            Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        }

        return $user;
    }

    public function test_without_per_package_lists_every_package_gets_todays_courses(): void
    {
        $moodle = new MoodleClient;

        foreach ([PackageTier::Professional, PackageTier::Advanced, PackageTier::Complete] as $tier) {
            $this->assertSame([...self::GENERAL, 7], $moodle->courseIdsFor('Austin, TX 78701', [$tier]));
        }

        $this->assertSame(self::GENERAL, $moodle->courseIdsFor(null));
    }

    public function test_a_package_with_its_own_list_gets_those_courses_plus_the_state_course(): void
    {
        config(['services.moodle.package_course_ids' => ['professional' => [6, 12], 'advanced' => null, 'complete' => null]]);

        $this->assertSame([6, 12, 7], (new MoodleClient)->courseIdsFor('Austin, TX 78701', [PackageTier::Professional]));
        $this->assertSame([...self::GENERAL, 7], (new MoodleClient)->courseIdsFor('Austin, TX 78701', [PackageTier::Advanced]));
    }

    public function test_two_lms_packages_get_every_course_once(): void
    {
        config(['services.moodle.package_course_ids' => ['professional' => [6, 12], 'advanced' => [12, 20, 7], 'complete' => null]]);

        // The state course (TX = 7) is already in the Advanced list, so it isn't added twice.
        $this->assertSame([6, 12, 20, 7], (new MoodleClient)->courseIdsFor('Austin, TX 78701', [PackageTier::Professional, PackageTier::Advanced]));
    }

    public function test_the_clients_lms_packages_are_their_live_professional_advanced_or_complete_orders(): void
    {
        $client = $this->clientWith('essential', 'professional', 'complete');
        $cancelled = Package::factory()->create(['slug' => 'advanced']);
        Order::factory()->create(['user_id' => $client->id, 'package_id' => $cancelled->id, 'status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

        $this->assertSame([PackageTier::Professional, PackageTier::Complete], $client->lmsPackageTiers());
    }

    public function test_provisioning_enrols_the_courses_for_the_clients_package(): void
    {
        config(['services.moodle.package_course_ids' => ['professional' => [6, 12], 'advanced' => null, 'complete' => null]]);
        Http::fake(fn ($request) => Http::response($request['wsfunction'] === 'core_user_get_users_by_field' ? [['id' => 900]] : null));
        $client = $this->clientWith('professional');

        (new ProvisionLmsAccount($client, 'Austin, TX 78701'))->handle(new MoodleClient);

        $enrolled = Http::recorded(fn ($request) => $request['wsfunction'] === 'enrol_manual_enrol_users')
            ->map(fn ($pair) => $pair[0]['enrolments'][0]['courseid'])->values()->all();
        $this->assertSame([6, 12, 7], $enrolled);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'lms.provisioned', 'user_id' => $client->id]);
    }

    public function test_course_lists_in_env_are_read_as_comma_separated_ids(): void
    {
        $parse = fn (?string $ids) => array_values(array_filter(array_map('intval', explode(',', (string) $ids)))) ?: null;

        $this->assertSame([6, 12, 13], $parse('6, 12,13'));
        $this->assertNull($parse(''));
        $this->assertNull($parse(null));
    }
}
