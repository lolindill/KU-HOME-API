<?php

namespace Tests\Feature;

use App\Models\StockInventory;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupStockMovementsTest extends TestCase
{
    use RefreshDatabase;

    private function createStockItem(): StockInventory
    {
        return StockInventory::create([
            'item_name' => 'สบู่ก้อน',
            'quantity' => 100,
            'unit' => 'ก้อน',
        ]);
    }

    private function createMovement(StockInventory $stock, int $delta, Carbon $createdAt): StockMovement
    {
        $movement = StockMovement::create([
            'stock_inventory_id' => $stock->id,
            'delta' => $delta,
            'quantity_before' => 50,
            'quantity_after' => 50 + $delta,
            'reason' => 'test movement',
        ]);

        $movement->created_at = $createdAt;
        $movement->updated_at = $createdAt;
        $movement->saveQuietly();

        return $movement;
    }

    public function test_cleanup_deletes_only_movements_older_than_365_days(): void
    {
        $stock = $this->createStockItem();

        // 400 วันที่แล้ว (เกิน 365 วัน -> ต้องถูกลบ)
        $oldMovement = $this->createMovement($stock, 10, Carbon::now()->subDays(400));
        // 30 วันที่แล้ว (ไม่เกิน 365 วัน -> ต้องไม่ถูกลบ)
        $recentMovement = $this->createMovement($stock, 5, Carbon::now()->subDays(30));

        $this->artisan('app:cleanup-stock-movements')->assertSuccessful();

        $this->assertDatabaseMissing('stock_movements', ['id' => $oldMovement->id]);
        $this->assertDatabaseHas('stock_movements', ['id' => $recentMovement->id]);
    }

    public function test_cleanup_respects_custom_retention_config(): void
    {
        $stock = $this->createStockItem();

        // 100 วันที่แล้ว — ถ้า retention 365 วันจะไม่ถูกลบ แต่ถ้าตั้งเป็น 90 วันต้องถูกลบ
        $movement = $this->createMovement($stock, 10, Carbon::now()->subDays(100));

        config(['stock.movement_retention_days' => 90]);

        $this->artisan('app:cleanup-stock-movements')->assertSuccessful();

        $this->assertDatabaseMissing('stock_movements', ['id' => $movement->id]);
    }

    public function test_cleanup_dry_run_does_not_delete_any_records(): void
    {
        $stock = $this->createStockItem();

        // 500 วันที่แล้ว
        $oldMovement = $this->createMovement($stock, 10, Carbon::now()->subDays(500));

        $this->artisan('app:cleanup-stock-movements', ['--dry-run' => true])->assertSuccessful();

        // ยังคงอยู่ใน DB
        $this->assertDatabaseHas('stock_movements', ['id' => $oldMovement->id]);
    }
}
