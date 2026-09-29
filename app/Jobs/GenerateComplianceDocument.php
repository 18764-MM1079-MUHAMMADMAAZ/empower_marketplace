<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Mail\ClientDocumentsApprovedMail;
use App\Models\ActivityLog;
use App\Models\CompliancePolicy;
use App\Models\GeneratedDocument;
use App\Models\IntakeAnswer;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\OshaLocation;
use App\Models\Practice;
use App\Services\CompliancePdfGenerator;
use App\Support\ManualQuestionSets;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use ZipArchive;

class GenerateComplianceDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Order $order,
        public readonly DocumentType $documentType,
        public readonly ?OshaLocation $oshaLocation = null,
        public readonly ?IntakeUpload $intakeUpload = null,
    ) {}

    /**
     * Whether this document was already approved (and so already delivered to the client)
     * before this run — captured before the "revoke prior approval" step below overwrites it.
     * finalizeGeneration() uses this to tell an untouched document that's simply finishing late
     * apart from one that's being deliberately regenerated after already going out; only the
     * former is safe to auto-approve.
     */
    private bool $wasApprovedBeforeThisRun = false;

    public function handle(CompliancePdfGenerator $pdfGenerator): void
    {
        $doc = GeneratedDocument::firstOrCreate(
            [
                'order_id' => $this->order->id,
                'document_type' => $this->documentType,
                'osha_location_id' => $this->oshaLocation?->id,
                'intake_upload_id' => $this->intakeUpload?->id,
            ],
            ['status' => DocumentStatus::Pending]
        );

        $this->wasApprovedBeforeThisRun = $doc->isApproved();

        // Revoke any prior admin approval — a (re)generated document must be reviewed again.
        $doc->update(['status' => DocumentStatus::Generating, 'reviewed_at' => null, 'reviewed_by' => null]);

        try {
            $viewData = $this->buildViewData();
            $basePath = 'private/compliance/'.$this->order->id;
            $slug = $this->documentType->value
                .($this->oshaLocation ? '_'.$this->oshaLocation->id : '')
                .($this->intakeUpload ? '_upload'.$this->intakeUpload->id : '');

            // An AI-polished 1:1 rendering of the client's own uploaded document — no merge
            // template involved, so this must be checked before the linked-questionnaire
            // branch below even though this type does have a linkedQuestionnaireType().
            if ($this->documentType->isPerUpload()) {
                $this->generateFromPolishedUpload($doc, $pdfGenerator, $basePath, $slug);

                return;
            }

            // A manual assembled from the client's own questionnaire answers — merge the
            // real answers into the template, then convert the result to a protected PDF.
            if ($this->documentType->linkedQuestionnaireType() !== null) {
                $this->generateFromQuestionnaireTemplate($doc, $pdfGenerator, $basePath, $slug, $viewData);

                return;
            }

            if ($this->documentType->isDocxOnly()) {
                $docxPath = $this->generateDocx($basePath, $slug, $viewData, $doc);

                if (! $docxPath) {
                    throw new \RuntimeException("Template not found for {$this->documentType->value}");
                }

                $this->finalizeGeneration($doc, [
                    'status' => DocumentStatus::Completed,
                    'pdf_storage_path' => null,
                    'docx_storage_path' => $docxPath,
                    'pdf_owner_password' => null,
                    'is_stale' => false,
                    'failure_reason' => null,
                    'generated_at' => now(),
                ]);

                return;
            }

            $html = view('documents.'.$this->documentType->value, $viewData)->render();

            $ownerPassword = Str::random(32);
            $pdfContent = $pdfGenerator->generate($html, $ownerPassword);

            $pdfPath = "{$basePath}/{$slug}.pdf";
            Storage::disk('local')->put($pdfPath, $pdfContent);

            $docxPath = $this->generateDocx($basePath, $slug, $viewData, $doc);

            $this->finalizeGeneration($doc, [
                'status' => DocumentStatus::Completed,
                'pdf_storage_path' => $pdfPath,
                'docx_storage_path' => $docxPath,
                'pdf_owner_password' => $ownerPassword,
                'is_stale' => false,
                'failure_reason' => null,
                'generated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateComplianceDocument failed', [
                'order_id' => $this->order->id,
                'document_type' => $this->documentType->value,
                'error' => $e->getMessage(),
            ]);

            $doc->update([
                'status' => DocumentStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Applies a successful generation result, then — only for a document completing for the
     * first time, never previously approved/delivered — if the submission was already approved
     * before it finished (e.g. it was still generating at approval time), immediately replays
     * that approval decision onto it. Approving a submission is meant to be a one-time decision
     * covering every document for the order (see SubmissionDetail::approve()), not just whichever
     * ones happened to be ready at that exact moment; without this, a late-finishing document is
     * stranded "Pending Review" forever, since the only approval action is hidden once the
     * submission itself is no longer awaiting review.
     *
     * A document that WAS already approved before this run (wasApprovedBeforeThisRun) is
     * deliberately excluded: that means it was already delivered and is now being regenerated,
     * which per the "revoke any prior admin approval" step above must go through a real human
     * re-review before going out again, submission status notwithstanding.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function finalizeGeneration(GeneratedDocument $doc, array $attributes): void
    {
        $doc->update($attributes);

        $submission = $this->order->intakeSubmission;

        if ($this->wasApprovedBeforeThisRun
            || ! $submission
            || $submission->status !== IntakeSubmissionStatus::Approved
            || ! $doc->canBeApproved()) {
            return;
        }

        $doc->update(['reviewed_at' => now(), 'reviewed_by' => $submission->reviewed_by, 'revoked_at' => null]);

        ActivityLog::record(
            'documents.approved',
            "{$doc->document_type->label()} for order #{$this->order->id} auto-approved after finishing generation (the submission was already approved).",
            user: $submission->reviewer,
            order: $this->order,
            subject: $doc,
        );

        try {
            Mail::to($this->order->user->email)->send(new ClientDocumentsApprovedMail($this->order, $doc->newCollection([$doc])));
        } catch (\Throwable $e) {
            Log::error('Failed to send the documents-ready email for an auto-approved document', [
                'order_id' => $this->order->id,
                'document_id' => $doc->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Renders the AI-polished HTML produced for this specific upload straight to a
     * protected PDF — no merge template exists for arbitrary client-uploaded content, so
     * unlike the questionnaire-linked manuals this document type is PDF-only.
     */
    private function generateFromPolishedUpload(
        GeneratedDocument $doc,
        CompliancePdfGenerator $pdfGenerator,
        string $basePath,
        string $slug,
    ): void {
        $upload = $this->intakeUpload ?? throw new \RuntimeException('Missing source upload for polished document.');

        $html = $upload->fresh()->ai_extracted_data['html'] ?? null;

        if (! $html) {
            throw new \RuntimeException('Source upload has no AI-polished content yet.');
        }

        $ownerPassword = Str::random(32);
        $pdfContent = $pdfGenerator->generate($html, $ownerPassword);

        $pdfPath = "{$basePath}/{$slug}.pdf";
        Storage::disk('local')->put($pdfPath, $pdfContent);

        $this->finalizeGeneration($doc, [
            'status' => DocumentStatus::Completed,
            'pdf_storage_path' => $pdfPath,
            'docx_storage_path' => null,
            'pdf_owner_password' => $ownerPassword,
            'is_stale' => false,
            'failure_reason' => null,
            'generated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $viewData */
    private function generateFromQuestionnaireTemplate(
        GeneratedDocument $doc,
        CompliancePdfGenerator $pdfGenerator,
        string $basePath,
        string $slug,
        array $viewData,
    ): void {
        $docxPath = $this->generateDocx($basePath, $slug, $viewData, $doc);

        if (! $docxPath) {
            throw new \RuntimeException("Template not found for {$this->documentType->value}");
        }

        $html = $this->convertDocxToHtml($docxPath);

        $schema = ManualQuestionSets::forDocumentType($this->documentType);
        $officer = $this->resolveOfficerInfo($schema, $viewData['aiData'], $viewData['practice']);

        $ownerPassword = Str::random(32);
        $pdfContent = $pdfGenerator->generate($html, $ownerPassword, [
            'title' => $this->extractCoverTitle($html) ?? $this->documentType->label(),
            'logoPath' => $this->resolvePracticeLogoPath($viewData['practice']),
            'officerLabel' => $this->officerLabel(),
            'officerName' => $officer['name'],
            'officerEmail' => $officer['email'],
            'officerPhone' => $officer['phone'],
            'date' => $viewData['generatedAt']->format('F j, Y'),
        ]);

        $pdfPath = "{$basePath}/{$slug}.pdf";
        Storage::disk('local')->put($pdfPath, $pdfContent);

        $this->finalizeGeneration($doc, [
            'status' => DocumentStatus::Completed,
            'pdf_storage_path' => $pdfPath,
            'docx_storage_path' => $docxPath,
            'pdf_owner_password' => $ownerPassword,
            'is_stale' => false,
            'failure_reason' => null,
            'generated_at' => now(),
        ]);
    }

    private function convertDocxToHtml(string $docxPath): string
    {
        // Converting a large, heavily-formatted manual to HTML and then parsing that HTML's
        // CSS in TCPDF comfortably exceeds PHP's default 128M CLI/worker memory limit.
        if ((int) ini_get('memory_limit') !== -1 && $this->parseMemoryLimit(ini_get('memory_limit')) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }

        $absoluteDocxPath = Storage::disk('local')->path($docxPath);
        $phpWord = IOFactory::load($absoluteDocxPath);
        $writer = IOFactory::createWriter($phpWord, 'HTML');

        $tempHtmlPath = tempnam(sys_get_temp_dir(), 'compliance_doc_').'.html';

        try {
            $writer->save($tempHtmlPath);

            return (string) file_get_contents($tempHtmlPath);
        } finally {
            if (file_exists($tempHtmlPath)) {
                unlink($tempHtmlPath);
            }
        }
    }

    private function parseMemoryLimit(string $limit): int
    {
        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /** @return array<string, mixed> */
    private function buildViewData(): array
    {
        $order = $this->order->load([
            'package', 'user.practice',
            'intakeSubmission.intakeUploads',
            'intakeSubmission.intakeAnswers.question.policies',
        ]);
        $practice = $order->user->practice;
        $submission = $order->intakeSubmission;

        $aiData = [];
        if ($submission) {
            foreach ($submission->intakeUploads as $upload) {
                if ($upload->ai_extracted_data) {
                    $aiData = array_merge($aiData, $upload->ai_extracted_data);
                }
            }
        }

        return [
            'practice' => $practice,
            'order' => $order,
            'handbookAnswers' => $submission?->handbook_answers ?? [],
            'aiData' => $aiData,
            'intakeAnswers' => $submission?->intakeAnswers ?? collect(),
            'oshaLocation' => $this->oshaLocation,
            'documentType' => $this->documentType,
            'generatedAt' => now(),
        ];
    }

    /** @param array<string, mixed> $viewData */
    private function generateDocx(string $basePath, string $slug, array $viewData, GeneratedDocument $doc): ?string
    {
        $templatePath = storage_path("app/templates/{$this->documentType->value}.docx");

        if (! file_exists($templatePath)) {
            return null;
        }

        $docxPath = "{$basePath}/{$slug}.docx";
        $absoluteOutput = Storage::disk('local')->path($docxPath);

        $dir = dirname($absoluteOutput);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $practice = $viewData['practice'];

        // PhpWord's TemplateProcessor::setValue() does NOT XML-escape replacement text unless
        // this is explicitly enabled — without it, a practice's own answer containing '&', '<',
        // or '>' (e.g. "Smith & Jones Family Practice") corrupts the merged document's XML.
        Settings::setOutputEscapingEnabled(true);
        $processor = new TemplateProcessor($templatePath);

        $values = [
            'practice_name' => $practice?->name ?? '',
            'practice_address' => $practice?->address ?? '',
            'specialty' => $practice?->specialty ?? '',
            'provider_count' => (string) ($practice?->billable_providers_count ?? ''),
            'package_name' => $viewData['order']->package?->name ?? '',
            'date' => $viewData['generatedAt']->format('F j, Y'),
            'osha_location_name' => $viewData['oshaLocation']?->name ?? '',
            'manual_history_change_type' => $doc->wasRecentlyCreated ? 'New' : 'Revision',
            'manual_history_description' => $doc->wasRecentlyCreated
                ? 'Initial policy generated.'
                : 'Policy regenerated following an update.',
            // Every questionnaire-linked manual's cover/Section 1 fields — harmless no-ops via
            // setValues() for whichever ones a given template doesn't actually declare.
            'compliance_officer_name' => $practice?->compliance_officer_name ?? '',
            'compliance_officer_email' => $practice?->compliance_officer_email ?? '',
            'compliance_officer_phone' => $practice?->compliance_officer_phone ?? '',
            'privacy_officer_name' => $practice?->hipaa_privacy_officer_name ?? '',
            'privacy_officer_email' => $practice?->hipaa_privacy_officer_email ?? '',
            'privacy_officer_phone' => $practice?->hipaa_privacy_officer_phone ?? '',
            'security_officer_name' => $practice?->hipaa_security_officer_name ?? '',
            'security_officer_email' => $practice?->hipaa_security_officer_email ?? '',
            'security_officer_phone' => $practice?->hipaa_security_officer_phone ?? '',
            'compliance_committee_members' => $this->formatMembers($practice?->compliance_committee_members),
            'governing_body' => $this->formatMembers($practice?->compliance_governing_board_members),
        ];

        $policies = CompliancePolicy::where('manual', $this->documentType->value)->get();
        $blocksToRemove = [];

        if ($policies->isNotEmpty()) {
            [$policyValues, $blocksToRemove] = $this->buildPolicyMergeValues($policies, $viewData['intakeAnswers']);
            $values = array_merge($values, $policyValues);
        } else {
            $schema = ManualQuestionSets::forDocumentType($this->documentType);

            if ($schema !== null) {
                $aiData = $viewData['aiData'];

                foreach (ManualQuestionSets::mergeFieldNames($schema) as $field) {
                    $values[$field] = (string) ($aiData[$field] ?? '[No response provided]');
                }
            }
        }

        $processor->setValues($values);
        $this->setPracticeLogo($processor, $practice);
        $processor->saveAs($absoluteOutput);

        if ($blocksToRemove !== []) {
            $this->removeUnansweredPolicyBlocks($absoluteOutput, $blocksToRemove);
        }

        return $docxPath;
    }

    /** @param array<int, array{name: string, title: string}>|null $members */
    private function formatMembers(?array $members): string
    {
        if (empty($members)) {
            return '';
        }

        return collect($members)
            ->filter(fn ($m) => trim($m['name'] ?? '') !== '')
            ->map(fn ($m) => trim($m['title'] ?? '') !== '' ? "{$m['name']} ({$m['title']})" : $m['name'])
            ->implode('; ');
    }

    /**
     * Builds the merge-field values for every policy this manual covers, and names the
     * cloneBlock-style markers (see InsertPolicyBlockMarkers) of every policy with no real
     * answer — those whole "Practice Specific Workflow Description" sections get physically
     * removed from the saved docx by removeUnansweredPolicyBlocks() rather than left showing
     * placeholder text. A policy fed by more than one wizard question (e.g. PRV-36) gets every
     * non-empty answer concatenated, each labeled by its source question.
     *
     * @param  Collection<int, CompliancePolicy>  $policies
     * @param  Collection<int, IntakeAnswer>  $answers
     * @return array{0: array<string, string>, 1: array<int, string>}
     */
    private function buildPolicyMergeValues(Collection $policies, Collection $answers): array
    {
        $entriesByPolicyId = [];

        foreach ($answers as $answer) {
            if (! $answer->has_documented_process || trim((string) $answer->response) === '') {
                continue;
            }

            foreach ($answer->question->policies as $policy) {
                $entriesByPolicyId[$policy->id][] = [
                    'title' => $answer->question->title,
                    'response' => $answer->response,
                ];
            }
        }

        $values = [];
        $blocksToRemove = [];

        foreach ($policies as $policy) {
            [$prefix, $number] = $policy->mergeFieldParts();
            $entries = $entriesByPolicyId[$policy->id] ?? [];

            if ($entries === []) {
                $blocksToRemove[] = "{$prefix}_{$number}_block";

                continue;
            }

            $values["{$prefix}_{$number}_answer"] = count($entries) > 1
                ? collect($entries)->map(fn ($e) => "{$e['title']}: {$e['response']}")->implode("\n\n")
                : $entries[0]['response'];

            // The block markers themselves are never removed for an answered policy (its
            // section is kept), but they still need a value — otherwise setValues() leaves
            // their literal "${cmp_01_block}" macro text sitting in the output.
            $values["{$prefix}_{$number}_block"] = '';
            $values["/{$prefix}_{$number}_block"] = '';
        }

        return [$values, $blocksToRemove];
    }

    /**
     * Physically deletes each unanswered policy's whole "Practice Specific Workflow
     * Description" section — the paragraphs between (and including) its
     * ${prefix_nn_block}/${/prefix_nn_block} markers — directly in the saved docx's XML.
     *
     * Deliberately not PhpWord's TemplateProcessor::cloneBlock(): its block regex hits PHP's
     * pcre.backtrack_limit against a document this size (confirmed empirically — see
     * InsertPolicyBlockMarkers, which inserted these same markers and documents the same
     * finding). A template that hasn't been migrated with these markers yet (e.g. the
     * business-associate manual, out of scope for now) simply has no matching markers, so this
     * is a safe no-op for it.
     *
     * @param  array<int, string>  $blockNames
     */
    private function removeUnansweredPolicyBlocks(string $absoluteDocxPath, array $blockNames): void
    {
        $zip = new ZipArchive;
        $zip->open($absoluteDocxPath);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        foreach ($blockNames as $blockName) {
            $startMarker = '<w:p><w:r><w:t>${'.$blockName.'}</w:t></w:r></w:p>';
            $endMarker = '<w:p><w:r><w:t>${/'.$blockName.'}</w:t></w:r></w:p>';

            $startPos = strpos($xml, $startMarker);
            $endPos = strpos($xml, $endMarker);

            if ($startPos === false || $endPos === false) {
                continue;
            }

            $removeLength = ($endPos + strlen($endMarker)) - $startPos;
            $xml = substr_replace($xml, '', $startPos, $removeLength);
        }

        $zip = new ZipArchive;
        $zip->open($absoluteDocxPath);
        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
    }

    /**
     * Swaps the ${practice_logo} macro (present only on the questionnaire-linked
     * manual covers) for the practice's uploaded logo. PhpWord can only embed
     * raster formats it can read dimensions from (png/jpg/gif) — an uploaded
     * SVG, or no logo at all, leaves the macro blank rather than broken.
     */
    private function setPracticeLogo(TemplateProcessor $processor, ?Practice $practice): void
    {
        $logoPath = $this->resolvePracticeLogoPath($practice);

        if ($logoPath === null) {
            $processor->setValue('practice_logo', '');

            return;
        }

        $processor->setImageValue('practice_logo', [
            'path' => $logoPath,
            'width' => 200,
            'height' => 200,
            'ratio' => true,
        ]);
    }

    /**
     * The manual's own cover-page title (e.g. "Compliance & Ethics Program"), used
     * as the running header text so it matches the source template verbatim rather
     * than our internal DocumentType label wording.
     */
    private function extractCoverTitle(string $html): ?string
    {
        if (! preg_match('/<span style="font-size: 12pt;">(.*?)<\/span>/s', $html, $matches)) {
            return null;
        }

        $title = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES));

        return $title !== '' ? $title : null;
    }

    /** Absolute path to the practice's uploaded logo, or null if there isn't one usable. */
    private function resolvePracticeLogoPath(?Practice $practice): ?string
    {
        $logoPath = $practice?->logo_path
            ? Storage::disk('public')->path($practice->logo_path)
            : null;

        if (! $logoPath || ! file_exists($logoPath) || strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)) === 'svg') {
            return null;
        }

        return $logoPath;
    }

    /** The cover page's officer role label, matching each template's own wording exactly. */
    private function officerLabel(): string
    {
        return match ($this->documentType) {
            DocumentType::ComplianceEthicsManual => 'Compliance Officer',
            DocumentType::HipaaSecurityManual => 'Security Officer',
            DocumentType::HipaaPrivacyPolicy => 'Privacy Officer',
            default => 'Officer',
        };
    }

    /**
     * Prefers the practice's own officer contact fields (captured on the Practice Intake
     * wizard's Team screen) — falls back to the AI-extracted questionnaire schema only for
     * manuals not yet on that flow (the business-associate manual).
     *
     * @param  array{prefix: string, count: int, extra_fields: array<string, string>}|null  $schema
     * @param  array<string, mixed>  $aiData
     * @return array{name: string, email: string, phone: string}
     */
    private function resolveOfficerInfo(?array $schema, array $aiData, ?Practice $practice): array
    {
        $fromPractice = match ($this->documentType) {
            DocumentType::ComplianceEthicsManual => [
                'name' => $practice?->compliance_officer_name,
                'email' => $practice?->compliance_officer_email,
                'phone' => $practice?->compliance_officer_phone,
            ],
            DocumentType::HipaaPrivacyPolicy => [
                'name' => $practice?->hipaa_privacy_officer_name,
                'email' => $practice?->hipaa_privacy_officer_email,
                'phone' => $practice?->hipaa_privacy_officer_phone,
            ],
            DocumentType::HipaaSecurityManual => [
                'name' => $practice?->hipaa_security_officer_name,
                'email' => $practice?->hipaa_security_officer_email,
                'phone' => $practice?->hipaa_security_officer_phone,
            ],
            default => null,
        };

        if ($fromPractice !== null && filled($fromPractice['name'])) {
            return [
                'name' => (string) $fromPractice['name'],
                'email' => (string) $fromPractice['email'],
                'phone' => (string) $fromPractice['phone'],
            ];
        }

        if ($schema === null) {
            return ['name' => '', 'email' => '', 'phone' => ''];
        }

        $find = function (string $suffix) use ($schema, $aiData): string {
            foreach (array_keys($schema['extra_fields']) as $key) {
                if (str_ends_with($key, $suffix)) {
                    return (string) ($aiData[$key] ?? '[No response provided]');
                }
            }

            return '';
        };

        return [
            'name' => $find('_officer_name'),
            'email' => $find('_officer_email'),
            'phone' => $find('_officer_phone'),
        ];
    }
}
