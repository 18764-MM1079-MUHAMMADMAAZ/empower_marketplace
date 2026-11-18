<?php

namespace Tests\Feature;

use App\Enums\IntakeSubmissionStatus;
use App\Enums\IntakeUploadType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Mail\NewSpecialistCallRequestMail;
use App\Models\CompliancePolicy;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

    /** A small gate-aware fixture — enough to exercise the services checklist, section gates,
     *  the Compliance Committee skip and IT-vendor consolidation, without seeding the full real
     *  66-question/101-policy production data. */
    private function seedGatedQuestions(): array
    {
        $policyCommittee = CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-04', 'title' => 'Compliance Committee', 'requirements' => []]);
        $policyLab = CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-11', 'title' => 'Medical necessity', 'requirements' => []]);
        $policyPasswords = CompliancePolicy::create(['manual' => 'hipaa_security_manual', 'code' => 'SEC-17', 'title' => 'Passwords', 'requirements' => []]);
        $policyFirewall = CompliancePolicy::create(['manual' => 'hipaa_security_manual', 'code' => 'SEC-33', 'title' => 'Firewall', 'requirements' => []]);

        $sectionCompliance = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $sectionSecurity = IntakeSection::create(['key' => 'security_systems_network', 'label' => 'Security: systems & network', 'sort_order' => 2]);

        $committeeQuestion = IntakeQuestion::create(['intake_section_id' => $sectionCompliance->id, 'sort_order' => 1, 'title' => 'Compliance Committee', 'requires_compliance_committee' => true]);
        $committeeQuestion->policies()->attach($policyCommittee->id);

        $labQuestion = IntakeQuestion::create(['intake_section_id' => $sectionCompliance->id, 'sort_order' => 2, 'title' => 'Medical necessity', 'service_key' => 'lab']);
        $labQuestion->policies()->attach($policyLab->id);

        $passwordsQuestion = IntakeQuestion::create(['intake_section_id' => $sectionSecurity->id, 'sort_order' => 1, 'title' => 'Passwords', 'is_it_managed_topic' => true]);
        $passwordsQuestion->policies()->attach($policyPasswords->id);

        $firewallQuestion = IntakeQuestion::create(['intake_section_id' => $sectionSecurity->id, 'sort_order' => 2, 'title' => 'Firewall', 'is_it_managed_topic' => true]);
        $firewallQuestion->policies()->attach($policyFirewall->id);

        return compact('sectionCompliance', 'sectionSecurity', 'committeeQuestion', 'labQuestion', 'passwordsQuestion', 'firewallQuestion');
    }

    public function test_services_screen_requires_a_choice_before_continuing(): void
    {
        $this->seedGatedQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'services')
            ->call('continueFromServices')
            ->assertHasErrors(['services']);
    }

    public function test_select_all_services_can_be_toggled_off_without_marking_none(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'services')
            ->call('toggleAllServices');

        $this->assertTrue($component->instance()->allServicesSelected);
        $this->assertCount(12, $component->instance()->selectedServices);

        $component->call('toggleAllServices');

        $this->assertFalse($component->instance()->allServicesSelected);
        $this->assertSame([], $component->instance()->selectedServices);
        $this->assertNull($component->instance()->currentSubmission->wizard_selected_services);
    }

    public function test_select_all_services_label_switches_to_deselect_all_when_all_are_selected(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'services')
            ->call('toggleAllServices')
            ->assertSee('Deselect all');
    }

    public function test_unchecking_a_service_excludes_its_mapped_question_from_the_gate(): void
    {
        $fixture = $this->seedGatedQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'services')
            ->call('setServicesNone')
            ->call('continueFromServices');

        $topics = $component->instance()->gateTopicsForSection($fixture['sectionCompliance']->fresh());
        $this->assertFalse($topics->contains('id', $fixture['labQuestion']->id));

        $component->call('toggleService', 'lab');
        $topicsAfter = $component->instance()->gateTopicsForSection($fixture['sectionCompliance']->fresh());
        $this->assertTrue($topicsAfter->contains('id', $fixture['labQuestion']->id));
    }

    public function test_compliance_committee_question_is_skipped_without_a_committee(): void
    {
        $fixture = $this->seedGatedQuestions();
        $user = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $user->id, 'committee_none' => true]);
        $order = $this->makeProfessionalOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]]);

        $topics = $component->instance()->gateTopicsForSection($fixture['sectionCompliance']->fresh());
        $this->assertFalse($topics->contains('id', $fixture['committeeQuestion']->id));

        $practice->update(['committee_none' => false]);
        $user->unsetRelation('practice');
        unset($component->instance()->practice, $component->instance()->hasNoCommittee);
        $topicsWithCommittee = $component->instance()->gateTopicsForSection($fixture['sectionCompliance']->fresh());
        $this->assertTrue($topicsWithCommittee->contains('id', $fixture['committeeQuestion']->id));
    }

    public function test_it_managed_questions_combine_into_one_question_when_outsourced(): void
    {
        $fixture = $this->seedGatedQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'it_mode' => 'vendor', 'it_vendor_name' => 'Acme IT']);
        $order = $this->makeProfessionalOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('setServicesNone')
            ->call('continueFromServices')
            ->call('chooseGateMode', $fixture['sectionCompliance']->id, 'none');

        $securityTopics = $component->instance()->gateTopicsForSection($fixture['sectionSecurity']->fresh());
        $this->assertFalse($securityTopics->contains('id', $fixture['passwordsQuestion']->id));
        $this->assertFalse($securityTopics->contains('id', $fixture['firewallQuestion']->id));
        $combo = $securityTopics->firstWhere('id', -1);
        $this->assertNotNull($combo);
        $this->assertStringContainsString('Acme IT', $combo->prompt_summary);

        $component->call('chooseGateMode', $fixture['sectionSecurity']->id, 'some')
            ->call('toggleGateTopic', $fixture['sectionSecurity']->id, -1)
            ->call('continueFromGate', $fixture['sectionSecurity']->id)
            ->assertSet('currentQuestionId', -1)
            ->set('currentResponse', 'Acme IT patches and firewalls everything monthly.')
            ->call('chooseDocumentedProcess')
            ->call('saveCurrentAnswer');

        $this->assertDatabaseHas('intake_answers', [
            'intake_question_id' => $fixture['passwordsQuestion']->id,
            'response' => 'Acme IT patches and firewalls everything monthly.',
        ]);
        $this->assertDatabaseHas('intake_answers', [
            'intake_question_id' => $fixture['firewallQuestion']->id,
            'response' => 'Acme IT patches and firewalls everything monthly.',
        ]);
    }

    public function test_skip_for_now_on_a_gate_screen_defers_it_until_everything_else_is_resolved(): void
    {
        $fixture = $this->seedGatedQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('setServicesNone')
            ->call('continueFromServices')
            ->assertSet('currentGateSectionId', $fixture['sectionCompliance']->id)
            ->assertSee('x-on:keydown.window=', false)
            ->assertSee('$wire.chooseGateMode', false)
            ->assertSee('$wire.continueFromGate', false);

        // Skip for now leaves the gate unresolved (the draft, if any, is untouched) and moves on
        // to the next section instead — it must not fall back to "apply the policy defaults".
        $component->call('skipCurrentGate', $fixture['sectionCompliance']->id)
            ->assertSet('currentGateSectionId', $fixture['sectionSecurity']->id);
        $this->assertSame([$fixture['sectionCompliance']->id], $component->instance()->skippedSectionIds);
        $this->assertArrayNotHasKey((string) $fixture['sectionCompliance']->id, $component->instance()->sectionGates);

        // Resolving the only other section routes back to the skipped one instead of finishing.
        $component->call('chooseGateMode', $fixture['sectionSecurity']->id, 'none')
            ->assertSet('currentGateSectionId', $fixture['sectionCompliance']->id);
        $this->assertSame([$fixture['sectionCompliance']->id], $component->instance()->skippedSectionIds);

        // Finally resolving it clears the skip and finishes the wizard.
        $component->call('chooseGateMode', $fixture['sectionCompliance']->id, 'none')
            ->assertSet('screen', 'saving');
        $this->assertSame([], $component->instance()->skippedSectionIds);
    }

    public function test_services_used_in_chips_list_every_service_mapped_policy(): void
    {
        $fixture = $this->seedGatedQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'services');

        $this->assertSame(['CMP-11'], $component->instance()->servicesUsedInPolicyCodes);
    }

    public function test_glossify_wraps_the_first_mention_of_a_term_with_its_definition(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        $component = Livewire::actingAs($user)->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]]);

        $html = $component->instance()->glossify('We also act as a health plan or a healthcare clearinghouse, since many clearinghouses do this.');

        $this->assertStringContainsString('<abbr', $html);
        $this->assertStringContainsString('title="A company that processes and forwards claims and other data between providers and payers."', $html);
        // Only the first mention of "clearinghouse(s)" gets wrapped.
        $this->assertSame(1, substr_count($html, '<abbr'));
    }

    private function makeEssentialOrder(User $user): Order
    {
        $package = Package::firstWhere('slug', 'essential')
            ?? Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        return Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
    }

    private function makeProfessionalOrder(User $user): Order
    {
        $package = Package::firstWhere('slug', 'professional')
            ?? Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);

        return Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
    }

    private function makeAdvancedOrder(User $user): Order
    {
        $package = Package::firstWhere('slug', 'advanced')
            ?? Package::factory()->create(['slug' => 'advanced', 'annual_price' => 1699, 'is_active' => true]);

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
            ->call('continueFromIntro')
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
            ->call('continueFromIntro')
            ->call('continueFromDocuments')
            ->assertHasNoErrors()
            ->assertSet('screen', 'b_profile');
    }

    public function test_advanced_tier_documents_screen_offers_employee_manual_and_encounter_list(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeAdvancedOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('continueFromIntro')
            ->assertSee('Employee manual')
            ->assertSee('Encounter list (10 per provider)');
    }

    public function test_professional_tier_documents_screen_does_not_offer_employee_manual_or_encounter_list(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('continueFromIntro')
            ->assertDontSee('Employee manual')
            ->assertDontSee('Encounter list (10 per provider)');
    }

    /** Opens the documents screen for a fresh client on the given tier. */
    private function documentsScreen(string $tier = 'essential'): Testable
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = match ($tier) {
            'professional' => $this->makeProfessionalOrder($user),
            'advanced' => $this->makeAdvancedOrder($user),
            default => $this->makeEssentialOrder($user),
        };

        return Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('continueFromIntro');
    }

    private function pdf(string $name, int $kilobytes = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    private function upload(string $name): IntakeUpload
    {
        return IntakeUpload::where('original_filename', $name)->firstOrFail();
    }

    public function test_only_pdf_and_docx_files_can_be_added_on_the_documents_screen(): void
    {
        $component = $this->documentsScreen()
            ->assertSee('PDF or DOCX')
            ->assertDontSee('XLSX')
            ->assertSeeHtml('accept=".pdf,.docx"')
            ->set('documentFiles', [
                UploadedFile::fake()->create('Budget.xlsx', 12, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
                UploadedFile::fake()->create('compliance manual.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                UploadedFile::fake()->image('scan.png'),
            ])
            ->assertHasErrors(['documentFiles'])
            ->assertSee('Only PDF or Word (.docx) files can be uploaded. Not added: Budget.xlsx, scan.png.');

        $this->assertSame(1, IntakeUpload::count());
        $docx = $this->upload('compliance manual.docx');
        $component->assertSet('typingUploadIds', [$docx->id])
            ->assertSet("typingTags.{$docx->id}", 'compliance_ethics')
            ->assertSet('documentFiles', []);
    }

    public function test_files_dropped_on_the_upload_box_are_saved_at_once_and_open_the_type_dialog(): void
    {
        $component = $this->documentsScreen()
            ->set('documentFiles', [$this->pdf('HIPAA Security Manual.pdf'), $this->pdf('Empower Test.pdf')]);

        $security = $this->upload('HIPAA Security Manual.pdf');
        $test = $this->upload('Empower Test.pdf');

        // Saved straight away, with no type until the client confirms one.
        $this->assertNull($security->document_category);
        $this->assertNull($test->document_category);
        Storage::disk('local')->assertExists($security->storage_path);

        $component->assertSet('typingUploadIds', [$security->id, $test->id])
            ->assertSet("typingTags.{$security->id}", 'hipaa_security')
            ->assertSet("typingTags.{$test->id}", '')
            ->assertSet('suggestedTags', [$security->id])
            ->assertSee('What are these documents?')
            ->assertSee('Suggested')
            ->assertSee('Add 2 files')
            ->assertSee('Decide later');

        // Every file needs a type before "Add 2 files" works; nothing changes until then.
        $component->call('confirmDocumentTypes')
            ->assertHasErrors(['typingTags'])
            ->assertSet('typingUploadIds', [$security->id, $test->id]);
        $this->assertNull($security->fresh()->document_category);

        $component->set("typingTags.{$test->id}", 'compliance_ethics')
            ->call('confirmDocumentTypes')
            ->assertHasNoErrors()
            ->assertSet('typingUploadIds', [])
            ->assertDontSee('What are these documents?');

        $this->assertSame('hipaa_security', $security->fresh()->document_category);
        $this->assertSame('compliance_ethics', $test->fresh()->document_category);
        $this->assertSame('uploaded', $component->instance()->documentCategoryStatus('hipaa_security'));
        $this->assertSame('uploaded', $component->instance()->documentCategoryStatus('compliance_ethics'));
        $this->assertSame('needed', $component->instance()->documentCategoryStatus('hipaa_privacy'));

        // Each file now shows inside its own checklist row.
        $component->assertSeeHtmlInOrder(['data-document-row="compliance_ethics"', 'Empower Test.pdf', 'data-document-row="hipaa_privacy"'])
            ->assertSeeHtmlInOrder(['data-document-row="hipaa_security"', 'HIPAA Security Manual.pdf', 'data-document-row="training_materials"']);
    }

    public function test_decide_later_keeps_the_files_under_needs_a_type(): void
    {
        $component = $this->documentsScreen()
            ->set('documentFiles', [$this->pdf('random.pdf')])
            ->call('closeDocumentTypeDialog')
            ->assertSet('typingUploadIds', [])
            ->assertSee('Needs a type')
            ->assertSeeHtmlInOrder(['data-needs-type', 'random.pdf', 'Choose type']);

        $random = $this->upload('random.pdf');
        $this->assertNull($random->document_category);

        // "Choose type" reopens the dialog for just that file.
        $component->call('openDocumentTypeDialog', $random->id)
            ->assertSet('typingUploadIds', [$random->id])
            ->assertSee('What is this document?')
            ->set("typingTags.{$random->id}", 'training_materials')
            ->call('confirmDocumentTypes')
            ->assertDontSee('Needs a type');

        $this->assertSame('training_materials', $random->fresh()->document_category);
    }

    public function test_a_row_upload_link_files_the_upload_under_that_row_without_the_dialog(): void
    {
        $component = $this->documentsScreen()
            ->assertSeeHtml('data-row-upload="hipaa_privacy"')
            ->call('toggleDocumentMissing', 'hipaa_privacy')
            ->set('categoryFiles.hipaa_privacy', [$this->pdf('Notice of Privacy Practices.pdf'), $this->pdf('Privacy forms.pdf')])
            ->assertSet('typingUploadIds', [])
            ->assertSet('categoryFiles', []);

        $this->assertSame(2, IntakeUpload::where('document_category', 'hipaa_privacy')->count());
        // Uploading to a row marked "Don't have it" switches it back to uploaded.
        $this->assertSame('uploaded', $component->instance()->documentCategoryStatus('hipaa_privacy'));
        $this->assertNotContains('hipaa_privacy', IntakeSubmission::first()->wizard_missing_document_categories);
        $component->assertSeeHtmlInOrder(['data-document-row="hipaa_privacy"', 'Add another', 'Notice of Privacy Practices.pdf', 'Privacy forms.pdf', 'data-document-row="hipaa_security"']);
    }

    public function test_a_row_upload_for_a_row_this_package_does_not_have_is_ignored(): void
    {
        $this->documentsScreen()
            ->set('categoryFiles.employee_manual', [$this->pdf('Employee handbook.pdf')]);

        $this->assertSame(0, IntakeUpload::count());
    }

    public function test_change_type_moves_a_file_to_another_row(): void
    {
        $component = $this->documentsScreen()
            ->set('categoryFiles.hipaa_privacy', [$this->pdf('Policies.pdf')]);
        $policies = $this->upload('Policies.pdf');

        $component->call('openDocumentTypeDialog', $policies->id)
            ->assertSet("typingTags.{$policies->id}", 'hipaa_privacy')
            ->assertSet('suggestedTags', [])
            ->assertSee('Change document type')
            ->assertSee('Cancel')
            ->set("typingTags.{$policies->id}", 'hipaa_security')
            ->call('confirmDocumentTypes');

        $this->assertSame('hipaa_security', $policies->fresh()->document_category);
        $this->assertSame('needed', $component->instance()->documentCategoryStatus('hipaa_privacy'));
        $this->assertSame('uploaded', $component->instance()->documentCategoryStatus('hipaa_security'));
    }

    public function test_cancelling_change_type_leaves_the_file_as_it_was(): void
    {
        $component = $this->documentsScreen()
            ->set('categoryFiles.training_materials', [$this->pdf('Slides.pdf')]);
        $slides = $this->upload('Slides.pdf');

        $component->call('openDocumentTypeDialog', $slides->id)
            ->set("typingTags.{$slides->id}", 'hipaa_security')
            ->call('closeDocumentTypeDialog');

        $this->assertSame('training_materials', $slides->fresh()->document_category);
    }

    public function test_removing_a_file_deletes_it_and_frees_its_row(): void
    {
        $component = $this->documentsScreen()
            ->set('categoryFiles.compliance_ethics', [$this->pdf('Code of conduct.pdf')]);
        $code = $this->upload('Code of conduct.pdf');

        $component->call('deleteExistingDocument', $code->id);

        $this->assertDatabaseMissing('intake_uploads', ['id' => $code->id]);
        Storage::disk('local')->assertMissing($code->storage_path);
        $this->assertSame('needed', $component->instance()->documentCategoryStatus('compliance_ethics'));
    }

    public function test_removing_the_only_file_in_the_dialog_closes_it(): void
    {
        $component = $this->documentsScreen()->set('documentFiles', [$this->pdf('random.pdf')]);
        $random = $this->upload('random.pdf');

        $component->call('deleteExistingDocument', $random->id)
            ->assertSet('typingUploadIds', [])
            ->assertSet('typingTags', []);
    }

    public function test_a_guess_never_uses_a_row_the_package_does_not_have(): void
    {
        // "handbook" points at Employee manual, which only the Advanced package lists.
        $essential = $this->documentsScreen('essential')->set('documentFiles', [$this->pdf('Staff handbook.pdf')]);
        $essentialUpload = $this->upload('Staff handbook.pdf');
        $essential->assertSet("typingTags.{$essentialUpload->id}", '')->assertSet('suggestedTags', []);

        $advanced = $this->documentsScreen('advanced')->set('documentFiles', [$this->pdf('Staff handbook 2.pdf')]);
        $advancedUpload = $this->upload('Staff handbook 2.pdf');
        $advanced->assertSet("typingTags.{$advancedUpload->id}", 'employee_manual');
    }

    public function test_the_dialog_only_accepts_this_packages_types_or_other(): void
    {
        $component = $this->documentsScreen()->set('documentFiles', [$this->pdf('random.pdf')]);
        $random = $this->upload('random.pdf');

        $component->set("typingTags.{$random->id}", 'employee_manual')
            ->call('confirmDocumentTypes')
            ->assertHasErrors(['typingTags']);
        $this->assertNull($random->fresh()->document_category);

        $component->set("typingTags.{$random->id}", 'other')
            ->call('confirmDocumentTypes')
            ->assertHasNoErrors()
            ->assertSeeHtmlInOrder(['data-document-row="other"', 'Other documents', 'random.pdf']);
        $this->assertSame('other', $random->fresh()->document_category);
    }

    public function test_another_clients_upload_cannot_be_typed_or_removed(): void
    {
        $this->documentsScreen()->set('categoryFiles.compliance_ethics', [$this->pdf('Theirs.pdf')]);
        $theirs = $this->upload('Theirs.pdf');

        $this->documentsScreen()
            ->call('openDocumentTypeDialog', $theirs->id)
            ->assertSet('typingUploadIds', [])
            ->set('typingUploadIds', [$theirs->id])
            ->set("typingTags.{$theirs->id}", 'hipaa_security')
            ->call('confirmDocumentTypes')
            ->call('deleteExistingDocument', $theirs->id);

        $this->assertSame('compliance_ethics', $theirs->fresh()->document_category);
    }

    public function test_uploading_a_document_stores_it_against_the_draft_submission(): void
    {
        $component = $this->documentsScreen()
            ->set('documentFiles', [$this->pdf('handbook.pdf')]);
        $handbook = $this->upload('handbook.pdf');

        $component->set("typingTags.{$handbook->id}", 'compliance_ethics')
            ->call('confirmDocumentTypes')
            ->call('toggleDocumentMissing', 'hipaa_privacy')
            ->call('toggleDocumentMissing', 'hipaa_security')
            ->call('toggleDocumentMissing', 'training_materials')
            ->call('continueFromDocuments')
            ->assertHasNoErrors()
            ->assertSet('screen', 'b_profile');

        $this->assertDatabaseHas('intake_submissions', [
            'id' => $handbook->intake_submission_id,
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
        $this->documentsScreen()
            ->set('categoryFiles.compliance_ethics', [$this->pdf('handbook.pdf')])
            ->call('continueFromDocuments')
            ->assertHasErrors(['documentFiles'])
            ->assertSet('screen', 'documents');
    }

    public function test_essential_tier_requires_uploaded_files_to_be_tagged(): void
    {
        $this->documentsScreen()
            ->set('documentFiles', [$this->pdf('random.pdf')])
            ->call('closeDocumentTypeDialog')
            ->call('toggleDocumentMissing', 'compliance_ethics')
            ->call('toggleDocumentMissing', 'hipaa_privacy')
            ->call('toggleDocumentMissing', 'hipaa_security')
            ->call('toggleDocumentMissing', 'training_materials')
            ->call('continueFromDocuments')
            ->assertHasErrors(['documentFiles'])
            ->assertSee('Choose a type for the file under "Needs a type".')
            ->assertSet('screen', 'documents');
    }

    public function test_marking_all_document_categories_as_declined_still_requires_one_upload(): void
    {
        $this->documentsScreen()
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
        $component = $this->documentsScreen()->call('toggleDocumentMissing', 'hipaa_privacy');

        $this->assertSame('declined', $component->instance()->documentCategoryStatus('hipaa_privacy'));

        $component->call('toggleDocumentMissing', 'hipaa_privacy');

        $this->assertSame('needed', $component->instance()->documentCategoryStatus('hipaa_privacy'));
    }

    public function test_bulk_mark_remaining_documents_missing_appears_once_one_is_uploaded(): void
    {
        $component = $this->documentsScreen('professional')
            ->set('documentFiles', [$this->pdf('training.pdf', 50)]);
        $training = $this->upload('training.pdf');

        $component->assertSet("typingTags.{$training->id}", 'training_materials')
            ->call('confirmDocumentTypes')
            ->assertSeeText('Mark the other 3 as "don\'t have"')
            ->call('markRemainingDocumentsMissing');

        $this->assertSame('declined', $component->instance()->documentCategoryStatus('compliance_ethics'));
        $this->assertSame('declined', $component->instance()->documentCategoryStatus('hipaa_privacy'));
        $this->assertSame('declined', $component->instance()->documentCategoryStatus('hipaa_security'));
        $this->assertSame('uploaded', $component->instance()->documentCategoryStatus('training_materials'));
    }

    public function test_professional_tier_offers_a_full_skip_before_any_upload(): void
    {
        $this->documentsScreen('professional')->assertSeeText('We don\'t have any of these');
    }

    public function test_essential_tier_does_not_offer_a_full_skip_before_any_upload(): void
    {
        $this->documentsScreen()->assertDontSee('We don\'t have any of these');
    }

    public function test_skipping_the_documents_screen_bypasses_validation_and_advances(): void
    {
        $this->documentsScreen()
            ->call('continueFromDocuments', true)
            ->assertHasNoErrors()
            ->assertSet('screen', 'b_profile');
    }

    public function test_saving_the_documents_screen_for_later_stays_put_and_flags_saved(): void
    {
        $this->documentsScreen()
            ->set('categoryFiles.compliance_ethics', [$this->pdf('handbook.pdf')])
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

    public function test_providers_screen_shows_the_count_already_confirmed_at_checkout(): void
    {
        // Set directly, the way ⚡portal.blade.php's pay()/payFreeTrial() now does at Step 1
        // checkout — this screen is read-only confirmation, not an editable input any more.
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'billable_providers_count' => 3]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_providers')
            ->assertSet('billableProviders', 3)
            ->assertSee('3')
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
            ->assertSet('screen', 'saving')
            ->call('finishWizard')
            ->assertSet('screen', 'done')
            ->call('continueToConfirm')
            ->assertDispatched('intake-wizard-complete');
    }

    public function test_essential_tier_finishes_the_wizard_after_all_basics_screens(): void
    {
        $user = User::factory()->create();
        // Billable providers is confirmed at Step 1 checkout now, not on this wizard screen —
        // set directly here the way ⚡portal.blade.php's pay() does.
        Practice::factory()->create(['user_id' => $user->id, 'billable_providers_count' => 2]);
        $order = $this->makeEssentialOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 'b_profile')
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('specialty', 'General Practice')
            ->call('continueFromProfile')
            ->assertSet('screen', 'b_providers')
            ->call('continueFromProviders')
            ->assertSet('screen', 'b_address')
            ->call('continueFromAddress')
            ->assertSet('screen', 'b_logo')
            ->call('continueFromLogo');

        $component->assertHasNoErrors();
        $component->assertSet('screen', 'saving');
        $component->call('finishWizard')->assertSet('screen', 'done');
        $component->call('continueToConfirm')->assertDispatched('intake-wizard-complete');

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
            ->assertSet('screen', 't_practice');
    }

    /** Regression: mount() only pre-fills legalPracticeName from whatever the practice's name
     *  already was at page load — a name typed on b_profile earlier in the same uninterrupted
     *  session never reached it, since nothing remounts the component between the two screens. */
    public function test_legal_practice_name_prefills_from_a_name_typed_earlier_in_the_same_session(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'name' => '', 'legal_practice_name' => null]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('continueFromIntro')
            ->call('continueFromDocuments', true)
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('specialty', 'Family Medicine')
            ->call('continueFromProfile')
            ->call('continueFromProviders')
            ->call('continueFromAddress', true)
            ->call('continueFromLogo')
            ->assertSet('screen', 't_practice')
            ->assertSet('legalPracticeName', 'Sunrise Family Medicine');
    }

    public function test_team_practice_screen_requires_legal_name_contact_and_a_location(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_practice')
            // legalPracticeName pre-fills from the practice name captured on the basics screen —
            // clear it here to also exercise its own "required" validation.
            ->set('legalPracticeName', '')
            ->set('practiceLocations', [''])
            ->call('continueFromPractice')
            ->assertHasErrors(['legalPracticeName', 'mainPhone', 'mainEmail', 'practiceLocations']);
    }

    public function test_phone_numbers_are_put_into_the_us_format(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_practice')
            ->assertSeeHtml('placeholder="(555) 555-5555"')
            ->assertSeeHtml('formatUsPhone($el.value)')
            ->set('mainPhone', '732.555.0148')
            ->assertSet('mainPhone', '(732) 555-0148')
            ->set('complianceOfficerPhone', '+1 732 555 0199')
            ->assertSet('complianceOfficerPhone', '(732) 555-0199')
            ->assertHasNoErrors();
    }

    public function test_a_phone_that_is_not_a_us_number_is_flagged_on_blur_and_on_continue(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_practice')
            ->set('mainPhone', '555-0100')
            ->assertHasErrors(['mainPhone' => 'regex'])
            ->assertSee('Enter the phone number as (XXX) XXX-XXXX.')
            ->set('mainEmail', 'office@practice.com')
            ->set('practiceLocations', ['1 Main St'])
            ->call('continueFromPractice')
            ->assertHasErrors(['mainPhone' => 'regex'])
            ->assertSet('screen', 't_practice');
    }

    public function test_an_email_without_a_proper_domain_is_flagged_on_blur_and_on_continue(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_practice')
            ->set('mainEmail', 'office@practice')
            ->assertHasErrors(['mainEmail' => 'email'])
            ->assertSee('Enter a valid email address, like name@practice.com.')
            ->set('mainEmail', ' office@practice.com ')
            ->assertSet('mainEmail', 'office@practice.com')
            ->assertHasNoErrors(['mainEmail'])
            ->set('mainEmail', 'office@practice')
            ->set('mainPhone', '(732) 555-0148')
            ->set('practiceLocations', ['1 Main St'])
            ->call('continueFromPractice')
            ->assertHasErrors(['mainEmail' => 'email'])
            ->assertSet('screen', 't_practice');
    }

    public function test_officer_and_it_phones_and_emails_must_be_valid(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_officers');

        foreach (['complianceOfficer', 'hipaaPrivacyOfficer', 'hipaaSecurityOfficer', 'releaseOfInfoOfficer'] as $prefix) {
            $component->set("{$prefix}Name", 'Jane Provider')
                ->set("{$prefix}Phone", '(732) 555-0148')
                ->set("{$prefix}Email", 'jane@practice.com');
        }

        $component->set('hipaaSecurityOfficerPhone', '12345')
            ->set('releaseOfInfoOfficerEmail', 'jane@')
            ->call('continueFromOfficers')
            ->assertHasErrors(['hipaaSecurityOfficerPhone' => 'regex', 'releaseOfInfoOfficerEmail' => 'email'])
            ->assertSet('screen', 't_officers')
            ->set('hipaaSecurityOfficerPhone', '7325550148')
            ->set('releaseOfInfoOfficerEmail', 'roi@practice.com')
            ->call('continueFromOfficers')
            ->assertHasNoErrors()
            ->assertSet('screen', 't_it');

        $this->assertSame('(732) 555-0148', $user->practice->fresh()->hipaa_security_officer_phone);

        $component->set('itMode', 'vendor')
            ->set('itVendorName', 'Acme IT')
            ->set('itContactPhone', '555 0100')
            ->set('itContactEmail', 'help@acme')
            ->call('continueFromIt')
            ->assertHasErrors(['itContactPhone' => 'regex', 'itContactEmail' => 'email'])
            ->assertSet('screen', 't_it');
    }

    public function test_main_phone_and_email_start_from_the_partner_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'jane@riverside.example']);
        Practice::factory()->create(['user_id' => $user->id, 'source_system' => 'cch', 'phone' => '7325550148', 'main_phone' => null, 'main_email' => null]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->assertSet('mainPhone', '(732) 555-0148')
            ->assertSet('mainEmail', 'jane@riverside.example');
    }

    public function test_saved_main_details_win_over_the_partner_sign_in_and_other_practices_start_empty(): void
    {
        $partner = User::factory()->create();
        Practice::factory()->create(['user_id' => $partner->id, 'source_system' => 'talkehr', 'phone' => '7325550148', 'main_phone' => '908-555-0101', 'main_email' => 'office@practice.com']);

        Livewire::actingAs($partner)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$this->makeProfessionalOrder($partner)->id]])
            ->assertSet('mainPhone', '(908) 555-0101')
            ->assertSet('mainEmail', 'office@practice.com');

        $direct = User::factory()->create();
        Practice::factory()->create(['user_id' => $direct->id, 'source_system' => null, 'phone' => '7325550148', 'main_phone' => null, 'main_email' => null]);

        Livewire::actingAs($direct)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$this->makeProfessionalOrder($direct)->id]])
            ->assertSet('mainPhone', '')
            ->assertSet('mainEmail', '');
    }

    public function test_the_hotline_step_no_longer_asks_for_posters_and_leaves_a_saved_count_alone(): void
    {
        $user = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $user->id, 'hotline_poster_count' => 5]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_hotline')
            ->set('usesEhcpHotline', false)
            ->assertDontSee('Hotline posters needed')
            ->assertDontSee('2 per location recommended')
            ->assertDontSee('posters per location')
            ->set('hotlineContact', '(732) 555-0100')
            ->call('continueFromHotline')
            ->assertHasNoErrors()
            ->assertSet('screen', 't_leadership');

        $practice->refresh();
        $this->assertSame(5, $practice->hotline_poster_count);
        $this->assertFalse($practice->uses_ehcp_hotline);
        $this->assertSame('(732) 555-0100', $practice->compliance_hotline_number);
    }

    public function test_finishing_the_hotline_step_does_not_invent_a_poster_count(): void
    {
        $user = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $user->id, 'hotline_poster_count' => null]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_hotline')
            ->set('usesEhcpHotline', true)
            ->call('continueFromHotline')
            ->assertHasNoErrors()
            ->assertSet('screen', 't_leadership');

        $this->assertNull($practice->fresh()->hotline_poster_count);
    }

    public function test_team_hotline_screen_requires_hotline_contact_unless_using_the_shared_hotline(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_hotline')
            ->set('usesEhcpHotline', false)
            ->call('continueFromHotline')
            ->assertHasErrors(['hotlineContact']);
    }

    public function test_team_screens_save_practice_fields_and_advance_to_the_first_question(): void
    {
        $this->seedWorkflowQuestions();

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $this->advanceToQuestions($user, $order)
            ->assertHasNoErrors()
            ->assertSet('screen', 'question');

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'legal_practice_name' => 'Sunrise Family Medicine LLC',
            'compliance_officer_name' => 'Jane Provider',
            'it_mode' => 'vendor',
            'it_vendor_name' => 'Acme IT',
            'uses_ehcp_hotline' => true,
            'board_mode' => 'owners',
        ]);
    }

    public function test_officer_quick_add_pills_offer_the_account_holder_and_other_named_officers(): void
    {
        $user = User::factory()->create(['name' => 'Jane Provider']);
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_officers')
            ->set('hipaaPrivacyOfficerName', 'Alex Privacy');

        $this->assertSame(
            [
                ['source' => 'you', 'label' => 'You (Jane Provider)'],
                ['source' => 'hipaaPrivacyOfficer', 'label' => 'Alex Privacy'],
            ],
            $component->instance()->quickAddOptionsForOfficer('hipaaSecurityOfficer')
        );

        // The Privacy Officer's own row never suggests itself.
        $privacyOptions = collect($component->instance()->quickAddOptionsForOfficer('hipaaPrivacyOfficer'));
        $this->assertFalse($privacyOptions->contains('label', 'Alex Privacy'));
    }

    public function test_officer_quick_add_pill_copies_name_phone_and_email(): void
    {
        $user = User::factory()->create(['name' => 'Jane Provider', 'email' => 'jane@example.com']);
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_officers')
            ->set('complianceOfficerName', 'Jane Provider')
            ->set('complianceOfficerPhone', '(555) 555-0100')
            ->set('complianceOfficerEmail', 'jane@example.com')
            ->call('copyOfficerContact', 'hipaaPrivacyOfficer', 'complianceOfficer')
            ->assertSet('hipaaPrivacyOfficerName', 'Jane Provider')
            ->assertSet('hipaaPrivacyOfficerPhone', '(555) 555-0100')
            ->assertSet('hipaaPrivacyOfficerEmail', 'jane@example.com');
    }

    public function test_it_quick_add_pill_copies_name_phone_and_email(): void
    {
        $user = User::factory()->create(['name' => 'Jane Provider', 'email' => 'jane@example.com']);
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_it')
            ->set('itMode', 'inhouse')
            ->assertSee('reveal-in border border-[#eef2f6] rounded-xl bg-[#f8fbfd] p-4')
            ->assertSee('wire:key="it-details-inhouse"', false)
            ->set('complianceOfficerName', 'Alex Compliance')
            ->set('complianceOfficerPhone', '(555) 555-0199')
            ->set('complianceOfficerEmail', 'alex@example.com');

        $this->assertSame(
            [
                ['source' => 'you', 'label' => 'You (Jane Provider)'],
                ['source' => 'complianceOfficer', 'label' => 'Alex Compliance'],
            ],
            $component->instance()->quickAddOptionsForIt()
        );

        $component->call('copyItContact', 'complianceOfficer')
            ->assertSet('itContactName', 'Alex Compliance')
            ->assertSet('itContactPhone', '(555) 555-0199')
            ->assertSet('itContactEmail', 'alex@example.com')
            ->set('itMode', 'vendor')
            ->assertSee('reveal-in rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] p-4')
            ->assertSee('wire:key="it-details-vendor"', false)
            ->assertSee('Company name');
    }

    public function test_leadership_quick_add_dedupes_a_shared_name_and_joins_its_roles(): void
    {
        $user = User::factory()->create(['name' => 'Muhammad Maaz']);
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_leadership')
            ->set('complianceOfficerName', 'Muhammad Maaz')
            ->set('hipaaPrivacyOfficerName', 'Muhammad Maaz')
            ->set('hipaaSecurityOfficerName', 'Muhammad Maaz')
            ->set('releaseOfInfoOfficerName', 'Muhammad Maaz');

        // One pill for the shared name, not one per role/account-holder match.
        $roster = $component->get('knownTeamRoster');
        $this->assertCount(1, $roster);
        $this->assertSame('Muhammad Maaz', $roster[0]['name']);
        $this->assertSame(
            'HIPAA Privacy Officer, HIPAA Security Officer, Release of Information Officer, Compliance Officer',
            $roster[0]['roles']
        );

        $component->call('quickAddMember', 'committee', 'Muhammad Maaz', $roster[0]['roles']);

        $this->assertSame('Muhammad Maaz', $component->get('complianceCommitteeMembers')[0]['name']);
        $this->assertSame(
            'HIPAA Privacy Officer, HIPAA Security Officer, Release of Information Officer, Compliance Officer',
            $component->get('complianceCommitteeMembers')[0]['title']
        );
    }

    public function test_leadership_quick_add_lists_distinct_names_with_their_own_single_role(): void
    {
        $user = User::factory()->create(['name' => 'Jane Provider']);
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $roster = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_leadership')
            ->set('complianceOfficerName', 'Jane Provider')
            ->set('hipaaPrivacyOfficerName', 'Alex Privacy')
            ->set('hipaaSecurityOfficerName', 'Sam Security')
            ->get('knownTeamRoster');

        $byName = collect($roster)->keyBy('name');
        $this->assertSame('Compliance Officer', $byName['Jane Provider']['roles']);
        $this->assertSame('HIPAA Privacy Officer', $byName['Alex Privacy']['roles']);
        $this->assertSame('HIPAA Security Officer', $byName['Sam Security']['roles']);
    }

    // ── Questions ─────────────────────────────────────────────────────────

    private function advanceToQuestions(User $user, Order $order): Testable
    {
        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->set('screen', 't_practice')
            ->set('legalPracticeName', 'Sunrise Family Medicine LLC')
            ->set('mainPhone', '(555) 555-0100')
            ->set('mainEmail', 'jane@example.com')
            ->set('practiceLocations', ['123 Main St'])
            ->call('continueFromPractice')
            ->set('complianceOfficerName', 'Jane Provider')
            ->set('complianceOfficerPhone', '(555) 555-0100')
            ->set('complianceOfficerEmail', 'jane@example.com')
            ->set('hipaaPrivacyOfficerName', 'Jane Provider')
            ->set('hipaaPrivacyOfficerPhone', '(555) 555-0100')
            ->set('hipaaPrivacyOfficerEmail', 'jane@example.com')
            ->set('hipaaSecurityOfficerName', 'Jane Provider')
            ->set('hipaaSecurityOfficerPhone', '(555) 555-0100')
            ->set('hipaaSecurityOfficerEmail', 'jane@example.com')
            ->set('releaseOfInfoOfficerName', 'Jane Provider')
            ->set('releaseOfInfoOfficerPhone', '(555) 555-0100')
            ->set('releaseOfInfoOfficerEmail', 'jane@example.com')
            ->call('continueFromOfficers')
            ->set('itMode', 'vendor')
            ->set('itVendorName', 'Acme IT')
            ->set('itContactPhone', '(555) 555-0200')
            ->set('itContactEmail', 'it@example.com')
            ->call('continueFromIt')
            ->set('usesEhcpHotline', true)
            ->call('continueFromHotline')
            ->set('committeeNone', true)
            ->set('boardMode', 'owners')
            ->set('complianceGoverningBoardMembers.0.name', 'Jane Provider')
            ->call('continueFromLeadership')
            ->call('setServicesNone')
            ->call('continueFromServices');

        return $this->pickEveryGateTopic($component);
    }

    /** Walks every section's gate screen choosing "Yes" and picking every one of its topics —
     *  the test-suite's stand-in for "answer the whole questionnaire", matching the pre-gate
     *  behavior most of this file's tests were written against. */
    private function pickEveryGateTopic(Testable $component): Testable
    {
        foreach (IntakeSection::orderBy('sort_order')->get() as $section) {
            $questionIds = $section->questions()->orderBy('sort_order')->pluck('id')->all();

            if ($questionIds === []) {
                continue;
            }

            $component->call('chooseGateMode', $section->id, 'some');

            foreach ($questionIds as $questionId) {
                $component->call('toggleGateTopic', $section->id, $questionId);
            }

            $component->call('continueFromGate', $section->id);
        }

        return $component;
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

        $component->set('currentHasDocumentedProcess', true)
            ->set('currentResponse', 'Our board reviews the program quarterly.')
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
        $component->set('currentHasDocumentedProcess', true)->set('currentResponse', 'Answer two')->call('saveCurrentAnswer');
        $thirdQuestionId = $component->get('currentQuestionId');
        $this->assertNotSame($firstQuestionId, $thirdQuestionId);
        $this->assertNotSame($secondQuestionId, $thirdQuestionId);

        // Only once every other question has been answered does the skipped one come back around.
        $component->set('currentHasDocumentedProcess', true)->set('currentResponse', 'Answer three')->call('saveCurrentAnswer');
        $this->assertSame($firstQuestionId, $component->get('currentQuestionId'));
    }

    public function test_completing_every_question_shows_the_done_screen_without_advancing_yet(): void
    {
        $this->seedWorkflowQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = $this->advanceToQuestions($user, $order);

        $component->set('currentHasDocumentedProcess', true)->set('currentResponse', 'Answer one')->call('saveCurrentAnswer');
        $component->set('currentHasDocumentedProcess', true)->set('currentResponse', 'Answer two')->call('saveCurrentAnswer');
        $component->set('currentHasDocumentedProcess', true)->set('currentResponse', 'Answer three')->call('saveCurrentAnswer');

        // The last answer lands on the "saving" transition screen first — a purely cosmetic,
        // client-timed delay (its own timer calls finishWizard() after the checklist animation),
        // not persisted to wizard_screen since it's not meant to be resumable.
        $component->assertSet('screen', 'saving');
        $this->assertDatabaseCount('intake_answers', 3);

        // Nothing was skipped, so finishWizard() goes straight to Step 3 instead of pausing on
        // the wizard's own "done" screen — matching the reference prototype.
        $component->call('finishWizard')->assertDispatched('intake-wizard-complete');
        $this->assertDatabaseHas('intake_submissions', ['order_id' => $order->id, 'wizard_screen' => 'done']);
    }

    public function test_finishing_with_a_skipped_question_shows_the_done_screen_without_advancing(): void
    {
        $this->seedWorkflowQuestions();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = $this->advanceToQuestions($user, $order);
        $firstQuestionId = $component->get('currentQuestionId');

        // A question left skipped at the very end of the queue (e.g. via "Finish questionnaire")
        // means the client didn't answer everything — the done screen should still require a
        // manual "Continue" so they notice and can go back.
        IntakeSubmission::where('order_id', $order->id)->update(['wizard_skipped_question_ids' => [$firstQuestionId]]);

        $component->call('finishWizard')
            ->assertSet('screen', 'done')
            ->assertNotDispatched('intake-wizard-complete');

        $component->call('continueToConfirm')->assertDispatched('intake-wizard-complete');
    }

    public function test_booking_a_specialist_call_persists_it_and_notifies_every_admin(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('openCallDialog')
            ->assertSet('callDialogOpen', true);

        $component->set('callPhone', '+15551234567')
            ->set('callTopic', 'A question in the intake')
            ->set('callNotes', 'Not sure about the HIPAA security section.')
            ->call('bookSpecialistCall')
            ->assertHasNoErrors()
            ->assertSet('callBooked', true);

        $this->assertDatabaseHas('specialist_call_requests', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'phone' => '+15551234567',
            'topic' => 'A question in the intake',
            'notes' => 'Not sure about the HIPAA security section.',
        ]);

        Mail::assertSent(NewSpecialistCallRequestMail::class, fn ($mail) => $mail->hasTo($admin->email));
        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
    }

    public function test_booking_a_specialist_call_requires_a_valid_phone_number(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('openCallDialog')
            ->set('callPhone', 'not-a-phone')
            ->call('bookSpecialistCall')
            ->assertHasErrors(['callPhone']);

        $this->assertDatabaseCount('specialist_call_requests', 0);
    }

    public function test_first_visit_shows_the_intro_screen_before_documents(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->assertSet('screen', 'intro')
            ->assertSet('returnToScreen', null);

        $component->call('continueFromIntro')->assertSet('screen', 'documents');

        $this->assertDatabaseHas('intake_submissions', [
            'order_id' => $order->id,
        ]);
        $submission = IntakeSubmission::where('order_id', $order->id)->first();
        $this->assertContains('documents', $submission->wizard_reached_screens);
    }

    public function test_intro_screen_checklist_is_tier_aware(): void
    {
        $essentialUser = User::factory()->create();
        Practice::factory()->create(['user_id' => $essentialUser->id]);
        Livewire::actingAs($essentialUser)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$this->makeEssentialOrder($essentialUser)->id]])
            ->assertSet('screen', 'intro')
            ->assertSeeText('about 5 minutes')
            ->assertSeeText('Your current policies and training materials')
            ->assertDontSee('Contact details for 4 compliance roles');

        $proUser = User::factory()->create();
        Practice::factory()->create(['user_id' => $proUser->id]);
        Livewire::actingAs($proUser)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$this->makeProfessionalOrder($proUser)->id]])
            ->assertSet('screen', 'intro')
            ->assertSeeText('about 15')
            ->assertSeeText('Any current policies, manuals or training materials')
            ->assertSeeText('Contact details for 4 compliance roles')
            ->assertSeeText('Compliance Committee and owners or board')
            ->assertDontSee('Employee manual and encounter list');

        $advUser = User::factory()->create();
        Practice::factory()->create(['user_id' => $advUser->id]);
        Livewire::actingAs($advUser)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$this->makeAdvancedOrder($advUser)->id]])
            ->assertSet('screen', 'intro')
            ->assertSeeText('about 20')
            ->assertSeeText('Employee manual and encounter list');
    }

    public function test_a_later_visit_skips_the_intro_screen(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'wizard_screen' => 'b_profile',
            'wizard_reached_screens' => ['documents', 'b_profile'],
        ]);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->assertSet('screen', 'b_profile');
    }

    public function test_what_you_need_reopens_the_intro_as_a_static_recap_and_returns(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'wizard_screen' => 'b_profile',
            'wizard_reached_screens' => ['documents', 'b_profile'],
        ]);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->assertSet('screen', 'b_profile')
            ->call('viewWhatYouNeed')
            ->assertSet('screen', 'intro')
            ->assertSet('returnToScreen', 'b_profile')
            ->assertSeeText('Your intake is ready')
            ->assertSeeText('Back to the questions')
            ->call('backToQuestions')
            ->assertSet('screen', 'b_profile')
            ->assertSet('returnToScreen', null);
    }

    public function test_back_on_the_documents_screen_opens_the_checklist_with_a_full_bar(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeEssentialOrder($user);

        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'wizard_screen' => 'documents',
            'wizard_reached_screens' => ['documents'],
        ]);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->assertSet('screen', 'documents')
            ->assertSeeHtml('wire:click="viewWhatYouNeed"')
            ->assertDontSeeHtml('go-to-payment-step')
            ->call('viewWhatYouNeed')
            ->assertSet('screen', 'intro')
            ->assertSet('returnToScreen', 'documents')
            ->assertSeeHtml('style="width: 100%"')
            ->call('backToQuestions')
            ->assertSet('screen', 'documents');
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

    public function test_stepping_through_the_wizard_does_not_query_the_settings_table(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = $this->makeProfessionalOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('continueFromIntro');

        $settingsQueries = 0;
        DB::listen(function ($query) use (&$settingsQueries) {
            if (str_contains($query->sql, 'from `settings`') || str_contains($query->sql, 'from "settings"')) {
                $settingsQueries++;
            }
        });

        $component->call('continueFromDocuments', true)->call('continueFromProfile', true);

        $this->assertSame(0, $settingsQueries, 'The call-booking dialog settings must only be read while the dialog is open.');

        $component->call('openCallDialog');
        $this->assertGreaterThan(0, $settingsQueries);
    }

    public function test_the_specialty_dropdown_starts_on_the_placeholder_and_must_be_chosen(): void
    {
        $user = User::factory()->create();
        $order = $this->makeEssentialOrder($user);

        $component = Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('continueFromIntro')
            ->call('continueFromDocuments', true)
            ->assertSet('screen', 'b_profile')
            ->assertSet('specialty', '')
            ->assertSee('Select your specialty');

        $component->set('practiceName', 'Riverside Family Medicine')
            ->set('billableProviders', 2)
            ->call('continueFromProfile')
            ->assertHasErrors(['specialty']);
    }

    public function test_a_specialty_from_the_partner_that_is_not_in_our_list_stays_selected(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'specialty' => 'Exercise Physiology']);
        $order = $this->makeEssentialOrder($user);

        Livewire::actingAs($user)
            ->test('portal.practice-intake-wizard', ['orderIds' => [$order->id]])
            ->call('continueFromIntro')
            ->call('continueFromDocuments', true)
            ->assertSet('specialty', 'Exercise Physiology')
            ->assertSeeHtml('<option value="Exercise Physiology" selected>Exercise Physiology</option>');
    }
}
