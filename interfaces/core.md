# Module: core
**Purpose:** Shared kernel — event bus, audit writer, job queue, feature flags, idempotency middleware, error envelope, REST base. Everything else builds on this.
**Status:** in_progress
**Depends on:** —

## Public REST API
| Method | Path | Params / Body | Returns | Auth | Errors |
|--------|------|---------------|---------|------|--------|
| GET | /trade/v1/system/status | — | `{success,data:{plugin,schema,tables,flags,languages,request_id}}` | capability | — |
| POST | /trade/v1/system/echo | `{"message": ≤500 chars}` | `{success,data:{echo,received_at,request_id}}` | capability | VALIDATION_FAILED |

> Both are gated behind flag `trade_dev_routes_enabled` (default **off**) — the Phase 0 exit-criterion proof. Removed when Phase 2 ships real listing endpoints. Other modules' controllers use `Rest::register()` — they never see these.

## Service API (used by all modules)
- `Events::emit(name, payload)` → INSERT `tb_events` (always) + `do_action('trade.event')` + `do_action('trade.{name}')` to wake the worker/other modules. Never skip on `wp-cron` absence; worker polls `tb_jobs`.
- `Audit::write(action, entity, entity_id, actor, before, after, metadata, source)` → INSERT `tb_audit_logs` with `request_id` from `Request::id()`. Only core writes the two log tables.
- `Flags::get(key)` / `Flags::all()` → row-driven; `Flags::set(key, bool, updated_by)` for the Phase 6 Control Center.
- `Throttle::hit($bucket, $window_seconds, $limit)` → `{allowed, retry_after}` — sliding window against `tb_throttle`. Used by identity (replay + auth rate limit); reusable anywhere a per-key rate limit is needed. `retry_after` is the seconds until window end.
- `Jobs` (§B.9) — see below.
- `Exception($code, $module, $message, $context, $retryable)` — the only way controllers signal errors; `Rest` renders it into the §B.10 envelope.
- `Error::envelope()/ok()/status()/validation()` — singleton render rules.

## Jobs API (worker + async modules)
- `enqueue($type, $payload, $opts{idempotency_key?, max_attempts?=5, run_after?})` → int id or existing id when idempotency_key reused.
- `claim($type=null)` → job row `{id,type,payload_json,status:'running',attempts,lock_token}` or null. **At-least-once, lease protocol mandatory.**
- `complete($id, $lock_token)` / `fail($id, $lock_token, $error)` — fail requeues with backoff 1m/5m/15m/1h/6h; at `max_attempts` → `dead_letter` + `JOB_DEAD_LETTERED`.
- `heartbeat($id, $lock_token)`; `reap()` requeues expired running leases.

## Events Emitted
| Event | Payload | When |
|-------|---------|------|
| JOB_DEAD_LETTERED | `{job_id,type,attempts,last_error}` | fail() exhausts max_attempts |

## Events Consumed
| Event | Action |
|-------|--------|
| system.echo (dev only) | proof endpoint; removed Phase 2 |

## Owned Tables
- tb_events, tb_audit_logs, tb_jobs, tb_feature_flags, tb_idempotency_keys, tb_throttle
(all literal `tb_` prefix, utf8mb4; schema versioned via `trade_schema_version` option)

## Invariants
- No module writes `tb_events`/`tb_audit_logs`/`tb_jobs`/`tb_feature_flags`/`tb_idempotency_keys`/`tb_throttle` directly — only through core.
- Controllers raise `Trade\Core\Exception`; models never construct `WP_REST_Response` or call `wpdb`.
- Mutating REST methods accept `Idempotency-Key` (§B.8 4-rule); replay returns the stored envelope + status.
- Capability guard runs inside dispatch (WP treats any non-false permission_callback return as allow), so denials carry the §B.10 envelope.
- Jobs are at-least-once: reaped/running jobs may re-run after lease expiry; handlers must be idempotent.