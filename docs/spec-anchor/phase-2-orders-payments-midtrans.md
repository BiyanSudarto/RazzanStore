# Spec Anchor — Phase 2: Orders, Checkout, and Midtrans

Status: implementasi Phase 2 pada branch `feat/phase-2-checkout-midtrans`; PR #3 tetap Draft dan belum di-merge.
Parent: `feat/phase-1-admin-catalog`.
Authority: Master Spec V2.0 yang disetujui. Catatan ini menjelaskan kondisi kode yang ada, bukan pernyataan bahwa payment production sudah aktif.

## 1. Scope dan batasan

Termasuk:
- Order snapshot, payment attempts, checkout, dan retry.
- Server-side price calculation dari katalog aktif.
- Midtrans Snap token creation.
- Verifikasi notification SHA-512 dan event deduplication.
- Payment Status API reconciliation.
- Private order tracking endpoint dan halaman tracking.
- Outbox event `order.paid` yang dicatat saat payment terverifikasi.
- Feature/unit tests, PHP Pint, frontend build, serta migrasi MySQL 8.4.

Tidak termasuk:
- Digiflazz API, Buyer Webhook HMAC-SHA1, fulfillment dispatch, dan safe resend. Itu fase berikutnya.
- Tidak ada dispatch fulfillment dari `order.paid` dalam fase ini.
- Tidak ada live transaction, deployment, atau pembelian layanan berbayar.

## 2. Invariants transaksi

1. Harga yang dikirim browser tidak digunakan sebagai sumber kebenaran. Checkout membaca `selling_price` dari product aktif dan membuat snapshot harga/nama/SKU/game ke order.
2. Nilai uang disimpan sebagai integer rupiah (IDR); tidak ada perhitungan harga menggunakan floating point.
3. Order memiliki business expiry 60 menit sejak dibuat. Expiry memblokir retry baru; tidak otomatis mengubah payment pending/unknown menjadi gagal.
4. Maksimum 3 payment attempts per order (1 awal + maksimal 2 retry).
5. Attempt `creating`, `pending`, `unknown`, atau `reconciliation_required` memblokir retry baru.
6. Retry hanya dibolehkan jika attempt terakhir sudah dipastikan `failed` atau `expired`, order belum expired/paid, dan batas attempt belum terlampaui.
7. Setiap provider attempt memiliki `provider_order_id` unik; retry yang benar-benar baru menggunakan reference baru.
8. Timeout atau respons ambigu saat membuat Snap tidak boleh diperlakukan sebagai gagal. Attempt dipertahankan `unknown`; create request tidak diulang menggunakan reference baru.
9. Status `paid` bersifat menang terhadap status non-paid yang datang belakangan (`paid_wins`). Status order tidak boleh kembali ke pending/unknown/failed setelah verified paid.
10. Bila lebih dari satu payment attempt untuk order yang sama terverifikasi paid, order tetap paid, konflik direkam di reconciliation log, dan diperlukan pemeriksaan manual. Outbox `order.paid` tetap satu.
11. Browser callback dan Snap `onClose` bukan bukti pembayaran. Status otoritatif berasal dari webhook terverifikasi dan/atau Status API.
12. Payment state, webhook event, dan pencatatan outbox `order.paid` diproses dalam transaksi database. Fase ini belum mengirim order ke Digiflazz.

## 3. Database — keadaan aktual

### `orders`

Kolom yang dibuat oleh migration `2026_11_14_000005_create_orders_table.php`:
- `public_order_code` unique.
- `product_id`, `game_id` nullable FK dengan `restrictOnDelete`.
- Snapshot: `product_sku_snapshot`, `product_name_snapshot`, `game_name_snapshot`, `nominal_label_snapshot`, `player_data`.
- `customer_contact` nullable.
- `unit_price`, `quantity`, `subtotal`, `discount_amount`, `total_amount`; integer rupiah.
- `currency` default `IDR`; `status`, `payment_status`, `fulfillment_status`.
- `tracking_token_hash` unique SHA-256 digest.
- `expires_at`, `paid_at`, `completed_at`, timestamps.
- Index status/payment status dan created time.

### `payment_attempts`

Dibuat oleh migration `2026_11_14_000006_create_payment_attempts_table.php`, lalu ditambah `provider_request_started_at` oleh migration `000010`:
- FK `order_id`, `attempt_no`, `provider`.
- `provider_order_id` unique; `provider_transaction_id` nullable unique.
- `status`, `amount`, `currency`.
- `snap_token` disimpan terenkripsi melalui Eloquent encrypted cast; `redirect_url` dan `provider_metadata`.
- `requested_at`, `provider_request_started_at`, `confirmed_at`, `expires_at`, `last_reconciled_at`, `reconciliation_count), timestamps.
- Unique `(order_id, attempt_no)`; index order/status dan status/last reconciliation.
- Tidak ada kolom idempotency key hash pada tabel payment_attempts.

Status attempt yang digunakan kode: `creating`, `pending`, `unknown`, `paid`, `failed`, `expired`, dan `reconciliation_required`.

### `idempotency_records`

- `scope`, `key_hash` (SHA-256), `request_hash` (SHA-256).
- FK `order_id` dan nullable FK `payment_attempt_id`.
- `created_at`, `expires_at`.
- Unique `(scope, key_hash)`.
- Tidak menyimpan raw idempotency key, `resource_type`, atau `resource_id`.
- Retention diset 30 hari. Ketika key yang sama digunakan kembali setelah `expires_at`, record lama untuk scope/key tersebut dihapus sebelum proses idempotency baru dibuat. Tidak ada background purge untuk record expired lain yang tidak pernah diminta lagi.

### `payment_webhook_events`

- Provider, unique `event_fingerprint`, nullable FK payment attempt.
- Provider order/transaction reference, event type/status, `payload_hash`.
- `signature_valid`, processing status, allowlisted `safe_metadata`, received/processed timestamps, optional processing error.
- Raw notification body, Server Key, Snap token, dan data kartu tidak disimpan ke safe metadata.

### `outbox_events`

- `event_uuid` unique, FK `order_id`, `event_type`, JSON payload.
- `status`, `attempts`, `available_at`, `dispatched_at`, `last_error`, timestamps.
- Unique `(order_id, event_type)`.
- Kolom aggregate_type/aggregate_id generik tidak ada pada migration aktual; outbox saat ini berorientasi pada order.

### `payment_reconciliation_logs`

- FK `payment_attempt_id`, `source`, `http_status`, `provider_status`, `outcome`.
- `response_hash`, `safe_metadata`, `checked_at`.
- Mencatat status polling dan konflik multiple-paid.

## 4. Tracking token

Token tracking **bukan token acak yang disimpan langsung**. Kode membuat:
1. HMAC-SHA256 dari `public_order_code` menggunakan `TRACKING_TOKEN_KEY` server-side (minimal 32 karakter).
2. Digest binary diubah ke Base64 URL-safe tanpa padding untuk token yang ditampilkan.
3. Database menyimpan SHA-256 hash dari token tersebut.

Konsekuensi: token sama dapat dibentuk ulang dari order code dan secret yang sama, sehingga response idempotent dapat mengembalikan URL tracking yang sama tanpa menyimpan raw token. Rotasi `TRACKING_TOKEN_KEY` mengubah token tracking untuk seluruh order; rencana rotasi/revocation per order belum dibuat. Tidak ada tracking-token TTL yang diterapkan oleh kode sekarang.

## 5. Customer API dan UI

| Method | Path | Implementasi |
|---|---|---|
| POST | `/api/v1/checkout` | Memerlukan `Idempotency-Key`; input `product_id`, `player_data`, optional `customer_contact`. Harga dihitung dari database. |
| GET | `/api/v1/orders/{trackingToken}` | Mengembalikan ringkasan order dan payment attempt yang aman untuk pelanggan. |
| POST | `/api/v1/orders/{trackingToken}/payment-attempts` | Membuat retry dengan `Idempotency-Key` baru jika aturan retry terpenuhi. |
| GET | `/track/{trackingToken}` | Halaman Inertia/React untuk status tracking. |
| POST | `/api/v1/webhooks/midtrans` | Endpoint notification provider dengan signature validation. |

Route API memberi rate limit: checkout/retry 10 request/menit, tracking API 60/menit, webhook 120/menit. Route web tracking juga dibatasi 60/menit. Tracking response tidak mengeluarkan token hash, Snap token, provider metadata, cost, atau secret.

`Idempotency-Key` wajib, panjang 16–128 karakter ASCII printable. Key mentah tidak disimpan; hanya hash. Canonical request hash mencakup product, player data yang diurutkan, dan customer contact. Key sama + body sama mengembalikan resource lama; key sama + body berbeda menghasilkan HTTP 409. Response checkout baru 201, replay 200, status create ambigu 202, dan penolakan create definitif 502.

## 6. Midtrans Snap

- Sandbox create: `https://app.sandbox.midtrans.com/snap/v1/transactions`.
- Production create: `https://app.midtrans.com/snap/v1/transactions`.
- Status: `GET /v2/{provider_order_id}/status` pada API host sandbox/production yang sesuai.
- Backend memakai HTTP Basic Auth dengan Server Key sebagai username dan password kosong.
- Client Key bukan secret server; Server Key tidak boleh dikirim ke browser.
- Default timeout koneksi 5 detik dan timeout total 15 detik.
- Host redirect URL dibatasi ke `app.midtrans.com` dan `app.sandbox.midtrans.com` via HTTPS.
- Item ID/name dipotong maksimal 50 karakter sebelum dikirim.
- `customer_contact` hanya menerima nomor telepon digit 8–15, dengan optional leading plus.
- Business order expiry 60 menit terpisah dari expiry transaksi Snap. Snap transaction expiry belum dikonfigurasi di payload.

Pembuatan Snap mengklaim request pada `provider_request_started_at` dalam transaksi pendek sebelum HTTP call. Jika proses berhenti di tengah request, reconciler menunggu 2 menit sebelum mencoba Status API untuk reference yang sama.

## 7. Midtrans webhook validation

1. Body JSON diparse dan signature dihitung dari string asli `order_id + status_code + gross_amount + ServerKey` dengan SHA-512.
2. Digest dibandingkan menggunakan `hash_equals`; signature tidak dihitung ulang dari angka float atau jumlah yang sudah diformat ulang.
3. Signature valid harus diikuti kecocokan `provider_order_id`, nominal IDR persis dengan immutable attempt amount, dan currency IDR.
4. ID transaksi provider dicek agar tidak digunakan oleh attempt berbeda atau berubah setelah ditetapkan.
5. `settlement` diterima sebagai paid setelah status code 200 dan validasi reference/amount. Fraud status tidak diwajibkan untuk settlement.
6. `capture` hanya paid jika status code 200 dan `fraud_status=accept`. `challenge` atau status fraud lain tidak dianggap paid.
7. `pending/authorize` menjadi pending; `deny/cancel` menjadi failed; `expire` menjadi expired. Status tak dikenal/refund/chargeback masuk reconciliation-required.
8. Event fingerprint unik mencegah webhook identik diproses ulang. Invalid signature tidak mengubah payment state.
9. Status unpaid yang datang setelah paid tidak dapat menurunkan aggregate order.

## 8. Reconciliation

Command yang terdaftar: `php artisan payments:reconcile --limit=100`.

Pemilihan attempt:
- `pending`, `unknown`, dan `reconciliation_required`.
- `creating` yang mempunyai `provider_request_started_at` lebih lama dari 2 menit. Attempt dikunci dan status diperiksa kembali sebelum polling.

Perlakuan hasil:
- Status API sukses: validasi reference/amount/currency, lalu apply state machine.
- 404, network failure, 5xx, malformed response, atau error lain: tetap unknown/ambiguous; tidak dianggap gagal.
- Tidak ada blind create ulang.
- Hasil dan response hash dicatat di reconciliation log; hanya metadata terpilih yang disimpan.
- Perintah tersedia untuk eksekusi terkontrol. Cron schedule/backoff interval belum dipasang dan harus ditentukan berdasarkan verifikasi provider serta kebutuhan operasi.

## 9. Security baseline dan data retention

- `TRACKING_TOKEN_KEY` dan `MIDTRANS_SERVER_KEY` hanya server-side environment/secret manager. `.env` tidak boleh masuk Git.
- Snap token terenkripsi saat disimpan; tidak dikembalikan dari GET tracking.
- Player fields divalidasi terhadap definisi field game, termasuk field tidak dikenal dan type/options.
- DB transactions, row locks, unique indexes, dan outbox unique order/event dipakai untuk perlindungan replay/double processing.
- Webhook raw body tidak dipersist; disimpan hash dan safe metadata.
- Tracking URL menggunakan Referrer Policy `no-referrer`.
- `idempotency_records` mencatat `expires_at` 30 hari; expired record dibersihkan saat key itu dipakai lagi. Pembersihan berkala untuk seluruh record expired belum dibuat.
- Retention `orders`, raw player data, webhook events, reconciliation logs, dan outbox masih memerlukan kebijakan terpisah sebelum production.

## 10. Test coverage dan acceptance

Pada commit terakhir yang telah diverifikasi:
- PHP: 38 tests passed, 203 assertions passed.
- Pint strict check: passed pada run dengan formatter preview; perubahan format harus ikut di-commit agar strict check tetap lulus tanpa auto-fix.
- Frontend production build: passed.
- MySQL 8.4 migrations + canonical role seeding: job CI selesai sukses pada run verifikasi.
- Test meliputi server price, disabled/invalid player data, idempotency replay/key mismatch/expiry, Snap timeout tanpa blind resend, retry setelah kegagalan definitif, tracking response, signature SHA-512, amount mismatch, duplicate webhook, settlement/capture fraud handling, multiple-paid conflict, stale paid monotonicity, Status API settlement, dan 404 ambiguous.
- Belum ada load test, database concurrency stress test, live Midtrans sandbox account verification, penetration test, atau production rehearsal.

Acceptance Phase 2 tetap memerlukan seluruh CI hijau pada commit PR terbaru, code review, dan konfirmasi eksplisit sebelum PR di-merge. Fase ini belum siap mengaktifkan transaksi production.

## 11. Deferred

- Digiflazz API/Buyer Webhook HMAC-SHA1 dan fulfillment jobs.
- Outbox consumer/dispatch untuk `order.paid`.
- Provider Snap expiry dan kalender reconciliation/backoff.
- Background cleanup untuk seluruh idempotency record expired.
- Tracking-token rotation/revocation per order.
- Retention/privacy policy lengkap, load/concurrency testing, alerting, backup/restore rehearsal, RPO/RTO, dan production deployment gate.
