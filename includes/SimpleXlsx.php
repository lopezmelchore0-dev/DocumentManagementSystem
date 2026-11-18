<?php
/**
 * Minimal single-sheet .xlsx writer built on ZipArchive (no Composer needed).
 *
 * Usage:
 *   $x = new DmsSimpleXlsx('Resolutions');
 *   $x->addRow(['Resolutions Report'], DmsSimpleXlsx::STYLE_TITLE);
 *   $x->addRow(['RESO NO.', 'TITLE'], DmsSimpleXlsx::STYLE_HEADER);
 *   $x->addRow(['001', 'A title'], DmsSimpleXlsx::STYLE_BODY);
 *   $x->download('report.xlsx');
 *
 * Cells are strings, numbers, or DmsSimpleXlsx::date('Y-m-d') values.
 */
class DmsSimpleXlsx
{
    // Indexes into cellXfs in styles.xml
    const STYLE_DEFAULT = 0;
    const STYLE_TITLE   = 1; // bold 14pt
    const STYLE_SUB     = 2; // 11pt, muted
    const STYLE_HEADER  = 3; // bold, green fill, borders, centered
    const STYLE_BODY    = 4; // wrap text, top aligned, borders
    const STYLE_DATE    = 5; // body + "mmmm d, yyyy" number format

    private $sheetName;
    private $rows    = [];
    private $widths  = [];
    private $merges  = [];
    private $freezeRow = 0;

    public function __construct($sheetName)
    {
        // Sheet names: max 31 chars, none of []:*?/\
        $name = preg_replace('/[\[\]:*?\/\\\\]/', ' ', (string) $sheetName);
        $this->sheetName = mb_substr(trim($name) !== '' ? $name : 'Sheet1', 0, 31);
    }

    /** Wrap a Y-m-d string so it is written as a real Excel date. */
    public static function date($ymd)
    {
        return ['date' => $ymd];
    }

    /** Column widths in Excel character units, in column order. */
    public function setWidths(array $widths)
    {
        $this->widths = array_values($widths);
    }

    /** Add a row; returns its 1-based row number. */
    public function addRow(array $cells, $style = self::STYLE_DEFAULT)
    {
        $this->rows[] = ['cells' => array_values($cells), 'style' => $style];
        return count($this->rows);
    }

    /** Merge the first $columns cells of row $row (1-based). */
    public function mergeRow($row, $columns)
    {
        if ($columns > 1) {
            $this->merges[] = 'A' . $row . ':' . self::col($columns - 1) . $row;
        }
    }

    /** Keep rows 1..$row visible while scrolling. */
    public function freezeAfterRow($row)
    {
        $this->freezeRow = (int) $row;
    }

    public function download($filename)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: max-age=0');
        readfile($tmp);
        unlink($tmp);
    }

    public function save($path)
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create xlsx file');
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());
        $zip->close();
    }

    /** 0 → A, 25 → Z, 26 → AA */
    private static function col($index)
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }
        return $name;
    }

    private static function esc($text)
    {
        // Strip characters that are illegal in XML 1.0 before escaping.
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $text);
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Excel serial day number for a Y-m-d date (1900 date system). */
    private static function serial($ymd)
    {
        $d = DateTime::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
        return $d ? (int) ($d->getTimestamp() / 86400) + 25569 : null;
    }

    private function sheet()
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
             . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';

        if ($this->freezeRow > 0) {
            $top  = 'A' . ($this->freezeRow + 1);
            $xml .= '<sheetViews><sheetView workbookViewId="0">'
                  . '<pane ySplit="' . $this->freezeRow . '" topLeftCell="' . $top . '" activePane="bottomLeft" state="frozen"/>'
                  . '</sheetView></sheetViews>';
        }

        if ($this->widths) {
            $xml .= '<cols>';
            foreach ($this->widths as $i => $w) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($this->rows as $r => $row) {
            $rowNum = $r + 1;
            $xml   .= '<row r="' . $rowNum . '">';
            foreach ($row['cells'] as $c => $value) {
                $ref   = self::col($c) . $rowNum;
                $style = $row['style'];

                if (is_array($value) && isset($value['date'])) {
                    $serial = self::serial($value['date']);
                    if ($serial !== null) {
                        $xml .= '<c r="' . $ref . '" s="' . self::STYLE_DATE . '"><v>' . $serial . '</v></c>';
                        continue;
                    }
                    $value = '';
                }
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
                          . self::esc($value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if ($this->merges) {
            $xml .= '<mergeCells count="' . count($this->merges) . '">';
            foreach ($this->merges as $ref) {
                $xml .= '<mergeCell ref="' . $ref . '"/>';
            }
            $xml .= '</mergeCells>';
        }

        $xml .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
              . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
              . '</worksheet>';
        return $xml;
    }

    private function styles()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="mmmm d, yyyy"/></numFmts>'
            . '<fonts count="4">'
            .   '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            .   '<font><b/><sz val="14"/><name val="Calibri"/><family val="2"/></font>'
            .   '<font><sz val="11"/><color rgb="FF4A4F49"/><name val="Calibri"/><family val="2"/></font>'
            .   '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            .   '<fill><patternFill patternType="none"/></fill>'
            .   '<fill><patternFill patternType="gray125"/></fill>'
            .   '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF7EE"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
            .   '<border><left style="thin"><color rgb="FFBFC3BC"/></left><right style="thin"><color rgb="FFBFC3BC"/></right>'
            .   '<top style="thin"><color rgb="FFBFC3BC"/></top><bottom style="thin"><color rgb="FFBFC3BC"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .   '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .     '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
            .     '<alignment vertical="top" wrapText="1"/></xf>'
            .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1">'
            .     '<alignment horizontal="left" vertical="top"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function contentTypes()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function rootRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }
}
