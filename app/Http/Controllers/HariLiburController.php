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
            ->with(['unitDetail', 'adminUnit'])
            ->orderBy('tanggal')
            ->get();

        return response()->json($hariLibur);
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
     * Update hari libur
     */
    public function update(Request $request, $id)
    {
        $admin = $request->get('admin');
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }

        $hariLibur = HariLibur::with('unitDetail')->find($id);
        if (!$hariLibur) {
            return response()->json(['message' => 'Hari libur tidak ditemukan'], 404);
        }

        // Validasi bahwa hari libur milik unit admin
        if ($hariLibur->unitDetail->unit_id !== $admin->unit_id) {
            return response()->json(['message' => 'Tidak memiliki akses ke hari libur ini'], 403);
        }

        $request->validate([
            'tanggal' => 'sometimes|required|date',
            'keterangan' => 'sometimes|required|string|max:255',
        ]);

        try {
            $hariLibur->update($request->only(['tanggal', 'keterangan']));
            $hariLibur->load(['unitDetail', 'adminUnit']);

            return response()->json([
                'message' => 'Hari libur berhasil diupdate',
                'data' => $hariLibur
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal mengupdate hari libur: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Hapus hari libur
     */
    public function destroy(Request $request, $id)
    {
        $admin = $request->get('admin');
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }

        $hariLibur = HariLibur::with('unitDetail')->find($id);
        if (!$hariLibur) {
            return response()->json(['message' => 'Hari libur tidak ditemukan'], 404);
        }

        // Validasi bahwa hari libur milik unit admin
        if ($hariLibur->unitDetail->unit_id !== $admin->unit_id) {
            return response()->json(['message' => 'Tidak memiliki akses ke hari libur ini'], 403);
        }

        try {
            $hariLibur->delete();
            return response()->json(['message' => 'Hari libur berhasil dihapus']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menghapus hari libur: ' . $e->getMessage()], 400);
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
}
