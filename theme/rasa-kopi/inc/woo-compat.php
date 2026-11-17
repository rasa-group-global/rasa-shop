<?php
/**
 * WooCommerce compatibility.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------ *
 * Cart / checkout / account URLs (mimic Shopify paths /cart /checkout)
 * ------------------------------------------------------------------ */
add_filter( 'woocommerce_store_api_transients', '__return_true' );

remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );

/* ------------------------------------------------------------------ *
 * Product loop — pakai card rasa sendiri
 * ------------------------------------------------------------------ */
remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10 );
remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );
remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_price', 10 );
remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_show_product_loop_sale_flash', 10 );
add_action( 'woocommerce_shop_loop_item', function () {
	global $product;
	echo rasa_product_card( $product ); // phpcs:ignore WordPress.Security.EscapeOutput
}, 5 );

/* ------------------------------------------------------------------ *
 * Breadcrumbs off (rasa pakai markup sendiri)
 * ------------------------------------------------------------------ */
add_filter( 'woocommerce_enable_breadcrumbs', '__return_false' );

/* ------------------------------------------------------------------ *
 * Quantity fields — pakai markup rasa di single product
 * ------------------------------------------------------------------ */
add_filter( 'woocommerce_quantity_input_class', function () {
	return array( 'quantity-input__input', 'field__input' );
} );

/* ------------------------------------------------------------------ *
 * Pagination
 * ------------------------------------------------------------------ */
add_filter( 'woocommerce_pagination', function ( $html ) {
	return preg_replace( '/woocommerce-pagination[^"]*/', 'pagination', $html );
} );

/* ------------------------------------------------------------------ *
 * Add-to-cart AJAX redirect — tetap di halaman (cart drawer)
 * & hapus alert notice bawaan WooCommerce ("added to your cart")
 * ------------------------------------------------------------------ */
add_filter( 'woocommerce_add_to_cart_redirect', function () {
	return wp_get_referer() ? wp_get_referer() : home_url( '/' );
} );
add_filter( 'wc_add_to_cart_message_html', '__return_empty_string' );

/* ------------------------------------------------------------------ *
 * Cart page
 * ------------------------------------------------------------------ */
add_filter( 'woocommerce_cart_is_empty', function ( $empty ) {
	return $empty;
} );

/* ------------------------------------------------------------------ *
 * Shop archive (/shop/) — hanya tampilkan produk aktif berharga (> 0)
 * agar placeholder "Bald im Shop" (0,00 €) tidak muncul di katalog
 * ------------------------------------------------------------------ */
add_action( 'woocommerce_product_query', function ( $q ) {
	if ( is_admin() ) return;
	if ( is_shop() && ! is_search() ) {
		$meta_query = (array) $q->get( 'meta_query' );
		$meta_query[] = array(
			'key'     => '_price',
			'value'   => 0,
			'compare' => '>',
			'type'    => 'NUMERIC',
		);
		$q->set( 'meta_query', $meta_query );
	}
} );

/* ------------------------------------------------------------------ *
 * Default Checkout Country = Germany (DE)
 * ------------------------------------------------------------------ */
add_filter( 'default_checkout_billing_country', function () {
	return 'DE';
} );
add_filter( 'default_checkout_shipping_country', function () {
	return 'DE';
} );

/* ------------------------------------------------------------------ *
 * Shopify Checkout parity: fields, layout hooks & shipping fragments
 * ------------------------------------------------------------------ */
add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false' );
add_filter( 'woocommerce_enable_order_notes_field', '__return_true' );

add_filter( 'woocommerce_order_button_text', function () {
	return function_exists( 'rasa_tr' ) ? rasa_tr( 'Jetzt bezahlen', 'Pay now' ) : 'Jetzt bezahlen';
} );

// Pindahkan payment section dari #order_review kanan ke Step 3 kiri (form-checkout.php)
remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );

// Sinkronisasi shipping options di Step 2 via WooCommerce AJAX update_order_review
add_filter( 'woocommerce_update_order_review_fragments', function ( $fragments ) {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $fragments;
	}
	ob_start();
	?>
	<div id="RasaCoStep2Shipping" class="rasa-co-shipping-box">
		<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
			<table class="rasa-co-shipping-table">
				<?php wc_cart_totals_shipping_html(); ?>
			</table>
		<?php else : ?>
			<p class="rasa-co-muted"><?php echo esc_html( rasa_tr( 'Für diese Bestellung ist kein Versand erforderlich.', 'No shipping required for this order.' ) ); ?></p>
		<?php endif; ?>
	</div>
	<?php
	$fragments['#RasaCoStep2Shipping'] = ob_get_clean();

	ob_start();
	echo '<span id="RasaCoMobileSummaryTotal">' . esc_html( function_exists( 'rasa_format_eur' ) ? rasa_format_eur( WC()->cart->get_total( 'edit' ), true ) : wp_strip_all_tags( WC()->cart->get_total() ) ) . '</span>';
	$fragments['#RasaCoMobileSummaryTotal'] = ob_get_clean();

	return $fragments;
} );

// Rapikan susunan & placeholder field billing agar persis Shopify Checkout
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	if ( isset( $fields['billing']['billing_company'] ) ) {
		unset( $fields['billing']['billing_company'] );
	}
	$tr = function ( $de, $en ) {
		return function_exists( 'rasa_tr' ) ? rasa_tr( $de, $en ) : $de;
	};
	if ( isset( $fields['billing']['billing_email'] ) ) {
		$fields['billing']['billing_email']['label']       = $tr( 'E-Mail', 'Email' );
		$fields['billing']['billing_email']['placeholder'] = $tr( 'E-Mail', 'Email' );
		$fields['billing']['billing_email']['priority']    = 5;
		$fields['billing']['billing_email']['class']       = array( 'form-row-wide' );
	}
	if ( isset( $fields['billing']['billing_country'] ) ) {
		$fields['billing']['billing_country']['label']    = $tr( 'Land / Region', 'Country / Region' );
		$fields['billing']['billing_country']['priority'] = 10;
		$fields['billing']['billing_country']['class']    = array( 'form-row-wide', 'address-field', 'update_totals_on_change' );
	}
	if ( isset( $fields['billing']['billing_first_name'] ) ) {
		$fields['billing']['billing_first_name']['label']       = $tr( 'Vorname', 'First name' );
		$fields['billing']['billing_first_name']['placeholder'] = $tr( 'Vorname', 'First name' );
		$fields['billing']['billing_first_name']['priority']    = 20;
		$fields['billing']['billing_first_name']['class']       = array( 'form-row-first' );
	}
	if ( isset( $fields['billing']['billing_last_name'] ) ) {
		$fields['billing']['billing_last_name']['label']       = $tr( 'Nachname', 'Last name' );
		$fields['billing']['billing_last_name']['placeholder'] = $tr( 'Nachname', 'Last name' );
		$fields['billing']['billing_last_name']['priority']    = 30;
		$fields['billing']['billing_last_name']['class']       = array( 'form-row-last' );
	}
	if ( isset( $fields['billing']['billing_address_1'] ) ) {
		$fields['billing']['billing_address_1']['label']       = $tr( 'Adresse', 'Address' );
		$fields['billing']['billing_address_1']['placeholder'] = $tr( 'Adresse', 'Address' );
		$fields['billing']['billing_address_1']['priority']    = 40;
		$fields['billing']['billing_address_1']['class']       = array( 'form-row-wide', 'address-field' );
	}
	if ( isset( $fields['billing']['billing_address_2'] ) ) {
		$fields['billing']['billing_address_2']['label']       = $tr( 'Wohnung, Etage usw. (optional)', 'Apartment, suite, etc. (optional)' );
		$fields['billing']['billing_address_2']['placeholder'] = $tr( 'Wohnung, Etage usw. (optional)', 'Apartment, suite, etc. (optional)' );
		$fields['billing']['billing_address_2']['required']    = false;
		$fields['billing']['billing_address_2']['priority']    = 50;
		$fields['billing']['billing_address_2']['class']       = array( 'form-row-wide', 'address-field' );
	}
	if ( isset( $fields['billing']['billing_postcode'] ) ) {
		$fields['billing']['billing_postcode']['label']       = $tr( 'Postleitzahl', 'Postal code' );
		$fields['billing']['billing_postcode']['placeholder'] = $tr( 'Postleitzahl', 'Postal code' );
		$fields['billing']['billing_postcode']['priority']    = 60;
		$fields['billing']['billing_postcode']['class']       = array( 'form-row-first', 'address-field' );
	}
	if ( isset( $fields['billing']['billing_city'] ) ) {
		$fields['billing']['billing_city']['label']       = $tr( 'Stadt', 'City' );
		$fields['billing']['billing_city']['placeholder'] = $tr( 'Stadt', 'City' );
		$fields['billing']['billing_city']['priority']    = 70;
		$fields['billing']['billing_city']['class']       = array( 'form-row-last', 'address-field' );
	}
	if ( isset( $fields['billing']['billing_phone'] ) ) {
		$fields['billing']['billing_phone']['label']       = $tr( 'Telefon (optional)', 'Phone (optional)' );
		$fields['billing']['billing_phone']['placeholder'] = $tr( 'Telefon (optional)', 'Phone (optional)' );
		$fields['billing']['billing_phone']['required']    = false;
		$fields['billing']['billing_phone']['priority']    = 80;
		$fields['billing']['billing_phone']['class']       = array( 'form-row-wide' );
	}
	if ( isset( $fields['order']['order_comments'] ) ) {
		$fields['order']['order_comments']['label']       = $tr( 'Hinweise zur Bestellung (optional)', 'Order notes (optional)' );
		$fields['order']['order_comments']['placeholder'] = $tr( 'Hinweise zur Lieferung oder Bestellung...', 'Notes about your order, e.g. special delivery notes...' );
	}
	return $fields;
} );
