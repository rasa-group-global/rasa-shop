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
		<div class="rasa-account-card" style="padding: 0; overflow: hidden;">
			<table class="rasa-account-orders-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Date', 'woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
						<th style="text-align:right;"><?php esc_html_e( 'Actions', 'woocommerce' ); ?></th>
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
								<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" style="color:#ffffff;font-weight:600;text-decoration:underline;">
									#<?php echo esc_html( $order->get_order_number() ); ?>
								</a>
							</td>
							<td style="color:rgba(255,255,255,0.7);">
								<time datetime="<?php echo esc_attr( $order->get_date_created()->date( 'c' ) ); ?>">
									<?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
								</time>
							</td>
							<td>
								<span style="display:inline-block;padding:3px 8px;border-radius:4px;background:rgba(255,255,255,0.1);font-size:1.2rem;color:#ffffff;">
									<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
								</span>
							</td>
							<td style="color:#ffffff;">
								<?php
								/* translators: 1: formatted order total 2: total order items */
								echo wp_kses_post( sprintf( _n( '%1$s for %2$s item', '%1$s for %2$s items', $item_count, 'woocommerce' ), $order->get_formatted_order_total(), $item_count ) );
								?>
							</td>
							<td style="text-align:right;">
								<?php
								$actions = wc_get_account_orders_actions( $order );
								if ( ! empty( $actions ) ) {
									foreach ( $actions as $key => $action ) {
										echo '<a href="' . esc_url( $action['url'] ) . '" class="rasa-account-btn" style="padding:4px 10px;font-size:1.2rem;margin-left:6px;">' . esc_html( $action['name'] ) . '</a>';
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
			<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination" style="margin-top:20px;display:flex;gap:12px;">
				<?php if ( 1 !== $current_page ) : ?>
					<a class="rasa-account-btn" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'woocommerce' ); ?></a>
				<?php endif; ?>

				<?php if ( intval( $customer_orders->max_num_pages ) !== $current_page ) : ?>
					<a class="rasa-account-btn" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'woocommerce' ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>

	<?php else : ?>
		<div class="rasa-account-card" style="text-align:center;padding:40px 20px;">
			<div style="font-size:1.5rem;color:rgba(255,255,255,0.7);margin-bottom:18px;">
				<?php echo esc_html( $no_orders ); ?>
			</div>
			<a href="<?php echo esc_url( home_url( '/shop/' ) ); ?>" class="rasa-account-btn" style="padding:10px 22px;background:#121212;border-color:#121212;font-weight:600;">
				<?php echo esc_html( $shop_btn ); ?>
			</a>
		</div>
	<?php endif; ?>
</section>

<?php do_action( 'woocommerce_after_account_orders', $has_orders ); ?>
