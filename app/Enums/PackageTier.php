<?php

namespace App\Enums;

enum PackageTier: string
{
    case Essential = 'essential';
    case Professional = 'professional';
    case Advanced = 'advanced';
    case Complete = 'complete';

    public function label(): string
    {
        return match ($this) {
            self::Essential => 'Essential Compliance',
            self::Professional => 'Professional Compliance',
            self::Advanced => 'Advanced Compliance',
            self::Complete => 'Complete Compliance',
        };
    }

    /** Whether this tier requires a custom quote (no self-serve payment) */
    public function isCustomQuote(): bool
    {
        return $this === self::Complete;
    }

    /** Whether Step 2 offers the "download our questionnaires" intake path — Essential Compliance
     *  only ever offers document upload for review. */
    public function allowsQuestionnaireDownload(): bool
    {
        return $this !== self::Essential;
    }

    /** Whether the Practice Intake wizard's one-question-per-screen workflow questionnaire
     *  applies to this tier. Essential only captures documents + practice basics — we don't
     *  generate compliance documents at that price point, so there's nothing for the 66
     *  workflow questions to feed. */
    public function includesWorkflowQuestionnaire(): bool
    {
        return $this !== self::Essential;
    }

    /** Whether the "Your documents" screen offers the Advanced-only "Employee manual" and
     *  "Encounter list (10 per provider)" upload categories, for its Coding & Documentation
     *  Mini Audit and Employee Manual Creation features. */
    public function includesAdvancedDocumentCategories(): bool
    {
        return $this === self::Advanced;
    }
}
