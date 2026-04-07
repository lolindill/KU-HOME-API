<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// 🌟 นำเข้า Controllers ทั้งหมดให้เป็นระเบียบ
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\FrontDeskController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PaymentController;

// 🌟 API Root / Health Check 
// http://hotel.test/api
Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to KU HOME API 🏨✨',
        'version' => '1.0.0',
        'status' => 'Active',
        'timestamp' => now()->toIso8601String()
    ]);
});

// 🌟 จัด Group หลัก V1
Route::prefix('v1')->group(function () {

    // ==========================================
    // 🔓 โซนสาธารณะ (Public Routes) ไม่ต้อง Login
    // ==========================================

    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('/login', 'login');                                  // http://hotel.test/api/v1/auth/login
        Route::post('/register', 'register');                            // http://hotel.test/api/v1/auth/register
    });

    Route::controller(BookingController::class)->group(function () {
        Route::get('/rooms/availability', 'availability');               // http://hotel.test/api/v1/rooms/availability
        Route::post('/bookings/discounts/validate', 'validateDiscount'); // http://hotel.test/api/v1/bookings/discounts/validate
        Route::get('/addons', 'addons');                                 // http://hotel.test/api/v1/addons
    });

    Route::prefix('payments')->group(function () {
        // ⚠️ Webhook ธนาคารต้องเข้าถึงได้แบบไม่ต้องมี Token ค่ะ
        Route::post('/webhook', [PaymentController::class, 'webhook']);  // http://hotel.test/api/v1/payments/webhook
    });


    // ==========================================
    // 🛡️ โซนหวงห้าม (Protected Routes) ต้องมี Token Sanctum
    // ==========================================
    
    Route::middleware('auth:sanctum')->group(function () {

        // 🔐 ข้อมูลส่วนตัวและการออกจากระบบ
        Route::prefix('auth')->controller(AuthController::class)->group(function () {
            Route::post('/logout', 'logout');                            // http://hotel.test/api/v1/auth/logout
            Route::get('/user', function (Request $request) {            // http://hotel.test/api/v1/auth/user
                return $request->user();
            });
            // ถ้านายท่านมี Route สำหรับดึง/แก้ Profile (GET/PUT /profile) เอามาใส่ตรงนี้ได้เลยนะคะ!
            // Route::get('/profile', 'profile');                        // http://hotel.test/api/v1/auth/profile
            // Route::put('/profile', 'updateProfile');                  // http://hotel.test/api/v1/auth/profile
        });

        // 🏨 ระบบจองห้องพัก (เฉพาะลูกค้าที่ Login แล้ว)
        Route::controller(BookingController::class)->group(function () {
            Route::post('/bookings', 'createBooking');                          // http://hotel.test/api/v1/bookings
            Route::get('/get_bookings', 'GetAllBookings');                      // http://hotel.test/api/v1/get_bookings
        });

        // 💳 ระบบการชำระเงิน (ต้องรู้ว่าเป็นใครจ่าย)
        Route::prefix('payments')->group(function () {
            Route::post('/request', [PaymentController::class, 'requestPayment']); // http://hotel.test/api/v1/payments/request
        });

        // 🛎️ ระบบหน้าฟรอนต์ (เฉพาะพนักงาน)
        Route::middleware('role:admin')-> prefix('frontdesk')->controller(FrontDeskController::class)->group(function () {
            Route::post('/walk-in', 'walkIn');                           // http://hotel.test/api/v1/frontdesk/walk-in

            Route::prefix('bookings/{booking_id}')->group(function () {
                Route::post('/check-in', 'checkIn');                     // http://hotel.test/api/v1/frontdesk/bookings/{booking_id}/check-in
                Route::post('/pay', 'recordPayment');                    // http://hotel.test/api/v1/frontdesk/bookings/{booking_id}/pay
                Route::post('/check-out', 'checkOut');                   // http://hotel.test/api/v1/frontdesk/bookings/{booking_id}/check-out
            });
        });

        // 📊 ระบบแดชบอร์ดและแม่บ้าน (เฉพาะแอดมิน / แม่บ้าน)
        Route::middleware('role:admin')-> prefix('dashboard')->controller(DashboardController::class)->group(function () {
            Route::get('/rooms/status', 'roomStatus');                   // http://hotel.test/api/v1/dashboard/rooms/status
            Route::get('/calendar', 'bookingCalendar');                  // http://hotel.test/api/v1/dashboard/calendar
            
            Route::prefix('housekeeping')->group(function () {
                Route::get('/', 'cleaningTasks');                        // http://hotel.test/api/v1/dashboard/housekeeping
                Route::post('/{task_id}/status', 'updateCleaningStatus'); // http://hotel.test/api/v1/dashboard/housekeeping/{task_id}/status
            });
        });

    }); // 🛑 จบเขตหวงห้าม Sanctum

});