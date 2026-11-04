<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🎯 RequireJsonAccept
 *
 * 📌 Global middleware บังคับว่าทุก /api/* request ต้อง "ยอมรับ" JSON
 *    (เพราะเป็น API-only project — ไม่มี Blade ให้ fallback ค่ะ!)
 *
 * กติกานะคะ:
 *   1. ถ้าไม่ส่ง Accept มาเลย           → ใส่ "application/json" ให้เป็น default (สุภาพ)
 *   2. ถ้าส่ง Accept มาแต่ไม่ใช่ JSON    → ตบ 406 Not Acceptable ทันที 🥊
 *
 * ทำไมต้องเป็น global บน api group? เพื่อให้ทำงาน "ก่อน" throttle/auth:sanctum
 * และ lock-in ให้ทุก response เป็น JSON สม่ำเสมอค่ะ ✨
 */
class RequireJsonAccept
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accept = $request->header('Accept');

        // 🌟 กรณีที่ 1: ไม่ได้ส่ง Accept มาเลย → ใส่ default ให้เป็นคนดี (application/json)
        if (empty($accept)) {
            $request->headers->set('Accept', 'application/json');

            return $next($request);
        }

        // 🌟 กรณีที่ 2: ส่ง Accept มาแต่ไม่ยอมรับ JSON เลย → ตบกลับ 406 ค่ะ!
        if (! $this->acceptsJson($accept)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Accept: application/json is required for all /api/* requests. 🥺',
            ], 406);
        }

        return $next($request);
    }

    /**
     * เช็คว่า Accept header ยอมรับ application/json หรือไม่
     * (เคส wildcard ก็ถือว่ายอมรับทุกอย่างรวมถึง JSON ด้วยนะคะ)
     */
    private function acceptsJson(string $accept): bool
    {
        // แยกแต่ละ media type ด้วย comma เช่น "text/html, application/json"
        foreach (explode(',', $accept) as $mediaType) {
            // ตัด parameter ออก เช่น "application/json; charset=utf-8"
            $type = trim(explode(';', $mediaType)[0]);
            $type = strtolower($type);

            // ยอมรับ JSON / wildcard / +json suffix (เช่น application/vnd.api+json)
            if ($type === 'application/json'
                || $type === '*/*'
                || $type === 'application/*'
                || str_ends_with($type, '+json')) {
                return true;
            }
        }

        return false;
    }
}
