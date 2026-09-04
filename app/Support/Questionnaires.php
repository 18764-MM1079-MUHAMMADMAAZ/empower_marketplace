<?php

namespace App\Support;

use App\Enums\IntakeUploadType;
use App\Models\QuestionnaireSetting;
use Illuminate\Support\Collection;

class Questionnaires
{
    private const DIRECTORY = 'Manuals/Questionnaires';

    /** @return array<int, array{file: string, title: string, description: string, tiers: ?array<int, string>, uploadType: IntakeUploadType, required: bool}> */
    private static function catalog(): array
    {
        return [
            [
                'file' => 'Compliance and Ethics Practice Workflow Questionnaire.docx',
                'title' => 'Compliance & Ethics Questionnaire',
                'description' => 'Practice workflow details used to build your Compliance & Ethics Manual.',
                'tiers' => null,
                'uploadType' => IntakeUploadType::ComplianceEthicsQuestionnaire,
                'required' => true,
            ],
            [
                'file' => 'HIPAA Business Associate Practice Workflow Questionnaire.docx',
                'title' => 'HIPAA Business Associate Questionnaire',
                'description' => 'Practice workflow details used to build your HIPAA Business Associate Manual.',
                'tiers' => null,
                'uploadType' => IntakeUploadType::HipaaBusinessAssociateQuestionnaire,
                'required' => false,
            ],
            [
                'file' => 'HIPAA Privacy Practice Workflow Questionnaire.docx',
                'title' => 'HIPAA Privacy Questionnaire',
                'description' => 'Practice workflow details used to build your HIPAA Privacy Policy.',
                'tiers' => null,
                'uploadType' => IntakeUploadType::HipaaPrivacyQuestionnaire,
                'required' => false,
            ],
            [
                'file' => 'HIPAA Security Practice Workflow Questionnaire.docx',
                'title' => 'HIPAA Security Questionnaire',
                'description' => 'Practice workflow details used to build your HIPAA Security Manual.',
                'tiers' => null,
                'uploadType' => IntakeUploadType::HipaaSecurityQuestionnaire,
                'required' => false,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $tierValues
     * @return Collection<int, array{file: string, title: string, description: string, tiers: ?array<int, string>, uploadType: IntakeUploadType, required: bool}>
     */
    public static function forTiers(array $tierValues): Collection
    {
        $settings = self::settingsByUploadType();

        return collect(self::catalog())
            ->filter(fn (array $q) => $q['tiers'] === null || array_intersect($q['tiers'], $tierValues))
            ->filter(fn (array $q) => $settings[$q['uploadType']->value]->is_visible ?? true)
            ->map(fn (array $q) => [
                ...$q,
                'required' => $settings[$q['uploadType']->value]->is_required ?? $q['required'],
            ])
            ->values();
    }

    /**
     * Every catalog entry regardless of tier, annotated with its effective (override-or-default)
     * visibility and required state — for the admin settings page.
     *
     * @return Collection<int, array{file: string, title: string, description: string, tiers: ?array<int, string>, uploadType: IntakeUploadType, required: bool, isVisible: bool}>
     */
    public static function allWithVisibility(): Collection
    {
        $settings = self::settingsByUploadType();

        return collect(self::catalog())->map(fn (array $q) => [
            ...$q,
            'required' => $settings[$q['uploadType']->value]->is_required ?? $q['required'],
            'isVisible' => $settings[$q['uploadType']->value]->is_visible ?? true,
        ])->values();
    }

    /**
     * The only way admin-side code should change a questionnaire's visibility. Re-syncs the
     * required overrides afterward in both directions: hiding the sole required questionnaire
     * among the visible set promotes another still-visible one, and showing a catalog-default
     * required questionnaire back clears any such promotion — so exactly one required
     * questionnaire is ever in effect, never zero and never more than one left stale.
     *
     * Returns the catalog entry that got newly promoted, or null if nothing needed to change.
     *
     * @return array{file: string, title: string, description: string, tiers: ?array<int, string>, uploadType: IntakeUploadType, required: bool}|null
     */
    public static function setVisibility(IntakeUploadType $uploadType, bool $isVisible): ?array
    {
        QuestionnaireSetting::updateOrCreate(['upload_type' => $uploadType], ['is_visible' => $isVisible]);

        return self::syncRequiredOverride();
    }

    /**
     * Finds whichever single visible questionnaire currently satisfies "at least one required"
     * (a catalog default first, else an already-promoted one; promoting a new one only if
     * neither exists), then clears an `is_required` override on every *other* row — including
     * ones not currently visible — so a promotion from an earlier toggle never lingers as a
     * second permanently-"Required" row once it's no longer needed.
     *
     * Returns the catalog entry that got newly promoted, or null if nothing needed to change.
     *
     * @return array{file: string, title: string, description: string, tiers: ?array<int, string>, uploadType: IntakeUploadType, required: bool}|null
     */
    private static function syncRequiredOverride(): ?array
    {
        $settings = self::settingsByUploadType();
        $visible = collect(self::catalog())->filter(fn (array $q) => $settings[$q['uploadType']->value]->is_visible ?? true);

        $satisfiedBy = $visible->first(fn (array $q) => $q['required'] === true)
            ?? $visible->first(fn (array $q) => ($settings[$q['uploadType']->value]->is_required ?? false) === true);

        $promoted = null;

        if ($satisfiedBy === null && $visible->isNotEmpty()) {
            $satisfiedBy = $promoted = $visible->first();
            QuestionnaireSetting::updateOrCreate(['upload_type' => $promoted['uploadType']], ['is_required' => true]);
        }

        QuestionnaireSetting::where('is_required', true)
            ->when($satisfiedBy !== null, fn ($query) => $query->where('upload_type', '!=', $satisfiedBy['uploadType']->value))
            ->update(['is_required' => null]);

        return $promoted;
    }

    /** @return Collection<string, QuestionnaireSetting> */
    private static function settingsByUploadType(): Collection
    {
        return QuestionnaireSetting::all()->keyBy(fn (QuestionnaireSetting $s) => $s->upload_type->value);
    }

    public static function url(string $filename): string
    {
        return asset(self::DIRECTORY.'/'.rawurlencode($filename));
    }
}
