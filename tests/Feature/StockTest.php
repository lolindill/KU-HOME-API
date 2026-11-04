<?php

namespace Tests\Feature;

use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): static
    {
        $staff = User::factory()->create(['role' => 'staff']);

        return $this->actingAs($staff, 'sanctum');
    }

    // ============================================
    // 1. Role Authorization Matrix
    // ============================================

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/stock');
        $response->assertStatus(401);
    }

    public function test_regular_user_cannot_access_stock_endpoints(): void
    {
        $this->actingAsUser();

        $stock = StockInventory::create([
            'item_name' => 'สบู่เหลว',
            'quantity' => 10,
            'unit' => 'ขวด',
        ]);

        $this->getJson('/api/v1/stock')->assertStatus(403);
        $this->getJson("/api/v1/stock/{$stock->id}")->assertStatus(403);
        $this->postJson('/api/v1/stock', ['item_name' => 'แชมพู'])->assertStatus(403);
        $this->putJson("/api/v1/stock/{$stock->id}", ['item_name' => 'สบู่เหลวพิเศษ'])->assertStatus(403);
        $this->deleteJson("/api/v1/stock/{$stock->id}")->assertStatus(403);
        $this->postJson("/api/v1/stock/{$stock->id}/adjust", ['delta' => 5, 'reason' => 'เติมของ'])->assertStatus(403);
    }

    public function test_housekeeping_can_read_stock_but_cannot_write(): void
    {
        $this->actingAsHousekeeping();

        $stock = StockInventory::create([
            'item_name' => 'ผ้าเช็ดตัว',
            'quantity' => 50,
            'unit' => 'ผืน',
            'reorder_point' => 20,
        ]);

        // Housekeeping อ่านได้ (200)
        $this->getJson('/api/v1/stock')->assertStatus(200);
        $this->getJson("/api/v1/stock/{$stock->id}")->assertStatus(200);
        $this->getJson("/api/v1/stock/{$stock->id}/movements")->assertStatus(200);

        // Housekeeping เขียนไม่ได้ (403)
        $this->postJson('/api/v1/stock', [
            'item_name' => 'หมวกคลุมผม',
            'quantity' => 100,
        ])->assertStatus(403);

        $this->putJson("/api/v1/stock/{$stock->id}", [
            'item_name' => 'ผ้าเช็ดตัวผืนใหญ่',
        ])->assertStatus(403);

        $this->deleteJson("/api/v1/stock/{$stock->id}")->assertStatus(403);

        $this->postJson("/api/v1/stock/{$stock->id}/adjust", [
            'delta' => 10,
            'reason' => 'แม่บ้านพยายามปรับยอด',
        ])->assertStatus(403);
    }

    public function test_admin_and_staff_can_perform_full_crud_and_adjust(): void
    {
        foreach (['admin', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user, 'sanctum');

            // CREATE
            $createRes = $this->postJson('/api/v1/stock', [
                'item_code' => "SKU-{$role}",
                'item_name' => "สินค้าทดสอบ {$role}",
                'category' => 'Guest Amenities',
                'quantity' => 25,
                'unit' => 'ชิ้น',
                'reorder_point' => 10,
                'max_stock' => 100,
                'notes' => 'โน้ตการทดสอบ',
            ]);
            $createRes->assertStatus(201);
            $stockId = $createRes->json('data.id');

            // READ (index & show)
            $this->getJson('/api/v1/stock')->assertStatus(200);
            $this->getJson("/api/v1/stock/{$stockId}")->assertStatus(200);

            // UPDATE
            $updateRes = $this->putJson("/api/v1/stock/{$stockId}", [
                'item_name' => "สินค้าทดสอบ {$role} (อัปเดตแล้ว)",
                'reorder_point' => 15,
            ]);
            $updateRes->assertStatus(200);
            $this->assertDatabaseHas('stock_inventories', [
                'id' => $stockId,
                'item_name' => "สินค้าทดสอบ {$role} (อัปเดตแล้ว)",
                'reorder_point' => 15,
            ]);

            // ADJUST
            $adjustRes = $this->postJson("/api/v1/stock/{$stockId}/adjust", [
                'delta' => -5,
                'reason' => 'เบิกใช้งาน',
            ]);
            $adjustRes->assertStatus(200);
            $this->assertEquals(20, $adjustRes->json('data.quantity'));

            // DELETE (soft delete)
            $deleteRes = $this->deleteJson("/api/v1/stock/{$stockId}");
            $deleteRes->assertStatus(200);
            $this->assertSoftDeleted('stock_inventories', ['id' => $stockId]);
        }
    }

    // ============================================
    // 2. Derived Status Boundary Testing
    // ============================================

    public function test_derived_status_boundary_conditions(): void
    {
        // 1. quantity <= 0 -> out
        $outItem1 = StockInventory::create([
            'item_name' => 'ของหมด 0',
            'quantity' => 0,
            'reorder_point' => 10,
        ]);
        $this->assertSame('out', $outItem1->status);

        $outItem2 = StockInventory::create([
            'item_name' => 'ของหมดติดลบ',
            'quantity' => -2,
            'reorder_point' => 10,
        ]);
        $this->assertSame('out', $outItem2->status);

        // 2. quantity == reorder_point -> low (ตรงขอบพอดี)
        $lowItemExact = StockInventory::create([
            'item_name' => 'ตรงขอบพอดี',
            'quantity' => 10,
            'reorder_point' => 10,
        ]);
        $this->assertSame('low', $lowItemExact->status);

        // 3. quantity < reorder_point (แต่ > 0) -> low
        $lowItemBelow = StockInventory::create([
            'item_name' => 'ต่ำกว่าจุดสั่งซื้อ',
            'quantity' => 5,
            'reorder_point' => 10,
        ]);
        $this->assertSame('low', $lowItemBelow->status);

        // 4. quantity > reorder_point -> ok
        $okItem = StockInventory::create([
            'item_name' => 'สต็อกปกติ',
            'quantity' => 11,
            'reorder_point' => 10,
        ]);
        $this->assertSame('ok', $okItem->status);

        // 5. reorder_point = 0 หรือ null, quantity > 0 -> ok
        $okItemNoReorder = StockInventory::create([
            'item_name' => 'ไม่มีจุดสั่งซื้อ',
            'quantity' => 5,
            'reorder_point' => 0,
        ]);
        $this->assertSame('ok', $okItemNoReorder->status);
    }

    public function test_filtering_by_derived_status_and_category(): void
    {
        $this->actingAsAdmin();

        StockInventory::create([
            'item_name' => 'สบู่หมด',
            'category' => 'Guest Amenities',
            'quantity' => 0,
            'reorder_point' => 5,
        ]);
        StockInventory::create([
            'item_name' => 'สบู่เหลือน้อย',
            'category' => 'Guest Amenities',
            'quantity' => 3,
            'reorder_point' => 5,
        ]);
        StockInventory::create([
            'item_name' => 'สบู่พอดี',
            'category' => 'Guest Amenities',
            'quantity' => 20,
            'reorder_point' => 5,
        ]);
        StockInventory::create([
            'item_name' => 'ผ้าปูที่นอน',
            'category' => 'Linen and Bedding',
            'quantity' => 50,
            'reorder_point' => 10,
        ]);

        // Filter status=out
        $resOut = $this->getJson('/api/v1/stock?status=out');
        $resOut->assertStatus(200);
        $this->assertCount(1, $resOut->json('data'));
        $this->assertSame('สบู่หมด', $resOut->json('data.0.item_name'));

        // Filter status=low
        $resLow = $this->getJson('/api/v1/stock?status=low');
        $resLow->assertStatus(200);
        $this->assertCount(1, $resLow->json('data'));
        $this->assertSame('สบู่เหลือน้อย', $resLow->json('data.0.item_name'));

        // Filter status=ok
        $resOk = $this->getJson('/api/v1/stock?status=ok');
        $resOk->assertStatus(200);
        $this->assertCount(2, $resOk->json('data'));

        // Filter category
        $resCat = $this->getJson('/api/v1/stock?category=Linen and Bedding');
        $resCat->assertStatus(200);
        $this->assertCount(1, $resCat->json('data'));
        $this->assertSame('ผ้าปูที่นอน', $resCat->json('data.0.item_name'));
    }

    // ============================================
    // 3. Movement Logs Tracking
    // ============================================

    public function test_initial_stock_creates_movement_log(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/stock', [
            'item_name' => 'น้ำดื่ม',
            'quantity' => 100,
            'unit' => 'ขวด',
            'reason' => 'ล็อตใหม่เปิดโรงแจม',
        ]);
        $response->assertStatus(201);
        $stockId = $response->json('data.id');

        $this->assertDatabaseHas('stock_movements', [
            'stock_inventory_id' => $stockId,
            'delta' => 100,
            'quantity_before' => 0,
            'quantity_after' => 100,
            'reason' => 'ล็อตใหม่เปิดโรงแจม',
        ]);
    }

    public function test_initial_stock_zero_does_not_create_movement_log(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/stock', [
            'item_name' => 'ของใหม่ยังไม่มีของ',
            'quantity' => 0,
            'unit' => 'กล่อง',
        ]);
        $response->assertStatus(201);
        $stockId = $response->json('data.id');

        $this->assertDatabaseMissing('stock_movements', [
            'stock_inventory_id' => $stockId,
        ]);
    }

    public function test_update_quantity_creates_movement_log(): void
    {
        $this->actingAsAdmin();

        $stock = StockInventory::create([
            'item_name' => 'กาแฟซอง',
            'quantity' => 50,
            'unit' => 'ซอง',
        ]);

        // อัปเดตข้อมูลทั่วไปโดยไม่เปลี่ยน quantity -> ไม่สร้าง movement
        $this->putJson("/api/v1/stock/{$stock->id}", [
            'notes' => 'กาแฟคั่วเข้ม',
        ])->assertStatus(200);
        $this->assertEquals(0, StockMovement::where('stock_inventory_id', $stock->id)->count());

        // อัปเดตเปลี่ยน quantity -> สร้าง movement อัตโนมัติ
        $this->putJson("/api/v1/stock/{$stock->id}", [
            'quantity' => 80,
            'reason' => 'นับสต็อกประจำสัปดาห์',
        ])->assertStatus(200);

        $this->assertDatabaseHas('stock_movements', [
            'stock_inventory_id' => $stock->id,
            'delta' => 30,
            'quantity_before' => 50,
            'quantity_after' => 80,
            'reason' => 'นับสต็อกประจำสัปดาห์',
        ]);
    }

    public function test_adjust_stock_with_delta_and_housekeeping_task(): void
    {
        $this->actingAsStaff();

        $stock = StockInventory::create([
            'item_name' => 'กระดาษชำระ',
            'quantity' => 40,
            'unit' => 'ม้วน',
        ]);

        $roomType = RoomType::create([
            'name_th' => 'ห้องสแตนดาร์ด',
            'name_en' => 'Standard Room',
            'max_guests' => 2,
        ]);
        $room = Room::create([
            'room_number' => '101',
            'room_type_id' => $roomType->id,
            'status' => 'dirty',
        ]);
        $task = HousekeepingTask::create([
            'room_id' => $room->id,
            'task_type' => 'checkout',
            'status' => 'in_progress',
        ]);

        // ปรับลดสต็อกพร้อมผูก housekeeping_task_id
        $response = $this->postJson("/api/v1/stock/{$stock->id}/adjust", [
            'delta' => -4,
            'reason' => 'เบิกใส่ห้องพัก',
            'housekeeping_task_id' => $task->id,
            'notes' => 'ห้อง '.$room->room_number,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(36, $response->json('data.quantity'));

        $this->assertDatabaseHas('stock_movements', [
            'stock_inventory_id' => $stock->id,
            'delta' => -4,
            'quantity_before' => 40,
            'quantity_after' => 36,
            'reason' => 'เบิกใส่ห้องพัก',
            'housekeeping_task_id' => $task->id,
        ]);
    }

    public function test_adjust_stock_cannot_result_in_negative_quantity(): void
    {
        $this->actingAsAdmin();

        $stock = StockInventory::create([
            'item_name' => 'หมอนหนุน',
            'quantity' => 5,
            'unit' => 'ใบ',
        ]);

        // พยายามปรับลดเกินกว่าที่มีอยู่ (-10)
        $response = $this->postJson("/api/v1/stock/{$stock->id}/adjust", [
            'delta' => -10,
            'reason' => 'ทำชำรุด',
        ]);

        $response->assertStatus(422);
        $this->assertSame('error', $response->json('status'));

        // จำนวนคงเหลือต้องไม่เปลี่ยนแปลง และไม่มี movement log บันทึก
        $this->assertEquals(5, $stock->fresh()->quantity);
        $this->assertEquals(0, StockMovement::where('stock_inventory_id', $stock->id)->count());
    }

    public function test_movements_history_preserved_after_soft_delete(): void
    {
        $this->actingAsAdmin();

        $stock = StockInventory::create([
            'item_name' => 'ของทดสอบ soft delete',
            'quantity' => 10,
            'unit' => 'ชิ้น',
        ]);

        // ปรับยอดเพื่อสร้าง movement
        $this->postJson("/api/v1/stock/{$stock->id}/adjust", [
            'delta' => 5,
            'reason' => 'เติมเพิ่ม',
        ])->assertStatus(200);

        $this->assertEquals(1, StockMovement::where('stock_inventory_id', $stock->id)->count());

        // Soft delete item
        $this->deleteJson("/api/v1/stock/{$stock->id}")->assertStatus(200);
        $this->assertSoftDeleted('stock_inventories', ['id' => $stock->id]);

        // Movement ยังคงอยู่ใน DB
        $this->assertEquals(1, StockMovement::where('stock_inventory_id', $stock->id)->count());

        // สามารถเรียกดู movements ของ item ที่ soft delete ได้
        $movementsRes = $this->getJson("/api/v1/stock/{$stock->id}/movements");
        $movementsRes->assertStatus(200);
        $this->assertCount(1, $movementsRes->json('data'));
    }
}
