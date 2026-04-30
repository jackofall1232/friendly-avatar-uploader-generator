<?php
/**
 * [zillha_avatar_profile] shortcode — full-page cinematic profile experience.
 *
 * @package Zillha_Avatar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders a wide profile hero for the current user: identity, stats, and the
 * full avatar-change toolkit (upload + AI generate + remove). Reuses every
 * existing AJAX endpoint, save flow, nonce, and CSS variable.
 */
class Zillha_Avatar_Shortcode_Profile {

	const SHORTCODE      = 'zillha_avatar_profile';
	const FONT_HANDLE    = 'google-fonts-zillha-profile';
	const FONT_FAMILY    = 'Cinzel';

	/**
	 * Wire the shortcode.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Render the full profile experience. Returns empty string for guests.
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
		$this->enqueue_display_font();

		ob_start();
		$this->render_template();
		return (string) ob_get_clean();
	}

	/**
	 * Enqueue the Google Font used for the display name. Idempotent.
	 *
	 * @return void
	 */
	private function enqueue_display_font() {
		if ( wp_style_is( self::FONT_HANDLE, 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style(
			self::FONT_HANDLE,
			'https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700&display=swap',
			array(),
			ZILLHA_AVATAR_VERSION
		);
	}

	/**
	 * Render the profile template.
	 *
	 * @return void
	 */
	private function render_template() {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$attachment_id = (int) get_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY, true );
		$has_custom    = $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id );
		$avatar_url    = $has_custom
			? wp_get_attachment_url( $attachment_id )
			: get_avatar_url( $user_id, array( 'size' => ZILLHA_AVATAR_TARGET_SIZE ) );
		$gravatar_url  = get_avatar_url( $user_id, array( 'size' => ZILLHA_AVATAR_TARGET_SIZE ) );

		$display_name = $user->display_name ? $user->display_name : $user->user_login;
		$username     = $user->user_login;
		$member_since = $user->user_registered
			? wp_date( 'F Y', strtotime( $user->user_registered ) )
			: '';

		$registered_ts = $user->user_registered ? strtotime( $user->user_registered ) : 0;
		$account_days  = $registered_ts ? max( 0, (int) floor( ( time() - $registered_ts ) / DAY_IN_SECONDS ) ) : 0;

		$post_count = (int) count_user_posts( $user_id, 'post', true );

		$comment_count = (int) get_comments(
			array(
				'user_id' => $user_id,
				'status'  => 'approve',
				'count'   => true,
			)
		);

		$webhook_configured = '' !== Zillha_Avatar_Plugin::get_webhook_url();
		$uid                = wp_unique_id( 'zag-pf-' );
		$file_input_id      = $uid . '-file';
		?>
		<div
			class="zag-wrap zag-profile"
			data-zag-profile
			id="<?php echo esc_attr( $uid ); ?>"
			data-zag-gravatar="<?php echo esc_attr( $gravatar_url ); ?>"
		>
			<div class="zag-profile__bg" aria-hidden="true"></div>
			<div class="zag-profile__noise" aria-hidden="true"></div>

			<div class="zag-profile__hero">
				<div class="zag-profile__avatar-wrap zag-profile__animate" style="--zag-anim-delay: 0ms;">
					<label
						class="zag-profile__avatar-label"
						for="<?php echo esc_attr( $file_input_id ); ?>"
						aria-label="<?php esc_attr_e( 'Change your profile picture', 'zillha-avatar' ); ?>"
					>
						<img
							class="zag-profile__avatar"
							data-zag-profile-avatar
							src="<?php echo esc_url( $avatar_url ); ?>"
							alt="<?php echo esc_attr( sprintf( /* translators: %s: user display name. */ __( 'Avatar for %s', 'zillha-avatar' ), $display_name ) ); ?>"
						/>
						<span class="zag-profile__avatar-overlay" aria-hidden="true">
							<svg class="zag-profile__camera" viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
								<path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"></path>
								<circle cx="12" cy="13" r="4"></circle>
							</svg>
						</span>
					</label>
					<input
						type="file"
						id="<?php echo esc_attr( $file_input_id ); ?>"
						class="zag-profile__file"
						data-zag-profile-file
						accept="image/jpeg,image/png,image/gif,image/webp"
					/>
				</div>

				<h2 class="zag-profile__name zag-profile__animate" style="--zag-anim-delay: 80ms;">
					<?php echo esc_html( $display_name ); ?>
				</h2>

				<div class="zag-profile__handle zag-profile__animate" style="--zag-anim-delay: 160ms;">
					<span class="zag-profile__at">@</span><?php echo esc_html( $username ); ?>
				</div>

				<?php if ( '' !== $member_since ) : ?>
					<div class="zag-profile__pill zag-profile__animate" style="--zag-anim-delay: 240ms;">
						<?php
						printf(
							/* translators: %s: month and year the user joined. */
							esc_html__( 'Member since %s', 'zillha-avatar' ),
							esc_html( $member_since )
						);
						?>
					</div>
				<?php endif; ?>
			</div>

			<div class="zag-profile__stats zag-profile__animate" style="--zag-anim-delay: 320ms;">
				<div class="zag-profile__stat">
					<div class="zag-profile__stat-value"><?php echo esc_html( number_format_i18n( $post_count ) ); ?></div>
					<div class="zag-profile__stat-label"><?php esc_html_e( 'Posts', 'zillha-avatar' ); ?></div>
				</div>
				<div class="zag-profile__stat">
					<div class="zag-profile__stat-value"><?php echo esc_html( number_format_i18n( $comment_count ) ); ?></div>
					<div class="zag-profile__stat-label"><?php esc_html_e( 'Comments', 'zillha-avatar' ); ?></div>
				</div>
				<div class="zag-profile__stat">
					<div class="zag-profile__stat-value"><?php echo esc_html( number_format_i18n( $account_days ) ); ?></div>
					<div class="zag-profile__stat-label"><?php esc_html_e( 'Days as member', 'zillha-avatar' ); ?></div>
				</div>
			</div>

			<div class="zag-profile__panel zag-profile__animate" style="--zag-anim-delay: 400ms;">
				<div class="zag-profile__panel-header">
					<h3 class="zag-profile__panel-title"><?php esc_html_e( 'Your avatar', 'zillha-avatar' ); ?></h3>
					<p class="zag-profile__panel-subtitle">
						<?php esc_html_e( 'Upload a photo, generate one with AI, or revert to your default Gravatar.', 'zillha-avatar' ); ?>
					</p>
				</div>

				<div class="zag-profile__actions">
					<button type="button" class="zag-btn zag-btn--primary" data-zag-profile-change>
						<span class="zag-btn__label"><?php esc_html_e( 'Change avatar', 'zillha-avatar' ); ?></span>
						<span class="zag-btn__spinner" aria-hidden="true"></span>
					</button>

					<?php if ( $webhook_configured ) : ?>
						<button type="button" class="zag-btn zag-btn--secondary" data-zag-profile-generate>
							<?php esc_html_e( 'Generate with AI', 'zillha-avatar' ); ?>
						</button>
					<?php endif; ?>

					<button
						type="button"
						class="zag-btn zag-btn--ghost zag-btn--danger"
						data-zag-profile-remove
						<?php echo $has_custom ? '' : 'hidden'; ?>
					>
						<?php esc_html_e( 'Remove custom avatar', 'zillha-avatar' ); ?>
					</button>
				</div>

				<p class="zag-profile__hint">
					<?php
					printf(
						/* translators: 1: max file size in MB, 2: target size in pixels. */
						esc_html__( 'JPEG, PNG, GIF or WebP. Max %1$d MB. Saved as a %2$d×%2$d WebP.', 'zillha-avatar' ),
						(int) ( ZILLHA_AVATAR_MAX_UPLOAD_BYTES / ( 1024 * 1024 ) ),
						(int) ZILLHA_AVATAR_TARGET_SIZE
					);
					?>
				</p>

				<div class="zag-message" data-zag-profile-message role="status" aria-live="polite"></div>

				<?php if ( $webhook_configured ) : ?>
					<div class="zag-profile__generator-host" data-zag-profile-generator-host hidden>
						<?php echo do_shortcode( '[zillha_avatar_generator]' ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
