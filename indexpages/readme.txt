=== Index Pages URL Indexing ===
Contributors: indexpages
Tags: indexing, seo, search engines, url submission
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send the URLs of your published posts and pages to Index Pages, a paid URL indexing service, and follow their status from WordPress.

== Description ==

[Index Pages](https://index-pages.com/) is a paid service that speeds up the indexing of URLs in search engines. You create an order with a list of URLs, run it – which charges your prepaid balance – and follow the status of every URL.

This plugin connects your site to your Index Pages account with an API key. It collects the URLs of posts you publish and sends them to the service, so you do not have to copy them into the dashboard by hand.

You decide how much it may do on its own:

* **Manual** (default) – nothing is sent automatically and nothing is spent. Use "Send to Index Pages" in the posts list; that creates a free draft order which you run yourself.
* **Draft orders** – newly published URLs are collected into draft orders. Drafts cost nothing; you run them in the Index Pages dashboard or under Tools → Index Pages, and only then is your balance charged.
* **Automatic** – orders are created and run automatically and every URL is charged to your balance, up to a daily spending cap that you set and the plugin enforces.

Features:

* Queues the URL when a post is published, and optionally when it is updated, with a minimum interval per URL (7 days by default) so an edit does not pay twice.
* Sends the queue on a schedule (every 15 minutes by default) as one order per run, within your account's limits, or right away with "Send now".
* Retries safely: every request that could create or pay for an order carries an idempotency key, so a retried run can never create or charge twice.
* Skips what should not be indexed: password-protected posts, non-public post types and statuses, posts marked noindex in Yoast SEO or Rank Math, and the whole site while "Discourage search engines from indexing this site" is on.
* Posts list: a "Send to Index Pages" bulk action and row action, and a column with the last submission date and status.
* Tools → Index Pages: the queue, your recent orders with their status and counters, per-URL statuses, running a draft after a confirmation that shows the cost against your balance, and deleting drafts.
* Dashboard widget with your balance, the queue length and the last order.
* Clear notices when something fails – a rejected key, a low balance, a rejected URL, a rate limit or a network problem – with what to do about it.
* Works per site on multisite.

The plugin needs an Index Pages account and an API key. Creating an account and draft orders is free; running an order costs the price per URL shown in your dashboard.

== External services ==

This plugin connects to the Index Pages API at `api.index-pages.com`, operated by Index Pages, to create, run and read your indexing orders and to show your balance. That is its whole purpose; without the API it does nothing.

It sends nothing until you enter an API key. After that:

* **What is sent:** your API key (in the `Authorization` header of every request) and the public URLs of the posts and pages being submitted, together with an order title made of your site's host name and the date. No other data about your site, your visitors or your users is sent, and the plugin does not track anything.
* **When:** when you verify the key, open the plugin's Tools page or dashboard widget (to read your balance and orders), click "Send now", "Run" or "Delete draft", use the "Send to Index Pages" actions, and – in the Draft orders and Automatic modes – on the scheduled run when URLs are waiting in the queue.

Index Pages [terms of service](https://index-pages.com/terms/) and [privacy policy](https://index-pages.com/privacy/).

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the `indexpages` folder to `/wp-content/plugins/`, and activate it.
2. In your Index Pages dashboard, open Settings → API keys and create a key. A key without the "orders:run" permission can never spend money – a good choice for the Draft orders mode.
3. In WordPress, open Settings → Index Pages, paste the key, save, and click "Verify key and show balance".
4. Choose the post types and the mode. The plugin starts in Manual mode, which sends nothing on its own.

You can also set the key in `wp-config.php` instead of the database:

`define( 'INDEXPAGES_API_KEY', 'ipk_live_…' );`

The key field is then hidden on the settings page.

== Frequently Asked Questions ==

= Does the plugin spend my money without asking? =

Only in Automatic mode, which you have to choose yourself, and never more URLs per day than the daily spending cap you set. In Manual and Draft orders modes the plugin only creates draft orders, which are free; you decide when to run them.

= Can an edit to a post be charged twice? =

Not within the minimum interval per URL, 7 days by default. A URL that went into an order is not queued again until the interval has passed, also when you use the posts list actions. Sending updates of published posts is off by default.

= What happens if the site loses the connection in the middle of sending? =

The batch is kept and retried on the next run with the same idempotency key, so Index Pages answers with the order it already created instead of creating or charging a second one.

= Why was a URL rejected? =

Index Pages accepts public http and https addresses only. Typical causes are a site address on `localhost` or a private network, or a URL that is too long. The reason is shown in a notice and in the posts list; the other URLs of the batch are sent anyway.

= Which posts are skipped? =

Password-protected posts, private and other non-published statuses, post types you did not enable or that are not public, posts marked noindex in Yoast SEO or Rank Math (the plugins do not need to be active for their settings to count), and everything while Settings → Reading → "Discourage search engines" is on.

= Does saving a post become slower? =

No. Saving a post only adds it to the queue in the database. The requests to Index Pages happen in WP-Cron or when you click a button.

= My queue is not sent. =

WP-Cron runs when your site gets visits. On a site with little traffic, or with `DISABLE_WP_CRON` set, make sure a real cron job calls `wp-cron.php`, or use "Send now" on Tools → Index Pages.

= Does it work on multisite? =

Yes, per site: every site has its own key, settings and queue. Activate the plugin on the sites that should use it.

= Where do I get help? =

Write to support@index-pages.com.

== Screenshots ==

1. Settings: API key, account balance and the three modes with what each one costs.
2. Tools → Index Pages: queue, recent orders and activity.
3. Posts list with the Index Pages column.

== Changelog ==

= 0.1.1 =
* The settings page shows the balance right away, without pressing Verify first.
* Amounts in the activity log are written the same way as everywhere else.
* Clearer wording after "Send to Index Pages" and tidier wrapping of long URLs.

= 0.1.0 =
* First release: API key settings with verification, Manual / Draft orders / Automatic modes, daily spending cap, update interval, queue sent by WP-Cron with idempotency keys, posts list column and actions, Tools page with orders and per-URL statuses, dashboard widget, noindex detection for Yoast SEO and Rank Math.

== Upgrade Notice ==

= 0.1.1 =
Small fixes to the settings page and the activity log.

= 0.1.0 =
First release.
