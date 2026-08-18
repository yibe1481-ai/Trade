# Module: identity
**Purpose:** Telegram initData verification, session lifecycle, capability checks, `/me` (identity + customer profile fields). Telegram user is the root of identity; WP user rows are the storage backstop, never the source.
**Status:** in_progress
**Depends on:** core, catalog

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| POST | /trade/v1/auth/session | `{"init_data": "<Telegram MiniApp initData>"}` | `{session_token, expires_at, onboarding_state, role}` | public (initData HMAC) | VALIDATION_FAILED, AUTH_INVALID_SIGNATURE, AUTH_EXPIRED_INITDATA, AUTH_REPLAY_DETECTED, RATE_LIMITED |
| GET | /trade/v1/me | — | `{language, display_name, location_id}` | `tb_session` | AUTH_SESSION_EXPIRED, FORBIDDEN_CAPABILITY |
| PATCH | /trade/v1/me | any of `{language?, display_name?, location_id?}` | same shape as GET /me (fresh read) | `tb_session` | VALIDATION_FAILED, AUTH_SESSION_EXPIRED, FORBIDDEN_CAPABILITY, LOCATION_NOT_FOUND |

> `expires_at` is ISO-8601 (UTC). `onboarding_state` and `role` are provisional constants (`none` / `customer`) until the modules that own them ship; clients must treat them as opaque strings and switch on equality only.

## Service API (used by other modules)
- `Session::resolve('Authorization' header)` → `{user_id, error}` — the auth middleware; `Rest::dispatch` calls it for every non-`''`-capability route. error is an `AUTH_*` code or null.
- `Session::grant_trade_caps($allcaps, $requested, $args)` — `user_has_cap` filter; grants `tb_session` to any logged-in user. Extension point for later named caps.
- `Session::issue($wp_user_id)` → `{token, expires_at}` — used by login; returns plaintext exactly once.
- `Session::revoke_user($wp_user_id)` — kills all live sessions (logout/suspend/role change).
- `Session::rotate($wp_user_id, $old_token)` — issue new + revoke old (privilege change).
- `Session::status(...)` — pure state machine `active|idle_expired|abs_expired|revoked`.

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| USER_REGISTERED | `{wp_user_id, telegram_user_id}` | first login for a Telegram user (profile row created) |

## Events Consumed
| Event | Action |
|-------|--------|
| — | none this phase |

## Owned Tables
- tb_identity (telegram_user_id PK, immutable; maps 1:1 to a WP user)
- tb_sessions (token_hash PK — SHA-256 only; plaintext never persisted)
- tb_customer_profiles (display_name, location_id; PK wp_user_id)
- tb_consents (schema_only this phase — the consent workflow ships with a later phase)

## Invariants
- Trust model: initData HMAC is the sole gate. `telegram_user_id` from the client JSON is never trusted; only the verified `user.id` is stored.
- Sessions: Bearer transport; absolute TTL 24 h, idle TTL 2 h clocked by `last_seen_at`; storage is the SHA-256 hash of the token only.
- Multi-device: login does not revoke earlier sessions. Revocation happens on logout/suspend/role-change only.
- Rate limit: auth brute-force guard is 10 session creations / min / Telegram user id → `RATE_LIMITED` + Retry-After; initData replay window is 300 s → `AUTH_REPLAY_DETECTED`.
- `telegram_user_id` is immutable once written. PATCH /me touches only the three listed fields.
- Locations are validated through catalog; nonexistent ids return LOCATION_NOT_FOUND.
- §B.12.3: initData and tokens never appear in logs, audit, error context, or events.
- Capability check runs in the service layer (Rest dispatch), not the permission_callback (WP treats any non-false return as allow).