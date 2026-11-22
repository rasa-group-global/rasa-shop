#!/usr/bin/env python3
"""
Permanent pre-push regression suite for Rasa Kopi WooCommerce (`digitalCommerce`).

Covers:
1. Theme version consistency (RASA_VERSION === style.css Version).
2. Light Block Checkout & Shopify-style Order Received (`thankyou.php`).
3. Strict CSS parser across all stylesheets + top-level rule alignment in rasa-overrides.css.
4. PHP balance, YAML workflow, and Nginx `/wp-json/` routing integrity.
5. WooCommerce Additional Checkout Fields API contract (blocks forbidden attributes like
   `placeholder` that trigger `_doing_it_wrong` early output) & `validate_callback` WP_Error contract.
6. Quirks-mode & `headers_sent` early-output guards (BOM/leading-whitespace/EOF checks,
   `.utility-bar { height: auto !important }` guard against `base.css:height:100%` 100vh collapse).
7. Store settings baseline (`setup/settings.sh`, `docs/settings.md`, `rasa-store-ops.php`,
   `first-run.sh`, `.env.example`), LF enforcement on `.sh`, and secret-hygiene scanning.
8. Notice HTML entity decoding & tag leak guard in AJAX cart error responses (`functions.php`)
   and frontend PDP error presentation (`rasa-shim.js`).
"""

import os
import re
import subprocess
import sys

try:
    import yaml
except ImportError:
    yaml = None

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)


def check_php_balance(src: str, label: str) -> None:
    idx = 0
    depth = {'(': 0, '{': 0, '[': 0}
    in_php = False
    while idx < len(src):
        if not in_php:
            if src.startswith('<?php', idx) or src.startswith('<?=', idx):
                in_php = True
                idx += 5 if src.startswith('<?php', idx) else 3
                continue
            idx += 1
        else:
            c = src[idx]
            if src.startswith('?>', idx):
                in_php = False
                idx += 2
            elif src.startswith('//', idx) or c == '#':
                nl = src.find('\n', idx)
                close = src.find('?>', idx)
                if close != -1 and (nl == -1 or close < nl):
                    idx = close
                elif nl == -1:
                    break
                else:
                    idx = nl + 1
            elif src.startswith('/*', idx):
                end = src.find('*/', idx + 2)
                idx = end + 2 if end != -1 else len(src)
            elif c in ('"', "'"):
                q = c
                idx += 1
                while idx < len(src):
                    if src[idx] == '\\':
                        idx += 2
                    elif src[idx] == q:
                        idx += 1
                        break
                    else:
                        idx += 1
            elif src.startswith('<<<', idx):
                m = re.match(r"<<<['\"]?([A-Za-z_][A-Za-z0-9_]*)['\"]?\r?\n", src[idx:])
                if m:
                    tag = m.group(1)
                    idx += len(m.group(0))
                    em = re.search(r'^' + re.escape(tag) + r';?\r?$', src[idx:], re.M)
                    idx = idx + em.end() if em else len(src)
                else:
                    idx += 3
            else:
                if c in '({[':
                    depth[c] += 1
                elif c == ')':
                    depth['('] -= 1
                elif c == '}':
                    depth['{'] -= 1
                elif c == ']':
                    depth['['] -= 1
                idx += 1
    assert all(v == 0 for v in depth.values()), f'Unbalanced PHP delimiters in {label}: {depth}'


def verify_css_file(path: str, allow_nesting: bool = False, check_section_reset: bool = False) -> None:
    raw = open(path, 'r', encoding='utf-8').read()
    i, n = 0, len(raw)
    line = 1
    stack = []
    token_buf = []
    in_str = None
    while i < n:
        c = raw[i]
        if c == '\n':
            line += 1
        if in_str:
            if c == '\\' and i + 1 < n:
                if raw[i + 1] == '\n':
                    line += 1
                i += 2
                continue
            if c == in_str:
                in_str = None
            i += 1
            continue
        if raw.startswith('/*', i):
            end = raw.find('*/', i + 2)
            assert end != -1, f'{path}:{line}: Unclosed CSS comment'
            comment = raw[i:end]
            if check_section_reset and '/* =====' in comment and not stack == []:
                raise AssertionError(f'{path}:{line}: CSS section header starts with unclosed blocks: {stack}')
            line += comment.count('\n')
            i = end + 2
            continue
        if c in ('"', "'"):
            in_str = c
            i += 1
            continue
        if c == '{':
            hdr = ''.join(token_buf).strip()
            token_buf.clear()
            if not allow_nesting and stack and stack[-1][0] == 'rule':
                raise AssertionError(f'{path}:{line}: Unclosed CSS rule "{stack[-1][2]}" (line {stack[-1][1]}) before "{hdr} {{"')
            kind = 'at' if hdr.startswith('@') else 'rule'
            stack.append((kind, line, hdr[:80]))
        elif c == '}':
            token_buf.clear()
            assert stack, f'{path}:{line}: Stray closing brace }}'
            stack.pop()
        elif c == ';':
            token_buf.clear()
        else:
            token_buf.append(c)
        i += 1
    assert not stack, f'{path}: Unclosed CSS blocks at EOF: {stack}'


def extract_balanced(src: str, open_idx: int, open_ch: str = '(', close_ch: str = ')') -> tuple[str, int]:
    """Return balanced substring including delimiters starting at open_idx and end index (inclusive)."""
    depth = 0
    pos = open_idx
    n = len(src)
    while pos < n:
        ch = src[pos]
        if ch in ('"', "'"):
            q = ch
            pos += 1
            while pos < n:
                if src[pos] == '\\':
                    pos += 2
                elif src[pos] == q:
                    pos += 1
                    break
                else:
                    pos += 1
            continue
        if src.startswith('//', pos) or ch == '#':
            nl = src.find('\n', pos)
            pos = nl + 1 if nl != -1 else n
            continue
        if src.startswith('/*', pos):
            end_c = src.find('*/', pos + 2)
            pos = end_c + 2 if end_c != -1 else n
            continue
        if ch == open_ch:
            depth += 1
        elif ch == close_ch:
            depth -= 1
            if depth == 0:
                return src[open_idx:pos + 1], pos
        pos += 1
    raise AssertionError(f'Unclosed delimiter {open_ch} starting at offset {open_idx}')


def check_additional_checkout_fields(php_paths: list[str]) -> None:
    allowed_top_keys = {
        'id', 'label', 'optionalLabel', 'location', 'type', 'required',
        'options', 'attributes', 'sanitize_callback', 'validate_callback',
        'index', 'hidden', 'show_in_order_confirmation',
    }
    allowed_attr_exact = {'autocomplete', 'autocapitalize', 'maxLength', 'readOnly', 'pattern', 'title'}
    allowed_locations = {'contact', 'address', 'order'}

    call_count = 0
    pat = re.compile(r'woocommerce_register_additional_checkout_field\s*\(\s*(?:array\s*\(|\[)')
    for pf in php_paths:
        src = open(pf, 'r', encoding='utf-8').read()
        for m in pat.finditer(src):
            call_count += 1
            open_idx = src.rfind('(', m.start(), m.end()) if m.group(0).endswith('(') else src.rfind('[', m.start(), m.end())
            close_ch = ')' if src[open_idx] == '(' else ']'
            block, _ = extract_balanced(src, open_idx, src[open_idx], close_ch)

            # 1. Verify location
            loc_m = re.search(r"['\"]location['\"]\s*=>\s*['\"]([^'\"]+)['\"]", block)
            assert loc_m and loc_m.group(1) in allowed_locations, f'{pf}: Invalid additional checkout field location'

            # 2. Verify attributes whitelist cleanly via balanced block extraction
            attr_m = re.search(r"['\"]attributes['\"]\s*=>\s*(?:array\s*\(|\[)", block)
            if attr_m:
                attr_open = attr_m.end() - 1
                attr_close = ')' if block[attr_open] == '(' else ']'
                attr_body, _ = extract_balanced(block, attr_open, block[attr_open], attr_close)
                attr_keys = re.findall(r"['\"]([A-Za-z0-9_-]+)['\"]\s*=>", attr_body)
                for ak in attr_keys:
                    if ak not in allowed_attr_exact and not ak.startswith(('data-', 'aria-')):
                        raise AssertionError(
                            f"{pf}: Disallowed attribute '{ak}' in woocommerce_register_additional_checkout_field['attributes']. "
                            "WooCommerce rejects placeholder/maxlength/class/style and triggers _wc_doing_it_wrong()!"
                        )

            # 3. Verify validate_callback uses WP_Error return contract, not 2-arg $errors->add()
            val_m = re.search(r"['\"]validate_callback['\"]\s*=>\s*function\s*\(([^)]*)\)\s*\{", block)
            if val_m:
                params_str = val_m.group(1)
                body_str, _ = extract_balanced(block, val_m.end() - 1, '{', '}')
                param_names = [p.strip() for p in params_str.split(',') if p.strip()]
                assert len(param_names) <= 2, f'{pf}: validate_callback accepts ($value, $field), got {param_names}'
                assert '->add(' not in body_str, (
                    f'{pf}: validate_callback must return new WP_Error(...), not call $errors->add()'
                )
                assert 'WP_Error' in body_str and 'return true' in body_str, (
                    f'{pf}: validate_callback should return WP_Error on failure and true on success'
                )

            # 4. Reject unknown / deprecated top-level keys (top-level keys sit 1 indent inside block)
            inner_start = block.find('\n')
            if inner_start != -1:
                for tk in re.findall(r"^\t\t['\"]([A-Za-z0-9_]+)['\"]\s*=>", block, re.M):
                    assert tk in allowed_top_keys, f'{pf}: Unknown top-level key {tk} in additional checkout field'
            assert not re.search(r"['\"]placeholder['\"]\s*=>", block), (
                f"{pf}: 'placeholder' is not allowed inside woocommerce_register_additional_checkout_field()"
            )

    assert call_count >= 1, 'Expected at least 1 woocommerce_register_additional_checkout_field registration'


def check_early_output_and_quirks_guards(php_paths: list[str]) -> None:
    early_hook_pat = re.compile(r"add_action\(\s*['\"](init|woocommerce_init|plugins_loaded|after_setup_theme)['\"]\s*,\s*function\s*\([^)]*\)\s*\{")
    for pf in php_paths:
        raw = open(pf, 'rb').read()
        assert not raw.startswith(b'\xef\xbb\xbf'), f'{pf}: UTF-8 BOM detected (breaks headers / Quirks Mode)'
        assert raw.startswith(b'<?php'), f'{pf}: Leading bytes before <?php detected (breaks headers / Quirks Mode)'
        rel = os.path.relpath(pf, ROOT)
        if pf.endswith('functions.php') or (os.path.sep + 'inc' + os.path.sep) in pf:
            text = raw.decode('utf-8')
            assert not re.search(r'\?>\s*$', text), f'{rel}: Library PHP file must not end with closing ?>'
            for hm in early_hook_pat.finditer(text):
                hook = hm.group(1)
                body, _ = extract_balanced(text, hm.end() - 1, '{', '}')
                assert not re.search(r'\b(echo|print|printf)\b', body), (
                    f'{rel}: Early hook "{hook}" must never output HTML directly'
                )

    header_src = open(os.path.join(ROOT, 'theme/rasa-kopi/header.php'), 'r', encoding='utf-8').read()
    # Ensure <!doctype html> has no preceding HTML tags and .utility-bar prevents height:100% Quirks collapse
    doctype_pos = header_src.lower().find('<!doctype html>')
    assert doctype_pos != -1, 'Missing <!doctype html> in header.php'
    before_doctype = header_src[:doctype_pos]
    assert '<div' not in before_doctype and '<style' not in before_doctype and '<script' not in before_doctype
    util_m = re.search(r'\.utility-bar\s*\{([^}]*)\}', header_src, re.S)
    assert util_m and re.search(r'height\s*:\s*auto\s*!important', util_m.group(1)), (
        'header.php .utility-bar must force height: auto !important to prevent base.css height:100% 100vh collapse'
    )


def main() -> None:
    print('=== 1. VERSION CONSISTENCY ===')
    func_src = open(os.path.join(ROOT, 'theme/rasa-kopi/functions.php'), 'r', encoding='utf-8').read()
    style_src = open(os.path.join(ROOT, 'theme/rasa-kopi/style.css'), 'r', encoding='utf-8').read()
    func_defs = re.findall(r"define\(\s*['\"]RASA_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", func_src)
    style_vers = re.findall(r"^Version:\s*(.+)$", style_src, re.M)
    assert len(func_defs) == 1 and len(style_vers) == 1
    assert func_defs[0] == style_vers[0].strip(), f'Version mismatch: {func_defs[0]} vs {style_vers[0]}'
    print(f"Single RASA_VERSION === style.css Version === '{func_defs[0]}': OK")

    print('\n=== 2. LIGHT BLOCK CHECKOUT & SHOPIFY THANK YOU ===')
    for removed in ('form-checkout.php', 'review-order.php'):
        assert not os.path.exists(os.path.join(ROOT, 'theme/rasa-kopi/woocommerce/checkout', removed))
    ty_src = open(os.path.join(ROOT, 'theme/rasa-kopi/woocommerce/checkout/thankyou.php'), 'r', encoding='utf-8').read()
    assert 'rasa-ty-grid' in ty_src and 'rasa-ty-summary-card' in ty_src and 'woocommerce_thankyou' in ty_src
    assert 'esc_html( $item->get_name()' not in ty_src
    assert re.search(r"wp_kses_post\(\s*apply_filters\(\s*'woocommerce_order_item_name'", ty_src)
    idx_src = open(os.path.join(ROOT, 'theme/rasa-kopi/index.php'), 'r', encoding='utf-8').read()
    assert 'rasa-checkout-content' in idx_src and 'the_content()' in idx_src and '[woocommerce_checkout]' not in idx_src
    print('Light Block Checkout + Shopify thankyou.php verified: OK')

    print('\n=== 3. STRICT CSS PARSER ===')
    css_dir = os.path.join(ROOT, 'theme/rasa-kopi/assets/css')
    for cf in sorted(os.listdir(css_dir)):
        if cf.endswith('.css'):
            verify_css_file(
                os.path.join(css_dir, cf),
                allow_nesting=(cf != 'rasa-overrides.css'),
                check_section_reset=(cf == 'rasa-overrides.css'),
            )
    verify_css_file(os.path.join(ROOT, 'theme/rasa-kopi/inc/tokens.css'), allow_nesting=False)
    verify_css_file(os.path.join(ROOT, 'theme/rasa-kopi/inc/fonts.css'), allow_nesting=False)
    ovr = open(os.path.join(css_dir, 'rasa-overrides.css'), 'r', encoding='utf-8').read()
    for cls in (
        '.wc-block-components-sidebar-layout.wc-block-checkout',
        '.wc-block-checkout__sidebar',
        '.rasa-ty-grid',
        '.rasa-ty-summary-card',
        '.woocommerce .rasa-cart-drawer a.button.cart__checkout-button',
        '.rasa-cart-drawer .cart__checkout-button::after',
    ):
        assert cls in ovr, f'Missing {cls} in rasa-overrides.css'
    print('Strict CSS parser passed on all stylesheets: OK')

    print('\n=== 4. PHP, YAML & NGINX INTEGRITY ===')
    if yaml is not None:
        with open(os.path.join(ROOT, '.github/workflows/deploy.yml'), 'r', encoding='utf-8') as f:
            y = yaml.safe_load(f)
        assert 'jobs' in y and 'deploy-dev' in y['jobs'] and 'deploy-prod' in y['jobs']

        deploy_content = open(os.path.join(ROOT, '.github/workflows/deploy.yml'), 'r', encoding='utf-8').read()
        for kc_opt in (
            'wp option update rasa_keycloak_enabled',
            'wp option update rasa_keycloak_url',
            'wp option update rasa_keycloak_realm',
            'wp option update rasa_keycloak_client_id',
            'wp option update rasa_keycloak_client_secret',
            'wp option update rasa_api_base_url',
        ):
            assert kc_opt in deploy_content, f'Missing {kc_opt} in .github/workflows/deploy.yml'

        dc_content = open(os.path.join(ROOT, 'docker-compose.yml'), 'r', encoding='utf-8').read()
        for env_var in (
            'RASA_KEYCLOAK_ENABLED',
            'RASA_KEYCLOAK_URL',
            'RASA_KEYCLOAK_REALM',
            'RASA_KEYCLOAK_CLIENT_ID',
            'RASA_KEYCLOAK_CLIENT_SECRET',
            'RASA_API_BASE_URL',
        ):
            assert env_var in dc_content, f'Missing {env_var} in docker-compose.yml'
    nginx = open(os.path.join(ROOT, 'conf/nginx/shop-woo.conf'), 'r', encoding='utf-8').read()
    assert 'location ^~ /wp-json/' in nginx and nginx.count('{') == nginx.count('}')

    php_files = []
    for dp, _, fns in os.walk(os.path.join(ROOT, 'theme/rasa-kopi')):
        for fn in fns:
            if fn.endswith('.php'):
                php_files.append(os.path.join(dp, fn))
    php_files.sort()
    for pf in php_files:
        src = open(pf, 'r', encoding='utf-8').read()
        for bad in ('rasa-checkout.css', 'rasa-checkout.js', 'rasa-checkout-body', 'rasa-js-checkout'):
            assert bad not in src, f'Leftover {bad} in {pf}'
        check_php_balance(src, pf)
    print(f'Checked {len(php_files)} PHP files, deploy.yml, shop-woo.conf: OK')

    print('\n=== 5. WOOCOMMERCE CHECKOUT FIELD API & EARLY-OUTPUT / QUIRKS GUARDS ===')
    check_additional_checkout_fields(php_files)
    check_early_output_and_quirks_guards(php_files)
    print('Additional checkout field attribute/callback contract & Quirks/headers guards: OK')

    print('\n=== 6. STORE SETTINGS BASELINE, SHELL PARITY & SECRET HYGIENE ===')
    for sh_rel in ('setup/settings.sh', 'scripts/first-run.sh'):
        sh_raw = open(os.path.join(ROOT, sh_rel), 'rb').read()
        assert b'\r\n' not in sh_raw, f'CRLF detected in {sh_rel}'

    sh_src = open(os.path.join(ROOT, 'setup/settings.sh'), 'r', encoding='utf-8').read()
    gitattr = open(os.path.join(ROOT, '.gitattributes'), 'r', encoding='utf-8').read()
    assert '*.sh text eol=lf' in gitattr

    heredoc_count = 0
    for m in re.finditer(r"WP_EVAL <<('?)PHP\1\n(.*?)\nPHP\n", sh_src, re.S):
        heredoc_count += 1
        quoted, body = m.group(1), m.group(2)
        if quoted == '':
            body = body.replace(r'\$', '$')
        check_php_balance('<?php\n' + body, f'setup/settings.sh heredoc@{m.start()}')
    assert heredoc_count >= 5, f'Expected >= 5 WP_EVAL PHP heredocs, got {heredoc_count}'

    inline_count = 0
    for m in re.finditer(r"WP eval '\n?(.*?)\n?'", sh_src, re.S):
        inline_count += 1
        check_php_balance('<?php\n' + m.group(1), f'setup/settings.sh inline@{m.start()}')
    assert inline_count >= 2, f'Expected >= 2 inline WP eval PHP blocks, got {inline_count}'

    for needle in (
        "woocommerce_currency 'EUR'", "woocommerce_prices_include_tax 'yes'", "'19.0000'", "'7.0000'",
        "'reduced-rate'", "'b2b_customer'", "'keey'", "'partner-roaster'", "'Germany'", "'4.90'",
        "'min_amount'", "'59'", "woocommerce_enable_guest_checkout 'yes'",
        "woocommerce_revolut_cc_settings", "woocommerce_revolut_pay_settings", "shop@rasakopi.coffee",
        "woocommerce_gzdp_invoice_enabled 'no'", "DISABLE_WP_CRON true --raw", "rewrite structure '/%postname%/'",
    ):
        assert needle in sh_src, f'Missing {needle} in setup/settings.sh'

    env_ex = open(os.path.join(ROOT, '.env.example'), 'r', encoding='utf-8').read()
    first_run = open(os.path.join(ROOT, 'scripts/first-run.sh'), 'r', encoding='utf-8').read()
    assert 'WOOCOMMERCE_CURRENCY=EUR' in env_ex and 'IDR' not in env_ex
    assert "woocommerce_currency 'EUR'" in first_run and "woocommerce_currency_pos 'right_space'" in first_run and 'IDR' not in first_run

    assert "require_once RASA_DIR . '/inc/rasa-store-ops.php';" in func_src
    ops_src = open(os.path.join(ROOT, 'theme/rasa-kopi/inc/rasa-store-ops.php'), 'r', encoding='utf-8').read()
    for ops_sym in (
        'function_exists( \'is_user_logged_in\' )',
        'function_exists( \'WC\' ) && WC()->cart',
        'woocommerce_register_additional_checkout_field',
        'rasa-kopi/vat-id',
        'WC_Tax::calc_inclusive_tax',
        'storeabill_invoice_create_document',
        '_rasa_fulfilled_by',
        '_rasa_roaster',
        'woocommerce_rest_prepare_product_object',
        'woocommerce_rest_pre_insert_product_object',
        'woocommerce_rest_prepare_shop_order_object',
    ):
        assert ops_sym in ops_src, f'Missing {ops_sym} in rasa-store-ops.php'

    docs_src = open(os.path.join(ROOT, 'docs/settings.md'), 'r', encoding='utf-8').read()
    for doc_needle in ('2026-11-18', '4.90', '59', 'DHL', 'Keey', 'Partner roaster', 'shop@rasakopi.coffee', 'DISABLE_WP_CRON', '/etc/cron.d/rasa-woo', 'fulfilled_by', 'roaster'):
        assert doc_needle in docs_src, f'Missing {doc_needle} in docs/settings.md'

    secret_re = re.compile(r'\b(?:r_test_|r_live_|rk_live_|rk_test_|pk_live_|pk_sandbox_|ck_|cs_|sk_test_|sk_live_)[A-Za-z0-9_]{16,}')
    for check_rel in ('setup/settings.sh', 'scripts/first-run.sh', '.env.example', 'docs/settings.md', 'theme/rasa-kopi/inc/rasa-store-ops.php'):
        txt = open(os.path.join(ROOT, check_rel), 'r', encoding='utf-8').read()
        assert not secret_re.search(txt), f'Potential hardcoded secret found in {check_rel}'

    print('All store settings baseline, shell heredocs, custom fields & secret hygiene checks: OK')

    print('\n=== 7. AJAX ADD-TO-CART REDIRECT & FORM HANDLER GUARD ===')
    shim_src = open(os.path.join(ROOT, 'theme/rasa-kopi/assets/js/rasa-shim.js'), 'r', encoding='utf-8').read()
    assert "fd.delete('add-to-cart')" in shim_src, (
        "rasa-shim.js must delete 'add-to-cart' from FormData before posting to ?wc-ajax=rasa_add_to_cart"
    )
    woo_compat = open(os.path.join(ROOT, 'theme/rasa-kopi/inc/woo-compat.php'), 'r', encoding='utf-8').read()
    assert "remove_action( 'wp_loaded', array( 'WC_Form_Handler', 'add_to_cart_action' ), 20 )" in woo_compat, (
        'woo-compat.php must disable WC_Form_Handler::add_to_cart_action on wc-ajax/ajax requests'
    )
    assert re.search(r"woocommerce_add_to_cart_redirect.*?(?:\$_GET\['wc-ajax'\]|wp_doing_ajax)", woo_compat, re.S), (
        'woocommerce_add_to_cart_redirect must return false on wc-ajax/ajax requests'
    )
    print('AJAX add-to-cart 302-redirect & WC_Form_Handler collision guard: OK')

    print('\n=== 8. NOTICE HTML ENTITY & TAG LEAK GUARD ===')
    assert re.search(r'html_entity_decode\s*\(\s*wp_strip_all_tags\s*\(', func_src), (
        'functions.php rasa_ajax_add_to_cart must decode HTML entities from stripped notices to prevent &quot;/&amp; leaks'
    )
    assert 'decodeHtmlEntities' in shim_src and 'errBox.textContent = decodeHtmlEntities(msg)' in shim_src, (
        'rasa-shim.js must decode HTML entities before assigning to errBox.textContent'
    )
    import html
    test_notice = 'You cannot add &quot;Rasa Essential Tee - s&quot; to the cart because the product is out of stock.'
    decoded = html.unescape(test_notice)
    assert '&quot;' not in decoded and '"Rasa Essential Tee - s"' in decoded, 'Entity decoding logic failed'
    print('Notice HTML entity and tag stripping guards: OK')

    print('\n=== 9. PAYMENT ICONS, CURRENCY POSTFIX, REVOLUT BANNERS & SHIPPING SAFEGUARDS ===')
    icons_src = open(os.path.join(ROOT, 'theme/rasa-kopi/inc/payment-icons.php'), 'r', encoding='utf-8').read()
    for approved in ('pi-visa', 'pi-master', 'pi-maestro', 'pi-revolut_pay', 'pi-apple_pay', 'pi-google_pay', 'pi-paypal'):
        assert approved in icons_src, f'Missing approved payment icon {approved} in payment-icons.php'
    for disallowed in ('shopify_pay', 'pi-american_express', 'pi-unionpay', 'pi-klarna'):
        assert disallowed not in icons_src, f'Disallowed payment icon {disallowed} still present in payment-icons.php'

    # Currency formatting: € sign after numbers
    assert "number_format((float) $amount, 2, ',', '.') . ' €'" in func_src, 'functions.php rasa_format_eur must place € after numbers'
    tags_src = open(os.path.join(ROOT, 'theme/rasa-kopi/inc/template-tags.php'), 'r', encoding='utf-8').read()
    assert 'number_format' in tags_src and '&nbsp;&euro;</bdi>' in tags_src, 'template-tags.php price html must place € after numbers'
    assert "toFixed(2).replace('.', ',') + ' €'" in shim_src, 'rasa-shim.js formatEurShort must place € after numbers'

    # Revolut banners OFF in setup/settings.sh
    assert re.search(r"['\"]reward_banner['\"]\]\s*=\s*['\"]no['\"]", sh_src), 'Missing reward_banner = no in settings.sh'
    assert re.search(r"['\"]cashback_label['\"]\]\s*=\s*['\"]no['\"]", sh_src), 'Missing cashback_label = no in settings.sh'

    # Shipping & Base Germany guard in rasa-store-ops.php
    ops_src = open(os.path.join(ROOT, 'theme/rasa-kopi/inc/rasa-store-ops.php'), 'r', encoding='utf-8').read()
    assert 'cart_subtotal < 59.0' in ops_src and "unset( $rates[ $rate_id ] )" in ops_src, (
        'rasa-store-ops.php must enforce 4.90 flat rate and unset free shipping for carts under 59 €'
    )
    assert "update_option( 'woocommerce_default_country', 'DE' )" in ops_src, (
        'rasa-store-ops.php must enforce DE base country on init'
    )
    print('Payment icons, currency postfix, Revolut banner & shipping safeguards: OK')


if __name__ == '__main__':
    main()
