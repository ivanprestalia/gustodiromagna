#!/usr/bin/env bash
# Controlli rapidi su produzione (stessi DEPLOY_* del deploy plugin).
#
#   export DEPLOY_HOST=162.19.153.199
#   export DEPLOY_USER=debian
#   # opzionale — invia email di prova dal server (GD-B2B ≥ 1.2.8):
#   export VERIFY_MAIL_TEST_TO=latua@email.it
#   ./scripts/verify-wp-mail-remote.sh
#
set -euo pipefail
: "${DEPLOY_HOST:?Imposta DEPLOY_HOST}"
: "${DEPLOY_USER:?Imposta DEPLOY_USER}"

VERIFY_MAIL_TEST_TO_LOCAL="${VERIFY_MAIL_TEST_TO:-}"

SSH=(ssh -o StrictHostKeyChecking=accept-new "${DEPLOY_USER}@${DEPLOY_HOST}")

"${SSH[@]}" env VERIFY_MAIL_TEST_TO_REMOTE="$VERIFY_MAIL_TEST_TO_LOCAL" bash <<'REMOTE_EOF'
set +e
BASE=/var/www/vhosts/gustodiromagna.com
WP="$BASE/httpdocs"
PATH_CLI="/opt/plesk/php/8.3/bin:/usr/local/bin:/usr/bin:/sbin:/bin"

echo "=== Host / utente ==="
hostname
whoami || true

echo ""
echo "=== wp-config: GD_B2B_MAIL_DEBUG / WPMS / WP_DEBUG ==="
if sudo test -r "$WP/wp-config.php"; then
	sudo grep -nE "GD_B2B_MAIL_DEBUG|WPMS_|define\s*\(\s*['\"]?(WP_DEBUG)" "$WP/wp-config.php" | head -40 || true
else
	echo "wp-config non leggibile senza sudo"
fi

echo ""
echo "=== Plugin GD-B2B (versione sul disco) ==="
if sudo test -f "$WP/wp-content/plugins/gd-b2b/gd-b2b.php"; then
	sudo grep -hE "^\s*\* Version:|GD_B2B_VERSION" "$WP/wp-content/plugins/gd-b2b/gd-b2b.php" | head -3
else
	echo "gd-b2b.php non trovato"
fi

echo ""
echo "=== Log: GD B2B / mail (sudo, ultime 30) ==="
for f in "$BASE/logs/error_log" "$WP/wp-content/debug.log"; do
	if sudo test -r "$f"; then
		echo "--- $f ---"
		sudo grep -E "GD B2B|wp_mail_failed|PHPMailer" "$f" 2>/dev/null | tail -30 || true
	fi
done

echo ""
echo "=== WP-CLI wp_mail_smtp sniffer ==="
sudo -u gustodiromagna.com env PATH="$PATH_CLI" /usr/local/bin/wp option get wp_mail_smtp --path="$WP" --format=json 2>&1 | head -c 4000
echo
sudo -u gustodiromagna.com env PATH="$PATH_CLI" /usr/local/bin/wp eval '$o=get_option("wp_mail_smtp",array()); echo "mailer=".($o["mail"]["mailer"]??"?")."\n"; echo "smtp_host=".($o["smtp"]["host"]??"")."\n"; echo "do_not_send=".(isset($o["general"]["do_not_send"])?(int)$o["general"]["do_not_send"]:"")."\n";' --path="$WP" 2>&1 || echo "wp eval fallito"

echo ""
if [[ -n "${VERIFY_MAIL_TEST_TO_REMOTE:-}" ]]; then
	echo "=== wp gd-b2b mail-test => ${VERIFY_MAIL_TEST_TO_REMOTE} ==="
	sudo -u gustodiromagna.com env PATH="$PATH_CLI" /usr/local/bin/wp gd-b2b mail-test --to="${VERIFY_MAIL_TEST_TO_REMOTE}" --show-sendmail-path=yes --path="$WP" 2>&1
else
	echo "=== wp gd-b2b mail-test (saltato: esporta VERIFY_MAIL_TEST_TO in locale) ==="
fi
REMOTE_EOF
