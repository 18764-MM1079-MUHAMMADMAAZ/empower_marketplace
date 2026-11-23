<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The provider count entered at checkout survives signing in part-way (e.g. with talkEHR). */
class CheckoutProviderCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_package_and_provider_count_picked_at_checkout_are_remembered_for_sign_in(): void
    {
        Package::factory()->create(['slug' => 'advanced', 'is_active' => true]);

        Livewire::withQueryParams(['package' => 'advanced', 'billing_cycle' => 'annual'])
            ->test('portal')
            ->call('incrementProviders')
            ->set('billableProviders', 5);

        $this->assertSame('advanced', session('intended_package'));
        $this->assertSame('annual', session('intended_billing_cycle'));
        $this->assertSame(5, session('intended_billable_providers'));
    }

    public function test_a_talkehr_client_signing_in_gets_the_provider_count_they_entered(): void
    {
        $package = Package::factory()->create(['slug' => 'advanced', 'is_active' => true]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'source_system' => 'talkehr', 'billable_providers_count' => 0]);
        session(['intended_billable_providers' => 5]);

        Livewire::actingAs($user)
            ->withQueryParams(['package' => 'advanced'])
            ->test('portal')
            ->assertSet('selectedPackageId', $package->id)
            ->assertSet('billableProviders', 5);
    }

    public function test_the_practices_saved_provider_count_still_wins(): void
    {
        Package::factory()->create(['slug' => 'advanced', 'is_active' => true]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'source_system' => 'talkehr', 'billable_providers_count' => 3]);
        session(['intended_billable_providers' => 5]);

        Livewire::actingAs($user)
            ->withQueryParams(['package' => 'advanced'])
            ->test('portal')
            ->assertSet('billableProviders', 3);
    }
}
