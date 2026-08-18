# Module: catalog
**Purpose:** Locations, categories, dynamic attributes, products, and product variants. Locations are seed-backed and shared by downstream modules; products are merchant-agnostic.
**Status:** in_progress
**Depends on:** core, localization

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| GET | /trade/v1/categories | `?page? &per_page? &type? &active? &parent_id?` | paginated categories `{id,parent_id,slug,name_key,name,type,active}` | public | VALIDATION_FAILED |
| GET | /trade/v1/categories/{id}/attributes | `?page? &per_page?` | paginated attributes `{id,category_id,key,label_key,label,data_type,required,options_json,sort}` | public | VALIDATION_FAILED, CATEGORY_NOT_FOUND |
| GET | /trade/v1/products | `?page? &per_page? &category_id? &status? &q?` | paginated products `{id,category_id,category_name_key,category_name,canonical_name,attributes_json,created_by,status,variants[]}` | public | VALIDATION_FAILED |
| POST | /trade/v1/products | `{category_id, canonical_name, attributes_json, variants?[]}` | created product row (same shape as GET /products) | `tb_session` | VALIDATION_FAILED, CATEGORY_NOT_FOUND |

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| — | none | this phase |

## Events Consumed
| Event | Action |
|-------|--------|
| — | none |

## Owned Tables
- tb_locations
- tb_categories
- tb_category_attributes
- tb_products
- tb_product_variants

## Invariants
- Locations are seed-backed and must exist before identity/profile validation accepts them.
- Category attributes are dynamic, but required keys must be present when a product is created.
- Product variants are merchant-agnostic and stored separately from the base product row.
- Collections are paginated and returned in stable order.
- User-facing names and labels resolve through `tb_translations`; missing translations fall back to `en`, then the key.
- No price, stock, or merchant ownership lives here.
