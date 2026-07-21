---
slug: sugar-admin-cli
name: SugarAdminCLI
status: preview
category: Administration and DevOps
seo_title: SugarAdminCLI Preview | Amaiza
seo_description: Preview SugarAdminCLI, a command-line toolkit for automating Sugar repair, maintenance, deployment, and recovery operations.
---

# SugarAdminCLI

## Automate Sugar repair and maintenance from the command line

SugarAdminCLI turns the actions from Sugar's Administration > Repair page into repeatable console commands. It is designed for administrators and development teams that want to run maintenance consistently during deployments, recovery work, scheduled operations, and CI/CD workflows.

## Preview capabilities

- Run Quick Repair and Rebuild without navigating the Administration interface.
- Rebuild relationships, roles, schedulers, JavaScript assets, caches, and other Sugar resources.
- Toggle maintenance mode from an automated workflow.
- Restore soft-deleted records and repair missing database tables.
- Find and clean up orphaned custom, audit, and parent records.
- Run database-pruning operations with explicit confirmation controls.
- Preview deletion counts with dry-run options before changing data.
- Use Sugar's own repair implementations rather than duplicating core behavior.

## Designed for repeatable operations

Each command invokes Sugar's native repair or maintenance implementation and presents it through Symfony Console. Teams can run the same operation interactively, over SSH, or as part of a controlled deployment pipeline.

## Planned compatibility

- **Sugar versions:** 25.x and 26.x
- **Sugar product:** Sugar Enterprise
- **Deployment:** On-site
- **Access:** Server command-line access

## Availability

SugarAdminCLI is in development and is not yet available for purchase. Some commands have been verified against live Sugar environments; the remaining commands are being validated before release.

Contact Amaiza if you are interested in the product preview or would like to discuss an early-access deployment.
