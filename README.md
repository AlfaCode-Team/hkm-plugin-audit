# hkm-plugin-audit

> HKM Kernel plugin — provides **`audit.trail`**.
> Part of the [HKM Kernel](https://github.com/AlfaCode-Team/hkm-kernel) Gated Demand Architecture framework.

[![License: MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)

## Install

```bash
composer require alfacode-team/hkm-plugin-audit
# or, from a project:
hkm plugins add audit
```

## Capability

Provides `audit.trail`. Requires: `database.management`.

Published contracts: `AuditServiceContract` (write), `AuditReaderContract`
(keyset-paged reads, retention purge) and `AuditSearchContract` (filtered,
counted, offset-paged reads with per-action and per-actor counts; see
[CHANGELOG](CHANGELOG.md)).

## Documentation

- [Kernel guides](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/docs/guides) — the framework contracts this plugin builds on.

## License

MIT — see [LICENSE](LICENSE).
