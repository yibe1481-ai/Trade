# Module: listings
**Purpose:** Merchant listings of catalog products (and variants), per-listing inventory (stock) or service availability, listing state machine (§B.6.1), and listing images with server-derived thumbnails.
**Status:** in_progress
**Depends on:** core, catalog, merchant

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| POST | /trade/v1/listings | `{product_id, variant_id?, price, currency?, location_id}` | listing (status=DRAFT) | `tb_session` + merchant profile | VALIDATION_FAILED, MERCHANT_NOT_VERIFIED |
| GET | /trade/v1/listings | `?page? &per_page? &category_id? &location_id? &price_min? &price_max? &merchant_id?` | paginated listings (ACTIVE only, unless owner's own) | public | VALIDATION_FAILED, FORBIDDEN_NOT_OWNER |
| GET | /trade/v1/listings/{id} | — | listing | public (owner sees non-ACTIVE) | LISTING_NOT_FOUND |
| PATCH | /trade/v1/listings/{id} | `{price?, currency?, location_id?, version}` | listing | `tb_manage_own_listings` + ownership | VALIDATION_FAILED, LISTING_NOT_FOUND, FORBIDDEN_NOT_OWNER, CONFLICT_STALE_VERSION, LOCATION_NOT_FOUND |
| POST | /trade/v1/listings/{id}/status | `{to, version, note?}` | `{status, version, published_at?}` | ownership (merchant) / admin | LISTING_NOT_FOUND, FORBIDDEN_NOT_OWNER, CONFLICT_STALE_VERSION, LISTING_INVALID_TRANSITION, MERCHANT_NOT_VERIFIED, ENTITLEMENT_LIMIT_REACHED, VALIDATION_FAILED |
| POST | /trade/v1/listings/{id}/images | multipart `file` | `{image_id, image_url}` | `tb_manage_own_listings` + ownership | LISTING_NOT_FOUND, FORBIDDEN_NOT_OWNER, ENTITLEMENT_LIMIT_REACHED, VALIDATION_FAILED |
| GET | /trade/v1/listings/{id}/images | — | `images[]` | public | LISTING_NOT_FOUND |
| DELETE | /trade/v1/listings/{id}/images/{image_id} | — | `{deleted: true}` | ownership | LISTING_NOT_FOUND, LISTING_IMAGE_NOT_FOUND, FORBIDDEN_NOT_OWNER |
| PATCH | /trade/v1/inventory/{listing_id} | `{stock, sku?, version}` | `{stock, sku, version}` | ownership | LISTING_NOT_FOUND, FORBIDDEN_NOT_OWNER, CONFLICT_STALE_VERSION |
| PATCH | /trade/v1/availability/{listing_id} | `{availability_state, note?}` | `{availability_state, note}` | ownership | LISTING_NOT_FOUND, FORBIDDEN_NOT_OWNER, VALIDATION_FAILED |

> `currency` defaults to `ETB`. `price` is integer minor currency units (§B.4). All mutating endpoints accept `Idempotency-Key` (§B.8).
> Status values: `DRAFT`, `PENDING_REVIEW`, `ACTIVE`, `PAUSED`, `OUT_OF_STOCK`, `REJECTED`, `ARCHIVED`.
> Availability states (service listings): `AVAILABLE_TODAY`, `AVAILABLE_THIS_WEEK`, `BOOKED`, `UNAVAILABLE`.

## Service API (used by other modules)
- `Service::find_listing($listing_id, ?Store)` → formatted listing or `null`
- `Service::find_listing_row($listing_id, ?Store)` → raw row (for stock updates)
- `Service::listing_is_active($listing_id, ?Store)` → `bool`
- `Service::require_active_listing($listing_id, ?Store)` → throws `LISTING_NOT_AVAILABLE`
- `Service::merchant_owns_listing($listing_id, $merchant_id, ?Store)` → `bool`
- `Service::decrement_stock($listing_id, $qty)` → `bool` — §B.7.2 atomic conditional UPDATE
- `Service::rebuild_search_text($listing_id, ?Store)` — §B.11.1 denormalized column

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| LISTING_CREATED | `{listing_id, merchant_id}` | Listing row inserted (status=DRAFT) |
| LISTING_PUBLISHED | `{listing_id, merchant_id, product_id, category_id, published_at}` | Listing enters ACTIVE (from any state) |
| LISTING_REJECTED | `{listing_id, merchant_id, reviewed_by, note}` | Admin transitions PENDING_REVIEW → REJECTED |
| LISTING_ARCHIVED | `{listing_id, merchant_id}` | Listing enters ARCHIVED (terminal) |

## Events Consumed
| Event | Action |
|-------|--------|
| MERCHANT_VERIFICATION_REVOKED | Pause all ACTIVE listings (system actor: ACTIVE → PAUSED) |

## Owned Tables
- tb_listings
- tb_inventory
- tb_service_availability
- tb_listing_images

## Invariants
- Stock mutations are atomic conditional UPDATEs (§B.7.2): `UPDATE tb_inventory SET stock=…, version=version+1 WHERE listing_id=? AND version=current AND stock>=qty`. Negative stock impossible by construction.
- `version` required on every PATCH (listing, inventory, images, status); mismatch → `CONFLICT_STALE_VERSION` (409). §B.7.3.
- Only verified merchants reach PENDING_REVIEW. §B.6.1.
- search_text rebuilt on every listing write from product canonical_name, merchant business_name, category name (both languages), and indexed attribute values. §B.11.1.
- Thumbnails are derived by `listing.image_process` job (§B.9.4), never client-supplied; storage_key is server-assigned, thumb_key null until processed.
- Image cap via `images_per_listing` entitlement (default 5) → `ENTITLEMENT_LIMIT_REACHED` (422).
- Deleting a listing deletes its images (cascade).
- ARCHIVED is terminal. §B.6.1.
- `inventory` and `availability` are mutually exclusive per listing (product-type → inventory, service-type → availability).
