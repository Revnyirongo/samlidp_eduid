#!/usr/bin/env bash
set -euo pipefail

HOST="${1:-${POSTGRES_HOST:-db}}"
PORT="${2:-${POSTGRES_PORT:-5432}}"
TIMEOUT="${WAIT_FOR_DB_TIMEOUT:-60}"
SLEEP="${WAIT_FOR_DB_INTERVAL:-2}"

start_time=$(date +%s)

while true; do
  if command -v pg_isready >/dev/null 2>&1; then
    if pg_isready -h "${HOST}" -p "${PORT}" >/dev/null 2>&1; then
      break
    fi
  else
    if (echo > "/dev/tcp/${HOST}/${PORT}" >/dev/null 2>&1); then
      break
    fi
  fi

  current_time=$(date +%s)
  elapsed=$((current_time - start_time))
  if [ "${elapsed}" -ge "${TIMEOUT}" ]; then
    echo "Timed out waiting for database at ${HOST}:${PORT}" >&2
    exit 1
  fi

  echo "Waiting for database at ${HOST}:${PORT}..."
  sleep "${SLEEP}"

done

echo "Database is available at ${HOST}:${PORT}."
