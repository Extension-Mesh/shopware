export function extensionMeshVersionIsNewer(candidate, installed) {
    const left = parseVersion(candidate);
    const right = parseVersion(installed);

    if (!left || !right) {
        return false;
    }

    for (let index = 0; index < left.core.length; index += 1) {
        if (left.core[index] !== right.core[index]) {
            return left.core[index] > right.core[index];
        }
    }

    if (left.preRelease.length === 0 || right.preRelease.length === 0) {
        return left.preRelease.length === 0 && right.preRelease.length > 0;
    }

    for (let index = 0; index < Math.max(left.preRelease.length, right.preRelease.length); index += 1) {
        const leftPart = left.preRelease[index];
        const rightPart = right.preRelease[index];

        if (leftPart === undefined || rightPart === undefined) {
            return rightPart === undefined;
        }

        if (leftPart === rightPart) {
            continue;
        }

        const leftNumeric = /^\d+$/.test(leftPart);
        const rightNumeric = /^\d+$/.test(rightPart);
        if (leftNumeric && rightNumeric) {
            return Number(leftPart) > Number(rightPart);
        }

        if (leftNumeric !== rightNumeric) {
            return !leftNumeric;
        }

        return leftPart > rightPart;
    }

    return false;
}

export function githubFineGrainedTokenUrl(repositoryName) {
    const parameters = new URLSearchParams({
        name: 'ExtensionMesh',
        description: 'Read-only repository access for ExtensionMesh synchronization',
        expires_in: '90',
        contents: 'read',
    });
    const owner = repositoryName.trim().match(/^([A-Za-z0-9_.-]{1,100})\//)?.[1];
    if (owner) {
        parameters.set('target_name', owner);
    }

    return `https://github.com/settings/personal-access-tokens/new?${parameters.toString()}`;
}

function parseVersion(version) {
    if (!version) {
        return null;
    }

    const match = String(version).trim().replace(/^v/, '').match(
        /^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/,
    );

    if (!match) {
        return null;
    }

    return {
        core: match.slice(1, 5).map((part) => Number(part || 0)),
        preRelease: match[5]?.split('.') || [],
    };
}
