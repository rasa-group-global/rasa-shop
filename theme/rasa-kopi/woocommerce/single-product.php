<?php
/**
 * woocommerce/single-product.php — Dawn Single Product Page (/product/...)
 * Match 1:1 dengan https://shop.rasakopi.coffee/products/...
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

while ( have_posts() ) :
	the_post();
	global $product;
	if ( ! ( $product instanceof WC_Product ) ) {
		$product = wc_get_product( get_the_ID() );
	}
	if ( ! $product ) continue;

	$images = array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() );
	$images = array_values( array_filter( $images ) );

	$is_variable = $product->is_type( 'variable' );
	$variations  = $is_variable ? $product->get_available_variations() : array();
	$attributes  = $is_variable ? $product->get_variation_attributes() : array();
	$default_attrs = $is_variable ? $product->get_default_attributes() : array();

	$shipping_url = home_url( '/versand-zahlung/' );

	// Per-variant galleries: variation image + variation meta `_rasa_gallery` (comma-separated attachment IDs).
	// Used only when the product has one attribute and at least one variation has `_rasa_gallery`.
	$rasa_groups   = array();
	$rasa_var_map  = array();
	if ( $is_variable && 1 === count( $attributes ) ) {
		$rasa_has_gallery = false;
		foreach ( $product->get_children() as $rasa_vid ) {
			$rasa_v = wc_get_product( $rasa_vid );
			if ( ! $rasa_v ) {
				continue;
			}
			$rasa_attrs = $rasa_v->get_attributes();
			$rasa_val   = strtolower( (string) reset( $rasa_attrs ) );
			if ( '' === $rasa_val ) {
				continue;
			}
			$rasa_extra = array_filter( array_map( 'absint', explode( ',', (string) $rasa_v->get_meta( '_rasa_gallery' ) ) ) );
			if ( $rasa_extra ) {
				$rasa_has_gallery = true;
			}
			$rasa_ids = array_values( array_unique( array_filter( array_merge( array( (int) $rasa_v->get_image_id() ), $rasa_extra ) ) ) );
			$rasa_groups[ $rasa_val ]  = $rasa_ids;
			$rasa_var_map[ $rasa_val ] = (int) $rasa_vid;
		}
		if ( ! $rasa_has_gallery ) {
			$rasa_groups = array();
		}
	}
	$rasa_default_val = '';
	if ( $rasa_groups ) {
		$rasa_first_attr  = array_key_first( $attributes );
		$rasa_default_val = strtolower( (string) ( $default_attrs[ sanitize_title( $rasa_first_attr ) ] ?? ( $attributes[ $rasa_first_attr ][0] ?? '' ) ) );
	}
	?>
	<section class="page-width section-main-product" style="padding-top: 36px; padding-bottom: 48px;">
		<div class="product product--large product--left product--stacked grid grid--1-col grid--2-col-tablet">
			<!-- Left Column: Stacked Gallery (65%) -->
			<div class="grid__item product__media-wrapper" style="flex: 0 0 65%; max-width: 65%;">
				<?php
				$rasa_gallery_sets = $rasa_groups ? $rasa_groups : array( '' => $images );
				foreach ( $rasa_gallery_sets as $rasa_key => $images ) :
					$rasa_hidden = $rasa_groups && $rasa_key !== $rasa_default_val;
					?>
				<div class="rasa-pdp-gallery"<?php echo $rasa_groups ? ' data-rasa-group="' . esc_attr( $rasa_key ) . '"' : ''; ?><?php echo $rasa_hidden ? ' hidden' : ''; ?> style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;">
					<?php foreach ( $images as $idx => $img_id ) :
						$src = wp_get_attachment_image_url( $img_id, 'large' ) ?: wp_get_attachment_image_url( $img_id, 'full' );
						$full = wp_get_attachment_image_url( $img_id, 'full' );
						$alt = get_post_meta( $img_id, '_wp_attachment_image_alt', true ) ?: $product->get_name();
						$span = ( 0 === $idx ) ? 'grid-column:1 / -1;' : '';
						$anim_cls = ( 0 === $idx ) ? 'scroll-trigger animate--fade-in' : 'scroll-trigger animate--slide-in';
						$anim_attr = ( 0 === $idx ) ? '' : ' data-cascade';
						?>
						<div class="rasa-pdp-gallery__item <?php echo esc_attr( $anim_cls ); ?>"<?php echo $anim_attr; ?> style="<?php echo esc_attr( $span ); ?>background:#efeee9;overflow:hidden;--animation-order:<?php echo (int) $idx; ?>;">
							<a href="<?php echo esc_url( $full ); ?>" class="rasa-zoom" aria-label="<?php echo esc_attr( rasa_tr( 'Bild vergrößern', 'Enlarge image' ) ); ?>">
								<img src="<?php echo esc_url( $src ); ?>" alt="<?php echo esc_attr( $alt ); ?>" style="width:100%;height:auto;display:block;object-fit:cover;" <?php echo 0 === $idx ? 'fetchpriority="high"' : 'loading="lazy"'; ?>>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- Right Column: Sticky Product Info (35%) -->
			<div class="product__info-wrapper grid__item" style="flex: 1 1 35%; max-width: 35%; padding-left: 5rem;">
				<section class="product__info-container product__column-sticky scroll-trigger animate--slide-in">
					<p style="margin:0 0 .4rem;font-size:1.2rem;letter-spacing:.12em;text-transform:uppercase;color:rgba(18,18,18,.75);">Rasa Kopi</p>

					<div class="product__title">
						<h1 style="margin:0;font-size:4rem;line-height:5.2rem;font-weight:400;color:#000;"><?php the_title(); ?></h1>
					</div>

					<div class="no-js-hidden" id="price-template" role="status" style="margin:1.2rem 0 .4rem;">
						<div class="price price--large" style="font-size:1.8rem;line-height:2.7rem;letter-spacing:1.3px;color:#000;">
							<?php echo rasa_price_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</div>
					</div>

					<?php
					$rasa_unit_price_html = function_exists( 'rasa_get_product_unit_price_html' ) ? rasa_get_product_unit_price_html( $product ) : '';
					$rasa_is_b2b          = function_exists( 'rasa_is_b2b_customer' ) && rasa_is_b2b_customer();
					$rasa_delivery_time   = function_exists( 'rasa_get_product_delivery_time' ) ? rasa_get_product_delivery_time( $product ) : rasa_tr( '3–7 Werktage', '3–7 business days' );
					?>
					<div class="rasa-price-note" style="font-size:1.2rem;line-height:1.6;color:rgba(0,0,0,0.75);margin-bottom:2rem;">
						<?php if ( '' !== $rasa_unit_price_html ) : ?>
							<div class="rasa-unit-price-row"><?php echo $rasa_unit_price_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<?php endif; ?>
						<div><?php echo esc_html( $rasa_is_b2b ? rasa_tr( 'zzgl. MwSt., zzgl.', 'excl. VAT, plus' ) : rasa_tr( 'inkl. MwSt., zzgl.', 'incl. VAT, plus' ) ); ?> <a href="<?php echo esc_url( $shipping_url ); ?>" style="color:inherit;text-decoration:underline;text-underline-offset:.3rem;"><?php echo esc_html( rasa_tr( 'Versandkosten', 'shipping' ) ); ?></a></div>
						<div><?php echo esc_html( rasa_tr( 'Lieferzeit:', 'Delivery time:' ) . ' ' . $rasa_delivery_time ); ?></div>
					</div>

					<form class="cart" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype="multipart/form-data" id="RasaPdpForm">
						<?php if ( $is_variable && ! empty( $attributes ) ) : ?>
							<div class="product-form__variants" style="margin-bottom:2rem;">
								<?php foreach ( $attributes as $attr_name => $options ) :
									$label = wc_attribute_label( $attr_name );
									if ( strtolower( $label ) === 'size' || strtolower( $label ) === 'größe' ) {
										$label = rasa_tr( 'Größe', 'Size' );
									}
									$field_name = 'attribute_' . sanitize_title( $attr_name );
									$selected = $default_attrs[ sanitize_title( $attr_name ) ] ?? ( $options[0] ?? '' );
									?>
									<fieldset class="js product-form__input product-form__input--pill" style="border:0;padding:0;margin:0 0 1.6rem;">
										<legend class="form__label" style="font-size:1.3rem;margin-bottom:.8rem;color:rgba(0,0,0,0.75);"><?php echo esc_html( $label ); ?></legend>
										<div style="display:flex;flex-wrap:wrap;gap:.8rem;">
											<?php foreach ( $options as $opt ) :
												$opt_id = sanitize_title( $attr_name . '-' . $opt );
												$is_checked = ( strtolower( (string) $selected ) === strtolower( (string) $opt ) );
												?>
												<label class="rasa-pill-option" style="cursor:pointer;">
													<input
														type="radio"
														name="<?php echo esc_attr( $field_name ); ?>"
														value="<?php echo esc_attr( $opt ); ?>"
														id="<?php echo esc_attr( $opt_id ); ?>"
														<?php checked( $is_checked ); ?>
														class="rasa-pill-radio visually-hidden"
													>
													<span class="rasa-pill-label" style="display:inline-block;padding:.9rem 2rem;border-radius:4rem;font-size:1.4rem;line-height:1;border:1px solid <?php echo $is_checked ? '#000' : 'rgba(0,0,0,0.55)'; ?>;background:<?php echo $is_checked ? '#000' : '#f3f3f3'; ?>;color:<?php echo $is_checked ? '#f3f3f3' : '#000'; ?>;transition:all .15s ease;">
														<?php echo esc_html( strtoupper( $opt ) ); ?>
													</span>
												</label>
											<?php endforeach; ?>
										</div>
									</fieldset>
								<?php endforeach; ?>
								<input type="hidden" name="variation_id" id="RasaVariationId" value="">
							</div>
						<?php endif; ?>

						<div class="product-form__input product-form__quantity" style="margin-bottom:2.2rem;">
							<label class="form__label" for="RasaQty" style="display:block;font-size:1.3rem;margin-bottom:.8rem;color:rgba(0,0,0,0.75);"><?php echo esc_html( rasa_tr( 'Anzahl', 'Quantity' ) ); ?></label>
							<div class="quantity" style="width:14.2rem;height:4.7rem;display:flex;align-items:center;background:#f3f3f3;float:none;margin:0;">
								<button type="button" class="quantity__button" onclick="var i=document.getElementById('RasaQty');if(i.value>1)i.value--;" aria-label="Decrease quantity" style="width:4.5rem;height:100%;border:0;background:transparent;font-size:1.8rem;cursor:pointer;">−</button>
								<input id="RasaQty" class="quantity__input" type="number" name="quantity" min="1" value="1" style="width:100%;text-align:center;border:0;background:transparent;font-size:1.4rem;font-family:inherit;-moz-appearance:textfield;">
								<button type="button" class="quantity__button" onclick="var i=document.getElementById('RasaQty');i.value++;" aria-label="Increase quantity" style="width:4.5rem;height:100%;border:0;background:transparent;font-size:1.8rem;cursor:pointer;">+</button>
							</div>
						</div>

						<input type="hidden" name="add-to-cart" value="<?php echo (int) $product->get_id(); ?>">
						<?php if ( $is_variable ) : ?>
						<input type="hidden" name="product_id" value="<?php echo (int) $product->get_id(); ?>">
						<?php endif; ?>
						<div id="RasaPdpError" role="alert" hidden style="margin:0 0 1.2rem;padding:1rem 1.4rem;border:1px solid #c62828;background:#fff5f5;color:#b71c1c;font-size:1.3rem;line-height:1.5;"></div>
						<?php if ( $product->is_in_stock() ) : ?>
						<button type="submit" name="add-to-cart" value="<?php echo (int) $product->get_id(); ?>" class="product-form__submit button button--full-width button--primary" style="width:100%;min-height:4.9rem;background:#121212;color:#ffffff;font-size:1.5rem;letter-spacing:.1rem;border:0;cursor:pointer;">
							<?php echo esc_html( rasa_tr( 'In den Warenkorb legen', 'Add to cart' ) ); ?>
						</button>
						<?php else : ?>
						<button type="button" class="product-form__submit button button--full-width button--secondary" disabled aria-disabled="true" style="opacity:.6;cursor:not-allowed;"><?php echo esc_html( rasa_tr( 'Ausverkauft', 'Sold out' ) ); ?></button>
						<?php endif; ?>
					</form>

					<!-- Trust row (conversion): shipping, withdrawal, payment, origin -->
					<ul class="rasa-trust" role="list">
						<li><svg aria-hidden="true" viewBox="0 -960 960 960"><path d="M240-160q-50 0-85-35t-35-85H40v-440q0-33 23.5-56.5T120-800h560v160h120l120 160v200h-80q0 50-35 85t-85 35q-50 0-85-35t-35-85H360q0 50-35 85t-85 35Zm0-80q17 0 28.5-11.5T280-280q0-17-11.5-28.5T240-320q-17 0-28.5 11.5T200-280q0 17 11.5 28.5T240-240ZM120-360h32q17-18 39-29t49-11q27 0 49 11t39 29h272v-360H120v360Zm600 120q17 0 28.5-11.5T760-280q0-17-11.5-28.5T720-320q-17 0-28.5 11.5T680-280q0 17 11.5 28.5T720-240Zm-40-200h170l-90-120h-80v120Z"/></svg><span><?php echo esc_html( rasa_tr( 'Kostenloser Versand ab 59 €', 'Free shipping over €59' ) ); ?></span></li>
						<li><svg aria-hidden="true" viewBox="0 -960 960 960"><path d="M280-200v-80h284q63 0 109.5-40T720-420q0-60-46.5-100T564-560H312l104 104-56 56-200-200 200-200 56 56-104 104h252q97 0 166.5 63T800-420q0 94-69.5 157T564-200H280Z"/></svg><span><?php echo esc_html( rasa_tr( '14 Tage Widerrufsrecht', '14-day right of withdrawal' ) ); ?></span></li>
						<li><svg aria-hidden="true" viewBox="0 -960 960 960"><path d="M240-80q-33 0-56.5-23.5T160-160v-400q0-33 23.5-56.5T240-640h40v-80q0-83 58.5-141.5T480-920q83 0 141.5 58.5T680-720v80h40q33 0 56.5 23.5T800-560v400q0 33-23.5 56.5T720-80H240Zm0-80h480v-400H240v400Zm240-120q33 0 56.5-23.5T560-360q0-33-23.5-56.5T480-440q-33 0-56.5 23.5T400-360q0 33 23.5 56.5T480-280ZM360-640h240v-80q0-50-35-85t-85-35q-50 0-85 35t-35 85v80Z"/></svg><span><?php echo esc_html( rasa_tr( 'Sicher bezahlen', 'Secure payment' ) ); ?></span></li>
						<li><svg aria-hidden="true" viewBox="0 -960 960 960"><path d="M480-480q33 0 56.5-23.5T560-560q0-33-23.5-56.5T480-640q-33 0-56.5 23.5T400-560q0 33 23.5 56.5T480-480Zm0 294q122-112 181-203.5T720-552q0-109-69.5-178.5T480-800q-101 0-170.5 69.5T240-552q0 71 59 162.5T480-186Zm0 106Q319-217 239.5-334.5T160-552q0-150 96.5-239T480-880q127 0 223.5 89T800-552q0 100-79.5 217.5T480-80Z"/></svg><span><?php echo esc_html( rasa_tr( 'Versand aus Deutschland', 'Ships from Germany' ) ); ?></span></li>
					</ul>

					<!-- Product Description: intro + collapsible sections (one per <h3>) -->
					<?php
					$rasa_desc = apply_filters( 'the_content', get_the_content() );
					$rasa_parts = preg_split( '/<h3[^>]*>(.*?)<\/h3>/s', $rasa_desc, -1, PREG_SPLIT_DELIM_CAPTURE );
					$rasa_intro = trim( (string) array_shift( $rasa_parts ) );
					?>
					<div class="product__description rte quick-add-hidden rasa-desc">
						<?php if ( '' !== $rasa_intro ) : ?>
						<div class="rasa-desc__intro"><?php echo $rasa_intro; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- post content ?></div>
						<?php endif; ?>
						<?php for ( $rasa_i = 0; $rasa_i + 1 < count( $rasa_parts ); $rasa_i += 2 ) : ?>
						<details class="rasa-acc"<?php echo 0 === $rasa_i ? ' open' : ''; ?>>
							<summary><?php echo wp_kses_post( $rasa_parts[ $rasa_i ] ); ?></summary>
							<div class="rasa-acc__body"><?php echo $rasa_parts[ $rasa_i + 1 ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- post content ?></div>
						</details>
						<?php endfor; ?>
					</div>

					<!-- EU Statutory Warranty Notice -->
					<div class="rasa-eu-notice" style="margin-top:2.4rem;padding-top:2rem;border-top:1px solid rgba(18,18,18,.12);">
						<p style="margin:0 0 1.2rem;font-size:1.4rem;font-weight:600;color:rgba(0,0,0,0.75);"><?php echo esc_html( rasa_tr( 'Gesetzliche Gewährleistung', 'Statutory Warranty' ) ); ?></p>
						<a href="https://europa.eu/youreurope/garantien" target="_blank" rel="noopener noreferrer" style="display:inline-block;">
							<img src="https://cdn.shopify.com/s/files/1/0901/3388/9407/files/EU-Gewaehrleistung-Hinweis-DE.png?v=1791724559&width=640" alt="Gesetzliche Gewährleistung" style="max-width:200px;width:100%;height:auto;border:1px solid #ddd;display:block;" loading="lazy">
						</a>
						<p style="margin:.8rem 0 0;font-size:1.3rem;color:rgba(0,0,0,0.75);"><?php echo esc_html( rasa_tr( 'Mehr zu Ihren Rechten:', 'More about your rights:' ) ); ?> <a href="https://europa.eu/youreurope/garantien" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">europa.eu/youreurope/garantien</a></p>
					</div>

					<!-- Share -->
					<div class="share-button" style="margin-top:2rem;">
						<button type="button" class="share-button__button" onclick="if(navigator.share){navigator.share({title:document.title,url:location.href});}else{navigator.clipboard.writeText(location.href);}" style="background:transparent;border:0;padding:0;display:inline-flex;align-items:center;gap:.6rem;font-size:1.4rem;color:#000;cursor:pointer;font-family:inherit;">
							<?php echo rasa_icon_raw( 'share' ); ?>
							<span><?php echo esc_html( rasa_tr( 'Teilen', 'Share' ) ); ?></span>
						</button>
					</div>
				</section>
			</div>
		</div>
	</section>

	<?php
	// "Passt dazu" (conversion): cross-sells/upsells first, then products from the same category.
	$rasa_pair_ids = array_unique( array_merge( $product->get_cross_sell_ids(), $product->get_upsell_ids() ) );
	if ( count( $rasa_pair_ids ) < 4 ) {
		$rasa_cat_ids = $product->get_category_ids();
		if ( $rasa_cat_ids ) {
			$rasa_more = wc_get_products( array(
				'status'   => 'publish',
				'limit'    => 8,
				'exclude'  => array_merge( array( $product->get_id() ), $rasa_pair_ids ),
				'category' => array_filter( array_map( function ( $id ) {
					$t = get_term( $id, 'product_cat' );
					return ( $t && ! is_wp_error( $t ) ) ? $t->slug : '';
				}, $rasa_cat_ids ) ),
				'return'   => 'ids',
			) );
			$rasa_pair_ids = array_merge( $rasa_pair_ids, $rasa_more );
		}
	}
	$rasa_pair = array_filter( array_map( 'wc_get_product', array_slice( $rasa_pair_ids, 0, 4 ) ), function ( $p ) {
		return $p && 'publish' === $p->get_status() && $p->is_visible();
	} );
	if ( $rasa_pair ) :
		?>
	<section class="page-width rasa-pair" aria-labelledby="RasaPairTitle">
		<h2 id="RasaPairTitle" class="rasa-pair__title"><?php echo esc_html( rasa_tr( 'Passt dazu', 'Goes well with' ) ); ?></h2>
		<ul class="rasa-pair__grid" role="list">
			<?php foreach ( $rasa_pair as $rp ) : ?>
			<li class="rasa-pair__item">
				<a href="<?php echo esc_url( $rp->get_permalink() ); ?>" class="rasa-pair__link">
					<span class="rasa-pair__media"><?php echo wp_kses_post( $rp->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ) ); ?></span>
					<span class="rasa-pair__name"><?php echo esc_html( $rp->get_name() ); ?></span>
					<span class="rasa-pair__price"><?php echo wp_kses_post( $rp->get_price_html() ); ?></span>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php endif; ?>

	<?php if ( $is_variable && ! empty( $variations ) ) : ?>
	<script>
	(function(){
		var variations = <?php echo wp_json_encode( array_map( function( $v ) {
			return array(
				'id'         => $v['variation_id'],
				'attributes' => $v['attributes'],
				'price_html' => $v['price_html'],
			);
		}, $variations ) ); ?>;
		var form = document.getElementById('RasaPdpForm');
		var varInput = document.getElementById('RasaVariationId');
		if (!form || !varInput) return;

		function sync() {
			var selected = {};
			form.querySelectorAll('.rasa-pill-radio').forEach(function(r) {
				var pill = r.nextElementSibling;
				if (r.checked) {
					selected[r.name] = r.value;
					if (pill) { pill.style.background = '#000'; pill.style.color = '#f3f3f3'; pill.style.borderColor = '#000'; }
				} else {
					if (pill) { pill.style.background = '#f3f3f3'; pill.style.color = '#000'; pill.style.borderColor = 'rgba(0,0,0,0.55)'; }
				}
			});
			var match = variations.find(function(v) {
				return Object.keys(v.attributes).every(function(k) {
					return !v.attributes[k] || (selected[k] && selected[k].toLowerCase() === v.attributes[k].toLowerCase());
				});
			});
			if (match) {
				varInput.value = match.id;
			}
		}
		form.addEventListener('change', sync);
		sync();
	})();
	</script>
	<?php endif; ?>

	<style>
	@media screen and (max-width: 749px) {
		.section-main-product .product { flex-direction: column; }
		.section-main-product .product__media-wrapper,
		.section-main-product .product__info-wrapper { flex: 1 1 100% !important; max-width: 100% !important; padding-left: 0 !important; }
		.section-main-product .product__info-wrapper { margin-top: 2.4rem; }
	}
	</style>
<?php
endwhile;

?>
<style>
.rasa-desc{margin-top:2.4rem;font-size:1.5rem;line-height:1.6;color:rgba(0,0,0,.75)}
.rasa-desc__intro p{margin:0 0 1.6rem}
.rasa-acc{border-top:1px solid rgba(18,18,18,.12)}
.rasa-acc:last-of-type{border-bottom:1px solid rgba(18,18,18,.12)}
.rasa-acc summary{list-style:none;cursor:pointer;display:flex;justify-content:space-between;align-items:center;padding:1.4rem 0;font-size:1.5rem;font-weight:600;color:#121212}
.rasa-acc summary::-webkit-details-marker{display:none}
.rasa-acc summary::after{content:"+";font-size:2rem;font-weight:400;line-height:1}
.rasa-acc[open] summary::after{content:"\2212"}
.rasa-acc__body{padding:0 0 1.6rem}
.rasa-acc__body ul,.rasa-acc__body ol{padding-left:2rem;margin:0}
.rasa-trust{list-style:none;margin:2rem 0 0;padding:1.6rem 0 0;border-top:1px solid rgba(18,18,18,.12);display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1.2rem 1.6rem}
.rasa-trust li{display:flex;align-items:center;gap:.8rem;font-size:1.35rem;line-height:1.35;color:rgba(0,0,0,.8)}
.rasa-trust svg{flex:0 0 2.2rem;width:2.2rem;height:2.2rem;fill:currentColor}
.rasa-pair{padding-top:8px;padding-bottom:56px}
.rasa-pair__title{font-size:2.4rem;margin:0 0 2rem}
.rasa-pair__grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1.6rem}
@media (min-width:750px){.rasa-pair__grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:2.4rem}}
.rasa-pair__link{display:flex;flex-direction:column;gap:.6rem;color:inherit;text-decoration:none}
.rasa-pair__media img{display:block;width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;background:#f3f3f3}
.rasa-pair__name{font-size:1.5rem;line-height:1.4}
.rasa-pair__link:hover .rasa-pair__name{text-decoration:underline;text-underline-offset:.3rem}
.rasa-pair__price{font-size:1.4rem;color:rgba(0,0,0,.75)}
</style>
<dialog id="RasaZoom" aria-label="<?php echo esc_attr( rasa_tr( 'Bild', 'Image' ) ); ?>" style="padding:0;border:0;max-width:96vw;max-height:96vh;background:transparent;overflow:visible;">
	<img alt="" style="display:block;max-width:96vw;max-height:96vh;width:auto;height:auto;object-fit:contain;">
	<button type="button" class="rasa-zoom__btn rasa-zoom__close" aria-label="<?php echo esc_attr( rasa_tr( 'Schließen', 'Close' ) ); ?>">&times;</button>
	<button type="button" class="rasa-zoom__btn rasa-zoom__prev" aria-label="<?php echo esc_attr( rasa_tr( 'Vorheriges Bild', 'Previous image' ) ); ?>">&#8249;</button>
	<button type="button" class="rasa-zoom__btn rasa-zoom__next" aria-label="<?php echo esc_attr( rasa_tr( 'Nächstes Bild', 'Next image' ) ); ?>">&#8250;</button>
	<span class="rasa-zoom__count" aria-live="polite"></span>
</dialog>
<style>
#RasaZoom::backdrop{background:rgba(0,0,0,.85)}
.rasa-pdp-gallery[hidden]{display:none!important}
[data-rasa-group] .scroll-trigger{opacity:1!important;transform:none!important;animation:none!important}
.rasa-zoom{cursor:zoom-in}
.rasa-zoom__btn{position:absolute;width:48px;height:48px;border-radius:50%;border:0;background:#fff;color:#000;font-size:30px;line-height:46px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25)}
.rasa-zoom__close{top:8px;right:8px;font-size:26px}
.rasa-zoom__prev{left:8px;top:50%;transform:translateY(-50%)}
.rasa-zoom__next{right:8px;top:50%;transform:translateY(-50%)}
.rasa-zoom__count{position:absolute;bottom:10px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.6);color:#fff;font-size:1.3rem;padding:.3rem 1rem;border-radius:2rem}
.rasa-zoom__btn[hidden],.rasa-zoom__count:empty{display:none}
</style>
<script>
(function(){
	var d=document.getElementById('RasaZoom');if(!d||!d.showModal)return;
	var im=d.querySelector('img'),prev=d.querySelector('.rasa-zoom__prev'),next=d.querySelector('.rasa-zoom__next'),cnt=d.querySelector('.rasa-zoom__count');
	var list=[],pos=0;
	function visible(){return Array.prototype.filter.call(document.querySelectorAll('a.rasa-zoom'),function(a){return a.offsetParent!==null;});}
	function show(i){if(!list.length)return;pos=(i+list.length)%list.length;var a=list[pos],img=a.querySelector('img');im.src=a.href;im.alt=img?img.alt:'';
		var many=list.length>1;prev.hidden=!many;next.hidden=!many;cnt.textContent=many?(pos+1)+' / '+list.length:'';}
	document.addEventListener('click',function(e){var a=e.target.closest('a.rasa-zoom');if(!a)return;e.preventDefault();list=visible();show(list.indexOf(a));d.showModal();});
	prev.addEventListener('click',function(e){e.stopPropagation();show(pos-1);});
	next.addEventListener('click',function(e){e.stopPropagation();show(pos+1);});
	d.querySelector('.rasa-zoom__close').addEventListener('click',function(){d.close();});
	d.addEventListener('click',function(e){if(e.target===d)d.close();});
	d.addEventListener('keydown',function(e){if(e.key==='ArrowLeft'){show(pos-1);}else if(e.key==='ArrowRight'){show(pos+1);}});
	var x0=null;d.addEventListener('touchstart',function(e){x0=e.touches[0].clientX;},{passive:true});
	d.addEventListener('touchend',function(e){if(x0===null)return;var dx=e.changedTouches[0].clientX-x0;if(Math.abs(dx)>40){show(pos+(dx<0?1:-1));}x0=null;});
})();
(function(){
	// Pills always clickable (also before prices exist); switch the per-variant gallery.
	var form=document.getElementById('RasaPdpForm');if(!form)return;
	var varMap=<?php echo wp_json_encode( (object) $rasa_var_map ); ?>;
	function paint(){
		form.querySelectorAll('.rasa-pill-radio').forEach(function(r){var l=r.nextElementSibling;if(!l)return;
			l.style.background=r.checked?'#000':'#f3f3f3';l.style.color=r.checked?'#f3f3f3':'#000';l.style.borderColor=r.checked?'#000':'rgba(0,0,0,0.55)';});
		var c=form.querySelector('.rasa-pill-radio:checked');if(!c)return;var key=c.value.toLowerCase();
		var groups=document.querySelectorAll('[data-rasa-group]');
		if(groups.length){groups.forEach(function(g){g.hidden=(g.getAttribute('data-rasa-group')!==key);});}
		var vid=document.getElementById('RasaVariationId');if(vid&&varMap[key]&&!vid.value){vid.dataset.rasaFallback=varMap[key];}
	}
	form.addEventListener('change',function(e){if(e.target.classList.contains('rasa-pill-radio'))paint();});
	paint();
})();
</script>
<?php
get_footer();

