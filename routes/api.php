<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingConfirmationController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FrontDeskController;
use App\Http\Controllers\Api\V1\GlobalRateController;
use App\Http\Controllers\Api\V1\ImageController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\UserController;
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

    // 🏨 Rooms (public read-only)
    Route::get('/rooms', [RoomController::class, 'allRooms']);
    Route::get('/rooms/status', [RoomController::class, 'roomStatus']);
    Route::get('/rooms/{id}', [RoomController::class, 'getRoomById']);
    Route::get('/room-types', [RoomController::class, 'allRoomTypes']);
    Route::get('/room-types/{id}', [RoomController::class, 'getRoomTypeById']);
    Route::get('/availability', [RoomController::class, 'availability']);

    // 💳 Webhook (called by payment gateway — ยืนยันด้วย signature ในอนาคต)
    Route::post('/payment/webhook', [PaymentController::class, 'webhook']);

    // 🌟 Refactor (22/07/26): Global Rates (public read-only — รวม room rate + addon rate ในตารางเดียว)
    Route::get('/global-rates', [GlobalRateController::class, 'index']);
    Route::get('/global-rates/{id}', [GlobalRateController::class, 'show']);

    // 🌟 Refactor (18/06/26): ลบ public booking/lookup/request-payment — non-member ใช้งานไม่ได้แล้ว ทุกคนต้อง login
    //    createBooking ย้ายไป protected routes ด้านล่าง

    // 🚧 DRAFT / TESTING — ยังไม่ใช้งานจริง
    Route::post('/upload-image', [ImageController::class, 'upload']);
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
    Route::post('/bookings', [BookingController::class, 'createBooking'])->middleware('throttle:5,1');

    // 🌟 Refactor (24/07/26): user ส่ง slip ยืนยันการชำระ → สร้าง booking_confirmation (1:N history)
    Route::post('/bookings/{bookingId}/confirm', [BookingConfirmationController::class, 'confirm'])
        ->where('bookingId', '[0-9a-f\-]{36}')
        ->middleware('throttle:5,1');

    // 🚧 DRAFT / TESTING — ยังไม่ใช้งานจริง ระบบส่วนลดยังไม่สมบูรณ์
    Route::post('/bookings/validate-discount', [BookingController::class, 'validateDiscount']);

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

        // 📅 Booking Management (admin only — เปลี่ยนสถานะด้วยมือ, assign ห้อง)
        Route::put('/bookings/update/{id}', [BookingController::class, 'updateStatus']);
        Route::put('/bookings/{bookingId}/assign-rooms', [BookingController::class, 'autoAssignRooms']);

        // 🌟 Refactor (24/07/26): admin verify/reject booking confirmation (by confirmation_id)
        //    1:N — admin ระบุ row เฉพาะที่จะ review
        Route::get('/booking-confirmations/pending', [BookingConfirmationController::class, 'pending']);
        Route::put('/booking-confirmations/{id}/verify', [BookingConfirmationController::class, 'verify'])
            ->where('id', '[0-9a-f\-]{36}');
        Route::put('/booking-confirmations/{id}/reject', [BookingConfirmationController::class, 'reject'])
            ->where('id', '[0-9a-f\-]{36}');

        // 🛏️ Rooms — เปลี่ยนสถานะห้อง (admin only)
        Route::put('/rooms/{id}/status', [RoomController::class, 'updateRoomStatus']);

        // 🛎️ Front Desk Operations (admin only)
        Route::prefix('front-desk')->group(function () {
            Route::post('/walk-in', [FrontDeskController::class, 'walkIn']);
            Route::post('/{bookingId}/check-in', [FrontDeskController::class, 'checkIn']);
            Route::post('/{bookingId}/check-out', [FrontDeskController::class, 'checkOut']);
            Route::post('/{bookingId}/mark-no-show', [FrontDeskController::class, 'markNoShow']);
            Route::post('/{bookingId}/payment', [FrontDeskController::class, 'recordPayment']);
        });

        // 💳 Payments — สร้างรายการชำระสำหรับผู้ใช้ที่ล็อกอิน
        Route::post('/payments', [PaymentController::class, 'requestPayment']);

        // 🌟 Refactor (22/07/26): Global Rates Management (admin only — แก้ราคา/เปิด-ปิดการใช้งาน)
        Route::put('/global-rates/{id}', [GlobalRateController::class, 'update']);
        Route::patch('/global-rates/{id}/toggle', [GlobalRateController::class, 'toggleActive']);

        // 🧹 Dashboard / Housekeeping — Admin endpoints (Fix S-B4)
        Route::prefix('dashboard')->group(function () {
            Route::get('/tasks', [DashboardController::class, 'listTasks']);
            Route::post('/tasks', [DashboardController::class, 'createTask']);
            Route::put('/tasks/{id}/assign', [DashboardController::class, 'assignTask'])
                ->where('id', '[0-9a-f\-]{36}');
        });
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
    });
});
