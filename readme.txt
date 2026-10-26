=== SMTP Email Log ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: email log, smtp, mail log, wp_mail, email debugging
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Logs every email your site sends, sent or failed, so you can debug SMTP errors and recover form messages and leads that never arrived.

== Description ==

SMTP Email Log keeps a record of every email WordPress sends: contact form notifications, WooCommerce order emails, password resets, comment notifications and everything else. Each entry shows whether the mail server accepted it, and if it didn't, the exact error, what it usually means and how to fix it.

Every email is written to the log before it's sent. When the SMTP server is down, the login has expired or the page times out halfway through sending, the message is still in the log, with the form submission inside it. Read it, resend it once the problem is fixed, or forward it to yourself, so a lead isn't lost because the email about it never arrived.

**What it records**

* Recipients (To, Cc, Bcc), From, Reply-To, subject, message, headers and attachments
* Sent, failed or unknown, with the error and a plain-English explanation of it
* How it was sent: the SMTP server, port, encryption and account, or PHP mail()
* The mail server's reference for the accepted email
* The SMTP conversation with the server, with passwords and the message left out
* Which plugin, theme or WordPress feature sent it, from which page, form or scheduled task

**What you can do with it**

* Search and filter the log, and export it to CSV
* Read HTML emails in a safe preview where nothing in them can run
* Resend a failed email, forward it to another address, or resend dozens at once
* Download the attachments of failed emails (copies are kept so a file sent through a form isn't lost)
* Send a test email and see exactly what happened, step by step
* Get a webhook alert in Slack, Discord, Microsoft Teams, Google Chat or Zapier when emails fail, plus a Dashboard notice and a count in the menu

**Settings**

* Log every email, or only the ones that fail
* Keep the message for every email, only for failed ones, or not at all
* Hide password reset and login links before they're saved (on by default)
* Leave out emails by recipient or subject
* Separate retention periods for sent and failed emails, and a maximum log size
* A shorter SMTP timeout, so a mail server that doesn't answer fails the form quickly instead of hanging it for minutes

It works with WordPress's own mail function and with SMTP plugins such as WP Mail SMTP, FluentSMTP, Post SMTP and Easy WP SMTP. The log lives in your own database; nothing is sent anywhere unless you set up a webhook.

Find it under Content Powered > SMTP Email Log in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `smtp-email-log` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > SMTP Email Log. Every email from then on is logged.
4. Use the Test Email tab to check that your site can send email.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Does it send email, or replace my SMTP plugin? =

No. It records what your site sends, however it sends it. Keep using your SMTP plugin to deliver email; this shows you whether it's working.

= What happens to a form submission when the mail server is down? =

The email is saved before it's sent, so its full content stays in the log, marked as failed with the server's error. Once the problem is fixed, resend it to the original recipients or forward it to any address. Attachments of failed emails are kept too.

= Is it safe to keep emails in the log? =

Only administrators can see the log. HTML emails are shown in a sandbox where scripts can't run and outside images don't load until you ask. Password reset and login links are hidden before they're saved, and SMTP passwords are never stored. Old entries are deleted automatically, and you can keep only failed emails, or no message content at all.

= Will it slow down my site? =

No. Logging an email adds a couple of small database writes to sending it, and nothing at all to pages that don't send email. Alerts are sent in the background by WP-Cron.

= What does "Unknown" mean? =

WordPress handed the email over for sending but never reported back, usually because the page was cut off mid-send while waiting for a slow mail server. Setting an SMTP timeout on the Advanced tab makes those fail properly instead.

== Changelog ==

= 1.0.0 =
* First release.

See https://github.com/jmsprsns/smtp-email-log/releases for the full release history.
