# Module: merchant
**Purpose:** Merchant profile, type, location, verification read state, and entitlement reads.
**Status:** in_progress
**Depends on:** core, identity, localization, catalog

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| GET | /trade/v1/merchants/{id} | — | `{id, business_name, merchant_type, location_id, verification_status, verified_at, suspended_at}` | public | MERCHANT_NOT_FOUND |
| POST | /trade/v1/merchants | `{business_name, merchant_type, location_id}` | same merchant shape as GET | `tb_session` + `tb_manage_own_merchant_profile` | VALIDATION_FAILED, LOCATION_NOT_FOUND |
| PATCH | /trade/v1/merchants/{id} | any subset of `{business_name?, merchant_type?, location_id?}` | same merchant shape as GET | `tb_session` + `tb_manage_own_merchant_profile` + ownership | VALIDATION_FAILED, LOCATION_NOT_FOUND, FORBIDDEN_NOT_OWNER, MERCHANT_NOT_FOUND |

## Service API
- `Service::find_merchant($merchant_id)` → merchant row or `null`
- `Service::merchant_is_verified($merchant_id)` → `bool`
- `Service::require_verified_merchant($merchant_id)` → throws `MERCHANT_NOT_VERIFIED`
- `Service::entitlement_value($merchant_id, $key)` → stored entitlement value or `null`
- `Service::entitlement_int($merchant_id, $key, $default = 0)` → integer entitlement value

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| — | none | this phase |

## Events Consumed
| Event | Action |
|-------|--------|
| — | none |

## Owned Tables
- tb_merchants
- tb_entitlements
- tb_subscriptions

## Invariants
- Merchant profiles are one per `wp_user_id`.
- `POST /merchants` is idempotent for an existing profile; retries must not create duplicates.
- `PATCH /merchants/{id}` can only touch the caller's own row.
- `verification_status` is read-only in this module; verification owns writes to that field.
- Location references are validated through catalog.
- Entitlements are checked by key, never by plan name.
