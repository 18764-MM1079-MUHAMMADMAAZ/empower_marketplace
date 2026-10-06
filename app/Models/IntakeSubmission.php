<?php

namespace App\Models;

use App\Enums\IntakeSubmissionStatus;
use Database\Factories\IntakeSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'status', 'handbook_answers',
    'reviewer_notes', 'reviewed_by', 'reviewed_at', 'submitted_at', 'under_review_started_at',
    'reviewer_question', 'reviewer_question_asked_at', 'reviewer_question_reply', 'reviewer_question_replied_at',
    'certified_by_name', 'certified_by_title', 'certified_signature', 'certified_at',
    'wizard_screen', 'wizard_reached_screens', 'wizard_skipped_question_ids', 'wizard_missing_document_categories',
    'wizard_selected_services', 'wizard_section_gates', 'wizard_skipped_section_ids',
])]
class IntakeSubmission extends Model
{
    /** @use HasFactory<IntakeSubmissionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IntakeSubmissionStatus::class,
            'handbook_answers' => 'array',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'under_review_started_at' => 'datetime',
            'reviewer_question_asked_at' => 'datetime',
            'reviewer_question_replied_at' => 'datetime',
            'certified_at' => 'datetime',
            'wizard_reached_screens' => 'array',
            'wizard_skipped_question_ids' => 'array',
            'wizard_missing_document_categories' => 'array',
            'wizard_selected_services' => 'array',
            'wizard_section_gates' => 'array',
            'wizard_skipped_section_ids' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function intakeUploads(): HasMany
    {
        return $this->hasMany(IntakeUpload::class);
    }

    public function intakeAnswers(): HasMany
    {
        return $this->hasMany(IntakeAnswer::class);
    }

    public function allUploadsProcessed(): bool
    {
        return $this->intakeUploads()
            ->whereNotIn('ai_extraction_status', ['completed', 'failed'])
            ->doesntExist();
    }
}
