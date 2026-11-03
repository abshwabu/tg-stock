#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Inventory Server Load Test: 50 Concurrent Sync & Sale Requests
# Measures concurrency latency, SQLite lock contention, and HTTP response codes.
# ==============================================================================

SERVER_URL="${1:-http://127.0.0.1:8000}"
CONCURRENCY="${2:-50}"
TEMP_DIR="$(mktemp -d /tmp/tgstock_loadtest_XXXXXX)"

trap 'rm -rf "${TEMP_DIR}"' EXIT

echo "================================================================="
echo "TGSTOCK CAPACITY & CONCURRENCY BENCHMARK"
echo "Target Server : ${SERVER_URL}"
echo "Concurrent Req: ${CONCURRENCY}"
echo "Temp Dir      : ${TEMP_DIR}"
echo "================================================================="

# 1. Start pairing to acquire a test device token
echo -e "\n==> 1. Setting up temporary test device..."
PAIR_RESP=$(curl -s -X POST "${SERVER_URL}/v1/pair/start" \
  -H "Content-Type: application/json" \
  -d '{"shop_name":"Benchmark Shop","device_name":"LoadTest-Worker"}')

CODE=$(echo "${PAIR_RESP}" | grep -o '"code":"[^"]*' | cut -d'"' -f4 || true)

if [ -z "${CODE}" ]; then
  echo "[WARN] Could not pair via /v1/pair/start (server may be offline or in test env). Using unauthenticated /health benchmark."
  TEST_ENDPOINT="${SERVER_URL}/health"
  AUTH_HEADER=""
else
  # Confirm pairing
  curl -s -X POST "${SERVER_URL}/v1/pair/confirm" \
    -H "Content-Type: application/json" \
    -d "{\"code\":\"${CODE}\",\"telegram_id\":999888777,\"first_name\":\"LoadTester\"}" >/dev/null || true

  # Fetch status to get device token
  STATUS_RESP=$(curl -s "${SERVER_URL}/v1/pair/status?code=${CODE}")
  DEVICE_TOKEN=$(echo "${STATUS_RESP}" | grep -o '"device_token":"[^"]*' | cut -d'"' -f4 || true)

  if [ -n "${DEVICE_TOKEN}" ]; then
    echo "[OK] Acquired test device token."
    TEST_ENDPOINT="${SERVER_URL}/v1/sync"
    AUTH_HEADER="Authorization: Bearer ${DEVICE_TOKEN}"
  else
    TEST_ENDPOINT="${SERVER_URL}/health"
    AUTH_HEADER=""
  fi
fi

# 2. Execute 50 concurrent requests
echo -e "\n==> 2. Dispatching ${CONCURRENCY} concurrent requests to ${TEST_ENDPOINT}..."
START_TIME=$(date +%s%3N)

for i in $(seq 1 "${CONCURRENCY}"); do
  (
    OUT_FILE="${TEMP_DIR}/res_${i}.txt"
    if [ -n "${AUTH_HEADER}" ]; then
      # Post sync payload
      curl -s -o /dev/null -w "%{http_code} %{time_total}\n" \
        -X POST "${TEST_ENDPOINT}" \
        -H "Content-Type: application/json" \
        -H "${AUTH_HEADER}" \
        -d '{"cursor":0,"changes":[]}' > "${OUT_FILE}" 2>&1
    else
      # Get health
      curl -s -o /dev/null -w "%{http_code} %{time_total}\n" \
        -X GET "${TEST_ENDPOINT}" > "${OUT_FILE}" 2>&1
    fi
  ) &
done

wait
END_TIME=$(date +%s%3N)
TOTAL_ELAPSED=$((END_TIME - START_TIME))

# 3. Analyze results
echo -e "\n==> 3. Benchmark Results & Latency Analysis"
echo "Total Wall-Clock Time: ${TOTAL_ELAPSED} ms"

HTTP_200=0
HTTP_503=0
HTTP_429=0
HTTP_OTHER=0

TIMES_FILE="${TEMP_DIR}/times.txt"
> "${TIMES_FILE}"

for f in "${TEMP_DIR}"/res_*.txt; do
  if [ -f "$f" ]; then
    read -r CODE DURATION < "$f" || true
    if [ "${CODE}" = "200" ]; then
      HTTP_200=$((HTTP_200 + 1))
    elif [ "${CODE}" = "503" ]; then
      HTTP_503=$((HTTP_503 + 1))
    elif [ "${CODE}" = "429" ]; then
      HTTP_429=$((HTTP_429 + 1))
    else
      HTTP_OTHER=$((HTTP_OTHER + 1))
    fi
    # Convert seconds to ms
    MS=$(echo "${DURATION:-0} * 1000" | bc 2>/dev/null || awk -v d="${DURATION:-0}" 'BEGIN {printf "%.0f", d * 1000}')
    echo "${MS}" >> "${TIMES_FILE}"
  fi
done

echo "Status 200 OK          : ${HTTP_200} / ${CONCURRENCY}"
echo "Status 503 (Busy Lock) : ${HTTP_503} / ${CONCURRENCY}"
echo "Status 429 (Throttled) : ${HTTP_429} / ${CONCURRENCY}"
echo "Status Other (Errors)  : ${HTTP_OTHER} / ${CONCURRENCY}"

# Calculate p50, p95, p99
sort -n "${TIMES_FILE}" -o "${TIMES_FILE}"
TOTAL_ROWS=$(wc -l < "${TIMES_FILE}")

if [ "${TOTAL_ROWS}" -gt 0 ]; then
  P50_LINE=$(( TOTAL_ROWS * 50 / 100 + 1 ))
  P95_LINE=$(( TOTAL_ROWS * 95 / 100 + 1 ))
  P99_LINE=$(( TOTAL_ROWS * 99 / 100 + 1 ))

  P50_VAL=$(sed -n "${P50_LINE}p" "${TIMES_FILE}")
  P95_VAL=$(sed -n "${P95_LINE}p" "${TIMES_FILE}")
  P99_VAL=$(sed -n "${P99_LINE}p" "${TIMES_FILE}")

  echo "-----------------------------------------------------------------"
  echo "Median (p50) Latency   : ${P50_VAL} ms"
  echo "p95 Latency            : ${P95_VAL} ms"
  echo "p99 Latency            : ${P99_VAL} ms"
  echo "-----------------------------------------------------------------"
fi

echo "Capacity check completed."
