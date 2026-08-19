# Module: admin
**Purpose:** Trade Bot Control Center inside WordPress Admin.
**Status:** in_progress
**Depends on:** all stable modules

> AI sell-agent configuration (module 13) lives on the existing **Trade → Settings** page
> (`Admin\Service::render_settings`). Options: `trade_ai_provider` (`` | `openrouter` | `groq`),
> `trade_ai_openrouter_key` / `trade_ai_openrouter_model`, `trade_ai_groq_key` / `trade_ai_groq_model`.
> Keys are password fields and never echoed; `ai_status()` shows the resolved provider + model.
>
> **Seller Approvals** (submenu `trade-approvals`): merchants with `verification_status = pending`
> (from in-chat registration) list with their submitted documents and current level, with
> **Approve / Reject(reason)** actions. Approving verifies the documents, assigns the level
> (L0–L3 from verified `tb_verification_documents`), writes entitlements, and sends the seller a
> Telegram congrats; rejection notifies with the admin's reason.
> Providers are OpenAI-compatible chat endpoints; `Admin\Service::AI_PROVIDERS` lists the defaults
> (`openai/gpt-4o-mini`, `llama-3.3-70b-versatile`). Full request shape lives in the AI gateway
> (`AI\Service::config` / `complete`).

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| GET | /trade/v1/admin/dashboard | — | dashboard summary (events, orders, requests counts) | admin | — |
| GET | /trade/v1/admin/events/recent | `?limit?` | recent events | admin | — |
| GET | /trade/v1/admin/statistics | `?period?` | statistics rollup (sales, listings, etc.) | admin | — |

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| — | none | this phase |

## Events Consumed
| Event | Action |
|-------|--------|
| — | none |

## Owned Tables
- tb_events (read-only via Events::get_recent())
- No marketplace tables owned directly

## Invariants
- Authorization via WordPress capabilities, not hidden menus
- Service-layer checks still apply — no direct SQL from admin controllers
- Dashboard data aggregates from existing module state (orders, requests, listings, etc.)
- Events are read-only; admin cannot mutate marketplace state from the control center
- Pending transitions that would require merchant/admin confirmation are blocked with appropriate errors