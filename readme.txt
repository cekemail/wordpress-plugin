=== CekEmail Email Validation ===
Contributors: cekemail
Tags: email validation, email verification, disposable email, woocommerce, contact form 7
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verify email addresses on your WordPress forms with the CekEmail API and stop invalid, undeliverable and disposable addresses at the door.

== Description ==

CekEmail Email Validation checks every email address submitted to your site against the CekEmail API before WordPress stores it. Each address is checked for a valid format, a reachable mail server, a real mailbox, and whether it belongs to a disposable provider.

You decide what happens for every outcome:

* Invalid addresses are always blocked.
* Disposable addresses are blocked, or allowed, as you prefer.
* Catch-all domains, unverifiable mailboxes and API errors each get their own allow or block policy, so a hiccup at the API never has to lock visitors out.

Supported forms:

* WordPress user registration.
* WordPress comments.
* WooCommerce checkout, both the classic shortcode checkout and the block checkout.
* WooCommerce account registration.
* Contact Form 7 email fields, required and optional.

Features:

* An optional front-end widget, bundled with the plugin and never loaded from a CDN, that checks every email field on the page while the visitor types and shows the result before the form is submitted. It uses a public widget key, never your API key. The bundled widget is open source: https://github.com/cekemail/widget
* Typo suggestions ("did you mean ...") in the error message.
* Result caching, so the same address is not billed twice within the cache lifetime.
* A "Test connection" button that validates your API key without spending credits.
* A log of the 50 most recent checks, with addresses masked.
* Admin notices when your key is rejected or your credits run out.
* Filters and actions so developers can extend or override every decision.

= External services =

This plugin connects to the CekEmail API, an email verification service run by CekEmail at https://api.cekemail.com (or the self-hosted URL you configure on the settings screen). The service is needed to tell whether an address is valid, deliverable or disposable.

* Form submissions: when an API key is configured, the plugin sends the email address entered in each covered form (WordPress registration, WordPress comments, WooCommerce checkout and account registration, Contact Form 7 email fields), together with your API key, from your server to the CekEmail API at https://api.cekemail.com. Nothing is sent while no API key is configured.
* Front-end widget: when the optional front-end widget is switched on, the bundled widget script sends the address a visitor types into an email field from the visitor's browser to the same CekEmail API at https://api.cekemail.com, using your public widget key.

No other personal data leaves your site. Addresses are stored locally only in masked form, in the recent checks log, and results are cached under a hash of the address. The service is provided under the CekEmail [terms of service](https://cekemail.com/terms-of-service) and [privacy policy](https://cekemail.com/privacy-policy). Read both before enabling the plugin, and mention the service in your own privacy policy.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through the WordPress plugins screen.
2. Activate the plugin through the Plugins screen.
3. Create an API key in your CekEmail dashboard.
4. Go to Settings > CekEmail, paste the key, save, then press "Test connection".
5. Choose your policy and the forms you want checked.
6. To use the front-end widget, paste your public widget key under "Front-end widget" and switch it on.

== Frequently Asked Questions ==

= Do I need a CekEmail account? =

Yes. The plugin talks to the CekEmail API, which requires an API key from https://cekemail.com. Verification stops as soon as the key is removed.

= What happens when the API is unreachable? =

Nothing is blocked by default. The "API errors" policy on the settings screen decides whether submissions are allowed or blocked while the API cannot be reached, and an admin notice tells you when your key was rejected or your credits ran out.

= Why did an address get through with "Error: network" in Recent checks? =

The plugin waits a limited time for a reply from the CekEmail API, 15 seconds by default, and treats anything slower as an API error. Because the "API errors" policy allows submissions by default, the address is accepted and the check is logged with the status `Error: network`. Raise "Request timeout" under Settings > CekEmail, up to 30 seconds, if your server needs longer to reach the API, or set the "API errors" policy to block if you would rather turn a visitor away than accept an unverified address.

= Does the plugin store email addresses? =

Only in masked form. The recent checks log keeps entries such as `j***@example.com`, never the full address, and results are cached under a hash of the address.

= Are credits used for every submission? =

Results are cached for the cache lifetime you configure, so repeated submissions of the same address inside that window do not reach the API. The connection test uses no credits at all.

= Should I change the API URL? =

Only change this if you use a self-hosted CekEmail instance; self-hosted instances use the `/api/v1` prefix automatically. An address on an `api.` host, like the default https://api.cekemail.com, is called at `/v1`. Any other address, such as `https://mail.example.com` or the old default `https://cekemail.com`, is called at `/api/v1`. The address must use https; plain http is only accepted for `localhost` and `127.0.0.1`, so your API key is never sent unencrypted.

= Can I change the messages or the decisions? =

Yes. Use the `cekemail_decision` filter to override any allow or block decision, and the `cekemail_after_check` action to log or report checks yourself.

== Screenshots ==

1. The settings screen, with the API key, policy and integration options.
2. The recent checks log.

== Changelog ==

= 1.0.2 =
* WordPress.org submission fixes.
* Tested up to WordPress 7.1.
* Contact Form 7 input sanitisation.
* The API URL must use https, so the API key is never sent unencrypted; plain http is only accepted for localhost and 127.0.0.1.
* API responses with unexpected field types are ignored.
* Local parts of one or two characters are masked completely in the recent checks log.
* Uninstall removes the plugin's options and notice transients on every site of a network, without flushing the object cache.

= 1.0.1 =
* The API URL now defaults to https://api.cekemail.com, which serves the API at `/v1`.
* Self-hosted instances, and sites that saved the old https://cekemail.com default, keep using the `/api/v1` paths automatically. No settings need to change.

= 1.0.0 =
* Initial release.
* Email verification through the CekEmail API with result caching.
* WordPress registration and comment integrations.
* WooCommerce integration for the classic checkout, the block checkout and account registration.
* Contact Form 7 integration for email fields.
* Bundled front-end widget that checks email fields in the browser.
* Allow or block policies for disposable, catch-all, unverifiable and API error outcomes.
* Connection test, recent checks log and admin notices.
* Indonesian (id_ID) translation.

== Upgrade Notice ==

= 1.0.2 =
WordPress.org submission fixes and security hardening: the API URL must now use https (except on localhost), and uninstall cleans up every site of a network.

= 1.0.1 =
Uses the new https://api.cekemail.com API host by default. Existing and self-hosted API URLs keep working.

= 1.0.0 =
Initial release.
