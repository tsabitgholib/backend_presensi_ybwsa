# Changelog - Standarisasi Status Presensi

## Versi 1.0.0 - Standarisasi Status Presensi

### Perubahan Utama

#### 1. Standarisasi Status Masuk (status_masuk)

**Status Lama:**

-   `hadir` → **`absen_masuk`**
-   `tidak_masuk` → **`tidak_absen_masuk`**

**Status Baru yang Standar:**

-   `absen_masuk`: Absen masuk tepat waktu
-   `terlambat`: Terlambat absen masuk
-   `tidak_absen_masuk`: Tidak absen masuk
-   `tidak_hadir`: Tidak hadir
-   `izin`: Izin
-   `sakit`: Sakit
-   `cuti`: Cuti

#### 2. Standarisasi Status Pulang (status_pulang)

**Status Lama:**

-   `hadir` → **`absen_pulang`**

**Status Baru yang Standar:**

-   `absen_pulang`: Absen pulang tepat waktu
-   `pulang_awal`: Pulang sebelum waktu pulang
-   `tidak_absen_pulang`: Tidak absen pulang
-   `tidak_hadir`: Tidak hadir
-   `izin`: Izin
-   `sakit`: Sakit
-   `cuti`: Cuti

#### 3. Status Presensi (status_presensi) - Final

**Status yang Tetap:**

-   `hadir`: Hadir (dihitung dari status masuk/pulang yang hadir)
-   `tidak_hadir`: Tidak hadir
-   `sakit`: Sakit
-   `izin`: Izin
-   `cuti`: Cuti

### Fitur Baru

#### 1. Dukungan Input Format Jam

Method `updatePresensiByAdminUnitBulk` sekarang mendukung input waktu dalam format jam saja:

```json
{
    "updates": [
        {
            "waktu_masuk": "08:30", // Format jam (HH:mm)
            "waktu_pulang": "17:00", // Format jam (HH:mm)
            "status_masuk": "absen_masuk",
            "status_pulang": "absen_pulang"
        }
    ]
}
```

#### 2. Validasi Status yang Diperketat

-   Validasi format jam (HH:mm)
-   Validasi status masuk dan pulang sesuai standar
-   Validasi logika waktu (waktu pulang harus setelah waktu masuk)

#### 3. Konstanta Status di Model

Model `Presensi` sekarang memiliki konstanta untuk semua status:

```php
// Status Masuk
Presensi::STATUS_MASUK_ABSEN_MASUK
Presensi::STATUS_MASUK_TERLAMBAT
Presensi::STATUS_MASUK_TIDAK_ABSEN_MASUK
// ... dst

// Status Pulang
Presensi::STATUS_PULANG_ABSEN_PULANG
Presensi::STATUS_PULANG_PULANG_AWAL
// ... dst

// Status Presensi
Presensi::STATUS_PRESENSI_HADIR
Presensi::STATUS_PRESENSI_TIDAK_HADIR
// ... dst
```

### Perubahan File

#### 1. `app/Http/Controllers/PresensiController.php`

-   ✅ Menambahkan dukungan input format jam di `updatePresensiByAdminUnitBulk`
-   ✅ Mengupdate method `calculateFinalStatus` untuk menggunakan konstanta model
-   ✅ Menambahkan validasi status yang diperketat
-   ✅ Mengupdate dokumentasi API
-   ✅ Menambahkan komentar dokumentasi status standar

#### 2. `app/Models/Presensi.php`

-   ✅ Menambahkan konstanta untuk semua status
-   ✅ Menambahkan method validasi status
-   ✅ Menambahkan method helper untuk status hadir dan khusus

#### 3. Dokumentasi

-   ✅ `API_DOCUMENTATION_STATUS_PRESENSI.md`: Dokumentasi lengkap status standar
-   ✅ `CHANGELOG_STATUS_PRESENSI.md`: Changelog perubahan

### Logika Perhitungan Status Presensi

#### Status yang dianggap HADIR:

-   `absen_masuk`
-   `terlambat`
-   `absen_pulang`
-   `pulang_awal`

#### Status Khusus (tidak dihitung sebagai hadir):

-   `izin`
-   `sakit`
-   `cuti`

#### Aturan Perhitungan:

1. Jika ada status khusus (izin, sakit, cuti) di status_masuk atau status_pulang, maka status_presensi = status khusus tersebut
2. Jika salah satu status masuk/pulang adalah hadir, maka status_presensi = 'hadir'
3. Jika tidak ada status hadir dan tidak ada status khusus, maka status_presensi = 'tidak_hadir'

### Breaking Changes

#### 1. Status yang Berubah

-   `tidak_masuk` → `tidak_absen_masuk`
-   `hadir` (untuk status_masuk) → `absen_masuk`
-   `hadir` (untuk status_pulang) → `absen_pulang`

#### 2. API Response

Response API sekarang menggunakan status yang standar. Pastikan frontend diupdate untuk menangani status baru.

### Migration Guide

#### Untuk Frontend:

1. Update semua referensi status lama ke status baru
2. Update validasi input untuk menggunakan status yang standar
3. Update tampilan untuk menampilkan status yang benar

#### Untuk Database:

1. Jalankan migration yang sudah ada
2. Update data existing jika diperlukan (opsional)

### Testing

#### Test Cases yang Perlu Ditambahkan:

1. ✅ Validasi format jam (HH:mm)
2. ✅ Validasi status masuk dan pulang
3. ✅ Validasi logika waktu
4. ✅ Test perhitungan status presensi
5. ✅ Test backward compatibility

### Keamanan

#### Validasi yang Ditambahkan:

-   ✅ Validasi format jam dengan regex
-   ✅ Validasi status menggunakan konstanta model
-   ✅ Validasi logika waktu
-   ✅ Sanitasi input

### Performance

#### Optimisasi yang Dilakukan:

-   ✅ Menggunakan konstanta untuk menghindari magic string
-   ✅ Validasi early return untuk menghemat resource
-   ✅ Menggunakan method helper untuk reusability

### Dokumentasi API

Dokumentasi lengkap tersedia di:

-   `API_DOCUMENTATION_STATUS_PRESENSI.md`
-   Komentar di dalam kode
-   Changelog ini

### Support

Untuk pertanyaan atau masalah terkait standarisasi status presensi, silakan hubungi tim development.
