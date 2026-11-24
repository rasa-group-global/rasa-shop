<?php
/**
 * Thankyou page (Order Received) — Shopify-inspired Light Theme layout
 * matching the Rasa Kopi Light Block Checkout aesthetic.
 *
 * Overrides WooCommerce `templates/checkout/thankyou.php`.
 *
 * @var WC_Order|false $order
 */

defined('ABSPATH') || exit;

$rasa_tr = function ($de, $en) {
	return function_exists('rasa_tr') ? rasa_tr($de, $en) : $en;
};
$shop_url = function_exists('wc_get_page_permalink') && wc_get_page_permalink('shop') ? wc_get_page_permalink('shop') : home_url('/shop/');
$contact_url = home_url('/contact/');
?>

<div class="woocommerce-order rasa-ty">

	<?php if ($order): ?>

		<?php do_action('woocommerce_before_thankyou', $order->get_id()); ?>

		<?php
		$is_failed = $order->has_status('failed');
		$first_name = trim((string) $order->get_billing_first_name());
		$order_number = $order->get_order_number();
		$billing_email = $order->get_billing_email();
		$date_created = $order->get_date_created();
		$formatted_date = $date_created ? wc_format_datetime($date_created) : '';
		$status_slug = $order->get_status();
		$status_label = wc_get_order_status_name($status_slug);
		$shipping_addr = $order->get_formatted_shipping_address();
		$billing_addr = $order->get_formatted_billing_address();
		$shipping_meth = $order->get_shipping_method();
		$customer_note = $order->get_customer_note();
		$item_totals = $order->get_order_item_totals();
		$hero_heading = $is_failed
			? $rasa_tr('Zahlung fehlgeschlagen', 'Payment could not be completed')
			: ('' !== $first_name
				? sprintf($rasa_tr('Vielen Dank, %s!', 'Thank you, %s!'), $first_name)
				: $rasa_tr('Vielen Dank für deine Bestellung!', 'Thank you for your order!'));
		?>

		<div class="rasa-ty-grid">

			<div class="rasa-ty-main">

				<header class="rasa-ty-hero<?php echo $is_failed ? ' rasa-ty-hero--failed' : ''; ?>">
					<div class="rasa-ty-hero__icon" aria-hidden="true">
						<?php if ($is_failed): ?>
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
								stroke-linecap="round" stroke-linejoin="round">
								<circle cx="12" cy="12" r="9" />
								<line x1="12" y1="8" x2="12" y2="12" />
								<line x1="12" y1="16" x2="12.01" y2="16" />
							</svg>
						<?php else: ?>
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
								stroke-linecap="round" stroke-linejoin="round">
								<polyline points="20 6 9 17 4 12" />
							</svg>
						<?php endif; ?>
					</div>
					<div class="rasa-ty-hero__text">
						<div class="rasa-ty-hero__kicker">
							<?php echo esc_html(sprintf($rasa_tr('Bestellung #%s', 'Confirmation #%s'), $order_number)); ?>
							<?php if ('' !== $formatted_date): ?>
								<span aria-hidden="true"> · </span>
								<span><?php echo esc_html($formatted_date); ?></span>
							<?php endif; ?>
						</div>
						<h1 class="h1 rasa-ty-hero__title"><?php echo esc_html($hero_heading); ?></h1>
					</div>
				</header>

				<?php if ($is_failed): ?>

					<section class="rasa-ty-card rasa-ty-card--failed">
						<h2 class="rasa-ty-card__title">
							<?php echo esc_html($rasa_tr('Zahlung wurde abgelehnt', 'Payment failed')); ?>
						</h2>
						<p
							class="rasa-ty-card__desc woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed">
							<?php esc_html_e('Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce'); ?>
						</p>
						<div class="rasa-ty-failed-actions woocommerce-thankyou-order-failed-actions">
							<a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>"
								class="button pay rasa-ty-btn-primary">
								<?php esc_html_e('Pay', 'woocommerce'); ?>
							</a>
							<?php if (is_user_logged_in()): ?>
								<a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"
									class="button rasa-ty-btn-secondary">
									<?php esc_html_e('My account', 'woocommerce'); ?>
								</a>
							<?php endif; ?>
						</div>
					</section>

				<?php else: ?>

					<section class="rasa-ty-card">
						<div class="rasa-ty-card__head">
							<h2 class="rasa-ty-card__title">
								<?php echo esc_html($rasa_tr('Deine Bestellung ist bestätigt', 'Your order is confirmed')); ?>
							</h2>
							<span
								class="rasa-ty-status rasa-ty-status--<?php echo esc_attr(sanitize_html_class($status_slug)); ?>">
								<?php echo esc_html($status_label); ?>
							</span>
						</div>
						<p
							class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received rasa-ty-card__desc">
							<?php
							if ('' !== $billing_email) {
								echo wp_kses_post(
									sprintf(
										$rasa_tr(
											'Wir haben deine Bestellung erhalten und bereiten sie gerade vor. Eine Bestätigung wurde an <strong>%s</strong> gesendet.',
											'We have received your order and are getting it ready. A confirmation email has been sent to <strong>%s</strong>.'
										),
										esc_html($billing_email)
									)
								);
							} else {
								echo esc_html(apply_filters('woocommerce_thankyou_order_received_text', esc_html__('Thank you. Your order has been received.', 'woocommerce'), $order));
							}
							?>
						</p>
					</section>

				<?php endif; ?>

				<section class="rasa-ty-card">
					<h2 class="rasa-ty-card__title">
						<?php echo esc_html($rasa_tr('Bestelldetails', 'Order details')); ?>
					</h2>

					<div class="rasa-ty-details-grid">
						<div class="rasa-ty-detail">
							<h3 class="rasa-ty-detail__label">
								<?php echo esc_html($rasa_tr('Kontaktinformationen', 'Contact information')); ?></h3>
							<p class="rasa-ty-detail__value">
								<?php echo esc_html($billing_email ? $billing_email : '—'); ?>
								<?php if ($order->get_billing_phone()): ?>
									<br><?php echo esc_html($order->get_billing_phone()); ?>
								<?php endif; ?>
							</p>
						</div>

						<div class="rasa-ty-detail">
							<h3 class="rasa-ty-detail__label">
								<?php echo esc_html($rasa_tr('Zahlungsmethode', 'Payment method')); ?></h3>
							<p class="rasa-ty-detail__value">
								<?php echo wp_kses_post($order->get_payment_method_title() ? $order->get_payment_method_title() : '—'); ?>
								<span
									class="rasa-ty-detail__sub"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
							</p>
						</div>

						<div class="rasa-ty-detail">
							<h3 class="rasa-ty-detail__label">
								<?php echo esc_html($rasa_tr('Lieferadresse', 'Shipping address')); ?></h3>
							<address class="rasa-ty-detail__address">
								<?php echo wp_kses_post($shipping_addr ? $shipping_addr : ($billing_addr ? $billing_addr : '—')); ?>
							</address>
						</div>

						<div class="rasa-ty-detail">
							<h3 class="rasa-ty-detail__label">
								<?php echo esc_html($rasa_tr('Rechnungsadresse', 'Billing address')); ?></h3>
							<address class="rasa-ty-detail__address">
								<?php echo wp_kses_post($billing_addr ? $billing_addr : '—'); ?>
							</address>
						</div>

						<?php if ('' !== $shipping_meth): ?>
							<div class="rasa-ty-detail">
								<h3 class="rasa-ty-detail__label">
									<?php echo esc_html($rasa_tr('Versandart', 'Shipping method')); ?></h3>
								<p class="rasa-ty-detail__value"><?php echo esc_html($shipping_meth); ?></p>
							</div>
						<?php endif; ?>

						<?php if ('' !== $customer_note): ?>
							<div class="rasa-ty-detail">
								<h3 class="rasa-ty-detail__label">
									<?php echo esc_html($rasa_tr('Bestellnotiz', 'Order note')); ?></h3>
								<p class="rasa-ty-detail__value"><?php echo esc_html($customer_note); ?></p>
							</div>
						<?php endif; ?>
					</div>
				</section>

				<div class="rasa-ty-actions">
					<p class="rasa-ty-help">
						<?php echo esc_html($rasa_tr('Brauchst du Hilfe?', 'Need help?')); ?>
						<a href="<?php echo esc_url($contact_url); ?>" class="underlined-link">
							<?php echo esc_html($rasa_tr('Kontaktiere uns', 'Contact us')); ?>
						</a>
					</p>
					<a href="<?php echo esc_url($shop_url); ?>" class="button button--primary rasa-ty-btn-primary">
						<?php echo esc_html($rasa_tr('Weiter shoppen', 'Continue shopping')); ?>
					</a>
				</div>

				<?php
				$had_order_table = has_action('woocommerce_thankyou', 'woocommerce_order_details_table');
				if ($had_order_table) {
					remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
				}
				?>
				<div class="rasa-ty-gateway-extra">
					<?php
					do_action('woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id());
					do_action('woocommerce_thankyou', $order->get_id());
					?>
				</div>
				<?php
				if ($had_order_table) {
					add_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
				}
				?>

			</div>

			<aside class="rasa-ty-sidebar"
				aria-label="<?php echo esc_attr($rasa_tr('Bestellübersicht', 'Order summary')); ?>">
				<div class="rasa-ty-summary-card">
					<h2 class="rasa-ty-summary-card__title">
						<?php echo esc_html($rasa_tr('Bestellübersicht', 'Order summary')); ?>
					</h2>

					<div class="rasa-ty-items">
						<?php
						foreach ($order->get_items() as $item_id => $item):
							if (!apply_filters('woocommerce_order_item_visible', true, $item)) {
								continue;
							}
							$product = $item->get_product();
							$qty = $item->get_quantity();
							$permalink = $product && $product->is_visible() ? $product->get_permalink($item) : '';
							$thumb_html = $product ? $product->get_image('woocommerce_thumbnail') : '<img src="' . esc_url(wc_placeholder_img_src()) . '" alt="" />';
							$meta_html = wc_display_item_meta($item, array('echo' => false));
							?>
							<div class="rasa-ty-item">
								<div class="rasa-ty-item__media">
									<div class="rasa-ty-item__thumb">
										<?php echo wp_kses_post($thumb_html); ?>
									</div>
									<span class="rasa-ty-item__badge"
										aria-label="<?php echo esc_attr(sprintf($rasa_tr('Menge: %s', 'Quantity: %s'), $qty)); ?>">
										<?php echo esc_html($qty); ?>
									</span>
								</div>
								<div class="rasa-ty-item__info">
									<div class="rasa-ty-item__title">
										<?php
										$item_name_html = $permalink
											? sprintf('<a href="%s">%s</a>', esc_url($permalink), $item->get_name())
											: $item->get_name();
										echo wp_kses_post(apply_filters('woocommerce_order_item_name', $item_name_html, $item, (bool) $permalink));
										?>
									</div>
									<?php if ('' !== trim(wp_strip_all_tags((string) $meta_html))): ?>
										<div class="rasa-ty-item__meta"><?php echo wp_kses_post($meta_html); ?></div>
									<?php endif; ?>
								</div>
								<div class="rasa-ty-item__price">
									<?php echo wp_kses_post($order->get_formatted_line_subtotal($item)); ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<?php if (!empty($item_totals)): ?>
						<dl class="rasa-ty-totals">
							<?php
							foreach ($item_totals as $key => $total):
								$is_grand_total = ('order_total' === $key);
								?>
								<div class="rasa-ty-totals__row<?php echo $is_grand_total ? ' rasa-ty-totals__row--grand' : ''; ?>">
									<dt><?php echo esc_html(rtrim(wp_strip_all_tags((string) $total['label']), ':')); ?></dt>
									<dd><?php echo wp_kses_post($total['value']); ?></dd>
								</div>
							<?php endforeach; ?>
						</dl>
					<?php endif; ?>

					<?php if ($order->get_customer_note()): ?>
						<div class="rasa-ty-summary-note">
							<span><?php echo esc_html($rasa_tr('Hinweis:', 'Note:')); ?></span>
							<span><?php echo esc_html($order->get_customer_note()); ?></span>
						</div>
					<?php endif; ?>
				</div>
			</aside>

		</div>

	<?php else: ?>

		<div class="rasa-ty-empty">
			<h1 class="h1 rasa-ty-hero__title">
				<?php echo esc_html($rasa_tr('Bestellung erhalten', 'Order received')); ?>
			</h1>
			<p
				class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received rasa-ty-card__desc">
				<?php echo esc_html(apply_filters('woocommerce_thankyou_order_received_text', esc_html__('Thank you. Your order has been received.', 'woocommerce'), null)); ?>
			</p>
			<div style="margin-top: 2rem;">
				<a href="<?php echo esc_url($shop_url); ?>" class="button button--primary rasa-ty-btn-primary">
					<?php echo esc_html($rasa_tr('Weiter shoppen', 'Continue shopping')); ?>
				</a>
			</div>
		</div>

	<?php endif; ?>

</div>