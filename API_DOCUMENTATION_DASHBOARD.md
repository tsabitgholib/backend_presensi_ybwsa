# API Documentation - Dashboard

## Overview

API Dashboard menyediakan data ringkasan untuk ditampilkan di dashboard super admin dan admin unit. Data ini bisa digunakan untuk membuat chart, grafik, dan visualisasi lainnya di frontend.

**Fitur Baru:** Super admin sekarang bisa melihat data total dari semua unit tanpa harus memfilter unit_id, memberikan overview yang komprehensif.

## Endpoints

### 1. Get Dashboard Data

**GET** `/api/dashboard`

Mengambil semua data dashboard untuk periode tertentu.

#### Query Parameters

-   `unit_id` (optional): ID unit (opsional untuk super admin, wajib untuk admin unit)
-   `bulan` (optional): Bulan (1-12), default: bulan sekarang
-   `tahun` (optional): Tahun, default: tahun sekarang

#### Access Patterns

**1. Super Admin - View All Units (Overview)**

```
GET /api/dashboard?bulan=1&tahun=2024
```

-   Menampilkan data total dari semua unit
-   Termasuk `unit_performance` dan `unit_breakdown`
-   Memberikan overview komprehensif

**2. Super Admin - View Specific Unit**

```
GET /api/dashboard?unit_id=1&bulan=1&tahun=2024
```

-   Menampilkan data unit tertentu
-   Sama seperti admin unit

**3. Admin Unit**

```
GET /api/dashboard?bulan=1&tahun=2024
```

-   Menampilkan data unit admin yang login
-   Tidak perlu parameter unit_id

#### Example Request

```
GET /api/dashboard?unit_id=1&bulan=1&tahun=2024
```

#### Response Success (200)

```json
{
    "scope": {
        "type": "all_units",
        "description": "Semua Unit",
        "unit_id": null
    },
    "period": {
        "bulan": 1,
        "tahun": 2024,
        "nama_bulan": "Januari 2024",
        "start_date": "2024-01-01",
        "end_date": "2024-01-31"
    },
    "summary": {
        "total_pegawai": 60,
        "attendance_summary": {
            "total_expected": 1860,
            "hadir": 1550,
            "terlambat": 120,
            "tidak_hadir": 100,
            "izin": 45,
            "sakit": 30,
            "cuti": 15,
            "dinas": 50,
            "attendance_rate": 83.33
        },
        "leave_requests": {
            "cuti": 20,
            "sakit": 15,
            "izin": 30,
            "total": 65
        }
    },
    "charts": {
        "daily_attendance": [
            {
                "tanggal": "2024-01-01",
                "hari": "Senin",
                "hadir": 50,
                "terlambat": 5,
                "tidak_hadir": 3,
                "izin": 1,
                "sakit": 1,
                "cuti": 0,
                "total": 60
            }
        ],
        "status_distribution": [
            {
                "status": "Hadir",
                "count": 1550,
                "color": "#10B981"
            },
            {
                "status": "Terlambat",
                "count": 120,
                "color": "#F59E0B"
            },
            {
                "status": "Tidak Hadir",
                "count": 100,
                "color": "#EF4444"
            },
            {
                "status": "Izin",
                "count": 45,
                "color": "#8B5CF6"
            },
            {
                "status": "Sakit",
                "count": 30,
                "color": "#F97316"
            },
            {
                "status": "Cuti",
                "count": 15,
                "color": "#06B6D4"
            }
        ],
        "shift_distribution": [
            {
                "shift": "Shift Pagi",
                "count": 800
            },
            {
                "shift": "Shift Siang",
                "count": 600
            },
            {
                "shift": "Shift Malam",
                "count": 150
            }
        ],
        "monthly_trend": [
            {
                "bulan": "2023-08",
                "nama_bulan": "Agu 2023",
                "hadir": 1400,
                "total": 1680,
                "attendance_rate": 83.33
            },
            {
                "bulan": "2023-09",
                "nama_bulan": "Sep 2023",
                "hadir": 1480,
                "total": 1800,
                "attendance_rate": 82.22
            },
            {
                "bulan": "2023-10",
                "nama_bulan": "Okt 2023",
                "hadir": 1440,
                "total": 1740,
                "attendance_rate": 82.76
            },
            {
                "bulan": "2023-11",
                "nama_bulan": "Nov 2023",
                "hadir": 1520,
                "total": 1860,
                "attendance_rate": 81.72
            },
            {
                "bulan": "2023-12",
                "nama_bulan": "Des 2023",
                "hadir": 1380,
                "total": 1680,
                "attendance_rate": 82.14
            },
            {
                "bulan": "2024-01",
                "nama_bulan": "Jan 2024",
                "hadir": 1550,
                "total": 1860,
                "attendance_rate": 83.33
            }
        ]
    },
    "lists": {
        "top_employees": [
            {
                "id": 1,
                "nama": "John Doe",
                "hadir": 22,
                "terlambat": 0,
                "total": 22,
                "attendance_rate": 100.0
            },
            {
                "id": 2,
                "nama": "Jane Smith",
                "hadir": 21,
                "terlambat": 1,
                "total": 22,
                "attendance_rate": 95.45
            }
        ],
        "recent_activities": [
            {
                "type": "presensi",
                "pegawai": "John Doe",
                "status": "hadir",
                "waktu": "2024-01-31 08:15:00",
                "tanggal": "2024-01-31",
                "jam": "08:15"
            }
        ]
    },
    "unit_performance": [
        {
            "unit_id": 1,
            "unit_name": "Unit A",
            "total_pegawai": 25,
            "hadir": 650,
            "total_presensi": 775,
            "attendance_rate": 83.87
        },
        {
            "unit_id": 2,
            "unit_name": "Unit B",
            "total_pegawai": 20,
            "hadir": 520,
            "total_presensi": 620,
            "attendance_rate": 83.87
        },
        {
            "unit_id": 3,
            "unit_name": "Unit C",
            "total_pegawai": 15,
            "hadir": 380,
            "total_presensi": 465,
            "attendance_rate": 81.72
        }
    ],
    "unit_breakdown": [
        {
            "unit_id": 1,
            "unit_name": "Unit A",
            "total_pegawai": 25,
            "attendance_summary": {
                "hadir": 650,
                "terlambat": 45,
                "tidak_hadir": 50,
                "izin": 15,
                "sakit": 10,
                "cuti": 5,
                "dinas": 20,
                "total_presensi": 775,
                "attendance_rate": 83.87
            },
            "leave_requests": {
                "cuti": 8,
                "sakit": 5,
                "izin": 12,
                "total": 25
            }
        },
        {
            "unit_id": 2,
            "unit_name": "Unit B",
            "total_pegawai": 20,
            "attendance_summary": {
                "hadir": 520,
                "terlambat": 40,
                "tidak_hadir": 30,
                "izin": 20,
                "sakit": 15,
                "cuti": 5,
                "dinas": 15,
                "total_presensi": 620,
                "attendance_rate": 83.87
            },
            "leave_requests": {
                "cuti": 7,
                "sakit": 6,
                "izin": 10,
                "total": 23
            }
        }
    ]
}
```

## Data Structure

### 1. Scope

Informasi tentang cakupan data yang ditampilkan.

-   `type`: Jenis cakupan ("all_units" atau "specific_unit")
-   `description`: Deskripsi cakupan ("Semua Unit" atau nama unit)
-   `unit_id`: ID unit (null jika all_units)

### 2. Period

Informasi periode data yang ditampilkan.

-   `bulan`: Nomor bulan (1-12)
-   `tahun`: Tahun
-   `nama_bulan`: Nama bulan dalam bahasa Indonesia
-   `start_date`: Tanggal awal periode
-   `end_date`: Tanggal akhir periode

### 3. Summary

Ringkasan data utama.

-   `total_pegawai`: Total jumlah pegawai di unit
-   `attendance_summary`: Ringkasan kehadiran
-   `leave_requests`: Ringkasan pengajuan cuti/izin/sakit

### 4. Charts

Data untuk berbagai jenis chart.

#### Daily Attendance

Data kehadiran harian untuk line chart atau bar chart.

-   `tanggal`: Tanggal (YYYY-MM-DD)
-   `hari`: Nama hari dalam bahasa Indonesia
-   `hadir`: Jumlah pegawai hadir
-   `terlambat`: Jumlah pegawai terlambat
-   `tidak_hadir`: Jumlah pegawai tidak hadir
-   `izin`: Jumlah pegawai izin
-   `sakit`: Jumlah pegawai sakit
-   `cuti`: Jumlah pegawai cuti
-   `total`: Total presensi hari tersebut

#### Status Distribution

Data distribusi status untuk pie chart atau donut chart.

-   `status`: Nama status
-   `count`: Jumlah
-   `color`: Warna untuk chart (hex code)

#### Shift Distribution

Data distribusi shift untuk bar chart.

-   `shift`: Nama shift
-   `count`: Jumlah presensi

#### Monthly Trend

Data trend bulanan untuk line chart.

-   `bulan`: Format YYYY-MM
-   `nama_bulan`: Nama bulan dalam bahasa Indonesia
-   `hadir`: Jumlah hadir
-   `total`: Total presensi
-   `attendance_rate`: Persentase kehadiran

### 5. Lists

Data untuk list/tabel.

#### Top Employees

Daftar pegawai dengan kehadiran terbaik.

-   `id`: ID pegawai
-   `nama`: Nama pegawai
-   `hadir`: Jumlah hadir
-   `terlambat`: Jumlah terlambat
-   `total`: Total presensi
-   `attendance_rate`: Persentase kehadiran

#### Recent Activities

Aktivitas terbaru.

-   `type`: Jenis aktivitas
-   `pegawai`: Nama pegawai
-   `status`: Status presensi
-   `waktu`: Waktu lengkap
-   `tanggal`: Tanggal
-   `jam`: Jam

### 6. Unit Performance (Super Admin Only)

Performa unit untuk super admin.

-   `unit_id`: ID unit
-   `unit_name`: Nama unit
-   `total_pegawai`: Jumlah pegawai
-   `hadir`: Jumlah hadir
-   `total_presensi`: Total presensi
-   `attendance_rate`: Persentase kehadiran

### 7. Unit Breakdown (Super Admin Only)

Detail breakdown per unit untuk super admin.

-   `unit_id`: ID unit
-   `unit_name`: Nama unit
-   `total_pegawai`: Jumlah pegawai di unit
-   `attendance_summary`: Ringkasan kehadiran di unit
-   `leave_requests`: Ringkasan pengajuan cuti/izin/sakit di unit

## Chart Recommendations

### 1. Attendance Rate Gauge

Gunakan `summary.attendance_summary.attendance_rate` untuk gauge chart.

### 2. Daily Attendance Line Chart

Gunakan `charts.daily_attendance` untuk line chart dengan multiple series (hadir, terlambat, tidak hadir, dll).

### 3. Status Distribution Pie Chart

Gunakan `charts.status_distribution` untuk pie chart dengan warna yang sudah disediakan.

### 4. Monthly Trend Line Chart

Gunakan `charts.monthly_trend` untuk line chart trend 6 bulan terakhir.

### 5. Shift Distribution Bar Chart

Gunakan `charts.shift_distribution` untuk bar chart distribusi shift.

### 6. Unit Performance Bar Chart (Super Admin)

Gunakan `unit_performance` untuk bar chart perbandingan unit.

### 7. Top Employees Table

Gunakan `lists.top_employees` untuk tabel ranking pegawai.

### 8. Recent Activities Timeline

Gunakan `lists.recent_activities` untuk timeline aktivitas terbaru.

## Contoh Penggunaan

### Super Admin - View All Units (Overview)

```bash
GET /api/dashboard?bulan=1&tahun=2024
```

**Response akan menampilkan:**

-   Data total dari semua unit
-   `scope.type`: "all_units"
-   `scope.description`: "Semua Unit"
-   `unit_performance`: Ranking unit berdasarkan attendance rate
-   `unit_breakdown`: Detail breakdown per unit

### Super Admin - View Specific Unit

```bash
GET /api/dashboard?unit_id=1&bulan=1&tahun=2024
```

**Response akan menampilkan:**

-   Data unit tertentu saja
-   `scope.type`: "specific_unit"
-   `scope.description`: "Unit A" (nama unit)
-   `unit_performance`: null
-   `unit_breakdown`: null

### Admin Unit

```bash
GET /api/dashboard?bulan=1&tahun=2024
```

**Response akan menampilkan:**

-   Data unit admin yang login
-   `scope.type`: "specific_unit"
-   `scope.description`: "Unit A" (nama unit admin)
-   `unit_performance`: null
-   `unit_breakdown`: null

## Notes

-   Data dihitung berdasarkan periode bulan yang dipilih
-   **Super Admin** memiliki 2 mode akses:
    -   **Overview Mode**: Tanpa parameter `unit_id` - melihat data total dari semua unit
    -   **Specific Unit Mode**: Dengan parameter `unit_id` - melihat data unit tertentu
-   **Admin Unit** hanya bisa melihat data unit mereka sendiri
-   `unit_performance` dan `unit_breakdown` hanya tersedia untuk super admin dalam overview mode
-   Semua data sudah diformat untuk langsung digunakan di frontend chart library
-   Field `scope` membantu frontend memahami cakupan data yang ditampilkan

## Route yang Ditambahkan

### **Dashboard (DashboardController)**

```
✅ GET    /api/dashboard
```

### **Perubahan Akses**

-   ✅ **Admin Unit:** Bisa akses dengan otomatis menggunakan unit admin yang login
-   ✅ **Super Admin:** Bisa akses dengan memilih unit terlebih dahulu (wajib input unit_id)
