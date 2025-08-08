# Dokumentasi Status Presensi yang Standar

## Status Masuk (status_masuk)

-   **absen_masuk**: Absen masuk tepat waktu
-   **terlambat**: Terlambat absen masuk
-   **tidak_absen_masuk**: Tidak absen masuk
-   **tidak_hadir**: Tidak hadir
-   **izin**: Izin
-   **sakit**: Sakit
-   **cuti**: Cuti

## Status Pulang (status_pulang)

-   **absen_pulang**: Absen pulang tepat waktu
-   **pulang_awal**: Pulang sebelum waktu pulang
-   **tidak_absen_pulang**: Tidak absen pulang
-   **tidak_hadir**: Tidak hadir
-   **izin**: Izin
-   **sakit**: Sakit
-   **cuti**: Cuti

## Status Presensi (status_presensi) - Final

-   **hadir**: Hadir (dihitung dari status masuk/pulang yang hadir)
-   **tidak_hadir**: Tidak hadir
-   **sakit**: Sakit
-   **izin**: Izin
-   **cuti**: Cuti

## Logika Perhitungan Status Presensi

### Status yang dianggap HADIR:

-   `absen_masuk`
-   `terlambat`
-   `absen_pulang`
-   `pulang_awal`

### Status Khusus (tidak dihitung sebagai hadir):

-   `izin`
-   `sakit`
-   `cuti`

### Aturan:

1. Jika ada status khusus (izin, sakit, cuti) di status_masuk atau status_pulang, maka status_presensi = status khusus tersebut
2. Jika salah satu status masuk/pulang adalah hadir, maka status_presensi = 'hadir'
3. Jika tidak ada status hadir dan tidak ada status khusus, maka status_presensi = 'tidak_hadir'

## Contoh Penggunaan API

### Update Presensi dengan Format Jam

```json
{
    "updates": [
        {
            "waktu_masuk": "08:30",
            "waktu_pulang": "17:00",
            "status_masuk": "absen_masuk",
            "status_pulang": "absen_pulang"
        }
    ]
}
```

### Update Presensi dengan Format DateTime Lengkap

```json
{
    "updates": [
        {
            "waktu_masuk": "2024-01-15 08:30:00",
            "waktu_pulang": "2024-01-15 17:00:00",
            "status_masuk": "absen_masuk",
            "status_pulang": "absen_pulang"
        }
    ]
}
```

## Endpoint yang Mendukung Format Jam

### PUT /api/presensi/update-bulk/{pegawai_id}/{tanggal}

**Parameter:**

-   `pegawai_id`: ID pegawai
-   `tanggal`: Format YYYY-MM-DD

**Request Body:**

```json
{
    "updates": [
        {
            "waktu_masuk": "08:30", // Format jam (HH:mm)
            "waktu_pulang": "17:00", // Format jam (HH:mm)
            "status_masuk": "absen_masuk",
            "status_pulang": "absen_pulang",
            "lokasi_masuk": "Kantor Pusat",
            "lokasi_pulang": "Kantor Pusat",
            "keterangan_masuk": "Keterangan masuk",
            "keterangan_pulang": "Keterangan pulang"
        }
    ]
}
```

**Validasi:**

-   Format jam harus HH:mm (contoh: 08:30, 17:00)
-   Waktu pulang harus setelah waktu masuk
-   Format tanggal harus YYYY-MM-DD

**Response:**

```json
{
  "message": "Presensi berhasil diupdate",
  "updated": [...]
}
```

## Migrasi dari Status Lama

### Status yang Diubah:

-   `tidak_masuk` → `tidak_absen_masuk`
-   `hadir` (untuk status_masuk) → `absen_masuk`
-   `hadir` (untuk status_pulang) → `absen_pulang`

### Status yang Tetap:

-   `terlambat`
-   `pulang_awal`
-   `tidak_absen_pulang`
-   `izin`
-   `sakit`
-   `cuti`
-   `tidak_hadir`
-   `hadir` (untuk status_presensi)
