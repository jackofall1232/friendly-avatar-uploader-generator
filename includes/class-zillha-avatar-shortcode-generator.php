<?php
/**
 * [zillha_avatar_generator] shortcode — AI questionnaire + generate flow.
 *
 * @package Zillha_Avatar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the AI-generator form. Returns nothing for guests; for
 * logged-in users it enqueues the shared CSS/JS bundle (registered once,
 * via Zillha_Avatar_Plugin::enqueue_assets()) and prints the form.
 */
class Zillha_Avatar_Shortcode_Generator {

	const SHORTCODE = 'zillha_avatar_generator';

	/**
	 * Wire the shortcode.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Render the questionnaire markup. Returns empty string for guests.
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
		$webhook_configured = '' !== Zillha_Avatar_Plugin::get_webhook_url();
		$uid                = wp_unique_id( 'zag-gen-' );
		?>
		<div class="zag-wrap zag-wrap--generator" data-zag-generator id="<?php echo esc_attr( $uid ); ?>">
			<?php if ( ! $webhook_configured ) : ?>
				<div class="zag-alert zag-alert--warning" role="alert">
					<?php esc_html_e( 'Avatar generation is not configured yet. Please contact the site administrator.', 'zillha-avatar' ); ?>
				</div>
			<?php endif; ?>

			<button type="button" class="zag-toggle" data-zag-toggle aria-expanded="false">
				<span class="zag-toggle__label"><?php esc_html_e( 'Generate your Zillha avatar', 'zillha-avatar' ); ?></span>
				<span class="zag-toggle__chevron" aria-hidden="true"></span>
			</button>

			<div class="zag-collapsible" data-zag-collapsible hidden>
				<form class="zag-form" data-zag-form novalidate>
					<p class="zag-form__subtitle"><?php esc_html_e( 'Answer the questions below. We will craft a unique avatar for you.', 'zillha-avatar' ); ?></p>

					<fieldset class="zag-fieldset" <?php echo $webhook_configured ? '' : 'disabled aria-disabled="true"'; ?>>

						<div class="zag-field">
							<label class="zag-label" for="<?php echo esc_attr( $uid . '-vibe' ); ?>"><?php esc_html_e( 'Overall vibe / aesthetic', 'zillha-avatar' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
							<input class="zag-input" type="text" id="<?php echo esc_attr( $uid . '-vibe' ); ?>" name="vibe" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. cyberpunk noir, cozy fantasy', 'zillha-avatar' ); ?>" />
						</div>

						<div class="zag-field">
							<label class="zag-label" for="<?php echo esc_attr( $uid . '-gender' ); ?>"><?php esc_html_e( 'Gender expression', 'zillha-avatar' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
							<input class="zag-input" type="text" id="<?php echo esc_attr( $uid . '-gender' ); ?>" name="gender" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. masculine, feminine, androgynous', 'zillha-avatar' ); ?>" />
						</div>

						<div class="zag-field">
							<label class="zag-label" for="<?php echo esc_attr( $uid . '-hair' ); ?>"><?php esc_html_e( 'Hair style and color', 'zillha-avatar' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
							<input class="zag-input" type="text" id="<?php echo esc_attr( $uid . '-hair' ); ?>" name="hair" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. long silver braid, short red curls', 'zillha-avatar' ); ?>" />
						</div>

						<div class="zag-field">
							<label class="zag-label" for="<?php echo esc_attr( $uid . '-outfit' ); ?>"><?php esc_html_e( 'Outfit or clothing style', 'zillha-avatar' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
							<input class="zag-input" type="text" id="<?php echo esc_attr( $uid . '-outfit' ); ?>" name="outfit" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. leather jacket, wizard robes', 'zillha-avatar' ); ?>" />
						</div>

						<div class="zag-field">
							<label class="zag-label" for="<?php echo esc_attr( $uid . '-background' ); ?>"><?php esc_html_e( 'Background / setting', 'zillha-avatar' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
							<input class="zag-input" type="text" id="<?php echo esc_attr( $uid . '-background' ); ?>" name="background" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. neon-lit alley, ancient forest', 'zillha-avatar' ); ?>" />
						</div>

						<div class="zag-field">
							<label class="zag-label" for="<?php echo esc_attr( $uid . '-mood' ); ?>"><?php esc_html_e( 'Mood or emotion', 'zillha-avatar' ); ?> <span class="zag-required" aria-hidden="true">*</span></label>
							<input class="zag-input" type="text" id="<?php echo esc_attr( $uid . '-mood' ); ?>" name="mood" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. confident, mischievous, serene', 'zillha-avatar' ); ?>" />
						</div>

						<div class="zag-field">
							<label class="zag-label" for="<?php echo esc_attr( $uid . '-features' ); ?>"><?php esc_html_e( 'Special features or accessories', 'zillha-avatar' ); ?> <span class="zag-optional"><?php esc_html_e( '(optional)', 'zillha-avatar' ); ?></span></label>
							<textarea class="zag-input zag-textarea" id="<?php echo esc_attr( $uid . '-features' ); ?>" name="features" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. glowing tattoos, animal companion, antique pocket watch', 'zillha-avatar' ); ?>"></textarea>
						</div>

						<div class="zag-field">
							<label class="zag-label" for="<?php echo esc_attr( $uid . '-art' ); ?>"><?php esc_html_e( 'Art style', 'zillha-avatar' ); ?></label>
							<select class="zag-input zag-select" id="<?php echo esc_attr( $uid . '-art' ); ?>" name="art_style">
								<option value="photorealistic"><?php esc_html_e( 'Photorealistic', 'zillha-avatar' ); ?></option>
								<option value="anime"><?php esc_html_e( 'Anime', 'zillha-avatar' ); ?></option>
								<option value="oil-painting"><?php esc_html_e( 'Oil painting', 'zillha-avatar' ); ?></option>
								<option value="pixel-art"><?php esc_html_e( 'Pixel art', 'zillha-avatar' ); ?></option>
								<option value="watercolor"><?php esc_html_e( 'Watercolor', 'zillha-avatar' ); ?></option>
								<option value="comic-book"><?php esc_html_e( 'Comic book', 'zillha-avatar' ); ?></option>
								<option value="3d-cgi"><?php esc_html_e( '3D / CGI', 'zillha-avatar' ); ?></option>
								<option value="flat-vector"><?php esc_html_e( 'Flat vector', 'zillha-avatar' ); ?></option>
								<option value="auto" selected><?php esc_html_e( 'Let AI decide', 'zillha-avatar' ); ?></option>
							</select>
						</div>

						<div class="zag-form__actions">
							<button type="submit" class="zag-btn zag-btn--primary" data-zag-generate <?php echo $webhook_configured ? '' : 'disabled'; ?>>
								<span class="zag-btn__label"><?php esc_html_e( 'Generate avatar', 'zillha-avatar' ); ?></span>
								<span class="zag-btn__spinner" aria-hidden="true"></span>
							</button>
						</div>

					</fieldset>

					<div class="zag-message" data-zag-form-message role="status" aria-live="polite"></div>
				</form>
			</div>

			<section class="zag-result" data-zag-result hidden>
				<h3 class="zag-result__title"><?php esc_html_e( 'Your avatar', 'zillha-avatar' ); ?></h3>
				<div class="zag-result__preview">
					<img class="zag-result__image" data-zag-preview alt="<?php esc_attr_e( 'Generated avatar preview', 'zillha-avatar' ); ?>" />
				</div>
				<div class="zag-result__actions">
					<a class="zag-btn zag-btn--secondary" data-zag-download href="#" download="zillha-avatar.webp">
						<?php esc_html_e( 'Download', 'zillha-avatar' ); ?>
					</a>
					<button type="button" class="zag-btn zag-btn--primary" data-zag-save>
						<span class="zag-btn__label"><?php esc_html_e( 'Set as profile picture', 'zillha-avatar' ); ?></span>
						<span class="zag-btn__spinner" aria-hidden="true"></span>
					</button>
					<button type="button" class="zag-btn zag-btn--ghost" data-zag-reset>
						<?php esc_html_e( 'Generate another', 'zillha-avatar' ); ?>
					</button>
				</div>
				<div class="zag-message" data-zag-result-message role="status" aria-live="polite"></div>
			</section>
		</div>
		<?php
	}
}
