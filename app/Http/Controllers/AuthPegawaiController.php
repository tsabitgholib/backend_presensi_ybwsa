<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Helpers\JWT;
use App\Models\MsOrang;

class AuthPegawaiController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'no_ktp' => 'required',
            'password' => 'required',
        ]);
    
        $orang = MsOrang::where('no_ktp', $request->no_ktp)->first();

        if (!$orang || $request->password !== $orang->no_ktp) {
            return response()->json(['message' => 'NIK atau password salah'], 401);
        }

        $payload = [
            'sub' => $orang->id, // ini ID orang, bukan pegawai
            'no_ktp' => $orang->no_ktp,
            'role' => 'pegawai',
            'exp' => time() + 86400
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
            'pegawai.shiftDetail.shift',
            'pegawai.unitDetailPresensi.unit'
        ]);

        $namaLengkap = trim(implode(' ', [
            $pegawai->gelar_depan,
            $pegawai->nama,
            $pegawai->gelar_belakang,
        ]));

        $response = [
            'id' => $pegawai->pegawai->id ?? null,
            'no_ktp' => $pegawai->no_ktp,
            'nama' => $namaLengkap,
            'tmpt_lahir' => $pegawai->tmpt_lahir,
            'tgl_lahir' => $pegawai->tgl_lahir,
            'jenis_kelamin' => $pegawai->jenis_kelamin,
            'no_hp' => $pegawai->no_hp,
            'jabatan' => $pegawai->pegawai->profesi ?? null,
            'shift_detail_id' => $pegawai->pegawai->presensi_shift_detail_id ?? null,
            'unit_detail_id_presensi' => $pegawai->pegawai->presensi_ms_unit_detail_id ?? null,
            'shift_detail' => $pegawai->pegawai->shiftDetail ? [
                'id' => $pegawai->pegawai->shiftDetail->id,
                'shift_id' => $pegawai->pegawai->shiftDetail->shift_id,
                'senin_masuk' => $pegawai->pegawai->shiftDetail->senin_masuk,
                'senin_pulang' => $pegawai->pegawai->shiftDetail->senin_pulang,
                'selasa_masuk' => $pegawai->pegawai->shiftDetail->selasa_masuk,
                'selasa_pulang' => $pegawai->pegawai->shiftDetail->selasa_pulang,
                'rabu_masuk' => $pegawai->pegawai->shiftDetail->rabu_masuk,
                'rabu_pulang' => $pegawai->pegawai->shiftDetail->rabu_pulang,
                'kamis_masuk' => $pegawai->pegawai->shiftDetail->kamis_masuk,
                'kamis_pulang' => $pegawai->pegawai->shiftDetail->kamis_pulang,
                'jumat_masuk' => $pegawai->pegawai->shiftDetail->jumat_masuk,
                'jumat_pulang' => $pegawai->pegawai->shiftDetail->jumat_pulang,
                'sabtu_masuk' => $pegawai->pegawai->shiftDetail->sabtu_masuk,
                'sabtu_pulang' => $pegawai->pegawai->shiftDetail->sabtu_pulang,
                'minggu_masuk' => $pegawai->pegawai->shiftDetail->minggu_masuk,
                'minggu_pulang' => $pegawai->pegawai->shiftDetail->minggu_pulang,
                'toleransi_terlambat' => $pegawai->pegawai->shiftDetail->toleransi_terlambat,
                'toleransi_pulang' => $pegawai->pegawai->shiftDetail->toleransi_pulang,
                'created_at' => $pegawai->pegawai->shiftDetail->created_at,
                'updated_at' => $pegawai->pegawai->shiftDetail->updated_at,
                'shift' => $pegawai->pegawai->shiftDetail->shift ? [
                    'id' => $pegawai->pegawai->shiftDetail->shift->id,
                    'name' => $pegawai->pegawai->shiftDetail->shift->name,
                    'unit_id' => $pegawai->pegawai->shiftDetail->shift->unit_id,
                    'created_at' => $pegawai->pegawai->shiftDetail->shift->created_at,
                    'updated_at' => $pegawai->pegawai->shiftDetail->shift->updated_at
                ] : null
            ] : null,
            'unit_detail_presensi' => $pegawai->pegawai->unitDetailPresensi ? [
                'id' => $pegawai->pegawai->unitDetailPresensi->id,
                'unit_id' => $pegawai->pegawai->unitDetailPresensi->ms_unit_id,
                'name' => $pegawai->pegawai->unitDetailPresensi->unit->nama ?? null,
                'lokasi' => $pegawai->pegawai->unitDetailPresensi->lokasi ?? null,
                'created_at' => $pegawai->pegawai->unitDetailPresensi->created_at,
                'updated_at' => $pegawai->pegawai->unitDetailPresensi->updated_at
            ] : null
        ];

        return response()->json($response);
    }
}