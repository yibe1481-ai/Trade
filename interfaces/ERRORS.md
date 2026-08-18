# Trade Bot — Global Error Taxonomy

**Version:** 1.4 (MVP)
**Spec reference:** Master Specification v1.5 · §B.10

Codes are stable identifiers. Never rename them. User-facing text is resolved from `tb_translations`; the error `message` is safe developer-facing text.

## Envelope

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

`retryable` is true only for transient 429/503 failures. Context must contain safe diagnostic information; never include credentials, tokens, identity documents, or other sensitive data.

## Registry

### core

| Code | HTTP | Retryable | Meaning |
|---|---:|:---:|---|
| `VALIDATION_FAILED` | 400 | false | Request validation failed. `context.fields[]` identifies fields. |
| `FORBIDDEN_CAPABILITY` | 403 | false | Session lacks the required capability. |
| `FORBIDDEN_NOT_OWNER` | 403 | false | Caller does not own the resource. |
| `RATE_LIMITED` | 429 | true | A configured rate limit was exceeded. |
| `IDEMPOTENCY_KEY_REUSED` | 422 | false | Same idempotency key was used for a different request. |
| `REQUEST_IN_PROGRESS` | 409 | true | Same idempotent operation is currently being processed. |
| `CONFLICT_STALE_VERSION` | 409 | false | Optimistic-lock version is stale. |
| `JOB_LEASE_LOST` | 409 | false | Worker lease was lost or reclaimed. |
| `INTERNAL_ERROR` | 500 | false | Unexpected server error. |

### identity

| Code | HTTP | Retryable | Meaning |
|---|---:|:---:|---|
| `AUTH_INVALID_SIGNATURE` | 401 | false | Telegram initData signature failed. |
| `AUTH_EXPIRED_INITDATA` | 401 | false | Telegram initData is outside the allowed age window. |
| `AUTH_REPLAY_DETECTED` | 401 | false | Signed initData was reused. |
| `AUTH_SESSION_EXPIRED` | 401 | false | Trade Bot session expired or was revoked. |

### merchant

| Code | HTTP | Retryable | Meaning |
|---|---:|:---:|---|
| `MERCHANT_NOT_FOUND` | 404 | false | Merchant does not exist or is not visible. |
| `MERCHANT_NOT_VERIFIED` | 403 | false | Operation requires a verified merchant. |

### verification

| Code | HTTP | Retryable | Meaning |
|---|---:|:---:|---|
| `VERIFICATION_INVALID_TRANSITION` | 409 | false | Requested verification status transition is not legal. |
| `VERIFICATION_ADMIN_REQUIRED` | 403 | false | Only an authorized administrator may review/revoke verification. |

### catalog

| Code | HTTP | Retryable | Meaning |
|---|---:|:---:|---|
| `LOCATION_NOT_FOUND` | 404 | false | Location does not exist or is not visible. |
| `CATEGORY_NOT_FOUND` | 404 | false | Category does not exist or is not visible. |

### listings

| Code | HTTP | Retryable | Meaning |
|---|---:|:---:|---|
| `LISTING_NOT_FOUND` | 404 | false | Listing does not exist or is not visible. |
| `LISTING_IMAGE_NOT_FOUND` | 404 | false | Listing image does not exist on the listing. |
| `LISTING_INVALID_TRANSITION` | 409 | false | Listing state transition is illegal. |
| `LISTING_NOT_AVAILABLE` | 409 | false | Listing is not currently active/available. |
| `INVENTORY_INSUFFICIENT_STOCK` | 409 | false | Atomic stock decrement could not be completed. |

### orders

| Code | HTTP | Retryable | Meaning |
|---|---:|:---:|---|
| `ORDER_NOT_FOUND` | 404 | false | Order does not exist or is not visible. |
| `ORDER_INVALID_TRANSITION` | 409 | false | Order state transition is illegal. |
| `ORDER_ALREADY_OPEN` | 409 | false | An open order already exists for the customer/listing pair. |
| `REVIEW_NOT_ELIGIBLE` | 422 | false | Review conditions are not satisfied. |

### requests

| Code | HTTP | Retryable | Meaning |
|---|---:|:---:|---|
| `REQUEST_NOT_FOUND` | 404 | false | Customer request does not exist or is not visible. |
| `REQUEST_INVALID_TRANSITION` | 409 | false | Request state transition is illegal. |

### billing

| Code | HTTP | Retryable | Meaning |
|---|---:|:---:|---|
| `ENTITLEMENT_LIMIT_REACHED` | 422 | false | Merchant entitlement limit was reached. |

### ai

| Code | HTTP | Retryable | Meaning |
|---|---:|:---:|---|
| `AI_BUDGET_EXHAUSTED` | 429 | true | AI cost ceiling reached; deterministic fallback must be used. |
| `AI_PROVIDER_UNAVAILABLE` | 503 | true | AI provider is temporarily unavailable. |

### telegram

| Code | HTTP | Retryable | Meaning |
|---|---:|:---:|---|
| `TELEGRAM_UNAVAILABLE` | 503 | true | Telegram Bot API is temporarily unavailable. |

## Rules for new codes

New codes must be added to this registry and the runtime mapping in `src/Core/Error.php` in the same change. Use stable names such as:

```text
<RESOURCE>_NOT_FOUND
<RESOURCE>_INVALID_TRANSITION
<RESOURCE>_NOT_ELIGIBLE
```

Never expose raw exceptions or provider-specific errors to clients.
