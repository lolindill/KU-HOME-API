<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'auth_provider', // 🎫 password | ku_sso | google (อนาคต) — split user ต่อ provider (wayfinder ticket 02)
        'password',
        'role',
        'title',
        'phone',
        'nationality',
        // 🔐 (2026-11-27) `status` จงใจไม่อยู่ใน fillable — เปลี่ยนได้ทางเดียวคือ
        //    PATCH /users/{id}/status (UserController::updateStatus) ซึ่ง revoke token ไปพร้อมกัน
    ];

    // 🔐 สถานะบัญชี (2026-11-27 — แทน legacy `ver`): inactive = login ไม่ได้ + token เดิมใช้ไม่ได้
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    // 🔐 default ฝั่ง model ให้ตรงกับ column default — instance ที่เพิ่ง create() (เช่น SSO first login)
    //    จะมี status ใน JSON ทันทีโดยไม่ต้อง fresh()
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function isActive(): bool
    {
        return $this->status !== self::STATUS_INACTIVE;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    // 🖼️ รูปโปรไฟล์ (avatar) — 1 user มี avatar เดียว (controller ลบ row เก่าก่อนสร้างใหม่)
    public function avatar(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    // 🖼️ ลบ user → ลบ avatar row ติดไปด้วย (hook deleting ของ Image จะลบไฟล์บน disk ให้)
    //    ใช้ relation query ตรง ไม่พึ่ง cache บน instance — กัน stale cache ของ relation เดิม
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            $user->avatar()->first()?->delete();
        });
    }

    public $incrementing = false;

    protected $keyType = 'string';
}
