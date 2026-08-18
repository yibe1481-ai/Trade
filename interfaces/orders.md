# Module: orders
**Purpose:** Order lifecycle, DM trust loop, reviews (§B.6.2–B.6.4, §B.7).
**Status:** in_progress
**Depends on:** core, identity, listings, merchant

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| GET | /trade/v1/orders | — | caller's orders (customer or merchant), newest first | session | — |
| POST | /trade/v1/orders | `{listing_id, qty?}` | `{order_id, status}` | session | VALIDATION_FAILED, LISTING_NOT_AVAILABLE, ORDER_ALREADY_OPEN |
| GET | /trade/v1/orders/{id} | — | order (customer/merchant/admin only) | session | ORDER_NOT_FOUND |
| POST | /trade/v1/orders/{id}/transition | `{to, reason?}` | `{order_id, status, completed?}` | session (owner) | ORDER_NOT_FOUND, ORDER_INVALID_TRANSITION, INVENTORY_INSUFFICIENT_STOCK, VALIDATION_FAILED |
| GET | /trade/v1/orders/{id}/review | — | review or `null` | session (owner) | ORDER_NOT_FOUND |
| POST | /trade/v1/orders/{id}/review | `{rating, comment?}` | `{review_id, order_id, rating}` | session (customer) | ORDER_NOT_FOUND, REVIEW_NOT_ELIGIBLE, FORBIDDEN_NOT_OWNER, VALIDATION_FAILED |

> `price` is integer minor currency units, snapshotted from the listing at order time. `qty` defaults to 1, max 99.

## State machine (§B.6.2)
```
REQUESTED ──merchant──→ ACCEPTED
REQUESTED ──customer|merchant──→ CANCELLED   (requires reason)
REQUESTED ──system──→ EXPIRED                 (14-day worker nudge)
REQUESTED ──customer|merchant──→ DISPUTED     (requires reason)
ACCEPTED  ──customer|merchant──→ COMPLETED    (dual confirmation)
ACCEPTED  ──customer|merchant──→ CANCELLED    (requires reason)
ACCEPTED  ──customer|merchant──→ DISPUTED     (requires reason)
COMPLETED / CANCELLED / EXPIRED / DISPUTED — terminal.
```

## Service API (used by other modules)
- `Service::create_order(array $payload, int $customer_id, ?Store)` → `{order_id, status:'REQUESTED'}`
- `Service::apply_transition(array $row, string $to, string $actor, string $reason='', ?Store)` → `{order_id, status, completed?, from?}`
- `Service::create_review(array $payload, int $customer_id, ?Store)` → `{review_id, order_id, rating}`
- `Service::order_row(int $order_id, ?Store)` / `Service::review_row(int $order_id, ?Store)` → raw row

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| ORDER_CREATED | `{order_id, customer_id, merchant_id, listing_id, source}` | POST /orders succeeds |
| ORDER_ACCEPTED | `{order_id}` | REQUESTED→ACCEPTED (stock decremented) |
| ORDER_COMPLETED | `{order_id, customer_id, merchant_id, auto_reconciled:false}` | dual confirmation resolves |
| ORDER_CONFIRMED_ONE_SIDE | `{order_id, confirmed_by}` | single-sided confirm (order stays ACCEPTED) |
| ORDER_CANCELLED | `{order_id, cancelled_by, reason}` | any cancellation |
| ORDER_EXPIRED | `{order_id}` | system-driven |
| ORDER_DISPUTED | `{order_id, raised_by, reason}` | dispute before terminal state |
| REVIEW_CREATED | `{review_id, order_id, subject_merchant_id, rating}` | POST review succeeds |

## Owned Tables
- `tb_orders` — id, customer_id, merchant_id, listing_id, price, currency, qty, status, customer_confirmed_at, merchant_confirmed_at, cancel_reason, disputed_by, created_at, updated_at
- `tb_reviews` — id, order_id, customer_id, merchant_id, listing_id, rating, comment, created_at

## Invariants
- COMPLETED requires dual confirmation (§B.6.3): both `customer_confirmed_at` and `merchant_confirmed_at` set. Single-sided confirms record that side only; the order stays ACCEPTED and emits `ORDER_CONFIRMED_ONE_SIDE`, never `ORDER_COMPLETED`.
- Stock decrements on ACCEPTED only (§B.7.2), atomically per listing & qty; insufficient stock → `INVENTORY_INSUFFICIENT_STOCK` and the transition is refused.
- One open REQUESTED order per (customer, listing); a second → `ORDER_ALREADY_OPEN`.
- One review per COMPLETED order, within 30 days, by the order's customer only (§B.6.4). `REVIEW_NOT_ELIGIBLE` otherwise.
