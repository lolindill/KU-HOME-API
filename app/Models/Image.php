<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * 🖼️ Image — ระบบรูปภาพจริงจัง (19/08/26) แทน 🚧 DRAFT เดิม
 *
 * - ไฟล์อยู่บน disk (default: local = storage/app/private — เว็บเปิดตรงๆ ไม่ได้)
 * - ผูกกับเจ้าของรูปผ่าน polymorphic imageable (BookingConfirmation slip วันนี้,
 *   HousekeepingTask รูปก่อน/หลังเก็บห้อง ในอนาคต)
 * - ดูรูปผ่าน signed URL อายุ SIGNED_URL_TTL_MINUTES นาที (route images.file)
 *   URL ถูกออกให้เฉพาะใน response ของผู้มีสิทธิ์เท่านั้น (เจ้าของ booking / admin)
 * - ลบ row = ลบไฟล์บน disk อัตโนมัติ (hook deleting — ทุก call site ไม่ต้องจำ)
 */
class Image extends Model
{
    use HasFactory, HasUuids;

    /** 🔐 อายุ signed URL (นาที) — พอสำหรับเปิดดู/โหลดรูป และไม่ยาวถึงขั้นถูกแชร์ต่อ */
    public const SIGNED_URL_TTL_MINUTES = 15;

    protected $fillable = [
        'path',
        'disk',
        'mime_type',
        'size',
        'original_name',
        'uploaded_by',
        'imageable_id',
        'imageable_type',
    ];

    protected $appends = ['url'];

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * 🔐 Signed URL ชั่วคราว — frontend ใช้ <img src> ตรงๆ ได้ หมดอายุเอง
     * (generate ตอน serialize เสมอ จึงสดทุกครั้งที่ response ถูกสร้าง)
     */
    protected function url(): Attribute
    {
        return Attribute::get(
            fn () => URL::temporarySignedRoute(
                'images.file',
                now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
                ['image' => $this->id],
            )
        );
    }

    protected static function booted(): void
    {
        // 🧹 ลบ row → ลบไฟล์บน disk ให้อัตโนมัติ
        static::deleting(function (Image $image) {
            if ($image->path) {
                Storage::disk($image->disk)->delete($image->path);
            }
        });
    }
}
