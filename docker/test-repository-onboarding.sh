#!/usr/bin/env sh
set -eu

seller_api="${SELLER_API_URL:-http://localhost:8082/api}"
temporary_dir=$(mktemp -d)

cleanup() {
    rm -rf "${temporary_dir}"
}
trap cleanup EXIT

fail() {
    echo "Repository onboarding test failed: $*" >&2
    exit 1
}

switch_release_fixture() {
    release_fixture="$1"
    docker compose exec -T --user root buyer cp \
        "/extension-mesh-registry/${release_fixture}" \
        /var/www/html/custom/plugins/ExtensionMesh/docker/registry/public/github-releases.json
}

random_id() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 16
    else
        hexdump -n 16 -e '16/1 "%02x"' /dev/urandom
    fi
}

token_for() {
    api_url="$1"
    response=$(curl -fsS -X POST "${api_url}/oauth/token" \
        -H 'Content-Type: application/json' \
        --data '{
            "grant_type": "password",
            "client_id": "administration",
            "scopes": "write",
            "username": "admin",
            "password": "shopware"
        }')
    printf '%s' "${response}" | jq -er '.access_token'
}

expect_status() {
    expected="$1"
    output="$2"
    shift 2
    status=$(curl -sS -o "${output}" -w '%{http_code}' "$@")
    if [ "${status}" != "${expected}" ]; then
        cat "${output}" >&2
        fail "expected HTTP ${expected}, got ${status}"
    fi
}

wait_for_repository() {
    wait_connection_id="$1"
    wait_output="$2"
    wait_attempts=0
    while [ "${wait_attempts}" -lt 30 ]; do
        wait_attempts=$((wait_attempts + 1))
        docker compose exec -T seller \
            bin/console messenger:consume async \
            --time-limit=10 --no-ansi --no-interaction >/dev/null
        curl -fsS -X POST \
            "${seller_api}/search/extension-mesh-repository-connection" \
            -H "${auth_header}" \
            -H 'Content-Type: application/json' \
            --data '{"ids":["'"${wait_connection_id}"'"]}' \
            | jq '.data[0] | {id} + .attributes' >"${wait_output}"
        wait_status=$(jq -r '.onboardingStatus // "missing"' "${wait_output}")
        if [ "${wait_status}" = "ready" ]; then
            return
        fi
        if [ "${wait_status}" = "failed" ]; then
            jq -r '.lastError' "${wait_output}" >&2
            fail "repository background processing failed"
        fi
    done

    fail "repository background processing did not finish"
}

find_publication() {
    publication_product_id="$1"
    publication_version="$2"
    publication_output="$3"
    publication_criteria=$(jq -nc \
        --arg productId "${publication_product_id}" \
        --arg version "${publication_version}" \
        '{page:1,limit:100,filter:[
            {type:"equals",field:"productId",value:$productId},
            {type:"equals",field:"technicalName",value:"AcmeDemoPlugin"},
            {type:"equals",field:"version",value:$version},
            {type:"equals",field:"validationError",value:null}
        ]}')
    curl -fsS -X POST \
        "${seller_api}/search/extension-mesh-published-release" \
        -H "${auth_header}" \
        -H 'Content-Type: application/json' \
        --data "${publication_criteria}" \
        | jq -e '[.data[] | {id} + .attributes]
            | sort_by(.metadata.releaseNotes == null)
            | .[0]' >"${publication_output}" \
        || fail "published release ${publication_version} was not found"
}

switch_release_fixture github-releases-v1.json

seller_token=$(token_for "${seller_api}")
auth_header="Authorization: Bearer ${seller_token}"

curl -fsS "${seller_api}/_action/extension-mesh/repositories/providers" -H "${auth_header}" \
    | jq -e '.data[] | select(
        .key == "github"
        and .label == "GitHub"
        and .defaultApiBaseUrl == "https://api.github.com"
    )' >/dev/null \
    || fail 'registered repository providers are not exposed to Administration'
# Keep the fixture repeatable after an interrupted previous run. Unlinking is
# intentionally non-destructive, so any previously created products remain.
curl -fsS -X POST "${seller_api}/search/extension-mesh-repository-connection" \
    -H "${auth_header}" \
    -H 'Content-Type: application/json' \
    --data '{"page":1,"limit":500}' \
    | jq -r '.data[]
        | select(
            .attributes.provider == "github"
            and (
                .attributes.repository == "acme/private-plugin"
                or .attributes.repository == "acme/public-plugin"
            )
        )
        | .id' \
    | while IFS= read -r stale_connection_id; do
        curl -fsS -X DELETE \
            "${seller_api}/_action/extension-mesh/repositories/${stale_connection_id}" \
            -H "${auth_header}"
    done

import_payload=$(jq -nc '{
    provider: "github",
    repository: "acme/private-plugin",
    apiBaseUrl: "http://registry/github-api",
    accessToken: "private-repo-token",
    mode: "import",
    productId: null
}')
expect_status 202 "${temporary_dir}/import.json" \
    -X POST "${seller_api}/_action/extension-mesh/repositories" \
    -H "${auth_header}" \
    -H 'Content-Type: application/json' \
    --data "${import_payload}"

jq -e '
    .data.provider == "github"
    and .data.repository == "acme/private-plugin"
    and .data.onboardingStatus == "queued"
    and .data.enabled == false
    and .data.technicalName == null
    and .data.hasCredential == true
    and (.data.credentialFingerprint | length) == 12
    and (has("credentialCiphertext") | not)
' "${temporary_dir}/import.json" >/dev/null \
    || fail 'the imported connection response is incomplete or leaks its token'

curl -fsS -X POST \
    "${seller_api}/search/extension-mesh-repository-connection" \
    -H "${auth_header}" \
    -H 'Content-Type: application/json' \
    --data '{"page":1,"limit":1}' \
    | jq -e '.meta.total >= 1 and (.data | length) == 1' \
    >/dev/null \
    || fail 'repository pagination metadata or page size is invalid'

connection_id=$(jq -er '.data.id' "${temporary_dir}/import.json")
wait_for_repository "${connection_id}" "${temporary_dir}/import-ready.json"
jq -e '
    .repositoryPrivate == true
    and .technicalName == "AcmeDemoPlugin"
    and .configPath == ".shopware-extension.yml"
    and .onboardingStatus == "ready"
    and .enabled == true
' "${temporary_dir}/import-ready.json" >/dev/null \
    || fail 'the queued repository import did not persist its completed state'
product_id=$(jq -er '.productId' "${temporary_dir}/import-ready.json")

public_payload=$(jq -nc --arg productId "${product_id}" '{
    provider: "github",
    repository: "acme/public-plugin",
    apiBaseUrl: "http://registry/github-api",
    accessToken: "",
    mode: "link",
    productId: $productId
}')
expect_status 202 "${temporary_dir}/public-link.json" \
    -X POST "${seller_api}/_action/extension-mesh/repositories" \
    -H "${auth_header}" \
    -H 'Content-Type: application/json' \
    --data "${public_payload}"
jq -e '
    .data.repository == "acme/public-plugin"
    and .data.onboardingStatus == "queued"
    and .data.hasCredential == false
    and .data.credentialFingerprint == null
' "${temporary_dir}/public-link.json" >/dev/null \
    || fail 'a public repository without optional metadata or a token could not be linked'
public_connection_id=$(jq -er '.data.id' "${temporary_dir}/public-link.json")
wait_for_repository "${public_connection_id}" "${temporary_dir}/public-link-ready.json"
jq -e '
    .repositoryPrivate == false
    and .configPath == null
    and .onboardingStatus == "ready"
' "${temporary_dir}/public-link-ready.json" >/dev/null \
    || fail 'the anonymous public repository background job did not finish'
expect_status 204 "${temporary_dir}/unlink-public.json" \
    -X DELETE "${seller_api}/_action/extension-mesh/repositories/${public_connection_id}" \
    -H "${auth_header}"

expect_status 200 "${temporary_dir}/product.json" \
    "${seller_api}/product/${product_id}" \
    -H "${auth_header}"
jq -e '
    .data.attributes.active == false
    and .data.attributes.type == "digital"
    and .data.attributes.shippingFree == true
    and .data.attributes.name == "ExtensionMesh Import"
    and .data.attributes.metaTitle == "ExtensionMesh Import - Sell extensions easily"
    and (.data.attributes.description | contains("<script>") | not)
    and (.data.attributes.price[0].gross == 0)
    and (.data.attributes.coverId | length) == 32
' "${temporary_dir}/product.json" >/dev/null \
    || fail 'the YAML-backed draft product was not created safely'

expect_status 200 "${temporary_dir}/product-relations.json" \
    -X POST "${seller_api}/search/product" \
    -H "${auth_header}" \
    -H 'Content-Type: application/json' \
    --data '{
        "ids":["'"${product_id}"'"],
        "associations":{"visibilities":{},"categories":{},"media":{}}
    }'
jq -e '.data[0].relationships.visibilities.data | length == 0' \
    "${temporary_dir}/product-relations.json" >/dev/null \
    || fail 'the imported draft unexpectedly has storefront visibility'
jq -e '.data[0].relationships.categories.data | length == 0' \
    "${temporary_dir}/product-relations.json" >/dev/null \
    || fail 'the imported draft unexpectedly has a category'
jq -e '.data[0].relationships.media.data | length == 2' \
    "${temporary_dir}/product-relations.json" >/dev/null \
    || fail 'the imported draft does not contain its icon and default-locale screenshot'

find_publication "${product_id}" "1.0.0" "${temporary_dir}/publication-v1.json"
jq -e '.metadata.releaseNotes
    == "Initial stable release.\n\n- Adds paid installation support."' \
    "${temporary_dir}/publication-v1.json" >/dev/null \
    || fail 'the initial GitHub release notes were not published'
curl -fsS -X POST \
    "${seller_api}/search/extension-mesh-published-release" \
    -H "${auth_header}" \
    -H 'Content-Type: application/json' \
    --data '{"page":1,"limit":1}' \
    | jq -e '.meta.total >= 1 and (.data | length) == 1' \
    >/dev/null \
    || fail 'publication pagination metadata or page size is invalid'

switch_release_fixture github-releases-v2.json
docker compose exec -T seller \
    bin/console scheduled-task:run-single extension_mesh.repository_sync \
    --no-ansi --no-interaction >/dev/null
wait_for_repository "${connection_id}" "${temporary_dir}/scheduled-sync-ready.json"

find_publication "${product_id}" "1.1.0" "${temporary_dir}/publication-v2.json"
jq -e '.metadata.releaseNotes
    == "Repository synchronization improvements.\n\n- Imports releases in background batches.\n- Adds storefront release notes."' \
    "${temporary_dir}/publication-v2.json" >/dev/null \
    || fail 'the updated GitHub release notes were not published'

expect_status 202 "${temporary_dir}/manual-sync.json" \
    -X POST "${seller_api}/_action/extension-mesh/repositories/${connection_id}/sync" \
    -H "${auth_header}" \
    -H 'Content-Type: application/json' \
    --data '{}'
jq -e '.data.onboardingStatus == "queued"' "${temporary_dir}/manual-sync.json" >/dev/null \
    || fail 'manual synchronization was not queued'
wait_for_repository "${connection_id}" "${temporary_dir}/manual-sync-ready.json"

expect_status 400 "${temporary_dir}/bad-token.json" \
    -X PUT "${seller_api}/_action/extension-mesh/repositories/${connection_id}/credential" \
    -H "${auth_header}" \
    -H 'Content-Type: application/json' \
    --data '{"accessToken":"invalid-private-repo-token"}'
expect_status 200 "${temporary_dir}/rotated-token.json" \
    -X PUT "${seller_api}/_action/extension-mesh/repositories/${connection_id}/credential" \
    -H "${auth_header}" \
    -H 'Content-Type: application/json' \
    --data '{"accessToken":"rotated-private-repo-token"}'
jq -e '.data.credentialFingerprint | length == 12' \
    "${temporary_dir}/rotated-token.json" >/dev/null \
    || fail 'the repository credential was not replaced'

expect_status 204 "${temporary_dir}/unlink.json" \
    -X DELETE "${seller_api}/_action/extension-mesh/repositories/${connection_id}" \
    -H "${auth_header}"
expect_status 200 "${temporary_dir}/preserved-product.json" \
    "${seller_api}/product/${product_id}" \
    -H "${auth_header}"

tax_id=$(curl -fsS "${seller_api}/tax?limit=1" -H "${auth_header}" | jq -er '.data[0].id')
linked_product_id=$(random_id)
linked_payload=$(jq -nc \
    --arg id "${linked_product_id}" \
    --arg taxId "${tax_id}" \
    '{
        id: $id,
        productNumber: ("EM-REPOSITORY-LINK-" + $id),
        stock: 10,
        active: false,
        name: "Repository link fixture",
        taxId: $taxId,
        type: "physical",
        price: [{currencyId: "b7d2554b0ce847cd82f3ac9bd1c0dfca", gross: 10, net: 8.4, linked: true}]
    }')
expect_status 204 "${temporary_dir}/create-linked-product.json" \
    -X POST "${seller_api}/product" \
    -H "${auth_header}" \
    -H 'Content-Type: application/json' \
    --data "${linked_payload}"

link_payload=$(jq -nc \
    --arg productId "${linked_product_id}" \
    '{
        provider: "github",
        repository: "acme/private-plugin",
        apiBaseUrl: "http://registry/github-api",
        accessToken: "rotated-private-repo-token",
        mode: "link",
        productId: $productId
    }')
expect_status 202 "${temporary_dir}/link.json" \
    -X POST "${seller_api}/_action/extension-mesh/repositories" \
    -H "${auth_header}" \
    -H 'Content-Type: application/json' \
    --data "${link_payload}"
jq -e '
    .data.productId == "'"${linked_product_id}"'"
    and .data.onboardingStatus == "queued"
' "${temporary_dir}/link.json" >/dev/null \
    || fail 'the repository was not linked to the selected product'
linked_connection_id=$(jq -er '.data.id' "${temporary_dir}/link.json")
wait_for_repository "${linked_connection_id}" "${temporary_dir}/link-ready.json"

expect_status 200 "${temporary_dir}/linked-product.json" \
    "${seller_api}/product/${linked_product_id}" \
    -H "${auth_header}"
jq -e '.data.attributes.type == "digital"' "${temporary_dir}/linked-product.json" >/dev/null \
    || fail 'the linked product was not made digital after importing releases'

expect_status 204 "${temporary_dir}/unlink-linked.json" \
    -X DELETE "${seller_api}/_action/extension-mesh/repositories/${linked_connection_id}" \
    -H "${auth_header}"
expect_status 200 "${temporary_dir}/preserved-linked-product.json" \
    "${seller_api}/product/${linked_product_id}" \
    -H "${auth_header}"

expect_status 204 "${temporary_dir}/delete-imported-product.json" \
    -X DELETE "${seller_api}/product/${product_id}" \
    -H "${auth_header}"
expect_status 204 "${temporary_dir}/delete-linked-product.json" \
    -X DELETE "${seller_api}/product/${linked_product_id}" \
    -H "${auth_header}"

echo "Repository import, scheduled sync, credential replacement and product linking passed."
