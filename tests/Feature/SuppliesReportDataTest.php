<?php

namespace Tests\Feature;

use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ReportExcel\Data\SuppliesReportData;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SuppliesReportDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplies_report_data_calculates_all_fields_and_removes_nulls(): void
    {
        // 1. สร้าง Stock พร้อมข้อมูลครบถ้วน
        $stock1 = StockInventory::create([
            'item_code' => 'SKU-WATER-01',
            'item_name' => 'น้ำดื่ม 500ml',
            'category' => 'Guest Amenities',
            'quantity' => 150,
            'unit' => 'ขวด',
            'reorder_point' => 50,
            'max_stock' => 500,
        ]);

        $stock2 = StockInventory::create([
            'item_code' => 'SKU-TOWEL-01',
            'item_name' => 'ผ้าเช็ดตัว',
            'category' => 'Linen and Bedding',
            'quantity' => 10,
            'unit' => 'ผืน',
            'reorder_point' => 20,
            'max_stock' => 100,
        ]);

        // สร้าง movements:
        // ก่อน 2026-10-01
        $m1 = StockMovement::create([
            'stock_inventory_id' => $stock1->id,
            'delta' => 100,
            'quantity_before' => 0,
            'quantity_after' => 100,
            'reason' => 'ยกมาก่อนช่วง',
        ]);
        $m1->created_at = Carbon::parse('2026-09-25 10:00:00');
        $m1->saveQuietly();

        // ในช่วง 2026-10-01 ถึง 2026-10-05
        $m2 = StockMovement::create([
            'stock_inventory_id' => $stock1->id,
            'delta' => 80,
            'quantity_before' => 100,
            'quantity_after' => 180,
            'reason' => 'รับเข้า',
        ]);
        $m2->created_at = Carbon::parse('2026-10-02 11:00:00');
        $m2->saveQuietly();

        $m3 = StockMovement::create([
            'stock_inventory_id' => $stock1->id,
            'delta' => -30,
            'quantity_before' => 180,
            'quantity_after' => 150,
            'reason' => 'เบิกจ่าย',
        ]);
        $m3->created_at = Carbon::parse('2026-10-03 14:00:00');
        $m3->saveQuietly();

        $reportData = new SuppliesReportData();
        $filters = [
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-05',
        ];

        $rows = iterator_to_array($reportData->rows($filters));

        $this->assertCount(2, $rows);

        // ตรวจสอบ stock1 (น้ำดื่ม)
        $waterRow = collect($rows)->firstWhere('item_code', 'SKU-WATER-01');
        $this->assertNotNull($waterRow);
        $this->assertSame('SKU-WATER-01', $waterRow['item_code']);
        $this->assertSame('Guest Amenities', $waterRow['category']);
        $this->assertSame('น้ำดื่ม 500ml', $waterRow['item_name']);
        $this->assertSame('ขวด', $waterRow['unit']);
        $this->assertSame(100, $waterRow['carried_over']);
        $this->assertSame(80, $waterRow['received']);
        $this->assertSame(30, $waterRow['issued']);
        $this->assertSame(150, $waterRow['remaining']);
        $this->assertSame(50, $waterRow['reorder_point']);
        $this->assertSame(500, $waterRow['max_stock']);
        $this->assertSame('ปกติ', $waterRow['status']); // quantity 150 > 50 -> ok -> ปกติ

        // ตรวจสอบ stock2 (ผ้าเช็ดตัว: quantity 10 <= reorder_point 20 -> low -> สั่งซื้อ)
        $towelRow = collect($rows)->firstWhere('item_code', 'SKU-TOWEL-01');
        $this->assertNotNull($towelRow);
        $this->assertSame('สั่งซื้อ', $towelRow['status']);

        // Summary
        $summary = $reportData->summary($filters);
        $this->assertSame(160, $summary['remaining']);
    }

    public function test_supplies_report_export_stream_contains_actual_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        StockInventory::create([
            'item_code' => 'SKU-REPORT-TEST',
            'item_name' => 'สบู่ก้อนทดสอบออกรายงาน',
            'category' => 'Guest Amenities',
            'quantity' => 75,
            'unit' => 'ก้อน',
            'reorder_point' => 30,
            'max_stock' => 200,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson(
            '/api/v1/reports/supplies/export?date_from=2026-10-01&date_to=2026-10-05'
        );

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('PK', $content);

        $tempFile = tempnam(sys_get_temp_dir(), 'supplies_test_') . '.xlsx';
        file_put_contents($tempFile, $content);

        try {
            $spreadsheet = IOFactory::load($tempFile);
            $sheet = $spreadsheet->getActiveSheet();

            $foundItemName = false;
            $foundItemCode = false;

            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();

            for ($row = 1; $row <= $highestRow; $row++) {
                for ($col = 'A'; $col <= $highestColumn; $col++) {
                    $cellValue = (string) $sheet->getCell("{$col}{$row}")->getValue();

                    if (str_contains($cellValue, 'สบู่ก้อนทดสอบออกรายงาน')) {
                        $foundItemName = true;
                    }
                    if (str_contains($cellValue, 'SKU-REPORT-TEST')) {
                        $foundItemCode = true;
                    }
                }
            }

            $this->assertTrue($foundItemName, 'Expected to find item_name in supplies spreadsheet');
            $this->assertTrue($foundItemCode, 'Expected to find item_code in supplies spreadsheet');
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}
