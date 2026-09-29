<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['intake_submission_id', 'intake_question_id', 'response', 'has_documented_process', 'skipped', 'answered_at'])]
class IntakeAnswer extends Model
{
    protected function casts(): array
    {
        return [
            'has_documented_process' => 'boolean',
            'skipped' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(IntakeSubmission::class, 'intake_submission_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(IntakeQuestion::class, 'intake_question_id');
    }
}
