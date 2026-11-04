<?php

/**
 * 🏨 Phase 3 — Room Allocation Algorithm (v3 — Walking Distance Final Judge)
 *
 *    Config สำหรับ RoomAllocator service (app/Services/RoomAllocator/).
 *    ปรับค่าได้ผ่าน .env โดยไม่ต้องแก้โค้ด หรือใช้ config:cache ใน production
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §5 (weights), §9 (caps)
 */
return [

    // =========================================================
    // ⚖️ Cost Weights — ใช้ทั้งใน costFunction (guide) และ walkCost (Final Judge)
    //    ค่า default ตรงกับ playground state.weights
    // =========================================================
    'weights' => [
        'floor' => (int) env('ALLOC_WEIGHT_FLOOR', 50), // ข้ามชั้นแพงมาก
        'side' => (int) env('ALLOC_WEIGHT_SIDE', 8),   // ข้ามฝั่ง V1↔V2
        'pos' => (int) env('ALLOC_WEIGHT_POS', 3),    // posSpread (ยกกำลังสอง)
        'bed' => (int) env('ALLOC_WEIGHT_BED', 5),    // ห้องมีเตียงเกินความต้องการ
        'walk' => (int) env('ALLOC_WEIGHT_WALK', 1),   // 🚶 Final Judge (Σ pairwise walking dist)
    ],

    // =========================================================
    // 🎯 X09 Priority Seed
    //    ถ้าเปิด: ห้อง Deluxe ที่ builtin_extra_beds >= x09_min_builtin_beds (เช่น X09)
    //    จะถูก pin ทันทีให้ slot ที่ต้องการ extra bed ก่อนรัน algorithm
    // =========================================================
    'x09_priority' => (bool) env('ALLOC_X09_PRIORITY', true),
    'x09_min_builtin_beds' => (int) env('ALLOC_X09_MIN_BUILTIN_BEDS', 2),

    // =========================================================
    // 🛡️ Combustion guards — กัน combinatorial explosion
    // =========================================================

    // Algorithm A (Block Contiguous): cap จำนวน window ที่ลอง sliding
    'max_windows' => (int) env('ALLOC_MAX_WINDOWS', 5000),

    // Algorithm D / FF (Greedy multi-seed): cap จำนวน seed ที่ลอง
    'max_seed_trials' => (int) env('ALLOC_MAX_SEED_TRIALS', 15),

    // Algorithm C (Coordinate + Cost): threshold สำหรับตัดสินใจ brute-force vs greedy fallback
    //   key   = จำนวนห้องที่ต้องการ (n)
    //   value = จำนวน candidate สูงสุด (len) ที่ยอม brute-force
    //   n=1,2 brute เสมอ (C(100,2)=4950 เร็วมาก)
    //   n>=3 ขยายตาม n เพื่อกัน C(n,k) ใหญ่เกิน (~cap 100k combos)
    //   n>6   ตก greedy multi-seed fallback
    'brute_combo_caps' => [
        1 => PHP_INT_MAX,
        2 => PHP_INT_MAX,
        3 => 80,
        4 => 40,
        5 => 25,
        6 => 20,
    ],
];
