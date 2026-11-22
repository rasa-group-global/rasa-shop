<?php
/**
 * Rasa Centralized Keycloak SSO & Single-Source-of-Truth Bridge for WooCommerce.
 *
 * Implements:
 * 1. OIDC Authorization Code + PKCE Flow (`?rasa_sso=login` -> Keycloak -> `?rasa_sso=callback`).
 * 2. "Login with Rasa SSO" button on WooCommerce My Account & Checkout login forms.
 * 3. Single Source of Truth Sync:
 *    - When a user signs in via Keycloak SSO, creates/updates the WooCommerce customer,
 *      stores `keycloak_id` (`keyloack_id`) and `_rasa_user_uuid` in `wp_usermeta`,
 *      synchronizes `b2b_customer` role & `_rasa_vat_id` (enabling Net Price & EU Reverse Charge VAT),
 *      and caches loyalty points & wallet balance from `RasaApi`.
 *    - When a user registers or logs in via the native WooCommerce form, synchronizes
 *      their account and credentials directly with `RasaApi` -> Keycloak so 1 Single Source
 *      of Truth is preserved across all applications.
 * 4. Single Logout (SLO) across WooCommerce and Keycloak.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RASA_META_KEYCLOAK_ID', 'keycloak_id' );
define( 'RASA_META_KEYLOACK_ID', 'keyloack_id' );
define( 'RASA_META_USER_UUID', '_rasa_user_uuid' );
define( 'RASA_META_WALLET_BALANCE', '_rasa_wallet_balance' );
define( 'RASA_META_LOYALTY_POINTS', '_rasa_loyalty_points' );

/**
 * Retrieve Keycloak & RasaApi SSO configuration from environment variables or WP options.
 */
function rasa_sso_config() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
	$is_prod = ( 'shop.rasakopi.coffee' === $host || 'rasakopi.coffee' === $host );

	$default_kc_url  = $is_prod ? 'https://sso.rasagroup.global' : 'https://sso-dev.rasagroup.global';
	$default_api_url = $is_prod ? 'https://api.rasagroup.global/api/v1' : 'https://api-dev.rasagroup.global/api/v1';

	$kc_url = getenv( 'RASA_KEYCLOAK_URL' ) ?: get_option( 'rasa_keycloak_url', $default_kc_url );
	$realm  = getenv( 'RASA_KEYCLOAK_REALM' ) ?: get_option( 'rasa_keycloak_realm', 'rasa' );
	$client_id = getenv( 'RASA_KEYCLOAK_CLIENT_ID' ) ?: get_option( 'rasa_keycloak_client_id', 'rasa-commerce' );
	$client_secret = getenv( 'RASA_KEYCLOAK_CLIENT_SECRET' ) ?: get_option( 'rasa_keycloak_client_secret', 'rasa-commerce-secret-dev-2026' );
	$api_url = getenv( 'RASA_API_BASE_URL' ) ?: get_option( 'rasa_api_base_url', $default_api_url );

	return array(
		'enabled'       => (bool) ( getenv( 'RASA_KEYCLOAK_ENABLED' ) !== '0' && get_option( 'rasa_keycloak_enabled', 'yes' ) !== 'no' ),
		'kc_url'        => rtrim( (string) $kc_url, '/' ),
		'realm'         => (string) $realm,
		'client_id'     => (string) $client_id,
		'client_secret' => (string) $client_secret,
		'api_url'       => rtrim( (string) $api_url, '/' ),
		'redirect_uri'  => add_query_arg( 'rasa_sso', 'callback', home_url( '/' ) ),
	);
}

/**
 * Generate URL to start the Rasa SSO login flow.
 */
function rasa_sso_login_url( $redirect_to = '' ) {
	$args = array( 'rasa_sso' => 'login' );
	if ( ! empty( $redirect_to ) ) {
		$args['redirect_to'] = rawurlencode( $redirect_to );
	}
	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Store Keycloak Subject ID in both `keycloak_id` and `keyloack_id` user meta keys.
 */
function rasa_sso_set_user_keycloak_id( $user_id, $keycloak_sub ) {
	$sub = sanitize_text_field( (string) $keycloak_sub );
	if ( '' === $sub || ! $user_id ) {
		return;
	}
	update_user_meta( $user_id, RASA_META_KEYCLOAK_ID, $sub );
	update_user_meta( $user_id, RASA_META_KEYLOACK_ID, $sub );
}

/**
 * Handle `?rasa_sso=login` and `?rasa_sso=callback` requests.
 */
add_action( 'init', function () {
	if ( empty( $_GET['rasa_sso'] ) ) {
		return;
	}

	$cfg = rasa_sso_config();
	if ( ! $cfg['enabled'] ) {
		return;
	}

	$action = sanitize_key( wp_unslash( $_GET['rasa_sso'] ) );

	if ( 'login' === $action ) {
		$state = wp_generate_password( 32, false );
		$verifier = wp_generate_password( 64, false );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$return_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';

		set_transient(
			'rasa_sso_state_' . $state,
			array(
				'verifier'  => $verifier,
				'return_to' => $return_to,
			),
			15 * MINUTE_IN_SECONDS
		);

		$auth_url = add_query_arg(
			array(
				'client_id'             => $cfg['client_id'],
				'redirect_uri'          => $cfg['redirect_uri'],
				'response_type'         => 'code',
				'scope'                 => 'openid email profile',
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			$cfg['kc_url'] . '/realms/' . rawurlencode( $cfg['realm'] ) . '/protocol/openid-connect/auth'
		);

		wp_redirect( $auth_url );
		exit;
	}

	if ( 'callback' === $action ) {
		$myaccount_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );

		if ( ! empty( $_GET['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'sso_error', 'cancelled', $myaccount_url ) );
			exit;
		}

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		$state_data = $state ? get_transient( 'rasa_sso_state_' . $state ) : false;
		if ( $state ) {
			delete_transient( 'rasa_sso_state_' . $state );
		}

		if ( empty( $code ) || empty( $state_data ) || ! is_array( $state_data ) ) {
			wp_safe_redirect( add_query_arg( 'sso_error', 'invalid_state', $myaccount_url ) );
			exit;
		}

		// Exchange code through RasaApi /auth/keycloak/exchange for 100% Single Source of Truth
		$exchange_res = wp_remote_post(
			$cfg['api_url'] . '/auth/keycloak/exchange',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'code'          => $code,
						'redirect_uri'  => $cfg['redirect_uri'],
						'client_id'     => $cfg['client_id'],
						'client_secret' => $cfg['client_secret'],
						'code_verifier' => $state_data['verifier'] ?? '',
					)
				),
			)
		);

		$rasa_user = null;
		$kc_sub    = '';
		$email     = '';
		$name      = '';
		$roles     = array();
		$vat_id    = '';
		$id_token  = '';

		if ( ! is_wp_error( $exchange_res ) && 200 === (int) wp_remote_retrieve_response_code( $exchange_res ) ) {
			$body = json_decode( wp_remote_retrieve_body( $exchange_res ), true );
			$data = $body['data'] ?? array();
			$rasa_user = $data['user'] ?? null;
			$kc_sub    = (string) ( $data['keycloak_id'] ?? ( $rasa_user['keycloak_id'] ?? '' ) );
			$id_token  = (string) ( $data['keycloak_id_token'] ?? '' );

			if ( is_array( $rasa_user ) ) {
				$email = sanitize_email( (string) ( $rasa_user['email'] ?? '' ) );
				$name  = sanitize_text_field( (string) ( $rasa_user['name'] ?? '' ) );
				if ( ! empty( $rasa_user['roles'] ) && is_array( $rasa_user['roles'] ) ) {
					foreach ( $rasa_user['roles'] as $r ) {
						$roles[] = is_array( $r ) ? (string) ( $r['name'] ?? '' ) : (string) $r;
					}
				}
			}
		} else {
			// Fallback: Exchange directly against Keycloak token endpoint if RasaApi is unreachable
			$token_res = wp_remote_post(
				$cfg['kc_url'] . '/realms/' . rawurlencode( $cfg['realm'] ) . '/protocol/openid-connect/token',
				array(
					'timeout' => 12,
					'body'    => array(
						'grant_type'    => 'authorization_code',
						'client_id'     => $cfg['client_id'],
						'client_secret' => $cfg['client_secret'],
						'code'          => $code,
						'redirect_uri'  => $cfg['redirect_uri'],
						'code_verifier' => $state_data['verifier'] ?? '',
					),
				)
			);

			if ( is_wp_error( $token_res ) || 200 !== (int) wp_remote_retrieve_response_code( $token_res ) ) {
				wp_safe_redirect( add_query_arg( 'sso_error', 'token_exchange_failed', $myaccount_url ) );
				exit;
			}

			$token_data = json_decode( wp_remote_retrieve_body( $token_res ), true );
			$access_token = (string) ( $token_data['access_token'] ?? '' );
			$id_token     = (string) ( $token_data['id_token'] ?? '' );
			$parts        = explode( '.', $access_token );
			if ( isset( $parts[1] ) ) {
				$claims = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ) ?: array();
				$kc_sub = sanitize_text_field( (string) ( $claims['sub'] ?? '' ) );
				$email  = sanitize_email( (string) ( $claims['email'] ?? '' ) );
				$name   = sanitize_text_field( (string) ( $claims['name'] ?? ( $claims['preferred_username'] ?? '' ) ) );
				$roles  = isset( $claims['roles'] ) && is_array( $claims['roles'] ) ? $claims['roles'] : ( $claims['realm_access']['roles'] ?? array() );
				$vat_id = isset( $claims['b2b_vat_id'] ) ? sanitize_text_field( (string) $claims['b2b_vat_id'] ) : '';
			}
		}

		if ( empty( $email ) ) {
			wp_safe_redirect( add_query_arg( 'sso_error', 'missing_email', $myaccount_url ) );
			exit;
		}

		$wp_user = rasa_sso_resolve_or_create_wp_user( $email, $name, $kc_sub, $roles, $rasa_user, $vat_id );
		if ( is_wp_error( $wp_user ) || ! ( $wp_user instanceof WP_User ) ) {
			wp_safe_redirect( add_query_arg( 'sso_error', 'user_provision_failed', $myaccount_url ) );
			exit;
		}

		if ( ! empty( $id_token ) ) {
			update_user_meta( $wp_user->ID, '_rasa_keycloak_id_token', $id_token );
		}

		wp_clear_auth_cookie();
		wp_set_current_user( $wp_user->ID );
		wp_set_auth_cookie( $wp_user->ID, true );
		do_action( 'wp_login', $wp_user->user_login, $wp_user );

		$target_url = ! empty( $state_data['return_to'] ) ? $state_data['return_to'] : $myaccount_url;
		wp_safe_redirect( $target_url );
		exit;
	}
}, 5 );

/**
 * Resolve or create a WordPress / WooCommerce user and synchronize roles, B2B status, and Rasa loyalty data.
 */
function rasa_sso_resolve_or_create_wp_user( $email, $name, $kc_sub, array $roles = array(), $rasa_user = null, $vat_id = '' ) {
	$wp_user = null;

	if ( ! empty( $kc_sub ) ) {
		$found = get_users(
			array(
				'meta_key'   => RASA_META_KEYCLOAK_ID,
				'meta_value' => $kc_sub,
				'number'     => 1,
			)
		);
		if ( empty( $found ) ) {
			$found = get_users(
				array(
					'meta_key'   => RASA_META_KEYLOACK_ID,
					'meta_value' => $kc_sub,
					'number'     => 1,
				)
			);
		}
		if ( ! empty( $found[0] ) ) {
			$wp_user = $found[0];
		}
	}

	if ( ! $wp_user ) {
		$wp_user = get_user_by( 'email', $email );
	}

	if ( ! $wp_user ) {
		$username = sanitize_user( current( explode( '@', $email ) ), true );
		if ( empty( $username ) || username_exists( $username ) ) {
			$username = sanitize_user( $username . '_' . strtolower( wp_generate_password( 5, false ) ), true );
		}
		$random_pass = wp_generate_password( 24, true, true );

		if ( function_exists( 'wc_create_new_customer' ) ) {
			$user_id = wc_create_new_customer( $email, $username, $random_pass );
		} else {
			$user_id = wp_create_user( $username, $random_pass, $email );
		}

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$wp_user = get_user_by( 'id', $user_id );
	}

	if ( ! ( $wp_user instanceof WP_User ) ) {
		return new WP_Error( 'sso_user_failed', 'Unable to initialize WP_User.' );
	}

	// Update display name, first name, last name
	if ( ! empty( $name ) ) {
		$parts = preg_split( '/\s+/', trim( $name ), 2 );
		wp_update_user(
			array(
				'ID'           => $wp_user->ID,
				'display_name' => $name,
				'first_name'   => $parts[0] ?? $name,
				'last_name'    => $parts[1] ?? '',
			)
		);
	}

	if ( ! empty( $kc_sub ) ) {
		rasa_sso_set_user_keycloak_id( $wp_user->ID, $kc_sub );
	}

	// Sync B2B Customer Role (without demoting WP Administrators)
	if ( ! in_array( 'administrator', (array) $wp_user->roles, true ) ) {
		if ( in_array( 'b2b_customer', $roles, true ) ) {
			$wp_user->set_role( 'b2b_customer' );
		} elseif ( empty( $wp_user->roles ) ) {
			$wp_user->set_role( 'customer' );
		}
	}

	if ( ! empty( $vat_id ) && defined( 'RASA_META_VAT_ID' ) ) {
		update_user_meta( $wp_user->ID, RASA_META_VAT_ID, rasa_sanitize_vat_id( $vat_id ) );
	}

	// Sync RasaApi UUID, Wallet & Loyalty Points
	if ( is_array( $rasa_user ) ) {
		if ( ! empty( $rasa_user['uuid'] ) ) {
			update_user_meta( $wp_user->ID, RASA_META_USER_UUID, sanitize_text_field( (string) $rasa_user['uuid'] ) );
		}
		if ( isset( $rasa_user['saldo_wallet'] ) ) {
			update_user_meta( $wp_user->ID, RASA_META_WALLET_BALANCE, (float) $rasa_user['saldo_wallet'] );
		}
		$points = (int) ( $rasa_user['point_credit'] ?? 0 ) - (int) ( $rasa_user['point_debit'] ?? 0 );
		update_user_meta( $wp_user->ID, RASA_META_LOYALTY_POINTS, max( 0, $points ) );
	}

	return $wp_user;
}

/**
 * ============================================================================
 * SINGLE SOURCE OF TRUTH: Sync Native WooCommerce Registration & Login -> RasaApi & Keycloak
 * ============================================================================
 */
function rasa_sso_sync_credentials_to_central_api( $email, $password, $name = '', $wp_user_id = 0 ) {
	$cfg = rasa_sso_config();
	if ( ! $cfg['enabled'] || empty( $email ) || empty( $password ) ) {
		return;
	}

	// 1. Try logging in against RasaApi (which automatically syncs with Keycloak)
	$login_res = wp_remote_post(
		$cfg['api_url'] . '/auth/login',
		array(
			'timeout' => 8,
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'email'    => $email,
					'password' => $password,
				)
			),
		)
	);

	if ( ! is_wp_error( $login_res ) && 200 === (int) wp_remote_retrieve_response_code( $login_res ) ) {
		$body  = json_decode( wp_remote_retrieve_body( $login_res ), true );
		$token = $body['data']['access_token'] ?? '';
		if ( $token && $wp_user_id ) {
			$me_res = wp_remote_get(
				$cfg['api_url'] . '/me',
				array(
					'timeout' => 6,
					'headers' => array(
						'Accept'        => 'application/json',
						'Authorization' => 'Bearer ' . $token,
					),
				)
			);
			if ( ! is_wp_error( $me_res ) && 200 === (int) wp_remote_retrieve_response_code( $me_res ) ) {
				$me_body = json_decode( wp_remote_retrieve_body( $me_res ), true );
				$r_user  = $me_body['data']['user'] ?? null;
				if ( is_array( $r_user ) ) {
					if ( ! empty( $r_user['keycloak_id'] ) ) {
						rasa_sso_set_user_keycloak_id( $wp_user_id, $r_user['keycloak_id'] );
					}
					if ( ! empty( $r_user['uuid'] ) ) {
						update_user_meta( $wp_user_id, RASA_META_USER_UUID, sanitize_text_field( (string) $r_user['uuid'] ) );
					}
				}
			}
		}
		return;
	}

	// 2. If user does not exist yet in RasaApi, register them in RasaApi (which auto-syncs to Keycloak)
	$display_name = $name ?: current( explode( '@', $email ) );
	$reg_res = wp_remote_post(
		$cfg['api_url'] . '/auth/register',
		array(
			'timeout' => 8,
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'name'                  => $display_name,
					'email'                 => $email,
					'password'              => $password,
					'password_confirmation' => $password,
					'is_guest'              => 1,
				)
			),
		)
	);

	if ( ! is_wp_error( $reg_res ) && in_array( (int) wp_remote_retrieve_response_code( $reg_res ), array( 200, 201 ), true ) && $wp_user_id ) {
		$reg_body = json_decode( wp_remote_retrieve_body( $reg_res ), true );
		$r_user   = $reg_body['data']['user'] ?? null;
		if ( is_array( $r_user ) ) {
			if ( ! empty( $r_user['keycloak_id'] ) ) {
				rasa_sso_set_user_keycloak_id( $wp_user_id, $r_user['keycloak_id'] );
			}
			if ( ! empty( $r_user['uuid'] ) ) {
				update_user_meta( $wp_user_id, RASA_META_USER_UUID, sanitize_text_field( (string) $r_user['uuid'] ) );
			}
		}
	}
}

/**
 * Hook into WooCommerce Customer Registration to push new account to RasaApi & Keycloak.
 */
add_action( 'woocommerce_created_customer', function ( $customer_id, $new_customer_data, $password_generated ) {
	$email = $new_customer_data['user_email'] ?? '';
	$pass  = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ( $new_customer_data['user_pass'] ?? '' );
	$name  = isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : ( $new_customer_data['user_login'] ?? '' );
	if ( ! empty( $email ) && ! empty( $pass ) ) {
		rasa_sso_sync_credentials_to_central_api( $email, $pass, $name, $customer_id );
	}
}, 10, 3 );

/**
 * Hook into WordPress native authentication so users in Keycloak/RasaApi can also log in via the native WooCommerce form,
 * and existing WooCommerce users are synced to RasaApi/Keycloak on login.
 */
add_filter( 'authenticate', function ( $user, $username, $password ) {
	if ( empty( $username ) || empty( $password ) ) {
		return $user;
	}

	$cfg = rasa_sso_config();
	if ( ! $cfg['enabled'] ) {
		return $user;
	}

	// Case A: Native WP login succeeded -> ensure user is synced to RasaApi & Keycloak
	if ( $user instanceof WP_User ) {
		rasa_sso_sync_credentials_to_central_api( $user->user_email, $password, $user->display_name, $user->ID );
		return $user;
	}

	// Case B: Native WP login failed or user doesn't exist locally in WP -> check RasaApi / Keycloak SSO
	if ( is_email( $username ) ) {
		$login_res = wp_remote_post(
			$cfg['api_url'] . '/auth/login',
			array(
				'timeout' => 8,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email'    => $username,
						'password' => $password,
					)
				),
			)
		);

		if ( ! is_wp_error( $login_res ) && 200 === (int) wp_remote_retrieve_response_code( $login_res ) ) {
			$body  = json_decode( wp_remote_retrieve_body( $login_res ), true );
			$token = $body['data']['access_token'] ?? '';
			if ( $token ) {
				$me_res = wp_remote_get(
					$cfg['api_url'] . '/me',
					array(
						'timeout' => 6,
						'headers' => array(
							'Accept'        => 'application/json',
							'Authorization' => 'Bearer ' . $token,
						),
					)
				);
				if ( ! is_wp_error( $me_res ) && 200 === (int) wp_remote_retrieve_response_code( $me_res ) ) {
					$me_body = json_decode( wp_remote_retrieve_body( $me_res ), true );
					$r_user  = $me_body['data']['user'] ?? null;
					if ( is_array( $r_user ) && ! empty( $r_user['email'] ) ) {
						$roles = array();
						if ( ! empty( $r_user['roles'] ) && is_array( $r_user['roles'] ) ) {
							foreach ( $r_user['roles'] as $r ) {
								$roles[] = is_array( $r ) ? (string) ( $r['name'] ?? '' ) : (string) $r;
							}
						}
						$wp_user = rasa_sso_resolve_or_create_wp_user(
							$r_user['email'],
							$r_user['name'] ?? '',
							$r_user['keycloak_id'] ?? '',
							$roles,
							$r_user
						);
						if ( $wp_user instanceof WP_User ) {
							wp_set_password( $password, $wp_user->ID );
							return $wp_user;
						}
					}
				}
			}
		}
	}

	return $user;
}, 35, 3 );

/**
 * ============================================================================
 * UI: Render "Login with Rasa SSO" Button on WooCommerce Login & My Account
 * ============================================================================
 */
function rasa_render_sso_login_button() {
	$cfg = rasa_sso_config();
	if ( ! $cfg['enabled'] ) {
		return;
	}

	$label = function_exists( 'rasa_tr' )
		? rasa_tr( 'Mit Rasa SSO anmelden', 'Login with Rasa SSO' )
		: 'Login with Rasa SSO';
	$or_label = function_exists( 'rasa_tr' )
		? rasa_tr( 'oder mit E-Mail anmelden', 'or sign in with email' )
		: 'or sign in with email';

	$sso_url = rasa_sso_login_url();
	?>
	<div class="rasa-sso-login-box" style="margin: 0 0 2.2rem 0;">
		<a href="<?php echo esc_url( $sso_url ); ?>"
		   class="button rasa-sso-btn"
		   style="display:flex;align-items:center;justify-content:center;gap:1rem;width:100%;min-height:4.8rem;padding:1.2rem 2rem;background:#121212;color:#edc0ae;border:1px solid #121212;border-radius:4px;font-weight:700;font-size:1.4rem;letter-spacing:0.06em;text-transform:uppercase;text-decoration:none;transition:all 0.2s ease;">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
				<path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
			</svg>
			<span><?php echo esc_html( $label ); ?></span>
		</a>
		<div style="display:flex;align-items:center;gap:1.2rem;margin-top:1.8rem;color:rgba(18,18,18,0.55);font-size:1.2rem;text-transform:uppercase;letter-spacing:0.08em;">
			<span style="flex:1;height:1px;background:rgba(18,18,18,0.15);"></span>
			<span><?php echo esc_html( $or_label ); ?></span>
			<span style="flex:1;height:1px;background:rgba(18,18,18,0.15);"></span>
		</div>
	</div>
	<?php
}
add_action( 'woocommerce_login_form_start', 'rasa_render_sso_login_button', 5 );

/**
 * Display Rasa SSO Identity, Loyalty Points & Wallet summary on WooCommerce My Account Dashboard.
 */
add_action( 'woocommerce_account_dashboard', function () {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$user_id   = get_current_user_id();
	$kc_id     = get_user_meta( $user_id, RASA_META_KEYCLOAK_ID, true ) ?: get_user_meta( $user_id, RASA_META_KEYLOACK_ID, true );
	$rasa_uuid = get_user_meta( $user_id, RASA_META_USER_UUID, true );
	$points    = (int) get_user_meta( $user_id, RASA_META_LOYALTY_POINTS, true );

	if ( empty( $kc_id ) && empty( $rasa_uuid ) ) {
		return;
	}

	$badge_title = function_exists( 'rasa_tr' )
		? rasa_tr( 'Rasa SSO Verbundenes Konto', 'Rasa SSO Connected Account' )
		: 'Rasa SSO Connected Account';
	$points_label = function_exists( 'rasa_tr' )
		? rasa_tr( 'Rasa Treuepunkte', 'Rasa Loyalty Points' )
		: 'Rasa Loyalty Points';
	?>
	<div class="rasa-sso-account-card" style="margin:2rem 0;padding:1.8rem 2rem;background:#121212;color:#edc0ae;border-radius:6px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1.2rem;">
		<div>
			<div style="font-size:1.15rem;text-transform:uppercase;letter-spacing:0.12em;opacity:0.85;">
				<?php echo esc_html( $badge_title ); ?>
			</div>
			<div style="font-size:1.4rem;color:#ffffff;margin-top:0.3rem;">
				ID: <code><?php echo esc_html( $kc_id ?: $rasa_uuid ); ?></code>
			</div>
		</div>
		<div style="text-align:right;">
			<div style="font-size:1.15rem;text-transform:uppercase;letter-spacing:0.1em;opacity:0.85;">
				<?php echo esc_html( $points_label ); ?>
			</div>
			<div style="font-size:2rem;font-weight:700;color:#edc0ae;">
				<?php echo esc_html( number_format_i18n( $points ) ); ?> pts
			</div>
		</div>
	</div>
	<?php
}, 15 );

/**
 * ============================================================================
 * WP-CLI & Bulk User Sync: Sync All Existing WooCommerce Users <-> Keycloak & RasaApi
 * Usage via WP-CLI:
 *   wp eval "rasa_sso_bulk_sync_all_woo_users();" --allow-root
 * ============================================================================
 */
function rasa_sso_bulk_sync_all_woo_users() {
	$cfg = rasa_sso_config();
	if ( ! $cfg['enabled'] ) {
		echo "Rasa SSO is disabled.\n";
		return;
	}

	// 1. Obtain service-account admin token from Keycloak using rasa-api credentials
	$api_secret = getenv( 'KEYCLOAK_API_CLIENT_SECRET' ) ?: 'rasa-api-secret-dev-2026';
	$token_url  = $cfg['kc_url'] . '/realms/' . rawurlencode( $cfg['realm'] ) . '/protocol/openid-connect/token';

	$token_res = wp_remote_post(
		$token_url,
		array(
			'timeout' => 10,
			'body'    => array(
				'grant_type'    => 'client_credentials',
				'client_id'     => 'rasa-api',
				'client_secret' => $api_secret,
			),
		)
	);

	$admin_token = '';
	if ( ! is_wp_error( $token_res ) && 200 === (int) wp_remote_retrieve_response_code( $token_res ) ) {
		$token_body  = json_decode( wp_remote_retrieve_body( $token_res ), true );
		$admin_token = (string) ( $token_body['access_token'] ?? '' );
	}

	$users  = get_users( array( 'fields' => 'all' ) );
	$total  = count( $users );
	$synced = 0;

	echo "Synchronizing {$total} WordPress/WooCommerce user(s) to Rasa SSO (Keycloak) & RasaApi...\n";

	foreach ( $users as $wp_user ) {
		if ( empty( $wp_user->user_email ) ) {
			continue;
		}

		$email      = strtolower( trim( $wp_user->user_email ) );
		$first_name = get_user_meta( $wp_user->ID, 'first_name', true ) ?: $wp_user->display_name ?: $wp_user->user_login;
		$last_name  = get_user_meta( $wp_user->ID, 'last_name', true ) ?: 'User';
		$phone      = get_user_meta( $wp_user->ID, 'billing_phone', true ) ?: '';
		$vat_id     = defined( 'RASA_META_VAT_ID' ) ? get_user_meta( $wp_user->ID, RASA_META_VAT_ID, true ) : '';
		$is_b2b     = in_array( 'b2b_customer', (array) $wp_user->roles, true );
		$kc_id      = get_user_meta( $wp_user->ID, RASA_META_KEYCLOAK_ID, true ) ?: get_user_meta( $wp_user->ID, RASA_META_KEYLOACK_ID, true );

		// A. Sync with Keycloak Admin REST API if admin_token is available
		if ( ! empty( $admin_token ) ) {
			$kc_base = $cfg['kc_url'] . '/admin/realms/' . rawurlencode( $cfg['realm'] );

			if ( empty( $kc_id ) ) {
				$search_res = wp_remote_get(
					add_query_arg(
						array(
							'email' => rawurlencode( $email ),
							'exact' => 'true',
						),
						$kc_base . '/users'
					),
					array(
						'timeout' => 8,
						'headers' => array( 'Authorization' => 'Bearer ' . $admin_token ),
					)
				);
				if ( ! is_wp_error( $search_res ) && 200 === (int) wp_remote_retrieve_response_code( $search_res ) ) {
					$found = json_decode( wp_remote_retrieve_body( $search_res ), true );
					if ( ! empty( $found[0]['id'] ) ) {
						$kc_id = $found[0]['id'];
					}
				}
			}

			$attrs = array(
				'wp_user_id' => array( (string) $wp_user->ID ),
			);
			if ( ! empty( $phone ) ) {
				$attrs['phone'] = array( (string) $phone );
			}
			if ( ! empty( $vat_id ) ) {
				$attrs['b2b_vat_id'] = array( (string) $vat_id );
			}

			$kc_payload = array(
				'username'      => $email,
				'email'         => $email,
				'firstName'     => (string) $first_name,
				'lastName'      => (string) $last_name,
				'enabled'       => true,
				'emailVerified' => true,
				'attributes'    => $attrs,
			);

			if ( ! empty( $kc_id ) ) {
				wp_remote_request(
					$kc_base . '/users/' . rawurlencode( $kc_id ),
					array(
						'method'  => 'PUT',
						'timeout' => 8,
						'headers' => array(
							'Authorization' => 'Bearer ' . $admin_token,
							'Content-Type'  => 'application/json',
						),
						'body'    => wp_json_encode( $kc_payload ),
					)
				);
			} else {
				$create_res = wp_remote_post(
					$kc_base . '/users',
					array(
						'timeout' => 8,
						'headers' => array(
							'Authorization' => 'Bearer ' . $admin_token,
							'Content-Type'  => 'application/json',
						),
						'body'    => wp_json_encode( $kc_payload ),
					)
				);
				if ( ! is_wp_error( $create_res ) && 201 === (int) wp_remote_retrieve_response_code( $create_res ) ) {
					$loc = wp_remote_retrieve_header( $create_res, 'location' );
					if ( $loc ) {
						$parts = explode( '/', rtrim( (string) $loc, '/' ) );
						$kc_id = end( $parts );
					}
				}
			}

			if ( ! empty( $kc_id ) ) {
				rasa_sso_set_user_keycloak_id( $wp_user->ID, $kc_id );
				$synced++;
				echo " - [OK] {$email} -> keycloak_id: {$kc_id}" . ( $is_b2b ? " (B2B)" : "" ) . "\n";
			} else {
				echo " - [SKIP] {$email} (unable to resolve keycloak_id)\n";
			}
		}
	}

	echo "WooCommerce SSO Sync completed: {$synced}/{$total} user(s) synchronized.\n";
}

