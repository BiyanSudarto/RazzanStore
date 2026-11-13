# RazzanStore

Platform top-up game otomatis untuk pelanggan Indonesia.

## Stack

- Laravel 13 / PHP 8.3+
- React + Inertia
- Tailwind CSS 4 + Vite Plus
- MySQL 8.x
- Redis untuk queue/cache
- Midtrans Snap (backend adapter dan webhook pada Phase 2)
- Digiflazz API/Buyer Webhook (ditunda ke Phase 3)

## Status

- Master Spec V2.0 disetujui.
- Phase 0 foundation CI lulus.
- Phase 1 database/admin/catalog dikerjakan pada branch `feat/phase-1-admin-catalog`.
- Phase 2 order/checkout/Midtrans dikerjakan pada branch `feat/phase-2-checkout-midtrans`; PR #3 masih Draft dan belum di-merge.
- Phase 2 belum memakai live credentials dan belum menjalankan fulfillment; Digiflazz ditunda ke Phase 3.
- Tidak ada VPS/domain berbayar yang dibeli atau deployment dilakukan.

## Setup lokal

Persyaratan: PHP 8.3+, Composer 2, Node.js 22+, npm, ekstensi PHP Laravel, MySQL 8.x, dan Redis.

    composer install
    cp .env.example .env
    php artisan key:generate
    npm install
    php artisan migrate
    php artisan db:seed
    php artisan razzanstore:admin:create --role=super_admin
    npm run dev

Perintah admin bootstrap meminta nama, email, password secara interaktif; gunakan --role=admin untuk akun Admin. Password tidak dicatat dalam repository dan di-hash dengan Argon2id. Sesuaikan koneksi MySQL/Redis di .env.

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

Lihat `docs/spec-anchor/phase-1-database-admin-catalog.md` untuk ERD, role matrix, endpoint, dan setup Phase 1. Lihat `docs/spec-anchor/phase-2-orders-payments-midtrans.md` untuk skema order/payment, API checkout, Midtrans webhook, rekonsiliasi, dan batasan Phase 2.