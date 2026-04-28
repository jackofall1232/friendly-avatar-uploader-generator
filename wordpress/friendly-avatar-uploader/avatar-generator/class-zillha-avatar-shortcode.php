<?php
/**
 * Shortcode rendering and avatar URL filter.
 *
 * @package ZillHa_Avatar_Generator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the shortcode form and replaces Gravatar URLs with the saved attachment.
 */
class Zillha_Avatar_Shortcode {

	const SHORTCODE = 'zillha_avatar_generator';
	const HANDLE    = 'zillha-avatar-generator';

	/**
	 * Whether assets have been registered (to avoid duplicate registration).
	 *
	 * @var bool
	 */
	private $assets_registered = false;

	/**
	 * Wire up shortcode and avatar filter.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_filter( 'get_avatar_url', array( $this, 'filter_avatar_url' ), 10, 3 );
		// PHP_INT_MAX so we run *after* friendly-avatar-uploader / WP User Avatar
		// (which hook get_avatar at the default priority 10) and overwrite their
		// markup. WordPress runs lower priorities first, so being last wins.
		add_filter( 'get_avatar', array( $this, 'filter_avatar_html' ), PHP_INT_MAX, 6 );
	}

	/**
	 * Register (but do not enqueue) the front-end assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		if ( $this->assets_registered ) {
			return;
		}

		wp_register_style(
			self::HANDLE,
			ZILLHA_AVATAR_GENERATOR_URL . 'assets/css/zillha-avatar.css',
			array(),
			ZILLHA_AVATAR_GENERATOR_VERSION
		);

		wp_register_script(
			self::HANDLE,
			ZILLHA_AVATAR_GENERATOR_URL . 'assets/js/zillha-avatar.js',
			array(),
			ZILLHA_AVATAR_GENERATOR_VERSION,
			true
		);

		$this->assets_registered = true;
	}

	/**
	 * Render the shortcode markup. Returns empty string for guests.
	 *
	 * @param array|string $atts Shortcode attributes (unused).
	 * @return string
	 */
	public function render( $atts = array() ) {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			return '';
		}

		$this->register_assets();

		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		wp_localize_script(
			self::HANDLE,
			'ZillhaAvatarConfig',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Zillha_Avatar_Ajax::NONCE_ACTION ),
				'i18n'    => array(
					'generating'      => __( 'Generating avatar… this can take up to two minutes.', 'zillha-avatar-generator' ),
					'saving'          => __( 'Saving as profile picture…', 'zillha-avatar-generator' ),
					'genericError'    => __( 'Something went wrong. Please try again.', 'zillha-avatar-generator' ),
					'savedSuccess'    => __( 'Saved! Your profile picture has been updated.', 'zillha-avatar-generator' ),
					'downloadName'    => __( 'zillha-avatar.webp', 'zillha-avatar-generator' ),
					'sessionExpired'  => __( 'Your avatar session expired. Please generate the avatar again.', 'zillha-avatar-generator' ),
					'fillRequired'    => __( 'Please fill in all required fields.', 'zillha-avatar-generator' ),
				),
			)
		);

		ob_start();
		$this->render_template();
		return (string) ob_get_clean();
	}

	/**
	 * Output the questionnaire template.
	 *
	 * @return void
	 */
	private function render_template() {
		$webhook_configured = '' !== Zillha_Avatar_Generator::get_webhook_url();
		?>
		<div class="zag-wrap" data-zag-root>
			<?php if ( ! $webhook_configured ) : ?>
				<div class="zag-alert zag-alert--warning" role="alert">
					<?php esc_html_e( 'Avatar generation is not configured yet. Please contact the site administrator.', 'zillha-avatar-generator' ); ?>
				</div>
			<?php endif; ?>

			<button type="button" class="zag-toggle" data-zag-toggle aria-expanded="false">
				<span class="zag-toggle__label"><?php esc_html_e( 'Generate your ZillHa avatar', 'zillha-avatar-generator' ); ?></span>
				<span class="zag-toggle__chevron" aria-hidden="true"></span>
			</button>

			<div class="zag-collapsible" data-zag-collapsible hidden>
				<form class="zag-form" data-zag-form novalidate>
					<p class="zag-form__subtitle"><?php esc_html_e( 'Answer the questions below. We will craft a unique avatar for you.', 'zillha-avatar-generator' ); ?></p>

				<fieldset class="zag-fieldset" <?php echo $webhook_configured ? '' : 'disabled aria-disabled="true"'; ?>>

				<div class="zag-field">
					<label class="zag-label" for="zag-vibe"><?php esc_html_e( 'Overall vibe / aesthetic', 'zillha-avatar-generator' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
					<input class="zag-input" type="text" id="zag-vibe" name="vibe" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. cyberpunk noir, cozy fantasy', 'zillha-avatar-generator' ); ?>" />
				</div>

				<div class="zag-field">
					<label class="zag-label" for="zag-gender"><?php esc_html_e( 'Gender expression', 'zillha-avatar-generator' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
					<input class="zag-input" type="text" id="zag-gender" name="gender" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. masculine, feminine, androgynous', 'zillha-avatar-generator' ); ?>" />
				</div>

				<div class="zag-field">
					<label class="zag-label" for="zag-hair"><?php esc_html_e( 'Hair style and color', 'zillha-avatar-generator' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
					<input class="zag-input" type="text" id="zag-hair" name="hair" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. long silver braid, short red curls', 'zillha-avatar-generator' ); ?>" />
				</div>

				<div class="zag-field">
					<label class="zag-label" for="zag-outfit"><?php esc_html_e( 'Outfit or clothing style', 'zillha-avatar-generator' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
					<input class="zag-input" type="text" id="zag-outfit" name="outfit" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. leather jacket, wizard robes', 'zillha-avatar-generator' ); ?>" />
				</div>

				<div class="zag-field">
					<label class="zag-label" for="zag-background"><?php esc_html_e( 'Background / setting', 'zillha-avatar-generator' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
					<input class="zag-input" type="text" id="zag-background" name="background" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. neon-lit alley, ancient forest', 'zillha-avatar-generator' ); ?>" />
				</div>

				<div class="zag-field">
					<label class="zag-label" for="zag-mood"><?php esc_html_e( 'Mood or emotion', 'zillha-avatar-generator' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
					<input class="zag-input" type="text" id="zag-mood" name="mood" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. confident, mischievous, serene', 'zillha-avatar-generator' ); ?>" />
				</div>

				<div class="zag-field">
					<label class="zag-label" for="zag-features"><?php esc_html_e( 'Special features or accessories', 'zillha-avatar-generator' ); ?> <span class="zag-optional"><?php esc_html_e( '(optional)', 'zillha-avatar-generator' ); ?></span></label>
					<textarea class="zag-input zag-textarea" id="zag-features" name="features" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. glowing tattoos, animal companion, antique pocket watch', 'zillha-avatar-generator' ); ?>"></textarea>
				</div>

				<div class="zag-field">
					<label class="zag-label" for="zag-art-style"><?php esc_html_e( 'Art style', 'zillha-avatar-generator' ); ?></label>
					<select class="zag-input zag-select" id="zag-art-style" name="art_style">
						<option value="photorealistic"><?php esc_html_e( 'Photorealistic', 'zillha-avatar-generator' ); ?></option>
						<option value="anime"><?php esc_html_e( 'Anime', 'zillha-avatar-generator' ); ?></option>
						<option value="oil-painting"><?php esc_html_e( 'Oil painting', 'zillha-avatar-generator' ); ?></option>
						<option value="pixel-art"><?php esc_html_e( 'Pixel art', 'zillha-avatar-generator' ); ?></option>
						<option value="watercolor"><?php esc_html_e( 'Watercolor', 'zillha-avatar-generator' ); ?></option>
						<option value="comic-book"><?php esc_html_e( 'Comic book', 'zillha-avatar-generator' ); ?></option>
						<option value="3d-cgi"><?php esc_html_e( '3D / CGI', 'zillha-avatar-generator' ); ?></option>
						<option value="flat-vector"><?php esc_html_e( 'Flat vector', 'zillha-avatar-generator' ); ?></option>
						<option value="auto" selected><?php esc_html_e( 'Let AI decide', 'zillha-avatar-generator' ); ?></option>
					</select>
				</div>

				<div class="zag-form__actions">
					<button type="submit" class="zag-btn zag-btn--primary" data-zag-generate <?php echo $webhook_configured ? '' : 'disabled'; ?>>
						<span class="zag-btn__label"><?php esc_html_e( 'Generate avatar', 'zillha-avatar-generator' ); ?></span>
						<span class="zag-btn__spinner" aria-hidden="true"></span>
					</button>
				</div>

				</fieldset>

				<div class="zag-message" data-zag-form-message role="status" aria-live="polite"></div>
			</form>
			</div>

			<section class="zag-result" data-zag-result hidden>
				<h3 class="zag-result__title"><?php esc_html_e( 'Your avatar', 'zillha-avatar-generator' ); ?></h3>
				<div class="zag-result__preview">
					<img class="zag-result__image" data-zag-preview alt="<?php esc_attr_e( 'Generated avatar preview', 'zillha-avatar-generator' ); ?>" />
				</div>
				<div class="zag-result__actions">
					<a class="zag-btn zag-btn--secondary" data-zag-download href="#" download="zillha-avatar.webp">
						<?php esc_html_e( 'Download', 'zillha-avatar-generator' ); ?>
					</a>
					<button type="button" class="zag-btn zag-btn--primary" data-zag-save>
						<span class="zag-btn__label"><?php esc_html_e( 'Set as profile picture', 'zillha-avatar-generator' ); ?></span>
						<span class="zag-btn__spinner" aria-hidden="true"></span>
					</button>
					<button type="button" class="zag-btn zag-btn--ghost" data-zag-reset>
						<?php esc_html_e( 'Generate another', 'zillha-avatar-generator' ); ?>
					</button>
				</div>
				<div class="zag-message" data-zag-result-message role="status" aria-live="polite"></div>
			</section>
		</div>
		<?php
	}

	/**
	 * Replace Gravatar URLs with the saved attachment URL when the user has one set.
	 *
	 * @param string $url         Default avatar URL.
	 * @param mixed  $id_or_email Object identifying the user. May be:
	 *                            user ID, user email, WP_User, WP_Post, or WP_Comment.
	 * @param array  $args        Avatar args (size, default, etc.).
	 * @return string
	 */
	public function filter_avatar_url( $url, $id_or_email, $args = array() ) {
		$user_id = $this->resolve_user_id( $id_or_email );
		if ( ! $user_id ) {
			return $url;
		}

		$attachment_id = (int) get_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY, true );
		if ( $attachment_id <= 0 ) {
			return $url;
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return $url;
		}

		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		$size = max( 1, min( 2048, $size ) );

		$image = wp_get_attachment_image_src( $attachment_id, array( $size, $size ) );
		if ( is_array( $image ) && ! empty( $image[0] ) ) {
			return $image[0];
		}

		$full = wp_get_attachment_url( $attachment_id );
		return $full ? $full : $url;
	}

	/**
	 * Replace the entire <img> output of get_avatar() so plugins that swap the
	 * HTML at that hook (rather than at get_avatar_url) cannot override us.
	 *
	 * @param string $avatar      Default <img> markup WordPress built.
	 * @param mixed  $id_or_email User ID, email, WP_User, WP_Post, or WP_Comment.
	 * @param int    $size        Square size in pixels.
	 * @param string $default     URL for the default image (unused here).
	 * @param string $alt         Alt text for the avatar image.
	 * @param array  $args        Additional args passed to get_avatar().
	 * @return string
	 */
	public function filter_avatar_html( $avatar, $id_or_email, $size = 96, $default = '', $alt = '', $args = array() ) {
		unset( $default );

		$user_id = $this->resolve_user_id( $id_or_email );
		if ( ! $user_id ) {
			return $avatar;
		}

		$attachment_id = (int) get_user_meta( $user_id, ZILLHA_AVATAR_USER_META_KEY, true );
		if ( $attachment_id <= 0 ) {
			return $avatar;
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return $avatar;
		}

		$size = (int) $size;
		$size = max( 1, min( 2048, $size ) );

		$image = wp_get_attachment_image_src( $attachment_id, array( $size, $size ) );
		if ( is_array( $image ) && ! empty( $image[0] ) ) {
			$url = $image[0];
		} else {
			$url = wp_get_attachment_url( $attachment_id );
		}

		if ( ! $url ) {
			return $avatar;
		}

		// Only emit srcset when a distinct higher-res variant exists; otherwise
		// it would just point at $url and tell the browser to redownload it.
		$srcset_attr = '';
		$image_2x    = wp_get_attachment_image_src( $attachment_id, array( $size * 2, $size * 2 ) );
		if ( is_array( $image_2x ) && ! empty( $image_2x[0] ) && $image_2x[0] !== $url ) {
			$srcset_attr = sprintf( ' srcset="%s 2x"', esc_url( $image_2x[0] ) );
		}

		$class = array( 'avatar', 'avatar-' . $size, 'photo', 'zillha-avatar' );
		if ( is_array( $args ) && ! empty( $args['class'] ) ) {
			$extra = is_array( $args['class'] ) ? $args['class'] : array( $args['class'] );
			$class = array_merge( $class, array_map( 'strval', $extra ) );
		}

		return sprintf(
			'<img alt="%s" src="%s"%s class="%s" height="%d" width="%d" loading="lazy" decoding="async" />',
			esc_attr( (string) $alt ),
			esc_url( $url ),
			$srcset_attr,
			esc_attr( implode( ' ', array_unique( array_filter( $class ) ) ) ),
			$size,
			$size
		);
	}

	/**
	 * Resolve any of WordPress's avatar identifier types into a user ID.
	 *
	 * @param mixed $id_or_email Possibly a user ID, email string, WP_User, WP_Post, or WP_Comment.
	 * @return int 0 if no user could be resolved.
	 */
	private function resolve_user_id( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}

		if ( is_string( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? (int) $user->ID : 0;
		}

		if ( $id_or_email instanceof WP_User ) {
			return (int) $id_or_email->ID;
		}

		if ( $id_or_email instanceof WP_Post ) {
			return (int) $id_or_email->post_author;
		}

		if ( $id_or_email instanceof WP_Comment ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}
			if ( ! empty( $id_or_email->comment_author_email ) ) {
				$user = get_user_by( 'email', $id_or_email->comment_author_email );
				return $user ? (int) $user->ID : 0;
			}
			return 0;
		}

		if ( is_object( $id_or_email ) ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}
			if ( ! empty( $id_or_email->ID ) ) {
				return (int) $id_or_email->ID;
			}
			if ( ! empty( $id_or_email->comment_author_email ) ) {
				$user = get_user_by( 'email', $id_or_email->comment_author_email );
				return $user ? (int) $user->ID : 0;
			}
		}

		return 0;
	}
}
