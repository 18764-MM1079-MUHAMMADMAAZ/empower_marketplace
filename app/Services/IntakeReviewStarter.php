<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Jobs\GenerateComplianceDocument;
use App\Models\ActivityLog;
use App\Models\GeneratedDocument;
use App\Models\IntakeSubmission;
use App\Models\Order;

class IntakeReviewStarter
{
    /** Moves a Submitted submission to Under Review and kicks off AI document generation. */
    public function start(IntakeSubmission $submission): void
    {
        if ($submission->status !== IntakeSubmissionStatus::Submitted) {
            return;
        }

        $submission->update(['status' => IntakeSubmissionStatus::UnderReview, 'under_review_started_at' => now()]);

        ActivityLog::record(
            'submission.under_review',
            "Submission for order #{$submission->order_id} moved to under review.",
            user: auth()->user(),
            order: $submission->order,
            subject: $submission,
        );

        $this->dispatchIncludedDocuments($submission->order);
    }

    public function dispatchIncludedDocuments(Order $order): void
    {
        $includedTypes = $order->package?->included_document_types ?? [];

        // Excludes Pending rows deliberately — ensureExpectedDocumentsExist() pre-creates those
        // as placeholders the moment the admin page loads, so their presence alone can't mean
        // "already generated." Only a status past Pending means generation was attempted.
        $alreadyGeneratedTypes = GeneratedDocument::where('order_id', $order->id)
            ->where('status', '!=', DocumentStatus::Pending)
            ->pluck('document_type');

        foreach ($includedTypes as $typeValue) {
            $documentType = DocumentType::tryFrom($typeValue);

            // Manual-upload-only reports (SRA, Mini Audit, Exclusions Screening) are never
            // generated: their placeholder waits on the submission page for an admin's upload.
            if ($documentType === null || $documentType->isManualUploadOnly() || $alreadyGeneratedTypes->contains($documentType)) {
                continue;
            }

            GenerateComplianceDocument::dispatch($order, $documentType);
        }
    }
}
