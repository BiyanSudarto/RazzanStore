# RazzanStore

Platform top-up game otomatis untuk pelanggan Indonesia.

## Stack

- Laravel 13 / PHP 8.3+
- React + Inertia
- Tailwind CSS 4 + Vite Plus
- MySQL 8.x
- Redis untuk queue/cache
- Midtrans Snap dan Digiflazz (integrasi akan dikerjakan pada fase terpisah)

## Status

- Master Spec V2.0 disetujui.
- Phase 0 foundation CI lulus.
- Phase 1 database/admin/catalog dikerjakan pada branch feat/phase-1-admin-catalog.
- Pembayaran/fulfillment belum diaktifkan.
- Tidak ada VPS/domain berbayar yang dibeli atau deployment dilakukan.

## Setup lokal

Persyaratan: PHP 8.3+, Composer 2, Node.js 22+, npm, ekstensi PHP Laravel, MySQL 8.x, dan Redis.

    composer install
    cp .env.example .env
    php artisan key:generate
    npm install
    php artisan migrate
    php artisan db:seed
    php artisan razzanstore:admin:create
    npm run dev

Perintah admin bootstrap meminta nama, email, password secara interaktif; password tidak dicatat dalam repository. Sesuaikan koneksi MySQL/Redis di .env.

## Pemeriksaan kualitas

    composer lint
    composer test
    npm run build

## Keamanan

- Jangan commit .env, credential, private key, atau provider secret.
- Midtrans Server Key dan Digiflazz API Key hanya berada di server.
- Harga dihitung di backend.
- Webhook wajib diverifikasi sebelum mengubah status.
- Checkout, payment event, dan fulfillment harus idempotent.
- Transaksi production tidak diaktifkan sebelum production gates lulus.

Lihat docs/spec-anchor/phase-1-database-admin-catalog.md untuk ERD, role matrix, endpoint, dan setup Phase 1.