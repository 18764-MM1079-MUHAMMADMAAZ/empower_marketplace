<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['manual', 'code', 'title', 'page_reference', 'requirements'])]
class CompliancePolicy extends Model
{
    protected function casts(): array
    {
        return [
            'requirements' => 'array',
        ];
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(IntakeQuestion::class, 'intake_question_policy');
    }

    /** The template merge-field prefix/number pair this policy's answer/block markers use,
     *  e.g. "CMP-01" -> ['cmp', '01']. */
    public function mergeFieldParts(): array
    {
        [$prefix, $number] = explode('-', $this->code);

        return [strtolower($prefix), $number];
    }
}
