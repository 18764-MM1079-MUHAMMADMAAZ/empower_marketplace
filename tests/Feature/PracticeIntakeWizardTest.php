<?php

namespace Tests\Feature;

use App\Enums\IntakeSubmissionStatus;
use App\Enums\IntakeUploadType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CompliancePolicy;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class PracticeIntakeWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    /** Two sections, three questions total — enough to exercise ordering, skip, and completion
     *  without depending on the full 66-question production seed. */
    private function seedWorkflowQuestions(): void
    {
        $policyA = CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-01', 'title' => 'Oversight', 'requirements' => ['bullets' => ['Names who oversees the program.']]]);
        $policyB = CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-02', 'title' => 'Management', 'requirements' => ['bullets' => ['Names who supervises staff.']]]);
        $policyC = CompliancePolicy::create(['manual' => 'hipaa_security_manual', 'code' => 'SEC-01', 'title' => 'Access control', 'requirements' => ['bullets' => ['Describes access review cadence.']]]);

        $sectionOne = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $sectionTwo = IntakeSection::create(['key' => 'security', 'label' => 'Security', 'sort_order' => 2]);

        $q1 = IntakeQuestion::create(['intake_section_id' => $sectionOne->id, 'sort_order' => 1, 'title' => 'Owner & board oversight']);
        $q1->policies()->attach($policyA->id);

        $q2 = IntakeQuestion::create(['intake_section_id' => $sectionOne->id, 'sort_order' => 2, 'title' => "Management's role"]);
        $q2->policies()->attach($policyB->id);

        $q3 = IntakeQuestion::create(['intake_section_id' => $sectionTwo->id, 'sort_order' => 1, 'title' => 'Access control policy']);
        $q3->policies()->attach($policyC->id);
    }

    private function makeEssentialOrder(User $user): Order
    {
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        return Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
    }

    private function makeProfessionalOrder(User $user): Order
    {
        $package = Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);

        return Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
    }

    // ── Documents screen ──────────────────────────────────────────────────

    public function test_essential_tier_requires_at_least_one_document_before_continuing(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('continueFromDocuments')
            ->assertHasErrors(['documentFiles'])
            ->assertSet('screen', 'documents');
    }

    public function test_professional_tier_documents_screen_is_optional(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('continueFromDocuments')
            ->assertHasNoErrors()
            ->assertSet('screen', 'b_profile');
    }

    public function test_uploading_a_document_stores_it_against_the_draft_submission(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('documentFiles', [UploadedFile::fake()->create('handbook.pdf', 100, 'application/pdf')])
            ->set('documentFileTags.0', 'compliance_ethics')
            ->call('toggleDocumentMissing', 'hipaa_privacy')
            ->call('toggleDocumentMissing', 'hipaa_security')
            ->call('toggleDocumentMissing', 'training_materials')
            ->call('continueFromDocuments');

        $component->assertHasNoErrors()->assertSet('screen', 'b_profile');

        $this->assertDatabaseHas('intake_submissions', [
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft->value,
        ]);
        $this->assertDatabaseHas('intake_uploads', [
            'original_filename' => 'handbook.pdf',
            'upload_type' => IntakeUploadType::ClientDocumentForReview->value,
            'document_category' => 'compliance_ethics',
        ]);
    }

    public function test_essential_tier_requires_every_document_category_to_be_uploaded_or_declined(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('documentFiles', [UploadedFile::fake()->create('handbook.pdf', 100, 'application/pdf')])
            ->set('documentFileTags.0', 'compliance_ethics')
            ->call('continueFromDocuments')
            ->assertHasErrors(['documentFiles'])
            ->assertSet('screen', 'documents');
    }

    public function test_essential_tier_requires_uploaded_files_to_be_tagged(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('documentFiles', [UploadedFile::fake()->create('random.pdf', 100, 'application/pdf')])
            ->call('toggleDocumentMissing', 'hipaa_privacy')
            ->call('toggleDocumentMissing', 'hipaa_security')
            ->call('toggleDocumentMissing', 'training_materials')
            ->call('continueFromDocuments')
            ->assertHasErrors(['documentFiles'])
            ->assertSet('screen', 'documents');
    }

    public function test_marking_all_document_categories_as_declined_still_requires_one_upload(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('toggleDocumentMissing', 'compliance_ethics')
            ->call('toggleDocumentMissing', 'hipaa_privacy')
            ->call('toggleDocumentMissing', 'hipaa_security')
            ->call('toggleDocumentMissing', 'training_materials')
            ->call('continueFromDocuments')
            ->assertHasErrors(['documentFiles'])
            ->assertSet('screen', 'documents');
    }

    public function test_toggling_a_declined_document_category_undoes_it(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('toggleDocumentMissing', 'hipaa_privacy');

        $this->assertSame('declined', $component->instance()->documentCategoryStatus('hipaa_privacy'));

        $component->call('toggleDocumentMissing', 'hipaa_privacy');

        $this->assertSame('needed', $component->instance()->documentCategoryStatus('hipaa_privacy'));
    }

    public function test_skipping_the_documents_screen_bypasses_validation_and_advances(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('continueFromDocuments', true)
            ->assertHasNoErrors()
            ->assertSet('screen', 'b_profile');
    }

    public function test_saving_the_documents_screen_for_later_stays_put_and_flags_saved(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('documentFiles', [UploadedFile::fake()->create('handbook.pdf', 100, 'application/pdf')])
            ->set('documentFileTags.0', 'compliance_ethics')
            ->call('continueFromDocuments', true, true)
            ->assertHasNoErrors()
            ->assertSet('screen', 'documents')
            ->assertSet('justSaved', true);

        $this->assertDatabaseHas('intake_uploads', [
            'original_filename' => 'handbook.pdf',
            'document_category' => 'compliance_ethics',
        ]);
    }

    // ── Basics: 1) Profile ───────────────────────────────────────────────────

    public function test_profile_screen_requires_practice_name_and_specialty(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_profile')
            ->set('practiceName', '')
            ->set('specialty', '')
            ->call('continueFromProfile')
            ->assertHasErrors(['practiceName', 'specialty']);
    }

    public function test_completing_the_profile_screen_locks_the_practice_name(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_profile')
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('specialty', 'General Practice')
            ->call('continueFromProfile')
            ->assertHasNoErrors()
            ->assertSet('screen', 'b_providers');

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'name' => 'Sunrise Family Medicine',
            'is_profile_locked' => true,
        ]);
    }

    public function test_practice_name_stays_locked_after_first_submission(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id, 'name' => 'Sunrise Family Medicine']);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_profile')
            ->set('practiceName', 'A Different Name')
            ->set('specialty', 'General Practice')
            ->call('continueFromProfile')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'name' => 'Sunrise Family Medicine',
        ]);
    }

    public function test_skipping_the_profile_screen_advances_without_locking_an_empty_name(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'name' => '']);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_profile')
            ->set('practiceName', '')
            ->call('continueFromProfile', true)
            ->assertHasNoErrors()
            ->assertSet('screen', 'b_providers');

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'name' => '',
            'is_profile_locked' => false,
        ]);
    }

    // ── Basics: 2) Providers ─────────────────────────────────────────────────

    public function test_providers_screen_saves_the_billable_provider_count(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_providers')
            ->set('billableProviders', 3)
            ->call('continueFromProviders')
            ->assertHasNoErrors()
            ->assertSet('screen', 'b_address');

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'billable_providers_count' => 3,
        ]);
    }

    // ── Basics: 3) Address ───────────────────────────────────────────────────

    public function test_address_screen_requires_a_street_address_unless_same_as_billing(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_address')
            ->set('sameAsBillingAddress', false)
            ->set('practiceAddress', '')
            ->call('continueFromAddress')
            ->assertHasErrors(['practiceAddress']);
    }

    public function test_saving_the_address_screen_for_later_persists_without_advancing(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_address')
            ->set('sameAsBillingAddress', false)
            ->set('practiceAddress', '7 Clyde Road, Somerset, NJ, 08873')
            ->call('continueFromAddress', true, true)
            ->assertHasNoErrors()
            ->assertSet('screen', 'b_address')
            ->assertSet('justSaved', true);

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'address' => '7 Clyde Road, Somerset, NJ, 08873',
        ]);
    }

    // ── Basics: 4) Logo (always optional) ────────────────────────────────────

    public function test_logo_screen_is_always_optional(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_logo')
            ->call('continueFromLogo')
            ->assertHasNoErrors()
            ->assertDispatched('intake-wizard-complete');
    }

    public function test_essential_tier_finishes_the_wizard_after_all_basics_screens(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_profile')
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('specialty', 'General Practice')
            ->call('continueFromProfile')
            ->assertSet('screen', 'b_providers')
            ->set('billableProviders', 2)
            ->call('continueFromProviders')
            ->assertSet('screen', 'b_address')
            ->call('continueFromAddress')
            ->assertSet('screen', 'b_logo')
            ->call('continueFromLogo');

        $component->assertHasNoErrors();
        $component->assertDispatched('intake-wizard-complete');

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'name' => 'Sunrise Family Medicine',
            'is_profile_locked' => true,
            'billable_providers_count' => 2,
        ]);
        $this->assertDatabaseHas('intake_submissions', [
            'order_id' => $order->id,
            'wizard_screen' => 'done',
        ]);
    }

    // ── Team screen (Professional/Advanced only) ─────────────────────────

    public function test_professional_tier_continues_to_team_screen_after_all_basics_screens(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_profile')
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('specialty', 'General Practice')
            ->call('continueFromProfile')
            ->call('continueFromProviders')
            ->call('continueFromAddress')
            ->call('continueFromLogo')
            ->assertHasNoErrors()
            ->assertSet('screen', 'team');
    }

    public function test_team_screen_requires_hotline_details_unless_using_the_shared_hotline(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'team')
            ->set('usesEhcpHotline', false)
            ->call('continueFromTeam')
            ->assertHasErrors(['complianceOfficerName', 'complianceHotlineNumber', 'complianceHotlineEmail']);
    }

    public function test_team_screen_saves_practice_fields_and_advances_to_the_first_question(): void
    {
        $this->seedWorkflowQuestions();

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'team')
            ->set('complianceOfficerName', 'Jane Provider')
            ->set('complianceOfficerPhone', '555-0100')
            ->set('complianceOfficerEmail', 'jane@example.com')
            ->set('hipaaPrivacyOfficerName', 'Jane Provider')
            ->set('hipaaPrivacyOfficerPhone', '555-0100')
            ->set('hipaaPrivacyOfficerEmail', 'jane@example.com')
            ->set('hipaaSecurityOfficerName', 'Jane Provider')
            ->set('hipaaSecurityOfficerPhone', '555-0100')
            ->set('hipaaSecurityOfficerEmail', 'jane@example.com')
            ->set('releaseOfInfoOfficerName', 'Jane Provider')
            ->set('releaseOfInfoOfficerPhone', '555-0100')
            ->set('releaseOfInfoOfficerEmail', 'jane@example.com')
            ->set('itVendorName', 'Acme IT')
            ->set('usesEhcpHotline', true)
            ->set('hotlinePosterCount', 2)
            ->call('continueFromTeam')
            ->assertHasNoErrors()
            ->assertSet('screen', 'question');

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'compliance_officer_name' => 'Jane Provider',
            'it_vendor_name' => 'Acme IT',
            'uses_ehcp_hotline' => true,
        ]);
    }

    // ── Questions ─────────────────────────────────────────────────────────

    private function advanceToQuestions(User $user, Order $order): Testable
    {
        return Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'team')
            ->set('complianceOfficerName', 'Jane Provider')
            ->set('complianceOfficerPhone', '555-0100')
            ->set('complianceOfficerEmail', 'jane@example.com')
            ->set('hipaaPrivacyOfficerName', 'Jane Provider')
            ->set('hipaaPrivacyOfficerPhone', '555-0100')
            ->set('hipaaPrivacyOfficerEmail', 'jane@example.com')
            ->set('hipaaSecurityOfficerName', 'Jane Provider')
            ->set('hipaaSecurityOfficerPhone', '555-0100')
            ->set('hipaaSecurityOfficerEmail', 'jane@example.com')
            ->set('releaseOfInfoOfficerName', 'Jane Provider')
            ->set('releaseOfInfoOfficerPhone', '555-0100')
            ->set('releaseOfInfoOfficerEmail', 'jane@example.com')
            ->set('itVendorName', 'Acme IT')
            ->set('usesEhcpHotline', true)
            ->set('hotlinePosterCount', 2)
            ->call('continueFromTeam');
    }

    public function test_documented_process_answer_requires_response_text(): void
    {
        $this->seedWorkflowQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $this->advanceToQuestions($user, $order)
            ->set('currentHasDocumentedProcess', true)
            ->set('currentResponse', '')
            ->call('saveCurrentAnswer')
            ->assertHasErrors(['currentResponse']);
    }

    public function test_choosing_no_documented_process_auto_advances_to_the_next_question(): void
    {
        $this->seedWorkflowQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = $this->advanceToQuestions($user, $order);
        $firstQuestionId = $component->get('currentQuestionId');

        $component->call('chooseNoDocumentedProcess')
            ->assertHasNoErrors();

        $this->assertNotSame($firstQuestionId, $component->get('currentQuestionId'));
        $this->assertDatabaseHas('intake_answers', [
            'intake_question_id' => $firstQuestionId,
            'has_documented_process' => false,
            'response' => null,
        ]);
    }

    public function test_answering_with_a_documented_process_saves_the_response_and_advances(): void
    {
        $this->seedWorkflowQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = $this->advanceToQuestions($user, $order);
        $firstQuestionId = $component->get('currentQuestionId');

        $component->set('currentResponse', 'Our board reviews the program quarterly.')
            ->call('saveCurrentAnswer')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('intake_answers', [
            'intake_question_id' => $firstQuestionId,
            'response' => 'Our board reviews the program quarterly.',
            'has_documented_process' => true,
        ]);
        $this->assertNotSame($firstQuestionId, $component->get('currentQuestionId'));
    }

    public function test_skipping_a_question_defers_it_to_the_end_of_the_queue(): void
    {
        $this->seedWorkflowQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = $this->advanceToQuestions($user, $order);
        $firstQuestionId = $component->get('currentQuestionId');

        $component->call('skipCurrentQuestion');
        $secondQuestionId = $component->get('currentQuestionId');
        $this->assertNotSame($firstQuestionId, $secondQuestionId);

        // Answering the next (unskipped) question in order must not bring the skipped one back
        // yet — it's deferred to the end of the queue, not immediately after.
        $component->set('currentResponse', 'Answer two')->call('saveCurrentAnswer');
        $thirdQuestionId = $component->get('currentQuestionId');
        $this->assertNotSame($firstQuestionId, $thirdQuestionId);
        $this->assertNotSame($secondQuestionId, $thirdQuestionId);

        // Only once every other question has been answered does the skipped one come back around.
        $component->set('currentResponse', 'Answer three')->call('saveCurrentAnswer');
        $this->assertSame($firstQuestionId, $component->get('currentQuestionId'));
    }

    public function test_completing_every_question_dispatches_intake_wizard_complete(): void
    {
        $this->seedWorkflowQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = $this->advanceToQuestions($user, $order);

        $component->set('currentResponse', 'Answer one')->call('saveCurrentAnswer');
        $component->set('currentResponse', 'Answer two')->call('saveCurrentAnswer');
        $component->set('currentResponse', 'Answer three')->call('saveCurrentAnswer');

        $component->assertDispatched('intake-wizard-complete');
        $this->assertDatabaseHas('intake_submissions', ['order_id' => $order->id, 'wizard_screen' => 'done']);
        $this->assertDatabaseCount('intake_answers', 3);
    }

    public function test_resuming_a_draft_submission_reloads_the_saved_screen_and_answers(): void
    {
        $this->seedWorkflowQuestions();
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $submission = IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'question',
            'wizard_reached_screens' => ['documents', 'b_profile', 'b_providers', 'b_address', 'b_logo', 'team', 'section:'.IntakeSection::first()->id],
        ]);
        $question = IntakeQuestion::orderBy('sort_order')->first();
        $submission->intakeAnswers()->create([
            'intake_question_id' => $question->id,
            'response' => 'Already answered on a previous visit.',
            'has_documented_process' => true,
            'answered_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->assertSet('screen', 'question');
    }
}
