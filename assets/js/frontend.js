(function ($) {
	'use strict';

	const cfg = window.iftpPblFrontend || {};

	// Maps a popup outcome status (see showOutcomeNotice()) to the confirmation key used
	// in this form's per-form overrides (see Field::build_confirmation_overrides()) —
	// "completed" is what the popup calls a successful payment, "paid" is what the admin
	// configures it as in the Payments builder panel.
	const CONFIRMATION_OVERRIDE_KEY = {
		completed: 'paid',
		pending: 'pending',
		cancelled: 'cancelled',
		failed: 'failed',
	};

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

	// Query params ifthenpay's hosted payment page can leave on the return URL: our own
	// tracking params (see IfthenpayPayload::build_gateway_urls()) plus ifthenpay's own, which
	// it appends regardless of whether our tracking params survive the round trip (they often
	// don't). All of these must be scrubbed from the address bar once read — left in place,
	// they get resubmitted with the next "Pay now" click since WPForms forms post back to the
	// current URL, corrupting values like `amount` on that next attempt.
	const RETURN_PARAM_KEYS = [
		'wpforms_pay',
		'iftp_payment_id',
		'iftp_gateway',
		'id',
		'amount',
		'requestId',
		'sk',
		'brand',
		'pan',
		'lang',
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

	class IfthenpayPaymentsForWpformsFront {
		constructor() {
			this.$loadingOverlay = null;
			this.allowProgrammaticSubmit = false;
			this.activeButton = null;
			this.activeForm = null;
			this.paymentSessionActive = false;
			this.scrollLockState = null;
			this.visibilityUpdateTimer = null;
			this.activeRuntimeError = false;

			// Pay By Link now opens in its own tab (see beginExternalPayment()) instead of
			// navigating this page away, so this page stays alive to watch for the outcome
			// by polling the payment's own status (watchExternalPayment()) — a message from
			// that tab (reportOutcomeAndClose()) can speed that up, but isn't relied on; see
			// beginExternalPayment()'s comment for why.
			this.activePayWindow = null;
			this.activePaymentContext = null;
			this.paymentResolved = false;

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

		/**
		 * Render the outcome of a payment attempt as a popup — used on return from ifthenpay's
		 * hosted payment page (see handleGatewayReturn()). This is a modal appended to <body>
		 * rather than inline markup on the field, because WPForms conditional logic can set the
		 * field to display:none, which would hide inline content right when it's needed most (the
		 * field the customer just paid on is often the one a later answer conditionally hides).
		 * Every outcome is final as shown: "completed"/"cancelled"/"failed" just state what
		 * happened (the pay button stays disabled) and "pending" explains a webhook will confirm
		 * it later. There is no in-place retry — re-attempting a payment is a brand new
		 * submission, so it goes through the normal form flow (a page reload) rather than
		 * silently reusing this field's state.
		 */
		showOutcomeNotice(status, $field, entryPreviewHtml) {
			const copy = {
				completed: {
					title: this.getFrontendText('paid_title', 'Payment received'),
					message: this.getFrontendText(
						'paid_message',
						'Your payment was successful. Thank you!'
					),
				},
				pending: {
					title: this.getFrontendText(
						'pending_title',
						'Payment processing'
					),
					message: this.getFrontendText(
						'pending_message',
						"We're waiting for your payment to be confirmed. You don't need to do anything else — this will update automatically once it's complete."
					),
				},
				cancelled: {
					title: this.getFrontendText('cancelled_title', 'Payment cancelled'),
					message: this.getFrontendText(
						'cancelled_message',
						'You cancelled the payment.'
					),
				},
				failed: {
					title: this.getFrontendText('failed_title', 'Payment failed'),
					message: this.getFrontendText(
						'failed_message',
						'Your payment could not be completed.'
					),
				},
			};

			const entry = copy[status] || copy.pending;

			// A form-level override (see Field::build_confirmation_overrides()) replaces the
			// global default message only when the admin actually typed one for this status —
			// an empty per-form field keeps the global default rather than showing a blank
			// popup. The message can contain rich HTML now that the builder field is a real
			// TinyMCE editor, so it's rendered with .html(), not .text() (see
			// renderOutcomeModal()).
			const override = this.getConfirmationOverride(status, $field);
			const message =
				(override.message && String(override.message).trim()) ||
				entry.message;

			this.activeRuntimeError = true;

			this.renderOutcomeModal(
				status,
				entry.title,
				message,
				entryPreviewHtml || ''
			);
		}

		/**
		 * Reads this status's per-form override (see Field::build_confirmation_overrides()),
		 * keyed by the builder's status name (e.g. "paid"), not the popup's status name
		 * (e.g. "completed") — see CONFIRMATION_OVERRIDE_KEY.
		 */
		getConfirmationOverride(status, $field) {
			const overrides =
				($field && $field.data('iftpConfirmations')) || {};

			return overrides[CONFIRMATION_OVERRIDE_KEY[status] || status] || {};
		}

		/**
		 * Decides whether this outcome should send the customer to a page/URL instead of
		 * showing the popup — only ever true for a "paid"/"pending" override configured with
		 * type "page" or "redirect" that actually resolved to a URL (see
		 * Field::build_confirmation_overrides(), which already falls back to "message" when it
		 * can't resolve one). Returns true when it navigated away, so the caller can skip
		 * showing/continuing to poll for the popup.
		 */
		handleOutcome(status, $field, entryPreviewHtml) {
			const override = this.getConfirmationOverride(status, $field);

			if (
				override.type &&
				override.type !== 'message' &&
				override.redirect_url
			) {
				window.location.href = override.redirect_url;
				return true;
			}

			this.showOutcomeNotice(status, $field, entryPreviewHtml);
			return false;
		}

		/**
		 * Lazily build the outcome popup's DOM (overlay + modal box) and wire its dismiss
		 * handlers. Built once and reused across calls so repeated poll updates
		 * (watchExternalPayment()) just swap its content rather than re-creating it.
		 */
		getOutcomeModalElements() {
			if (this.$outcomeModalOverlay?.length) {
				return this.$outcomeModalOverlay;
			}

			const $overlay = $(
				'<div class="iftp-pbl-outcome-modal-overlay" role="presentation">'
			);
			const $modal = $(
				'<div class="iftp-pbl-outcome-modal" role="dialog" aria-modal="true">'
			);
			const $close = $(
				'<button type="button" class="iftp-pbl-outcome-modal-close" aria-label="Close">'
			).html('&times;');
			const $title = $('<p class="iftp-pbl-outcome-modal-title">');
			const $message = $('<p class="iftp-pbl-outcome-modal-message">');
			const $entryPreview = $(
				'<div class="iftp-pbl-outcome-modal-entry-preview">'
			);

			$modal.append($close, $title, $message, $entryPreview);
			$overlay.append($modal);
			$('body').append($overlay);

			$close.on('click', () => this.dismissOutcomeModal());
			$overlay.on('click', (e) => {
				if (e.target === $overlay.get(0)) {
					this.dismissOutcomeModal();
				}
			});
			$(document).on('keydown.iftpOutcomeModal', (e) => {
				if (e.key === 'Escape' && $overlay.hasClass('iftp-pbl-is-open')) {
					this.dismissOutcomeModal();
				}
			});

			this.$outcomeModalOverlay = $overlay;
			this.$outcomeModalBox = $modal;
			this.$outcomeModalTitle = $title;
			this.$outcomeModalMessage = $message;
			this.$outcomeModalEntryPreview = $entryPreview;

			return $overlay;
		}

		/**
		 * Fill and (re)open the outcome popup. Called every time the outcome is known or
		 * re-checked (initial "pending" on return, then again on each poll tick) — it always
		 * shows, even if the customer already closed it once, since a status the customer
		 * dismissed while it was "pending" must still be able to reappear once it resolves.
		 *
		 * entryPreviewHtml is already-escaped markup built server-side by
		 * EntryPreviewRenderer::render() (see Process::maybe_build_entry_preview_html()) — never
		 * raw, unescaped field input — so setting it via .html() here is safe. message can be
		 * rich HTML too, either the plugin's own static default text or an admin-authored
		 * TinyMCE message sanitized server-side with wp_kses_post() (see
		 * Payments::sanitize_confirmations()) — never raw customer/field input.
		 */
		renderOutcomeModal(status, title, message, entryPreviewHtml) {
			this.getOutcomeModalElements();

			this.$outcomeModalTitle.text(title);
			this.$outcomeModalMessage.html(message);
			this.$outcomeModalEntryPreview.html(entryPreviewHtml || '');
			this.$outcomeModalEntryPreview.toggle(!!entryPreviewHtml);

			if (this.appliedOutcomeStatusClass) {
				this.$outcomeModalBox.removeClass(
					this.appliedOutcomeStatusClass
				);
			}
			this.appliedOutcomeStatusClass = 'iftp-pbl-outcome-' + status;
			this.$outcomeModalBox.addClass(this.appliedOutcomeStatusClass);

			this.$outcomeModalOverlay.addClass('iftp-pbl-is-open');
			this.lockScroll();
		}

		dismissOutcomeModal() {
			if (!this.$outcomeModalOverlay?.length) {
				return;
			}
			this.$outcomeModalOverlay.removeClass('iftp-pbl-is-open');
			this.unlockScroll();
		}

		/**
		 * Detect a return visit from ifthenpay's hosted payment page (success/error/cancel_url,
		 * see IfthenpayPayload::build_gateway_urls()). Pay By Link now always opens in its
		 * own tab (see beginExternalPayment()) rather than navigating the original form
		 * page away, so this only ever runs in that tab — report the outcome back to
		 * whichever page opened it and get out of the way (see reportOutcomeAndClose()).
		 * This deliberately does NOT branch on window.opener to decide that: a payment
		 * gateway page commonly sets Cross-Origin-Opener-Policy, which severs window.opener
		 * (and any other cross-window reference back to it) the moment this tab navigated
		 * to ifthenpay's domain — permanently, even after navigating back here. Relying on
		 * it to gate behavior would wrongly fall through to showing a second, orphaned
		 * outcome popup in this about-to-close tab.
		 */
		handleGatewayReturn() {
			const status = getReturnStatusFromUrl(window.location.href);
			if (!status) {
				// No recognized wpforms_pay status, but ifthenpay may still have appended its own
				// params (id, amount, requestId, sk, brand, pan, ...) on top of — or instead of —
				// ours. Scrub those too so a stale amount/requestId can't bleed into the next
				// "Pay now" attempt on this page.
				if (
					RETURN_PARAM_KEYS.some(
						(key) => getUrlSearchParam(window.location.href, key) !== ''
					)
				) {
					this.stripReturnParamsFromUrl();
				}
				return;
			}

			const paymentId = getUrlSearchParam(
				window.location.href,
				'iftp_payment_id'
			);

			this.stripReturnParamsFromUrl();

			if (!paymentId) {
				return;
			}

			this.reportOutcomeAndClose(status, paymentId);
		}

		/**
		 * Runs in the Pay By Link tab once ifthenpay redirects it back to our own
		 * success/error/cancel URL. Reports the real outcome to the server exactly as
		 * before (persisting "cancelled"/"failed" for a return_action of cancel/error — see
		 * Process::ajax_verify_payment()), opportunistically lets the tab that opened this
		 * one know immediately if that reference still works, then closes this tab —
		 * window.close() only succeeds on a tab the page itself opened via script, which
		 * describes every tab that can reach this method through our own flow, regardless
		 * of whether window.opener itself survived. The short fallback below only matters
		 * for the rare case this exact return URL is loaded some other way (bookmarked,
		 * reopened by hand) — window.close() silently no-ops there instead, and this page
		 * is still around a moment later to show the outcome itself.
		 */
		reportOutcomeAndClose(status, paymentId) {
			this.verifyPaymentReturn(status, paymentId, (ok, data) => {
				const resolvedStatus = String(
					data.status || (ok ? 'completed' : 'pending')
				);

				if (window.opener) {
					try {
						window.opener.postMessage(
							{
								iftpPblOutcome: true,
								paymentId: String(paymentId),
								status: resolvedStatus,
								entryPreviewHtml: data.entry_preview_html || '',
							},
							window.location.origin
						);
					} catch (e) {
						// window.opener existed but scripting into it is blocked (e.g. a
						// cross-origin-opener-policy boundary crossed while this tab was on
						// ifthenpay's own domain) — the original tab's own fallback polling
						// (watchExternalPayment()) will still pick this outcome up on its
						// next tick regardless.
					}
				}

				window.close();

				window.setTimeout(() => {
					if (document.hidden) {
						return;
					}

					// Still here — window.close() only works on a script-opened tab, so this
					// wasn't reached through our own flow. Show the outcome here instead of
					// leaving the customer looking at a bare, unexplained page.
					const $field = $('.iftp-pbl-live-field').first();
					if (!$field.length) {
						return;
					}
					const $button = $field
						.find('.iftp-pbl-pay-now-button')
						.first();
					$button.prop('disabled', resolvedStatus === 'completed');
					this.showOutcomeNotice(
						resolvedStatus,
						$field,
						data.entry_preview_html
					);
				}, 300);
			});
		}

		stripReturnParamsFromUrl() {
			try {
				const url = new URL(window.location.href);
				RETURN_PARAM_KEYS.forEach((key) => url.searchParams.delete(key));
				window.history.replaceState({}, document.title, url.toString());
			} catch (e) {
				// Cosmetic only — leaving the params in place is harmless.
			}
		}

		init() {
			this.handleGatewayReturn();

			// The best-effort fast path for hearing back from a Pay By Link tab this page
			// itself opened (see beginExternalPayment()/reportOutcomeAndClose()) — not
			// relied on for correctness, since window.opener can end up unusable there (see
			// beginExternalPayment()'s comment). Origin-checked since window.postMessage
			// delivers to any listener regardless of who sent it.
			window.addEventListener('message', (event) => {
				if (event.origin !== window.location.origin) {
					return;
				}
				const payload = event.data;
				if (!payload || payload.iftpPblOutcome !== true) {
					return;
				}
				this.handleExternalPaymentMessage(payload);
			});

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
				.toggleClass('iftp-pbl-is-blocked', fieldState.isBlocked)
				// A competing gateway field being active on the form isn't a
				// misconfiguration to explain to the visitor — just hide the whole
				// ifthenpay field (box + button) rather than showing a warning.
				.toggleClass(
					'iftp-pbl-gateway-conflict',
					fieldState.hasActiveCompetingGateway
				);

			this.renderWarning(
				$field.find('.iftp-pbl-config-warning').first(),
				this.getFrontendText(
					'warning_config_title',
					'Configuration Required'
				),
				[fieldState.disabledReason || 'This field is disabled.'],
				!fieldState.isConfigReady
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
				.find('.iftp-pbl-payment-id-input, .iftp-pbl-paid-now-return-input')
				.val('');
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

		_showLoadingOverlay(message) {
			const text = message || 'Processing payment...';
			const html =
				'<div class="iftp-loading-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.7);z-index:999999;display:flex;align-items:center;justify-content:center;">' +
				'<div style="text-align:center;">' +
				'<div style="width:40px;height:40px;border:3px solid rgba(0,0,0,0.1);border-top-color:#0077a1;border-radius:50%;animation:iftp-spin 0.8s linear infinite;margin:0 auto 16px;"></div>' +
				'<p style="margin:0;color:#374151;font-weight:500;max-width:280px;">' +
				$('<span>').text(text).html() +
				'</p>' +
				'</div></div>';
			this.$loadingOverlay = $(html).appendTo('body');
			this.lockScroll();
		}

		_updateLoadingOverlayText(message) {
			if (this.$loadingOverlay?.length) {
				this.$loadingOverlay.find('p').text(message || '');
			}
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
			$field.find('.iftp-pbl-runtime-warning').first().empty().hide();
			this.dismissOutcomeModal();

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

			// Opened synchronously, right here in the click handler, before any async work —
			// most browsers only allow window.open() to bypass their popup blocker when it
			// happens as the direct, immediate result of a user gesture. Opening it later,
			// after the AJAX round-trip below completes, reliably gets it silently blocked
			// instead. beginExternalPayment() below navigates this same, already-open tab
			// to the real payment URL once we have it back from the server.
			const payWindow = window.open('', '_blank');
			if (!payWindow) {
				this.showRuntimeError(
					$field,
					this.getFrontendText(
						'warning_popup_blocked',
						'Please allow pop-ups for this site to complete the payment, then try again.'
					)
				);
				return;
			}

			this.activeButton = $button;
			this.activeForm = $form;
			this.paymentSessionActive = true;

			this._disableButton($button);
			this._injectSpinnerStyle();
			this._showLoadingOverlay();
			this._prepareHiddenInputs($form);

			apiPost('iftp_pbl_create_pay_button_payment', {
				form_id: formId,
				gateway_key: gatewayKey,
				form_payload: $form.serialize(),
			})
				.done((response) => {
					const data = response?.data ?? {};

					if (!response?.success) {
						payWindow.close();
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
						payWindow.close();
						this.resetButton();
						this.closeOverlay();
						this.submitPaidForm($form);
						return;
					}

					const redirectUrl = String(
						data.iframe_url || data.redirect_url || ''
					);
					if (!redirectUrl) {
						payWindow.close();
						this.showRuntimeError(
							$field,
							'Unable to open payment. Please try again.'
						);
						this.resetButton();
						this.closeOverlay();
						return;
					}

					this.beginExternalPayment(
						$field,
						$button,
						$form,
						payWindow,
						redirectUrl,
						data.payment_id
					);
				})
				.fail((jqXHR) => {
					payWindow.close();
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

		/**
		 * Navigates the tab handlePayNowClick() already opened (synchronously, as part of
		 * the click itself — see the comment there) to ifthenpay's hosted payment page,
		 * instead of navigating this page away, so the form and this page's own loading
		 * spinner stay put while the customer completes payment there. This page then
		 * watches for the outcome two ways: a message from that tab the instant it lands
		 * back on our own success/error/cancel URL (see reportOutcomeAndClose(), a
		 * best-effort fast path — not guaranteed, see its own comment), and
		 * watchExternalPayment() below, which polls the payment's own status regardless and
		 * is what this whole flow actually depends on to be correct.
		 *
		 * Deliberately does NOT try to notice the customer closing the payment tab
		 * themselves via window.closed — polling that is a well-known pattern in general,
		 * but not a safe one for a cross-origin payment page specifically: the moment this
		 * tab navigates there, a Cross-Origin-Opener-Policy header (common on gateway
		 * pages, precisely to prevent this kind of cross-tab monitoring) can sever the
		 * window reference outright, which several browsers then reflect back as .closed
		 * already being true — reporting the payment cancelled seconds later regardless of
		 * what the customer is actually doing there. If the customer abandons the tab
		 * without it ever reaching our return URL, watchExternalPayment() simply keeps
		 * polling until it runs out of attempts and stops — no worse an outcome than not
		 * detecting the close at all, and never a false cancellation.
		 */
		beginExternalPayment($field, $button, $form, payWindow, redirectUrl, paymentId) {
			payWindow.location.href = redirectUrl;

			this.activePayWindow = payWindow;
			this.paymentResolved = false;
			this.activePaymentContext = { $field, $button, $form, paymentId };

			this._updateLoadingOverlayText(
				this.getFrontendText(
					'waiting_external_text',
					"Complete your payment in the tab that just opened — we'll update this page automatically."
				)
			);

			this.watchExternalPayment(paymentId, 0);
		}

		/**
		 * Polls the payment's own status from this page — the mechanism this whole flow
		 * actually relies on to be correct, since the payment tab's own message
		 * (reportOutcomeAndClose()) is only a best-effort speed-up, not guaranteed (see
		 * beginExternalPayment()'s comment on why). Uses return_action "poll" specifically
		 * — not "success" — so the server can tell this page's own background check apart
		 * from the payment tab's genuine report of an outcome (see
		 * Process::ajax_verify_payment()); that distinction is what data.returned reflects
		 * below.
		 *
		 * A bare "pending" status is never treated as final by itself — the customer may
		 * still be actively paying — UNLESS data.returned says the payment tab has already
		 * genuinely come back from ifthenpay for this payment (e.g. a Multibanco/Payshop
		 * reference was just generated, so there's nothing more happening on this visit
		 * even though the payment itself is still pending). Otherwise this keeps this
		 * page's spinner up until that happens or attempts run out, in which case it
		 * quietly stops polling and leaves everything as-is — a delayed reference still
		 * resolves later via the webhook regardless of whether anyone's here to see it.
		 */
		watchExternalPayment(paymentId, attempt) {
			const maxAttempts = 100;
			const intervalMs = 3000;

			if (this.paymentResolved) {
				return;
			}

			this.verifyPaymentReturn('poll', paymentId, (ok, data) => {
				if (this.paymentResolved) {
					return;
				}

				const status = String(data.status || 'pending');
				if (status !== 'pending' || data.returned) {
					this.resolveExternalPayment(status, data.entry_preview_html);
					return;
				}

				if (attempt < maxAttempts) {
					window.setTimeout(
						() => this.watchExternalPayment(paymentId, attempt + 1),
						intervalMs
					);
				}
			});
		}

		/**
		 * Receives the message the payment tab sends the instant it lands back on our own
		 * success/error/cancel URL (see reportOutcomeAndClose()) — a speed-up over waiting
		 * for this page's own next poll tick, when it manages to arrive at all.
		 */
		handleExternalPaymentMessage(payload) {
			if (!this.activePaymentContext || this.paymentResolved) {
				return;
			}
			if (
				String(payload.paymentId || '') !==
				String(this.activePaymentContext.paymentId || '')
			) {
				return;
			}

			this.resolveExternalPayment(
				String(payload.status || 'pending'),
				payload.entryPreviewHtml
			);
		}

		/**
		 * The single place that finalizes an external payment attempt, however its outcome
		 * was learned (handleExternalPaymentMessage() or watchExternalPayment()) — guarded
		 * by paymentResolved so only the first of those to arrive actually acts. Attempts
		 * to close the payment tab (wrapped, rather than gated on .closed first — see
		 * beginExternalPayment()'s comment on why that property can't be trusted here), then
		 * animates the form before showing the outcome: "closing" (paid/pending) or "shake"
		 * (cancelled/failed) — see animateFormOutcome().
		 */
		resolveExternalPayment(status, entryPreviewHtml) {
			if (this.paymentResolved) {
				return;
			}
			this.paymentResolved = true;

			if (this.activePayWindow) {
				try {
					this.activePayWindow.close();
				} catch (e) {
					// Reference may already be invalid — nothing more to do.
				}
			}
			this.activePayWindow = null;

			const context = this.activePaymentContext;
			this.activePaymentContext = null;
			if (!context) {
				return;
			}

			const { $field, $button, $form, paymentId } = context;

			this.closeOverlay();

			// A retry only makes sense for cancelled/failed — completed obviously
			// shouldn't be repeated, and pending already has a payment attempt working its
			// way through (e.g. a Multibanco reference), so a second click here would just
			// create a competing one rather than helping.
			if (status === 'completed' || status === 'pending') {
				$button.prop('disabled', true);
			} else {
				this.activeButton = $button;
				this.resetButton();
			}

			const animation =
				status === 'completed' || status === 'pending'
					? 'close'
					: 'shake';

			this.animateFormOutcome($form, animation, () => {
				this.handleOutcome(status, $field, entryPreviewHtml);

				// None of pending/cancelled/failed are actually final server-side — a
				// webhook can still confirm a Multibanco/Payshop reference as paid later,
				// or resolve an attempt that was reported cancelled/failed here. Keep a
				// light background watch running so the popup already on screen updates
				// to the paid outcome if that happens, instead of staying stale.
				if (status !== 'completed') {
					this.watchForLateCompletion($field, $button, $form, paymentId, 0);
				}
			});
		}

		/**
		 * Runs only after resolveExternalPayment() has already shown a pending/cancelled/
		 * failed popup — none of those are actually final server-side (see its own
		 * comment), so this keeps checking, much less eagerly than watchExternalPayment(),
		 * in case the payment later resolves to "completed" while the customer is still on
		 * this page. Re-plays the "closing" animation and swaps the popup to the paid
		 * outcome the moment that happens; otherwise just keeps checking until attempts run
		 * out.
		 */
		watchForLateCompletion($field, $button, $form, paymentId, attempt) {
			const maxAttempts = 60;
			const intervalMs = 20000;

			window.setTimeout(() => {
				this.verifyPaymentReturn('poll', paymentId, (ok, data) => {
					const status = String(data.status || 'pending');

					if (status === 'completed') {
						$button.prop('disabled', true);
						this.animateFormOutcome($form, 'close', () => {
							this.handleOutcome(
								'completed',
								$field,
								data.entry_preview_html
							);
						});
						return;
					}

					if (attempt < maxAttempts) {
						this.watchForLateCompletion(
							$field,
							$button,
							$form,
							paymentId,
							attempt + 1
						);
					}
				});
			}, intervalMs);
		}

		/**
		 * Plays the outcome animation and calls done() once it's finished (or immediately,
		 * if there's nothing to animate). "close" fades/recedes the form back as if it's
		 * closing behind the popup that's about to appear, and is left in that state (the
		 * transaction is done, one way or another). "shake" is a quick, self-reverting
		 * shake, since cancelled/failed both leave the form usable again for another
		 * attempt.
		 *
		 * Targets the outer .wpforms-container (WPForms' own wrapper — background, padding,
		 * border all live there), not the inner <form> itself: animating just the <form>
		 * leaves that outer box sitting there fully visible, empty, which reads as "nothing
		 * happened" rather than "the form closed." Falls back to $form itself if that
		 * wrapper isn't found, for older/non-standard render styles.
		 */
		animateFormOutcome($form, type, done) {
			const $target =
				$form && $form.length
					? $form.closest('.wpforms-container').length
						? $form.closest('.wpforms-container')
						: $form
					: $();

			if (!$target.length) {
				done();
				return;
			}

			if (type === 'shake') {
				$target.addClass('iftp-pbl-form-shake');
				window.setTimeout(() => {
					$target.removeClass('iftp-pbl-form-shake');
					done();
				}, 400);
				return;
			}

			// Fades/recedes first (opacity/transform/blur, see the CSS), then — once that's
			// visually finished — collapses the now-invisible box's height/margin/padding
			// down to nothing too via jQuery's own slideUp animation, so it stops reserving
			// its old space on the page instead of leaving a big empty gap where the form
			// used to be.
			$target.addClass('iftp-pbl-form-closing');
			window.setTimeout(() => {
				$target.slideUp(250, done);
			}, 320);
		}

		verifyPaymentReturn(status, paymentId, callback = () => {}) {
			apiPost('iftp_pbl_verify_payment', {
				payment_id: paymentId,
				return_action: status,
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
	 * For a single ifthenpay field block, check the payment box's own background
	 * colour — the actual surface the logos are drawn against — and replace logo
	 * src with the dark (white) version when that background is dark.
	 *
	 * Previously this sampled the *text* colour of the nearest label/heading
	 * anywhere in the form as a proxy for the theme; that probe could easily
	 * find nothing (a form with no other visible labels) or an element whose
	 * color doesn't represent this field's own background, silently skipping
	 * the swap — e.g. a solid-black logo (Apple Pay) staying black on a dark
	 * background and effectively disappearing. Reading the box's own
	 * background is direct and can't miss.
	 *
	 * @param {Element} fieldEl
	 */
	function applyThemeAwareLogos(fieldEl) {
		var box           = fieldEl.querySelector('.iftp-pbl-public-box') || fieldEl;
		var computedColor = window.getComputedStyle(box).backgroundColor;
		var luminance     = colorLuminance(computedColor);

		if (luminance < 0.5) {
			// Dark background → use white/dark-mode logos.
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
