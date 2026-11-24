<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\GeneratedDocument;
use App\Models\IntakeSubmission;
use App\Models\Order;
use App\Models\Practice;
use App\Models\User;
use App\Services\ClientActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** My Account › Activity Log (v5): the five activity types, filters, pages, exports and the new events. */
class ClientActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function client(): array
    {
        $user = User::factory()->create(['name' => 'Wayne Best', 'email' => 'wayne@practice.com', 'password' => 'Secret-Pass-123!']);
        Practice::factory()->create(['user_id' => $user->id, 'name' => 'Testing Practice']);
        $order = Order::factory()->create(['user_id' => $user->id, 'payment_status' => PaymentStatus::Paid]);

        return [$user, $order];
    }

    public function test_only_the_five_types_are_listed_with_who_did_them(): void
    {
        [$user, $order] = $this->client();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        ActivityLog::record('auth.login', 'Signed in with email & password', user: $user);
        ActivityLog::record('submission.approved', 'Compliance review approved', user: $admin, order: $order);
        ActivityLog::record('lms.provisioned', 'Empower LMS account created', user: $user);
        ActivityLog::record('order.test_created', 'Test order', user: $user);
        ActivityLog::record('auth.login', 'Someone else signed in', user: User::factory()->create());
        $this->actingAs($user);

        Livewire::test('account.activity-log')
            ->assertSee('Signed in with email & password')
            ->assertSee('Wayne Best')
            ->assertSee('Compliance review approved')
            ->assertSee('Empower compliance team')
            ->assertDontSee('Empower LMS account created')
            ->assertDontSee('Test order')
            ->assertDontSee('Someone else signed in')
            ->assertSee('Showing 1–2 of 2 activities · Times shown in '.now('America/New_York')->format('T'));
    }

    public function test_type_date_range_and_search_filter_the_list(): void
    {
        [$user] = $this->client();
        ActivityLog::record('auth.login', 'Signed in with email & password', user: $user);
        ActivityLog::record('document.downloaded', 'Downloaded HIPAA Security Manual', user: $user);
        $old = ActivityLog::record('account.password_changed', 'Changed password', user: $user);
        $old->forceFill(['created_at' => now()->subDays(20)])->save();
        $this->actingAs($user);

        Livewire::test('account.activity-log')
            ->assertDontSee('Changed password')
            ->set('range', '30')
            ->assertSee('Changed password')
            ->set('type', 'documents')
            ->assertSee('Downloaded HIPAA Security Manual')
            ->assertDontSee('Signed in with email')
            ->set('type', 'all')
            ->set('search', 'hipaa')
            ->assertSee('Downloaded HIPAA Security Manual')
            ->assertDontSee('Changed password')
            ->set('search', 'nothing like this')
            ->assertSee('No activity matches these filters.');
    }

    public function test_ten_entries_per_page(): void
    {
        [$user] = $this->client();
        foreach (range(1, 12) as $n) {
            ActivityLog::record('auth.login', "Sign-in number {$n}", user: $user);
        }
        $this->actingAs($user);

        Livewire::test('account.activity-log')
            ->assertSee('Showing 1–10 of 12')
            ->assertSee('Sign-in number 12')
            ->assertDontSee('Sign-in number 2<', false)
            ->call('goTo', 2)
            ->assertSee('Showing 11–12 of 12')
            ->assertSee('Sign-in number 1');
    }

    public function test_a_wrong_password_is_logged_as_a_failed_sign_in_without_revealing_the_account(): void
    {
        [$user] = $this->client();

        Livewire::test('auth.login-form')
            ->set('email', 'wayne@practice.com')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['email']);

        Livewire::test('auth.login-form')
            ->set('email', 'nobody@practice.com')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['email']);

        $this->assertSame(1, ActivityLog::where('event_type', 'auth.login_failed')->count());
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'auth.login_failed', 'user_id' => $user->id]);

        $this->actingAs($user);
        Livewire::test('account.activity-log')
            ->assertSee('Sign-in attempt: incorrect password')
            ->assertSee('Failed')
            ->assertSeeHtml('data-activity-row="security"');
    }

    public function test_signing_in_is_logged_and_remembered_on_the_session(): void
    {
        [$user] = $this->client();

        Livewire::test('auth.login-form')
            ->set('email', 'wayne@practice.com')
            ->set('password', 'Secret-Pass-123!')
            ->call('login');

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'auth.login', 'user_id' => $user->id, 'description' => 'Signed in with email & password']);
        $this->assertSame('password', session('sign_in_method'));
    }

    public function test_document_and_intake_answer_downloads_are_logged(): void
    {
        Storage::fake('local');
        [$user, $order] = $this->client();
        Storage::disk('local')->put('docs/manual.pdf', '%PDF');
        $document = GeneratedDocument::factory()->completed()->approved()->create(['order_id' => $order->id, 'pdf_storage_path' => 'docs/manual.pdf']);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id]);

        $this->actingAs($user)->get(route('documents.download', $document))->assertOk();
        $this->actingAs($user)->get(route('intake-submissions.answers', $submission))->assertOk();

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'document.downloaded', 'user_id' => $user->id, 'description' => 'Downloaded '.$document->document_type->label()]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'intake_answers.downloaded', 'user_id' => $user->id]);
    }

    public function test_the_list_exports_as_csv_and_pdf_with_the_filters(): void
    {
        [$user] = $this->client();
        ActivityLog::record('auth.login', 'Signed in with email & password', user: $user);
        ActivityLog::record('document.downloaded', 'Downloaded HIPAA Security Manual', user: $user);

        $csv = $this->actingAs($user)->get(route('account.activity.export', ['format' => 'csv', 'type' => 'documents']));
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $csv->getContent();
        $this->assertStringContainsString('"When ('.now('America/New_York')->format('T').')"', $body);
        $this->assertStringContainsString('Downloaded HIPAA Security Manual', $body);
        $this->assertStringNotContainsString('Signed in with email', $body);

        $pdf = $this->actingAs($user)->get(route('account.activity.export', ['format' => 'pdf']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_an_admin_sets_the_user_type_and_the_client_sees_it_in_their_activity(): void
    {
        [$user] = $this->client();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->test('admin.user-form', ['user' => $user])
            ->assertSet('userType', 'actual')
            ->assertSeeHtml('data-user-type-select')
            ->set('userType', 'dummy')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('dummy', $user->fresh()->user_type);

        Livewire::actingAs($user)->test('account.activity-log')
            ->assertSee('User type changed to Dummy user by the Empower team')
            ->assertSeeHtml('data-activity-row="account"');
    }

    public function test_admins_cannot_use_the_client_export(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get(route('account.activity.export', ['format' => 'csv']))->assertNotFound();
    }

    public function test_every_listed_event_has_one_of_the_five_types(): void
    {
        foreach (ClientActivity::EVENTS as $type) {
            $this->assertArrayHasKey($type, ClientActivity::TYPES);
        }
    }
}
