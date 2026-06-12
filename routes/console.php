<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule; // เพิ่มการ Import Facade ตรงนี้ค่ะ

// แก้ไขการปิดท้ายคำสั่งด้วย ;
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// เรียกใช้ Schedule ผ่าน Facade ให้ถูกต้อง
Schedule::command('app:daily-room-maintenance')->daily();
Schedule::command('app:cleanup-expired-drafts')->dailyAt('02:00');
