/**
 * Project: PHP Gallery
 * Purpose: Exercise the production lightbox navigation transaction owner.
 * Responsibilities:
 *   - Protect canonical target identity, generation, phase, and signal lifetime.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_navigation_lifecycle_test.mjs
 * Module Type: Test Script
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {test} from 'node:test';
import {createLightboxNavigationLifecycle} from '../public/assets/gallery-modules/lightbox-navigation-lifecycle.js';

test('begin owns one canonical target and aborts superseded navigation', () => {
    const owner = createLightboxNavigationLifecycle();
    const first = owner.begin(3, 'image-a');
    assert.ok(first);
    assert.equal(owner.isCurrent(3, first.generation), true);
    assert.equal(owner.snapshot().imageId, 'image-a');
    assert.equal(owner.snapshot().phase, 'intent');

    const second = owner.begin(8, 'image-b');
    assert.ok(second);
    assert.equal(first.signal.aborted, true);
    assert.equal(owner.isCurrent(3, first.generation), false);
    assert.equal(owner.isCurrent(8, second.generation), true);
    assert.deepEqual(
        {index: owner.index, imageId: owner.imageId, generation: owner.generation},
        {index: 8, imageId: 'image-b', generation: second.generation},
    );
});

test('sparse metadata binds one image ID without changing the navigation generation', () => {
    const owner = createLightboxNavigationLifecycle();
    const transaction = owner.begin(14);
    assert.ok(transaction);
    assert.equal(owner.snapshot().imageId, '');

    assert.equal(owner.bindImageId(14, transaction.generation, 'image-sparse'), true);
    assert.equal(owner.snapshot().imageId, 'image-sparse');
    assert.equal(owner.generation, transaction.generation);
    assert.equal(owner.isCurrent(14, transaction.generation), true);
    assert.equal(owner.bindImageId(14, transaction.generation, 'other-image'), false);
});

test('phase and settlement reject stale work but preserve the displayed transaction for quality work', () => {
    const owner = createLightboxNavigationLifecycle();
    const transaction = owner.begin(1, 'image-live');
    assert.ok(transaction);

    assert.equal(owner.setPhase(1, transaction.generation, 'metadata'), true);
    assert.equal(owner.setPhase(1, transaction.generation, 'presenting'), true);
    assert.equal(owner.settle(1, transaction.generation, 'displayed'), true);
    assert.equal(owner.isCurrent(1, transaction.generation), true);
    assert.equal(transaction.signal.aborted, false);
    assert.equal(owner.snapshot().phase, 'displayed');
    assert.equal(owner.settle(1, transaction.generation, 'failed', 'late failure'), false);
    assert.equal(owner.snapshot().detail, '');

    const next = owner.begin(2, 'image-next');
    assert.ok(next);
    assert.equal(owner.setPhase(1, transaction.generation, 'failed', 'stale decode'), false);
    assert.equal(owner.settle(1, transaction.generation, 'failed', 'stale decode'), false);
    assert.equal(owner.snapshot().detail, '');
    assert.equal(owner.isCurrent(2, next.generation), true);
});

test('invalidate aborts stale metadata/decode callbacks, retains target, and supports reopen', () => {
    const owner = createLightboxNavigationLifecycle();
    const closed = owner.begin(6, 'image-close');
    assert.ok(closed);

    const invalidated = owner.invalidate();
    assert.equal(closed.signal.aborted, true);
    assert.equal(invalidated.active, false);
    assert.equal(invalidated.phase, 'closed');
    assert.equal(invalidated.index, 6);
    assert.equal(invalidated.imageId, 'image-close');
    assert.equal(owner.isCurrent(6, closed.generation), false);
    assert.equal(owner.settle(6, closed.generation, 'displayed'), false);

    const reopened = owner.begin(6, 'image-close');
    assert.ok(reopened);
    assert.ok(reopened.generation > invalidated.generation);
    assert.equal(owner.isCurrent(6, reopened.generation), true);
    assert.equal(owner.settle(6, closed.generation, 'failed', 'old decode'), false);
    assert.equal(owner.snapshot().phase, 'intent');
});

test('dispose is terminal and idempotently retires the active signal', () => {
    const owner = createLightboxNavigationLifecycle();
    const transaction = owner.begin(0, 'image-zero');
    assert.ok(transaction);

    owner.dispose();
    const disposedGeneration = owner.generation;
    owner.dispose();

    assert.equal(transaction.signal.aborted, true);
    assert.equal(owner.phase, 'disposed');
    assert.equal(owner.generation, disposedGeneration);
    assert.equal(owner.isCurrent(0, transaction.generation), false);
    assert.equal(owner.begin(0, 'image-zero'), null);
    assert.equal(owner.setPhase(0, transaction.generation, 'displayed'), false);
    assert.equal(owner.invalidate().generation, disposedGeneration);
    assert.equal(owner.phase, 'disposed');
});
