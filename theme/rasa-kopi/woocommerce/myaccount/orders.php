<?php
/**
 * Orders (Shopify parity)
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 8.5.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_orders', $has_orders );

$orders_title = function_exists( 'rasa_tr' ) ? rasa_tr( 'Bestellungen', 'Orders' ) : 'Orders';
$no_orders    = function_exists( 'rasa_tr' ) ? rasa_tr( 'Bisher keine Bestellungen aufgegeben.', 'No orders placed yet.' ) : 'No orders placed yet.';
$shop_btn     = function_exists( 'rasa_tr' ) ? rasa_tr( 'Jetzt einkaufen', 'Shop now' ) : 'Shop now';
?>

<section class="rasa-account-section">
	<div class="rasa-account-section__header">
		<h2 class="rasa-account-section__title"><?php echo esc_html( $orders_title ); ?></h2>
	</div>

	<?php if ( $has_orders ) : ?>
		<div class="rasa-account-card rasa-account-card--table">
			<table class="rasa-account-orders-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Date', 'woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
						<th class="is-right"><?php esc_html_e( 'Actions', 'woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $customer_orders->orders as $customer_order ) :
						$order      = wc_get_order( $customer_order );
						$item_count = $order->get_item_count();
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" class="rasa-account-orders__num">
									#<?php echo esc_html( $order->get_order_number() ); ?>
								</a>
							</td>
							<td class="rasa-account-orders__date">
								<time datetime="<?php echo esc_attr( $order->get_date_created()->date( 'c' ) ); ?>">
									<?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
								</time>
							</td>
							<td>
								<span class="rasa-account-orders__status">
									<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
								</span>
							</td>
							<td>
								<?php
								/* translators: 1: formatted order total 2: total order items */
								echo wp_kses_post( sprintf( _n( '%1$s for %2$s item', '%1$s for %2$s items', $item_count, 'woocommerce' ), $order->get_formatted_order_total(), $item_count ) );
								?>
							</td>
							<td class="is-right">
								<?php
								$actions = wc_get_account_orders_actions( $order );
								if ( ! empty( $actions ) ) {
									foreach ( $actions as $key => $action ) {
										echo '<a href="' . esc_url( $action['url'] ) . '" class="rasa-account-btn rasa-account-btn--compact">' . esc_html( $action['name'] ) . '</a>';
									}
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php do_action( 'woocommerce_before_account_orders_pagination' ); ?>

		<?php if ( 1 < $customer_orders->max_num_pages ) : ?>
			<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination rasa-account-orders__pagination">
				<?php if ( 1 !== $current_page ) : ?>
					<a class="rasa-account-btn" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'woocommerce' ); ?></a>
				<?php endif; ?>

				<?php if ( intval( $customer_orders->max_num_pages ) !== $current_page ) : ?>
					<a class="rasa-account-btn" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'woocommerce' ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>

	<?php else : ?>
		<?php $shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ); ?>
		<div class="rasa-account-card rasa-account-card--empty">
			<div class="rasa-account-empty-text">
				<?php echo esc_html( $no_orders ); ?>
			</div>
			<a href="<?php echo esc_url( $shop_url ); ?>" class="rasa-account-btn rasa-account-btn--primary">
				<?php echo esc_html( $shop_btn ); ?>
			</a>
		</div>
	<?php endif; ?>
</section>

<?php do_action( 'woocommerce_after_account_orders', $has_orders ); ?>
