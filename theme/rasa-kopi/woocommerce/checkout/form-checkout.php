<?php
/**
 * woocommerce/checkout/form-checkout.php — Shopify Dark Espresso 2-Column & 3-Step Checkout
 * Menjaga seluruh native hooks WooCommerce & Revolut Gateway tetap aktif dalam satu <form>.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );

$lang = function_exists( 'rasa_current_lang' ) ? rasa_current_lang() : 'de';
$tr   = function ( $de, $en ) {
	return function_exists( 'rasa_tr' ) ? rasa_tr( $de, $en ) : $de;
};
$cart_url   = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
$login_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
$total_text = ( function_exists( 'WC' ) && WC()->cart )
	? ( function_exists( 'rasa_format_eur' ) ? rasa_format_eur( WC()->cart->get_total( 'edit' ), true ) : wp_strip_all_tags( WC()->cart->get_total() ) )
	: '€0,00 EUR';
?>

<div class="rasa-co-notices-wrap">
	<?php do_action( 'woocommerce_before_checkout_form', $checkout ); ?>
</div>

<?php
if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo '<div class="rasa-co-login-required">' . esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) ) . '</div>';
	return;
}

$billing_fields = $checkout->get_checkout_fields( 'billing' );
$order_fields   = $checkout->get_checkout_fields( 'order' );
?>

<form name="checkout" method="post" class="checkout woocommerce-checkout rasa-co-form" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php esc_attr_e( 'Checkout', 'woocommerce' ); ?>">

	<!-- Mobile Collapsible Order Summary Bar (< 960px) -->
	<div class="rasa-co-mobile-bar">
		<button type="button" class="rasa-co-mobile-bar__btn" id="RasaCoSummaryToggle" aria-expanded="false" aria-controls="RasaCoRightPanel">
			<span class="rasa-co-mobile-bar__left">
				<svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M2.5 3.5h2l2.1 9.5h8.9l1.8-6.8H5.2"/><circle cx="8" cy="16.2" r="1.3"/><circle cx="15" cy="16.2" r="1.3"/></svg>
				<span class="rasa-co-mobile-bar__label-show"><?php echo esc_html( $tr( 'Bestellübersicht anzeigen', 'Show order summary' ) ); ?></span>
				<span class="rasa-co-mobile-bar__label-hide"><?php echo esc_html( $tr( 'Bestellübersicht verbergen', 'Hide order summary' ) ); ?></span>
				<svg class="rasa-co-mobile-bar__caret" viewBox="0 0 12 8" width="12" height="8" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M1 1.5l5 5 5-5"/></svg>
			</span>
			<span id="RasaCoMobileSummaryTotal" class="rasa-co-mobile-bar__total"><?php echo esc_html( $total_text ); ?></span>
		</button>
	</div>

	<div class="rasa-co-grid">
		<!-- ===================== KOLOM KIRI: FORM 3 STEP ===================== -->
		<div class="rasa-co-left">
			<div class="rasa-co-left__inner">

				<!-- Breadcrumb Stepper -->
				<nav class="rasa-co-breadcrumbs" aria-label="Checkout steps">
					<a href="<?php echo esc_url( $cart_url ); ?>" class="rasa-co-crumb rasa-co-crumb--link"><?php echo esc_html( $tr( 'Warenkorb', 'Shopping cart' ) ); ?></a>
					<span class="rasa-co-crumb__sep" aria-hidden="true">›</span>
					<button type="button" class="rasa-co-crumb rasa-co-crumb--btn is-active" data-goto-step="1" aria-current="step"><?php echo esc_html( $tr( 'Informationen', 'Information' ) ); ?></button>
					<span class="rasa-co-crumb__sep" aria-hidden="true">›</span>
					<button type="button" class="rasa-co-crumb rasa-co-crumb--btn" data-goto-step="2"><?php echo esc_html( $tr( 'Versand', 'Shipping' ) ); ?></button>
					<span class="rasa-co-crumb__sep" aria-hidden="true">›</span>
					<button type="button" class="rasa-co-crumb rasa-co-crumb--btn" data-goto-step="3"><?php echo esc_html( $tr( 'Zahlung', 'Payment' ) ); ?></button>
					<span class="rasa-co-crumb__sep" aria-hidden="true">›</span>
					<span class="rasa-co-crumb rasa-co-crumb--static"><?php echo esc_html( $tr( 'Prüfen', 'Review' ) ); ?></span>
				</nav>

				<!-- ===== STEP 1: KONTAK & ALAMAT PENGIRIMAN ===== -->
				<div class="rasa-co-panel is-active" data-step="1" id="RasaCoStep1">

					<!-- Express Checkout Slot (otomatis tampil bila ada tombol Express dari Revolut/gateway) -->
					<div id="RasaCoExpressWrap" class="rasa-co-express" hidden>
						<div class="rasa-co-express__title"><?php echo esc_html( $tr( 'Express Checkout', 'Express checkout' ) ); ?></div>
						<div id="RasaCoExpressSlot">
							<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>
						</div>
						<div class="rasa-co-or"><span><?php echo esc_html( $tr( 'ODER', 'OR' ) ); ?></span></div>
					</div>

					<div id="customer_details">
						<!-- Kontakt / Contact -->
						<div class="rasa-co-section">
							<div class="rasa-co-section__head">
								<h2 class="rasa-co-heading"><?php echo esc_html( $tr( 'Kontakt', 'Contact' ) ); ?></h2>
								<?php if ( ! is_user_logged_in() ) : ?>
									<a href="<?php echo esc_url( $login_url ); ?>" class="rasa-co-login-link"><?php echo esc_html( $tr( 'Anmelden', 'Log in' ) ); ?></a>
								<?php endif; ?>
							</div>

							<div class="rasa-co-fields">
								<?php
								if ( isset( $billing_fields['billing_email'] ) ) {
									woocommerce_form_field( 'billing_email', $billing_fields['billing_email'], $checkout->get_value( 'billing_email' ) );
								}
								?>
							</div>

							<label class="rasa-co-checkbox">
								<input type="checkbox" name="rasa_marketing_optin" value="1">
								<span><?php echo esc_html( $tr( 'Ja, ich möchte Neuigkeiten, Angebote und Erinnerungen per E-Mail erhalten. Abmeldung jederzeit möglich.', 'Yes, I would like to receive news, offers and reminders via email. Unsubscribe at any time.' ) ); ?></span>
							</label>
						</div>

						<!-- Lieferadresse / Delivery address -->
						<div class="rasa-co-section">
							<h2 class="rasa-co-heading"><?php echo esc_html( $tr( 'Lieferadresse', 'Delivery address' ) ); ?></h2>
							<div class="woocommerce-billing-fields__field-wrapper rasa-co-fields">
								<?php
								foreach ( $billing_fields as $key => $field ) {
									if ( 'billing_email' === $key ) {
										continue;
									}
									woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
								}
								?>
							</div>

							<?php if ( true === WC()->cart->needs_shipping_address() ) : ?>
							<div class="woocommerce-shipping-fields rasa-co-diff-shipping">
								<label class="rasa-co-checkbox" id="ship-to-different-address">
									<input id="ship-to-different-address-checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" <?php checked( apply_filters( 'woocommerce_ship_to_different_address_checked', 'shipping' === get_option( 'woocommerce_ship_to_destination' ) ? 1 : 0 ), 1 ); ?> type="checkbox" name="ship_to_different_address" value="1" />
									<span><?php echo esc_html( $tr( 'An eine andere Adresse liefern?', 'Ship to a different address?' ) ); ?></span>
								</label>
								<div class="shipping_address rasa-co-fields">
									<?php do_action( 'woocommerce_before_checkout_shipping_form', $checkout ); ?>
									<div class="woocommerce-shipping-fields__field-wrapper">
										<?php
										$shipping_fields = $checkout->get_checkout_fields( 'shipping' );
										foreach ( $shipping_fields as $key => $field ) {
											woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
										}
										?>
									</div>
									<?php do_action( 'woocommerce_after_checkout_shipping_form', $checkout ); ?>
								</div>
							</div>
							<?php endif; ?>
						</div>
					</div>

					<div id="RasaCoStep1Error" class="rasa-co-step-error" role="alert" hidden></div>

					<div class="rasa-co-actions">
						<a href="<?php echo esc_url( $cart_url ); ?>" class="rasa-co-back-link">‹ <?php echo esc_html( $tr( 'Zurück zum Warenkorb', 'Back to shopping cart' ) ); ?></a>
						<button type="button" id="RasaCoNextToShipping" class="rasa-co-btn-primary">
							<?php echo esc_html( $tr( 'Weiter zum Versand', 'Continue to shipping' ) ); ?>
						</button>
					</div>
				</div>

				<!-- ===== STEP 2: VERSAND / SHIPPING ===== -->
				<div class="rasa-co-panel" data-step="2" id="RasaCoStep2" hidden>
					<div class="rasa-co-recap">
						<div class="rasa-co-recap__row">
							<span class="rasa-co-recap__label"><?php echo esc_html( $tr( 'Kontakt', 'Contact' ) ); ?></span>
							<span class="rasa-co-recap__val" data-recap="email">—</span>
							<button type="button" class="rasa-co-recap__edit" data-goto-step="1"><?php echo esc_html( $tr( 'Ändern', 'Change' ) ); ?></button>
						</div>
						<div class="rasa-co-recap__row">
							<span class="rasa-co-recap__label"><?php echo esc_html( $tr( 'Liefern an', 'Ship to' ) ); ?></span>
							<span class="rasa-co-recap__val" data-recap="address">—</span>
							<button type="button" class="rasa-co-recap__edit" data-goto-step="1"><?php echo esc_html( $tr( 'Ändern', 'Change' ) ); ?></button>
						</div>
					</div>

					<div class="rasa-co-section">
						<h2 class="rasa-co-heading"><?php echo esc_html( $tr( 'Versandart', 'Shipping method' ) ); ?></h2>
						<div id="RasaCoStep2Shipping" class="rasa-co-shipping-box">
							<?php if ( WC()->cart && WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
								<table class="rasa-co-shipping-table">
									<?php wc_cart_totals_shipping_html(); ?>
								</table>
							<?php else : ?>
								<p class="rasa-co-muted"><?php echo esc_html( $tr( 'Kostenloser Standardversand / Wird im Checkout bestätigt.', 'Standard shipping / Confirmed at checkout.' ) ); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( ! empty( $order_fields ) ) : ?>
					<div class="rasa-co-section">
						<h2 class="rasa-co-heading"><?php echo esc_html( $tr( 'Anmerkung zur Bestellung', 'Order notes' ) ); ?></h2>
						<div class="rasa-co-fields">
							<?php
							foreach ( $order_fields as $key => $field ) {
								woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
							}
							?>
						</div>
					</div>
					<?php endif; ?>

					<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

					<div class="rasa-co-actions">
						<button type="button" class="rasa-co-back-link rasa-co-back-btn" data-goto-step="1">‹ <?php echo esc_html( $tr( 'Zurück zu Informationen', 'Return to information' ) ); ?></button>
						<button type="button" id="RasaCoNextToPayment" class="rasa-co-btn-primary">
							<?php echo esc_html( $tr( 'Weiter zur Zahlung', 'Continue to payment' ) ); ?>
						</button>
					</div>
				</div>

				<!-- ===== STEP 3: ZAHLUNG / PAYMENT ===== -->
				<div class="rasa-co-panel" data-step="3" id="RasaCoStep3" hidden>
					<div class="rasa-co-recap">
						<div class="rasa-co-recap__row">
							<span class="rasa-co-recap__label"><?php echo esc_html( $tr( 'Kontakt', 'Contact' ) ); ?></span>
							<span class="rasa-co-recap__val" data-recap="email">—</span>
							<button type="button" class="rasa-co-recap__edit" data-goto-step="1"><?php echo esc_html( $tr( 'Ändern', 'Change' ) ); ?></button>
						</div>
						<div class="rasa-co-recap__row">
							<span class="rasa-co-recap__label"><?php echo esc_html( $tr( 'Liefern an', 'Ship to' ) ); ?></span>
							<span class="rasa-co-recap__val" data-recap="address">—</span>
							<button type="button" class="rasa-co-recap__edit" data-goto-step="1"><?php echo esc_html( $tr( 'Ändern', 'Change' ) ); ?></button>
						</div>
						<div class="rasa-co-recap__row">
							<span class="rasa-co-recap__label"><?php echo esc_html( $tr( 'Versandart', 'Method' ) ); ?></span>
							<span class="rasa-co-recap__val" data-recap="shipping">—</span>
							<button type="button" class="rasa-co-recap__edit" data-goto-step="2"><?php echo esc_html( $tr( 'Ändern', 'Change' ) ); ?></button>
						</div>
					</div>

					<div class="rasa-co-section">
						<h2 class="rasa-co-heading"><?php echo esc_html( $tr( 'Zahlung', 'Payment' ) ); ?></h2>
						<p class="rasa-co-subtitle"><?php echo esc_html( $tr( 'Alle Transaktionen sind sicher und verschlüsselt.', 'All transactions are secure and encrypted.' ) ); ?></p>

						<div class="rasa-co-payment-box">
							<?php woocommerce_checkout_payment(); ?>
						</div>
					</div>

					<div class="rasa-co-actions rasa-co-actions--payment">
						<button type="button" class="rasa-co-back-link rasa-co-back-btn" data-goto-step="2">‹ <?php echo esc_html( $tr( 'Zurück zum Versand', 'Return to shipping' ) ); ?></button>
					</div>
				</div>

				<!-- Footer Legal Links inside Left Column -->
				<div class="rasa-co-legal-footer">
					<a href="<?php echo esc_url( home_url( '/agb/' ) ); ?>"><?php echo esc_html( $tr( 'Allgemeine Geschäftsbedingungen und Richtlinien', 'Terms and conditions and guidelines' ) ); ?></a>
					<span aria-hidden="true">·</span>
					<a href="<?php echo esc_url( home_url( '/widerrufsbelehrung/' ) ); ?>"><?php echo esc_html( $tr( 'Widerrufsbelehrung', 'Cancellation policy' ) ); ?></a>
					<span aria-hidden="true">·</span>
					<a href="<?php echo esc_url( home_url( '/datenschutz/' ) ); ?>"><?php echo esc_html( $tr( 'Datenschutz', 'Privacy policy' ) ); ?></a>
					<span aria-hidden="true">·</span>
					<a href="<?php echo esc_url( home_url( '/impressum/' ) ); ?>"><?php echo esc_html( $tr( 'Impressum', 'Imprint' ) ); ?></a>
				</div>
			</div>
		</div>

		<!-- ===================== KOLOM KANAN: ORDER SUMMARY / CART ===================== -->
		<aside class="rasa-co-right" id="RasaCoRightPanel" aria-label="<?php echo esc_attr( $tr( 'Bestellübersicht', 'Order summary' ) ); ?>">
			<div class="rasa-co-right__inner">
				<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
				<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

				<div id="order_review" class="woocommerce-checkout-review-order">
					<?php do_action( 'woocommerce_checkout_order_review' ); ?>
				</div>

				<!-- Coupon / Discount Code Input (di luar table fragment supaya fokus mengetik tidak hilang) -->
				<?php if ( wc_coupons_enabled() ) : ?>
				<div class="rasa-co-coupon">
					<input type="text" id="RasaCoCouponCode" class="rasa-co-coupon__input" placeholder="<?php echo esc_attr( $tr( 'Rabattcode', 'Discount code' ) ); ?>" autocomplete="off">
					<button type="button" id="RasaCoCouponApply" class="rasa-co-coupon__btn"><?php echo esc_html( $tr( 'Anwenden', 'Apply' ) ); ?></button>
				</div>
				<div id="RasaCoCouponMsg" class="rasa-co-coupon__msg" role="status" hidden></div>
				<?php endif; ?>

				<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
			</div>
		</aside>
	</div>

</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
