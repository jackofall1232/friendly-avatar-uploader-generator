<?php
/**
 * Settings page for Zillha Avatar.
 *
 * @package Zillha_Avatar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds Settings → Zillha Avatar with the n8n webhook URL field, an overview
 * of both shortcodes, and copy-paste usage instructions.
 */
class Zillha_Avatar_Settings {

	const MENU_SLUG    = 'zillha-avatar';
	const SETTING_PAGE = 'zillha_avatar_settings';
	const OPTION_GROUP = 'zillha_avatar_group';

	/**
	 * Wire admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_missing_webhook_notice' ) );
		add_filter( 'plugin_action_links_' . ZILLHA_AVATAR_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Register the Settings submenu entry.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'Zillha Avatar', 'zillha-avatar' ),
			__( 'Zillha Avatar', 'zillha-avatar' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option, section, and field.
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
			__( 'AI generator webhook', 'zillha-avatar' ),
			array( $this, 'render_section_intro' ),
			self::SETTING_PAGE
		);

		add_settings_field(
			'webhook_url',
			__( 'n8n Webhook URL', 'zillha-avatar' ),
			array( $this, 'render_webhook_url_field' ),
			self::SETTING_PAGE,
			'zillha_avatar_main_section'
		);
	}

	/**
	 * Sanitize the option array. Only accepts http(s) URLs with a host.
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
		// characters that mobile clipboards often slip in. The /u regex can
		// return null on invalid UTF-8, in which case we fall back to a plain
		// trim() so we never let null leak downstream.
		$trimmed = preg_replace( '/^[\s\x{00A0}\x{200B}-\x{200F}\x{2028}\x{2029}\x{FEFF}]+|[\s\x{00A0}\x{200B}-\x{200F}\x{2028}\x{2029}\x{FEFF}]+$/u', '', $raw );
		$raw     = ( null === $trimmed ) ? trim( $raw ) : $trimmed;

		if ( '' === $raw ) {
			return $clean;
		}

		$has_valid_scheme = 0 === stripos( $raw, 'http://' ) || 0 === stripos( $raw, 'https://' );
		$host             = $has_valid_scheme ? wp_parse_url( $raw, PHP_URL_HOST ) : '';

		if ( $has_valid_scheme && is_string( $host ) && '' !== $host ) {
			$clean['webhook_url'] = esc_url_raw( $raw );
		} else {
			add_settings_error(
				ZILLHA_AVATAR_OPTION_KEY,
				'zillha_avatar_invalid_url',
				__( 'The webhook URL must be an http:// or https:// URL with a host.', 'zillha-avatar' )
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
		echo '<p>' . esc_html__( 'Configure the n8n webhook that turns the questionnaire into a WebP avatar. The manual uploader works without a webhook.', 'zillha-avatar' ) . '</p>';
	}

	/**
	 * Webhook URL input.
	 *
	 * @return void
	 */
	public function render_webhook_url_field() {
		$value = Zillha_Avatar_Plugin::get_webhook_url();
		printf(
			'<input type="url" id="zillha_avatar_webhook_url" name="%1$s[webhook_url]" value="%2$s" class="regular-text code" placeholder="https://n8n.example.com/webhook/zillha-avatar" autocomplete="off" />',
			esc_attr( ZILLHA_AVATAR_OPTION_KEY ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'The webhook must accept a text/plain POST body and respond with image/webp binary.', 'zillha-avatar' ) . '</p>';
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

			<p><?php esc_html_e( 'Zillha Avatar gives logged-in users two ways to set their profile picture: a manual upload form and an AI generator that calls an n8n webhook. Both modes share the same media-library save flow and replace Gravatar across the site.', 'zillha-avatar' ); ?></p>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::SETTING_PAGE );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Shortcodes', 'zillha-avatar' ); ?></h2>

			<h3><?php esc_html_e( 'Manual uploader', 'zillha-avatar' ); ?></h3>
			<p><?php esc_html_e( 'Drop this shortcode on any page where logged-in users should upload their own image. Uploaded files are validated, resized and cropped to a 400×400 square WebP, and saved to the media library.', 'zillha-avatar' ); ?></p>
			<p><code>[zillha_avatar_uploader]</code></p>

			<h3><?php esc_html_e( 'AI generator', 'zillha-avatar' ); ?></h3>
			<p><?php esc_html_e( 'Drop this shortcode on any page where logged-in users should answer the avatar questionnaire. The plugin POSTs the answers to your webhook, previews the returned WebP, then lets the user download it or set it as their profile picture.', 'zillha-avatar' ); ?></p>
			<p><code>[zillha_avatar_generator]</code></p>

			<p><em><?php esc_html_e( 'Both shortcodes can be placed on the same page. Guests see nothing — they are rendered for logged-in users only.', 'zillha-avatar' ); ?></em></p>

			<h2><?php esc_html_e( 'How it works', 'zillha-avatar' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Manual upload: the user picks an image, the browser sends it to admin-ajax.php, and the server resizes and crops it to a 400×400 WebP, sideloads it into the media library, and stores the attachment ID in user meta.', 'zillha-avatar' ); ?></li>
				<li><?php esc_html_e( 'AI generate: the user fills out the questionnaire, the server POSTs a plain-text payload to the webhook (120 second timeout), the returned WebP is held in a private pending file, and the user previews it before deciding to save.', 'zillha-avatar' ); ?></li>
				<li><?php esc_html_e( 'Whichever flow runs last wins: the previous attachment is removed from the media library and replaced with the new one. The avatar is shown anywhere WordPress calls get_avatar() or get_avatar_url().', 'zillha-avatar' ); ?></li>
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

		if ( '' !== Zillha_Avatar_Plugin::get_webhook_url() ) {
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
				<strong><?php esc_html_e( 'Zillha Avatar', 'zillha-avatar' ); ?>:</strong>
				<?php
				printf(
					/* translators: %s: settings page link. */
					esc_html__( 'No AI generator webhook is configured. The manual uploader still works. %s', 'zillha-avatar' ),
					'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Configure it now', 'zillha-avatar' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Add a Settings link to the plugins-list row.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ) . '">' . esc_html__( 'Settings', 'zillha-avatar' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}
