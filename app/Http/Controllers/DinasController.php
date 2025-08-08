<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Presensi;
use App\Models\MsPegawai;
use App\Models\ShiftDetail;
use Carbon\Carbon;
use App\Helpers\AdminUnitHelper;

class DinasController extends Controller
{
    /**
     * Create dinas for multiple employees
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Get validation rules using helper
        $unitValidationRules = AdminUnitHelper::getUnitIdValidationRules($request);
        
        $request->validate(array_merge([
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
            'keterangan' => 'required|string|max:255',
            'pegawai_ids' => 'required|array',
            'pegawai_ids.*' => 'exists:pegawai,id',
        ], $unitValidationRules));

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        // Validasi bahwa semua pegawai milik unit admin
        $pegawais = MsPegawai::whereIn('id', $request->pegawai_ids)
            ->whereHas('unitDetailPresensi', function ($q) use ($unitId) {
                $q->where('unit_id', $unitId);
            })
            ->with(['shiftDetail'])
            ->get();

        if ($pegawais->count() !== count($request->pegawai_ids)) {
            return response()->json(['message' => 'Beberapa pegawai tidak ditemukan atau tidak memiliki akses'], 400);
        }

        $createdPresensi = [];
        $errors = [];

        // Generate tanggal range
        $start = Carbon::parse($request->tanggal_mulai);
        $end = Carbon::parse($request->tanggal_selesai);

        foreach ($pegawais as $pegawai) {
            // Loop setiap tanggal dalam range
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $tanggal = $date->format('Y-m-d');
                
                // Cek apakah sudah ada presensi di tanggal tersebut
                $existingPresensi = Presensi::where('no_ktp', $pegawai->no_ktp)
                    ->whereDate('waktu_masuk', $tanggal)
                    ->first();
                
                if ($existingPresensi) {
                    $errors[] = "Pegawai {$pegawai->nama} sudah memiliki presensi pada tanggal {$tanggal}";
                    continue;
                }

                // Ambil shift detail pegawai
                $shiftDetail = $pegawai->shiftDetail;
                if (!$shiftDetail) {
                    $errors[] = "Pegawai {$pegawai->nama} tidak memiliki shift detail";
                    continue;
                }

                // Tentukan waktu masuk dan pulang berdasarkan shift
                $waktuMasuk = $this->getWaktuMasukShift($shiftDetail, $date);
                $waktuPulang = $this->getWaktuPulangShift($shiftDetail, $date);

                if (!$waktuMasuk || !$waktuPulang) {
                    $errors[] = "Pegawai {$pegawai->nama} tidak memiliki jam kerja pada hari " . $date->locale('id')->isoFormat('dddd');
                    continue;
                }

                try {
                    $presensi = Presensi::create([
                        'no_ktp' => $pegawai->no_ktp,
                        'shift_id' => $shiftDetail->shift_id,
                        'shift_detail_id' => $shiftDetail->id,
                        'waktu_masuk' => $waktuMasuk,
                        'waktu_pulang' => $waktuPulang,
                        'status_masuk' => 'absen_masuk',
                        'status_pulang' => 'absen_pulang',
                        'lokasi_masuk' => null,
                        'lokasi_pulang' => null,
                        'keterangan_masuk' => $request->keterangan,
                        'keterangan_pulang' => $request->keterangan,
                        'status_presensi' => 'dinas',
                    ]);

                    $createdPresensi[] = [
                        'pegawai' => $pegawai->nama,
                        'tanggal' => $tanggal,
                        'presensi_id' => $presensi->id
                    ];
                } catch (\Exception $e) {
                    $errors[] = "Gagal membuat presensi dinas untuk pegawai {$pegawai->nama} pada tanggal {$tanggal}: " . $e->getMessage();
                }
            }
        }

        return response()->json([
            'message' => 'Proses pembuatan dinas selesai',
            'created_count' => count($createdPresensi),
            'error_count' => count($errors),
            'created_data' => $createdPresensi,
            'errors' => $errors
        ]);
    }

    /**
     * Get waktu masuk berdasarkan shift detail dan tanggal
     * 
     * @param ShiftDetail $shiftDetail
     * @param Carbon $date
     * @return Carbon|null
     */
    private function getWaktuMasukShift($shiftDetail, $date)
    {
        $hari = strtolower($date->locale('id')->isoFormat('dddd'));
        $masukKey = $hari . '_masuk';
        
        if (!$shiftDetail->$masukKey) {
            return null;
        }

        // Parse jam masuk dari shift (format H:i)
        $jamMasuk = Carbon::createFromFormat('H:i', $shiftDetail->$masukKey);
        
        // Kombinasikan dengan tanggal
        return $date->copy()->setTime($jamMasuk->hour, $jamMasuk->minute, 0);
    }

    /**
     * Get waktu pulang berdasarkan shift detail dan tanggal
     * 
     * @param ShiftDetail $shiftDetail
     * @param Carbon $date
     * @return Carbon|null
     */
    private function getWaktuPulangShift($shiftDetail, $date)
    {
        $hari = strtolower($date->locale('id')->isoFormat('dddd'));
        $pulangKey = $hari . '_pulang';
        
        if (!$shiftDetail->$pulangKey) {
            return null;
        }

        // Parse jam pulang dari shift (format H:i)
        $jamPulang = Carbon::createFromFormat('H:i', $shiftDetail->$pulangKey);
        
        // Kombinasikan dengan tanggal
        return $date->copy()->setTime($jamPulang->hour, $jamPulang->minute, 0);
    }

    /**
     * Get list dinas by unit
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        $bulan = $request->query('bulan', Carbon::now()->month);
        $tahun = $request->query('tahun', Carbon::now()->year);
        $pegawai_id = $request->query('pegawai_id');

        // Ambil semua pegawai di unit admin
        $pegawaiQuery = MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($unitId) {
            $q->where('unit_id', $unitId);
        });

        if ($pegawai_id) {
            $pegawaiQuery->where('id', $pegawai_id);
        }

        $pegawais = $pegawaiQuery->get(['id', 'no_ktp', 'nama']);
        $noKtps = $pegawais->pluck('no_ktp');

        // Ambil presensi dinas
        $start = Carbon::create($tahun, $bulan, 1, 0, 0, 0, 'Asia/Jakarta');
        $end = $start->copy()->endOfMonth();

        $presensiDinas = Presensi::whereIn('no_ktp', $noKtps)
            ->where('status_presensi', 'dinas')
            ->whereBetween('waktu_masuk', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
            ->with(['shiftDetail.shift'])
            ->orderBy('waktu_masuk')
            ->get();

        // Group by pegawai dan tanggal
        $result = [];
        $pegawaiMap = $pegawais->keyBy('no_ktp');

        foreach ($presensiDinas as $presensi) {
            $pegawai = $pegawaiMap[$presensi->no_ktp] ?? null;
            if (!$pegawai) continue;

            $tanggal = $presensi->waktu_masuk->format('Y-m-d');
            $key = $pegawai->id . '_' . $tanggal;

            if (!isset($result[$key])) {
                $result[$key] = [
                    'pegawai' => [
                        'id' => $pegawai->id,
                        'no_ktp' => $pegawai->no_ktp,
                        'nama' => $pegawai->nama,
                    ],
                    'tanggal' => $tanggal,
                    'hari' => $presensi->waktu_masuk->locale('id')->isoFormat('dddd'),
                    'waktu_masuk' => $presensi->waktu_masuk->format('H:i:s'),
                    'waktu_pulang' => $presensi->waktu_pulang ? $presensi->waktu_pulang->format('H:i:s') : null,
                    'keterangan' => $presensi->keterangan_masuk,
                    'shift_name' => $presensi->shiftDetail && $presensi->shiftDetail->shift ? $presensi->shiftDetail->shift->name : null,
                    'presensi_id' => $presensi->id,
                ];
            }
        }

        return response()->json(array_values($result));
    }

    /**
     * Delete dinas for specific date range and employees
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Get validation rules using helper
        $unitValidationRules = AdminUnitHelper::getUnitIdValidationRules($request);
        
        $request->validate(array_merge([
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
            'pegawai_ids' => 'required|array',
            'pegawai_ids.*' => 'exists:pegawai,id',
        ], $unitValidationRules));

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        // Validasi bahwa semua pegawai milik unit admin
        $pegawais = MsPegawai::whereIn('id', $request->pegawai_ids)
            ->whereHas('unitDetailPresensi', function ($q) use ($unitId) {
                $q->where('unit_id', $unitId);
            })
            ->get();

        if ($pegawais->count() !== count($request->pegawai_ids)) {
            return response()->json(['message' => 'Beberapa pegawai tidak ditemukan atau tidak memiliki akses'], 400);
        }

        $noKtps = $pegawais->pluck('no_ktp');
        $start = Carbon::parse($request->tanggal_mulai);
        $end = Carbon::parse($request->tanggal_selesai);

        // Hapus presensi dinas
        $deleted = Presensi::whereIn('no_ktp', $noKtps)
            ->where('status_presensi', 'dinas')
            ->whereBetween('waktu_masuk', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
            ->delete();

        return response()->json([
            'message' => 'Dinas berhasil dihapus',
            'deleted_count' => $deleted
        ]);
    }
}