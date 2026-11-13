# Contribution and Coding Standards

## Branching
- Do not work directly on `main`.
- Use short-lived branches such as `feat/*`, `fix/*`, and `docs/*`.
- Keep commits focused and descriptive.

## PHP
- Follow PSR-12 and Laravel conventions.
- Run Laravel Pint before opening a pull request.
- Keep controllers thin; business rules belong in domain actions/services.
- Validate all external input at the boundary.
- Use dependency injection and parameterized database access.
- Represent IDR monetary values as integers; never use floating point.

## React / TypeScript
- Use typed props and explicit component contracts.
- Keep page components focused; move reusable behavior into hooks/components.
- Handle loading, empty, validation, and failure states.
- Do not expose private provider configuration to browser code.

## Transactions and providers
- Server-side price is authoritative.
- Webhook signatures must be verified against the exact raw body before payload processing.
- Checkout, provider events, queue jobs, and fulfillment must be idempotent.
- Unknown provider outcomes must be reconciled; never blindly resend.
- Any state transition must have tests.

## Secrets and data
- Never commit `.env`, credentials, private keys, encryption keys, or production payloads.
- Redact sensitive fields from logs and provider response snapshots.
- Keep database and Redis on loopback/private interfaces.
- Do not use real payment credentials in automated tests.

## Required checks
```bash
composer lint
composer test
npm run build
```
