<?php

namespace App\Console\Commands;

use App\Models\BookingConfirmation;
use App\Models\BookingRoom;
use App\Models\HousekeepingTask;
use App\Models\Image;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Signature('app:cleanup-images')]
#[Description('Sweep orphaned slip/evidence/housekeeping files/rows + apply retention policy on rejected slips (30d), checkin evidences (365d) and housekeeping photos (90d)')]
class CleanupImages extends Command
{
    public function handle()
    {
        $this->info('🧹 Starting image cleanup...');

        $results = [
            'orphan_files_deleted' => 0,
            'orphan_rows_deleted' => 0,
            'rejected_slips_deleted' => 0,
            'checkin_evidences_deleted' => 0,
            'expired_housekeeping_photos_deleted' => 0,
        ];

        // ── 1) ไฟล์กำพร้าบน disk — เขียนไปแล้วแต่ไม่มี row คุม
        //    (เช่น เหลือจาก failed transaction ก่อน best-effort delete ใน controller จะทำงาน)
        $disk = Storage::disk('local');
        $knownPaths = Image::where('disk', 'local')->pluck('path')->flip();
        foreach (['slips', 'checkin_evidences', 'housekeeping'] as $folder) {
            foreach ($disk->files($folder) as $file) {
                if (! $knownPaths->has($file)) {
                    $disk->delete($file);
                    $results['orphan_files_deleted']++;
                    $this->line("  ✓ Orphan file deleted: {$file}");
                }
            }
        }

        // ── 2) row กำพร้า
        //    2a. image morph ไป confirmation ที่ถูกลบไปแล้ว (defense-in-depth นอกเหนือจาก
        //        destroyBooking/CleanupExpiredDrafts ที่ลบให้แล้ว — เผื่อมีจุดลบที่ลืม)
        $orphans = Image::query()
            ->where('imageable_type', BookingConfirmation::class)
            ->whereNotIn('imageable_id', BookingConfirmation::pluck('id'))
            ->get();
        foreach ($orphans as $image) {
            $image->delete(); // hook deleting จะลบไฟล์บน disk ให้
            $results['orphan_rows_deleted']++;
            $this->line("  ✓ Orphan confirmation row deleted: {$image->id}");
        }

        //    2b. image morph ไป booking_room (หลักฐาน check-in) ที่ถูกลบไปแล้ว
        $orphanEvidences = Image::query()
            ->where('imageable_type', BookingRoom::class)
            ->whereNotIn('imageable_id', BookingRoom::pluck('id'))
            ->get();
        foreach ($orphanEvidences as $image) {
            $image->delete(); // hook deleting จะลบไฟล์บน disk ให้
            $results['orphan_rows_deleted']++;
            $this->line("  ✓ Orphan checkin evidence row deleted: {$image->id}");
        }

        //    2c. image morph ไป housekeeping task ที่ถูกลบไปแล้ว
        $taskOrphans = Image::query()
            ->where('imageable_type', HousekeepingTask::class)
            ->whereNotIn('imageable_id', HousekeepingTask::pluck('id'))
            ->get();
        foreach ($taskOrphans as $image) {
            $image->delete(); // hook deleting จะลบไฟล์บน disk ให้
            $results['orphan_rows_deleted']++;
            $this->line("  ✓ Orphan task photo row deleted: {$image->id}");
        }

        //    2d. image ไม่มี imageable เลยและแก่กว่า 24 ชม. (สร้างไม่สำเร็จ/ค้างจากอะไรก็ตาม)
        $unlinked = Image::query()
            ->whereNull('imageable_id')
            ->where('created_at', '<', Carbon::now()->subDay())
            ->get();
        foreach ($unlinked as $image) {
            $image->delete();
            $results['orphan_rows_deleted']++;
            $this->line("  ✓ Unlinked row deleted: {$image->id}");
        }

        // ── 3) retention — สลิปของ confirmation ที่ถูก rejected เก่ากว่า N วัน → ลบรูป
        //    ⚠️ คง confirmation row ไว้ตาม audit trail (ลบเฉพาะรูป)
        //    ⚠️ สลิป verified เก็บไว้ทั้งหมด — หลักฐานการเงิน ห้ามลับอัตโนมัติ
        $retentionDays = max(1, (int) env('SLIP_RETENTION_DAYS', 30));
        $expiredSlipIds = BookingConfirmation::query()
            ->where('status', 'rejected')
            ->where('created_at', '<', Carbon::now()->subDays($retentionDays))
            ->pluck('id');

        $expiredSlips = Image::query()
            ->where('imageable_type', BookingConfirmation::class)
            ->whereIn('imageable_id', $expiredSlipIds)
            ->get();
        foreach ($expiredSlips as $image) {
            $image->delete();
            $results['rejected_slips_deleted']++;
            $this->line("  ✓ Rejected slip image deleted (retention {$retentionDays}d): {$image->id}");
        }

        // ── 4) retention — หลักฐานการเข้าพัก check-in (REQ-033) เก็บ 365 วัน (1 ปี)
        //    ⚠️ ตัดตามอายุ 365 วันตรงๆ ไม่มีข้อยกเว้นแบบสลิป verified
        $evidenceRetentionDays = max(1, (int) env('CHECKIN_EVIDENCE_RETENTION_DAYS', 365));
        $expiredEvidences = Image::query()
            ->where('imageable_type', BookingRoom::class)
            ->where('created_at', '<', Carbon::now()->subDays($evidenceRetentionDays))
            ->get();
        foreach ($expiredEvidences as $image) {
            $image->delete();
            $results['checkin_evidences_deleted']++;
            $this->line("  ✓ Check-in evidence image deleted (retention {$evidenceRetentionDays}d): {$image->id}");
        }

        // ── 5) retention — รูปแม่บ้าน (HousekeepingTask) เก็บ 90 วัน (REQ-048)
        //    รูปที่สร้างเกิน N วัน (default 90) → ลบรูป (hook deleting จะลบไฟล์บน disk ให้)
        $hkRetentionDays = max(1, (int) env('HOUSEKEEPING_PHOTO_RETENTION_DAYS', 90));
        $expiredHkPhotos = Image::query()
            ->where('imageable_type', HousekeepingTask::class)
            ->where('created_at', '<', Carbon::now()->subDays($hkRetentionDays))
            ->get();
        foreach ($expiredHkPhotos as $image) {
            $image->delete();
            $results['expired_housekeeping_photos_deleted']++;
            $this->line("  ✓ Expired housekeeping photo deleted (retention {$hkRetentionDays}d): {$image->id}");
        }

        Log::info('Image cleanup completed', $results);
        $this->info("  ✓ Orphan files deleted: {$results['orphan_files_deleted']}");
        $this->info("  ✓ Orphan rows deleted: {$results['orphan_rows_deleted']}");
        $this->info("  ✓ Rejected slip images deleted (retention {$retentionDays}d): {$results['rejected_slips_deleted']}");
        $this->info("  ✓ Check-in evidence images deleted (retention {$evidenceRetentionDays}d): {$results['checkin_evidences_deleted']}");
        $this->info("  ✓ Expired housekeeping photos deleted (retention {$hkRetentionDays}d): {$results['expired_housekeeping_photos_deleted']}");
        $this->info('✅ Image cleanup completed.');

        return Command::SUCCESS;
    }
}
