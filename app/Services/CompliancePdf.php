<?php

namespace App\Services;

use TCPDF;

/**
 * TCPDF is extended (rather than configured via setHeaderData()) because that
 * built-in helper always resolves the logo path through the K_PATH_IMAGES
 * constant, which would silently break an absolute, per-practice logo path.
 */
class CompliancePdf extends TCPDF
{
    public ?string $headerTitle = null;

    public ?string $headerLogoPath = null;

    public function Header(): void
    {
        if ($this->headerTitle === null) {
            return;
        }

        $x = $this->original_lMargin;
        $y = $this->header_margin;

        // The logo is fit into a fixed-size box (rather than drawn at a fixed height with an
        // auto width) so a wide wordmark logo can't grow past its reserved space and collide
        // with the title text that starts right after it.
        $logoBoxWidth = 0.0;
        $logoBoxHeight = 9.0;

        if ($this->headerLogoPath !== null && file_exists($this->headerLogoPath)) {
            $logoBoxWidth = 35.0;
            $dimensions = @getimagesize($this->headerLogoPath);

            if ($dimensions !== false && $dimensions[0] > 0 && $dimensions[1] > 0) {
                $ratio = $dimensions[0] / $dimensions[1];
                [$drawWidth, $drawHeight] = $ratio >= ($logoBoxWidth / $logoBoxHeight)
                    ? [$logoBoxWidth, $logoBoxWidth / $ratio]
                    : [$logoBoxHeight * $ratio, $logoBoxHeight];

                $this->Image($this->headerLogoPath, $x, $y + (($logoBoxHeight - $drawHeight) / 2), $drawWidth, $drawHeight);
            }
        }

        $titleX = $x + $logoBoxWidth + ($logoBoxWidth > 0 ? 6 : 0);

        $this->setTextColorArray([90, 90, 90]);
        $this->setFont('helvetica', 'B', 10);
        $this->setXY($titleX, $y + 2);
        $this->Cell($this->w - $this->original_rMargin - $titleX, 6, $this->headerTitle, 0, 0, 'L');

        $this->setLineStyle(['width' => 0.2, 'color' => [200, 200, 200]]);
        $this->Line($this->original_lMargin, $y + 12, $this->w - $this->original_rMargin, $y + 12);
    }
}
