<?php
/**
 * woocommerce/checkout/review-order.php — Light Theme Order Summary Card
 * Menampilkan thumbnail produk, badge qty, harga satuan, variasi, dan totals table.
 * Root tetap memakai class `woocommerce-checkout-review-order-table` untuk fragment AJAX WooCommerce.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tr = function ( $de, $en ) {
	return function_exists( 'rasa_tr' ) ? rasa_tr( $de, $en ) : $en;
};
?>
<div class="shop_table woocommerce-checkout-review-order-table rasa-co-summary-card">
	<h3 id="order_review_heading" class="rasa-co-summary-card__title">
		<?php echo esc_html( $tr( 'Bestellübersicht', 'Order summary' ) ); ?>
	</h3>

	<?php do_action( 'woocommerce_review_order_before_cart_contents' ); ?>

	<ul class="rasa-co-items" role="list">
		<?php
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

			if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) :
				$qty     = (int) $cart_item['quantity'];
				$img_id  = $_product->get_image_id();
				$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : '';
				if ( ! $img_url && $_product->get_parent_id() ) {
					$parent = wc_get_product( $_product->get_parent_id() );
					if ( $parent && $parent->get_image_id() ) {
						$img_url = wp_get_attachment_image_url( $parent->get_image_id(), 'woocommerce_thumbnail' );
					}
				}
				if ( ! $img_url && function_exists( 'wc_placeholder_img_src' ) ) {
					$img_url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
				}

				$item_name  = wp_strip_all_tags( apply_filters( 'woocommerce_cart_item_name', $_product->get_title(), $cart_item, $cart_item_key ) );
				$unit_price = WC()->cart->get_product_price( $_product );
				$meta_html  = function_exists( 'wc_get_formatted_cart_item_data' ) ? wc_get_formatted_cart_item_data( $cart_item ) : '';
				$line_total = apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key );
				?>
				<li class="rasa-co-item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">
					<div class="rasa-co-item__media">
						<div class="rasa-co-item__thumb">
							<?php if ( $img_url ) : ?>
								<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $item_name ); ?>" class="rasa-co-item__img" width="60" height="60" loading="lazy">
							<?php endif; ?>
						</div>
						<span class="rasa-co-item__badge" aria-label="<?php echo esc_attr( sprintf( $tr( 'Menge: %d', 'Quantity: %d' ), $qty ) ); ?>"><?php echo (int) $qty; ?></span>
					</div>

					<div class="rasa-co-item__info">
						<div class="rasa-co-item__title"><?php echo esc_html( $item_name ); ?></div>
						<?php if ( $unit_price ) : ?>
							<div class="rasa-co-item__unit"><?php echo wp_kses_post( $unit_price ); ?></div>
						<?php endif; ?>
						<?php if ( $meta_html ) : ?>
							<div class="rasa-co-item__meta"><?php echo wp_kses_post( $meta_html ); ?></div>
						<?php endif; ?>
					</div>

					<div class="rasa-co-item__price">
						<?php echo wp_kses_post( $line_total ); ?>
					</div>
				</li>
				<?php
			endif;
		endforeach;
		?>
	</ul>

	<?php do_action( 'woocommerce_review_order_after_cart_contents' ); ?>

	<table class="rasa-co-totals-table">
		<tbody>
			<tr class="cart-subtotal">
				<th><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
				<td><?php wc_cart_totals_subtotal_html(); ?></td>
			</tr>

			<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
				<tr class="cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
					<th><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
					<td><?php wc_cart_totals_coupon_html( $coupon ); ?></td>
				</tr>
			<?php endforeach; ?>

			<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>

				<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>

				<?php wc_cart_totals_shipping_html(); ?>

				<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>

			<?php endif; ?>

			<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
				<tr class="fee">
					<th><?php echo esc_html( $fee->name ); ?></th>
					<td><?php wc_cart_totals_fee_html( $fee ); ?></td>
				</tr>
			<?php endforeach; ?>

			<?php if ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) : ?>
				<?php if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) : ?>
					<?php foreach ( WC()->cart->get_tax_totals() as $code => $tax ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited ?>
						<tr class="tax-rate tax-rate-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
							<th><?php echo esc_html( $tax->label ); ?></th>
							<td><?php echo wp_kses_post( $tax->formatted_amount ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr class="tax-total">
						<th><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
						<td><?php wc_cart_totals_taxes_total_html(); ?></td>
					</tr>
				<?php endif; ?>
			<?php endif; ?>

			<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>

			<tr class="order-total">
				<th><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
				<td><?php wc_cart_totals_order_total_html(); ?></td>
			</tr>

			<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>
		</tbody>
	</table>
</div>
