# API Documentation - Fitur Dinas

## Overview

Fitur dinas memungkinkan admin unit untuk mengatur dinas (duty/official trip) untuk pegawai dengan inputan tanggal mulai, tanggal selesai, keterangan, dan pilihan pegawai. Ketika dibuat, sistem akan otomatis insert ke tabel presensi dengan status "dinas".

## Endpoints

### 1. Create Dinas

**POST** `/api/dinas/create`

Membuat dinas untuk multiple pegawai dalam rentang tanggal tertentu.

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
    "message": "Proses pembuatan dinas selesai",
    "created_count": 9,
    "error_count": 0,
    "created_data": [
        {
            "pegawai": "John Doe",
            "tanggal": "2024-01-15",
            "presensi_id": 123
        },
        {
            "pegawai": "Jane Smith",
            "tanggal": "2024-01-15",
            "presensi_id": 124
        }
    ],
    "errors": []
}
```

#### Response Error (400)

```json
{
    "message": "unit_id wajib diisi untuk super admin"
}
```

### 2. Get List Dinas

**GET** `/api/dinas`

Mengambil daftar dinas berdasarkan unit.

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
        "pegawai": {
            "id": 1,
            "no_ktp": "1234567890",
            "nama": "John Doe"
        },
        "tanggal": "2024-01-15",
        "hari": "Senin",
        "waktu_masuk": "08:00:00",
        "waktu_pulang": "17:00:00",
        "keterangan": "Dinas ke Jakarta",
        "shift_name": "Shift Pagi",
        "presensi_id": 123
    },
    {
        "pegawai": {
            "id": 2,
            "no_ktp": "0987654321",
            "nama": "Jane Smith"
        },
        "tanggal": "2024-01-16",
        "hari": "Selasa",
        "waktu_masuk": "08:00:00",
        "waktu_pulang": "17:00:00",
        "keterangan": "Dinas ke Jakarta",
        "shift_name": "Shift Pagi",
        "presensi_id": 124
    }
]
```

### 3. Delete Dinas

**DELETE** `/api/dinas/delete`

Menghapus dinas untuk rentang tanggal dan pegawai tertentu.

#### Request Body

```json
{
    "unit_id": 1, // Wajib untuk super admin, opsional untuk admin unit
    "tanggal_mulai": "2024-01-15", // Format: YYYY-MM-DD
    "tanggal_selesai": "2024-01-17", // Format: YYYY-MM-DD
    "pegawai_ids": [1, 2, 3] // Array ID pegawai
}
```

#### Response Success (200)

```json
{
    "message": "Dinas berhasil dihapus",
    "deleted_count": 9
}
```

## Business Logic

### 1. Waktu Masuk dan Pulang

-   Sistem akan mengambil waktu masuk dan pulang dari shift detail pegawai
-   Waktu disesuaikan dengan hari kerja (senin, selasa, dll)
-   Jika pegawai tidak memiliki shift detail atau tidak ada jam kerja pada hari tertentu, akan error

### 2. Status Presensi

-   `status_masuk`: "absen_masuk"
-   `status_pulang`: "absen_pulang"
-   `status_presensi`: "dinas"
-   `lokasi_masuk`: null
-   `lokasi_pulang`: null
-   `keterangan_masuk`: sesuai input keterangan
-   `keterangan_pulang`: sesuai input keterangan

### 3. Validasi

-   Tanggal selesai harus >= tanggal mulai
-   Semua pegawai harus milik unit admin yang login
-   Tidak boleh ada presensi yang sudah ada pada tanggal yang sama
-   Pegawai harus memiliki shift detail
-   Pegawai harus memiliki jam kerja pada hari tersebut

### 4. Error Handling

-   Jika ada presensi yang sudah ada, akan skip dan return error message
-   Jika pegawai tidak memiliki shift detail, akan skip dan return error message
-   Jika tidak ada jam kerja pada hari tertentu, akan skip dan return error message

## Contoh Penggunaan

### Admin Unit

```bash
# Create dinas
POST /api/dinas/create
{
    "tanggal_mulai": "2024-01-15",
    "tanggal_selesai": "2024-01-17",
    "keterangan": "Dinas ke Jakarta",
    "pegawai_ids": [1, 2, 3]
}

# Get list dinas
GET /api/dinas?bulan=1&tahun=2024

# Delete dinas
DELETE /api/dinas/delete
{
    "tanggal_mulai": "2024-01-15",
    "tanggal_selesai": "2024-01-17",
    "pegawai_ids": [1, 2, 3]
}
```

### Super Admin

```bash
# Create dinas
POST /api/dinas/create
{
    "unit_id": 1,
    "tanggal_mulai": "2024-01-15",
    "tanggal_selesai": "2024-01-17",
    "keterangan": "Dinas ke Jakarta",
    "pegawai_ids": [1, 2, 3]
}

# Get list dinas
GET /api/dinas?unit_id=1&bulan=1&tahun=2024

# Delete dinas
DELETE /api/dinas/delete
{
    "unit_id": 1,
    "tanggal_mulai": "2024-01-15",
    "tanggal_selesai": "2024-01-17",
    "pegawai_ids": [1, 2, 3]
}
```

## Notes

-   Fitur ini menggunakan AdminUnitHelper untuk konsistensi dengan fitur lainnya
-   Status "dinas" sudah ditambahkan ke model Presensi
-   Dinas akan otomatis terintegrasi dengan sistem presensi yang ada
-   Admin unit tidak perlu input unit_id, super admin wajib input unit_id

## Route yang Ditambahkan

### **Dinas (DinasController)**

```
✅ GET    /api/dinas
✅ POST   /api/dinas/create
✅ DELETE /api/dinas/delete
```

### **Perubahan Akses**

-   ✅ **Admin Unit:** Bisa akses dengan otomatis menggunakan unit admin yang login
-   ✅ **Super Admin:** Bisa akses dengan memilih unit terlebih dahulu (wajib input unit_id)
