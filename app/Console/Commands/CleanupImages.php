<?php

namespace App\Console\Commands;

use App\Models\BookingConfirmation;
use App\Models\HousekeepingTask;
use App\Models\Image;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Signature('app:cleanup-images')]
#[Description('Sweep orphaned slip/housekeeping files/rows + apply retention policy on rejected slips (30d) and housekeeping photos (90d)')]
class CleanupImages extends Command
{
    public function handle()
    {
        $this->info('🧹 Starting image cleanup...');

        $results = [
            'orphan_files_deleted' => 0,
            'orphan_rows_deleted' => 0,
            'rejected_slips_deleted' => 0,
            'expired_housekeeping_photos_deleted' => 0,
        ];

        // ── 1) ไฟล์กำพร้าบน disk — เขียนไปแล้วแต่ไม่มี row คุม
        //    (เช่น เหลือจาก failed transaction ก่อน best-effort delete ใน controller จะทำงาน)
        $disk = Storage::disk('local');
        $knownPaths = Image::where('disk', 'local')->pluck('path')->flip();
        foreach (['slips', 'housekeeping'] as $dir) {
            foreach ($disk->files($dir) as $file) {
                if (! $knownPaths->has($file)) {
                    $disk->delete($file);
                    $results['orphan_files_deleted']++;
                    $this->line("  ✓ Orphan file deleted: {$file}");
                }
            }
        }

        // ── 2) row กำพร้า
        //    2a. image morph ไป confirmation หรือ housekeeping task ที่ถูกลบไปแล้ว
        $orphans = Image::query()
            ->where('imageable_type', BookingConfirmation::class)
            ->whereNotIn('imageable_id', BookingConfirmation::pluck('id'))
            ->get();
        foreach ($orphans as $image) {
            $image->delete(); // hook deleting จะลบไฟล์บน disk ให้
            $results['orphan_rows_deleted']++;
            $this->line("  ✓ Orphan row deleted: {$image->id}");
        }

        $taskOrphans = Image::query()
            ->where('imageable_type', HousekeepingTask::class)
            ->whereNotIn('imageable_id', HousekeepingTask::pluck('id'))
            ->get();
        foreach ($taskOrphans as $image) {
            $image->delete();
            $results['orphan_rows_deleted']++;
            $this->line("  ✓ Orphan task photo row deleted: {$image->id}");
        }

        //    2b. image ไม่มี imageable เลยและแก่กว่า 24 ชม. (สร้างไม่สำเร็จ/ค้างจากอะไรก็ตาม)
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

        // ── 4) retention — รูปแม่บ้าน (HousekeepingTask) เก็บ 90 วัน (REQ-048)
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
        $this->info("  ✓ Expired housekeeping photos deleted (retention {$hkRetentionDays}d): {$results['expired_housekeeping_photos_deleted']}");
        $this->info('✅ Image cleanup completed.');

        return Command::SUCCESS;
    }
}
