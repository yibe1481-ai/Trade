# Module: localization
**Purpose:** Languages and translated UI strings for Telegram-facing text (en/am at MVP).
**Status:** stable
**Depends on:** core

## Service API
- `Lang::text($string_key, $lang, $store = null)` → `{lang row}` else `{en row}` else bare `$string_key`. User-facing text never hard-codes a string.

## Public REST API
None at this phase — admin CRUD lands with the Phase 6 Control Center.

## Events Emitted
none

## Events Consumed
none

## Owned Tables
- tb_languages (code PK; enabled; is_default)
- tb_translations (UNIQUE `(language_code, string_key)`; utf8mb4 — Amharic requires it)

## Invariants
- Launch languages: en, am. `trade_default_lang` option = default fallback.
- Missing translation falls back en → bare key; never a blank string.
- All seed strings are in core seeds (`INTERNAL_ERROR` etc. en+am); Amharic glosses are `# ponytail: needs native-speaker gloss before launch` until reviewed.