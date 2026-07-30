#!/usr/bin/env sh
set -eu

attempt=0
max_attempts=120

while [ "${attempt}" -lt "${max_attempts}" ]; do
    buyer_id=$(docker compose ps -q buyer)
    seller_id=$(docker compose ps -q seller)

    if [ -n "${buyer_id}" ] && [ -n "${seller_id}" ]; then
        buyer_status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "${buyer_id}")
        seller_status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "${seller_id}")

        if [ "${buyer_status}" = "healthy" ] && [ "${seller_status}" = "healthy" ]; then
            echo "Buyer and seller Dockware instances are ready."
            exit 0
        fi

        case "${buyer_status}:${seller_status}" in
            *exited*|*dead*)
                echo "A Dockware instance stopped during initialization." >&2
                exit 1
                ;;
        esac
    fi

    attempt=$((attempt + 1))
    sleep 5
done

echo "Dockware did not become ready within 10 minutes." >&2
exit 1
