<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

-   [Simple, fast routing engine](https://laravel.com/docs/routing).
-   [Powerful dependency injection container](https://laravel.com/docs/container).
-   Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
-   Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
-   Database agnostic [schema migrations](https://laravel.com/docs/migrations).
-   [Robust background job processing](https://laravel.com/docs/queues).
-   [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains over 2000 video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the Laravel [Patreon page](https://patreon.com/taylorotwell).

### Premium Partners

-   **[Vehikl](https://vehikl.com/)**
-   **[Tighten Co.](https://tighten.co)**
-   **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
-   **[64 Robots](https://64robots.com)**
-   **[Cubet Techno Labs](https://cubettech.com)**
-   **[Cyber-Duck](https://cyber-duck.co.uk)**
-   **[Many](https://www.many.co.uk)**
-   **[Webdock, Fast VPS Hosting](https://www.webdock.io/en)**
-   **[DevSquad](https://devsquad.com)**
-   **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
-   **[OP.GG](https://op.gg)**
-   **[WebReinvent](https://webreinvent.com/?utm_source=laravel&utm_medium=github&utm_campaign=patreon-sponsors)**
-   **[Lendio](https://lendio.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

# Backend Presensi YBWSA

## Deskripsi

Backend API untuk sistem presensi YBWSA dengan integrasi Android/iOS.

## Perbaikan Masalah Lokasi Presensi

### Masalah yang Diperbaiki

1. **Lokasi yang terdeteksi terlalu banyak** - Pegawai bisa presensi di mana saja tidak sesuai dengan lokasi pada `unit_detail_id_presensi`
2. **Android/iOS tidak mendapatkan informasi lokasi yang spesifik** untuk validasi di sisi client
3. **Relasi tidak dimuat dengan benar** di middleware dan controller

## Fitur Hari Libur

### Konsep Hari Libur
- Masing-masing admin unit dapat melakukan setting hari libur dengan keterangannya
- Hari libur nempel ke masing-masing unit detail pada masing-masing unitnya
- Jika pada hari tersebut libur, pegawai akan otomatis ter-flagging hadir/masuk semua dengan keterangan "Hari Libur"
- Status presensi hari libur: `hadir_hari_libur`

### Cara Kerja Hari Libur
1. **Admin Unit** mengatur hari libur untuk unit detail tertentu
2. **Pegawai** melakukan presensi pada hari libur
3. **Sistem** otomatis memberikan status `hadir_hari_libur` dengan keterangan "Hari Libur"
4. **Pegawai** hanya perlu presensi sekali per hari libur

### Endpoint untuk Admin Unit
```javascript
// Tambah hari libur
const response = await fetch('/api/hari-libur', {
  method: 'POST',
  headers: { 
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${admin_token}`
  },
  body: JSON.stringify({
    unit_detail_id: 1,
    tanggal: '2024-01-15',
    keterangan: 'Hari Raya Idul Fitri'
  })
});

// Tambah hari libur untuk multiple unit detail
const response = await fetch('/api/hari-libur/multiple', {
  method: 'POST',
  headers: { 
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${admin_token}`
  },
  body: JSON.stringify({
    unit_detail_ids: [1, 2, 3],
    tanggal: '2024-01-15',
    keterangan: 'Hari Raya Idul Fitri'
  })
});
```

### Endpoint untuk Pegawai
```javascript
// Cek apakah hari ini libur
const response = await fetch('/api/pegawai/cek-hari-libur', {
  headers: { 'Authorization': `Bearer ${pegawai_token}` }
});

const result = await response.json();
// Response: { is_hari_libur: true, tanggal: '2024-01-15', keterangan: 'Hari Raya Idul Fitri' }

// Presensi pada hari libur (otomatis status hadir_hari_libur)
const presensiResponse = await fetch('/api/presensi', {
  method: 'POST',
  headers: { 
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${pegawai_token}`
  },
  body: JSON.stringify({
    lokasi: [latitude, longitude]
  })
});
```

### Perubahan yang Dilakukan

#### 1. Perbaikan Endpoint `pegawai/me`

Endpoint ini sekarang mengembalikan data lengkap dengan relasi yang diperlukan:

```json
{
  "id": 1,
  "no_ktp": "1234567890",
  "nama_depan": "John",
  "nama_belakang": "Doe",
  "shift_detail_id": 1,
  "unit_detail_id_presensi": 1,
  "shift_detail": {
    "id": 1,
    "shift_id": 1,
    "senin_masuk": "08:00",
    "senin_pulang": "17:00",
    "shift": {
      "id": 1,
      "name": "Shift Pagi",
      "unit_id": 1
    }
  },
  "unit_detail_presensi": {
    "id": 1,
    "name": "Kantor Pusat",
    "lokasi": [[lat1, lng1], [lat2, lng2], ...],
    "unit": {
      "id": 1,
      "name": "Unit A"
    }
  }
}
```

#### 2. Endpoint Baru: `pegawai/lokasi-presensi`

Endpoint khusus untuk Android/iOS mendapatkan informasi lokasi presensi yang valid:

**GET** `/api/pegawai/lokasi-presensi`

**Response:**

```json
{
  "pegawai_id": 1,
  "no_ktp": "1234567890",
  "nama": "John Doe",
  "lokasi_presensi": {
    "unit_detail_id": 1,
    "nama_lokasi": "Kantor Pusat",
    "polygon_lokasi": [[lat1, lng1], [lat2, lng2], ...],
    "unit_name": "Unit A"
  },
  "shift_info": {
    "shift_detail_id": 1,
    "shift_name": "Shift Pagi",
    "jam_kerja": {
      "senin": {
        "masuk": "08:00",
        "pulang": "17:00"
      },
      "selasa": {
        "masuk": "08:00",
        "pulang": "17:00"
      }
      // ... hari lainnya
    },
    "toleransi": {
      "terlambat": 15,
      "pulang": 0
    }
  }
}
```

#### 3. Perbaikan Middleware AuthJWT

Middleware sekarang memuat relasi yang diperlukan saat autentikasi:

```php
$pegawai = MsPegawai::with(['shiftDetail.shift', 'unitDetailPresensi'])->find($payload->sub);
```

#### 4. Perbaikan PresensiController

Controller presensi sekarang memuat relasi dengan benar sebelum validasi:

```php
$pegawai->load(['shiftDetail.shift', 'unitDetailPresensi']);
```

### Cara Penggunaan untuk Android/iOS

#### 1. Login dan Ambil Data Pegawai

```javascript
// Login
const loginResponse = await fetch("/api/pegawai/login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ no_ktp: "1234567890", password: "password" }),
});

const { token } = await loginResponse.json();

// Ambil data pegawai dengan lokasi
const pegawaiResponse = await fetch("/api/pegawai/me", {
    headers: { Authorization: `Bearer ${token}` },
});

const pegawai = await pegawaiResponse.json();
```

#### 2. Ambil Informasi Lokasi Presensi

```javascript
// Ambil informasi lokasi yang valid untuk presensi
const lokasiResponse = await fetch("/api/pegawai/lokasi-presensi", {
    headers: { Authorization: `Bearer ${token}` },
});

const lokasiPresensi = await lokasiResponse.json();

// Gunakan polygon_lokasi untuk validasi di sisi client
const validLocation = lokasiPresensi.lokasi_presensi.polygon_lokasi;
```

#### 3. Validasi Lokasi di Sisi Client

```javascript
// Fungsi point-in-polygon untuk validasi lokasi
function isPointInPolygon(point, polygon) {
    const [x, y] = point;
    let inside = false;

    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        const [xi, yi] = polygon[i];
        const [xj, yj] = polygon[j];

        if (yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) {
            inside = !inside;
        }
    }

    return inside;
}

// Validasi sebelum presensi
const currentLocation = [latitude, longitude];
if (!isPointInPolygon(currentLocation, validLocation)) {
    alert("Anda berada di luar area presensi!");
    return;
}
```

#### 4. Presensi

```javascript
// Lakukan presensi
const presensiResponse = await fetch("/api/presensi", {
    method: "POST",
    headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({
        lokasi: [latitude, longitude],
    }),
});

const presensi = await presensiResponse.json();
```

### Struktur Database yang Diperbaiki

#### Relasi yang Benar:

-   **Unit** memiliki banyak **UnitDetail**
-   **UnitDetail** memiliki lokasi polygon masing-masing
-   **Shift** berlaku untuk semua **UnitDetail** dalam satu **Unit**
-   **MsPegawai** memiliki relasi ke **ShiftDetail** dan **UnitDetail** (untuk presensi)
-   **Presensi** menggunakan **shift_id** dan **shift_detail_id** dari pegawai

### Validasi Lokasi

1. **Server-side**: Validasi point-in-polygon di PresensiController
2. **Client-side**: Android/iOS dapat melakukan validasi awal menggunakan data dari endpoint `pegawai/lokasi-presensi`

## Installation

```bash
composer install
php artisan migrate
php artisan serve
```

## API Documentation

### Authentication

-   **POST** `/api/pegawai/login` - Login pegawai
-   **GET** `/api/pegawai/me` - Get data pegawai (dengan relasi)
-   **GET** `/api/pegawai/lokasi-presensi` - Get lokasi presensi yang valid

### Presensi

-   **POST** `/api/presensi` - Lakukan presensi
-   **GET** `/api/presensi/today` - Presensi hari ini
-   **GET** `/api/presensi/history` - History presensi

### Hari Libur

-   **GET** `/api/hari-libur` - Daftar hari libur (admin unit)
-   **POST** `/api/hari-libur` - Tambah hari libur (admin unit)
-   **PUT** `/api/hari-libur/{id}` - Update hari libur (admin unit)
-   **DELETE** `/api/hari-libur/{id}` - Hapus hari libur (admin unit)
-   **POST** `/api/hari-libur/multiple` - Tambah hari libur untuk multiple unit detail (admin unit)
-   **GET** `/api/pegawai/cek-hari-libur` - Cek apakah hari ini libur (pegawai)

## Testing

```bash
php artisan test
```
