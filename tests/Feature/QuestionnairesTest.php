<?php

namespace Tests\Feature;

use App\Enums\IntakeUploadType;
use App\Models\QuestionnaireSetting;
use App\Support\Questionnaires;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionnairesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_tier_gets_all_four_questionnaires(): void
    {
        foreach (['essential', 'professional', 'advanced', 'complete'] as $tier) {
            $files = Questionnaires::forTiers([$tier])->pluck('file');

            $this->assertCount(4, $files, "Tier {$tier} should see all 4 questionnaires.");
            $this->assertTrue($files->contains('Compliance and Ethics Practice Workflow Questionnaire.docx'));
            $this->assertTrue($files->contains('HIPAA Business Associate Practice Workflow Questionnaire.docx'));
            $this->assertTrue($files->contains('HIPAA Privacy Practice Workflow Questionnaire.docx'));
            $this->assertTrue($files->contains('HIPAA Security Practice Workflow Questionnaire.docx'));
        }
    }

    public function test_url_points_into_the_manuals_directory(): void
    {
        $url = Questionnaires::url('HIPAA Security Practice Workflow Questionnaire.docx');

        $this->assertStringContainsString('/Manuals/Questionnaires/', $url);
        $this->assertStringContainsString('HIPAA%20Security%20Practice%20Workflow%20Questionnaire', $url);
    }

    // ── Visibility ──────────────────────────────────────────────────────────

    public function test_a_hidden_questionnaire_is_excluded_from_every_tier(): void
    {
        QuestionnaireSetting::factory()->create([
            'upload_type' => IntakeUploadType::HipaaPrivacyQuestionnaire,
            'is_visible' => false,
        ]);

        foreach (['essential', 'professional', 'advanced', 'complete'] as $tier) {
            $types = Questionnaires::forTiers([$tier])->pluck('uploadType');

            $this->assertFalse($types->contains(IntakeUploadType::HipaaPrivacyQuestionnaire), "Tier {$tier} should not see the hidden questionnaire.");
            $this->assertCount(3, $types);
        }
    }

    public function test_an_unhidden_questionnaire_reappears(): void
    {
        $setting = QuestionnaireSetting::factory()->create([
            'upload_type' => IntakeUploadType::HipaaPrivacyQuestionnaire,
            'is_visible' => false,
        ]);

        $setting->update(['is_visible' => true]);

        $types = Questionnaires::forTiers(['essential'])->pluck('uploadType');
        $this->assertTrue($types->contains(IntakeUploadType::HipaaPrivacyQuestionnaire));
        $this->assertCount(4, $types);
    }

    public function test_all_with_visibility_reports_true_and_the_catalog_default_with_no_row(): void
    {
        $questionnaire = Questionnaires::allWithVisibility()
            ->firstWhere(fn (array $q) => $q['uploadType'] === IntakeUploadType::ComplianceEthicsQuestionnaire);

        $this->assertTrue($questionnaire['isVisible']);
        $this->assertTrue($questionnaire['required']);

        $optional = Questionnaires::allWithVisibility()
            ->firstWhere(fn (array $q) => $q['uploadType'] === IntakeUploadType::HipaaPrivacyQuestionnaire);

        $this->assertTrue($optional['isVisible']);
        $this->assertFalse($optional['required']);
    }

    public function test_all_with_visibility_reflects_a_stored_override(): void
    {
        QuestionnaireSetting::factory()->create([
            'upload_type' => IntakeUploadType::HipaaSecurityQuestionnaire,
            'is_visible' => false,
        ]);

        $questionnaire = Questionnaires::allWithVisibility()
            ->firstWhere(fn (array $q) => $q['uploadType'] === IntakeUploadType::HipaaSecurityQuestionnaire);

        $this->assertFalse($questionnaire['isVisible']);
    }

    // ── setVisibility() / required reassignment ────────────────────────────

    public function test_hiding_the_required_questionnaire_promotes_the_next_visible_one(): void
    {
        $promoted = Questionnaires::setVisibility(IntakeUploadType::ComplianceEthicsQuestionnaire, false);

        $this->assertNotNull($promoted);
        $this->assertSame(IntakeUploadType::HipaaBusinessAssociateQuestionnaire, $promoted['uploadType']);

        $result = Questionnaires::forTiers(['essential'])->firstWhere(
            fn (array $q) => $q['uploadType'] === IntakeUploadType::HipaaBusinessAssociateQuestionnaire
        );
        $this->assertTrue($result['required']);

        $allWithVisibility = Questionnaires::allWithVisibility()
            ->firstWhere(fn (array $q) => $q['uploadType'] === IntakeUploadType::HipaaBusinessAssociateQuestionnaire);
        $this->assertTrue($allWithVisibility['required']);
    }

    public function test_hiding_a_non_required_questionnaire_promotes_nothing(): void
    {
        $promoted = Questionnaires::setVisibility(IntakeUploadType::HipaaPrivacyQuestionnaire, false);

        $this->assertNull($promoted);
        $this->assertDatabaseMissing('questionnaire_settings', [
            'upload_type' => 'hipaa_business_associate_questionnaire',
            'is_required' => true,
        ]);

        $required = Questionnaires::forTiers(['essential'])->firstWhere(fn (array $q) => $q['required']);
        $this->assertSame(IntakeUploadType::ComplianceEthicsQuestionnaire, $required['uploadType']);
    }

    public function test_hiding_every_questionnaire_in_turn_ends_with_nothing_required_and_no_error(): void
    {
        foreach (IntakeUploadType::cases() as $type) {
            if (Questionnaires::allWithVisibility()->firstWhere(fn (array $q) => $q['uploadType'] === $type) === null) {
                continue;
            }

            Questionnaires::setVisibility($type, false);
        }

        $this->assertCount(0, Questionnaires::forTiers(['essential']));
    }

    public function test_reshowing_the_original_required_questionnaire_clears_the_promoted_overrides(): void
    {
        Questionnaires::setVisibility(IntakeUploadType::ComplianceEthicsQuestionnaire, false);
        Questionnaires::setVisibility(IntakeUploadType::ComplianceEthicsQuestionnaire, true);

        $original = Questionnaires::allWithVisibility()
            ->firstWhere(fn (array $q) => $q['uploadType'] === IntakeUploadType::ComplianceEthicsQuestionnaire);
        $formerlyPromoted = Questionnaires::allWithVisibility()
            ->firstWhere(fn (array $q) => $q['uploadType'] === IntakeUploadType::HipaaBusinessAssociateQuestionnaire);

        $this->assertTrue($original['isVisible']);
        $this->assertTrue($original['required']);
        // Only one questionnaire should ever be required at a time — a stale promotion left
        // behind after the original required one comes back would otherwise show as a second
        // permanently-"Required" row in the admin list.
        $this->assertFalse($formerlyPromoted['required']);

        $requiredCount = Questionnaires::allWithVisibility()->filter(fn (array $q) => $q['required'])->count();
        $this->assertSame(1, $requiredCount);
    }

    /**
     * Regression test for a bug where hiding the promoted questionnaire itself (rather than
     * re-showing the original required one) left its `is_required` override in place forever —
     * so if it ever became visible again later, it showed as a second permanently-"Required" row
     * alongside whatever was promoted after it.
     */
    public function test_hiding_a_promoted_questionnaire_and_later_reshowing_it_does_not_leave_it_stuck_required(): void
    {
        Questionnaires::setVisibility(IntakeUploadType::ComplianceEthicsQuestionnaire, false); // promotes HIPAA BA
        Questionnaires::setVisibility(IntakeUploadType::HipaaBusinessAssociateQuestionnaire, false); // promotes HIPAA Privacy
        Questionnaires::setVisibility(IntakeUploadType::HipaaBusinessAssociateQuestionnaire, true); // shown again, still not required
        Questionnaires::setVisibility(IntakeUploadType::ComplianceEthicsQuestionnaire, true); // back to the original required one

        $all = Questionnaires::allWithVisibility();
        $this->assertTrue($all->firstWhere(fn (array $q) => $q['uploadType'] === IntakeUploadType::ComplianceEthicsQuestionnaire)['required']);
        $this->assertFalse($all->firstWhere(fn (array $q) => $q['uploadType'] === IntakeUploadType::HipaaBusinessAssociateQuestionnaire)['required']);
        $this->assertFalse($all->firstWhere(fn (array $q) => $q['uploadType'] === IntakeUploadType::HipaaPrivacyQuestionnaire)['required']);
        $this->assertSame(1, $all->filter(fn (array $q) => $q['required'])->count());
    }
}
