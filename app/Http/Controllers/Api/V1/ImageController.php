<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Image;

class ImageController extends Controller
{
    public function store(Request $request)
    {
        // 1. ตรวจสอบว่าส่งไฟล์มาจริง และเป็นไฟล์รูปภาพ
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($request->hasFile('image')) {
            // 2. บันทึกรูปลงโฟลเดอร์เซิร์ฟเวอร์
            $path = $request->file('image')->store('uploads', 'public');

            // 3. บันทึกข้อมูลที่อยู่รูปลง PostgreSQL
            $image = Image::create([
                'name' => $request->file('image')->getClientOriginalName(),
                'image_path' => $path,
            ]);

            // 4. ตอบกลับฝั่ง Frontend พร้อม URL รูป
            return response()->json([
                'success' => true,
                'message' => 'น้องเมดอัปโหลดรูปสำเร็จแล้วค่ะ!',
                'data' => [
                    'id' => $image->id,
                    'name' => $image->name,
                    'url' => asset('storage/' . $path) // URL เต็มสำหรับเอาไปโชว์
                ]
            ], 201);
        }

        return response()->json(['message' => 'ไม่พบไฟล์ภาพค่ะ'], 400);
    }
}