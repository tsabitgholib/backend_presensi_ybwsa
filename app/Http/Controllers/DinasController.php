<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Presensi;
use App\Models\MsPegawai;
use App\Models\ShiftDetail;
use App\Models\PresensiJadwalDinas;
use Carbon\Carbon;
use App\Helpers\AdminUnitHelper;
use Illuminate\Support\Facades\Log;

class DinasController extends Controller
{
    /**
     * Create jadwal dinas for multiple employees
     * Sekarang hanya menyimpan jadwal dinas, tidak langsung membuat presensi
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
            'pegawai_ids.*' => 'exists:ms_pegawai,id',
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
                $q->where('presensi_ms_unit_detail_id', $unitId);
            })
            ->with(['orang'])
            ->get();

        if ($pegawais->count() !== count($request->pegawai_ids)) {
            return response()->json(['message' => 'Beberapa pegawai tidak ditemukan atau tidak memiliki akses'], 400);
        }

        try {
            // Simpan jadwal dinas ke tabel baru
            $jadwalDinas = PresensiJadwalDinas::create([
                'tanggal_mulai' => $request->tanggal_mulai,
                'tanggal_selesai' => $request->tanggal_selesai,
                'keterangan' => $request->keterangan,
                'pegawai_ids' => $request->pegawai_ids,
                'unit_id' => $unitId,
                'created_by' => $admin->id,
                'is_active' => true
            ]);

            return response()->json([
                'message' => 'Jadwal dinas berhasil dibuat',
                'jadwal_dinas_id' => $jadwalDinas->id,
                'tanggal_mulai' => $jadwalDinas->tanggal_mulai->format('Y-m-d'),
                'tanggal_selesai' => $jadwalDinas->tanggal_selesai->format('Y-m-d'),
                'keterangan' => $jadwalDinas->keterangan,
                'jumlah_pegawai' => count($request->pegawai_ids),
                'pegawai_list' => $pegawais->map(function ($pegawai) {
                    return [
                        'id' => $pegawai->id,
                        'nama' => $pegawai->orang->nama ?? $pegawai->nama,
                        'no_ktp' => $pegawai->orang->no_ktp ?? $pegawai->no_ktp
                    ];
                })
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating jadwal dinas: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal membuat jadwal dinas: ' . $e->getMessage()
            ], 500);
        }
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
        //dd($masukKey, $shiftDetail->$masukKey);
        $jamString = trim($shiftDetail->$masukKey ?? '');

        if (!$jamString) {
            return null; // tidak ada data jam
        }

        try {
            // Gunakan H:i karena di DB formatnya "08:00"
            $jamMasuk = Carbon::createFromFormat('H:i', $jamString);
            return $date->copy()->setTime($jamMasuk->hour, $jamMasuk->minute, 0);
        } catch (\Exception $e) {
            //Log::error("Format jam masuk tidak valid: {$jamString}");
            return null;
        }
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


        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        $bulan = $request->query('bulan', Carbon::now()->month);
        $tahun = $request->query('tahun', Carbon::now()->year);
        $pegawai_id = $request->query('pegawai_id');

        // Ambil semua pegawai di unit admin
        $pegawais = MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($unitId) {
            $q->where('presensi_ms_unit_detail_id', $unitId);
        })
            ->with('orang:id,no_ktp,nama')
            ->get(['id', 'id_orang']);
        $noKtps = $pegawais->pluck('orang.no_ktp');


        // Ambil jadwal dinas dari tabel baru
        $start = Carbon::create($tahun, $bulan, 1, 0, 0, 0, 'Asia/Jakarta');
        $end = $start->copy()->endOfMonth();

        $query = PresensiJadwalDinas::active()
            ->where('unit_id', $unitId)
            ->inDateRange($start->toDateString(), $end->toDateString())
            ->with(['unit', 'createdBy'])
            ->orderBy('tanggal_mulai');

        // Filter by pegawai jika ada
        if ($pegawai_id) {
            $query->whereJsonContains('pegawai_ids', $pegawai_id);
        }

        $jadwalDinas = $query->get();

        // Ambil data pegawai untuk mapping
        $pegawaiIds = collect();
        foreach ($jadwalDinas as $jadwal) {
            $pegawaiIds = $pegawaiIds->merge($jadwal->pegawai_ids);
        }
        $pegawaiIds = $pegawaiIds->unique();

        $pegawais = MsPegawai::whereIn('id', $pegawaiIds)
            ->with(['orang'])
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($jadwalDinas as $jadwal) {
            $pegawaiList = [];
            foreach ($jadwal->pegawai_ids as $pegawaiId) {
                $pegawai = $pegawais->get($pegawaiId);
                if ($pegawai) {
                    $pegawaiList[] = [
                        'id' => $pegawai->id,
                        'nama' => $pegawai->orang->nama ?? $pegawai->nama,
                        'no_ktp' => $pegawai->orang->no_ktp ?? $pegawai->no_ktp
                    ];
                }
            }

            $result[] = [
                'id' => $jadwal->id,
                'tanggal_mulai' => $jadwal->tanggal_mulai->format('Y-m-d'),
                'tanggal_selesai' => $jadwal->tanggal_selesai->format('Y-m-d'),
                'keterangan' => $jadwal->keterangan,
                'unit' => $jadwal->unit ? $jadwal->unit->nama : null,
                'created_by' => $jadwal->createdBy ? $jadwal->createdBy->name : null,
                'created_at' => $jadwal->created_at->format('Y-m-d H:i:s'),
                'pegawai_list' => $pegawaiList,
                'jumlah_pegawai' => count($pegawaiList)
            ];
        }

        return response()->json($result);
    }

    public function update(Request $request, $id)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Cari jadwal dinas
        $jadwalDinas = PresensiJadwalDinas::find($id);
        if (!$jadwalDinas) {
            return response()->json(['message' => 'Jadwal dinas tidak ditemukan'], 404);
        }

        // Get validation rules using helper
        $unitValidationRules = AdminUnitHelper::getUnitIdValidationRules($request);

        $request->validate(array_merge([
            'tanggal_mulai'   => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
            'keterangan'      => 'required|string|max:255',
            'pegawai_ids'     => 'required|array',
            'pegawai_ids.*'   => 'exists:ms_pegawai,id',
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
                $q->where('presensi_ms_unit_detail_id', $unitId);
            })
            ->with(['orang'])
            ->get();

        if ($pegawais->count() !== count($request->pegawai_ids)) {
            return response()->json(['message' => 'Beberapa pegawai tidak ditemukan atau tidak memiliki akses'], 400);
        }

        try {
            // Update jadwal dinas
            $jadwalDinas->update([
                'tanggal_mulai'   => $request->tanggal_mulai,
                'tanggal_selesai' => $request->tanggal_selesai,
                'keterangan'      => $request->keterangan,
                'pegawai_ids'     => $request->pegawai_ids,
                'unit_id'         => $unitId,
                'updated_by'      => $admin->id,
            ]);

            return response()->json([
                'message'        => 'Jadwal dinas berhasil diperbarui',
                'jadwal_dinas_id' => $jadwalDinas->id,
                'tanggal_mulai'  => $jadwalDinas->tanggal_mulai->format('Y-m-d'),
                'tanggal_selesai' => $jadwalDinas->tanggal_selesai->format('Y-m-d'),
                'keterangan'     => $jadwalDinas->keterangan,
                'jumlah_pegawai' => count($request->pegawai_ids),
                'pegawai_list'   => $pegawais->map(function ($pegawai) {
                    return [
                        'id'     => $pegawai->id,
                        'nama'   => $pegawai->orang->nama ?? $pegawai->nama,
                        'no_ktp' => $pegawai->orang->no_ktp ?? $pegawai->no_ktp
                    ];
                })
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating jadwal dinas: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal memperbarui jadwal dinas: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Delete dinas for specific date range and employees
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, $jadwal_dinas_id)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        // Cari jadwal dinas yang akan dihapus
        $jadwalDinas = PresensiJadwalDinas::where('id', $jadwal_dinas_id)
            ->where('unit_id', $unitId)
            ->first();

        if (!$jadwalDinas) {
            return response()->json(['message' => 'Jadwal dinas tidak ditemukan atau tidak memiliki akses'], 404);
        }

        try {
            // Hapus permanen
            $jadwalDinas->delete();

            return response()->json([
                'message' => 'Jadwal dinas berhasil dihapus',
                'jadwal_dinas_id' => $jadwal_dinas_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting jadwal dinas: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal menghapus jadwal dinas: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get list presensi dinas yang sudah dilakukan (bukan jadwal dinas)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function presensiDinas(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }


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
            $q->where('ms_unit_id', $unitId);
        });

        if ($pegawai_id) {
            $pegawaiQuery->where('id', $pegawai_id);
        }

        $pegawais = $pegawaiQuery->with(['orang'])->get();
        $noKtps = $pegawais->pluck('orang.no_ktp')->filter();

        // Ambil presensi dinas yang sudah dilakukan
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
        $pegawaiMap = $pegawais->keyBy('orang.no_ktp');

        foreach ($presensiDinas as $presensi) {
            $pegawai = $pegawaiMap[$presensi->no_ktp] ?? null;
            if (!$pegawai) continue;

            $tanggal = $presensi->waktu_masuk->format('Y-m-d');
            $key = $pegawai->id . '_' . $tanggal;

            if (!isset($result[$key])) {
                $result[$key] = [
                    'pegawai' => [
                        'id' => $pegawai->id,
                        'no_ktp' => $pegawai->orang->no_ktp,
                        'nama' => $pegawai->orang->nama,
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
}
