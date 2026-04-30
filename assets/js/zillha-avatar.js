/**
 * Zillha Avatar — single front-end script for both shortcodes.
 * Vanilla JS, fetch API, no jQuery.
 */
(function () {
	'use strict';

	if (typeof window === 'undefined' || typeof document === 'undefined') {
		return;
	}

	var config = window.ZillhaAvatarConfig || null;
	if (!config || !config.ajaxUrl || !config.nonce) {
		return;
	}

	var i18n = config.i18n || {};

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function setButtonState(button, state) {
		if (!button) {
			return;
		}
		button.classList.remove('is-loading', 'is-success', 'is-error');
		if (state === 'loading') {
			button.classList.add('is-loading');
			button.setAttribute('aria-busy', 'true');
			button.disabled = true;
		} else if (state === 'success') {
			button.classList.add('is-success');
			button.removeAttribute('aria-busy');
			button.disabled = false;
		} else if (state === 'error') {
			button.classList.add('is-error');
			button.removeAttribute('aria-busy');
			button.disabled = false;
		} else {
			button.removeAttribute('aria-busy');
			button.disabled = false;
		}
	}

	function setMessage(target, text, kind) {
		if (!target) {
			return;
		}
		target.textContent = text || '';
		target.classList.remove('is-error', 'is-success');
		if (kind === 'error') {
			target.classList.add('is-error');
		} else if (kind === 'success') {
			target.classList.add('is-success');
		}
	}

	function postAjax(body) {
		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json().catch(function () {
				return { success: false, data: { message: i18n.genericError || 'Something went wrong.' } };
			}).then(function (json) {
				return { ok: response.ok, status: response.status, json: json };
			});
		});
	}

	function bustCache(url) {
		if (!url) { return url; }
		return url + (url.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
	}

	/* ----------------------------------------------------------------
	 * Generator shortcode
	 * ---------------------------------------------------------------- */

	function initGenerator(root) {
		var form = root.querySelector('[data-zag-form]');
		var resultSection = root.querySelector('[data-zag-result]');
		var preview = root.querySelector('[data-zag-preview]');
		var downloadLink = root.querySelector('[data-zag-download]');
		var generateButton = root.querySelector('[data-zag-generate]');
		var saveButton = root.querySelector('[data-zag-save]');
		var resetButton = root.querySelector('[data-zag-reset]');
		var formMessage = root.querySelector('[data-zag-form-message]');
		var resultMessage = root.querySelector('[data-zag-result-message]');
		var toggleButton = root.querySelector('[data-zag-toggle]');
		var collapsible = root.querySelector('[data-zag-collapsible]');

		var LS_KEY = 'zillha_avatar_form_open';

		function isCollapsibleOpen() {
			return collapsible && !collapsible.hasAttribute('hidden');
		}

		function openCollapsible() {
			if (collapsible) { collapsible.removeAttribute('hidden'); }
			if (toggleButton) { toggleButton.setAttribute('aria-expanded', 'true'); }
		}

		function closeCollapsible() {
			if (collapsible) { collapsible.setAttribute('hidden', ''); }
			if (toggleButton) { toggleButton.setAttribute('aria-expanded', 'false'); }
		}

		if (toggleButton) {
			try {
				if (window.localStorage && localStorage.getItem(LS_KEY) === '1') {
					openCollapsible();
				}
			} catch (e) {}

			toggleButton.addEventListener('click', function () {
				if (isCollapsibleOpen()) {
					closeCollapsible();
					try { localStorage.setItem(LS_KEY, '0'); } catch (e) {}
				} else {
					openCollapsible();
					try { localStorage.setItem(LS_KEY, '1'); } catch (e) {}
				}
			});
		}

		if (!form || !resultSection || !preview || !generateButton) {
			return;
		}

		function showForm() {
			resultSection.hidden = true;
			form.hidden = false;
			openCollapsible();
			setMessage(resultMessage, '');
		}

		function showResult(dataUri) {
			preview.src = dataUri;
			if (downloadLink) {
				downloadLink.setAttribute('href', dataUri);
				downloadLink.setAttribute('download', i18n.downloadName || 'zillha-avatar.webp');
			}
			form.hidden = true;
			closeCollapsible();
			resultSection.hidden = false;
			setButtonState(saveButton, 'default');
			setMessage(resultMessage, '');
		}

		function buildGenerateBody() {
			var data = new FormData();
			data.append('action', 'zillha_avatar_generate');
			data.append('nonce', config.nonce);

			var fields = ['vibe', 'gender', 'hair', 'outfit', 'background', 'mood', 'features', 'art_style'];
			for (var i = 0; i < fields.length; i++) {
				var input = form.querySelector('[name="' + fields[i] + '"]');
				if (input) {
					data.append(fields[i], input.value || '');
				}
			}
			return data;
		}

		function validateRequired() {
			var requiredNames = ['vibe', 'gender', 'hair', 'outfit', 'background', 'mood'];
			for (var i = 0; i < requiredNames.length; i++) {
				var input = form.querySelector('[name="' + requiredNames[i] + '"]');
				if (!input || !input.value || !input.value.trim()) {
					return input;
				}
			}
			return null;
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var missing = validateRequired();
			if (missing) {
				setMessage(formMessage, i18n.fillRequired || 'Please fill in all required fields.', 'error');
				if (typeof missing.focus === 'function') {
					missing.focus();
				}
				return;
			}

			setButtonState(generateButton, 'loading');
			setMessage(formMessage, i18n.generating || 'Generating avatar…');

			postAjax(buildGenerateBody())
				.then(function (result) {
					if (result.ok && result.json && result.json.success && result.json.data && result.json.data.data_uri) {
						setButtonState(generateButton, 'default');
						setMessage(formMessage, '');
						showResult(result.json.data.data_uri);
					} else {
						setButtonState(generateButton, 'error');
						var msg = (result.json && result.json.data && result.json.data.message) || i18n.genericError || 'Something went wrong.';
						setMessage(formMessage, msg, 'error');
						setTimeout(function () {
							setButtonState(generateButton, 'default');
						}, 1800);
					}
				})
				.catch(function () {
					setButtonState(generateButton, 'error');
					setMessage(formMessage, i18n.networkError || 'Network error.', 'error');
					setTimeout(function () {
						setButtonState(generateButton, 'default');
					}, 1800);
				});
		});

		if (saveButton) {
			saveButton.addEventListener('click', function () {
				setButtonState(saveButton, 'loading');
				setMessage(resultMessage, i18n.saving || 'Saving as profile picture…');

				var data = new FormData();
				data.append('action', 'zillha_avatar_save_generated');
				data.append('nonce', config.nonce);

				postAjax(data)
					.then(function (result) {
						if (result.ok && result.json && result.json.success) {
							setButtonState(saveButton, 'success');
							var msg = (result.json.data && result.json.data.message) || i18n.savedSuccess || 'Saved!';
							setMessage(resultMessage, msg, 'success');
							syncUploaderPreview(result.json.data && result.json.data.url);
						} else {
							var errMsg;
							if (result.status === 410) {
								errMsg = i18n.sessionExpired || (result.json && result.json.data && result.json.data.message);
							} else {
								errMsg = (result.json && result.json.data && result.json.data.message) || i18n.genericError;
							}
							setButtonState(saveButton, 'error');
							setMessage(resultMessage, errMsg || i18n.genericError || 'Error.', 'error');
							setTimeout(function () {
								setButtonState(saveButton, 'default');
							}, 1800);
						}
					})
					.catch(function () {
						setButtonState(saveButton, 'error');
						setMessage(resultMessage, i18n.networkError || 'Network error.', 'error');
						setTimeout(function () {
							setButtonState(saveButton, 'default');
						}, 1800);
					});
			});
		}

		if (resetButton) {
			resetButton.addEventListener('click', function () {
				preview.removeAttribute('src');
				if (downloadLink) {
					downloadLink.setAttribute('href', '#');
				}
				setButtonState(saveButton, 'default');
				setButtonState(generateButton, 'default');
				setMessage(formMessage, '');
				showForm();
			});
		}
	}

	/* ----------------------------------------------------------------
	 * Uploader shortcode
	 * ---------------------------------------------------------------- */

	function initUploader(root) {
		var form = root.querySelector('[data-zag-upload-form]');
		var fileInput = root.querySelector('[data-zag-file]');
		var preview = root.querySelector('[data-zag-preview-image]');
		var uploadButton = root.querySelector('[data-zag-upload]');
		var removeButton = root.querySelector('[data-zag-remove]');
		var message = root.querySelector('[data-zag-upload-message]');
		var gravatar = root.getAttribute('data-zag-gravatar') || '';

		if (!form || !fileInput || !preview || !uploadButton) {
			return;
		}

		fileInput.addEventListener('change', function () {
			var file = fileInput.files && fileInput.files[0];
			if (!file) { return; }
			var reader = new FileReader();
			reader.onload = function (e) {
				preview.src = e.target.result;
			};
			reader.readAsDataURL(file);
		});

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			if (!fileInput.files || !fileInput.files[0]) {
				setMessage(message, i18n.pickImage || 'Please choose an image first.', 'error');
				return;
			}

			var data = new FormData();
			data.append('action', 'zillha_avatar_upload');
			data.append('nonce', config.nonce);
			data.append('zillha_avatar', fileInput.files[0]);

			setButtonState(uploadButton, 'loading');
			setMessage(message, i18n.uploading || 'Uploading…');

			postAjax(data)
				.then(function (result) {
					if (result.ok && result.json && result.json.success && result.json.data && result.json.data.url) {
						setButtonState(uploadButton, 'success');
						var url = result.json.data.url;
						preview.src = bustCache(url);
						setMessage(message, (result.json.data && result.json.data.message) || i18n.uploadedOk || '', 'success');
						form.reset();
						if (removeButton) { removeButton.hidden = false; }
						syncGeneratorPreview(url);
						syncProfilePreview(url);
						setTimeout(function () {
							setButtonState(uploadButton, 'default');
						}, 1800);
					} else {
						setButtonState(uploadButton, 'error');
						var errMsg = (result.json && result.json.data && result.json.data.message) || i18n.genericError;
						setMessage(message, errMsg || i18n.genericError || 'Error.', 'error');
						setTimeout(function () {
							setButtonState(uploadButton, 'default');
						}, 1800);
					}
				})
				.catch(function () {
					setButtonState(uploadButton, 'error');
					setMessage(message, i18n.networkError || 'Network error.', 'error');
					setTimeout(function () {
						setButtonState(uploadButton, 'default');
					}, 1800);
				});
		});

		if (removeButton) {
			removeButton.addEventListener('click', function () {
				setButtonState(removeButton, 'loading');
				setMessage(message, i18n.removing || 'Removing…');

				var data = new FormData();
				data.append('action', 'zillha_avatar_remove');
				data.append('nonce', config.nonce);

				postAjax(data)
					.then(function (result) {
						if (result.ok && result.json && result.json.success) {
							setButtonState(removeButton, 'default');
							removeButton.hidden = true;
							var fallback = (result.json.data && result.json.data.gravatar) || gravatar;
							if (fallback) {
								preview.src = fallback;
								syncRemoveAcrossPage(fallback);
							}
							setMessage(message, (result.json.data && result.json.data.message) || i18n.removedOk || '', 'success');
						} else {
							setButtonState(removeButton, 'error');
							var errMsg = (result.json && result.json.data && result.json.data.message) || i18n.genericError;
							setMessage(message, errMsg || i18n.genericError || 'Error.', 'error');
							setTimeout(function () {
								setButtonState(removeButton, 'default');
							}, 1800);
						}
					})
					.catch(function () {
						setButtonState(removeButton, 'error');
						setMessage(message, i18n.networkError || 'Network error.', 'error');
						setTimeout(function () {
							setButtonState(removeButton, 'default');
						}, 1800);
					});
			});
		}
	}

	/* ----------------------------------------------------------------
	 * Cross-shortcode preview sync — when one root saves a new avatar,
	 * keep any other shortcode on the same page in sync so the user
	 * sees a single, consistent preview.
	 * ---------------------------------------------------------------- */

	function syncUploaderPreview(url) {
		if (!url) { return; }
		var images = document.querySelectorAll('[data-zag-uploader] [data-zag-preview-image]');
		for (var i = 0; i < images.length; i++) {
			images[i].src = bustCache(url);
		}
		var removeButtons = document.querySelectorAll('[data-zag-uploader] [data-zag-remove]');
		for (var j = 0; j < removeButtons.length; j++) {
			removeButtons[j].hidden = false;
		}
		syncProfilePreview(url);
	}

	function syncGeneratorPreview(url) {
		if (!url) { return; }
		var images = document.querySelectorAll('[data-zag-generator] [data-zag-preview]');
		for (var i = 0; i < images.length; i++) {
			if (!images[i].getAttribute('src')) {
				images[i].src = bustCache(url);
			}
		}
	}

	function syncProfilePreview(url) {
		if (!url) { return; }
		var avatars = document.querySelectorAll('[data-zag-profile] [data-zag-profile-avatar]');
		for (var i = 0; i < avatars.length; i++) {
			avatars[i].src = bustCache(url);
		}
		var removeButtons = document.querySelectorAll('[data-zag-profile] [data-zag-profile-remove]');
		for (var j = 0; j < removeButtons.length; j++) {
			removeButtons[j].hidden = false;
		}
	}

	/**
	 * Cross-shortcode revert after a successful remove. Updates every
	 * uploader preview and every profile avatar on the page to the
	 * fallback (Gravatar) URL and hides all remove buttons. Without this
	 * a page hosting more than one shortcode would leave stale previews
	 * and visible remove buttons in the shortcodes that didn't trigger
	 * the remove call.
	 */
	function syncRemoveAcrossPage(fallback) {
		if (!fallback) { return; }
		var uploaderImages = document.querySelectorAll('[data-zag-uploader] [data-zag-preview-image]');
		for (var i = 0; i < uploaderImages.length; i++) {
			uploaderImages[i].src = fallback;
		}
		var uploaderRemoves = document.querySelectorAll('[data-zag-uploader] [data-zag-remove]');
		for (var j = 0; j < uploaderRemoves.length; j++) {
			uploaderRemoves[j].hidden = true;
		}
		var profileAvatars = document.querySelectorAll('[data-zag-profile] [data-zag-profile-avatar]');
		for (var k = 0; k < profileAvatars.length; k++) {
			profileAvatars[k].src = fallback;
		}
		var profileRemoves = document.querySelectorAll('[data-zag-profile] [data-zag-profile-remove]');
		for (var m = 0; m < profileRemoves.length; m++) {
			profileRemoves[m].hidden = true;
		}
	}

	/* ----------------------------------------------------------------
	 * Profile shortcode
	 * ---------------------------------------------------------------- */

	function initProfile(root) {
		var avatarImg = root.querySelector('[data-zag-profile-avatar]');
		var fileInput = root.querySelector('[data-zag-profile-file]');
		var changeBtn = root.querySelector('[data-zag-profile-change]');
		var generateBtn = root.querySelector('[data-zag-profile-generate]');
		var removeBtn = root.querySelector('[data-zag-profile-remove]');
		var message = root.querySelector('[data-zag-profile-message]');
		var generatorHost = root.querySelector('[data-zag-profile-generator-host]');
		var gravatar = root.getAttribute('data-zag-gravatar') || '';

		var cropModal    = root.querySelector('[data-zag-crop-modal]');
		var cropImg      = cropModal ? cropModal.querySelector('[data-zag-crop-img]') : null;
		var cropConfirm  = cropModal ? cropModal.querySelector('[data-zag-crop-confirm]') : null;
		var cropCancel   = cropModal ? cropModal.querySelector('[data-zag-crop-cancel]') : null;
		var cropBackdrop = cropModal ? cropModal.querySelector('[data-zag-crop-backdrop]') : null;
		var cropMessage  = cropModal ? cropModal.querySelector('[data-zag-crop-message]') : null;
		var jcropApi     = null;
		var srcNaturalW  = 0;
		var srcNaturalH  = 0;

		if (!avatarImg || !fileInput || !changeBtn) {
			return;
		}

		if (changeBtn) {
			changeBtn.addEventListener('click', function () {
				fileInput.click();
			});
		}

		function openCropModal(dataUrl) {
			if (!cropModal || !cropImg) { return; }

			setMessage(cropMessage, '');
			setButtonState(cropConfirm, 'default');

			cropImg.onload = function () {
				cropModal.removeAttribute('hidden');

				srcNaturalW = cropImg.naturalWidth;
				srcNaturalH = cropImg.naturalHeight;

				var imgW = cropImg.width || cropImg.offsetWidth;
				var imgH = cropImg.height || cropImg.offsetHeight;

				var selSize = Math.round(Math.min(imgW, imgH) * 0.8);
				var selX    = Math.round((imgW - selSize) / 2);
				var selY    = Math.round((imgH - selSize) / 2);

				if (jcropApi && typeof jcropApi.destroy === 'function') {
					jcropApi.destroy();
					jcropApi = null;
				}

				if (window.jQuery && typeof window.jQuery.fn.Jcrop === 'function') {
					window.jQuery(cropImg).Jcrop(
						{
							setSelect: [selX, selY, selX + selSize, selY + selSize],
							bgColor:   'black',
							bgOpacity: 0.55,
							minSize:   [40, 40]
						},
						function () { jcropApi = this; }
					);
				}
			};

			cropImg.src = dataUrl;
		}

		function closeCropModal() {
			if (jcropApi && typeof jcropApi.destroy === 'function') {
				jcropApi.destroy();
			}
			jcropApi = null;
			srcNaturalW = 0;
			srcNaturalH = 0;
			if (cropModal) { cropModal.setAttribute('hidden', ''); }
			if (cropImg) { cropImg.removeAttribute('src'); }
			fileInput.value = '';
			setMessage(message, '');
			setMessage(cropMessage, '');
			setButtonState(cropConfirm, 'default');
		}

		function applyCrop() {
			if (!jcropApi) { closeCropModal(); return; }

			var coords = jcropApi.tellSelect();
			if (!coords || !coords.w || !coords.h) { closeCropModal(); return; }

			var bounds = jcropApi.getBounds();
			var jcropW = bounds[0];
			var jcropH = bounds[1];
			if (!srcNaturalW || !srcNaturalH || !jcropW || !jcropH) {
				setMessage(cropMessage, i18n.genericError || 'Something went wrong.', 'error');
				return;
			}

			var scaleX = srcNaturalW / jcropW;
			var scaleY = srcNaturalH / jcropH;

			var cropW = Math.round(coords.w * scaleX);
			var cropH = Math.round(coords.h * scaleY);
			var cropX = Math.round(coords.x * scaleX);
			var cropY = Math.round(coords.y * scaleY);

			var canvas = document.createElement('canvas');
			canvas.width  = cropW;
			canvas.height = cropH;
			var ctx = canvas.getContext('2d');
			ctx.drawImage(cropImg, cropX, cropY, cropW, cropH, 0, 0, cropW, cropH);

			setButtonState(cropConfirm, 'loading');
			setMessage(cropMessage, i18n.uploading || 'Uploading…');

			canvas.toBlob(function (blob) {
				if (!blob) {
					setButtonState(cropConfirm, 'error');
					setMessage(cropMessage, i18n.genericError || 'Something went wrong.', 'error');
					setTimeout(function () { setButtonState(cropConfirm, 'default'); }, 1800);
					return;
				}

				var data = new FormData();
				data.append('action', 'zillha_avatar_upload');
				data.append('nonce', config.nonce);
				data.append('zillha_avatar', blob, 'avatar-crop.jpg');

				postAjax(data)
					.then(function (result) {
						if (result.ok && result.json && result.json.success && result.json.data && result.json.data.url) {
							var url = result.json.data.url;
							avatarImg.src = bustCache(url);
							syncUploaderPreview(url);
							syncProfilePreview(url);
							if (removeBtn) { removeBtn.hidden = false; }
							closeCropModal();
							setMessage(message, (result.json.data && result.json.data.message) || i18n.uploadedOk || '', 'success');
						} else {
							var msg = (result.json && result.json.data && result.json.data.message) || i18n.genericError;
							setButtonState(cropConfirm, 'error');
							setMessage(cropMessage, msg || i18n.genericError || 'Error.', 'error');
							setTimeout(function () { setButtonState(cropConfirm, 'default'); }, 1800);
						}
					})
					.catch(function () {
						setButtonState(cropConfirm, 'error');
						setMessage(cropMessage, i18n.networkError || 'Network error.', 'error');
						setTimeout(function () { setButtonState(cropConfirm, 'default'); }, 1800);
					});
			}, 'image/jpeg', 0.92);
		}

		fileInput.addEventListener('change', function () {
			var file = fileInput.files && fileInput.files[0];
			if (!file) { return; }

			var reader = new FileReader();
			reader.onload = function (e) {
				openCropModal(e.target.result);
			};
			reader.readAsDataURL(file);
		});

		if (cropConfirm)  { cropConfirm.addEventListener('click', applyCrop); }
		if (cropCancel)   { cropCancel.addEventListener('click', closeCropModal); }
		if (cropBackdrop) { cropBackdrop.addEventListener('click', closeCropModal); }

		if (removeBtn) {
			removeBtn.addEventListener('click', function () {
				setButtonState(removeBtn, 'loading');
				setMessage(message, i18n.removing || 'Removing…');

				var data = new FormData();
				data.append('action', 'zillha_avatar_remove');
				data.append('nonce', config.nonce);

				postAjax(data)
					.then(function (result) {
						if (result.ok && result.json && result.json.success) {
							setButtonState(removeBtn, 'default');
							removeBtn.hidden = true;
							var fallback = (result.json.data && result.json.data.gravatar) || gravatar;
							if (fallback) {
								avatarImg.src = fallback;
								syncRemoveAcrossPage(fallback);
							}
							setMessage(message, (result.json.data && result.json.data.message) || i18n.removedOk || '', 'success');
						} else {
							setButtonState(removeBtn, 'error');
							var errMsg = (result.json && result.json.data && result.json.data.message) || i18n.genericError;
							setMessage(message, errMsg || i18n.genericError || 'Error.', 'error');
							setTimeout(function () {
								setButtonState(removeBtn, 'default');
							}, 1800);
						}
					})
					.catch(function () {
						setButtonState(removeBtn, 'error');
						setMessage(message, i18n.networkError || 'Network error.', 'error');
						setTimeout(function () {
							setButtonState(removeBtn, 'default');
						}, 1800);
					});
			});
		}

		if (generateBtn) {
			generateBtn.addEventListener('click', function () {
				// Prefer revealing the embedded generator inside the host;
				// fall back to any other generator already on the page.
				var target = null;
				if (generatorHost) {
					generatorHost.hidden = false;
					target = generatorHost.querySelector('[data-zag-generator]');
				}
				if (!target) {
					target = document.querySelector('[data-zag-generator]');
				}
				if (!target) { return; }

				var toggle = target.querySelector('[data-zag-toggle]');
				var collapsible = target.querySelector('[data-zag-collapsible]');
				if (collapsible && collapsible.hasAttribute('hidden')) {
					if (toggle) {
						toggle.click();
					} else {
						collapsible.removeAttribute('hidden');
					}
				}

				if (typeof target.scrollIntoView === 'function') {
					target.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			});
		}
	}

	ready(function () {
		var generators = document.querySelectorAll('[data-zag-generator]');
		for (var i = 0; i < generators.length; i++) {
			initGenerator(generators[i]);
		}
		var uploaders = document.querySelectorAll('[data-zag-uploader]');
		for (var j = 0; j < uploaders.length; j++) {
			initUploader(uploaders[j]);
		}
		var profiles = document.querySelectorAll('[data-zag-profile]');
		for (var k = 0; k < profiles.length; k++) {
			initProfile(profiles[k]);
		}
	});
})();
