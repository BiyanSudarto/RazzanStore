# Spec Anchor — Phase 2: Orders, Checkout, and Midtrans

Status: implementation baseline for Phase 2 on `feat/phase-2-checkout-midtrans`.
Parent: approved Phase 1 branch `feat/phase-1-admin-catalog`.
Authority: approved Master Spec V2.0. This file records Phase 2 implementation details; provider-specific behavior must be verified against the active Midtrans account/docs before production use.

## 1. Scope

- Order management and monotonic order/payment state machine.
- Payment attempts and checkout idempotency.
- Server-side price calculation and immutable order item snapshots.
- Midtrans Snap token creation and customer payment handoff.
- Midtrans notification signature validation (SHA-512).
- Payment Status API reconciliation for pending/unknown attempts.
- Automated feature, unit, concurrency, and provider adapter tests.
- No Digiflazz fulfillment in this phase. A paid outbox event may be recorded, but Phase 3 owns fulfillment dispatch.

## 2. Non-negotiable invariants

1. Client-supplied amount, price, cost, provider SKU, payment status, and Snap token are never authoritative.
2. The backend reads the active product and selling price from MySQL and stores immutable product/game/price snapshots on the order.
3. IDR is represented as integer rupiah; no floating-point arithmetic.
4. A business order expires 60 minutes after database creation time. Expiry blocks new attempts but does not auto-fail or stop reconciliation of an existing pending/unknown attempt.
5. At most 3 payment attempts per order (1 initial + up to 2 retries).
6. At most one attempt in creating, pending, or unknown state may exist for an order. Unknown must be reconciled before another attempt.
7. Every provider payment attempt has a unique `provider_order_id`; a new actual attempt uses a new reference.
8. A Snap create timeout is unknown. Do not resend create with a new reference until the old reference is reconciled. One `404 / Payment Not Found` response alone is not proof that no transaction exists.
9. Any verified paid attempt makes the aggregate order paid (`paid_wins`). Paid never regresses due to stale unpaid notifications.
10. An order is eligible for retry only when all attempts are definitively failed/expired and none is paid, pending, active, or unknown (`unpaid_retry`).
11. If multiple attempts are found paid, retain paid state, record a conflict, and require reconciliation/manual review. No automatic duplicate fulfillment.
12. Browser callbacks and Snap `onClose` are UX signals only. They do not prove payment success or failure.
13. Payment state, webhook event record, and `order.paid` outbox event are persisted atomically. Phase 2 does not dispatch fulfillment.

## 3. Database model

### `orders`

- `id`, `public_order_code` (unique), `tracking_token_hash` (unique SHA-256 digest), `status`, `payment_status`.
- Product/game/SKU/name/nominal snapshots and `player_data` snapshot.
- `quantity`, `unit_price`, `subtotal`, `discount_amount`, `total_amount` (unsigned integer IDR), `currency` (`IDR`).
- Optional customer contact fields only when required for support/receipts.
- `expires_at`, `paid_at`, `completed_at` (nullable), timestamps.

### `payment_attempts`

- `id`, `order_id`, `attempt_no`, `provider` (`midtrans`), `provider_order_id` (unique), optional `provider_transaction_id` (unique when present).
- `status`, `amount`, `currency`, encrypted `snap_token`, optional `redirect_url`, provider status/fraud metadata allowlist.
- `idempotency_key_hash`, `requested_at`, `confirmed_at`, `expires_at`, `last_reconciled_at`, `reconciliation_count`, timestamps.
- Unique `(order_id, attempt_no)` and unique provider reference. Database constraints are the final guard against duplicate attempts.

### `idempotency_records`

- `scope`, `key_hash`, `request_hash`, `resource_type`, `resource_id`, safe response metadata, timestamps/expiry.
- Unique `(scope, key_hash)`. Raw idempotency keys are not stored.
- Same key + same canonical request hash returns the original resource. Same key + different hash returns HTTP 409.

### `payment_webhook_events`

- `provider`, `event_fingerprint` (unique), `provider_order_id`, `provider_transaction_id`, `event_type`, `provider_status`, `payload_hash`, allowlisted metadata, `signature_valid`, processing status, timestamps.
- Do not persist Server Key, authorization headers, full raw webhook bodies, card data, or Snap token in event metadata.

### `outbox_events`

- `event_uuid` (unique), `aggregate_type`, `aggregate_id`, `event_type`, safe payload, status, attempts, `available_at`, `dispatched_at`, timestamps.
- Phase 2 writes `order.paid` atomically. Phase 3 will consume it for fulfillment only after its own idempotent dispatch contract is ready.

### ERD

    PRODUCTS 1---* ORDERS
    ORDERS 1---* PAYMENT_ATTEMPTS
    ORDERS 1---* IDEMPOTENCY_RECORDS
    PAYMENT_ATTEMPTS 1---* PAYMENT_WEBHOOK_EVENTS (by provider reference)
    ORDERS 1---* OUTBOX_EVENTS (by aggregate)

## 4. Customer API contract

| Method | Endpoint | Contract |
|---|---|---|
| POST | `/api/v1/checkout` | Requires `Idempotency-Key`; accepts `product_id`, `player_data`, and optional customer contact. Server validates active product and game fields, calculates amount, creates order + attempt + idempotency record, then requests Snap token. |
| POST | `/api/v1/orders/{tracking_token}/payment-attempts` | Creates a retry only if the previous attempt is definitively failed/expired, order is not expired, attempt limit is available, and no pending/unknown attempt exists. Requires a new idempotency key. |
| GET | `/api/v1/orders/{tracking_token}` | Returns customer-safe order, amount, and payment status. Never reveals provider credentials, cost, provider metadata, internal event payload, or token hashes. |
| GET | `/track/{tracking_token}` | Tracking page; token is high entropy and only its SHA-256 digest is stored. |
| POST | `/api/v1/webhooks/midtrans` | Public provider notification endpoint; verifies SHA-512, reference, amount, status, and applicable fraud state before mutation. |

`Idempotency-Key` is required and must contain 16–128 printable ASCII characters. Store only a SHA-256 digest. Canonicalize the request before hashing. Replayed key with an identical request returns the original order/attempt response; key reuse with a different body returns 409.

## 5. Midtrans adapter

- Sandbox Snap create endpoint: `https://app.sandbox.midtrans.com/snap/v1/transactions`.
- Production Snap create endpoint: `https://app.midtrans.com/snap/v1/transactions`.
- Status endpoint: `GET /v2/{order_id}/status` on the matching sandbox/production API host.
- Server Key is used only server-side via the provider's documented authentication scheme. Client Key is public configuration only.
- Snap create timeout/ambiguous response => attempt `unknown`; query the exact existing `provider_order_id` using Status API before any new provider transaction.
- Keep separate settings for provider transaction expiry and the 60-minute business order expiry. Do not invent a provider expiry value.
- API calls have explicit connection/overall timeouts. Network/5xx errors are ambiguous, not payment failures.
- Provider responses are reduced to an allowlist before being persisted; secrets and payment instrument data are excluded.

## 6. Midtrans notification verification

- Expected signature is SHA-512 of the exact concatenated incoming strings `order_id + status_code + gross_amount + ServerKey`.
- Compute expected digest on the server and compare with `hash_equals`; do not trim, parse/reformat, or float-convert `gross_amount` before signature calculation.
- After signature verification, match `order_id` to one payment attempt and compare amount exactly to its immutable integer IDR amount using a canonical decimal string.
- Validate provider transaction identifier and the provider transaction status before applying any state transition.
- `settlement` => paid after all validation.
- `capture` => paid only when `fraud_status` is `accept`; `challenge` or unexpected fraud state remains non-paid and requires review/reconciliation.
- `pending` => pending. `deny` and `cancel` => failed. `expire` => expired.
- Unknown statuses, refund, partial refund, or contradictory events are recorded and reconciled; they must not regress a paid order.
- Verify raw notification fields/signature before mutating business state. Use a unique event fingerprint for replay deduplication.
- Invalid signature returns an error and causes no order/payment mutation. Duplicate valid notifications are acknowledged but do not re-run the transition.

## 7. Reconciliation

- Reconcile attempts in pending, unknown, or reconciliation-required states against Midtrans Status API using the same provider order reference.
- Network failure, 5xx, malformed response, or ambiguous 404 remains unknown and is retried through reconciliation only.
- No blind transaction re-creation.
- State updates are monotonic; verified paid wins over stale unpaid events.
- Provide an artisan command `payments:reconcile --limit=100` for controlled/manual execution. Schedule/backoff intervals require observed provider behavior and operational review; do not guess a cadence here.
- Record every reconciliation outcome and safe diagnostic metadata for audit.

## 8. Security and privacy

- Dynamic player data is validated against field definitions for the selected game; reject unknown keys and invalid types.
- Tracking token uses cryptographically secure randomness and is returned once; database stores only its SHA-256 digest.
- Snap token is encrypted at rest and never logged. Server Key is injected from secret environment/secret manager only.
- Webhook signature, attempt reference, amount, status, and applicable fraud state are checked before database mutation.
- Use DB transactions, row locks, unique constraints, and after-commit queue/outbox handling.
- Never log tracking tokens, Server Key, Snap token, authorization headers, raw full webhook payload, or unnecessary customer PII.
- Customer response is allowlisted and cannot expose internal provider or admin data.

## 9. Test matrix

- Server price wins: client-supplied amount/price is rejected or ignored; total equals DB selling price.
- Product disabled or unknown product cannot be checked out.
- Dynamic Player ID/server required/type/option validation; unknown fields rejected.
- Missing/invalid Idempotency-Key rejected; same key/same request produces one order; same key/different request returns 409.
- Concurrent checkout and retry cannot exceed max 3 attempts or create parallel active/unknown attempts.
- Snap create timeout creates/retains unknown attempt and does not blindly call create again.
- Midtrans signature valid/invalid; altered gross amount; unknown provider reference; duplicate notification; malformed notification.
- Settlement and accepted capture pay the order; challenge does not; stale pending/deny/expire cannot roll back paid.
- Status API success, pending, timeout, 5xx, and ambiguous 404 reconciliation behavior.
- Public order/tracking response never includes tracking-token hash, Snap token, Server Key, base cost, provider SKU, or raw provider payload.
- CI: Composer install, Laravel Pint, PHPUnit, npm install, frontend production build.

## 10. Phase 2 acceptance and deferred work

Phase 2 acceptance requires migrations and MySQL-compatible tests, API tests, payment adapter tests using fakes/sandbox credentials only, concurrency/idempotency tests, and green CI. Production credentials and live payment mode remain disabled.

Deferred to Phase 3: Digiflazz API, Buyer Webhook HMAC-SHA1, fulfillment jobs/retry safety, product sync, consuming `order.paid` outbox, and fulfillment reconciliation.

Deferred operational decisions: provider-side Snap expiry, measured reconciliation cadence/backoff, target traffic/load profile, RTO, and production credential provisioning.