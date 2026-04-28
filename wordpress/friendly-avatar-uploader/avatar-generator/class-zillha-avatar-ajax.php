<?php
/**
 * AJAX handlers for the ZillHa Avatar Generator plugin.
 *
 * @package ZillHa_Avatar_Generator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles avatar generation and saving via authenticated AJAX.
 */
class Zillha_Avatar_Ajax {

	const NONCE_ACTION = 'zillha_avatar_generator_nonce';

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

	const COOLDOWN_PREFIX  = 'zillha_avatar_cooldown_';
	const COOLDOWN_SECONDS = 15;
	const MAX_RESPONSE_BYTES = 4194304; // 4 MB cap on the WebP returned by the webhook.

	/**
	 * Wire up AJAX hooks. Note: only wp_ajax_ — guests are not allowed.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_zillha_generate_avatar', array( $this, 'handle_generate' ) );
		add_action( 'wp_ajax_zillha_save_avatar', array( $this, 'handle_save' ) );
	}

	/**
	 * Generate an avatar from the submitted questionnaire.
	 *
	 * @return void
	 */
	public function handle_generate() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array( 'message' => __( 'You must be logged in to generate an avatar.', 'zillha-avatar-generator' ) ),
				401
			);
		}

		$webhook_url = Zillha_Avatar_Generator::get_webhook_url();
		if ( '' === $webhook_url ) {
			wp_send_json_error(
				array( 'message' => __( 'Avatar webhook is not configured. Please contact the site administrator.', 'zillha-avatar-generator' ) ),
				500
			);
		}

		$cooldown_key   = self::COOLDOWN_PREFIX . get_current_user_id();
		$next_allowed   = (int) get_transient( $cooldown_key );
		$now            = time();
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
							'zillha-avatar-generator'
						),
						$remaining
					),
				),
				429
			);
		}
		set_transient( $cooldown_key, $now + self::COOLDOWN_SECONDS, self::COOLDOWN_SECONDS );

		$fields = $this->collect_fields();
		if ( is_wp_error( $fields ) ) {
			wp_send_json_error( array( 'message' => $fields->get_error_message() ), 400 );
		}

		$payload = $this->build_payload( $fields );

		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		if ( $debug ) {
			error_log( '[ZillHa Avatar] POST ' . $webhook_url );
			error_log( '[ZillHa Avatar] Payload: ' . $payload );
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
				error_log( '[ZillHa Avatar] WP_Error: ' . $response->get_error_message() );
			}
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message. */
						__( 'Network error contacting the avatar service: %s', 'zillha-avatar-generator' ),
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
			error_log( '[ZillHa Avatar] Response code: ' . $code );
			error_log( '[ZillHa Avatar] Response headers: ' . wp_json_encode( $headers_for_log ) );
			error_log( '[ZillHa Avatar] Response body length: ' . strlen( (string) $body ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Avatar service responded with HTTP %d.', 'zillha-avatar-generator' ),
						$code
					),
				),
				502
			);
		}

		if ( '' === $body ) {
			wp_send_json_error(
				array( 'message' => __( 'Avatar service returned an empty response.', 'zillha-avatar-generator' ) ),
				502
			);
		}

		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: maximum allowed size in megabytes. */
						__( 'Avatar service returned a file larger than the %d MB limit.', 'zillha-avatar-generator' ),
						(int) ( self::MAX_RESPONSE_BYTES / 1048576 )
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
						__( 'Unexpected response type from avatar service: %s', 'zillha-avatar-generator' ),
						$content_type ? $content_type : __( '(none)', 'zillha-avatar-generator' )
					),
				),
				502
			);
		}

		$user_id = get_current_user_id();

		if ( ! Zillha_Avatar_Generator::ensure_pending_dir() ) {
			wp_send_json_error(
				array( 'message' => __( 'Could not prepare the avatar storage directory.', 'zillha-avatar-generator' ) ),
				500
			);
		}

		// Drop any previous pending file for this user; we are about to mint a fresh token.
		$previous_path = Zillha_Avatar_Generator::pending_file_path_for_user( $user_id );
		if ( '' !== $previous_path && file_exists( $previous_path ) ) {
			@unlink( $previous_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$token        = Zillha_Avatar_Generator::generate_pending_token();
		$pending_path = Zillha_Avatar_Generator::pending_file_path( $user_id, $token );
		if ( '' === $pending_path ) {
			wp_send_json_error(
				array( 'message' => __( 'Could not determine the avatar storage location.', 'zillha-avatar-generator' ) ),
				500
			);
		}

		$expected      = strlen( $body );
		$bytes_written = file_put_contents( $pending_path, $body, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes_written || $bytes_written !== $expected ) {
			if ( file_exists( $pending_path ) ) {
				@unlink( $pending_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			wp_send_json_error(
				array( 'message' => __( 'Could not write the generated avatar to disk.', 'zillha-avatar-generator' ) ),
				500
			);
		}
		@chmod( $pending_path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		update_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY, $token );

		$data_uri = 'data:image/webp;base64,' . base64_encode( $body );

		wp_send_json_success(
			array(
				'data_uri'   => $data_uri,
				'expires_in' => ZILLHA_AVATAR_PENDING_TTL,
			)
		);
	}

	/**
	 * Persist the most recently generated avatar to the media library and link it to the user.
	 *
	 * @return void
	 */
	public function handle_save() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array( 'message' => __( 'You must be logged in to save an avatar.', 'zillha-avatar-generator' ) ),
				401
			);
		}

		$user_id      = get_current_user_id();
		$pending_path = Zillha_Avatar_Generator::pending_file_path_for_user( $user_id );

		if ( '' === $pending_path || ! file_exists( $pending_path ) ) {
			delete_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY );
			wp_send_json_error(
				array( 'message' => __( 'Your avatar session expired. Please generate the avatar again.', 'zillha-avatar-generator' ) ),
				410
			);
		}

		$mtime = @filemtime( $pending_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $mtime || ( time() - $mtime ) > ZILLHA_AVATAR_PENDING_TTL ) {
			@unlink( $pending_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			delete_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY );
			wp_send_json_error(
				array( 'message' => __( 'Your avatar session expired. Please generate the avatar again.', 'zillha-avatar-generator' ) ),
				410
			);
		}

		$binary = file_get_contents( $pending_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $binary || '' === $binary ) {
			@unlink( $pending_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			delete_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY );
			wp_send_json_error(
				array( 'message' => __( 'Your avatar session expired. Please generate the avatar again.', 'zillha-avatar-generator' ) ),
				410
			);
		}

		// media_handle_sideload requires these admin-only includes.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Pipeline images are 2:3 portrait with the subject centered horizontally
		// and the head starting near the top. A full-width square pulled in 20%
		// on each side (60% wide), nudged 5% down so the top of the head/hat is
		// included, captures the head and upper shoulders. Resized to 400x400 —
		// large enough for every avatar slot WordPress and themes request.
		// `?zag_crop=manual` is an unadvertised back-door that skips the crop
		// for the rare image whose framing the heuristic gets wrong.
		$skip_crop = isset( $_GET['zag_crop'] ) && 'manual' === $_GET['zag_crop']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $skip_crop ) {
			$crop_tmp = wp_tempnam( 'zillha-crop-' . $user_id . '.webp' );
			if ( $crop_tmp ) {
				if ( false !== file_put_contents( $crop_tmp, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					$editor = wp_get_image_editor( $crop_tmp );
					if ( ! is_wp_error( $editor ) ) {
						$size = $editor->get_size();
						if ( is_array( $size ) && ! empty( $size['width'] ) && ! empty( $size['height'] ) ) {
							$width  = (int) $size['width'];
							$height = (int) $size['height'];

							$crop_y    = (int) ( $height * 0.05 );
							$crop_side = (int) ( $width * 0.60 );

							// Clamp to a single square so the source crop stays
							// square (no stretch when resized to 400x400) and
							// in-bounds even on near-square or landscape inputs.
							$crop_side = (int) min( $crop_side, $width, max( 0, $height - $crop_y ) );
							$crop_w    = $crop_side;
							$crop_h    = $crop_side;
							$crop_x    = (int) ( ( $width - $crop_side ) / 2 );

							$cropped_ok = $editor->crop( $crop_x, $crop_y, $crop_w, $crop_h, 400, 400, false );
							if ( ! is_wp_error( $cropped_ok ) && false !== $cropped_ok ) {
								$saved = $editor->save( $crop_tmp, 'image/webp' );
								if ( ! is_wp_error( $saved ) ) {
									$cropped = file_get_contents( $crop_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
									if ( false !== $cropped && '' !== $cropped ) {
										$binary = $cropped;
									}
								}
							}
						}
					}
				}
				@unlink( $crop_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		$tmp_dir = get_temp_dir();
		$file    = wp_tempnam( 'zillha-avatar-' . $user_id . '.webp', $tmp_dir );
		if ( ! $file ) {
			wp_send_json_error(
				array( 'message' => __( 'Could not create a temporary file for the avatar.', 'zillha-avatar-generator' ) ),
				500
			);
		}

		$expected_bytes = strlen( $binary );
		$bytes_written  = file_put_contents( $file, $binary );
		if ( false === $bytes_written || $bytes_written !== $expected_bytes ) {
			if ( file_exists( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			wp_send_json_error(
				array( 'message' => __( 'Could not write the avatar to a temporary file.', 'zillha-avatar-generator' ) ),
				500
			);
		}

		$file_array = array(
			'name'     => 'zillha-avatar-' . $user_id . '-' . time() . '.webp',
			'type'     => 'image/webp',
			'tmp_name' => $file,
			'error'    => 0,
			'size'     => filesize( $file ),
		);

		// media_handle_sideload() ignores any 4th-arg overrides (it hardcodes its own),
		// so we must allow webp via the upload_mimes filter for the duration of the call.
		$allow_webp = static function ( $mimes ) {
			if ( ! is_array( $mimes ) ) {
				$mimes = array();
			}
			$mimes['webp'] = 'image/webp';
			return $mimes;
		};
		add_filter( 'upload_mimes', $allow_webp );

		$attachment_id = media_handle_sideload( $file_array, 0, null );

		remove_filter( 'upload_mimes', $allow_webp );

		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message. */
						__( 'Could not save avatar to the media library: %s', 'zillha-avatar-generator' ),
						$attachment_id->get_error_message()
					),
				),
				500
			);
		}

		$previous = (int) get_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY, true );
		update_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY, (int) $attachment_id );

		// Overwrite the meta keys used by friendly-avatar-uploader / WP User Avatar so
		// our attachment wins when those plugins are active alongside this one.
		update_user_meta( $user_id, 'friendly_avatar', (int) $attachment_id );
		update_user_meta( $user_id, 'wp_user_avatar', (int) $attachment_id );

		if ( $previous && $previous !== (int) $attachment_id ) {
			wp_delete_attachment( $previous, true );
		}

		if ( file_exists( $pending_path ) ) {
			@unlink( $pending_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		delete_user_meta( $user_id, ZILLHA_AVATAR_PENDING_META_KEY );

		$url = wp_get_attachment_url( $attachment_id );

		wp_send_json_success(
			array(
				'attachment_id' => (int) $attachment_id,
				'url'           => $url ? $url : '',
				'message'       => __( 'Avatar saved as your profile picture.', 'zillha-avatar-generator' ),
			)
		);
	}

	/**
	 * Pull and sanitize the questionnaire fields from $_POST.
	 *
	 * @return array|WP_Error
	 */
	private function collect_fields() {
		$required = array(
			'vibe'       => __( 'Overall vibe / aesthetic', 'zillha-avatar-generator' ),
			'gender'     => __( 'Gender expression', 'zillha-avatar-generator' ),
			'hair'       => __( 'Hair style and color', 'zillha-avatar-generator' ),
			'outfit'     => __( 'Outfit or clothing style', 'zillha-avatar-generator' ),
			'background' => __( 'Background / setting', 'zillha-avatar-generator' ),
			'mood'       => __( 'Mood or emotion', 'zillha-avatar-generator' ),
		);

		$fields = array();

		foreach ( $required as $key => $label ) {
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$val = sanitize_text_field( (string) $raw );
			if ( '' === trim( $val ) ) {
				return new WP_Error(
					'zillha_missing_field',
					sprintf(
						/* translators: %s: field label. */
						__( 'Please fill in: %s', 'zillha-avatar-generator' ),
						$label
					)
				);
			}
			$fields[ $key ] = $val;
		}

		$features = isset( $_POST['features'] ) ? wp_unslash( $_POST['features'] ) : '';
		$fields['features'] = sanitize_textarea_field( (string) $features );

		$art_style = isset( $_POST['art_style'] ) ? sanitize_text_field( wp_unslash( $_POST['art_style'] ) ) : 'auto';
		if ( ! in_array( $art_style, self::ART_STYLES, true ) ) {
			$art_style = 'auto';
		}
		$fields['art_style'] = $art_style;

		return $fields;
	}

	/**
	 * Map a sanitized field array to the plain text payload sent to the webhook.
	 *
	 * @param array $fields Sanitized field data.
	 * @return string
	 */
	private function build_payload( array $fields ) {
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
}
