<?php

namespace App\Providers;

use App\Models\User;
use App\Services\RoomAllocator\RoomAllocator;
use App\Services\RoomAllocator\Weights;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 🏨 Phase 5 binding (fixed 15/07/26): container ไม่สามารถ autowire Weights ได้
        //    เพราะ constructor ต้องการ int $floor (primitive) — ต้อง bind ผ่าน factory fromConfig()
        //    ก่อนหน้านี้ใช้ app(RoomAllocator::class) ใน DailyRoomMaintenance แล้วพัง BindingResolutionException
        $this->app->bind(Weights::class, fn () => Weights::fromConfig());
        $this->app->bind(RoomAllocator::class, fn ($app) => new RoomAllocator($app->make(Weights::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 🔐 (2026-11-27) user status: token ของบัญชี inactive ใช้ไม่ได้ทุก route ที่ผ่าน auth:sanctum → 401
        //    ชั้นที่สองต่อจากการลบ token ตอนปิดบัญชี (UserController::updateStatus) — กันกรณีปิดบัญชี
        //    ด้วยทางอื่น (tinker/SQL) แล้ว token ยังค้าง · $isValid ส่งผล expiry 8 ชม. มาให้แล้ว ห้ามทิ้ง
        Sanctum::authenticateAccessTokensUsing(
            fn ($accessToken, bool $isValid) => $isValid
                && ! ($accessToken->tokenable instanceof User && ! $accessToken->tokenable->isActive())
        );
    }
}
