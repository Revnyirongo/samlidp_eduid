#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${SCRIPT_DIR}/.."
CERT_DIR="${PROJECT_ROOT}/certs"

if [ -f "${PROJECT_ROOT}/.env" ]; then
  set -a
  # shellcheck disable=SC1090
  . "${PROJECT_ROOT}/.env"
  set +a
fi

mkdir -p "${CERT_DIR}"

COMMON_NAME=${SAML_IDP_COMMON_NAME:-localhost}

if ! command -v openssl >/dev/null 2>&1; then
  echo "openssl is required to generate certificates." >&2
  exit 1
fi

openssl req -x509 -nodes -newkey rsa:2048 \
  -keyout "${CERT_DIR}/idp.key" \
  -out "${CERT_DIR}/idp.crt" \
  -subj "/CN=${COMMON_NAME}" \
  -days 825 >/dev/null 2>&1

echo "Generated certificate at ${CERT_DIR}/idp.crt"
echo "Generated key at ${CERT_DIR}/idp.key"
