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
    'is_stale', 'stale_reason', 'failure_reason', 'generated_at', 'version',
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
            'version' => 'integer',
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
            && (! $this->document_type->isManualUploadOnly()
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

    /** Can the client ask for this document to be generated again from My Account? Only when it's
     *  out of date or the last attempt failed, and never while a generation is already running. */
    public function canBeRegeneratedByClient(): bool
    {
        if ($this->document_type->isManualUploadOnly()
            || in_array($this->status, [DocumentStatus::Pending, DocumentStatus::Generating], true)) {
            return false;
        }

        return ($this->is_stale || $this->status === DocumentStatus::Failed)
            && ! $this->order->blockedFromAiGeneration();
    }

    /**
     * The status shown to the client on My Account, as [label, tone]. Tones map to the badge
     * colours in resources/views/account/_chip.blade.php.
     *
     * @return array{0: string, 1: string}
     */
    public function clientStatus(): array
    {
        // Manual-upload-only reports are never generated: until the admin's file is approved
        // they're simply "In review", not stuck on "Generating".
        $awaitingManualUpload = $this->document_type->isManualUploadOnly() && ! $this->isReady() && ! $this->is_stale;

        return match (true) {
            $awaitingManualUpload => ['In review', 'muted'],
            in_array($this->status, [DocumentStatus::Pending, DocumentStatus::Generating], true) => ['Generating', 'info'],
            $this->status === DocumentStatus::Failed => ['Failed', 'bad'],
            $this->is_stale => ['Needs update', 'warn'],
            $this->isReady() => ['Current', 'good'],
            default => ['In review', 'muted'],
        };
    }

    protected static function booted(): void
    {
        // Each new generation of an existing document is a new version. The first generation
        // (generated_at going from null to a date) stays version 1.
        static::updating(function (GeneratedDocument $document) {
            if ($document->isDirty('generated_at') && $document->getOriginal('generated_at') !== null) {
                $document->version = ($document->getOriginal('version') ?? 1) + 1;
            }
        });
    }
}
