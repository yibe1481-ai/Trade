# Module: verification
**Purpose:** Merchant document review workflow (§B.6.5).
**Status:** in_progress
**Depends on:** core, identity, merchant

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| POST | /trade/v1/verification | `{merchant_id}` | `{merchant_id, status}` | session (system) | MERCHANT_NOT_FOUND, MERCHANT_VERIFICATION_PENDING |
| GET | /trade/v1/verification/{id} | — | merchant profile + verification_status | session (owner/admin) | MERCHANT_NOT_FOUND |
| POST | /trade/v1/verification/{id}/transition | `{to, reason?}` | `{merchant_id, status, from}` | session (admin for REVOKED; system for VERIFIED/REJECTED) | MERCHANT_NOT_FOUND, REQUEST_INVALID_TRANSITION, MERCHANT_VERIFICATION_PENDING |

> `to` ∈ {VERIFIED, REJECTED, REVOKED}. REVOKED requires `reason`. System (non-admin) may VERIFIED/REJECTED; admin may all three.

## State machine (§B.6.5)
```
NONE ──system──→ PENDING
PENDING ──system──→ VERIFIED
PENDING ──system──→ REJECTED   (with note)
VERIFIED ──admin──→ REVOKED   (with reason)
REVOKED — terminal
```

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| MERCHANT_VERIFIED | `{merchant_id, reviewed_by}` | PENDING → VERIFIED |
| MERCHANT_VERIFICATION_REJECTED | `{merchant_id, reviewed_by, note}` | PENDING → REJECTED |
| MERCHANT_VERIFICATION_REVOKED | `{merchant_id, revoked_by, reason}` | VERIFIED → REVOKED (cascades: all ACTIVE listings → PAUSED) |

## Events Consumed
| Event | Action |
|-------|--------|
| LISTING_PAUSED (from verification REVOKED) | All merchant ACTIVE listings transitioned to PAUSED; search deindexed |

## Owned Tables
- `tb_merchants` — id, user_id, business_name, phone, website, verification_status (owned column; NONE|PENDING|VERIFIED|REJECTED|REVOKED)
- `tb_verification_documents` — id, merchant_id, document_type, storage_key, status, verified_at, revoked_at, revocation_reason, created_at, updated_at

## Invariants
- Verification status transitions: NONE→PENDING (create), PENDING→VERIFIED (documents approved), PENDING→REJECTED (documents failed), VERIFIED→REVOKED (admin revokes, cascades pause).
- REVOKED cascades: every ACTIVE listing owned by the revoked merchant is transitioned to PAUSED via `ListingsService::apply_transition(listing, 'PAUSED', 'admin', '', $store)`. Search deindexes paused listings.
- Single document record per merchant (upsert on each verification change).
- Merchant NOT_FOUND if id does not exist.