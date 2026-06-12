<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StoreImageRequest;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;

// 🚧 DRAFT / TESTING — ยังไม่ใช้งานจริง ระบบอัปโหลดรูปภาพยังไม่สมบูรณ์
class ImageController extends Controller
{
    /**
     * อัปโหลดรูปภาพ (DRAFT / TESTING)
     */
    public function upload(StoreImageRequest $request)
    {
        $validated = $request->validated();

        $path = $request->file('image')->store('images', 'public');

        $image = Image::create([
            'url' => $path,
            'imageable_id' => $validated['imageable_id'] ?? null,
            'imageable_type' => $validated['imageable_type'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Image uploaded successfully',
            'image' => $image,
            'url' => Storage::url($path)
        ], 201);
    }
}