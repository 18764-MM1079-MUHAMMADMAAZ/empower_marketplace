<?php

namespace App\Services;

class CompliancePdfGenerator
{
    /** RGB colors lifted directly from the source Word templates. */
    private const COLOR_TEAL_BAR = [168, 217, 219]; // A8D9DB

    private const COLOR_NAVY = [28, 53, 87]; // 1C3557

    private const COLOR_GOLD_LINE = [231, 208, 141]; // E7D08D

    private const COLOR_ACCENT_BLUE = [70, 124, 157]; // 467C9D

    private const COLOR_GREY = [94, 94, 94]; // 5E5E5E

    /**
     * Renders HTML to an AES-256-protected, read-only PDF and returns the raw bytes.
     *
     * Passing $manual switches this from the generic single-shot renderer (used by
     * every other document type) to the questionnaire-linked manual layout: a cover
     * page redrawn to match the source Word template pixel-for-pixel (brand header,
     * side accent bar, centered practice logo, title, officer block), a running
     * header on every following page, and a generated Table of Contents.
     *
     * @param  array{title: string, logoPath: ?string, officerLabel: string, officerName: string, officerEmail: string, officerPhone: string, date: string}|null  $manual
     */
    public function generate(string $html, string $ownerPassword, ?array $manual = null): string
    {
        $html = $this->sanitizeForCoreFont($html);

        if ($manual !== null) {
            $manual = array_map(
                fn ($value) => is_string($value) ? $this->sanitizeForCoreFont($value) : $value,
                $manual,
            );
        }

        $pdf = new CompliancePdf('P', 'mm', $manual !== null ? 'LETTER' : 'A4', true, 'UTF-8');
        $pdf->setCreator(config('app.name'));
        $pdf->setPrintFooter(false);
        // 3 = AES-256; user password empty = opens without password; read-only (print only)
        $pdf->setProtection(['print'], '', $ownerPassword, 3);

        $pdf->setPrintHeader(false);
        $pdf->AddPage();

        if ($manual === null) {
            $pdf->writeHTML($html, true, false, true, false, '');

            return (string) $pdf->Output('', 'S');
        }

        $this->drawCoverPage($pdf, $manual);

        ['sections' => $sections] = $this->splitIntoHeadingSections($html);

        $pdf->headerTitle = $manual['title'];
        $pdf->headerLogoPath = $manual['logoPath'];
        $pdf->setMargins(15, $manual['logoPath'] !== null ? 26 : 20, 15);
        $pdf->setHeaderMargin(8);
        $pdf->setPrintHeader(true);
        $pdf->AddPage();

        foreach ($sections as $section) {
            if ($section['title'] !== '') {
                $pdf->Bookmark($section['title'], $section['level'], -1);
            }
            $pdf->writeHTML($section['html'], true, false, true, false, '');
        }

        if ($sections !== []) {
            $pdf->addTOCPage();
            $pdf->writeHTML('<h1 style="color: #1C3557; font-weight: bold;">Table of Contents</h1>', true, false, true, false, '');
            $pdf->addTOC(2, '', '.', 'Table of Contents');
            $pdf->endTOCPage();
        }

        return (string) $pdf->Output('', 'S');
    }

    /**
     * TCPDF's core "helvetica" font (used for every generated manual — nothing here embeds a
     * TrueType font) silently fails to render a handful of "smart" typographic characters that
     * Word freely inserts into typed text: an en dash in particular comes out as an invisible
     * soft-hyphen byte rather than a dash, which is what made the templates' "– Reference bullet"
     * lines look like their bullet marker had vanished. Swapping these for their plain-ASCII
     * equivalents keeps every character on the page actually visible.
     */
    private function sanitizeForCoreFont(string $text): string
    {
        return strtr($text, [
            "\u{2013}" => '-',  // en dash
            "\u{2018}" => "'",  // left single quote
            "\u{2019}" => "'",  // right single quote / apostrophe
            "\u{201C}" => '"',  // left double quote
            "\u{201D}" => '"',  // right double quote
            "\u{2026}" => '...', // ellipsis
            "\u{00A0}" => ' ',  // non-breaking space
        ]);
    }

    /**
     * Recreates the manual's cover page from scratch using the exact colors and
     * positions measured from the source Word templates (all 4 share identical
     * branding), rather than relying on PhpWord's lossy conversion of Word's
     * floating logo/text boxes — which is what drifted from the original design.
     *
     * @param  array{title: string, logoPath: ?string, officerLabel: string, officerName: string, officerEmail: string, officerPhone: string, date: string}  $manual
     */
    private function drawCoverPage(CompliancePdf $pdf, array $manual): void
    {
        $pageWidth = $pdf->getPageWidth();

        // Side accent bar + its small navy foot, anchored to the page exactly as
        // in the template (both are absolutely positioned, independent of margins).
        $pdf->setFillColorArray(self::COLOR_TEAL_BAR);
        $pdf->Rect(10.97, 35.61, 2.17, 222.95, 'F');
        $pdf->setFillColorArray(self::COLOR_NAVY);
        $pdf->Rect(10.94, 261.55, 2.23, 5.12, 'F');

        // Brand header: eHCP logo + manual title + gold rule (cover-only "first page" header).
        $brandLogo = storage_path('app/templates/ehcp-logo.png');
        if (file_exists($brandLogo)) {
            $pdf->Image($brandLogo, 13.41, 13.05, 44.36, 17.29);
        }
        $pdf->setTextColorArray(self::COLOR_ACCENT_BLUE);
        $pdf->setFont('helvetica', 'B', 13);
        $pdf->setXY(125.65, 17);
        $pdf->Cell($pageWidth - 125.65 - 10.75, 8, $manual['title'], 0, 0, 'L');

        $pdf->setDrawColorArray(self::COLOR_GOLD_LINE);
        $pdf->setLineWidth(0.8);
        $pdf->Line(10.75, 31.41, 204.81, 31.41);
        $pdf->setLineWidth(0.2);

        // Centered practice logo.
        if ($manual['logoPath'] !== null && file_exists($manual['logoPath'])) {
            $this->drawFittedImage($pdf, $manual['logoPath'], $pageWidth / 2, 85, 65);
        }

        // Title, date, officer block — each its own centered line, matching the layout.
        $pdf->setTextColorArray(self::COLOR_NAVY);
        $pdf->setFont('helvetica', 'BI', 22);
        $pdf->setXY(0, 168);
        $pdf->Cell($pageWidth, 10, $manual['title'], 0, 0, 'C');

        $pdf->setTextColorArray(self::COLOR_ACCENT_BLUE);
        $pdf->setFont('helvetica', 'B', 11);
        $pdf->setXY(0, 182);
        $pdf->Cell($pageWidth, 8, 'Date:  '.$manual['date'], 0, 0, 'C');

        $pdf->setTextColorArray(self::COLOR_GREY);
        $pdf->setFont('helvetica', '', 10);
        $pdf->setXY(0, 243);
        $pdf->Cell($pageWidth, 6, "{$manual['officerLabel']}:  {$manual['officerName']}", 0, 0, 'C');
        $pdf->setXY(0, 250);
        $pdf->Cell($pageWidth, 6, "Email:  {$manual['officerEmail']}     Telephone:  {$manual['officerPhone']}", 0, 0, 'C');

        $pdf->setTextColorArray([0, 0, 0]);
    }

    /** Draws an image no larger than $maxSize×$maxSize (mm), preserving its aspect ratio, centered on $centerX. */
    private function drawFittedImage(CompliancePdf $pdf, string $path, float $centerX, float $topY, float $maxSize): void
    {
        $dimensions = @getimagesize($path);
        if ($dimensions === false || $dimensions[0] <= 0 || $dimensions[1] <= 0) {
            return;
        }

        $ratio = $dimensions[0] / $dimensions[1];
        [$width, $height] = $ratio >= 1
            ? [$maxSize, $maxSize / $ratio]
            : [$maxSize * $ratio, $maxSize];

        $pdf->Image($path, $centerX - ($width / 2), $topY, $width, $height);
    }

    /**
     * Splits the converted manual HTML into its cover-page content and one
     * entry per top-level <h1>/<h2> heading, each paired with the HTML that
     * follows it up to the next heading. Bookmarking each section exactly
     * where it starts is what powers the generated Table of Contents. The
     * cover-page content itself is discarded — drawCoverPage() replaces it.
     *
     * @return array{cover: string, sections: array<int, array{level: int, title: string, html: string}>}
     */
    private function splitIntoHeadingSections(string $html): array
    {
        $parts = preg_split('/(<h[12][^>]*>.*?<\/h[12]>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false || count($parts) < 3) {
            return ['cover' => $html, 'sections' => []];
        }

        $sections = [];
        for ($i = 1; $i < count($parts); $i += 2) {
            $tag = $parts[$i];
            $body = $parts[$i + 1] ?? '';
            $level = stripos($tag, '<h1') === 0 ? 0 : 1;
            $title = trim(html_entity_decode(strip_tags($tag), ENT_QUOTES));

            // PhpWord's HTML writer drops a heading paragraph's own run color/bold
            // (unlike regular paragraphs, where it's preserved) — so headings need
            // their source template's colors reapplied here: navy for <h1>, teal
            // for <h2>, matching DarkBlueHeading1 / BlueHeading2 in the Word styles.
            $headingColor = $level === 0 ? '#1C3557' : '#467C9D';
            $tag = preg_replace(
                '/^(<h[12])(>)/i',
                '$1 style="color: '.$headingColor.'; font-weight: bold;"$2',
                $tag,
            );

            $sections[] = ['level' => $level, 'title' => $title, 'html' => $tag.$body];
        }

        return ['cover' => $parts[0], 'sections' => $sections];
    }
}
