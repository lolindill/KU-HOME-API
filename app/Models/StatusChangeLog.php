<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 📝 Audit Log Model (04/08/26)
 *
 * เก็บประวัติการเปลี่ยนสถานะของ Booking & BookingRoom (polymorphic)
 * - ถูกเขียนที่ chokepoint เดียว: transitionStatus() ของแต่ละ model
 * - append-only — ห้าม update/delete row (เป็น audit trail)
 *
 * entity_type values:
 *   - 'booking'      → entity_id = bookings.id
 *   - 'booking_room' → entity_id = booking_rooms.id
 *   - (ขยายได้ในอนาคต เช่น 'room', 'housekeeping_task')
 */
class StatusChangeLog extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'status_change_logs';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'from_status',
        'to_status',
        'role',
        'causer_id',
        'note',
    ];

    /*
    |-------------------------------------------
    | 🔗 Relationships (optional, polymorphic)
    |-------------------------------------------
    | ไม่ใช้ laravel morphTo() เพราะตั้งใจเก็บเป็น string entity_type
    | ตาม convention ของ codebase ที่ไม่ใช้ polymorphic relationship
    | ดึง entity จริงได้ผ่าน controller ถ้าจำเป็น
    */
}
