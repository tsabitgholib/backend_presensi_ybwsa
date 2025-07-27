<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Helpers\JWT;
use App\Models\Admin;
use App\Models\MsPegawai;
use App\Models\Pegawai;

class AuthJWT
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
            return response()->json(['message' => 'Token tidak ditemukan'], 401);
        }
        $token = $matches[1];
        try {
            $payload = JWT::decode($token, env('JWT_SECRET'));
            if (isset($payload->role) && in_array($payload->role, ['admin', 'super_admin', 'admin_unit'])) {
                $admin = Admin::find($payload->sub);
                if (!$admin) {
                    return response()->json(['message' => 'Admin tidak ditemukan'], 401);
                }
                $request->attributes->set('admin', $admin);
            } elseif (isset($payload->role) && $payload->role === 'pegawai') {
                $pegawai = MsPegawai::with(['shiftDetail.shift', 'unitDetailPresensi'])->find($payload->sub);
                if (!$pegawai) {
                    return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
                }
                $request->attributes->set('pegawai', $pegawai);
            } else {
                return response()->json(['message' => 'Role tidak valid'], 401);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Token tidak valid: ' . $e->getMessage()], 401);
        }
        return $next($request);
    }
}
