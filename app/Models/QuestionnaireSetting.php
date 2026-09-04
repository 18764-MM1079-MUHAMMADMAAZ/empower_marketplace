<?php

namespace App\Models;

use App\Enums\IntakeUploadType;
use Database\Factories\QuestionnaireSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['upload_type', 'is_visible', 'is_required'])]
class QuestionnaireSetting extends Model
{
    /** @use HasFactory<QuestionnaireSettingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'upload_type' => IntakeUploadType::class,
            'is_visible' => 'boolean',
            'is_required' => 'boolean',
        ];
    }
}
