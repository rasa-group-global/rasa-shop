#!/usr/bin/env bash
# ==============================================================================
# setup/settings.sh — Idempotent WooCommerce Store Configuration for Rasa Kopi
# ==============================================================================
# Run from the host repo root (/var/www/RASA/wordpress-woo):
#   bash setup/settings.sh
#
# Optional environment flags:
#   WEB_CONTAINER=shop-woo-web        # Docker PHP-FPM container name
#   WP_CLI_DIRECT=1                   # Run wp directly (when already inside container)
#   REVOLUT_MODE=keep                 # keep (default) | sandbox | live
#   INSTALL_GERMANIZED=0              # 1 to install/activate Free Germanized plugin
# ==============================================================================

set -euo pipefail

WEB="${WEB_CONTAINER:-shop-woo-web}"
REVOLUT_MODE="${REVOLUT_MODE:-keep}"
INSTALL_GERMANIZED="${INSTALL_GERMANIZED:-0}"

case "$REVOLUT_MODE" in
	keep|sandbox|live) ;;
	*)
		echo "ERROR: Invalid REVOLUT_MODE='$REVOLUT_MODE' (expected keep | sandbox | live)." >&2
		exit 1
		;;
esac

WP() {
	if [ "${WP_CLI_DIRECT:-0}" = "1" ] || { ! command -v docker >/dev/null 2>&1 && [ -x /usr/local/bin/wp ]; }; then
		php -d open_basedir= -d disable_functions= /usr/local/bin/wp --path="${WP_PATH:-/var/www/html}" "$@" --allow-root
	else
		docker exec -i "$WEB" php -d open_basedir= -d disable_functions= /usr/local/bin/wp "$@" --allow-root
	fi
}

WP_EVAL() {
	local code
	code="$(cat)"
	WP eval "$code"
}

if ! WP core is-installed >/dev/null 2>&1; then
	echo "ERROR: WordPress is not installed or container '$WEB' is not running." >&2
	exit 1
fi

echo "==> 1/6 Tax & Currency (EUR, DE incl. VAT, 19% Standard / 7% Reduced, B2B role)..."
WP eval '
$cur = get_option( "woocommerce_default_country", "" );
if ( strpos( (string) $cur, "DE" ) !== 0 ) {
	update_option( "woocommerce_default_country", "DE" );
}
'
WP option update woocommerce_currency 'EUR'
WP option update woocommerce_currency_pos 'right_space'
WP option update woocommerce_price_thousand_sep '.'
WP option update woocommerce_price_decimal_sep ','
WP option update woocommerce_price_num_decimals '2'

WP option update woocommerce_calc_taxes 'yes'
WP option update woocommerce_prices_include_tax 'yes'
WP option update woocommerce_tax_based_on 'shipping'
WP option update woocommerce_default_customer_address 'base'
WP option update woocommerce_shipping_cost_requires_address 'no'
WP option update woocommerce_store_city 'Berlin'
WP option update woocommerce_store_postcode '10405'
WP option update woocommerce_tax_round_at_subtotal 'no'
WP option update woocommerce_shipping_tax_class 'inherit'
WP option update woocommerce_tax_display_shop 'incl'
WP option update woocommerce_tax_display_cart 'incl'
WP option update woocommerce_tax_total_display 'itemized'

WP_EVAL <<'PHP'
global $wpdb;

if ( class_exists( 'WC_Tax' ) ) {
	$existing_classes = WC_Tax::get_tax_classes();
	if ( ! in_array( 'Reduced rate', $existing_classes, true ) ) {
		WC_Tax::create_tax_class( 'Reduced rate', 'reduced-rate' );
	}
}

$table = $wpdb->prefix . 'woocommerce_tax_rates';
$desired_rates = array(
	array(
		'tax_rate_country'  => 'DE',
		'tax_rate_state'    => '',
		'tax_rate'          => '19.0000',
		'tax_rate_name'     => 'MwSt. 19%',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 1,
		'tax_rate_order'    => 1,
		'tax_rate_class'    => '',
	),
	array(
		'tax_rate_country'  => 'DE',
		'tax_rate_state'    => '',
		'tax_rate'          => '7.0000',
		'tax_rate_name'     => 'MwSt. 7%',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 1,
		'tax_rate_order'    => 2,
		'tax_rate_class'    => 'reduced-rate',
	),
);

foreach ( $desired_rates as $rate ) {
	$found_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT tax_rate_id FROM {$table} WHERE tax_rate_country = %s AND tax_rate_state = %s AND tax_rate_class = %s LIMIT 1",
			$rate['tax_rate_country'],
			$rate['tax_rate_state'],
			$rate['tax_rate_class']
		)
	);
	if ( $found_id ) {
		$wpdb->update(
			$table,
			array(
				'tax_rate'          => $rate['tax_rate'],
				'tax_rate_name'     => $rate['tax_rate_name'],
				'tax_rate_priority' => $rate['tax_rate_priority'],
				'tax_rate_compound' => $rate['tax_rate_compound'],
				'tax_rate_shipping' => $rate['tax_rate_shipping'],
				'tax_rate_order'    => $rate['tax_rate_order'],
			),
			array( 'tax_rate_id' => (int) $found_id )
		);
	} elseif ( class_exists( 'WC_Tax' ) ) {
		WC_Tax::_insert_tax_rate( $rate );
	}
}

if ( class_exists( 'WC_Cache_Helper' ) ) {
	WC_Cache_Helper::invalidate_cache_group( 'taxes' );
}

if ( ! get_role( 'b2b_customer' ) ) {
	$customer_role = get_role( 'customer' );
	$caps          = $customer_role ? $customer_role->capabilities : array( 'read' => true );
	add_role( 'b2b_customer', 'B2B Customer', $caps );
}
PHP

echo "==> 2/6 Shipping (Germany zone, Flat rate 4.90 €, Free from 59 €, Classes: Keey / Partner roaster)..."
WP_EVAL <<'PHP'
global $wpdb;

if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
	fwrite( STDERR, "WooCommerce Shipping classes not loaded.\n" );
	exit( 1 );
}

// Shipping classes requested: "Keey" and "Partner roaster"
$shipping_classes = array(
	'keey'            => 'Keey',
	'partner-roaster' => 'Partner roaster',
);
foreach ( $shipping_classes as $slug => $label ) {
	$existing = term_exists( $slug, 'product_shipping_class' );
	if ( ! $existing ) {
		wp_insert_term( $label, 'product_shipping_class', array( 'slug' => $slug ) );
	}
}

// Find existing Germany / DE zone so re-runs stay 100% idempotent
$target_zone = null;
foreach ( WC_Shipping_Zones::get_zones() as $z ) {
	$is_de = false;
	if ( ! empty( $z['zone_locations'] ) ) {
		$countries = array();
		foreach ( $z['zone_locations'] as $loc ) {
			if ( 'country' === $loc->type ) {
				$countries[] = $loc->code;
			}
		}
		if ( array( 'DE' ) === $countries ) {
			$is_de = true;
		}
	}
	if ( 'Germany' === $z['zone_name'] || 'Deutschland' === $z['zone_name'] || $is_de ) {
		$target_zone = WC_Shipping_Zones::get_zone( (int) $z['id'] );
		break;
	}
}

if ( ! $target_zone ) {
	$target_zone = new WC_Shipping_Zone();
}

$target_zone->set_zone_name( 'Germany' );
$target_zone->set_zone_order( 0 );
$target_zone->set_locations( array(
	array(
		'code' => 'DE',
		'type' => 'country',
	),
) );
$target_zone->save();

$methods_table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
$existing_methods = $target_zone->get_shipping_methods( false, 'admin' );

$find_or_add_method = function ( $method_id ) use ( $target_zone, $existing_methods, $wpdb, $methods_table ) {
	foreach ( $existing_methods as $m ) {
		if ( $m->id === $method_id ) {
			$wpdb->update(
				$methods_table,
				array( 'is_enabled' => 1 ),
				array( 'instance_id' => (int) $m->instance_id )
			);
			return (int) $m->instance_id;
		}
	}
	$instance_id = (int) $target_zone->add_shipping_method( $method_id );
	$wpdb->update(
		$methods_table,
		array( 'is_enabled' => 1 ),
		array( 'instance_id' => $instance_id )
	);
	return $instance_id;
};

// Flat rate: 4.90 € gross (converted to inclusive VAT net+tax at runtime by theme/rasa-kopi/inc/rasa-store-ops.php)
$flat_id       = $find_or_add_method( 'flat_rate' );
$flat_opt_key  = 'woocommerce_flat_rate_' . $flat_id . '_settings';
$flat_settings = get_option( $flat_opt_key, array() );
if ( ! is_array( $flat_settings ) ) {
	$flat_settings = array();
}
$flat_settings['title']      = 'Standardversand (DHL)';
$flat_settings['tax_status'] = 'taxable';
$flat_settings['cost']       = '4.90';
update_option( $flat_opt_key, $flat_settings );

// Free shipping: >= 59.00 €
$free_id       = $find_or_add_method( 'free_shipping' );
$free_opt_key  = 'woocommerce_free_shipping_' . $free_id . '_settings';
$free_settings = get_option( $free_opt_key, array() );
if ( ! is_array( $free_settings ) ) {
	$free_settings = array();
}
$free_settings['title']            = 'Kostenloser Versand';
$free_settings['requires']         = 'min_amount';
$free_settings['min_amount']       = '59';
$free_settings['ignore_discounts'] = 'no';
update_option( $free_opt_key, $free_settings );

if ( class_exists( 'WC_Cache_Helper' ) ) {
	WC_Cache_Helper::get_transient_version( 'shipping', true );
}
PHP

echo "==> 3/6 Checkout & Payment Gateways (Guest checkout ON, fields, Revolut active, mode=$REVOLUT_MODE)..."
WP option update woocommerce_enable_guest_checkout 'yes'
WP option update woocommerce_enable_checkout_login_reminder 'yes'
WP option update woocommerce_enable_signup_and_login_from_checkout 'yes'
WP option update woocommerce_enable_myaccount_registration 'yes'
WP option update woocommerce_registration_generate_username 'yes'
WP option update woocommerce_registration_generate_password 'yes'

WP option update woocommerce_checkout_company_field 'optional'
WP option update woocommerce_checkout_address_2_field 'optional'
WP option update woocommerce_checkout_phone_field 'optional'

echo "▸ Install & activate WooCommerce PayPal Payments..."
WP plugin install woocommerce-paypal-payments --activate || true

WP_EVAL <<PHP
\$target_mode = '$REVOLUT_MODE';

// 1. Enable Revolut Card & Revolut Pay, turn OFF promotional/affiliate banners for speed
foreach ( array( 'woocommerce_revolut_cc_settings', 'woocommerce_revolut_pay_settings' ) as \$opt ) {
	\$cfg = get_option( \$opt, array() );
	if ( ! is_array( \$cfg ) ) {
		\$cfg = array();
	}
	\$cfg['enabled']          = 'yes';
	\$cfg['reward_banner']    = 'no';
	\$cfg['benefits_banner']  = 'no';
	\$cfg['cashback_label']   = 'no';
	update_option( \$opt, \$cfg );
}

// Preserve existing Revolut API credentials; turn OFF affiliate/promotional banners
\$rev = get_option( 'woocommerce_revolut_settings', array() );
if ( ! is_array( \$rev ) ) {
	\$rev = array();
}
\$rev['enabled']            = 'yes';
\$rev['reward_banner']      = 'no';
\$rev['benefits_banner']    = 'no';
\$rev['cashback_label']     = 'no';
\$rev['promotional_banner'] = 'no';
if ( in_array( \$target_mode, array( 'sandbox', 'live' ), true ) ) {
	\$rev['mode'] = \$target_mode;
}
update_option( 'woocommerce_revolut_settings', \$rev );

// 2. Configure Apple Pay / Google Pay (Payment Request)
\$pr_cfg = get_option( 'woocommerce_revolut_payment_request_settings', array() );
if ( ! is_array( \$pr_cfg ) ) {
	\$pr_cfg = array();
}
\$pr_cfg['enabled']     = 'yes';
\$pr_cfg['title']       = 'Apple Pay / Google Pay';
\$pr_cfg['description'] = 'Sicher und schnell bezahlen mit Apple Pay oder Google Pay.';
update_option( 'woocommerce_revolut_payment_request_settings', \$pr_cfg );

// 3. Configure Pay by Bank with correct description
\$pbb_cfg = get_option( 'woocommerce_revolut_pay_by_bank_settings', array() );
if ( ! is_array( \$pbb_cfg ) ) {
	\$pbb_cfg = array();
}
\$pbb_cfg['title']       = 'Pay by Bank';
\$pbb_cfg['description'] = 'Direkt und sicher per Online-Banking über dein Bankkonto bezahlen.';
\$pbb_cfg['enabled']     = 'no';
update_option( 'woocommerce_revolut_pay_by_bank_settings', \$pbb_cfg );

// 4. Configure PayPal Payments (incl. Pay Later)
\$pp_cfg = get_option( 'woocommerce_ppcp-gateway_settings', array() );
if ( ! is_array( \$pp_cfg ) ) {
	\$pp_cfg = array();
}
\$pp_cfg['enabled']     = 'yes';
\$pp_cfg['title']       = 'PayPal';
\$pp_cfg['description'] = 'Sicher und bequem bezahlen mit PayPal (inkl. Später Bezahlen).';
update_option( 'woocommerce_ppcp-gateway_settings', \$pp_cfg );

// 5. Keep legacy offline gateways OFF
foreach ( array(
	'woocommerce_bacs_settings',
	'woocommerce_cheque_settings',
	'woocommerce_cod_settings',
) as \$off_opt ) {
	\$cfg = get_option( \$off_opt, array() );
	if ( is_array( \$cfg ) || false !== get_option( \$off_opt, false ) ) {
		if ( ! is_array( \$cfg ) ) {
			\$cfg = array();
		}
		\$cfg['enabled'] = 'no';
		update_option( \$off_opt, \$cfg );
	}
}

// 6. Ensure products are not marked as Virtual so shipping is always calculated
global \$wpdb;
\$wpdb->query( "UPDATE {\$wpdb->postmeta} SET meta_value = 'no' WHERE meta_key = '_virtual' AND meta_value = 'yes'" );
PHP

echo "==> 4/6 Orders & Emails (sender shop@rasakopi.coffee, notifications ON)..."
WP option update woocommerce_email_from_name 'Rasa Kopi'
WP option update woocommerce_email_from_address 'shop@rasakopi.coffee'

WP_EVAL <<'PHP'
$admin_emails = array(
	'woocommerce_new_order_settings',
	'woocommerce_cancelled_order_settings',
	'woocommerce_failed_order_settings',
);
foreach ( $admin_emails as $opt ) {
	$cfg = get_option( $opt, array() );
	if ( ! is_array( $cfg ) ) {
		$cfg = array();
	}
	$cfg['enabled']   = 'yes';
	$cfg['recipient'] = 'shop@rasakopi.coffee';
	update_option( $opt, $cfg );
}

$customer_emails = array(
	'woocommerce_customer_on_hold_order_settings',
	'woocommerce_customer_processing_order_settings',
	'woocommerce_customer_completed_order_settings',
	'woocommerce_customer_refunded_order_settings',
	'woocommerce_customer_invoice_settings',
	'woocommerce_customer_note_settings',
	'woocommerce_customer_reset_password_settings',
	'woocommerce_customer_new_account_settings',
);
foreach ( $customer_emails as $opt ) {
	$cfg = get_option( $opt, array() );
	if ( ! is_array( $cfg ) ) {
		$cfg = array();
	}
	$cfg['enabled'] = 'yes';
	update_option( $opt, $cfg );
}
PHP

echo "==> 5/6 Germanized / German Market (invoice OFF, unit prices, delivery time, checkboxes)..."
if [ "$INSTALL_GERMANIZED" = "1" ]; then
	WP plugin install woocommerce-germanized --activate || true
fi

# Keep PDF invoicing OFF even if Pro/StoreaBill is installed
WP option update woocommerce_gzdp_invoice_enabled 'no'
WP option update woocommerce_gzdp_invoice_auto_enabled 'no'

if WP plugin is-active woocommerce-germanized >/dev/null 2>&1; then
	WP option update woocommerce_gzd_display_product_detail_unit_price 'yes'
	WP option update woocommerce_gzd_display_listings_unit_price 'yes'
	WP option update woocommerce_gzd_display_cart_unit_price 'yes'
	WP option update woocommerce_gzd_display_checkout_unit_price 'yes'

	WP option update woocommerce_gzd_display_product_detail_delivery_time 'yes'
	WP option update woocommerce_gzd_display_listings_delivery_time 'yes'
	WP option update woocommerce_gzd_display_cart_delivery_time 'yes'
	WP option update woocommerce_gzd_display_checkout_delivery_time 'yes'

	WP option update woocommerce_gzd_display_product_detail_tax_info 'yes'
	WP option update woocommerce_gzd_display_product_detail_shipping_costs_info 'yes'
	WP option update woocommerce_gzd_display_checkout_back_to_cart_button 'yes'

	WP_EVAL <<'PHP'
if ( taxonomy_exists( 'product_delivery_time' ) ) {
	$term = term_exists( '3-7-werktage', 'product_delivery_time' );
	if ( ! $term ) {
		$term = wp_insert_term( '3–7 Werktage', 'product_delivery_time', array( 'slug' => '3-7-werktage' ) );
	}
	if ( is_array( $term ) && ! empty( $term['term_id'] ) ) {
		$t_obj = get_term( (int) $term['term_id'], 'product_delivery_time' );
		if ( $t_obj && ! is_wp_error( $t_obj ) ) {
			update_option( 'woocommerce_gzd_default_delivery_time', $t_obj->slug );
		}
	}
}
PHP
else
	echo "    (Info: woocommerce-germanized plugin is not active; Germanized display options skipped. PAngV delivery time / VAT / Block Checkout legal notice & StoreaBill invoice suppression remain enforced via theme/rasa-kopi/inc/rasa-store-ops.php. Re-run with INSTALL_GERMANIZED=1 if Free Germanized is desired.)"
fi

echo "==> 6/6 Interfaces (DISABLE_WP_CRON=true, permalinks /wp-json/, custom REST fields check)..."
WP config set DISABLE_WP_CRON true --raw
WP rewrite structure '/%postname%/' --hard
WP rewrite flush --hard

WP eval '
echo "    WC Currency : " . get_woocommerce_currency() . "\n";
echo "    Revolut Mode: " . ( get_option( "woocommerce_revolut_settings" )["mode"] ?? "unset" ) . "\n";
echo "    REST URL    : " . rest_url( "wc/v3/products" ) . "\n";
echo "    DISABLE_WP_CRON: " . ( defined( "DISABLE_WP_CRON" ) && DISABLE_WP_CRON ? "true" : "false" ) . "\n";
'

echo "Done. Review docs/settings.md for manual/host items (host crontab, REST API keys, live Revolut OAuth, Block Checkout field UI verification)."
