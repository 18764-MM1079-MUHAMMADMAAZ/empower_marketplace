<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'intake_section_id', 'sort_order', 'title', 'prompt_summary', 'why_we_ask',
    'service_key', 'is_it_managed_topic', 'requires_compliance_committee',
])]
class IntakeQuestion extends Model
{
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_it_managed_topic' => 'boolean',
            'requires_compliance_committee' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(IntakeSection::class, 'intake_section_id');
    }

    /** Ordered by the pivot's own id (seeding order) rather than left to MySQL's unspecified
     *  default — the mapping order matters: "Notice of Privacy Practices" must show PRV-31 before
     *  PRV-10, matching the client's own mapping table. */
    public function policies(): BelongsToMany
    {
        return $this->belongsToMany(CompliancePolicy::class, 'intake_question_policy')->orderBy('intake_question_policy.id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(IntakeAnswer::class);
    }
}
