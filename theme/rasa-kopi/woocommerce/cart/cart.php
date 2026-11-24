<?php
/**
 * woocommerce/cart/cart.php — Dawn Cart Page (/cart/)
 * Match 1:1 dengan https://shop.rasakopi.coffee/cart
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cart_data    = function_exists( 'rasa_cart_payload' ) ? rasa_cart_payload() : array( 'count' => 0, 'subtotal' => '€0,00 EUR', 'subtotal_num' => 0, 'checkoutUrl' => home_url( '/checkout/' ), 'items' => array() );
$items        = $cart_data['items'] ?? array();
$subtotal_num = (float) ( $cart_data['subtotal_num'] ?? 0 );
$free_min     = 59.0;
$progress     = min( 100, (int) round( ( $subtotal_num / $free_min ) * 100 ) );
$remaining    = max( 0, $free_min - $subtotal_num );
$shop_url     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
$payment_icons = file_exists( RASA_DIR . '/inc/payment-icons.php' ) ? require RASA_DIR . '/inc/payment-icons.php' : array();
?>
<div id="RasaCartPage" class="rasa-cart-page color-scheme-1 gradient <?php echo empty( $items ) ? 'is-empty' : ''; ?>"
	data-free-threshold="59"
	data-free-reached="<?php echo esc_attr( rasa_tr( '✓ Dein Versand ist <strong>kostenlos</strong>', '✓ Your shipping is <strong>free</strong>' ) ); ?>"
	data-free-left="<?php echo esc_attr( rasa_tr( 'Noch <strong>%s</strong> bis zum kostenlosen Versand', 'Only <strong>%s</strong> away from free shipping' ) ); ?>">

	<div class="page-width">
		<div class="cart__warnings" id="RasaCartPageEmpty"<?php echo empty( $items ) ? '' : ' hidden'; ?>>
			<h1 class="cart__empty-text h1"><?php echo esc_html( rasa_tr( 'Dein Warenkorb ist leer', 'Your cart is empty' ) ); ?></h1>
			<a href="<?php echo esc_url( $shop_url ); ?>" class="button button--primary">
				<?php echo esc_html( rasa_tr( 'Weiter shoppen', 'Continue shopping' ) ); ?>
			</a>
		</div>

		<div id="RasaCartPageFilled"<?php echo empty( $items ) ? ' hidden' : ''; ?>>
			<div class="title-wrapper-with-link" style="display:flex;justify-content:space-between;align-items:baseline;margin:2.4rem 0 3.2rem;">
				<h1 class="title title--primary" style="margin:0;"><?php echo esc_html( rasa_tr( 'Dein Warenkorb', 'Your cart' ) ); ?></h1>
				<a href="<?php echo esc_url( $shop_url ); ?>" class="underlined-link" style="font-size:1.4rem;color:inherit;">
					<?php echo esc_html( rasa_tr( 'Weiter shoppen', 'Continue shopping' ) ); ?>
				</a>
			</div>

			<div class="cart__items" style="padding-bottom:3.6rem;border-bottom:1px solid rgba(18,18,18,0.08);">
				<div class="js-contents">
					<table class="cart-items" style="width:100%;border-collapse:collapse;">
						<thead>
							<tr style="border-bottom:1px solid rgba(18,18,18,0.08);">
								<th class="caption-with-letter-spacing" colspan="2" scope="col" style="padding-bottom:1.6rem;font-size:1.1rem;letter-spacing:.12em;text-transform:uppercase;color:rgba(18,18,18,0.65);text-align:left;font-weight:400;">
									<?php echo esc_html( rasa_tr( 'Produkt', 'Product' ) ); ?>
								</th>
								<th class="cart-items__heading--wide medium-up caption-with-letter-spacing" colspan="1" scope="col" style="padding-bottom:1.6rem;font-size:1.1rem;letter-spacing:.12em;text-transform:uppercase;color:rgba(18,18,18,0.65);text-align:left;font-weight:400;">
									<?php echo esc_html( rasa_tr( 'Anzahl', 'Quantity' ) ); ?>
								</th>
								<th class="right caption-with-letter-spacing" colspan="1" scope="col" style="padding-bottom:1.6rem;font-size:1.1rem;letter-spacing:.12em;text-transform:uppercase;color:rgba(18,18,18,0.65);text-align:right;font-weight:400;">
									<?php echo esc_html( rasa_tr( 'Gesamtsumme', 'Total' ) ); ?>
								</th>
							</tr>
						</thead>
						<tbody id="RasaCartPageTbody">
							<?php foreach ( $items as $it ) : ?>
							<tr class="cart-item" data-key="<?php echo esc_attr( $it['key'] ); ?>" style="border-bottom:1px solid rgba(18,18,18,0.06);">
								<td class="cart-item__media" style="width:12rem;padding:2.4rem 2.4rem 2.4rem 0;vertical-align:top;">
									<a href="<?php echo esc_url( $it['url'] ); ?>" style="display:block;width:10rem;background:#efeee9;">
										<?php if ( ! empty( $it['image'] ) ) : ?>
										<img src="<?php echo esc_url( $it['image'] ); ?>" alt="<?php echo esc_attr( $it['name'] ); ?>" class="cart-item__image" width="100" height="120" style="width:10rem;height:12rem;object-fit:cover;display:block;">
										<?php endif; ?>
									</a>
								</td>
								<td class="cart-item__details" style="padding:2.4rem 2rem 2.4rem 0;vertical-align:top;">
									<a href="<?php echo esc_url( $it['url'] ); ?>" class="cart-item__name h4 break" style="font-size:1.5rem;font-weight:400;color:#000;text-decoration:none;display:block;margin-bottom:.6rem;">
										<?php echo esc_html( $it['name'] ); ?>
									</a>
									<div class="product-option" style="font-size:1.4rem;color:rgba(18,18,18,0.75);margin-bottom:.4rem;">
										<?php echo esc_html( $it['unit_price'] ); ?>
									</div>
									<?php if ( ! empty( $it['variation'] ) ) : ?>
									<div class="product-option" style="font-size:1.4rem;color:rgba(18,18,18,0.75);">
										<?php echo esc_html( $it['variation'] ); ?>
									</div>
									<?php endif; ?>
								</td>
								<td class="cart-item__quantity" style="padding:2.4rem 2rem 2.4rem 0;vertical-align:top;width:24rem;">
									<div class="cart-item__quantity-wrapper" style="display:flex;align-items:center;gap:1.2rem;">
										<div class="rasa-cart-page__qty">
											<button type="button" data-rasa-page-qty="<?php echo (int) ( $it['qty'] - 1 ); ?>" aria-label="<?php echo esc_attr( rasa_tr( 'Menge verringern', 'Decrease quantity' ) ); ?>">−</button>
											<span><?php echo (int) $it['qty']; ?></span>
											<button type="button" data-rasa-page-qty="<?php echo (int) ( $it['qty'] + 1 ); ?>" aria-label="<?php echo esc_attr( rasa_tr( 'Menge erhöhen', 'Increase quantity' ) ); ?>">+</button>
										</div>
										<button type="button" class="rasa-cart-page__remove" data-rasa-page-qty="0" aria-label="<?php echo esc_attr( rasa_tr( 'Entfernen', 'Remove' ) ); ?>" title="<?php echo esc_attr( rasa_tr( 'Entfernen', 'Remove' ) ); ?>">
											<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true" focusable="false" width="16" height="16" fill="currentColor"><path d="M14 3h-3.53a3.07 3.07 0 00-.6-1.65C9.44.82 8.8.5 8 .5s-1.44.32-1.87.85A3.06 3.06 0 005.53 3H2a.5.5 0 000 1h1.25v10c0 .28.22.5.5.5h8.5a.5.5 0 00.5-.5V4H14a.5.5 0 000-1zM6.91 1.98c.23-.29.58-.48 1.09-.48s.85.19 1.09.48c.2.24.3.6.36 1.02h-2.9c.05-.42.17-.78.36-1.02zm4.84 11.52h-7.5V4h7.5v9.5z"/><path d="M6.55 5.25a.5.5 0 00-.5.5v6a.5.5 0 001 0v-6a.5.5 0 00-.5-.5zM9.45 5.25a.5.5 0 00-.5.5v6a.5.5 0 001 0v-6a.5.5 0 00-.5-.5z"/></svg>
										</button>
									</div>
								</td>
								<td class="cart-item__totals right" style="padding:2.4rem 0;vertical-align:top;text-align:right;white-space:nowrap;">
									<span class="price price--end" style="font-size:1.6rem;color:#000;"><?php echo esc_html( $it['line_total'] ); ?></span>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="cart__footer-wrapper" style="padding-top:3.2rem;padding-bottom:6rem;">
				<div class="cart__footer" style="display:flex;justify-content:flex-end;">
					<div class="cart__blocks" style="width:100%;max-width:36rem;margin-left:auto;">
						<div class="rasa-free-shipping-box">
							<div class="rasa-free-shipping-text" id="RasaCartPageShipText">
								<?php
								if ( $remaining <= 0 ) {
									echo wp_kses_post( rasa_tr( '✓ Dein Versand ist <strong>kostenlos</strong>', '✓ Your shipping is <strong>free</strong>' ) );
								} else {
									echo wp_kses_post( sprintf( rasa_tr( 'Noch <strong>%s</strong> bis zum kostenlosen Versand', 'Only <strong>%s</strong> away from free shipping' ), esc_html( rasa_format_eur( $remaining ) ) ) );
								}
								?>
							</div>
							<div class="rasa-free-shipping-bar">
								<span id="RasaCartPageShipBar" style="width:<?php echo (int) $progress; ?>%;"></span>
							</div>
						</div>

						<div class="totals" style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.8rem;">
							<h2 class="totals__total" style="margin:0;font-size:1.6rem;font-weight:400;color:#000;"><?php echo esc_html( rasa_tr( 'Geschätzte Gesamtkosten', 'Estimated total' ) ); ?></h2>
							<p class="totals__total-value" id="RasaCartPageSubtotal" style="margin:0;font-size:1.8rem;font-weight:400;color:#000;"><?php echo esc_html( $cart_data['subtotal'] ); ?></p>
						</div>

						<small class="tax-note caption-large rte" style="display:block;text-align:right;font-size:1.3rem;line-height:1.5;color:rgba(18,18,18,0.7);margin:0 0 2rem;">
							<?php echo esc_html( rasa_tr( 'Inkl. Steuern. Rabatte und Versand werden beim Checkout berechnet.', 'Taxes included. Discounts and shipping calculated at checkout.' ) ); ?>
						</small>

						<div class="cart__ctas" style="display:block;width:100%;">
							<a href="<?php echo esc_url( $cart_data['checkoutUrl'] ); ?>" class="cart__checkout-button button button--primary" style="width:100%;max-width:100%;min-height:4.9rem;display:inline-flex;align-items:center;justify-content:center;background:#121212;color:#fff;text-decoration:none;font-size:1.5rem;letter-spacing:.08rem;">
								<?php echo esc_html( rasa_tr( 'Auschecken', 'Checkout' ) ); ?>
							</a>
						</div>

						<div class="rasa-cart-trust">
							<span>🔒 <?php echo esc_html( rasa_tr( 'Sichere Bezahlung', 'Secure payment' ) ); ?></span>
							<span>·</span>
							<span>🚚 <?php echo esc_html( rasa_tr( 'Lieferung in 3–7 Werktagen', 'Delivery in 3–7 business days' ) ); ?></span>
						</div>

						<?php if ( ! empty( $payment_icons ) ) : ?>
						<ul class="list list-payment rasa-cart-payments" role="list">
							<?php foreach ( $payment_icons as $svg ) : ?>
							<li class="list-payment__item"><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
							<?php endforeach; ?>
						</ul>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
