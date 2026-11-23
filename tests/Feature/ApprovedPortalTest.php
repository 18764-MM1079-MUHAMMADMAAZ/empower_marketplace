<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\IntakeSubmission;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Once the intake is approved, the client only has the Dashboard, and is congratulated once. */
class ApprovedPortalTest extends TestCase
{
    use RefreshDatabase;

    private function approvedClient(string $slug = 'advanced'): User
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => $slug, 'annual_price' => 2499, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Approved,
        ]);
        IntakeSubmission::factory()->approved()->create(['order_id' => $order->id]);

        return $user;
    }

    public function test_an_approved_client_lands_on_the_dashboard_without_the_step_bar(): void
    {
        Livewire::actingAs($this->approvedClient())
            ->test('portal')
            ->assertSet('step', 5)
            ->assertDontSeeHtml('data-stepper');
    }

    public function test_an_approved_client_cannot_go_back_to_the_intake_steps(): void
    {
        $component = Livewire::actingAs($this->approvedClient())->test('portal');

        foreach ([1, 2, 3, 4] as $step) {
            $component->call('goToStep', $step)->assertSet('step', 5);
            $this->assertFalse($component->instance()->canReach($step));
        }

        $component->call('onGoToPaymentStep')->assertSet('step', 5)
            ->assertDontSee('Back to Review');
    }

    public function test_the_first_visit_after_approval_shows_the_congratulations_message_once(): void
    {
        $user = $this->approvedClient('advanced');

        Livewire::actingAs($user)->test('portal')
            ->assertSeeHtml('data-approval-welcome')
            ->assertSee('Congratulations!')
            ->assertSee('Your profile has been approved. Thank you for choosing the Advanced Plan.')
            ->call('dismissApprovalWelcome')
            ->assertDontSeeHtml('data-approval-welcome');

        $this->assertNotNull(IntakeSubmission::first()->approval_welcome_shown_at);

        Livewire::actingAs($user)->test('portal')
            ->assertSet('step', 5)
            ->assertDontSeeHtml('data-approval-welcome');
    }

    public function test_a_client_who_is_not_yet_approved_keeps_the_step_bar_and_no_message(): void
    {
        $user = $this->approvedClient();
        IntakeSubmission::first()->update(['status' => 'under_review']);

        Livewire::actingAs($user)->test('portal')
            ->assertSet('step', 4)
            ->assertSeeHtml('data-stepper')
            ->assertDontSeeHtml('data-approval-welcome');

        $this->assertNull(IntakeSubmission::first()->approval_welcome_shown_at);
    }
}
