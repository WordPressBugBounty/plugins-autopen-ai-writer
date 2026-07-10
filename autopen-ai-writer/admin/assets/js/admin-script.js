/**
 * Admin-facing JavaScript for the AutoPen plugin.
 *
 * Handles AJAX requests for API key validation, manual post generation,
 * system status updates, and toggling automation. Also manages UI
 * elements like the topic counter and API key visibility.
 *
 * @package AutoPen
 */
(function ($) {
	'use strict';

	/**
	 * Displays a toast notification on the screen.
	 * @param {string} message The message to display.
	 * @param {string} [type='info'] The type of toast (success, error, info).
	 * @param {number} [duration=5000] The duration to show the toast in milliseconds.
	 */
	window.showAutopenToast = function (message, type = 'info', duration = 5000) {
		const iconClass = { success: 'dashicons-yes-alt', error: 'dashicons-warning', info: 'dashicons-info' };
		const $toast = $(`<div class="autopen-toast autopen-toast-${type}"><span class="dashicons ${iconClass[type]}"></span><p>${message}</p></div>`);

		if (!$('#autopen-toast-container').length) {
			$('body').append('<div id="autopen-toast-container"></div>');
		}

		$('#autopen-toast-container').append($toast);
		setTimeout(() => { $toast.fadeOut(300, () => $toast.remove()); }, duration);
	};

	/**
	 * Fires when the DOM is ready. Initializes all admin functionalities.
	 */
	$(document).ready(function () {
		initApiKeyValidation();
		initManualGenerator();
		initApiKeyToggle();
		initTopicCounter();
		initCronToggle();

		setTimeout(updateSystemStatus, 100);
		setInterval(updateSystemStatus, 30000);
	});

	function initCronToggle() {
		$('#autopen-toggle-cron-btn').on('click', function() {
			const $button = $(this);
			const $span = $button.find('.dashicons');
			const $buttonText = $button.find('.autopen-button-text');
			
			const originalClasses = $button.attr('class');
			const originalIcon = $span.attr('class');
			const originalText = $buttonText.text();
			
			$button.prop('disabled', true);
			$span.removeClass('dashicons-no dashicons-update').addClass('autopen-spinner');

			$.post(autopen_ajax.ajax_url, {
				action: 'autopen_toggle_cron',
				nonce: autopen_ajax.nonce
			})
			.done(response => {
				if (response.success) {
					window.showAutopenToast(response.data.message, 'success');
					
					if (response.data.is_enabled) {
						$button.removeClass('button-primary').addClass('button-secondary');
						$span.removeClass('autopen-spinner').addClass('dashicons-no');
						$buttonText.text(autopen_ajax.i18n.disableAutomation);
					} else {
						$button.removeClass('button-secondary').addClass('button-primary');
						$span.removeClass('autopen-spinner').addClass('dashicons-update');
						$buttonText.text(autopen_ajax.i18n.enableAutomation);
					}
					
					setTimeout(updateSystemStatus, 500);
				} else {
					window.showAutopenToast(response.data.message, 'error');
					$button.attr('class', originalClasses);
					$span.attr('class', originalIcon);
					$buttonText.text(originalText);
				}
			})
			.fail(() => {
				window.showAutopenToast('An error occurred. Please try again.', 'error');
				$button.attr('class', originalClasses);
				$span.attr('class', originalIcon);
				$buttonText.text(originalText);
			})
			.always(() => {
				$button.prop('disabled', false);
			});
		});
	}

	function initApiKeyValidation() {
		$(document).on('click', '#autopen-validate-key-btn', function () {
			const $button = $(this);
			const originalText = $button.html();
			const apiKey = $('#autopen_options_api_key').val();

			if ( ! apiKey ) { window.showAutopenToast('API Key field is empty.', 'error'); return; }

			$button.html('<span class="autopen-spinner"></span> ' + autopen_ajax.i18n.validating).prop('disabled', true);

			$.post(autopen_ajax.ajax_url, { action: 'autopen_validate_api_key', nonce: autopen_ajax.nonce, api_key: apiKey })
				.done(response => { window.showAutopenToast(response.data.message, response.success ? 'success' : 'error'); })
				.fail(() => { window.showAutopenToast('An unknown error occurred during validation.', 'error'); })
				.always(() => { 
					$button.html(originalText).prop('disabled', false); 
					updateSystemStatus(); 
				});
		});
	}

	function initManualGenerator() {
		$('#autopen-run-now-btn').on('click', function () {
			const $button = $(this);
			const originalText = $button.html();

			if (!confirm('This will generate a post instantly and may take up to 10 minutes. Please DO NOT navigate away from this page until it is complete. Are you sure?')) { return; }
			
			$button.html('<span class="autopen-spinner"></span> ' + autopen_ajax.i18n.generating).prop('disabled', true);
			
			$.ajaxSetup({ timeout: 300000 });
			
			$.post(autopen_ajax.ajax_url, { action: 'autopen_manual_start', nonce: autopen_ajax.nonce })
				.done(response => {
					if (response.success) {
						window.showAutopenToast(response.data.message, 'info', 15000);
						processSteps($button, originalText);
					} else {
						window.showAutopenToast(response.data.message, 'error', 10000);
						$button.html(originalText).prop('disabled', false);
					}
				})
				.fail((jqXHR, textStatus, errorThrown) => {
					handleAjaxError(jqXHR, textStatus, errorThrown);
					$button.html(originalText).prop('disabled', false);
				});
		});
	}

	function processSteps($button, originalText) {
		$.post(autopen_ajax.ajax_url, { action: 'autopen_manual_step', nonce: autopen_ajax.nonce })
			.done(response => {
				if (response.success) {
					window.showAutopenToast(response.data.message, 'info', 5000);
					if (!response.data.done) {
						processSteps($button, originalText);
					} else {
						const postId = response.data.post_id;
						const editLink = `${window.location.origin}/wp-admin/post.php?post=${postId}&action=edit`;
						const viewLink = response.data.view_link;
						const message = `Post #${postId} generated! <a href="${editLink}" target="_blank">Edit Post</a> | <a href="${viewLink}" target="_blank">View Post</a>`;
						window.showAutopenToast(message, 'success', 10000);
						setTimeout(() => window.location.reload(), 1500);
						$button.html(originalText).prop('disabled', false);
					}
				} else {
					window.showAutopenToast(response.data.message, 'error', 10000);
					$button.html(originalText).prop('disabled', false);
				}
			})
			.fail((jqXHR, textStatus, errorThrown) => {
				handleAjaxError(jqXHR, textStatus, errorThrown);
				$button.html(originalText).prop('disabled', false);
			});
	}

	function handleAjaxError(jqXHR, textStatus, errorThrown) {
		let errorMessage = 'A critical server error occurred.';
		
		if (textStatus === 'timeout') {
			errorMessage = 'The step timed out. This might be due to server limits. Please check your server\'s PHP execution time and memory limits.';
		} else if (jqXHR.status === 500) {
			errorMessage = 'Server internal error (HTTP 500). Please check your server error logs and PHP configuration.';
		} else if (jqXHR.status === 0) {
			errorMessage = 'Connection lost. The server may have terminated the request due to resource limits.';
		} else if (jqXHR.responseText) {
			errorMessage = 'Server error: ' + jqXHR.responseText.substring(0, 200);
		}
		
		window.showAutopenToast(errorMessage + ' Please check the plugin logs and your server error logs.', 'error', 15000);
	}

	function initApiKeyToggle() {
		$(document).on('click', '#autopen-toggle-key-visibility', function() {
			const $input = $('#autopen_options_api_key');
			const $icon = $(this).find('.dashicons');
			if ($input.attr('type') === 'password') {
				$input.attr('type', 'text');
				$icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
			} else {
				$input.attr('type', 'password');
				$icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
			}
		});
	}

	function initTopicCounter() {
		const $textarea = $('#autopen_options_topics_queue');
		if ( !$textarea.length ) return;
		const $counter = $('#autopen-topic-count');
		const updateCount = () => {
			const text = $textarea.val();
			const lines = text ? text.split('\n').filter(line => line.trim() !== '') : [];
			$counter.text(lines.length);
		};
		$textarea.on('input', updateCount);
		updateCount();
	}

	function updateSystemStatus() {
		$.post(autopen_ajax.ajax_url, { action: 'autopen_get_system_status', nonce: autopen_ajax.nonce })
			.done(response => {
				if (!response.success) { return; }
				const data = response.data;
				$('[data-stat="topics_queued"]').text(data.topics_queued);
				$('[data-stat="posts_generated"]').text(data.posts_generated);
				$('[data-stat="next_run"]').html(data.next_run || '<em>Not Scheduled</em>');
				
				const $statusWidget = $('#autopen-status-widget');
				$statusWidget.removeClass('autopen-status-loading autopen-status-active autopen-status-paused autopen-status-error').addClass('autopen-status-' + data.status_class);
				$statusWidget.find('.autopen-status-text').text(data.status_text);

				const apiKeyStatus = data.api_key_configured ? '<span class="ok">✔ Configured</span>' : '<span class="error">✖ Missing</span>';
				const cronStatus = data.cron_ok ? '<span class="ok">✔ Operational</span>' : '<span class="error">✖ Check Status</span>';
				$('[data-status="api_key_status"]').html(apiKeyStatus);
				$('[data-status="cron_status"]').html(cronStatus);
			})
			.fail(jqXHR => {
				console.error("AutoPen: AJAX call to get system status failed.", jqXHR.responseText);
				const $statusWidget = $('#autopen-status-widget');
				$statusWidget.removeClass('autopen-status-loading autopen-status-active autopen-status-paused').addClass('autopen-status-error');
				$statusWidget.find('.autopen-status-text').text('Error updating status');
			});
	}

})(jQuery);