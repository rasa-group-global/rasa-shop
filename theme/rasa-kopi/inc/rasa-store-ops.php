<?php
/**
 * Store Operations, Compliance & Interface Bridge for Rasa Kopi.
 *
 * Implements runtime WooCommerce behaviors requested for DE/EU commerce:
 * - Tax: B2B customer role net-price display + EU B2B reverse charge (non-DE EU VAT ID),
 *   plus VAT ID (USt-IdNr.) Block Checkout & Classic Checkout field.
 * - Shipping: Inclusive gross Flat Rate conversion (4.90 € stays 4.90 € incl. 19% / 7% VAT)
 *   and automatic Flat Rate suppression once Free Shipping (>= 59 €) qualifies.
 * - Germanized / PAngV: Prevent accidental StoreaBill PDF invoice auto-creation and expose
 *   unit price (Grundpreis) + delivery time helpers.
 * - Interfaces: Product fields "Fulfilled by" (_rasa_fulfilled_by) and "Roaster" (_rasa_roaster)
 *   in WP Product Admin, WC REST API v3 (/wp-json/wc/v3/products & orders), and order line items.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RASA_META_FULFILLED_BY', '_rasa_fulfilled_by' );
define( 'RASA_META_ROASTER', '_rasa_roaster' );
define( 'RASA_META_DELIVERY_TIME', '_rasa_delivery_time' );
define( 'RASA_META_UNIT_PRICE', '_rasa_unit_price' );
define( 'RASA_META_VAT_ID', '_rasa_vat_id' );

/* ------------------------------------------------------------------ *
 * Shared Helpers
 * ------------------------------------------------------------------ */

/**
 * Check whether the current logged-in user holds the B2B customer role.
 */
function rasa_is_b2b_customer() {
	if ( ! function_exists( 'is_user_logged_in' ) || ! function_exists( 'wp_get_current_user' ) || ! is_user_logged_in() ) {
		return false;
	}
	$user = wp_get_current_user();
	if ( ! ( $user instanceof WP_User ) ) {
		return false;
	}
	return in_array( 'b2b_customer', (array) $user->roles, true );
}

/**
 * Normalize an EU VAT ID (USt-IdNr.) to uppercase without whitespace or punctuation.
 */
function rasa_sanitize_vat_id( $value ) {
	$clean = strtoupper( sanitize_text_field( (string) $value ) );
	return preg_replace( '/[^A-Z0-9]/', '', $clean );
}

/**
 * Validate EU VAT ID format (2-letter ISO/EL prefix + 2-12 alphanumeric characters).
 */
function rasa_is_valid_vat_id_format( $vat_id ) {
	if ( '' === $vat_id ) {
		return true;
	}
	return 1 === preg_match( '/^(AT|BE|BG|CY|CZ|DE|DK|EE|EL|ES|FI|FR|HR|HU|IE|IT|LT|LU|LV|MT|NL|PL|PT|RO|SE|SI|SK|XI)[0-9A-Z]{2,12}$/', $vat_id );
}

/**
 * Map a billing/shipping ISO country code to its VAT ID prefix (GR uses EL).
 */
function rasa_country_to_vat_prefix( $country_code ) {
	$code = strtoupper( trim( (string) $country_code ) );
	return ( 'GR' === $code ) ? 'EL' : $code;
}

/**
 * Translation wrapper safe for standalone/early calls before template-tags loads.
 */
function rasa_ops_tr( $de, $en ) {
	return function_exists( 'rasa_tr' ) ? rasa_tr( $de, $en ) : $de;
}

/**
 * Get product meta with automatic fallback to parent product for variations.
 */
function rasa_get_product_meta_inherited( $product, $meta_key ) {
	if ( ! ( $product instanceof WC_Product ) ) {
		return '';
	}
	$val = (string) $product->get_meta( $meta_key, true );
	if ( '' === $val && $product->get_parent_id() ) {
		$parent = wc_get_product( $product->get_parent_id() );
		if ( $parent instanceof WC_Product ) {
			$val = (string) $parent->get_meta( $meta_key, true );
		}
	}
	return trim( $val );
}

/* ------------------------------------------------------------------ *
 * 1. Tax: B2B Net Prices + VAT ID Field (Block & Classic Checkout)
 * ------------------------------------------------------------------ */

/**
 * Display net prices (excl. VAT) on storefront/cart/checkout/Store API for B2B accounts.
 */
function rasa_filter_b2b_tax_display( $value ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $value;
	}
	if ( rasa_is_b2b_customer() ) {
		return 'excl';
	}
	return $value;
}
add_filter( 'pre_option_woocommerce_tax_display_shop', 'rasa_filter_b2b_tax_display' );
add_filter( 'pre_option_woocommerce_tax_display_cart', 'rasa_filter_b2b_tax_display' );

/**
 * Apply EU intra-community reverse-charge VAT exemption before totals are calculated
 * when a logged-in B2B customer has a saved non-DE EU VAT ID matching shipping country.
 */
add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
	if ( ! $cart || ( is_admin() && ! defined( 'DOING_AJAX' ) ) ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->customer || ! rasa_is_b2b_customer() ) {
		return;
	}

	$user_id = get_current_user_id();
	$vat_id  = rasa_sanitize_vat_id( get_user_meta( $user_id, 'billing_vat_id', true ) );
	$ship_country = strtoupper( (string) WC()->customer->get_shipping_country() );
	if ( '' === $ship_country ) {
		$ship_country = strtoupper( (string) WC()->customer->get_billing_country() );
	}

	$eu_non_de = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK' );
	$exempt    = false;

	if ( '' !== $vat_id && rasa_is_valid_vat_id_format( $vat_id ) && in_array( $ship_country, $eu_non_de, true ) ) {
		$vat_prefix      = substr( $vat_id, 0, 2 );
		$expected_prefix = rasa_country_to_vat_prefix( $ship_country );
		if ( $vat_prefix === $expected_prefix && 'DE' !== $ship_country ) {
			$exempt = true;
		}
	}

	WC()->customer->set_is_vat_exempt( $exempt );
}, 20 );

/**
 * Register optional VAT ID (USt-IdNr.) field in WooCommerce Block Checkout (WC 8.9+).
 */
add_action( 'woocommerce_init', function () {
	if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
		return;
	}

	woocommerce_register_additional_checkout_field( array(
		'id'                => 'rasa-kopi/vat-id',
		'label'             => rasa_ops_tr( 'USt-IdNr. (optional, B2B)', 'VAT ID (optional, B2B)' ),
		'optionalLabel'     => rasa_ops_tr( 'USt-IdNr. (optional, B2B)', 'VAT ID (optional, B2B)' ),
		'location'          => 'contact',
		'type'              => 'text',
		'required'          => false,
		'attributes'        => array(
			'autocomplete'   => 'off',
			'autocapitalize' => 'characters',
		),
		'sanitize_callback' => 'rasa_sanitize_vat_id',
		'validate_callback' => function ( $value ) {
			$clean = rasa_sanitize_vat_id( $value );
			if ( '' !== $clean && ! rasa_is_valid_vat_id_format( $clean ) ) {
				return new WP_Error(
					'rasa_invalid_vat_id',
					rasa_ops_tr(
						'Bitte gib eine gültige EU USt-IdNr. ein (z. B. DE123456789).',
						'Please enter a valid EU VAT ID (e.g. DE123456789).'
					)
				);
			}
			return true;
		},
	) );
} );

/**
 * Pre-fill Block Checkout VAT ID from logged-in user's saved `billing_vat_id`.
 */
add_filter( 'woocommerce_get_default_value_for_rasa-kopi/vat-id', function ( $value, $group, $wc_object ) {
	if ( '' !== (string) $value ) {
		return $value;
	}
	if ( $wc_object instanceof WC_Customer || is_user_logged_in() ) {
		$user_id = $wc_object instanceof WC_Customer ? $wc_object->get_id() : get_current_user_id();
		if ( $user_id ) {
			$saved = rasa_sanitize_vat_id( get_user_meta( $user_id, 'billing_vat_id', true ) );
			if ( '' !== $saved ) {
				return $saved;
			}
		}
	}
	return $value;
}, 10, 3 );

/**
 * Classic Checkout & My Account billing VAT ID support (fallback / profile storage).
 */
add_filter( 'woocommerce_billing_fields', function ( $fields ) {
	$fields['billing_vat_id'] = array(
		'label'       => rasa_ops_tr( 'USt-IdNr. (optional, B2B)', 'VAT ID (optional, B2B)' ),
		'placeholder' => 'DE123456789',
		'required'    => false,
		'class'       => array( 'form-row-wide' ),
		'clear'       => true,
		'priority'    => 35,
	);
	return $fields;
} );

add_action( 'woocommerce_checkout_process', function () {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Classic Woo checkout validates its nonce before firing this hook.
	if ( empty( $_POST['billing_vat_id'] ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$vat_id = rasa_sanitize_vat_id( wp_unslash( $_POST['billing_vat_id'] ) );
	if ( ! rasa_is_valid_vat_id_format( $vat_id ) ) {
		wc_add_notice(
			rasa_ops_tr(
				'Bitte gib eine gültige EU USt-IdNr. ein (z. B. DE123456789).',
				'Please enter a valid EU VAT ID (e.g. DE123456789).'
			),
			'error'
		);
	}
} );

/**
 * Normalize and sync VAT ID onto canonical order meta for both Block and Classic Checkout.
 */
function rasa_sync_order_vat_id( $order ) {
	if ( ! ( $order instanceof WC_Order ) ) {
		return;
	}
	$vat_id = $order->get_meta( '_wc_other/rasa-kopi/vat-id', true );
	if ( '' === (string) $vat_id ) {
		$vat_id = $order->get_meta( '_billing_vat_id', true );
	}
	if ( '' === (string) $vat_id ) {
		$vat_id = $order->get_meta( RASA_META_VAT_ID, true );
	}
	$vat_id = rasa_sanitize_vat_id( $vat_id );
	if ( '' === $vat_id ) {
		return;
	}
	$order->update_meta_data( RASA_META_VAT_ID, $vat_id );
	$order->update_meta_data( '_billing_vat_id', $vat_id );
	$order->save_meta_data();

	$customer_id = $order->get_customer_id();
	if ( $customer_id > 0 ) {
		update_user_meta( $customer_id, 'billing_vat_id', $vat_id );
	}
}
add_action( 'woocommerce_store_api_checkout_order_processed', 'rasa_sync_order_vat_id', 20 );
add_action( 'woocommerce_checkout_order_created', 'rasa_sync_order_vat_id', 20 );

/**
 * Show VAT ID on WP Admin Edit Order screen & transactional emails.
 */
add_action( 'woocommerce_admin_order_data_after_billing_address', function ( $order ) {
	if ( ! ( $order instanceof WC_Order ) ) {
		return;
	}
	$vat_id = $order->get_meta( RASA_META_VAT_ID, true ) ?: $order->get_meta( '_wc_other/rasa-kopi/vat-id', true );
	if ( '' !== (string) $vat_id ) {
		echo '<p><strong>' . esc_html__( 'VAT ID (USt-IdNr.)', 'rasa-kopi' ) . ':</strong> ' . esc_html( $vat_id ) . '</p>';
	}
} );

add_filter( 'woocommerce_email_order_meta_fields', function ( $fields, $sent_to_admin, $order ) {
	if ( ! ( $order instanceof WC_Order ) ) {
		return $fields;
	}
	$vat_id = $order->get_meta( RASA_META_VAT_ID, true ) ?: $order->get_meta( '_wc_other/rasa-kopi/vat-id', true );
	if ( '' !== (string) $vat_id ) {
		$fields['rasa_vat_id'] = array(
			'label' => rasa_ops_tr( 'USt-IdNr. / VAT ID', 'VAT ID' ),
			'value' => $vat_id,
		);
	}
	return $fields;
}, 10, 3 );

/* ------------------------------------------------------------------ *
 * 2. Shipping: Gross Flat Rate 4.90 € & Auto Free Shipping (>= 59 €)
 * ------------------------------------------------------------------ */

/**
 * Convert taxable Flat Rate costs entered as gross retail prices (e.g. 4.90 € DE DHL standard)
 * into net + inclusive VAT breakdown, and hide paid flat_rate once free_shipping qualifies.
 */
add_filter( 'woocommerce_package_rates', function ( $rates, $package ) {
	if ( ! is_array( $rates ) || empty( $rates ) ) {
		return $rates;
	}

	$has_free_shipping = false;
	$tax_enabled       = wc_tax_enabled();
	$gross_mode        = $tax_enabled && wc_prices_include_tax();
	$is_vat_exempt     = function_exists( 'WC' ) && WC()->customer && WC()->customer->get_is_vat_exempt();
	$precision         = function_exists( 'wc_get_rounding_precision' ) ? wc_get_rounding_precision() : 4;

	foreach ( $rates as $rate ) {
		if ( ! ( $rate instanceof WC_Shipping_Rate ) ) {
			continue;
		}
		if ( 'free_shipping' === $rate->get_method_id() ) {
			$has_free_shipping = true;
		}

		if ( 'flat_rate' !== $rate->get_method_id() ) {
			continue;
		}

		$gross_cost = (float) $rate->get_cost();
		if ( $gross_cost <= 0 || ! $gross_mode ) {
			continue;
		}

		$meta = $rate->get_meta_data();
		if ( ! empty( $meta['rasa_gross_converted'] ) ) {
			continue;
		}

		$rate_taxes = $rate->get_taxes();
		$tax_rates  = ( function_exists( 'WC' ) && WC()->cart ) ? WC_Tax::get_shipping_tax_rates() : array();

		if ( ! empty( $rate_taxes ) && ! empty( $tax_rates ) ) {
			$inclusive_taxes = WC_Tax::calc_inclusive_tax( $gross_cost, $tax_rates );
			$net_cost        = round( $gross_cost - array_sum( $inclusive_taxes ), $precision );
			$rate->set_cost( $net_cost );
			$rate->set_taxes( $inclusive_taxes );
			$rate->add_meta_data( 'rasa_gross_converted', '1' );
		} elseif ( $is_vat_exempt && empty( $rate_taxes ) ) {
			// VAT-exempt EU B2B still receives net shipping (deduct store base DE 19% VAT).
			$base_rates = WC_Tax::find_shipping_rates( array( 'country' => wc_get_base_location()['country'] ) );
			if ( ! empty( $base_rates ) ) {
				$base_taxes = WC_Tax::calc_inclusive_tax( $gross_cost, $base_rates );
				$rate->set_cost( round( $gross_cost - array_sum( $base_taxes ), $precision ) );
				$rate->set_taxes( array() );
				$rate->add_meta_data( 'rasa_gross_converted', '1' );
			}
		}
	}

	if ( $has_free_shipping ) {
		foreach ( $rates as $rate_id => $rate ) {
			if ( $rate instanceof WC_Shipping_Rate && 'flat_rate' === $rate->get_method_id() ) {
				unset( $rates[ $rate_id ] );
			}
		}
	}

	return $rates;
}, 20, 2 );

/* ------------------------------------------------------------------ *
 * 3. Germanized: Invoice OFF + Unit Price & Delivery Time Helpers
 * ------------------------------------------------------------------ */

/**
 * Always keep automated StoreaBill / Germanized Pro invoice creation OFF;
 * invoicing is managed outside WooCommerce.
 */
add_filter( 'storeabill_invoice_create_document', '__return_false', 99 );

/**
 * Return localized product delivery time (prefers Germanized term if installed,
 * then product meta `_rasa_delivery_time`, otherwise default "3–7 Werktage").
 */
function rasa_get_product_delivery_time( $product ) {
	if ( $product instanceof WC_Product ) {
		if ( function_exists( 'wc_gzd_get_product' ) ) {
			$gzd_product = wc_gzd_get_product( $product );
			if ( $gzd_product && method_exists( $gzd_product, 'get_delivery_time_html' ) ) {
				$gzd_html = trim( wp_strip_all_tags( (string) $gzd_product->get_delivery_time_html() ) );
				if ( '' !== $gzd_html ) {
					$gzd_html = preg_replace( '/^Lieferzeit:\s*/i', '', $gzd_html );
					return trim( $gzd_html );
				}
			}
		}

		$custom = rasa_get_product_meta_inherited( $product, RASA_META_DELIVERY_TIME );
		if ( '' !== $custom ) {
			return $custom;
		}
	}

	return rasa_ops_tr( '3–7 Werktage', '3–7 business days' );
}

/**
 * Return PAngV unit price (Grundpreis) HTML if set on Germanized or `_rasa_unit_price`.
 */
function rasa_get_product_unit_price_html( $product ) {
	if ( ! ( $product instanceof WC_Product ) ) {
		return '';
	}
	if ( function_exists( 'wc_gzd_get_product' ) ) {
		$gzd_product = wc_gzd_get_product( $product );
		if ( $gzd_product && method_exists( $gzd_product, 'get_unit_price_html' ) ) {
			$gzd_unit = (string) $gzd_product->get_unit_price_html();
			if ( '' !== trim( wp_strip_all_tags( $gzd_unit ) ) ) {
				return wp_kses_post( $gzd_unit );
			}
		}
	}

	$custom = rasa_get_product_meta_inherited( $product, RASA_META_UNIT_PRICE );
	if ( '' !== $custom ) {
		return '<span class="rasa-unit-price">' . esc_html( $custom ) . '</span>';
	}

	return '';
}

/* ------------------------------------------------------------------ *
 * 4. Interfaces: Product Fields "Fulfilled by" + "Roaster" (UI + REST)
 * ------------------------------------------------------------------ */

/**
 * Register product meta in WordPress REST schema as well as WC CRUD/REST.
 */
add_action( 'init', function () {
	$args = array(
		'type'              => 'string',
		'single'            => true,
		'show_in_rest'      => true,
		'sanitize_callback' => 'sanitize_text_field',
		'auth_callback'     => function () {
			return current_user_can( 'edit_products' );
		},
	);
	foreach ( array( RASA_META_FULFILLED_BY, RASA_META_ROASTER, RASA_META_DELIVERY_TIME, RASA_META_UNIT_PRICE ) as $meta_key ) {
		register_post_meta( 'product', $meta_key, $args );
	}
} );

/**
 * Add "Fulfilled by", "Roaster", and optional PAngV fields to Product Data -> General.
 */
add_action( 'woocommerce_product_options_general_product_data', function () {
	global $post;
	$product_id = $post ? $post->ID : 0;
	$current_fulfilled = $product_id ? (string) get_post_meta( $product_id, RASA_META_FULFILLED_BY, true ) : '';

	$fulfilled_options = array(
		''                => __( '— Select fulfillment —', 'rasa-kopi' ),
		'Keey'            => 'Keey',
		'Partner roaster' => 'Partner roaster',
		'Rasa Kopi'       => 'Rasa Kopi',
	);
	if ( '' !== $current_fulfilled && ! isset( $fulfilled_options[ $current_fulfilled ] ) ) {
		$fulfilled_options[ $current_fulfilled ] = $current_fulfilled;
	}

	echo '<div class="options_group rasa-ops-group">';

	woocommerce_wp_select( array(
		'id'          => RASA_META_FULFILLED_BY,
		'label'       => __( 'Fulfilled by', 'rasa-kopi' ),
		'desc_tip'    => true,
		'description' => __( 'Fulfillment partner / warehouse responsible for shipping this SKU (REST API key: fulfilled_by).', 'rasa-kopi' ),
		'options'     => $fulfilled_options,
		'value'       => $current_fulfilled,
	) );

	woocommerce_wp_text_input( array(
		'id'          => RASA_META_ROASTER,
		'label'       => __( 'Roaster', 'rasa-kopi' ),
		'placeholder' => __( 'e.g. Rasa Kopi Roastery / Partner name', 'rasa-kopi' ),
		'desc_tip'    => true,
		'description' => __( 'Roaster identifier exposed to fulfillment interfaces (REST API key: roaster).', 'rasa-kopi' ),
	) );

	if ( ! class_exists( 'WooCommerce_Germanized' ) ) {
		woocommerce_wp_text_input( array(
			'id'          => RASA_META_DELIVERY_TIME,
			'label'       => __( 'Delivery time', 'rasa-kopi' ),
			'placeholder' => '3–7 Werktage',
			'desc_tip'    => true,
			'description' => __( 'Overrides the default "3–7 Werktage" delivery notice on the product page.', 'rasa-kopi' ),
		) );

		woocommerce_wp_text_input( array(
			'id'          => RASA_META_UNIT_PRICE,
			'label'       => __( 'Unit price (Grundpreis)', 'rasa-kopi' ),
			'placeholder' => __( 'e.g. 55,60 € / 1 kg', 'rasa-kopi' ),
			'desc_tip'    => true,
			'description' => __( 'Optional PAngV base unit price shown below the product price.', 'rasa-kopi' ),
		) );
	}

	echo '</div>';
} );

/**
 * Save custom operational product fields on WP Admin Product save.
 */
add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified `woocommerce_meta_nonce` before `woocommerce_process_product_meta`.
	foreach ( array( RASA_META_FULFILLED_BY, RASA_META_ROASTER, RASA_META_DELIVERY_TIME, RASA_META_UNIT_PRICE ) as $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$val = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			if ( '' === $val ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $val );
			}
		}
	}
} );

/**
 * Declare top-level `fulfilled_by` and `roaster` fields on WooCommerce Product REST schema.
 */
add_filter( 'woocommerce_rest_product_schema', function ( $schema ) {
	$schema['fulfilled_by'] = array(
		'description' => __( 'Fulfillment partner responsible for this product.', 'rasa-kopi' ),
		'type'        => 'string',
		'context'     => array( 'view', 'edit' ),
	);
	$schema['roaster'] = array(
		'description' => __( 'Roaster name or code associated with this product.', 'rasa-kopi' ),
		'type'        => 'string',
		'context'     => array( 'view', 'edit' ),
	);
	return $schema;
} );

/**
 * Expose top-level `fulfilled_by` and `roaster` on `/wp-json/wc/v3/products` responses.
 */
add_filter( 'woocommerce_rest_prepare_product_object', function ( $response, $product ) {
	if ( ! empty( $response->data ) && $product instanceof WC_Product ) {
		$response->data['fulfilled_by'] = rasa_get_product_meta_inherited( $product, RASA_META_FULFILLED_BY );
		$response->data['roaster']      = rasa_get_product_meta_inherited( $product, RASA_META_ROASTER );
	}
	return $response;
}, 10, 2 );

/**
 * Persist `fulfilled_by` and `roaster` when creating or updating products via WC REST API.
 */
add_filter( 'woocommerce_rest_pre_insert_product_object', function ( $product, $request ) {
	if ( ! ( $product instanceof WC_Product ) ) {
		return $product;
	}
	if ( isset( $request['fulfilled_by'] ) ) {
		$product->update_meta_data( RASA_META_FULFILLED_BY, sanitize_text_field( (string) $request['fulfilled_by'] ) );
	}
	if ( isset( $request['roaster'] ) ) {
		$product->update_meta_data( RASA_META_ROASTER, sanitize_text_field( (string) $request['roaster'] ) );
	}
	return $product;
}, 10, 2 );

/**
 * Snapshot product `fulfilled_by` and `roaster` onto order line item meta at checkout
 * so downstream fulfillment systems have immutable per-order records.
 */
add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $cart_item_key, $values ) {
	if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
		return;
	}
	$product = $values['data'] ?? $item->get_product();
	if ( ! ( $product instanceof WC_Product ) ) {
		return;
	}
	$fulfilled_by = rasa_get_product_meta_inherited( $product, RASA_META_FULFILLED_BY );
	$roaster      = rasa_get_product_meta_inherited( $product, RASA_META_ROASTER );

	if ( '' !== $fulfilled_by ) {
		$item->add_meta_data( RASA_META_FULFILLED_BY, $fulfilled_by, true );
	}
	if ( '' !== $roaster ) {
		$item->add_meta_data( RASA_META_ROASTER, $roaster, true );
	}
}, 10, 3 );

/**
 * Expose top-level `vat_id`, plus `fulfilled_by` and `roaster` per line item,
 * on `/wp-json/wc/v3/orders` REST responses.
 */
add_filter( 'woocommerce_rest_prepare_shop_order_object', function ( $response, $order ) {
	if ( empty( $response->data ) || ! ( $order instanceof WC_Order ) ) {
		return $response;
	}

	$vat_id = $order->get_meta( RASA_META_VAT_ID, true ) ?: $order->get_meta( '_wc_other/rasa-kopi/vat-id', true );
	$response->data['vat_id'] = (string) $vat_id;

	if ( ! empty( $response->data['line_items'] ) && is_array( $response->data['line_items'] ) ) {
		$order_items = $order->get_items();
		foreach ( $response->data['line_items'] as $idx => $line ) {
			$item_id = isset( $line['id'] ) ? (int) $line['id'] : 0;
			if ( $item_id && isset( $order_items[ $item_id ] ) ) {
				$item = $order_items[ $item_id ];
				$fb   = (string) $item->get_meta( RASA_META_FULFILLED_BY, true );
				$rst  = (string) $item->get_meta( RASA_META_ROASTER, true );
				if ( '' === $fb || '' === $rst ) {
					$prod = $item->get_product();
					if ( $prod instanceof WC_Product ) {
						if ( '' === $fb ) {
							$fb = rasa_get_product_meta_inherited( $prod, RASA_META_FULFILLED_BY );
						}
						if ( '' === $rst ) {
							$rst = rasa_get_product_meta_inherited( $prod, RASA_META_ROASTER );
						}
					}
				}
				$response->data['line_items'][ $idx ]['fulfilled_by'] = $fb;
				$response->data['line_items'][ $idx ]['roaster']      = $rst;
			}
		}
	}

	return $response;
}, 10, 2 );

/**
 * Render Fulfilled by & Roaster cleanly under each order item in WP Admin Order details.
 */
add_action( 'woocommerce_after_order_itemmeta', function ( $item_id, $item ) {
	if ( ! is_admin() || ! ( $item instanceof WC_Order_Item_Product ) ) {
		return;
	}
	$fb  = (string) $item->get_meta( RASA_META_FULFILLED_BY, true );
	$rst = (string) $item->get_meta( RASA_META_ROASTER, true );
	if ( '' === $fb && '' === $rst ) {
		return;
	}
	echo '<div class="rasa-admin-item-ops" style="margin-top:4px;font-size:12px;color:#50575e;">';
	if ( '' !== $fb ) {
		echo '<span><strong>' . esc_html__( 'Fulfilled by', 'rasa-kopi' ) . ':</strong> ' . esc_html( $fb ) . '</span>';
	}
	if ( '' !== $fb && '' !== $rst ) {
		echo ' &middot; ';
	}
	if ( '' !== $rst ) {
		echo '<span><strong>' . esc_html__( 'Roaster', 'rasa-kopi' ) . ':</strong> ' . esc_html( $rst ) . '</span>';
	}
	echo '</div>';
}, 10, 2 );
