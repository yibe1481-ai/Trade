# Module: notifications
**Purpose:** Notification routing and delivery (Telegram + in-app at MVP) (§B.6.8).
**Status:** in_progress
**Depends on:** core, identity, telegram

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| GET | /trade/v1/notifications/recent | `?limit?` | recent events (admin) | admin | — |
| POST | /trade/v1/notifications/trigger | `{event, payload?}` | `{sent, message}` | admin (system) | EVENT_NOT_FOUND |

> `event` ∈ {ORDER_CREATED, ORDER_ACCEPTED, ORDER_COMPLETED, ORDER_CONFIRMED_ONE_SIDE, ORDER_CANCELLED, ORDER_DISPUTED, REVIEW_CREATED, REQUEST_MATCHED, REQUEST_FULFILLED, REQUEST_EXPIRED, REQUEST_CANCELLED, MERCHANT_VERIFIED, MERCHANT_VERIFICATION_REJECTED, MERCHANT_VERIFICATION_REVOKED, REPORT_SUBMITTED, REPORT_RESOLVED, REPORT_DISMISSED, MERCHANT_FLAGGED}.
> `payload` depends on the event type; see each module's contract for the full payload shape.

## Consumed Events (from all prior modules)
| Event | Source Module | Payload Highlights |
|-------|--------------|-------------------|
| ORDER_CREATED | orders | `{order_id, customer_id, merchant_id, listing_id}` |
| ORDER_ACCEPTED | orders | `{order_id, merchant_id}` |
| ORDER_COMPLETED | orders | `{order_id, customer_id, merchant_id, auto_reconciled}` |
| ORDER_CONFIRMED_ONE_SIDE | orders | `{order_id, confirmed_by}` |
| ORDER_CANCELLED | orders | `{order_id, cancelled_by, reason}` |
| ORDER_DISPUTED | orders | `{order_id, raised_by, reason}` |
| REVIEW_CREATED | orders | `{review_id, order_id, subject_merchant_id, rating}` |
| REQUEST_MATCHED | requests | `{request_id, merchant_ids[]}` |
| REQUEST_FULFILLED | requests | `{request_id, order_id}` |
| REQUEST_EXPIRED | requests | `{request_id}` |
| REQUEST_CANCELLED | requests | `{request_id, cancelled_by}` |
| MERCHANT_VERIFIED | verification | `{merchant_id, reviewed_by}` |
| MERCHANT_VERIFICATION_REJECTED | verification | `{merchant_id, reviewed_by, note}` |
| MERCHANT_VERIFICATION_REVOKED | verification | `{merchant_id, revoked_by, reason}` |
| REPORT_SUBMITTED | trust_safety | `{report_id, reporter_id, entity_type, entity_id, reason}` |
| REPORT_RESOLVED | trust_safety | `{report_id, entity_type, entity_id, outcome}` |
| REPORT_DISMISSED | trust_safety | `{report_id, entity_type, entity_id}` |
| MERCHANT_FLAGGED | trust_safety | `{merchant_id, report_count, window_days}` |

## Routing & Delivery (MVP)
| Event | Telegram | In‑App |
|-------|----------|--------|
| ORDER_CREATED | merchant alert (new order) | admin dashboard card |
| ORDER_ACCEPTED | merchant alert (stock taken) | — |
| ORDER_COMPLETED | — | review invite to customer |
| ORDER_CONFIRMED_ONE_SIDE | merchant nudge (single side) | — |
| ORDER_CANCELLED | customer alert (cancellation) | — |
| ORDER_DISPUTED | admin alert (dispute raised) | admin queue card |
| REVIEW_CREATED | merchant alert (new review) | — |
| REQUEST_MATCHED | merchant alert (match found) | — |
| REQUEST_FULFILLED | customer notification (resolved) | — |
| REQUEST_EXPIRED | customer notification (expired) | — |
| REQUEST_CANCELLED | customer notification (cancelled) | — |
| MERCHANT_VERIFIED | admin alert (verification status) | — |
| MERCHANT_VERIFICATION_REJECTED | admin alert | — |
| MERCHANT_VERIFICATION_REVOKED | admin alert (cascade: all ACTIVE listings → PAUSED) | — |
| REPORT_SUBMITTED | — | moderation queue alert |
| REPORT_RESOLVED | — | reporter alert |
| REPORT_DISMISSED | — | reporter alert |
| MERCHANT_FLAGGED | — | moderation queue alert |

## Invariants
- All events are persisted via `Events::emit( 'EVENT_NAME', $payload )` → `tb_events`, then `do_action( 'trade.EVENT_NAME', $payload )`.
- Telegram delivery uses the bot token configured in WP admin (`trade_telegram_bot_token`) and target chat ID (`trade_telegram_admin_chat` at MVP; per‑user chats later).
- In‑app messages are surfaced via the WP admin Recent Events screen; they read from `tb_events` directly.
- Duplicate events within a 5‑minute window are collapsed (same `event_name` + identical core payload fields).
- The `consume()` method is the single entry point for routing any event; originating modules call `Events::emit()` + `NotificationsService::on()`.
- Template keys (`order_created`, `review_created`, etc.) are resolved from `tb_translations` in a later phase; MVP returns the key as‑is.