/* ------------------------------------------------------------------ *
 * rasa-shim.js — shim globals Shopify untuk JS Dawn di WordPress
 * Dipakai: StandardEvents (parse-time di cart.js), routes, strings.
 * ------------------------------------------------------------------ */
(function () {
	document.documentElement.classList.add('js');

	// Dawn import StandardEvents dari CDN Shopify (module). Di Woo tidak ada,
	// jadi kita sediakan class stub yang behavior-nya no-op tapi struktur sama.
	if (!window.StandardEvents) {
		window.StandardEvents = {
			createViewEventElement: function (Element) {
				return Element;
			},
			CartLinesUpdateEvent: function () { },
			CartErrorEvent: function () { },
			CartNoteUpdateEvent: function () { },
		};
	}

	if (typeof window.Shopify === 'undefined') {
		window.Shopify = {};
	}
	window.Shopify.designMode = window.Shopify.designMode || false;
	window.Shopify.bind = window.Shopify.bind || function (el, ev, fn) { el.addEventListener(ev, fn); };
	window.Shopify.addListener = window.Shopify.addListener || function (ev, fn) { document.addEventListener(ev, fn); };
	window.Shopify.postLink = window.Shopify.postLink || function (action, options) {
		var form = document.createElement('form');
		form.method = 'post';
		form.action = action;
		for (var k in (options && options.data || {})) {
			var input = document.createElement('input');
			input.type = 'hidden';
			input.name = k;
			input.value = options.data[k];
			form.appendChild(input);
		}
		document.body.appendChild(form);
		form.submit();
	};
	window.Shopify.loadFeatures = window.Shopify.loadFeatures || function () { };
	window.Shopify.setSelectorByValue = window.Shopify.setSelectorByValue || function (id, value) {
		var el = document.getElementById(id);
		if (el) el.value = value;
	};
	window.Shopify.CountryProvinceSelector = window.Shopify.CountryProvinceSelector || function () { };
	window.Shopify.ModelViewerUI = window.Shopify.ModelViewerUI || {};
	window.Shopify.PaymentButton = window.Shopify.PaymentButton || { init: function () { } };
	window.Shopify.actions = window.Shopify.actions || {
		openCart: { configure: function () { } },
		updateCart: { configure: function () { } }
	};

	// Dawn StickyHeader (on-scroll-up: hide saat scroll down, muncul saat scroll up)
	if (!customElements.get('sticky-header')) {
		customElements.define(
			'sticky-header',
			class StickyHeader extends HTMLElement {
				connectedCallback() {
					this.header = document.getElementById('shopify-section-header');
					this.headerIsAlwaysSticky = this.getAttribute('data-sticky-type') === 'always';
					this.headerBounds = {};
					this.currentScrollTop = 0;
					this.preventReveal = false;

					if (!this.header) return;
					this.setHeaderHeight();
					window.addEventListener('resize', () => this.setHeaderHeight());

					this.observer = new IntersectionObserver((entries) => {
						this.headerBounds = entries[0].intersectionRect;
					});
					this.observer.observe(this.header);

					window.addEventListener('scroll', this.onScroll.bind(this), { passive: true });
				}

				setHeaderHeight() {
					document.documentElement.style.setProperty('--header-height', `${this.header.offsetHeight}px`);
				}

				onScroll() {
					const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
					const topBound = (this.headerBounds && this.headerBounds.bottom) ? this.headerBounds.bottom : 140;

					if (scrollTop > this.currentScrollTop && scrollTop > topBound) {
						if (this.preventHide) { this.currentScrollTop = scrollTop; return; }
						requestAnimationFrame(() => {
							this.header.classList.add('shopify-section-header-hidden', 'shopify-section-header-sticky');
						});
					} else if (scrollTop < this.currentScrollTop && scrollTop > topBound) {
						requestAnimationFrame(() => {
							this.header.classList.add('shopify-section-header-sticky', 'animate');
							this.header.classList.remove('shopify-section-header-hidden');
						});
					} else if (scrollTop <= (this.headerBounds ? this.headerBounds.top : 0)) {
						requestAnimationFrame(() => {
							this.header.classList.remove('shopify-section-header-hidden', 'shopify-section-header-sticky', 'animate');
						});
					}
					this.currentScrollTop = scrollTop;
				}
			}
		);
	}

	// Smooth skeleton shimmer -> fade-in saat image selesai load
	function initImagePreloaders() {
		var containerSel = '.card__media, .rasa-cs__media, .product-spotlight__media, .rasa-pdp-gallery__item, .banner__media';
		var imgs = document.querySelectorAll('.card__media img, .rasa-cs__media img, .product-spotlight__media img, .rasa-pdp-gallery__item img, .banner__media img');
		function markLoaded(img) {
			img.classList.add('is-loaded');
			var box = img.closest(containerSel);
			if (box) box.classList.add('is-loaded');
		}
		imgs.forEach(function (img) {
			if (img.complete && img.naturalWidth > 0) {
				markLoaded(img);
			} else {
				img.addEventListener('load', function () { markLoaded(img); }, { once: true });
				img.addEventListener('error', function () { markLoaded(img); }, { once: true });
			}
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initImagePreloaders);
	} else {
		initImagePreloaders();
	}

	// AJAX Add-to-Cart + Slide-out Cart Drawer (no page redirect/reload)
	function initRasaCartDrawer() {
		var drawer = document.getElementById('RasaCartDrawer');
		var bodyEl = document.getElementById('RasaCartDrawerBody');
		var footerEl = document.getElementById('RasaCartDrawerFooter');
		var subtotalEl = document.getElementById('RasaCartSubtotal');
		var checkoutBtn = document.getElementById('RasaCartCheckoutBtn');
		var cfg = window.RasaTheme || {};
		if (!drawer || !bodyEl) return;

		function getEndpoint(action) {
			var liveCfg = window.RasaTheme || cfg;
			if (liveCfg.wcAjaxUrl) {
				return liveCfg.wcAjaxUrl.replace('%%endpoint%%', action);
			}
			return '/?wc-ajax=' + encodeURIComponent(action);
		}

		function esc(s) {
			return String(s || '').replace(/[&<>"']/g, function (c) {
				return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
			});
		}

		function decodeHtmlEntities(s) {
			if (!s || typeof s !== 'string' || s.indexOf('&') === -1) return s || '';
			var txt = document.createElement('textarea');
			txt.innerHTML = s;
			return txt.value;
		}

		var emptyText = drawer.getAttribute('data-empty') || 'Dein Warenkorb ist leer.';
		var loadingText = drawer.getAttribute('data-loading') || 'Warenkorb wird geladen…';
		var cachedCart = null;
		try {
			var rawInit = drawer.getAttribute('data-initial-cart');
			if (rawInit) cachedCart = JSON.parse(rawInit);
		} catch (err) {}

		function updateHeaderBubble(count) {
			var bubbleWrap = document.getElementById('cart-icon-bubble');
			if (!bubbleWrap) return;
			var bubble = bubbleWrap.querySelector('.cart-count-bubble');
			if (count > 0) {
				if (!bubble) {
					bubble = document.createElement('div');
					bubble.className = 'cart-count-bubble';
					bubble.innerHTML = '<span aria-hidden="true"></span>';
					bubbleWrap.appendChild(bubble);
				}
				var span = bubble.querySelector('span[aria-hidden="true"]');
				if (span) span.textContent = count < 100 ? String(count) : '99+';
			} else if (bubble) {
				bubble.remove();
			}
		}

		function formatEurShort(num) {
			return Number(num || 0).toFixed(2).replace('.', ',') + ' €';
		}

		function updateFreeShipping(boxTextEl, boxBarEl, holderEl, subtotalNum) {
			if (!boxTextEl || !boxBarEl || !holderEl) return;
			var threshold = 59;
			var left = Math.max(0, threshold - Number(subtotalNum || 0));
			var pct = Math.min(100, Math.round((Number(subtotalNum || 0) / threshold) * 100));
			var reachedTpl = holderEl.getAttribute('data-free-reached') || '✓ Dein Versand ist <strong>kostenlos</strong>';
			var leftTpl = holderEl.getAttribute('data-free-left') || 'Noch <strong>%s</strong> bis zum kostenlosen Versand';
			boxTextEl.innerHTML = left <= 0 ? reachedTpl : leftTpl.replace('%s', formatEurShort(left));
			boxBarEl.style.width = pct + '%';
		}

		function syncCartPage(data) {
			var pageEl = document.getElementById('RasaCartPage');
			if (!pageEl || !data) return;
			var emptyEl = document.getElementById('RasaCartPageEmpty');
			var filledEl = document.getElementById('RasaCartPageFilled');
			var tbodyEl = document.getElementById('RasaCartPageTbody');
			var pageSubtotalEl = document.getElementById('RasaCartPageSubtotal');
			var shipTextEl = document.getElementById('RasaCartPageShipText');
			var shipBarEl = document.getElementById('RasaCartPageShipBar');
			var items = data.items || [];

			if (!items.length) {
				pageEl.classList.add('is-empty');
				if (emptyEl) emptyEl.hidden = false;
				if (filledEl) filledEl.hidden = true;
				return;
			}
			pageEl.classList.remove('is-empty');
			if (emptyEl) emptyEl.hidden = true;
			if (filledEl) filledEl.hidden = false;

			if (tbodyEl) {
				tbodyEl.innerHTML = items.map(function (it) {
					return '<tr class="cart-item" data-key="' + esc(it.key) + '" style="border-bottom:1px solid rgba(18,18,18,0.06);">'
						+ '<td class="cart-item__media" style="width:12rem;padding:2.4rem 2.4rem 2.4rem 0;vertical-align:top;">'
						+ '<a href="' + esc(it.url) + '" style="display:block;width:10rem;background:#efeee9;">'
						+ (it.image ? '<img src="' + esc(it.image) + '" alt="' + esc(it.name) + '" class="cart-item__image" width="100" height="120" style="width:10rem;height:12rem;object-fit:cover;display:block;">' : '')
						+ '</a></td>'
						+ '<td class="cart-item__details" style="padding:2.4rem 2rem 2.4rem 0;vertical-align:top;">'
						+ '<a href="' + esc(it.url) + '" class="cart-item__name h4 break" style="font-size:1.5rem;font-weight:400;color:#000;text-decoration:none;display:block;margin-bottom:.6rem;">' + esc(it.name) + '</a>'
						+ '<div class="product-option" style="font-size:1.4rem;color:rgba(18,18,18,0.75);margin-bottom:.4rem;">' + esc(it.unit_price || '') + '</div>'
						+ (it.variation ? '<div class="product-option" style="font-size:1.4rem;color:rgba(18,18,18,0.75);">' + esc(it.variation) + '</div>' : '')
						+ '</td>'
						+ '<td class="cart-item__quantity" style="padding:2.4rem 2rem 2.4rem 0;vertical-align:top;width:24rem;">'
						+ '<div class="cart-item__quantity-wrapper" style="display:flex;align-items:center;gap:1.2rem;">'
						+ '<div class="rasa-cart-page__qty">'
						+ '<button type="button" data-rasa-page-qty="' + (it.qty - 1) + '" aria-label="-">−</button>'
						+ '<span>' + it.qty + '</span>'
						+ '<button type="button" data-rasa-page-qty="' + (it.qty + 1) + '" aria-label="+">+</button>'
						+ '</div>'
						+ '<button type="button" class="rasa-cart-page__remove" data-rasa-page-qty="0" aria-label="Remove">'
						+ '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true" width="16" height="16" fill="currentColor"><path d="M14 3h-3.53a3.07 3.07 0 00-.6-1.65C9.44.82 8.8.5 8 .5s-1.44.32-1.87.85A3.06 3.06 0 005.53 3H2a.5.5 0 000 1h1.25v10c0 .28.22.5.5.5h8.5a.5.5 0 00.5-.5V4H14a.5.5 0 000-1zM6.91 1.98c.23-.29.58-.48 1.09-.48s.85.19 1.09.48c.2.24.3.6.36 1.02h-2.9c.05-.42.17-.78.36-1.02zm4.84 11.52h-7.5V4h7.5v9.5z"/><path d="M6.55 5.25a.5.5 0 00-.5.5v6a.5.5 0 001 0v-6a.5.5 0 00-.5-.5zM9.45 5.25a.5.5 0 00-.5.5v6a.5.5 0 001 0v-6a.5.5 0 00-.5-.5z"/></svg>'
						+ '</button></div></td>'
						+ '<td class="cart-item__totals right" style="padding:2.4rem 0;vertical-align:top;text-align:right;white-space:nowrap;">'
						+ '<span class="price price--end" style="font-size:1.6rem;color:#000;">' + esc(it.line_total) + '</span>'
						+ '</td></tr>';
				}).join('');
			}
			if (pageSubtotalEl) pageSubtotalEl.textContent = data.subtotal || '';
			updateFreeShipping(shipTextEl, shipBarEl, pageEl, data.subtotal_num || 0);
		}

		function renderCart(data) {
			if (!data) return;
			cachedCart = data;
			updateHeaderBubble(data.count || 0);
			syncCartPage(data);
			updateFreeShipping(document.getElementById('RasaCartDrawerShipText'), document.getElementById('RasaCartDrawerShipBar'), drawer, data.subtotal_num || 0);
			if (checkoutBtn && data.checkoutUrl) checkoutBtn.href = data.checkoutUrl;
			var items = data.items || [];
			if (!items.length) {
				bodyEl.innerHTML = '<div class="rasa-cart-drawer__empty">' + esc(emptyText) + '</div>';
				if (footerEl) footerEl.hidden = true;
				return;
			}
			bodyEl.innerHTML = items.map(function (it) {
				return '<div class="rasa-cart-drawer__item" data-key="' + esc(it.key) + '">'
					+ (it.image ? '<a href="' + esc(it.url) + '"><img class="rasa-cart-drawer__img" src="' + esc(it.image) + '" alt="' + esc(it.name) + '"></a>' : '<div class="rasa-cart-drawer__img"></div>')
					+ '<div>'
					+ '<a class="rasa-cart-drawer__title" href="' + esc(it.url) + '">' + esc(it.name) + '</a>'
					+ (it.unit_price ? '<div class="rasa-cart-drawer__meta">' + esc(it.unit_price) + '</div>' : '')
					+ (it.variation ? '<div class="rasa-cart-drawer__meta">' + esc(it.variation) + '</div>' : '')
					+ '<div style="display:flex;align-items:center;">'
					+ '<div class="rasa-cart-drawer__qty">'
					+ '<button type="button" data-rasa-qty="' + (it.qty - 1) + '" aria-label="-">−</button>'
					+ '<span>' + it.qty + '</span>'
					+ '<button type="button" data-rasa-qty="' + (it.qty + 1) + '" aria-label="+">+</button>'
					+ '</div>'
					+ '<button type="button" class="rasa-cart-drawer__remove" data-rasa-qty="0" aria-label="Remove">✕</button>'
					+ '</div>'
					+ '</div>'
					+ '<div class="rasa-cart-drawer__price">' + esc(it.line_total) + '</div>'
					+ '</div>';
			}).join('');
			if (subtotalEl) subtotalEl.textContent = data.subtotal || '';
			if (footerEl) footerEl.hidden = false;
		}

		if (cachedCart) {
			renderCart(cachedCart);
		}

		function openDrawer() {
			drawer.classList.add('active');
			drawer.setAttribute('aria-hidden', 'false');
			document.body.classList.add('overflow-hidden');
		}
		function closeDrawer() {
			drawer.classList.remove('active');
			drawer.setAttribute('aria-hidden', 'true');
			document.body.classList.remove('overflow-hidden');
		}

		drawer.querySelectorAll('[data-rasa-drawer-close]').forEach(function (el) {
			el.addEventListener('click', closeDrawer);
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && drawer.classList.contains('active')) closeDrawer();
		});

		function parseJsonResponse(r) {
			return r.text().then(function (txt) {
				try {
					return JSON.parse(txt);
				} catch (err) {
					return { success: false, data: { message: 'Invalid response' } };
				}
			});
		}

		bodyEl.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-rasa-qty]');
			if (!btn) return;
			var row = btn.closest('[data-key]');
			if (!row) return;
			var liveCfg = window.RasaTheme || cfg;
			var fd = new FormData();
			fd.append('nonce', liveCfg.nonce || '');
			fd.append('cart_item_key', row.getAttribute('data-key'));
			fd.append('quantity', btn.getAttribute('data-rasa-qty'));
			fetch(getEndpoint('rasa_update_cart_item'), { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(parseJsonResponse)
				.then(function (res) { if (res && res.success) renderCart(res.data); });
		});

		document.addEventListener('click', function (e) {
			var pBtn = e.target.closest('[data-rasa-page-qty]');
			if (!pBtn) return;
			var row = pBtn.closest('[data-key]');
			if (!row) return;
			e.preventDefault();
			var liveCfg = window.RasaTheme || cfg;
			var fd = new FormData();
			fd.append('nonce', liveCfg.nonce || '');
			fd.append('cart_item_key', row.getAttribute('data-key'));
			fd.append('quantity', pBtn.getAttribute('data-rasa-page-qty'));
			fetch(getEndpoint('rasa_update_cart_item'), { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(parseJsonResponse)
				.then(function (res) { if (res && res.success) renderCart(res.data); });
		});

		document.addEventListener('click', function (e) {
			var cartIcon = e.target.closest('#cart-icon-bubble');
			if (!cartIcon) return;
			e.preventDefault();
			e.stopPropagation();
			if (!cachedCart) {
				bodyEl.innerHTML = '<div class="rasa-cart-drawer__empty">' + esc(loadingText) + '</div>';
			}
			openDrawer();
			var liveCfg = window.RasaTheme || cfg;
			var fd = new FormData();
			fd.append('nonce', liveCfg.nonce || '');
			fetch(getEndpoint('rasa_get_cart'), { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(parseJsonResponse)
				.then(function (res) {
					if (res && res.success) {
						renderCart(res.data);
					} else if (!cachedCart) {
						bodyEl.innerHTML = '<div class="rasa-cart-drawer__empty">' + esc(emptyText) + '</div>';
					}
				})
				.catch(function () {
					if (!cachedCart) {
						bodyEl.innerHTML = '<div class="rasa-cart-drawer__empty">' + esc(emptyText) + '</div>';
					}
				});
		}, true);

		function handlePdpSubmit(e) {
			var pdpForm = (e.target && e.target.id === 'RasaPdpForm') ? e.target : (e.currentTarget && e.currentTarget.id === 'RasaPdpForm' ? e.currentTarget : null);
			if (!pdpForm) return;
			e.preventDefault();
			e.stopPropagation();
			if (pdpForm.dataset.submitting === '1') return;
			pdpForm.dataset.submitting = '1';

			var liveCfg = window.RasaTheme || cfg;
			var errBox = document.getElementById('RasaPdpError');
			if (errBox) { errBox.hidden = true; errBox.textContent = ''; }
			var submitBtn = pdpForm.querySelector('.product-form__submit');
			var origText = submitBtn ? submitBtn.textContent : '';
			if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.7'; }

			var fd = new FormData(pdpForm);
			fd.append('nonce', liveCfg.nonce || '');
			if (!fd.get('product_id') && fd.get('add-to-cart')) {
				fd.set('product_id', fd.get('add-to-cart'));
			}
			// Prevent WC_Form_Handler::add_to_cart_action from intercepting ?wc-ajax= with a 302 redirect.
			fd.delete('add-to-cart');

			fetch(getEndpoint('rasa_add_to_cart'), { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(parseJsonResponse)
				.then(function (res) {
					if (res && res.success) {
						renderCart(res.data);
						openDrawer();
					} else {
						var msg = (res && res.data && res.data.message) ? res.data.message : 'Produkt konnte nicht in den Warenkorb gelegt werden.';
						if (errBox) { errBox.textContent = decodeHtmlEntities(msg); errBox.hidden = false; }
					}
				})
				.catch(function () {
					if (errBox) {
						errBox.textContent = 'Verbindungsfehler. Bitte erneut versuchen.';
						errBox.hidden = false;
					}
				})
				.finally(function () {
					pdpForm.dataset.submitting = '0';
					if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; submitBtn.textContent = origText; }
				});
		}

		document.addEventListener('submit', handlePdpSubmit, true);
		var directPdpForm = document.getElementById('RasaPdpForm');
		if (directPdpForm) {
			directPdpForm.addEventListener('submit', handlePdpSubmit, false);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initRasaCartDrawer);
	} else {
		initRasaCartDrawer();
	}

	// Mobile Burger Menu Drawer reliability sync & language links inside drawer
	function initMobileMenuFix() {
		var detailsEl = document.getElementById('Details-menu-drawer-container');
		if (!detailsEl) return;
		var sectionHeader = document.querySelector('.section-header');

		function syncMenuPosition() {
			document.documentElement.style.setProperty('--viewport-height', window.innerHeight + 'px');
			if (sectionHeader) {
				document.documentElement.style.setProperty('--header-bottom-position', parseInt(sectionHeader.getBoundingClientRect().bottom, 10) + 'px');
			}
		}

		detailsEl.addEventListener('toggle', function () {
			syncMenuPosition();
			if (detailsEl.open) {
				requestAnimationFrame(function () {
					detailsEl.classList.add('menu-opening');
				});
				if (sectionHeader) sectionHeader.classList.add('menu-open');
				document.body.classList.add('overflow-hidden-tablet');
			} else {
				detailsEl.classList.remove('menu-opening');
				if (sectionHeader) sectionHeader.classList.remove('menu-open');
				document.body.classList.remove('overflow-hidden-tablet');
			}
		});

		detailsEl.querySelectorAll('a.menu-drawer__menu-item').forEach(function (lnk) {
			lnk.addEventListener('click', function () {
				detailsEl.classList.remove('menu-opening');
				detailsEl.removeAttribute('open');
				if (sectionHeader) sectionHeader.classList.remove('menu-open');
				document.body.classList.remove('overflow-hidden-tablet');
			});
		});

		var langForm = document.getElementById('HeaderDrawerLanguageForm');
		if (langForm) {
			langForm.querySelectorAll('a[data-rasa-lang]').forEach(function (a) {
				a.addEventListener('click', function (e) {
					e.preventDefault();
					var input = langForm.querySelector('input[name="locale_code"]');
					if (input) input.value = a.getAttribute('data-rasa-lang') || 'de';
					langForm.submit();
				});
			});
		}

		window.addEventListener('resize', function () {
			if (detailsEl.open) syncMenuPosition();
		}, { passive: true });
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initMobileMenuFix);
	} else {
		initMobileMenuFix();
	}

	// Checkout declined payment error clarity & unfreeze button guard
	function initCheckoutDeclineGuard() {
		if (!document.body.classList.contains('woocommerce-checkout')) return;

		var declineMsgDe = 'Zahlung abgelehnt. Bitte versuche es mit einer anderen Karte.';
		var declineMsgEn = 'Payment declined, please try another card.';
		var isDe = !document.documentElement.lang || document.documentElement.lang.toLowerCase().indexOf('de') === 0;
		var preferredMsg = isDe ? declineMsgDe : declineMsgEn;

		function checkErrors() {
			var banners = document.querySelectorAll('.wc-block-components-notice-banner.is-error, .woocommerce-error li, .woocommerce-NoticeGroup-checkout .woocommerce-error');
			banners.forEach(function (el) {
				var txt = (el.textContent || '').trim();
				if (/unable to process payment|something went wrong|abgelehnt|declined|failed|abgebrochen/i.test(txt)) {
					if (!el.dataset.rasaReplaced) {
						el.dataset.rasaReplaced = '1';
						el.textContent = preferredMsg;
					}
				}
			});

			// If error is visible, make sure checkout button is not left spinning indefinitely
			if (banners.length > 0) {
				var btn = document.querySelector('.wc-block-components-checkout-place-order-button, button#place_order');
				if (btn && btn.classList.contains('is-loading')) {
					btn.classList.remove('is-loading');
					btn.disabled = false;
				}
			}
		}

		var observer = new MutationObserver(function () {
			checkErrors();
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}

	// Header Account Popover Modal
	function initHeaderAccountPopover() {
		var trigger = document.getElementById('HeaderAccountTrigger');
		var popover = document.getElementById('HeaderAccountPopover');
		var closeBtn = document.getElementById('HeaderAccountClose');
		if (!trigger || !popover) return;

		function openPopover() {
			popover.hidden = false;
			popover.classList.add('is-open');
			trigger.setAttribute('aria-expanded', 'true');
		}

		function closePopover() {
			popover.hidden = true;
			popover.classList.remove('is-open');
			trigger.setAttribute('aria-expanded', 'false');
		}

		trigger.addEventListener('click', function (e) {
			if (trigger.getAttribute('aria-haspopup') === 'dialog') {
				e.preventDefault();
				if (popover.hidden) {
					openPopover();
				} else {
					closePopover();
				}
			}
		});

		if (closeBtn) {
			closeBtn.addEventListener('click', function (e) {
				e.preventDefault();
				closePopover();
			});
		}

		document.addEventListener('click', function (e) {
			if (!popover.hidden && !popover.contains(e.target) && !trigger.contains(e.target)) {
				closePopover();
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !popover.hidden) {
				closePopover();
				trigger.focus();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initCheckoutDeclineGuard();
			initHeaderAccountPopover();
		});
	} else {
		initCheckoutDeclineGuard();
		initHeaderAccountPopover();
	}
})();
