=== Past-Due Actions — Action Scheduler Monitor ===
Contributors: hamzanaimat
Tags: action scheduler, past due actions, scheduled actions, woocommerce cron, wp-cron
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Seeing "past-due actions found; something may be wrong"? This tells you which plugin caused it, why the queue stopped, and how to fix it.

== Description ==

WooCommerce shows you this and then leaves you alone with it:

> **Action Scheduler: 848 past-due actions found; something may be wrong.**

It does not say what is wrong, which plugin is responsible, or what to do. This plugin answers all three.

= What it tells you =

* **Which hooks are backed up**, grouped and counted — so instead of 848 unreadable rows you see "812 of these are subscription renewals".
* **Which plugin they probably come from**, worked out from the hook name.
* **How late the oldest one is**, in plain words.
* **Whether they are waiting or failing** — a completely different problem with a completely different fix.

= Why the queue stopped =

The diagnostics check the causes that actually happen, and each one says what it means and what to do next:

* **WP-Cron switched off** in wp-config.php with no server cron job put in its place. This is the most common cause by a distance.
* **The site cannot call itself** — WP-Cron works by making a request to your own site, and if outbound requests are blocked that request silently never lands.
* **The queue has not run in days**, whatever the settings claim.
* **Jobs failing rather than waiting**, which points at a broken plugin instead of broken scheduling.
* **A backlog too large to clear** at the normal batch size.
* **PHP execution limits** cutting batches off part-way, so the same actions are retried forever.

= Fixing it =

* **Run the queue now** — processes a batch immediately. This is also the fastest test there is: if actions clear, your jobs are fine and nothing is triggering the queue automatically. If nothing clears, the jobs themselves are failing.
* **Retry failed actions** for one hook, keeping their arguments and history.
* **Cancel a backlog** for one hook, with a clear warning first about what will not happen.
* **Daily email** if the backlog grows past a number you choose. Stuck actions fail silently — without a warning, nobody finds out until a customer complains.

= How this differs from the cleaner plugins =

The existing tools in this area delete **completed** actions to shrink your database. That is useful housekeeping and it does nothing at all for actions that are stuck. If you are here because of the past-due warning, a cleaner will not help you.

This plugin does not delete anything on its own. Cancelling is marked, not deleted, so it stays visible afterwards and a mistake can be seen.

= Works with =

Anything that uses Action Scheduler — WooCommerce, WooCommerce Subscriptions, WPForms, Jetpack, MailPoet, Easy Digital Downloads and many more.

== Installation ==

1. Install and activate. Action Scheduler must be present, which it is if you run WooCommerce or any plugin that uses it.
2. Go to **Tools → Past-Due Actions**.
3. Read the page top to bottom: how bad it is, why it is happening, what to press.

== Frequently Asked Questions ==

= Will this delete my scheduled actions? =

Not on its own. Nothing is removed unless you press "Cancel backlog" for a specific hook, and that marks them cancelled rather than deleting the rows, so the history stays visible.

= What does "past due" actually mean? =

An action whose scheduled time has passed but which has not run. It is not a status Action Scheduler stores, which is why you cannot find these by filtering the list — and why the warning can appear while every row still says "pending".

= I clicked "Run the queue now" and it cleared everything. What was wrong? =

Your jobs are fine and nothing is triggering the queue automatically. Look at the WP-Cron check on the same page — usually WP-Cron is disabled and no server cron job replaced it.

= I clicked it and nothing cleared. Now what? =

The actions are failing rather than waiting. Check which hook has failures in the table, then look at that plugin's support forum or error log.

= Do I need WooCommerce? =

No. Action Scheduler ships inside several plugins, and this works with any of them.

== Screenshots ==

1. How bad it is, why it is happening, and what to press
2. The backlog grouped by hook, with the plugin each one probably belongs to
3. Daily warning settings

== Changelog ==

= 1.0.0 =
* First release.
