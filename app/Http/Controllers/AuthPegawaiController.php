<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\MsPegawai;
use App\Helpers\JWT;

class AuthPegawaiController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'no_ktp' => 'required',
            'password' => 'required',
        ]);

        $pegawai = MsPegawai::where('no_ktp', $request->no_ktp)->first();
        if (!$pegawai || !Hash::check($request->password, $pegawai->password)) {
            return response()->json(['message' => 'NIK atau password salah'], 401);
        }

        $payload = [
            'sub' => $pegawai->id,
            'no_ktp' => $pegawai->no_ktp,
            'role' => 'pegawai',
            'exp' => time() + 86400 // expired 1 hari
        ];
        $token = JWT::encode($payload, env('JWT_SECRET'));

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token
        ]);
    }

    public function me(Request $request)
    {
        $pegawai = $request->get('pegawai');

        // Load relasi yang diperlukan untuk Android/iOS
        $pegawai->load([
            'unitDetailPresensi',
            'shiftDetail.shift'
        ]);

        return response()->json($pegawai);
    }
}
