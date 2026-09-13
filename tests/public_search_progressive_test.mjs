/**
 * Protect progressive public-search result merging and stale-response suppression.
 */

import assert from 'node:assert/strict';
import {
    comparePublicSearchResults,
    limitPublicSearchResults,
    mergePublicSearchResults,
    publicSearchResponseIsCurrent,
    publicSearchResultIdentity,
} from '../public/assets/gallery-modules/public-home-search.js';

assert.equal(publicSearchResultIdentity({key: 'gallery:42', type: 'gallery', url: '/g/a320'}), 'gallery:42');
assert.equal(publicSearchResultIdentity({type: 'photo', url: '/p/7', title: 'Photo'}), 'photo:/p/7:Photo');

const primary = [
    {key: 'gallery:42', type: 'gallery', title: 'A320', subtitle: 'Tags: aircraft', url: '/g/a320', rank: 950},
];
const media = [
    {key: 'photo:7', type: 'photo', title: 'A320 cockpit', subtitle: 'In A320 · Tags: cockpit', url: '/p/7', rank: 900},
];
const compatibility = [
    {key: 'gallery:42', type: 'gallery', title: 'A320', subtitle: 'Tags: aircraft · Photo tags: cockpit', url: '/g/a320', rank: 120},
    {key: 'photo:7', type: 'photo', title: 'A320 cockpit', subtitle: 'In A320', url: '/p/7', rank: 106},
];
const primaryAndMedia = mergePublicSearchResults(primary, media);
assert.equal(primaryAndMedia.length, 2, 'Media results must merge progressively before compatibility search completes.');
assert.deepEqual(primaryAndMedia.map((item) => item.key), ['gallery:42', 'photo:7'], 'Central relevance ranks must order primary and media results together.');

const merged = mergePublicSearchResults(primaryAndMedia, compatibility);
assert.equal(merged.length, 2, 'Duplicate staged gallery results must merge by stable key.');
assert.equal(merged[0].key, 'gallery:42', 'Higher-ranked primary gallery must remain first.');
assert.equal(merged[0].rank, 950, 'Later compatibility payload must not reduce an existing relevance rank.');
assert.equal(merged[0].subtitle, 'Tags: aircraft · Photo tags: cockpit', 'Later phases may enrich duplicate display details.');
assert.equal(merged[1].key, 'photo:7');
assert.equal(merged[1].rank, 900, 'Compatibility enrichment must not reduce the media-phase relevance rank.');

const tieSorted = [
    {key: 'photo:2', type: 'photo', title: 'Bravo', rank: 100},
    {key: 'gallery:3', type: 'gallery', title: 'Zulu', rank: 100},
    {key: 'gallery:1', type: 'gallery', title: 'Alpha', rank: 100},
].sort(comparePublicSearchResults);
assert.deepEqual(tieSorted.map((item) => item.key), ['gallery:1', 'gallery:3', 'photo:2'], 'Tie-breaking must prefer galleries, title order, then stable identity.');


const capped = limitPublicSearchResults([
    ...Array.from({length: 10}, (_, index) => ({key: `gallery:${index + 1}`, type: 'gallery', title: `Gallery ${index + 1}`, rank: 100 - index})),
    ...Array.from({length: 10}, (_, index) => ({key: `photo:${index + 1}`, type: 'photo', title: `Photo ${index + 1}`, rank: 50 - index})),
], 14, 8, 8);
assert.equal(capped.length, 14, 'Progressive merge must keep the compact result box bounded.');
assert.equal(capped.filter((item) => item.type === 'gallery').length, 8, 'Gallery result cap must be enforced after sorting.');
assert.equal(capped.filter((item) => item.type === 'photo').length, 6, 'Remaining compact-result capacity should be filled by photos.');

assert.equal(publicSearchResponseIsCurrent(5, 5, '320', '320'), true);
assert.equal(publicSearchResponseIsCurrent(4, 5, '320', '320'), false, 'Older generations must be discarded.');
assert.equal(publicSearchResponseIsCurrent(5, 5, '32', '320'), false, 'Responses for an older query string must be discarded.');

console.log('Progressive public-search browser checks passed.');
