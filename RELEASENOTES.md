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

Added `admin:repair:export-records --module X --ids A,B,C [--output path]`:
a standalone safety-net command that exports full record data (core table
row, plus the `_cstm` row if the module has custom fields) for a given list
of ids to a JSON Lines file — a timestamped file under
`./sugaradmincli-backups/` by default, or an explicit `--output` path.
**Not yet live-verified.**

All 3 existing destructive commands now support an opt-in
`--backup-dir=PATH` option: when passed, the exact rows about to be deleted
are written to a timestamped JSON Lines file under that directory *before*
the delete happens, using the same `AbstractRepairCommand::appendBackupRows()`
mechanism `export-records` uses directly. Omitting `--backup-dir` (the
default) skips backing up entirely — unchanged, already-shipped behavior.
`--backup-dir` is ignored during `--dry-run` (nothing is deleted, so there's
nothing to back up).

- `admin:repair:orphans-cleanup` — backs up each batch's full rows
  immediately before its own `DELETE ... WHERE id IN (...)` statement.
- `admin:repair:prune-database` — backs up a one-time snapshot of every row
  matching the same `deleted=1 AND date_modified < threshold` criteria
  `--dry-run` already reports, before invoking the real `pruneDatabase()`
  job (its own internal batching is treated as a black box, same as before).
- `admin:repair:orphaned-parent-cleanup` — backs up each record's core row
  (plus its `_cstm` row, if any) immediately before that record's own
  `mark_deleted()` call.

`admin:repair:orphaned-parent-cleanup` fixes found live against keecor's real
`Calls` data:

- A distinct `parent_type` value that doesn't resolve to any real module at
  all (e.g. a stray `"Account"` instead of `"Accounts"`) was previously just
  skipped with a message — every record carrying it can never have a valid
  parent, so it's now treated as orphaned too. Since "the type itself is
  invalid" is a broader, riskier judgment call than "the type is valid but
  this one row's parent is missing," it requires its own explicit
  confirmation per distinct invalid `parent_type` (new
  `AbstractRepairCommand::confirmOrSkip()` — declining skips just that group,
  not the whole run) rather than being silently bulk-included or bulk-skipped.
  `--dry-run` reports these the same as any other orphan, with no prompt.
- **Real Sugar core bug, not a logic hook**: `SugarBean::mark_deleted()`
  unconditionally calls `InteractionsHelper::deleteInteraction()` first,
  which — for `Calls`/`Meetings` (the only 2 stock modules with a dictionary
  `'interactions'` entry, e.g. `Calls` tracking `Accounts.last_call_date` when
  `status = 'Held'`) — retrieves the parent bean via a non-nullable
  `BeanFactory::retrieveBean(): SugarBean` return type. For exactly the
  records this command targets, that parent doesn't exist, so `retrieveBean()`
  returns `null` and Sugar core throws a `TypeError` before the delete logic
  even runs — every `Held` orphaned Call failed this way in live testing.
  Fixed by clearing `parent_id`/`parent_type` in memory right before calling
  `mark_deleted()`: safe, because `doMarkDeleted()`'s actual DB write never
  touches those two fields (so nothing is persisted differently), and the
  real stored values are already captured by `--backup-dir` if it was passed.

Added Customer Journey ("Smart Guide") diagnostics, verified directly against
`src/CustomerJourney/LogicHooks/ActivityHooksHelper.php` (where the exact
blocking error text originates), `modules/DRI_Workflows/vardefs.php`/
`DRI_Workflow.php`'s `STATE_*` constants, and
`src/CustomerJourney/Bean/Journey/Canceller.php` (the real "Terminate" logic):

- `admin:report:blocked-record --module X --record Y [--unblock] [--cancel-journey]`
  — reports whether the record is linked to a journey/stage (only
  Tasks/Calls/Meetings carry the `dri_workflow_id`/`dri_subworkflow_id` field
  pair) and their current `state`, plus whether `ActivityHelper::isBlocked()`/
  `isBlockedByStage()` currently blocks it and by what. `--unblock` sets the
  runtime-only `ignore_blocked_by` property (confirmed: no vardef, never
  persisted) and saves, bypassing the block for that one save only.
  `--cancel-journey` calls the real `Canceller::cancel()` (same class the
  UI's Terminate action uses). Both require confirmation.
- `admin:report:stale-journeys [--days 30]` — lists `dri_workflows` rows
  still `state = 'in_progress'`, not archived, started more than the
  threshold ago.

Both **not yet live-verified.**

Added `admin:repair:prune-bpm-tables --before=YYYY-MM-DD [--dry-run]` and
`admin:repair:prune-bpm-tables --drop-renamed-tables [--dry-run]`, directly
implementing Sugar Support's own recommended SugarBPM (`pmse_*`) table
cleanup procedure (provided to this instance's admin on 2025-07-09): a
`CREATE => RENAME => INSERT` dance per table (`pmse_bpm_flow`,
`pmse_bpm_form_action`, `pmse_bpm_thread`, then `pmse_inbox` last — the first
3 batches all query the original, not-yet-renamed `pmse_inbox`, so it must be
touched last), keeping every row for a case newer than `--before` or
currently `IN PROGRESS`, plus (per Sugar's own defect #93985, to avoid a
spurious re-trigger) every `pmse_bpm_flow` first-run row belonging to a
process with a First Update/Updated start event. Old data is renamed to
`{table}_delete`, not dropped, until a separate `--drop-renamed-tables` run
removes it for good. Both actions require interactive confirmation with
**no `--yes` bypass at all** (`AbstractRepairCommand::requireInteractiveConfirmation()`,
a new, stronger gate than `confirmDestructiveAction()`) — per instruction,
since the prune can run for a long time against production-sized tables and
neither action should ever be triggerable unattended. The prune also pauses
every currently-`Active` Schedulers record (`status = 'Inactive'`) before
touching any table and restores exactly those records afterward in a
`finally` block, so no scheduled job's `cron.php` invocation can race the
rename/copy sequence. **Not yet live-verified** — this is the highest-risk
command in the package: it runs raw DDL (`CREATE TABLE`, `RENAME TABLE`,
`DROP TABLE`) directly via Doctrine DBAL, entirely bypassing the bean/ORM
layer, and a real database backup is strongly recommended regardless of
`--dry-run` testing first.

Added `admin:report:stale-bpm-cases [--days 30] [--case-id ID] [--terminate]`
for real SugarBPM (`pmse_*`) — the counterpart to the Customer Journey
diagnostics above. Verified directly against
`modules/pmse_Inbox/clients/base/api/PMSEEngineApi.php::cancelCase()` and
`modules/pmse_Inbox/engine/PMSEHandlers/PMSECaseFlowHandler.php::terminateCase()`
— the exact classes/methods the stock "Terminate" admin action itself uses.
`pmse_Inbox.cas_status = 'IN PROGRESS'` is the authoritative "still running"
signal (confirmed via `PMSEEngineUtils::getBPMInboxStatus()` and
`PMSEEngineApi::getUnattendedCases()`, both checking exactly this) —
`pmse_bpm_flow.cas_flow_status` is a per-step value with a different
vocabulary entirely and isn't authoritative for the case as a whole.
`--terminate` replicates `cancelCase()`'s own pre-check (skip if already
`CANCELLED`/`TERMINATED`/`COMPLETED`) before calling the real
`PMSECaseFlowHandler::terminateCase()`, gated behind confirmation.
**Not yet live-verified.**

Added `admin:report:duplicates --module X [--limit 500] [--offset 0]` —
finds (does not merge) likely duplicate records within a module, by calling
Sugar's own per-record `SugarBean::findDuplicates()` (`data/SugarBean.php:8339`)
for every record in a bounded batch, since there's no bulk/module-wide "scan
for duplicates" API in Sugar core. Only modules with a `duplicate_check`
vardef entry support this at all (Accounts/Leads/Contacts out of the box);
any other module errors clearly. Deliberately find-only: Sugar has no
server-side "merge two records" API at all (the UI wizard does it in 3
JS-orchestrated steps with no PHP equivalent) — a from-scratch
relationship-reassignment implementation would be unreviewed, real, risky
logic, and is out of scope here, tracked as separate future work if ever
pursued. **Not yet live-verified.**

Added `admin:report:logic-hooks [--module X]` — audits every
`custom/modules/*/Ext/LogicHooks/logichooks.ext.php` (the Extension Model
this repo's own CLAUDE.md documents) for two problems CLAUDE.md already
tells contributors to check by hand: two hooks on the same module + event
sharing the same priority integer (undefined execution order), and a
registered file/class/method that no longer exists on disk (would fail
silently or fatally the next time that event fires, not at scan time).
Purely a filesystem/reflection scan, never touches the database. Has real
PHPUnit coverage: a fixture `logichooks.ext.php`
(`tests/fixtures/sugar/custom/modules/TestModule/...`) with a deliberate
priority collision and a missing-file hook, asserting both are flagged.
**Not yet live-verified against a real instance's full custom module set**
(only against the test fixture), though it's read-only with no destructive
path at all.

Added `admin:report:field-usage --module X [--threshold 1]` and
`admin:report:dropdown-usage --module X` (Forge-derived ideas, adapted to
this repo's own bean/DBAL APIs). Both **live-verified against keecor's real
28,050-row Accounts table** — field-usage correctly reported 230 custom
columns with real population percentages (flagging 54 under 1% as removal
candidates, e.g. `nsbillingaccountid_c`/`nscustomerid_c`/`nssubcustomerid_c`
at 0.00%); dropdown-usage correctly found 243 unused configured options
across 55 enum fields (e.g. departed employees still listed in
`primary_market_president_c`'s dropdown).

- `field-usage` uses the `_cstm` table's real DB columns
  (`$db->get_columns()`, the same ground-truth source `export-records` and
  `orphans-cleanup` already use) rather than iterating vardef `field_defs` —
  a field removed from Studio but never dropped from the DB (exactly the
  kind of orphaned column this report exists to find) has no vardef entry at
  all, so vardefs alone would miss it.
- `dropdown-usage` is scoped to `enum` fields only, not `multienum` (packed
  caret-delimited storage needs different diff logic — skipped with a
  message rather than silently mis-covering it). Found and fixed a real gap
  during live testing: `$app_list_strings` is **not** pre-populated by the
  Symfony console bootstrap the way it is for a web request or `cron.php`'s
  own explicit load — every dom lookup silently came back empty until fixed
  by loading it explicitly the same way `cron.php` does
  (`return_app_list_strings_language()`).

Both **not yet live-verified against modules other than Accounts**, though
the underlying logic is module-agnostic.

Added `admin:report:schema-diff [--module X]`, `admin:report:table-sizes
[--top 20]`, and `admin:report:stuck-jobs [--minutes 60] [--failure-hours 24]`
(Forge-derived ideas for the first, direct DB/job_queue introspection for
the other two). All three **live-verified against keecor's real data** —
`table-sizes` correctly ranked real tables (`tasks` at 2.58 GB /
772,009 rows topping the list); `stuck-jobs` ran cleanly against the real
`job_queue` table (confirmed table name, not `schedulers_jobs`) with no
false positives; `schema-diff` against the real `Accounts` module correctly
found 4 genuinely orphaned DB columns (`lead_source`, `opportunity_type`,
`sales_stage`, `mailchimp_rating_c` — no vardef entry at all, confirmed by
inspecting `$bean->field_defs` directly) with zero false positives after a
live-testing fix (below).

- `schema-diff` initially had a **real false-positive bug**, found via live
  testing: it only recognized a custom field via a `custom_module` key in
  its compiled `field_defs` entry, but some real custom fields instead carry
  `'source' => 'custom_fields'` with no `custom_module` key at all (both
  markers exist depending on how the field was created) — the check missed
  that second form and flagged legitimate `_cstm`-backed custom fields
  (e.g. `fbsg_platinum_parts_held_c`) as "no matching DB column." Fixed by
  checking for either marker.
- `stuck-jobs` treats `job_queue.date_modified` as the right "how long
  stuck" signal despite it not being a live heartbeat during execution — a
  `status='running'` row only gets that status (and its `date_modified`
  stamp) set once, when the job actually started, so an old
  `date_modified` on a still-`running` row means it genuinely hasn't
  progressed, not that it just hasn't heartbeated recently.
- `table-sizes` reports `TABLE_ROWS` from `information_schema` as an
  approximation (InnoDB's own internal estimate, not an exact count),
  labeled as such rather than presented as precise.
