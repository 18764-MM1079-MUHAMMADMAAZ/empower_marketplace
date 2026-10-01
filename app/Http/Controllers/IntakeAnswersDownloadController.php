<?php

namespace App\Http\Controllers;

use App\Models\IntakeSubmission;
use App\Services\IntakeAnswersPdfGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IntakeAnswersDownloadController extends Controller
{
    public function show(Request $request, IntakeSubmission $submission, IntakeAnswersPdfGenerator $generator): Response
    {
        $submission->loadMissing('order.user');

        if ($submission->order?->user_id !== $request->user()->id) {
            abort(403);
        }

        $pdf = $generator->generate($submission);
        $filename = 'intake-answers-'.$submission->id.'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
