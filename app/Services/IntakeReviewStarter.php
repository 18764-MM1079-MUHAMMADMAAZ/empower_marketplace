<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Jobs\GenerateComplianceDocument;
use App\Models\ActivityLog;
use App\Models\GeneratedDocument;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
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

        $hasEncounterList = $order->intakeSubmission?->intakeUploads
            ->contains(fn (IntakeUpload $u) => $u->document_category === 'encounter_list') ?? false;

        foreach ($includedTypes as $typeValue) {
            $documentType = DocumentType::tryFrom($typeValue);

            if ($documentType === null || $alreadyGeneratedTypes->contains($documentType)) {
                continue;
            }

            // No point dispatching a Mini Audit report that will just fail without an encounter list.
            if ($documentType === DocumentType::CodingMiniAuditReport && ! $hasEncounterList) {
                continue;
            }

            GenerateComplianceDocument::dispatch($order, $documentType);
        }
    }
}
