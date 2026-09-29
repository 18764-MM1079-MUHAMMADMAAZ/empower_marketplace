<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['intake_section_id', 'sort_order', 'title', 'prompt_summary', 'why_we_ask'])]
class IntakeQuestion extends Model
{
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(IntakeSection::class, 'intake_section_id');
    }

    public function policies(): BelongsToMany
    {
        return $this->belongsToMany(CompliancePolicy::class, 'intake_question_policy');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(IntakeAnswer::class);
    }
}
