=== ZillHa Avatar Generator ===
Contributors: zillhagames
Tags: avatar, gravatar, ai, profile picture, webhook, n8n
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate AI avatars from a questionnaire by calling an n8n webhook, then download or set as your WordPress profile picture.

== Description ==

ZillHa Avatar Generator gives logged-in users a short questionnaire (vibe, gender expression, hair, outfit, background, mood, optional accessories, art style) and turns those answers into a unique WebP avatar by calling an n8n webhook you control.

Users can then either download the result or save it as their WordPress profile picture, replacing Gravatar site-wide.

**Highlights**

* One simple shortcode: `[zillha_avatar_generator]`.
* Fully vanilla front-end — no jQuery, no third-party libraries.
* Dark theme matching the ZillHa Games aesthetic, mobile responsive.
* Nonce-protected AJAX endpoints, logged-in users only.
* Replaces Gravatar via the standard `get_avatar_url` filter.
* Webhook URL sanitized with `esc_url_raw()` and stored in `wp_options`.
* All inputs sanitized, all outputs escaped, all strings translatable.
* Plain text webhook contract, 120-second timeout, response is raw `image/webp`.

**You will need**

An n8n (or compatible) webhook that accepts a `text/plain` POST body and responds with raw WebP image bytes (`Content-Type: image/webp`).

== Installation ==

1. Upload the `zillha-avatar-generator` folder to `/wp-content/plugins/`, or install via the Plugins screen.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Go to **Settings → ZillHa Avatars** and paste your n8n webhook URL.
4. Add the shortcode `[zillha_avatar_generator]` to any page or post visible to logged-in users.

== Frequently Asked Questions ==

= Does it work for logged-out visitors? =

No. The form, AJAX endpoints, and saved-avatar replacement are all gated to logged-in users. Guests rendering the shortcode receive an empty string.

= Where is the avatar stored? =

The freshly generated WebP is held in a 10-minute, per-user transient. It is only persisted to the WordPress media library when the user explicitly clicks **Set as profile picture**. The attachment ID is stored in user meta `zillha_avatar_attachment_id`.

= How does it replace Gravatar? =

When a user has `zillha_avatar_attachment_id` set, the plugin filters `get_avatar_url` and substitutes the saved attachment URL. Users without a saved avatar continue to see Gravatar (or whatever default the theme uses).

= Can I see what is sent to the webhook for debugging? =

Yes — set `WP_DEBUG` to `true` and the plugin will log the request URL, payload, response code, headers, and body length via `error_log()`.

= Does this plugin make outbound requests? =

Yes, exactly one: a `wp_remote_post()` to the webhook URL you configure. No analytics, no telemetry, no other network calls.

== Screenshots ==

1. The questionnaire form rendered by the shortcode.
2. The generated avatar preview with download / save / regenerate buttons.
3. The Settings → ZillHa Avatars admin page.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
