<?php

namespace App\Models;

use App\Enums\AiExtractionStatus;
use App\Enums\IntakeUploadType;
use Database\Factories\IntakeUploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'intake_submission_id', 'upload_type', 'document_category', 'original_filename', 'storage_path',
    'mime_type', 'file_size', 'ai_extraction_status',
    'ai_extracted_data', 'ai_error_message', 'processed_at',
])]
class IntakeUpload extends Model
{
    /** @use HasFactory<IntakeUploadFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'upload_type' => IntakeUploadType::class,
            'ai_extraction_status' => AiExtractionStatus::class,
            'ai_extracted_data' => 'array',
            'file_size' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function intakeSubmission(): BelongsTo
    {
        return $this->belongsTo(IntakeSubmission::class);
    }

    public function fileSizeForHumans(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return round($bytes / 1024, 1).' KB';
    }
}
