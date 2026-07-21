# Amaiza | SugarAdminCLI

Console commands for every action on SugarCRM's classic Administration "Repair" page (`index.php?module=Administration&action=Upgrade`) — Quick Repair and Rebuild, Rebuild Relationships, Repair Roles, Rebuild Schedulers, and the rest — plus a handful of maintenance commands with no stock Sugar UI equivalent at all (restore a soft-deleted record, purge old soft-deleted data, custom-table orphan cleanup, maintenance mode toggle). Runnable headlessly over `bin/sugarcrm`, without clicking through the admin UI.

## Features

- One `admin:repair:*` command per stock repair action (19 total), plus an `admin:qrr` alias for the most-used one (Quick Repair and Rebuild)
- 6 additional `admin:repair:*`/`admin:maintenance:*` commands covering real maintenance gaps stock Sugar has no UI for at all
- Every command drives Sugar's own core repair file/function/scheduled-job directly (never reimplements Sugar's logic), so behavior stays in sync with core across versions
- Scriptable: chain repair steps in deploy pipelines, cron jobs, or CI without a browser session

## Requirements

| | |
|---|---|
| Sugar edition | Sugar Enterprise (ENT) |
| Sugar versions | 25.x through 26.x |
| Development | PHP 8.4+, Composer |

## Repository layout

| Path | Purpose |
|---|---|
| `crm/custom/src/amaiza/SugarAdminCLI/Console/Command/` | The command classes |
| `crm/custom/Extension/application/Ext/Console/` | Sugar Extension-framework registration of those commands |
| `tests/` | PHPUnit suite against stubbed Sugar globals/classes (no live Sugar instance required) |
| `bin/sugar-module-install.php` | Link or copy `crm/custom/` into a local Sugar instance for development |
| `bin/pack.php` | Build a distributable archive of `crm/custom/` |

## Development setup

1. `composer install`
2. `cp sugar.env.php.dist sugar.env.php` (edit `sugar_root` to point at your local Sugar instance)
3. `composer sugar:module:install && composer sugar:repair`
4. Run a command for real: `php bin/sugarcrm admin:qrr` (from inside your Sugar root)

## Commands

| Command | Repairs |
|---|---|
| `admin:repair:qrr` (alias `admin:qrr`) | DB, Extensions, Vardefs, Dashlets, etc. — same as "Quick Repair and Rebuild" |
| `admin:repair:teams:upgrade` | Creates teams for users |
| `admin:repair:htaccess` | Rebuilds `.htaccess` |
| `admin:repair:config` | Rebuilds `config.php` |
| `admin:repair:sugarlogic` | Rebuilds Sugar Logic functions cache |
| `admin:repair:relationships` | Rebuilds relationship metadata |
| `admin:repair:schedulers` | Rebuilds out-of-the-box Scheduler Jobs |
| `admin:repair:workflow` | Rebuilds WorkFlow cache & compiles plugins |
| `admin:repair:js-languages` | Rebuilds JS versions of language files |
| `admin:repair:js-groupings` | Re-concatenates JS group files |
| `admin:repair:sprites` | Rebuilds sprite images/config |
| `admin:repair:field-casing` | Repairs mixed-case custom table/metadata fields |
| `admin:repair:teams` | Rebuilds private team memberships from reporting hierarchy |
| `admin:repair:roles` | Adds new modules/ACLs to Roles |
| `admin:repair:inbound-email` | Repairs Inbound Email accounts, re-encrypts passwords |
| `admin:repair:xss` | Removes XSS vulnerabilities from the database |
| `admin:repair:activities` | Repairs Calls/Meetings end dates |
| `admin:repair:seed-users` | Enables/disables demo seed users |
| `admin:repair:cache:clear` | Clears additional (API/other) cached resources |

Not stock Administration > Repair actions — no equivalent exists in Sugar core. Modeled on [esimonetti/toothpaste](https://github.com/esimonetti/toothpaste)'s commands (Apache-2.0), reimplemented directly against Sugar's own APIs rather than reusing that project's code:

| Command | Does |
|---|---|
| `admin:repair:restore-record` | Restores a soft-deleted record and most of its relationships (`--module`, `--record`) |
| `admin:repair:orphans-cleanup` | Deletes `_cstm` table rows with no matching core table row (permanent SQL delete, not soft) — requires confirmation, see below |
| `admin:repair:prune-database` | Runs Sugar's own OOTB "Prune Database" scheduled job synchronously (hard-deletes old soft-deleted records + optimizes affected tables), optionally scoped to specific tables (`--table mytable,mytable_cstm`) — requires confirmation, see below |
| `admin:repair:missing-tables` | Creates any SQL table missing entirely, for recovering an incomplete/partial-backup instance |
| `admin:maintenance:on` / `admin:maintenance:off` | Toggles `maintenanceMode` — normally only settable by hand-editing `config_override.php` |

`admin:repair:orphans-cleanup` and `admin:repair:prune-database` perform permanent, irreversible deletion with no backup, so both require confirmation before running: pass `--yes`/`-y` to proceed non-interactively (scripts/CI), otherwise you'll get an interactive `[y/N]` prompt. Running non-interactively without `--yes` fails with a clear error rather than silently doing nothing.

See RELEASENOTES.md for what's implemented vs. pending live verification.

## Unit tests

Run locally: `composer test` (or `vendor/bin/phpunit`). Tests run against `tests/Support/SugarStubs.php`, lightweight fakes for the Sugar globals/classes each command references — no live Sugar instance required. Stub coverage verifies each command pre-seeds the correct request/global state; it is not a substitute for running a command for real against a live Sugar instance (see AGENTS.md).

## License

Copyright (C) Amaiza LLC. Proprietary — see LICENSE.txt.
