<?php
/**
 * [zillha_avatar_uploader] shortcode — manual image upload form.
 *
 * @package Zillha_Avatar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the manual upload form. Returns nothing for guests; for
 * logged-in users it enqueues the shared CSS/JS bundle and prints the
 * preview, file picker, upload, and remove controls.
 */
class Zillha_Avatar_Shortcode_Uploader {

	const SHORTCODE = 'zillha_avatar_uploader';

	/**
	 * Wire the shortcode.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Render the uploader markup. Returns empty string for guests.
	 *
	 * @param array|string $atts Shortcode attributes (unused).
	 * @return string
	 */
	public function render( $atts = array() ) {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			return '';
		}

		Zillha_Avatar_Plugin::enqueue_assets();

		$user_id       = get_current_user_id();
		$attachment_id = (int) get_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY, true );
		$has_custom    = $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id );
		$avatar_url    = $has_custom
			? wp_get_attachment_url( $attachment_id )
			: get_avatar_url( $user_id, array( 'size' => ZILLHA_AVATAR_TARGET_SIZE ) );
		$gravatar_url  = get_avatar_url( $user_id, array( 'size' => ZILLHA_AVATAR_TARGET_SIZE ) );
		$uid           = wp_unique_id( 'zag-up-' );

		ob_start();
		?>
		<div class="zag-wrap zag-wrap--uploader" data-zag-uploader id="<?php echo esc_attr( $uid ); ?>" data-zag-gravatar="<?php echo esc_attr( $gravatar_url ); ?>">
			<div class="zag-preview-wrap">
				<img
					class="zag-preview"
					data-zag-preview-image
					src="<?php echo esc_url( $avatar_url ); ?>"
					alt="<?php esc_attr_e( 'Your avatar preview', 'zillha-avatar' ); ?>"
				/>
			</div>

			<form class="zag-upload-form" data-zag-upload-form enctype="multipart/form-data">
				<label class="zag-file-label" for="<?php echo esc_attr( $uid . '-file' ); ?>">
					<span class="zag-file-label__text"><?php esc_html_e( 'Choose an image', 'zillha-avatar' ); ?></span>
					<input
						type="file"
						id="<?php echo esc_attr( $uid . '-file' ); ?>"
						name="zillha_avatar"
						class="zag-file"
						accept="image/jpeg,image/png,image/gif,image/webp"
						data-zag-file
						required
					/>
				</label>

				<div class="zag-form__actions zag-form__actions--inline">
					<button type="submit" class="zag-btn zag-btn--primary" data-zag-upload>
						<span class="zag-btn__label"><?php esc_html_e( 'Upload avatar', 'zillha-avatar' ); ?></span>
						<span class="zag-btn__spinner" aria-hidden="true"></span>
					</button>
					<button
						type="button"
						class="zag-btn zag-btn--ghost"
						data-zag-remove
						<?php echo $has_custom ? '' : 'hidden'; ?>
					>
						<?php esc_html_e( 'Remove custom avatar', 'zillha-avatar' ); ?>
					</button>
				</div>

				<p class="zag-hint">
					<?php
					printf(
						/* translators: 1: max file size in MB, 2: target size in pixels. */
						esc_html__( 'JPEG, PNG, GIF or WebP. Max %1$d MB. Saved as a %2$d×%2$d WebP.', 'zillha-avatar' ),
						(int) ( ZILLHA_AVATAR_MAX_UPLOAD_BYTES / ( 1024 * 1024 ) ),
						(int) ZILLHA_AVATAR_TARGET_SIZE
					);
					?>
				</p>

				<div class="zag-message" data-zag-upload-message role="status" aria-live="polite"></div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
