# Changelog

## 1.1.0 — 2026-10-13

### Added

- `AuditSearchContract` (published, bound and listed in `exposes`): a filtered,
  counted, offset-paged read of `audit_log`, all in SQL over the whole trail.
  `search()`, `count()`, `countByAction()`, `topActors()`, `countActors()`.
- `AuditSearch` filter object: tenant, actor, exact action list, `since`, a
  meta key/value match (`metaAny`) and a free-text match over action, IP, event
  id, meta key names, and (supplied by the caller, since the trail stores ids
  only) actor ids and meta values. `LIKE` wildcards are escaped, every value is
  bound, meta keys are restricted to `[A-Za-z0-9_]`.
- Opt-in and backward compatible: `AuditReaderContract` is unchanged (a new
  method on it would break other implementers), and a consumer injects the new
  contract as optional, falling back to `recent()` on an older plugin.

No migration: the new filters ride the primary key (`ORDER BY id DESC`) and the
existing `(action|tenant_id|user_id, occurred_at)` indexes; meta matches are a
scan of the filtered rows, as a JSON path cannot be indexed without a
generated column.
