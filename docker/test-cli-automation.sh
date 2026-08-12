#!/usr/bin/env sh
set -eu

buyer_api="http://127.0.0.1:${BUYER_HTTP_PORT:-8081}/api"
public_dir="$(CDPATH= cd -- "$(dirname -- "$0")/registry/public" && pwd)"
registry_v1="${public_dir}/registry-v1.json"
registry_current="${public_dir}/registry.json"
credential="cli-automation-secret"

fail() {
    echo "CLI automation test failed: $1" >&2
    exit 1
}

restore_registry() {
    jq '.extensions[].releases |= map(select(.version == "1.0.0"))' \
        "${registry_current}" > "${registry_v1}"
}
trap restore_registry EXIT

token=$(curl -fsS \
    -X POST "${buyer_api}/oauth/token" \
    -H 'Content-Type: application/json' \
    --data '{"client_id":"administration","grant_type":"password","scopes":"write","username":"admin","password":"shopware"}' \
    | jq -er '.access_token')

curl -fsS -X POST "${buyer_api}/search/extension-mesh-registry-source" \
    -H "Authorization: Bearer ${token}" \
    -H 'Content-Type: application/json' \
    --data '{"page":1,"limit":500}' \
    | jq -r '.data[].id' \
    | while IFS= read -r source_id; do
        curl -fsS \
            -X DELETE \
            "${buyer_api}/_action/extension-mesh/registries/${source_id}" \
            -H "Authorization: Bearer ${token}" \
            >/dev/null
    done

installed_json=$(curl -fsS "${buyer_api}/_action/extension/installed" -H "Authorization: Bearer ${token}")
if printf '%s' "${installed_json}" | jq -e '.[] | select(.name == "AcmeDemoPlugin" and .installedAt != null)' >/dev/null; then
    curl -fsS \
        -X POST "${buyer_api}/_action/extension/uninstall/plugin/AcmeDemoPlugin" \
        -H "Authorization: Bearer ${token}" \
        -H 'Content-Type: application/json' \
        --data '{"keepUserData":true}' \
        >/dev/null
fi
if printf '%s' "${installed_json}" | jq -e '.[] | select(.name == "AcmeDemoPlugin")' >/dev/null; then
    curl -fsS \
        -X POST "${buyer_api}/_action/extension/remove/plugin/AcmeDemoPlugin" \
        -H "Authorization: Bearer ${token}" \
        -H 'Content-Type: application/json' \
        --data '{}' \
        >/dev/null
fi

restore_registry
docker compose exec -T buyer bin/console cache:clear --no-ansi >/dev/null
docker compose exec -T buyer bin/console extension-mesh:registry:add \
    http://registry/registry-v1.json --no-ansi >/dev/null

duplicate_output=$(docker compose exec -T buyer bin/console extension-mesh:registry:add \
    http://registry/registry-v1.json --token="${credential}" --no-ansi)
printf '%s' "${duplicate_output}" | grep -q 'credential updated' \
    || fail 'duplicate registry add did not update the credential idempotently'
if printf '%s' "${duplicate_output}" | grep -q "${credential}"; then
    fail 'registry credential was printed'
fi

install_plan=$(docker compose exec -T buyer bin/console extension-mesh:sync \
    --install --activate --dry-run --json --no-ansi)
printf '%s' "${install_plan}" \
    | jq -e '.success == true and (.actions[] | select(
        .technicalName == "AcmeDemoPlugin"
        and .action == "install"
        and .availableVersion == "1.0.0"
        and .status == "planned"
    ))' >/dev/null \
    || fail 'dry-run did not plan the 1.0.0 install'

if docker compose exec -T buyer bin/console plugin:list --format=json --no-ansi \
    | jq -e '.[] | select(.name == "AcmeDemoPlugin" and .installedAt != null)' >/dev/null; then
    fail 'dry-run installed the plugin'
fi

if ! install_output=$(docker compose exec -T buyer bin/console extension-mesh:sync \
    --install --activate --no-refresh --no-ansi 2>&1); then
    printf '%s\n' "${install_output}" >&2
    fail 'real install command returned a failure status'
fi
docker compose exec -T buyer bin/console plugin:list --format=json --no-ansi \
    | jq -e '.[] | select(
        .name == "AcmeDemoPlugin"
        and .version == "1.0.0"
        and .active == true
    )' >/dev/null \
    || fail 'sync did not install and activate version 1.0.0'

cp "${registry_current}" "${registry_v1}"
docker compose exec -T buyer bin/console extension-mesh:refresh --no-ansi >/dev/null
update_plan=$(docker compose exec -T buyer bin/console extension-mesh:sync \
    --update --dry-run --json --no-ansi)
printf '%s' "${update_plan}" \
    | jq -e '.success == true and (.actions[] | select(
        .technicalName == "AcmeDemoPlugin"
        and .action == "update"
        and .installedVersion == "1.0.0"
        and .availableVersion == "1.1.0"
        and .status == "planned"
    ))' >/dev/null \
    || fail 'dry-run did not plan the 1.1.0 update'

docker compose exec -T buyer bin/console plugin:list --format=json --no-ansi \
    | jq -e '.[] | select(.name == "AcmeDemoPlugin" and .version == "1.0.0")' >/dev/null \
    || fail 'update dry-run changed the installed plugin'

if ! update_output=$(docker compose exec -T buyer bin/console extension-mesh:sync \
    --update --no-refresh --no-ansi 2>&1); then
    printf '%s\n' "${update_output}" >&2
    fail 'real update command returned a failure status'
fi
docker compose exec -T buyer bin/console plugin:list --format=json --no-ansi \
    | jq -e '.[] | select(
        .name == "AcmeDemoPlugin"
        and .version == "1.1.0"
        and .active == true
    )' >/dev/null \
    || fail 'sync did not update to 1.1.0 while preserving activation'

echo "CLI automation test passed: idempotent add, credential safety, dry-run, install, activate and update."
