<?php

namespace App\Models;

use App\Enums\DocumentDeliverySource;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Database\Factories\GeneratedDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'osha_location_id', 'intake_upload_id', 'document_type', 'status',
    'pdf_storage_path', 'docx_storage_path', 'pdf_owner_password',
    'is_stale', 'stale_reason', 'failure_reason', 'generated_at',
    'reviewed_at', 'reviewed_by', 'revoked_at', 'delivery_source',
    'custom_storage_path', 'custom_original_filename',
])]
class GeneratedDocument extends Model
{
    /** @use HasFactory<GeneratedDocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'delivery_source' => DocumentDeliverySource::class,
            'is_stale' => 'boolean',
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'revoked_at' => 'datetime',
            // owner password is encrypted at rest
            'pdf_owner_password' => 'encrypted',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function oshaLocation(): BelongsTo
    {
        return $this->belongsTo(OshaLocation::class);
    }

    public function intakeUpload(): BelongsTo
    {
        return $this->belongsTo(IntakeUpload::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function hasCustomDocument(): bool
    {
        return ! is_null($this->custom_storage_path);
    }

    public function isApproved(): bool
    {
        return ! is_null($this->reviewed_at);
    }

    /** Previously approved, then pulled back for changes (revoked, or un-approved by a
     *  delivery-source/custom-file change) and not yet re-approved. */
    public function wasRevoked(): bool
    {
        return ! is_null($this->revoked_at) && ! $this->isApproved();
    }

    /** Whether this document currently has a deliverable file for its active delivery source. */
    public function canBeApproved(): bool
    {
        return ! $this->is_stale
            && is_null($this->reviewed_at)
            && ($this->document_type !== DocumentType::ExclusionsScreeningReport
                || ($this->delivery_source === DocumentDeliverySource::Custom && $this->hasCustomDocument()))
            && $this->activeStoragePath() !== null;
    }

    /** The storage path of whichever version (AI-generated or custom) is set to be delivered. */
    public function activeStoragePath(): ?string
    {
        if ($this->delivery_source === DocumentDeliverySource::Custom) {
            return $this->custom_storage_path;
        }

        return $this->pdf_storage_path ?? $this->docx_storage_path;
    }

    /** Ready for the client to see and download, per the active delivery source. */
    public function isReady(): bool
    {
        return ! $this->is_stale
            && $this->isApproved()
            && $this->activeStoragePath() !== null;
    }

    public function markStale(string $reason): void
    {
        $this->update(['is_stale' => true, 'stale_reason' => $reason]);
    }
}
