<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\HariLibur;
use App\Models\UnitDetail;
use Carbon\Carbon;

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
        // if (!$admin || $admin->role !== 'admin_unit') {
        //     return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        // }

        $bulan = $request->query('bulan', Carbon::now()->month);
        $tahun = $request->query('tahun', Carbon::now()->year);

        // Ambil semua unit detail dari unit admin yang login
        $unitDetails = UnitDetail::where('unit_id', $admin->unit_id)->get();
        $unitDetailIds = $unitDetails->pluck('id');

        $hariLibur = HariLibur::whereIn('unit_detail_id', $unitDetailIds)
            ->whereYear('tanggal', $tahun)
            ->whereMonth('tanggal', $bulan)
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
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }

        $request->validate([
            'unit_detail_id' => 'required|exists:unit_detail,id',
            'tanggal' => 'required|date',
            'keterangan' => 'required|string|max:255',
        ]);

        // Validasi bahwa unit detail milik unit admin
        $unitDetail = UnitDetail::where('id', $request->unit_detail_id)
            ->where('unit_id', $admin->unit_id)
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
                'unit_detail_id' => $request->unit_detail_id,
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
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }

        $request->validate([
            'unit_detail_ids' => 'required|array',
            'unit_detail_ids.*' => 'exists:unit_detail,id',
            'tanggal' => 'required|date',
            'keterangan' => 'required|string|max:255',
        ]);

        // Validasi bahwa semua unit detail milik unit admin
        $unitDetails = UnitDetail::whereIn('id', $request->unit_detail_ids)
            ->where('unit_id', $admin->unit_id)
            ->get();

        if ($unitDetails->count() !== count($request->unit_detail_ids)) {
            return response()->json(['message' => 'Beberapa unit detail tidak ditemukan atau tidak memiliki akses'], 400);
        }

        $createdHariLibur = [];
        $errors = [];

        foreach ($request->unit_detail_ids as $unitDetailId) {
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
            'message' => 'Proses penambahan hari libur selesai',
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
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }

        $request->validate([
            'unit_detail_ids' => 'required|array',
            'unit_detail_ids.*' => 'exists:unit_detail,id',
            'tanggal' => 'required|date',
            'keterangan' => 'required|string|max:255',
        ]);

        // Validasi bahwa semua unit detail milik unit admin
        $unitDetails = UnitDetail::whereIn('id', $request->unit_detail_ids)
            ->where('unit_id', $admin->unit_id)
            ->get();
        if ($unitDetails->count() !== count($request->unit_detail_ids)) {
            return response()->json(['message' => 'Beberapa unit detail tidak ditemukan atau tidak memiliki akses'], 400);
        }

        $updated = HariLibur::whereIn('unit_detail_id', $request->unit_detail_ids)
            ->whereDate('tanggal', $request->tanggal)
            ->update(['keterangan' => $request->keterangan]);

        return response()->json([
            'message' => 'Update hari libur selesai',
            'updated_count' => $updated
        ]);
    }

    /**
     * Delete hari libur untuk multiple unit detail
     */
    public function deleteMultiple(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }

        $request->validate([
            'unit_detail_ids' => 'required|array',
            'unit_detail_ids.*' => 'exists:unit_detail,id',
            'tanggal' => 'required|date',
        ]);

        // Validasi bahwa semua unit detail milik unit admin
        $unitDetails = UnitDetail::whereIn('id', $request->unit_detail_ids)
            ->where('unit_id', $admin->unit_id)
            ->get();
        if ($unitDetails->count() !== count($request->unit_detail_ids)) {
            return response()->json(['message' => 'Beberapa unit detail tidak ditemukan atau tidak memiliki akses'], 400);
        }

        $deleted = HariLibur::whereIn('unit_detail_id', $request->unit_detail_ids)
            ->whereDate('tanggal', $request->tanggal)
            ->delete();

        return response()->json([
            'message' => 'Delete hari libur selesai',
            'deleted_count' => $deleted
        ]);
    }
}
