# SMTP Email Log

Logs every email your site sends, sent or failed, so you can debug SMTP errors and recover form messages and leads that never arrived.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## How an email is recorded

Every email WordPress sends goes through `wp_mail()`, and the recorder (`includes/class-smtp-email-log-recorder.php`) follows it through WordPress's mail hooks:

| Hook | What's recorded |
|---|---|
| `wp_mail` filter (last) | A row is written with status `sending`: recipients, Cc, Bcc, Reply-To, subject, message, headers, attachment names and sizes, the plugin or theme that called `wp_mail()` (from the backtrace) and the request (page, AJAX action, REST route, cron hook). Written **before** sending, so a crash or timeout can't lose the email. |
| `pre_wp_mail` (last) | Another plugin took over delivery: recorded as sent (or failed if it returned false). |
| `phpmailer_init` (last) | The final From and recipients, the content type, and the transport: SMTP host, port, encryption and a shortened username, or PHP `mail()`/sendmail/an SMTP plugin's API mailer. For SMTP it turns on debug output (level 3) into the transcript, and sets the SMTP timeout if one is configured. Every PHPMailer property it changes is put back afterwards. |
| PHPMailer `action_function` | Whether the server accepted each recipient, and its queue ID (`server_reply`). |
| `wp_mail_succeeded` / `wp_mail_failed` (priority -1000) | Status `sent` or `failed`, the error, the transcript (kept for failed emails by default) and, for failures, copies of the attachments. |
| `wp_php_error_message`, `shutdown` | Anything still open is settled: `failed` with the PHP fatal error if PHP crashed, `sent` if PHPMailer saw the server accept it (WordPress before 5.9 has no success hook), otherwise `unknown`. |

Emails are kept on a stack, so an email sent while another is being sent (an alert from a `wp_mail_failed` handler, say) gets its own row. Every hook catches its own errors and database errors are never printed, so the log can't break sending or another plugin's JSON response. Rows still `sending` after 10 minutes become `unknown`.

Emails sent by plugins that replace `wp_mail()` are still logged when they run the usual hooks; the Test Email tab shows whether this site's mail reaches the log.

### Privacy and safety

- **Passwords:** the SMTP password is never stored. The transcript hides every `AUTH` payload (XOAUTH2 tokens included) and every client line after a `334` prompt, whatever PHPMailer did, and leaves out the message itself (`DATA`).
- **Login links:** with "Hide password reset and login links" on (the default), the value of any `key`, `token`, `otp`, `*_key` or `*_token` query parameter in the message is replaced with `[hidden]` before it's written. If the regex can't finish, the message is checked line by line and a line that still can't be checked isn't stored. On multisite only a super admin can switch this off, because a site admin could otherwise read the reset link of any network user.
- **HTML emails** are previewed in an iframe with `sandbox` (no `allow-scripts`, no `allow-same-origin`) and a Content-Security-Policy that blocks every outside request until "Load images" is clicked. Scripts, frames, forms, event handlers and `javascript:` links are stripped first as a second layer. Every regex that touches email content is linear, so a crafted message can't stall a page.
- **Attachments** of failed emails are copied to `wp-content/uploads/smtp-email-log/` as `{row}-{32 random hex}` with no extension, in a folder with a deny-all `.htaccess`, and served only to administrators through `admin-post.php` as `application/octet-stream` downloads. Up to the per-email limit (10 MB by default) and 256 MB in total.
- **Webhooks** must be `https://` on a public host (`wp_http_validate_url()` on save, `wp_safe_remote_post()` on send, no redirects). They're sent from WP-Cron, at most once per cooldown period, with failures in between added up.
- **CSV export** prefixes cells starting with `=`, `+`, `-`, `@`, tab or CR with a quote.
- Personal data export and erase cover the log by email address (erase also removes emails that only mention the address in the message).

### Data

- Table `{prefix}smtp_email_log` (schema in `class-smtp-email-log-install.php`, `DB_VERSION` 1). Times are UTC.
- Daily counts in `smtp_email_log_counts`, so the totals stay right when only failed emails are kept.
- `smtp_email_log_state` (autoloaded): unseen failures for the menu count and Dashboard notice, and the webhook queue.
- The daily cron (`smtp_email_log_daily`) marks stale rows unknown, deletes rows past retention (30 days sent, 90 days failed by default), caps the table (10,000 rows by default) and removes orphaned attachment copies.
- Size limits: message 512 KB, transcript 64 KB, addresses and headers 16 KB each.

## For developers

| Hook | Type | Use |
|---|---|---|
| `smtp_email_log_skip` | filter | Return true to leave an email out of the log. Gets the `wp_mail()` arguments. |
| `smtp_email_log_row` | filter | Change what's stored for an email before it's written, or return false to skip it. |
| `smtp_email_log_failed` | action | An email failed: log row ID and error message. |
| `smtp_email_log_webhook_payload` | filter | Change the JSON sent to the webhook. |

Define `SMTP_EMAIL_LOG_DISABLED` as true in `wp-config.php` to stop logging whatever the settings say.

## Automatic updates

Updates come from https://github.com/jmsprsns/smtp-email-log through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `smtp-email-log.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `smtp-email-log.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version, or just push the tag:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   # or
   git tag -a v1.0.1 -m "SMTP Email Log 1.0.1" && git push origin v1.0.1
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'SMTP_EMAIL_LOG_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'SMTP_EMAIL_LOG_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
smtp-email-log.php                          Plugin header, constants, boot, activation
uninstall.php                               Removes the table, attachment copies and options (unless "keep data" is on)
readme.txt                                  WordPress readme; feeds the "View details" popup
includes/
  class-smtp-email-log-plugin.php           Main singleton, settings, daily housekeeping
  class-smtp-email-log-install.php          Table schema (dbDelta)
  class-smtp-email-log-recorder.php         The mail hooks: captures every email and its result
  class-smtp-email-log-transcript.php       SMTP conversation, with credentials and message left out
  class-smtp-email-log-log.php              Storage, queries, counts, pruning, plain-text rendering
  class-smtp-email-log-attachments.php      Copies of failed emails' attachments
  class-smtp-email-log-errors.php           Plain-English explanations of sending errors
  class-smtp-email-log-alerts.php           Menu count, Dashboard notice, webhook alerts
  class-smtp-email-log-resend.php           Resend and forward
  class-smtp-email-log-actions.php          Bulk actions, resend, delete, export, downloads, test email (admin-post/AJAX)
  class-smtp-email-log-privacy.php          Privacy policy text, personal data export and erase
  class-smtp-email-log-admin.php            Content Powered > SMTP Email Log page and Plugins-screen links
  class-smtp-email-log-menu.php             Shared top-level "Content Powered" menu
  class-smtp-email-log-feedback.php         Shared Submit Feedback and Report a Bug pages
  class-smtp-email-log-updater.php          GitHub update checks (PUC wiring)
  class-smtp-email-log-icons.php            Inline Lucide icon helper
assets/
  admin.css, admin.js                       Loaded only on the plugin's page
  icon.svg                                  Icon on the update screens
  content-powered.svg                       Sumo logo for the Content Powered menu
icons/                                      The Lucide icons the plugin uses (plus LICENSE)
plugin-update-checker/                      Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-smtp-email-log-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-smtp-email-log-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons it uses in `icons/` (copy more from the Content Powered skeleton). Print one inline with:

```php
echo SMTP_Email_Log_Icons::get( 'mail-search', 20 );       // name, size in px
echo SMTP_Email_Log_Icons::get( 'circle-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
