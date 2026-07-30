#!/usr/bin/env bash

set -euo pipefail

: "${GH_TOKEN:?GH_TOKEN is required}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${GITHUB_SHA:?GITHUB_SHA is required}"
: "${REGISTRY:?REGISTRY is required}"

if [[ ! -f "$REGISTRY" ]]; then
    echo "Registry document not found: $REGISTRY" >&2
    exit 1
fi

channel_branch="extension-mesh-registry"
channel_path="extension-mesh-registry.json"
branch_exists="false"

blob_sha="$(
    jq -n --rawfile content "$REGISTRY" \
        '{content: $content, encoding: "utf-8"}' \
        | gh api --method POST \
            "repos/$GITHUB_REPOSITORY/git/blobs" \
            --input - \
            --jq '.sha'
)"

if parent_sha="$(
    gh api "repos/$GITHUB_REPOSITORY/git/ref/heads/$channel_branch" \
        --jq '.object.sha' 2>/dev/null
)"; then
    branch_exists="true"
    base_tree_sha="$(
        gh api "repos/$GITHUB_REPOSITORY/git/commits/$parent_sha" \
            --jq '.tree.sha'
    )"
    tree_payload="$(
        jq -n \
            --arg baseTree "$base_tree_sha" \
            --arg path "$channel_path" \
            --arg blob "$blob_sha" \
            '{
                base_tree: $baseTree,
                tree: [{
                    path: $path,
                    mode: "100644",
                    type: "blob",
                    sha: $blob
                }]
            }'
    )"
else
    parent_sha="$GITHUB_SHA"
    tree_payload="$(
        jq -n \
            --arg path "$channel_path" \
            --arg blob "$blob_sha" \
            '{
                tree: [{
                    path: $path,
                    mode: "100644",
                    type: "blob",
                    sha: $blob
                }]
            }'
    )"
fi

tree_sha="$(
    gh api --method POST \
        "repos/$GITHUB_REPOSITORY/git/trees" \
        --input - \
        --jq '.sha' <<< "$tree_payload"
)"

commit_message="Update ExtensionMesh registry"
if [[ -n "${VERSION:-}" ]]; then
    commit_message="$commit_message to $VERSION"
fi

commit_payload="$(
    jq -n \
        --arg message "$commit_message" \
        --arg tree "$tree_sha" \
        --arg parent "$parent_sha" \
        '{
            message: $message,
            tree: $tree,
            parents: [$parent]
        }'
)"
commit_sha="$(
    gh api --method POST \
        "repos/$GITHUB_REPOSITORY/git/commits" \
        --input - \
        --jq '.sha' <<< "$commit_payload"
)"

if [[ "$branch_exists" == "true" ]]; then
    jq -n --arg sha "$commit_sha" '{sha: $sha, force: false}' \
        | gh api --method PATCH \
            "repos/$GITHUB_REPOSITORY/git/refs/heads/$channel_branch" \
            --input - >/dev/null
else
    jq -n \
        --arg ref "refs/heads/$channel_branch" \
        --arg sha "$commit_sha" \
        '{ref: $ref, sha: $sha}' \
        | gh api --method POST \
            "repos/$GITHUB_REPOSITORY/git/refs" \
            --input - >/dev/null
fi

echo "Registry channel updated:"
echo "https://raw.githubusercontent.com/$GITHUB_REPOSITORY/$channel_branch/$channel_path"
