<?php
/**
 * Edit account form (Shopify parity)
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 8.7.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_edit_account_form' );

$user_id = get_current_user_id();
$user    = get_userdata( $user_id );

$billing_address_1 = get_user_meta( $user_id, 'billing_address_1', true );
$billing_city      = get_user_meta( $user_id, 'billing_city', true );
$billing_postcode  = get_user_meta( $user_id, 'billing_postcode', true );
$billing_country   = get_user_meta( $user_id, 'billing_country', true );

$has_address = ! empty( $billing_address_1 );
$address_url = wc_get_account_endpoint_url( 'edit-address' );

$marketing_opt_in = (bool) get_user_meta( $user_id, 'marketing_opt_in', true );

$contact_title   = function_exists( 'rasa_tr' ) ? rasa_tr( 'Kontakt', 'Contact' ) : 'Contact';
$addresses_title = function_exists( 'rasa_tr' ) ? rasa_tr( 'Adressen', 'Addresses' ) : 'Addresses';
$marketing_title = function_exists( 'rasa_tr' ) ? rasa_tr( 'Marketing-Einstellungen', 'Marketing preferences' ) : 'Marketing preferences';
$edit_btn_label  = function_exists( 'rasa_tr' ) ? rasa_tr( 'Bearbeiten', 'Edit' ) : 'Edit';
$add_btn_label   = function_exists( 'rasa_tr' ) ? rasa_tr( 'Hinzufügen', 'Add' ) : 'Add';
$signout_label   = function_exists( 'rasa_tr' ) ? rasa_tr( 'Abmelden', 'Sign out' ) : 'Sign out';
$signout_all     = function_exists( 'rasa_tr' ) ? rasa_tr( 'Von allen Geräten abmelden', 'Sign out of all devices' ) : 'Sign out of all devices';
$no_addr_label   = function_exists( 'rasa_tr' ) ? rasa_tr( 'Keine Adressen hinzugefügt', 'No addresses added' ) : 'No addresses added';
$terms_label     = function_exists( 'rasa_tr' ) ? rasa_tr( 'AGB & Datenschutz', 'Terms and policies' ) : 'Terms and policies';
?>

<!-- ================= 1. CONTACT SECTION ================= -->
<section class="rasa-account-section">
	<div class="rasa-account-section__header">
		<h2 class="rasa-account-section__title"><?php echo esc_html( $contact_title ); ?></h2>
		<button type="button" class="rasa-account-btn" id="RasaToggleContactEdit">
			<?php echo esc_html( $edit_btn_label ); ?>
		</button>
	</div>

	<!-- Contact View Card (Shopify parity) -->
	<div class="rasa-account-card" id="RasaContactCardView">
		<div class="rasa-account-card__row">
			<span class="rasa-account-card__label"><?php echo esc_html( function_exists( 'rasa_tr' ) ? rasa_tr( 'E-Mail', 'Email' ) : 'Email' ); ?></span>
			<span class="rasa-account-card__value"><?php echo esc_html( $user->user_email ); ?></span>
		</div>
		<?php if ( ! empty( $user->first_name ) || ! empty( $user->last_name ) ) : ?>
			<div class="rasa-account-card__row">
				<span class="rasa-account-card__label"><?php echo esc_html( function_exists( 'rasa_tr' ) ? rasa_tr( 'Name', 'Name' ) : 'Name' ); ?></span>
				<span class="rasa-account-card__value"><?php echo esc_html( trim( $user->first_name . ' ' . $user->last_name ) ); ?></span>
			</div>
		<?php endif; ?>
	</div>

	<!-- Contact Edit Form (Hidden by default, reveals on [Edit] click) -->
	<div class="rasa-account-edit-form-wrap" id="RasaContactFormWrapper" hidden style="display:none;">
		<form class="woocommerce-EditAccountForm edit-account rasa-account-edit-form" action="" method="post" <?php do_action( 'woocommerce_edit_account_form_tag' ); ?>>
			<?php do_action( 'woocommerce_edit_account_form_start' ); ?>

			<div class="rasa-account-edit-form__grid">
				<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
					<label for="account_first_name"><?php esc_html_e( 'First name', 'woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
					<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_first_name" id="account_first_name" autocomplete="given-name" value="<?php echo esc_attr( $user->first_name ); ?>" />
				</p>
				<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
					<label for="account_last_name"><?php esc_html_e( 'Last name', 'woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
					<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_last_name" id="account_last_name" autocomplete="family-name" value="<?php echo esc_attr( $user->last_name ); ?>" />
				</p>
			</div>
			<div class="clear"></div>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="account_display_name"><?php esc_html_e( 'Display name', 'woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_display_name" id="account_display_name" value="<?php echo esc_attr( $user->display_name ); ?>" />
				<small class="rasa-account-edit-form__hint"><?php esc_html_e( 'This will be how your name will be displayed in the account section and in reviews', 'woocommerce' ); ?></small>
			</p>
			<div class="clear"></div>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="account_email"><?php esc_html_e( 'Email address', 'woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
				<input type="email" class="woocommerce-Input woocommerce-Input--email input-text" name="account_email" id="account_email" autocomplete="email" value="<?php echo esc_attr( $user->user_email ); ?>" />
			</p>

			<?php
			/**
			 * Hook where additional fields like VAT ID are hooked.
			 */
			do_action( 'woocommerce_edit_account_form' );
			?>

			<fieldset>
				<legend><?php esc_html_e( 'Password change', 'woocommerce' ); ?></legend>

				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="password_current"><?php esc_html_e( 'Current password (leave blank to leave unchanged)', 'woocommerce' ); ?></label>
					<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_current" id="password_current" autocomplete="current-password" />
				</p>
				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="password_1"><?php esc_html_e( 'New password (leave blank to leave unchanged)', 'woocommerce' ); ?></label>
					<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_1" id="password_1" autocomplete="new-password" />
				</p>
				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="password_2"><?php esc_html_e( 'Confirm new password', 'woocommerce' ); ?></label>
					<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_2" id="password_2" autocomplete="new-password" />
				</p>
			</fieldset>
			<div class="clear"></div>

			<?php do_action( 'woocommerce_edit_account_form_end' ); ?>

			<div class="rasa-account-edit-form__actions">
				<?php wp_nonce_field( 'save_account_details', 'save-account-details-nonce' ); ?>
				<button type="submit" class="woocommerce-Button button rasa-account-btn rasa-account-btn--primary" name="save_account_details" value="<?php esc_attr_e( 'Save changes', 'woocommerce' ); ?>">
					<?php esc_html_e( 'Save changes', 'woocommerce' ); ?>
				</button>
				<button type="button" class="rasa-account-btn" id="RasaCancelContactEdit">
					<?php echo esc_html( function_exists( 'rasa_tr' ) ? rasa_tr( 'Abbrechen', 'Cancel' ) : 'Cancel' ); ?>
				</button>
				<input type="hidden" name="action" value="save_account_details" />
			</div>
		</form>
	</div>
</section>

<!-- ================= 2. ADDRESSES SECTION ================= -->
<section class="rasa-account-section">
	<div class="rasa-account-section__header">
		<h2 class="rasa-account-section__title"><?php echo esc_html( $addresses_title ); ?></h2>
		<a href="<?php echo esc_url( $address_url ); ?>" class="rasa-account-btn">
			<?php echo esc_html( $has_address ? $edit_btn_label : $add_btn_label ); ?>
		</a>
	</div>

	<div class="rasa-account-card">
		<div class="rasa-account-addr">
			<div class="rasa-account-addr__icon">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
					<circle cx="12" cy="10" r="3"></circle>
				</svg>
			</div>
			<div>
				<?php if ( $has_address ) : ?>
					<div class="rasa-account-addr__line1">
						<?php echo esc_html( $billing_address_1 ); ?>
					</div>
					<div class="rasa-account-addr__line2">
						<?php echo esc_html( trim( $billing_postcode . ' ' . $billing_city . ', ' . $billing_country ) ); ?>
					</div>
				<?php else : ?>
					<div class="rasa-account-addr__empty">
						<?php echo esc_html( $no_addr_label ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>

<!-- ================= 3. MARKETING PREFERENCES ================= -->
<section class="rasa-account-section">
	<div class="rasa-account-section__header">
		<h2 class="rasa-account-section__title"><?php echo esc_html( $marketing_title ); ?></h2>
	</div>

	<div class="rasa-account-card">
		<div class="rasa-account-marketing__row">
			<div class="rasa-account-marketing__info">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
					<polyline points="22,6 12,13 2,6"></polyline>
				</svg>
				<span class="rasa-account-marketing__label">Email</span>
			</div>
			<label class="rasa-toggle-switch" aria-label="Email Marketing Preference">
				<input type="checkbox" id="RasaMarketingToggle" <?php checked( $marketing_opt_in ); ?> />
				<span class="rasa-toggle-slider"></span>
			</label>
		</div>
	</div>
</section>

<!-- ================= 4. SIGN OUT ================= -->
<section class="rasa-account-section rasa-account-section--signout">
	<div class="rasa-account-signout__row">
		<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="rasa-account-btn rasa-account-btn--signout">
			<?php echo esc_html( $signout_label ); ?>
		</a>
		<span class="rasa-account-signout__note">
			<?php echo esc_html( $signout_all ); ?>
		</span>
	</div>
</section>

<!-- ================= 5. FOOTER POLICIES ================= -->
<div class="rasa-account-terms">
	<a href="<?php echo esc_url( home_url( '/agb/' ) ); ?>" class="rasa-account-terms__link">
		<?php echo esc_html( $terms_label ); ?>
	</a>
</div>

<?php do_action( 'woocommerce_after_edit_account_form' ); ?>
