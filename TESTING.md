# Testing

`tests-e2e.php` builds a realistic broken queue — actions scheduled in the past, some
failed, one hook whose actions have *all* failed — then asserts the plugin reports it
correctly and repairs it. It runs through WP-CLI against a real WordPress install, not a
mocked one.

```
wp eval-file tests-e2e.php
```

**57 assertions, 0 failures** in every configuration below.

## The matrix

Every row was actually run. A plugin proven on one configuration is proven on one
configuration.

| PHP | Database | WordPress | Notes |
|---|---|---|---|
| 7.4.33 | MariaDB 11.8.8 | 7.1 | the declared minimum PHP |
| 8.3.33 | MariaDB 11.8.8 | 7.1 | |
| 8.4.25 | MariaDB 11.8.8 | 7.1 | |
| 8.3.33 | SQLite | 7.1 | via the SQLite Database Integration plugin |
| 8.3.33 | SQLite | 7.1 | WooCommerce **HPOS** enabled |
| 8.3.33 | MariaDB 11.8.8 | 7.1 | **multisite**, two sites |
| 8.3.33 | SQLite | 7.1 | installed from the built zip, not the working copy |

## What's covered

**Reading the queue.** Past-due counted correctly and future actions excluded; failures
counted separately; backlog grouped by hook with the worst first and the owning plugin
identified. A hook whose actions have all failed and has nothing pending must still appear
in the table — an inner-joined query silently drops it, while the diagnostics tell the
reader to "check which hook is failing in the table below".

**Diagnostics.** At least five checks returned, worst problem first, and every problem
carries a next step.

**Repair.** Retrying re-queues only the named hook and leaves other hooks' failures alone.
Cancelling respects its limit, leaves other hooks untouched, and **marks rows rather than
deleting them** — verified by counting the rows afterwards.

**Admin guards.** The four `admin_post` handlers are the only destructive entry points on
the site. A subscriber holding a nonce that is genuinely valid *for them* is refused on
capability alone; a logged-out visitor is refused; bad and missing nonces are refused; and
a source check fails if any handler ever stops calling the shared guard.

**The alert email.** Captured through `pre_wp_mail`. Silent under the threshold, exactly
one message over it, throttled for a day and released after, a custom recipient beating
the admin email, silence when switched off, and no `%1$s` surviving into the body.

**Uninstall.** Every option removed and the cron event cleared, on every site of a network.
A bystander option seeded beforehand must survive, proving the cleanup is targeted rather
than a prefix sweep.

**Portability.** `UPDATE ... LIMIT` is a MySQL extension that SQLite does not support — the
SQLite translation layer hid that during development, so it would have worked in testing
and failed on every real store. Every write now selects ids first and updates by primary
key, and a test reads the source and fails if any `UPDATE` regains a `LIMIT`. A second
source check fails if any table name hardcodes the `wp_` prefix.

**Scale.** `PDA_Scanner::is_huge()` counts up to 250,000 and stops rather than running
`COUNT(*)` over the whole table. One of the support threads that prompted this plugin was
titled *"10+ million action scheduler tasks"*.

## Reproducing the MySQL run

SQLite is only a harness — WordPress really runs on MySQL/MariaDB. To run against the real
thing: start MariaDB on a spare port, swap in a wp-config pointing at it, park
`wp-content/db.php` (the SQLite drop-in), run `wp core update-db`, run the suite, then
restore both files.

## Notes for anyone extending this

Two things worth knowing, both learned the hard way:

- **A green suite proves nothing if an assertion is a tautology.** One check here compared
  `get_option('admin_email')` to itself. It passed, and it tested nothing.
- **Confirm the environment actually changed before believing a compatibility result.**
  `wp core update --version=6.6` reported no error while leaving the site on 7.1, and the
  suite briefly "passed on 6.6" without ever running there.
