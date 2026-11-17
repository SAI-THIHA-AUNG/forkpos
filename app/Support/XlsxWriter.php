<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Minimal multi-sheet .xlsx writer (OOXML) with no third-party dependency.
 *
 * Column kinds: 'text', 'money' (#,##0.00), 'qty' (#,##0.###), 'int', 'date'.
 * Numbers are written as real numeric cells so Excel can sum / pivot them.
 */
class XlsxWriter
{
    /** @var array<int, array{name:string,columns:array,rows:array,title:?string}> */
    protected array $sheets = [];

    /**
     * @param  array<int, array{0:string,1?:string,2?:float}>  $columns  [heading, kind, width]
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function addSheet(string $name, array $columns, array $rows, ?string $title = null): static
    {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name);
        $this->sheets[] = ['name' => mb_substr($name, 0, 31), 'columns' => $columns, 'rows' => $rows, 'title' => $title];

        return $this;
    }

    /** Write the workbook to $path. */
    public function save(string $path): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to export Excel files.');
        }
        if (! $this->sheets) {
            $this->addSheet('Sheet1', [['']], []);
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the Excel file.');
        }

        $sheetOverrides = '';
        $sheetRefs = '';
        $workbookRels = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $sheetOverrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetRefs .= '<sheet name="'.$this->esc($sheet['name']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $workbookRels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', $this->sheetXml($sheet));
        }
        $styleRel = count($this->sheets) + 1;
        $workbookRels .= '<Relationship Id="rId'.$styleRel.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheetOverrides.'</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetRefs.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$workbookRels.'</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->close();
    }

    /** Style ids: 0 default, 1 bold header, 2 money, 3 qty, 4 int, 5 title. */
    protected function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="#,##0.###"/></numFmts>'
            .'<fonts count="3">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="6">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    protected function sheetXml(array $sheet): string
    {
        $columns = $sheet['columns'];
        $kinds = array_map(fn ($c) => $c[1] ?? 'text', $columns);

        $cols = '';
        foreach ($columns as $i => $c) {
            $width = $c[2] ?? match ($c[1] ?? 'text') {
                'money', 'qty', 'int' => 14,
                'date' => 18,
                default => max(12, min(40, mb_strlen((string) $c[0]) + 4)),
            };
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        $rowsXml = '';
        $r = 1;
        if ($sheet['title'] !== null) {
            $rowsXml .= '<row r="'.$r.'">'.$this->cell(1, $r, $sheet['title'], 5).'</row>';
            $r += 2; // blank spacer row
        }
        $headerRow = $r;
        $cells = '';
        foreach ($columns as $i => $c) {
            $cells .= $this->cell($i + 1, $r, (string) $c[0], 1);
        }
        $rowsXml .= '<row r="'.$r.'">'.$cells.'</row>';

        foreach ($sheet['rows'] as $row) {
            $r++;
            $cells = '';
            foreach (array_values($row) as $i => $value) {
                $kind = $kinds[$i] ?? 'text';
                $style = match ($kind) {
                    'money' => 2, 'qty' => 3, 'int' => 4, default => 0
                };
                $cells .= $this->cell($i + 1, $r, $value, $style);
            }
            $rowsXml .= '<row r="'.$r.'">'.$cells.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$headerRow.'" topLeftCell="A'.($headerRow + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.$cols.'</cols><sheetData>'.$rowsXml.'</sheetData></worksheet>';
    }

    protected function cell(int $col, int $row, mixed $value, int $style): string
    {
        $ref = $this->columnName($col).$row;
        if ($value === null || $value === '') {
            return '<c r="'.$ref.'" s="'.$style.'"/>';
        }
        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }
        if (is_int($value) || is_float($value)) {
            return '<c r="'.$ref.'" s="'.$style.'"><v>'.(is_float($value) ? rtrim(rtrim(sprintf('%.8F', $value), '0'), '.') : $value).'</v></c>';
        }
        $text = $this->esc((string) $value);

        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
    }

    protected function columnName(int $col): string
    {
        $name = '';
        while ($col > 0) {
            $mod = ($col - 1) % 26;
            $name = chr(65 + $mod).$name;
            $col = intdiv($col - 1, 26);
        }

        return $name;
    }

    protected function esc(string $v): string
    {
        // Strip characters that are illegal in XML 1.0, then escape.
        $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v) ?? '';

        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
