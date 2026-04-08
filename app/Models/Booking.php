<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Booking extends Model
{
    use HasFactory,HasUuids;

    // 1. ปลดล็อกให้เซฟข้อมูลได้ทุกฟิลด์ (เพราะใน Controller เรา Validate มาอย่างดีแล้วค่ะ!)
    protected $guarded = []; 

    // 2. บอก Laravel ว่า Primary Key ของเราไม่ใช่ตัวเลขเรียงลำดับนะ
    public $incrementing = false;

    // 3. บอก Laravel ว่า ID ของเราเป็นประเภท String (UUID)
    protected $keyType = 'string';

    // นำไปวางในไฟล์ Booking.php นะคะนายท่าน
    public function bookingAddons()
    {
        return $this->hasMany(Addon::class);
    }
}