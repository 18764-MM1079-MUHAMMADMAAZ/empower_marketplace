<?php

namespace App\Enums;

enum IntakeSubmissionStatus: string
{
    // A practice intake wizard in progress — answers/uploads are saved as the client goes, but
    // the submission isn't visible to admins and doesn't count as "submitted" for step routing
    // until the client certifies and submits it from the Upload & Confirm step.
    case Draft = 'draft';

    case Pending = 'pending';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
