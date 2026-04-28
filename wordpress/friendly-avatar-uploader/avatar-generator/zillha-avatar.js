/**
 * ZillHa Avatar Generator front-end behaviour.
 * Vanilla JS, no jQuery, no external libraries.
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

	function buildFormData(form, action) {
		var data = new FormData();
		data.append('action', action);
		data.append('nonce', config.nonce);

		var fields = ['vibe', 'gender', 'hair', 'outfit', 'background', 'mood', 'features', 'art_style'];
		for (var i = 0; i < fields.length; i++) {
			var name = fields[i];
			var input = form.querySelector('[name="' + name + '"]');
			if (input) {
				data.append(name, input.value || '');
			}
		}
		return data;
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

	function init(root) {
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

		var LS_KEY = 'zillha_form_open';

		function isCollapsibleOpen() {
			if (!collapsible) { return false; }
			return !collapsible.hasAttribute('hidden');
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
				if (localStorage.getItem(LS_KEY) === '1') { openCollapsible(); }
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

			postAjax(buildFormData(form, 'zillha_generate_avatar'))
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
					setMessage(formMessage, i18n.genericError || 'Something went wrong.', 'error');
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
				data.append('action', 'zillha_save_avatar');
				data.append('nonce', config.nonce);

				postAjax(data)
					.then(function (result) {
						if (result.ok && result.json && result.json.success) {
							setButtonState(saveButton, 'success');
							var msg = (result.json.data && result.json.data.message) || i18n.savedSuccess || 'Saved!';
							setMessage(resultMessage, msg, 'success');
						} else {
							var errMsg;
							if (result.status === 410) {
								errMsg = i18n.sessionExpired || (result.json && result.json.data && result.json.data.message);
							} else {
								errMsg = (result.json && result.json.data && result.json.data.message) || i18n.genericError;
							}
							setButtonState(saveButton, 'error');
							setMessage(resultMessage, errMsg || 'Error.', 'error');
							setTimeout(function () {
								setButtonState(saveButton, 'default');
							}, 1800);
						}
					})
					.catch(function () {
						setButtonState(saveButton, 'error');
						setMessage(resultMessage, i18n.genericError || 'Something went wrong.', 'error');
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

	ready(function () {
		var roots = document.querySelectorAll('[data-zag-root]');
		for (var i = 0; i < roots.length; i++) {
			init(roots[i]);
		}
	});
})();
