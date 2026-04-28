<?php
/**
 * Front-end shortcode for the avatar upload form.
 *
 * @package FriendlyAvatarUploader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class FAU_Shortcode
 *
 * Registers [friendly_avatar_upload] and renders the upload UI plus the
 * inline CSS/JS that drives it. Inline by design — no build step, no
 * external assets.
 */
class FAU_Shortcode {

	/**
	 * Constructor: register the shortcode.
	 */
	public function __construct() {
		add_shortcode( 'friendly_avatar_upload', array( $this, 'render' ) );
	}

	/**
	 * Crop modal markup, shared between this shortcode and the profile-page
	 * shortcode. Exposed as a public static so other classes in the plugin
	 * can render the same modal without duplicating the HTML.
	 *
	 * @param string $uid Unique id (e.g. uniqid('fau_')) used in the modal id.
	 * @return string
	 */
	public static function fau_crop_modal_html( $uid ) {
		$modal_id = 'fau-crop-modal-' . $uid;
		ob_start();
		?>
		<div class="fau-crop-modal" id="<?php echo esc_attr( $modal_id ); ?>" aria-modal="true" role="dialog" aria-label="<?php esc_attr_e( 'Crop your avatar', 'friendly-avatar-uploader' ); ?>" hidden>
			<div class="fau-crop-modal__backdrop"></div>
			<div class="fau-crop-modal__box">
				<h2 class="fau-crop-modal__title"><?php esc_html_e( 'Crop your avatar', 'friendly-avatar-uploader' ); ?></h2>
				<div class="fau-crop-modal__stage">
					<img class="fau-crop-modal__img" src="" alt="" />
				</div>
				<p class="fau-crop-modal__hint"><?php esc_html_e( 'Drag to reposition. The circle shows your final avatar.', 'friendly-avatar-uploader' ); ?></p>
				<div class="fau-crop-modal__actions">
					<button type="button" class="fau-crop-btn fau-crop-btn--confirm"><?php esc_html_e( 'Apply Crop', 'friendly-avatar-uploader' ); ?></button>
					<button type="button" class="fau-crop-btn fau-crop-btn--cancel"><?php esc_html_e( 'Cancel', 'friendly-avatar-uploader' ); ?></button>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the shortcode output.
	 *
	 * @return string
	 */
	public function render() {
		if ( ! is_user_logged_in() ) {
			return '<p class="fau-error">' . esc_html__( 'You must be logged in to upload an avatar.', 'friendly-avatar-uploader' ) . '</p>';
		}

		$user_id    = get_current_user_id();
		$custom_url = get_user_meta( $user_id, FAU_META_KEY, true );
		$avatar_url = ! empty( $custom_url ) ? $custom_url : get_avatar_url( $user_id, array( 'size' => FAU_TARGET_SIZE ) );
		$has_custom = ! empty( $custom_url );
		$ajax_url   = admin_url( 'admin-ajax.php' );
		$uid        = uniqid( 'fau_' );

		ob_start();
		?>
		<div class="fau-wrap" data-fau-uid="<?php echo esc_attr( $uid ); ?>">
			<?php echo $this->styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="fau-preview-wrap">
				<img
					src="<?php echo esc_url( $avatar_url ); ?>"
					alt="<?php esc_attr_e( 'Your avatar preview', 'friendly-avatar-uploader' ); ?>"
					class="fau-preview"
					data-gravatar="<?php echo esc_attr( get_avatar_url( $user_id, array( 'size' => FAU_TARGET_SIZE ) ) ); ?>"
				/>
			</div>

			<form class="fau-form" enctype="multipart/form-data">
				<input type="hidden" name="action" value="fau_upload_avatar" />
				<?php wp_nonce_field( 'fau_upload_avatar', 'fau_nonce' ); ?>

				<label class="fau-file-label">
					<span class="fau-file-label-text"><?php esc_html_e( 'Choose an image', 'friendly-avatar-uploader' ); ?></span>
					<input
						type="file"
						name="fau_avatar"
						class="fau-file"
						accept="image/jpeg,image/png,image/gif,image/webp"
						required
					/>
				</label>

				<div class="fau-actions">
					<button type="submit" class="fau-btn fau-btn-primary">
						<?php esc_html_e( 'Upload avatar', 'friendly-avatar-uploader' ); ?>
					</button>
					<?php if ( $has_custom ) : ?>
						<button type="button" class="fau-btn fau-btn-secondary fau-remove">
							<?php esc_html_e( 'Remove custom avatar', 'friendly-avatar-uploader' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<p class="fau-hint">
					<?php
					printf(
						/* translators: 1: max file size in MB, 2: target image size in pixels. */
						esc_html__( 'JPEG, PNG, GIF or WebP. Max %1$d MB. Resized to %2$dpx square.', 'friendly-avatar-uploader' ),
						(int) ( FAU_MAX_FILE_SIZE / ( 1024 * 1024 ) ),
						(int) FAU_TARGET_SIZE
					);
					?>
				</p>

				<div class="fau-message" aria-live="polite"></div>
			</form>

			<?php echo self::fau_crop_modal_html( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $this->script( $ajax_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inline CSS scoped to .fau-wrap.
	 *
	 * @return string
	 */
	protected function styles() {
		ob_start();
		?>
		<style>
			.fau-wrap { font-family: inherit; max-width: 420px; margin: 0; }
			.fau-wrap .fau-preview-wrap { display: flex; justify-content: center; margin-bottom: 16px; }
			.fau-wrap .fau-preview {
				width: 120px; height: 120px;
				border-radius: 50%;
				object-fit: cover;
				border: 3px solid #c8a85c;
				background: #f4f4f4;
			}
			.fau-wrap .fau-form { display: flex; flex-direction: column; gap: 12px; }
			.fau-wrap .fau-file-label {
				display: block;
				padding: 10px 12px;
				border: 1px dashed #c8a85c;
				border-radius: 6px;
				background: #fff;
				cursor: pointer;
				font-size: 14px;
			}
			.fau-wrap .fau-file-label-text { display: block; margin-bottom: 6px; font-weight: 600; color: #333; }
			.fau-wrap .fau-file { display: block; width: 100%; }
			.fau-wrap .fau-actions { display: flex; flex-wrap: wrap; gap: 8px; }
			.fau-wrap .fau-btn {
				display: inline-block;
				padding: 10px 16px;
				border-radius: 4px;
				border: 1px solid #c8a85c;
				background: #c8a85c;
				color: #fff;
				font-weight: 600;
				cursor: pointer;
				font-size: 14px;
				line-height: 1.2;
			}
			.fau-wrap .fau-btn:hover { background: #b69447; border-color: #b69447; }
			.fau-wrap .fau-btn-secondary { background: transparent; color: #c8a85c; }
			.fau-wrap .fau-btn-secondary:hover { background: #f8f1de; color: #b69447; }
			.fau-wrap .fau-btn[disabled] { opacity: .6; cursor: not-allowed; }
			.fau-wrap .fau-hint { font-size: 12px; color: #666; margin: 0; }
			.fau-wrap .fau-message { font-size: 14px; min-height: 1.4em; }
			.fau-wrap .fau-message.is-success { color: #2a7a3a; }
			.fau-wrap .fau-message.is-error { color: #b3261e; }
			.fau-error { color: #b3261e; padding: 12px; border: 1px solid #b3261e; border-radius: 4px; background: #fdecea; }

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
			.fau-crop-btn--confirm { background: #4a90d9; color: #fff; }
			.fau-crop-btn--cancel  { background: #333; color: #ccc; }
		</style>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inline JS that wires up live preview, AJAX upload and remove.
	 *
	 * @param string $ajax_url Resolved admin-ajax.php URL.
	 * @return string
	 */
	protected function script( $ajax_url ) {
		ob_start();
		?>
		<script>
		(function () {
			var roots = document.querySelectorAll('.fau-wrap');
			if ( ! roots.length ) { return; }
			var ajaxUrl    = <?php echo wp_json_encode( $ajax_url ); ?>;
			var targetSize = <?php echo (int) FAU_TARGET_SIZE; ?>;
			var $          = ( typeof window.jQuery !== 'undefined' ) ? window.jQuery : null;
			var hasJcrop   = !! ( $ && $.fn && $.fn.Jcrop );

			roots.forEach(function (root) {
				if ( root.dataset.fauBound ) { return; }
				root.dataset.fauBound = '1';

				var form    = root.querySelector('.fau-form');
				var fileIn  = root.querySelector('.fau-file');
				var preview = root.querySelector('.fau-preview');
				var msg     = root.querySelector('.fau-message');
				var actions = root.querySelector('.fau-actions');
				var submit  = root.querySelector('.fau-btn-primary');

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
					msg.textContent = text || '';
					msg.classList.remove('is-success', 'is-error');
					if ( kind ) { msg.classList.add('is-' + kind); }
				}

				function setBusy(busy) {
					if ( submit ) { submit.disabled = !! busy; }
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

				function uploadBlob(blob, previewUrl) {
					if ( previewUrl ) { preview.src = previewUrl; }
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
								if ( ! root.querySelector('.fau-remove') ) {
									var rm = document.createElement('button');
									rm.type = 'button';
									rm.className = 'fau-btn fau-btn-secondary fau-remove';
									rm.textContent = <?php echo wp_json_encode( __( 'Remove custom avatar', 'friendly-avatar-uploader' ) ); ?>;
									actions.appendChild(rm);
									bindRemove(rm);
								}
							} else {
								var errMsg = (res && res.data && res.data.message) ? res.data.message : <?php echo wp_json_encode( __( 'Upload failed.', 'friendly-avatar-uploader' ) ); ?>;
								setMessage(errMsg, 'error');
							}
						})
						.catch(function () {
							setBusy(false);
							setMessage(<?php echo wp_json_encode( __( 'Network error. Please try again.', 'friendly-avatar-uploader' ) ); ?>, 'error');
						});
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
						if ( fileIn ) { fileIn.value = ''; }
						if ( ! blob ) {
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

				if ( fileIn ) {
					fileIn.addEventListener('change', function () {
						var file = fileIn.files && fileIn.files[0];
						if ( ! file ) { return; }
						var reader = new FileReader();
						reader.onload = function (e) {
							if ( hasJcrop && modal && modalImg ) {
								openModal(e.target.result);
							} else {
								preview.src = e.target.result;
							}
						};
						reader.readAsDataURL(file);
					});
				}

				if ( btnConfirm ) { btnConfirm.addEventListener('click', applyCrop); }
				if ( btnCancel )  { btnCancel.addEventListener('click', cancelCrop); }
				if ( backdrop )   { backdrop.addEventListener('click', cancelCrop); }

				if ( form ) {
					form.addEventListener('submit', function (e) {
						e.preventDefault();
						if ( ! fileIn.files || ! fileIn.files[0] ) {
							setMessage(<?php echo wp_json_encode( __( 'Please choose an image first.', 'friendly-avatar-uploader' ) ); ?>, 'error');
							return;
						}
						// Fallback path when Jcrop is not available — send the
						// original file through the existing endpoint; the
						// server-side resize() safety net handles squaring.
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
									form.reset();
									if ( ! root.querySelector('.fau-remove') ) {
										var rm = document.createElement('button');
										rm.type = 'button';
										rm.className = 'fau-btn fau-btn-secondary fau-remove';
										rm.textContent = <?php echo wp_json_encode( __( 'Remove custom avatar', 'friendly-avatar-uploader' ) ); ?>;
										actions.appendChild(rm);
										bindRemove(rm);
									}
								} else {
									var errMsg = (res && res.data && res.data.message) ? res.data.message : <?php echo wp_json_encode( __( 'Upload failed.', 'friendly-avatar-uploader' ) ); ?>;
									setMessage(errMsg, 'error');
								}
							})
							.catch(function () {
								setBusy(false);
								setMessage(<?php echo wp_json_encode( __( 'Network error. Please try again.', 'friendly-avatar-uploader' ) ); ?>, 'error');
							});
					});
				}

				function bindRemove(btn) {
					btn.addEventListener('click', function () {
						btn.disabled = true;
						setMessage(<?php echo wp_json_encode( __( 'Removing…', 'friendly-avatar-uploader' ) ); ?>, '');
						var data = new FormData();
						data.append('action', 'fau_remove_avatar');
						data.append('fau_nonce', <?php echo wp_json_encode( wp_create_nonce( 'fau_remove_avatar' ) ); ?>);

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

				var existingRemove = root.querySelector('.fau-remove');
				if ( existingRemove ) { bindRemove(existingRemove); }
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}
