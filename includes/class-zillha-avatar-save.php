<?php
/**
 * Shared save flow: write a binary into the media library and link it
 * to a WordPress user as their avatar.
 *
 * @package Zillha_Avatar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Both shortcodes funnel through Zillha_Avatar_Save::save_to_profile().
 *
 * The caller is responsible for cropping/resizing the binary to the
 * intended final shape (400×400 WebP for both flows). This class
 * sideloads the bytes, replaces any prior attachment, and updates user
 * meta. There is exactly one media-library write path in the plugin.
 */
class Zillha_Avatar_Save {

	/**
	 * Persist a binary as a media library attachment and set it as the
	 * user's avatar. Replaces any previously saved attachment.
	 *
	 * @param int    $user_id   User ID who owns the avatar.
	 * @param string $binary    Raw image bytes (already cropped/resized).
	 * @param string $mime_type IANA mime type, e.g. "image/webp".
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	public static function save_to_profile( $user_id, $binary, $mime_type ) {
		$user_id   = absint( $user_id );
		$mime_type = strtolower( trim( (string) $mime_type ) );

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'zillha_avatar_invalid_user',
				__( 'Invalid user.', 'zillha-avatar' )
			);
		}

		if ( ! is_string( $binary ) || '' === $binary ) {
			return new WP_Error(
				'zillha_avatar_empty_binary',
				__( 'No image data was supplied.', 'zillha-avatar' )
			);
		}

		$extension = self::extension_for_mime( $mime_type );
		if ( '' === $extension ) {
			return new WP_Error(
				'zillha_avatar_unsupported_mime',
				__( 'Unsupported image type.', 'zillha-avatar' )
			);
		}

		// media_handle_sideload() lives in wp-admin/includes and isn't
		// loaded on AJAX or front-end requests by default.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp_path = wp_tempnam( 'zillha-avatar-' . $user_id . '.' . $extension );
		if ( ! $tmp_path ) {
			return new WP_Error(
				'zillha_avatar_tmpfile',
				__( 'Could not create a temporary file for the avatar.', 'zillha-avatar' )
			);
		}

		$bytes_written = file_put_contents( $tmp_path, $binary ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes_written || $bytes_written !== strlen( $binary ) ) {
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
			return new WP_Error(
				'zillha_avatar_tmpwrite',
				__( 'Could not write the avatar to a temporary file.', 'zillha-avatar' )
			);
		}

		$file_array = array(
			'name'     => 'zillha-avatar-' . $user_id . '-' . time() . '.' . $extension,
			'type'     => $mime_type,
			'tmp_name' => $tmp_path,
			'error'    => 0,
			'size'     => filesize( $tmp_path ),
		);

		// media_handle_sideload() ignores any 4th-arg overrides — it builds
		// its allowed-types map from upload_mimes. We must let WebP through
		// the upload_mimes filter for the duration of this single call.
		$allow_image_mime = static function ( $mimes ) use ( $extension, $mime_type ) {
			if ( ! is_array( $mimes ) ) {
				$mimes = array();
			}
			$mimes[ $extension ] = $mime_type;
			return $mimes;
		};
		add_filter( 'upload_mimes', $allow_image_mime );

		$attachment_id = media_handle_sideload( $file_array, 0, null );

		remove_filter( 'upload_mimes', $allow_image_mime );

		if ( file_exists( $tmp_path ) ) {
			wp_delete_file( $tmp_path );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$previous = (int) get_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY, true );
		update_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY, (int) $attachment_id );

		// Whichever flow ran last wins. Drop the old attachment so the
		// media library doesn't accumulate orphan avatars.
		if ( $previous > 0 && $previous !== (int) $attachment_id ) {
			wp_delete_attachment( $previous, true );
		}

		return (int) $attachment_id;
	}

	/**
	 * Map a mime type to a canonical file extension.
	 *
	 * @param string $mime_type Mime type.
	 * @return string Extension without dot, or empty string when unsupported.
	 */
	private static function extension_for_mime( $mime_type ) {
		switch ( $mime_type ) {
			case 'image/webp':
				return 'webp';
			case 'image/jpeg':
				return 'jpg';
			case 'image/png':
				return 'png';
			case 'image/gif':
				return 'gif';
			default:
				return '';
		}
	}
}
