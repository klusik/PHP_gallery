<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/cooperative_gallery.php
 * Module Type: View
 * Purpose: Render independent attributed source albums without discovering policy.
 * Responsibilities: Keep public cooperation bounded, source-authorized and independent of admin sessions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Views;


use function Gallery\Core\e;

/** Render one validated source page or its bounded pending/unavailable state.
 * @param array<string,mixed> $model Prepared labels and media URLs.
 * @return void Emit escaped semantic images, links and pagination.
 */
function view_cooperative_source(array $model): void
{
    $labels = $model['labels'];
    if (!empty($model['unavailable']) || !empty($model['pending'])) {
        echo '<p role="status">' . e($labels[!empty($model['pending']) ? 'pending' : 'unavailable']) . '</p>';
        return;
    }
    echo '<h3>' . e($model['title']) . '</h3><div class="cooperative-photo-grid">';
    foreach ($model['photos'] as $photo) {
        echo '<figure><a href="' . e($photo['href']) . '" target="_blank" rel="noopener noreferrer" aria-label="' . e($labels['open'] . ': ' . $photo['alt']) . '">'
            . '<img src="' . e($photo['src']) . '" alt="' . e($photo['alt']) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer"></a>'
            . '<figcaption>' . e($photo['alt']) . '</figcaption></figure>';
    }
    echo '</div>';
    if ($model['photos'] === []) { echo '<p>' . e($labels['empty']) . '</p>'; }
    if ($model['next'] !== '') { echo '<a class="button secondary" data-cooperative-more href="' . e($model['next']) . '">' . e($labels['more']) . '</a>'; }
}

/** Render all participant sections with a fully usable source-by-source HTML fallback.
 * @param array<string,mixed> $model Prepared page and source models.
 * @return void Emit the cooperative gallery and its independent progressive enhancement.
 */
function view_cooperative_gallery(array $model): void
{
    $labels = $model['labels'];
    echo '<link rel="stylesheet" href="' . e($model['style_url']) . '"><main class="cooperative-public" data-cooperative-gallery data-unavailable="' . e($labels['unavailable']) . '">';
    echo '<h1>' . e($model['title']) . '</h1><p>' . e($labels['title']) . '</p>';
    foreach ($model['sources'] as $source) {
        echo '<section data-cooperative-source><h2>' . e($labels['source']) . ' ' . e($source['label']) . '</h2>';
        echo '<div data-cooperative-content aria-live="polite">';
        if ($source['catalog'] !== null) { view_cooperative_source($source['catalog']); }
        echo '</div><a class="button secondary" data-cooperative-load href="' . e($source['url']) . '">' . e($labels[$source['catalog'] !== null ? 'retry' : 'load']) . '</a></section>';
    }
    echo '</main><script type="module" src="' . e($model['script_url']) . '"></script>';
}
