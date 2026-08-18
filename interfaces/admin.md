# Module: admin
**Purpose:** Trade Bot Control Center inside WordPress Admin.
**Status:** not_started
**Depends on:** all stable modules

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