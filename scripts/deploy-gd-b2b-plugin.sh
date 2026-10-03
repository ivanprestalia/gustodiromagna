#!/usr/bin/env bash
# Carica il plugin gd-b2b dalla copia locale httpdocs sul VPS di produzione.
#
# L'IP del server deve coincidere con il DNS attuale:
#   dig +short gustodiromagna.com A
# (Chi pubblicava su 51.89.22.164 aggiornava un host diverso da quello del sito pubblico.)
#
# Esempio con password (installare: brew install hudochenkov/sshpass/sshpass):
#   export DEPLOY_HOST=162.19.153.199
#   export DEPLOY_USER=debian
#   export SSHPASS='...'
#   ./scripts/deploy-gd-b2b-plugin.sh
#
# Con chiave SSH (consigliato): ometti SSHPASS, usa ssh-agent.
#
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${ROOT}/httpdocs/wp-content/plugins/gd-b2b"
: "${DEPLOY_HOST:?Imposta DEPLOY_HOST (IP da dig gustodiromagna.com)}"
: "${DEPLOY_USER:?Imposta DEPLOY_USER}"

REMOTE_BASE="${DEPLOY_REMOTE_PATH:-/var/www/vhosts/gustodiromagna.com/httpdocs/wp-content/plugins/}"
REMOTE="${DEPLOY_USER}@${DEPLOY_HOST}:${REMOTE_BASE}gd-b2b/"

echo "Origine: ${SRC}/"
echo "Dest:    ${REMOTE}"

if [[ -n "${SSHPASS:-}" ]] && command -v sshpass >/dev/null 2>&1; then
  SSHPASS="${SSHPASS}" sshpass -e rsync -avz --human-readable \
    --rsync-path="sudo rsync" \
    -e "ssh -o StrictHostKeyChecking=accept-new" \
    "${SRC}/" "${REMOTE}"
else
  rsync -avz --human-readable \
    --rsync-path="sudo rsync" \
    -e "ssh -o StrictHostKeyChecking=accept-new" \
    "${SRC}/" "${REMOTE}"
fi

echo "--- Imposta proprietario (Plesk) ---"
if [[ -n "${SSHPASS:-}" ]] && command -v sshpass >/dev/null 2>&1; then
  SSHPASS="${SSHPASS}" sshpass -e ssh -o StrictHostKeyChecking=accept-new "${DEPLOY_USER}@${DEPLOY_HOST}" \
    "sudo chown -R gustodiromagna.com:psacln ${REMOTE_BASE}gd-b2b"
else
  ssh -o StrictHostKeyChecking=accept-new "${DEPLOY_USER}@${DEPLOY_HOST}" \
    "sudo chown -R gustodiromagna.com:psacln ${REMOTE_BASE}gd-b2b"
fi
echo "Fatto."
