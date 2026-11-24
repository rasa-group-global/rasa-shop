<?php
/**
 * Compatibility bridge for Revolut Gateway for WooCommerce (v4.22.x) when
 * using modern Developer API Console Sandbox keys (`r_test_...`).
 *
 * New `r_test_...` keys only authorise `/api/...` (2024-09-01) endpoints and
 * receive HTTP 401 on legacy `/api/1.0/...` paths that v4.22.13 still calls
 * during order validation, customer lookup, and capture/refund operations.
 * This adapter is strictly limited to `sandbox-merchant.revolut.com/api/1.0/`
 * while mode is `sandbox` and never touches production (`merchant.revolut.com`).
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Determine whether the Sandbox `r_test_` compatibility adapter should run.
 */
function rasa_revolut_sandbox_rtest_active()
{
	$settings = get_option('woocommerce_revolut_settings', array());
	if (!is_array($settings)) {
		return false;
	}

	$mode = isset($settings['mode']) && $settings['mode'] !== '' ? strtolower((string) $settings['mode']) : 'sandbox';
	$sandbox_key = isset($settings['api_key_sandbox']) ? trim((string) $settings['api_key_sandbox']) : '';

	return 'sandbox' === $mode && strpos($sandbox_key, 'r_test_') === 0;
}

/**
 * Prevent empty overwrites of `sandbox_revolut_merchant_public_key` when
 * Revolut API Settings save hits HTTP 401 on `/api/public-key/latest`.
 */
add_filter('pre_update_option_sandbox_revolut_merchant_public_key', function ($new_value, $old_value) {
	if (rasa_revolut_sandbox_rtest_active() && empty($new_value) && !empty($old_value)) {
		return $old_value;
	}
	return $new_value;
}, 10, 2);

/**
 * Normalise a single Revolut 2024-09-01 order payload to the legacy `/1.0/`
 * shape expected by `WC_Payment_Gateway_Revolut` and `WC_Revolut_Helper_Trait`.
 *
 * @param array $data Decoded JSON order object.
 * @return array
 */
function rasa_revolut_normalise_order_payload(array $data)
{
	if (isset($data[0]) && is_array($data[0])) {
		foreach ($data as $i => $item) {
			if (is_array($item)) {
				$data[$i] = rasa_revolut_normalise_order_payload($item);
			}
		}
		return $data;
	}

	if (isset($data['token']) && !isset($data['public_id'])) {
		$data['public_id'] = $data['token'];
	}
	if (isset($data['state']) && is_string($data['state'])) {
		$data['state'] = strtoupper($data['state']);
	}
	if (isset($data['type']) && is_string($data['type'])) {
		$data['type'] = strtoupper($data['type']);
	}
	if (isset($data['amount']) && !isset($data['order_amount'])) {
		$data['order_amount'] = array(
			'value'    => (int) $data['amount'],
			'currency' => isset($data['currency']) ? (string) $data['currency'] : '',
		);
	}
	if (isset($data['refunded_amount']) && !is_array($data['refunded_amount'])) {
		$data['refunded_amount'] = array(
			'value'    => (int) $data['refunded_amount'],
			'currency' => isset($data['currency']) ? (string) $data['currency'] : '',
		);
	}
	if (isset($data['customer']['id']) && !isset($data['customer_id'])) {
		$data['customer_id'] = $data['customer']['id'];
	}
	if (isset($data['merchant_order_data']['reference']) && !isset($data['merchant_order_ext_ref'])) {
		$data['merchant_order_ext_ref'] = $data['merchant_order_data']['reference'];
	}
	if (isset($data['payments']) && is_array($data['payments'])) {
		foreach ($data['payments'] as $idx => $payment) {
			if (isset($payment['payment_method']['type']) && is_string($payment['payment_method']['type'])) {
				$data['payments'][$idx]['payment_method']['type'] = strtoupper($payment['payment_method']['type']);
			}
			if (isset($payment['state']) && is_string($payment['state'])) {
				$data['payments'][$idx]['state'] = strtoupper($payment['state']);
			}
		}
	}
	return $data;
}

/**
 * Translate legacy `/1.0/` request path, query, and JSON body into `2024-09-01` format.
 *
 * @param string $url    Full request URL starting with `https://sandbox-merchant.revolut.com/api/1.0/`.
 * @param string $method HTTP method.
 * @param array  $args   wp_remote_request args.
 * @return array{url:string,args:array,term:string,rel_path:string}
 */
function rasa_revolut_build_modern_request($url, $method, array $args)
{
	$prefix = '/api/1.0/';
	$parts = wp_parse_url($url);
	$full_path = isset($parts['path']) ? (string) $parts['path'] : '';
	$rel_path = strpos($full_path, $prefix) === 0 ? ltrim(substr($full_path, strlen($prefix)), '/') : '';
	$query = array();
	if (!empty($parts['query'])) {
		parse_str($parts['query'], $query);
	}

	$term = '';
	if ('GET' === $method && 'customers' === $rel_path && isset($query['term'])) {
		$term = (string) $query['term'];
		unset($query['term']);
	}

	if (!empty($args['body']) && is_string($args['body'])) {
		$payload = json_decode($args['body'], true);
		if (is_array($payload)) {
			if (strpos($rel_path, 'orders/') === 0 && 'PATCH' === $method) {
				if (isset($payload['merchant_order_ext_ref'])) {
					$payload['merchant_order_data'] = array('reference' => (string) $payload['merchant_order_ext_ref']);
					unset($payload['merchant_order_ext_ref']);
				}
				if (array_key_exists('customer_id', $payload)) {
					if (!empty($payload['customer_id'])) {
						$payload['customer'] = array('id' => (string) $payload['customer_id']);
					}
					unset($payload['customer_id']);
				}
			} elseif ('customers' === $rel_path && 'POST' === $method && empty($payload['full_name']) && !empty($payload['email'])) {
				$payload['full_name'] = strstr((string) $payload['email'], '@', true) ?: (string) $payload['email'];
			}
			$args['body'] = wp_json_encode($payload);
		}
	}

	$headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : array();
	$has_version = false;
	foreach (array_keys($headers) as $hk) {
		if (strtolower((string) $hk) === 'revolut-api-version') {
			$has_version = true;
			break;
		}
	}
	if (!$has_version) {
		$headers['Revolut-Api-Version'] = '2024-09-01';
	}
	$args['headers'] = $headers;

	$target_url = 'https://sandbox-merchant.revolut.com/api/' . $rel_path;
	if (!empty($query)) {
		$target_url = add_query_arg($query, $target_url);
	}

	return array(
		'url'      => $target_url,
		'args'     => $args,
		'term'     => $term,
		'rel_path' => $rel_path,
	);
}

/**
 * Intercept `sandbox-merchant.revolut.com/api/1.0/` requests and route them
 * through the `2024-09-01` `/api/` endpoints expected by `r_test_` keys.
 */
add_filter('pre_http_request', function ($preempt, $parsed_args, $url) {
	static $in_bridge = false;

	if ($in_bridge || false !== $preempt || !is_string($url)) {
		return $preempt;
	}

	$prefix = 'https://sandbox-merchant.revolut.com/api/1.0/';
	if (strpos($url, $prefix) !== 0 || !rasa_revolut_sandbox_rtest_active()) {
		return $preempt;
	}

	$method = isset($parsed_args['method']) ? strtoupper((string) $parsed_args['method']) : 'GET';
	$mapped = rasa_revolut_build_modern_request($url, $method, $parsed_args);

	$in_bridge = true;
	try {
		$response = wp_remote_request($mapped['url'], $mapped['args']);
	} finally {
		$in_bridge = false;
	}

	if (is_wp_error($response)) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code($response);
	$body = wp_remote_retrieve_body($response);
	$data = json_decode($body, true);

	if ($code >= 200 && $code < 300 && is_array($data)) {
		if (strpos($mapped['rel_path'], 'orders') === 0) {
			$data = rasa_revolut_normalise_order_payload($data);
		} elseif ('GET' === $method && 'customers' === $mapped['rel_path'] && '' !== $mapped['term']) {
			$filtered = array();
			foreach ($data as $row) {
				if (is_array($row) && isset($row['email']) && strcasecmp((string) $row['email'], $mapped['term']) === 0) {
					$filtered[] = $row;
				}
			}
			$data = $filtered;
		}
		$response['body'] = wp_json_encode($data);
	}

	return $response;
}, 10, 3);
