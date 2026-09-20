'use strict';

/**
 * Selector registry rules (prompt §59):
 *   - every entry has at least one candidate
 *   - every candidate is a non-empty string
 *   - the entries the publisher cannot function without are marked required,
 *     so a Facebook change fails loudly instead of silently
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const selectors = require('../../src/facebook/selectors');

const REQUIRED_PATHS = [
    'login.emailInput',
    'login.passwordInput',
    'login.submitButton',
    'composer.composerTrigger',
    'composer.modal',
    'composer.textArea',
    'composer.postButton',
    'composer.fileInput',
];

test('required composer and login selectors are declared and marked required', () => {
    for (const path of REQUIRED_PATHS) {
        const entry = selectors.entry(path);
        assert.ok(entry, `${path} is missing from the registry`);
        assert.equal(entry.required, true, `${path} must be marked required`);
        assert.ok(entry.candidates.length >= 1, `${path} has no candidates`);
    }
});

test('every registered entry has usable candidates', () => {
    const walk = (node, prefix) => {
        for (const [key, value] of Object.entries(node)) {
            const path = prefix ? `${prefix}.${key}` : key;
            if (value && typeof value === 'object' && Array.isArray(value.candidates)) {
                assert.ok(value.candidates.length > 0, `${path} has an empty candidate list`);
                assert.ok(value.candidates.every((c) => typeof c === 'string' && c.trim().length > 0),
                    `${path} contains an empty candidate`);
                assert.equal(typeof value.required, 'boolean', `${path} must declare required`);
                assert.ok(selectors.css(path), `${path} did not resolve to CSS`);
            } else if (value && typeof value === 'object') {
                walk(value, path);
            }
        }
    };
    walk(selectors.SELECTORS, '');
});

test('unknown paths resolve to null instead of throwing', () => {
    assert.equal(selectors.entry('nope.nothing'), null);
    assert.equal(selectors.css('nope.nothing'), null);
    assert.equal(selectors.isRequired('nope.nothing'), false);
});

test('challenge selectors cover every code the worker can report', () => {
    // These are the codes the job failure taxonomy uses for human intervention.
    const required = ['captcha', 'checkpoint', 'twoFactor', 'identity', 'securityBlock', 'rateLimit'];
    for (const name of required) {
        assert.ok(selectors.entry(`challenge.${name}`), `challenge.${name} is missing`);
    }
});
