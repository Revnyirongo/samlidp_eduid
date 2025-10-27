#!/usr/bin/env bash
set -euo pipefail

if [[ ${1:-} == "" ]]; then
  echo "Usage: $0 <tenant-host> [additional-host ...]" >&2
  exit 1
fi

HOST_PRIMARY=$(echo "$1" | tr '[:upper:]' '[:lower:]')
shift || true

SAN_ENTRIES=()
SAN_INDEX=1
SAN_ENTRIES+=("DNS.${SAN_INDEX} = ${HOST_PRIMARY}")
SAN_INDEX=$((SAN_INDEX + 1))

for alt in "$@"; do
  alt_normalised=$(echo "$alt" | tr '[:upper:]' '[:lower:]')
  SAN_ENTRIES+=("DNS.${SAN_INDEX} = ${alt_normalised}")
  SAN_INDEX=$((SAN_INDEX + 1))
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${SCRIPT_DIR}/.."
CERT_ROOT="${PROJECT_ROOT}/certs/${HOST_PRIMARY}"

umask 077
mkdir -p "${CERT_ROOT}"

OPENSSL_CONFIG="$(mktemp)"
trap 'rm -f "${OPENSSL_CONFIG}"' EXIT

{
  echo "[ req ]"
  echo "default_bits = 2048"
  echo "prompt = no"
  echo "default_md = sha256"
  echo "x509_extensions = v3_req"
  echo "distinguished_name = dn"
  echo
  echo "[ dn ]"
  echo "CN = ${HOST_PRIMARY}"
  echo
  echo "[ v3_req ]"
  echo "subjectAltName = @san"
  echo
  echo "[ san ]"
  for entry in "${SAN_ENTRIES[@]}"; do
    echo "${entry}"
  done
} > "${OPENSSL_CONFIG}"

openssl req -x509 -nodes -newkey rsa:2048 \
  -keyout "${CERT_ROOT}/idp.key.pem" \
  -out "${CERT_ROOT}/idp.crt.pem" \
  -days 825 \
  -config "${OPENSSL_CONFIG}"

echo "Generated certificate: ${CERT_ROOT}/idp.crt.pem"
echo "Generated key: ${CERT_ROOT}/idp.key.pem"
