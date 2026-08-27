# Past-Due Actions — Action Scheduler Monitor

A WordPress plugin that answers the question WooCommerce raises and then leaves alone:

> **Action Scheduler: 848 past-due actions found; something may be wrong.**

It doesn't say what's wrong, which plugin is responsible, or what to do. This does.

**[What the warning means and how to fix it by hand →](https://re-coder376.github.io/past-due-actions/)**

---

## What it tells you

- **Which hooks are backed up**, grouped and counted — so instead of 848 unreadable rows you see *"812 of these are subscription renewals"*.
- **Which plugin they came from**, worked out from the hook name.
- **How late the oldest one is**, in plain words.
- **Whether they are waiting or failing** — a completely different problem with a completely different fix, and the existing tools don't distinguish them.

## Why the queue stopped

Each diagnostic says what it means and what to do next:

| Check | Why it matters |
|---|---|
| WP-Cron disabled with no server cron replacing it | The most common cause by a distance |
| The site can't call itself | WP-Cron works by requesting your own site; if that's blocked it fails silently |
| The queue hasn't run in days | Whatever the settings claim |
| Jobs failing rather than waiting | Points at a broken plugin, not broken scheduling |
| Backlog too large for the batch size | It will never catch up on its own |
| PHP limits cutting batches short | The same actions retry forever |

## Fixing it

- **Run the queue now** — also the fastest test there is. If actions clear, your jobs are fine and nothing is triggering the queue. If nothing clears, the jobs themselves are failing.
- **Retry failed actions** for one hook, keeping their arguments and history.
- **Cancel a backlog** for one hook, with a clear warning about what will no longer happen.
- **Daily email** if the backlog grows past a threshold you choose.

## How this differs from the cleaner plugins

The existing tools delete **completed** actions to shrink your database. That's useful housekeeping and it does nothing for actions that are stuck. If you're here because of the past-due warning, a cleaner won't help.

This plugin deletes nothing on its own. Cancelling **marks** rows rather than removing them, so the history stays visible and a mistake can be seen.

## Requirements

- WordPress 7.0+
- PHP 7.4+
- Action Scheduler, which ships inside WooCommerce, WooCommerce Subscriptions, WPForms, MailPoet, Jetpack, Easy Digital Downloads and others. You do **not** need WooCommerce specifically.

## Install

Download the latest release, upload it under **Plugins → Add New → Upload Plugin**, activate, then open **Tools → Past-Due Actions**.

## Testing

Verified across PHP 7.4 / 8.3 / 8.4, MariaDB and SQLite, single-site and multisite, with and without WooCommerce HPOS. See [TESTING.md](TESTING.md) for the matrix and how to reproduce it.

## Licence

GPL-2.0-or-later.
