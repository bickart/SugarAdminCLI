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

`admin:repair:orphans-cleanup` and `admin:repair:prune-database` now
require confirmation before running (`--yes`/`-y` to skip non-interactively,
otherwise an interactive `[y/N]` prompt) since both perform permanent
deletion with no backup — covered by dedicated unit tests
(`ConfirmDestructiveActionTest`) against the shared
`AbstractRepairCommand::confirmDestructiveAction()` mechanism.

Also added `admin:repair:orphaned-parent-cleanup`: generalizes a
customer-specific command (`dynatron:clean:tasks`, Tasks-only) into a
configurable `--modules` list, defaulting to the 5 stock modules verified
(directly against Sugar 26's vardefs) to actually have the
`parent_type`/`parent_id` flex-relate field pair — Tasks, Calls, Meetings,
Notes, Emails. Uses `mark_deleted()` (soft delete, reversible via
`admin:repair:restore-record`), so no confirmation gate needed. **Not yet
live-verified.**

`admin:repair:orphans-cleanup` now also cleans up per-module `_audit`
tables (`parent_id -> core.id`, existence checked via `$db->tableExists()`
the same way Sugar's own core code guards these tables) and the shared
`audit_events` table (`parent_id -> core.id`, additionally scoped by
`module_name` since one table covers every module) — previously it only
handled `_cstm` tables. Both field mappings verified directly against
`metadata/audit_templateMetaData.php` and `metadata/audit_eventsMetaData.php`.

All three commands that delete data now support `--dry-run` (reports
per-table/module counts and a total without deleting anything, and skips
the confirmation prompt entirely since nothing destructive happens):
`admin:repair:orphans-cleanup`, `admin:repair:prune-database`, and
`admin:repair:orphaned-parent-cleanup`. For prune-database, dry-run
reimplements just the row-selection criteria (`deleted=1 AND
date_modified < threshold`, on tables with both columns) as read-only
COUNT(*) queries, since the underlying `PruneDatabaseService` has no
preview mode of its own and dry-run never touches it or creates a
SchedulersJob.

`admin:repair:orphans-cleanup` now always reports scan coverage (how many
`_cstm`/`_audit` tables and `audit_events` module_names were actually
checked) before the total, so a "0 found" result is distinguishable from a
silent no-op scan.

An independent adversarial review (reading every command against real
Sugar 26 source, not just this package's own tests) found and fixed 3 real
bugs, plus live testing against keecor's `test/sugar-admin-cli` branch
surfaced a 4th:

- **`RestoreRecordCommand`** — the join-table (M2M) relationship-restore
  logic never worked: `Link2::getBeans(['deleted' => 1])` filters on
  whether the *join row itself* was removed (via `add_deleted` internally),
  not on whether the related bean is itself soft-deleted, so it silently
  restored nothing in the common case and could wrongly re-link a
  deliberately-unlinked record in an uncommon one. Fixed with a direct
  join-table query (`join_table` INNER JOIN the related core table WHERE
  the join row is active but the related row is `deleted=1`).
- **`RepairMissingTablesCommand`** — the dictionary glob missed
  `custom/metadata/*.php`, which is exactly where every Studio-created
  custom relationship's join-table definition lives — meaning a missing
  custom-relationship join table (this command's whole reason to exist)
  would never actually get recreated. Added that glob.
- **`OrphanedParentCleanupCommand`** — reused one never-`retrieve()`'d bean
  instance across every `mark_deleted()` call instead of retrieving each
  record first, so `SugarBean::doMarkDeleted()`'s team-set cleanup silently
  no-op'd (it reads `team_set_id` off a blank bean) and delete-time logic
  hooks ran against empty field data instead of the real record.
- **Performance + robustness (found live against keecor, 2M+ row Tasks
  table)**: the original per-row `BeanFactory::getBean()` check for each
  candidate record doesn't scale (2M individual queries). Rewrote to a
  single `LEFT JOIN ... WHERE core.id IS NULL` query per distinct
  `parent_type` present in the module. Also: a custom logic hook blocking
  deletion of one record (e.g. an active SugarBPM/"Smart Guide" workflow
  gate) previously aborted the entire command — now each `mark_deleted()`
  call is individually try/caught, reported, and the rest of the batch
  continues. Also added `--yes`/confirmation (previously skipped since
  soft-delete seemed reversible-enough; in practice a real mutation at this
  scale warrants the same gate as the hard-delete commands), and the
  command now reports which modules it's actually processing instead of
  always listing the full default set regardless of `--modules`.
