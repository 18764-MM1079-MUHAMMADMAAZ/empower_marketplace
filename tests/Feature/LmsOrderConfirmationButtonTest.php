<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Gap 13 (3b) in lms-enrollment-plan.md: "Go to LMS" on the order confirmation, spec §7. */
class LmsOrderConfirmationButtonTest extends TestCase
{
    use RefreshDatabase;

    private function paidStep1(string $slug, array $order = [], array $practice = []): Testable
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, ...$practice]);
        $package = Package::factory()->create(['slug' => $slug, 'annual_price' => 1299, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => now(),
            ...$order,
        ]);

        return Livewire::actingAs($user)->test('portal')->assertSet('step', 1);
    }

    public function test_an_lms_package_shows_the_navy_go_to_lms_button_after_paying(): void
    {
        $this->paidStep1('professional', ['lms_provisioned_at' => now()])
            ->assertSeeHtml('data-order-lms="open"')
            ->assertSeeHtml('data-order-lms-button')
            ->assertSeeHtml('href="'.route('lms.launch').'"')
            ->assertSee('Your courses are ready.');
    }

    public function test_while_the_lms_account_is_being_set_up_the_button_says_so(): void
    {
        $this->paidStep1('advanced')
            ->assertSeeHtml('data-order-lms-button')
            ->assertSee('Your courses are being set up.');
    }

    public function test_essential_shows_the_greyed_button_with_the_upgrade_message(): void
    {
        $this->paidStep1('essential')
            ->assertSeeHtml('data-order-lms="locked"')
            ->assertSeeHtml('data-order-lms-button-locked')
            ->assertDontSeeHtml('data-order-lms-button ')
            ->assertSee('Upgrade to Professional, Advanced or Complete to unlock training.');
    }

    public function test_a_talkehr_order_form_confirmation_shows_the_button_too(): void
    {
        $this->paidStep1('complete', ['payment_status' => PaymentStatus::ExternallyBilled, 'amount_paid' => 0], ['source_system' => 'talkehr'])
            ->assertSee('Your subscription is connected')
            ->assertSeeHtml('data-order-lms="open"');
    }
}
