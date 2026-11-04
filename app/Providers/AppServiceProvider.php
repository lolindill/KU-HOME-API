<?php

namespace App\Providers;

use App\Services\RoomAllocator\RoomAllocator;
use App\Services\RoomAllocator\Weights;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
