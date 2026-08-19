# Module: telegram
**Purpose:** Bot adapter — webhook receiver, outbound messages, inline buttons, deep links. Phase 1 ships only the webhook receiver (secret validation + ack). Outbound send/edit/callback lands with module 3.
**Status:** in_progress
**Depends on:** core, identity, localization, ai

> **Conversation (module 13):** `Conversation::step()` is the conversation-based gateway (returns
> `void`, sends directly). Onboarding: `/start` → inline language → inline role; a plain first
> message gets a one-time greeting (never the language menu); a chat stuck mid-onboarding
> (`awaiting_role`) is nudged back to role selection. Post-onboarding text (state `main`/`completed`)
> goes to the **AI sell-agent** (`AI\Service::chat`) with the last 8 turns of thread memory stored in
> the chat row's `data.history`; every reply carries an **Open Mini App** web_app button for handoff.
> No provider configured → a friendly fallback reply, still with the button. Provider/keys come from
> the admin Settings page (`trade_ai_provider`, `trade_ai_openrouter_key/model`, `trade_ai_groq_key/model`).

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