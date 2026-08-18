# Trade Bot — Global Error Taxonomy

**Version:** 1.3 (MVP)
**Spec reference:** Master Specification v1.5 · §B.10
**Rule:** Codes are stable identifiers. Never rename. Add new codes in the same task that introduces them, in this file. User-facing text comes from `tb_translations` keyed by `code` — never from the `message` field.

---

## Envelope

All failures return:

```json
{
  "success": false,
  "error": {
    "code": "STRING_ERROR_CODE",
    "module": "module_name",
    "message": "Developer-facing description.",
    "retryable": false,
    "request_id": "req_…",
    "context": {}
  }
}
```

`message` is for logs/developers. `context` should contain enough structured detail (IDs, field names, limits) that an agent can diagnose the failure from the log line alone — see §B.10.4 "zero-scan debugging." If you find yourself wanting to open source code to understand why a code fired, `context` is under-populated; fix that instead.

`retryable: true` only for codes mapped to HTTP 429 or 503.

---

## Registry

### Cross-cutting (`core`)

| Code | HTTP | Retryable | Meaning |
|---|---|---|---|
| `VALIDATION_FAILED` | 400 | false | Request body/params failed validation. `context.fields[]` lists offending fields. |
| `FORBIDDEN_CAPABILITY` | 403 | false | Session lacks the capability the endpoint requires. |
| `FORBIDDEN_NOT_OWNER` | 403 | false | Caller authenticated but does not own the resource (`ownership_rule` failed). |
| `RATE_LIMITED` | 429 | true | A limit in §B.3.5 was exceeded. `Retry-After` header set. |
| `IDEMPOTENCY_KEY_REUSED` | 422 | false | `Idempotency-Key` reused with a different request body/hash. |
| `REQUEST_IN_PROGRESS` | 409 | true | Same `Idempotency-Key` is currently being processed elsewhere. |
| `CONFLICT_STALE_VERSION` | 409 | false | Optimistic-lock `version` on a `PATCH` didn't match current row (§B.7.3). Applies to any module using version-guarded writes, not only `listings`. |
| `JOB_LEASE_LOST` | 409 | false | Worker's `lock_token` didn't match on complete — lease expired or was reclaimed (§B.9.2). |
| `INTERNAL_ERROR` | 500 | false | Unhandled server error. |

### `identity`

| Code | HTTP | Retryable | Meaning |
|---|---|---|---|
| `AUTH_INVALID_SIGNATURE` | 401 | false | `initData` HMAC did not verify against the bot secret. |
| `AUTH_EXPIRED_INITDATA` | 401 | false | `auth_date` older than the 300s window (§B.3.1). |
| `AUTH_REPLAY_DETECTED` | 401 | false | This signed payload's hash was already used within the replay window. |
| `AUTH_SESSION_EXPIRED` | 401 | false | Session token past its absolute (24h) or idle (2h) TTL. |

### `merchant`

| Code | HTTP | Retryable | Meaning |
|---|---|---|---|
| `MERCHANT_NOT_FOUND` | 404 | false | Merchant doesn't exist or isn't visible to this caller. |
| `MERCHANT_NOT_VERIFIED` | 403 | false | Action requires `verification_status == verified`. |

### `catalog`

| Code | HTTP | Retryable | Meaning |
|---|---|---|---|
| `LOCATION_NOT_FOUND` | 404 | false | Location does not exist or is not visible to this caller. |
| `CATEGORY_NOT_FOUND` | 404 | false | Category does not exist or is not visible to this caller. |

### `listings`

| Code | HTTP | Retryable | Meaning |
|---|---|---|---|
| `LISTING_NOT_FOUND` | 404 | false | Listing doesn't exist or isn't visible to this caller. |
| `LISTING_IMAGE_NOT_FOUND` | 404 | false | Listing image doesn't exist or isn't on this listing. |
| `LISTING_INVALID_TRANSITION` | 409 | false | Requested status change isn't legal from the current state (§B.6.1). |
| `LISTING_NOT_AVAILABLE` | 409 | false | Listing exists but isn't `ACTIVE` — used at contact/order time. |
| `INVENTORY_INSUFFICIENT_STOCK` | 409 | false | Atomic conditional stock decrement affected 0 rows (§B.7.2). |

### `orders`

| Code | HTTP | Retryable | Meaning |
|---|---|---|---|
| `ORDER_NOT_FOUND` | 404 | false | Order doesn't exist or isn't visible to this caller. |
| `ORDER_INVALID_TRANSITION` | 409 | false | Requested status change isn't legal from the current state (§B.6.2). |
| `ORDER_ALREADY_OPEN` | 409 | false | An open `REQUESTED` order already exists for this (customer, listing) pair. |
| `REVIEW_NOT_ELIGIBLE` | 422 | false | Order isn't `COMPLETED`, the 30-day review window has closed, or the caller isn't the order's customer. |

### `billing`

| Code | HTTP | Retryable | Meaning |
|---|---|---|---|
| `ENTITLEMENT_LIMIT_REACHED` | 422 | false | Merchant's plan limit (e.g. `active_listings`) exceeded. |

### `ai`

| Code | HTTP | Retryable | Meaning |
|---|---|---|---|
| `AI_BUDGET_EXHAUSTED` | 429 | true | Cost ceiling reached (§B.2.2); caller should use/expect the deterministic fallback. Also emitted as an event — see `EVENTS.md`. |
| `AI_PROVIDER_UNAVAILABLE` | 503 | true | Upstream AI provider unreachable or erroring. |

### `telegram`

| Code | HTTP | Retryable | Meaning |
|---|---|---|---|
| `TELEGRAM_UNAVAILABLE` | 503 | true | Telegram Bot API unreachable or erroring. |

---

## Naming convention for codes not yet needed

New modules (`verification`, `requests`, `trust_safety`, `ai`, `billing`, …) need their own codes as they're built. Follow the existing pattern instead of inventing a new shape:

```
<RESOURCE>_NOT_FOUND            → 404   (e.g. VERIFICATION_NOT_FOUND, REQUEST_NOT_FOUND)
<RESOURCE>_INVALID_TRANSITION   → 409   (state-machine violations)
<RESOURCE>_NOT_ELIGIBLE         → 422   (business-rule refusal, not a state-machine violation)
```

Add the row to this file in the same task that introduces the code (§B.10.3). Do not front-load codes for modules that don't exist yet.
