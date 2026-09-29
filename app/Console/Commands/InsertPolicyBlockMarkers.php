<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use ZipArchive;

/**
 * One-time template surgery: wraps each of the 101 existing "Practice Specific Workflow
 * Description" blocks (across the 3 manual templates) in ${prefix_nn_block}/${/prefix_nn_block}
 * cloneBlock markers, so GenerateComplianceDocument can delete a policy's whole section
 * (cloneBlock count: 0) instead of leaving "[No response provided]" placeholder text when a
 * question goes unanswered. Idempotent — re-running on an already-migrated template is a no-op
 * per policy (skips any block whose markers already exist).
 *
 * Backs up each template to "{name}.pre-block-markers.bak" before writing, since storage/app/* is
 * not git-tracked (storage/app/.gitignore blanket-ignores it).
 */
#[Signature('templates:insert-policy-block-markers')]
#[Description('Wraps each policy\'s Practice Specific Workflow Description block in cloneBlock markers')]
class InsertPolicyBlockMarkers extends Command
{
    private const TEMPLATES = [
        'compliance_ethics_manual.docx' => ['cmp', 17],
        'hipaa_privacy_policy.docx' => ['prv', 38],
        'hipaa_security_manual.docx' => ['sec', 46],
    ];

    public function handle(): int
    {
        foreach (self::TEMPLATES as $filename => [$prefix, $count]) {
            $this->migrateTemplate($filename, $prefix, $count);
        }

        return self::SUCCESS;
    }

    private function migrateTemplate(string $filename, string $prefix, int $count): void
    {
        $path = storage_path("app/templates/{$filename}");

        if (! file_exists($path)) {
            $this->components->warn("Skipping {$filename} — not found at {$path}.");

            return;
        }

        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $alreadyDone = 0;
        $inserted = 0;

        // Process from the last numbered block to the first so inserting text never shifts the
        // byte offsets of a block that hasn't been processed yet.
        for ($n = $count; $n >= 1; $n--) {
            $nn = sprintf('%02d', $n);
            $blockName = "{$prefix}_{$nn}_block";
            $answerNeedle = '${'.$prefix.'_'.$nn.'_answer}';

            if (str_contains($xml, '${'.$blockName.'}')) {
                $alreadyDone++;

                continue;
            }

            $answerPos = strpos($xml, $answerNeedle);

            if ($answerPos === false) {
                throw new RuntimeException("Missing {$answerNeedle} in {$filename}");
            }

            $endParaClose = strpos($xml, '</w:p>', $answerPos) + strlen('</w:p>');

            $headingTextPos = strrpos(substr($xml, 0, $answerPos), 'Practice Specific Workflow Description');

            if ($headingTextPos === false) {
                throw new RuntimeException("Missing heading before {$answerNeedle} in {$filename}");
            }

            preg_match_all('/<w:p(?=[ >])/', substr($xml, 0, $headingTextPos), $matches, PREG_OFFSET_CAPTURE);
            $headingParaStart = end($matches[0])[1];

            $startMarker = '<w:p><w:r><w:t>${'.$blockName.'}</w:t></w:r></w:p>';
            $endMarker = '<w:p><w:r><w:t>${/'.$blockName.'}</w:t></w:r></w:p>';

            // Insert at the higher offset first so $headingParaStart stays valid.
            $xml = substr_replace($xml, $endMarker, $endParaClose, 0);
            $xml = substr_replace($xml, $startMarker, $headingParaStart, 0);
            $inserted++;
        }

        if ($inserted === 0) {
            $this->components->info("{$filename}: all {$count} blocks already migrated, nothing to do.");

            return;
        }

        copy($path, $path.'.pre-block-markers.bak');

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        // Self-check: confirm every start/end marker pair exists, in the right order, directly
        // against the raw XML. Deliberately not using PhpWord's cloneBlock() here — its block
        // regex hits PHP's pcre.backtrack_limit on a document this size (confirmed empirically:
        // "Backtrack limit exhausted" against the real ~400KB compliance_ethics_manual.docx), so
        // GenerateComplianceDocument's runtime merge does its own direct string-based block
        // removal instead of calling cloneBlock() too — see that job for the actual mechanism.
        $zip = new ZipArchive;
        $zip->open($path);
        $finalXml = $zip->getFromName('word/document.xml');
        $zip->close();

        for ($n = 1; $n <= $count; $n++) {
            $nn = sprintf('%02d', $n);
            $blockName = "{$prefix}_{$nn}_block";
            $startPos = strpos($finalXml, '${'.$blockName.'}');
            $endPos = strpos($finalXml, '${/'.$blockName.'}');

            if ($startPos === false || $endPos === false || $endPos < $startPos) {
                throw new RuntimeException("Marker pair for {$blockName} missing or out of order in {$filename}");
            }
        }

        $this->components->info("{$filename}: inserted {$inserted} block marker(s), {$alreadyDone} already present. Verified all {$count} marker pairs are present and ordered correctly. Backup at {$filename}.pre-block-markers.bak");
    }
}
