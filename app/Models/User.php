<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; 
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany; // 🌟 เพิ่มเข้ามา

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids;

    // 🌟 หนูแอบแก้คำผิด 'hone' เป็น 'phone' และลบ 'role' ที่ซ้ำกันออกให้นะคะ
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'title',          
        'phone',           
        'nationality',    
        'is_ku_member',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // 🌟 เพิ่ม Relationship ให้ User สามารถดูประวัติการจองทั้งหมดของตัวเองได้ค่ะ
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public $incrementing = false;
    protected $keyType = 'string';
}