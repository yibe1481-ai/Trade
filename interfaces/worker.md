# Module: worker
**Purpose:** External Python service for reliable background jobs.
**Status:** not_started
**Depends on:** core (jobs API only)

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| POST | /trade/v1/worker/lease | `{job_type, payload?}` | `{job_id, lock_token, expires_at}` | worker_capability | — |
| POST | /trade/v1/worker/complete | `{job_id, lock_token, result?}` | `{status}` | worker_capability | — |
| POST | /trade/v1/worker/fail | `{job_id, lock_token, error?}` | `{status}` | worker_capability | — |

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| — | none | this phase |

## Events Consumed
| Event | Action |
|-------|--------|
| — | none |

## Invariants
- Owns no marketplace tables directly — reads/writes only through core Jobs API
- Lease protocol mandatory: job must be released or expired before re-claim
- At-least-once delivery: handlers must be idempotent
- Authenticates with `tb_worker` capability — never writes marketplace tables directly
- Calls REST only — no direct SQL on tb_ tables
- Job types must map to existing core job enqueue types
- Lock timeout must respect core Jobs::retry_after() schedule