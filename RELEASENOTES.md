# Release Notes

## [Unreleased]

Initial scaffold. Command classes live in a product-scoped namespace
directory (`crm/custom/src/amaiza/SugarAdminCLI/Console/Command/`) rather
than the shared `custom/src/Console/Command/`, to avoid colliding with
other custom or Amaiza-product commands in the same Sugar instance —
matches the `custom/src/amaiza` convention JobQueue already established,
one level more specific per-product.

Fully implemented and live-verified (symlinked in, ran for real, confirmed
side effects, then cleanly uninstalled) against a real Sugar instance:

- `admin:repair:qrr` (alias `admin:qrr`)
- `admin:repair:relationships`
- `admin:repair:roles`
- `admin:repair:sprites`

The remaining 15 commands have their `$_REQUEST`/global pre-seed and target
core file already filled in (based on reading the stock Sugar core files
directly), but have **not yet been run against a live Sugar instance** to
confirm they perform the expected repair — treat as pending verification
before relying on them:

- `admin:repair:teams:upgrade`
- `admin:repair:htaccess`
- `admin:repair:config`
- `admin:repair:sugarlogic`
- `admin:repair:schedulers`
- `admin:repair:workflow`
- `admin:repair:js-languages`
- `admin:repair:js-groupings`
- `admin:repair:field-casing`
- `admin:repair:teams`
- `admin:repair:inbound-email`
- `admin:repair:xss`
- `admin:repair:activities`
- `admin:repair:seed-users`
- `admin:repair:cache:clear`

Also added: 6 commands with no stock Sugar Repair-page equivalent, modeled
on esimonetti/toothpaste (Apache-2.0) but reimplemented directly against
Sugar's own APIs. `admin:maintenance:on`/`admin:maintenance:off` have real
unit test coverage (`Configurator` is fully stubbed); the other 4 are
**not yet live-verified**, and `admin:repair:prune-database` and
`admin:repair:missing-tables` are the highest-risk commands in this package
(the former performs real irreversible data deletion; the latter
require()s every metadata/vardefs file directly and relies on an internal
implementation detail of `PruneDatabaseService` for its `--table` option) —
test both against a disposable, non-production instance before relying on
them:

- `admin:repair:restore-record`
- `admin:repair:orphans-cleanup`
- `admin:repair:prune-database`
- `admin:repair:missing-tables`
- `admin:maintenance:on`
- `admin:maintenance:off`
