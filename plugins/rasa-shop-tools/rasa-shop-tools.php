<?php
/**
 * Plugin Name: Rasa Shop Tools
 * Description: Rasa Kopi shop tools: own online withdrawal function (Widerrufsbutton), supplier order mails with tracking link (Keey / Meramanis), small privacy fixes.
 * Version: 0.2.2
 * Author: Rasa Kopi
 * Requires PHP: 8.1
 * Text Domain: rasa-shop-tools
 */

defined( 'ABSPATH' ) || exit;

final class Rasa_Shop_Tools {

	const OPT        = 'rasa_shop_tools';
	const CPT        = 'rasa_withdrawal';
	const META_SUP   = '_rasa_supplier';
	const DEFAULT_LOCATIONS = "keey | Keey GmbH (Lager Langenzenn) | timur@keey.de\nmeramanis | Meramanis (Rösterei Köln) | \nrasa | Rasa Kopi (Garching, Versand selbst) | shop@rasakopi.coffee";

	/** Locations: key => [ 'name' => …, 'email' => … ] from the settings text (one per line: key | name | e-mail). */
	public static function locations() {
		$out = array();
		foreach ( preg_split( '/\r?\n/', (string) self::opt( 'locations' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			$key   = sanitize_key( $parts[0] ?? '' );
			if ( '' === $key || 'none' === $key ) {
				continue;
			}
			$out[ $key ] = array(
				'name'  => $parts[1] ?? $key,
				'email' => is_email( $parts[2] ?? '' ) ? $parts[2] : '',
				// Optional 4th field: own return address, lines separated by ";".
				'return' => implode( "\n", array_filter( array_map( 'trim', explode( ';', $parts[3] ?? '' ) ) ) ),
			);
		}
		return $out;
	}

	public static function location_name( $key ) {
		$l = self::locations();
		return $l[ $key ]['name'] ?? ( 'none' === $key ? 'Kein Versand' : $key );
	}

	private static function location_options() {
		return array_map( fn( $l ) => $l['name'], self::locations() ) + array( 'none' => 'Kein Versand (z. B. digital)' );
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_shortcode( 'rasa_widerruf', array( __CLASS__, 'shortcode_withdrawal' ) );
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_tracking_page' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 60 );
		add_action( 'admin_post_rasa_tools_save', array( __CLASS__, 'admin_save' ) );
		add_action( 'admin_post_rasa_tools_testmail', array( __CLASS__, 'admin_testmail' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'remember_mail_error' ) );

		// Supplier assignment on products.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'product_field_save' ) );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'admin_head', array( __CLASS__, 'column_css' ) );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'woocommerce_product_bulk_edit_end', array( __CLASS__, 'bulk_field' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( __CLASS__, 'bulk_save' ) );

		// Paid order -> supplier mails.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'send_supplier_mails' ), 20 );
		add_action( 'rasa_shop_tools_hourly', array( __CLASS__, 'reminders' ) );
	}

	/* ---------- settings ---------- */

	public static function opt( $key = null ) {
		$o = wp_parse_args(
			get_option( self::OPT, array() ),
			array(
				'copy_to'        => 'shop@rasakopi.coffee',
				'locations'      => self::DEFAULT_LOCATIONS,
				'default_loc'    => 'keey',
				'test_mode'      => 1,
				'return_address' => "Rasa Kopi c/o Keey GmbH\nHausener Weg 69\n90579 Langenzenn",
				'old_link'       => 'https://connect.haendlerbund.de/widerrufsformular/',
			)
		);
		return null === $key ? $o : ( $o[ $key ] ?? '' );
	}

	/* ---------- withdrawal function (§ 356a BGB) ---------- */

	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'label'           => 'Widerrufe',
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor' ),
			)
		);
	}

	public static function shortcode_withdrawal() {
		$out = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['rasa_wr'] ) ) {
			$out = self::handle_withdrawal();
			if ( str_starts_with( $out, '<div class="rasa-wr-ok' ) ) {
				return $out;
			}
		}
		$v = function ( $k ) {
			return isset( $_POST[ $k ] ) ? esc_attr( wp_unslash( $_POST[ $k ] ) ) : '';
		};
		ob_start();
		?>
		<?php echo $out; // phpcs:ignore -- already escaped. ?>
		<form class="rasa-wr" method="post" action="">
			<?php wp_nonce_field( 'rasa_wr', 'rasa_wr_nonce' ); ?>
			<input type="hidden" name="rasa_wr" value="1">
			<p><label>Name *<br><input type="text" name="wr_name" required maxlength="120" value="<?php echo $v( 'wr_name' ); ?>" autocomplete="name"></label></p>
			<p><label>E-Mail-Adresse (für die Eingangsbestätigung) *<br><input type="email" name="wr_email" required maxlength="190" value="<?php echo $v( 'wr_email' ); ?>" autocomplete="email"></label></p>
			<p><label>Bestellnummer *<br><input type="text" name="wr_order" required maxlength="40" value="<?php echo $v( 'wr_order' ); ?>"></label></p>
			<p><label>Welche Artikel möchten Sie widerrufen? (leer lassen = ganze Bestellung)<br><textarea name="wr_items" rows="3" maxlength="1000"><?php echo esc_textarea( wp_unslash( $_POST['wr_items'] ?? '' ) ); ?></textarea></label></p>
			<p style="position:absolute;left:-9999px" aria-hidden="true"><label>Website<input type="text" name="wr_website" tabindex="-1" autocomplete="off"></label></p>
			<p><button type="submit" class="button">Widerruf bestätigen</button></p>
			<p><small>Nach dem Absenden erhalten Sie sofort eine Eingangsbestätigung per E-Mail. Ihre Angaben verwenden wir nur zur Bearbeitung des Widerrufs (siehe <a href="<?php echo esc_url( home_url( '/datenschutz/' ) ); ?>">Datenschutzerklärung</a>).</small></p>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function err( $msg ) {
		return '<div class="rasa-wr-error" role="alert" style="border:1px solid #b3261e;padding:12px;margin:0 0 16px">' . esc_html( $msg ) . '</div>';
	}

	/**
	 * Return addresses for an order: one block per storage location that ships items of this order.
	 * Location without own return address -> default return address. No order -> default only.
	 */
	public static function return_blocks( $wc_order ) {
		$default = (string) self::opt( 'return_address' );
		$locs    = self::locations();
		$blocks  = array();
		if ( $wc_order ) {
			foreach ( $wc_order->get_items() as $item ) {
				$sup = self::supplier_for( $item->get_product() );
				if ( 'none' === $sup ) {
					continue;
				}
				$addr = '' !== ( $locs[ $sup ]['return'] ?? '' ) ? $locs[ $sup ]['return'] : $default;
				$blocks[ $addr ][] = $item->get_name();
			}
		}
		if ( ! $blocks ) {
			$blocks[ $default ] = array();
		}
		return $blocks;
	}

	private static function return_text( $blocks ) {
		if ( 1 === count( $blocks ) ) {
			return 'Bitte senden Sie die Ware innerhalb von 14 Tagen an:' . "\n" . array_key_first( $blocks );
		}
		$t = 'Ihre Bestellung wurde aus verschiedenen Lagern verschickt. Bitte senden Sie die Ware innerhalb von 14 Tagen an die jeweilige Adresse:';
		foreach ( $blocks as $addr => $items ) {
			$t .= "\n\n" . implode( ', ', array_unique( $items ) ) . ":\n" . $addr;
		}
		return $t;
	}

	private static function handle_withdrawal() {
		if ( ! isset( $_POST['rasa_wr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rasa_wr_nonce'] ) ), 'rasa_wr' ) ) {
			return self::err( 'Die Seite war zu lange geöffnet. Bitte laden Sie sie neu und senden Sie das Formular erneut.' );
		}
		if ( ! empty( $_POST['wr_website'] ) ) {
			return self::err( 'Das Formular konnte nicht gesendet werden.' );
		}
		$ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key = 'rasa_wr_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 5 ) {
			return self::err( 'Zu viele Versuche. Bitte versuchen Sie es in einer Stunde erneut oder schreiben Sie an ' . self::opt( 'copy_to' ) . '.' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$name  = sanitize_text_field( wp_unslash( $_POST['wr_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['wr_email'] ?? '' ) );
		$order = sanitize_text_field( wp_unslash( $_POST['wr_order'] ?? '' ) );
		$items = sanitize_textarea_field( wp_unslash( $_POST['wr_items'] ?? '' ) );
		if ( '' === $name || ! is_email( $email ) || '' === $order ) {
			return self::err( 'Bitte füllen Sie Name, eine gültige E-Mail-Adresse und die Bestellnummer aus.' );
		}

		$when  = wp_date( 'd.m.Y, H:i:s' ) . ' Uhr';
		$items = '' === $items ? 'gesamte Bestellung' : $items;
		$lines = array(
			'Name: ' . $name,
			'E-Mail: ' . $email,
			'Bestellnummer: ' . $order,
			'Widerrufene Artikel: ' . $items,
			'Eingegangen am: ' . $when,
		);

		// Link to the WooCommerce order (only if e-mail matches).
		$order_id = absint( preg_replace( '/\D/', '', $order ) );
		$wc_order = ( $order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : null;
		$matched  = $wc_order && 0 === strcasecmp( (string) $wc_order->get_billing_email(), $email );
		if ( $matched ) {
			$wc_order->add_order_note( "Widerruf über die Online-Widerrufsfunktion erklärt.\n" . implode( "\n", $lines ) );
		}

		$blocks  = self::return_blocks( $matched ? $wc_order : null );
		$post_id = wp_insert_post(
			array(
				'post_type'    => self::CPT,
				'post_status'  => 'private',
				'post_title'   => sprintf( 'Widerruf %s – %s', $order, $name ),
				'post_content' => implode( "\n", $lines ) . "\nBestellung zugeordnet: " . ( $matched ? 'ja' : 'nein' ),
			)
		);

		$subject = sprintf( 'Eingangsbestätigung Ihres Widerrufs – Bestellung %s – Rasa Kopi', $order );
		$body    = "Hallo {$name},\n\nwir bestätigen den Eingang Ihres Widerrufs:\n\n" . implode( "\n", $lines )
			. "\n\n" . self::return_text( $blocks )
			. "\n\nDie Kosten der Rücksendung tragen Sie. Wir erstatten den Kaufpreis wie in unserer Widerrufsbelehrung beschrieben: " . home_url( '/widerrufsbelehrung/' )
			. "\n\nViele Grüße\nIhr Rasa Kopi Team\n\nRasa Kopi – Inh. Osman Gedik · Jochbergweg 10 · 85748 Garching · " . self::opt( 'copy_to' );
		$sent_customer = wp_mail( $email, $subject, $body );
		wp_mail( self::opt( 'copy_to' ), '[Widerruf] ' . $order . ' – ' . $name, implode( "\n", $lines ) . "\nBestellung zugeordnet: " . ( $matched ? 'ja' : 'nein' ) . "\nBestätigung an Kunden gesendet: " . ( $sent_customer ? 'ja' : 'NEIN – bitte manuell bestätigen' ) . ( $matched ? '' : "\nACHTUNG: Bestellung nicht zugeordnet – Kunde hat die Standard-Rücksendeadresse erhalten. Bei Partnerware die richtige Adresse nachsenden." ) . "\nEintrag: " . admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ) );

		$html  = '<div class="rasa-wr-ok" role="status" style="border:1px solid #2e7d32;padding:16px;margin:0 0 16px">';
		$html .= '<p><strong>Vielen Dank. Ihr Widerruf ist bei uns eingegangen.</strong></p><p>';
		foreach ( $lines as $l ) {
			$html .= esc_html( $l ) . '<br>';
		}
		$html .= '</p><p>' . ( $sent_customer ? 'Eine Eingangsbestätigung haben wir an ' . esc_html( $email ) . ' gesendet.' : 'Bitte speichern oder drucken Sie diese Seite als Bestätigung.' ) . '</p>';
		$html .= '<p>' . nl2br( esc_html( self::return_text( $blocks ) ) ) . '</p></div>';
		return $html;
	}

	/* ---------- output fixes ---------- */

	public static function start_buffer() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'filter_html' ) );
	}

	public static function filter_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		// Withdrawal button: own function instead of the Händlerbund form.
		$old = preg_quote( self::opt( 'old_link' ), '#' );
		if ( $old ) {
			$html = preg_replace( '#' . $old . '[^"\'\s<]*#', esc_url( home_url( '/vertrag-widerrufen/' ) ), $html );
		}
		// No connection to Google: fonts are served locally.
		$html = preg_replace( '#<link[^>]+fonts\.(gstatic|googleapis)\.com[^>]*>\s*#i', '', $html );
		return $html;
	}

	/* ---------- supplier mails ---------- */

	public static function product_field() {
		woocommerce_wp_select(
			array(
				'id'          => self::META_SUP,
				'label'       => 'Lagerort / Versand durch',
				'options'     => array( '' => 'Standard (' . self::location_name( self::opt( 'default_loc' ) ) . ')' ) + self::location_options(),
				'desc_tip'    => true,
				'description' => 'Wer diesen Artikel verschickt. Der Lieferant bekommt bei bezahlten Bestellungen automatisch eine E-Mail.',
			)
		);
	}

	public static function product_field_save( $post_id ) {
		if ( isset( $_POST[ self::META_SUP ] ) ) {
			$val = sanitize_key( wp_unslash( $_POST[ self::META_SUP ] ) );
			if ( '' === $val ) {
				delete_post_meta( $post_id, self::META_SUP );
			} elseif ( isset( self::location_options()[ $val ] ) ) {
				update_post_meta( $post_id, self::META_SUP, $val );
			}
		}
	}

	public static function add_column( $cols ) {
		$cols['rasa_loc'] = 'Lagerort';
		return $cols;
	}

	public static function column_css() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'edit-product' === $screen->id ) {
			echo '<style>.wp-list-table .column-rasa_loc{width:9em;white-space:normal;word-break:normal}</style>';
		}
	}

	public static function short_name( $key ) {
		$name = self::location_name( $key );
		$pos  = strpos( $name, ' (' );
		return false === $pos ? $name : substr( $name, 0, $pos );
	}

	public static function render_column( $col, $post_id ) {
		if ( 'rasa_loc' !== $col ) {
			return;
		}
		$val = (string) get_post_meta( $post_id, self::META_SUP, true );
		echo '' === $val ? '<span style="color:#777">' . esc_html( self::short_name( self::opt( 'default_loc' ) ) ) . ' (Std.)</span>' : esc_html( self::short_name( $val ) );
	}

	public static function bulk_field() {
		echo '<div class="inline-edit-group"><label class="alignleft"><span class="title">Lagerort</span><span class="input-text-wrap"><select name="rasa_bulk_loc"><option value="">— Keine Änderung —</option><option value="__default">Standard</option>';
		foreach ( self::location_options() as $k => $n ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $n ) . '</option>';
		}
		echo '</select></span></label></div>';
	}

	public static function bulk_save( $product ) {
		$val = isset( $_REQUEST['rasa_bulk_loc'] ) ? sanitize_key( wp_unslash( $_REQUEST['rasa_bulk_loc'] ) ) : '';
		if ( '' === $val ) {
			return;
		}
		if ( '__default' === $val ) {
			delete_post_meta( $product->get_id(), self::META_SUP );
		} elseif ( isset( self::location_options()[ $val ] ) ) {
			update_post_meta( $product->get_id(), self::META_SUP, $val );
		}
	}

	public static function supplier_for( $product ) {
		$def = (string) self::opt( 'default_loc' );
		if ( ! $product ) {
			return $def;
		}
		$id  = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$val = (string) get_post_meta( $id, self::META_SUP, true );
		return '' !== $val ? $val : $def;
	}

	private static function recipient( $sup ) {
		$mail = self::locations()[ $sup ]['email'] ?? '';
		if ( self::opt( 'test_mode' ) || ! is_email( $mail ) ) {
			return self::opt( 'copy_to' );
		}
		return $mail;
	}

	public static function send_supplier_mails( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_rasa_sup_sent' ) ) {
			return;
		}
		$groups = array();
		foreach ( $order->get_items() as $item ) {
			$p   = $item->get_product();
			$sup = self::supplier_for( $p );
			if ( 'none' === $sup ) {
				continue;
			}
			$groups[ $sup ][] = sprintf( '%d × %s%s', $item->get_quantity(), $item->get_name(), ( $p && $p->get_sku() ) ? ' (SKU ' . $p->get_sku() . ')' : '' );
		}
		if ( ! $groups ) {
			return;
		}
		$state = array();
		foreach ( $groups as $sup => $lines ) {
			$token           = wp_generate_password( 32, false );
			$state[ $sup ]   = array( 'token' => $token, 'sent' => time(), 'shipped' => 0, 'reminded' => 0 );
			self::mail_supplier( $order, $sup, $lines, $token, false );
		}
		$order->update_meta_data( '_rasa_sup_sent', 1 );
		$order->update_meta_data( '_rasa_sup_state', $state );
		$order->add_order_note( 'Versandauftrag gesendet an: ' . implode( ', ', array_map( fn( $s ) => self::location_name( $s ), array_keys( $groups ) ) ) . ( self::opt( 'test_mode' ) ? ' (Testmodus – nur an ' . self::opt( 'copy_to' ) . ')' : '' ) );
		$order->save();
		if ( ! wp_next_scheduled( 'rasa_shop_tools_hourly' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'rasa_shop_tools_hourly' );
		}
	}

	private static function mail_supplier( $order, $sup, $lines, $token, $reminder ) {
		$addr = array_filter(
			array(
				trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				$order->get_shipping_company(),
				$order->get_shipping_address_1() ?: $order->get_billing_address_1(),
				$order->get_shipping_address_2() ?: $order->get_billing_address_2(),
				trim( ( $order->get_shipping_postcode() ?: $order->get_billing_postcode() ) . ' ' . ( $order->get_shipping_city() ?: $order->get_billing_city() ) ),
				'Deutschland',
			)
		);
		$link    = add_query_arg( array( 'rasa_ship' => $order->get_id(), 't' => $token ), home_url( '/' ) );
		$subject = ( self::opt( 'test_mode' ) ? '[TEST] ' : '' ) . ( $reminder ? 'Erinnerung: ' : '' ) . sprintf( 'Versandauftrag Rasa Kopi – Bestellung #%s', $order->get_order_number() );
		$body    = 'Hallo ' . self::location_name( $sup ) . ",\n\n"
			. ( $reminder ? "diese Bestellung ist noch nicht als versendet gemeldet. Bitte heute versenden oder kurz Bescheid geben.\n\n" : "bitte versendet diese Bestellung (bezahlt):\n\n" )
			. 'Bestellung: #' . $order->get_order_number() . ' vom ' . wc_format_datetime( $order->get_date_created(), 'd.m.Y H:i' ) . "\n\n"
			. "Artikel:\n- " . implode( "\n- ", $lines ) . "\n\n"
			. "Lieferadresse:\n" . implode( "\n", $addr ) . "\n"
			. 'E-Mail für die Versandbenachrichtigung: ' . $order->get_billing_email() . "\n\n"
			. "Nach dem Versand bitte die Sendungsnummer hier eintragen (kein Login nötig):\n" . $link . "\n\n"
			. "Bitte keinen Lieferschein mit Preisen beilegen.\n\nDanke!\nRasa Kopi · " . self::opt( 'copy_to' );
		$headers = array( 'Reply-To: Rasa Kopi <' . self::opt( 'copy_to' ) . '>' );
		$to      = self::recipient( $sup );
		wp_mail( $to, $subject, $body, $headers );
		if ( $to !== self::opt( 'copy_to' ) ) {
			wp_mail( self::opt( 'copy_to' ), '[Kopie] ' . $subject, $body, $headers );
		}
	}

	public static function reminders() {
		$orders = wc_get_orders( array( 'status' => 'processing', 'limit' => 50, 'meta_key' => '_rasa_sup_sent', 'meta_value' => 1 ) );
		foreach ( $orders as $order ) {
			$state = (array) $order->get_meta( '_rasa_sup_state' );
			$dirty = false;
			foreach ( $state as $sup => $s ) {
				if ( empty( $s['shipped'] ) && empty( $s['reminded'] ) && ( time() - (int) $s['sent'] ) > DAY_IN_SECONDS ) {
					$lines = array();
					foreach ( $order->get_items() as $item ) {
						if ( self::supplier_for( $item->get_product() ) === $sup ) {
							$lines[] = sprintf( '%d × %s', $item->get_quantity(), $item->get_name() );
						}
					}
					self::mail_supplier( $order, $sup, $lines, $s['token'], true );
					$state[ $sup ]['reminded'] = time();
					$dirty                     = true;
				}
			}
			if ( $dirty ) {
				$order->update_meta_data( '_rasa_sup_state', $state );
				$order->add_order_note( 'Versand-Erinnerung an Lieferanten gesendet.' );
				$order->save();
			}
		}
	}

	public static function maybe_tracking_page() {
		if ( ! isset( $_GET['rasa_ship'], $_GET['t'] ) ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		$order = wc_get_order( absint( $_GET['rasa_ship'] ) );
		$token = sanitize_text_field( wp_unslash( $_GET['t'] ) );
		$state = $order ? (array) $order->get_meta( '_rasa_sup_state' ) : array();
		$sup   = null;
		foreach ( $state as $k => $s ) {
			if ( ! empty( $s['token'] ) && hash_equals( $s['token'], $token ) ) {
				$sup = $k;
			}
		}
		$page = function ( $inner ) {
			wp_die( $inner, 'Rasa Kopi – Versand melden', array( 'response' => 200 ) ); // phpcs:ignore
		};
		if ( ! $sup ) {
			$page( '<p>Dieser Link ist ungültig.</p>' );
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['tracking'] ) ) {
			$carrier  = sanitize_text_field( wp_unslash( $_POST['carrier'] ?? 'DHL' ) );
			$tracking = preg_replace( '/[^A-Za-z0-9\-]/', '', wp_unslash( $_POST['tracking'] ) );
			if ( '' === $tracking ) {
				$page( '<p>Bitte eine Sendungsnummer eingeben. <a href="javascript:history.back()">Zurück</a></p>' );
			}
			$urls = array(
				'DHL'    => 'https://www.dhl.de/de/privatkunden/pakete-empfangen/verfolgen.html?piececode=',
				'Hermes' => 'https://www.myhermes.de/empfangen/sendungsverfolgung/sendungsinformation#',
				'DPD'    => 'https://tracking.dpd.de/status/de_DE/parcel/',
				'GLS'    => 'https://gls-group.com/DE/de/paketverfolgung?match=',
			);
			$url = isset( $urls[ $carrier ] ) ? $urls[ $carrier ] . rawurlencode( $tracking ) : '';
			$state[ $sup ]['shipped']  = time();
			$state[ $sup ]['tracking'] = $carrier . ' ' . $tracking;
			$order->update_meta_data( '_rasa_sup_state', $state );
			$order->add_order_note( sprintf( "Ihre Bestellung ist unterwegs (%s).\nSendungsnummer: %s%s", $carrier, $tracking, $url ? "\nSendungsverfolgung: " . $url : '' ), 1 );
			$all = ! array_filter( $state, fn( $s ) => empty( $s['shipped'] ) );
			$order->save();
			if ( $all ) {
				$order->update_status( 'completed', 'Alle Teile versendet.' );
			}
			$page( '<p><strong>Danke!</strong> Versand für Bestellung #' . esc_html( $order->get_order_number() ) . ' gespeichert. Der Kunde wurde benachrichtigt.</p>' );
		}
		$done = ! empty( $state[ $sup ]['shipped'] ) ? '<p>Bereits gemeldet: ' . esc_html( $state[ $sup ]['tracking'] ?? '' ) . '. Eine neue Eingabe ergänzt die Sendung.</p>' : '';
		$page(
			'<h1>Versand melden – Bestellung #' . esc_html( $order->get_order_number() ) . '</h1>' . $done
			. '<form method="post"><p><label>Versanddienst<br><select name="carrier"><option>DHL</option><option>Hermes</option><option>DPD</option><option>GLS</option><option>Andere</option></select></label></p>'
			. '<p><label>Sendungsnummer<br><input name="tracking" required style="width:100%;max-width:360px"></label></p>'
			. '<p><button type="submit">Versand melden</button></p></form>'
		);
	}

	/* ---------- admin ---------- */

	public static function admin_menu() {
		add_submenu_page( 'woocommerce', 'Rasa Tools', 'Rasa Tools', 'manage_woocommerce', 'rasa-tools', array( __CLASS__, 'admin_page' ) );
	}

	public static function remember_mail_error( $err ) {
		set_transient( 'rasa_tools_mail_error', $err->get_error_message(), HOUR_IN_SECONDS );
	}

	public static function admin_page() {
		$o = self::opt();
		$m = get_transient( 'rasa_tools_msg' );
		delete_transient( 'rasa_tools_msg' );
		echo '<div class="wrap"><h1>Rasa Tools</h1>';
		if ( $m ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $m ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'rasa_tools_save' );
		echo '<input type="hidden" name="action" value="rasa_tools_save"><table class="form-table">';
		$row = function ( $label, $html ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore
		};
		$row( 'Testmodus', '<label><input type="checkbox" name="test_mode" value="1" ' . checked( $o['test_mode'], 1, false ) . '> Versandaufträge nur an die Kopie-Adresse senden (nicht an Lieferanten)</label>' );
		$row( 'Kopie an', '<input type="email" class="regular-text" name="copy_to" value="' . esc_attr( $o['copy_to'] ) . '">' );
		$t  = '<table class="widefat striped rasa-locs" style="max-width:1100px"><thead><tr><th style="width:120px">Kürzel</th><th>Name</th><th>E-Mail für Versandaufträge</th><th>Eigene Rücksendeadresse (optional)</th></tr></thead><tbody>';
		$i  = 0;
		foreach ( self::locations() + array( '' => array( 'name' => '', 'email' => '', 'return' => '' ) ) as $k => $l ) {
			$n  = 'loc[' . $i++ . ']';
			$t .= '<tr><td>' . ( '' === $k
					? '<input type="text" name="' . $n . '[key]" placeholder="neu, z. B. roesttrommel" style="width:100%">'
					: '<code>' . esc_html( $k ) . '</code><input type="hidden" name="' . $n . '[key]" value="' . esc_attr( $k ) . '">' )
				. '</td><td><input type="text" name="' . $n . '[name]" value="' . esc_attr( $l['name'] ) . '" style="width:100%"></td>'
				. '<td><input type="email" name="' . $n . '[email]" value="' . esc_attr( $l['email'] ) . '" style="width:100%"></td>'
				. '<td><textarea name="' . $n . '[return]" rows="3" style="width:100%" placeholder="leer = Standard-Rücksendeadresse">' . esc_textarea( $l['return'] ?? '' ) . '</textarea></td></tr>';
		}
		$t .= '</tbody></table><p class="description">Letzte Zeile = neuer Lagerort. Kürzel kann man später nicht ändern (Produkte hängen daran). Name leeren = Lagerort löschen.</p>';
		$row( 'Lagerorte', $t );
		$sel = '';
		foreach ( self::locations() as $k => $l ) {
			$sel .= '<option value="' . esc_attr( $k ) . '" ' . selected( $o['default_loc'], $k, false ) . '>' . esc_html( $l['name'] ) . '</option>';
		}
		$row( 'Standard-Lagerort', '<select name="default_loc">' . $sel . '</select><p class="description">Gilt für alle Produkte ohne eigene Zuordnung.</p>' );
		$row( 'Standard-Rücksendeadresse', '<textarea name="return_address" rows="3" class="regular-text">' . esc_textarea( $o['return_address'] ) . '</textarea><p class="description">Für alle Lagerorte ohne eigene Rücksendeadresse. Der Kunde bekommt nach dem Widerruf pro Lager die passende Adresse.</p>' );
		echo '</table>';
		submit_button( 'Speichern' );
		echo '</form><hr><h2>E-Mail-Versand testen</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'rasa_tools_testmail' );
		echo '<input type="hidden" name="action" value="rasa_tools_testmail">';
		submit_button( 'Testmail an ' . $o['copy_to'] . ' senden', 'secondary' );
		echo '</form><p>Widerrufsformular: Shortcode <code>[rasa_widerruf]</code> auf der Seite /vertrag-widerrufen/. Eingänge: WooCommerce ▸ Widerrufe.</p></div>';
	}

	/** Table rows -> stored text (key | name | e-mail | return lines split by ;). Old textarea still accepted. */
	private static function locations_from_post() {
		if ( ! isset( $_POST['loc'] ) || ! is_array( $_POST['loc'] ) ) {
			return sanitize_textarea_field( wp_unslash( $_POST['locations'] ?? self::opt( 'locations' ) ) );
		}
		$clean = fn( $v ) => trim( str_replace( array( '|', ';' ), array( '/', ',' ), sanitize_text_field( $v ) ) );
		$lines = array();
		foreach ( wp_unslash( $_POST['loc'] ) as $r ) {
			$key  = sanitize_key( $r['key'] ?? '' );
			$name = $clean( $r['name'] ?? '' );
			if ( '' === $key || 'none' === $key || '' === $name ) {
				continue;
			}
			$email  = sanitize_email( $r['email'] ?? '' );
			$return = implode( '; ', array_filter( array_map( $clean, preg_split( '/\r?\n/', (string) ( $r['return'] ?? '' ) ) ) ) );
			$lines[ $key ] = rtrim( "$key | $name | $email | $return", ' |' );
		}
		return implode( "\n", $lines );
	}

	public static function admin_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'no' );
		}
		check_admin_referer( 'rasa_tools_save' );
		update_option(
			self::OPT,
			array(
				'test_mode'      => empty( $_POST['test_mode'] ) ? 0 : 1,
				'copy_to'        => sanitize_email( wp_unslash( $_POST['copy_to'] ?? '' ) ),
				'locations'      => self::locations_from_post(),
				'default_loc'    => sanitize_key( wp_unslash( $_POST['default_loc'] ?? 'keey' ) ),
				'return_address' => sanitize_textarea_field( wp_unslash( $_POST['return_address'] ?? '' ) ),
				'old_link'       => self::opt( 'old_link' ),
			)
		);
		set_transient( 'rasa_tools_msg', 'Gespeichert.', 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=rasa-tools' ) );
		exit;
	}

	public static function admin_testmail() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'no' );
		}
		check_admin_referer( 'rasa_tools_testmail' );
		delete_transient( 'rasa_tools_mail_error' );
		$ok  = wp_mail( self::opt( 'copy_to' ), 'Rasa Tools – Testmail', 'Wenn diese Mail ankommt, funktioniert der Mailversand des Shops. ' . wp_date( 'd.m.Y H:i:s' ) );
		$err = get_transient( 'rasa_tools_mail_error' );
		set_transient( 'rasa_tools_msg', $ok ? 'Testmail wurde vom Server angenommen. Bitte im Postfach (und Spam) prüfen.' : 'Testmail FEHLGESCHLAGEN: ' . $err, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=rasa-tools' ) );
		exit;
	}
}

Rasa_Shop_Tools::init();

register_activation_hook(
	__FILE__,
	function () {
		if ( ! wp_next_scheduled( 'rasa_shop_tools_hourly' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'rasa_shop_tools_hourly' );
		}
	}
);
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'rasa_shop_tools_hourly' );
	}
);
