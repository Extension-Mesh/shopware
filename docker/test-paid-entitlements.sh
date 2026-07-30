#!/usr/bin/env sh
set -eu

buyer_api="http://127.0.0.1:${BUYER_HTTP_PORT:-8081}/api"
seller_port="${SELLER_HTTP_PORT:-8082}"
seller_api="http://127.0.0.1:${seller_port}/api"
seller_storefront="http://localhost:${seller_port}"
temporary_dir=$(mktemp -d)

cleanup() {
    rm -r "${temporary_dir}"
}
trap cleanup EXIT

fail() {
    echo "Paid entitlement test failed: $1" >&2
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

expect_status() {
    expected=$1
    output_file=$2
    shift 2

    actual=$(curl -sS -o "${output_file}" -w '%{http_code}' "$@")
    if [ "${actual}" != "${expected}" ]; then
        sed -n '1,100p' "${output_file}" >&2
        fail "${output_file}: expected HTTP ${expected}, got ${actual}"
    fi
}

random_id() {
    openssl rand -hex 16
}

upload_private_zip() {
    media_id=$1
    file_name=$2
    file_path=$3

    expect_status 204 "${temporary_dir}/create-media.json" \
        -X POST "${seller_api}/media" \
        -H "Authorization: Bearer ${seller_admin_token}" \
        -H 'Content-Type: application/json' \
        --data "{\"id\":\"${media_id}\",\"private\":true}"

    expect_status 204 "${temporary_dir}/upload-media.json" \
        -X POST "${seller_api}/_action/media/${media_id}/upload?extension=zip&fileName=${file_name}" \
        -H "Authorization: Bearer ${seller_admin_token}" \
        -H 'Content-Type: application/octet-stream' \
        --data-binary "@${file_path}"
}

clear_buyer_sources() {
    curl -fsS "${buyer_api}/_action/extension-mesh/registries" \
        -H "Authorization: Bearer ${buyer_admin_token}" \
        | jq -r '.data[].id' \
        | while IFS= read -r source_id; do
            expect_status 204 "${temporary_dir}/remove-source.json" \
                -X DELETE "${buyer_api}/_action/extension-mesh/registries/${source_id}" \
                -H "Authorization: Bearer ${buyer_admin_token}"
        done
}

reset_buyer_plugin() {
    installed_json=$(curl -fsS "${buyer_api}/_action/extension/installed" \
        -H "Authorization: Bearer ${buyer_admin_token}")

    if printf '%s' "${installed_json}" | jq -e \
        '.[] | select(.name == "AcmeDemoPlugin" and .installedAt != null)' >/dev/null; then
        expect_status 204 "${temporary_dir}/uninstall.json" \
            -X POST "${buyer_api}/_action/extension/uninstall/plugin/AcmeDemoPlugin" \
            -H "Authorization: Bearer ${buyer_admin_token}" \
            -H 'Content-Type: application/json' \
            --data '{}'
    fi
    if printf '%s' "${installed_json}" | jq -e \
        '.[] | select(.name == "AcmeDemoPlugin")' >/dev/null; then
        expect_status 204 "${temporary_dir}/remove-plugin.json" \
            -X POST "${buyer_api}/_action/extension/remove/plugin/AcmeDemoPlugin" \
            -H "Authorization: Bearer ${buyer_admin_token}" \
            -H 'Content-Type: application/json' \
            --data '{}'
    fi

    docker compose exec -T buyer bin/console plugin:zip-import \
        /extension-mesh-registry/AcmeDemoPlugin-1.0.0.zip --no-ansi >/dev/null
    docker compose exec -T buyer bin/console plugin:install \
        --activate AcmeDemoPlugin --no-ansi >/dev/null
}

seller_admin_token=$(token_for "${seller_api}")
buyer_admin_token=$(token_for "${buyer_api}")

sales_channels=$(curl -fsS "${seller_api}/sales-channel?limit=20" \
    -H "Authorization: Bearer ${seller_admin_token}")
sales_channel_id=$(printf '%s' "${sales_channels}" | jq -er \
    '.data[] | select(.attributes.typeId == "8a243080f92e4c719546314b577cf82b") | .id' \
    | head -1)
sales_channel_access_key=$(printf '%s' "${sales_channels}" | jq -er \
    '.data[] | select(.id == "'"${sales_channel_id}"'") | .attributes.accessKey')

domains=$(curl -fsS "${seller_api}/sales-channel-domain?limit=50" \
    -H "Authorization: Bearer ${seller_admin_token}")
primary_domain_id=$(printf '%s' "${domains}" | jq -er \
    '.data[] | select(.attributes.salesChannelId == "'"${sales_channel_id}"'") | .id' \
    | head -1)
language_id=$(printf '%s' "${domains}" | jq -er \
    '.data[] | select(.id == "'"${primary_domain_id}"'") | .attributes.languageId')
currency_id=$(printf '%s' "${domains}" | jq -er \
    '.data[] | select(.id == "'"${primary_domain_id}"'") | .attributes.currencyId')
snippet_set_id=$(printf '%s' "${domains}" | jq -er \
    '.data[] | select(.id == "'"${primary_domain_id}"'") | .attributes.snippetSetId')

expect_status 204 "${temporary_dir}/public-domain.json" \
    -X PATCH "${seller_api}/sales-channel-domain/${primary_domain_id}" \
    -H "Authorization: Bearer ${seller_admin_token}" \
    -H 'Content-Type: application/json' \
    --data "{\"url\":\"${seller_storefront}\"}"

if ! printf '%s' "${domains}" | jq -e \
    '.data[] | select(.attributes.salesChannelId == "'"${sales_channel_id}"'" and .attributes.url == "http://seller")' \
    >/dev/null; then
    internal_domain_id=$(random_id)
    internal_domain=$(jq -nc \
        --arg id "${internal_domain_id}" \
        --arg salesChannelId "${sales_channel_id}" \
        --arg languageId "${language_id}" \
        --arg currencyId "${currency_id}" \
        --arg snippetSetId "${snippet_set_id}" \
        '{
            id: $id,
            url: "http://seller",
            salesChannelId: $salesChannelId,
            languageId: $languageId,
            currencyId: $currencyId,
            snippetSetId: $snippetSetId
        }')
    expect_status 204 "${temporary_dir}/internal-domain.json" \
        -X POST "${seller_api}/sales-channel-domain" \
        -H "Authorization: Bearer ${seller_admin_token}" \
        -H 'Content-Type: application/json' \
        --data "${internal_domain}"
fi

docker compose exec -T seller bin/console cache:clear --no-warmup --no-ansi >/dev/null

product_id=$(random_id)
normal_product_id=$(random_id)
normal_download_id=$(random_id)
download_v1_id=$(random_id)
download_v2_id=$(random_id)
media_v1_id=$(random_id)
media_v2_id=$(random_id)
tax_id=$(curl -fsS "${seller_api}/tax?limit=1" \
    -H "Authorization: Bearer ${seller_admin_token}" | jq -er '.data[0].id')

upload_private_zip \
    "${media_v1_id}" \
    "AcmeDemoPlugin-1.0.0" \
    "docker/registry/public/AcmeDemoPlugin-1.0.0.zip"
upload_private_zip \
    "${media_v2_id}" \
    "AcmeDemoPlugin-1.1.0" \
    "docker/registry/public/AcmeDemoPlugin-1.1.0.zip"

product_payload=$(jq -nc \
    --arg id "${product_id}" \
    --arg taxId "${tax_id}" \
    --arg currencyId "${currency_id}" \
    --arg salesChannelId "${sales_channel_id}" \
    --arg downloadId "${download_v1_id}" \
    --arg mediaId "${media_v1_id}" \
    '{
        id: $id,
        productNumber: ("EM-PAID-FIXTURE-" + $id),
        stock: 999,
        active: true,
        name: "ExtensionMesh paid fixture",
        taxId: $taxId,
        type: "digital",
        shippingFree: true,
        price: [{currencyId: $currencyId, gross: 119, net: 100, linked: true}],
        visibilities: [{salesChannelId: $salesChannelId, visibility: 30}],
        downloads: [{id: $downloadId, mediaId: $mediaId, position: 1}]
    }')
expect_status 204 "${temporary_dir}/create-product.json" \
    -X POST "${seller_api}/product" \
    -H "Authorization: Bearer ${seller_admin_token}" \
    -H 'Content-Type: application/json' \
    --data "${product_payload}"

normal_product_payload=$(jq -nc \
    --arg id "${normal_product_id}" \
    --arg taxId "${tax_id}" \
    --arg currencyId "${currency_id}" \
    --arg downloadId "${normal_download_id}" \
    --arg mediaId "${media_v1_id}" \
    '{
        id: $id,
        productNumber: ("NORMAL-DIGITAL-FIXTURE-" + $id),
        stock: 1,
        active: false,
        name: "Normal digital fixture",
        taxId: $taxId,
        type: "digital",
        shippingFree: true,
        price: [{currencyId: $currencyId, gross: 1.19, net: 1, linked: true}],
        downloads: [{id: $downloadId, mediaId: $mediaId, position: 1}]
    }')
expect_status 204 "${temporary_dir}/create-normal-product.json" \
    -X POST "${seller_api}/product" \
    -H "Authorization: Bearer ${seller_admin_token}" \
    -H 'Content-Type: application/json' \
    --data "${normal_product_payload}"

expect_status 200 "${temporary_dir}/connect-product.json" \
    -X PUT "${seller_api}/_action/extension-mesh/products/${product_id}/integration" \
    -H "Authorization: Bearer ${seller_admin_token}" \
    -H 'Content-Type: application/json' \
    --data '{"enabled":true}'
jq -e '.data.enabled == true and .data.source == "manual"' \
    "${temporary_dir}/connect-product.json" >/dev/null \
    || fail 'the manually uploaded product was not connected to Extension Mesh'

expect_status 200 "${temporary_dir}/publication-v1.json" \
    "${seller_api}/_action/extension-mesh/publication?page=999999&limit=100" \
    -H "Authorization: Bearer ${seller_admin_token}"
jq -e '.data[] | select(
    .productId == "'"${product_id}"'"
    and .technicalName == "AcmeDemoPlugin"
    and .version == "1.0.0"
    and .validationError == null
)' "${temporary_dir}/publication-v1.json" >/dev/null \
    || fail 'the first digital-product ZIP was not published'
jq -e '[.data[] | select(.productId == "'"${normal_product_id}"'")] | length == 0' \
    "${temporary_dir}/publication-v1.json" >/dev/null \
    || fail 'a normal digital product was published without explicit Extension Mesh opt-in'

expect_status 200 "${temporary_dir}/product-downloads-v1.json" \
    "${seller_api}/_action/extension-mesh/products/${product_id}/downloads?page=1&limit=10" \
    -H "Authorization: Bearer ${seller_admin_token}"
jq -e '.total == 1
    and (.items | length) == 1
    and .items[0].version == "1.0.0"
    and .items[0].shopware == "~6.7.0"
    and .items[0].publicationStatus == "published"' \
    "${temporary_dir}/product-downloads-v1.json" >/dev/null \
    || fail 'the paginated product download catalog did not expose release metadata'

context_headers="${temporary_dir}/context-headers.txt"
curl -fsS -D "${context_headers}" "${seller_storefront}/store-api/context" \
    -H "sw-access-key: ${sales_channel_access_key}" >/dev/null
context_token=$(awk 'BEGIN{IGNORECASE=1} /^sw-context-token:/ {gsub("\r", "", $2); print $2}' \
    "${context_headers}")
country_id=$(curl -fsS "${seller_storefront}/store-api/country" \
    -H "sw-access-key: ${sales_channel_access_key}" \
    -H "sw-context-token: ${context_token}" \
    | jq -er '.elements[] | select(.iso == "DE") | .id')
buyer_email="mesh-paid-$(date +%s)-$(random_id)@example.test"
registration=$(jq -nc \
    --arg email "${buyer_email}" \
    --arg countryId "${country_id}" \
    --arg storefrontUrl "${seller_storefront}" \
    '{
        firstName: "Paid",
        lastName: "Fixture",
        email: $email,
        password: "Testpass123!",
        storefrontUrl: $storefrontUrl,
        billingAddress: {
            firstName: "Paid",
            lastName: "Fixture",
            street: "Teststrasse 1",
            zipcode: "10115",
            city: "Berlin",
            countryId: $countryId
        }
    }')
registration_headers="${temporary_dir}/registration-headers.txt"
expect_status 200 "${temporary_dir}/registration.json" \
    -D "${registration_headers}" \
    -X POST "${seller_storefront}/store-api/account/register" \
    -H "sw-access-key: ${sales_channel_access_key}" \
    -H "sw-context-token: ${context_token}" \
    -H 'Content-Type: application/json' \
    --data "${registration}"
registered_context=$(awk 'BEGIN{IGNORECASE=1} /^sw-context-token:/ {gsub("\r", "", $2); print $2}' \
    "${registration_headers}")
if [ -n "${registered_context}" ]; then
    context_token="${registered_context}"
fi

expect_status 200 "${temporary_dir}/cart.json" \
    -X POST "${seller_storefront}/store-api/checkout/cart/line-item" \
    -H "sw-access-key: ${sales_channel_access_key}" \
    -H "sw-context-token: ${context_token}" \
    -H 'Content-Type: application/json' \
    --data "{\"items\":[{\"type\":\"product\",\"referencedId\":\"${product_id}\",\"quantity\":1}]}"
expect_status 200 "${temporary_dir}/order.json" \
    -X POST "${seller_storefront}/store-api/checkout/order" \
    -H "sw-access-key: ${sales_channel_access_key}" \
    -H "sw-context-token: ${context_token}" \
    -H 'Content-Type: application/json' \
    --data '{}'
transaction_id=$(jq -er '.primaryOrderTransaction.id' "${temporary_dir}/order.json")
ordered_download_id=$(jq -er '.lineItems[] | select(.productId == "'"${product_id}"'") | .downloads[0].id' \
    "${temporary_dir}/order.json")

expect_status 200 "${temporary_dir}/paid.json" \
    -X POST "${seller_api}/_action/state-machine/order_transaction/${transaction_id}/state/paid" \
    -H "Authorization: Bearer ${seller_admin_token}" \
    -H 'Content-Type: application/json' \
    --data '{}'
granted=$(curl -fsS "${seller_api}/order-line-item-download/${ordered_download_id}" \
    -H "Authorization: Bearer ${seller_admin_token}" \
    | jq -er '.data.attributes.accessGranted')
[ "${granted}" = "true" ] || fail 'Shopware did not grant the paid digital-product download'

expect_status 200 "${temporary_dir}/access.json" \
    "${seller_storefront}/store-api/extension-mesh/access" \
    -H "sw-access-key: ${sales_channel_access_key}" \
    -H "sw-context-token: ${context_token}"
access_token=$(jq -er '.accessToken' "${temporary_dir}/access.json")
[ -n "${access_token}" ] || fail 'the paid customer account did not receive a registry access token'

expect_status 401 "${temporary_dir}/anonymous-registry.json" \
    "${seller_storefront}/extension-mesh/v1/registry"
expect_status 200 "${temporary_dir}/registry-v1.json" \
    "${seller_storefront}/extension-mesh/v1/registry" \
    -H "Authorization: Bearer ${access_token}"
jq -e '.extensions[0].releases | length == 1 and .[0].version == "1.0.0"' \
    "${temporary_dir}/registry-v1.json" >/dev/null \
    || fail 'the buyer saw a release that was not attached at purchase time'

expect_status 204 "${temporary_dir}/add-v2.json" \
    -X PATCH "${seller_api}/product/${product_id}" \
    -H "Authorization: Bearer ${seller_admin_token}" \
    -H 'Content-Type: application/json' \
    --data "{\"downloads\":[{\"id\":\"${download_v2_id}\",\"mediaId\":\"${media_v2_id}\",\"position\":2}]}"

for request_number in 1 2 3 4; do
    curl -fsS \
        "${seller_storefront}/extension-mesh/v1/registry" \
        -H "Authorization: Bearer ${access_token}" \
        -o "${temporary_dir}/concurrent-registry-${request_number}.json" &
done
wait
for request_number in 1 2 3 4; do
    jq -e '.extensions[0].releases[] | select(.version == "1.1.0")' \
        "${temporary_dir}/concurrent-registry-${request_number}.json" >/dev/null \
        || fail 'a concurrent first registry refresh did not publish version 1.1.0'
done

expect_status 200 "${temporary_dir}/registry-v2.json" \
    "${seller_storefront}/extension-mesh/v1/registry" \
    -H "Authorization: Bearer ${access_token}"
artifact_url=$(jq -er '.extensions[0].releases[] | select(.version == "1.1.0") | .downloadUrl' \
    "${temporary_dir}/registry-v2.json")
expected_digest=$(jq -er '.extensions[0].releases[] | select(.version == "1.1.0") | .sha256' \
    "${temporary_dir}/registry-v2.json")
expect_status 200 "${temporary_dir}/paid-artifact.zip" \
    "${artifact_url}" \
    -H "Authorization: Bearer ${access_token}"
actual_digest=$(shasum -a 256 "${temporary_dir}/paid-artifact.zip" | awk '{print $1}')
[ "${actual_digest}" = "${expected_digest}" ] \
    || fail 'the entitled artifact digest does not match the published release'

clear_buyer_sources
reset_buyer_plugin
source_payload=$(jq -nc --arg accessToken "${access_token}" \
    '{url: "http://seller/extension-mesh/v1/registry", accessToken: $accessToken}')
expect_status 201 "${temporary_dir}/buyer-source.json" \
    -X POST "${buyer_api}/_action/extension-mesh/registries" \
    -H "Authorization: Bearer ${buyer_admin_token}" \
    -H 'Content-Type: application/json' \
    --data "${source_payload}"
registry_id=$(jq -er '.id' "${temporary_dir}/buyer-source.json")

expect_status 200 "${temporary_dir}/buyer-sources.json" \
    "${buyer_api}/_action/extension-mesh/registries" \
    -H "Authorization: Bearer ${buyer_admin_token}"
jq -e '.data[] | select(
    .id == "'"${registry_id}"'"
    and .hasCredential == true
    and (.credentialFingerprint | length == 12)
    and (has("accessToken") | not)
)' "${temporary_dir}/buyer-sources.json" >/dev/null \
    || fail 'the buyer did not store the registry credential safely'

expect_status 200 "${temporary_dir}/buyer-catalog.json" \
    "${buyer_api}/_action/extension-mesh/extensions?locale=en-GB" \
    -H "Authorization: Bearer ${buyer_admin_token}"
jq -e '.data.extensions[] | select(
    .name == "AcmeDemoPlugin"
    and .latestVersion == "1.1.0"
    and .extensionMesh.registryId == "'"${registry_id}"'"
)' "${temporary_dir}/buyer-catalog.json" >/dev/null \
    || fail 'the active buyer entitlement did not expose the update'

expect_status 204 "${temporary_dir}/prepare.json" \
    -X POST "${buyer_api}/_action/extension-mesh/download/${registry_id}/AcmeDemoPlugin" \
    -H "Authorization: Bearer ${buyer_admin_token}" \
    -H 'Content-Type: application/json' \
    --data '{}'
expect_status 204 "${temporary_dir}/update.json" \
    -X POST "${buyer_api}/_action/extension/update/plugin/AcmeDemoPlugin" \
    -H "Authorization: Bearer ${buyer_admin_token}" \
    -H 'Content-Type: application/json' \
    --data '{}'
updated=$(docker compose exec -T buyer bin/console plugin:list --format=json --no-ansi \
    | jq -er '.[] | select(.name == "AcmeDemoPlugin" and .version == "1.1.0" and .active == true) | .version')
[ "${updated}" = "1.1.0" ] || fail 'the paid native update did not leave 1.1.0 active'

expect_status 200 "${temporary_dir}/rotate.json" \
    -X POST "${seller_storefront}/store-api/extension-mesh/access/rotate" \
    -H "sw-access-key: ${sales_channel_access_key}" \
    -H "sw-context-token: ${context_token}"
rotated_token=$(jq -er '.accessToken' "${temporary_dir}/rotate.json")
[ "${rotated_token}" != "${access_token}" ] || fail 'token rotation returned the existing token'
expect_status 401 "${temporary_dir}/revoked-token.json" \
    "${seller_storefront}/extension-mesh/v1/registry" \
    -H "Authorization: Bearer ${access_token}"
expect_status 200 "${temporary_dir}/rotated-token.json" \
    "${seller_storefront}/extension-mesh/v1/registry" \
    -H "Authorization: Bearer ${rotated_token}"

replacement=$(jq -nc --arg accessToken "${rotated_token}" '{accessToken: $accessToken}')
expect_status 204 "${temporary_dir}/replace-credential.json" \
    -X PUT "${buyer_api}/_action/extension-mesh/registries/${registry_id}/credential" \
    -H "Authorization: Bearer ${buyer_admin_token}" \
    -H 'Content-Type: application/json' \
    --data "${replacement}"

expect_status 200 "${temporary_dir}/refund.json" \
    -X POST "${seller_api}/_action/state-machine/order_transaction/${transaction_id}/state/refund" \
    -H "Authorization: Bearer ${seller_admin_token}" \
    -H 'Content-Type: application/json' \
    --data '{}'
expect_status 200 "${temporary_dir}/refunded-registry.json" \
    "${seller_storefront}/extension-mesh/v1/registry" \
    -H "Authorization: Bearer ${rotated_token}"
jq -e '.extensions | length == 0' "${temporary_dir}/refunded-registry.json" >/dev/null \
    || fail 'a fully refunded order retained its entitlement'
expect_status 401 "${temporary_dir}/refunded-artifact.json" \
    "${artifact_url}" \
    -H "Authorization: Bearer ${rotated_token}"

echo "Paid entitlement test passed: upload-only publishing, checkout, grant, authenticated update, rotation and refund revocation."
