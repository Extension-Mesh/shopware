#!/usr/bin/env sh
set -eu

buyer_api="http://127.0.0.1:${BUYER_HTTP_PORT:-8081}/api"
seller_api="http://127.0.0.1:${SELLER_HTTP_PORT:-8082}/api"
temporary_dir=$(mktemp -d)

cleanup() {
    rm -rf "${temporary_dir}"
}
trap cleanup EXIT

fail() {
    echo "Interaction test failed: $1" >&2
    exit 1
}

token_for() {
    api_url=$1
    curl -fsS \
        -X POST "${api_url}/oauth/token" \
        -H 'Content-Type: application/json' \
        --data '{"client_id":"administration","grant_type":"password","scopes":"write","username":"admin","password":"shopware"}' \
        | jq -er '.access_token'
}

clear_sources() {
    api_url=$1
    access_token=$2

    curl -fsS -X POST "${api_url}/search/extension-mesh-registry-source" \
        -H "Authorization: Bearer ${access_token}" \
        -H 'Content-Type: application/json' \
        --data '{"page":1,"limit":500}' \
        | jq -r '.data[].id' \
        | while IFS= read -r source_id; do
            curl -fsS \
                -X DELETE \
                "${api_url}/_action/extension-mesh/registries/${source_id}" \
                -H "Authorization: Bearer ${access_token}" \
                >/dev/null
        done
}

expect_status() {
    expected=$1
    output_file=$2
    shift 2

    actual=$(curl -sS -o "${output_file}" -w '%{http_code}' "$@")
    if [ "${actual}" != "${expected}" ]; then
        sed -n '1,80p' "${output_file}" >&2
        fail "expected HTTP ${expected}, got ${actual}"
    fi
}

buyer_token=$(token_for "${buyer_api}")
seller_token=$(token_for "${seller_api}")

expect_status 401 "${temporary_dir}/unauthorized.json" \
    "${buyer_api}/_action/extension-mesh/extensions"

clear_sources "${buyer_api}" "${buyer_token}"
clear_sources "${seller_api}" "${seller_token}"

installed_json=$(curl -fsS "${buyer_api}/_action/extension/installed" -H "Authorization: Bearer ${buyer_token}")
if printf '%s' "${installed_json}" | jq -e '.[] | select(.name == "AcmeDemoPlugin" and .installedAt != null)' >/dev/null; then
    expect_status 204 "${temporary_dir}/uninstall.json" \
        -X POST "${buyer_api}/_action/extension/uninstall/plugin/AcmeDemoPlugin" \
        -H "Authorization: Bearer ${buyer_token}" \
        -H 'Content-Type: application/json' \
        --data '{}'
fi
if printf '%s' "${installed_json}" | jq -e '.[] | select(.name == "AcmeDemoPlugin")' >/dev/null; then
    expect_status 204 "${temporary_dir}/remove.json" \
        -X POST "${buyer_api}/_action/extension/remove/plugin/AcmeDemoPlugin" \
        -H "Authorization: Bearer ${buyer_token}" \
        -H 'Content-Type: application/json' \
        --data '{}'
fi

docker compose exec -T buyer bin/console plugin:zip-import \
    /extension-mesh-registry/AcmeDemoPlugin-1.0.0.zip --no-ansi >/dev/null
docker compose exec -T buyer bin/console plugin:install \
    --activate AcmeDemoPlugin --no-ansi >/dev/null

baseline=$(docker compose exec -T buyer bin/console plugin:list --format=json --no-ansi \
    | jq -er '.[] | select(.name == "AcmeDemoPlugin") | select(.version == "1.0.0" and .active == true) | .version')
[ "${baseline}" = "1.0.0" ] || fail 'buyer baseline plugin is not active at 1.0.0'

expect_status 400 "${temporary_dir}/invalid-registry.json" \
    -X POST "${seller_api}/_action/extension-mesh/registries" \
    -H "Authorization: Bearer ${seller_token}" \
    -H 'Content-Type: application/json' \
    --data '{"url":"http://registry/invalid-registry.json"}'

expect_status 201 "${temporary_dir}/tampered-source.json" \
    -X POST "${seller_api}/_action/extension-mesh/registries" \
    -H "Authorization: Bearer ${seller_token}" \
    -H 'Content-Type: application/json' \
    --data '{"url":"http://registry/tampered-registry.json"}'
tampered_id=$(jq -er '.id' "${temporary_dir}/tampered-source.json")
expect_status 400 "${temporary_dir}/tampered-download.json" \
    -X POST "${seller_api}/_action/extension-mesh/download/${tampered_id}/AcmeDemoPlugin" \
    -H "Authorization: Bearer ${seller_token}" \
    -H 'Content-Type: application/json' \
    --data '{}'
jq -e '.errors[0].detail | contains("SHA-256 digest does not match")' \
    "${temporary_dir}/tampered-download.json" >/dev/null \
    || fail 'tampered artifact was not rejected for its digest'
expect_status 204 "${temporary_dir}/remove-tampered.json" \
    -X DELETE "${seller_api}/_action/extension-mesh/registries/${tampered_id}" \
    -H "Authorization: Bearer ${seller_token}"

expect_status 201 "${temporary_dir}/buyer-source.json" \
    -X POST "${buyer_api}/_action/extension-mesh/registries" \
    -H "Authorization: Bearer ${buyer_token}" \
    -H 'Content-Type: application/json' \
    --data '{"url":"http://registry/registry.json"}'
registry_id=$(jq -er '.id' "${temporary_dir}/buyer-source.json")

catalog=$(curl -fsS "${buyer_api}/_action/extension-mesh/extensions?locale=en-GB" \
    -H "Authorization: Bearer ${buyer_token}")
printf '%s' "${catalog}" \
    | jq -e '.data.extensions[] | select(
        .name == "AcmeDemoPlugin"
        and .latestVersion == "1.1.0"
        and .extensionMesh.registryId == "'"${registry_id}"'"
        and .extensionMesh.conflict == false
    )' >/dev/null \
    || fail 'buyer catalog did not expose the 1.1.0 update candidate'

expect_status 204 "${temporary_dir}/prepare.json" \
    -X POST "${buyer_api}/_action/extension-mesh/download/${registry_id}/AcmeDemoPlugin" \
    -H "Authorization: Bearer ${buyer_token}" \
    -H 'Content-Type: application/json' \
    --data '{}'
expect_status 204 "${temporary_dir}/update.json" \
    -X POST "${buyer_api}/_action/extension/update/plugin/AcmeDemoPlugin" \
    -H "Authorization: Bearer ${buyer_token}" \
    -H 'Content-Type: application/json' \
    --data '{}'

updated=$(docker compose exec -T buyer bin/console plugin:list --format=json --no-ansi \
    | jq -er '.[] | select(.name == "AcmeDemoPlugin") | select(.version == "1.1.0" and .active == true) | .version')
[ "${updated}" = "1.1.0" ] || fail 'native Shopware update did not leave 1.1.0 active'

seller_sources=$(curl -fsS -X POST "${seller_api}/search/extension-mesh-registry-source" \
    -H "Authorization: Bearer ${seller_token}" \
    -H 'Content-Type: application/json' \
    --data '{"page":1,"limit":500}' \
    | jq -er '.data | length')
[ "${seller_sources}" = "0" ] || fail 'buyer and seller registry state is not isolated'

echo "Interaction test passed: auth, isolation, invalid metadata, digest rejection, discovery and native update."
