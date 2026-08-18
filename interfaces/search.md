# Module: search
**Purpose:** Deterministic FULLTEXT search over listings + fixed-weight ranking (§B.11). No AI calls while `ai_search_enabled=false`.
**Status:** in_progress
**Depends on:** core, catalog, listings, merchant

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| GET | /trade/v1/search | `?q=…&category_id? &location_id? &price_min? &price_max? &merchant_id? &page? &per_page?` | paginated, ranked listings | public | VALIDATION_FAILED |

> `q` is required. `price` is integer minor currency units (§B.4). All filters are optional conjunctions (AND).
> Returns only listings in `ACTIVE`, `PAUSED`, or `OUT_OF_STOCK` status (PUBLIC_STATES), unless `merchant_id` is specified (then that merchant's listings in any status are visible to the owner).

## Service API (used by other modules)
- `Service::search_listings($q, array $filters)` → array of ranked, formatted listings
- `Service::normalize_query($q)` → NFC-normalized, casefolded, punctuation-stripped query
- `Service::extract_terms($normalized)` → array of individual query terms
- `Service::rank($row, $terms, $filters, ?Store)` → float score per §B.11.3
- `Service::empty_result_suggestions($q, $filters, ?Store)` → relaxed-filter hints (§B.11.4)

## Ranking (§B.11.3)
Fixed-weight formula — configuration constants, not ML:
```
score = 0.40 × text_relevance        (fraction of query terms in search_text)
      + 0.20 × location_proximity   (exact location 1.0, else 0.1)
      + 0.15 × merchant_verified    (1 | 0)
      + 0.10 × availability         (in-stock / available_today 1.0 else 0.0)
      + 0.10 × freshness            (linear decay over 30 days)
      + 0.05 × completed_txn_signal (0 at MVP — no orders module)
```
Weights are configuration per spec. Subscription plan is not a ranking input. Ties break by `listing_id` ASC for stable pagination.

## Events Consumed
| Event | Action |
|-------|--------|
| LISTING_PUBLISHED | — (search reads live; no index maintenance needed for FULLTEXT) |
| LISTING_ARCHIVED | — (listed in PUBLIC_STATES filter) |

## Owned Tables
- none (read-only — queries `tb_listings`, `tb_inventory`, `tb_service_availability`, `tb_merchants`, `tb_locations`, `tb_categories`, `tb_products` via service API of other modules)

## Invariants
- `q` parameter is required; empty → VALIDATION_FAILED (400).
- No AI calls while `ai_search_enabled=false` (§B.2.1 flag). Determinism is the contract.
- Query normalization: Unicode NFC, casefold, punctuation stripped, whitespace collapsed. Amharic-safe (§B.11.2).
- `search_text` is rebuilt on every listing write by the listings module (§B.11.1); search only reads it.
- Empty results return relaxed-filter suggestions (§B.11.4): drop price, then location, then category; nearest-category fallback is future work.
- Only PUBLIC_STATES visible in unscoped search; merchant_id scope shows own listings in any status.
