#!/usr/bin/env sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
fixture_dir="${script_dir}/fixture"
public_dir="${script_dir}/public"
archive="${public_dir}/AcmeDemoPlugin-1.1.0.zip"
baseline_archive="${public_dir}/AcmeDemoPlugin-1.0.0.zip"
temporary_dir=$(mktemp -d)

cleanup() {
    rm -rf "${temporary_dir}"
}
trap cleanup EXIT

mkdir -p "${public_dir}"
rm -f \
    "${archive}" \
    "${baseline_archive}" \
    "${public_dir}/registry.json" \
    "${public_dir}/tampered-registry.json" \
    "${public_dir}/invalid-registry.json" \
    "${public_dir}/github-repository.json" \
    "${public_dir}/github-public-repository.json" \
    "${public_dir}/github-shopware-extension.yml" \
    "${public_dir}/github-description.de.html" \
    "${public_dir}/github-description.en.html" \
    "${public_dir}/github-icon.png" \
    "${public_dir}/github-image-1.png" \
    "${public_dir}/github-images.json" \
    "${public_dir}/github-releases-v1.json" \
    "${public_dir}/github-releases-v2.json" \
    "${public_dir}/github-public-releases.json" \
    "${public_dir}/github-releases.json"

(
    cd "${fixture_dir}"
    zip -q -r "${archive}" AcmeDemoPlugin
)

cp -R "${fixture_dir}/AcmeDemoPlugin" "${temporary_dir}/AcmeDemoPlugin"
sed -i.bak 's/"version": "1.1.0"/"version": "1.0.0"/' "${temporary_dir}/AcmeDemoPlugin/composer.json"
rm -f "${temporary_dir}/AcmeDemoPlugin/composer.json.bak"
(
    cd "${temporary_dir}"
    zip -q -r "${baseline_archive}" AcmeDemoPlugin
)

if command -v sha256sum >/dev/null 2>&1; then
    digest=$(sha256sum "${archive}" | awk '{print $1}')
    baseline_digest=$(sha256sum "${baseline_archive}" | awk '{print $1}')
else
    digest=$(shasum -a 256 "${archive}" | awk '{print $1}')
    baseline_digest=$(shasum -a 256 "${baseline_archive}" | awk '{print $1}')
fi

sed \
    -e "s/__SHA256_1_1_0__/${digest}/g" \
    -e "s/__SHA256_1_0_0__/${baseline_digest}/g" \
    "${script_dir}/registry.template.json" > "${public_dir}/registry.json"
sed -E \
    's/"sha256": "[a-f0-9]{64}"/"sha256": "0000000000000000000000000000000000000000000000000000000000000000"/g' \
    "${public_dir}/registry.json" > "${public_dir}/tampered-registry.json"
printf '{not valid JSON\n' > "${public_dir}/invalid-registry.json"

cp \
    "${script_dir}/github-repository.json" \
    "${script_dir}/github-public-repository.json" \
    "${script_dir}/github-shopware-extension.yml" \
    "${script_dir}/github-description.de.html" \
    "${script_dir}/github-description.en.html" \
    "${public_dir}/"
base64 -d < "${script_dir}/github-icon.base64" > "${public_dir}/github-icon.png"
cp "${public_dir}/github-icon.png" "${public_dir}/github-image-1.png"

size_v1=$(wc -c < "${baseline_archive}" | tr -d ' ')
size_v2=$(wc -c < "${archive}" | tr -d ' ')
image_size=$(wc -c < "${public_dir}/github-image-1.png" | tr -d ' ')
sed \
    -e "s/__IMAGE_SIZE__/${image_size}/g" \
    "${script_dir}/github-images.template.json" \
    > "${public_dir}/github-images.json"
sed \
    -e "s/__SIZE_1_0_0__/${size_v1}/g" \
    "${script_dir}/github-releases-v1.template.json" \
    > "${public_dir}/github-releases-v1.json"
sed \
    -e "s/__SIZE_1_0_0__/${size_v1}/g" \
    -e "s/__SIZE_1_1_0__/${size_v2}/g" \
    "${script_dir}/github-releases-v2.template.json" \
    > "${public_dir}/github-releases-v2.json"
sed \
    -e "s/__SIZE_1_0_0__/${size_v1}/g" \
    "${script_dir}/github-public-releases.template.json" \
    > "${public_dir}/github-public-releases.json"
cp "${public_dir}/github-releases-v1.json" "${public_dir}/github-releases.json"
mkdir -p "${public_dir}/github-assets"
cp "${baseline_archive}" "${archive}" "${public_dir}/github-assets/"

echo "Built local registry fixtures in ${public_dir}"
