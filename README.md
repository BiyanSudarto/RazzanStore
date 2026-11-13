# RazzanStore

Platform top-up game otomatis untuk pelanggan Indonesia.

## Status

Fase 0 — fondasi repository dan aplikasi. Modul transaksi Midtrans/Digiflazz belum diaktifkan.

## Stack

- Laravel 13 / PHP 8.3+
- React + Inertia
- Tailwind CSS 4 + Vite Plus
- MySQL 8.x
- Redis untuk queue/cache
- GitHub Actions CI

## Setup lokal

Persyaratan: PHP 8.3+, Composer 2, Node.js 22+, npm, ekstensi PHP yang diperlukan Laravel, MySQL 8.x, dan Redis.

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run dev
```

Sesuaikan koneksi MySQL dan Redis di `.env`. Aplikasi dapat dijalankan dengan:

```bash
php artisan serve
```

API health check: `GET /api/v1/health`.

## Pemeriksaan kualitas

```bash
composer lint
composer test
npm run build
```

## Keamanan

- Jangan commit `.env`, credential, private key, atau provider secret.
- Midtrans Server Key dan Digiflazz API Key hanya berada di server.
- Harga dihitung di backend.
- Webhook wajib diverifikasi sebelum mengubah status.
- Checkout, payment event, dan fulfillment harus idempotent.
- Transaksi production tidak diaktifkan sebelum production gates lulus.

Lihat `docs/` untuk keputusan arsitektur dan standar pengembangan.
