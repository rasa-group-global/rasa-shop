<?php
/**
 * index.php — fallback template (blog posts / pages default)
 */
if (!defined('ABSPATH'))
	exit;

get_header();

if (function_exists('is_cart') && is_cart() && file_exists(RASA_DIR . '/woocommerce/cart/cart.php')) {
	include RASA_DIR . '/woocommerce/cart/cart.php';
	get_footer();
	return;
}

if (function_exists('is_checkout') && is_checkout()) {
	$is_order_received = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received');
	$checkout_title = $is_order_received
		? (function_exists('rasa_tr') ? rasa_tr('Bestellung erhalten', 'Order received') : get_the_title())
		: (function_exists('rasa_tr') ? rasa_tr('Kasse', 'Checkout') : get_the_title());
	?>
	<div class="rasa-page-wrap color-scheme-1 gradient" style="padding: 36px 0 64px;">
		<div class="page-width">
			<article class="rasa-page-article color-scheme-1 gradient" style="display:block;width:100%;">
					<h1 class="h1 article__title" style="margin:0 0 2.4rem;"><?php echo esc_html($checkout_title); ?></h1>
					<div class="article__content rasa-checkout-content" style="width:100%;display:block;">
						<?php echo do_shortcode('[woocommerce_checkout]'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
			</article>
		</div>
	</div>
	<?php
	get_footer();
	return;
}
?>
<div class="rasa-page-wrap color-scheme-1 gradient" style="padding: 36px 0 64px;">
	<div class="page-width">
		<?php
		while (have_posts()):
			the_post();
			?>
			<article class="rasa-page-article color-scheme-1 gradient" style="display:block;width:100%;">
				<h1 class="h1 article__title" style="margin:0 0 2.4rem;"><?php the_title(); ?></h1>
				<div class="article__content rte" style="width:100%;display:block;"><?php the_content(); ?></div>
			</article>
			<?php
		endwhile;
		?>
	</div>
</div>
<?php
get_footer();
