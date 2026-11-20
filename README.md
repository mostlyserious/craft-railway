# craft-railway

A Railway pre-deploy command for Craft CMS 5.

```sh
php craft railway/pre-deploy
```

Use it as the service's pre-deploy command in place of `php craft up --interactive=0`.

## Why

Railway runs every deploy's pre-deploy command, even when two commits deploy seconds apart. When two `craft up` runs overlap, Craft skips the second one and still exits 0, so that deploy goes live with its migrations and project config unapplied.

## What it does

1. **Waits its turn.** It takes the same lock Craft's `up` command uses, waiting up to 5 minutes. Overlapping deploys queue instead of skipping, and a manual `craft up` is excluded too. If the wait times out, it exits 75.
2. **Refuses stale releases.** If the database's project config `dateModified` is newer than this release's YAML, a newer release has already been applied. It exits 1 without running `up`, so the older release never goes live over newer config. This check is skipped when `allowAdminChanges` is on, since the database can then legitimately be ahead.
3. **Runs `craft up`** in a fresh process, so it sees the database as it is after the wait. Its exit code is passed through.
4. **Verifies the result** in another fresh process. It exits 1 if migrations are still pending or the database's project config doesn't match the YAML on disk.

It also exits 1 when it can't confirm the deploy is safe: Craft isn't installed (or the database is unreachable), the release has no `config/project/project.yaml`, Craft has recorded a failed project config write, the `dateModified` of the database or the release isn't a whole number of seconds, or the lock was lost before it finished. Install Craft before the first deploy that uses this command.

Configure Craft with environment variables (`CRAFT_ENVIRONMENT` and so on). Bootstrap flags such as `--env` aren't passed on to the `craft up` it runs.

## Install

It isn't on Packagist, so add the GitHub repository to the site's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/mostlyserious/craft-railway"
    }
]
```

Then require it:

```sh
composer require mostlyserious/craft-railway:^1.0
```

It's a Yii extension, so there's nothing to configure or install in Craft. Set the service's pre-deploy command to `php craft railway/pre-deploy`, and set a pre-deploy timeout that covers the lock wait plus your slowest migrations, e.g. 900 seconds.

## Environment variables

| Variable | Default | Effect |
|---|---|---|
| `CRAFT_RAILWAY_LOCK_TIMEOUT` | `300` | Seconds to wait for another `craft up` to finish. `0` doesn't wait. Unset or `null` uses the default; any other value that isn't a whole number exits 1. |
| `CRAFT_RAILWAY_FORCE` | off | Skip the stale-release check. Use it to deploy an intentional revert, then unset it. |

## When a deploy is refused as stale

If a newer release is deploying, there's nothing to do. If you meant to deploy older project config (a revert), either run `php craft project-config/touch` and commit, or set `CRAFT_RAILWAY_FORCE=1` and redeploy.

## Limits

- The stale-release check compares `dateModified`, which Craft advances for control panel changes but not for hand-edited YAML or `project-config/set`. Releases with an equal `dateModified` aren't ordered.
- A lost lock is detected when this command releases it, not prevented. Railway kills the whole pre-deploy container on timeout or cancel, so in practice the lock is lost only if its database session drops.
- Verify compares the database with the YAML on disk after `craft up`. If a content migration changes project config, Craft can write that change into the YAML during `up`, so verify passes even though the database no longer matches the YAML the release shipped. The next deploy can reapply the shipped YAML only if it passes the stale-release check; if the migration advanced `dateModified`, it will be refused until you run `php craft project-config/touch` and commit. Make project config changes in dev and commit the YAML instead.
