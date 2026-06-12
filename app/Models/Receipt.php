<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Carbon\Carbon;
use Exception;

class Receipt extends Model
{
    use HasFactory, HasUuids;

    /**
     * ✅ #17 Fixed: เปลี่ยนจาก $guarded = [] เป็น $fillable
     */
    protected $fillable = [
        'receipt_no',
        'booking_id',
        'payment_id',
        'amount',
        'billing_name',
        'billing_address',
    ];

    /**
     * ✅ #30 Fixed: เพิ่ม cast สำหรับ amount เป็น integer (satang/cents)
     * ให้ตรงกับ Booking.total_amount และ Payment.amount
     */
    protected $casts = [
        'amount' => 'integer',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /*
      ✅ #19 Fixed: Atomic counter receipt number generator
      
      ใช้ receipt_sequences table + SELECT FOR UPDATE เพื่อป้องกัน collision
     Format: REC-YYYYMM-XXXXX (เช่น REC-202606-00001)
     
     @return string Receipt number ที่ unique การันตี
      @throws Exception ถ้าสร้างไม่สำเร็จหลัง retry
     */
    public static function generateUniqueReceiptNo(): string
    {
        $maxAttempts = 3;

        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                return DB::transaction(function () {
                    $key = Carbon::now()->format('Ym');

                    $seq = DB::table('receipt_sequences')
                        ->where('key', $key)
                        ->lockForUpdate()
                        ->first();

                    if ($seq) {
                        $next = $seq->last_number + 1;
                        DB::table('receipt_sequences')
                            ->where('key', $key)
                            ->update(['last_number' => $next]);
                    } else {
                        $next = 1;
                        DB::table('receipt_sequences')->insert([
                            'key' => $key,
                            'last_number' => $next,
                        ]);
                    }

                    return 'REC-' . $key . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
                });
            } catch (QueryException $e) {
                if ($i === $maxAttempts - 1) {
                    throw new Exception('Unable to generate unique receipt number after ' . $maxAttempts . ' attempts');
                }
                usleep(100000); // รอ 100ms แล้ว retry
            }
        }

        throw new Exception('Unable to generate unique receipt number');
    }
}
