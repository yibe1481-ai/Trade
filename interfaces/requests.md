# Module: requests
**Purpose:** Customer requests and rule-based merchant matching (§B.6.6, §B.11.5).
**Status:** in_progress
**Depends on:** core, identity, catalog, merchant, listings

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| GET | /trade/v1/requests | — | caller's requests, newest first | session | — |
| POST | /trade/v1/requests | `{category_id, location_id, budget_max?, urgency?, attributes?}` | `{request_id, status:'OPEN'}` | session | VALIDATION_FAILED, CATEGORY_NOT_FOUND, LOCATION_NOT_FOUND |
| GET | /trade/v1/requests/{id} | — | request (owner/admin only) | session | REQUEST_NOT_FOUND |
| POST | /trade/v1/requests/{id}/transition | `{to, order_id?}` | `{request_id, status, from}` | session (owner) | REQUEST_NOT_FOUND, REQUEST_INVALID_TRANSITION, ORDER_NOT_FOUND, FORBIDDEN_NOT_OWNER, VALIDATION_FAILED |
| GET | /trade/v1/requests/{id}/matches | — | `{status, matches[]}` (≤10, score desc) | session (owner) | REQUEST_NOT_FOUND |

> `budget_max` is integer minor currency units. `urgency` ∈ low|normal|high|urgent (default normal).
> `order_id` is required when `to=FULFILLED` — must be the caller's own COMPLETED order against a matched listing.

## State machine (§B.6.6)
```
OPEN ──system──→ MATCHED
OPEN ──customer──→ CANCELLED
OPEN ──system──→ EXPIRED
MATCHED ──customer──→ FULFILLED   (requires order_id)
MATCHED ──customer──→ CANCELLED
MATCHED ──system──→ EXPIRED
FULFILLED / CANCELLED / EXPIRED — terminal.
```

## Service API (used by other modules)
- `Service::create_request(array $payload, int $customer_id, ?Store)` → `{request_id, status:'OPEN'}`
- `Service::run_matching(int $request_id, ?Store)` → `{request_id, matches[]}` (idempotent rewrite)
- `Service::apply_transition(array $row, string $to, string $actor, array $extra=[], ?Store)` → `{request_id, status, from}`
- `Service::get_matches(int $request_id, ?Store)` → `{status, matches[]}` (lazy: runs matching on first read)
- `Service::expire_due(?Store)` → int (worker job; not wired to scheduler yet)
- `Service::request_row(int $request_id, ?Store)` → raw row

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| REQUEST_MATCHED | `{request_id, merchant_ids[]}` | matching run finds ≤10 eligible merchants (merchants over the daily cap excluded) |
| REQUEST_FULFILLED | `{request_id, customer_id, order_id}` | customer marks request resolved |
| REQUEST_EXPIRED | `{request_id, customer_id}` | system-driven (expire_due / worker) |
| REQUEST_CANCELLED | `{request_id, customer_id, cancelled_by}` | customer cancels OPEN/MATCHED request |

## Events Consumed
| Event | Action |
|-------|--------|
| — | (matching reads listings/merchants live) |

## Owned Tables
- `tb_customer_requests` — id, customer_id, category_id, attributes_json, budget_max, location_id, urgency, status, fulfilled_order_id, expires_at, created_at, updated_at
- `tb_request_matches` — id, request_id, merchant_id, listing_id, score, notified_at

## Invariants
- Matching (§B.11.5) is rule-based: category required, location within region required (parent chain), budget (merchant has ≥1 ACTIVE listing in category with price ≤ `budget_max × 1.2`). Ranked by §B.11.3 (`Search::rank` with empty terms).
- Cap 10 merchants per request; best listing per merchant only.
- Cap 3 request notifications per merchant per day — merchants already at cap are excluded from new matches.
- `expires_at` defaults to 14 days; expiry is a worker job (`expire_due`), not WP-Cron.
- FULFILLED requires a COMPLETED order of the same customer against a matched listing.
