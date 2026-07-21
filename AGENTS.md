# AGENTS.md

This file provides guidance to AI coding assistants (Claude Code, Codex, or otherwise) when working with code in this repository. `CLAUDE.md` is a symlink to this file — edit here, not there.

## What this is

SugarAdminCLI is a suite of Symfony Console commands (`admin:repair:*`) that expose every action from SugarCRM's classic Administration "Repair" page (Quick Repair and Rebuild, Rebuild Relationships, Repair Roles, etc.) as a headless CLI command, so they can be scripted, run in CI/deploy pipelines, or run over SSH without a browser.

It is not a Sugar bean/UI module — there is no `crm/modules/<Name>` payload, no Module Loader package, no admin panel. The entire product is `crm/custom/src/amaiza/SugarAdminCLI/Console/Command/*.php` (the command classes, in a product-scoped namespace directory to avoid colliding with other custom or Amaiza-product commands sharing the same Sugar instance) plus `crm/custom/Extension/application/Ext/Console/RegisterSugarAdminCliCommands.php` (Sugar's Extension-framework registration of those commands), meant to be copied or symlinked into any Sugar instance's `custom/` tree.

## Core technical pattern — read this before adding or changing a command

Sugar's stock "Repair" actions are implemented as standalone procedural PHP files under `modules/Administration/` (and a few adjacent locations like `modules/ACL/install_actions.php`, `modules/UpgradeWizard/uw_utils.php`, `jssource/minify.php`). Each command class in this repo:

1. Pre-seeds the exact `$_REQUEST`/`$_POST`/global variables the corresponding stock core file expects (documented in each command's class docblock, sourced from reading that stock file directly against a real Sugar install — never guessed).
2. `require`s that stock file directly (or calls the specific function/method it exposes, when one exists) — never reimplements Sugar's own repair logic. This keeps behavior in sync with core across Sugar versions instead of drifting.
3. Wraps the `require`/call in `ob_start()`/`ob_get_clean()` since several of these stock files echo HTML fragments meant for a browser.
4. Reports outcome via `SymfonyStyle`.

A few actions (Rebuild Sprites, Rebuild JS Groupings, Remove XSS) have a top-level action file that's pure UI chrome (renders a form or AJAX trigger) with the real logic living in a *different* file the UI's AJAX handler calls — for those, the command requires/calls that deeper file directly, not the UI shell. Don't assume the file named after the admin UI label is the one with the actual logic; verify against a real Sugar install first.

## Compatibility constraints

- Target whatever Sugar version(s) are declared supported in README.md. Sugar's own core file layout, function signatures, and even which actions are "clean callable" vs. procedural-only can change between major versions — when updating for a new Sugar version, re-verify every command's target file(s) against a real install of that version rather than assuming the old file paths/signatures still hold.
- Keep PHP compatible with whatever floor the target Sugar version's own `composer.json` requires.

## Testing changes

- `composer test` runs the PHPUnit suite against `tests/Support/SugarStubs.php` (lightweight fakes for the Sugar globals/classes the command classes reference) — this verifies each command pre-seeds the correct request/global state and calls the right stub, without needing a live Sugar instance.
- Stub coverage is not a substitute for the real thing: before considering any command done, run it for real via `php bin/sugarcrm admin:repair:<x>` against a real (non-production) Sugar instance and confirm the actual side effect (cache file rebuilt, ACL actions added, sprite images regenerated, etc.) — see README.md's development setup for linking this repo into a local Sugar instance.
- `./vendor/bin/php-cs-fixer fix --dry-run --diff` and `./vendor/bin/phpstan analyse` should be clean before considering a change finished.

## Code style

Same conventions as this author's other SugarCRM tooling repos (`sugar_repair`, `JobQueue`): php-cs-fixer with a Symfony-based ruleset plus PHP 8.4-migration rules, 4-space indentation, `//` comments not `#`, no spaces around string-concatenation `.`, leading `\` for global-namespace **class** references inside a namespaced function body that also has `use` imports (but not for global functions).

## Licensing

Proprietary — see LICENSE.txt. This is a commercial Amaiza product, not open source; don't add MIT/Apache-style headers or assume public-repo conventions (compare against `sugar_repair`, which *is* public/MIT, for contrast).
