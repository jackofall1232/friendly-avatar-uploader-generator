<?php
/**
 * AJAX handlers for Zillha Avatar.
 *
 * @package Zillha_Avatar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Four authenticated AJAX endpoints. No nopriv handlers exist by design —
 * all flows require a logged-in user.
 *
 * - zillha_avatar_generate         POSTs the questionnaire to the webhook,
 *                                  stores the returned WebP in a private
 *                                  pending file, and returns a base64 data
 *                                  URI for preview.
 * - zillha_avatar_save_generated   Reads the pending WebP, applies the
 *                                  face-bias crop, and persists it.
 * - zillha_avatar_upload           Validates a manually uploaded image,
 *                                  resizes/center-crops to 400×400 WebP,
 *                                  and persists it.
 * - zillha_avatar_remove           Drops the saved attachment and the meta
 *                                  link.
 */
class Zillha_Avatar_Ajax {

	const NONCE_ACTION = 'zillha_avatar_nonce';

	const ART_STYLES = array(
		'photorealistic',
		'anime',
		'oil-painting',
		'pixel-art',
		'watercolor',
		'comic-book',
		'3d-cgi',
		'flat-vector',
		'auto',
	);

	const ALLOWED_UPLOAD_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	);

	const COOLDOWN_PREFIX  = 'zillha_avatar_cooldown_';
	const COOLDOWN_SECONDS = 15;

	/**
	 * Wire AJAX hooks. wp_ajax_ only — guests cannot reach any of these.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_zillha_avatar_generate', array( $this, 'handle_generate' ) );
		add_action( 'wp_ajax_zillha_avatar_save_generated', array( $this, 'handle_save_generated' ) );
		add_action( 'wp_ajax_zillha_avatar_upload', array( $this, 'handle_upload' ) );
		add_action( 'wp_ajax_zillha_avatar_remove', array( $this, 'handle_remove' ) );
	}

	/**
	 * Generate an avatar from the submitted questionnaire and stash the
	 * resulting WebP in a private pending file.
	 *
	 * @return void
	 */
	public function handle_generate() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array( 'message' => __( 'You must be logged in to generate an avatar.', 'zillha-avatar' ) ),
				401
			);
		}

		$webhook_url = Zillha_Avatar_Plugin::get_webhook_url();
		if ( '' === $webhook_url ) {
			wp_send_json_error(
				array( 'message' => __( 'Avatar webhook is not configured. Please contact the site administrator.', 'zillha-avatar' ) ),
				500
			);
		}

		$user_id      = get_current_user_id();
		$cooldown_key = self::COOLDOWN_PREFIX . $user_id;
		$next_allowed = (int) get_transient( $cooldown_key );
		$now          = time();
		if ( $next_allowed > $now ) {
			$remaining = max( 1, $next_allowed - $now );
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: number of seconds the user has to wait. */
						_n(
							'Please wait %d second before generating another avatar.',
							'Please wait %d seconds before generating another avatar.',
							$remaining,
							'zillha-avatar'
						),
						$remaining
					),
				),
				429
			);
		}
		set_transient( $cooldown_key, $now + self::COOLDOWN_SECONDS, self::COOLDOWN_SECONDS );

		$fields = $this->collect_questionnaire_fields();
		if ( is_wp_error( $fields ) ) {
			wp_send_json_error( array( 'message' => $fields->get_error_message() ), 400 );
		}

		$payload = $this->build_webhook_payload( $fields );

		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		if ( $debug ) {
			error_log( '[Zillha Avatar] POST ' . $webhook_url );
			error_log( '[Zillha Avatar] Payload: ' . $payload );
		}

		$response = wp_remote_post(
			$webhook_url,
			array(
				'method'      => 'POST',
				'timeout'     => ZILLHA_AVATAR_WEBHOOK_TIMEOUT,
				'redirection' => 3,
				'httpversion' => '1.1',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'text/plain; charset=utf-8',
					'Accept'       => 'image/webp',
				),
				'body'        => $payload,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( $debug ) {
				error_log( '[Zillha Avatar] WP_Error: ' . $response->get_error_message() );
			}
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message. */
						__( 'Network error contacting the avatar service: %s', 'zillha-avatar' ),
						$response->get_error_message()
					),
				),
				502
			);
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body         = wp_remote_retrieve_body( $response );

		if ( $debug ) {
			$response_headers = wp_remote_retrieve_headers( $response );
			if ( is_object( $response_headers ) && method_exists( $response_headers, 'getAll' ) ) {
				$headers_for_log = $response_headers->getAll();
			} else {
				$headers_for_log = (array) $response_headers;
			}
			error_log( '[Zillha Avatar] Response code: ' . $code );
			error_log( '[Zillha Avatar] Response headers: ' . wp_json_encode( $headers_for_log ) );
			error_log( '[Zillha Avatar] Response body length: ' . strlen( (string) $body ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Avatar service responded with HTTP %d.', 'zillha-avatar' ),
						$code
					),
				),
				502
			);
		}

		if ( '' === $body ) {
			wp_send_json_error(
				array( 'message' => __( 'Avatar service returned an empty response.', 'zillha-avatar' ) ),
				502
			);
		}

		if ( strlen( $body ) > ZILLHA_AVATAR_WEBHOOK_MAX_BYTES ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: maximum allowed size in megabytes. */
						__( 'Avatar service returned a file larger than the %d MB limit.', 'zillha-avatar' ),
						(int) ( ZILLHA_AVATAR_WEBHOOK_MAX_BYTES / 1048576 )
					),
				),
				502
			);
		}

		if ( false === stripos( $content_type, 'image/webp' ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: content type returned by webhook. */
						__( 'Unexpected response type from avatar service: %s', 'zillha-avatar' ),
						$content_type ? $content_type : __( '(none)', 'zillha-avatar' )
					),
				),
				502
			);
		}

		if ( ! Zillha_Avatar_Plugin::ensure_pending_dir() ) {
			wp_send_json_error(
				array( 'message' => __( 'Could not prepare the avatar storage directory.', 'zillha-avatar' ) ),
				500
			);
		}

		// Drop any prior pending file for this user; we are about to mint a fresh token.
		$previous_path = Zillha_Avatar_Plugin::pending_file_path_for_user( $user_id );
		if ( '' !== $previous_path && file_exists( $previous_path ) ) {
			wp_delete_file( $previous_path );
		}

		$token        = Zillha_Avatar_Plugin::generate_pending_token();
		$pending_path = Zillha_Avatar_Plugin::pending_file_path( $user_id, $token );
		if ( '' === $pending_path ) {
			wp_send_json_error(
				array( 'message' => __( 'Could not determine the avatar storage location.', 'zillha-avatar' ) ),
				500
			);
		}

		$expected      = strlen( $body );
		$bytes_written = file_put_contents( $pending_path, $body, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes_written || $bytes_written !== $expected ) {
			if ( file_exists( $pending_path ) ) {
				wp_delete_file( $pending_path );
			}
			wp_send_json_error(
				array( 'message' => __( 'Could not write the generated avatar to disk.', 'zillha-avatar' ) ),
				500
			);
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@chmod( $pending_path, 0600 );

		update_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY, $token );

		wp_send_json_success(
			array(
				'data_uri'   => 'data:image/webp;base64,' . base64_encode( $body ),
				'expires_in' => ZILLHA_AVATAR_PENDING_TTL,
			)
		);
	}

	/**
	 * Save the most recently generated pending WebP as the user's avatar.
	 *
	 * @return void
	 */
	public function handle_save_generated() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array( 'message' => __( 'You must be logged in to save an avatar.', 'zillha-avatar' ) ),
				401
			);
		}

		$user_id      = get_current_user_id();
		$pending_path = Zillha_Avatar_Plugin::pending_file_path_for_user( $user_id );

		if ( '' === $pending_path || ! file_exists( $pending_path ) ) {
			delete_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY );
			wp_send_json_error(
				array( 'message' => __( 'Your avatar session expired. Please generate the avatar again.', 'zillha-avatar' ) ),
				410
			);
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mtime = @filemtime( $pending_path );
		if ( false === $mtime || ( time() - $mtime ) > ZILLHA_AVATAR_PENDING_TTL ) {
			wp_delete_file( $pending_path );
			delete_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY );
			wp_send_json_error(
				array( 'message' => __( 'Your avatar session expired. Please generate the avatar again.', 'zillha-avatar' ) ),
				410
			);
		}

		$binary = file_get_contents( $pending_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $binary || '' === $binary ) {
			wp_delete_file( $pending_path );
			delete_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY );
			wp_send_json_error(
				array( 'message' => __( 'Your avatar session expired. Please generate the avatar again.', 'zillha-avatar' ) ),
				410
			);
		}

		$cropped = $this->crop_generated_for_face( $binary, $user_id );
		if ( '' !== $cropped ) {
			$binary = $cropped;
		}

		$attachment_id = Zillha_Avatar_Save::save_to_profile( $user_id, $binary, 'image/webp' );

		if ( file_exists( $pending_path ) ) {
			wp_delete_file( $pending_path );
		}
		delete_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message. */
						__( 'Could not save avatar to the media library: %s', 'zillha-avatar' ),
						$attachment_id->get_error_message()
					),
				),
				500
			);
		}

		$url = wp_get_attachment_url( $attachment_id );
		wp_send_json_success(
			array(
				'attachment_id' => (int) $attachment_id,
				'url'           => $url ? $url : '',
				'message'       => __( 'Avatar saved as your profile picture.', 'zillha-avatar' ),
			)
		);
	}

	/**
	 * Handle a manual avatar upload from the [zillha_avatar_uploader] form.
	 *
	 * @return void
	 */
	public function handle_upload() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array( 'message' => __( 'You must be logged in to upload an avatar.', 'zillha-avatar' ) ),
				401
			);
		}

		if ( empty( $_FILES['zillha_avatar'] ) || ! isset( $_FILES['zillha_avatar']['error'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No file was uploaded.', 'zillha-avatar' ) ),
				400
			);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file payload, validated below.
		$file = $_FILES['zillha_avatar'];

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			wp_send_json_error(
				array( 'message' => $this->upload_error_message( (int) $file['error'] ) ),
				400
			);
		}

		if ( (int) $file['size'] > ZILLHA_AVATAR_MAX_UPLOAD_BYTES ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: max file size in megabytes. */
						__( 'File is too large. Max %d MB.', 'zillha-avatar' ),
						(int) ( ZILLHA_AVATAR_MAX_UPLOAD_BYTES / ( 1024 * 1024 ) )
					),
				),
				400
			);
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid upload.', 'zillha-avatar' ) ),
				400
			);
		}

		$mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$detected = finfo_file( $finfo, $tmp_name );
				finfo_close( $finfo );
				if ( is_string( $detected ) && '' !== $detected ) {
					$mime = $detected;
				}
			}
		}

		if ( ! in_array( $mime, self::ALLOWED_UPLOAD_TYPES, true ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Unsupported file type. Use JPEG, PNG, GIF or WebP.', 'zillha-avatar' ) ),
				415
			);
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt files legitimately error.
		$image_info = @getimagesize( $tmp_name );
		if ( ! $image_info || empty( $image_info[0] ) || empty( $image_info[1] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'The uploaded file is not a valid image.', 'zillha-avatar' ) ),
				400
			);
		}

		$user_id = get_current_user_id();
		$binary  = $this->resize_upload_to_square_webp( $tmp_name );
		if ( is_wp_error( $binary ) ) {
			wp_send_json_error(
				array( 'message' => $binary->get_error_message() ),
				500
			);
		}

		$attachment_id = Zillha_Avatar_Save::save_to_profile( $user_id, $binary, 'image/webp' );
		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message. */
						__( 'Could not save avatar to the media library: %s', 'zillha-avatar' ),
						$attachment_id->get_error_message()
					),
				),
				500
			);
		}

		$url = wp_get_attachment_url( $attachment_id );
		wp_send_json_success(
			array(
				'attachment_id' => (int) $attachment_id,
				'url'           => $url ? $url : '',
				'message'       => __( 'Avatar updated.', 'zillha-avatar' ),
			)
		);
	}

	/**
	 * Remove the saved avatar so the user falls back to Gravatar.
	 *
	 * @return void
	 */
	public function handle_remove() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array( 'message' => __( 'You must be logged in.', 'zillha-avatar' ) ),
				401
			);
		}

		$user_id       = get_current_user_id();
		$attachment_id = (int) get_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY, true );

		if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
		delete_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY );

		wp_send_json_success(
			array(
				'message'  => __( 'Custom avatar removed.', 'zillha-avatar' ),
				'gravatar' => get_avatar_url( $user_id, array( 'size' => ZILLHA_AVATAR_TARGET_SIZE ) ),
			)
		);
	}

	/**
	 * Resize and center-crop a manually uploaded image to a 400×400 WebP.
	 *
	 * @param string $source_path Absolute path to the validated upload tempfile.
	 * @return string|WP_Error Raw WebP binary on success.
	 */
	private function resize_upload_to_square_webp( $source_path ) {
		$editor = wp_get_image_editor( $source_path );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error(
				'zillha_avatar_editor',
				__( 'Could not process the image.', 'zillha-avatar' )
			);
		}

		// Standard center-crop square at 400×400. WP_Image_Editor::resize()
		// with $crop=true does a smart cover-fit crop from the center, which
		// is the intended behaviour for manual uploads where there is no
		// face-bias to apply.
		$resized = $editor->resize( ZILLHA_AVATAR_TARGET_SIZE, ZILLHA_AVATAR_TARGET_SIZE, true );
		if ( is_wp_error( $resized ) ) {
			return new WP_Error(
				'zillha_avatar_resize',
				__( 'Could not resize the image.', 'zillha-avatar' )
			);
		}

		$tmp_out = wp_tempnam( 'zillha-avatar-out.webp' );
		if ( ! $tmp_out ) {
			return new WP_Error(
				'zillha_avatar_tmpfile',
				__( 'Could not create a temporary file for the avatar.', 'zillha-avatar' )
			);
		}

		$saved = $editor->save( $tmp_out, 'image/webp' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			if ( file_exists( $tmp_out ) ) {
				wp_delete_file( $tmp_out );
			}
			// Fall back to JPEG if the install's image library doesn't
			// support WebP encoding (rare but possible on old GD builds).
			$tmp_out = wp_tempnam( 'zillha-avatar-out.jpg' );
			if ( ! $tmp_out ) {
				return new WP_Error(
					'zillha_avatar_tmpfile',
					__( 'Could not create a temporary file for the avatar.', 'zillha-avatar' )
				);
			}
			$saved = $editor->save( $tmp_out, 'image/jpeg' );
			if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
				if ( file_exists( $tmp_out ) ) {
					wp_delete_file( $tmp_out );
				}
				return new WP_Error(
					'zillha_avatar_save',
					__( 'Could not save the resized image.', 'zillha-avatar' )
				);
			}
		}

		$binary = file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $saved['path'] );

		if ( false === $binary || '' === $binary ) {
			return new WP_Error(
				'zillha_avatar_read',
				__( 'Could not read the resized image.', 'zillha-avatar' )
			);
		}

		return $binary;
	}

	/**
	 * Apply the head-and-shoulders crop to a generated 2:3 portrait WebP.
	 *
	 * The webhook pipeline returns 2:3 portrait images with the subject
	 * centered horizontally and the head starting near the top of the
	 * frame. Taking a full-width square from the top cut the sides off;
	 * a min(width,height) square left a sliver of background to either
	 * side and still missed the very top of the head once compositions
	 * varied. The current heuristic is calibrated to that framing:
	 *
	 *   crop_y    = 5% of height  (so the top of head/hat isn't clipped)
	 *   crop_side = 60% of width  (lops 20% of background off each side)
	 *   crop_side clamped to width and (height - crop_y) so we stay
	 *     in-bounds and the source crop is genuinely square — feeding
	 *     an asymmetric crop into crop(…,400,400) would stretch it.
	 *   crop_x    = (width - crop_side) / 2  (horizontally centered)
	 *
	 * Output is 400×400 WebP — large enough for every avatar slot core
	 * and themes request without bloating the media library.
	 *
	 * On any editor error we return an empty string and the caller falls
	 * back to the uncropped original so the save still succeeds.
	 *
	 * @param string $binary  Raw WebP bytes from the pending file.
	 * @param int    $user_id User ID — used only for the tempfile name.
	 * @return string Cropped WebP bytes, or empty string on failure.
	 */
	private function crop_generated_for_face( $binary, $user_id ) {
		$tmp = wp_tempnam( 'zillha-crop-' . $user_id . '.webp' );
		if ( ! $tmp ) {
			return '';
		}

		$bytes = file_put_contents( $tmp, $binary ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return '';
		}

		$editor = wp_get_image_editor( $tmp );
		if ( is_wp_error( $editor ) ) {
			wp_delete_file( $tmp );
			return '';
		}

		$size = $editor->get_size();
		if ( ! is_array( $size ) || empty( $size['width'] ) || empty( $size['height'] ) ) {
			wp_delete_file( $tmp );
			return '';
		}

		$width  = (int) $size['width'];
		$height = (int) $size['height'];

		$crop_y    = (int) ( $height * 0.05 );
		$crop_side = (int) ( $width * 0.60 );

		// Clamp to a single side length so the source crop stays square
		// (no stretch when resized to 400×400) and in-bounds even on
		// near-square or landscape inputs.
		$crop_side = (int) min( $crop_side, $width, max( 0, $height - $crop_y ) );
		if ( $crop_side <= 0 ) {
			wp_delete_file( $tmp );
			return '';
		}
		$crop_x = (int) ( ( $width - $crop_side ) / 2 );

		$cropped_ok = $editor->crop( $crop_x, $crop_y, $crop_side, $crop_side, ZILLHA_AVATAR_TARGET_SIZE, ZILLHA_AVATAR_TARGET_SIZE, false );
		if ( is_wp_error( $cropped_ok ) || false === $cropped_ok ) {
			wp_delete_file( $tmp );
			return '';
		}

		$saved = $editor->save( $tmp, 'image/webp' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			wp_delete_file( $tmp );
			return '';
		}

		$out = file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $saved['path'] );

		if ( false === $out || '' === $out ) {
			return '';
		}
		return $out;
	}

	/**
	 * Pull and sanitize the questionnaire fields from $_POST.
	 *
	 * @return array|WP_Error
	 */
	private function collect_questionnaire_fields() {
		$required = array(
			'vibe'       => __( 'Overall vibe / aesthetic', 'zillha-avatar' ),
			'gender'     => __( 'Gender expression', 'zillha-avatar' ),
			'hair'       => __( 'Hair style and color', 'zillha-avatar' ),
			'outfit'     => __( 'Outfit or clothing style', 'zillha-avatar' ),
			'background' => __( 'Background / setting', 'zillha-avatar' ),
			'mood'       => __( 'Mood or emotion', 'zillha-avatar' ),
		);

		$fields = array();

		foreach ( $required as $key => $label ) {
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by caller.
			$val = sanitize_text_field( (string) $raw );
			if ( '' === trim( $val ) ) {
				return new WP_Error(
					'zillha_avatar_missing_field',
					sprintf(
						/* translators: %s: field label. */
						__( 'Please fill in: %s', 'zillha-avatar' ),
						$label
					)
				);
			}
			$fields[ $key ] = $val;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by caller.
		$features           = isset( $_POST['features'] ) ? wp_unslash( $_POST['features'] ) : '';
		$fields['features'] = sanitize_textarea_field( (string) $features );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by caller.
		$art_style = isset( $_POST['art_style'] ) ? sanitize_text_field( wp_unslash( $_POST['art_style'] ) ) : 'auto';
		if ( ! in_array( $art_style, self::ART_STYLES, true ) ) {
			$art_style = 'auto';
		}
		$fields['art_style'] = $art_style;

		return $fields;
	}

	/**
	 * Build the plain-text webhook body from sanitized fields.
	 *
	 * @param array $fields Sanitized field data.
	 * @return string
	 */
	private function build_webhook_payload( array $fields ) {
		$art_label = $this->art_style_label( $fields['art_style'] );

		$lines = array(
			'Overall vibe / aesthetic: ' . $fields['vibe'],
			'Gender expression: ' . $fields['gender'],
			'Hair style and color: ' . $fields['hair'],
			'Outfit or clothing style: ' . $fields['outfit'],
			'Background / setting: ' . $fields['background'],
			'Mood or emotion: ' . $fields['mood'],
			'Special features or accessories: ' . ( '' !== $fields['features'] ? $fields['features'] : '(none)' ),
			'Art style: ' . $art_label,
		);

		return implode( "\n", $lines );
	}

	/**
	 * Convert an art style slug into its human-readable label.
	 *
	 * @param string $slug Art style slug.
	 * @return string
	 */
	private function art_style_label( $slug ) {
		switch ( $slug ) {
			case 'photorealistic':
				return 'Photorealistic';
			case 'anime':
				return 'Anime';
			case 'oil-painting':
				return 'Oil painting';
			case 'pixel-art':
				return 'Pixel art';
			case 'watercolor':
				return 'Watercolor';
			case 'comic-book':
				return 'Comic book';
			case '3d-cgi':
				return '3D / CGI';
			case 'flat-vector':
				return 'Flat vector';
			case 'auto':
			default:
				return 'Let AI decide';
		}
	}

	/**
	 * Translate a PHP UPLOAD_ERR_* code into a human-readable string.
	 *
	 * @param int $code Upload error code.
	 * @return string
	 */
	private function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The uploaded file is too large.', 'zillha-avatar' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The file was only partially uploaded. Please try again.', 'zillha-avatar' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was uploaded.', 'zillha-avatar' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'Server is missing a temporary folder.', 'zillha-avatar' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Failed to write file to disk.', 'zillha-avatar' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'A PHP extension stopped the upload.', 'zillha-avatar' );
			default:
				return __( 'Unknown upload error.', 'zillha-avatar' );
		}
	}
}
