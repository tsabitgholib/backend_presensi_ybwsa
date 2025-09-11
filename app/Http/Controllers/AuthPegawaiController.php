<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Helpers\JWT;
use App\Models\MsOrang;
use Illuminate\Support\Facades\DB;

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
            'sub' => $orang->id,
            'no_ktp' => $orang->no_ktp,
            'role' => 'pegawai',
            'iat' => time(),
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
            'pegawai.unitDetailPresensi.unit',
            'pegawai'
        ]);

        $namaLengkap = trim(implode(' ', [
            $pegawai->gelar_depan,
            $pegawai->nama,
            $pegawai->gelar_belakang,
        ]));

        $id_orang = $pegawai->pegawai->id_orang;

        $sql = "
            SELECT 
                CASE
                    WHEN oyg.id IS NOT NULL AND oyg.aktif = 1 THEN oy.nama
                    ELSE upk.nama
                END AS upk_name
            FROM ms_pegawai
            LEFT JOIN ms_unit upk ON ms_pegawai.id_upk = upk.id
            LEFT JOIN organ_yayasan_anggota oyg ON oyg.id_orang = ms_pegawai.id_orang
            LEFT JOIN organ_yayasan_jabatan oyj ON oyj.id = oyg.id_organ_jabatan
            LEFT JOIN organ_yayasan oy ON oy.id = oyj.id_organ
            WHERE ms_pegawai.id_orang = ?
            LIMIT 1
        ";

        $upk = DB::selectOne($sql, [$id_orang]);
        //dd($upk);


        
        //var_dump($id_orang);
        //var_dump($pegawai->pegawa->id_orang);
        //var_dump($upk);
        //die();
        

        $upkName = $upk ? $upk->upk_name : null;

        $unit = $pegawai->pegawai->unit ?? null;

        $baseUrl = "https://pegawai.ybw-sa.org/uploads/sdi/sekrt/"; //default
        if ($unit) {
            $rootId = $unit->getRootParentId();
            if ($rootId == 4) {
                $baseUrl = "https://pegawai.ybw-sa.org/uploads/sdi/sekrt/";
            } elseif ($rootId == 147) {
                $baseUrl = "https://sdi.rsisultanagung.com/foto-karyawan/";
            } elseif ($rootId == 10 || $rootId == 37) {
                $baseUrl = "https://sim.unissula.ac.id/app/modules/sdm/uploads/fotopeg/";
            }
        }

        $fotoPegawai = $pegawai->foto ? $baseUrl . $pegawai->foto : null;


        // $response = [
        //     'id' => $pegawai->pegawai->id ?? null,
        //     'no_ktp' => $pegawai->no_ktp,
        //     'nama' => $namaLengkap,
        //     'foto' => $fotoPegawai,
        //     'tmpt_lahir' => $pegawai->tmpt_lahir,
        //     'tgl_lahir' => $pegawai->tgl_lahir,
        //     'jenis_kelamin' => $pegawai->jenis_kelamin,
        //     'no_hp' => $pegawai->no_hp,
        //     'jabatan' => $pegawai->pegawai->profesi ?? null,
        //     'shift_detail_id' => $pegawai->pegawai->presensi_shift_detail_id ?? null,
        //     'unit_detail_id_presensi' => $pegawai->pegawai->presensi_ms_unit_detail_id ?? null,
        //     'shift_detail' => $pegawai->pegawai->shiftDetail ? [
        //         'id' => $pegawai->pegawai->shiftDetail->id,
        //         'shift_id' => $pegawai->pegawai->shiftDetail->shift_id,
        //         'senin_masuk' => $pegawai->pegawai->shiftDetail->senin_masuk,
        //         'senin_pulang' => $pegawai->pegawai->shiftDetail->senin_pulang,
        //         'selasa_masuk' => $pegawai->pegawai->shiftDetail->selasa_masuk,
        //         'selasa_pulang' => $pegawai->pegawai->shiftDetail->selasa_pulang,
        //         'rabu_masuk' => $pegawai->pegawai->shiftDetail->rabu_masuk,
        //         'rabu_pulang' => $pegawai->pegawai->shiftDetail->rabu_pulang,
        //         'kamis_masuk' => $pegawai->pegawai->shiftDetail->kamis_masuk,
        //         'kamis_pulang' => $pegawai->pegawai->shiftDetail->kamis_pulang,
        //         'jumat_masuk' => $pegawai->pegawai->shiftDetail->jumat_masuk,
        //         'jumat_pulang' => $pegawai->pegawai->shiftDetail->jumat_pulang,
        //         'sabtu_masuk' => $pegawai->pegawai->shiftDetail->sabtu_masuk,
        //         'sabtu_pulang' => $pegawai->pegawai->shiftDetail->sabtu_pulang,
        //         'minggu_masuk' => $pegawai->pegawai->shiftDetail->minggu_masuk,
        //         'minggu_pulang' => $pegawai->pegawai->shiftDetail->minggu_pulang,
        //         'toleransi_terlambat' => $pegawai->pegawai->shiftDetail->toleransi_terlambat,
        //         'toleransi_pulang' => $pegawai->pegawai->shiftDetail->toleransi_pulang,
        //         'created_at' => $pegawai->pegawai->shiftDetail->created_at,
        //         'updated_at' => $pegawai->pegawai->shiftDetail->updated_at,
        //         'shift' => $pegawai->pegawai->shiftDetail->shift ? [
        //             'id' => $pegawai->pegawai->shiftDetail->shift->id,
        //             'name' => $pegawai->pegawai->shiftDetail->shift->name,
        //             'unit_id' => $pegawai->pegawai->shiftDetail->shift->unit_id,
        //             'created_at' => $pegawai->pegawai->shiftDetail->shift->created_at,
        //             'updated_at' => $pegawai->pegawai->shiftDetail->shift->updated_at
        //         ] : null
        //     ] : null,
        //     'unit_detail_presensi' => $pegawai->pegawai->unitDetailPresensi ? [
        //         'id' => $pegawai->pegawai->unitDetailPresensi->id,
        //         'unit_id' => $pegawai->pegawai->unitDetailPresensi->ms_unit_id,
        //         'name' => $pegawai->pegawai->unitDetailPresensi->unit->nama ?? null,
        //         'lokasi' => $pegawai->pegawai->unitDetailPresensi->lokasi ?? null,
        //         'lokasi2' => $pegawai->pegawai->unitDetailPresensi->lokasi2 ?? null,
        //         'lokasi3' => $pegawai->pegawai->unitDetailPresensi->lokasi3 ?? null,
        //         'created_at' => $pegawai->pegawai->unitDetailPresensi->created_at,
        //         'updated_at' => $pegawai->pegawai->unitDetailPresensi->updated_at
        //     ] : null
        // ];

        $unitDetail = $pegawai->pegawai->unitDetailPresensi;

        $lokasi_presensi = [];

        if ($unitDetail) {
            // lokasi utama
            if (!empty($unitDetail->lokasi) && is_array($unitDetail->lokasi) && count($unitDetail->lokasi) > 0) {
                $lokasi_presensi[] = [
                    'unit_detail_id' => $unitDetail->id,
                    'nama_lokasi' => $pegawai->pegawai->unitDetailPresensi->unit->nama ?? null,
                    'lokasi' => $unitDetail->lokasi,
                    'unit_name' => $pegawai->pegawai->unitDetailPresensi->unit->nama ?? null,
                ];
            }

            // lokasi 2
            if (!empty($unitDetail->lokasi2) && is_array($unitDetail->lokasi2) && count($unitDetail->lokasi2) > 0) {
                $lokasi_presensi[] = [
                    'unit_detail_id' => $unitDetail->id,
                    'nama_lokasi' => $pegawai->pegawai->unitDetailPresensi->unit->nama  . ' - Area 2',
                    'lokasi' => $unitDetail->lokasi2,
                    'unit_name' => $pegawai->pegawai->unitDetailPresensi->unit->nama ?? null,
                ];
            }

            // lokasi 3
            if (!empty($unitDetail->lokasi3) && is_array($unitDetail->lokasi3) && count($unitDetail->lokasi3) > 0) {
                $lokasi_presensi[] = [
                    'unit_detail_id' => $unitDetail->id,
                    'nama_lokasi' => $pegawai->pegawai->unitDetailPresensi->unit->nama .' - Area 3',
                    'lokasi' => $unitDetail->lokasi3,
                    'unit_name' => $pegawai->pegawai->unitDetailPresensi->unit->nama ?? null,
                ];
            }
        }

        $response = [
            'id' => $pegawai->id,
            'no_ktp' => $pegawai->no_ktp,
            'nama' => $pegawai->nama,
            'foto' => $fotoPegawai,
            'tmpt_lahir' => $pegawai->tmpt_lahir,
            'tgl_lahir' => $pegawai->tgl_lahir,
            'jenis_kelamin' => $pegawai->jenis_kelamin,
            'no_hp' => $pegawai->no_hp,
            'jabatan' => $upkName,
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
            'lokasi_presensi' => $lokasi_presensi,
        ];



        return response()->json($response);
    }
}