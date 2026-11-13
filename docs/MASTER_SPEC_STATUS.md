# Master Spec Status

- Global Master Spec V2.0: approved by the project owner.
- Backup Spec V2.0: separately approved and included as an implementation baseline.
- Phase 0: foundation work in progress on `feat/phase-0-foundation`.
- Main branch: protected from direct project foundation changes during this phase.
- No VPS/domain purchase, paid service activation, or deployment has been authorized.

## Production safety baseline
- Compute prices on the server.
- Verify Midtrans SHA-512 notifications.
- Verify Digiflazz Buyer Webhook HMAC-SHA1 over raw request body.
- Use idempotency, unique provider references, database locks, and outbox dispatch.
- Reconcile unknown provider outcomes before any retry.
- Trigger fulfillment only after verified payment.
- Test backup restore and PITR; RPO <= 1 hour is a target that must be evidenced by tests.

## Next phases
1. Complete Phase 0 CI validation.
2. Build the database schema and domain models.
3. Implement catalog and admin access.
4. Implement checkout and Midtrans integration.
5. Implement Digiflazz fulfillment and reconciliation.
6. Implement customer/admin UI.
7. Implement backup automation and disaster-recovery tests.
8. Complete security, load, resilience, UAT, and go-live gates.
