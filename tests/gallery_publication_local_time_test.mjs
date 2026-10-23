/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_publication_local_time_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify native local picker times retain their actual UTC instant.
 * Responsibilities: Cover summer/winter offsets, cancellation and DST edges.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL(
    '../public/assets/gallery-modules/admin-date-picker.js', import.meta.url,
), 'utf8');
const sandbox = vm.createContext({Date, Intl});
vm.runInContext(
    source.replace(/^import .*;\r?\n/gm, '').replaceAll('export function ', 'function ')
        + '\nglobalThis.conversion = {publicationLocalInputValue, publicationUtcInputValue};',
    sandbox,
);
const {publicationLocalInputValue, publicationUtcInputValue} = sandbox.conversion;
const originalZone = process.env.TZ;
try {
    // Fixed timezone/date pairs are regression vectors, not runtime policies.
    process.env.TZ = 'Europe/Prague';
    assert.equal(publicationUtcInputValue('2026-10-23T12:05'), '2026-10-23T10:05');
    assert.equal(publicationUtcInputValue('2026-12-23T12:05'), '2026-12-23T11:05');
    assert.equal(publicationLocalInputValue(new Date('2026-10-23T10:05:00Z')), '2026-10-23T12:05');
    assert.equal(publicationUtcInputValue('2030-03-31T02:30'), null);
    assert.equal(publicationUtcInputValue('2030-10-27T02:30'), '2030-10-27T00:30');
    assert.equal(publicationLocalInputValue(new Date('2030-10-27T01:30:00Z')), '2030-10-27T02:30');
    assert.equal(publicationUtcInputValue('2030-02-30T12:00'), null);
    assert.equal(publicationUtcInputValue(''), '');
    assert.equal(publicationUtcInputValue('invalid'), null);
    process.env.TZ = 'Asia/Kolkata';
    assert.equal(publicationUtcInputValue('2026-10-23T12:05'), '2026-10-23T06:35');
    process.env.TZ = 'America/New_York';
    assert.equal(publicationUtcInputValue('2026-10-23T12:05'), '2026-10-23T16:05');
    process.env.TZ = 'UTC';
    assert.equal(publicationUtcInputValue('2026-10-23T12:05'), '2026-10-23T12:05');
} finally {
    if (originalZone === undefined) delete process.env.TZ;
    else process.env.TZ = originalZone;
}
console.log('PASS publication local picker UTC conversion, seasonal offsets, DST gap/overlap and cancellation');
