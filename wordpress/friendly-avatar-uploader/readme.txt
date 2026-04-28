=== Friendly Avatar Uploader ===
Contributors: jackofall1232
Tags: avatar, upload, profile, gravatar, user
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets logged-in users upload a custom avatar from the front end via the [friendly_avatar_upload] shortcode. Replaces the Gravatar throughout WordPress.

== Description ==

Friendly Avatar Uploader gives logged-in users a simple, front-end form to upload, replace, or remove their avatar without going to the WordPress dashboard. Uploaded images are validated, resized to a 300px square JPEG, and used everywhere `get_avatar()` or `get_avatar_url()` is called.

= Features =

* Front-end shortcode `[friendly_avatar_upload]` — drop it on any page or template.
* Live image preview before upload.
* AJAX upload and remove (no page reload).
* Strict server-side validation — type, size, and real image check via `getimagesize()` and `finfo`.
* Images are resized to 300×300 (cropped) JPEG via `wp_get_image_editor()`.
* Replaces Gravatars site-wide for users who have a custom avatar.
* No external CSS or JS files, no build step, no admin pages.

= Usage =

Place the shortcode on any page where logged-in users should be able to manage their avatar:

`[friendly_avatar_upload]`

Anonymous visitors see a friendly login prompt instead of the form.

== Installation ==

1. Upload the `friendly-avatar-uploader` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Add the `[friendly_avatar_upload]` shortcode to any page or post.

== Frequently Asked Questions ==

= What file types are allowed? =

JPEG, PNG, GIF, and WebP. Files are also re-checked with `finfo` and `getimagesize()` to confirm they are valid images.

= How big can a file be? =

Up to 2 MB. Larger files are rejected with a friendly error message.

= Where are uploaded avatars stored? =

In the current month's WordPress uploads directory, named `fau-avatar-{user_id}-{timestamp}.jpg`.

= Will this break Gravatar for users who haven't uploaded anything? =

No. The filter only swaps the URL when a user has a custom avatar set. Everyone else still gets their Gravatar.

= Does this add an admin page? =

No. The plugin is intentionally front-end-only.

== Changelog ==

= 1.0.0 =
* Initial release.
* `[friendly_avatar_upload]` shortcode with live preview, AJAX upload, and remove.
* `pre_get_avatar_data` integration so custom avatars appear everywhere.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
