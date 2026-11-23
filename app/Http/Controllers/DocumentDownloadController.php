<?php

namespace App\Http\Controllers;

use App\Enums\DocumentDeliverySource;
use App\Models\ActivityLog;
use App\Models\GeneratedDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentDownloadController extends Controller
{
    public function show(Request $request, GeneratedDocument $document): StreamedResponse
    {
        if ($document->order->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $document->isReady()) {
            abort(404, 'Document not yet available.');
        }

        if ($document->delivery_source === DocumentDeliverySource::Custom) {
            $storagePath = $document->custom_storage_path;
            $filename = $document->custom_original_filename ?? $document->document_type->value;
            $mime = Storage::disk('local')->mimeType($storagePath) ?: 'application/octet-stream';
        } else {
            $format = $request->query('format', 'pdf');

            if ($format === 'docx' && $document->docx_storage_path) {
                $storagePath = $document->docx_storage_path;
                $filename = $document->document_type->value.'.docx';
                $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            } else {
                $storagePath = $document->pdf_storage_path;
                $filename = $document->document_type->value.'.pdf';
                $mime = 'application/pdf';
            }
        }

        if (! $storagePath || ! Storage::disk('local')->exists($storagePath)) {
            abort(404);
        }

        // My Account › Activity Log ("Downloaded Compliance & Ethics Manual").
        ActivityLog::record('document.downloaded', "Downloaded {$document->document_type->label()}", user: $request->user(), order: $document->order, subject: $document);

        return Storage::disk('local')->download($storagePath, $filename, ['Content-Type' => $mime]);
    }
}
