/*
	ffmpeg web gui: progressive enhancement. Every page works without this
	file; it adds:
	- a size check before uploading, an upload progress bar and no double submits
	- audio settings that switch off with "Enable audio"
	- live progress on a conversion's page
	- a confirmation before deleting a conversion
*/
(function () {
	'use strict';

	function formatBytes(bytes) {
		var units = ['bytes', 'KB', 'MB', 'GB'];
		var i = 0;
		while (bytes >= 1024 && i < units.length - 1) {
			bytes /= 1024;
			i++;
		}
		return (i ? bytes.toFixed(1) : bytes) + ' ' + units[i];
	}

	/** Replace the error messages in container with these. */
	function showErrors(container, messages) {
		container.textContent = '';
		messages.forEach(function (message) {
			var p = document.createElement('p');
			p.className = 'notice notice-error';
			p.setAttribute('role', 'alert');
			p.textContent = message;
			container.appendChild(p);
		});
		if (messages.length) {
			container.scrollIntoView({ block: 'nearest' });
		}
	}

	// --- Convert form ---------------------------------------------------------

	function enhanceConvertForm(form) {
		var fileInput = form.querySelector('#file');
		var button = form.querySelector('button[type="submit"]');
		var status = form.querySelector('.upload-status');
		var bar = status.querySelector('progress');
		var text = status.querySelector('[data-upload-text]');
		var messages = document.querySelector('.form-messages');
		var buttonText = button.textContent;

		function checkSize() {
			var file = fileInput.files[0];
			var maxBytes = parseInt(fileInput.getAttribute('data-max-bytes'), 10) || 0;
			if (file && maxBytes && file.size > maxBytes) {
				fileInput.setCustomValidity('This file is ' + formatBytes(file.size) + '; the limit is ' + formatBytes(maxBytes) + '.');
			} else {
				fileInput.setCustomValidity('');
			}
		}
		fileInput.addEventListener('change', checkSize);

		function reset() {
			button.disabled = false;
			button.removeAttribute('aria-busy');
			button.textContent = buttonText;
			status.hidden = true;
		}

		form.addEventListener('submit', function (event) {
			checkSize();
			if (!form.checkValidity()) {
				return; // let the browser show its message
			}
			event.preventDefault();
			if (button.disabled) {
				return;
			}
			showErrors(messages, []);
			button.disabled = true;
			button.setAttribute('aria-busy', 'true');
			button.textContent = 'Uploading…';
			status.hidden = false;
			bar.value = 0;
			text.textContent = 'Starting upload…';

			var xhr = new XMLHttpRequest();
			xhr.open('POST', form.getAttribute('action') || window.location.href);
			xhr.setRequestHeader('Accept', 'application/json');
			xhr.responseType = 'json';
			xhr.upload.addEventListener('progress', function (e) {
				if (e.lengthComputable) {
					var percent = Math.floor(e.loaded / e.total * 100);
					bar.value = percent;
					text.textContent = 'Uploading: ' + percent + '% (' + formatBytes(e.loaded) + ' of ' + formatBytes(e.total) + ')';
				}
			});
			xhr.upload.addEventListener('load', function () {
				bar.removeAttribute('value'); // indeterminate while the server checks the video
				text.textContent = 'Checking the video…';
				button.textContent = 'Checking…';
			});
			xhr.addEventListener('load', function () {
				var data = xhr.response || {};
				if (xhr.status === 200 && data.url) {
					text.textContent = 'Uploaded. Opening the conversion…';
					window.location.assign(data.url);
					return;
				}
				reset();
				showErrors(messages, data.errors || ['The upload failed (HTTP ' + xhr.status + '). Please try again.']);
				if (xhr.status === 403) {
					window.location.reload(); // logged out: show the login page
				}
			});
			xhr.addEventListener('error', function () {
				reset();
				showErrors(messages, ['The upload failed. Check your connection and try again.']);
			});
			xhr.send(new FormData(form));
		});

		// Audio settings only apply while audio is on
		var audio = form.querySelector('#encoding_enable_audio');
		var audioSettings = form.querySelectorAll('.audio-settings select, .audio-settings input');
		function syncAudio() {
			audioSettings.forEach(function (field) {
				field.disabled = !audio.checked;
			});
		}
		if (audio) {
			audio.addEventListener('change', syncAudio);
			syncAudio();
		}
	}

	// --- Conversion page ------------------------------------------------------

	function watchJob(panel) {
		var url = panel.getAttribute('data-job-status');
		var bar = panel.querySelector('progress');
		var text = panel.querySelector('[data-job-text]');
		var heading = document.querySelector('[data-job-heading]');
		var failures = 0;

		function poll() {
			fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
				.then(function (response) {
					if (response.status === 403 || response.status === 404) {
						window.location.reload();
						return null;
					}
					return response.json();
				})
				.then(function (status) {
					if (!status) {
						return;
					}
					failures = 0;
					if (status.status === 'done' || status.status === 'failed') {
						window.location.reload(); // the page shows the results
						return;
					}
					if (status.status === 'running') {
						bar.value = status.percent;
						if (heading) {
							heading.textContent = 'Converting';
						}
					} else {
						bar.removeAttribute('value');
					}
					text.textContent = status.text;
					window.setTimeout(poll, 1500);
				})
				.catch(function () {
					failures++;
					text.textContent = 'Lost contact with the server; trying again…';
					window.setTimeout(poll, Math.min(30000, 2000 * failures));
				});
		}
		window.setTimeout(poll, 1000);
	}

	// --- Start ----------------------------------------------------------------

	var form = document.querySelector('form.convert-form');
	if (form) {
		enhanceConvertForm(form);
	}
	var panel = document.querySelector('[data-job-status]');
	if (panel) {
		watchJob(panel);
	}
	document.querySelectorAll('form[data-confirm]').forEach(function (deleteForm) {
		deleteForm.addEventListener('submit', function (event) {
			if (!window.confirm(deleteForm.getAttribute('data-confirm'))) {
				event.preventDefault();
			}
		});
	});
})();
