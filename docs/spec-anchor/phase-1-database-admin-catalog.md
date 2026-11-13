# Spec Anchor — Phase 1: Database, Admin, and Catalog

Status: implementation draft on feat/phase-1-admin-catalog.
Master Spec V2.0 is the approved project baseline. This document records the Phase 1 implementation details.

## Database entities

- roles: unique role codes super_admin and admin.
- admins: isolated administrator identities, role FK, active flag, login timestamp, encrypted TOTP secret slot, password hash.
- games: slug, name, description, image path, active flag, display order.
- game_fields: per-game input definitions such as Player ID and server; unique key per game; input type limited to text, number, select.
- products: SKU, provider SKU, product labels, base cost and selling price as integer IDR, active flag, display order, metadata.
- price_histories: immutable old/new base cost and selling price, actor, reason, timestamp.
- admin_audit_logs: actor, action, target, redacted before/after snapshots, request metadata.

All operational tables use InnoDB-compatible foreign keys and indexes. Transaction-related tables from the Master Spec are reserved for later phases; this phase does not implement checkout or payment.

## ERD

    ROLES 1---* ADMINS
    GAMES 1---* GAME_FIELDS
    GAMES 1---* PRODUCTS
    PRODUCTS 1---* PRICE_HISTORIES
    ADMINS 1---* PRICE_HISTORIES
    ADMINS 1---* ADMIN_AUDIT_LOGS

## Authentication and authorization

- Dedicated Laravel session guard admin and Eloquent provider admins; separate from the default web user guard.
- Admin login uses session regeneration and route throttling (5 requests/minute) plus a per-email/IP failure counter.
- Inactive administrators cannot authenticate.
- Passwords are hashed with Argon2id (64 MiB memory, time cost 4, one thread) through Laravel's hashed cast; CLI bootstrap enforces 12–128 characters and never writes a default credential to source control. Production PHP must expose Argon2id in password_algos().
- razzanstore:admin:create --role=super_admin interactively creates a Super Admin; use --role=admin to create an Admin. Passwords are never printed or seeded.
- admin.role middleware enforces active status and role allowlists.
- Game and game-field catalog management is available to both administrator roles.
- Product creation, price changes, provider SKU mapping, and internal product metadata are restricted to Super Admin. Admin can update customer-facing product fields and deactivate products.
- Administrator mutations are written to audit logs; password/token/secret-like keys are redacted. Price history and supplier cost/provider metadata are available only to Super Admin. Admin responses hide base cost, provider SKU, and internal metadata.

## Catalog interfaces

Public read-only:
- GET /api/v1/catalog/games
- GET /api/v1/catalog/games/{slug}

Administrator routes are session-authenticated web routes under /admin/api and require CSRF protection:
- GET|POST /admin/api/games
- GET|PUT|PATCH|DELETE /admin/api/games/{game}
- POST /admin/api/games/{game}/fields
- PATCH|DELETE /admin/api/games/{game}/fields/{field}
- GET /admin/api/products
- GET|PATCH /admin/api/products/{product}
- POST /admin/api/products/{product}/deactivate
- Super Admin only: POST /admin/api/products
- Super Admin only: PATCH /admin/api/products/{product}/price

Admin UI:
- GET /admin/catalog renders the responsive catalog management screen.
- The screen supports game creation, dynamic Player ID/server fields, product listing, Super Admin-only product creation and price changes, provider SKU entry, and product deactivation.
- Product listing/show hides supplier cost/provider metadata for Admin; the API applies the same restriction, not merely the UI.

Public catalog never exposes base cost, provider SKU, or internal metadata.

## Price integrity

- IDR values are integer rupiah, never floating point.
- Regular product updates cannot change base_cost or selling_price.
- Price changes use a DB transaction and row lock, insert price history, and create an audit event atomically.
- Product deletion is not offered in this phase; deactivation preserves catalog history.
- A game with products cannot be deleted; it must be deactivated.

## Dynamic input fields

Field key is restricted to lowercase identifiers. Input types are text, number, or select. Select requires options. Validation metadata accepts only structured numeric keys min_length, max_length, min, and max; arbitrary validation rule strings or regex are not accepted from administrators.

## Bootstrap and local setup

1. Configure .env for local MySQL and Redis.
2. Run composer install and npm install.
3. Run php artisan migrate.
4. Run php artisan db:seed to insert the two role definitions.
5. Run php artisan razzanstore:admin:create --role=super_admin to create the first Super Admin; use --role=admin for additional Admin accounts.
6. Run npm run build and composer test.

No default Super Admin credential is seeded. Do not use production credentials in tests.

## Phase 1 test gates

- Guest admin access redirects to admin login.
- Active administrator can log in; inactive account cannot.
- Logout invalidates session.
- Both roles can create games and dynamic fields.
- Select field without options fails validation.
- Admin cannot create products or alter price.
- Super Admin price change produces a price-history row and audit event.
- Public catalog returns active products only and hides internal base cost.
- Admin product list/show/game detail hide base cost, provider SKU, internal metadata, and price history; Super Admin can access them.
- Authenticated admin can open /admin/catalog; form actions call the session-authenticated CSRF-protected web endpoints.
- CI runs Composer install, Pint, PHPUnit, npm install and frontend build.

## Deferred to later phases

Checkout, order/payment state machines, Midtrans, Digiflazz, fulfillment, customer tracking, product-provider synchronization, admin management of additional admin accounts, TOTP enrollment/verification, and backup automation are not implemented by Phase 1.