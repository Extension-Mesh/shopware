import assert from 'node:assert/strict';
import test from 'node:test';

import {
    extensionMeshVersionIsNewer,
    githubFineGrainedTokenUrl,
} from '../../src/Resources/app/administration/src/util/extension-mesh.js';

test('version comparison follows semantic-version precedence', () => {
    assert.equal(extensionMeshVersionIsNewer('1.10.0', '1.9.9'), true);
    assert.equal(extensionMeshVersionIsNewer('2.0.0', '2.0.0-rc.1'), true);
    assert.equal(extensionMeshVersionIsNewer('2.0.0-rc.2', '2.0.0-rc.1'), true);
    assert.equal(extensionMeshVersionIsNewer('2.0.0-alpha.1', '2.0.0-alpha.beta'), false);
    assert.equal(extensionMeshVersionIsNewer('1.0.0+build.2', '1.0.0+build.1'), false);
    assert.equal(extensionMeshVersionIsNewer('invalid', '1.0.0'), false);
});

test('GitHub token URL requests only repository contents read access', () => {
    const url = new URL(githubFineGrainedTokenUrl('extension-mesh/shopware'));

    assert.equal(url.origin, 'https://github.com');
    assert.equal(url.pathname, '/settings/personal-access-tokens/new');
    assert.equal(url.searchParams.get('contents'), 'read');
    assert.equal(url.searchParams.get('expires_in'), '90');
    assert.equal(url.searchParams.get('target_name'), 'extension-mesh');
});

test('GitHub token URL does not infer an owner from malformed input', () => {
    const url = new URL(githubFineGrainedTokenUrl('https://github.com/owner/repository'));

    assert.equal(url.searchParams.has('target_name'), false);
});
