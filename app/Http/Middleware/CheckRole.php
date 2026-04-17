<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{   
    // user ku_member staff admin housekeeping
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles (รับค่า Role ได้หลายๆ ตัวพร้อมกันค่ะ)
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // ถ้ายังไม่ได้ Login หรือ Role ไม่ตรงกับที่อนุญาต ให้เตะออกเลยค่ะ!
        if (! $request->user() || ! in_array($request->user()->role, $roles)) {
            return response()->json([
                'error' => 'Forbidden: พื้นที่หวงห้ามค่ะ! สิทธิ์ของนายท่านไม่เพียงพอ 🥺'
            ], 403);
        }

        return $next($request);
    }
}