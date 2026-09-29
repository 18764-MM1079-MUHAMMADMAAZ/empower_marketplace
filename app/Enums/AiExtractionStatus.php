<?php

namespace App\Enums;

enum AiExtractionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    // A reference document uploaded for human review only (the Practice Intake wizard's
    // documents screen) — deliberately never dispatched to ProcessIntakeUpload, so there's
    // nothing to extract. Distinct from Completed, which means extraction actually ran.
    case NotApplicable = 'not_applicable';
}
