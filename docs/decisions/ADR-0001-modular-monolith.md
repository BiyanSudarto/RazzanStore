# ADR-0001: Modular Monolith

- Status: Accepted through Master Spec V2.0 approval
- Date: 2026-11-14
- Decision: Start with one Laravel deployable application, separated into explicit domain modules.
- Context: Initial launch needs strong transaction integrity without unnecessary distributed-systems complexity.
- Consequences:
  - Keep catalog, orders, payments, fulfillment, tracking, and admin boundaries explicit.
  - Use database transactions, unique constraints, outbox records, idempotent workers, and reconciliation.
  - Split services only when measured scaling/ownership needs justify the operational cost and a reviewed change request approves it.
