# RazzanStore

Platform top-up game otomatis.

## Status

Fase 0 — fondasi repository dan aplikasi.

## Stack target

- Laravel 13 / PHP 8.3+
- React + Inertia + Tailwind CSS
- MySQL 8.x
- Redis
- Midtrans Snap
- Digiflazz API dan Buyer Webhook

## Prinsip keamanan

- Harga selalu dihitung di server.
- Webhook diverifikasi sebelum mengubah status.
- Checkout, payment event, dan fulfillment wajib idempotent.
- Secret tidak boleh masuk repository atau frontend.
- Transaksi produksi tidak aktif sampai gerbang pengujian produksi lulus.
