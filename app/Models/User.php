<?php

namespace App\Models;

use App\Casts\PgBoolean;
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
        'ver',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            // 🌟 Fix PostgreSQL strict boolean (03/07/26): PgBoolean cast
            'ver' => PgBoolean::class,
        ];
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
