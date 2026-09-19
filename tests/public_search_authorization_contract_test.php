<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_search_authorization_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Locks visitor-aware authorization at the public-search presentation boundary.
 *
 * Responsibilities:
 *   - Keep public search restricted to publicly listed galleries
 *   - Reuse canonical visitor gallery/image authorization for password, share-link, and NSFW policy
 *   - Protect both compatibility and progressive search hydration paths
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - This focused contract uses small policy stubs for helper behavior and source checks for call-site coverage.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Services;

/** Test stub for the public-listing half of search eligibility. */
function gallery_is_public_listed(array $gallery): bool
{
    return !empty($gallery['test_listed']);
}

/** Test stub for canonical visitor gallery authorization. */
function visitor_can_access_gallery(array $gallery): bool
{
    return !empty($gallery['test_access_granted']);
}

/** Test stub for canonical visitor image authorization. */
function public_image_visible_to_current_visitor(array $image, array $gallery): bool
{
    return (string) ($image['visibility'] ?? '') === 'public'
        && !empty($gallery['test_access_granted'])
        && empty($image['test_nsfw_blocked']);
}

require_once dirname(__DIR__) . '/app/services/public_search.php';

/** Throw when one public-search authorization invariant fails. */
function public_search_authorization_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

public_search_authorization_assert(
    public_search_gallery_visible_to_current_visitor(['test_listed' => true, 'test_access_granted' => true]),
    'A listed gallery with current visitor access must remain searchable.'
);
public_search_authorization_assert(
    !public_search_gallery_visible_to_current_visitor(['test_listed' => false, 'test_access_granted' => true]),
    'An unlisted/unpublished gallery must not become discoverable merely because its direct URL is reachable.'
);
public_search_authorization_assert(
    !public_search_gallery_visible_to_current_visitor(['test_listed' => true, 'test_access_granted' => false]),
    'A listed password/NSFW gallery must not expose search metadata before visitor authorization.'
);
public_search_authorization_assert(
    public_search_image_visible_to_current_visitor(
        ['visibility' => 'public'],
        ['test_listed' => true, 'test_access_granted' => true]
    ),
    'A public image in an authorized listed gallery must remain searchable.'
);
public_search_authorization_assert(
    !public_search_image_visible_to_current_visitor(
        ['visibility' => 'public', 'test_nsfw_blocked' => true],
        ['test_listed' => true, 'test_access_granted' => true]
    ),
    'Search must delegate image/gallery NSFW policy to canonical image authorization.'
);

$compatibilitySource = (string) file_get_contents(dirname(__DIR__) . '/app/services/public_search.php');
$progressiveSource = (string) file_get_contents(dirname(__DIR__) . '/app/services/public_search_progressive.php');

public_search_authorization_assert(
    substr_count($compatibilitySource, 'public_search_gallery_visible_to_current_visitor(') >= 3
        && str_contains($compatibilitySource, 'public_search_image_visible_to_current_visitor($row, $gallery)'),
    'Compatibility public search must revalidate gallery and image metadata with visitor-aware service policy.'
);
public_search_authorization_assert(
    str_contains($progressiveSource, 'public_search_gallery_visible_to_current_visitor($gallery)')
        && str_contains($progressiveSource, 'public_search_image_visible_to_current_visitor($row, $gallery)')
        && str_contains($progressiveSource, 'public_search_model_gallery_tag_name_rows($authorizedGalleryIds)')
        && str_contains($progressiveSource, 'public_search_model_image_tag_name_rows($authorizedImageIds)'),
    'Progressive search hydration must authorize before enriching protected gallery/image metadata.'
);

echo "public_search_authorization_contract_test: ok\n";
