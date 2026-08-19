# Module: telegram
**Purpose:** Bot adapter — webhook receiver, outbound messages, inline buttons, deep links. Phase 1 ships only the webhook receiver (secret validation + ack). Outbound send/edit/callback lands with module 3.
**Status:** in_progress
**Depends on:** core, identity, localization, ai

> **Conversation (module 13):** `Conversation::step()` is the conversation-based gateway (returns
> `void`, sends directly). Onboarding: `/start` → inline language → inline role; a plain first
> message gets a one-time greeting (never the language menu); a chat stuck mid-onboarding
> (`awaiting_role`) is nudged back to role selection. Post-onboarding text (state `main`/`completed`)
> goes to the **AI sell-agent** (`AI\Service::chat`) with the last 8 turns of thread memory stored in
> the chat row's `data.history`; no provider configured → a graceful fallback reply. Mini App
> handoff: once the sell-agent structures the query (`chat` returns a JSON `{reply, slots}`
> envelope), `Conversation::step` §7 runs
> `Search\Service::search_listings` with the extracted filters; only when matches exist does the
> reply carry the **Open Mini App** inline button whose `web_app.url` includes the
> `category`/`location`/`budget_max` params. No match → an honest "couldn't find" reply and no
> button.
>
> **Seller registration:** lives in the **Mini App** (dynamic country → region → city cascading
> selects from `catalog/locations`, ID + trade-license photo upload to `verification/documents`).
> A first-time seller choosing the seller role in chat is sent an **Open Mini App** button
> (`view=register`). The merchant profile is created (`verification_status = pending`), and the
> admin approves in the admin menu — verifying the documents, setting the **level** (L0–L3 = number
> of verified documents, as `tb_entitlements.seller_level` + caps) and sending the seller a Telegram
> congrats. Sellers can update business name / merchant_type / city by talking to the AI bot (the
> seller persona emits a `profile` field).
>
> **Seller flow (module 8):** role `seller` switches the AI to the listing persona (JSON
> `slots: {item, price, category, location}`). Once item + price are captured the bot resolves the
> seller's merchant via `tb_identity`→`tb_merchants`, matches category (`tb_categories.slug`) and
> city (`tb_locations.name_key`, normalized), creates the product + a **DRAFT** listing, and hands
> off to the Mini App (`view=my_listings`). No merchant → setup prompt, no listing. A photo sent in
> chat is downloaded (`Bot::getFile`) and attached to the latest DRAFT via
> `Listings\Service::create_image`.
>
> **Anchored controls:** the only controls are the anchored reply keyboard below the input —
> 🌐 Language, 🔄 Change role, 🏠 Home, and **🚀 Open Mini App** (web_app) on its own row. It attaches
> at role selection and is re-pinned on every AI sell-agent reply (so chats that onboarded before the
> bar shipped still get it). No buttons are attached to messages themselves. Changing language keeps
> the role and vice versa; `lang:`/`role:` callbacks from an onboarded chat re-confirm the single
> field and stay in state `completed` instead of restarting onboarding.

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| POST | /trade/v1/webhook/telegram | raw Telegram update body | `{received: true}` | `X-Telegram-Bot-Api-Secret-Token` header (`trade_telegram_webhook_secret` option) | FORBIDDEN_CAPABILITY (wrong/missing secret → 403) |

> The update body is ack'd only this phase — nothing is parsed or persisted (§B.12.3: never store raw updates/PII). The `telegram.webhook` event fires with an empty payload, purely as a liveness signal.

## Service API (used by other modules)
- `Verify::verify($init_data, $bot_token, ?$now)` → `{user_id, first_name, last_name, username, language_code, auth_date}` — pure Telegram initData HMAC verification (§B.3.1). Throws `AUTH_INVALID_SIGNATURE` / `AUTH_EXPIRED_INITDATA`. Used by identity's login; owned here because it's Telegram protocol, not identity state.
- `Bot` — outbound adapter over the Bot API: `sendMessage(chat_id, text[, opts])`, `editMessageText(chat_id, message_id, text[, opts])`, `answerCallbackQuery(id[, text])`, `deep_link(start_payload)` (`§A.9`). Token from `trade_telegram_bot_token` ('' → `TELEGRAM_UNAVAILABLE`). Transport injectable for tests. Call flows must fire these via the jobs queue, never from a webhook request handler (invariant). `Bot::BASE` = `https://api.telegram.org`.

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| telegram.webhook | `{}` (empty — §B.12.3) | valid webhook secret received |

## Events Consumed
| Event | Action |
|-------|--------|
| — | none this phase |

## Owned Tables
— (adapter over the Telegram Bot API; no DB tables). Config options: `trade_telegram_bot_token`, `trade_telegram_webhook_secret`.

## Invariants
- Secret comparison is constant-time (`hash_equals`) and a mismatch is audited (`security.webhook_secret`) before rejecting.
- No marketplace business logic here; webhook handling dispatches to the owning module (Phase 3+).
- Outbound messages never fire from a webhook request handler; they go through the jobs queue (worker).