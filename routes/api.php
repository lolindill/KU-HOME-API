<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// 🌟 นำเข้า Controllers ทั้งหมดให้เป็นระเบียบ
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\FrontDeskController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\RoomController; 
use App\Http\Controllers\Api\V1\UserController;

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

    Route::prefix('bookings')->controller(BookingController::class)->group(function () {
        Route::post('/discounts/validate', 'validateDiscount'); // http://hotel.test/api/v1/bookings/discounts/validate
        Route::get('/addons', 'addons');               // http://hotel.test/api/v1/addons
        Route::post('', 'createBooking');               // http://hotel.test/api/v1/bookings
        Route::put('/update', 'updateStatus');           // http://hotel.test/api/v1/bookings/update
    });
        

    Route::prefix('payments')->group(function () {
        Route::post('/webhook', [PaymentController::class, 'webhook']); // http://hotel.test/api/v1/payments/webhook
    });

    
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
                                                       // http://hotel.test/api/v1/auth/user
        });

        // 🏨 ระบบจองห้องพัก (เฉพาะลูกค้าที่ Login แล้ว)
        Route::controller(BookingController::class)->group(function () {
            Route::get('/get/bookings', 'getBookings');     // http://hotel.test/api/v1/get/bookings
            Route::get('/bookings/{id}', 'showById')        // http://hotel.test/api/v1/bookings/{id}
            
        });

        // 💳 ระบบการชำระเงิน
        Route::prefix('payments')->group(function () {
            Route::post('/request', [PaymentController::class, 'requestPayment']); // http://hotel.test/api/v1/payments/request
        });

        Route::controller(UserController::class)->prefix('users')->group(function () {
                Route::get('/self', 'me');                              // http://hotel.test/api/v1/users/self
                Route::put('/update_profile', 'updateProfile');         // http://hotel.test/api/v1/users/update_profile
            });
        
        Route::middleware('role:admin')->prefix('frontdesk')->controller(FrontDeskController::class)->group(function () {
            Route::post('/walk-in', 'walkIn');                              // http://hotel.test/api/v1/frontdesk/walk-in

            Route::prefix('bookings/{booking_id}')->group(function () {
                Route::post('/check-in', 'checkIn');                        // http://hotel.test/api/v1/frontdesk/bookings/{booking_id}/check-in
                Route::post('/pay', 'recordPayment');                       // http://hotel.test/api/v1/frontdesk/bookings/{booking_id}/pay
                Route::post('/check-out', 'checkOut');                      // http://hotel.test/api/v1/frontdesk/bookings/{booking_id}/check-out
            });
        
             
        });

        // 📊 ระบบแดชบอร์ด แอดมิน และแม่บ้าน
        Route::middleware('role:admin')->group(function () {

            Route::controller(UserController::class)->prefix('users')->group(function () {
                Route::get('/get', 'index');            // GET http://hotel.test/api/v1/users/get
                Route::get('/{id}', 'show');            // GET http://hotel.test/api/v1/users/{id}
                Route::delete('/{id}', 'destroy');      // DELETE http://hotel.test/api/v1/users/{id}
            });
            // 🛏️ ดึงสถานะห้องให้พนักงานดู (ย้ายมา RoomController)
            Route::controller(RoomController::class)->group(function () {
                Route::get('/rooms/get', 'allRooms');                           // http://hotel.test/api/v1/rooms/get
                Route::get('/rooms/status', 'roomStatus');                      // http://hotel.test/api/v1/rooms/status
                Route::put('/rooms/updateStatus/{id}', 'updateRoomStatus');     // http://hotel.test/api/v1/rooms/updateStatus/{id}
            });
           
            Route::controller(BookingController::class)->group(function () {                
                                     // http://hotel.test/api/v1/get/bookings
                                   // http://hotel.test/api/v1//search/bookings/
            });
            
            Route::controller(DashboardController::class)->prefix('housekeeping')->group(function () {
                Route::get('/', 'cleaningTasks');                           // http://hotel.test/api/v1/housekeeping
                Route::post('/{room_id}/status', 'updateCleaningStatus');   // http://hotel.test/api/v1/housekeeping/{room_id}/status
            });

             Route::controller(FrontDeskController::class)->prefix('frontdesk')->group(function () {
                Route::put('/check_in/{booking_id}', 'checkIn');           //  http://hotel.test/api/v1/frontdesk/check_in/{booking_id}

            }); // ✨ ปิด FrontDeskController

        }); 

    }); // 🛑 จบเขตหวงห้าม Sanctum
    
});