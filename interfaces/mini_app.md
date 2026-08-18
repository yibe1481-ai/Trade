# Module: mini_app
**Purpose:** Telegram Mini App client — contextual onboarding and session handling.
**Status:** not_started
**Depends on:** identity

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| GET | /trade/v1/mini_app/onboard | `?step?` | onboard step data | session | — |
| POST | /trade/v1/mini_app/session | `{session_data}` | `{validated, user_id}` | session | AUTH_INVALID_SESSION |

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| — | none | this phase |

## Events Consumed
| Event | Action |
|-------|--------|
| — | none |

## Invariants
- Client only. No privileged logic — all validation goes through the service layer
- session_token passed via Authorization header, validated against tb_sessions
- Contextual open based on onboarding_state from /auth/session
- Mini App never directly mutates marketplace tables
- Missing or expired sessions → redirect to login / re-auth flow