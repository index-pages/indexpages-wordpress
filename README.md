# Index Pages for WordPress – developer notes

The WordPress plugin for [Index Pages](https://index-pages.com/). It sends the
URLs of published posts to the Index Pages API v1 with the site owner's API
key and shows orders and per-URL statuses in the admin. User documentation is
in [`indexpages/readme.txt`](indexpages/readme.txt).

No Composer, no npm, no build step: plain PHP 7.4+ for WordPress 6.4+.

## Layout

```
indexpages/                         the plugin as it ships
  indexpages.php                    header, constants, bootstrap
  uninstall.php                      removes options, transients, post meta, cron (every site on multisite)
  readme.txt                         WordPress.org readme
  includes/
    class-indexpages-settings.php    settings array, API key, API base URL
    class-indexpages-state.php       runtime state: notices, batch in flight, spending counter, log, lock, post meta
    class-indexpages-api.php         HTTP client over wp_remote_request(); errors as WP_Error with the problem `code`
    class-indexpages-errors.php      error code → message and what to do
    class-indexpages-eligibility.php published, public, not password-protected, not noindex (Yoast, Rank Math, blog_public)
    class-indexpages-queue.php       queue (post id → time, reason) and the per-URL minimum interval
    class-indexpages-collector.php   transition_post_status → queue (no HTTP in the save request)
    class-indexpages-sender.php      queue → one order per run; idempotency, daily cap, rejected URLs
    class-indexpages-plugin.php      hooks, WP-Cron schedule, activation, deactivation
    class-indexpages-admin.php       notices, flash messages, dashboard widget, helpers (admin only)
    class-indexpages-settings-page.php  Settings → Index Pages
    class-indexpages-tools-page.php  Tools → Index Pages: queue, orders, URL statuses, run, delete
    class-indexpages-posts-list.php  column, row action, bulk action
  assets/admin.css, assets/admin.js  small admin styles; confirm() before destructive submits
  languages/indexpages.pot          translation template
package.sh                           builds dist/indexpages-<version>.zip
```

## How sending works

1. On `transition_post_status` to `publish` (and `publish → publish` when
   "updated posts" is on) the post id goes into the `indexpages_queue` option,
   unless the mode is Manual, the post is not eligible, or its URL went into
   an order less than the minimum interval ago. Nothing else happens in the
   save request.
2. The WP-Cron event `indexpages_send_queue` (15 minutes by default, scheduled
   only when a key is set and the mode is not Manual) calls
   `IndexPages_Sender::flush( 'cron' )`. "Send now" and the posts list actions
   call `flush( 'manual' )`, which also works in Manual mode.
3. `flush()` takes a lock, reads `GET /account` for the limits and balance,
   re-checks every queued post, takes up to `max_urls_per_order` URLs (in
   Automatic mode also no more than the rest of today's cap and what the
   balance covers), and stores a **batch**: the URLs, the exact JSON body and
   two fresh `Idempotency-Key`s (create and run).
4. `POST /orders` with the create key. On a network error, 5xx, 429 or 409
   `idempotency_key_in_flight` the batch stays, and the next run sends the
   same bytes with the same key, so the service replays the first answer. Any
   other 4xx is final for that key: the batch is dropped and the URLs stay in
   the queue. A 422 that names `urls[i]` drops those URLs (recorded on the
   post, in the log and in a notice) and retries the rest with a new key.
5. In Automatic mode, `POST /orders/{id}/run` with the run key; same retry
   rules. A 409 `order_not_executable` is checked with `GET /orders/{id}`, so
   a run whose answer was lost is recognised as done. Runs count towards the
   daily cap (site time zone).

Runs started by hand on the Tools page go through a confirmation screen that
shows the cost against the balance; they are not counted against the daily
cap, which limits automatic spending only.

## Stored data

| Kind | Name | Notes |
|---|---|---|
| option | `indexpages_settings` | autoloaded (read on every post save) |
| option | `indexpages_api_key` | autoload off; never printed back, only prefix + last 4 |
| option | `indexpages_queue`, `indexpages_state`, `indexpages_log`, `indexpages_lock` | autoload off |
| transient | `indexpages_account`, `indexpages_orders`, `indexpages_flash_<user id>` | caches and one-time messages |
| post meta | `_indexpages_status`, `_indexpages_last_sent`, `_indexpages_last_url`, `_indexpages_order`, `_indexpages_message` | protected (leading underscore) |
| cron | `indexpages_send_queue` | |

`uninstall.php` removes all of it, on every site of a network.

## Constants

| Constant | Effect |
|---|---|
| `INDEXPAGES_API_KEY` | Uses this key instead of the stored one and hides the key field. Documented for users. |
| `INDEXPAGES_API_BASE` | Overrides `https://api.index-pages.com/v1`. For testing against a local copy of the API only; not documented in `readme.txt`. |

## Hooks

Filters:

| Filter | Arguments | Use |
|---|---|---|
| `indexpages_post_url` | `string $url, WP_Post $post` | URL sent for a post (default: the permalink). Return an empty string to skip. |
| `indexpages_skip_reason` | `string $reason, WP_Post $post` | Return a non-empty, human-readable reason to skip a post, or `''` to allow it. |
| `indexpages_order_title` | `string $title, string[] $urls` | Title of orders created by the plugin (default: host and date). |
| `indexpages_max_urls_per_order` | `int $max` | Smaller batches; the account maximum always wins. |
| `indexpages_user_can_send` | `bool $can, WP_Post $post` | Who may use the posts list actions (default: can edit the post and publish its type). |
| `indexpages_http_timeout` | `int $seconds` | API request timeout, default 15. |

Actions:

| Action | Arguments | When |
|---|---|---|
| `indexpages_order_created` | `array $order, array $items` | A draft order was created (`$items`: post id → URL). |
| `indexpages_order_run` | `array $order` | An order was run and the balance charged. |
| `indexpages_url_rejected` | `string $url, string $code, string $reason, int $post_id` | The API rejected a URL. |

## Testing locally

You need Docker and a local copy of the API (or a test account). Point the
plugin at it with `INDEXPAGES_API_BASE` and use example.com-style site URLs:
the API rejects `localhost`, `*.local` and private IP addresses as URLs.

A throwaway stack with the official images, sharing the host network so
WordPress can reach an API bound to the host's loopback:

```sh
docker volume create --label test=indexpages-wp indexpages-wp-db
docker volume create --label test=indexpages-wp indexpages-wp-html

docker run -d --name indexpages-wp-db --label test=indexpages-wp --network host \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp \
  -v indexpages-wp-db:/var/lib/mysql mariadb:11 --port=13306 --bind-address=127.0.0.1
```

Make Apache listen on a free loopback port: a `ports.conf` with
`Listen 127.0.0.1:18480` and a `000-default.conf` with
`<VirtualHost 127.0.0.1:18480>` and `DocumentRoot /var/www/html`, mounted over
the image's files. Put the environment in a file (`env`):

```
WORDPRESS_DB_HOST=127.0.0.1:13306
WORDPRESS_DB_USER=wp
WORDPRESS_DB_PASSWORD=wp
WORDPRESS_DB_NAME=wp
WORDPRESS_DEBUG=1
WORDPRESS_CONFIG_EXTRA=define('WP_DEBUG_LOG',true);define('WP_DEBUG_DISPLAY',false);define('DISABLE_WP_CRON',true);define('INDEXPAGES_API_BASE','http://127.0.0.1:8080/v1');define('WP_HOME','http://www.example.com');define('WP_SITEURL','http://127.0.0.1:18480');
```

```sh
docker run -d --name indexpages-wp-web --label test=indexpages-wp --network host --env-file env \
  -v indexpages-wp-html:/var/www/html \
  -v "$PWD/ports.conf:/etc/apache2/ports.conf:ro" \
  -v "$PWD/000-default.conf:/etc/apache2/sites-enabled/000-default.conf:ro" \
  wordpress:latest
```

WP-CLI uses the same volume and environment (the CLI image is Alpine, so run
it as the Debian image's `www-data`, uid 33):

```sh
wp() { docker run --rm -i --label test=indexpages-wp --network host --user 33:33 -e HOME=/tmp \
  --env-file env -v indexpages-wp-html:/var/www/html -v "$PWD:/src:ro" wordpress:cli wp "$@"; }

wp core install --url=http://www.example.com --title=Test --admin_user=admin \
  --admin_password=change-me --admin_email=admin@example.com --skip-email
docker run --rm --user 33:33 -v indexpages-wp-html:/var/www/html -v "$PWD:/src:ro" wordpress:cli \
  cp -r /src/indexpages /var/www/html/wp-content/plugins/
wp plugin activate indexpages
```

`WP_HOME` and `WP_SITEURL` differ in this setup, so permalinks use
`www.example.com` while the admin answers on `127.0.0.1:18480`. WordPress
only redirects to the home host by default; add the admin host with a test
mu-plugin (`allowed_redirect_hosts`) if you click through the admin.

Things worth checking:

```sh
wp post create --post_title="Hello" --post_status=publish
wp option get indexpages_queue --format=json
wp cron event run indexpages_send_queue
wp eval 'print_r( IndexPages_State::get_log() );'
wp eval 'print_r( IndexPages_Sender::flush( "manual" ) );'
```

With `DISABLE_WP_CRON` on, the queue is sent only when you run the event.
Failures can be simulated with the core `pre_http_request` filter (fake
responses) and `http_response` (turn a real answer into a `WP_Error` to test
the idempotent retry). Check `wp-content/debug.log` stays empty, run
`php -l` on every file with a PHP 7.4 image, and run PHP_CodeSniffer with the
WordPress Coding Standards or the Plugin Check plugin if you have them.

Clean up everything the stack created:

```sh
docker rm -f indexpages-wp-web indexpages-wp-db
docker volume rm indexpages-wp-db indexpages-wp-html
docker ps -a --filter label=test=indexpages-wp
```

## Building the zip

```sh
./package.sh
```

It checks that the plugin header version, `INDEXPAGES_VERSION` and the
readme's `Stable tag` agree, and writes `dist/indexpages-<version>.zip` with
the `indexpages/` folder: the main file, `uninstall.php`, `readme.txt`,
`includes/`, `assets/` and `languages/`. Developer files stay out.

To refresh the translation template after changing strings:

```sh
wp i18n make-pot /var/www/html/wp-content/plugins/indexpages /tmp/indexpages.pot --slug=indexpages --domain=indexpages
```

## Releasing

Bump the version in three places – the plugin header, `INDEXPAGES_VERSION`
and `Stable tag` in `readme.txt` – and add a changelog entry.
