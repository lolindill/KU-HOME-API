<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockInventoryRequest;
use App\Http\Requests\StoreStockInventoryRequest;
use App\Http\Requests\UpdateStockInventoryRequest;
use App\Models\StockInventory;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 📦 StockController — ระบบจัดการสต็อกสินค้าและวัสดุสิ้นเปลือง (REQ-051/052)
 *
 *    - สิทธิ์:
 *      * admin + staff: อ่านและเขียน (CRUD + adjust)
 *      * housekeeping: อ่านอย่างเดียว (list/show/movements)
 *    - Derived status: ok / low / out (คำนวณผ่าน model accessor)
 *    - SoftDeletes เพื่อรักษา audit trail 1 ปี ของ stock_movements
 */
class StockController extends Controller
{
    /**
     * 🛡️ ตรวจสอบสิทธิ์สำหรับอ่าน: admin, staff, housekeeping
     */
    private function authorizeRead(Request $request): void
    {
        $user = $request->user('sanctum');
        if (! $user || ! in_array($user->role, ['admin', 'staff', 'housekeeping'], true)) {
            abort(403, 'ไม่มีสิทธิ์เข้าถึงข้อมูลสต็อกสินค้าค่ะ 🔒');
        }
    }

    /**
     * 🛡️ ตรวจสอบสิทธิ์สำหรับเขียน: admin, staff เท่านั้น (housekeeping 403)
     */
    private function authorizeWrite(Request $request): void
    {
        $user = $request->user('sanctum');
        if (! $user || ! in_array($user->role, ['admin', 'staff'], true)) {
            abort(403, 'เฉพาะผู้ดูแลระบบหรือเจ้าหน้าที่เท่านั้นที่สามารถจัดการสต็อกสินค้าได้ค่ะ 🔒');
        }
    }

    /**
     * 📋 รายการสต็อกสินค้าทั้งหมด
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeRead($request);

        $query = StockInventory::query();

        // 🔍 ค้นหาตามคำค้น (search)
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('item_name', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        // 🏷️ กรองตามหมวดหมู่ (category)
        if ($request->filled('category') && ! in_array($request->category, ['ทั้งหมด', 'all'], true)) {
            $query->where('category', $request->category);
        }

        // 🚦 กรองตามสถานะ derived (status: ok | low | out)
        if ($request->filled('status')) {
            $status = strtolower(trim($request->status));
            if ($status === 'out') {
                $query->where('quantity', '<=', 0);
            } elseif ($status === 'low') {
                $query->where('quantity', '>', 0)
                    ->whereNotNull('reorder_point')
                    ->whereColumn('quantity', '<=', 'reorder_point');
            } elseif ($status === 'ok') {
                $query->where('quantity', '>', 0)
                    ->where(function ($q) {
                        $q->whereNull('reorder_point')
                            ->orWhereColumn('quantity', '>', 'reorder_point');
                    });
            }
        }

        $items = $query->orderBy('item_name')->get();

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'stock' => $items,
        ]);
    }

    /**
     * 🔍 รายละเอียดสต็อกสินค้าชิ้นเดียว
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorizeRead($request);

        $item = StockInventory::findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $item,
            'stock' => $item,
        ]);
    }

    /**
     * ➕ สร้างรายการสต็อกสินค้าใหม่ (admin + staff)
     */
    public function store(StoreStockInventoryRequest $request): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validated();
        $user = $request->user('sanctum');

        $initialQuantity = (int) ($validated['quantity'] ?? 0);

        $item = DB::transaction(function () use ($validated, $initialQuantity, $user) {
            $stock = StockInventory::create([
                'item_code' => $validated['item_code'] ?? null,
                'item_name' => $validated['item_name'],
                'category' => $validated['category'] ?? null,
                'quantity' => $initialQuantity,
                'unit' => $validated['unit'] ?? 'unit',
                'reorder_point' => (int) ($validated['reorder_point'] ?? 0),
                'max_stock' => isset($validated['max_stock']) ? (int) $validated['max_stock'] : null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // บันทึก initial movement ถ้ามีจำนวนเริ่มต้น > 0
            if ($initialQuantity > 0) {
                $stock->movements()->create([
                    'delta' => $initialQuantity,
                    'quantity_before' => 0,
                    'quantity_after' => $initialQuantity,
                    'reason' => $validated['reason'] ?? 'initial_stock',
                    'user_id' => $user?->id,
                    'notes' => 'สร้างรายการสินค้าเริ่มต้น',
                ]);
            }

            return $stock;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'เพิ่มรายการสินค้าในคลังเรียบร้อยแล้วค่ะนายท่าน ✨',
            'data' => $item->fresh(),
            'stock' => $item->fresh(),
        ], 201);
    }

    /**
     * ✏️ แก้ไขข้อมูลสต็อกสินค้า (admin + staff)
     */
    public function update(UpdateStockInventoryRequest $request, string $id): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validated();
        $user = $request->user('sanctum');

        $item = StockInventory::findOrFail($id);

        $item = DB::transaction(function () use ($item, $validated, $user) {
            $oldQuantity = (int) $item->quantity;
            $newQuantity = isset($validated['quantity']) ? (int) $validated['quantity'] : $oldQuantity;

            // ตรวจจับการเปลี่ยนแปลงของ quantity เพื่อบันทึก movement
            if (isset($validated['quantity']) && $newQuantity !== $oldQuantity) {
                $delta = $newQuantity - $oldQuantity;
                $item->recordMovement(
                    delta: $delta,
                    reason: $validated['reason'] ?? 'manual_update',
                    housekeepingTaskId: $validated['housekeeping_task_id'] ?? null,
                    userId: $user?->id,
                    notes: $validated['notes'] ?? null
                );
            }

            $updateData = [];
            foreach (['item_code', 'item_name', 'category', 'unit', 'reorder_point', 'max_stock', 'notes'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updateData[$field] = $validated[$field];
                }
            }

            if (! empty($updateData)) {
                $item->update($updateData);
            }

            return $item;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'อัปเดตข้อมูลสินค้าในคลังเรียบร้อยแล้วค่ะนายท่าน ✨',
            'data' => $item->fresh(),
            'stock' => $item->fresh(),
        ]);
    }

    /**
     * 🗑️ ลบสต็อกสินค้า (Soft delete เพื่อรักษา movement log 1 ปี)
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeWrite($request);

        $item = StockInventory::findOrFail($id);
        $item->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'ลบรายการสินค้าในคลังเรียบร้อยแล้วค่ะนายท่าน 🗑️',
        ]);
    }

    /**
     * 🔄 ปรับปรุงจำนวนสต็อกสินค้า (admin + staff)
     */
    public function adjust(AdjustStockInventoryRequest $request, string $id): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validated();
        $user = $request->user('sanctum');

        $item = StockInventory::findOrFail($id);

        $delta = null;
        if (isset($validated['delta'])) {
            $delta = (int) $validated['delta'];
            $newQuantity = $item->quantity + $delta;
        } else {
            $newQuantity = (int) $validated['quantity'];
            $delta = $newQuantity - $item->quantity;
        }

        if ($newQuantity < 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'จำนวนสินค้าคงเหลือไม่สามารถติดลบได้ค่ะนายท่าน 🥺',
            ], 422);
        }

        $movement = DB::transaction(function () use ($item, $delta, $validated, $user) {
            return $item->recordMovement(
                delta: $delta,
                reason: $validated['reason'],
                housekeepingTaskId: $validated['housekeeping_task_id'] ?? null,
                userId: $user?->id,
                notes: $validated['notes'] ?? null
            );
        });

        return response()->json([
            'status' => 'success',
            'message' => 'ปรับปรุงจำนวนสต็อกสินค้าเรียบร้อยแล้วค่ะนายท่าน ✨',
            'data' => $item->fresh(),
            'stock' => $item->fresh(),
            'movement' => $movement,
        ]);
    }

    /**
     * 📜 ประวัติการเคลื่อนไหวสต๊อกของรายการนี้
     */
    public function movements(Request $request, string $id): JsonResponse
    {
        $this->authorizeRead($request);

        $item = StockInventory::withTrashed()->findOrFail($id);

        $movements = $item->movements()
            ->with(['user', 'housekeepingTask'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $movements,
            'movements' => $movements,
        ]);
    }
}
