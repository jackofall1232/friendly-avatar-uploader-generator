# ZillHa Avatar Generator — architecture notes

This document is the canonical reference for anyone (human or AI) editing this
plugin. Read it before making changes; update it when behaviour changes.

## What the plugin does

A logged-in WordPress user fills out a short avatar questionnaire on a page
that contains the `[zillha_avatar_generator]` shortcode. The plugin POSTs the
answers as plain text to an n8n webhook, receives a WebP image back, previews
it in the browser, and lets the user either download it or set it as their
WordPress profile picture (replacing Gravatar everywhere on the site).

## File layout

```
zillha-avatar-generator/
├── zillha-avatar-generator.php     # Plugin bootstrap, constants, singleton.
├── includes/
│   ├── class-zillha-avatar-settings.php   # Admin settings screen.
│   ├── class-zillha-avatar-ajax.php       # AJAX endpoints.
│   └── class-zillha-avatar-shortcode.php  # Shortcode + get_avatar_url filter.
├── assets/
│   ├── css/zillha-avatar.css       # Dark-theme front-end styles, zag- prefix.
│   └── js/zillha-avatar.js         # Vanilla JS, no jQuery, fetch-based.
├── CLAUDE.md
└── readme.txt
```

## Bootstrap

`zillha-avatar-generator.php` defines all constants (`ZILLHA_AVATAR_*`),
requires the three class files, and instantiates the singleton
`Zillha_Avatar_Generator`. The singleton constructs the three subsystem
objects, wires `plugins_loaded` (text domain) and `init` (where each subsystem
calls its own `register_hooks()`), and registers activation/deactivation
hooks. Activation seeds the option row and schedules the cleanup cron;
deactivation only clears the cron (settings and user meta survive).

Constants worth knowing:

| Constant                                | Purpose                                                                           |
| --------------------------------------- | --------------------------------------------------------------------------------- |
| `ZILLHA_AVATAR_OPTION_KEY`              | wp_options row name (array with `webhook_url`).                                   |
| `ZILLHA_AVATAR_USER_META_KEY`           | User meta key holding the saved attachment ID.                                    |
| `ZILLHA_AVATAR_PENDING_META_KEY`        | User meta key holding the per-generation pending-file token (32-char alphanum).   |
| `ZILLHA_AVATAR_PENDING_DIR_NAME`        | Subdir of `uploads/` where pending WebPs live (`zillha-pending`).                 |
| `ZILLHA_AVATAR_PENDING_TTL`             | 10 minutes — also the staleness threshold for cleanup.                            |
| `ZILLHA_AVATAR_PENDING_TOKEN_LENGTH`    | 32 characters; the token is generated via `wp_generate_password( …, false )`.     |
| `ZILLHA_AVATAR_WEBHOOK_TIMEOUT`         | 120 seconds for `wp_remote_post()`.                                               |

`Zillha_Avatar_Generator::get_webhook_url()` is the only sanctioned way to read
the configured URL. The pending-file helpers form a small triangle:

- `generate_pending_token()` mints a fresh 32-char alphanumeric token.
- `is_valid_pending_token( $token )` is the regex gate every read goes
  through before the token touches the filesystem.
- `pending_file_path( $user_id, $token )` builds
  `{uploads}/zillha-pending/{user_id}-{token}.webp` after validating both.
- `pending_file_path_for_user( $user_id )` reads the stored token from
  `user_meta[ ZILLHA_AVATAR_PENDING_META_KEY ]`, validates it, then returns
  the same path (or `''` if no valid token is on file).
- `ensure_pending_dir()` lazily `wp_mkdir_p()`s the directory.

A WP-Cron event (`Zillha_Avatar_Generator::CLEANUP_HOOK`,
`zillha_avatar_pending_cleanup`, scheduled hourly) calls
`Zillha_Avatar_Generator::cleanup_pending_files()` to delete any `*.webp` in
the pending dir whose mtime is older than `ZILLHA_AVATAR_PENDING_TTL`.
Activation schedules the event; deactivation clears it. The hook is also
re-armed defensively on every `init` if it has gone missing.

## Webhook contract

- Method: `POST`
- URL: stored in the option `zillha_avatar_generator_options['webhook_url']`,
  sanitized with `esc_url_raw()` on save.
- Headers:
  - `Content-Type: text/plain; charset=utf-8`
  - `Accept: image/webp`
- Body: a plain-text, newline-separated list. The exact lines are built in
  `Zillha_Avatar_Ajax::build_payload()`:

  ```
  Overall vibe / aesthetic: <value>
  Gender expression: <value>
  Hair style and color: <value>
  Outfit or clothing style: <value>
  Background / setting: <value>
  Mood or emotion: <value>
  Special features or accessories: <value or "(none)">
  Art style: <human-readable label>
  ```

- Expected response:
  - HTTP `2xx`
  - `Content-Type` header containing `image/webp`
  - Raw WebP binary in the body (no JSON wrapper, no base64)
- Timeout: 120 seconds.

If any of those expectations fail, the AJAX handler returns a specific,
translatable error message to the front-end (see `handle_generate()`).

## AJAX endpoints

Both endpoints are wired with `wp_ajax_` only — guests have no handler.
Both verify the nonce `zillha_avatar_generator_nonce` (the constant
`Zillha_Avatar_Ajax::NONCE_ACTION`) and re-check `is_user_logged_in()`.

### `zillha_generate_avatar`

1. `check_ajax_referer( NONCE_ACTION, 'nonce' )`.
2. Read and sanitize each field (`sanitize_text_field` for short fields,
   `sanitize_textarea_field` for `features`). Required fields are validated;
   `art_style` is whitelisted against `ART_STYLES`.
3. Build the plain-text payload.
4. `wp_remote_post()` with `data_format => 'body'` and a `text/plain` header.
   Never call cURL directly.
5. Validate response code is `2xx`, body non-empty, content type contains
   `image/webp`. Return specific errors otherwise.
6. Call `Zillha_Avatar_Generator::ensure_pending_dir()`. If a previous pending
   file exists for this user (via `pending_file_path_for_user()`), `unlink()`
   it — we are about to mint a new token and don't want stale files lying
   around. Generate a fresh token, write the raw binary atomically
   (`file_put_contents( $path, $body, LOCK_EX )`) to
   `wp-content/uploads/zillha-pending/{user_id}-{token}.webp`, `chmod 0600`
   the file, then `update_user_meta( $user_id,
   ZILLHA_AVATAR_PENDING_META_KEY, $token )`.
7. Return `{ data_uri: 'data:image/webp;base64,…', expires_in: <seconds> }`.

The raw binary is **never** echoed back. The base64 data URI is sent for
preview/download in the browser; the canonical bytes live on disk in the
pending file until the user explicitly saves them.

### `zillha_save_avatar`

1. Nonce + login check.
2. Resolve the per-user pending path via `pending_file_path_for_user()` —
   that helper reads the token from `user_meta[ ZILLHA_AVATAR_PENDING_META_KEY ]`
   and validates its shape before returning a path. If the path is empty,
   the file is missing, or its mtime is older than `ZILLHA_AVATAR_PENDING_TTL`,
   return HTTP 410 and the "session expired" string (deleting any stale file
   and clearing the meta key on the way out).
3. Require `wp-admin/includes/file.php`, `media.php`, `image.php` (these are
   not normally loaded on the front end — see "Gotchas").
4. **Auto-crop to a head-and-shoulders square, then resize to 400×400.** The
   webhook pipeline returns 2:3 portrait images with the subject centered
   horizontally and the head starting near the top of the frame. Taking the
   full-width square from the top-left cut the sides off; using `min()` of
   width and height left a sliver of background to either side and still
   missed the very top of the head once compositions varied. The current
   heuristic is calibrated to that framing:

   - `$crop_y = (int) ( $height * 0.05 )` — nudged 5% down from the top so
     the very top of the head/hat is included instead of being clipped.
   - `$crop_side = (int) ( $width * 0.60 )` — 60% of the source width is
     the target square edge, which lops 20% of background off each side
     without clipping the shoulders.
   - Guard: `$crop_side = min( $crop_side, $width, max( 0, $height -
     $crop_y ) )`. Clamping with a single side length (rather than
     shrinking only `$crop_h`) keeps the source crop genuinely square,
     which matters because an asymmetric crop fed into `crop( …, 400,
     400 )` would be stretched to 400×400 and look distorted. The
     `$width` term protects against any future heuristic tweak that
     might exceed the frame width; the `max( 0, $height - $crop_y )`
     term keeps us inside the bottom edge on a near-square or landscape
     input.
   - `$crop_w = $crop_h = $crop_side` and
     `$crop_x = (int) ( ( $width - $crop_side ) / 2 )` — square,
     centered horizontally.
   - Output is resized to 400×400 by passing `$dst_w = $dst_h = 400` to
     `WP_Image_Editor::crop()`. 400×400 is large enough for every avatar
     slot WordPress core and themes request while keeping the stored file
     small.

   Before sideloading the handler writes the bytes to a tempfile, loads
   them with `wp_get_image_editor()`, applies the crop, saves back as
   `image/webp`, and reads the cropped bytes back into `$binary`. If the
   editor errors out at any step (`wp_get_image_editor`, `crop`, or
   `save`), the original (uncropped) binary is used so the save still
   succeeds. The crop is also skipped entirely if the request carries
   `?zag_crop=manual` — an unadvertised back-door for edge-case images,
   not exposed in the UI.
5. Read the (possibly cropped) binary, write it to a tempfile, build a
   `$_FILES`-shaped array, call `media_handle_sideload()` (with the
   `upload_mimes` filter temporarily allowing `image/webp` — see Gotchas).
6. Replace any previously saved attachment (`get_user_meta` + delete the old
   file via `wp_delete_attachment( $previous, true )`).
7. Update `user_meta[ ZILLHA_AVATAR_USER_META_KEY ]` to the new attachment
   ID, `unlink()` the pending file, and
   `delete_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY )`.

## Shortcode and front-end flow

`[zillha_avatar_generator]` returns an empty string for guests. For logged-in
users it enqueues the CSS/JS (registered lazily — see "Asset registration"),
calls `wp_localize_script()` to inject `ZillhaAvatarConfig` (ajaxUrl, nonce,
i18n strings), and prints the form template.

`assets/js/zillha-avatar.js` (no jQuery, no dependencies):

1. Validates required fields client-side, then `fetch()`-POSTs a `FormData`
   to `admin-ajax.php` with `action=zillha_generate_avatar` and the nonce.
2. On success: hides the form, shows the preview `<img>` with the data URI
   set as `src`, points the download `<a>`'s `href` to the same data URI.
3. The "Set as profile picture" button triggers a separate fetch to
   `zillha_save_avatar`. It is **never** called automatically — the user
   must click it.
4. The "Generate another" button clears state and shows the form again.
5. Buttons cycle through `default → loading → success/error` classes; the
   spinner element is purely CSS (`@keyframes zag-spin`). All errors render
   inline in `.zag-message`. There are no `alert()` calls.

### Asset registration

Both the script and stylesheet are *registered* (not enqueued) inside
`Zillha_Avatar_Shortcode::register_assets()`. They are only enqueued from
inside `render()`, which runs only when the shortcode actually appears on
the page. This keeps every other page free of plugin assets.

## Avatar filter

`add_filter( 'get_avatar_url', …, 10, 3 )` lets us replace Gravatar URLs
site-wide whenever a user has `ZILLHA_AVATAR_USER_META_KEY` set.

`Zillha_Avatar_Shortcode::resolve_user_id()` accepts every shape WordPress
ever passes to this filter:

- numeric (user ID),
- string (treated as email — `get_user_by('email', …)`),
- `WP_User`,
- `WP_Post` (uses `post_author`),
- `WP_Comment` (uses `user_id`, falling back to `comment_author_email`),
- generic objects with `user_id` / `ID` / `comment_author_email`.

If a user has no saved attachment we return `$url` unchanged so Gravatar (or
whatever default the theme is using) keeps working. We also re-validate that
the stored attachment still exists (`get_post_type( $id ) === 'attachment'`)
before substituting, so a deleted attachment falls back gracefully.

For sizing, we ask WordPress for the closest registered size to `$args['size']`
(clamped between 1 and 2048) and fall back to the full attachment URL if no
sized variant exists.

## Settings page

Under **Settings → ZillHa Avatars**. One option array
(`zillha_avatar_generator_options`) with one key (`webhook_url`). Sanitized
via `esc_url_raw()` on save. The settings page also shows shortcode usage
instructions and a quick description of the flow.

If no webhook URL is configured, an admin notice is shown on the **Plugins**
list and on the plugin's settings screen itself (only to users who can
`manage_options`).

A "Settings" action link is added to the plugin row on the Plugins list.

## Debugging and logging

When `WP_DEBUG` is true, `handle_generate()` writes via `error_log()`:

- the webhook URL it is about to call,
- the exact plain-text payload,
- the response code,
- a JSON dump of all response headers,
- the response body length.

Production runs (no `WP_DEBUG`) log nothing.

User-facing error strings cover, at minimum:
"webhook not configured", "network error with HTTP code", "empty response",
"unexpected content type", and "session expired" — each is wrapped in
`__()` with the `zillha-avatar-generator` text domain.

## Security checklist

- Nonce (`zillha_avatar_generator_nonce`) verified on every AJAX call.
- `wp_ajax_` only — guests cannot reach the handlers.
- `is_user_logged_in()` re-checked inside each handler.
- All `$_POST` data passes through `sanitize_text_field` /
  `sanitize_textarea_field`; the art style is whitelisted.
- All output uses `esc_html_e` / `esc_attr_e` / `esc_url`.
- Webhook URL sanitized with `esc_url_raw()` on save.
- `media_handle_sideload()` runs under a temporary `upload_mimes` filter that
  whitelists exactly `webp => image/webp` for that one call.
- Pending avatar files are stored at
  `uploads/zillha-pending/{user_id}-{token}.webp` where `token` is a fresh
  32-char alphanumeric value from `wp_generate_password( 32, false )`. The
  filename is the security boundary — guessing it costs ~62^32 attempts —
  so the path being inside the public `uploads/` tree is acceptable. Files
  are also written `chmod 0600` and swept hourly by
  `zillha_avatar_pending_cleanup`.
- The token is the only thing held in user meta
  (`ZILLHA_AVATAR_PENDING_META_KEY`) — never the binary itself. A regex check
  (`^[A-Za-z0-9]{32}$`) gates every read so a tampered/foreign meta value
  cannot escape the pending dir via path traversal.
- Settings screen requires `manage_options`.
- The webhook call goes through `wp_remote_post()`, never `curl_*` directly,
  so the standard WordPress HTTP API filters apply.

## Known gotchas

1. **`media_handle_sideload()` requires admin includes.** It lives in
   `wp-admin/includes/`, which is not loaded on AJAX requests by default.
   The save handler does:

   ```php
   require_once ABSPATH . 'wp-admin/includes/file.php';
   require_once ABSPATH . 'wp-admin/includes/media.php';
   require_once ABSPATH . 'wp-admin/includes/image.php';
   ```

   Do not remove those.

2. **`Content-Type: text/plain` must be set explicitly.** `wp_remote_post()`
   defaults to `application/x-www-form-urlencoded` when given a string body.
   If you forget the explicit header, n8n receives the body as form-encoded
   junk. Always pass it via the `headers` array and pair it with
   `data_format => 'body'`.

3. **WebP upload requires the `upload_mimes` filter, not the 4th sideload arg.**
   `media_handle_sideload()` ignores any `mimes` map you pass in its
   `$overrides` argument — it builds its own from `wp_check_filetype_and_ext()`,
   which in turn calls `get_allowed_mime_types()` and obeys `upload_mimes`.
   The save handler therefore wraps the sideload call in
   `add_filter( 'upload_mimes', … )` / `remove_filter( … )` to inject
   `'webp' => 'image/webp'` for that one call only. Don't "simplify" by
   moving back to the `$overrides` argument — it silently does nothing.

4. **Pending binary lives on disk; only the access token lives in user meta.**
   Generate mints a 32-char alphanumeric token via
   `wp_generate_password( 32, false )`, writes the raw WebP bytes to
   `wp-content/uploads/zillha-pending/{user_id}-{token}.webp`, and stores the
   token (and only the token — never the binary) in
   `user_meta[ ZILLHA_AVATAR_PENDING_META_KEY ]`. Save reads the token back,
   passes it through the regex validator, reconstructs the path, validates
   `filemtime()` is within `ZILLHA_AVATAR_PENDING_TTL`, reads the file,
   `unlink()`s it, and deletes the meta key. We do **not** put the bytes in
   a transient (object cache flushes / non-persistent caches make them
   disappear between requests — the original bug) or in user meta (binary
   blobs in `wp_usermeta` get loaded into memory on every `WP_User` fetch
   and waste ~33% to base64). The base64 data URI we send to the browser is
   preview-only; the save endpoint never trusts client-supplied binary.

   The token is what makes the public-uploads path safe: an attacker who
   knows the user ID still cannot guess `{user_id}-{token}.webp`, so the
   directory does **not** need `.htaccess`/`index.php` deny rules and
   doesn't depend on any particular web server's behaviour.

   A WP-Cron job (`zillha_avatar_pending_cleanup`, hourly) sweeps any
   pending file older than the TTL — protects against users who generate
   then close the tab without saving. The sweep streams entries via
   `DirectoryIterator` rather than `glob()` so it stays memory-flat on
   busy installs.

5. **Avatar filter shapes.** `get_avatar_url` receives many shapes
   (WP_User, WP_Post, WP_Comment, email string, integer, generic objects
   from third-party plugins). Always go through `resolve_user_id()`.

6. **Replace, don't accumulate.** Saving a new avatar deletes the previous
   attachment file via `wp_delete_attachment( $previous, true )`. Skipping
   that would slowly fill the media library with abandoned WebPs.

7. **Asset enqueue scope.** Assets enqueue only when `render()` runs. If
   you ever add a Gutenberg block wrapping the shortcode, make sure the
   enqueue still happens (or move the enqueue to a more appropriate hook).

8. **Plain-text payload encoding.** The webhook contract is plain text, not
   JSON. Do not "improve" this by JSON-encoding the payload; the n8n
   workflow on the other side parses raw text.

## Known constraints

- **Shared uploads directory required across all WP nodes.** Pending avatars
  are written via `file_put_contents()` into `wp-content/uploads/`, and the
  save handler reads them back from the same path. On a load-balanced
  WordPress install both AJAX requests must land on a node that sees the
  same `uploads/` tree (shared mount, S3/CDN-backed offload, sticky sessions,
  or single-node).

  This is consistent with WordPress core's own assumption: `media_handle_sideload()`
  writes the saved attachment into the same `uploads/` tree, so any install
  that does **not** share uploads has a broken media library regardless of
  this plugin. If you operate such a setup, fix the shared-storage layer —
  there is no per-node fallback here on purpose.

## Testing it locally

1. Activate the plugin.
2. Go to **Settings → ZillHa Avatars** and paste your n8n webhook URL.
3. Add `[zillha_avatar_generator]` to a page.
4. Visit the page logged in, fill out the form, hit Generate.
5. Confirm preview appears.
6. Click **Set as profile picture**, then visit any page that renders the
   user's avatar (e.g., a comment, user profile bar) and confirm the saved
   attachment is shown instead of Gravatar.
7. Toggle `WP_DEBUG` on to confirm webhook details land in `debug.log`.
