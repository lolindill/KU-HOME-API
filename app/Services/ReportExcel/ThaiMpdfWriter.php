<?php

namespace App\Services\ReportExcel;

use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * 🖨️ (18/11/26) report-pdf-export — PDF writer ครอบ PhpSpreadsheet Writer\Pdf\Mpdf
 *
 *    ทำไมต้อง subclass? PhpSpreadsheet wrapper สร้าง \Mpdf\Mpdf instance เองข้างใน
 *    save() ด้วย config แค่ ['tempDir' => …] (ดู vendor Writer/Pdf/Mpdf.php) —
 *    ไม่มีช่องตั้ง fontDir/fontdata/default_font จึงต้อง override
 *    createExternalWriterInstance() เพื่อเติม config ฟอนต์ไทยก่อนสร้างจริง
 *
 *    - ฟอนต์ **Sarabun** (SIL OFL — แจกซ้ำได้ปลอดภัย) bundle ที่ resources/fonts/
 *      รองรับวรรณยุกต์/สระไทย + Latin ครบในตัวเดียว เหมาะกับรายงานไทย-เงินบาท
 *    - HTML ที่ PhpSpreadsheet generate อ้าง font-family ของ Excel default (Calibri)
 *      ซึ่ง mPDF ไม่รู้จัก → fallback ไป default_font ที่ตั้งเป็น sarabun ทุก cell
 *    - ห้าม hardcode path ฟอนต์แบบ absolute — ใช้ resource_path('fonts/') เสมอ
 *      เพื่อ sync ขึ้น main (deploy tree) ได้โดยไม่แตก
 */
class ThaiMpdfWriter extends \PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf
{
    public const THAI_FONT_FAMILY = 'sarabun';

    /**
     * 🖨️ CSS เฉพาะ PDF — inject เข้าไปใน HTML ที่ PhpSpreadsheet generate
     *    ผ่าน hook ทางการ Writer\Html::setEditHtmlCallback() (ต่อท้าย style block
     *    ของ vendor ก่อน </head> — cascade ชนะตัวเดิมเสมอ)
     *
     *    เทียบไม่ได้กับ Excel: เส้นตาราง (border) ติดที่ renderForPdf() เป็น style ของ
     *    spreadsheet เอง — ที่นี่คุมเฉพาะ padding ช่องที่ spreadsheet model ไม่มี concept
     */
    private const TABLE_CSS = <<<'CSS'
        <style>
        td, th { padding: 2px 5px; }
        </style>
        CSS;

    public function __construct(Spreadsheet $spreadsheet)
    {
        parent::__construct($spreadsheet);
        $this->setEditHtmlCallback(
            fn (string $html): string => str_replace('</head>', self::TABLE_CSS.'</head>', $html)
        );
    }

    /**
     * เติม config ฟอนต์ไทยแล้วค่อยสร้าง \Mpdf\Mpdf จริง
     * (user config 'fontdata' ของ mPDF จะ merge เพิ่มทับชุด default เอง)
     */
    protected function createExternalWriterInstance(array $config): Mpdf
    {
        $config['mode'] = 'utf-8';
        $config['fontDir'] = [resource_path('fonts')];
        $config['fontdata'] = [
            self::THAI_FONT_FAMILY => [
                'R' => 'Sarabun-Regular.ttf',
                'B' => 'Sarabun-Bold.ttf',
            ],
        ];
        $config['default_font'] = self::THAI_FONT_FAMILY;

        return new Mpdf($config);
    }
}
