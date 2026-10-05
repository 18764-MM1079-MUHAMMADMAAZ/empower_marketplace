<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    use RefreshDatabase;

    private function seedActivePackage(): void
    {
        Package::factory()->create(['slug' => 'essential', 'is_active' => true]);
    }

    public function test_guest_can_see_a_select_package_link_on_the_pricing_page(): void
    {
        $this->seedActivePackage();

        $response = $this->withoutVite()->get('/');

        $response->assertOk();
        $response->assertSeeText('Select Package');
    }

    /**
     * Mirrors the client prototype's selectPackage(): a logged-out visitor is routed through
     * account creation first (carrying the package), not straight into the guest-checkout
     * flow — matching the prototype's goAccount() vs goPortal() branch.
     */
    public function test_guest_selecting_a_package_is_routed_through_registration_not_straight_to_checkout(): void
    {
        $this->seedActivePackage();

        $response = $this->withoutVite()->get('/');

        $response->assertOk();
        $response->assertSee(route('register', ['package' => 'essential']), false);
        $response->assertDontSee(route('portal', ['package' => 'essential']), false);
    }

    public function test_authenticated_user_selecting_a_package_goes_straight_to_checkout(): void
    {
        $this->seedActivePackage();
        $user = User::factory()->create();

        $response = $this->withoutVite()->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee(route('portal', ['package' => 'essential']), false);
    }

    public function test_authenticated_user_can_see_a_select_package_link_on_the_pricing_page(): void
    {
        $this->seedActivePackage();
        $user = User::factory()->create();

        $response = $this->withoutVite()->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSeeText('Select Package');
    }

    /**
     * The pricing cards' "Empower Provides" / "You Provide" copy is fixed marketing content
     * (hardcoded in welcome.blade.php), independent of Package.features — which is still used
     * elsewhere (e.g. the dashboard's "Services included" line) — so an admin editing a
     * package's features must not change what the pricing page shows.
     */
    public function test_pricing_card_features_are_the_fixed_marketing_copy_not_the_package_record(): void
    {
        Package::factory()->create([
            'slug' => 'essential',
            'is_active' => true,
            'features' => ['A custom feature set by the admin', 'Another admin-defined feature'],
        ]);

        $response = $this->withoutVite()->get('/');

        $response->assertOk();
        $response->assertDontSee('A custom feature set by the admin');
        $response->assertDontSee('Another admin-defined feature');
        $response->assertSee('Empower Provides');
        $response->assertSee('Exclusions Screening');
    }

    public function test_an_unmatched_url_redirects_to_the_home_page(): void
    {
        $response = $this->get('/this-page-does-not-exist');

        $response->assertRedirect(route('home'));
    }

    /**
     * The package-picker quiz is a pure Alpine component (no Livewire backend — its
     * recommendation logic is a simple function of 2 answers) — this just confirms the trigger
     * button and all 3 question screens are actually in the markup, since there's no server-side
     * logic to unit test beyond "did the view render without error."
     */
    public function test_pricing_page_includes_the_package_picker_quiz(): void
    {
        Package::factory()->create(['slug' => 'essential', 'is_active' => true]);
        Package::factory()->create(['slug' => 'professional', 'is_active' => true]);
        Package::factory()->create(['slug' => 'advanced', 'is_active' => true]);

        $response = $this->withoutVite()->get('/');

        $response->assertOk();
        $response->assertSeeText('Take the 3-question quiz');
        $response->assertSeeText('Do you have written compliance and HIPAA policies today?');
        $response->assertSeeText('Do you also need a Security Risk Assessment or a coding audit this year?');
        $response->assertSeeText('How many billable providers do you have?');
        $response->assertSeeText('We recommend');
    }
}
