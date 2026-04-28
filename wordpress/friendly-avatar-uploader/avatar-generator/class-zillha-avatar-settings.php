<?php
/**
 * Settings page for the ZillHa Avatar Generator plugin.
 *
 * @package ZillHa_Avatar_Generator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the admin settings screen.
 */
class Zillha_Avatar_Settings {

	const MENU_SLUG    = 'zillha-avatar-generator';
	const SETTING_PAGE = 'zillha_avatar_generator_settings';
	const OPTION_GROUP = 'zillha_avatar_generator_group';

	/**
	 * Register all hooks for the settings screen.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_missing_webhook_notice' ) );
		add_filter( 'plugin_action_links_' . ZILLHA_AVATAR_GENERATOR_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Add the Settings menu entry.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'ZillHa Avatar Generator', 'zillha-avatar-generator' ),
			__( 'ZillHa Avatars', 'zillha-avatar-generator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option, section, and field with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			ZILLHA_AVATAR_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => array(
					'webhook_url' => '',
				),
			)
		);

		add_settings_section(
			'zillha_avatar_main_section',
			__( 'Webhook configuration', 'zillha-avatar-generator' ),
			array( $this, 'render_section_intro' ),
			self::SETTING_PAGE
		);

		add_settings_field(
			'webhook_url',
			__( 'n8n Webhook URL', 'zillha-avatar-generator' ),
			array( $this, 'render_webhook_url_field' ),
			self::SETTING_PAGE,
			'zillha_avatar_main_section'
		);
	}

	/**
	 * Sanitize the option array.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$clean = array(
			'webhook_url' => '',
		);

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		$raw = isset( $input['webhook_url'] ) ? (string) $input['webhook_url'] : '';

		// Strip standard whitespace plus zero-width, non-breaking, and BOM
		// characters that mobile clipboards often prepend or append. The /u
		// regex can return null on invalid UTF-8, so fall back to an ASCII
		// trim in that case rather than letting null leak downstream.
		$trimmed = preg_replace( '/^[\s\x{00A0}\x{200B}-\x{200F}\x{2028}\x{2029}\x{FEFF}]+|[\s\x{00A0}\x{200B}-\x{200F}\x{2028}\x{2029}\x{FEFF}]+$/u', '', $raw );
		$raw     = ( null === $trimmed ) ? trim( $raw ) : $trimmed;

		if ( '' === $raw ) {
			return $clean;
		}

		$has_valid_scheme = 0 === stripos( $raw, 'http://' ) || 0 === stripos( $raw, 'https://' );
		$host             = $has_valid_scheme ? wp_parse_url( $raw, PHP_URL_HOST ) : '';

		if ( $has_valid_scheme && is_string( $host ) && '' !== $host ) {
			// Stored verbatim — only ever passed to wp_remote_post(), never echoed.
			$clean['webhook_url'] = $raw;
		} else {
			add_settings_error(
				ZILLHA_AVATAR_OPTION_KEY,
				'zillha_avatar_invalid_url',
				__( 'The webhook URL must be an http:// or https:// URL with a host.', 'zillha-avatar-generator' )
			);
		}

		return $clean;
	}

	/**
	 * Section description.
	 *
	 * @return void
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Configure the n8n webhook that turns questionnaire answers into a WebP avatar.', 'zillha-avatar-generator' ) . '</p>';
	}

	/**
	 * Webhook URL input.
	 *
	 * @return void
	 */
	public function render_webhook_url_field() {
		$value = Zillha_Avatar_Generator::get_webhook_url();
		printf(
			'<input type="url" id="zillha_avatar_webhook_url" name="%1$s[webhook_url]" value="%2$s" class="regular-text code" placeholder="https://n8n.example.com/webhook/zillha-avatar" autocomplete="off" />',
			esc_attr( ZILLHA_AVATAR_OPTION_KEY ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Your n8n webhook must accept text/plain POST bodies and respond with image/webp binary.', 'zillha-avatar-generator' ) . '</p>';
	}

	/**
	 * Render the full settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::SETTING_PAGE );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Shortcode usage', 'zillha-avatar-generator' ); ?></h2>
			<p><?php esc_html_e( 'Place the following shortcode on any page or post visible to logged-in users:', 'zillha-avatar-generator' ); ?></p>
			<p><code>[zillha_avatar_generator]</code></p>
			<p><?php esc_html_e( 'Guests will see nothing — the form is rendered for logged-in users only.', 'zillha-avatar-generator' ); ?></p>

			<h2><?php esc_html_e( 'How it works', 'zillha-avatar-generator' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'A logged-in user fills out the questionnaire.', 'zillha-avatar-generator' ); ?></li>
				<li><?php esc_html_e( 'The plugin POSTs a plain-text payload to your webhook with a 120 second timeout.', 'zillha-avatar-generator' ); ?></li>
				<li><?php esc_html_e( 'The returned WebP binary is held server-side in a 10-minute transient and previewed via a base64 data URI.', 'zillha-avatar-generator' ); ?></li>
				<li><?php esc_html_e( 'The user explicitly chooses to download the image or save it as their profile picture.', 'zillha-avatar-generator' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Show an admin notice on plugin pages when the webhook URL is missing.
	 *
	 * @return void
	 */
	public function maybe_show_missing_webhook_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( '' !== Zillha_Avatar_Generator::get_webhook_url() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$is_plugin_screen = ( 'plugins' === $screen->id ) || ( 'settings_page_' . self::MENU_SLUG === $screen->id );
		if ( ! $is_plugin_screen ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=' . self::MENU_SLUG );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'ZillHa Avatar Generator', 'zillha-avatar-generator' ); ?>:</strong>
				<?php
				printf(
					/* translators: %s: settings page URL. */
					esc_html__( 'No webhook URL is configured. %s', 'zillha-avatar-generator' ),
					'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Configure it now', 'zillha-avatar-generator' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Add a Settings link from the plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ) . '">' . esc_html__( 'Settings', 'zillha-avatar-generator' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}
