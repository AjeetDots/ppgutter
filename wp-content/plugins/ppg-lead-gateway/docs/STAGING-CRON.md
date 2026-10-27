# Staging: set up cron and prove the plugin is polling

Staging: https://ppgutters.24livehost.com  -  WordPress admin: Tools > **PPG Lead Status**

> Staging writes to the **real** HubSpot. Use only your own test leads, and set `PPG_GO_LIVE` to a time just
> before the test (never earlier), or older real leads in the mailbox will be imported.

## 1. Before cron: install and configure

1. Upload the latest `ppg-lead-gateway` folder to `wp-content/plugins/` and activate it.
2. Enable the PHP **imap** extension (hosting panel > PHP extensions / PHP configuration).
3. In the staging `wp-config.php` (above "That's all, stop editing"):
   ```php
   define( 'WP_ENVIRONMENT_TYPE', 'staging' );
   define( 'PPG_HUBSPOT_TOKEN', '...' );
   define( 'PPG_LEAD_SECRET',   '<long random string, 24+ characters>' );
   define( 'PPG_IMAP_HOST',     '{imap.gmail.com:993/imap/ssl}[Gmail]/All Mail' );
   define( 'PPG_IMAP_USER',     'info@ppgutters.co.uk' );
   define( 'PPG_IMAP_PASS',     '<Google app password>' );
   // leave PPG_GO_LIVE unset until step 4
   ```
4. Open **Tools > PPG Lead Status**. Expected: imap, mailbox, HubSpot token and secret green; Go-live amber
   ("not set: importing is OFF").
5. Press **Test mailbox login** and **Test HubSpot token**. Both must say OK.

## 2. Set up cron (choose one)

WordPress' built-in cron only runs when someone visits the site, so use a real scheduled job that calls
`wp-cron.php` every 5 minutes.

**A. Hosting control panel "Cron Jobs" (best).** Schedule `*/5 * * * *`, command either
```
wget -q -O - "https://ppgutters.24livehost.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```
or `php /home/<account>/<path-to-site>/wp-cron.php`. Then add `define( 'DISABLE_WP_CRON', true );` to
wp-config.php so it does not also run on page loads.

**B. No cron access:** a free external pinger (for example cron-job.org) calling the same URL every 5 minutes.
Leave `DISABLE_WP_CRON` off.

**C. WP-CLI available:** `*/5 * * * * cd <site path> && wp cron event run --due-now >/dev/null 2>&1`.
To run the poll once by hand: `wp ppg poll`.

The staging site must answer `wp-cron.php` without a password prompt (it currently does).

## 3. Prove cron is firing (no leads needed yet)

1. Reload **Tools > PPG Lead Status** every few minutes. **Recent runs** should gain a new row about every
   5 minutes (outcome `no_go_live` is correct while `PPG_GO_LIVE` is unset: it proves cron runs and the
   mailbox login works, while importing nothing).
2. "Last poll" must stay green ("x minutes ago", under 15). Red = cron is not firing, see section 6.
3. Pressing **Run poll now** adds a row immediately. That proves the code runs, not that cron does, so do not
   count it as the cron test.

## 4. Prove it imports (one controlled test lead)

1. Set `PPG_GO_LIVE` to the current time, e.g. `define( 'PPG_GO_LIVE', '2026-10-21 10:00:00 Europe/London' );`
2. Send ONE test: a Yell enquiry or a Yelp request as the test customer (Checkatrade has no test button).
3. Within about 5 minutes + the platform's own email delay, a row shows **New leads 1 / Imported 1**.
4. In HubSpot: the contact exists, Lead Source = Yell/Yelp, Lead Status = New, a note with the details.
5. The next rows must show **Imported 0** for that email (nothing re-imported, no duplicate contact).

## 5. Checks you can do without the admin page

* Error log lines tagged `[ppg-lead-gateway]` (hosting panel > error log / `wp-content/debug.log`).
* `wp cron event list` should list `ppg_poll_mailbox` with a next-run time.
* Held leads: **Tools > PPG Lead Review**.

## 6. If a row is red

| Row | Meaning | Fix |
|---|---|---|
| PHP imap extension | extension off | enable it in the hosting PHP settings |
| Mailbox settings | a PPG_IMAP_* value missing | add it to wp-config.php |
| Test mailbox login fails | wrong app password / IMAP off | recreate the Google app password; check IMAP is on for info@ |
| HubSpot token 401 | token wrong/expired | rotate in HubSpot, update wp-config.php |
| Scheduled event missing | event not registered | load any page, or deactivate and reactivate the plugin |
| Scheduled event / Last poll "cron is not firing" | nothing calls wp-cron.php | fix the section 2 job, then reload after 5 minutes |
| Last poll amber `connect_failed` | cron fires but login fails | see "Test mailbox login" |

## 7. Before production goes live

Remove `PPG_GO_LIVE` (and ideally `PPG_IMAP_PASS`) from staging, so staging and production never both import
from the same mailbox. Then repeat sections 1 to 4 on production with the production URL.
