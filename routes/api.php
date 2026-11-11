<?php

use App\Http\Controllers\Api\V1\AdditionalChargeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingConfirmationController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\FrontDeskController;
use App\Http\Controllers\Api\V1\GlobalRateController;
use App\Http\Controllers\Api\V1\ImageController;
use App\Http\Controllers\Api\V1\MockController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\RoomStatePeriodController;
use App\Http\Controllers\Api\V1\SsoController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\RequireJsonAccept;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — KU HOME API
|--------------------------------------------------------------------------
|
| 🎯 Response Format (Standardized):
|   Success: { "status": "success", "message": "...", ...fields }
|   Error:   { "status": "error", "message": "..." }
|
| 🚧 = Draft / Testing route (ยังไม่ใช้งานจริง)
|--------------------------------------------------------------------------
*/

// ============================================
// 🔓 Public Routes
// ============================================

Route::prefix('v1')->group(function () {

    // 🔑 Auth
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // 🎫 KU SSO (wayfinder/ku-sso): SPA รับ code จาก Keycloak แล้วส่งมาแลก Sanctum token
    //    throttle เทียบเท่า login · error map ตาม contract ticket 03
    Route::post('/auth/sso/exchange', [SsoController::class, 'exchange'])->middleware('throttle:5,1');

    // 🎫 Google SSO (wayfinder/google-integration): SPA รับ code จาก Google แล้วส่งมาแลก Sanctum token
    //    mirror KU SSO ทุกอย่างยกเว้น role = 'user' (contract ticket 06 sign-off 2026-10-06)
    Route::post('/auth/sso/google/exchange', [SsoController::class, 'exchangeGoogle'])->middleware('throttle:5,1');

    // 🏨 Rooms (public read-only)
    Route::get('/rooms', [RoomController::class, 'allRooms']);
    Route::get('/rooms/status', [RoomController::class, 'roomStatus']);
    Route::get('/rooms/{id}', [RoomController::class, 'getRoomById']);
    Route::get('/room-types', [RoomController::class, 'allRoomTypes']);
    Route::get('/room-types/{id}', [RoomController::class, 'getRoomTypeById']);
    Route::get('/availability', [RoomController::class, 'availability']);
    Route::get('/availability-per-day', [RoomController::class, 'availabilityPerDay']);
    Route::get('/availability-ranges', [RoomController::class, 'availabilityRanges']);
    Route::get('/unavailable-dates', [RoomController::class, 'unavailableDates']);
    Route::get('/unavailable-ranges', [RoomController::class, 'unavailableRanges']);

    // 🚧 DRAFT / TESTING — mock sold-out ranges สำหรับ frontend test (ไม่อ่าน DB, วันที่สัมพัทธ์ today)
    //    🔒 Gate ด้วย MOCK_ROUTES_ENABLED (config/booking.php — default false, prod-safe
    //    ไม่ผูกกับ APP_ENV) — เปิดเฉพาะเครื่อง dev ผ่าน .env เท่านั้น
    // 🗑️ DELETION PLAN: ลบ route นี้ (พร้อม MockController + tests + docs) เมื่อ frontend ย้ายไปใช้ /availability-ranges จริง — อย่าปล่อยขึ้น prod
    if (config('booking.mock_routes_enabled')) {
        Route::get('/mock/availability-ranges', [MockController::class, 'availabilityRanges']);
    }

    // 💳 Webhook (called by payment gateway — ยืนยันด้วย signature ในอนาคต)
    Route::post('/payment/webhook', [PaymentController::class, 'webhook']);

    // 🌟 Refactor (22/07/26): Global Rates (public read-only — รวม room rate + addon rate ในตารางเดียว)
    Route::get('/global-rates', [GlobalRateController::class, 'index']);
    Route::get('/global-rates/{id}', [GlobalRateController::class, 'show']);

    // 🌟 Refactor (18/06/26): ลบ public booking/lookup/request-payment — non-member ใช้งานไม่ได้แล้ว ทุกคนต้อง login
    //    createBooking ย้ายไป protected routes ด้านล่าง

    // 🖼️ (19/08/26): ให้บริการไฟล์รูปผ่าน signed URL อายุ 15 นาที
    //    🔐 (26/10/26): เพิ่ม auth:sanctum — ผู้ขอต้องเป็นเจ้าของรูป (ตาม imageable:
    //    สลิป/evidence → เจ้าของ booking · รูป housekeeping → assigned_to) หรือ admin
    //    ตรวจใน ImageController::show — signature + token คุมคู่กัน (2 ชั้น)
    //    ⚠️ exempt RequireJsonAccept เฉพาะ route นี้ เพื่อให้ fetch รูปที่ส่ง Accept: image/* โหลดได้
    //       (document ใน cline.md — frontend ใช้ fetch + Authorization header + blob แทน <img src>)
    Route::get('/images/{image}/file', [ImageController::class, 'show'])
        ->where('image', '[0-9a-f\-]{36}')
        ->name('images.file')
        ->middleware(['auth:sanctum', 'signed', 'throttle:10,1'])
        ->withoutMiddleware([RequireJsonAccept::class]);
});

// ============================================
// 🔒 Protected Routes (auth:sanctum)
// ============================================

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    // 🔑 Auth
    Route::post('/logout', [AuthController::class, 'logout']);

    // 👤 User Profile (เจ้าของเท่านั้น)
    Route::get('/me', [UserController::class, 'me']);
    Route::put('/profile', [UserController::class, 'updateProfile']);

    // 📅 Bookings (เจ้าของบุ๊กกิ้งดูของตัวเองได้)
    Route::get('/bookings', [BookingController::class, 'getBookings']);
    Route::get('/bookings/{id}', [BookingController::class, 'showById'])->where('id', '[0-9a-f\-]{36}');

    // 🌟 Refactor (18/06/26): createBooking ย้ายมานี่ — ต้อง login (auth:sanctum) ทุกกรณี
    Route::post('/bookings', [BookingController::class, 'createBooking'])
        ->middleware('throttle:5,1');

    // 🌟 (10/08/26): เพิ่มห้องเข้า booking ที่สร้างไปแล้ว (เฉพาะ draft state)
    //    เจ้าของ booking หรือ admin ใช้ได้ (ownership check ใน controller)
    Route::post('/bookings/{bookingId}/rooms', [BookingController::class, 'addRooms'])
        ->where('bookingId', '[0-9a-f\-]{36}')
        ->middleware('throttle:5,1');

    // 🌟 (17/08/26): ลบ draft booking (เจ้าของหรือ admin) — hard delete cascade แบบเดียวกับ CleanupExpiredDrafts
    Route::delete('/bookings/{bookingId}', [BookingController::class, 'destroyBooking'])
        ->where('bookingId', '[0-9a-f\-]{36}')
        ->middleware('throttle:5,1');

    // 🌟 (17/08/26): แก้ไข / ลบ booking room รายห้อง (เฉพาะ BR=draft และ parent booking=draft)
    //    เจ้าของ booking หรือ admin ใช้ได้ (ownership check ใน controller)
    // 💳 (25/09/26, booking-payment-types ticket 10): parent booking=paid|confirmed →
    //    surcharge หลังจ่าย — admin เท่านั้น (ส่วนต่างราคาเก็บต่อผ่าน recordPayment)
    Route::put('/bookings/{bookingId}/rooms/{bookingRoomId}', [BookingController::class, 'updateRoom'])
        ->where(['bookingId' => '[0-9a-f\-]{36}', 'bookingRoomId' => '[0-9a-f\-]{36}'])
        ->middleware('throttle:5,1');

    Route::delete('/bookings/{bookingId}/rooms/{bookingRoomId}', [BookingController::class, 'destroyRoom'])
        ->where(['bookingId' => '[0-9a-f\-]{36}', 'bookingRoomId' => '[0-9a-f\-]{36}'])
        ->middleware('throttle:5,1');

    // 🌟 (19/08/26): แก้ไข booking room หลายห้องพร้อมกัน (batch update, all-or-nothing)
    //    payload ต่างกันได้รายห้อง — แต่ละ entry ระบุ booking_room_id ของตัวเอง
    //    เจ้าของ booking หรือ admin ใช้ได้ (ownership check ใน controller)
    Route::put('/bookings/{bookingId}/rooms', [BookingController::class, 'updateRooms'])
        ->where('bookingId', '[0-9a-f\-]{36}')
        ->middleware('throttle:5,1');

    // 🌟 Refactor (24/07/26): user ส่ง slip ยืนยันการชำระ → สร้าง booking_confirmation (1:N history)
    // 💳 (06/11/26): บิล deferred รับสลิปที่นี่แล้วทุกสถานะหลัก — admin ส่ง = instant-verify (confirmed + ledger),
    //    เจ้าของส่ง = pending รอ verify · บิล full/deposit คงเดิม (draft/verify_error เท่านั้น)
    Route::post('/bookings/{bookingId}/confirm', [BookingConfirmationController::class, 'confirm'])
        ->where('bookingId', '[0-9a-f\-]{36}')
        ->middleware('throttle:5,1');

    // 🎟️ Discount (27/08/26) — preview โค้ด (ไม่มี side-effect ไม่ถือ slot)
    Route::post('/discounts/validate', [DiscountController::class, 'preview'])
        ->middleware('throttle:10,1');
    Route::get('/discounts/{code}', [DiscountController::class, 'show'])
        ->middleware('throttle:10,1');

    // 🎟️ ใส่/เปลี่ยน/ลบ โค้ดบน draft booking (เจ้าของหรือ admin)
    Route::put('/bookings/{bookingId}/discount-code', [BookingController::class, 'setDiscountCode'])
        ->where('bookingId', '[0-9a-f\-]{36}')->middleware('throttle:5,1');
    Route::delete('/bookings/{bookingId}/discount-code', [BookingController::class, 'destroyDiscountCode'])
        ->where('bookingId', '[0-9a-f\-]{36}')->middleware('throttle:5,1');

    // ============================================
    // 🔐 Admin-only Routes
    // ============================================
    Route::middleware('role:admin')->group(function () {

        // 👥 Users (admin only)
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{id}', [UserController::class, 'show']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);
        Route::put('/users/{id}/verify', [UserController::class, 'verify']);

        // 🏛️ Organizations (wayfinder/organization-bookings ticket 01/05) —
        //    ไม่มี DELETE ตาม precedent discounts — เลิกใช้ = PATCH .../toggle
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::post('/organizations', [OrganizationController::class, 'store']);
        Route::get('/organizations/{id}', [OrganizationController::class, 'show'])
            ->where('id', '[0-9a-f\-]{36}');
        Route::put('/organizations/{id}', [OrganizationController::class, 'update'])
            ->where('id', '[0-9a-f\-]{36}');
        Route::patch('/organizations/{id}/toggle', [OrganizationController::class, 'toggle'])
            ->where('id', '[0-9a-f\-]{36}');

        // 📅 Booking Management (admin only — เปลี่ยนสถานะด้วยมือ)
        //    🧾 debt (2026-11-11, wayfinder/api-route-hygiene ticket 03): verb-in-path + ไม่มี
        //    where-uuid/throttle — ต่าง convention จาก PUT /bookings/{id} ด้านล่าง · รอ unify
        //    เป็น REST shape พร้อม api_patches (มติ scrutinize: เลื่อน เพราะ breaking กับ frontend)
        Route::put('/bookings/update/{id}', [BookingController::class, 'updateStatus']);

        // 💳 (25/09/26, booking-payment-types ticket 01/05): admin แก้ payment fields
        //    (payment_type / deposit_amount / discount_code / payment_deadline) ของ draft booking
        //    (2026-11-11: แก้คอมเมนต์เดิมที่บอกว่าต้อง "วางก่อน /bookings/update/{id} ไม่ให้ชน" —
        //    literal segment "update" กับ {id}+where-uuid ไม่มีทางจับคู่ชนกัน ลำดับไม่มีผล)
        Route::put('/bookings/{id}', [BookingController::class, 'updateBookingPayment'])
            ->where('id', '[0-9a-f\-]{36}')
            ->middleware('throttle:5,1');

        // 📝 Status change logs (audit) — ประวัติการเปลี่ยนสถานะของ booking + booking_rooms
        Route::get('/bookings/{id}/status-logs', [BookingController::class, 'statusLogs'])
            ->where('id', '[0-9a-f\-]{36}');

        // 🌟 Refactor (24/07/26): admin verify/reject booking confirmation (by confirmation_id)
        //    1:N — admin ระบุ row เฉพาะที่จะ review
        Route::get('/booking-confirmations/pending', [BookingConfirmationController::class, 'pending']);
        Route::put('/booking-confirmations/{id}/verify', [BookingConfirmationController::class, 'verify'])
            ->where('id', '[0-9a-f\-]{36}');
        Route::put('/booking-confirmations/{id}/reject', [BookingConfirmationController::class, 'reject'])
            ->where('id', '[0-9a-f\-]{36}');

        // 🛏️ Rooms — เปลี่ยนสถานะห้องบน board (admin only) — 5 สถานะ lifecycle เท่านั้น
        //    (maintenance/reserved_closed ถูกถอดออกจาก machine ตั้งแต่ 24/09/26)
        //    ⚠️ สถานะนี้ไม่มีผลต่อการขายห้อง — availability/allocator มอง room_state_periods
        //    อย่างเดียว ปิดขายช่วงเวลา = /rooms/{roomId}/periods (แยกอยู่กลุ่ม admin+staff)
        Route::put('/rooms/{id}/status', [RoomController::class, 'updateRoomStatus']);

        // 📝 Room status logs (audit, admin only) — ประวัติการเปลี่ยนสถานะห้อง (REQ-039)
        Route::get('/rooms/{id}/status-logs', [RoomController::class, 'statusLogs'])
            ->where('id', '[0-9a-f\-]{36}');

        // 💳 (2026-11-11, api-route-hygiene): ลบ POST /payments — เดิมเป็น 410 tombstone
        //    (requestPayment FROZEN 2026-11-03) ที่คอมเมนต์ "สร้างรายการชำระ" หลอกผู้อ่าน
        //    · เส้น walk-in / mark-no-show (admin only) ย้ายไปรวมกลุ่ม front-desk (admin+staff)
        //      ด้านล่าง โดยใส่ role:admin รายเส้นแทน
        //    flow ชำระเงินจริง = POST /bookings/{id}/confirm + POST /front-desk/{bookingId}/payment
        //    · POST /payment/webhook (public) คง 410 ไว้เป็น contract รอ gateway (blocker #4)

        // 🌟 Refactor (22/07/26): Global Rates Management (admin only — แก้ราคา/เปิด-ปิดการใช้งาน)
        Route::put('/global-rates/{id}', [GlobalRateController::class, 'update']);
        Route::patch('/global-rates/{id}/toggle', [GlobalRateController::class, 'toggleActive']);

        // 🎟️ Discounts management (admin)
        Route::get('/discounts', [DiscountController::class, 'index']);
        Route::post('/discounts', [DiscountController::class, 'store']);
        Route::put('/discounts/{id}', [DiscountController::class, 'update'])->where('id', '[0-9a-f\-]{36}');
        Route::patch('/discounts/{id}/toggle', [DiscountController::class, 'toggleActive'])->where('id', '[0-9a-f\-]{36}');

        // 🧹 Dashboard / Housekeeping — Admin endpoints (Fix S-B4)
        Route::prefix('dashboard')->group(function () {
            Route::get('/tasks', [DashboardController::class, 'listTasks']);
            Route::post('/tasks', [DashboardController::class, 'createTask']);
            Route::put('/tasks/{id}/assign', [DashboardController::class, 'assignTask'])
                ->where('id', '[0-9a-f\-]{36}');
        });
    });

    // ============================================
    // 🛎️ Front Desk & Operations (admin + staff — REQ-031 24hr front desk)
    //    (2026-11-11, api-route-hygiene: ยุบ 3 กลุ่ม front-desk กระจัดกระจายเป็นกลุ่มเดียว
    //    zero-URL-change · เส้น admin-only ใช้ role:admin รายเส้น — CheckRole ตรวจต่อ layer
    //    จึงได้สิทธิ์เดิมเป๊ะ: staff โดน 403 ที่เส้น admin-only เหมือนเดิม)
    // ============================================
    Route::middleware('role:admin,staff')->group(function () {
        // 📅 Auto assign rooms for booking
        Route::put('/bookings/{bookingId}/assign-rooms', [BookingController::class, 'autoAssignRooms']);

        // 🛎️ Front Desk Operations (check-in, check-out, counter payment, walk-in, no-show)
        Route::prefix('front-desk')->group(function () {
            // 🚶 Walk-in booking (admin เท่านั้น — เดิมอยู่กลุ่ม role:admin แยก)
            Route::post('/walk-in', [FrontDeskController::class, 'walkIn'])
                ->middleware('role:admin');

            // 🚫 Mark no-show (admin เท่านั้น — รองรับห้อง draft/confirmed promote)
            Route::post('/{bookingId}/mark-no-show', [FrontDeskController::class, 'markNoShow'])
                ->middleware('role:admin');

            // 🛎️ Per-room check-in/check-out (2026-11-06 — แทนที่ booking-level เดิมทั้งหมด):
            //    เช็คอิน/เอาท์ "รายห้อง" (booking_room) — บิล multi-room แขกมาถึง/จ่ายไม่พร้อมกันได้
            //    · check-in ห้องแรก promote บิล paid → confirmed · บิล complete ปิดรับ check-in
            //    · check-out ทุกครั้งตรวจยอดรวมทั้งบิล (deferred ยกเว้น — มติ "ตั้งแต่ห้องแรก")
            //    · ห้องสุดท้ายที่จบ syncStatusFromRooms ปิดบิล complete เอง
            Route::post('/{bookingId}/rooms/{bookingRoomId}/check-in', [FrontDeskController::class, 'checkInRoom'])
                ->where('bookingId', '[0-9a-f\-]{36}')
                ->where('bookingRoomId', '[0-9a-f\-]{36}');
            Route::post('/{bookingId}/rooms/{bookingRoomId}/check-out', [FrontDeskController::class, 'checkOutRoom'])
                ->where('bookingId', '[0-9a-f\-]{36}')
                ->where('bookingRoomId', '[0-9a-f\-]{36}');
            Route::post('/{bookingId}/payment', [FrontDeskController::class, 'recordPayment']);

            // 🚪 In-stay room move (2026-11-04) — ย้ายห้องจริงหลัง check-in + เปลี่ยนประเภทห้องแบบ upgrade-only
            Route::post('/{bookingId}/rooms/{bookingRoomId}/change-room', [FrontDeskController::class, 'changeRoom'])
                ->where('bookingId', '[0-9a-f\-]{36}')
                ->where('bookingRoomId', '[0-9a-f\-]{36}');

            // 🔀 Mid-stay split-upgrade (2026-11-11) — แบ่งช่วงพักครึ่งหนึ่งย้ายขึ้น tier สูงกว่า
            Route::post('/{bookingId}/rooms/{bookingRoomId}/split-upgrade', [FrontDeskController::class, 'splitUpgrade'])
                ->where('bookingId', '[0-9a-f\-]{36}')
                ->where('bookingRoomId', '[0-9a-f\-]{36}');

            // 🚪 Check-in Evidence (REQ-033) — อัปโหลดบัตร ปชช./registration/PDPA
            //    ผูกกับ BookingRoom (เก็บ 365 วัน)
            //    🧾 debt (2026-11-11, wayfinder/api-route-hygiene ticket 04): address ตรงด้วย
            //    booking-rooms/{id} ต่างจากพี่น้อง nested {bookingId}/rooms/{bookingRoomId}
            //    — รอ unify พร้อม api_patches (มติ scrutinize: เลื่อน เพราะ breaking)
            Route::post('/booking-rooms/{id}/evidence', [FrontDeskController::class, 'uploadEvidence'])
                ->where('id', '[0-9a-f\-]{36}');
            Route::get('/booking-rooms/{id}/evidence', [FrontDeskController::class, 'getEvidences'])
                ->where('id', '[0-9a-f\-]{36}');
        });
    });

    // 🗓️ Room state periods (24/09/26 — wayfinder/room-state-periods): "ห้องสำรอง/ซ่อมแซม"
    //    เป็นช่วงเวลาแบบ booking · route = role:admin,staff (OR) · guard แยก kind ใน controller:
    //    kind=maintenance → admin+staff ครบทุก verb / kind=reserved → admin เท่านั้น (staff 403)
    //    ไม่เพิ่ม throttle — precedent กลุ่ม admin (/discounts)
    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/rooms/{roomId}/periods', [RoomStatePeriodController::class, 'index'])
            ->where('roomId', '[0-9a-f\-]{36}');
        Route::post('/rooms/{roomId}/periods', [RoomStatePeriodController::class, 'store'])
            ->where('roomId', '[0-9a-f\-]{36}');
        Route::patch('/rooms/{roomId}/periods/{periodId}', [RoomStatePeriodController::class, 'update'])
            ->where(['roomId' => '[0-9a-f\-]{36}', 'periodId' => '[0-9a-f\-]{36}']);
        Route::delete('/rooms/{roomId}/periods/{periodId}', [RoomStatePeriodController::class, 'destroy'])
            ->where(['roomId' => '[0-9a-f\-]{36}', 'periodId' => '[0-9a-f\-]{36}']);
    });

    // 💸 Additional charges (05/10/26 — excel-reports spec §2.6 · แก้ 15/10/26 ticket 13):
    //    ค่าปรับ/ค่าเสียหาย/บริการเสริม (addon) — **บวกยอดจริงที่ booking** (REQ-042/044):
    //    charges_total → outstanding/is_paid คิดจากยอดรวม · เก็บเงินผ่าน recordPayment เท่านั้น
    //    · บันทึกอิสระทุกเมื่อ · สิทธิ์ admin+staff (default §9 รอ sign-off)
    //    GET /bookings/{id}/additional-charges — ปลดธรรมเนียม "ไม่มี GET" เพราะยอดเงิน
    //    ของ booking ขึ้นกับ charges แล้ว หน้า front desk ต้องอ่านรายการได้ (เหตุผลใน cline.md)
    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/bookings/{bookingId}/additional-charges', [AdditionalChargeController::class, 'index'])
            ->where('bookingId', '[0-9a-f\-]{36}');
        Route::post('/additional-charges', [AdditionalChargeController::class, 'store']);
        Route::patch('/additional-charges/{id}', [AdditionalChargeController::class, 'update'])
            ->where('id', '[0-9a-f\-]{36}');
        Route::delete('/additional-charges/{id}', [AdditionalChargeController::class, 'destroy'])
            ->where('id', '[0-9a-f\-]{36}');
    });

    // 📊 Reports — Excel export 15 ฉบับ (05/10/26 — excel-reports spec §4):
    //    route ต่อรายงาน (explicit — owner เลือกแยก) · stream ไฟล์ทันที ไม่มีไฟล์ค้าง disk
    //    · สิทธิ์ admin+staff ทุกใบ · housekeeping เฉพาะ 2 ใบแม่บ้าน (+in-controller re-check)
    //    · throttle 10,1 (กลุ่ม lookups) · validate filter ต่อรายงาน (เพดานช่วงวันที่ ≤ 1 ปี)
    Route::middleware('role:admin,staff')->prefix('reports')->group(function () {
        Route::get('/check-in/export', [ReportController::class, 'checkIn'])->middleware('throttle:10,1');
        Route::get('/check-out/export', [ReportController::class, 'checkOut'])->middleware('throttle:10,1');
        Route::get('/daily-financial/export', [ReportController::class, 'dailyFinancial'])->middleware('throttle:10,1');
        Route::get('/deposit/export', [ReportController::class, 'deposit'])->middleware('throttle:10,1');
        Route::get('/occupancy/export', [ReportController::class, 'occupancy'])->middleware('throttle:10,1');
        Route::get('/breakfast/export', [ReportController::class, 'breakfast'])->middleware('throttle:10,1');
        Route::get('/erp-transfer/export', [ReportController::class, 'erpTransfer'])->middleware('throttle:10,1');
        Route::get('/room-status/export', [ReportController::class, 'roomStatus'])->middleware('throttle:10,1');
        Route::get('/extra-bed/export', [ReportController::class, 'extraBed'])->middleware('throttle:10,1');
        Route::get('/supplies/export', [ReportController::class, 'supplies'])->middleware('throttle:10,1');
        Route::get('/out-of-service-room/export', [ReportController::class, 'outOfServiceRoom'])->middleware('throttle:10,1');
        Route::get('/additional-charges/export', [ReportController::class, 'additionalCharges'])->middleware('throttle:10,1');
        Route::get('/manager/export', [ReportController::class, 'manager'])->middleware('throttle:10,1');
    });

    // 📊 Reports — 2 ใบแม่บ้าน (admin+staff+housekeeping)
    Route::middleware('role:admin,staff,housekeeping')->prefix('reports')->group(function () {
        Route::get('/housekeeping/export', [ReportController::class, 'housekeeping'])->middleware('throttle:10,1');
        Route::get('/housekeeping-v2/export', [ReportController::class, 'housekeepingV2'])->middleware('throttle:10,1');
    });

    // 🧹 Dashboard / Housekeeping — Shared endpoints (admin OR housekeeping)
    //    CheckRole middleware: 'role:admin,housekeeping' = OR semantics (เข้าได้ทั้งสอง role)
    //    อยู่ใต้ prefix('v1') + auth:sanctum อยู่แล้ว จึงใช้ prefix('dashboard') ต่อ
    Route::middleware('role:admin,housekeeping')->prefix('dashboard')->group(function () {
        Route::get('/tasks/unassigned', [DashboardController::class, 'unassignedTasks']);
        Route::post('/tasks/{id}/accept', [DashboardController::class, 'acceptTask'])
            ->where('id', '[0-9a-f\-]{36}');
        Route::patch('/tasks/{id}/status', [DashboardController::class, 'updateStatus'])
            ->where('id', '[0-9a-f\-]{36}');
        // 🖼️ (14/10/26, REQ-048): รูปภาพงานแม่บ้าน 4 หมวด (before/after/forgotten_item/damage)
        Route::post('/tasks/{id}/photos', [DashboardController::class, 'uploadPhoto'])
            ->where('id', '[0-9a-f\-]{36}');
        Route::get('/tasks/{id}/photos', [DashboardController::class, 'listPhotos'])
            ->where('id', '[0-9a-f\-]{36}');
    });

    // 📦 Stock Inventories (REQ-051/052)
    //    - อ่าน: admin, staff, housekeeping
    //    - เขียน: admin, staff เท่านั้น (housekeeping 403)
    Route::middleware('role:admin,staff,housekeeping')->group(function () {
        Route::get('/stock', [StockController::class, 'index']);
        Route::get('/stock/{id}', [StockController::class, 'show'])->where('id', '[0-9a-f\-]{36}');
        Route::get('/stock/{id}/movements', [StockController::class, 'movements'])->where('id', '[0-9a-f\-]{36}');
    });

    Route::middleware('role:admin,staff')->group(function () {
        Route::post('/stock', [StockController::class, 'store']);
        Route::put('/stock/{id}', [StockController::class, 'update'])->where('id', '[0-9a-f\-]{36}');
        Route::delete('/stock/{id}', [StockController::class, 'destroy'])->where('id', '[0-9a-f\-]{36}');
        Route::post('/stock/{id}/adjust', [StockController::class, 'adjust'])->where('id', '[0-9a-f\-]{36}');
    });
});
