<?php
/**
 * My Account navigation (Shopify parity)
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 2.6.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_navigation' );
?>
<ul class="rasa-account-nav__list">
	<?php foreach ( wc_get_account_menu_items() as $endpoint => $label ) : ?>
		<?php
		$is_active = false;
		if ( function_exists( 'is_wc_endpoint_url' ) ) {
			$is_active = is_wc_endpoint_url( $endpoint );
		}
		if ( ! $is_active && 'edit-account' === $endpoint && is_wc_endpoint_url( 'edit-account' ) ) {
			$is_active = true;
		}
		$url = wc_get_account_endpoint_url( $endpoint );
		?>
		<li class="rasa-account-nav__item">
			<a href="<?php echo esc_url( $url ); ?>" class="rasa-account-nav__link<?php echo $is_active ? ' is-active' : ''; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		</li>
	<?php endforeach; ?>
</ul>
<?php do_action( 'woocommerce_after_account_navigation' ); ?>
