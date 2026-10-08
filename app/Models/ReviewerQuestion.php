<?php

namespace App\Models;

use Database\Factories\ReviewerQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['intake_submission_id', 'asked_by', 'question', 'reply', 'replied_at'])]
class ReviewerQuestion extends Model
{
    /** @use HasFactory<ReviewerQuestionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['replied_at' => 'datetime'];
    }

    public function isAnswered(): bool
    {
        return $this->reply !== null;
    }

    public function intakeSubmission(): BelongsTo
    {
        return $this->belongsTo(IntakeSubmission::class);
    }

    public function askedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by');
    }
}
