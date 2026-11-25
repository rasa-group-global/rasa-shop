# CLAUDE.md – rasa-shop

Rasa Kopi online shop (WooCommerce) – shop.rasakopi.coffee. Owner: Osman. Maintained by Osman + Claude since 25 Nov 2026
(handed over from the previous dev team; repo moved from rasagroup-global/digitalCommerce).

## Rules
- Simple English in repo files. Talk to Osman in German.
- Own branch `shop-…`, pull request, `python3 tests/regression_check.py` must pass. Self-merge when green.
- **Push to `main` deploys the theme to LIVE** (GitHub Actions `deploy.yml`, rsync `--delete` of `theme/rasa-kopi/`).
  Never edit theme files on live via the WordPress editor – the next deploy overwrites them. Change them here.
- `plugins/rasa-shop-tools/` is our own plugin (withdrawal form, supplier order mails, storage locations).
  It is NOT deployed by CI yet – it is installed on live by hand. Keep the repo copy in sync.
- Never commit secrets (`.env`, `wp-config.php`, keys). Never change the live server by hand.
- Legal texts come from IT-Recht Kanzlei (plugin "Legal Text Connector"), not from this repo.
- Status notes live in the claude.ai project (`claude/shop-*.md`).
