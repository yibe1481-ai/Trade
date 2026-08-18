# Trade Bot — Global Event Catalogue

**Version:** 1.0 (MVP)
**Spec reference:** Master Specification v1.5 · §A.8, §B.14.5
**Rule:** Modules communicate across boundaries via events, not direct calls (§A.8). Add new events in the same task that introduces them. Payloads are additive-only once `stable` — new fields don't break consumers, removing or renaming one does.

---

## How to read this file

Two provenances, marked in the **Source** column:

- **spec** — the event is explicitly named in the master specification text.
- **inferred** — not literally named, but required by a state machine (§B.6) or cross-module dependency the spec already defines (e.g. something has to tell `notifications` an order completed so it can invite a review). Flagged separately so it's obvious what to confirm rather than take on faith.

If a task needs an event that isn't here yet, add it here in that same task — don't let it live only in code.

---

## Registry

| Event | Emitted by | Payload | When | Known consumers | Source |
|---|---|---|---|---|---|
| `USER_REGISTERED` | identity | `{ wp_user_id, telegram_user_id, created_at }` | First time a Telegram user resolves to a new `wp_user` | analytics, notifications (welcome flow) | inferred |
| `LISTING_PUBLISHED` | listings | `{ listing_id, merchant_id, product_id, category_id }` | Listing enters `ACTIVE` (§B.6.1) | search (index), notifications (matching requests), analytics | spec |
| `LISTING_REJECTED` | listings | `{ listing_id, merchant_id, reviewed_by, note }` | Admin transitions `PENDING_REVIEW → REJECTED` | notifications | inferred |
| `LISTING_ARCHIVED` | listings | `{ listing_id, merchant_id }` | Listing enters terminal `ARCHIVED` | search (deindex), analytics | inferred |
| `ORDER_CREATED` | orders | `{ order_id, customer_id, merchant_id, listing_id, source }` | `POST /orders` succeeds — see worked example §B.13.4 | notifications (merchant alert), worker (schedules `order.confirm_nudge`) | spec |
| `ORDER_ACCEPTED` | orders | `{ order_id, merchant_id }` | `REQUESTED → ACCEPTED`; stock decrement attempted (§B.7.1) | notifications, analytics | inferred |
| `ORDER_COMPLETED` | orders | `{ order_id, customer_id, merchant_id, auto_reconciled: bool }` | Dual confirmation resolves to `COMPLETED` (§B.6.3); trust counter increments | notifications (review invite), trust_safety (completed-transaction counter), search (ranking signal), analytics | inferred |
| `ORDER_CANCELLED` | orders | `{ order_id, cancelled_by, reason }` | Any actor-initiated cancellation, or either party taps ❌ in the DM-loop nudge | notifications, analytics | inferred |
| `ORDER_EXPIRED` | orders | `{ order_id }` | No response within 14 days (§B.6.2), system-driven | analytics | inferred |
| `ORDER_CLOSED_UNCONFIRMED` | orders | `{ order_id, confirmed_by }` | One-sided confirmation times out at +7d (§B.6.3) — no trust credit | analytics (visibility into gaming attempts / unresponsive merchants) | inferred |
| `ORDER_DISPUTED` | orders | `{ order_id, raised_by, reason }` | Either party disputes before a terminal state (§B.6.2) | notifications (admin queue), trust_safety | inferred |
| `REVIEW_CREATED` | orders | `{ review_id, order_id, subject_merchant_id, rating }` | `POST /reviews` succeeds (order-gated, §B.6.4) | notifications (merchant alert), search (ranking signal), analytics | inferred |
| `MERCHANT_VERIFIED` | verification | `{ merchant_id, reviewed_by }` | `pending → verified` | notifications, search (ranking signal), listings (unblocks `PENDING_REVIEW`) | inferred |
| `MERCHANT_VERIFICATION_REJECTED` | verification | `{ merchant_id, reviewed_by, note }` | `pending → rejected` | notifications | inferred |
| `MERCHANT_VERIFICATION_REVOKED` | verification | `{ merchant_id, revoked_by, reason }` | `verified → revoked` — cascades to pause all `ACTIVE` listings (§B.6.5) | listings (pause cascade), notifications, search (deindex) | spec |
| `REQUEST_MATCHED` | requests | `{ request_id, merchant_ids[] }` | Matching run (§B.11.5) finds ≤10 eligible merchants | notifications (merchant alerts, capped 3/day) | inferred |
| `REQUEST_FULFILLED` | requests | `{ request_id, order_id }` | Customer marks the request resolved | analytics | inferred |
| `REQUEST_EXPIRED` | requests | `{ request_id }` | `expires_at` reached (default 14d), worker-driven (§B.6.6) | analytics | inferred |
| `REPORT_SUBMITTED` | trust_safety | `{ report_id, reporter_id, entity_type, entity_id, reason }` | `POST /reports` succeeds | notifications (moderation queue) | inferred |
| `AI_BUDGET_EXHAUSTED` | ai | `{ provider, scope: daily\|monthly, spend, ceiling }` | Cost ceiling reached (§B.2.2) | notifications (admin alert) | spec |
| `JOB_DEAD_LETTERED` | core | `{ job_id, type, attempts, last_error }` | Job exhausts `max_attempts` (5) (§B.9.3) | notifications (admin alert), analytics | spec |

---

## Naming convention for events not yet needed

Follow `<ENTITY>_<PAST_TENSE_VERB>` — matches every row above. Don't invent a `_CHANGED`/`_UPDATED` catch-all; name the specific thing that happened (`LISTING_PAUSED`, not `LISTING_UPDATED`) so consumers can subscribe narrowly. Add the row here in the same task that introduces the emit call — an event that exists only in code isn't part of the contract.
