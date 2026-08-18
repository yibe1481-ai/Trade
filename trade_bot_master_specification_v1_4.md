Below is the rewritten specification as **v1.3**. It is restructured rather than appended-to, because the single biggest defect in v1.2 was length and internal contradiction, not missing ideas.

**What I did:**
- Split the document into three parts with a hard precedence rule, so "which section wins" is never ambiguous.
- Compressed the original 75 sections into a canonical baseline (Part A) that preserves every decision but stops re-explaining itself.
- Added the missing engineering layer (Part B): normative auth, state machines, concurrency, idempotency, error taxonomy, acceptance-criteria contract.
- Kept your cost objective central — Part B is written so an agent can build a module from ~3 attached files, never the whole document.

---

# Trade Bot — Master Product & Technical Specification

**Version:** 1.5
**Status:** Implementation Baseline — Ready for Phase 0
**Supersedes:** v1.1 (Sections 1–75), v1.2 addendums (Sections 76–77), v1.3 (Parts A/B/C), v1.4, v1.5 (thumbnail support)
**Platform:** Telegram Bot + Telegram Mini App + WordPress + Python Worker Service
**Primary Market:** Ethiopia

---

## 0. How To Read This Document

This document has three parts with **strict precedence**. If two statements conflict, the higher part wins.

| Part | Name | Binding? | Audience |
|---|---|---|---|
| **B** | MVP Engineering Specification | **Normative — this is what gets built** | Implementers, AI coding agents |
| **A** | Product & Architecture Baseline | Directional — constrains B, does not override it | Product, architecture review |
| **C** | Deferred Decisions & Future Architecture | Non-binding — must not be implemented | Planning |

**Precedence rule:** Part B > Part A > Part C. A capability described in Part A that is absent from Part B is **not in scope**.

### Normative language

| Term | Meaning |
|---|---|
| **MUST** | Required. A build is non-conformant without it. |
| **SHOULD** | Strongly expected. Deviation requires a note in the module's `MODULE.md`. |
| **MAY** | Optional. |
| *(descriptive prose)* | Context only. Never a requirement. |

### What an AI coding agent receives

Never this document. See **§B.14 Agent Context Protocol**.

---

## 0.1 Changelog v1.2 → v1.3

| Change | Reason |
|---|---|
| Document split into Parts A/B/C with precedence rule | v1.2 contained ~9 unresolvable MVP-vs-future contradictions (verification levels, AI search, billing, no-code languages) |
| Old §1–75 compressed into Part A §A.1–A.12 | Repetition across 75 sections was a maintenance and agent-cost liability |
| **New:** normative auth spec (§B.3) | v1.2 said "verify initData" without session, replay, or expiry rules |
| **New:** order & listing state machines with actor permissions (§B.6) | State lists existed; legal transitions did not |
| **New:** inventory concurrency rules (§B.7) | Overselling was unaddressed |
| **New:** idempotency & job semantics (§B.8, §B.9) | Worker was specified as at-least-once by implication only |
| **New:** error taxonomy + HTTP mapping (§B.10) | v1.2 §77 defined an envelope but no status codes or registry |
| **New:** acceptance-criteria contract + worked examples (§B.13) | No definition of "done" existed anywhere |
| **Revised:** DM trust loop — single-sided confirmation no longer increments trust (§B.6.3) | v1.2 §76 was trivially gameable |
| **Revised:** search made explicit for Amharic/English (§B.11) | v1.2 assumed English tokenization |
| **Revised:** compliance skeleton given lawful-basis table and access logging (§B.12) | v1.2 named the law but not the controls |
| MVP scope restated as a single truth table (§B.1) | Scope was previously spread across §64, §65, §73, §74 |

## 0.2 Changelog v1.3 → v1.4 (pre-Phase-0 readiness pass)

| Change | Reason |
|---|---|
| §A.9 module map corrected to match Part B | Listed the old 21-module breakdown (`marketplace`/`products`/`inventory`/`services`/`matching`/`customer`) while Part B and `INDEX.md` had already consolidated to `catalog`/`listings`/`requests`; Part B wins by precedence, but Part A was misleading as written |
| `tb_customer_profiles` ownership assigned to `identity` | Table existed in §B.4 with no owning module and no endpoint in §B.5 after `customer` was dropped from the module map — orphaned data |
| `POST /merchants`, `PATCH /merchants/{id}` added to §B.5 | No endpoint existed for a merchant to create or edit their own profile — onboarding could not have shipped without this |
| `GET/PATCH /me` scope clarified to include customer profile fields | Closes the same gap on the customer side without a separate module |
| §B.6.3/§B.7.1 relationship made explicit | Dual-confirmation `COMPLETED` could previously occur with stock never decremented if a merchant skipped `ACCEPTED`; now completion auto-reconciles stock |
| `interfaces/ERRORS.md` populated | Was an envelope shell with no actual codes despite ~25 being named in the spec text |
| `interfaces/EVENTS.md` created | Referenced in §B.14.5 as load-bearing for cross-module communication (§A.8) but did not exist |

## 0.3 Changelog v1.4 → v1.5 (thumbnail support)

| Change | Reason |
|---|---|
| `tb_listing_images` table + index added (§B.4) | `listing.image_process` (§B.9.4) and the "optimized images" note (A.11) existed, but listings had no persisted image model for the job to write to — the thumbnail pipeline had no target |
| `POST/GET/DELETE /listings/{id}/images` added (§B.5) | No endpoint existed to attach or read listing images; search results and listing detail need a thumbnail |
| `listing.image_process` contract defined (§B.9.4) | The job was named but its output (`thumb_key`) unspecified; thumbnails are derived server-side, never client-supplied |
| `LISTING_IMAGE_NOT_FOUND` registered (ERRORS.md) | New resource 404; the per-listing cap reuses the existing `ENTITLEMENT_LIMIT_REACHED` instead of a new code |
| Per-listing image cap defined | Enforced via existing `tb_entitlements` key `images_per_listing` (default 5) — no new schema |
| `index.md` `listings` row updated | Owned tables and public surface now list image endpoints/schema |

---

# PART A — Product & Architecture Baseline

*Directional. Constrains Part B. Does not itself authorize implementation.*

## A.1 Product Vision

Trade Bot is an **AI-assisted local commerce platform delivered through Telegram**, connecting customers seeking products or services with merchants and service providers.

**Core philosophy:** *Simple on the surface, powerful underneath.* The user explains what they want naturally; the system converts that intent into structured marketplace operations.

Telegram is the initial distribution channel, not a permanent architectural constraint.

## A.2 Product Principles

1. Intent-first UX
2. AI-assisted, never AI-dependent
3. Configuration over coding
4. Modular architecture
5. Security and trust by design
6. Mobile-first, low-bandwidth friendly
7. High-conversion UX
8. Progressive disclosure
9. Reusable components
10. Strict separation of concerns
11. Minimize AI/API cost
12. Build for future expansion **without overbuilding the MVP**

Principle 12 is enforced structurally by Part C, not by intent.

## A.3 Interfaces

**Telegram Bot** — conversational gateway: welcome, language, intent/role identification, minimal onboarding, notifications, confirmations, re-entry into the Mini App.

**Telegram Mini App** — primary visual application: marketplace, search, browsing, product detail, merchant profiles, customer requests, merchant dashboard (products, inventory, orders, verification, settings).

**Invariant:** the Mini App MUST open contextually based on onboarding state already captured. A user never re-enters information the bot already collected.

## A.4 Domain Model (conceptual)

| Concept | Definition |
|---|---|
| **Product** | What an item or service *is*. Shared across merchants. |
| **Listing** | A specific merchant's offer of a product (price, stock, location). |
| **Service** | First-class alongside products. Uses *availability*, not inventory. |
| **Customer Request** | A structured "I need X" record that merchants respond to. First-class object. |
| **Order** | A tracked transaction record between a customer and a merchant. |
| **Merchant** | A seller or service provider, subject to mandatory verification. |

Multiple merchants MAY list the same product. This enables comparison and better search.

## A.5 Identity, Verification, Trust — Three Separate Things

| Concept | Question answered |
|---|---|
| Identity | Who are you? (Telegram User ID) |
| Verification | What information has been checked? |
| Trust | How have you behaved? |

**Frozen decisions:** Telegram User ID is the immutable external identity. Merchant verification is mandatory. Verification begins manual. Verification and trust never merge. **Subscription never buys trust or verification.**

## A.6 Intent-First Architecture

Fundamental intents: `FIND`, `SELL`, `OFFER_SERVICE`, `REQUEST_SERVICE`.
Operational intents: `SEARCH`, `CREATE_LISTING`, `EDIT_LISTING`, `CONTACT_MERCHANT`, `CREATE_ORDER`, `CANCEL_ORDER`, `CHANGE_PRICE`, `RESTOCK`, `MANAGE_INVENTORY`, `VERIFY_MERCHANT`, `UPGRADE_PLAN`, `ORDER_SUPPORT`.

## A.7 Universal Action Layer (frozen)

AI MUST NOT mutate the database.

```
AI interpretation → Action validation → Authorization
→ Confirmation (if required) → Application service → Database mutation
```

AI produces a *proposed structured action*. Deterministic code validates, authorizes, and executes it. Financially significant, security-sensitive, or destructive actions require explicit user confirmation.

## A.8 Technical Architecture (frozen)

```
WordPress + custom Trade Bot plugin + custom tables
+ REST API + Telegram Bot + Telegram Mini App
+ separate Python Worker Service
```

- WooCommerce is **not** the marketplace core.
- Marketplace operational data lives in custom tables with the plugin's own prefix.
- WordPress provides admin, auth primitives, capabilities, cron, REST infrastructure, media.
- The Worker Service is **not** a plugin module. It reaches the system only through the same REST surface as any other client.
- Layering: `Client → REST → Controller → Service → Repository → Database`.
- Modules communicate across boundaries via **events**, not direct calls.

## A.9 Module Map

```
core · identity · localization · telegram
catalog · merchant · listings
search · orders · requests · verification · trust_safety
ai · billing · notifications · analytics · admin · mini_app
```

External to the plugin: **worker** (Python service; not a module — see §A.8).

`identity` owns customer-facing profile data (`tb_customer_profiles`) alongside session/auth — there is no separate `customer` module. A profile thin enough to be a handful of fields on "who is this person" doesn't earn its own boundary; splitting it out was v1.3's one over-decomposition.

A module MUST know as little as possible about other modules. Product logic contains no Telegram logic. Billing is never embedded in merchant logic.

## A.10 AI Architecture

Centralized AI module: Provider Manager · Model Manager · Task Router · Prompt Manager · Usage Tracker · Fallback Manager.

**Cost ladder (mandatory order of evaluation):**

```
Can deterministic code answer this?   → YES: no AI call
Can a cheap model handle it?          → YES: cheap model
Otherwise                             → stronger model
```

AI failure MUST degrade, never block: structured search continues, manual listing creation works, DB translations continue, navigation works, orders work, verification falls back to manual.

## A.11 Cross-Cutting Requirements

**Security:** signed-data verification, server-side authorization, capability checks, input validation, REST permission checks, rate limiting, audit logs, secure document handling, destructive-action confirmation, secure webhooks.

**Privacy:** never auto-expose exact addresses, private phone numbers, identity documents, government ID data, or verification internals. Use "Contact merchant" instead.

**Audit:** `actor · action · entity · entity_id · timestamp · source · before · after · metadata`. Mandatory for verification, price changes, inventory changes, orders, subscription changes, admin actions, security events, consent events.

**Configuration over code:** languages, translations, categories, attributes, verification requirements, limits, plans, entitlements, AI providers/models/tasks, notification templates, risk thresholds, feature flags.

**Performance:** lightweight Mini App, lazy loading, optimized images, pagination, DB indexing, caching where appropriate, background processing, minimal JavaScript.

## A.12 Frozen Architectural Decisions

1. Telegram-first experience.
2. Telegram User ID is the primary external identity.
3. Telegram signed initData is verified server-side; raw client-supplied IDs are never trusted.
4. Bot owns conversation; Mini App owns visual workflows.
5. Mini App opens contextually from onboarding state.
6. Languages and fixed UI text are database-driven.
7. Merchant verification is mandatory and starts manual.
8. Verification ≠ trust; subscription buys neither.
9. WordPress is the initial backend; WordPress Admin hosts the Trade Bot Control Center.
10. WooCommerce is not the marketplace core; marketplace data uses custom tables.
11. REST is the primary client/backend interface.
12. System is modular and event-aware.
13. AI never performs uncontrolled database mutations and is never a single point of failure.
14. AI routing is configurable; AI usage and cost are tracked **and enforced**.
15. Merchant subscriptions are separate from marketplace payments; customer payments are deferred.
16. Products, listings, services, and customer requests are distinct first-class entities.
17. Search and ranking are separate concerns.
18. Audit logging exists from day one.
19. AI coding agents work module-by-module and do not modify unrelated modules.
20. **The MVP stays deliberately small.**

---

# PART B — MVP Engineering Specification

*Normative. This is the build.*

## B.1 MVP Scope Truth Table

This table is the **only** authority on scope. It resolves all prior ambiguity.

| Capability | MVP status | Notes |
|---|---|---|
| WordPress plugin, custom tables, migrations | **BUILD** | |
| REST API v1 | **BUILD** | §B.5 |
| Telegram initData auth + sessions | **BUILD** | §B.3 |
| Bot webhook + onboarding flow | **BUILD** | |
| Mini App shell, customer + merchant flows | **BUILD** | |
| Localization | **BUILD — `en` + `am` only** | DB strings. **No** no-code add-language admin UI. |
| Feature flags | **BUILD** | §B.2 |
| Events + audit logging | **BUILD** | |
| Categories + dynamic attributes | **BUILD** | Admin CRUD |
| Products, Listings, Services | **BUILD** | |
| Inventory (stock, price, variants) | **BUILD** | Single location only. §B.7 |
| Service availability | **BUILD** | Simple enum. §B.7.4 |
| Search | **BUILD — deterministic only** | §B.11 |
| Ranking | **BUILD — fixed weighted formula** | §B.11.3 |
| Customer Requests | **BUILD** | |
| Matching | **BUILD — rule-based** | category + location + budget. No ML. |
| Orders + DM trust loop | **BUILD** | §B.6 |
| Merchant verification | **BUILD — binary `pending`/`verified`/`rejected`** | **No L0–L4.** |
| Reports / moderation queue | **BUILD** | |
| Reviews | **BUILD — order-gated** | §B.6.4 |
| Reputation display | **BUILD — raw signals only** | verified badge, completed count, rating, account age. No score. |
| Notifications (Telegram + in-app) | **BUILD** | Email/SMS deferred. |
| Worker Service + `tb_jobs` | **BUILD** | §B.9 |
| AI: intent detection | **BUILD** | Cheap model, cached. |
| AI: listing extraction assistant | **BUILD** | Merchant confirms before publish. |
| AI: search assistance | **SCHEMA/FLAG ONLY — OFF** | `ai_search_enabled = false` |
| AI: conversational assistant | **SCHEMA/FLAG ONLY — OFF** | `ai_assistant_enabled = false` |
| AI cost governance (limits, ceilings, cache) | **BUILD** | §B.2.2 — enforcement, not just tracking |
| Subscriptions / entitlements | **SCHEMA ONLY** | `billing_enabled = false`. Pro granted manually by admin. |
| Payment provider integration | **DO NOT BUILD** | |
| Trade Bot Control Center (admin) | **BUILD** | |
| Backups + tested restore | **BUILD** | Release gate. §B.12.4 |

Anything not in this table is out of scope. See Part C.

## B.2 Configuration & Flags

### B.2.1 Launch flag values

| Flag | Value |
|---|---|
| `ai_intent_detection_enabled` | `true` |
| `ai_listing_assistant_enabled` | `true` |
| `ai_search_enabled` | `false` |
| `ai_assistant_enabled` | `false` |
| `billing_enabled` | `false` |
| `reviews_enabled` | `true` |
| `customer_requests_enabled` | `true` |
| `payments_enabled` | `false` |
| `delivery_enabled` | `false` |

Flags are boolean at MVP. Targeting (role, plan, percentage) is deferred.

### B.2.2 AI cost governance (normative)

| Control | MVP value |
|---|---|
| Per-user AI-invoking actions | 30 / hour, 200 / day |
| Per-provider daily cost ceiling | Configurable; default set at deploy |
| Behavior at ceiling | Demote to cheapest model; if still exceeded, disable AI path and return deterministic behavior |
| Intent-detection cache | Normalized query hash, 24h TTL, per-language |
| Default model | Cheapest configured. Escalate only on low confidence or explicit complex task. |
| Ceiling breach | Emits `AI_BUDGET_EXHAUSTED` event → admin notification |

"AI unavailable" (A.10) explicitly includes "budget exhausted." Every AI call site MUST have a non-AI path.

## B.3 Authentication & Authorization (normative)

### B.3.1 Mini App session establishment

```
1. Mini App sends raw initData string to POST /trade/v1/auth/session
2. Server computes:
     secret_key = HMAC_SHA256(key="WebAppData", msg=bot_token)
     check_hash = HMAC_SHA256(key=secret_key, msg=data_check_string)
3. Constant-time compare against supplied hash → else AUTH_INVALID_SIGNATURE
4. Reject if (now - auth_date) > 300 seconds → AUTH_EXPIRED_INITDATA
5. Reject if hash seen before within 300s window (replay table) → AUTH_REPLAY_DETECTED
6. Extract telegram_user_id from verified payload ONLY
7. Find or create wp_user; link via immutable tb_identity.telegram_user_id (UNIQUE)
8. Issue opaque session token (32 bytes CSPRNG); store SHA-256 hash server-side
9. Return { session_token, expires_at, onboarding_state, role }
```

**MUST NOT:** trust any `telegram_user_id`, `role`, `merchant_id`, or `is_verified` value sent by a client under any circumstance.

### B.3.2 Session rules

| Property | Value |
|---|---|
| Transport | `Authorization: Bearer <token>` header |
| Cookies | Not used → no CSRF surface |
| Absolute TTL | 24 hours |
| Idle TTL | 2 hours |
| Storage | SHA-256 hash only; plaintext never persisted |
| Revocation | On logout, verification revocation, admin suspend, role change |
| Rotation | New token on privilege change |

### B.3.3 Bot webhook

- Registered with `secret_token`; every update MUST validate `X-Telegram-Bot-Api-Secret-Token` in constant time → else 403, logged as security event.
- Webhook endpoint is rate-limited and IP-loggable.
- Callback-query payloads MUST be re-authorized server-side against the acting Telegram user. A callback button is not proof of authority.

### B.3.4 Authorization model

Every endpoint declares:

| Field | Example |
|---|---|
| `capability` | `tb_manage_own_listings` |
| `ownership_rule` | `listing.merchant_id == session.merchant_id` |
| `verification_gate` | `merchant.status == verified` |

Capability + ownership are checked **in the service layer**, not only at the route. Hiding a menu item is never an authorization control.

### B.3.5 Rate limits (defaults)

| Scope | Limit |
|---|---|
| Auth session creation / Telegram ID | 10 / min |
| Write endpoints / session | 60 / min |
| Search / session | 30 / min |
| Contact merchant / customer | 10 / hour, 30 / day |
| Report submission / user | 5 / day |
| Bot webhook / Telegram ID | 30 / min |

Exceeding returns `RATE_LIMITED` (429) with `Retry-After`.

## B.4 Data Model (MVP tables)

Prefix is plugin-owned, not assumed `wp_`.

**Money:** every price/amount column (`tb_listings.price`, and any added later) is an integer in minor currency units (e.g. cents). Floats are forbidden for currency anywhere in the system — this is not specific to bulk operations (§B.7.5 relies on it, it doesn't establish it).

```
tb_identity              telegram_user_id (UNIQUE), wp_user_id, language, created_at
tb_sessions              token_hash, wp_user_id, issued_at, last_seen_at, expires_at, revoked_at
tb_customer_profiles     wp_user_id, display_name, location_id, created_at
tb_merchants             wp_user_id, business_name, merchant_type, location_id,
                         verification_status, verified_at, suspended_at
tb_locations             parent_id, level, name_key           -- hierarchy, seeded
tb_languages             code, name, native_name, direction, enabled, is_default
tb_translations          language_code, string_key, value     -- UNIQUE(language_code,string_key)
tb_categories            parent_id, slug, name_key, type(product|service), active
tb_category_attributes   category_id, key, label_key, data_type, required, options_json, sort
tb_products              category_id, canonical_name, attributes_json, created_by, status
tb_product_variants      product_id, variant_key, attributes_json
tb_listings              merchant_id, product_id, variant_id, price, currency,
                         location_id, status, published_at, search_text, version
tb_listing_images        listing_id, storage_key, thumb_key, sort_order, created_at
tb_inventory             listing_id (UNIQUE), stock, sku, updated_at, version
tb_service_availability  listing_id (UNIQUE), availability_state, note, updated_at
tb_customer_requests     customer_id, category_id, attributes_json, budget_max,
                         location_id, urgency, status, expires_at
tb_request_matches       request_id, merchant_id, score, notified_at
tb_orders                customer_id, merchant_id, listing_id, quantity, status,
                         source, customer_confirmed_at, merchant_confirmed_at,
                         created_at, closed_at
tb_reviews               order_id (UNIQUE), author_id, subject_merchant_id,
                         rating, body, created_at
tb_reports               reporter_id, entity_type, entity_id, reason, status, resolved_by
tb_verifications         merchant_id, status, submitted_at, reviewed_by, reviewed_at,
                         decision_note
tb_verification_documents verification_id, storage_key, doc_type, retention_expires_at
tb_consents              wp_user_id, consent_type, granted, version, created_at
tb_subscriptions         merchant_id, plan_code, status, started_at, ends_at   -- inactive
tb_entitlements          merchant_id, key, value                                -- active
tb_feature_flags         key, enabled, updated_by, updated_at
tb_ai_providers          code, enabled, config_json
tb_ai_models             provider_id, code, tier(cheap|medium|premium), enabled
tb_ai_usage              wp_user_id, task, model_id, tokens_in, tokens_out, cost, created_at
tb_ai_cache              query_hash, language, task, response_json, expires_at
tb_notifications         wp_user_id, channel, template_key, payload_json, status, sent_at
tb_events                event_name, payload_json, created_at
tb_audit_logs            actor_id, actor_type, action, entity, entity_id, source,
                         before_json, after_json, metadata_json, request_id, created_at
tb_jobs                  type, payload_json, run_after, status, attempts, max_attempts,
                         lock_token, lease_expires_at, last_error, idempotency_key
tb_idempotency_keys      key, wp_user_id, endpoint, request_hash, response_json,
                         status_code, expires_at
```

**Indexing MUST include:** `tb_listings(status, category_id, location_id, price)`, `tb_listings(merchant_id, status)`, `tb_listing_images(listing_id, sort_order)`, `tb_orders(merchant_id, status)`, `tb_orders(customer_id, status)`, `tb_jobs(status, run_after)`, `tb_audit_logs(entity, entity_id)`, FULLTEXT on `tb_listings.search_text`.

## B.5 API Contract Rules

- Namespace: `/trade/v1/`.
- Every endpoint MUST be defined in OpenAPI before implementation. **OpenAPI is the source of truth**; `interfaces/<module>.md` links to it.
- Responses: `{ "success": true, "data": {...}, "meta": {...} }` or the error envelope (§B.10).
- Collections MUST paginate: `?page`, `?per_page` (default 20, max 100), `meta.total`, `meta.has_more`.
- All mutating endpoints MUST accept `Idempotency-Key` (§B.8).
- Breaking changes require `/trade/v2/`. Additive fields are non-breaking.
- Every request MUST carry/generate `X-Request-ID`, propagated into logs, audit rows, and job payloads.

### MVP endpoint surface

```
POST   /auth/session
GET    /me                         PATCH /me
POST   /merchants                  PATCH /merchants/{id}
GET    /categories                 GET /categories/{id}/attributes
GET    /products                   POST /products
GET    /listings                   POST /listings
GET    /listings/{id}             PATCH /listings/{id}
POST   /listings/{id}/status
POST   /listings/{id}/images      GET /listings/{id}/images
DELETE /listings/{id}/images/{image_id}
PATCH  /inventory/{listing_id}
PATCH  /availability/{listing_id}
GET    /search
GET    /merchants/{id}
GET    /requests                   POST /requests
PATCH  /requests/{id}
GET    /requests/{id}/matches
POST   /orders                     GET /orders
GET    /orders/{id}                POST /orders/{id}/transition
POST   /reviews
POST   /reports
POST   /verification               GET /verification/status
GET    /entitlements
POST   /internal/jobs/claim        POST /internal/jobs/{id}/complete   (worker only)
```

**Profile ownership (fixes a v1.3 gap — neither endpoint existed):**

- `GET/PATCH /me` covers `tb_identity` + `tb_customer_profiles`: `language`, `display_name`, `location_id`. Owned by `identity`.
- `POST /merchants` creates the caller's own merchant profile (`business_name`, `merchant_type`, `location_id`); one per `wp_user_id`, idempotent on retry. `PATCH /merchants/{id}` edits it. Both require `capability: tb_manage_own_merchant_profile`, `ownership_rule: merchant.wp_user_id == session.wp_user_id`. Neither endpoint may set `verification_status` — that field is written only by the `verification` module.

**Listing images (thumbnail support):** `POST /listings/{id}/images` accepts a file upload from the listing owner; the row is created with a server-assigned `storage_key` and an appended `sort_order`, and `listing.image_process` is enqueued (§B.9.4) to derive the thumbnail into `thumb_key`. **A thumbnail is never client-supplied** — the client uploads the original, the worker derives the thumb. `GET /listings/{id}/images` returns `{ image_id, image_url, thumb_url, sort_order }`; the row with the lowest `sort_order` is the listing's cover in search results. `DELETE /listings/{id}/images/{image_id}` is owner-only. Deleting a listing deletes its images. Cap: `images_per_listing` entitlement (default 5); exceeding it returns `ENTITLEMENT_LIMIT_REACHED` (422). Ownership and versioning follow the listing: `tb_manage_own_listings`, `listing.merchant_id == session.merchant_id`.

## B.6 State Machines (normative)

### B.6.1 Listing

```
DRAFT ──► PENDING_REVIEW ──► ACTIVE ──► PAUSED ──► ACTIVE
                    │            │
                    ▼            ▼
                REJECTED    OUT_OF_STOCK ──► ACTIVE
                                 │
ACTIVE / PAUSED / OUT_OF_STOCK ──┴──► ARCHIVED  (terminal)
```

| Transition | Actor | Guard |
|---|---|---|
| `DRAFT → PENDING_REVIEW` | merchant | merchant `verified`; required attributes complete; entitlement `active_listings` not exceeded |
| `PENDING_REVIEW → ACTIVE` | admin | — |
| `PENDING_REVIEW → REJECTED` | admin | note required |
| `ACTIVE ↔ PAUSED` | merchant, admin | — |
| `ACTIVE → OUT_OF_STOCK` | system | `stock == 0` |
| `OUT_OF_STOCK → ACTIVE` | system | `stock > 0` |
| `* → ARCHIVED` | merchant, admin | terminal |

`ARCHIVED` is terminal. To relist, create a new listing. Emits `LISTING_PUBLISHED` on entry to `ACTIVE`.

### B.6.2 Order

```
REQUESTED ──► ACCEPTED ──► IN_PROGRESS ──► READY ──► COMPLETED (terminal)
    │             │             │            │
    │             └─────────────┴────────────┴──► CANCELLED (terminal)
    │
    ├──► CANCELLED (terminal)
    ├──► EXPIRED   (terminal, system, no response in 14d)
    └──► DISPUTED  ──► COMPLETED | CANCELLED   (admin only resolves)
```

| Transition | Permitted actor |
|---|---|
| `REQUESTED → ACCEPTED` | merchant |
| `REQUESTED → CANCELLED` | customer, merchant |
| `REQUESTED → EXPIRED` | system (14 days) |
| `ACCEPTED → IN_PROGRESS` | merchant |
| `IN_PROGRESS → READY` | merchant |
| `→ COMPLETED` | **system only**, on dual confirmation (§B.6.3) |
| `ACCEPTED / IN_PROGRESS / READY → CANCELLED` | customer, merchant (reason required) |
| `* → DISPUTED` | customer, merchant (before terminal) |
| `DISPUTED → *` | admin only |

Invalid transitions return `ORDER_INVALID_TRANSITION` (409). Every transition writes an audit row.

### B.6.3 DM conversion & trust capture (revised — anti-gaming)

```
Customer taps "Contact Merchant"
   → POST /orders  (status REQUESTED, source = telegram_dm)
   → ORDER_CREATED event
   → enqueue tb_jobs: order.confirm_nudge, run_after = +24h
   → return deep link; open Telegram DM

Worker at T+24h → bot message to BOTH parties:
   [✅ Deal completed]   [❌ No deal]   [⏳ Still talking]

Resolution:
   both confirm ✅            → COMPLETED, trust counter +1, review invite to customer
   one ✅ only                → status stays; second nudge at +72h; if still one-sided
                                at +7d → CLOSED_UNCONFIRMED (terminal, NO trust credit)
   either ❌                  → CANCELLED, no trust effect
   ⏳                         → re-nudge at +72h (max 2 nudges total)
   no response by +14d       → EXPIRED, no trust effect
```

**Rule:** a merchant's public completed-transaction counter increments **only** on `COMPLETED`, which **requires both parties**. Single-sided confirmation never affects trust or ranking. This closes the v1.2 gaming hole.

**Inventory note:** reaching `COMPLETED` this way does not require a prior `ACCEPTED` transition. Stock reconciles automatically at that point — see §B.7.1.

**Additional guards (MUST):**
- Max 1 open `REQUESTED` order per (customer, listing) pair.
- Contact rate limits per §B.3.5.
- Same (customer, merchant) pair completing >5 orders in 7 days → `trust.pair_velocity` flag → moderation queue; counter increments are withheld pending review.
- Customer account age < 24h → orders created but excluded from trust counters.
- Merchant may not confirm on the customer's behalf; confirmations are recorded per Telegram user ID.

### B.6.4 Reviews

- Exactly one review per order (`UNIQUE(order_id)`), authored only by `order.customer_id`.
- Only on orders in `COMPLETED`.
- Window: 30 days after completion.
- Editable for 24h, then immutable; edits audited.
- `CANCELLED`, `EXPIRED`, `CLOSED_UNCONFIRMED`, and `DISPUTED` orders yield no review rights.
- Merchants cannot delete reviews. Admin removal requires a reason and is audited.
- Reviews on flagged pairs (`pair_velocity`) are hidden until moderation clears them.

### B.6.5 Verification (binary at MVP)

```
none ──► pending ──► verified
             │
             └────► rejected ──► pending  (resubmit allowed)

verified ──► revoked  (admin, reason required) ──► pending
```

Only `verified` merchants may transition a listing to `PENDING_REVIEW`. Revocation immediately transitions all `ACTIVE` listings to `PAUSED` and emits `MERCHANT_VERIFICATION_REVOKED`.

### B.6.6 Customer request

```
OPEN ──► MATCHED ──► FULFILLED (terminal)
  │         │
  └─────────┴──► EXPIRED | CANCELLED (terminal)
```

Default `expires_at` = 14 days. Expiry is a worker job, not WP-Cron.

## B.7 Inventory & Concurrency (normative)

### B.7.1 Stock decrement point

Stock decrements on `REQUESTED → ACCEPTED`, **not** at order creation. Rationale: at MVP there is no payment, so reserving on contact would let one browsing customer freeze a merchant's stock.

**Reconciliation with §B.6.3:** the DM trust loop can reach `COMPLETED` via dual confirmation without the merchant ever having clicked through `ACCEPTED` — that path is deliberately low-friction and shouldn't require it. So: on transition to `COMPLETED`, if stock was never decremented for this order, the system runs the same atomic decrement (§B.7.2) as a system-actor action before closing the order. If it fails (`affected_rows = 0` — already sold elsewhere, already zero), the decrement is skipped and logged; it does **not** block `COMPLETED` or withhold trust credit. Two people confirming a deal happened is stronger evidence than the inventory row agreeing with them. Audit row notes `auto_reconciled: true` when this path fires.

### B.7.2 Atomicity

Every stock mutation MUST be a single conditional statement inside a transaction:

```sql
UPDATE tb_inventory
   SET stock = stock - :qty, version = version + 1, updated_at = NOW()
 WHERE listing_id = :id AND stock >= :qty;
-- affected_rows = 0  →  INVENTORY_INSUFFICIENT_STOCK (409)
```

Read-modify-write in PHP is **forbidden**. Negative stock is impossible by construction.

### B.7.3 Optimistic locking

`tb_listings.version` and `tb_inventory.version` are returned on read. `PATCH` requests MUST include the version; mismatch returns `CONFLICT_STALE_VERSION` (409). This prevents the merchant-dashboard-plus-AI-assistant double-write case.

### B.7.4 Service availability

Enum only, no calendar at MVP: `AVAILABLE_TODAY`, `AVAILABLE_THIS_WEEK`, `BOOKED`, `UNAVAILABLE`. Never coupled to `tb_inventory`. Booking/scheduling is Part C.

### B.7.5 Bulk price operations

`"Increase all Samsung prices by 5%"` MUST: (1) AI extracts filter + operation only; (2) service computes the arithmetic in deterministic code using integer minor units (§B.4); (3) preview of affected listings returned; (4) merchant confirms; (5) applied in one transaction; (6) one audit row per listing with before/after.

## B.8 Idempotency (normative)

All `POST` and state-transition endpoints MUST accept `Idempotency-Key` (client-generated UUIDv4).

```
Key unseen              → execute; persist (key, request_hash, response, status) 24h
Key seen + same hash    → replay stored response verbatim
Key seen + diff hash    → IDEMPOTENCY_KEY_REUSED (422)
Key seen + in flight    → REQUEST_IN_PROGRESS (409)
```

Keys are scoped per user and endpoint. Order creation, order transitions, review creation, and verification submission are the mandatory cases.

## B.9 Worker Service & Job Semantics (normative)

### B.9.1 Delivery guarantee

**At-least-once.** Every job handler MUST be idempotent. Handlers that send messages MUST record a dispatch key and check it before sending.

### B.9.2 Lease protocol

```
Claim:    UPDATE tb_jobs
             SET status='running', lock_token=:tok,
                 lease_expires_at=NOW()+60s, attempts=attempts+1
           WHERE id=:id AND status='queued' AND run_after<=NOW()
           -- affected_rows must be 1

Complete: requires matching lock_token, else JOB_LEASE_LOST
Heartbeat: worker may extend lease while running
Reaper:   lease_expires_at < NOW() AND status='running' → back to 'queued'
```

### B.9.3 Retry & failure

| Property | Value |
|---|---|
| `max_attempts` | 5 |
| Backoff | 1m, 5m, 15m, 1h, 6h (± jitter) |
| Exhausted | `status = dead_letter`, admin-visible, emits `JOB_DEAD_LETTERED` |
| Worker auth | Dedicated service credential, capability `tb_worker`, rotatable; **not** an admin account |
| Worker writes | REST only. Direct SQL to marketplace tables is forbidden. Worker owns `tb_jobs` alone. |

### B.9.4 MVP job types

`notification.dispatch` · `order.confirm_nudge` · `order.expire` · `request.expire` · `request.match_run` · `listing.image_process` · `analytics.rollup_daily` · `ai.cache_prune` · `verification.reminder`

`listing.image_process` (payload `{ listing_id, image_id }`): decodes the original at `storage_key`, writes a fixed-ratio thumbnail to `thumb_key` (size from configuration), updates the `tb_listing_images` row. Idempotent via `tb_jobs.idempotency_key` (§B.9.1) — reprocessing a failed image is always safe. Failure retries per §B.9.3; a listing without a thumbnail still serves its original image.

WP-Cron MAY only enqueue jobs. It MUST NOT perform work.

## B.10 Error Taxonomy (normative)

### B.10.1 Envelope

```json
{
  "success": false,
  "error": {
    "code": "LISTING_NOT_FOUND",
    "module": "products",
    "message": "Listing 4821 does not exist or is not visible to you.",
    "retryable": false,
    "request_id": "req_01HZ...",
    "context": { "listing_id": 4821 }
  }
}
```

`message` is for logs and developers. **User-facing text MUST come from `tb_translations` keyed by `code`**, never from `message`.

### B.10.2 HTTP mapping

| Status | Meaning | Example codes |
|---|---|---|
| 400 | Malformed | `VALIDATION_FAILED` |
| 401 | No/invalid session | `AUTH_INVALID_SIGNATURE`, `AUTH_EXPIRED_INITDATA`, `AUTH_SESSION_EXPIRED`, `AUTH_REPLAY_DETECTED` |
| 403 | Authenticated, not permitted | `FORBIDDEN_CAPABILITY`, `FORBIDDEN_NOT_OWNER`, `MERCHANT_NOT_VERIFIED` |
| 404 | Absent or not visible | `LISTING_NOT_FOUND`, `ORDER_NOT_FOUND` |
| 409 | State conflict | `ORDER_INVALID_TRANSITION`, `INVENTORY_INSUFFICIENT_STOCK`, `CONFLICT_STALE_VERSION`, `REQUEST_IN_PROGRESS`, `JOB_LEASE_LOST` |
| 422 | Semantically invalid | `IDEMPOTENCY_KEY_REUSED`, `ENTITLEMENT_LIMIT_REACHED`, `REVIEW_NOT_ELIGIBLE` |
| 429 | Throttled | `RATE_LIMITED`, `AI_BUDGET_EXHAUSTED` |
| 500 | Internal | `INTERNAL_ERROR` |
| 503 | Dependency down | `AI_PROVIDER_UNAVAILABLE`, `TELEGRAM_UNAVAILABLE` |

`retryable: true` only for 429 and 503.

### B.10.3 Registry

`interfaces/ERRORS.md` holds the authoritative code list. Adding a code is part of the task that introduces it. Codes are stable identifiers and MUST NOT be renamed.

### B.10.4 Zero-scan debugging

An agent fixing a runtime error receives: the log snippet (with `request_id`), the owning module's `interfaces/<module>.md`, and `interfaces/ERRORS.md`. It MUST NOT read other modules' source to trace an error. If the error cannot be diagnosed from those three inputs, the error's `context` is under-populated — fix the error payload, not the boundary.

## B.11 Search & Matching (normative, deterministic)

### B.11.1 Index

`tb_listings.search_text` is a denormalized column rebuilt on write from: product canonical name, merchant business name, category name (both languages), and indexed attribute values. MySQL FULLTEXT (`ngram` parser, so Amharic and other non-space-delimited input tokenizes) plus a `LIKE` fallback for short queries.

### B.11.2 Query pipeline

```
raw query
  → normalize (trim, casefold, Unicode NFC, strip punctuation)
  → detect script (Ethiopic vs Latin)
  → deterministic term extraction:
        price hints ("under 30,000", "cheap")
        condition hints ("used", "new" / Amharic equivalents)
        location hints (match against tb_locations names, both languages)
        remaining terms → full-text
  → structured filter set
  → SQL query with filters + FULLTEXT
  → rank (B.11.3)
  → paginate
```

`ai_search_enabled = false` means the pipeline never calls a model at MVP. Term hint lists live in configuration, not code.

**Amharic requirements:** UTF-8mb4 throughout; Unicode NFC normalization on write and query; a configurable synonym map for common Amharic/English product terms (seeded, admin-editable); no assumption of whitespace tokenization.

### B.11.3 Ranking

Fixed, documented, no ML:

```
score = 0.40 × text_relevance
      + 0.20 × location_proximity   (same city 1.0, zone 0.6, region 0.3, else 0.1)
      + 0.15 × merchant_verified    (1 | 0)
      + 0.10 × availability         (in stock / available 1.0 else 0.0)
      + 0.10 × freshness            (decay over 30 days)
      + 0.05 × completed_txn_signal (log-scaled, capped)
```

Weights are configuration. **Subscription plan is not an input.** Ties break by `listing_id` for stable pagination.

### B.11.4 Empty results

Never a bare empty list. Return, in order: relaxed-filter suggestions (drop price, then location), nearest-category listings, and a call to action to create a Customer Request.

### B.11.5 Matching (requests → merchants)

Rule-based: category match (required) → location within region (required) → budget compatibility (merchant has ≥1 active listing in category ≤ budget × 1.2) → rank by §B.11.3. Cap at 10 merchants notified per request, max 3 request notifications per merchant per day.

## B.12 Privacy, Compliance & Operations

### B.12.1 Lawful basis register (Ethiopia PDPP 1321/2024)

| Data | Purpose | Basis | Retention |
|---|---|---|---|
| Telegram user ID, name | Account identity | Contract performance | Life of account + 90d |
| Language, location (area) | Service delivery | Contract performance | Life of account |
| Phone number | Contact/verification | Explicit consent | Life of account + 90d |
| Identity/business documents | Merchant verification | Legal/consent | **180 days after decision**, then purge |
| Order & review records | Marketplace integrity | Legitimate interest | 3 years |
| Audit logs | Security & accountability | Legal obligation | 3 years |
| AI usage records | Cost control | Legitimate interest | 12 months |

`tb_verification_documents.retention_expires_at` MUST be set at upload. A worker job flags expired documents for purge; purge is admin-confirmed at MVP.

### B.12.2 Controls that MUST exist at launch

- Named Data Protection Officer, documented internally.
- Documented hosting location for database and media, decided before launch.
- Explicit consent screen before: document upload, phone sharing, precise location. Recorded in `tb_consents` with version, and audited.
- **Every access to a verification document is logged** (`actor`, `document_id`, `timestamp`, `request_id`). Document URLs are signed, short-lived, and never public.
- Documents encrypted at rest; TLS in transit; storage keys not guessable.
- Written breach runbook: detect → assess → notify ECA within 72 hours → notify affected users. Owner named.
- Manual data-subject request process (access / rectify / erase) with a 30-day SLA and an audit trail.
- Privacy notice reachable from the bot and the Mini App, in both launch languages.
- Erasure honors legal-retention exceptions (audit and order records are anonymized, not deleted).

Automated DSR portals, automated deletion jobs, and cross-border transfer tooling are Part C.

### B.12.3 Observability

Every request and job carries `request_id`. Structured JSON logs with PII redaction (never log initData, tokens, document contents, phone numbers).

| Metric | Target |
|---|---|
| API p95 latency | < 800 ms |
| Search p95 | < 1.2 s |
| Job queue p95 wait | < 5 min |
| Auth failure rate | alert > 5% over 10 min |
| Dead-lettered jobs | alert on any |
| AI spend | alert at 80% of ceiling |
| Webhook failure rate | alert > 2% over 10 min |

### B.12.4 Backup & recovery (release gate)

Nightly automated database backup; media backup; configuration export. **RPO 24h, RTO 4h.** A restore MUST be performed and documented on a scratch environment **before launch**; an untested backup does not satisfy this section. Audit logs are included in backup scope.

## B.13 Definition of Done

### B.13.1 Per-module gate

A module is complete only when all hold:

1. Every endpoint exists in OpenAPI and matches the implementation.
2. Authorization is enforced in the service layer and tested for the unauthorized case.
3. All state transitions are tested, **including rejected illegal transitions**.
4. Error codes are registered in `interfaces/ERRORS.md`.
5. Audit rows are written for every mutating action.
6. Events emitted match `interfaces/<module>.md` exactly.
7. Migrations are reversible and idempotent.
8. Both `en` and `am` strings exist for all user-facing keys.
9. `MODULE.md` and `interfaces/<module>.md` are current.
10. Tests: happy path, auth failure, validation failure, conflict/concurrency, idempotent replay.

### B.13.2 Acceptance-criteria template (mandatory per feature)

```
FEATURE: <name>
ACTOR: <role>
PRECONDITIONS: <state required>
MAIN FLOW: <numbered steps>
POSTCONDITIONS: <db state, events emitted, notifications sent, audit rows>
ERROR FLOWS: <condition → error code → HTTP status>
AUTHORIZATION: <capability, ownership rule, verification gate>
NON-GOALS: <explicitly excluded>
```

### B.13.3 Worked example — Publish a listing

```
FEATURE: Publish listing
ACTOR: Merchant
PRECONDITIONS: session valid; merchant.verification_status == verified;
               listing exists, owned by merchant, status == DRAFT

MAIN FLOW:
 1. POST /listings/{id}/status { to: "PENDING_REVIEW", version: N }
 2. Validate all required category attributes are present
 3. Check entitlement active_listings not exceeded
 4. Transition DRAFT → PENDING_REVIEW (version N → N+1)
 5. Write audit row
 6. Notify moderation queue

POSTCONDITIONS: status PENDING_REVIEW; audit row exists; admin queue count +1;
                no LISTING_PUBLISHED event yet (fires on ACTIVE)

ERROR FLOWS:
  not verified              → MERCHANT_NOT_VERIFIED         403
  not owner                 → FORBIDDEN_NOT_OWNER           403
  missing required attrs    → VALIDATION_FAILED             400 (context.fields[])
  limit reached             → ENTITLEMENT_LIMIT_REACHED     422
  status != DRAFT           → LISTING_INVALID_TRANSITION    409
  stale version             → CONFLICT_STALE_VERSION        409

AUTHORIZATION: tb_manage_own_listings; listing.merchant_id == session.merchant_id;
               requires verified
NON-GOALS: auto-approval, scheduled publishing, bulk publish
```

### B.13.4 Worked example — Contact merchant

```
FEATURE: Contact merchant (creates trackable order)
ACTOR: Customer
PRECONDITIONS: session valid; listing.status == ACTIVE; merchant not suspended

MAIN FLOW:
 1. POST /orders { listing_id, quantity } + Idempotency-Key
 2. Reject if an open REQUESTED order exists for (customer, listing)
 3. Enforce contact rate limit
 4. Create order status REQUESTED, source telegram_dm   [no stock decrement]
 5. Emit ORDER_CREATED
 6. Enqueue tb_jobs order.confirm_nudge at +24h (idempotency_key = order:{id}:nudge:1)
 7. Notify merchant via Telegram
 8. Return order + merchant deep link

POSTCONDITIONS: one order row; one queued job; merchant notified; audit row;
                inventory UNCHANGED

ERROR FLOWS:
  listing not ACTIVE     → LISTING_NOT_AVAILABLE   409
  duplicate open order   → ORDER_ALREADY_OPEN      409
  rate limited           → RATE_LIMITED            429
  key reused, diff body  → IDEMPOTENCY_KEY_REUSED  422

NON-GOALS: payment, delivery, in-app messaging, stock reservation
```

## B.14 Agent Context Protocol (cost control)

Implementation is AI-agent-driven and paid per token. Context discipline is the primary cost lever.

### B.14.1 Starting a task on module X — attach exactly

```
✅  X's MODULE.md
✅  interfaces/X.md            (if X exists)
✅  interfaces/<dep>.md         for each DIRECT dependency only
✅  interfaces/INDEX.md         (tiny, orientation)
✅  interfaces/ERRORS.md        (if the task can fail)
✅  the task brief (§B.14.3)

❌  this specification
❌  other modules' source code
❌  contract files X does not directly depend on
```

If an agent needs more than this, the boundary is wrong. Fix the boundary, not the context budget.

### B.14.2 Finishing a task

Update `interfaces/X.md` if the public API or events changed, and X's row in `interfaces/INDEX.md`. This is the "update documentation" rule already required — not extra work.

### B.14.3 Task brief format

```
TARGET MODULE · TASK · ALLOWED FILES · DEPENDENCIES (public API only)
API CONTRACT · DATABASE IMPACT · STATE TRANSITIONS TOUCHED
ERROR CODES INTRODUCED · EVENTS EMITTED/CONSUMED · TEST REQUIREMENTS
ACCEPTANCE CRITERIA (§B.13.2 format)
```

### B.14.4 Rules

- One module task = one session. If it does not fit, it is not one module — split it.
- Build in dependency order so later modules only consume frozen contracts.
- New capability on an existing module → prefer a new event/listener over reopening the module.
- Never modify unrelated modules.
- Never hard-code configurable rules or language strings.
- Never let AI execute database mutations directly.
- Report schema changes explicitly.

### B.14.5 Interface registry

```
interfaces/
├── INDEX.md      ← module · purpose · status · deps · owned tables · build order
├── ERRORS.md     ← global error code registry
├── EVENTS.md     ← global event catalogue with payload schemas
└── <module>.md   ← one per module, written when the module is built
```

Contract file template: Purpose · Status · Depends on · Public REST API table · Events Emitted · Events Consumed · Owned Tables · **Invariants a caller must never violate**.

Contracts are written as modules are built. Do not front-load contracts for modules that do not exist.

## B.15 Build Order

| Phase | Contents | Exit criterion |
|---|---|---|
| **0 — Foundation** | plugin skeleton, migrations, core, `tb_jobs`, config, flags, events, audit, REST base, error envelope, localization (en/am) | An endpoint can be added, authorized, audited, and error-handled end to end |
| **1 — Identity** | initData verification, sessions, capabilities, `/me`, bot webhook + secret validation | A Telegram user reaches an authorized session; replay and expiry tested |
| **2 — Primitives** | locations, categories, attributes, products, listings, inventory, services | A verified merchant can publish a listing; overselling is provably impossible |
| **3 — Discovery** | search index, query pipeline, ranking, empty states, merchant profile | Amharic and English queries return correctly ranked results |
| **4 — Transaction** | orders, DM loop, worker service, notifications, reviews | Dual-confirmation trust loop works; single-sided grants nothing |
| **5 — Trust** | verification workflow, reports, moderation, reputation display | An admin can verify, reject, revoke, and moderate with full audit |
| **6 — Admin & Ops** | Control Center, System Health, backups + **tested restore**, entitlements (billing off) | Restore drill documented; launch gate passed |

Phases are sequential. A phase does not start until the previous phase meets §B.13.1 for all its modules.

---

# PART C — Deferred Decisions

*Do not implement. The architecture leaves room for each; that is precisely why leaving them out now is safe.*

| Deferred | Trigger to revisit | Why it is safe to defer |
|---|---|---|
| Customer marketplace payments | Repeat transaction volume + demand signal | Orders already model the lifecycle |
| Wallet, escrow, commission | After payments | Billing abstraction exists |
| Delivery management | Merchant demand | Order states accommodate fulfilment |
| Automated KYC | Verification volume exceeds manual capacity | Verification is already an isolated module |
| Verification levels L0–L4 | Same trigger | Binary status widens to an enum + `tb_verification_levels` |
| Complex trust scoring | Enough completed transactions to be meaningful | Raw signals shipped; score is a derived read |
| AI search assistance | Deterministic search demonstrably insufficient | Flag-gated; pipeline already has the hook |
| AI conversational assistant | Support load justifies it | Flag-gated |
| No-code add-language workflow | A third language is actually needed | Strings are already DB-driven |
| Recommendations | Traffic supports it | Ranking module is separate |
| Full internal messaging | Off-platform DM proves inadequate | Order + conversation boundary already defined |
| Multi-location inventory, multi-branch, staff accounts | Business-tier demand | `tb_inventory` gains a `location_id` |
| Service booking calendars | Provider demand | Availability enum widens |
| External partner APIs | Partner exists | REST + capabilities already in place |
| Native mobile apps | Telegram becomes a constraint | REST is client-agnostic |
| Separate admin frontend | Admin scale | Admin already consumes the same API |
| Automated DSR portal, automated retention jobs, cross-border tooling | Verification volume grows | Manual process satisfies the same principles now |

---

## Closing

**Part B is the contract. Part A explains why. Part C is a promise, not a plan.**

Implementation proceeds module-by-module in the order of §B.15. No module begins without a task brief and acceptance criteria in the §B.13.2 format. No agent receives this document.

---

### Two decisions still needed from you before Phase 0

1. **Hosting location** for database and media (§B.12.2) — this gates the compliance register and cannot be retrofitted cheaply.
2. **Named Data Protection Officer** (§B.12.2) — required before any verification document is collected, including in testing.

I can draft `interfaces/INDEX.md`, `interfaces/ERRORS.md`, and the Phase 0 task briefs next if useful.