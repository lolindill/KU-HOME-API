<?php

namespace App\Services\ReportExcel;

use App\Services\ReportExcel\Contracts\ReportData;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 📊 (05/10/26) excel-reports spec §1.2, §5 — Engine กลาง template-driven สำหรับเรนเดอร์ Excel (.xlsx)
 *
 *    หน้าที่หลัก:
 *    - อ่าน template JSON จาก resources/report-templates/ เพื่อจัดโครงหน้ามาตรฐาน
 *    - จัดการ styling ขาวดำ แบบ Minimal flat (ไม่มี merge cell, ไม่มี fill สี, bold เท่านั้น)
 *    - กำหนด PageSetup A4 Landscape, freezePane ใต้ header, repeat print title rows, autoFilter
 *    - Format ค่าตามชนิดคอลัมน์ (money_baht, integer, date พ.ศ. ผ่าน ThaiDate, boolean TRUE/FALSE)
 *    - ให้บริการ streamResponse() สร้างใน memory แล้ว stream ออกทันที (0 ไฟล์ค้าง disk)
 *
 *    ⚠️ ข้อห้ามเด็ดขาด: ห้ามมี business query ใน class นี้ (query ทั้งหมดเป็นหน้าที่ของ Data class)
 */
class ExcelReportRenderer
{
    /**
     * @var array<string, string> รูปแบบ Number format ต่อชนิดคอลัมน์ (spec §5)
     *                            · 'percent' เก็บสเกล 0–100 แสดงด้วย % literal (ไม่ใช่ 0% ที่ Excel คูณ 100 เอง)
     */
    public const FORMAT_MAP = [
        'money_baht' => '#,##0',
        'integer' => '#,##0',
        'percent' => '0.0"%"',
    ];

    /**
     * @var array<string, array> template cache ต่อ process เพื่อลด I/O ซ้ำซ้อน
     */
    private static array $templateCache = [];

    /**
     * เรนเดอร์ ReportData เป็น PhpSpreadsheet Spreadsheet object พร้อมโครงหน้าและสไตล์ครบถ้วน
     */
    public function render(ReportData $report, array $filters): Spreadsheet
    {
        $template = $this->loadTemplate($report->templateId());
        $nameTh = $template['name_th'] ?? $report->templateId();
        $templateFilters = $template['filters'] ?? [];

        // 1. 🔍 วิเคราะห์วันที่หลักและจัดสร้างข้อความแถว 2 (บรรทัด filter/ช่วงวันที่)
        //    รายงาน real-time (template ระบุ "filter_line_mode": "as_of") ใช้บรรทัด "ข้อมูลอัพเดต : วันที่ เวลา"
        [$primaryFrom, $primaryTo, $primaryKey] = $this->resolvePrimaryDate($templateFilters, $filters);
        $filterLine = ($template['filter_line_mode'] ?? null) === 'as_of'
            ? ThaiDate::asOfLine()
            : $this->buildFilterLine($templateFilters, $filters, $primaryKey, $primaryFrom, $primaryTo);

        // 2. 📄 สร้าง Spreadsheet และ active sheet
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // 🏷️ ตั้งชื่อชีต (tab) พร้อม sanitize อักขระต้องห้ามของ Excel (: \ / ? * [ ]) เหลือ ≤ 31 ตัวอักษร
        $sheet->setTitle($this->sanitizeSheetTitle($nameTh));

        // 🖨️ Print setup + ธรรมเนียมชีต (spec §5): A4 Landscape, freezePane A4, repeat print title แถว 3
        $pageSetup = $sheet->getPageSetup();
        $pageSetup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $pageSetup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->freezePane('A4');
        $pageSetup->setRowsToRepeatAtTopByStartAndEnd(3, 3);

        // 📝 แถว 1: ชื่อรายงาน (template name_th) — bold ขนาด 14
        $sheet->setCellValue('A1', $nameTh);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // 📝 แถว 2: บรรทัด filter/ช่วงวันที่ — bold ขนาดปกติ
        $sheet->setCellValue('A2', $filterLine);
        $sheet->getStyle('A2')->getFont()->setBold(true);

        // 📋 แถว 3: Header row จาก $report->columns($filters) (label_th) — bold ไม่มี fill สี
        $columns = $report->columns($filters);
        $colCount = count($columns);
        $lastColLetter = $colCount > 0 ? Coordinate::stringFromColumnIndex($colCount) : 'A';

        foreach ($columns as $idx => $col) {
            $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
            $sheet->setCellValue("{$colLetter}3", $col['label_th'] ?? $col['key']);
        }
        if ($colCount > 0) {
            $sheet->getStyle("A3:{$lastColLetter}3")->getFont()->setBold(true);
        }

        // 📊 แถวข้อมูลจาก $report->rows($filters) (iterable — buffer เป็น array ก่อนเขียนเพื่อคำนวณความกว้าง)
        $rawRows = $report->rows($filters);
        $rows = is_array($rawRows) ? $rawRows : iterator_to_array($rawRows);
        $currentRow = 4;

        foreach ($rows as $row) {
            // กรณีเป็นแถว section label (_section)
            if (isset($row['_section']) || array_key_exists('_section', $row)) {
                $sheet->setCellValue("A{$currentRow}", (string) $row['_section']);
                $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true);
                // ⚠️ สำคัญ (spec §5): แถว section bold ธรรมดา ไม่ merge cell
                $currentRow++;

                continue;
            }

            // แถวข้อมูลปกติ
            foreach ($columns as $idx => $col) {
                $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
                $colKey = $col['key'];
                $colType = $col['type'] ?? 'string';
                $val = $row[$colKey] ?? null;

                $this->writeCellValue($sheet, "{$colLetter}{$currentRow}", $val, $colType);
            }

            // 🏷️ format รายแถว (meta '_types' = map column key → type เช่นแถว % ของ manager-report)
            if (isset($row['_types']) && is_array($row['_types'])) {
                $keyIndex = array_column($columns, 'key');
                foreach ($row['_types'] as $typeKey => $rowType) {
                    $colIdx = array_search($typeKey, $keyIndex, true);
                    if ($colIdx !== false && isset(self::FORMAT_MAP[$rowType])) {
                        $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
                        $sheet->getStyle("{$colLetter}{$currentRow}")
                            ->getNumberFormat()
                            ->setFormatCode(self::FORMAT_MAP[$rowType]);
                    }
                }
            }
            $currentRow++;
        }

        $lastDataRow = $currentRow - 1;
        if ($lastDataRow < 4) {
            $lastDataRow = 3;
        }

        // 🔍 AutoFilter จาก header row ถึงแถวข้อมูลสุดท้าย (ไม่รวมแถว summary)
        if ($colCount > 0) {
            $sheet->setAutoFilter("A3:{$lastColLetter}{$lastDataRow}");
        }

        // 🧮 แถวท้ายตาราง = summary จาก $report->summary($filters) — ทุกแถว bold
        //    · map เดี่ยว (มี '_label') = แถวรวมแถวเดียว (spec §5)
        //    · ['_rows' => [map, …]] = summary หลายแถวตามชีตต้นทาง (เช่น extra-bed 3 แถว)
        $summary = $report->summary($filters);
        $summaryRows = [];
        if (isset($summary['_rows']) && is_array($summary['_rows'])) {
            $summaryRows = array_values($summary['_rows']);
        } elseif (! empty($summary)) {
            $summaryRows = [$summary];
        }

        foreach ($summaryRows as $summaryRow) {
            foreach ($columns as $idx => $col) {
                $colIdx = $idx + 1;
                $colLetter = Coordinate::stringFromColumnIndex($colIdx);

                if ($colIdx === 1) {
                    $sheet->setCellValue("{$colLetter}{$currentRow}", $summaryRow['_label'] ?? 'รวม');
                } else {
                    $colKey = $col['key'];
                    if (array_key_exists($colKey, $summaryRow)) {
                        $colType = $col['type'] ?? 'string';
                        $this->writeCellValue($sheet, "{$colLetter}{$currentRow}", $summaryRow[$colKey], $colType);
                    }
                }
            }
            if ($colCount > 0) {
                $sheet->getStyle("A{$currentRow}:{$lastColLetter}{$currentRow}")->getFont()->setBold(true);
            }
            $currentRow++;
        }

        // 🎨 กำหนด Number format รายบล็อก (ทั้งคอลัมน์ต่อช่วงแถว) ตาม spec §5
        $hasSummary = $summaryRows !== [];
        $endRow = $hasSummary ? $currentRow - 1 : $lastDataRow;
        if ($endRow >= 4) {
            foreach ($columns as $idx => $col) {
                $colType = $col['type'] ?? 'string';
                if (isset(self::FORMAT_MAP[$colType])) {
                    $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
                    $formatCode = self::FORMAT_MAP[$colType];
                    $sheet->getStyle("{$colLetter}4:{$colLetter}{$endRow}")
                        ->getNumberFormat()
                        ->setFormatCode($formatCode);
                }
            }
        }

        // 📏 ปรับความกว้างคอลัมน์: ประมาณจาก mb_strlen ของหัวคอลัมน์กับค่าที่ยาวสุดในคอลัมน์นั้น + 2 (ขั้นต่ำ 10 สูงสุด 42)
        $this->adjustColumnWidths($sheet, $columns, $rows, $summaryRows);

        return $spreadsheet;
    }

    /**
     * สตรีมไฟล์ Excel .xlsx ทันที (Synchronous streaming ใน memory ไม่ค้างลง disk)
     */
    public function streamResponse(ReportData $report, array $filters): StreamedResponse
    {
        $spreadsheet = $this->render($report, $filters);
        $filename = $this->filename($report, $filters);
        $encodedFilename = rawurlencode($filename);

        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => sprintf("attachment; filename=report.xlsx; filename*=UTF-8''%s", $encodedFilename),
            'Cache-Control' => 'max-age=0',
        ];

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, $headers);
    }

    /**
     * สร้างชื่อไฟล์ภาษาไทยตามช่วง filter เช่น รายงานห้องเข้าพัก (14-09-2569_ถึง_20-09-2569).xlsx
     */
    public function filename(ReportData $report, array $filters): string
    {
        $template = $this->loadTemplate($report->templateId());
        $nameTh = $template['name_th'] ?? $report->templateId();
        $templateFilters = $template['filters'] ?? [];

        [$primaryFrom, $primaryTo] = $this->resolvePrimaryDate($templateFilters, $filters);
        $slug = ThaiDate::rangeSlug($primaryFrom, $primaryTo);

        if ($slug !== '') {
            return "{$nameTh} ({$slug}).xlsx";
        }

        return "{$nameTh}.xlsx";
    }

    /**
     * 🏷️ Sanitize ชื่อชีตตามข้อกำหนดของ Excel (: \ / ? * [ ]) เป็น '-' และตัดเหลือ ≤ 31 ตัวอักษร
     */
    public function sanitizeSheetTitle(string $title): string
    {
        $cleaned = preg_replace('/[:\\\\\\/?*\\[\\]]/u', '-', $title);
        $cleaned = trim((string) $cleaned, "'");
        $cleaned = mb_substr($cleaned, 0, 31);

        return $cleaned !== '' ? $cleaned : 'Sheet1';
    }

    /**
     * 📁 โหลด template JSON จาก resources/report-templates/ (มี cache ต่อ process)
     *
     * @return array<string, mixed>
     */
    protected function loadTemplate(string $templateId): array
    {
        if (isset(self::$templateCache[$templateId])) {
            return self::$templateCache[$templateId];
        }

        $path = resource_path('report-templates/'.$templateId.'.json');
        if (is_file($path)) {
            try {
                $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                self::$templateCache[$templateId] = $data;

                return $data;
            } catch (\Throwable $e) {
                // หากไฟล์ JSON เสีย ให้ fallback โครงสร้างเปล่า
            }
        }

        return [
            'name_th' => $templateId,
            'filters' => [],
            'columns' => [],
        ];
    }

    /**
     * 🔍 วิเคราะห์หา filter วันที่หลักจาก template filters
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} [primaryFrom, primaryTo, primaryKey]
     */
    protected function resolvePrimaryDate(array $templateFilters, array $filters): array
    {
        foreach ($templateFilters as $tf) {
            $type = $tf['type'] ?? '';
            $key = $tf['key'] ?? '';

            // type = 'date' → ใช้ค่า filter key นั้น (หรือช่วงวันที่ date_from/date_to หากส่งมา)
            if ($type === 'date') {
                $val = $filters[$key] ?? $filters['date'] ?? null;
                if ($val !== null && $val !== '') {
                    return [$val, $val, $key];
                }

                $from = $filters[$key.'_from'] ?? $filters['date_from'] ?? null;
                $to = $filters[$key.'_to'] ?? $filters['date_to'] ?? null;
                if ($from !== null || $to !== null) {
                    return [$from, $to, $key];
                }

                return [null, null, $key];
            }

            // type = 'date_range' → คู่คีย์ {key}_from/{key}_to หรือ date_from/date_to · ถ้าไม่เจอทั้งคู่ ใช้ค่าเดียว
            if ($type === 'date_range') {
                $from = $filters[$key.'_from'] ?? $filters['date_from'] ?? null;
                $to = $filters[$key.'_to'] ?? $filters['date_to'] ?? null;

                if ($from === null && $to === null && isset($filters[$key])) {
                    $from = $filters[$key];
                    $to = $filters[$key];
                }

                return [$from, $to, $key];
            }
        }

        // กรณี template filters ไม่ได้ระบุ date/date_range ให้ลองหาจาก $filters โดยตรง
        if (isset($filters['date_from']) || isset($filters['date_to'])) {
            return [$filters['date_from'] ?? null, $filters['date_to'] ?? null, null];
        }

        if (isset($filters['date'])) {
            return [$filters['date'], $filters['date'], null];
        }

        return [null, null, null];
    }

    /**
     * 📝 สร้างข้อความบรรทัด filter / ช่วงวันที่ แถว 2
     */
    protected function buildFilterLine(
        array $templateFilters,
        array $filters,
        ?string $primaryKey,
        ?string $primaryFrom,
        ?string $primaryTo
    ): string {
        $parts = [];

        // ขึ้นต้น "ข้อมูลวันที่ …" (ใช้ ThaiDate::rangeLine กับ filter วันที่หลัก)
        if ($primaryFrom !== null || $primaryTo !== null) {
            $parts[] = ThaiDate::rangeLine($primaryFrom, $primaryTo);
        } else {
            $parts[] = 'ข้อมูลวันที่ -';
        }

        // ต่อด้วย " · label: value" ของ filter อื่นที่ส่งมา
        foreach ($templateFilters as $tf) {
            $key = $tf['key'] ?? '';
            if ($key === '' || $key === $primaryKey) {
                continue;
            }

            // ข้าม date_from/date_to ที่ถูกใช้เป็นส่วนหนึ่งของ date_range แล้ว
            if ($primaryKey === 'date_range' && in_array($key, ['date_from', 'date_to', 'date_range_from', 'date_range_to'], true)) {
                continue;
            }

            $val = $filters[$key] ?? null;
            if ($val === null || $val === '') {
                continue;
            }

            $label = $tf['label_th'] ?? $key;
            $type = $tf['type'] ?? 'string';

            $displayVal = $type === 'date' ? ThaiDate::format($val) : (string) $val;
            $parts[] = "{$label}: {$displayVal}";
        }

        return implode(' · ', $parts);
    }

    /**
     * ✍️ เขียนค่าลงใน Cell ตามชนิดข้อมูล (spec §5)
     */
    protected function writeCellValue($sheet, string $cellCoordinate, mixed $val, string $type): void
    {
        if ($val === null || $val === '') {
            $sheet->setCellValue($cellCoordinate, '');

            return;
        }

        match ($type) {
            'money_baht', 'integer', 'percent' => is_numeric($val)
                ? $sheet->setCellValueExplicit($cellCoordinate, $val + 0, DataType::TYPE_NUMERIC)
                : $sheet->setCellValue($cellCoordinate, (string) $val),
            'date' => $sheet->setCellValueExplicit($cellCoordinate, ThaiDate::format($val), DataType::TYPE_STRING),
            'boolean' => $sheet->setCellValueExplicit($cellCoordinate, $val ? 'TRUE' : 'FALSE', DataType::TYPE_STRING),
            default => $sheet->setCellValue($cellCoordinate, (string) $val),
        };
    }

    /**
     * 📐 แปลงค่าเป็น string สำหรับใช้คำนวณความกว้างคอลัมน์
     */
    protected function formatDisplayString(mixed $val, string $type): string
    {
        if ($val === null || $val === '') {
            return '';
        }

        return match ($type) {
            'money_baht', 'integer' => is_numeric($val) ? number_format((float) $val) : (string) $val,
            'percent' => is_numeric($val) ? number_format((float) $val, 1).'%' : (string) $val,
            'date' => ThaiDate::format($val),
            'boolean' => $val ? 'TRUE' : 'FALSE',
            default => (string) $val,
        };
    }

    /**
     * 📏 คำนวณและปรับความกว้างคอลัมน์อัตโนมัติ (min 10, max 42)
     *
     * @param  array  $summaryRows  list ของแถว summary (0 หรือหลายแถว)
     */
    protected function adjustColumnWidths($sheet, array $columns, array $rows, array $summaryRows): void
    {
        foreach ($columns as $idx => $col) {
            $colIdx = $idx + 1;
            $colLetter = Coordinate::stringFromColumnIndex($colIdx);
            $colKey = $col['key'];
            $colType = $col['type'] ?? 'string';

            $maxLen = mb_strlen((string) ($col['label_th'] ?? $colKey));

            // ตรวจสอบความยาวในแถวข้อมูลปกติ (ข้ามแถว section label)
            foreach ($rows as $row) {
                if (isset($row['_section']) || array_key_exists('_section', $row)) {
                    continue;
                }
                $val = $row[$colKey] ?? null;
                $len = mb_strlen($this->formatDisplayString($val, $colType));
                if ($len > $maxLen) {
                    $maxLen = $len;
                }
            }

            // ตรวจสอบความยาวในทุกแถว summary
            foreach ($summaryRows as $summary) {
                if ($colIdx === 1) {
                    $len = mb_strlen((string) ($summary['_label'] ?? 'รวม'));
                } elseif (array_key_exists($colKey, $summary)) {
                    $len = mb_strlen($this->formatDisplayString($summary[$colKey], $colType));
                } else {
                    $len = 0;
                }
                if ($len > $maxLen) {
                    $maxLen = $len;
                }
            }

            $width = max(10, min(42, $maxLen + 2));
            $sheet->getColumnDimension($colLetter)->setWidth($width);
        }
    }
}
