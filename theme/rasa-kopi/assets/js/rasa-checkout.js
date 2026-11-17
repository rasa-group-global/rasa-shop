/* ------------------------------------------------------------------ *
 * rasa-checkout.js — 3-Step Shopify Checkout Controller
 * Mengatur Step 1 (Informationen) -> Step 2 (Versand) -> Step 3 (Zahlung)
 * di dalam satu <form class="woocommerce-checkout"> agar Revolut & WC hooks tetap utuh.
 * ------------------------------------------------------------------ */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	ready(function () {
		var form = document.querySelector('form.woocommerce-checkout');
		if (!form) return;

		var cfg = window.RasaCheckout || {};
		var i18n = cfg.i18n || {};
		var currentStep = 1;

		var panels = Array.prototype.slice.call(form.querySelectorAll('.rasa-co-panel'));
		var crumbs = Array.prototype.slice.call(form.querySelectorAll('.rasa-co-crumb--btn'));
		var errBox = document.getElementById('RasaCoStep1Error');

		function updateRecaps() {
			var emailEl = document.getElementById('billing_email');
			var firstEl = document.getElementById('billing_first_name');
			var lastEl = document.getElementById('billing_last_name');
			var addr1El = document.getElementById('billing_address_1');
			var addr2El = document.getElementById('billing_address_2');
			var zipEl = document.getElementById('billing_postcode');
			var cityEl = document.getElementById('billing_city');
			var countryEl = document.getElementById('billing_country');

			var emailVal = emailEl ? emailEl.value.trim() : '';
			var countryText = '';
			if (countryEl && countryEl.selectedOptions && countryEl.selectedOptions[0]) {
				countryText = countryEl.selectedOptions[0].text;
			}
			var nameStr = [firstEl ? firstEl.value.trim() : '', lastEl ? lastEl.value.trim() : ''].filter(Boolean).join(' ');
			var addrParts = [
				nameStr,
				addr1El ? addr1El.value.trim() : '',
				addr2El ? addr2El.value.trim() : '',
				[zipEl ? zipEl.value.trim() : '', cityEl ? cityEl.value.trim() : ''].filter(Boolean).join(' '),
				countryText
			].filter(Boolean);

			form.querySelectorAll('[data-recap="email"]').forEach(function (el) {
				el.textContent = emailVal || '—';
			});
			form.querySelectorAll('[data-recap="address"]').forEach(function (el) {
				el.textContent = addrParts.length ? addrParts.join(', ') : '—';
			});

			// Shipping method recap
			var shipLabel = i18n.freeShipping || 'Kostenlos';
			var checkedShip = form.querySelector('#RasaCoStep2Shipping input[type="radio"]:checked') || form.querySelector('#RasaCoStep2Shipping input[type="hidden"]');
			if (checkedShip) {
				var li = checkedShip.closest('li');
				if (li) {
					var lbl = li.querySelector('label');
					if (lbl) shipLabel = lbl.textContent.replace(/\s+/g, ' ').trim();
				}
			}
			form.querySelectorAll('[data-recap="shipping"]').forEach(function (el) {
				el.textContent = shipLabel;
			});
		}

		function validateStep1() {
			if (errBox) { errBox.hidden = true; errBox.textContent = ''; }
			var step1 = document.getElementById('RasaCoStep1');
			if (!step1) return true;

			var emailInput = document.getElementById('billing_email');
			if (emailInput) {
				var ev = emailInput.value.trim();
				if (!ev || ev.indexOf('@') === -1 || ev.indexOf('.') === -1) {
					emailInput.closest('.form-row')?.classList.add('woocommerce-invalid');
					if (errBox) {
						errBox.textContent = i18n.enterEmail || 'Bitte gib eine gültige E-Mail-Adresse ein.';
						errBox.hidden = false;
					}
					emailInput.focus();
					return false;
				}
			}

			var requiredRows = step1.querySelectorAll('.validate-required');
			var firstInvalid = null;
			requiredRows.forEach(function (row) {
				if (row.offsetParent === null) return; // hidden field (e.g. shipping_address unchecked)
				var input = row.querySelector('input, select, textarea');
				if (!input) return;
				var val = (input.value || '').trim();
				if (!val) {
					row.classList.add('woocommerce-invalid');
					if (!firstInvalid) firstInvalid = input;
				} else {
					row.classList.remove('woocommerce-invalid');
				}
			});

			if (firstInvalid) {
				if (errBox) {
					errBox.textContent = i18n.fillRequired || 'Bitte fülle alle Pflichtfelder der Lieferadresse aus.';
					errBox.hidden = false;
				}
				firstInvalid.focus();
				return false;
			}
			return true;
		}

		function goToStep(step) {
			step = parseInt(step, 10);
			if (isNaN(step) || step < 1 || step > 3) return;
			if (step > currentStep && currentStep === 1 && !validateStep1()) return;

			currentStep = step;
			updateRecaps();

			panels.forEach(function (p) {
				var pStep = parseInt(p.getAttribute('data-step'), 10);
				if (pStep === step) {
					p.hidden = false;
					p.classList.add('is-active');
				} else {
					p.hidden = true;
					p.classList.remove('is-active');
				}
			});

			crumbs.forEach(function (c) {
				var cStep = parseInt(c.getAttribute('data-goto-step'), 10);
				c.classList.remove('is-active', 'is-done');
				c.removeAttribute('aria-current');
				if (cStep === step) {
					c.classList.add('is-active');
					c.setAttribute('aria-current', 'step');
				} else if (cStep < step) {
					c.classList.add('is-done');
				}
			});

			// Trigger WooCommerce review update & resize saat masuk step 2/3 agar shipping & widget kartu Revolut ter-render rapi
			window.dispatchEvent(new Event('resize'));
			if (window.jQuery) {
				window.jQuery(document.body).trigger('update_checkout');
			}

			var headerEl = document.querySelector('.rasa-co-header');
			window.scrollTo({ top: headerEl ? headerEl.offsetTop : 0, behavior: 'smooth' });
		}

		// Step navigation buttons
		var nextShipping = document.getElementById('RasaCoNextToShipping');
		if (nextShipping) {
			nextShipping.addEventListener('click', function () { goToStep(2); });
		}
		var nextPayment = document.getElementById('RasaCoNextToPayment');
		if (nextPayment) {
			nextPayment.addEventListener('click', function () { goToStep(3); });
		}
		form.querySelectorAll('[data-goto-step]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var target = parseInt(btn.getAttribute('data-goto-step'), 10);
				if (target === currentStep) return;
				if (target > 1 && currentStep === 1 && !validateStep1()) return;
				goToStep(target);
			});
		});

		// Mobile Order Summary Toggle
		var summaryToggle = document.getElementById('RasaCoSummaryToggle');
		var rightPanel = document.getElementById('RasaCoRightPanel');
		if (summaryToggle && rightPanel) {
			summaryToggle.addEventListener('click', function () {
				var expanded = summaryToggle.getAttribute('aria-expanded') === 'true';
				summaryToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
				rightPanel.classList.toggle('is-open', !expanded);
			});
		}

		// Show Express Checkout block only if gateway actually rendered visible content
		function syncExpressVisibility() {
			var wrap = document.getElementById('RasaCoExpressWrap');
			var slot = document.getElementById('RasaCoExpressSlot');
			if (!wrap || !slot) return;
			var hasContent = Boolean(slot.querySelector('button, iframe, a, img') || (slot.children.length > 0 && slot.textContent.trim().length > 0));
			wrap.hidden = !hasContent;
		}
		syncExpressVisibility();

		// Discount / Coupon Apply
		var couponInput = document.getElementById('RasaCoCouponCode');
		var couponBtn = document.getElementById('RasaCoCouponApply');
		var couponMsg = document.getElementById('RasaCoCouponMsg');
		if (couponInput && couponBtn) {
			function applyCoupon() {
				var code = couponInput.value.trim();
				if (!code) {
					if (couponMsg) {
						couponMsg.textContent = i18n.couponEmpty || 'Bitte gib einen Rabattcode ein.';
						couponMsg.hidden = false;
					}
					return;
				}
				couponBtn.disabled = true;
				var endpoint = (cfg.wcAjaxUrl || '/?wc-ajax=%%endpoint%%').replace('%%endpoint%%', 'apply_coupon');
				var fd = new FormData();
				fd.append('coupon_code', code);
				fd.append('security', cfg.applyCouponNonce || '');
				fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.text(); })
					.then(function (html) {
						if (couponMsg) {
							var tmp = document.createElement('div');
							tmp.innerHTML = html;
							couponMsg.textContent = tmp.textContent.trim();
							couponMsg.hidden = !couponMsg.textContent;
						}
						couponInput.value = '';
						if (window.jQuery) {
							window.jQuery(document.body).trigger('update_checkout');
						}
					})
					.finally(function () {
						couponBtn.disabled = false;
					});
			}
			couponBtn.addEventListener('click', applyCoupon);
			couponInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					applyCoupon();
				}
			});
		}

		// WooCommerce jQuery event hooks: shipping update & checkout validation errors
		if (window.jQuery) {
			var $ = window.jQuery;
			$(document.body).on('updated_checkout', function () {
				updateRecaps();
				syncExpressVisibility();
			});
			$(document.body).on('checkout_error', function () {
				var invalidInStep1 = document.querySelector('#RasaCoStep1 .woocommerce-invalid');
				if (invalidInStep1) {
					goToStep(1);
				}
			});
		}
	});
})();
