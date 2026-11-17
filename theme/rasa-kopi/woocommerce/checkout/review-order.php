<?php
/**
 * woocommerce/checkout/review-order.php — Shopify Order Summary Sidebar Content
 * Root elemen wajib mempertahankan class `woocommerce-checkout-review-order-table`
 * agar AJAX update_order_review WooCommerce memperbarui bagian ini otomatis.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tr  = function ( $de, $en ) {
	return function_exists( 'rasa_tr' ) ? rasa_tr( $de, $en ) : $de;
};
$fmt = function ( $val, $suffix = false ) {
	if ( function_exists( 'rasa_format_eur' ) ) {
		return rasa_format_eur( $val, $suffix );
	}
	$s = '€' . number_format( (float) $val, 2, ',', '.' );
	return $suffix ? ( $s . ' EUR' ) : $s;
};
?>
<div class="shop_table woocommerce-checkout-review-order-table rasa-co-summary-box">
	<?php do_action( 'woocommerce_review_order_before_cart_contents' ); ?>

	<ul class="rasa-co-items" role="list">
		<?php
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			if ( ! $_product || ! $_product->exists() || $cart_item['quantity'] <= 0 || ! apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
				continue;
			}
			$qty     = (int) $cart_item['quantity'];
			$img_url = wp_get_attachment_image_url( $_product->get_image_id(), 'thumbnail' );
			if ( ! $img_url && $_product->get_parent_id() ) {
				$parent = wc_get_product( $_product->get_parent_id() );
				if ( $parent ) {
					$img_url = wp_get_attachment_image_url( $parent->get_image_id(), 'thumbnail' );
				}
			}
			$var_parts = array();
			if ( ! empty( $cart_item['variation'] ) && is_array( $cart_item['variation'] ) ) {
				foreach ( $cart_item['variation'] as $k => $v ) {
					if ( '' !== (string) $v ) {
						$var_parts[] = strtoupper( (string) $v );
					}
				}
			}
			$line_num = (float) ( $cart_item['line_subtotal'] ?? 0 ) + (float) ( $cart_item['line_subtotal_tax'] ?? 0 );
			?>
			<li class="rasa-co-item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">
				<div class="rasa-co-item__media">
					<?php if ( $img_url ) : ?>
						<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $_product->get_title() ); ?>" class="rasa-co-item__img" width="64" height="64">
					<?php else : ?>
						<div class="rasa-co-item__img rasa-co-item__img--empty"></div>
					<?php endif; ?>
					<span class="rasa-co-item__badge"><?php echo (int) $qty; ?></span>
				</div>
				<div class="rasa-co-item__info">
					<div class="rasa-co-item__title"><?php echo esc_html( $_product->get_title() ); ?></div>
					<?php if ( ! empty( $var_parts ) ) : ?>
						<div class="rasa-co-item__meta"><?php echo esc_html( implode( ' / ', $var_parts ) ); ?></div>
					<?php endif; ?>
				</div>
				<div class="rasa-co-item__price">
					<?php echo esc_html( $fmt( $line_num ) ); ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php do_action( 'woocommerce_review_order_after_cart_contents' ); ?>

	<div class="rasa-co-totals">
		<?php
		$sub_num = (float) WC()->cart->get_subtotal() + (float) WC()->cart->get_subtotal_tax();
		?>
		<div class="rasa-co-total-row">
			<span><?php echo esc_html( $tr( 'Zwischensumme', 'Subtotal' ) ); ?></span>
			<span><?php echo esc_html( $fmt( $sub_num ) ); ?></span>
		</div>

		<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
		<div class="rasa-co-total-row rasa-co-total-row--discount cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
			<span><?php echo esc_html( $tr( 'Rabatt', 'Discount' ) ); ?> (<?php echo esc_html( strtoupper( $code ) ); ?>)</span>
			<span><?php wc_cart_totals_coupon_html( $coupon ); ?></span>
		</div>
		<?php endforeach; ?>

		<div class="rasa-co-total-row">
			<span><?php echo esc_html( $tr( 'Versand', 'Shipping' ) ); ?></span>
			<span id="RasaCoSummaryShipping" class="rasa-co-total-shipping">
				<?php
				$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();
				if ( ! WC()->cart->needs_shipping() ) {
					echo esc_html( $tr( 'Kostenlos', 'Free' ) );
				} elseif ( ! empty( $chosen ) && (float) WC()->cart->get_shipping_total() === 0.0 ) {
					echo esc_html( $tr( 'Kostenlos', 'Free' ) );
				} elseif ( (float) WC()->cart->get_shipping_total() > 0 ) {
					$ship_num = (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax();
					echo esc_html( $fmt( $ship_num ) );
				} else {
					echo esc_html( $tr( 'Lieferadresse eingeben', 'Enter shipping address' ) );
				}
				?>
			</span>
		</div>

		<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
		<div class="rasa-co-total-row">
			<span><?php echo esc_html( $fee->name ); ?></span>
			<span><?php wc_cart_totals_fee_html( $fee ); ?></span>
		</div>
		<?php endforeach; ?>

		<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>

		<div class="rasa-co-total-row rasa-co-total-row--grand">
			<span class="rasa-co-grand-label"><?php echo esc_html( $tr( 'Gesamt', 'Total' ) ); ?></span>
			<span class="rasa-co-grand-val">
				<small>EUR</small>
				<?php echo esc_html( $fmt( WC()->cart->get_total( 'edit' ) ) ); ?>
			</span>
		</div>

		<?php if ( wc_tax_enabled() && WC()->cart->get_total_tax() > 0 ) : ?>
		<div class="rasa-co-tax-note">
			<?php
			echo esc_html(
				sprintf(
					$tr( 'Inkl. %s MwSt.', 'Incl. %s VAT' ),
					$fmt( WC()->cart->get_total_tax() )
				)
			);
			?>
		</div>
		<?php else : ?>
		<div class="rasa-co-tax-note"><?php echo esc_html( $tr( 'Inkl. MwSt.', 'Incl. VAT' ) ); ?></div>
		<?php endif; ?>

		<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>
	</div>
</div>
