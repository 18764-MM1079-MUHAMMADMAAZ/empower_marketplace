<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Gap 15 in lms-enrollment-plan.md: an email that's already registered is sent to Log in or Forgot password. */
class ExistingAccountCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function checkoutWithTakenEmail(): Testable
    {
        User::factory()->create(['email' => 'jane@practice.com']);
        $package = Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);

        return Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billableProviders', 3)
            ->set('accountName', 'Jane Provider')
            ->set('accountEmail', 'jane@practice.com')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true);
    }

    public function test_a_registered_email_gets_log_in_and_reset_links_and_no_second_account(): void
    {
        $this->checkoutWithTakenEmail()
            ->assertHasErrors(['accountEmail'])
            ->assertSee('An account with this email already exists.')
            ->assertSee('data-email-taken', false)
            ->assertSee('Log in')
            ->assertSee('reset your password')
            // Both links keep the email and the chosen package, cycle and provider count.
            ->assertSee(e(route('login', ['email' => 'jane@practice.com', 'package' => 'professional', 'billing_cycle' => 'annual', 'billable_providers' => 3])), false)
            ->assertSee(e(route('password.request', ['email' => 'jane@practice.com', 'package' => 'professional', 'billing_cycle' => 'annual', 'billable_providers' => 3])), false);

        $this->assertSame(1, User::where('email', 'jane@practice.com')->count());
        $this->assertSame(0, Order::count());
    }

    public function test_other_email_errors_keep_their_plain_message(): void
    {
        Livewire::test('portal')
            ->set('accountEmail', 'not-an-email')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasErrors(['accountEmail'])
            ->assertDontSee('data-email-taken', false);
    }

    public function test_the_login_link_prefills_the_email_and_returns_to_the_chosen_package(): void
    {
        $user = User::factory()->create(['email' => 'jane@practice.com', 'password' => 'Secret-Password-1!']);
        Order::factory()->create(['user_id' => $user->id]);

        $this->get(route('login', ['email' => 'jane@practice.com', 'package' => 'professional', 'billing_cycle' => 'annual', 'billable_providers' => 3]))
            ->assertOk();
        $this->assertSame('professional', session('intended_package'));

        Livewire::withQueryParams(['email' => 'jane@practice.com'])
            ->test('auth.login-form')
            ->assertSet('email', 'jane@practice.com')
            ->set('password', 'Secret-Password-1!')
            ->call('login')
            ->assertRedirect(route('portal', ['package' => 'professional', 'billing_cycle' => 'annual', 'billable_providers' => 3]));
    }

    public function test_the_reset_link_prefills_the_email_and_remembers_the_package(): void
    {
        $this->get(route('password.request', ['email' => 'jane@practice.com', 'package' => 'professional']))->assertOk();
        $this->assertSame('professional', session('intended_package'));

        Livewire::withQueryParams(['email' => 'jane@practice.com'])
            ->test('auth.forgot-password-form')
            ->assertSet('email', 'jane@practice.com');
    }
}
