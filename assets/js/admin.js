(function ($) {
	'use strict';

	const panel =
		window.ifthenpayWpformsBuilder || window.ifthenpayWpformsField || {};
	const slug = panel.slug || 'iftp_pbl';
	const fieldType = panel.fieldType || panel.type || 'iftp_pbl_field';

	function getBuilderString(key, fallback) {
		return (
			(window.wpforms_builder && window.wpforms_builder[key]) || fallback
		);
	}

	function showBuilderWarning(message) {
		if (!message) {
			return;
		}

		if ($.alert) {
			$.alert({
				title: 'Heads up!',
				content: message,
				icon: 'fa fa-exclamation-circle',
				type: 'orange',
				buttons: {
					confirm: {
						text: getBuilderString('ok', 'OK'),
						btnClass: 'btn-confirm',
					},
				},
			});
			return;
		}

		window.alert($('<div>').html(message).text());
	}

	function hasBuilderFieldType(type) {
		if (!type) {
			return false;
		}

		const normalized = String(type);

		return (
			$('.wpforms-field-' + normalized).length > 0 ||
			$(
				'.wpforms-field[data-field-type="' +
					normalized +
					'"], .wpforms-field[data-type="' +
					normalized +
					'"]'
			).length > 0 ||
			$('input[name^="fields["][name$="[type]"]').filter(function () {
				return String($(this).val() || '') === normalized;
			}).length > 0
		);
	}

	function hasIftpField() {
		return hasBuilderFieldType(fieldType);
	}

	function hasGatewayKeySelected() {
		const select = $('#iftp_pbl_gateway_key');
		return !select.length || String(select.val() || '').trim() !== '';
	}

	function getMissingRequirements() {
		const missing = [];
		if (!hasIftpField()) {
			missing.push('ifthenpay field');
		}
		if (!hasGatewayKeySelected()) {
			missing.push('Gateway key');
		}
		return missing;
	}

	function getRequirementsMessage(missing) {
		const messages = panel.messages || {};
		if (!missing.length) {
			return '';
		}

		if (missing.indexOf('ifthenpay field') !== -1) {
			return (
				messages.fieldRequired ||
				'Add the ifthenpay field to this form before enabling ifthenpay.'
			);
		}
		return getBuilderString(
			'ifthenpay_gateway_key_required',
			messages.gatewayRequired ||
				'Select an ifthenpay gateway key in the Payments tab first.'
		);
	}

	function getEnableToggle() {
		return $(
			'.wpforms-panel-content-section-' +
				slug +
				' input[name="payments[' +
				slug +
				'][enable]"]'
		);
	}

	function getConfigWrap() {
		return $('#iftp-pbl-config-wrapper');
	}

	function syncConfigVisibility() {
		const toggle = getEnableToggle();
		const wrap = getConfigWrap();
		if (!toggle.length || !wrap.length) {
			return;
		}
		wrap.stop(true, true)[toggle.is(':checked') ? 'slideDown' : 'slideUp'](
			150
		);
	}

	function syncSelectedGatewayLabel() {}

	function getBuilderFormId() {
		try {
			const params = new URLSearchParams(window.location.search || '');
			return params.get('form_id') || params.get('id') || '';
		} catch (error) {
			return '';
		}
	}

	let gatewayMethodsRequestId = 0;

	function loadGatewayMethods(gatewayKey) {
		if (!panel.ajaxUrl || !panel.gatewayMethodsNonce || !gatewayKey) {
			syncDefaultPaymentMethods();
			scheduleRequirementsSync();
			return;
		}

		const messages = panel.messages || {};
		const requestId = ++gatewayMethodsRequestId;
		const $methodsPanel = $(
			'#wpforms-panel-field-' + slug + '-payment-methods'
		);
		const $defaultConfig = $('#iftp-pbl-global-config');

		$methodsPanel.add($defaultConfig).addClass('iftp-pbl-is-loading');

		if (
			$methodsPanel.length &&
			!$methodsPanel.find('.iftp-pbl-methods-loading').length
		) {
			$methodsPanel.prepend(
				$('<p>', {
					class: 'iftp-pbl-methods-loading',
					text:
						messages.loadingMethods || 'Loading payment methods...',
				})
			);
		}

		$.post(
			panel.ajaxUrl,
			{
				action: 'iftp_pbl_load_gateway_methods',
				nonce: panel.gatewayMethodsNonce,
				gateway_key: gatewayKey,
				form_id: getBuilderFormId(),
				description: $('#iftp_pbl_description').val() || '',
			},
			null,
			'json'
		)
			.done(function (response) {
				if (requestId !== gatewayMethodsRequestId) {
					return;
				}

				if (response?.success && response.data) {
					if (response.data.methods_html) {
						$methodsPanel.replaceWith(response.data.methods_html);
					}
					if (response.data.default_html) {
						$defaultConfig.replaceWith(response.data.default_html);
					}

					syncSelectedGatewayLabel();
					syncDefaultPaymentMethods();
					scheduleRequirementsSync();
					return;
				}

				showBuilderWarning(
					response?.data?.message ||
						messages.loadMethodsError ||
						'Unable to load the payment methods for this gateway. Please try again.'
				);
			})
			.fail(function (xhr) {
				if (requestId !== gatewayMethodsRequestId) {
					return;
				}
				showBuilderWarning(
					xhr?.responseJSON?.data?.message ||
						messages.loadMethodsError ||
						'Unable to load the payment methods for this gateway. Please try again.'
				);
			})
			.always(function () {
				if (requestId !== gatewayMethodsRequestId) {
					return;
				}
				$(
					'#wpforms-panel-field-' +
						slug +
						'-payment-methods, #iftp-pbl-global-config'
				)
					.removeClass('iftp-pbl-is-loading')
					.find('.iftp-pbl-methods-loading')
					.remove();
			});
	}

	function syncDefaultPaymentMethods() {
		const select = $('#iftp_pbl_default_method');
		if (!select.length) {
			return;
		}

		const selected = select.val() || '';
		const autoText =
			select.find('option[value=""]').first().text() || 'Default (Auto)';

		const $methodOptions = $('.iftp-pbl-method-enabled:checked').map(
			function () {
				const entity = String($(this).data('entity') || '');
				const label = String($(this).data('label') || entity);
				return entity
					? $('<option>', { value: entity, text: label })[0]
					: null;
			}
		);

		select
			.empty()
			.append($('<option>', { value: '', text: autoText }))
			.append($methodOptions);

		select.val(
			$methodOptions.filter((_, el) => $(el).val() === selected).length
				? selected
				: ''
		);
	}

	function syncPaymentRequirements() {
		const missing = getMissingRequirements();
		const hasMissing = missing.length > 0;
		const toggle = getEnableToggle();

		$('.iftp-pbl-builder-requirements-warning').toggle(hasMissing);

		if (toggle.length) {
			toggle.prop('disabled', hasMissing);
			if (hasMissing && toggle.is(':checked')) {
				toggle.prop('checked', false).trigger('change');
			}
		}

		return !hasMissing;
	}

	function syncFieldAlert() {
		const hasField = hasIftpField();
		const $alert = $('#wpforms-' + slug + '-field-alert');
		const $content = $('#wpforms-panel-content-section-payment-' + slug);
		$alert.toggleClass('wpforms-hidden', hasField);
		$content.toggleClass('wpforms-hidden', !hasField);
		if (!hasField) {
			const toggle = getEnableToggle();
			if (toggle.is(':checked')) {
				toggle.prop('checked', false).trigger('change');
			}
		}
	}

	let requirementsSyncTimer = null;

	function scheduleRequirementsSync() {
		window.clearTimeout(requirementsSyncTimer);
		requirementsSyncTimer = window.setTimeout(function () {
			syncPaymentRequirements();
			syncConfigVisibility();
			syncDefaultPaymentMethods();
			syncFieldAlert();
		}, 60);
	}

	function getActivationMessage($button) {
		return $button
			.closest('.iftp-pbl-no-accounts')
			.find('.iftp-pbl-activation-message')
			.first();
	}

	function setActivationMessage($button, message, status) {
		const $message = getActivationMessage($button);
		if (!$message.length) {
			return;
		}
		$message.removeClass('is-error is-success').text(String(message || ''));
		if (status) {
			$message.addClass('is-' + status);
		}
	}

	function requestMethodActivation($button) {
		const messages = panel.messages || {};
		const gatewayKey = String(
			$button.data('gateway-key') ||
				$('#iftp_pbl_gateway_key').val() ||
				''
		).trim();
		const entity = String($button.data('entity') || '').trim();
		const originalLabel =
			String($button.data('original-label') || '').trim() ||
			String($button.text() || '').trim() ||
			'Activate';

		if (
			!gatewayKey ||
			!entity ||
			!panel.ajaxUrl ||
			!panel.activationNonce
		) {
			setActivationMessage(
				$button,
				messages.activationServerError ||
					'Server error sending activation request.',
				'error'
			);
			return;
		}

		$button
			.data('original-label', originalLabel)
			.prop('disabled', true)
			.text(
				messages.sendingActivation || 'Sending activation request...'
			);
		setActivationMessage($button, '', '');

		$.post(
			panel.ajaxUrl,
			{
				action: 'iftp_pbl_activate_payment_method',
				nonce: panel.activationNonce,
				gateway_key: gatewayKey,
				entity,
			},
			null,
			'json'
		)
			.done(function (response) {
				if (response?.success) {
					setActivationMessage(
						$button,
						response.data?.message ||
							messages.activationSent ||
							'Your activation request has been sent to support.',
						'success'
					);
					$button.text(originalLabel);
					return;
				}

				setActivationMessage(
					$button,
					response?.data?.message ||
						messages.activationFailed ||
						'Failed to send the activation email. Please try again later.',
					'error'
				);
				$button.prop('disabled', false).text(originalLabel);
			})
			.fail(function (xhr) {
				const isCooldownError = xhr && Number(xhr.status || 0) === 429;
				setActivationMessage(
					$button,
					xhr?.responseJSON?.data?.message ||
						messages.activationServerError ||
						'Server error sending activation request.',
					isCooldownError ? 'success' : 'error'
				);
				$button.prop('disabled', isCooldownError).text(originalLabel);
			});
	}

	function interceptFieldAddWarnings(event) {
		const type = String(
			$(event.currentTarget).data('field-type') ||
				$(event.currentTarget).data('type') ||
				$(event.currentTarget).attr('data-field-type') ||
				''
		);

		if (
			type !== fieldType &&
			!$(event.currentTarget).hasClass('iftp-pbl-connection-required')
		) {
			return;
		}

		if (!panel.hasConnection) {
			event.preventDefault();
			event.stopImmediatePropagation();
			showBuilderWarning(
				getBuilderString(
					'ifthenpay_connection_required',
					panel.unusableReason ||
						'Connect your ifthenpay Backoffice Key before adding this field.'
				)
			);
			return;
		}

		if (!hasGatewayKeySelected()) {
			event.preventDefault();
			event.stopImmediatePropagation();
			showBuilderWarning(
				getBuilderString(
					'ifthenpay_gateway_key_required',
					'Select an ifthenpay gateway key in the Payments tab first.'
				)
			);
		}
	}

	$(function () {
		syncSelectedGatewayLabel();
		syncConfigVisibility();
		syncDefaultPaymentMethods();
		syncFieldAlert();

		$(document).on(
			'change',
			'.wpforms-panel-content-section-' +
				slug +
				' input[name="payments[' +
				slug +
				'][enable]"]',
			function () {
				if ($(this).is(':checked') && !syncPaymentRequirements()) {
					showBuilderWarning(
						getRequirementsMessage(getMissingRequirements())
					);
				}
				syncConfigVisibility();
			}
		);

		$(document).on('change', '#iftp_pbl_gateway_key', function () {
			syncSelectedGatewayLabel();
			loadGatewayMethods(String($(this).val() || '').trim());
			scheduleRequirementsSync();
		});

		$(document).on(
			'change',
			'.iftp-pbl-method-enabled',
			syncDefaultPaymentMethods
		);

		$(document).on('click', '.iftp-pbl-activate-method', function (event) {
			event.preventDefault();
			const $button = $(this);
			if (!$button.prop('disabled')) {
				requestMethodActivation($button);
			}
		});

		$(document).on(
			'click',
			'.wpforms-add-fields-button, .wpforms-field-button, .warning-modal.iftp-pbl-connection-required',
			interceptFieldAddWarnings
		);

		$(document).on(
			'wpformsFieldAdd wpformsFieldDelete wpformsFieldMove wpformsFieldUpdate',
			scheduleRequirementsSync
		);

		$(document).on(
			'change input',
			'input[name^="fields["][name$="[type]"]',
			scheduleRequirementsSync
		);

		syncPaymentRequirements();
	});
})(jQuery);
