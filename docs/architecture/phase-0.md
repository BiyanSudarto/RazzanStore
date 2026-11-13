# Phase 0 — Application Foundation

## Architecture
RazzanStore starts as a Laravel Modular Monolith. Business modules will be separated by domain boundaries while sharing one deployable application and relational database.

Planned modules:
- Catalog
- Orders
- Payments
- Fulfillment
- Tracking
- Admin
- Audit
- Backup and Operations

## Runtime
- Laravel 13 / PHP 8.3+
- React + Inertia
- Tailwind CSS 4
- MySQL 8.x
- Redis for queue/cache
- Vite Plus toolchain
- GitHub Actions CI

## Phase 0 scope
- Laravel application skeleton
- React/Inertia bootstrapping
- environment template
- coding conventions
- health endpoint
- CI workflow
- baseline user/session/cache/queue migrations

## Explicitly not implemented in Phase 0
- Midtrans checkout and payment processing
- Digiflazz fulfillment or webhook
- Customer order state machine
- Admin authorization workflows
- Automated backup job or R2 integration
- Production deployment

No live transactions are enabled by this foundation.
