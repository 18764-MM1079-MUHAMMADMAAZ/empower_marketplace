<?php

namespace Tests\Feature;

use App\Enums\IntakeSubmissionStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\ProvisionLmsAccount;
use App\Models\IntakeSubmission;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\SpecialistCallRequest;
use App\Models\User;
use App\Notifications\ClientNotification;
use App\Services\MoodleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ClientNotificationBellTest extends TestCase
{
    use RefreshDatabase;

    private function submissionForClient(): IntakeSubmission
    {
        $client = User::factory()->create();
        Practice::factory()->create(['user_id' => $client->id]);
        $package = Package::where('slug', 'professional')->first()
            ?? Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $client->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::UnderReview,
        ]);

        return IntakeSubmission::factory()->create(['order_id' => $order->id, 'status' => IntakeSubmissionStatus::UnderReview, 'submitted_at' => now()]);
    }

    public function test_the_bell_shows_on_the_client_portal_but_a_guest_gets_none(): void
    {
        $client = User::factory()->create();

        $this->withoutVite()->actingAs($client)->get(route('portal'))->assertOk()->assertSee('aria-label="Notifications"', false);

        auth()->logout();
        $this->withoutVite()->get(route('portal'))->assertOk()->assertDontSee('aria-label="Notifications"', false);
    }

    public function test_a_client_sees_only_their_own_notifications_and_can_open_one(): void
    {
        $client = User::factory()->create();
        $other = User::factory()->create();
        $client->notify(new ClientNotification('Your submission was approved', 'All set.', route('portal')));
        $other->notify(new ClientNotification('Someone else’s notice', 'Private.'));

        Livewire::actingAs($client)
            ->test('notification-bell')
            ->assertSet('unreadCount', 1)
            ->assertSee('Your submission was approved')
            ->assertDontSee('Someone else’s notice')
            ->call('openNotification', $client->notifications()->first()->id)
            ->assertRedirect(route('portal'));

        $this->assertSame(0, $client->unreadNotifications()->count());
    }

    public function test_asking_a_reviewer_question_notifies_the_client(): void
    {
        Mail::fake();
        $submission = $this->submissionForClient();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.reviewer-questions', ['submissionId' => $submission->id])
            ->set('questionInput', 'Which policy is current?')
            ->call('ask');

        $notification = $submission->order->user->notifications()->first();
        $this->assertSame('Your reviewer has a question', $notification->data['title']);
        $this->assertStringContainsString('Which policy is current?', $notification->data['message']);
    }

    public function test_rejecting_and_approving_a_submission_notify_the_client(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $rejected = $this->submissionForClient();
        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $rejected])
            ->set('reviewerNotes', 'Please add your policy.')
            ->call('reject');

        $this->assertSame('Changes requested on your submission', $rejected->order->user->notifications()->first()->data['title']);

        $approved = $this->submissionForClient();
        Livewire::actingAs($admin)
            ->test('admin.submission-detail', ['submission' => $approved])
            ->call('approve');

        $this->assertSame('Your submission was approved', $approved->order->user->notifications()->first()->data['title']);
    }

    public function test_scheduling_or_cancelling_a_call_notifies_the_client_but_completing_does_not(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $call = SpecialistCallRequest::create([
            'user_id' => $client->id,
            'requested_date' => now()->addDay()->toDateString(),
            'requested_time' => '9:00 AM',
            'phone' => '+15551234567',
            'topic' => 'Pricing or billing',
        ]);

        $component = Livewire::actingAs($admin)->test('admin.specialist-call-list');
        $component->call('setStatus', $call->id, 'scheduled');
        $component->call('setStatus', $call->id, 'completed');
        $component->call('setStatus', $call->id, 'cancelled');

        $titles = $client->notifications->map(fn ($n) => $n->data['title'])->all();
        $this->assertCount(2, $titles);
        $this->assertContains('Your specialist call is confirmed', $titles);
        $this->assertContains('Your specialist call was cancelled', $titles);
    }

    public function test_first_lms_provisioning_notifies_the_client(): void
    {
        Mail::fake();
        config(['services.moodle.token' => 'test-token', 'services.moodle.base_url' => 'https://lms.test']);
        Http::fake(fn ($request) => Http::response($request['wsfunction'] === 'core_user_get_users_by_field' ? [['id' => 5]] : null));
        $client = User::factory()->create();

        (new ProvisionLmsAccount($client, 'Austin, TX 78701'))->handle(new MoodleClient);

        $this->assertSame('Your training access is ready', $client->notifications()->first()->data['title']);
    }
}
