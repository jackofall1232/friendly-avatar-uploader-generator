=== Zillha Avatar ===
Contributors: zillhagames
Tags: avatar, gravatar, ai, profile picture, upload, webhook, n8n
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Three front-end shortcodes for setting a user's WordPress avatar: a manual uploader, an AI generator that calls an n8n webhook, and a full profile experience with a Jcrop crop modal.

== Description ==

Zillha Avatar gives logged-in users three ways to set their profile picture, sharing one save flow and one Gravatar replacement filter.

* `[zillha_avatar_uploader]` — drop on any page to give users a manual upload form. Files are validated, resized and center-cropped to a 400×400 WebP, and stored in the media library.
* `[zillha_avatar_generator]` — drop on any page to give users an avatar questionnaire. The plugin POSTs the answers to your n8n webhook and previews the returned WebP. The user can download the result or set it as their profile picture.
* `[zillha_avatar_profile]` — drop on a member-only page to render a cinematic profile hero (display name, handle, member-since pill, post/comment/days stats) plus the full avatar toolkit. Choosing a new image opens a Jcrop modal so the user can pick the region they want to keep before upload; the final avatar is saved as a 400×400 square WebP. If a webhook is configured, the AI generator is embedded inline.

Whichever flow runs last wins: the previous attachment is removed from the media library, and the new one replaces it. The avatar is shown anywhere WordPress calls `get_avatar()` or `get_avatar_url()`, including comments, BuddyPress, theme widgets, the admin bar, and the user profile screen.

**Highlights**

* Three shortcodes, one merged plugin, one shared save flow.
* Single user-meta key (`zillha_avatar_attachment_id`) and a single `get_avatar_url` filter at priority 1 — no duplicate hooks.
* Vanilla front-end JS (no jQuery), one stylesheet, dark theme matching the ZillHa Games aesthetic.
* Nonce-protected `wp_ajax_` endpoints, logged-in users only.
* All inputs sanitized, all outputs escaped, all strings translatable.
* `wp_remote_post()` for the webhook (never raw cURL), 120 second timeout, `text/plain` request and `image/webp` response.
* `WP_Image_Editor` for all image manipulation. `media_handle_sideload()` for all media-library writes.
* Pending generated WebPs live at `wp-content/uploads/zillha-pending/{user_id}-{token}.webp` where `token` is a 32-character `wp_generate_password()` value. The unguessable filename is the security model — no `.htaccess` is needed and no binary is stored in the database.
* Hourly WP-Cron sweep deletes pending files older than 10 minutes.

**You will need (for the AI generator)**

An n8n (or compatible) webhook that accepts a `text/plain` POST body and responds with raw WebP image bytes (`Content-Type: image/webp`). The manual uploader works without one.

== Installation ==

1. Upload the `zillha-avatar` folder to `/wp-content/plugins/`, or install via the Plugins screen.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. (Optional, for the AI generator only.) Go to **Settings → Zillha Avatar** and paste your n8n webhook URL.
4. Add `[zillha_avatar_uploader]`, `[zillha_avatar_generator]`, and/or `[zillha_avatar_profile]` to any page or post.

== Frequently Asked Questions ==

= Does it work for logged-out visitors? =

No. Both shortcodes return an empty string for guests, and every AJAX endpoint refuses requests that aren't authenticated.

= Where is the saved avatar stored? =

In the WordPress media library. The attachment ID is stored in user meta under `zillha_avatar_attachment_id`. The avatar URL is substituted via `get_avatar_url`.

= How does the cropping work? =

Manual uploads use a standard center-crop resized to 400×400. AI-generated images come back as 2:3 portraits with the head near the top, so the plugin takes the central 60% of the width starting 5% from the top, then resizes that square to 400×400. The crop logic and reasoning are documented in `CLAUDE.md`.

= Can I see what is sent to the webhook for debugging? =

Yes — set `WP_DEBUG` to `true` and the plugin will log the request URL, payload, response code, response headers, and body length via `error_log()`.

= What about a load-balanced WordPress install? =

Pending generated avatars are written to and read from `wp-content/uploads/`. Both AJAX requests must land on a node that sees the same `uploads/` tree (shared mount, S3/CDN-backed offload, or sticky sessions). This is the same assumption WordPress core's media library makes.

= Does this plugin make outbound requests? =

Yes, exactly one — a `wp_remote_post()` to the webhook URL you configure, when a user clicks Generate. No analytics, no telemetry, no other network calls.

== Screenshots ==

1. The manual upload form rendered by `[zillha_avatar_uploader]`.
2. The AI questionnaire rendered by `[zillha_avatar_generator]`.
3. The generated avatar preview with download / save / generate-another buttons.
4. The Settings → Zillha Avatar admin screen.

== Changelog ==

= 1.0.1 =
* New `[zillha_avatar_profile]` shortcode — full cinematic profile hero with display name, handle, member-since pill, post/comment/days stats, and the full avatar toolkit.
* New Jcrop crop modal in the profile flow: the user can drag a selection over the chosen image before it's uploaded; the cropped region is saved as a 400×400 square WebP. The cropped canvas blob is posted to the existing `zillha_avatar_upload` endpoint, so the server-side flow is unchanged.
* Jcrop styles and script are enqueued only on pages that render the profile shortcode.
* Cross-shortcode preview sync — placing more than one shortcode on the same page now keeps every preview in sync after upload, generate, or remove.
* Admin Settings page documents all three shortcodes, including the new crop step.

= 1.0.0 =
* Initial release of the merged plugin.
* `[zillha_avatar_uploader]` and `[zillha_avatar_generator]` shortcodes share a single save flow, single user-meta key, and single `get_avatar_url` filter.
* AI generation via n8n webhook with face-bias crop to 400×400 WebP.
* Manual upload with center-crop to 400×400 WebP.
* Hourly WP-Cron cleanup of pending generated WebPs older than 10 minutes.

== Upgrade Notice ==

= 1.0.1 =
Adds the `[zillha_avatar_profile]` shortcode and a Jcrop free-form crop modal before upload. No database or server changes — purely additive.

= 1.0.0 =
Initial release of the merged Zillha Avatar plugin (replaces the legacy Friendly Avatar Uploader and ZillHa Avatar Generator plugins).
