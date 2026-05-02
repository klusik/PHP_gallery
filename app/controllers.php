<?php

declare(strict_types=1);

/**
 * Send validators for a streamed file and stop on a matching browser cache entry.
 */
function send_conditional_file_headers(string $path, string $cacheControl): void
{
    $mtime = (int) filemtime($path);
    $size = (int) filesize($path);
    $etag = '"' . sha1($path . '|' . $mtime . '|' . $size) . '"';
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Cache-Control: ' . $cacheControl);

    $clientEtag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    $clientModifiedSince = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
    if ($clientEtag === $etag || ($clientModifiedSince !== '' && (int) strtotime($clientModifiedSince) >= $mtime)) {
        http_response_code(304);
        exit;
    }
}


/**
 * Render the public scroll helper next to a listing without joining the listing grid.
 */
function render_back_to_top_button(): void
{
    echo '<button type="button" class="back-to-top-button" data-back-to-top-button hidden aria-label="Go back to top" title="Go back to top"><span aria-hidden="true">↑</span><span>Top</span></button>';
}

/**
 * Public homepage showing top-level public galleries.
 */
function cms_home(): void
{
    $listingCondition = public_gallery_listing_condition('g');
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE $listingCondition AND g.parent_id IS NULL
        GROUP BY g.id
        ORDER BY g.sort_order, g.title");
    $stmt->execute();
    // Variable $galleries stores this steps working value.
    $galleries = $stmt->fetchAll();
    render_header(site_name());
    if ($galleries) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<section class="grid gallery-list-content" data-back-to-top-list>';
        foreach ($galleries as $gallery) {
            render_gallery_card($gallery, true);
        }
        echo '</section>';
        render_back_to_top_button();
        echo '</div>';
    }
    render_footer();
}

/**
 * Public gallery detail page with breadcrumbs, subgalleries, images, tags, and votes.
 */
function cms_gallery(): void
{
    // Variable $viewer stores this steps working value.
    $viewer = current_user();
    // Variable $gallery stores this steps working value.
    $gallery = null;
    // Variable $requestedImage stores this steps working value.
    $requestedImage = null;
    if (isset($_GET['public_path'])) {
        $resolved = resolve_public_gallery_path((string) $_GET['public_path'], !$viewer);
        $gallery = $resolved['gallery'];
        $requestedImage = $resolved['image'];
    }
    if (!$gallery && isset($_GET['gallery_path'])) {
        try {
            $gallery = find_gallery_by_folder_path((string) $_GET['gallery_path']);
        } catch (RuntimeException) {
            $gallery = null;
        }
    }
    if (!$gallery && isset($_GET['slug'])) {
        try {
            $gallery = find_gallery_by_folder_path((string) $_GET['slug']);
        } catch (RuntimeException) {
            $gallery = null;
        }
    }
    if (!$gallery) {
        $gallery = find_gallery_by_slug((string) ($_GET['slug'] ?? ''));
    }
    if (!$gallery || ($gallery['visibility'] !== 'public' && !$viewer)) {
        cms_not_found();
        return;
    }
    if (!$viewer && !visitor_can_access_gallery($gallery)) {
        render_gallery_access_gate($gallery);
        return;
    }
    // Variable $publicOnly stores this steps working value.
    $publicOnly = !$viewer;

    // Variable $stmt stores this steps working value.
    $sql = "SELECT i.*, COALESCE(SUM(v.vote), 0) AS score
        FROM images i
        LEFT JOIN image_votes v ON v.image_id = i.id
        WHERE i.gallery_id = ? AND i.relative_path NOT LIKE '%/%'";
    if ($publicOnly) {
        $sql .= " AND i.visibility = 'public'";
    }
    $sql .= "
        GROUP BY i.id
        ORDER BY i.sort_order, i.filename";
    $stmt = db()->prepare($sql);
    $stmt->execute([(int) $gallery['id']]);
    // Variable $images stores this steps working value.
    $images = $stmt->fetchAll();
    // Variable $imageIds stores this steps working value.
    $imageIds = array_map(static fn (array $image): int => (int) $image['id'], $images);
    // Variable $imageTagsById stores this steps working value.
    $imageTagsById = tags_for_entities('image', $imageIds);
    // Variable $votesById stores this steps working value.
    $votesById = current_votes_for_images($imageIds);
    // Variable $children stores this steps working value.
    $children = child_galleries((int) $gallery['id'], $publicOnly);
    // Variable $mapsAllowed stores this steps working value.
    $mapsAllowed = gallery_allows_gps_maps($gallery);
    // Variable $galleryMapPoints stores this steps working value.
    $galleryMapPoints = $mapsAllowed ? gallery_map_points($gallery, $publicOnly, true) : [];
    // Variable $votingAllowed stores this steps working value.
    $votingAllowed = gallery_voting_allowed($gallery);
    // Variable $pictureGameImages stores this steps working value.
    $pictureGameImages = picture_game_images($gallery);
    // Variable $backgroundAssetUrl stores this steps working value.
    $backgroundAssetUrl = gallery_background_asset_url($gallery, $publicOnly);
    // Variable $seo stores this steps working value.
    $seo = public_gallery_metadata($gallery);
    ob_start();
    render_public_seo_tags($gallery, $images);
    render_gallery_json_ld($gallery, $images);
    append_cms_head_extras((string) ob_get_clean());
    if ($backgroundAssetUrl !== '') {
        append_cms_head_extras('<style>.theme-background-image{background-image:url("' . css_value($backgroundAssetUrl) . '");}</style>');
    }

    render_header((string) $seo['title']);
    echo '<section class="hero"><h1>' . e((string) $seo['title']) . '</h1><p>' . e((string) $seo['description']) . '</p>';
    render_tag_list(tags_for_entity('gallery', (int) $gallery['id']));
    if ($children) {
        render_tag_list(contained_tags_for_gallery($gallery, $publicOnly), 'Containing tags');
    }
    echo '<div class="hero-actions">';
    echo '<a class="button" href="' . e(url_for('download_gallery', ['id' => $gallery['id']])) . '">Download gallery</a>';
    if ($galleryMapPoints) {
        echo '<button type="button" class="button secondary map-button" data-gallery-map-url="' . e(url_for('gallery_map_data', ['id' => $gallery['id']])) . '" data-gallery-map-title="' . e((string) $gallery['title']) . '">Show gallery map</button>';
    }
    if (picture_game_available($gallery, $pictureGameImages)) {
        echo '<a class="button secondary" href="' . e(url_for('picture_game', ['id' => $gallery['id']])) . '">Play picture game</a>';
    }
    echo '</div>';
    render_breadcrumbs($gallery);
    echo '</section>';
    render_public_gallery_admin_form($gallery);
    if ($children || $images) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<div class="gallery-list-content" data-back-to-top-list>';
    }
    if ($children) {
        echo '<section class="panel"><h2>Subgalleries</h2><div class="grid">';
        foreach ($children as $child) {
            render_gallery_card($child, true);
        }
        echo '</div></section>';
    }
    if ($images) {
        echo '<section class="grid gallery-image-grid" data-gallery-image-list>';
    }
    foreach ($images as $index => $image) {
        // Variable $mediaUrl stores this steps working value.
        $mediaUrl = public_path_schema_ready() ? image_public_media_url($image, $gallery) : url_for('media', ['id' => $image['id']]);
        // Variable $imagePageUrl stores this steps working value.
        $imagePageUrl = image_public_url($image, $gallery);
        // Variable $previewUrl stores this steps working value.
        $previewUrl = thumbnail_url($image, 1600);
        // Variable $imageTags stores this steps working value.
        $imageTags = $imageTagsById[(int) $image['id']] ?? [];
        // Variable $imageHasPublicGps stores this steps working value.
        $imageHasPublicGps = $mapsAllowed && image_has_gps($image);
        // Variable $imageMapPoint stores this steps working value.
        $imageMapPoint = $imageHasPublicGps ? image_map_point($image, $gallery) : null;
        // Variable $altText stores this steps working value.
        $altText = image_alt_text($image, $gallery, $index + 1);
        // Variable $vote stores this steps working value.
        $vote = $votesById[(int) $image['id']] ?? 0;
        echo '<article class="image-card" data-lightbox-image data-image-id="' . (int) $image['id'] . '" data-full-src="' . e($mediaUrl) . '" data-preview-src="' . e($previewUrl) . '" data-page-url="' . e($imagePageUrl) . '" data-gallery-url="' . e(gallery_public_url($gallery)) . '" data-title="' . e($image['title'] ?: $image['filename']) . '" data-description="' . e($image['description']) . '" data-score="' . (int) $image['score'] . '" data-user-vote="' . $vote . '" data-image-width="' . (int) ($image['width'] ?? 0) . '" data-image-height="' . (int) ($image['height'] ?? 0) . '"' . ($imageMapPoint ? ' data-map-point="' . e(json_encode($imageMapPoint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"' : '') . '>';
        echo '<a class="image-preview-link" href="' . e($imagePageUrl) . '">' . thumbnail_picture_html($image, 300, [300, 600, 800, 960], '(min-width: 70rem) 28vw, (min-width: 50rem) 34vw, 90vw', $altText, 'loading="lazy"') . '</a>';
        if ($imageMapPoint) {
            echo '<button type="button" class="photo-map-pin" data-photo-map aria-label="Show photo location" title="Show photo location">&#128205;</button>';
        }
        echo '<div class="image-meta"><h2>' . e($image['title'] ?: $image['filename']) . '</h2><p>' . e($image['description']) . '</p>';
        render_tag_list($imageTags);
        render_vote_form((int) $image['id'], (int) $image['score'], $vote, $votingAllowed);
        echo '</div>';
        render_public_image_admin_form($image);
        echo '</article>';
    }
    if ($images) {
        echo '</section>';
    }
    if ($children || $images) {
        echo '</div>';
        render_back_to_top_button();
        echo '</div>';
    }
    render_lightbox($votingAllowed);
    if ($requestedImage) {
        append_cms_footer_script('document.addEventListener("DOMContentLoaded",function(){var card=document.querySelector("[data-lightbox-image][data-image-id=\"' . (int) $requestedImage['id'] . '\"]");if(card){card.click();}});');
    }
    render_footer();
}

/**
 * Public tag-filter page listing galleries associated with a tag.
 */
function cms_tag(): void
{
    // Variable $tag stores this steps working value.
    $tag = find_tag_by_slug((string) ($_GET['slug'] ?? ''));
    if (!$tag) {
        cms_not_found();
        return;
    }
    // Variable $galleries stores this steps working value.
    $galleries = public_galleries_for_tag((int) $tag['id']);
    render_header('Tag: ' . (string) $tag['name']);
    echo '<nav class="breadcrumbs" aria-label="Breadcrumbs"><a href="' . e(url_for('home')) . '">Galleries</a><span aria-hidden="true">/</span><span>Tag: ' . e($tag['name']) . '</span></nav>';
    echo '<section class="hero"><h1>Tag: ' . e($tag['name']) . '</h1><p class="muted">' . count($galleries) . ' galleries</p></section>';
    if ($galleries) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<section class="grid gallery-list-content" data-back-to-top-list>';
        foreach ($galleries as $gallery) {
            render_gallery_card($gallery, true);
        }
        echo '</section>';
        render_back_to_top_button();
        echo '</div>';
    }
    render_footer();
}

/**
 * Public picture comparison game for opted-in gallery branches.
 */
function cms_picture_game(): void
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_GET['id'] ?? $_POST['gallery_id'] ?? 0));
    if (!$gallery || !visitor_can_access_gallery($gallery)) {
        cms_not_found();
        return;
    }
    if (request_method() === 'POST') {
        verify_csrf();
        try {
            record_picture_game_vote(
                $gallery,
                (int) ($_POST['left_image_id'] ?? 0),
                (int) ($_POST['right_image_id'] ?? 0),
                (int) ($_POST['winner_image_id'] ?? 0)
            );
        } catch (RuntimeException) {
            admin_log_event('warning', 'picture_game.vote_rejected', 'Picture game vote was rejected.', [
                'gallery_id' => (int) $gallery['id'],
            ]);
        }
        redirect_to(url_for('picture_game', ['id' => $gallery['id']]));
    }
    // Variable $pair stores this steps working value.
    $pair = next_picture_game_pair($gallery);
    // Variable $topImages stores this steps working value.
    $topImages = picture_game_top_images($gallery);
    render_header('Picture game: ' . (string) $gallery['title']);
    render_breadcrumbs($gallery);
    echo '<section class="hero"><h1>Picture game</h1><p>Choose the picture you prefer. Your choice gives that picture one upvote; the other picture is not downvoted.</p></section>';
    if (!$pair) {
        echo '<section class="panel"><h2>All comparisons complete</h2><p>You have already seen every available picture pair in this gallery game. Thank you for voting.</p><p><a class="button" href="' . e(gallery_public_url($gallery)) . '">Back to gallery</a></p></section>';
        render_picture_game_stats($topImages);
        render_footer();
        return;
    }
    echo '<section class="picture-game" data-picture-game>';
    echo '<form method="post" action="' . e(url_for('picture_game')) . '">' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="left_image_id" value="' . (int) $pair['left']['id'] . '">';
    echo '<input type="hidden" name="right_image_id" value="' . (int) $pair['right']['id'] . '">';
    echo '<div class="picture-game-pair">';
    render_picture_game_choice($pair['left'], 'left');
    render_picture_game_choice($pair['right'], 'right');
    echo '</div>';
    echo '</form>';
    echo '<p class="muted">Remaining comparisons for you: ' . max(0, (int) $pair['remaining_pairs'] - 1) . ' of ' . (int) $pair['total_pairs'] . '</p>';
    echo '</section>';
    render_picture_game_stats($topImages);
    render_footer();
}

/**
 * Render one selectable picture-game choice.
 */
function render_picture_game_choice(array $image, string $side): void
{
    // Variable $label stores this steps working value.
    $label = $side === 'left' ? 'Choose left picture' : 'Choose right picture';
    echo '<button class="picture-game-choice" type="submit" name="winner_image_id" value="' . (int) $image['id'] . '" data-picture-game-choice="' . e($side) . '" aria-label="' . e($label) . '">';
    echo '<img decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" srcset="' . e(thumbnail_srcset($image, [300, 600, 800])) . '" sizes="(min-width: 60rem) 30vw, 80vw" alt="' . e($image['title'] ?: $image['filename']) . '">';
    echo '<span><strong>' . e($image['title'] ?: $image['filename']) . '</strong><small>' . e((string) ($image['gallery_title'] ?? '')) . '</small></span>';
    echo '</button>';
}

/**
 * Render top global picture-game winners for one gallery.
 */
function render_picture_game_stats(array $topImages): void
{
    if (!$topImages) {
        return;
    }
    echo '<section class="panel"><h2>Top pictures</h2><div class="grid">';
    foreach ($topImages as $image) {
        echo '<article class="image-card"><img decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" srcset="' . e(thumbnail_srcset($image, [300, 600, 800])) . '" sizes="(min-width: 60rem) 30vw, 80vw" alt="' . e($image['title'] ?: $image['filename']) . '">';
        echo '<div class="image-meta"><h2>' . e($image['title'] ?: $image['filename']) . '</h2><p class="muted">' . (int) $image['game_wins'] . ' game wins, score ' . (int) $image['score'] . '</p></div></article>';
    }
    echo '</div></section>';
}

/**
 * Render gallery ancestor links for public navigation.
 */
function render_breadcrumbs(?array $gallery = null): void
{
    echo '<nav class="breadcrumbs" aria-label="Breadcrumbs">';
    echo '<a href="' . e(url_for('home')) . '">Galleries</a>';
    if ($gallery) {
        foreach (gallery_ancestors($gallery, true) as $ancestor) {
            echo '<span aria-hidden="true">/</span><a href="' . e(gallery_public_url($ancestor)) . '">' . e($ancestor['title']) . '</a>';
        }
        echo '<span aria-hidden="true">/</span><span>' . e($gallery['title']) . '</span>';
    }
    echo '</nav>';
}

/**
 * Render the password prompt for a protected public gallery.
 */
function render_gallery_access_gate(array $gallery, string $error = ''): void
{
    $requirement = gallery_access_requirement($gallery) ?: $gallery;
    render_header((string) $gallery['title']);
    render_breadcrumbs($gallery);
    echo '<section class="panel"><h1>' . e($gallery['title']) . '</h1>';
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    if (empty($requirement['access_password_hash'])) {
        echo '<p>This gallery is available only through its share link.</p>';
    } else {
        echo '<p>This gallery is password protected. Access closes after ' . (int) (gallery_access_lifetime_seconds() / 60) . ' minutes of session time.</p>';
        echo '<form method="post" action="' . e(url_for('gallery_access')) . '" class="form-grid">' . csrf_field();
        echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
        echo '<input type="hidden" name="requirement_id" value="' . (int) $requirement['id'] . '">';
        echo '<label>Password<input name="gallery_password" type="password" required autocomplete="current-password"></label>';
        echo '<button type="submit">Open gallery</button></form>';
    }
    echo '</section>';
    render_footer();
}

/**
 * Process a public protected-gallery password unlock.
 */
function cms_gallery_access(): void
{
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    $gallery = find_gallery((int) ($_POST['gallery_id'] ?? 0));
    if (!$gallery || (string) $gallery['visibility'] !== 'public') {
        cms_not_found();
        return;
    }
    $requirement = gallery_access_requirement($gallery);
    if (!$requirement || empty($requirement['access_password_hash'])) {
        render_gallery_access_gate($gallery, 'This gallery does not have a password login configured.');
        return;
    }
    $password = (string) ($_POST['gallery_password'] ?? '');
    if (!password_verify($password, (string) $requirement['access_password_hash'])) {
        render_gallery_access_gate($gallery, 'The password is incorrect.');
        return;
    }
    grant_gallery_public_access((int) $requirement['id']);
    redirect_to(gallery_public_url($gallery));
}

/**
 * Resolve a share token and redirect to its protected gallery.
 */
function cms_share(): void
{
    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '' || !gallery_access_schema_ready()) {
        cms_not_found();
        return;
    }
    $galleryId = (int) ($_GET['id'] ?? 0);
    if ($galleryId > 0) {
        $stmt = db()->prepare("SELECT * FROM galleries WHERE id = ? AND access_token_hash = ? AND visibility = 'public' LIMIT 1");
        $stmt->execute([$galleryId, hash('sha256', $token)]);
    } else {
        $stmt = db()->prepare("SELECT * FROM galleries WHERE access_token_hash = ? AND visibility = 'public' ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute([hash('sha256', $token)]);
    }
    $gallery = $stmt->fetch();
    if (!$gallery || (!empty($gallery['access_token_expires_at']) && strtotime((string) $gallery['access_token_expires_at']) < time())) {
        cms_not_found();
        return;
    }
    grant_gallery_public_access((int) $gallery['id']);
    redirect_to(gallery_public_url($gallery));
}

/**
 * Build the canonical copyable share URL for one gallery/token pair.
 */
function gallery_share_url(int $galleryId, string $token): string
{
    return url_for('share', ['id' => $galleryId, 'token' => $token]);
}

/**
 * Render one gallery card, including direct cover or child-cover collage.
 */
function render_gallery_card(array $gallery, bool $publicOnly): void
{
    $isProtectedPublicCard = $publicOnly && gallery_access_requirement($gallery) !== null;
    // Variable $cover stores this steps working value.
    $coverAsset = $isProtectedPublicCard ? '' : gallery_cover_asset_url($gallery, $publicOnly);
    $cover = $isProtectedPublicCard || $coverAsset !== '' ? null : gallery_cover_image((int) $gallery['id'], $publicOnly);
    echo '<article class="gallery-card' . ($isProtectedPublicCard ? ' is-protected-gallery' : '') . '"><a class="gallery-card-link" href="' . e(gallery_public_url($gallery)) . '">';
    if ($isProtectedPublicCard) {
        echo '<span class="gallery-collage gallery-locked-preview" aria-hidden="true">Protected</span>';
    } elseif ($coverAsset !== '') {
        echo '<img decoding="async" loading="lazy" src="' . e($coverAsset) . '" alt="">';
    } elseif ($cover) {
        echo thumbnail_picture_html($cover, 800, [300, 800, 960], '(max-width: 299px) 300px, 800px', '', 'loading="lazy"');
    } else {
        // Variable $collage stores this steps working value.
        $collage = gallery_cover_collage_images((int) $gallery['id'], $publicOnly);
        if ($collage) {
            echo '<span class="gallery-collage collage-count-' . count($collage) . '">';
            foreach ($collage as $image) {
                echo thumbnail_picture_html($image, 800, [300, 800, 960], '(max-width: 299px) 300px, 800px', '', 'loading="lazy"');
            }
            echo '</span>';
        }
    }
    echo '<span class="gallery-card-body"><h2>' . e($gallery['title']) . '</h2>';
    echo '<p>' . e($gallery['description']) . '</p>';
    if ($isProtectedPublicCard) {
        echo '<p class="muted">Protected gallery</p></span>';
    } else {
        // Variable $branchImageCount stores this steps working value.
        $branchImageCount = gallery_branch_image_count((int) $gallery['id'], $publicOnly);
        echo '<p class="muted">' . $branchImageCount . ' images</p></span>';
    }
    echo '</a>';
    render_public_gallery_admin_form($gallery);
    if (!$isProtectedPublicCard) {
        render_tag_list(contained_tags_for_gallery($gallery, $publicOnly), 'Containing tags');
    }
    echo '</article>';
}

/**
 * Render logged-in admin metadata controls directly on public gallery pages.
 */
function render_public_gallery_admin_form(array $gallery): void
{
    if (!current_user()) {
        return;
    }
    echo '<details class="inline-editor" data-admin-inline-editor><summary>Edit gallery</summary>';
    echo '<form method="post" action="' . e(url_for('admin_public_update_gallery')) . '" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<label>Gallery name<input name="title" value="' . e((string) $gallery['title']) . '" required></label>';
    echo '<label>Description<textarea name="description">' . e((string) $gallery['description']) . '</textarea></label>';
    echo '<div class="bulk-row"><button type="submit" name="action" value="save">Save</button>';
    echo '<button type="submit" class="secondary" name="action" value="publish">Publish</button>';
    echo '<button type="submit" class="secondary" name="action" value="hide">Hide from public</button>';
    echo '<button type="submit" class="secondary" name="action" value="delete">Remove from CMS</button>';
    echo '<a class="button secondary" href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '">Admin edit</a></div>';
    echo '</form></details>';
}

/**
 * Render logged-in admin metadata controls for a public image card.
 */
function render_public_image_admin_form(array $image): void
{
    if (!current_user()) {
        return;
    }
    echo '<details class="inline-editor image-inline-editor" data-admin-inline-editor><summary>Edit photo</summary>';
    echo '<form method="post" action="' . e(url_for('admin_public_update_image')) . '" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="image_id" value="' . (int) $image['id'] . '">';
    echo '<label>Photo title<input name="title" value="' . e((string) ($image['title'] ?: pathinfo((string) $image['filename'], PATHINFO_FILENAME))) . '"></label>';
    echo '<label>Description<textarea name="description">' . e((string) $image['description']) . '</textarea></label>';
    echo '<div class="bulk-row"><button type="submit" name="action" value="save">Save</button>';
    echo '<button type="submit" class="secondary" name="action" value="publish">Publish</button>';
    echo '<button type="submit" class="secondary" name="action" value="hide">Hide from public</button>';
    echo '<button type="submit" class="secondary" name="action" value="delete">Remove from CMS</button>';
    echo '<a class="button secondary" href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '">Admin edit</a></div>';
    echo '</form></details>';
}

/**
 * Render clickable tag pills.
 */
function render_tag_list(array $tags, ?string $label = null): void
{
    if (!$tags) {
        return;
    }
    echo '<p class="tag-list">';
    if ($label !== null) {
        echo '<span class="tag-list-label">' . e($label) . '</span>';
    }
    foreach ($tags as $tag) {
        echo '<a class="tag" href="' . e(url_for('tag', ['slug' => $tag['slug']])) . '">' . e($tag['name']) . '</a>';
    }
    echo '</p>';
}

/**
 * Render the public vote controls and current vote state.
 */
function render_vote_form(int $imageId, int $score, int $currentVote, bool $votingAllowed = true): void
{
    if (!$votingAllowed) {
        return;
    }
    echo '<form class="vote-row" method="post" action="' . e(url_for('vote')) . '" data-vote-form>';
    echo '<input type="hidden" name="image_id" value="' . $imageId . '">';
    echo csrf_field();
    echo '<span>Score: <strong data-score-for="' . $imageId . '">' . $score . '</strong></span>';
    echo '<button type="submit" name="vote" value="1" class="' . ($currentVote === 1 ? 'is-active' : '') . '" aria-pressed="' . ($currentVote === 1 ? 'true' : 'false') . '" aria-label="Vote up">&#9650;</button>';
    echo '<button type="submit" name="vote" value="-1" class="' . ($currentVote === -1 ? 'is-active' : '') . '" aria-pressed="' . ($currentVote === -1 ? 'true' : 'false') . '" aria-label="Vote down">&#9660;</button>';
    echo '</form>';
}

/**
 * Render the lightbox shell used by public gallery JavaScript.
 */
function render_lightbox(bool $votingAllowed = true): void
{
    echo '<div class="lightbox" data-lightbox hidden>';
    echo '<button class="lightbox-close lightbox-hud" type="button" data-lightbox-action="close">Close</button>';
    echo '<button type="button" class="lightbox-nav lightbox-previous lightbox-hud" data-lightbox-action="previous" aria-label="Previous image">&lt;</button>';
    echo '<figure><button type="button" class="lightbox-stage-link" data-lightbox-stage aria-label="Toggle fullscreen image"><img decoding="async" data-lightbox-img alt=""></button><figcaption class="lightbox-meta"><div class="lightbox-toolbar"><span class="lightbox-counter" data-lightbox-counter></span><button type="button" class="lightbox-fullscreen-link" data-lightbox-action="fullscreen" aria-label="Toggle fullscreen" title="Toggle fullscreen">F fullscreen</button><button type="button" class="lightbox-map-button" data-lightbox-map hidden>&#128205; Map</button></div><div class="lightbox-score-badge">Score <strong data-lightbox-score data-score-for="">0</strong></div><h2 data-lightbox-title></h2><p class="lightbox-description" data-lightbox-description></p>' . ($votingAllowed ? '<div class="lightbox-vote-panel"><form class="vote-row lightbox-vote" method="post" action="' . e(url_for('vote')) . '" data-vote-form data-lightbox-vote-form><input type="hidden" name="image_id" value="">' . csrf_field() . '<span class="lightbox-vote-label">Vote</span><button type="submit" name="vote" value="1" aria-label="Vote up" title="Vote up">&#9650;</button><button type="submit" name="vote" value="-1" aria-label="Vote down" title="Vote down">&#9660;</button><span class="lightbox-vote-indicator" data-lightbox-vote-indicator>No vote</span></form></div>' : '') . '</figcaption><div class="lightbox-map-split" data-lightbox-map-split hidden><button type="button" class="lightbox-map-split-close" data-lightbox-map-split-close aria-label="Close map split">Close map</button><div class="lightbox-map-split-title" data-lightbox-map-split-title></div><div class="lightbox-map-split-canvas" data-lightbox-map-split-canvas></div></div></figure>';
    echo '<button type="button" class="lightbox-nav lightbox-next lightbox-hud" data-lightbox-action="next" aria-label="Next image">&gt;</button>';
    echo '<button type="button" class="lightbox-fullscreen-button lightbox-hud" data-lightbox-action="fullscreen" aria-label="Toggle fullscreen" title="Toggle fullscreen">F</button>';
    echo '<button type="button" class="lightbox-mobile-fullscreen-button" data-lightbox-action="fullscreen" aria-label="Toggle fullscreen" title="Toggle fullscreen">&#9974;</button>';
    echo '</div>';
}

/**
 * Stream a generated thumbnail after the same visibility checks as originals.
 */
function cms_thumb(): void
{
    // Variable $image stores this steps working value.
    $image = find_image((int) ($_GET['id'] ?? 0));
    // Variable $size stores this steps working value.
    $size = (int) ($_GET['size'] ?? 0);
    // Variable $format stores this steps working value.
    $format = (string) ($_GET['format'] ?? 'jpg');
    if (!$image || !in_array($size, thumbnail_sizes(), true) || !in_array($format, ['jpg', 'webp'], true)) {
        cms_not_found();
        return;
    }
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $image['gallery_id']);
    if (!$gallery || (($image['visibility'] !== 'public' || !visitor_can_access_gallery($gallery)) && !current_user())) {
        cms_not_found();
        return;
    }
    try {
        // Variable $path stores this steps working value.
        $path = thumbnail_abs_path($image, $gallery, $size, $format);
    } catch (RuntimeException) {
        cms_not_found();
        return;
    }
    if (!is_file($path)) {
        cms_not_found();
        return;
    }
    header('Content-Type: ' . ($format === 'webp' ? 'image/webp' : 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    $cacheControl = gallery_access_requirement($gallery) && !current_user() ? 'private, max-age=300' : 'public, max-age=31536000, immutable';
    send_conditional_file_headers($path, $cacheControl);
    header('Content-Length: ' . filesize($path));
    readfile($path);
}


/**
 * Stream a generated thumbnail addressed through the clean public image URL.
 */
function cms_public_thumb(): void
{
    $resolved = resolve_public_gallery_path((string) ($_GET['public_path'] ?? ''), !current_user());
    $gallery = $resolved['gallery'];
    $image = $resolved['image'];
    $size = (int) ($_GET['size'] ?? 0);
    $format = (string) ($_GET['format'] ?? 'jpg');

    if (!$gallery || !$image || !in_array($size, thumbnail_sizes(), true) || !in_array($format, ['jpg', 'webp'], true)) {
        cms_not_found();
        return;
    }
    if (($image['visibility'] !== 'public' || !visitor_can_access_gallery($gallery)) && !current_user()) {
        cms_not_found();
        return;
    }

    try {
        $path = thumbnail_abs_path($image, $gallery, $size, $format);
    } catch (RuntimeException) {
        cms_not_found();
        return;
    }
    if (!is_file($path)) {
        cms_not_found();
        return;
    }

    header('Content-Type: ' . ($format === 'webp' ? 'image/webp' : 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    $cacheControl = gallery_access_requirement($gallery) && !current_user() ? 'private, max-age=300' : 'public, max-age=31536000, immutable';
    send_conditional_file_headers($path, $cacheControl);
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

/**
 * Stream an original image addressed through the clean public image URL.
 */
function cms_public_media(): void
{
    $resolved = resolve_public_gallery_path((string) ($_GET['public_path'] ?? ''), !current_user());
    $gallery = $resolved['gallery'];
    $image = $resolved['image'];

    if (!$gallery || !$image) {
        cms_not_found();
        return;
    }
    if (($image['visibility'] !== 'public' || !visitor_can_access_gallery($gallery)) && !current_user()) {
        cms_not_found();
        return;
    }

    $path = image_abs_path($image, $gallery);
    if (!is_file($path)) {
        cms_not_found();
        return;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($path) ?: mime_content_type($path));
    if (!str_starts_with($mime, 'image/')) {
        cms_not_found();
        return;
    }

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename((string) $image['filename']) . '"');
    $cacheControl = gallery_access_requirement($gallery) && !current_user() ? 'private, max-age=300' : 'public, max-age=31536000, immutable';
    send_conditional_file_headers($path, $cacheControl);
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

/**
 * Stream an uploaded gallery thumbnail asset.
 */
function cms_gallery_cover_asset(): void
{
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    if (!$gallery) {
        cms_not_found();
        return;
    }
    $coverPath = gallery_cover_path($gallery);
    if ($coverPath === null) {
        cms_not_found();
        return;
    }
    $path = gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $coverPath);
    if (!is_file($path)) {
        cms_not_found();
        return;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($path) ?: mime_content_type($path));
    if (!str_starts_with($mime, 'image/')) {
        cms_not_found();
        return;
    }
    if (extension_loaded('gd')) {
        $info = @getimagesize($path);
        $source = $info !== false ? image_create_from_path($path, (string) ($info['mime'] ?? $mime)) : false;
        if ($info !== false && $source && ((int) $info[0] > 800 || (int) $info[1] > 800)) {
            $scale = min(1.0, 800 / max((int) $info[0], (int) $info[1]));
            $targetWidth = max(1, (int) round((int) $info[0] * $scale));
            $targetHeight = max(1, (int) round((int) $info[1] * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, (int) $info[0], (int) $info[1]);
            imageinterlace($target, true);
            header('Content-Type: image/jpeg');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=86400');
            imagejpeg($target, null, 82);
            imagedestroy($target);
            imagedestroy($source);
            return;
        }
        if ($source) {
            imagedestroy($source);
        }
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=86400');
    readfile($path);
}

/**
 * Stream a protected image file after checking gallery/image visibility.
 */
function cms_media(): void
{
    // Variable $image stores this steps working value.
    $image = find_image((int) ($_GET['id'] ?? 0));
    if (!$image) {
        cms_not_found();
        return;
    }
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $image['gallery_id']);
    if (!$gallery || (($image['visibility'] !== 'public' || !visitor_can_access_gallery($gallery)) && !current_user())) {
        cms_not_found();
        return;
    }
    // Variable $path stores this steps working value.
    $path = image_abs_path($image, $gallery);
    if (!is_file($path)) {
        cms_not_found();
        return;
    }
    // Variable $finfo stores this steps working value.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    // Variable $mime stores this steps working value.
    $mime = (string) ($finfo->file($path) ?: mime_content_type($path));
    if (!str_starts_with($mime, 'image/')) {
        cms_not_found();
        return;
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename((string) $image['filename']) . '"');
    $cacheControl = gallery_access_requirement($gallery) && !current_user() ? 'private, max-age=300' : 'public, max-age=31536000, immutable';
    send_conditional_file_headers($path, $cacheControl);
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

/**
 * Record or update the current visitor's vote for an image.
 */
function cms_vote(): void
{
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    // Variable $imageId stores this steps working value.
    $imageId = (int) ($_POST['image_id'] ?? 0);
    verify_vote_rate_limit($imageId);
    // Variable $vote stores this steps working value.
    $vote = (int) ($_POST['vote'] ?? 0);
    $image = find_image($imageId);
    $gallery = $image ? find_gallery((int) $image['gallery_id']) : null;
    if (!in_array($vote, [-1, 1], true) || !$image || !$gallery || !gallery_voting_allowed($gallery) || (($image['visibility'] !== 'public' || !visitor_can_access_gallery($gallery)) && !current_user())) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid vote.']);
        return;
    }
    // Variable $user stores this steps working value.
    $user = current_user();
    if ($user) {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
        $stmt->execute([$imageId, (int) $user['id'], $vote, now_sql(), now_sql()]);
    } else {
        // Variable $hash stores this steps working value.
        $hash = visitor_hash();
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
        $stmt->execute([$imageId, $hash, $vote, now_sql(), now_sql()]);
    }
    // Variable $result stores this steps working value.
    $result = ['image_id' => $imageId, 'score' => vote_score($imageId), 'vote' => $vote];
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode($result);
        return;
    }
    redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
}

/**
 * Download a public ZIP for one gallery.
 */
function cms_download_gallery(): void
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    if (!$gallery || !visitor_can_access_gallery($gallery)) {
        cms_not_found();
        return;
    }
    // Variable $zip stores this steps working value.
    $zip = build_gallery_zip((int) $gallery['id'], true);
    send_download($zip, slugify((string) $gallery['title']) . '.zip');
}

/**
 * Download an admin ZIP containing all imported galleries.
 */
function cms_download_all(): void
{
    require_admin();
    // Variable $zip stores this steps working value.
    $zip = build_all_zip();
    send_download($zip, 'all-galleries.zip');
}

/**
 * Serve robots.txt for search engines.
 */
function cms_robots_txt(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "Disallow: /index.php?page=admin\n";
    echo "Disallow: /index.php?page=admin_\n";
    echo "Disallow: /admin/\n";
    echo "Sitemap: " . public_base_url() . "/sitemap.xml\n";
}

/**
 * Serve sitemap.xml for public gallery pages.
 */
function cms_sitemap_xml(): void
{
    output_sitemap_xml();
}

/**
 * Render and process the admin login form.
 */
function cms_admin_login(): void
{
    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([(string) ($_POST['username'] ?? '')]);
        // Variable $user stores this steps working value.
        $user = $stmt->fetch();
        if ($user && password_verify((string) ($_POST['password'] ?? ''), (string) $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            redirect_to(url_for('admin'));
        }
        // Variable $error stores this steps working value.
        $error = 'Invalid username or password.';
    }
    render_header('Admin login');
    if (isset($error)) {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>Admin login</h1><form method="post" class="form-grid">';
    echo csrf_field();
    echo '<label>Username<input name="username" required autocomplete="username"></label>';
    echo '<label>Password<input name="password" type="password" required autocomplete="current-password"></label>';
    echo '<button type="submit">Log in</button></form></section>';
    render_footer();
}

/**
 * Log the admin out of the current session.
 */
function cms_admin_logout(): void
{
    unset($_SESSION['user_id']);
    redirect_to(url_for('home'));
}

/**
 * Render and process visual theme settings.
 */
function cms_admin_theme(): void
{
    require_admin();
    if (request_method() === 'POST') {
        verify_csrf();
        if (!empty($_POST['reset_custom_css'])) {
            if (is_file(custom_css_path())) {
                unlink(custom_css_path());
            }
            set_app_setting('custom_css_preset', '');
        } elseif (!empty($_POST['reset_favicon'])) {
            remove_stored_favicon();
        } elseif (!empty($_POST['reset_theme_background'])) {
            $path = theme_background_path();
            if ($path !== null) {
                $absolute = dirname(__DIR__) . '/' . ltrim($path, '/');
                if (is_file($absolute)) {
                    @unlink($absolute);
                }
            }
            set_app_setting('theme_background_path', '');
        } elseif (!empty($_POST['reset_all_gallery_backgrounds'])) {
            if (gallery_background_source_schema_ready()) {
                db()->exec("UPDATE galleries SET background_source = NULL, updated_at = " . db()->quote(now_sql()) . " WHERE background_source IS NOT NULL");
            }
        } elseif (!empty($_POST['reset_theme_overrides'])) {
            clear_theme_overrides();
        } else {
            // Variable $siteName stores this steps working value.
            $siteName = trim((string) ($_POST['site_name'] ?? ''));
            set_app_setting('site_name', $siteName !== '' ? substr($siteName, 0, 120) : 'Gallery CMS');
            $themeControlsChanged = (string) ($_POST['theme_controls_changed'] ?? '') === '1';
            // Variable $preset stores this steps working value.
            $preset = (string) ($_POST['custom_css_preset'] ?? '');
            // Variable $presetPath stores this steps working value.
            $presetPath = custom_css_preset_path($preset);
            $customCssChanged = false;
            if ($presetPath !== null) {
                copy($presetPath, custom_css_path());
                set_app_setting('custom_css_preset', $preset);
                $customCssChanged = true;
            }
            if (!empty($_FILES['custom_css']['tmp_name']) && is_uploaded_file($_FILES['custom_css']['tmp_name'])) {
                // Variable $name stores this steps working value.
                $name = strtolower((string) ($_FILES['custom_css']['name'] ?? ''));
                if (str_ends_with($name, '.css')) {
                    move_uploaded_file($_FILES['custom_css']['tmp_name'], custom_css_path());
                    set_app_setting('custom_css_preset', 'uploaded');
                    $customCssChanged = true;
                }
            }
            if (!empty($_FILES['favicon_source']['tmp_name']) && is_uploaded_file($_FILES['favicon_source']['tmp_name'])) {
                $name = strtolower((string) ($_FILES['favicon_source']['name'] ?? ''));
                if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) {
                    $info = @getimagesize((string) $_FILES['favicon_source']['tmp_name']);
                    if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
                        throw new RuntimeException('The uploaded favicon source is not a valid image.');
                    }
                    store_uploaded_favicon($_FILES['favicon_source'], (string) ($_POST['favicon_cropped_png'] ?? '') ?: null);
                }
            }
            if (!empty($_FILES['theme_background']['tmp_name']) && is_uploaded_file($_FILES['theme_background']['tmp_name'])) {
                // Variable $name stores this steps working value.
                $name = strtolower((string) ($_FILES['theme_background']['name'] ?? ''));
                if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) {
                    $info = @getimagesize((string) $_FILES['theme_background']['tmp_name']);
                    if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
                        throw new RuntimeException('The uploaded theme background is not a valid image.');
                    }
                    store_uploaded_theme_background($_FILES['theme_background']);
                }
            }
            set_app_setting('theme_background_opacity', (string) max(0, min(100, (int) ($_POST['theme_background_opacity'] ?? 65))));
            $themeBackgroundSource = (string) ($_POST['theme_background_source'] ?? '');
            set_app_setting('theme_background_source', in_array($themeBackgroundSource, ['upload', 'existing', 'collage'], true) ? $themeBackgroundSource : '');
            if ($themeControlsChanged) {
                set_app_setting('theme_accent', sanitize_hex_color((string) $_POST['theme_accent'], '#a5481c'));
                set_app_setting('theme_accent_dark', sanitize_hex_color((string) $_POST['theme_accent_dark'], '#713414'));
                set_app_setting('theme_paper', sanitize_hex_color((string) $_POST['theme_paper'], '#f8f4ec'));
                set_app_setting('theme_panel', sanitize_hex_color((string) $_POST['theme_panel'], '#fffaf0'));
                set_app_setting('theme_gallery_panel', sanitize_hex_color((string) $_POST['theme_gallery_panel'], '#fffaf0'));
                set_app_setting('theme_header_text', sanitize_hex_color((string) $_POST['theme_header_text'], '#0f172a'));
                set_app_setting('theme_hero_text', sanitize_hex_color((string) $_POST['theme_hero_text'], '#0f172a'));
                set_app_setting('theme_radius', (string) max(0, min(32, (int) $_POST['theme_radius'])));
                set_app_setting('theme_font', in_array($_POST['theme_font'] ?? '', ['serif', 'sans'], true) ? (string) $_POST['theme_font'] : 'serif');
            } elseif ($customCssChanged) {
                clear_theme_overrides();
            }
        }
        redirect_to(url_for('admin_theme', ['saved' => 1]));
    }
    // Variable $theme stores this steps working value.
    $theme = theme_settings();
    render_header('Theme');
    echo '<section class="panel"><h1>Theme</h1><form method="post" enctype="multipart/form-data" class="form-grid" data-theme-form>' . csrf_field();
    echo '<input type="hidden" name="theme_controls_changed" value="0" data-theme-controls-changed>';
    echo '<label>Site name<input name="site_name" value="' . e(site_name()) . '" maxlength="120" required></label>';
    echo '<label>Accent color<input type="color" name="theme_accent" value="' . e((string) $theme['accent']) . '" data-theme-override-control></label>';
    echo '<label>Dark accent<input type="color" name="theme_accent_dark" value="' . e((string) $theme['accent_dark']) . '" data-theme-override-control></label>';
    echo '<label>Page background<input type="color" name="theme_paper" value="' . e((string) $theme['paper']) . '" data-theme-override-control></label>';
    echo '<label>Panel background<input type="color" name="theme_panel" value="' . e((string) $theme['panel']) . '" data-theme-override-control></label>';
    echo '<label>Open gallery panel<input type="color" name="theme_gallery_panel" value="' . e((string) $theme['gallery_panel']) . '" data-theme-override-control></label>';
    echo '<label>Header title color<input type="color" name="theme_header_text" value="' . e((string) $theme['header_text']) . '" data-theme-override-control></label>';
    echo '<label>Gallery title color<input type="color" name="theme_hero_text" value="' . e((string) $theme['hero_text']) . '" data-theme-override-control></label>';
    echo '<fieldset class="form-grid"><legend>Favicon</legend>';
    $faviconUrl = favicon_asset_url();
    if ($faviconUrl !== '') {
        $faviconVersion = (string) app_setting('favicon_version', '1');
        echo '<div class="favicon-current"><img src="' . e($faviconUrl) . '&s=48&v=' . e($faviconVersion) . '" alt="Current favicon"><p class="muted">Current favicon is generated as 32px, 48px, and 180px PNG variants.</p></div>';
    } else {
        echo '<p class="muted">No favicon is stored yet. Browsers will use their default icon until one is saved.</p>';
    }
    echo '<label>Favicon source image<input type="file" name="favicon_source" accept="image/png,image/jpeg,image/gif,image/webp,image/*" data-favicon-input><span class="muted">Upload a square-friendly photo or logo. The cropper saves a browser-ready square PNG favicon.</span></label>';
    echo '<input type="hidden" name="favicon_cropped_png" value="" data-favicon-cropped>';
    echo '<div class="favicon-cropper" data-favicon-cropper hidden><div class="favicon-crop-stage"><canvas width="256" height="256" data-favicon-canvas></canvas></div><label>Zoom<input type="range" min="1" max="3" step="0.01" value="1" data-favicon-zoom></label><div class="favicon-preview-row"><canvas width="48" height="48" data-favicon-preview></canvas><span class="muted">Drag the image to place the square crop. The small preview shows the browser icon scale.</span></div></div>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid"><legend>Background</legend>';
    echo '<label>Theme background image<input type="file" name="theme_background" accept="image/*"></label>';
    $themeBackgroundUrl = theme_background_asset_url();
    if ($themeBackgroundUrl !== '') {
        echo '<p class="muted">Current theme background: <a href="' . e($themeBackgroundUrl) . '" target="_blank" rel="noopener">view stored image</a></p>';
    } else {
        echo '<p class="muted">No global theme background image is stored yet.</p>';
    }
    echo '<label>Background transparency <span data-theme-background-opacity-display>' . (int) ($theme['background_opacity'] ?? 65) . '%</span><input type="range" name="theme_background_opacity" min="0" max="100" value="' . (int) ($theme['background_opacity'] ?? 65) . '" data-theme-override-control data-theme-background-opacity><span class="muted">Higher means more visible image, lower means more of the color underneath.</span></label>';
    echo '<label>Gallery background fallback<select name="theme_background_source" data-theme-override-control><option value=""' . (theme_background_source() === null ? ' selected' : '') . '>No fallback set</option><option value="upload"' . (theme_background_source() === 'upload' ? ' selected' : '') . '>Upload new image</option><option value="existing"' . (theme_background_source() === 'existing' ? ' selected' : '') . '>Pick from existing gallery images</option><option value="collage"' . (theme_background_source() === 'collage' ? ' selected' : '') . '>Generate collage from public galleries</option></select><span class="muted">Used when a gallery does not set its own background source.</span></label>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_all_gallery_backgrounds" value="1" formnovalidate>Reset all gallery backgrounds</button></div>';
    echo '</fieldset>';
    echo '<label>Rounded corners<input type="range" name="theme_radius" min="0" max="32" value="' . (int) $theme['radius'] . '" data-theme-override-control></label>';
    echo '<label>Font style<select name="theme_font" data-theme-override-control><option value="serif"' . ($theme['font'] === 'serif' ? ' selected' : '') . '>Classic serif</option><option value="sans"' . ($theme['font'] === 'sans' ? ' selected' : '') . '>Clean sans-serif</option></select></label>';
    // Variable $selectedPreset stores this steps working value.
    $selectedPreset = (string) app_setting('custom_css_preset', '');
    echo '<label>Custom CSS skin<select name="custom_css_preset"><option value="">Keep current custom CSS</option>';
    foreach (custom_css_presets() as $filename => $path) {
        // Variable $label stores this steps working value.
        $label = ucwords(str_replace(['-', '_'], ' ', pathinfo((string) $filename, PATHINFO_FILENAME)));
        echo '<option value="' . e((string) $filename) . '"' . ($selectedPreset === $filename ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select><span class="muted">Selecting a skin copies it from <code>custom_css/</code> into the active custom stylesheet.</span></label>';
    echo '<label>Custom CSS file<input type="file" name="custom_css" accept=".css,text/css"></label>';
    echo '<p class="muted">Uploaded CSS is saved as <code>public/assets/custom.css</code> and loaded after the built-in stylesheet and theme controls.</p>';
    echo '<div class="bulk-row"><button type="submit">Save theme</button><button type="submit" class="secondary" name="reset_theme_overrides" value="1" formnovalidate>Reset to CSS</button><button type="submit" class="secondary" name="reset_custom_css" value="1" formnovalidate>Reset custom CSS</button><button type="submit" class="secondary" name="reset_theme_background" value="1" formnovalidate>Remove theme background</button><button type="submit" class="secondary" name="reset_favicon" value="1" formnovalidate>Remove favicon</button></div></form></section>';
    render_footer();
}

/**
 * Stream the stored global theme background image.
 */
function cms_theme_background_asset(): void
{
    $relative = theme_background_path();
    if ($relative === null) {
        cms_not_found();
        return;
    }
    $absolute = dirname(__DIR__) . '/' . ltrim($relative, '/');
    if (!is_file($absolute)) {
        cms_not_found();
        return;
    }
    $mime = mime_content_type($absolute) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($absolute));
    header('Cache-Control: public, max-age=86400');
    readfile($absolute);
}

/**
 * Stream the stored favicon image variant.
 */
function cms_favicon_asset(): void
{
    $size = favicon_safe_size((int) ($_GET['s'] ?? 32));
    $relative = favicon_path($size);
    if ($relative === null) {
        cms_not_found();
        return;
    }
    $absolute = dirname(__DIR__) . '/' . ltrim($relative, '/');
    if (!is_file($absolute)) {
        cms_not_found();
        return;
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . (string) filesize($absolute));
    header('Cache-Control: public, max-age=604800');
    readfile($absolute);
}

/**
 * Render and process the logged-in admin account settings.
 */
function cms_admin_account(): void
{
    require_admin();
    // Variable $user stores this steps working value.
    $user = current_user();
    if (!$user) {
        redirect_to(url_for('admin_login'));
    }
    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $currentPassword stores this steps working value.
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        // Variable $newUsername stores this steps working value.
        $newUsername = trim((string) ($_POST['username'] ?? ''));
        // Variable $newPassword stores this steps working value.
        $newPassword = (string) ($_POST['new_password'] ?? '');
        // Variable $confirmPassword stores this steps working value.
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        // Variable $errors stores this steps working value.
        $errors = [];

        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT username, password_hash FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        // Variable $account stores this steps working value.
        $account = $stmt->fetch();
        if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        }
        if ($newUsername === '') {
            $errors[] = 'Username is required.';
        }
        if ($newPassword !== '' && $newPassword !== $confirmPassword) {
            $errors[] = 'New password confirmation does not match.';
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        }
        if ($newUsername !== '') {
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
            $stmt->execute([$newUsername, (int) $user['id']]);
            if ($stmt->fetch()) {
                $errors[] = 'That username is already in use.';
            }
        }
        if (!$errors) {
            $sql = 'UPDATE users SET username = ?, updated_at = ?';
            // Variable $params stores this steps working value.
            $params = [$newUsername, now_sql()];
            if ($newPassword !== '') {
                $sql .= ', password_hash = ?';
                $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = ?';
            $params[] = (int) $user['id'];
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            redirect_to(url_for('admin_account', ['saved' => 1]));
        }
        // Variable $error stores this steps working value.
        $error = implode(' ', $errors);
    }
    render_header('Account');
    if (isset($_GET['saved'])) {
        echo '<div class="notice">Account updated.</div>';
    }
    if (isset($error)) {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>Account</h1><form method="post" class="form-grid">';
    echo csrf_field();
    echo '<label>Username<input name="username" required autocomplete="username" value="' . e((string) $user['username']) . '"></label>';
    echo '<label>Current password<input name="current_password" type="password" required autocomplete="current-password"></label>';
    echo '<label>New password<input name="new_password" type="password" autocomplete="new-password"></label>';
    echo '<label>Confirm new password<input name="confirm_password" type="password" autocomplete="new-password"></label>';
    echo '<p class="muted">Leave the new password fields empty to keep the current password.</p>';
    echo '<button type="submit">Save account</button></form></section>';
    render_footer();
}

/**
 * Check GitHub for newer application versions and install them on request.
 */
function cms_admin_update(): void
{
    require_admin();
    $error = null;

    if (request_method() === 'POST') {
        verify_csrf();
        try {
            $action = (string) ($_POST['update_action'] ?? 'stable_update');
            if ($action === 'beta_install') {
                $result = install_application_beta((string) ($_POST['beta_commit'] ?? ''));
                admin_log_event('info', 'update.beta_installed', 'Admin installed a beta application build.', $result);
                $_SESSION['admin_update_notice'] = 'Installed beta code ' . (string) $result['version'] . '. Copied ' . (int) $result['files_copied'] . ' files and applied ' . count((array) $result['migrations']) . ' migrations.';
            } elseif ($action === 'beta_revert') {
                $result = restore_application_stable_release();
                admin_log_event('info', 'update.beta_reverted', 'Admin restored beta application build from the stable branch head.', $result);
                $_SESSION['admin_update_notice'] = 'Restored the stable release from the GitHub branch head.';
            } else {
                $result = install_application_update();
                admin_log_event('info', 'update.installed', 'Admin installed an application update.', $result);
                $_SESSION['admin_update_notice'] = 'Updated to version ' . (string) $result['version'] . '. Copied ' . (int) $result['files_copied'] . ' files and applied ' . count((array) $result['migrations']) . ' migrations.';
            }
            redirect_to(url_for('admin_update'));
        } catch (Throwable $exception) {
            admin_log_event('warning', 'update.failed', 'Application update failed.', ['error' => $exception->getMessage()]);
            $error = $exception->getMessage();
        }
    }

    $notice = (string) ($_SESSION['admin_update_notice'] ?? '');
    unset($_SESSION['admin_update_notice']);
    $status = check_application_update();
    $betaActive = application_update_beta_active();
    render_header('Application updates');
    echo '<section class="hero"><h1>Application updates</h1><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a>';
    echo '<a class="button secondary" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">Open GitHub</a>';
    echo '</nav></section>';
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="notice">Update failed: ' . e($error) . '</div>';
    }
    echo '<section class="panel"><h2>Status</h2>';
    echo '<p>Installed version: <strong>' . e(cms_current_version()) . '</strong></p>';
    if ($betaActive) {
        echo '<p>Active channel: <strong>beta</strong></p>';
        echo '<p>Installed beta code: <code>' . e(application_update_beta_commit()) . '</code></p>';
    } else {
        echo '<p>Active channel: <strong>stable</strong></p>';
    }
    echo '<p>Repository: <a href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">' . e(CMS_GITHUB_REPOSITORY) . '</a></p>';
    if (!empty($status['error'])) {
        echo '<p class="muted">Could not check for updates: ' . e((string) $status['error']) . '</p>';
    } else {
        echo '<p>Latest version on GitHub: <strong>' . e((string) $status['latest_version']) . '</strong></p>';
        echo '<p class="muted">Checked branch: ' . e((string) $status['branch']) . '</p>';
        if (!empty($status['version_source'])) {
            echo '<p class="muted">Version source: ' . e((string) $status['version_source']) . '</p>';
        }
        if (!empty($status['update_available'])) {
            echo '<form method="post" class="form-grid">' . csrf_field();
            echo '<input type="hidden" name="update_action" value="stable_update">';
            echo '<p>A newer version is available. The updater will download the GitHub branch archive, back up overwritten files under <code>cache/updates/backups</code>, and keep local config, galleries, cache, and custom CSS untouched.</p>';
            echo '<button type="submit" class="is-update-pending">Update(1)</button></form>';
        } else {
            echo '<p class="muted">This installation is current.</p>';
        }
    }
    echo '<hr><h3>Beta build</h3>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="update_action" value="beta_install">';
    echo '<label>Beta code<input name="beta_commit" value="' . e(application_update_beta_commit()) . '" placeholder="abcdef1234567890"></label>';
    echo '<p class="muted">Enter the beta code for the snapshot you want to install.</p>';
    echo '<button type="submit">Install beta snapshot</button>';
    echo '</form>';
    if ($betaActive) {
        echo '<form method="post" class="form-grid form-grid-spaced">' . csrf_field();
        echo '<input type="hidden" name="update_action" value="beta_revert">';
        echo '<p class="muted">This downloads the stable branch head from GitHub and restores application files from that release. Database changes from the beta are not rolled back automatically.</p>';
        echo '<button type="submit" class="button secondary">Restore stable release</button>';
        echo '</form>';
    }
    echo '</section>';
    render_footer();
}

/**
 * Provide a dedicated admin-only reset page for restoring the stable branch head.
 */
function cms_admin_reset(): void
{
    require_admin();
    $error = null;
    $notice = '';

    if (request_method() === 'POST') {
        verify_csrf();
        try {
            $result = restore_application_stable_release();
            admin_log_event('info', 'update.stable_restored', 'Admin restored the stable branch head from the reset page.', $result);
            $notice = 'Restored the stable branch head. Copied ' . (int) $result['files_copied'] . ' files.';
        } catch (Throwable $exception) {
            admin_log_event('warning', 'update.reset_failed', 'Stable branch reset failed.', ['error' => $exception->getMessage()]);
            $error = $exception->getMessage();
        }
    }

    render_header('Reset application');
    echo '<section class="hero"><h1>Reset application</h1><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_update')) . '">Open updates</a>';
    echo '</nav></section>';
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="notice">Reset failed: ' . e($error) . '</div>';
    }
    echo '<section class="panel"><h2>Restore stable branch head</h2>';
    echo '<p>This replaces the application files with the current `main` branch head from GitHub, which is useful if a beta build broke the site.</p>';
    echo '<p class="muted">You must be logged in as an administrator. The action uses the same restore logic as the admin update screen.</p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<button type="submit" class="button danger">Reset to stable branch head</button>';
    echo '</form></section>';
    render_footer();
}

/**
 * Admin dashboard for gallery scanning, publishing, and bulk actions.
 */
function cms_admin(): void
{
    require_admin();
    // Self-heal voting/game state only when the admin dashboard is opened.
    $repairedVotingGame = sync_gallery_voting_game_state();
    if ($repairedVotingGame > 0) {
        admin_log_event('info', 'gallery.voting_game_synced', 'Admin dashboard repaired gallery voting/game settings.', [
            'gallery_count' => $repairedVotingGame,
        ]);
    }
    sync_gallery_parent_ids();
    // Variable $galleries stores this steps working value.
    $galleries = db()->query("SELECT g.*, parent.title AS parent_title, COUNT(i.id) AS image_count FROM galleries g LEFT JOIN galleries parent ON parent.id = g.parent_id LEFT JOIN images i ON i.gallery_id = g.id AND i.relative_path NOT LIKE '%/%' GROUP BY g.id, parent.title ORDER BY g.folder_path")->fetchAll();
    // Variable $collapsedIds stores this steps working value.
    $collapsedIds = array_flip(collapsed_gallery_ids());
    // Variable $pictureGameReady stores this steps working value.

    $pictureGameReady = picture_game_schema_ready();
    // Variable $gpsMapReady stores this steps working value.
    $gpsMapReady = exif_gps_schema_ready();
    // Variable $votingReady stores this steps working value.
    $votingReady = gallery_voting_schema_ready();
    // Variable $migrationPending stores this steps working value.
    $migrationPending = pending_migrations_exist();
    // Variable $accessReady stores this steps working value.
    $accessReady = gallery_access_schema_ready();
    $updatePending = application_update_pending();
    $updateButtonClass = $updatePending ? 'button secondary is-update-pending' : 'button secondary';
    $updateLabel = application_update_nav_label($updatePending);
    $thumbnailSummary = thumbnail_maintenance_summary(null, 1000);
    render_header('Admin dashboard');
    echo '<section class="hero"><h1>Admin dashboard</h1><nav class="nav">';
    echo '<form method="post" action="' . e(url_for('admin_discover')) . '" class="inline-action-form" data-refresh-galleries-form>' . csrf_field();
    echo '<button type="submit">Check for new gallery folders</button>';
    echo '</form>';
    echo '<a class="button secondary" href="' . e(url_for('admin_new_gallery')) . '">Create empty gallery</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_upload')) . '">Upload photos</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_logs')) . '">View log</a>';
    echo '<a class="' . e($updateButtonClass) . '" href="' . e(url_for('admin_update')) . '">' . e($updateLabel) . '</a>';
    if ($migrationPending) {
        echo '<form method="post" action="' . e(url_for('admin_run_migrations')) . '" class="inline-action-form">' . csrf_field();
        echo '<button type="submit" class="button is-update-pending">Run database migration</button>';
        echo '</form>';
    }
    echo '<a class="button secondary" href="' . e(url_for('download_all')) . '">Download all galleries</a>';
    echo '<button type="button" class="secondary" data-create-all-thumbnails>Create all thumbnails</button>';
    echo '<form method="post" action="' . e(url_for('admin_regenerate_paths')) . '" class="inline-action-form" onsubmit="return confirm(\'Regenerate clean public URLs for all galleries and images?\');">' . csrf_field();
    echo '<button type="submit" class="secondary">Regenerate paths</button>';
    echo '</form>';
    echo '</nav></section>';
    if (isset($_GET['deleted_galleries'])) {
        echo '<div class="notice">Deleted ' . (int) $_GET['deleted_galleries'] . ' gallery folder(s).</div>';
    } elseif (isset($_GET['delete_error'])) {
        echo '<div class="notice">Gallery delete failed: ' . e((string) $_GET['delete_error']) . '</div>';
    }
    if (isset($_GET['paths_regenerated'])) {
        echo '<div class="notice">Regenerated clean public paths. Updated ' . (int) ($_GET['gallery_paths'] ?? 0) . ' gallery path(s) and ' . (int) ($_GET['image_paths'] ?? 0) . ' image path(s).</div>';
    } elseif (isset($_GET['paths_error'])) {
        echo '<div class="notice">Path regeneration failed: ' . e((string) $_GET['paths_error']) . '</div>';
    }
    if (isset($_GET['migrations_ran'])) {
        echo '<div class="notice">Applied migrations: ' . e((string) $_GET['migrations_ran']) . '.</div>';
    } elseif (isset($_GET['migrations_current'])) {
        echo '<div class="notice">Database is already current.</div>';
    } elseif (isset($_GET['migration_failed'])) {
        echo '<div class="notice">Migration failed: ' . e((string) $_GET['migration_failed']) . '</div>';
    }
    if ($migrationPending) {
        render_admin_migration_notice('Some admin features still need database migrations.');
    }
    render_admin_thumbnail_maintenance_notice($thumbnailSummary);
    echo '<section class="panel"><h2>Galleries</h2><form method="post" action="' . e(url_for('admin_bulk_galleries')) . '" data-gallery-bulk-form>' . csrf_field();
    echo '<div class="bulk-row">';
    echo '<label>Filter galleries<select data-gallery-visibility-filter><option value="all">All statuses</option><option value="draft">Only drafts</option><option value="public">Only public</option><option value="private">Only private</option></select></label>';
    echo '<span class="muted" data-gallery-filter-summary></span>';
    echo '<label><input type="checkbox" data-select-all="gallery_ids[]"> Select displayed galleries</label><label>Bulk action<select name="action"><option value="scan">Scan/import images</option><option value="thumbs">Create thumbnails</option><option value="public">Set public</option><option value="draft">Set draft</option><option value="private">Set private</option><option value="maps_on">Enable GPS maps</option><option value="maps_off">Disable GPS maps</option><option value="delete">Delete selected galleries</option>';
    if ($votingReady) {
        echo '<option value="vote_on">Enable voting</option><option value="vote_off">Disable voting</option>';
    }
    if ($pictureGameReady) {
        echo '<option value="game_on">Enable picture game</option><option value="game_off">Disable picture game</option>';
    }
    echo '</select></label><button type="submit">Apply to selected</button><button type="button" class="secondary" data-gallery-tree-action="collapse-all">Collapse all</button><button type="button" class="secondary" data-gallery-tree-action="expand-all">Expand all</button></div>';
    echo '<table><thead><tr><th>Select</th><th>Title</th><th>Parent</th><th>Folder</th><th>Status</th>';
    if ($accessReady) {
        echo '<th>Access</th>';
    }
    echo '<th title="Maps">M</th>';
    echo '<th>B</th>';
    if ($votingReady) {
        echo '<th title="Voting">V</th>';
    }
    if ($pictureGameReady) {
        echo '<th title="Game">G</th>';
    }
    echo '<th>Images</th><th>Actions</th></tr></thead><tbody>';
    foreach ($galleries as $gallery) {
        // Variable $depth stores this steps working value.
        $depth = substr_count((string) $gallery['folder_path'], '/');
        // Variable $hasChildren stores this steps working value.
        $hasChildren = array_filter($galleries, static fn (array $candidate): bool => (int) ($candidate['parent_id'] ?? 0) === (int) $gallery['id']);
        // Variable $isCollapsed stores this steps working value.
        $isCollapsed = isset($collapsedIds[(int) $gallery['id']]);
        echo '<tr class="' . ($depth > 0 ? 'is-subgallery' : '') . ($isCollapsed ? ' is-collapsed' : '') . '" data-gallery-row data-gallery-id="' . (int) $gallery['id'] . '" data-parent-id="' . (int) ($gallery['parent_id'] ?? 0) . '" data-depth="' . $depth . '" data-gallery-visibility="' . e((string) $gallery['visibility']) . '" data-gallery-title="' . e((string) $gallery['title']) . '"><td><input type="checkbox" name="gallery_ids[]" value="' . (int) $gallery['id'] . '"></td>';
        // Variable $depthClass stores this steps working value.
        $depthClass = 'tree-depth-' . min($depth, 8);
        echo '<td><span class="tree-title ' . e($depthClass) . '">' . ($hasChildren ? '<button type="button" class="tree-toggle" data-gallery-toggle="' . (int) $gallery['id'] . '" aria-expanded="' . ($isCollapsed ? 'false' : 'true') . '">' . ($isCollapsed ? '+' : '-') . '</button>' : '<span class="tree-spacer" aria-hidden="true"></span>') . ($depth > 0 ? '<span class="tree-branch" aria-hidden="true"></span>' : '') . '<a href="' . e(gallery_public_url($gallery)) . '">' . e($gallery['title']) . '</a></span></td>';
        echo '<td>' . e($gallery['parent_title'] ?: '') . '</td><td>' . e($gallery['folder_path']) . '</td><td>' . e($gallery['visibility']) . '</td>';
        if ($accessReady) {
            $accessLabel = (string) ($gallery['access_mode'] ?? 'normal') === 'password' ? 'Protected' . ((string) ($gallery['access_listing'] ?? 'listed') === 'unlisted' ? ', unlisted' : ', listed') : 'Normal';
            echo '<td>' . e($accessLabel) . '</td>';
        }
        echo '<td>' . render_admin_feature_flag(exif_gps_schema_ready() && (int) ($gallery['gps_map_enabled'] ?? 0) === 1, '✓', 'GPS maps enabled') . '</td>';
        echo '<td>' . render_admin_feature_flag(gallery_background_source_schema_ready() && gallery_background_source($gallery) !== null, '✓', 'Custom gallery background set') . '</td>';
        if ($votingReady) {
            echo '<td>' . render_admin_feature_flag((int) ($gallery['voting_enabled'] ?? 0) === 1, '✓', 'Voting enabled') . '</td>';
        }
        if ($pictureGameReady) {
            echo '<td>' . render_admin_feature_flag((int) ($gallery['picture_game_enabled'] ?? 0) === 1, '✓', 'Picture game enabled') . '</td>';
        }
        echo '<td>' . (int) $gallery['image_count'] . '</td><td class="nav gallery-row-actions">';
        echo '<a class="gallery-row-action" href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '">Edit</a>';
        echo '<button type="submit" class="secondary gallery-row-action" name="thumbnail_gallery_id" value="' . (int) $gallery['id'] . '" formaction="' . e(url_for('admin_create_thumbnails')) . '">Thumbs</button>';
        echo '</td></tr>';
    }
    echo '</tbody></table></form></section>';
    render_footer();
}

/**
 * Render one admin-log table row for the dashboard or log page.
 */
function render_admin_log_row(array $entry, bool $withActions = false): string
{
    // Variable $context stores this steps working value.
    $context = [];
    if (!empty($entry['context_json'])) {
        $decoded = json_decode((string) $entry['context_json'], true);
        if (is_array($decoded)) {
            $context = $decoded;
        }
    }
    // Variable $stateLabel stores this steps working value.
    $stateLabel = admin_log_status_label((string) ($entry['status'] ?? 'todo'));
    // Variable $statusForm stores this steps working value.
    $statusForm = '';
    if ($withActions) {
        $statusForm = '<form method="post" action="' . e(url_for('admin_log_update')) . '" class="inline-action-form">' . csrf_field()
            . '<input type="hidden" name="log_id" value="' . (int) $entry['id'] . '">'
            . '<select name="status">';
        foreach (admin_log_status_options() as $status => $label) {
            $statusForm .= '<option value="' . e($status) . '"' . ((string) ($entry['status'] ?? '') === $status ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $statusForm .= '</select><button type="submit">Update</button></form>';
    }
    return '<tr>'
        . '<td>' . e((string) $entry['created_at']) . '</td>'
        . '<td>' . e($stateLabel) . '</td>'
        . '<td>' . e((string) $entry['event_key']) . '</td>'
        . '<td>' . e((string) $entry['message']) . ($context ? '<div class="muted">' . e(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</div>' : '') . '</td>'
        . '<td>' . e((string) ($entry['username'] ?? '')) . '</td>'
        . ($withActions ? '<td>' . $statusForm . '</td>' : '')
        . '</tr>';
}

/**
 * Render a compact admin feature indicator for table cells.
 */
function render_admin_feature_flag(bool $enabled, string $symbol, string $label): string
{
    if (!$enabled) {
        return '';
    }
    return '<span class="admin-flag is-enabled" title="' . e($label) . '" aria-label="' . e($label) . '">' . e($symbol) . '</span>';
}

/**
 * Show and manage the admin log.
 */

/**
 * Render the compact admin dashboard integrity summary.
 */
function render_admin_integrity_summary(array $integrityStatus): void
{
    $status = (string) ($integrityStatus['status'] ?? 'error');
    $label = integrity_status_label($status);
    $modifiedCount = count((array) ($integrityStatus['modified'] ?? []));
    $missingCount = count((array) ($integrityStatus['missing'] ?? []));
    $unknownCount = count((array) ($integrityStatus['unknown'] ?? []));
    $checkedAt = (string) ($integrityStatus['checked_at_iso'] ?? '');

    echo '<section class="panel"><h2>System integrity</h2>';
    echo '<p><strong>Status:</strong> ' . e($label) . '</p>';
    if ($status === 'ok') {
        echo '<p class="muted">Core PHP, HTML, CSS and JavaScript files match the installed manifest.</p>';
    } elseif ($status === 'warning') {
        echo '<p class="notice">Core files match, but ' . (int) $unknownCount . ' unknown core-like file(s) were found.</p>';
    } elseif ($status === 'modified') {
        echo '<p class="notice">Detected ' . (int) $modifiedCount . ' modified and ' . (int) $missingCount . ' missing core file(s).</p>';
    } else {
        echo '<p class="notice">' . e((string) ($integrityStatus['manifest_error'] ?? 'Integrity check failed.')) . '</p>';
    }
    if ($checkedAt !== '') {
        echo '<p class="muted">Last checked: ' . e($checkedAt) . '</p>';
    }
    echo '<p><a class="button secondary" href="' . e(url_for('admin_integrity')) . '">Show details</a></p>';
    echo '</section>';
}

/**
 * Render a list of integrity paths.
 */
function render_admin_integrity_path_list(string $title, array $paths): void
{
    echo '<h3>' . e($title) . '</h3>';
    if (!$paths) {
        echo '<p class="muted">None.</p>';
        return;
    }

    echo '<ul>';
    foreach ($paths as $path) {
        echo '<li><code>' . e((string) $path) . '</code></li>';
    }
    echo '</ul>';
}

/**
 * Show and refresh the admin-only system integrity check.
 */
function cms_admin_integrity(): void
{
    require_admin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $status = integrity_status(true);
        admin_log_event('info', 'integrity.checked', 'Admin ran the system integrity check.', [
            'status' => (string) ($status['status'] ?? 'unknown'),
            'modified' => count((array) ($status['modified'] ?? [])),
            'missing' => count((array) ($status['missing'] ?? [])),
            'unknown' => count((array) ($status['unknown'] ?? [])),
        ]);
        redirect_to(url_for('admin_integrity', ['checked' => 1]));
    }

    $status = integrity_status(false);
    render_header('System integrity');
    echo '<section class="hero"><h1>System integrity</h1><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a>';
    echo '<form method="post" action="' . e(url_for('admin_integrity')) . '" class="inline-action-form">' . csrf_field();
    echo '<button type="submit">Check now</button>';
    echo '</form>';
    echo '</nav></section>';

    if (isset($_GET['checked'])) {
        echo '<div class="notice">Integrity check completed.</div>';
    }

    echo '<section class="panel">';
    echo '<h2>Status: ' . e(integrity_status_label((string) ($status['status'] ?? 'error'))) . '</h2>';
    echo '<p><strong>Manifest version:</strong> ' . e((string) ($status['version'] ?? '')) . '</p>';
    echo '<p><strong>Last checked:</strong> ' . e((string) ($status['checked_at_iso'] ?? '')) . '</p>';

    if (!empty($status['manifest_error'])) {
        echo '<p class="notice">' . e((string) $status['manifest_error']) . '</p>';
    }

    render_admin_integrity_path_list('Modified core files', (array) ($status['modified'] ?? []));
    render_admin_integrity_path_list('Missing core files', (array) ($status['missing'] ?? []));
    render_admin_integrity_path_list('Unknown core-like files', (array) ($status['unknown'] ?? []));
    echo '<p class="muted">Ignored folders include cache, galleries, custom CSS, local config, and common hosting/runtime files.</p>';
    echo '</section>';
    render_footer();
}

function cms_admin_logs(): void
{
    require_admin();
    // Variable $status stores this steps working value.
    $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
    // Variable $logs stores this steps working value.
    $logs = admin_log_list($status, 100);
    render_header('Admin log');
    echo '<section class="hero"><h1>Admin log</h1><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a>';
    echo '</nav></section>';
    echo '<section class="panel"><h2>Filter</h2><div class="nav">';
    echo '<a class="button' . ($status === null ? '' : ' secondary') . '" href="' . e(url_for('admin_logs')) . '">All</a>';
    foreach (admin_log_status_options() as $value => $label) {
        echo '<a class="button' . ($status === $value ? '' : ' secondary') . '" href="' . e(url_for('admin_logs', ['status' => $value])) . '">' . e($label) . '</a>';
    }
    echo '</div></section>';
    if (!$logs) {
        echo '<section class="panel"><p>No log entries yet.</p></section>';
        render_footer();
        return;
    }
    echo '<section class="panel"><h2>Entries</h2>';
    echo '<table><thead><tr><th>Select</th><th>When</th><th>State</th><th>Event</th><th>Message</th><th>By</th><th>Set state</th></tr></thead><tbody>';
    foreach ($logs as $entry) {
        echo '<tr data-admin-log-row>';
        echo '<td><input type="checkbox" name="log_ids[]" value="' . (int) $entry['id'] . '" form="admin-log-bulk-form"></td>';
        echo '<td>' . e((string) $entry['created_at']) . '</td>';
        echo '<td data-admin-log-state>' . e(admin_log_status_label((string) ($entry['status'] ?? 'todo'))) . '</td>';
        echo '<td>' . e((string) $entry['event_key']) . '</td>';
        echo '<td>' . e((string) $entry['message']) . '</td>';
        echo '<td>' . e((string) ($entry['username'] ?? '')) . '</td>';
        echo '<td><select name="status" data-admin-log-status-select data-log-id="' . (int) $entry['id'] . '" data-update-url="' . e(url_for('admin_log_update')) . '" data-csrf-token="' . e(csrf_token()) . '">';
        foreach (admin_log_status_options() as $value => $label) {
            echo '<option value="' . e($value) . '"' . ((string) ($entry['status'] ?? '') === $value ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';
    }
    echo '</tbody></table><form id="admin-log-bulk-form" method="post" action="' . e(url_for('admin_log_update')) . '">' . csrf_field();
    echo '<div class="bulk-row"><label>Bulk set selected<select name="status">';
    foreach (admin_log_status_options() as $value => $label) {
        echo '<option value="' . e($value) . '">' . e($label) . '</option>';
    }
    echo '</select></label><button type="submit" name="action" value="bulk">Apply to selected</button></div></form></section>';
    render_footer();
}

/**
 * Update one or more admin log entries.
 */
function cms_admin_log_update(): void
{
    require_admin();
    verify_csrf();
    // Variable $wantsJson stores this steps working value.
    $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? '');
    // Variable $status stores this steps working value.
    $status = (string) ($_POST['status'] ?? '');
    if ($action === 'single') {
        // Variable $logId stores this steps working value.
        $logId = (int) ($_POST['log_id'] ?? 0);
        try {
            admin_log_update_status($logId, $status);
            if ($wantsJson) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'status' => $status, 'label' => admin_log_status_label($status)]);
                return;
            }
        } catch (RuntimeException $exception) {
            admin_log_event('error', 'admin_log.update_failed', 'Admin log status update failed.', [
                'log_id' => $logId,
                'status' => $status,
                'error' => $exception->getMessage(),
            ]);
            if ($wantsJson) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
                return;
            }
        }
        redirect_to(url_for('admin_logs'));
    }
    if ($action === 'bulk' && !empty($_POST['log_ids']) && is_array($_POST['log_ids'])) {
        foreach (array_map('intval', $_POST['log_ids']) as $logId) {
            try {
                admin_log_update_status($logId, $status);
            } catch (RuntimeException $exception) {
                admin_log_event('error', 'admin_log.bulk_update_failed', 'Bulk admin log status update failed.', [
                    'log_id' => $logId,
                    'status' => $status,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
        redirect_to(url_for('admin_logs'));
    }
    cms_not_found();
    return;
}

/**
 * Render a thumbnail maintenance warning for admins without forcing public visitors to wait.
 */
function render_admin_thumbnail_maintenance_notice(array $summary): void
{
    if (($summary['images_with_missing'] ?? 0) <= 0 && ($summary['webp_skipped'] ?? 0) <= 0) {
        return;
    }
    echo '<div class="notice">';
    if (($summary['images_with_missing'] ?? 0) > 0) {
        echo '<strong>Thumbnail maintenance required.</strong> ';
        echo e((string) $summary['images_with_missing']) . ' image(s) are missing optimized thumbnails or have stale thumbnail files. ';
        echo e((string) $summary['missing_variants']) . ' thumbnail variant(s) need to be created. ';
        if (!empty($summary['limited'])) {
            echo 'Only the first ' . e((string) $summary['images_scanned']) . ' image(s) were checked, so more may be pending. ';
        }
        echo 'Public visitors will not generate these thumbnails while browsing. Use <strong>Create all thumbnails</strong> in the admin toolbar.';
    }
    if (($summary['webp_skipped'] ?? 0) > 0) {
        echo (($summary['images_with_missing'] ?? 0) > 0 ? '<br>' : '');
        echo 'Some WebP variants are intentionally skipped because the source images contain EXIF metadata and this server cannot preserve EXIF during WebP conversion.';
    }
    echo '</div>';
}
/**
 * Render an admin-only prompt that can run pending migrations.
 */
function render_admin_migration_notice(string $message): void
{
    echo '<div class="notice is-alert"><form method="post" action="' . e(url_for('admin_run_migrations')) . '" class="inline-action-form">' . csrf_field();
    echo '<span>' . e($message) . '</span> ';
    echo '<button type="submit" class="button is-update-pending">Run database migration</button>';
    echo '</form></div>';
}

/**
 * Show filesystem folders that can be imported as galleries.
 */
function cms_admin_discover(): void
{
    require_admin();
    $refresh = null;
    if (request_method() === 'POST') {
        verify_csrf();
        $refresh = scan_all_imported_gallery_images();
        admin_log_event('info', 'galleries.refresh_scanned', 'Admin refreshed imported galleries from filesystem.', $refresh);
    }
    // Variable $candidates stores this steps working value.
    $candidates = discover_gallery_candidates();
    render_header('New gallery folders');
    echo '<section class="panel"><h1>New gallery folders</h1>';
    echo '<p><a class="button secondary" href="' . e(url_for('admin')) . '">Back to admin dashboard</a></p>';
    if ($refresh !== null) {
        echo '<div class="notice">Scanned ' . (int) $refresh['galleries'] . ' existing galleries and imported or updated ' . (int) $refresh['images'] . ' images.</div>';
    }
    if (!$candidates) {
        echo '<p>No new gallery folders found.</p>';
    } else {
        echo '<form method="post" action="' . e(url_for('admin_import')) . '" data-import-galleries-form>' . csrf_field();
        echo '<p><label><input type="checkbox" name="create_thumbnails" value="1" checked> Create optimized thumbnails during import</label></p>';
        echo '<table><thead><tr><th>Import</th><th>Folder</th><th>Title</th><th>Visibility</th></tr></thead><tbody>';
        foreach ($candidates as $candidate) {
            echo '<tr><td><input type="checkbox" name="folders[]" value="' . e($candidate['folder_path']) . '"></td><td>' . e($candidate['folder_path']) . '</td><td>' . e($candidate['title']) . '</td><td>' . e($candidate['visibility']) . '</td></tr>';
        }
        echo '</tbody></table><button type="submit">Import selected detected galleries</button></form>';
    }
    echo '</section>';
    render_footer();
}

/**
 * Import selected discovered gallery folders.
 */
function cms_admin_import(): void
{
    require_admin();
    verify_csrf();
    if (!empty($_POST['ajax']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        // Variable $result stores this steps working value.
        $result = import_galleries_without_thumbnails($_POST['folders'] ?? []);
        header('Content-Type: application/json');
        echo json_encode($result);
        return;
    }
    // Variable $result stores this steps working value.
    $result = import_galleries($_POST['folders'] ?? [], !empty($_POST['create_thumbnails']));
    redirect_to(url_for('admin', $result));
}

/**
 * Create a gallery row backed by a real empty folder.
 */
function cms_admin_new_gallery(): void
{
    require_admin();
    $error = '';
    if (request_method() === 'POST') {
        verify_csrf();
        try {
            $gallery = create_empty_gallery([
                'title' => $_POST['title'] ?? '',
                'folder_name' => $_POST['folder_name'] ?? '',
                'description' => $_POST['description'] ?? '',
                'visibility' => $_POST['visibility'] ?? 'draft',
                'parent_id' => $_POST['parent_id'] ?? 0,
                'voting_enabled' => $_POST['voting_enabled'] ?? 0,
            ]);
            admin_log_event('info', 'gallery.folder_created', 'Admin created an empty gallery folder.', [
                'gallery_id' => (int) $gallery['id'],
                'folder_path' => (string) $gallery['folder_path'],
            ]);
            redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id'], 'created' => 1]));
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            admin_log_event('error', 'gallery.folder_create_failed', 'Admin empty gallery creation failed.', ['error' => $error]);
        }
    }

    render_header('Create empty gallery');
    echo '<section class="hero"><h1>Create empty gallery</h1><nav class="nav"><a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a><a class="button secondary" href="' . e(url_for('admin_upload')) . '">Upload photos</a></nav></section>';
    if ($error !== '') {
        echo '<div class="notice">Create failed: ' . e($error) . '</div>';
    }
    echo '<section class="panel"><form method="post" class="form-grid">' . csrf_field();
    echo '<label>Gallery name<input name="title" required></label>';
    echo '<label>Folder name<input name="folder_name"><span class="muted">Leave empty to derive it from the gallery name.</span></label>';
    echo '<label>Parent gallery<select name="parent_id"><option value="0">No parent</option>' . gallery_parent_options_for_new() . '</select></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options('draft') . '</select></label>';
    echo '<label><input type="checkbox" name="voting_enabled" value="1"> Enable image voting for this gallery</label>';
    echo '<label>Description<textarea name="description"></textarea></label>';
    echo '<button type="submit">Create gallery folder</button></form></section>';
    render_footer();
}

/**
 * Upload images into an existing gallery or a newly created gallery folder.
 */
function cms_admin_upload(): void
{
    $isAjaxUpload = request_method() === 'POST' && admin_wants_json();
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        if ($isAjaxUpload) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Your admin session expired. Please sign in again.']);
            return;
        }
        redirect_to(url_for('admin_login'));
    }
    if (request_method() === 'POST') {
        verify_csrf();
        $wantsJson = admin_wants_json();
        if ($wantsJson) {
            ob_start();
        }
        try {
            $entries = gallery_upload_entries($_FILES['images'] ?? null);
            $mode = (string) ($_POST['upload_mode'] ?? 'existing');
            if ($mode === 'new') {
                $gallery = create_empty_gallery([
                    'title' => $_POST['title'] ?? '',
                    'folder_name' => $_POST['folder_name'] ?? '',
                    'description' => $_POST['description'] ?? '',
                    'visibility' => $_POST['visibility'] ?? 'draft',
                    'parent_id' => $_POST['parent_id'] ?? 0,
                ]);
            } else {
                $gallery = find_gallery((int) ($_POST['gallery_id'] ?? 0));
                if (!$gallery) {
                    throw new RuntimeException('Choose an existing gallery.');
                }
            }

            $stored = store_uploaded_gallery_images((int) $gallery['id'], $entries);
            $thumbnails = 0;
            if (!$wantsJson && !empty($_POST['create_thumbnails'])) {
                $thumbnails = create_gallery_thumbnails((int) $gallery['id']);
            }
            admin_log_event('info', 'gallery.images_uploaded', 'Admin uploaded images into a gallery folder.', [
                'gallery_id' => (int) $gallery['id'],
                'folder_path' => (string) $gallery['folder_path'],
                'uploaded' => (int) $stored['uploaded'],
                'scanned' => (int) $stored['scanned'],
            ]);
            $response = [
                'ok' => true,
                'gallery_id' => (int) $gallery['id'],
                'gallery_ids' => [(int) $gallery['id']],
                'image_ids' => array_map('intval', $stored['image_ids'] ?? []),
                'filenames' => array_values($stored['filenames'] ?? []),
                'uploaded' => (int) $stored['uploaded'],
                'scanned' => (int) $stored['scanned'],
                'thumbnails' => $thumbnails,
                'redirect_url' => url_for('admin_edit_gallery', ['id' => $gallery['id'], 'uploaded' => (int) $stored['uploaded'], 'scanned' => (int) $stored['scanned'], 'thumbnails' => $thumbnails]),
            ];
            if ($wantsJson) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Type: application/json');
                echo json_encode($response);
                return;
            }
            redirect_to($response['redirect_url']);
        } catch (Throwable $exception) {
            admin_log_event('error', 'gallery.upload_failed', 'Admin image upload failed.', ['error' => $exception->getMessage()]);
            if ($wantsJson) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
                return;
            }
            $_SESSION['admin_upload_error'] = $exception->getMessage();
            redirect_to(url_for('admin_upload'));
        }
    }

    $error = (string) ($_SESSION['admin_upload_error'] ?? '');
    unset($_SESSION['admin_upload_error']);
    $heicSupported = heic_conversion_supported();
    $rawSupported = raw_conversion_supported();
    render_header('Upload photos');
    echo '<section class="hero"><h1>Upload photos</h1><nav class="nav"><a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a><a class="button secondary" href="' . e(url_for('admin_new_gallery')) . '">Create empty gallery</a></nav></section>';
    if ($error !== '') {
        echo '<div class="notice">Upload failed: ' . e($error) . '</div>';
    }
    echo '<section class="panel compact-support"><h2>Upload support</h2><table class="support-matrix"><thead><tr><th>Type</th><th>JPG</th><th>PNG</th><th>GIF</th><th>WebP</th><th>HEIC</th><th>DNG</th></tr></thead><tbody><tr>';
    echo '<th scope="row">Available</th>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="' . ($heicSupported ? 'support-yes' : 'support-no') . '">' . ($heicSupported ? '✓' : '✕') . '</td>';
    echo '<td class="' . ($rawSupported ? 'support-yes' : 'support-no') . '">' . ($rawSupported ? '✓' : '✕') . '</td>';
    echo '</tr></tbody></table></section>';
    $acceptTypes = ['.jpg', '.jpeg', '.png', '.gif', '.webp'];
    if ($heicSupported) {
        $acceptTypes[] = '.heic';
        $acceptTypes[] = '.heif';
    }
    if ($rawSupported) {
        $acceptTypes[] = '.dng';
    }
    $acceptTypes[] = 'image/*';
    $acceptValue = implode(',', $acceptTypes);
    echo '<section class="panel"><h2>Upload into existing gallery</h2><form method="post" action="' . e(url_for('admin_upload')) . '" enctype="multipart/form-data" class="form-grid" data-gallery-upload-form>' . csrf_field();
    echo '<input type="hidden" name="upload_mode" value="existing">';
    echo '<label>Gallery<select name="gallery_id" required>' . gallery_options_for_select() . '</select></label>';
    echo '<label>Images<input name="images[]" type="file" accept="' . e($acceptValue) . '" multiple required></label>';
    echo '<label><input type="checkbox" name="create_thumbnails" value="1" checked> Create optimized thumbnails after upload</label>';
    echo '<button type="submit">Upload images</button></form></section>';
    echo '<section class="panel"><h2>Create gallery from upload</h2><form method="post" action="' . e(url_for('admin_upload')) . '" enctype="multipart/form-data" class="form-grid" data-gallery-upload-form>' . csrf_field();
    echo '<input type="hidden" name="upload_mode" value="new">';
    echo '<label>Gallery name<input name="title" required></label>';
    echo '<label>Folder name<input name="folder_name"><span class="muted">Leave empty to derive it from the gallery name.</span></label>';
    echo '<label>Parent gallery<select name="parent_id"><option value="0">No parent</option>' . gallery_parent_options_for_new() . '</select></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options('draft') . '</select></label>';
    echo '<label><input type="checkbox" name="voting_enabled" value="1"> Enable image voting for this gallery</label>';
    echo '<label>Description<textarea name="description"></textarea></label>';
    echo '<label>Images<input name="images[]" type="file" accept="' . e($acceptValue) . '" multiple required></label>';
    echo '<label><input type="checkbox" name="create_thumbnails" value="1" checked> Create optimized thumbnails after upload</label>';
    echo '<button type="submit">Create gallery and upload</button></form></section>';
    render_footer();
}

/**
 * Return whether an admin POST expects JSON.
 */
function admin_wants_json(): bool
{
    return !empty($_POST['ajax']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/**
 * Apply a bulk action to selected galleries.
 */
function cms_admin_bulk_galleries(): void
{
    require_admin();
    verify_csrf();
    // Variable $galleryIds stores this steps working value.
    $galleryIds = array_map('intval', $_POST['gallery_ids'] ?? []);
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? 'scan');
    // Variable $count stores this steps working value.
    $count = 0;
    if ($action === 'scan') {
        foreach ($galleryIds as $galleryId) {
            $count += scan_gallery_images($galleryId);
        }
        redirect_to(url_for('admin', ['scanned' => $count]));
    }
    if ($action === 'thumbs') {
        foreach ($galleryIds as $galleryId) {
            $count += create_gallery_thumbnails($galleryId);
        }
        redirect_to(url_for('admin', ['thumbnails' => $count]));
    }
    if ($action === 'delete' && $galleryIds) {
        try {
            $deleted = delete_gallery_subtrees($galleryIds);
            admin_log_event('warning', 'gallery.bulk_deleted', 'Admin deleted selected gallery folders.', [
                'gallery_ids' => $galleryIds,
                'deleted_roots' => (int) $deleted['root_count'],
                'deleted_rows' => (int) $deleted['row_count'],
            ]);
            redirect_to(url_for('admin', ['deleted_galleries' => (int) $deleted['root_count']]));
        } catch (Throwable $exception) {
            admin_log_event('error', 'gallery.bulk_delete_failed', 'Bulk gallery delete failed.', [
                'gallery_ids' => $galleryIds,
                'exception' => $exception->getMessage(),
            ]);
            redirect_to(url_for('admin', ['delete_error' => $exception->getMessage()]));
        }
    }
    if (in_array($action, ['draft', 'public', 'private'], true) && $galleryIds) {
        // Variable $placeholders stores this steps working value.
        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE galleries SET visibility = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$action, now_sql()], $galleryIds));
        foreach ($galleryIds as $galleryId) {
            // Variable $gallery stores this steps working value.
            $gallery = find_gallery($galleryId);
            if ($gallery) {
                write_gallery_sidecar($gallery);
            }
        }
        redirect_to(url_for('admin', ['updated' => count($galleryIds)]));
    }
    if (in_array($action, ['maps_on', 'maps_off'], true) && $galleryIds) {
        if (!exif_gps_schema_ready()) {
            admin_log_event('warning', 'gps_maps.schema_missing', 'Attempted to change GPS maps before migration was applied.', [
                'gallery_ids' => $galleryIds,
                'action' => $action,
            ]);
            redirect_to(url_for('admin', ['migration_required' => 1]));
        }
        // Variable $expandedIds stores this steps working value.
        $expandedIds = [];
        foreach ($galleryIds as $galleryId) {
            $expandedIds = array_merge($expandedIds, gallery_subtree_ids($galleryId));
        }
        $expandedIds = array_values(array_unique(array_filter($expandedIds)));
        if ($expandedIds) {
            // Variable $placeholders stores this steps working value.
            $placeholders = implode(',', array_fill(0, count($expandedIds), '?'));
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE galleries SET gps_map_enabled = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$action === 'maps_on' ? 1 : 0, now_sql()], $expandedIds));
        }
        redirect_to(url_for('admin', ['updated' => count($expandedIds)]));
    }
    if (in_array($action, ['vote_on', 'vote_off'], true) && $galleryIds) {
        if (!gallery_voting_schema_ready()) {
            admin_log_event('warning', 'votes.schema_missing', 'Attempted to change gallery voting before migration was applied.', [
                'gallery_ids' => $galleryIds,
                'action' => $action,
            ]);
            redirect_to(url_for('admin', ['migration_required' => 1]));
        }
        // Variable $expandedIds stores this steps working value.
        $expandedIds = [];
        foreach ($galleryIds as $galleryId) {
            $expandedIds = array_merge($expandedIds, gallery_subtree_ids($galleryId));
        }
        $expandedIds = array_values(array_unique(array_filter($expandedIds)));
        if ($expandedIds) {
            // Variable $placeholders stores this steps working value.
            $placeholders = implode(',', array_fill(0, count($expandedIds), '?'));
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE galleries SET voting_enabled = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$action === 'vote_on' ? 1 : 0, now_sql()], $expandedIds));
            if ($action === 'vote_off') {
                $stmt = db()->prepare('UPDATE galleries SET picture_game_enabled = 0, updated_at = ? WHERE id IN (' . $placeholders . ')');
                $stmt->execute(array_merge([now_sql()], $expandedIds));
            }
        }
        redirect_to(url_for('admin', ['updated' => count($expandedIds)]));
    }
    if (in_array($action, ['game_on', 'game_off'], true) && $galleryIds) {
        if (!admin_feature_schema_ready()) {
            admin_log_event('warning', 'picture_game.schema_missing', 'Attempted to change picture game before migration was applied.', [
                'gallery_ids' => $galleryIds,
                'action' => $action,
            ]);
            redirect_to(url_for('admin', ['migration_required' => 1]));
        }
        // Variable $expandedIds stores this steps working value.
        $expandedIds = [];
        foreach ($galleryIds as $galleryId) {
            $expandedIds = array_merge($expandedIds, gallery_subtree_ids($galleryId));
        }
        $expandedIds = array_values(array_unique(array_filter($expandedIds)));
        if ($expandedIds) {
            // Variable $placeholders stores this steps working value.
            $placeholders = implode(',', array_fill(0, count($expandedIds), '?'));
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE galleries SET picture_game_enabled = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([$action === 'game_on' ? 1 : 0, now_sql()], $expandedIds));
            if ($action === 'game_on') {
                $stmt = db()->prepare('UPDATE galleries SET voting_enabled = 1, updated_at = ? WHERE id IN (' . $placeholders . ')');
                $stmt->execute(array_merge([now_sql()], $expandedIds));
            }
        }
        redirect_to(url_for('admin', ['updated' => count($expandedIds)]));
    }
    redirect_to(url_for('admin'));
}

/**
 * Run pending database migrations from the admin dashboard.
 */
function cms_admin_run_migrations(): void
{
    require_admin();
    verify_csrf();
    try {
        $ran = run_migrations();
        if ($ran) {
            admin_log_event('info', 'migrations.ran', 'Admin ran pending migrations.', ['versions' => $ran]);
            redirect_to(url_for('admin', ['migrations_ran' => implode(', ', $ran)]));
        }
        admin_log_event('info', 'migrations.current', 'Admin checked migrations and database was already current.');
        redirect_to(url_for('admin', ['migrations_current' => 1]));
    } catch (Throwable $exception) {
        admin_log_event('error', 'migrations.failed', 'Admin migration run failed.', ['exception' => $exception->getMessage()]);
        redirect_to(url_for('admin', ['migration_failed' => $exception->getMessage()]));
    }
}


/**
 * Regenerate clean public gallery and image URL paths from current titles and filenames.
 */
function cms_admin_regenerate_paths(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    try {
        $result = regenerate_public_paths();
        redirect_to(url_for('admin', [
            'paths_regenerated' => 1,
            'gallery_paths' => (int) $result['galleries'],
            'image_paths' => (int) $result['images'],
        ]));
    } catch (Throwable $exception) {
        redirect_to(url_for('admin', ['paths_error' => $exception->getMessage()]));
    }
}

/**
 * Create thumbnails for one gallery, selected images, or every gallery.
 */
function cms_admin_create_thumbnails(): void
{
    require_admin();
    verify_csrf();
    if (!empty($_POST['ajax']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        cms_admin_create_thumbnails_batch();
        return;
    }
    // Variable $count stores this steps working value.
    $count = 0;
    if (($_POST['scope'] ?? '') === 'all') {
        // Variable $count stores this steps working value.
        $count = create_all_thumbnails();
        redirect_to(url_for('admin', ['thumbnails' => $count]));
    }
    // Variable $galleryId stores this steps working value.
    $galleryId = (int) ($_POST['thumbnail_gallery_id'] ?? $_POST['gallery_id'] ?? 0);
    // Variable $gallery stores this steps working value.
    $gallery = $galleryId > 0 ? find_gallery($galleryId) : null;
    if ($gallery && empty($_POST['thumbnail_gallery_id']) && !empty($_POST['image_ids'])) {
        foreach (array_map('intval', $_POST['image_ids']) as $imageId) {
            // Variable $image stores this steps working value.
            $image = find_image($imageId);
            if ($image && (int) $image['gallery_id'] === $galleryId) {
                $count += create_image_thumbnails($image, $gallery);
            }
        }
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId, 'thumbnails' => $count]));
    }
    if ($gallery) {
        // Variable $count stores this steps working value.
        $count = create_gallery_thumbnails($galleryId);
        redirect_to(url_for('admin', ['thumbnails' => $count]));
    }
    redirect_to(url_for('admin'));
}

/**
 * Process one AJAX thumbnail batch and return enough data for a progress bar.
 */
function cms_admin_create_thumbnails_batch(): void
{
    // Variable $imageIds stores this steps working value.
    $imageIds = thumbnail_request_image_ids($_POST);
    // Variable $total stores this steps working value.
    $total = count($imageIds);
    // Variable $offset stores this steps working value.
    $offset = max(0, (int) ($_POST['offset'] ?? 0));
    // Variable $batchSize stores this steps working value.
    $batchSize = max(1, min(12, (int) ($_POST['batch_size'] ?? 6)));
    // Variable $batch stores this steps working value.
    $batch = array_slice($imageIds, $offset, $batchSize);
    // Variable $created stores this steps working value.
    $created = 0;
    // Variable $skipped stores this steps working value.
    $skipped = 0;
    // Variable $webpSkipped stores this steps working value.
    $webpSkipped = 0;
    // Variable $galleryCache stores this steps working value.
    $galleryCache = [];
    foreach ($batch as $imageId) {
        // Variable $image stores this steps working value.
        $image = find_image((int) $imageId);
        if (!$image) {
            continue;
        }
        // Variable $galleryId stores this steps working value.
        $galleryId = (int) $image['gallery_id'];
        if (!array_key_exists($galleryId, $galleryCache)) {
            $galleryCache[$galleryId] = find_gallery($galleryId);
        }
        if (!$galleryCache[$galleryId]) {
            continue;
        }
        // Variable $result stores this steps working value.
        $result = create_image_thumbnails_result($image, $galleryCache[$galleryId]);
        $created += (int) $result['created'];
        $skipped += (int) $result['skipped'];
        $webpSkipped += (int) ($result['webp_skipped'] ?? 0);
    }
    // Variable $processed stores this steps working value.
    $processed = min($total, $offset + count($batch));
    header('Content-Type: application/json');
    echo json_encode([
        'total' => $total,
        'processed' => $processed,
        'next_offset' => $processed,
        'webp_skipped' => $webpSkipped,
        'created' => $created,
        'skipped' => $skipped,
        'done' => $processed >= $total,
    ]);
}

/**
 * Resolve thumbnail target images from dashboard and gallery-edit forms.
 */
function thumbnail_request_image_ids(array $post): array
{
    if (($post['scope'] ?? '') === 'all') {
        return all_image_ids();
    }
    if (!empty($post['gallery_ids']) && is_array($post['gallery_ids'])) {
        return image_ids_for_galleries($post['gallery_ids']);
    }
    // Variable $thumbnailGalleryId stores this steps working value.
    $thumbnailGalleryId = (int) ($post['thumbnail_gallery_id'] ?? 0);
    if ($thumbnailGalleryId > 0) {
        return image_ids_for_galleries([$thumbnailGalleryId]);
    }
    // Variable $galleryId stores this steps working value.
    $galleryId = (int) ($post['gallery_id'] ?? 0);
    if (!empty($post['image_ids']) && is_array($post['image_ids'])) {
        // Variable $ids stores this steps working value.
        $ids = [];
        foreach (array_map('intval', $post['image_ids']) as $imageId) {
            // Variable $image stores this steps working value.
            $image = find_image($imageId);
            if (!$image) {
                continue;
            }
            if ($galleryId > 0 && (int) $image['gallery_id'] !== $galleryId) {
                continue;
            }
            $ids[] = $imageId;
        }
        return array_values(array_unique($ids));
    }
    if ($galleryId > 0) {
        return image_ids_for_galleries([$galleryId]);
    }
    return [];
}

/**
 * Persist the admin gallery tree collapse state from JavaScript.
 */
function cms_admin_save_gallery_collapse(): void
{
    require_admin();
    verify_csrf();
    // Variable $ids stores this steps working value.
    $ids = json_decode((string) ($_POST['collapsed_ids'] ?? '[]'), true);
    set_collapsed_gallery_ids(is_array($ids) ? $ids : []);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}

/**
 * Scan selected galleries or one gallery from its edit page.
 */
function cms_admin_scan_images(): void
{
    require_admin();
    verify_csrf();
    // Variable $galleryIds stores this steps working value.
    $galleryIds = $_POST['gallery_ids'] ?? [];
    if (!$galleryIds && isset($_POST['gallery_id'])) {
        // Variable $galleryIds stores this steps working value.
        $galleryIds = [$_POST['gallery_id']];
    }
    // Variable $count stores this steps working value.
    $count = 0;
    foreach ($galleryIds as $galleryId) {
        $count += scan_gallery_images((int) $galleryId);
    }
    redirect_to(url_for('admin', ['scanned' => $count]));
}

/**
 * Render and process gallery metadata editing.
 */
function cms_admin_edit_gallery(): void
{
    require_admin();
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
    if (!$gallery) {
        cms_not_found();
        return;
    }
    // Variable $pictureGameReady stores this steps working value.

    $pictureGameReady = picture_game_schema_ready();
    // Variable $gpsMapReady stores this steps working value.
    $gpsMapReady = exif_gps_schema_ready();
    // Variable $accessReady stores this steps working value.
    $accessReady = gallery_access_schema_ready();
    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $title stores this steps working value.
        $title = trim((string) $_POST['title']);
        // Variable $slug stores this steps working value.
        $slug = trim((string) $_POST['slug']);
        // Variable $visibility stores this steps working value.
        $visibility = in_array($_POST['visibility'] ?? '', ['draft', 'public', 'private'], true) ? (string) $_POST['visibility'] : 'draft';
        // Variable $pictureGameEnabled stores this steps working value.
        $pictureGameEnabled = $pictureGameReady && !empty($_POST['picture_game_enabled']) ? 1 : 0;
        // Variable $gpsMapEnabled stores this steps working value.
        $gpsMapEnabled = $gpsMapReady && !empty($_POST['gps_map_enabled']) ? 1 : 0;
        // Variable $votingEnabled stores this steps working value.
        $votingEnabled = gallery_voting_schema_ready() && !empty($_POST['voting_enabled']) ? 1 : 0;
        if ($pictureGameEnabled) {
            $votingEnabled = 1;
        }
        if (!$votingEnabled) {
            $pictureGameEnabled = 0;
        }
        // Variable $accessType stores this steps working value.
        $accessType = $accessReady && in_array($_POST['access_type'] ?? '', ['password', 'share'], true) ? (string) $_POST['access_type'] : 'normal';
        // Variable $accessMode stores this steps working value.
        $accessMode = $accessType === 'normal' ? 'normal' : 'password';
        // Variable $accessListing stores this steps working value.
        $accessListing = $accessType === 'share' || ($accessReady && ($_POST['access_listing'] ?? '') === 'unlisted') ? 'unlisted' : 'listed';
        // Variable $accessPasswordHash stores this steps working value.
        $accessPasswordHash = $accessReady ? ($gallery['access_password_hash'] ?? null) : null;
        if ($accessType === 'share') {
            $accessPasswordHash = null;
        }
        if ($accessReady && !empty($_POST['clear_access_password'])) {
            $accessPasswordHash = null;
        }
        // Variable $newAccessPassword stores this steps working value.
        $newAccessPassword = trim((string) ($_POST['access_password'] ?? ''));
        if ($accessReady && $accessType === 'password' && $newAccessPassword !== '') {
            $accessPasswordHash = password_hash($newAccessPassword, PASSWORD_DEFAULT);
        }
        // Variable $parentId stores this steps working value.
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        // Variable $parentId stores this steps working value.
        $parentId = $parentId > 0 && find_gallery($parentId) ? $parentId : null;
        $currentFolderName = gallery_folder_name_from_path((string) $gallery['folder_path']);
        $submittedFolderName = trim((string) ($_POST['folder_name'] ?? $currentFolderName));
        $folderNameChanged = $submittedFolderName !== '' && $submittedFolderName !== $currentFolderName;
        $moveResult = null;
        if ((int) ($gallery['parent_id'] ?? 0) !== (int) ($parentId ?? 0) || $folderNameChanged) {
            try {
                $moveResult = move_gallery_folder_to_parent((int) $gallery['id'], $parentId, $folderNameChanged ? $submittedFolderName : null);
                if (!empty($moveResult['moved'])) {
                    admin_log_event('info', 'gallery.folder_moved', 'Admin moved a gallery folder.', [
                        'gallery_id' => (int) $gallery['id'],
                        'from' => (string) $moveResult['from'],
                        'to' => (string) $moveResult['to'],
                        'galleries' => (int) $moveResult['galleries'],
                    ]);
                }
                $gallery = find_gallery((int) $gallery['id']) ?: $gallery;
            } catch (Throwable $exception) {
                admin_log_event('error', 'gallery.folder_move_failed', 'Admin gallery folder move failed.', [
                    'gallery_id' => (int) $gallery['id'],
                    'error' => $exception->getMessage(),
                ]);
                $_SESSION['admin_gallery_error_' . (int) $gallery['id']] = $exception->getMessage();
                redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id'], 'move_failed' => 1]));
            }
        }
        // Variable $coverImageId stores this steps working value.
        $coverImageId = (int) ($_POST['cover_image_id'] ?? 0);
        // Variable $coverImage stores this steps working value.
        $coverImage = $coverImageId > 0 ? find_image($coverImageId) : null;
        // Variable $coverImageId stores this steps working value.
        $coverImageId = $coverImage && (int) $coverImage['gallery_id'] === (int) $gallery['id'] ? $coverImageId : null;
        $coverImagePath = gallery_cover_asset_schema_ready() ? gallery_cover_path($gallery) : null;
        $backgroundSource = null;
        if (gallery_background_source_schema_ready()) {
            $submittedBackgroundSource = (string) ($_POST['background_source'] ?? '');
            if (in_array($submittedBackgroundSource, ['upload', 'existing', 'collage'], true)) {
                $backgroundSource = $submittedBackgroundSource;
            }
        }
        if (gallery_cover_asset_schema_ready() && !empty($_FILES['cover_upload']['name'] ?? '')) {
            $uploadError = (int) ($_FILES['cover_upload']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                if ($uploadError !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(upload_error_message($uploadError));
                }
                $tmpName = (string) ($_FILES['cover_upload']['tmp_name'] ?? '');
                if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    throw new RuntimeException('Uploaded thumbnail is not available.');
                }
                $info = @getimagesize($tmpName);
                if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
                    throw new RuntimeException('The uploaded gallery thumbnail is not a valid image.');
                }
                $coverImagePath = store_uploaded_gallery_cover((int) $gallery['id'], $_FILES['cover_upload']);
                $coverImageId = null;
            }
        }
        // Variable $slug stores this steps working value.
        $slug = $slug !== '' ? slugify($slug) : unique_slug(db(), $title, (int) $gallery['id']);
        $fields = [
            'parent_id = ?' => $parentId,
            'cover_image_id = ?' => $coverImageId,
            'title = ?' => $title,
            'description = ?' => (string) $_POST['description'],
            'slug = ?' => unique_slug_for_value($slug, (int) $gallery['id']),
            'visibility = ?' => $visibility,
            'sort_order = ?' => (int) $_POST['sort_order'],
        ];
        if ($pictureGameReady) {
            $fields['picture_game_enabled = ?'] = $pictureGameEnabled;
        }
        if ($gpsMapReady) {
            $fields['gps_map_enabled = ?'] = $gpsMapEnabled;
        }
        if (gallery_voting_schema_ready()) {
            $fields['voting_enabled = ?'] = $votingEnabled;
        }
        if ($accessReady) {
            $fields['access_mode = ?'] = $accessMode;
            $fields['access_listing = ?'] = $accessMode === 'password' ? $accessListing : 'listed';
            $fields['access_password_hash = ?'] = $accessMode === 'password' ? $accessPasswordHash : null;
            if ($accessMode !== 'password') {
                if (gallery_access_share_token_schema_ready()) {
                    $fields['access_share_token = ?'] = null;
                }
                $fields['access_token_hash = ?'] = null;
                $fields['access_token_expires_at = ?'] = null;
            }
        }
        if (gallery_cover_asset_schema_ready()) {
            $fields['cover_image_path = ?'] = $coverImagePath;
        }
        if (gallery_background_source_schema_ready()) {
            $fields['background_source = ?'] = $backgroundSource;
        }
        $fields['updated_at = ?'] = now_sql();
        $stmt = db()->prepare('UPDATE galleries SET ' . implode(', ', array_keys($fields)) . ' WHERE id = ?');
        $stmt->execute(array_merge(array_values($fields), [(int) $gallery['id']]));
        if ($accessReady) {
            $accessAction = (string) ($_POST['access_action'] ?? 'save');
            if ($accessAction === 'revoke_link') {
                revoke_gallery_share_token((int) $gallery['id']);
            }
        }
        if ($accessReady && $accessMode === 'password') {
            $needsShareLink = $accessType === 'share' && empty($gallery['access_token_hash']);
            if ($accessAction === 'generate_link' || $needsShareLink) {
                $expires = trim((string) ($_POST['access_token_expires_at'] ?? ''));
                $expiresTimestamp = $expires !== '' ? strtotime($expires) : false;
                $expiresAt = $expiresTimestamp !== false ? date('Y-m-d H:i:s', $expiresTimestamp) : null;
                $_SESSION['new_gallery_share_token_' . (int) $gallery['id']] = regenerate_gallery_share_token((int) $gallery['id'], $expiresAt);
            }
        }
        sync_entity_tags('gallery', (int) $gallery['id'], (string) ($_POST['tags'] ?? ''));
        // Variable $gallery stores this steps working value.
        $gallery = find_gallery((int) $gallery['id']);
        if ($gallery) {
            write_gallery_sidecar($gallery);
        }
        $params = ['id' => $gallery['id'], 'saved' => 1];
        if (!empty($moveResult['moved'])) {
            $params['moved'] = 1;
        }
        redirect_to(url_for('admin_edit_gallery', $params));
    }
    // Variable $images stores this steps working value.
    $images = gallery_images((int) $gallery['id'], false);
    render_header('Edit gallery');
    $galleryError = (string) ($_SESSION['admin_gallery_error_' . (int) $gallery['id']] ?? '');
    unset($_SESSION['admin_gallery_error_' . (int) $gallery['id']]);
    if ($galleryError !== '') {
        echo '<div class="notice">Gallery folder move failed: ' . e($galleryError) . '</div>';
    }
    if (isset($_GET['created'])) {
        echo '<div class="notice">Gallery folder created.</div>';
    } elseif (isset($_GET['uploaded'])) {
        echo '<div class="notice">Uploaded ' . (int) $_GET['uploaded'] . ' images, scanned or updated ' . (int) ($_GET['scanned'] ?? 0) . ' image records, and created ' . (int) ($_GET['thumbnails'] ?? 0) . ' thumbnails.</div>';
    } elseif (isset($_GET['moved'])) {
        echo '<div class="notice">Gallery folder moved on disk and database paths were updated.</div>';
    } elseif (isset($_GET['saved'])) {
        echo '<div class="notice">Gallery saved.</div>';
    }
    if (!$pictureGameReady) {
        render_admin_migration_notice('Picture game settings are hidden until the latest database migration is applied.');
    }
    echo '<section class="panel"><h1>Edit gallery</h1><form method="post" enctype="multipart/form-data" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '">';
    echo '<label>Title<input name="title" value="' . e($gallery['title']) . '" required></label>';
    echo '<label>Description<textarea name="description">' . e($gallery['description']) . '</textarea></label>';
    echo '<label>Slug<input name="slug" value="' . e($gallery['slug']) . '" required></label>';
    echo '<label>Folder name<input name="folder_name" value="' . e(gallery_folder_name_from_path((string) $gallery['folder_path'])) . '" required><span class="muted">Changing this renames the folder on disk.</span></label>';
    echo '<label>Parent gallery<select name="parent_id"><option value="0">No parent</option>' . gallery_parent_options($gallery) . '</select></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options((string) $gallery['visibility']) . '</select></label>';
    if ($accessReady) {
        $newShareToken = (string) ($_SESSION['new_gallery_share_token_' . (int) $gallery['id']] ?? '');
        unset($_SESSION['new_gallery_share_token_' . (int) $gallery['id']]);
        $currentAccessType = 'normal';
        if ((string) ($gallery['access_mode'] ?? 'normal') === 'password') {
            $currentAccessType = empty($gallery['access_password_hash']) ? 'share' : 'password';
        }
        echo '<fieldset class="form-grid"><legend>Protected access</legend>';
        echo '<label>Access<select name="access_type"><option value="normal"' . ($currentAccessType === 'normal' ? ' selected' : '') . '>Normal public access</option><option value="password"' . ($currentAccessType === 'password' ? ' selected' : '') . '>Password protected</option><option value="share"' . ($currentAccessType === 'share' ? ' selected' : '') . '>Share link only</option></select></label>';
        echo '<label>Public listing<select name="access_listing"><option value="listed"' . ((string) ($gallery['access_listing'] ?? 'listed') === 'listed' ? ' selected' : '') . '>Listed without thumbnail</option><option value="unlisted"' . ((string) ($gallery['access_listing'] ?? 'listed') === 'unlisted' ? ' selected' : '') . '>Unlisted, direct link only</option></select></label>';
        echo '<label>New gallery password<input name="access_password" type="password" autocomplete="new-password"><span class="muted">Leave empty to keep the current gallery password.</span></label>';
        if (!empty($gallery['access_password_hash'])) {
            echo '<label><input type="checkbox" name="clear_access_password" value="1"> Clear current gallery password</label>';
        }
        echo '<label>Share link expiry<input name="access_token_expires_at" type="datetime-local" value="' . e(!empty($gallery['access_token_expires_at']) ? date('Y-m-d\TH:i', strtotime((string) $gallery['access_token_expires_at'])) : '') . '"><span class="muted">Leave empty for a non-expiring generated link.</span></label>';
        $visibleShareToken = $newShareToken !== '' ? $newShareToken : gallery_share_token_for_admin($gallery);
        if ($visibleShareToken !== null && $visibleShareToken !== '') {
            $shareLabel = $newShareToken !== '' ? 'Generated share link' : 'Active share link';
            echo '<label>' . $shareLabel . '<input readonly value="' . e(gallery_share_url((int) $gallery['id'], $visibleShareToken)) . '"></label>';
        } elseif (!empty($gallery['access_token_hash'])) {
            echo '<p class="muted">A share link is active' . (!empty($gallery['access_token_expires_at']) ? ' until ' . e((string) $gallery['access_token_expires_at']) : ' with no expiry') . ', but the original token cannot be displayed because it is stored as hash-only or cannot be decrypted on this server. Regenerate the link once to make a new copyable link visible here.</p>';
        } else {
            echo '<p class="muted">No share link is active.</p>';
        }
        echo '<p class="muted">Share-link-only galleries are hidden from public listings and get a link automatically when saved.</p>';
        echo '<div class="bulk-row"><button type="submit" class="secondary" name="access_action" value="generate_link">Generate/regenerate share link</button><button type="submit" class="secondary" name="access_action" value="revoke_link">Revoke share link</button></div>';
        echo '</fieldset>';
    } else {
        echo '<p class="notice">Protected gallery settings are hidden until the v0.13 database migration is applied.</p>';
    }
    if ($pictureGameReady) {
        echo '<label><input type="checkbox" name="picture_game_enabled" value="1"' . ((int) ($gallery['picture_game_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Enable picture game for this gallery branch</label>';
    }
    if (gallery_voting_schema_ready()) {
        echo '<label><input type="checkbox" name="voting_enabled" value="1"' . ((int) ($gallery['voting_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Enable image voting for this gallery</label>';
        echo '<p class="muted">When disabled, existing votes remain stored and visible, but vote arrows and vote submissions are blocked.</p>';
    }
    if ($gpsMapReady) {
        echo '<label><input type="checkbox" name="gps_map_enabled" value="1"' . ((int) ($gallery['gps_map_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Enable EXIF GPS maps for this gallery branch</label>';
        echo '<p class="muted">When enabled here, this gallery and its subgalleries may show photo map pins and gallery maps for images with GPS EXIF coordinates.</p>';
    }
    echo '<label>Sort order<input name="sort_order" type="number" value="' . (int) $gallery['sort_order'] . '"></label>';
    echo '<label>Title picture<select name="cover_image_id"><option value="0">Automatic</option>' . gallery_cover_options((int) $gallery['id'], (int) ($gallery['cover_image_id'] ?? 0), true) . '</select><span class="muted">Includes images from subgalleries.</span></label>';
    if (gallery_cover_asset_schema_ready()) {
        echo '<label>Upload gallery thumbnail<input type="file" name="cover_upload" accept="image/*"><span class="muted">This is stored separately from gallery images.</span></label>';
    } else {
        echo '<p class="muted">Uploadable gallery thumbnails will be available after the gallery thumbnail migration is applied.</p>';
    }
    if (gallery_background_source_schema_ready()) {
        $backgroundSource = gallery_background_source($gallery);
        echo '<label>Background source<select name="background_source"><option value=""' . ($backgroundSource === null ? ' selected' : '') . '>Use theme background</option><option value="upload"' . ($backgroundSource === 'upload' ? ' selected' : '') . '>Upload new image</option><option value="existing"' . ($backgroundSource === 'existing' ? ' selected' : '') . '>Pick from existing gallery images</option><option value="collage"' . ($backgroundSource === 'collage' ? ' selected' : '') . '>Generate collage from public galleries</option></select><span class="muted">If unset, the gallery inherits the Theme background.</span></label>';
    } else {
        echo '<p class="muted">Background source selection will be available after the background migration is applied.</p>';
    }
    echo '<label>Tags<input name="tags" value="' . e(tag_names_for_entity('gallery', (int) $gallery['id'])) . '" list="tag-suggestions" data-tag-input><span class="muted">Separate tags with commas.</span></label>';
    render_tag_datalist();
    echo '<button type="submit">Save gallery</button></form></section>';
    echo '<section class="panel"><h2>Scan</h2><form method="post" action="' . e(url_for('admin_scan_images')) . '" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<button type="submit">Scan/import images in this gallery</button></form></section>';
    echo '<section class="panel"><h2>Images</h2><form method="post" action="' . e(url_for('admin_bulk_images')) . '">' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<div class="bulk-row"><label><input type="checkbox" data-select-all="image_ids[]"> Select all images</label><label>Bulk action<select name="action"><option value="public">Set public</option><option value="draft">Set draft</option><option value="private">Set private</option><option value="cover">Set as title picture</option><option value="thumbs">Create thumbnails</option></select></label><button type="submit">Apply to selected</button><button type="submit" class="secondary" name="thumbnail_gallery_id" value="' . (int) $gallery['id'] . '" formaction="' . e(url_for('admin_create_thumbnails')) . '">Create gallery thumbnails</button></div>';
    echo '<table><thead><tr><th>Select</th><th>Preview</th><th>Image</th><th>Status</th><th>Cover</th><th>Actions</th></tr></thead><tbody>';
    foreach ($images as $image) {
        // Variable $isCover stores this steps working value.
        $isCover = (int) ($gallery['cover_image_id'] ?? 0) === (int) $image['id'];
        echo '<tr><td><input type="checkbox" name="image_ids[]" value="' . (int) $image['id'] . '"></td>';
        echo '<td><img class="admin-thumb" decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" alt=""></td>';
        echo '<td>' . e($image['relative_path']) . '</td><td>' . e($image['visibility']) . '</td><td>' . ($isCover ? 'Title picture' : '') . '</td><td><a href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '">Edit</a></td></tr>';
    }
    echo '</tbody></table></form></section>';
    render_footer();
}

/**
 * Apply a bulk action to images inside one gallery.
 */
function cms_admin_bulk_images(): void
{
    require_admin();
    verify_csrf();
    // Variable $galleryId stores this steps working value.
    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        cms_not_found();
        return;
    }
    // Variable $imageIds stores this steps working value.
    $imageIds = array_map('intval', $_POST['image_ids'] ?? []);
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? '');
    // Variable $count stores this steps working value.
    $count = 0;
    if (!$imageIds) {
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
    }
    // Variable $ownedIds stores this steps working value.
    $ownedIds = [];
    foreach ($imageIds as $imageId) {
        // Variable $image stores this steps working value.
        $image = find_image($imageId);
        if ($image && (int) $image['gallery_id'] === $galleryId) {
            $ownedIds[] = $imageId;
        }
    }
    if (!$ownedIds) {
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
    }
    if ($action === 'cover') {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$ownedIds[0], now_sql(), $galleryId]);
        // Variable $updated stores this steps working value.
        $updated = find_gallery($galleryId);
        if ($updated) {
            write_gallery_sidecar($updated);
        }
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId, 'saved' => 1]));
    }
    if (in_array($action, ['draft', 'public', 'private'], true)) {
        // Variable $placeholders stores this steps working value.
        $placeholders = implode(',', array_fill(0, count($ownedIds), '?'));
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE images SET visibility = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$action, now_sql()], $ownedIds));
    }
    if ($action === 'thumbs') {
        foreach ($ownedIds as $imageId) {
            // Variable $image stores this steps working value.
            $image = find_image($imageId);
            if ($image) {
                $count += create_image_thumbnails($image, $gallery);
            }
        }
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId, 'thumbnails' => $count]));
    }
    redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId, 'updated' => count($ownedIds)]));
}

/**
 * Save gallery metadata from admin controls rendered on public pages.
 */
function cms_admin_public_update_gallery(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_POST['gallery_id'] ?? 0));
    if (!$gallery) {
        cms_not_found();
        return;
    }
    // Variable $title stores this steps working value.
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') {
        $title = (string) $gallery['title'];
    }
    // Variable $visibility stores this steps working value.
    $visibility = (string) $gallery['visibility'];
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        // Variable $redirect stores this steps working value.
        $redirect = url_for('home');
        if (!empty($gallery['parent_id'])) {
            // Variable $parent stores this steps working value.
            $parent = find_gallery((int) $gallery['parent_id']);
            if ($parent) {
                $redirect = gallery_public_url($parent);
            }
        }
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('DELETE FROM galleries WHERE id = ?');
        $stmt->execute([(int) $gallery['id']]);
        redirect_to($redirect);
    }
    if ($action === 'publish') {
        $visibility = 'public';
    }
    if ($action === 'hide') {
        $visibility = 'private';
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('UPDATE galleries SET title = ?, description = ?, visibility = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$title, (string) ($_POST['description'] ?? ''), $visibility, now_sql(), (int) $gallery['id']]);
    if (public_path_schema_ready()) {
        regenerate_public_paths();
    }
    // Variable $updated stores this steps working value.
    $updated = find_gallery((int) $gallery['id']);
    if ($updated) {
        write_gallery_sidecar($updated);
    }
    redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? gallery_public_url($gallery)));
}

/**
 * Save image metadata from admin controls rendered on public pages.
 */
function cms_admin_public_update_image(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    // Variable $image stores this steps working value.
    $image = find_image((int) ($_POST['image_id'] ?? 0));
    if (!$image) {
        cms_not_found();
        return;
    }
    // Variable $visibility stores this steps working value.
    $visibility = (string) $image['visibility'];
    // Variable $action stores this steps working value.
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('DELETE FROM images WHERE id = ?');
        $stmt->execute([(int) $image['id']]);
        redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
    }
    if ($action === 'publish') {
        $visibility = 'public';
    }
    if ($action === 'hide') {
        $visibility = 'private';
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('UPDATE images SET title = ?, description = ?, visibility = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([trim((string) ($_POST['title'] ?? '')), (string) ($_POST['description'] ?? ''), $visibility, now_sql(), (int) $image['id']]);
    if (public_path_schema_ready()) {
        regenerate_public_paths();
    }
    redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
}

/**
 * Render and process image metadata editing.
 */
function cms_admin_edit_image(): void
{
    require_admin();
    // Variable $image stores this steps working value.
    $image = find_image((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
    if (!$image) {
        cms_not_found();
        return;
    }
    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $visibility stores this steps working value.
        $visibility = in_array($_POST['visibility'] ?? '', ['draft', 'public', 'private'], true) ? (string) $_POST['visibility'] : 'public';
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE images SET title = ?, description = ?, visibility = ?, sort_order = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([(string) $_POST['title'], (string) $_POST['description'], $visibility, (int) $_POST['sort_order'], now_sql(), (int) $image['id']]);
        sync_entity_tags('image', (int) $image['id'], (string) ($_POST['tags'] ?? ''));
        if (public_path_schema_ready()) {
            regenerate_public_paths();
        }
        redirect_to(url_for('admin_edit_image', ['id' => $image['id'], 'saved' => 1]));
    }
    render_header('Edit image');
    echo '<section class="panel"><h1>Edit image</h1><p><img decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 800)) . '" alt=""></p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $image['id'] . '">';
    echo '<label>Title<input name="title" value="' . e($image['title']) . '"></label>';
    echo '<label>Description<textarea name="description">' . e($image['description']) . '</textarea></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options((string) $image['visibility']) . '</select></label>';
    echo '<label>Sort order<input name="sort_order" type="number" value="' . (int) $image['sort_order'] . '"></label>';
    echo '<label>Tags<input name="tags" value="' . e(tag_names_for_entity('image', (int) $image['id'])) . '" list="tag-suggestions" data-tag-input><span class="muted">Separate tags with commas.</span></label>';
    render_tag_datalist();
    if (exif_gps_schema_ready()) {
        echo '<div class="exif-admin-summary"><h2>EXIF / GPS</h2><dl>';
        echo '<dt>Taken</dt><dd>' . e((string) ($image['exif_taken_at'] ?? '')) . '</dd>';
        echo '<dt>Camera</dt><dd>' . e(trim((string) ($image['exif_camera_make'] ?? '') . ' ' . (string) ($image['exif_camera_model'] ?? ''))) . '</dd>';
        echo '<dt>Lens</dt><dd>' . e((string) ($image['exif_lens_model'] ?? '')) . '</dd>';
        echo '<dt>Exposure</dt><dd>' . e(trim((string) ($image['exif_focal_length'] ?? '') . ' ' . (string) ($image['exif_aperture'] ?? '') . ' ' . (string) ($image['exif_exposure_time'] ?? '') . ' ISO ' . (string) ($image['exif_iso'] ?? ''))) . '</dd>';
        echo '<dt>GPS</dt><dd>' . (image_has_gps($image) ? e((string) $image['gps_lat'] . ', ' . (string) $image['gps_lng']) : 'No GPS coordinates found') . '</dd>';
        echo '</dl><p class="muted">EXIF and GPS values are refreshed when the image is scanned again.</p></div>';
    }
    echo '<button type="submit">Save image</button></form></section>';
    render_footer();
}

/**
 * Build visibility select options.
 */
function visibility_options(string $selected): string
{
    // Variable $html stores this steps working value.
    $html = '';
    foreach (['draft', 'public', 'private'] as $visibility) {
        $html .= '<option value="' . e($visibility) . '"' . ($visibility === $selected ? ' selected' : '') . '>' . e($visibility) . '</option>';
    }
    return $html;
}

/**
 * Render existing tags as datalist suggestions for tag inputs.
 */
function render_tag_datalist(): void
{
    echo '<datalist id="tag-suggestions">';
    foreach (all_tag_names() as $name) {
        echo '<option value="' . e((string) $name) . '"></option>';
    }
    echo '</datalist>';
}

/**
 * Build parent gallery options while preventing cycles.
 */
function gallery_parent_options(array $currentGallery): string
{
    // Variable $galleries stores this steps working value.
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    // Variable $html stores this steps working value.
    $html = '';
    // Variable $currentPath stores this steps working value.
    $currentPath = rtrim((string) $currentGallery['folder_path'], '/');
    foreach ($galleries as $gallery) {
        if ((int) $gallery['id'] === (int) $currentGallery['id']) {
            continue;
        }
        // Variable $path stores this steps working value.
        $path = (string) $gallery['folder_path'];
        if ($path !== '' && str_starts_with($path . '/', $currentPath . '/')) {
            continue;
        }
        // Variable $selected stores this steps working value.
        $selected = (int) ($currentGallery['parent_id'] ?? 0) === (int) $gallery['id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $gallery['id'] . '"' . $selected . '>' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

/**
 * Build parent gallery options for a new gallery or upload-created gallery.
 */
function gallery_parent_options_for_new(): string
{
    $html = '';
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        $html .= '<option value="' . (int) $gallery['id'] . '">' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

/**
 * Build existing gallery options for upload targets.
 */
function gallery_options_for_select(): string
{
    $html = '';
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        $html .= '<option value="' . (int) $gallery['id'] . '">' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

/**
 * Build cover-image options for one gallery.
 */
function gallery_cover_options(int $galleryId, int $selectedImageId, bool $includeDescendants = false): string
{
    $images = $includeDescendants ? gallery_cover_choices($galleryId, false) : array_map(static fn (array $image): array => ['image' => $image], gallery_images($galleryId, false));
    // Variable $html stores this steps working value.
    $html = '';
    foreach ($images as $entry) {
        $image = $entry['image'];
        // Variable $selected stores this steps working value.
        $selected = $selectedImageId === (int) $image['id'] ? ' selected' : '';
        // Variable $label stores this steps working value.
        $label = ($image['title'] ?: $image['filename']) . ' (' . $image['relative_path'] . ')';
        if ($includeDescendants && !empty($entry['gallery_title'])) {
            $label = $entry['gallery_title'] . ' - ' . $label;
        }
        $html .= '<option value="' . (int) $image['id'] . '"' . $selected . '>' . e($label) . '</option>';
    }
    return $html;
}

/**
 * Generate a unique slug from a submitted slug value.
 */
function unique_slug_for_value(string $slug, int $excludeGalleryId): string
{
    // Variable $pdo stores this steps working value.
    $pdo = db();
    // Variable $base stores this steps working value.
    $base = slugify($slug);
    // Variable $candidate stores this steps working value.
    $candidate = $base;
    // Variable $counter stores this steps working value.
    $counter = 2;
    while (true) {
        // Variable $stmt stores this steps working value.
        $stmt = $pdo->prepare('SELECT id FROM galleries WHERE slug = ? AND id <> ?');
        $stmt->execute([$candidate, $excludeGalleryId]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        // Variable $candidate stores this steps working value.
        $candidate = $base . '-' . $counter;
        $counter++;
    }
}

/**
 * Browser setup route protected by config setup_key.
 */
function cms_setup(): void
{
    if (cms_setup_is_locked()) {
        http_response_code(403);
        render_header('Setup locked');
        echo '<section class="panel"><h1>Setup locked</h1><p>The setup endpoint is locked because installation has already completed.</p></section>';
        render_footer();
        return;
    }
    // Variable $key stores this steps working value.
    $key = (string) ($_GET['key'] ?? '');
    if ($key === '' || !hash_equals((string) cms_config()['setup_key'], $key)) {
        cms_not_found();
        return;
    }
    // Variable $ran stores this steps working value.
    $ran = run_migrations();
    if (cms_admin_user_exists()) {
        cms_write_setup_lock();
        http_response_code(403);
        render_header('Setup locked');
        echo '<section class="panel"><h1>Setup locked</h1><p>The setup endpoint is locked because an administrator already exists.</p></section>';
        render_footer();
        return;
    }
    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $username stores this steps working value.
        $username = trim((string) $_POST['username']);
        // Variable $password stores this steps working value.
        $password = (string) $_POST['password'];
        if ($username !== '' && $password !== '') {
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('INSERT INTO users (username, password_hash, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), updated_at = VALUES(updated_at)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin', now_sql(), now_sql()]);
            cms_write_setup_lock();
            redirect_to(url_for('admin_login'));
        }
    }
    render_header('Setup');
    echo '<section class="panel"><h1>Setup</h1><p>Applied migrations: ' . e($ran ? implode(', ', $ran) : 'none') . '</p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<label>Admin username<input name="username" required></label>';
    echo '<label>Admin password<input name="password" type="password" required></label>';
    echo '<button type="submit">Create or update admin</button></form></section>';
    render_footer();
}

/**
 * Render dynamic theme CSS without using HTML style attributes.
 */

/**
 * Return JSON map points for a gallery branch.
 *
 * The endpoint uses the same public/private access rules as the gallery page and
 * only returns points when GPS maps are enabled on this gallery or an ancestor.
 */
function cms_gallery_map_data(): void
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    if (!$gallery || (!visitor_can_access_gallery($gallery) && !current_user())) {
        cms_not_found();
        return;
    }
    // Variable $publicOnly stores this steps working value.
    $publicOnly = !current_user();
    // Variable $points stores this steps working value.
    $points = gallery_map_points($gallery, $publicOnly, true);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'gallery_id' => (int) $gallery['id'],
        'title' => (string) $gallery['title'],
        'points' => $points,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function cms_theme_css(): void
{
    $updatePendingCss = '.nav a.is-update-pending,.button.is-update-pending,button.is-update-pending{border-color:#7f1d1d!important;background:repeating-linear-gradient(135deg,#b91c1c 0 .55rem,#f59e0b .55rem 1.1rem)!important;color:#fff!important;box-shadow:0 0 0 2px #fff,0 0 0 4px #7f1d1d!important;font-weight:800;}';
    $themeBackground = theme_background_asset_url();
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=31536000, immutable');
    $theme = theme_settings();
    $fontFamily = $theme['font'] === 'sans' ? 'Arial, Helvetica, sans-serif' : 'Georgia, Times New Roman, serif';
    $backgroundOpacity = max(0, min(100, (int) ($theme['background_opacity'] ?? 65)));
    echo ':root{';
    echo '--accent:' . css_value((string) $theme['accent']) . ';';
    echo '--accent-dark:' . css_value((string) $theme['accent_dark']) . ';';
    echo '--paper:' . css_value((string) $theme['paper']) . ';';
    echo '--panel:' . css_value((string) $theme['panel']) . ';';
    echo '--gallery-panel:' . css_value((string) $theme['gallery_panel']) . ';';
    echo '--header-text:' . css_value((string) $theme['header_text']) . ';';
    echo '--hero-text:' . css_value((string) $theme['hero_text']) . ';';
    echo '--radius:' . (int) $theme['radius'] . 'px;';
    echo '--font-family:' . css_value($fontFamily) . ';';
    echo '}';
    echo 'body,.admin-page{color:var(--ink);background:var(--paper);font-family:var(--font-family);}';
    echo '.public-page{color:var(--ink);background:var(--paper);font-family:var(--font-family);position:relative;}';
    echo '.theme-background-shell{position:fixed;inset:0;pointer-events:none;z-index:0;}';
    echo '.theme-background-base,.theme-background-image{position:absolute;inset:0;}';
    echo '.theme-background-base{background:var(--paper);}';
    echo '.theme-background-image{background-image:' . ($themeBackground !== '' ? 'url("' . css_value($themeBackground) . '")' : 'none') . ';background-size:cover;background-position:center center;background-repeat:no-repeat;opacity:' . number_format($backgroundOpacity / 100, 2, '.', '') . ';}';
    echo '.public-page > *:not(.theme-background-shell):not(.map-overlay):not(.lightbox){position:relative;z-index:1;}';
    echo 'a{color:var(--accent-dark);}';
    echo '.site-header{background:rgba(255,255,255,0.10);backdrop-filter:blur(12px) saturate(1.08);-webkit-backdrop-filter:blur(12px) saturate(1.08);border-color:rgba(255,255,255,0.22);padding:clamp(1rem,3vw,2rem);margin-bottom:1rem;border-radius:var(--radius);}';
    echo '.admin-page .site-header{background:var(--paper);border-color:var(--line);}';
    echo '.brand{color:var(--header-text, var(--ink));font-family:var(--font-family);}';
    echo '.admin-page .brand{color:var(--ink);font-family:var(--font-family);}';
    echo '.nav a,.button,button,input[type="submit"]{border-color:var(--accent-dark);background:var(--accent);color:#fffdf8;border-radius:var(--radius);}';
    echo '.nav a:hover,.button:hover,button:hover,input[type="submit"]:hover{border-color:var(--accent-dark);background:var(--accent-dark);}';
    echo '.lightbox .lightbox-stage-link,.lightbox .lightbox-stage-link:hover,.lightbox .lightbox-stage-link:focus,.lightbox .lightbox-stage-link:focus-visible,.lightbox .lightbox-stage-link:active{border:0!important;background:transparent!important;color:inherit!important;box-shadow:none!important;text-decoration:none!important;outline:0!important;}';
    echo '.lightbox .lightbox-stage-link::-moz-focus-inner{border:0!important;}';
    echo '.button.secondary,button.secondary{border-color:var(--accent-dark);background:transparent;color:var(--accent-dark);}';
    echo '.hero,.panel,.gallery-card,.image-card,.admin-page .hero,.admin-page .panel{background:var(--panel);border-color:var(--line);border-radius:var(--radius);}';
    echo '.public-page .hero{background:rgba(255,255,255,0.18);backdrop-filter:blur(10px) saturate(1.06);-webkit-backdrop-filter:blur(10px) saturate(1.06);position:relative;overflow:hidden;border-color:rgba(255,255,255,0.28);}';
    echo '.public-page .hero > *{position:relative;z-index:1;}';
    echo '.public-page .hero::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(255,255,255,.10) 0%,rgba(255,255,255,.16) 52%,rgba(255,255,255,.22) 100%);pointer-events:none;}';
    echo '.public-page .hero h1,.public-page .hero p,.public-page .hero .tag-list-label{color:var(--hero-text, var(--ink));}';
    echo '.gallery-card-link{background:var(--panel);color:inherit;}';
    echo '.gallery-card-body h2,.image-meta h2{color:var(--ink);}';
    echo '.inline-editor{border-color:var(--line);background:var(--field);border-radius:var(--radius);}';
    echo 'input,textarea,select{background:var(--field);border-color:var(--line);border-radius:var(--radius);color:var(--ink);}';
    echo 'input:focus,textarea:focus,select:focus{border-color:var(--accent);outline-color:color-mix(in srgb,var(--accent) 22%,transparent);}';
    echo '.tag{border-color:var(--accent);background:var(--field);color:var(--accent-dark);}';
    echo '.tag:hover,.tag:focus{border-color:var(--accent-dark);background:var(--panel);color:var(--accent-dark);}';
    echo 'table{border-color:var(--line);border-radius:var(--radius);}';
    echo 'th{background:var(--field);color:var(--ink);}';
    echo $updatePendingCss;
}

/**
 * Render a generic 404 page.
 */
function cms_not_found(): void
{
    http_response_code(404);
    render_header('Not found');
    echo '<section class="panel"><h1>Not found</h1><p>The requested page was not found.</p></section>';
    render_footer();
}
