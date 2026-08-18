# Module: trust_safety
**Purpose:** Reports, moderation queue, pair-velocity flags (§B.6.7).
**Status:** in_progress
**Depends on:** core, identity, merchant, orders, listings

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| POST | /trade/v1/reports | `{entity_type, entity_id, reason}` | `{report_id, status}` | session (owner for self, admin for any) | VALIDATION_FAILED, REPORT_SUBMITTED |
| GET | /trade/v1/reports | `?status?&entity_type?&entity_id?` | paginated reports | session (admin for all, owner for self) | — |
| GET | /trade/v1/reports/{id} | — | one report | session (owner/admin) | REPORT_NOT_FOUND |
| POST | /trade/v1/reports/{id}/transition | `{outcome, reason}` | `{report_id, status, from}` | session (admin) | REPORT_NOT_FOUND, VALIDATION_FAILED |

> `entity_type` ∈ {merchant, listing, order, request}. `outcome` ∈ {cleared, rejected, flagged}. Reason required on resolve/dismiss.

## State machine
```
pending ──admin──→ cleared     (admin resolves, report closed)
pending ──admin──→ rejected    (admin dismisses, report closed)
pending ──system──→ flagged    (pair_velocity trigger: ≥3 reports within 7d)
cleared / rejected / flagged — terminal (no outgoing edges)
```

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| REPORT_SUBMITTED | `{report_id, reporter_id, entity_type, entity_id, reason}` | POST /reports succeeds |
| REPORT_RESOLVED  | `{report_id, entity_type, entity_id, outcome}` | admin transition → cleared/rejected |
| REPORT_DISMISSED | `{report_id, entity_type, entity_id}` | admin transition → rejected (synonym: dismissed) |
| MERCHANT_FLAGGED | `{merchant_id, report_count, window_days}` | pair_velocity trigger |

## Events Consumed
| Event | Action |
|-------|--------|
| — | (reports are originated by callers; moderation is admin-driven) |

## Owned Tables
- `tb_reports` — id, reporter_id, entity_type, entity_id, reason, status, resolved_by, resolved_at, resolved_reason, created_at, updated_at

## Invariants
- Reports always audited via tb_audit_logs.
- pair_velocity: ≥3 reports against same merchant/entity_type within 7 days → FLAGGED event emitted.
- Self-reports (reporter_id = entity owner) are REJECTED immediately.
- Admin must provide `reason` on resolve/dismiss.
- Status transitions: pending → {cleared, rejected, flagged}; all terminal.
- Reports on non-existent entities → VALIDATION_FAILED.