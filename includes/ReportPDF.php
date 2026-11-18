<?php
/**
 * FPDF report used by export_report.php (format=pdf).
 * Printed on the paper chosen in the report modal (dms_report_paper()), with
 * the LGU header, the centred watermark on every page, wrapped table rows,
 * the column header repeated after page breaks, and a footer with the
 * office, the print date and "Page X of Y".
 */

require_once __DIR__ . '/../libraries/fpdf.php';

class DmsReportPDF extends FPDF
{
    /** @var array export columns from report_config.php, widths scaled to the page */
    private $columns;
    private $watermark;
    private $lineHeight = 5.5;
    private $margin = 12.7; // 0.5 in, same as the print page
    private $bottomMargin = 22;
    private $footerLeft = '';

    /**
     * @param array  $columns   report_config.php columns (w = relative width)
     * @param string $watermark image path, ignored when missing
     * @param array  $paper     dms_report_paper() result (w/h in mm, already turned)
     * @param string $footer    left footer text, e.g. office · printed date
     */
    public function __construct(array $columns, $watermark, array $paper, $footer = '')
    {
        $orientation = $paper['w'] > $paper['h'] ? 'L' : 'P';
        // FPDF wants the size as [short side, long side] and turns it itself.
        parent::__construct($orientation, 'mm', [min($paper['w'], $paper['h']), max($paper['w'], $paper['h'])]);

        $this->watermark  = is_file($watermark) ? $watermark : null;
        $this->footerLeft = self::enc($footer);
        $this->SetMargins($this->margin, $this->margin, $this->margin);
        $this->AliasNbPages();

        // Fill the printable width exactly, whatever the paper.
        $usable = $this->GetPageWidth() - 2 * $this->margin;
        $total  = array_sum(array_column($columns, 'w'));
        foreach ($columns as $i => $col) {
            $columns[$i]['w'] = $col['w'] * $usable / $total;
        }
        $this->columns = $columns;
    }

    /** UTF-8 → Windows-1252, the encoding of FPDF's core fonts. */
    public static function enc($text)
    {
        return mb_convert_encoding((string) $text, 'Windows-1252', 'UTF-8');
    }

    public function Header()
    {
        if ($this->watermark) {
            $size = 100;
            $this->Image(
                $this->watermark,
                ($this->GetPageWidth() - $size) / 2,
                ($this->GetPageHeight() - $size) / 2,
                $size,
                $size
            );
        }
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $half = ($this->GetPageWidth() - 2 * $this->margin) / 2;
        $this->Cell($half, 8, $this->footerLeft, 0, 0, 'L');
        $this->Cell($half, 8, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }

    /**
     * Draw the whole report.
     *
     * @param string $title     e.g. "Resolutions Report"
     * @param array  $subLines  lines under the title (period, filter, ...)
     * @param string $generated "Generated: ..." line
     * @param array  $rows      cell text per row, already in column order
     */
    public function render($title, array $subLines, $generated, array $rows)
    {
        $this->AddPage();

        $this->SetFont('Times', '', 12);
        $this->Cell(0, 5, 'Republic of the Philippines', 0, 1, 'C');
        $this->Cell(0, 5, 'Province of Camarines Norte', 0, 1, 'C');
        $this->Cell(0, 5, 'Municipality of Talisay', 0, 1, 'C');

        $this->Ln(5);
        $this->SetFont('Times', 'B', 17);
        $this->Cell(0, 8, self::enc($title), 0, 1, 'C');

        $this->SetFont('Times', '', 12);
        foreach ($subLines as $line) {
            $this->Cell(0, 6, self::enc($line), 0, 1, 'C');
        }
        $this->SetFont('Times', 'I', 10);
        $this->Cell(0, 6, self::enc($generated), 0, 1, 'C');
        $this->Ln(4);

        $this->tableHeader();

        $this->SetFont('Arial', '', 9);
        foreach ($rows as $cells) {
            $this->tableRow($cells);
        }

        if (!$rows) {
            $this->Cell($this->tableWidth(), 8, 'No records found for the selected filters.', 1, 1, 'C');
        }
    }

    private function tableWidth()
    {
        return array_sum(array_column($this->columns, 'w'));
    }

    /** Column labels; long labels wrap so narrow columns (portrait) still fit. */
    private function tableHeader()
    {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(238, 247, 238);

        $lh       = 4.5;
        $maxLines = 1;
        foreach ($this->columns as $col) {
            $maxLines = max($maxLines, $this->NbLines($col['w'], self::enc($col['label'])));
        }
        $height = max(8, $lh * $maxLines + 2);

        $x = $this->GetX();
        $y = $this->GetY();
        foreach ($this->columns as $col) {
            $label = self::enc($col['label']);
            $this->Rect($x, $y, $col['w'], $height, 'DF');
            // Centre the label block vertically in the cell.
            $this->SetXY($x, $y + ($height - $lh * $this->NbLines($col['w'], $label)) / 2);
            $this->MultiCell($col['w'], $lh, $label, 0, 'C');
            $x += $col['w'];
        }
        $this->SetXY($this->lMargin, $y + $height);
        $this->SetFont('Arial', '', 9);
    }

    private function tableRow(array $cells)
    {
        $encoded  = [];
        $maxLines = 1;
        foreach ($this->columns as $i => $col) {
            $encoded[$i] = self::enc(isset($cells[$i]) ? $cells[$i] : '');
            $maxLines    = max($maxLines, $this->NbLines($col['w'], $encoded[$i]));
        }
        $rowHeight = $this->lineHeight * $maxLines;

        if ($this->GetY() + $rowHeight > $this->GetPageHeight() - $this->bottomMargin) {
            $this->AddPage();
            $this->tableHeader();
        }

        $x = $this->GetX();
        $y = $this->GetY();
        foreach ($this->columns as $i => $col) {
            $this->Rect($x, $y, $col['w'], $rowHeight);
            $this->SetXY($x, $y);
            $this->MultiCell($col['w'], $this->lineHeight, $encoded[$i], 0, $col['align']);
            $x += $col['w'];
        }
        $this->SetXY($this->lMargin, $y + $rowHeight);
    }

    /** Number of lines a MultiCell of width $w will use for $txt. */
    public function NbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s    = str_replace("\r", '', $txt);
        $nb   = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }
        $sep = -1;
        $i   = 0;
        $j   = 0;
        $l   = 0;
        $nl  = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j   = $i;
                $l   = 0;
                $nl++;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
            }
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j   = $i;
                $l   = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }
}
