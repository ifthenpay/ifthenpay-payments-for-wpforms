(function ($) {
	'use strict';

	const cfg = window.iftpPblFrontend || {};

	function apiPost(action, data) {
		return $.post(
			cfg.ajax_url,
			Object.assign({ action, nonce: cfg.ajax_nonce }, data || {}),
			null,
			'json'
		);
	}

	function getUrlSearchParam(href, key) {
		if (!href) {
			return '';
		}
		try {
			return (
				new URL(href, window.location.href).searchParams.get(key) || ''
			);
		} catch (e) {
			return '';
		}
	}

	function getReturnStatusFromUrl(href) {
		if (!href) {
			return '';
		}
		try {
			return String(
				new URL(href, window.location.href).searchParams.get(
					'wpforms_pay'
				) || ''
			).toLowerCase();
		} catch (e) {
			return '';
		}
	}

	// Map of WPForms field CSS class fragment -> internal field type.
	const FIELD_CLASS_TO_TYPE = [
		['wpforms-field-email', 'email'],
		['wpforms-field-name', 'name'],
		['wpforms-field-payment-total', 'payment-total'],
		['wpforms-field-payment-single', 'payment-single'],
		['wpforms-field-payment-checkbox', 'payment-checkbox'],
		['wpforms-field-payment-multiple', 'payment-multiple'],
		['wpforms-field-payment-select', 'payment-select'],
		['wpforms-field-payment-coupon', 'payment-coupon'],
		['iftp-pbl-live-field', 'iftp_pbl_field'],
	];

	// Field types allowed alongside an ifthenpay field (do not count as competing gateways).
	const ALLOWED_PAYMENT_FIELD_TYPES = [
		'iftp_pbl_field',
		'payment-single',
		'payment-checkbox',
		'payment-multiple',
		'payment-select',
		'payment-coupon',
		'coupon',
		'payment-total',
	];

	// CSS class fragments for the built-in WPForms payment fields (not considered competing).
	const NEUTRAL_PAYMENT_CLASSES = [
		'wpforms-field-payment-total',
		'wpforms-field-payment-coupon',
		'wpforms-field-payment-single',
		'wpforms-field-payment-checkbox',
		'wpforms-field-payment-multiple',
		'wpforms-field-payment-select',
	];

	// CSS class fragments for third-party payment gateways that conflict with ifthenpay.
	const COMPETING_GATEWAY_CLASSES = [
		'wpforms-field-paypal',
		'wpforms-field-square',
		'wpforms-field-stripe',
		'wpforms-field-authorize',
	];

	function classifyFieldType(fieldClass) {
		const match = FIELD_CLASS_TO_TYPE.find(
			([cls]) => fieldClass.indexOf(cls) !== -1
		);
		return match ? match[1] : 'text';
	}

	class IfthenpayPaymentsForWpformsFront {
		constructor() {
			this.$modal = null;
			this.$loadingOverlay = null;
			this.allowProgrammaticSubmit = false;
			this.activeButton = null;
			this.activeModalToken = '';
			this.activeForm = null;
			this.paymentHandled = false;
			this.paymentSessionActive = false;
			this.scrollLockState = null;
			this.modalTimer = null;
			this.visibilityUpdateTimer = null;
			this.activeRuntimeError = false;

			this.init();
		}

		getFormId($form) {
			const candidates = [
				$form.data('formid'),
				$form.data('form-id'),
				$form.attr('data-formid'),
				$form.attr('data-form-id'),
				$form.find('input[name="wpforms[id]"]').val(),
				$form.find('input[name="form_id"]').val(),
			];

			for (const candidate of candidates) {
				const id = parseInt(candidate, 10);
				if (id > 0) {
					return id;
				}
			}

			const idMatch = String($form.attr('id') || '').match(
				/wpforms-form-(\d+)/
			);
			return idMatch ? parseInt(idMatch[1], 10) || 0 : 0;
		}

		getFormConfig($form, gatewayKey) {
			const formId = this.getFormId($form);
			if (!formId) {
				return null;
			}

			const fields = {};

			$form.find('.wpforms-field').each(function (index) {
				const $field = $(this);
				const fieldId =
					$field.data('field-id') ||
					$field
						.find('input, select, textarea')
						.first()
						.attr('data-field-id');
				const fieldType = classifyFieldType(
					String($field.attr('class') || '')
				);
				const label = $field
					.find('label')
					.first()
					.text()
					.replace(/\s*\*\s*$/, '')
					.trim();

				if (fieldId) {
					fields[fieldId] = {
						id: parseInt(fieldId, 10),
						type: fieldType,
						label: label || 'Field #' + (index + 1),
					};
				}
			});

			return {
				id: formId,
				fields,
				payments: {
					iftp_pbl: { enable: '1', gateway_key: gatewayKey || '' },
				},
				settings: {
					form_title:
						$form.find('h2.wpforms-title').text() ||
						'Form ' + formId,
				},
			};
		}

		showRuntimeError($field, message) {
			const text = String(message || '').trim();
			if (!text || !$field || !$field.length) {
				return;
			}
			this.activeRuntimeError = true;
			this.renderWarning(
				$field.find('.iftp-pbl-runtime-warning').first(),
				this.getFrontendText(
					'warning_payment_error_title',
					'Unable to open payment'
				),
				[text],
				true
			);
		}

		init() {
			$(document).on('click', '.iftp-pbl-pay-now-button', (e) => {
				e.preventDefault();
				this.handlePayNowClick($(e.currentTarget));
			});

			$(document).on('submit', 'form.wpforms-form', (e) => {
				if (this.allowProgrammaticSubmit) {
					return true;
				}
				if (this.isFormBlockedByExternalPayment($(e.currentTarget))) {
					e.preventDefault();
					e.stopImmediatePropagation();
					return false;
				}
				return true;
			});

			$(document).on(
				'click',
				'form.wpforms-form .wpforms-submit, form.wpforms-form button[type="submit"], form.wpforms-form input[type="submit"]',
				(e) => {
					if (this.allowProgrammaticSubmit) {
						return true;
					}
					if (
						this.isFormBlockedByExternalPayment(
							$(e.currentTarget).closest('form')
						)
					) {
						e.preventDefault();
						e.stopImmediatePropagation();
						return false;
					}
					return true;
				}
			);

			window.setTimeout(() => this.syncSubmitButtonsVisibility(), 200);
			$(document).on('wpformsReady wpformsJSReady change', () =>
				this.scheduleVisibilitySync()
			);
			this.observeConditionalVisibilityChanges();
		}

		scheduleVisibilitySync() {
			window.clearTimeout(this.visibilityUpdateTimer);
			this.visibilityUpdateTimer = window.setTimeout(
				() => this.syncSubmitButtonsVisibility(),
				50
			);
		}

		observeConditionalVisibilityChanges() {
			if (!window.MutationObserver) {
				return;
			}
			$('form.wpforms-form')
				.has('.iftp-pbl-live-field')
				.each((_, form) => {
					new MutationObserver(() =>
						this.scheduleVisibilitySync()
					).observe(form, {
						attributes: true,
						attributeFilter: ['class', 'style', 'hidden'],
						subtree: true,
					});
				});
		}

		getFrontendText(key, fallback) {
			return cfg?.[key] ? String(cfg[key]) : fallback;
		}

		setReturnPayload($form, payload) {
			if (!$form || !$form.length) {
				return;
			}
			const data = payload || {};
			$form
				.find('.iftp-pbl-transaction-id-input')
				.val(data.transaction_id || '')
				.end()
				.find('.iftp-pbl-payment-id-input')
				.val(data.payment_id || '')
				.end()
				.find('.iftp-pbl-payment-session-input')
				.val(data.modal_token || '')
				.end()
				.find('.iftp-pbl-paid-now-return-input')
				.val(JSON.stringify(data));
		}

		getVisibilityState($element) {
			const className = String($element.attr('class') || '');
			const inlineStyle = String($element.attr('style') || '');
			const computedStyle =
				$element.length && window.getComputedStyle
					? window.getComputedStyle($element.get(0))
					: null;
			const isConditionallyHidden =
				className.indexOf('wpforms-conditional-hide') !== -1;
			const isConditionallyShown =
				className.indexOf('wpforms-conditional-show') !== -1;
			const hasDisplayNone =
				/display\s*:\s*none/i.test(inlineStyle) ||
				(computedStyle && computedStyle.display === 'none');
			const isVisible = $element.is(':visible') && !hasDisplayNone;

			return {
				isConditionallyHidden,
				isConditionallyShown,
				hasDisplayNone,
				isVisible,
				isHiddenByConditions: hasDisplayNone && isConditionallyHidden,
				isActive:
					isVisible && !(hasDisplayNone && isConditionallyHidden),
			};
		}

		isCompetingGatewayField($element, $currentField) {
			if (
				!$element.length ||
				($currentField && $element.is($currentField))
			) {
				return false;
			}
			if ($element.closest('.iftp-pbl-live-field').length) {
				return false;
			}

			const className = String(
				$element.attr('class') || ''
			).toLowerCase();
			const provider = String(
				$element.data('provider') || ''
			).toLowerCase();
			const fieldType = String(
				$element.data('field-type') || $element.data('type') || ''
			).toLowerCase();

			if (ALLOWED_PAYMENT_FIELD_TYPES.indexOf(fieldType) !== -1) {
				return false;
			}
			if (
				NEUTRAL_PAYMENT_CLASSES.some((c) => className.indexOf(c) !== -1)
			) {
				return false;
			}
			if (provider && provider !== 'iftp_pbl') {
				return true;
			}
			return COMPETING_GATEWAY_CLASSES.some(
				(c) => className.indexOf(c) !== -1
			);
		}

		getActiveCompetingGatewayFields($form, $currentField) {
			return $form.find('.wpforms-field').filter((_, el) => {
				const $element = $(el);
				const visibility = this.getVisibilityState($element);
				return (
					this.isCompetingGatewayField($element, $currentField) &&
					(visibility.isActive || visibility.isConditionallyShown)
				);
			});
		}

		getIfthenpayFieldState($form, $field) {
			const $fieldContainer = $field.closest('.wpforms-field');
			const visibility = this.getVisibilityState(
				$fieldContainer.length ? $fieldContainer : $field
			);
			const isConfigReady =
				String($field.data('iftp-config-ready') || '1') === '1';
			const disabledReason = String(
				$field.data('iftp-disabled-reason') || ''
			).trim();
			const hasActiveCompetingGateway =
				this.getActiveCompetingGatewayFields($form, $field).length > 0;
			const canUseIfthenpay =
				isConfigReady &&
				visibility.isActive &&
				!hasActiveCompetingGateway;

			return $.extend({}, visibility, {
				isConfigReady,
				disabledReason,
				hasActiveCompetingGateway,
				canUseIfthenpay,
				isBlocked: !canUseIfthenpay,
			});
		}

		renderWarning($target, title, messages, show) {
			if (!$target.length) {
				return;
			}
			if (!show) {
				$target.empty().hide();
				return;
			}

			const $body = $('<div class="wpforms-iftp-pbl-warning-body">');
			(messages || [])
				.filter((m) => String(m || '').trim() !== '')
				.forEach((m) =>
					$body.append(
						$('<p class="wpforms-iftp-pbl-warning-message">').text(
							m
						)
					)
				);

			$target
				.empty()
				.append(
					$('<div class="wpforms-iftp-pbl-warning-div">').append(
						$('<p class="wpforms-iftp-pbl-warning-title">').text(
							title
						),
						$body
					)
				)
				.show();
		}

		formHasChosenPaymentAmount($form) {
			const $paymentFields = $form.find(
				'.wpforms-field-payment-single, .wpforms-field-payment-multiple, ' +
					'.wpforms-field-payment-checkbox, .wpforms-field-payment-select'
			);

			if (!$paymentFields.length) {
				return true;
			}

			let hasAmount = false;

			$paymentFields.each(function () {
				$(this)
					.find('input, select')
					.each(function () {
						const $input = $(this);
						const value = $input.val();

						if ($input.is(':disabled')) {
							return;
						}
						if (
							($input.is(':checkbox') || $input.is(':radio')) &&
							!$input.is(':checked')
						) {
							return;
						}

						if (Array.isArray(value)) {
							hasAmount = value.some(
								(entry) => String(entry || '').trim() !== ''
							);
							return !hasAmount;
						}

						if (String(value || '').trim() !== '') {
							hasAmount = true;
							return false;
						}
					});

				return !hasAmount;
			});

			return hasAmount;
		}

		applyIfthenpayFieldState($field, fieldState) {
			const $button = $field.find('.iftp-pbl-pay-now-button').first();

			$field
				.toggleClass('iftp-pbl-is-active', fieldState.canUseIfthenpay)
				.toggleClass('iftp-pbl-is-blocked', fieldState.isBlocked);

			this.renderWarning(
				$field.find('.iftp-pbl-config-warning').first(),
				this.getFrontendText(
					'warning_config_title',
					'Configuration Required'
				),
				[fieldState.disabledReason || 'This field is disabled.'],
				!fieldState.isConfigReady
			);

			this.renderWarning(
				$field.find('.iftp-pbl-conflict-warning').first(),
				this.getFrontendText(
					'warning_gateway_conflict_title',
					'Heads up! Another payment gateway is currently active'
				),
				[
					this.getFrontendText(
						'warning_gateway_conflict_message',
						'Another payment gateway is currently active on this form. The ifthenpay button is unavailable while that gateway is active.'
					),
				],
				fieldState.isConfigReady && fieldState.hasActiveCompetingGateway
			);

			if ($button.length) {
				$button.prop('disabled', fieldState.isBlocked);
				if (fieldState.isBlocked) {
					$button.attr('tabindex', '-1').attr('aria-hidden', 'true');
				} else {
					$button.removeAttr('tabindex').removeAttr('aria-hidden');
				}
			}
		}

		syncSubmitButtonsVisibility() {
			$('form.wpforms-form').each((_, form) => {
				const $form = $(form);
				let shouldHideWpformsSubmit = false;

				$form.find('.iftp-pbl-live-field').each((_i, field) => {
					const $field = $(field);
					const fieldState = this.getIfthenpayFieldState(
						$form,
						$field
					);
					this.applyIfthenpayFieldState($field, fieldState);

					if (fieldState.canUseIfthenpay) {
						shouldHideWpformsSubmit = true;
					}
				});

				$form.toggleClass(
					'iftp-pbl-hide-wpforms-button',
					shouldHideWpformsSubmit
				);
				$form
					.find(
						'.wpforms-submit, button[type="submit"], input[type="submit"]'
					)
					.toggleClass('wpforms-hidden', shouldHideWpformsSubmit)
					.prop('disabled', false);
				$form
					.find('.wpforms-submit-container')
					.toggle(!shouldHideWpformsSubmit);
			});
		}

		isFormBlockedByExternalPayment($form) {
			if (!$form || !$form.length) {
				return false;
			}
			return (
				$form.find('.iftp-pbl-live-field.iftp-pbl-is-active').length > 0
			);
		}

		_prepareHiddenInputs($form) {
			$form
				.find(
					'.iftp-pbl-payment-id-input, .iftp-pbl-transaction-id-input, .iftp-pbl-payment-session-input, .iftp-pbl-paid-now-return-input'
				)
				.val('');
			$form.find('.iftp-pbl-paid-now-clicked-input').val('1');
		}

		_disableButton($button) {
			if (!$button.data('iftp-original-label')) {
				$button.data(
					'iftp-original-label',
					String($button.text() || '').trim() || 'Pay now'
				);
			}
			$button
				.prop('disabled', true)
				.css({
					opacity: '0.6',
					'background-color': '#f3f4f6',
					color: '#d1d5db',
					cursor: 'not-allowed',
				})
				.text(
					this.getFrontendText('opening_text', 'Opening payment...')
				);
		}

		_injectSpinnerStyle() {
			if (!$('#iftp-spin-animation').length) {
				$('head').append(
					'<style id="iftp-spin-animation">@keyframes iftp-spin { to { transform: rotate(360deg); } }</style>'
				);
			}
		}

		_showLoadingOverlay() {
			const html =
				'<div class="iftp-loading-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.7);z-index:999999;display:flex;align-items:center;justify-content:center;">' +
				'<div style="text-align:center;">' +
				'<div style="width:40px;height:40px;border:3px solid rgba(0,0,0,0.1);border-top-color:#0077a1;border-radius:50%;animation:iftp-spin 0.8s linear infinite;margin:0 auto 16px;"></div>' +
				'<p style="margin:0;color:#374151;font-weight:500;">Processing payment...</p>' +
				'</div></div>';
			this.$loadingOverlay = $(html).appendTo('body');
			this.lockScroll();
		}

		handlePayNowClick($button) {
			if (this.paymentSessionActive) {
				return;
			}

			const $form = $button.closest('form');
			if (!$form.length) {
				return;
			}

			if (typeof $.fn.validate !== 'undefined' && !$form.valid()) {
				const $firstError = $form.find('.wpforms-error').first();
				if ($firstError.length) {
					$('html, body').animate(
						{ scrollTop: $firstError.offset().top - 100 },
						500
					);
				}
				return;
			}

			const $field = $button.closest('.iftp-pbl-live-field');

			if (!this.formHasChosenPaymentAmount($form)) {
				this.showRuntimeError(
					$field,
					this.getFrontendText(
						'warning_missing_amount',
						'The payment total is not ready yet. Please review the form and try again.'
					)
				);
				return;
			}

			const gatewayKey = String($button.data('gateway-key') || '');
			const formId = this.getFormId($form);
			const fieldState = this.getIfthenpayFieldState($form, $field);

			if (!fieldState.canUseIfthenpay) {
				this.syncSubmitButtonsVisibility();
				return;
			}

			if (!gatewayKey || !formId) {
				this.showRuntimeError(
					$field,
					!gatewayKey
						? 'The ifthenpay gateway key is missing.'
						: 'The WPForms form ID could not be detected.'
				);
				return;
			}

			this.activeButton = $button;
			this.activeForm = $form;
			this.paymentHandled = false;
			this.paymentSessionActive = true;

			this._disableButton($button);
			this._injectSpinnerStyle();
			this._showLoadingOverlay();
			this._prepareHiddenInputs($form);

			apiPost('iftp_pbl_create_pay_button_payment', {
				form_id: formId,
				gateway_key: gatewayKey,
				form_payload: $form.serialize(),
				form_data: JSON.stringify(this.getFormFieldData($form)),
				form_config: JSON.stringify(
					this.getFormConfig($form, gatewayKey)
				),
				clicked_pay_now: 1,
				iftp_pbl_field_visible: $field.is(':visible') ? '1' : '0',
				iftp_pbl_field_hidden_by_condition: $field.hasClass(
					'wpforms-conditional-hide'
				)
					? '1'
					: '0',
				iftp_pbl_field_conditionally_shown:
					fieldState.isConditionallyShown ? '1' : '0',
				iftp_pbl_field_conditionally_hidden:
					fieldState.isConditionallyHidden ? '1' : '0',
				iftp_pbl_has_active_conflict:
					fieldState.hasActiveCompetingGateway ? '1' : '0',
			})
				.done((response) => {
					const data = response?.data ?? {};

					if (!response?.success) {
						this.showRuntimeError(
							$field,
							data.message ||
								'Unable to create the payment link. Please review the form and try again.'
						);
						this.resetButton();
						this.closeOverlay();
						return;
					}

					if (data.skip_payment) {
						this.resetButton();
						this.closeOverlay();
						this.submitPaidForm($form);
						return;
					}

					$form
						.find('.iftp-pbl-payment-id-input')
						.val(data.payment_id || '');
					$form
						.find('.iftp-pbl-payment-session-input')
						.val(
							data.payment_session_token || data.modal_token || ''
						);
					this.openModal(data, $form);
				})
				.fail((jqXHR) => {
					this.showRuntimeError(
						$field,
						String(
							jqXHR?.responseJSON?.data?.message ||
								'Unable to reach the payment service. Please try again.'
						).trim()
					);
					this.resetButton();
					this.closeOverlay();
				});
		}

		getFormFieldData($form) {
			const fieldData = {};
			$form.find('input, select, textarea').each(function () {
				const $element = $(this);
				const name = $element.attr('name');
				const type = String($element.attr('type') || '');
				if (!name || type === 'button' || type === 'submit') {
					return;
				}
				fieldData[name] = $element.val();
			});
			return fieldData;
		}

		_buildModalHtml(iframeUrl) {
			return [
				'<div class="ifp-overlay" style="position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;z-index:2147483647;">',
				'<div class="ifp-container" style="position:relative;width:100vw;height:100vh;background:#fff;overflow:hidden;display:flex;flex-direction:column;">',
				'<div class="ifp-header" style="display:flex;align-items:center;gap:14px;padding:16px 24px;background:#fef3c7;border-bottom:1px solid #fcd34d;">',
				'<div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.6);display:flex;align-items:center;justify-content:center;flex-shrink:0;">',
				'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
				'</div>',
				'<div style="flex:1;min-width:0;">',
				'<p style="margin:0;font-size:20px;font-weight:600;color:#92400e;line-height:1.4;">Payment in progress</p>',
				'<p style="margin:2px 0 0;font-size:15px;font-weight: 500;color:#92400e;opacity:.85;line-height:1.4;">Closing this window will cancel the transaction.</p>',
				'</div>',
				'<button type="button" class="ifp-close" aria-label="Close" style="width:36px;height:36px;border:none;border-radius:50%;background:rgba(255,255,255,.5);cursor:pointer;display:flex;align-items:center;justify-content:center;color:#92400e;flex-shrink:0;transition:background .15s;" onmouseover="this.style.background=\'rgba(255,255,255,.8)\'" onmouseout="this.style.background=\'rgba(255,255,255,.5)\'">',
				'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
				'</button>',
				'</div>',
				'<iframe class="ifp-iframe" src="' +
					iframeUrl +
					'" allow="payment" frameborder="0" style="flex:1;border:none;width:100%;"></iframe>',
				'</div>',
				'</div>',
			].join('');
		}

		openModal(data, $form) {
			this.closeModal();
			this.activeModalToken = String(data.modal_token || '');

			const iframeUrl = String(
				data.iframe_url || data.redirect_url || ''
			);
			if (!iframeUrl) {
				this.resetButton();
				this.closeOverlay();
				return;
			}

			const $modal = $(this._buildModalHtml(iframeUrl)).appendTo('body');
			this.$modal = $modal;

			$modal.find('.ifp-close').on('click', () => {
				const modalToken = this.activeModalToken;
				this.closeModal();
				this.resetButton();
				this.closeOverlay();
				this.cancelPayment(modalToken, () => window.location.reload());
			});

			$modal.find('.ifp-iframe').on('load', () => {
				this.inspectPaymentFrame(
					$modal.find('.ifp-iframe'),
					$form,
					data,
					() => {
						this.closeModal();
						this.closeOverlay();
					}
				);
			});

			if (this.modalTimer) {
				window.clearInterval(this.modalTimer);
			}

			this.modalTimer = window.setInterval(() => {
				if (!this.$modal || !this.$modal.length) {
					window.clearInterval(this.modalTimer);
					this.modalTimer = null;
					return;
				}
				const $iframe = this.$modal.find('.ifp-iframe');
				if ($iframe.length) {
					this.inspectPaymentFrame($iframe, $form, data, () =>
						this.closeModal()
					);
				}
			}, 500);
		}

		inspectPaymentFrame($iframe, $form, data, done) {
			let href = '';
			try {
				href = $iframe.get(0).contentWindow.location.href;
			} catch (e) {
				return;
			}
			this.inspectReturnUrl(href, $form, done || function () {});
		}

		inspectReturnUrl(href, $form, done) {
			const status = getReturnStatusFromUrl(href);
			if (!status || this.paymentHandled) {
				return false;
			}

			const transactionId = getUrlSearchParam(href, 'transaction_id');
			const requestId = getUrlSearchParam(href, 'requestId');
			const paymentId =
				getUrlSearchParam(href, 'id') ||
				String($form.find('.iftp-pbl-payment-id-input').val() || '');

			const buildPayload = () => ({
				wpforms_pay: status,
				status,
				id: paymentId,
				payment_id: paymentId,
				transaction_id: transactionId,
				request_id: requestId,
				modal_token: this.activeModalToken || '',
				verified: false,
			});

			if (status === 'cancel' || status === 'error') {
				const modalToken = this.activeModalToken || '';
				const returnPayload = buildPayload();

				this.paymentHandled = true;
				this.setReturnPayload($form, returnPayload);
				this.closeOverlay();
				this.resetButton();
				done?.();
				this.cancelPayment(modalToken, () =>
					this.submitPaidForm($form)
				);

				return true;
			}

			if (status === 'success' && (transactionId || requestId)) {
				const returnPayload = buildPayload();

				this.paymentHandled = true;
				this.setReturnPayload($form, returnPayload);
				done?.();

				this.setButtonProcessing();
				this.verifyPaymentReturn(
					status,
					paymentId,
					transactionId,
					requestId,
					(ok, verifyData) => {
						if (ok) {
							returnPayload.verified = true;
							returnPayload.status =
								verifyData.status || 'completed';
							returnPayload.payment_method =
								verifyData.payment_method || '';
							returnPayload.transaction_id =
								verifyData.transaction_id ||
								returnPayload.transaction_id ||
								requestId;
							this.setReturnPayload($form, returnPayload);
							this.resetButton();
							this.submitPaidForm($form);
							this.closeOverlay();
						} else {
							returnPayload.status =
								verifyData.status || 'failed';
							returnPayload.payment_method =
								verifyData.payment_method || '';
							this.setReturnPayload($form, returnPayload);
							this.closeOverlay();
							this.resetButton();
							this.submitPaidForm($form);
						}
					}
				);

				return true;
			}

			return false;
		}

		verifyPaymentReturn(
			status,
			paymentId,
			transactionId,
			requestId,
			callback = () => {}
		) {
			apiPost('iftp_pbl_verify_payment', {
				payment_id: paymentId,
				return_action: status,
				transaction_id: transactionId,
				request_id: requestId,
			})
				.done((response) => {
					const data = response?.data ?? {};
					const ok =
						!!response?.success &&
						String(data.status || '') === 'completed';
					callback(ok, data);
				})
				.fail(() => callback(false, {}));
		}

		cancelPayment(modalToken, callback = () => {}) {
			if (!modalToken) {
				callback(false);
				return;
			}
			apiPost('iftp_pbl_cancel_payment', {
				modal_token: modalToken,
			}).always(() => callback(true));
		}

		setButtonProcessing() {
			if (this.activeButton?.length) {
				this.activeButton
					.text(
						this.getFrontendText(
							'processing_text',
							'Processing payment...'
						)
					)
					.prop('disabled', true);
			}
		}

		submitPaidForm($form) {
			if (!$form || !$form.length) {
				this.resetButton();
				this.closeOverlay();
				return;
			}

			this.allowProgrammaticSubmit = true;

			window.setTimeout(() => {
				try {
					const formEl = $form.get(0);
					if (formEl && typeof formEl.requestSubmit === 'function') {
						formEl.requestSubmit();
					} else if (formEl) {
						formEl.submit();
					}
				} finally {
					window.setTimeout(() => {
						this.allowProgrammaticSubmit = false;
					}, 50);
				}
			}, 50);
		}

		closeModal() {
			if (this.$modal?.length) {
				this.$modal.remove();
			}
			this.$modal = null;
			this.activeModalToken = '';

			if (this.modalTimer) {
				window.clearInterval(this.modalTimer);
				this.modalTimer = null;
			}
		}

		closeOverlay() {
			if (this.$loadingOverlay?.length) {
				this.$loadingOverlay.remove();
				this.$loadingOverlay = null;
			}
			this.unlockScroll();
		}

		lockScroll() {
			if (this.scrollLockState) {
				return;
			}

			const $html = $('html');
			const $body = $('body');

			this.scrollLockState = {
				htmlOverflow: $html.css('overflow'),
				bodyOverflow: $body.css('overflow'),
				bodyPaddingRight: $body.css('padding-right'),
			};

			const scrollbarWidth =
				window.innerWidth - document.documentElement.clientWidth;
			const currentPadding = parseFloat($body.css('padding-right')) || 0;

			$('html, body').css('overflow', 'hidden');

			if (scrollbarWidth > 0) {
				$body.css(
					'padding-right',
					currentPadding + scrollbarWidth + 'px'
				);
			}
		}

		unlockScroll() {
			if (!this.scrollLockState) {
				return;
			}

			$('html').css('overflow', this.scrollLockState.htmlOverflow || '');
			$('body')
				.css('overflow', this.scrollLockState.bodyOverflow || '')
				.css(
					'padding-right',
					this.scrollLockState.bodyPaddingRight || ''
				);

			this.scrollLockState = null;
		}

		resetButton() {
			this.paymentSessionActive = false;

			if (this.activeButton?.length) {
				const originalLabel =
					String(
						this.activeButton.data('iftp-original-label') || ''
					).trim() || 'Pay now';
				this.activeButton
					.text(originalLabel)
					.prop('disabled', false)
					.css({
						opacity: '1',
						'background-color': '',
						color: '',
						cursor: '',
					});
			}
			this.activeButton = null;
		}
	}

	new IfthenpayPaymentsForWpformsFront();
})(jQuery);

/* -----------------------------------------------------------------------
 * Theme-aware logo switcher
 * Detects whether the form's text colour is light (dark-background theme)
 * and swaps payment-method logos to their white variants when it is.
 * ----------------------------------------------------------------------- */
(function () {
	'use strict';

	/**
	 * Compute WCAG relative luminance (0 = black, 1 = white) from a CSS colour string.
	 *
	 * @param {string} cssColor  Computed colour value, e.g. "rgb(30, 30, 30)".
	 * @returns {number}
	 */
	function colorLuminance(cssColor) {
		var m = /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i.exec(cssColor);
		if (!m) { return 0; }
		var toLinear = function (c) {
			var s = parseInt(c, 10) / 255;
			return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
		};
		return 0.2126 * toLinear(m[1]) + 0.7152 * toLinear(m[2]) + 0.0722 * toLinear(m[3]);
	}

	/**
	 * Walk up from the field element to the form (or body) and return the
	 * first visible text element that we can sample for the theme colour.
	 *
	 * @param {Element} fieldEl
	 * @returns {Element|null}
	 */
	function findTextProbe(fieldEl) {
		var ctx = fieldEl.parentElement;
		while (ctx && ctx.tagName !== 'FORM' && ctx !== document.body) {
			ctx = ctx.parentElement;
		}
		if (!ctx) { return null; }
		return ctx.querySelector(
			'.wpforms-field-label, label, .wpforms-title, h1, h2, h3, p'
		);
	}

	/**
	 * For a single ifthenpay field block, check the surrounding text colour
	 * and replace logo src with the dark (white) version when text is light.
	 *
	 * @param {Element} fieldEl
	 */
	function applyThemeAwareLogos(fieldEl) {
		var probe = findTextProbe(fieldEl);
		if (!probe) { return; }

		var computedColor = window.getComputedStyle(probe).color;
		var luminance     = colorLuminance(computedColor);

		if (luminance > 0.5) {
			// Light text → dark background → use white logos.
			var imgs = fieldEl.querySelectorAll('img[data-logo-dark]');
			for (var i = 0; i < imgs.length; i++) {
				var darkSrc = imgs[i].getAttribute('data-logo-dark');
				if (darkSrc) { imgs[i].src = darkSrc; }
			}
		}
	}

	function initThemeLogos() {
		var fields = document.querySelectorAll('.iftp-pbl-live-field');
		for (var i = 0; i < fields.length; i++) {
			applyThemeAwareLogos(fields[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initThemeLogos);
	} else {
		initThemeLogos();
	}

	// Re-run when WPForms reinitialises (e.g. multi-step form navigation).
	document.addEventListener('wpformsReady', initThemeLogos);

	// Re-run when a dark-mode plugin toggles a class/style on <body> after DOMContentLoaded.
	// This fixes the race where the theme plugin runs its JS after the logo switcher already
	// sampled the (still-light) computed color.
	if (window.MutationObserver) {
		new MutationObserver(function () {
			initThemeLogos();
		}).observe(document.body, {
			attributes: true,
			attributeFilter: ['class', 'style'],
		});
	}
})();
