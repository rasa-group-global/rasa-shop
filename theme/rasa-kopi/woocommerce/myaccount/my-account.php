<?php
/**
 * My Account page (Shopify parity: 2-column sidebar layout)
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 3.5.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="rasa-account-container">
	<nav class="rasa-account-nav" aria-label="<?php esc_attr_e( 'Account navigation', 'woocommerce' ); ?>">
		<?php wc_get_template( 'myaccount/navigation.php' ); ?>
	</nav>

	<div class="rasa-account-content">
		<?php
		/**
		 * My Account content.
		 * @since 2.6.0
		 */
		do_action( 'woocommerce_account_content' );
		?>
	</div>
</div>
