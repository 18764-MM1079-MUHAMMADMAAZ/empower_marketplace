<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'label', 'sort_order'])]
class IntakeSection extends Model
{
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(IntakeQuestion::class)->orderBy('sort_order');
    }
}
