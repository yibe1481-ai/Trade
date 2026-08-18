# Trade Bot — Module Interface Registry

**Version:** 1.2 (MVP)
**Spec reference:** Master Specification v1.5 · Part B
**Rule:** An agent building module X attaches this file + X's contract + direct
dependency contracts only, plus `interfaces/ERRORS.md` and `interfaces/EVENTS.md`
where the task can fail or needs to react to another module. Never the master
specification. Never other modules' source.

Status values: `not_started` · `in_progress` · `stable` · `schema_only`

---

## Build order

| Order | Module | Status | Depends on (public API only) | Owned tables |
|------:|--------|--------|------------------------------|--------------|
| 0 | core | in_progress | — | tb_events, tb_audit_logs, tb_jobs, tb_feature_flags, tb_idempotency_keys, tb_throttle |
| 1 | identity | in_progress | core, catalog | tb_identity, tb_sessions, tb_consents, tb_customer_profiles |
| 2 | localization | stable | core | tb_languages, tb_translations |
| 3 | telegram | in_progress | core, identity, localization | — (no owned tables; adapter over Bot API) |
| 4 | catalog | in_progress | core, localization | tb_locations, tb_categories, tb_category_attributes, tb_products, tb_product_variants |
| 5 | merchant | in_progress | core, identity, localization, catalog | tb_merchants, tb_entitlements, tb_subscriptions |
| 6 | listings | in_progress | core, catalog, merchant | tb_listings, tb_inventory, tb_service_availability, tb_listing_images |
| 7 | search | in_progress | core, catalog, listings, merchant | — (reads listings; writes none) |
| 8 | orders | in_progress | core, identity, listings, merchant | tb_orders, tb_reviews |
| 9 | requests | in_progress | core, identity, catalog, merchant, listings | tb_customer_requests, tb_request_matches |
| 10 | verification | in_progress | core, identity, merchant | tb_merchants, tb_verification_documents |
| 11 | trust_safety | in_progress | core, identity, merchant, orders, listings | tb_reports |
| 12 | notifications | not_started | core, identity, telegram | tb_notifications |
| 13 | ai | not_started | core, identity | tb_ai_providers, tb_ai_models, tb_ai_usage, tb_ai_cache |
| 14 | billing | schema_only | core, merchant | (uses tb_subscriptions, tb_entitlements; billing_enabled=false) |
| 15 | analytics | not_started | core | — (reads events; owns no tables at MVP) |
| 16 | admin | not_started | all stable modules | — (Control Center; no owned tables) |
| 17 | mini_app | not_started | identity + whatever screens it calls | — (client; no owned tables) |
| — | worker | not_started | core (jobs API only) | — (external Python service; owns no tables) |

---

## Module summaries

### core
- **Purpose:** Shared kernel — events, audit, jobs queue, feature flags, idempotency, error helpers, REST base.
- **Status:** in_progress
- **Public surface:** event bus, audit writer, job enqueue/claim/complete, flag reader, idempotency middleware.
- **Invariants:** No module writes tb_audit_logs or tb_events directly except through core. Job handlers are at-least-once and must be idempotent.

### identity
- **Purpose:** Telegram initData verification, session lifecycle, capability checks, `/me` (identity + customer profile fields).
- **Status:** in_progress
- **Depends on:** core, catalog
- **Public surface:** POST /auth/session, GET|PATCH /me, session middleware, capability guard. Contract: `interfaces/identity.md`.
- **Owned tables:** tb_identity, tb_sessions, tb_consents (schema_only), tb_customer_profiles.
- **Invariants:** Never trust client-supplied telegram_user_id. Sessions store token hash only. telegram_user_id is immutable. Locations are validated through catalog; nonexistent ids return LOCATION_NOT_FOUND. There is no separate `customer` module — v1.4 folded it here (spec §A.9).

### localization
- **Purpose:** Languages and translated UI strings.
- **Status:** stable
- **Depends on:** core
- **Public surface:** string resolver t(key, lang), admin CRUD for translations (en/am only at MVP).
- **Invariants:** User-facing text never hard-coded. Missing key falls back to en, then to the key itself. Launch languages: en, am.

### telegram
- **Purpose:** Bot adapter — webhook, outbound messages, inline buttons, deep links.
- **Status:** in_progress
- **Depends on:** core, identity, localization
- **Public surface:** webhook receiver (Phase 1: secret-gated ack only); send/edit/answer_callback land later. Contract: `interfaces/telegram.md`.
- **Invariants:** Webhook secret validated in constant time. Callback — payloads re-authorized server-side. No marketplace business logic here. Update bodies never persisted (§B.12.3).

### catalog
- **Purpose:** Locations, categories, dynamic attributes, products, variants.
- **Status:** not_started
- **Depends on:** core, localization
- **Public surface:** GET /categories, GET /categories/{id}/attributes, GET|POST /products.
- **Invariants:** Categories and attributes are DB-driven. Products are merchant-agnostic. No price or stock here.

### merchant
- **Purpose:** Merchant profile, type, location, entitlement reads.
- **Status:** in_progress
- **Depends on:** core, identity, localization, catalog
- **Public surface:** POST /merchants (create own profile), PATCH /merchants/{id} (edit own), GET /merchants/{id} (public read), entitlement checker.
- **Invariants:** verification_status is owned by verification module; merchant module reads it, does not set it to verified. Entitlements checked by key, never by plan name. Neither create nor edit may set verification_status.

### listings
- **Purpose:** Listings, inventory, service availability, listing state machine.
- **Status:** not_started
- **Depends on:** core, catalog, merchant
- **Public surface:** /listings, /inventory/{id}, /availability/{id}, /listings/{id}/images, status transitions.
- **Invariants:** Stock mutations are atomic conditional UPDATEs. Negative stock impossible. version required on PATCH. Only verified merchants reach PENDING_REVIEW. search_text rebuilt on write. Thumbnails are derived by `listing.image_process`, never client-supplied; deleting a listing deletes its images.

### search
- **Purpose:** Deterministic search + fixed-weight ranking.
- **Status:** in_progress
- **Depends on:** core, catalog, listings, merchant
- **Public surface:** GET /search
- **Invariants:** No AI calls while ai_search_enabled=false. Ranking weights from config. Subscription plan is not a ranking input. Amharic-safe tokenization (ngram + NFC). search_text rebuilt on write by listings module (§B.11.1); search only reads.

### orders
- **Purpose:** Order lifecycle, DM trust loop, reviews.
- **Status:** in_progress
- **Depends on:** core, identity, listings, merchant
- **Public surface:** GET/POST /orders, GET /orders/{id}, POST /orders/{id}/transition, GET/POST /orders/{id}/review
- **Invariants:** COMPLETED requires dual confirmation (§B.6.3); single-sided confirmation never resolves COMPLETED. Stock decrements on ACCEPTED only (§B.7.2, atomic). One open REQUESTED order per (customer, listing). One review per COMPLETED order, within 30 days. CANCELLED and DISPUTED require a reason. EXPIRED is system-driven (14-day nudge via worker).

### requests
- **Purpose:** Customer requests and rule-based matching.
- **Status:** in_progress
- **Depends on:** core, identity, catalog, merchant, listings
- **Public surface:** GET/POST /requests, GET /requests/{id}, POST /requests/{id}/transition, GET /requests/{id}/matches
- **Invariants:** Matching is rule-based (category + location + budget). Cap 10 merchants/request, 3 request notifications/merchant/day. Expiry via worker job.

### verification
- **Purpose:** Merchant document review workflow (§B.6.5).
- **Status:** in_progress
- **Depends on:** core, identity, merchant
- **Public surface:** POST /verification, GET /verification/{id}, POST /verification/{id}/transition
- **Invariants:** Verification status transitions: NONE→PENDING (create), PENDING→VERIFIED (documents approved), PENDING→REJECTED (documents failed), VERIFIED→REVOKED (admin revokes, cascades: all ACTIVE listings → PAUSED). REVOKED is terminal. Each status change is audited via tb_audit_logs. REVOKED cascades: all merchant ACTIVE listings transitioned to PAUSED via ListingsService::apply_transition; search deindexes paused listings.

### trust_safety
- **Purpose:** Reports, moderation queue, pair-velocity flags (§B.6.7).
- **Status:** not_started
- **Depends on:** core, identity, merchant, orders, listings
- **Public surface:** POST /reports, GET /reports, GET /reports/{id}, POST /reports/{id}/transition
- **Invariants:** Reports always audited via tb_audit_logs. pair_velocity: ≥3 reports against same merchant/entity_type within 7 days → FLAGGED event. Self-reports (reporter_id = entity owner) → REJECTED immediately. Admin must provide reason on resolve/dismiss. Status transitions: pending → {cleared, rejected, flagged}; all terminal.

### notifications
- **Purpose:** Notification routing and delivery (Telegram + in-app at MVP) (§B.6.8).
- **Status:** stable
- **Depends on:** core, identity, telegram
- **Public surface:** GET /notifications/recent, POST /notifications/trigger
- **Invariants:** Consumes all domain events from prior modules (orders, requests, verification, trust_safety). Telegram delivery via bot token (`trade_telegram_bot_token`) to admin chat (`trade_telegram_admin_chat`); in‑app messages surfaced through WP admin Recent Events screen (reads `tb_events`). Template keys resolved from `tb_translations` in later phases; MVP returns key as‑is. Duplicate events within a 5‑minute window are collapsed (same `event_name` + identical core payload). Single entry point: `NotificationsService::consume(event, payload)`.

### ai
- **Purpose:** Centralized AI gateway — routing, usage, cache, cost governance.
- **Status:** in_progress
- **Depends on:** core, identity
- **Public surface:** interpret(task, input) → structured result; no DB writes.
- **Invariants:** AI never mutates marketplace tables. Cost ladder mandatory. Budget exhaustion returns deterministic fallback (AI_BUDGET_EXHAUSTED). ai_search_enabled and ai_assistant_enabled are false at MVP. Every call site has a non-AI path.

### billing
- **Purpose:** Plans, subscriptions, entitlements (§B.14).
- **Status:** in_progress
- **Depends on:** core, merchant
- **Public surface:** GET /billing/entitlements, POST /billing/subscribe
- **Invariants:** billing_enabled=false at MVP. Entitlement keys: images_per_listing (default 5), active_listings (default unlimited). Subscriptions track entitlement keys, not plan names. Entitlement limits cap listing/images counts per merchant. Modules check entitlement keys, never plan names.

### analytics
- **Purpose:** Funnel and operational rollups from tb_events.
- **Status:** not_started
- **Depends on:** core
- **Public surface:** admin read APIs, daily rollup job.
- **Invariants:** Analytics never blocks primary flows. Reads events; does not write marketplace state.

### admin
- **Purpose:** Trade Bot Control Center inside WordPress Admin.
- **Status:** not_started
- **Depends on:** all modules it surfaces
- **Public surface:** WP Admin pages calling the same REST/service layer as clients.
- **Invariants:** Authorization via capabilities, not hidden menus. Service-layer checks still apply. No direct SQL from admin controllers.

### mini_app
- **Purpose:** Telegram Mini App client.
- **Status:** not_started
- **Depends on:** identity (session) + REST contracts of features it uses
- **Invariants:** Client only. No privileged logic. session_token via Authorization header. Contextual open based on onboarding_state from /auth/session.

### worker
- **Purpose:** External Python service for reliable background jobs.
- **Status:** not_started
- **Depends on:** core jobs API only
- **Invariants:** Authenticates with tb_worker capability. Calls REST only — never writes marketplace tables directly. Owns no tables. Lease protocol mandatory. At-least-once; handlers idempotent.

---

## Contract file rule

When a module reaches `in_progress`, create `interfaces/<module>.md` in the same task.
When it reaches `stable`, the contract is frozen for dependents.
Additive fields are non-breaking. Breaking changes require a new API version and a new contract section.

Template for every `interfaces/<module>.md`:

```markdown
# Module: <name>
**Purpose:** …
**Status:** not_started | in_progress | stable | schema_only
**Depends on:** <modules>

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |

## Events Emitted
| Event | Payload | When |

## Events Consumed
| Event | Action |

## Owned Tables
- tb_…

## Invariants
- …