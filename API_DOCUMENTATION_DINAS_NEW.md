# API Documentation - Fitur Dinas (Alur Baru)

## Overview

Fitur dinas telah diubah untuk memisahkan antara **jadwal dinas** dan **presensi dinas**. Sekarang admin atau admin unit dapat:

1. **Membuat jadwal dinas** - Menandai pegawai yang akan dinas pada tanggal tertentu
2. **Pegawai tetap melakukan presensi normal** - Pegawai melakukan presensi seperti biasa
3. **Status presensi otomatis menjadi dinas** - Jika ada jadwal dinas, status presensi otomatis berubah menjadi "dinas"

## Endpoints

### 1. Create Jadwal Dinas

**POST** `/api/dinas/create`

Membuat jadwal dinas untuk multiple pegawai dalam rentang tanggal tertentu. **TIDAK langsung membuat presensi**.

#### Request Body

```json
{
    "unit_id": 1, // Wajib untuk super admin, opsional untuk admin unit
    "tanggal_mulai": "2024-01-15", // Format: YYYY-MM-DD
    "tanggal_selesai": "2024-01-17", // Format: YYYY-MM-DD
    "keterangan": "Dinas ke Jakarta",
    "pegawai_ids": [1, 2, 3] // Array ID pegawai
}
```

#### Response Success (200)

```json
{
    "message": "Jadwal dinas berhasil dibuat",
    "jadwal_dinas_id": 123,
    "tanggal_mulai": "2024-01-15",
    "tanggal_selesai": "2024-01-17",
    "keterangan": "Dinas ke Jakarta",
    "jumlah_pegawai": 3,
    "pegawai_list": [
        {
            "id": 1,
            "nama": "John Doe",
            "no_ktp": "1234567890123456"
        },
        {
            "id": 2,
            "nama": "Jane Smith",
            "no_ktp": "1234567890123457"
        }
    ]
}
```

### 2. Get List Jadwal Dinas

**GET** `/api/dinas`

Mengambil daftar jadwal dinas (belum dilakukan presensi).

#### Query Parameters

-   `unit_id` (optional): ID unit (wajib untuk super admin)
-   `bulan` (optional): Bulan (1-12), default: bulan sekarang
-   `tahun` (optional): Tahun, default: tahun sekarang
-   `pegawai_id` (optional): Filter by pegawai ID

#### Example Request

```
GET /api/dinas?unit_id=1&bulan=1&tahun=2024&pegawai_id=5
```

#### Response Success (200)

```json
[
    {
        "id": 123,
        "tanggal_mulai": "2024-01-15",
        "tanggal_selesai": "2024-01-17",
        "keterangan": "Dinas ke Jakarta",
        "unit": "Unit A",
        "created_by": "Admin Name",
        "created_at": "2024-01-10 10:00:00",
        "pegawai_list": [
            {
                "id": 1,
                "nama": "John Doe",
                "no_ktp": "1234567890123456"
            }
        ],
        "jumlah_pegawai": 1
    }
]
```

### 3. Get List Presensi Dinas

**GET** `/api/dinas/presensi`

Mengambil daftar presensi dinas yang sudah dilakukan (pegawai sudah presensi dengan status dinas).

#### Query Parameters

-   `unit_id` (optional): ID unit (wajib untuk super admin)
-   `bulan` (optional): Bulan (1-12), default: bulan sekarang
-   `tahun` (optional): Tahun, default: tahun sekarang
-   `pegawai_id` (optional): Filter by pegawai ID

#### Example Request

```
GET /api/dinas/presensi?unit_id=1&bulan=1&tahun=2024&pegawai_id=5
```

#### Response Success (200)

```json
[
    {
        "pegawai": {
            "id": 1,
            "no_ktp": "1234567890123456",
            "nama": "John Doe"
        },
        "tanggal": "2024-01-15",
        "hari": "Senin",
        "waktu_masuk": "08:00:00",
        "waktu_pulang": "17:00:00",
        "keterangan": "Dinas ke Jakarta",
        "shift_name": "Shift Pagi",
        "presensi_id": 456
    }
]
```

### 4. Delete Jadwal Dinas

**DELETE** `/api/dinas/delete`

Menghapus jadwal dinas (soft delete dengan mengubah is_active menjadi false).

#### Request Body

```json
{
    "unit_id": 1, // Wajib untuk super admin, opsional untuk admin unit
    "jadwal_dinas_id": 123 // ID jadwal dinas yang akan dihapus
}
```

#### Response Success (200)

```json
{
    "message": "Jadwal dinas berhasil dihapus",
    "jadwal_dinas_id": 123,
    "tanggal_mulai": "2024-01-15",
    "tanggal_selesai": "2024-01-17"
}
```

## Alur Kerja Baru

### 1. Admin/Admin Unit Membuat Jadwal Dinas

```bash
POST /api/dinas/create
{
    "tanggal_mulai": "2024-01-15",
    "tanggal_selesai": "2024-01-17",
    "keterangan": "Dinas ke Jakarta",
    "pegawai_ids": [1, 2, 3]
}
```

**Hasil:** Jadwal dinas tersimpan di tabel `presensi_jadwal_dinas`, **TIDAK ada presensi yang dibuat**.

### 2. Pegawai Melakukan Presensi Normal

Pegawai melakukan presensi seperti biasa menggunakan endpoint presensi yang sudah ada:

```bash
POST /api/presensi
{
    "lokasi": [lat, lng]
}
```

**Sistem otomatis:**

-   Mengecek apakah pegawai memiliki jadwal dinas pada tanggal tersebut
-   Jika ada jadwal dinas, status presensi otomatis menjadi "dinas"
-   Keterangan presensi menggunakan keterangan dari jadwal dinas

### 3. Melihat Jadwal vs Presensi Dinas

**Jadwal Dinas (belum presensi):**

```bash
GET /api/dinas?bulan=1&tahun=2024
```

**Presensi Dinas (sudah presensi):**

```bash
GET /api/dinas/presensi?bulan=1&tahun=2024
```

## Perbedaan dengan Alur Lama

| Aspek                | Alur Lama                                     | Alur Baru                                   |
| -------------------- | --------------------------------------------- | ------------------------------------------- |
| **Pembuatan Dinas**  | Langsung membuat presensi dengan status dinas | Hanya membuat jadwal dinas                  |
| **Presensi Pegawai** | Tidak perlu presensi (sudah otomatis dinas)   | Tetap harus presensi normal                 |
| **Status Presensi**  | Langsung dinas tanpa presensi                 | Status otomatis dinas setelah presensi      |
| **Fleksibilitas**    | Kaku, tidak bisa mengubah waktu presensi      | Fleksibel, pegawai bisa presensi kapan saja |
| **Tracking**         | Sulit tracking presensi vs jadwal             | Jelas pemisahan jadwal vs presensi          |

## Database Schema

### Tabel Baru: `presensi_jadwal_dinas`

```sql
CREATE TABLE presensi_jadwal_dinas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NOT NULL,
    keterangan TEXT NOT NULL,
    pegawai_ids JSON NOT NULL,
    unit_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (unit_id) REFERENCES ms_unit(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES admin(id) ON DELETE CASCADE,

    INDEX idx_tanggal (tanggal_mulai, tanggal_selesai),
    INDEX idx_unit_active (unit_id, is_active)
);
```

## Validasi

-   Semua pegawai harus milik unit admin yang login
-   Tanggal selesai harus setelah atau sama dengan tanggal mulai
-   Jadwal dinas yang sudah dihapus (is_active = false) tidak akan mempengaruhi presensi

## Error Handling

-   Jika pegawai tidak memiliki jadwal dinas, presensi berjalan normal dengan status "hadir"
-   Jika ada jadwal dinas, status presensi otomatis menjadi "dinas"
-   Jadwal dinas yang sudah dihapus tidak akan mempengaruhi presensi baru

## Contoh Penggunaan Lengkap

### Admin Unit

```bash
# 1. Buat jadwal dinas
POST /api/dinas/create
{
    "tanggal_mulai": "2024-01-15",
    "tanggal_selesai": "2024-01-17",
    "keterangan": "Dinas ke Jakarta",
    "pegawai_ids": [1, 2, 3]
}

# 2. Lihat jadwal dinas
GET /api/dinas?bulan=1&tahun=2024

# 3. Lihat presensi dinas yang sudah dilakukan
GET /api/dinas/presensi?bulan=1&tahun=2024

# 4. Hapus jadwal dinas jika perlu
DELETE /api/dinas/delete
{
    "jadwal_dinas_id": 123
}
```

### Super Admin

```bash
# Semua endpoint sama, tapi wajib input unit_id
POST /api/dinas/create
{
    "unit_id": 1,
    "tanggal_mulai": "2024-01-15",
    "tanggal_selesai": "2024-01-17",
    "keterangan": "Dinas ke Jakarta",
    "pegawai_ids": [1, 2, 3]
}
```

## Notes

-   Fitur ini menggunakan AdminUnitHelper untuk konsistensi dengan fitur lainnya
-   Status "dinas" sudah ditambahkan ke model Presensi
-   Jadwal dinas akan otomatis terintegrasi dengan sistem presensi yang ada
-   Admin unit tidak perlu input unit_id, super admin wajib input unit_id
-   Pegawai tetap harus melakukan presensi normal, hanya status yang berubah menjadi dinas
