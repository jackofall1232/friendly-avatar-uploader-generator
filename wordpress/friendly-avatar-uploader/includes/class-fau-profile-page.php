<?php
/**
 * Full-page profile shortcode and its companion admin settings page.
 *
 * @package FriendlyAvatarUploader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class FAU_Profile_Page
 *
 * Registers the [friendly_profile_page] shortcode, which renders a
 * full-width profile section with avatar uploader, member metadata and
 * stats. Reuses the existing fau_upload_avatar / fau_remove_avatar AJAX
 * endpoints — no new handlers added here.
 *
 * Also exposes a small Settings page so an admin can pick default colors
 * via the WordPress color picker; saved values become the shortcode
 * defaults but can still be overridden per-instance via shortcode atts.
 */
class FAU_Profile_Page {

	/**
	 * Option name that stores the saved color defaults.
	 */
	const OPTION_KEY = 'fau_profile_page_colors';

	/**
	 * Hard-coded fallback defaults — the safety net when no option is set.
	 *
	 * @return array
	 */
	protected function fallback_defaults() {
		return array(
			'accent'      => '#4a90d9',
			'bg_from'     => '#1e1e2e',
			'bg_to'       => '#0a0a0f',
			'text_color'  => '#f0f0f0',
			'muted_color' => '#999999',
		);
	}

	/**
	 * Constructor: register shortcode, settings, and admin menu.
	 */
	public function __construct() {
		add_shortcode( 'friendly_profile_page', array( $this, 'render' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Render the [friendly_profile_page] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$saved    = (array) get_option( self::OPTION_KEY, array() );
		$fallback = $this->fallback_defaults();

		$defaults = array(
			'accent'      => isset( $saved['accent'] ) && $saved['accent'] ? $saved['accent'] : $fallback['accent'],
			'bg_from'     => isset( $saved['bg_from'] ) && $saved['bg_from'] ? $saved['bg_from'] : $fallback['bg_from'],
			'bg_to'       => isset( $saved['bg_to'] ) && $saved['bg_to'] ? $saved['bg_to'] : $fallback['bg_to'],
			'text_color'  => isset( $saved['text_color'] ) && $saved['text_color'] ? $saved['text_color'] : $fallback['text_color'],
			'muted_color' => isset( $saved['muted_color'] ) && $saved['muted_color'] ? $saved['muted_color'] : $fallback['muted_color'],
			'show_since'  => 'true',
			'show_stats'  => 'true',
		);

		$atts = shortcode_atts( $defaults, $atts, 'friendly_profile_page' );

		if ( ! is_user_logged_in() ) {
			return '<p class="fau-error">' . esc_html__( 'You must be logged in to view your profile.', 'friendly-avatar-uploader' ) . '</p>';
		}

		$accent      = sanitize_hex_color( $atts['accent'] );
		$bg_from     = sanitize_hex_color( $atts['bg_from'] );
		$bg_to       = sanitize_hex_color( $atts['bg_to'] );
		$text_color  = sanitize_hex_color( $atts['text_color'] );
		$muted_color = sanitize_hex_color( $atts['muted_color'] );

		// Re-apply fallbacks if sanitization rejected anything.
		if ( ! $accent ) {
			$accent = $fallback['accent'];
		}
		if ( ! $bg_from ) {
			$bg_from = $fallback['bg_from'];
		}
		if ( ! $bg_to ) {
			$bg_to = $fallback['bg_to'];
		}
		if ( ! $text_color ) {
			$text_color = $fallback['text_color'];
		}
		if ( ! $muted_color ) {
			$muted_color = $fallback['muted_color'];
		}

		$show_since = ( 'true' === (string) $atts['show_since'] );
		$show_stats = ( 'true' === (string) $atts['show_stats'] );

		$user          = wp_get_current_user();
		$user_id       = (int) $user->ID;
		$display_name  = $user->display_name ? $user->display_name : $user->user_login;
		$user_login    = $user->user_login;
		$post_count    = (int) count_user_posts( $user_id, 'post', true );
		$comment_count = (int) get_comments(
			array(
				'user_id' => $user_id,
				'status'  => 'approve',
				'count'   => true,
			)
		);
		$member_since  = wp_date( 'F Y', strtotime( $user->user_registered ) );

		$custom_url = get_user_meta( $user_id, FAU_META_KEY, true );
		$avatar_url = $custom_url
			? $custom_url
			: get_avatar_url( $user_id, array( 'size' => 280, 'default' => 'mystery' ) );
		$has_custom = ! empty( $custom_url );

		$accent_rgb = $this->hex_to_rgb_triplet( $accent );
		$uid        = uniqid( 'fau_' );
		$file_id    = 'fau-profile-file-' . $uid;
		$ajax_url   = admin_url( 'admin-ajax.php' );

		$inline_vars = sprintf(
			'--fau-accent: %1$s; --fau-accent-rgb: %2$s; --fau-bg-from: %3$s; --fau-bg-to: %4$s; --fau-text: %5$s; --fau-muted: %6$s;',
			$accent,
			$accent_rgb,
			$bg_from,
			$bg_to,
			$text_color,
			$muted_color
		);

		ob_start();
		?>
		<section class="fau-profile-page" style="<?php echo esc_attr( $inline_vars ); ?>" data-fau-uid="<?php echo esc_attr( $uid ); ?>">
			<?php echo $this->styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<form class="fau-profile-page__form" enctype="multipart/form-data">
				<input type="hidden" name="action" value="fau_upload_avatar" />
				<?php wp_nonce_field( 'fau_upload_avatar', 'fau_nonce' ); ?>

				<label for="<?php echo esc_attr( $file_id ); ?>" class="fau-profile-page__avatar-label">
					<img
						class="fau-profile-page__avatar"
						src="<?php echo esc_url( $avatar_url ); ?>"
						alt="<?php echo esc_attr( sprintf( /* translators: %s: user display name. */ __( 'Avatar for %s', 'friendly-avatar-uploader' ), $display_name ) ); ?>"
					/>
					<span class="fau-profile-page__camera" aria-hidden="true">&#128247;</span>
					<input
						id="<?php echo esc_attr( $file_id ); ?>"
						type="file"
						name="fau_avatar"
						class="fau-profile-page__file"
						accept="image/jpeg,image/png,image/gif,image/webp"
					/>
				</label>

				<h2 class="fau-profile-page__name"><?php echo esc_html( $display_name ); ?></h2>
				<p class="fau-profile-page__username">@<?php echo esc_html( $user_login ); ?></p>

				<?php if ( $show_since ) : ?>
					<p class="fau-profile-page__since">
						<?php
						printf(
							/* translators: %s: month and year the user registered. */
							esc_html__( 'Member since: %s', 'friendly-avatar-uploader' ),
							esc_html( $member_since )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( $show_stats ) : ?>
					<div class="fau-profile-page__stats">
						<div class="fau-profile-page__stat">
							<span class="fau-profile-page__stat-value"><?php echo esc_html( number_format_i18n( $post_count ) ); ?></span>
							<span class="fau-profile-page__stat-label"><?php esc_html_e( 'Posts', 'friendly-avatar-uploader' ); ?></span>
						</div>
						<div class="fau-profile-page__stat">
							<span class="fau-profile-page__stat-value"><?php echo esc_html( number_format_i18n( $comment_count ) ); ?></span>
							<span class="fau-profile-page__stat-label"><?php esc_html_e( 'Comments', 'friendly-avatar-uploader' ); ?></span>
						</div>
					</div>
				<?php endif; ?>

				<div class="fau-profile-page__panel">
					<div class="fau-profile-page__actions">
						<button type="button" class="fau-profile-page__btn fau-profile-page__btn--primary" data-fau-file-target="<?php echo esc_attr( $file_id ); ?>">
							<?php esc_html_e( 'Upload Avatar', 'friendly-avatar-uploader' ); ?>
						</button>
						<?php if ( $has_custom ) : ?>
							<button type="button" class="fau-profile-page__btn fau-profile-page__btn--secondary fau-profile-page__remove">
								<?php esc_html_e( 'Remove', 'friendly-avatar-uploader' ); ?>
							</button>
						<?php endif; ?>
					</div>
					<div class="fau-profile-page__message" aria-live="polite"></div>
				</div>
			</form>

			<?php echo FAU_Shortcode::fau_crop_modal_html( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $this->script( $ajax_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inline CSS scoped to .fau-profile-page.
	 *
	 * Emitted only on the first render per request — subsequent shortcode
	 * instances on the same page reuse the already-output stylesheet.
	 *
	 * @return string
	 */
	protected function styles() {
		static $emitted = false;
		if ( $emitted ) {
			return '';
		}
		$emitted = true;
		ob_start();
		?>
		<style>
			.fau-profile-page {
				width: 100%;
				min-height: 480px;
				padding: 48px 24px;
				box-sizing: border-box;
				display: flex;
				flex-direction: column;
				align-items: center;
				justify-content: center;
				gap: 16px;
				background: linear-gradient(180deg, var(--fau-bg-from), var(--fau-bg-to));
				color: var(--fau-text);
				font-family: inherit;
				text-align: center;
			}
			.fau-profile-page * { box-sizing: border-box; }
			.fau-profile-page .fau-profile-page__form {
				display: flex;
				flex-direction: column;
				align-items: center;
				gap: 16px;
				width: 100%;
				max-width: 480px;
				margin: 0;
			}
			.fau-profile-page .fau-profile-page__avatar-label {
				position: relative;
				display: inline-block;
				width: 140px;
				height: 140px;
				border-radius: 50%;
				cursor: pointer;
				overflow: hidden;
				box-shadow:
					0 0 0 3px var(--fau-accent),
					0 0 0 6px rgba(var(--fau-accent-rgb), 0.2);
				transition: transform 0.2s ease;
			}
			.fau-profile-page .fau-profile-page__avatar-label:hover { transform: scale(1.02); }
			.fau-profile-page .fau-profile-page__avatar-label:focus-within {
				outline: 2px solid var(--fau-accent);
				outline-offset: 4px;
			}
			.fau-profile-page .fau-profile-page__avatar {
				display: block;
				width: 100%;
				height: 100%;
				object-fit: cover;
				border-radius: 50%;
				background: rgba(0, 0, 0, 0.3);
			}
			.fau-profile-page .fau-profile-page__camera {
				position: absolute;
				inset: 0;
				display: flex;
				align-items: center;
				justify-content: center;
				font-size: 32px;
				background: rgba(0, 0, 0, 0.45);
				color: #fff;
				opacity: 0;
				transition: opacity 0.2s ease;
				pointer-events: none;
			}
			.fau-profile-page .fau-profile-page__avatar-label:hover .fau-profile-page__camera,
			.fau-profile-page .fau-profile-page__avatar-label:focus-within .fau-profile-page__camera {
				opacity: 1;
			}
			.fau-profile-page .fau-profile-page__file {
				position: absolute;
				width: 1px;
				height: 1px;
				padding: 0;
				margin: -1px;
				overflow: hidden;
				clip: rect(0, 0, 0, 0);
				white-space: nowrap;
				border: 0;
			}
			.fau-profile-page .fau-profile-page__name {
				margin: 8px 0 0;
				font-size: 28px;
				font-weight: 600;
				color: var(--fau-text);
				line-height: 1.2;
			}
			.fau-profile-page .fau-profile-page__username {
				margin: 0;
				font-size: 15px;
				color: var(--fau-muted);
			}
			.fau-profile-page .fau-profile-page__since {
				margin: 4px 0 0;
				padding: 6px 14px;
				font-size: 13px;
				color: var(--fau-muted);
				background: rgba(255, 255, 255, 0.04);
				border: 1px solid rgba(var(--fau-accent-rgb), 0.25);
				border-radius: 999px;
			}
			.fau-profile-page .fau-profile-page__stats {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 12px;
				width: 100%;
				max-width: 320px;
				margin-top: 8px;
			}
			.fau-profile-page .fau-profile-page__stat {
				display: flex;
				flex-direction: column;
				align-items: center;
				gap: 2px;
				padding: 14px 8px;
				background: rgba(0, 0, 0, 0.25);
				border: 1px solid rgba(var(--fau-accent-rgb), 0.25);
				border-radius: 10px;
			}
			.fau-profile-page .fau-profile-page__stat-value {
				font-size: 22px;
				font-weight: 700;
				color: var(--fau-text);
				line-height: 1.1;
			}
			.fau-profile-page .fau-profile-page__stat-label {
				font-size: 12px;
				text-transform: uppercase;
				letter-spacing: 0.08em;
				color: var(--fau-muted);
			}
			.fau-profile-page .fau-profile-page__panel {
				width: 100%;
				max-width: 360px;
				margin-top: 12px;
				padding: 16px;
				background: rgba(0, 0, 0, 0.25);
				border: 1px solid rgba(var(--fau-accent-rgb), 0.25);
				border-radius: 10px;
			}
			.fau-profile-page .fau-profile-page__actions {
				display: flex;
				flex-wrap: wrap;
				justify-content: center;
				gap: 8px;
			}
			.fau-profile-page .fau-profile-page__btn {
				display: inline-block;
				padding: 10px 18px;
				border-radius: 6px;
				border: 1px solid var(--fau-accent);
				background: var(--fau-accent);
				color: #fff;
				font-weight: 600;
				font-size: 14px;
				line-height: 1.2;
				cursor: pointer;
				transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
			}
			.fau-profile-page .fau-profile-page__btn:hover {
				background: rgba(var(--fau-accent-rgb), 0.85);
				border-color: rgba(var(--fau-accent-rgb), 0.85);
			}
			.fau-profile-page .fau-profile-page__btn--secondary {
				background: transparent;
				color: var(--fau-accent);
			}
			.fau-profile-page .fau-profile-page__btn--secondary:hover {
				background: rgba(var(--fau-accent-rgb), 0.15);
				color: var(--fau-accent);
			}
			.fau-profile-page .fau-profile-page__btn[disabled] {
				opacity: 0.6;
				cursor: not-allowed;
			}
			.fau-profile-page .fau-profile-page__message {
				margin-top: 10px;
				min-height: 1.4em;
				font-size: 14px;
				color: var(--fau-muted);
			}
			.fau-profile-page .fau-profile-page__message.is-success { color: #5ee08a; }
			.fau-profile-page .fau-profile-page__message.is-error { color: #ff6b6b; }

			.fau-crop-modal {
				position: fixed;
				inset: 0;
				z-index: 99999;
				display: flex;
				align-items: center;
				justify-content: center;
			}
			.fau-crop-modal[hidden] { display: none; }
			.fau-crop-modal__backdrop {
				position: absolute;
				inset: 0;
				background: rgba(0,0,0,0.82);
				cursor: pointer;
			}
			.fau-crop-modal__box {
				position: relative;
				background: #1a1a1a;
				border: 1px solid rgba(255,255,255,0.12);
				border-radius: 8px;
				padding: 1.5rem;
				max-width: 480px;
				width: 94vw;
				z-index: 1;
				display: flex;
				flex-direction: column;
				gap: 1rem;
			}
			.fau-crop-modal__title {
				margin: 0;
				font-size: 1.1rem;
				color: #f0f0f0;
				text-align: center;
			}
			.fau-crop-modal__stage {
				width: 100%;
				max-height: 340px;
				overflow: hidden;
				display: flex;
				align-items: center;
				justify-content: center;
				position: relative;
			}
			.fau-crop-modal__stage .jcrop-holder { margin: 0 auto; }
			.fau-crop-modal__img { max-width: 100%; max-height: 340px; height: auto; display: block; }
			.fau-crop-modal__hint {
				margin: 0;
				font-size: 0.8rem;
				color: #888;
				text-align: center;
			}
			.fau-crop-modal__actions {
				display: flex;
				gap: .5rem;
				justify-content: center;
			}
			.fau-crop-btn {
				padding: .5rem 1.4rem;
				border: none;
				border-radius: 4px;
				font-size: .9rem;
				font-weight: 600;
				cursor: pointer;
				letter-spacing: .05em;
				text-transform: uppercase;
				transition: opacity .15s;
			}
			.fau-crop-btn:hover { opacity: .85; }
			.fau-profile-page .fau-crop-btn--confirm { background: var(--fau-accent); color: #fff; }
			.fau-crop-btn--cancel  { background: #333; color: #ccc; }
		</style>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inline JS that wires up live preview, AJAX upload, and AJAX remove.
	 *
	 * Emitted only on the first render per request — the script already
	 * binds every `.fau-profile-page` it finds, so one copy handles all
	 * instances on the page.
	 *
	 * @param string $ajax_url Resolved admin-ajax.php URL.
	 * @return string
	 */
	protected function script( $ajax_url ) {
		static $emitted = false;
		if ( $emitted ) {
			return '';
		}
		$emitted = true;
		ob_start();
		?>
		<script>
		(function () {
			var roots = document.querySelectorAll('.fau-profile-page');
			if ( ! roots.length ) { return; }
			var ajaxUrl     = <?php echo wp_json_encode( $ajax_url ); ?>;
			var removeNonce = <?php echo wp_json_encode( wp_create_nonce( 'fau_remove_avatar' ) ); ?>;
			var targetSize  = <?php echo (int) FAU_TARGET_SIZE; ?>;
			var $           = ( typeof window.jQuery !== 'undefined' ) ? window.jQuery : null;
			var hasJcrop    = !! ( $ && $.fn && $.fn.Jcrop );

			roots.forEach(function (root) {
				if ( root.dataset.fauProfileBound ) { return; }
				root.dataset.fauProfileBound = '1';

				var form    = root.querySelector('.fau-profile-page__form');
				var fileIn  = root.querySelector('.fau-profile-page__file');
				var preview = root.querySelector('.fau-profile-page__avatar');
				var msg     = root.querySelector('.fau-profile-page__message');
				var actions = root.querySelector('.fau-profile-page__actions');
				var upload  = root.querySelector('.fau-profile-page__btn--primary');

				var modal      = root.querySelector('.fau-crop-modal');
				var modalImg   = modal ? modal.querySelector('.fau-crop-modal__img') : null;
				var btnConfirm = modal ? modal.querySelector('.fau-crop-btn--confirm') : null;
				var btnCancel  = modal ? modal.querySelector('.fau-crop-btn--cancel') : null;
				var backdrop   = modal ? modal.querySelector('.fau-crop-modal__backdrop') : null;
				var nonceField = form ? form.querySelector('input[name="fau_nonce"]') : null;
				var jcropApi   = null;
				var srcNaturalW = 0;
				var srcNaturalH = 0;

				function setMessage(text, kind) {
					if ( ! msg ) { return; }
					msg.textContent = text || '';
					msg.classList.remove('is-success', 'is-error');
					if ( kind ) { msg.classList.add('is-' + kind); }
				}

				function setBusy(busy) {
					if ( upload ) { upload.disabled = !! busy; }
					if ( fileIn ) { fileIn.disabled = !! busy; }
				}

				function uploadBlob(blob, previewUrl) {
					if ( previewUrl && preview ) { preview.src = previewUrl; }
					var data = new FormData();
					data.append('action', 'fau_upload_avatar');
					if ( nonceField ) { data.append('fau_nonce', nonceField.value); }
					data.append('fau_avatar', blob, 'avatar-crop.jpg');

					setBusy(true);
					setMessage(<?php echo wp_json_encode( __( 'Uploading…', 'friendly-avatar-uploader' ) ); ?>, '');

					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							setBusy(false);
							if ( res && res.success && res.data && res.data.url ) {
								var url = res.data.url;
								preview.src = url + ( url.indexOf('?') === -1 ? '?' : '&' ) + 't=' + Date.now();
								setMessage(res.data.message || '', 'success');
								if ( fileIn ) { fileIn.value = ''; }
								if ( ! root.querySelector('.fau-profile-page__remove') ) {
									var rm = document.createElement('button');
									rm.type = 'button';
									rm.className = 'fau-profile-page__btn fau-profile-page__btn--secondary fau-profile-page__remove';
									rm.textContent = <?php echo wp_json_encode( __( 'Remove', 'friendly-avatar-uploader' ) ); ?>;
									actions.appendChild(rm);
									bindRemove(rm);
								}
							} else {
								var errMsg = (res && res.data && res.data.message) ? res.data.message : <?php echo wp_json_encode( __( 'Upload failed.', 'friendly-avatar-uploader' ) ); ?>;
								if ( fileIn ) { fileIn.value = ''; }
								setMessage(errMsg, 'error');
							}
						})
						.catch(function () {
							setBusy(false);
							if ( fileIn ) { fileIn.value = ''; }
							setMessage(<?php echo wp_json_encode( __( 'Network error. Please try again.', 'friendly-avatar-uploader' ) ); ?>, 'error');
						});
				}

				function openModal(dataUrl) {
					modalImg.onload = function () {
						modal.removeAttribute('hidden');
						srcNaturalW = modalImg.naturalWidth;
						srcNaturalH = modalImg.naturalHeight;
						var imgW = modalImg.width || modalImg.offsetWidth;
						var imgH = modalImg.height || modalImg.offsetHeight;
						var selSize = Math.round( Math.min(imgW, imgH) * 0.8 );
						var selX = Math.round( (imgW - selSize) / 2 );
						var selY = Math.round( (imgH - selSize) / 2 );

						$(modalImg).Jcrop(
							{
								aspectRatio: 1,
								setSelect: [ selX, selY, selX + selSize, selY + selSize ],
								bgColor: 'black',
								bgOpacity: 0.5
							},
							function () {
								jcropApi = this;
							}
						);
					};
					modalImg.src = dataUrl;
				}

				function closeModal() {
					if ( jcropApi ) {
						try { jcropApi.destroy(); } catch (e) {}
						jcropApi = null;
					}
					srcNaturalW = 0;
					srcNaturalH = 0;
					if ( modal ) { modal.setAttribute('hidden', ''); }
					if ( modalImg ) { modalImg.removeAttribute('src'); }
				}

				function applyCrop() {
					if ( ! modalImg || ! jcropApi ) { closeModal(); return; }
					var coords = jcropApi.tellSelect();
					if ( ! coords || ! coords.w ) { closeModal(); return; }

					var bounds = jcropApi.getBounds();
					var jcropW = bounds ? bounds[0] : 0;
					var jcropH = bounds ? bounds[1] : 0;

					if ( ! srcNaturalW || ! srcNaturalH || ! jcropW || ! jcropH ) {
						closeModal();
						if ( fileIn ) { fileIn.value = ''; }
						setMessage(<?php echo wp_json_encode( __( 'Could not process the image.', 'friendly-avatar-uploader' ) ); ?>, 'error');
						return;
					}

					var scaleX = srcNaturalW / jcropW;
					var scaleY = srcNaturalH / jcropH;

					var canvas = document.createElement('canvas');
					canvas.width  = targetSize;
					canvas.height = targetSize;
					var ctx = canvas.getContext('2d');
					ctx.drawImage(
						modalImg,
						coords.x * scaleX, coords.y * scaleY,
						coords.w * scaleX, coords.h * scaleY,
						0, 0, targetSize, targetSize
					);
					var previewUrl = canvas.toDataURL('image/jpeg', 0.92);
					canvas.toBlob(function (blob) {
						closeModal();
						if ( ! blob ) {
							if ( fileIn ) { fileIn.value = ''; }
							setMessage(<?php echo wp_json_encode( __( 'Could not process the image.', 'friendly-avatar-uploader' ) ); ?>, 'error');
							return;
						}
						uploadBlob(blob, previewUrl);
					}, 'image/jpeg', 0.92);
				}

				function cancelCrop() {
					closeModal();
					if ( fileIn ) { fileIn.value = ''; }
				}

				if ( upload && fileIn ) {
					upload.addEventListener('click', function () {
						if ( upload.disabled ) { return; }
						fileIn.click();
					});
				}

				if ( fileIn ) {
					fileIn.addEventListener('change', function () {
						var file = fileIn.files && fileIn.files[0];
						if ( ! file ) { return; }
						var reader = new FileReader();
						reader.onload = function (e) {
							if ( hasJcrop && modal && modalImg ) {
								openModal(e.target.result);
							} else {
								// Fallback: no Jcrop available, send original
								// file to the existing endpoint and let the
								// server-side resize() safety net square it.
								if ( preview ) { preview.src = e.target.result; }
								if ( ! form ) { return; }
								var data = new FormData(form);
								setBusy(true);
								setMessage(<?php echo wp_json_encode( __( 'Uploading…', 'friendly-avatar-uploader' ) ); ?>, '');
								fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
									.then(function (r) { return r.json(); })
									.then(function (res) {
										setBusy(false);
										if ( res && res.success && res.data && res.data.url ) {
											var url = res.data.url;
											preview.src = url + ( url.indexOf('?') === -1 ? '?' : '&' ) + 't=' + Date.now();
											setMessage(res.data.message || '', 'success');
											fileIn.value = '';
											if ( ! root.querySelector('.fau-profile-page__remove') ) {
												var rm = document.createElement('button');
												rm.type = 'button';
												rm.className = 'fau-profile-page__btn fau-profile-page__btn--secondary fau-profile-page__remove';
												rm.textContent = <?php echo wp_json_encode( __( 'Remove', 'friendly-avatar-uploader' ) ); ?>;
												actions.appendChild(rm);
												bindRemove(rm);
											}
										} else {
											var errMsg = (res && res.data && res.data.message) ? res.data.message : <?php echo wp_json_encode( __( 'Upload failed.', 'friendly-avatar-uploader' ) ); ?>;
											fileIn.value = '';
											setMessage(errMsg, 'error');
										}
									})
									.catch(function () {
										setBusy(false);
										fileIn.value = '';
										setMessage(<?php echo wp_json_encode( __( 'Network error. Please try again.', 'friendly-avatar-uploader' ) ); ?>, 'error');
									});
							}
						};
						reader.readAsDataURL(file);
					});
				}

				if ( btnConfirm ) { btnConfirm.addEventListener('click', applyCrop); }
				if ( btnCancel )  { btnCancel.addEventListener('click', cancelCrop); }
				if ( backdrop )   { backdrop.addEventListener('click', cancelCrop); }

				function bindRemove(btn) {
					btn.addEventListener('click', function () {
						btn.disabled = true;
						setMessage(<?php echo wp_json_encode( __( 'Removing…', 'friendly-avatar-uploader' ) ); ?>, '');
						var data = new FormData();
						data.append('action', 'fau_remove_avatar');
						data.append('fau_nonce', removeNonce);

						fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
							.then(function (r) { return r.json(); })
							.then(function (res) {
								btn.disabled = false;
								if ( res && res.success ) {
									if ( res.data && res.data.gravatar ) { preview.src = res.data.gravatar; }
									setMessage((res.data && res.data.message) || '', 'success');
									btn.remove();
								} else {
									var errMsg = (res && res.data && res.data.message) ? res.data.message : <?php echo wp_json_encode( __( 'Remove failed.', 'friendly-avatar-uploader' ) ); ?>;
									setMessage(errMsg, 'error');
								}
							})
							.catch(function () {
								btn.disabled = false;
								setMessage(<?php echo wp_json_encode( __( 'Network error. Please try again.', 'friendly-avatar-uploader' ) ); ?>, 'error');
							});
					});
				}

				var existingRemove = root.querySelector('.fau-profile-page__remove');
				if ( existingRemove ) { bindRemove(existingRemove); }
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Convert a sanitized hex color to an "r, g, b" triplet for use inside
	 * rgba(...) via a CSS custom property.
	 *
	 * @param string $hex Sanitized hex color (e.g. "#4a90d9" or "#abc").
	 * @return string
	 */
	protected function hex_to_rgb_triplet( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			$defaults = $this->fallback_defaults();
			$hex      = ltrim( $defaults['accent'], '#' );
		}
		return sprintf(
			'%d, %d, %d',
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) )
		);
	}

	/**
	 * Register the Settings submenu page.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_options_page(
			__( 'Friendly Profile Page', 'friendly-avatar-uploader' ),
			__( 'Friendly Profile', 'friendly-avatar-uploader' ),
			'manage_options',
			'fau-profile-page',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Register the color settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'fau_profile_page_settings',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => $this->fallback_defaults(),
			)
		);
	}

	/**
	 * Sanitize the saved color options.
	 *
	 * @param array $input Raw posted values.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$out      = array();
		$fallback = $this->fallback_defaults();
		$keys     = array( 'accent', 'bg_from', 'bg_to', 'text_color', 'muted_color' );
		foreach ( $keys as $key ) {
			$value       = isset( $input[ $key ] ) ? sanitize_hex_color( (string) $input[ $key ] ) : '';
			$out[ $key ] = $value ? $value : $fallback[ $key ];
		}
		return $out;
	}

	/**
	 * Enqueue WP color picker assets on our settings screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_fau-profile-page' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	/**
	 * Render the Settings screen.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$saved   = (array) get_option( self::OPTION_KEY, array() );
		$values  = wp_parse_args( $saved, $this->fallback_defaults() );
		$fields  = array(
			'accent'      => __( 'Accent', 'friendly-avatar-uploader' ),
			'bg_from'     => __( 'Background (top)', 'friendly-avatar-uploader' ),
			'bg_to'       => __( 'Background (bottom)', 'friendly-avatar-uploader' ),
			'text_color'  => __( 'Text color', 'friendly-avatar-uploader' ),
			'muted_color' => __( 'Muted color', 'friendly-avatar-uploader' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Friendly Profile Page', 'friendly-avatar-uploader' ); ?></h1>
			<p>
				<?php esc_html_e( 'Default colors for the [friendly_profile_page] shortcode. Per-shortcode attributes still override these defaults.', 'friendly-avatar-uploader' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'fau_profile_page_settings' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( $fields as $key => $label ) : ?>
						<tr>
							<th scope="row">
								<label for="fau-profile-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="fau-profile-<?php echo esc_attr( $key ); ?>"
									class="fau-profile-color-picker"
									name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>"
									value="<?php echo esc_attr( isset( $values[ $key ] ) ? $values[ $key ] : '' ); ?>"
									data-default-color="<?php echo esc_attr( $this->fallback_defaults()[ $key ] ); ?>"
								/>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
			<script>
			jQuery(function ($) {
				$('.fau-profile-color-picker').wpColorPicker();
			});
			</script>
		</div>
		<?php
	}
}
