<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\HariLibur;
use App\Models\UnitDetail;
use Carbon\Carbon;
use App\Helpers\AdminUnitHelper;

class HariLiburController extends Controller
{
    /**
     * Tampilkan daftar hari libur untuk unit detail tertentu
     */
    /**
     * Tampilkan daftar hari libur berdasarkan admin unit yang login
     */
    public function index(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        $bulan = $request->query('bulan');
        $tahun = $request->query('tahun');

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        // Ambil semua unit detail dari unit admin yang login
        $unitDetails = UnitDetail::where('ms_unit_id', $unitId)->get();
        $unitDetailIds = $unitDetails->pluck('ms_unit_id'); // pakai id unit_detail

        $hariLibur = HariLibur::whereIn('unit_detail_id', $unitDetailIds)
            ->when($tahun && $bulan, function ($query) use ($tahun, $bulan) {
                return $query->whereYear('tanggal', $tahun)
                    ->whereMonth('tanggal', $bulan);
            })
            ->with(['unitDetail.unit'])
            ->orderBy('tanggal')
            ->get();


        // Ubah response: tambah unit_detail_id
        $result = $hariLibur->map(function ($hl) {
            return [
                'id' => $hl->id,
                'unit_detail_id' => $hl->unit_detail_id,
                'tanggal' => $hl->tanggal->format('Y-m-d'),
                'keterangan' => $hl->keterangan,
                'unit_name' => $hl->unitDetail && $hl->unitDetail->unit ? $hl->unitDetail->unit->name : null,
                'unit_detail_name' => $hl->unitDetail ? $hl->unitDetail->name : null,
            ];
        });
        return response()->json($result);
    }

    /**
     * Tambah hari libur baru
     */
    public function store(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Get validation rules using helper
        $unitDetailValidationRules = AdminUnitHelper::getUnitIdValidationRules($request, 'unit_detail_id');

        $request->validate(array_merge([
            'unit_detail_id' => 'required|exists:ms_unit,id',
            'tanggal' => 'required|date',
            'keterangan' => 'required|string|max:255',
        ], $unitDetailValidationRules));

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        // Validasi bahwa unit detail milik unit admin
        $unitDetail = UnitDetail::where('ms_unit_id', $unitId)
            ->first();

        if (!$unitDetail) {
            return response()->json(['message' => 'Unit detail tidak ditemukan'], 404);
        }

        // Cek apakah sudah ada hari libur untuk tanggal dan unit detail yang sama
        $existingHariLibur = HariLibur::where('unit_detail_id', $request->unit_detail_id)
            ->whereDate('tanggal', $request->tanggal)
            ->first();

        if ($existingHariLibur) {
            return response()->json(['message' => 'Hari libur untuk tanggal ini sudah ada'], 400);
        }

        try {
            $hariLibur = HariLibur::create([
                'unit_detail_id' => $unitId,
                'tanggal' => $request->tanggal,
                'keterangan' => $request->keterangan,
                'admin_unit_id' => $admin->id,
            ]);

            $hariLibur->load(['unitDetail', 'adminUnit']);

            return response()->json([
                'message' => 'Hari libur berhasil ditambahkan',
                'data' => $hariLibur
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menambahkan hari libur: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Tambah hari libur untuk multiple unit detail
     */
    public function storeMultiple(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Get validation rules using helper
        $unitDetailIdsValidationRules = AdminUnitHelper::getUnitDetailIdsValidationRules($request);

        $request->validate(array_merge([
            'unit_detail_ids' => 'required|array',
            //'unit_detail_ids.*' => 'exists:presensi_ms_unit_detail,id',
            'tanggal' => 'required|date',
            'keterangan' => 'required|string|max:255',
        ], $unitDetailIdsValidationRules));

        // Get unit_detail_ids using helper
        $unitDetailIdsResult = AdminUnitHelper::getUnitDetailIds($request);
        if ($unitDetailIdsResult['error']) {
            return response()->json(['message' => $unitDetailIdsResult['error']], 400);
        }
        $unitDetailIds = $unitDetailIdsResult['unit_detail_ids'];

        // Validasi bahwa semua unit detail milik unit admin
        $unitDetails = UnitDetail::whereIn('ms_unit_id', $unitDetailIds)->get();

        if ($unitDetails->count() !== count($unitDetailIds)) {
            return response()->json(['message' => 'Beberapa unit detail tidak ditemukan atau tidak memiliki akses'], 400);
        }

        $createdHariLibur = [];
        $errors = [];

        foreach ($unitDetailIds as $unitDetailId) {
            // Cek apakah sudah ada hari libur untuk tanggal dan unit detail yang sama
            $existingHariLibur = HariLibur::where('unit_detail_id', $unitDetailId)
                ->whereDate('tanggal', $request->tanggal)
                ->first();

            if ($existingHariLibur) {
                $errors[] = "Hari libur untuk unit detail ID {$unitDetailId} pada tanggal {$request->tanggal} sudah ada";
                continue;
            }

            try {
                $hariLibur = HariLibur::create([
                    'unit_detail_id' => $unitDetailId,
                    'tanggal' => $request->tanggal,
                    'keterangan' => $request->keterangan,
                    'admin_unit_id' => $admin->id,
                ]);
                $createdHariLibur[] = $hariLibur;
            } catch (\Exception $e) {
                $errors[] = "Gagal menambahkan hari libur untuk unit detail ID {$unitDetailId}: " . $e->getMessage();
            }
        }

        return response()->json([
            'message' => 'Proses penambahan sukses',
            'created_count' => count($createdHariLibur),
            'error_count' => count($errors),
            'created_data' => $createdHariLibur,
            'errors' => $errors
        ]);
    }

    /**
     * Update hari libur untuk multiple unit detail
     */
    public function updateMultiple(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Get validation rules using helper
        $unitDetailIdsValidationRules = AdminUnitHelper::getUnitDetailIdsValidationRules($request);

        $request->validate(array_merge([
            'unit_detail_ids' => 'required|array',
            'unit_detail_ids.*' => 'exists:presensi_ms_unit_detail, ms_unit_id',
            'tanggal' => 'required|date',
            'keterangan' => 'required|string|max:255',
        ], $unitDetailIdsValidationRules));

        // Get unit_detail_ids using helper
        $unitDetailIdsResult = AdminUnitHelper::getUnitDetailIds($request);
        if ($unitDetailIdsResult['error']) {
            return response()->json(['message' => $unitDetailIdsResult['error']], 400);
        }
        $unitDetailIds = $unitDetailIdsResult['unit_detail_ids'];

        // Validasi bahwa semua unit detail milik unit admin
        $unitDetails = UnitDetail::whereIn('id', $unitDetailIds)->get();
        if ($unitDetails->count() !== count($unitDetailIds)) {
            return response()->json(['message' => 'Beberapa unit detail tidak ditemukan atau tidak memiliki akses'], 400);
        }

        $updated = HariLibur::whereIn('unit_detail_id', $unitDetailIds)
            ->whereDate('tanggal', $request->tanggal)
            ->update(['keterangan' => $request->keterangan]);

        return response()->json([
            'message' => 'Update sukses',
            'updated_count' => $updated
        ]);
    }

    /**
     * Delete hari libur untuk multiple unit detail
     */
    public function deleteMultiple(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Get validation rules using helper
        $unitDetailIdsValidationRules = AdminUnitHelper::getUnitDetailIdsValidationRules($request);

        $request->validate(array_merge([
            'unit_detail_ids' => 'required|array',
            'unit_detail_ids.*' => 'exists:presensi_ms_unit_detail,ms_unit_id',
            'tanggal' => 'required|date',
        ], $unitDetailIdsValidationRules));

        // Get unit_detail_ids using helper
        $unitDetailIdsResult = AdminUnitHelper::getUnitDetailIds($request);
        if ($unitDetailIdsResult['error']) {
            return response()->json(['message' => $unitDetailIdsResult['error']], 400);
        }
        $unitDetailIds = $unitDetailIdsResult['unit_detail_ids'];

        // Validasi bahwa semua unit detail milik unit admin
        $unitDetails = UnitDetail::whereIn('id', $unitDetailIds)->get();
        if ($unitDetails->count() !== count($unitDetailIds)) {
            return response()->json(['message' => 'Beberapa unit detail tidak ditemukan atau tidak memiliki akses'], 400);
        }

        return response()->json([
            'message' => 'Delete sukses',
        ]);
    }
}
