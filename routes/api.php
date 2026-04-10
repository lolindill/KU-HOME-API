<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// 🌟 นำเข้า Controllers ทั้งหมดให้เป็นระเบียบ
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\FrontDeskController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\RoomController; // 🌟 เพิ่ม RoomController เข้ามาใหม่ค่ะนายท่าน!

// 🌟 API Root / Health Check 
Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to KU HOME API 🏨✨',
        'version' => '1.0.0',
        'status' => 'Active',
        'timestamp' => now()->toIso8601String()
    ]);
}); // http://hotel.test/api

// 🌟 จัด Group หลัก V1
Route::prefix('v1')->group(function () {

    // ==========================================
    // 🔓 โซนสาธารณะ (Public Routes) ไม่ต้อง Login
    // ==========================================

    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('/login', 'login')->name('login'); // http://hotel.test/api/v1/auth/login
        Route::post('/register', 'register');          // http://hotel.test/api/v1/auth/register
    });

    Route::controller(BookingController::class)->group(function () {
        Route::post('/bookings/discounts/validate', 'validateDiscount'); // http://hotel.test/api/v1/bookings/discounts/validate
        Route::get('/addons', 'addons');               // http://hotel.test/api/v1/addons
        Route::post('/bookings', 'createBooking');     // http://hotel.test/api/v1/bookings
        Route::get('/get/bookings', 'GetAllBookings');                       // http://hotel.test/api/v1/get/bookings
    });

    Route::prefix('payments')->group(function () {
        Route::post('/webhook', [PaymentController::class, 'webhook']); // http://hotel.test/api/v1/payments/webhook
    });

    // 👇 หนูย้ายหน้าที่ดูแลเรื่องห้องพักมาให้ RoomController แล้วนะคะ! 💖
    Route::controller(RoomController::class)->group(function (){
        Route::get('/rooms/availability', 'availability'); // http://hotel.test/api/v1/rooms/availability
        Route::get('/room-types', 'allRoomTypes');          // http://hotel.test/api/v1/room-types
        Route::get('/rooms/{id}','getRoomById');            // http://hotel.test/api/v1/rooms/{id}
        Route::get('/room-types/{id}','getRoomTypeById');   // http://hotel.test/api/v1/room-types/{id}
    });   
    
    // ==========================================
    // 🛡️ โซนหวงห้าม (Protected Routes) ต้องมี Token Sanctum
    // ==========================================
    
    Route::middleware('auth:sanctum')->group(function () {

        // 🔐 ข้อมูลส่วนตัวและการออกจากระบบ
        Route::prefix('auth')->controller(AuthController::class)->group(function () {
            Route::post('/logout', 'logout');          // http://hotel.test/api/v1/auth/logout
            Route::get('/user', function (Request $request) {
                return $request->user();
            });                                        // http://hotel.test/api/v1/auth/user
        });

        // 🏨 ระบบจองห้องพัก (เฉพาะลูกค้าที่ Login แล้ว)
        Route::controller(BookingController::class)->group(function () {
            Route::get('user/get/bookings', 'userGetAllBookings'); // http://hotel.test/api/v1/user/get/bookings
            
        });

        // 💳 ระบบการชำระเงิน
        Route::prefix('payments')->group(function () {
            Route::post('/request', [PaymentController::class, 'requestPayment']); // http://hotel.test/api/v1/payments/request
        });

        // 🛎️ ระบบหน้าฟรอนต์ (เฉพาะพนักงาน)
        Route::middleware('role:admin')->prefix('frontdesk')->controller(FrontDeskController::class)->group(function () {
            Route::post('/walk-in', 'walkIn');         // http://hotel.test/api/v1/frontdesk/walk-in

            Route::prefix('bookings/{booking_id}')->group(function () {
                Route::post('/check-in', 'checkIn');   // http://hotel.test/api/v1/frontdesk/bookings/{booking_id}/check-in
                Route::post('/pay', 'recordPayment');  // http://hotel.test/api/v1/frontdesk/bookings/{booking_id}/pay
                Route::post('/check-out', 'checkOut'); // http://hotel.test/api/v1/frontdesk/bookings/{booking_id}/check-out
            });
        });

        // 📊 ระบบแดชบอร์ด แอดมิน และแม่บ้าน
        Route::middleware('role:admin')->group(function () {
            
            // 🛏️ ดึงสถานะห้องให้พนักงานดู (ย้ายมา RoomController)
            Route::controller(RoomController::class)->group(function () {
                Route::get('/rooms/get', 'allRooms');                           // http://hotel.test/api/v1/rooms/get
                Route::get('/rooms/status', 'roomStatus');                  // http://hotel.test/api/v1/rooms/status
            });

            // 📅 ปฏิทินการจอง (ย้ายมา BookingController)
            Route::controller(BookingController::class)->group(function () {                
                //Route::get('/get/bookings', 'GetAllBookings');                       // http://hotel.test/api/v1/get/bookings
                Route::get('/search/bookings', 'bookingSearch');                    // http://hotel.test/api/v1//search/bookings/
            });
            
            // 🧹 งานแม่บ้าน (เหลือไว้ใน DashboardController ก่อนค่ะ หรือจะแยกเป็น HousekeepingController ทีหลังก็ได้น้า)
            Route::controller(DashboardController::class)->prefix('housekeeping')->group(function () {
                Route::get('/', 'cleaningTasks');                           // http://hotel.test/api/v1/housekeeping
                // 👇 เปลี่ยน {task_id} เป็น {room_id} ตามโค้ด Controller ของนายท่านค่ะ
                Route::post('/{room_id}/status', 'updateCleaningStatus');   // http://hotel.test/api/v1/housekeeping/{room_id}/status
            });
        });

    }); // 🛑 จบเขตหวงห้าม Sanctum

});