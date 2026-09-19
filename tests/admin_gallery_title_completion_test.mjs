/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_gallery_title_completion_test.mjs
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

import {
    findGalleryTitleCompletion,
    normalizeGalleryTitleCompletionText,
    galleryTitleCompletionSuffix,
} from '../public/assets/gallery-modules/admin-gallery-title-completion.js';
import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

const candidates = [
    {
        id: 11,
        parent_id: 42,
        title: 'Westbound Tour Leg: 01 Prague to Bristol',
        created_at: '2026-08-01 10:00:00',
    },
    {
        id: 12,
        parent_id: 42,
        title: 'Westbound Tour Leg: 02 Bristol to Inverness',
        created_at: '2026-08-02 10:00:00',
    },
    {
        id: 99,
        parent_id: 7,
        title: 'Westbound Archive',
        created_at: '2026-09-19 10:00:00',
    },
    {
        id: 100,
        parent_id: 7,
        title: 'Nordic Flight Notes',
        created_at: '2026-09-20 10:00:00',
    },
];

assert(normalizeGalleryTitleCompletionText('WEST') === 'west', 'Completion matching must be case-insensitive.');
assert(
    findGalleryTitleCompletion(candidates, 'west', 42) === 'Westbound Tour Leg: 02 Bristol to Inverness',
    'The newest matching sibling must win over both older siblings and newer unrelated galleries.'
);
assert(
    findGalleryTitleCompletion(candidates, 'Westbound A', 42) === 'Westbound Archive',
    'Matching titles outside the selected parent must remain available as a fallback.'
);
assert(
    findGalleryTitleCompletion(candidates, 'nord', 42) === 'Nordic Flight Notes',
    'Fallback matching must work when the selected parent has no matching sibling.'
);
assert(findGalleryTitleCompletion(candidates, 'w', 42) === '', 'One-character fragments must not produce noisy completion.');
assert(galleryTitleCompletionSuffix('\ufb00light notes', 'ff') === 'light notes', 'A ligature consumes one original character, not two.');
assert(galleryTitleCompletionSuffix('Cafe\u0301 trip', 'Café') === ' trip', 'A decomposed grapheme maps to its complete original boundary.');
assert(galleryTitleCompletionSuffix('\ufb00light', 'f') === null, 'A partial compatibility ligature must not be offered.');
assert(galleryTitleCompletionSuffix('👩‍🚀 flight', '👩') === null, 'An emoji grapheme must not be split.');
assert(galleryTitleCompletionSuffix('Åland', 'åland') === null, 'An exact normalized match has no suffix.');
assert(findGalleryTitleCompletion([{title: '\ufb00light notes'}], 'ff') === '\ufb00light notes', 'Length-changing normalization is valid for selection.');
assert(
    findGalleryTitleCompletion(candidates, 'Westbound Tour Leg: 02 Bristol to Inverness', 42) === '',
    'An already complete title must not render a redundant suggestion.'
);

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const browserSource = await fs.readFile(path.join(projectRoot, 'public/assets/gallery-modules/admin-gallery-title-completion.js'), 'utf8');
const entrypointSource = await fs.readFile(path.join(projectRoot, 'public/assets/gallery.js'), 'utf8');
const viewSource = await fs.readFile(path.join(projectRoot, 'app/views/admin_gallery_forms.php'), 'utf8');

assert(browserSource.includes("event.key === 'Tab'"), 'Tab must remain an explicit completion acceptance key.');
assert(browserSource.includes("event.key === 'ArrowRight'"), 'ArrowRight must remain an explicit completion acceptance key.');
assert(browserSource.includes("document.addEventListener('pointerdown'"), 'Pointer acceptance must remain delegated for dynamically injected forms.');
assert(entrypointSource.includes('setupAdminGalleryTitleCompletion();'), 'The gallery browser entrypoint must boot title completion.');
assert(viewSource.includes('data-gallery-title-completion-candidates='), 'Create-gallery forms must carry title completion candidates.');
assert(/<input name="title"[^>]*aria-label=/.test(viewSource), 'Suggestion announcements must not alter the title input accessible name.');

console.log('admin_gallery_title_completion_test: OK');
