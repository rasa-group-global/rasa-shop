<?php
/**
 * woocommerce/checkout/form-checkout.php — Light Theme 2-Column Classic Checkout
 * Pertahankan seluruh native hooks WooCommerce & Revolut Gateway.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );

do_action( 'woocommerce_before_checkout_form', $checkout );

// If checkout registration is disabled and not logged in, the user cannot checkout.
if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}

$tr = function ( $de, $en ) {
	return function_exists( 'rasa_tr' ) ? rasa_tr( $de, $en ) : $en;
};
?>

<form name="checkout" method="post" class="checkout woocommerce-checkout rasa-co-form" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php esc_attr_e( 'Checkout', 'woocommerce' ); ?>">

	<div class="rasa-co-grid">
		<div class="rasa-co-main">
			<?php if ( $checkout->get_checkout_fields() ) : ?>

				<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

				<div class="col2-set" id="customer_details">
					<div class="col-1">
						<?php do_action( 'woocommerce_checkout_billing' ); ?>
					</div>

					<div class="col-2">
						<?php do_action( 'woocommerce_checkout_shipping' ); ?>
					</div>
				</div>

				<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

			<?php endif; ?>

			<div class="rasa-co-payment-section">
				<h3 class="rasa-co-payment-heading"><?php echo esc_html( $tr( 'Zahlungsmethoden', 'Payment options' ) ); ?></h3>
				<?php woocommerce_checkout_payment(); ?>
			</div>
		</div>

		<aside class="rasa-co-sidebar" aria-label="<?php echo esc_attr( $tr( 'Bestellübersicht', 'Order summary' ) ); ?>">
			<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
			<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

			<div id="order_review" class="woocommerce-checkout-review-order">
				<?php do_action( 'woocommerce_checkout_order_review' ); ?>
			</div>

			<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
		</aside>
	</div>

</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
