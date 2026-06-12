<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\FrontDeskController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ImageController;

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

    // 📅 Booking — Public (Guest สร้างบุ๊กกิ้งได้โดยไม่ต้องล็อกอิน) + Rate Limited
    Route::post('/bookings', [BookingController::class, 'createBooking'])->middleware('throttle:5,1');
    Route::post('/bookings/lookup', [BookingController::class, 'lookupBooking'])->middleware('throttle:10,1');

    // 💳 Guest ขอลิงก์ชำระเงินสำหรับบุ๊กกิ้งของตัวเอง + Rate Limited
    Route::post('/bookings/{id}/request-payment', [PaymentController::class, 'requestPaymentForGuest'])->middleware('throttle:5,1');

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

        // 🛏️ Rooms — เปลี่ยนสถานะห้อง (admin only)
        Route::put('/rooms/{id}/status', [RoomController::class, 'updateRoomStatus']);

        // 🛎️ Front Desk Operations (admin only)
        Route::prefix('front-desk')->group(function () {
            Route::post('/walk-in', [FrontDeskController::class, 'walkIn']);
            Route::post('/{bookingId}/check-in', [FrontDeskController::class, 'checkIn']);
            Route::post('/{bookingId}/check-out', [FrontDeskController::class, 'checkOut']);
            Route::post('/{bookingId}/payment', [FrontDeskController::class, 'recordPayment']);
        });

        // 🧹 Dashboard / Housekeeping (admin only)
        Route::prefix('dashboard')->group(function () {
            Route::get('/cleaning-tasks', [DashboardController::class, 'cleaningTasks']);
            Route::put('/cleaning-tasks/{roomId}', [DashboardController::class, 'updateCleaningStatus']);
        });

        // 💳 Payments — สร้างรายการชำระสำหรับผู้ใช้ที่ล็อกอิน
        Route::post('/payments', [PaymentController::class, 'requestPayment']);

    });
});