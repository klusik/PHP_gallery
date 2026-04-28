<?php

declare(strict_types=1);

/**
 * Public homepage showing top-level public galleries.
 */
function cms_home(): void
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE g.visibility = 'public' AND g.parent_id IS NULL
        GROUP BY g.id
        ORDER BY g.sort_order, g.title");
    $stmt->execute();
    render_header('Galleries');
    echo '<section class="hero"><h1>Galleries</h1><p class="muted">Filesystem-backed galleries with CMS metadata.</p></section>';
    echo '<section class="grid">';
    foreach ($stmt->fetchAll() as $gallery) {
        render_gallery_card($gallery, true);
    }
    echo '</section>';
    render_footer();
}

/**
 * Public gallery detail page with breadcrumbs, subgalleries, images, tags, and votes.
 */
function cms_gallery(): void
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery_by_slug((string) ($_GET['slug'] ?? ''));
    if (!$gallery || ($gallery['visibility'] !== 'public' && !current_user())) {
        cms_not_found();
        return;
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT i.*, COALESCE(SUM(v.vote), 0) AS score
        FROM images i
        LEFT JOIN image_votes v ON v.image_id = i.id
        WHERE i.gallery_id = ? AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        GROUP BY i.id
        ORDER BY i.sort_order, i.filename");
    $stmt->execute([(int) $gallery['id']]);
    // Variable $images stores this steps working value.
    $images = $stmt->fetchAll();
    // Variable $children stores this steps working value.
    $children = child_galleries((int) $gallery['id'], true);

    render_header((string) $gallery['title']);
    render_breadcrumbs($gallery);
    echo '<section class="hero"><h1>' . e($gallery['title']) . '</h1><p>' . e($gallery['description']) . '</p>';
    render_tag_list(tags_for_entity('gallery', (int) $gallery['id']));
    if ($children) {
        render_tag_list(contained_tags_for_gallery($gallery, true), 'Containing tags');
    }
    echo '<a class="button" href="' . e(url_for('download_gallery', ['id' => $gallery['id']])) . '">Download gallery</a></section>';
    if ($children) {
        echo '<section class="panel"><h2>Subgalleries</h2><div class="grid">';
        foreach ($children as $child) {
            render_gallery_card($child, true);
        }
        echo '</div></section>';
    }
    echo '<section class="grid">';
    foreach ($images as $image) {
        // Variable $mediaUrl stores this steps working value.
        $mediaUrl = url_for('media', ['id' => $image['id']]);
        // Variable $previewUrl stores this steps working value.
        $previewUrl = thumbnail_url($image, 300);
        // Variable $imageTags stores this steps working value.
        $imageTags = tags_for_entity('image', (int) $image['id']);
        echo '<article class="image-card" data-lightbox-image data-image-id="' . (int) $image['id'] . '" data-full-src="' . e($mediaUrl) . '" data-title="' . e($image['title'] ?: $image['filename']) . '" data-description="' . e($image['description']) . '" data-score="' . (int) $image['score'] . '" data-user-vote="' . current_vote_for_image((int) $image['id']) . '">';
        echo '<a href="' . e($mediaUrl) . '"><img loading="lazy" src="' . e($previewUrl) . '" srcset="' . e(thumbnail_url($image, 300)) . ' 300w, ' . e(thumbnail_url($image, 800)) . ' 800w" sizes="(min-width: 900px) 33vw, 100vw" alt="' . e($image['title'] ?: $image['filename']) . '"></a>';
        echo '<div class="image-meta"><h2>' . e($image['title'] ?: $image['filename']) . '</h2><p>' . e($image['description']) . '</p>';
        render_tag_list($imageTags);
        render_vote_form((int) $image['id'], (int) $image['score'], current_vote_for_image((int) $image['id']));
        echo '</div></article>';
    }
    echo '</section>';
    render_lightbox();
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
    echo '<section class="grid">';
    foreach ($galleries as $gallery) {
        render_gallery_card($gallery, true);
    }
    echo '</section>';
    render_footer();
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
            echo '<span aria-hidden="true">/</span><a href="' . e(url_for('gallery', ['slug' => $ancestor['slug']])) . '">' . e($ancestor['title']) . '</a>';
        }
        echo '<span aria-hidden="true">/</span><span>' . e($gallery['title']) . '</span>';
    }
    echo '</nav>';
}

/**
 * Render one gallery card, including direct cover or child-cover collage.
 */
function render_gallery_card(array $gallery, bool $publicOnly): void
{
    // Variable $cover stores this steps working value.
    $cover = gallery_cover_image((int) $gallery['id'], $publicOnly);
    echo '<article class="gallery-card"><a class="gallery-card-link" href="' . e(url_for('gallery', ['slug' => $gallery['slug']])) . '">';
    if ($cover) {
        echo '<img loading="lazy" src="' . e(thumbnail_url($cover, 300)) . '" srcset="' . e(thumbnail_url($cover, 300)) . ' 300w, ' . e(thumbnail_url($cover, 800)) . ' 800w" sizes="(min-width: 900px) 360px, 100vw" alt="">';
    } else {
        // Variable $collage stores this steps working value.
        $collage = gallery_cover_collage_images((int) $gallery['id'], $publicOnly);
        if ($collage) {
            echo '<span class="gallery-collage collage-count-' . count($collage) . '">';
            foreach ($collage as $image) {
                echo '<img loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" srcset="' . e(thumbnail_url($image, 300)) . ' 300w, ' . e(thumbnail_url($image, 800)) . ' 800w" sizes="180px" alt="">';
            }
            echo '</span>';
        }
    }
    echo '<span class="gallery-card-body"><h2>' . e($gallery['title']) . '</h2>';
    echo '<p>' . e($gallery['description']) . '</p>';
    echo '<p class="muted">' . (int) $gallery['image_count'] . ' images</p></span>';
    echo '</a>';
    render_tag_list(contained_tags_for_gallery($gallery, $publicOnly), 'Containing tags');
    echo '</article>';
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
function render_vote_form(int $imageId, int $score, int $currentVote): void
{
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
function render_lightbox(): void
{
    echo '<div class="lightbox" data-lightbox hidden>';
    echo '<button class="lightbox-close" type="button" data-lightbox-action="close">Close</button>';
    echo '<button type="button" data-lightbox-action="previous" aria-label="Previous image">&lt;</button>';
    echo '<figure><a data-lightbox-original-link href="#"><img data-lightbox-img alt=""></a><figcaption class="lightbox-meta"><div class="lightbox-toolbar"><span class="lightbox-counter" data-lightbox-counter></span><a class="lightbox-original-button" data-lightbox-original-link href="#">Open original</a><span class="lightbox-help">Arrow keys navigate, Esc closes</span></div><h2 data-lightbox-title></h2><p data-lightbox-description></p><form class="vote-row lightbox-vote" method="post" action="' . e(url_for('vote')) . '" data-vote-form data-lightbox-vote-form><input type="hidden" name="image_id" value="">' . csrf_field() . '<span>Score: <strong data-lightbox-score data-score-for="">0</strong></span><button type="submit" name="vote" value="1" aria-label="Vote up">&#9650;</button><button type="submit" name="vote" value="-1" aria-label="Vote down">&#9660;</button></form></figcaption></figure>';
    echo '<button type="button" data-lightbox-action="next" aria-label="Next image">&gt;</button>';
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
    if (!$image || !in_array($size, thumbnail_sizes(), true)) {
        cms_not_found();
        return;
    }
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $image['gallery_id']);
    if (!$gallery || (($gallery['visibility'] !== 'public' || $image['visibility'] !== 'public') && !current_user())) {
        cms_not_found();
        return;
    }
    try {
        // Variable $path stores this steps working value.
        $path = thumbnail_abs_path($image, $gallery, $size);
    } catch (RuntimeException) {
        cms_not_found();
        return;
    }
    if (!is_file($path)) {
        cms_not_found();
        return;
    }
    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=604800');
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
    if (!$gallery || (($gallery['visibility'] !== 'public' || $image['visibility'] !== 'public') && !current_user())) {
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
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=604800');
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
    if (!in_array($vote, [-1, 1], true) || !find_image($imageId)) {
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
    if (!$gallery || $gallery['visibility'] !== 'public') {
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
        set_app_setting('theme_accent', sanitize_hex_color((string) $_POST['theme_accent'], '#a5481c'));
        set_app_setting('theme_accent_dark', sanitize_hex_color((string) $_POST['theme_accent_dark'], '#713414'));
        set_app_setting('theme_paper', sanitize_hex_color((string) $_POST['theme_paper'], '#f8f4ec'));
        set_app_setting('theme_panel', sanitize_hex_color((string) $_POST['theme_panel'], '#fffaf0'));
        set_app_setting('theme_radius', (string) max(0, min(32, (int) $_POST['theme_radius'])));
        set_app_setting('theme_font', in_array($_POST['theme_font'] ?? '', ['serif', 'sans'], true) ? (string) $_POST['theme_font'] : 'serif');
        if (!empty($_FILES['custom_css']['tmp_name']) && is_uploaded_file($_FILES['custom_css']['tmp_name'])) {
            // Variable $name stores this steps working value.
            $name = strtolower((string) ($_FILES['custom_css']['name'] ?? ''));
            if (str_ends_with($name, '.css')) {
                move_uploaded_file($_FILES['custom_css']['tmp_name'], custom_css_path());
            }
        }
        redirect_to(url_for('admin_theme', ['saved' => 1]));
    }
    // Variable $theme stores this steps working value.
    $theme = theme_settings();
    render_header('Theme');
    echo '<section class="panel"><h1>Theme</h1><form method="post" enctype="multipart/form-data" class="form-grid">' . csrf_field();
    echo '<label>Accent color<input type="color" name="theme_accent" value="' . e((string) $theme['accent']) . '"></label>';
    echo '<label>Dark accent<input type="color" name="theme_accent_dark" value="' . e((string) $theme['accent_dark']) . '"></label>';
    echo '<label>Page background<input type="color" name="theme_paper" value="' . e((string) $theme['paper']) . '"></label>';
    echo '<label>Panel background<input type="color" name="theme_panel" value="' . e((string) $theme['panel']) . '"></label>';
    echo '<label>Rounded corners<input type="range" name="theme_radius" min="0" max="32" value="' . (int) $theme['radius'] . '"></label>';
    echo '<label>Font style<select name="theme_font"><option value="serif"' . ($theme['font'] === 'serif' ? ' selected' : '') . '>Classic serif</option><option value="sans"' . ($theme['font'] === 'sans' ? ' selected' : '') . '>Clean sans-serif</option></select></label>';
    echo '<label>Custom CSS file<input type="file" name="custom_css" accept=".css,text/css"></label>';
    echo '<p class="muted">Uploaded CSS is loaded after the built-in stylesheet and theme controls.</p>';
    echo '<button type="submit">Save theme</button></form></section>';
    render_footer();
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
 * Admin dashboard for gallery scanning, publishing, and bulk actions.
 */
function cms_admin(): void
{
    require_admin();
    sync_gallery_parent_ids();
    // Variable $galleries stores this steps working value.
    $galleries = db()->query("SELECT g.*, parent.title AS parent_title, COUNT(i.id) AS image_count FROM galleries g LEFT JOIN galleries parent ON parent.id = g.parent_id LEFT JOIN images i ON i.gallery_id = g.id AND i.relative_path NOT LIKE '%/%' GROUP BY g.id, parent.title ORDER BY g.folder_path")->fetchAll();
    // Variable $collapsedIds stores this steps working value.
    $collapsedIds = array_flip(collapsed_gallery_ids());
    render_header('Admin dashboard');
    echo '<section class="hero"><h1>Admin dashboard</h1><nav class="nav">';
    echo '<a class="button" href="' . e(url_for('admin_discover')) . '">Check for new gallery folders</a>';
    echo '<a class="button secondary" href="' . e(url_for('download_all')) . '">Download all galleries</a>';
    echo '<form method="post" action="' . e(url_for('admin_create_thumbnails')) . '" class="inline-form">' . csrf_field() . '<input type="hidden" name="scope" value="all"><button type="submit" class="secondary">Create all thumbnails</button></form>';
    echo '</nav></section>';
    echo '<section class="panel"><h2>Galleries</h2><form method="post" action="' . e(url_for('admin_bulk_galleries')) . '">' . csrf_field();
    echo '<div class="bulk-row"><label><input type="checkbox" data-select-all="gallery_ids[]"> Select all galleries</label><label>Bulk action<select name="action"><option value="scan">Scan/import images</option><option value="thumbs">Create thumbnails</option><option value="public">Set public</option><option value="draft">Set draft</option><option value="private">Set private</option></select></label><button type="submit">Apply to selected</button><button type="button" class="secondary" data-gallery-tree-action="collapse-all">Collapse all</button><button type="button" class="secondary" data-gallery-tree-action="expand-all">Expand all</button></div>';
    echo '<table><thead><tr><th>Select</th><th>Title</th><th>Parent</th><th>Folder</th><th>Status</th><th>Images</th><th>Actions</th></tr></thead><tbody>';
    foreach ($galleries as $gallery) {
        // Variable $depth stores this steps working value.
        $depth = substr_count((string) $gallery['folder_path'], '/');
        // Variable $hasChildren stores this steps working value.
        $hasChildren = array_filter($galleries, static fn (array $candidate): bool => (int) ($candidate['parent_id'] ?? 0) === (int) $gallery['id']);
        // Variable $isCollapsed stores this steps working value.
        $isCollapsed = isset($collapsedIds[(int) $gallery['id']]);
        echo '<tr class="' . ($depth > 0 ? 'is-subgallery' : '') . ($isCollapsed ? ' is-collapsed' : '') . '" data-gallery-row data-gallery-id="' . (int) $gallery['id'] . '" data-parent-id="' . (int) ($gallery['parent_id'] ?? 0) . '" data-depth="' . $depth . '"><td><input type="checkbox" name="gallery_ids[]" value="' . (int) $gallery['id'] . '"></td>';
        // Variable $depthClass stores this steps working value.
        $depthClass = 'tree-depth-' . min($depth, 8);
        echo '<td><span class="tree-title ' . e($depthClass) . '">' . ($hasChildren ? '<button type="button" class="tree-toggle" data-gallery-toggle="' . (int) $gallery['id'] . '" aria-expanded="' . ($isCollapsed ? 'false' : 'true') . '">' . ($isCollapsed ? '+' : '-') . '</button>' : '<span class="tree-spacer" aria-hidden="true"></span>') . ($depth > 0 ? '<span class="tree-branch" aria-hidden="true"></span>' : '') . '<a href="' . e(url_for('gallery', ['slug' => $gallery['slug']])) . '">' . e($gallery['title']) . '</a></span></td>';
        echo '<td>' . e($gallery['parent_title'] ?: '') . '</td><td>' . e($gallery['folder_path']) . '</td><td>' . e($gallery['visibility']) . '</td><td>' . (int) $gallery['image_count'] . '</td><td class="nav">';
        echo '<a href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '">Edit</a>';
        echo '<button type="submit" class="secondary" name="thumbnail_gallery_id" value="' . (int) $gallery['id'] . '" formaction="' . e(url_for('admin_create_thumbnails')) . '">Thumbs</button>';
        echo '</td></tr>';
    }
    echo '</tbody></table></form></section>';
    render_footer();
}

/**
 * Show filesystem folders that can be imported as galleries.
 */
function cms_admin_discover(): void
{
    require_admin();
    // Variable $candidates stores this steps working value.
    $candidates = discover_gallery_candidates();
    render_header('New gallery folders');
    echo '<section class="panel"><h1>New gallery folders</h1>';
    if (!$candidates) {
        echo '<p>No new gallery folders found.</p>';
    } else {
        echo '<form method="post" action="' . e(url_for('admin_import')) . '">' . csrf_field();
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
    // Variable $result stores this steps working value.
    $result = import_galleries($_POST['folders'] ?? [], !empty($_POST['create_thumbnails']));
    redirect_to(url_for('admin', $result));
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
    redirect_to(url_for('admin'));
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
    }
    // Variable $processed stores this steps working value.
    $processed = min($total, $offset + count($batch));
    header('Content-Type: application/json');
    echo json_encode([
        'total' => $total,
        'processed' => $processed,
        'next_offset' => $processed,
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
    if ($galleryId > 0 && !empty($post['image_ids']) && is_array($post['image_ids'])) {
        // Variable $ids stores this steps working value.
        $ids = [];
        foreach (array_map('intval', $post['image_ids']) as $imageId) {
            // Variable $image stores this steps working value.
            $image = find_image($imageId);
            if ($image && (int) $image['gallery_id'] === $galleryId) {
                $ids[] = $imageId;
            }
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
    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $title stores this steps working value.
        $title = trim((string) $_POST['title']);
        // Variable $slug stores this steps working value.
        $slug = trim((string) $_POST['slug']);
        // Variable $visibility stores this steps working value.
        $visibility = in_array($_POST['visibility'] ?? '', ['draft', 'public', 'private'], true) ? (string) $_POST['visibility'] : 'draft';
        // Variable $parentId stores this steps working value.
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        // Variable $parentId stores this steps working value.
        $parentId = $parentId > 0 && find_gallery($parentId) ? $parentId : null;
        // Variable $coverImageId stores this steps working value.
        $coverImageId = (int) ($_POST['cover_image_id'] ?? 0);
        // Variable $coverImage stores this steps working value.
        $coverImage = $coverImageId > 0 ? find_image($coverImageId) : null;
        // Variable $coverImageId stores this steps working value.
        $coverImageId = $coverImage && (int) $coverImage['gallery_id'] === (int) $gallery['id'] ? $coverImageId : null;
        // Variable $slug stores this steps working value.
        $slug = $slug !== '' ? slugify($slug) : unique_slug(db(), $title, (int) $gallery['id']);
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE galleries SET parent_id = ?, cover_image_id = ?, title = ?, description = ?, slug = ?, visibility = ?, sort_order = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$parentId, $coverImageId, $title, (string) $_POST['description'], unique_slug_for_value($slug, (int) $gallery['id']), $visibility, (int) $_POST['sort_order'], now_sql(), (int) $gallery['id']]);
        sync_entity_tags('gallery', (int) $gallery['id'], (string) ($_POST['tags'] ?? ''));
        // Variable $gallery stores this steps working value.
        $gallery = find_gallery((int) $gallery['id']);
        if ($gallery) {
            write_gallery_sidecar($gallery);
        }
        redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id'], 'saved' => 1]));
    }
    // Variable $images stores this steps working value.
    $images = gallery_images((int) $gallery['id'], false);
    render_header('Edit gallery');
    echo '<section class="panel"><h1>Edit gallery</h1><form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '">';
    echo '<label>Title<input name="title" value="' . e($gallery['title']) . '" required></label>';
    echo '<label>Description<textarea name="description">' . e($gallery['description']) . '</textarea></label>';
    echo '<label>Slug<input name="slug" value="' . e($gallery['slug']) . '" required></label>';
    echo '<label>Parent gallery<select name="parent_id"><option value="0">No parent</option>' . gallery_parent_options($gallery) . '</select></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options((string) $gallery['visibility']) . '</select></label>';
    echo '<label>Sort order<input name="sort_order" type="number" value="' . (int) $gallery['sort_order'] . '"></label>';
    echo '<label>Title picture<select name="cover_image_id"><option value="0">Automatic</option>' . gallery_cover_options((int) $gallery['id'], (int) ($gallery['cover_image_id'] ?? 0)) . '</select></label>';
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
        echo '<td><img class="admin-thumb" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" alt=""></td>';
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
        redirect_to(url_for('admin_edit_image', ['id' => $image['id'], 'saved' => 1]));
    }
    render_header('Edit image');
    echo '<section class="panel"><h1>Edit image</h1><p><img loading="lazy" src="' . e(thumbnail_url($image, 800)) . '" alt=""></p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $image['id'] . '">';
    echo '<label>Title<input name="title" value="' . e($image['title']) . '"></label>';
    echo '<label>Description<textarea name="description">' . e($image['description']) . '</textarea></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options((string) $image['visibility']) . '</select></label>';
    echo '<label>Sort order<input name="sort_order" type="number" value="' . (int) $image['sort_order'] . '"></label>';
    echo '<label>Tags<input name="tags" value="' . e(tag_names_for_entity('image', (int) $image['id'])) . '" list="tag-suggestions" data-tag-input><span class="muted">Separate tags with commas.</span></label>';
    render_tag_datalist();
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
 * Validate a six-digit hex color from theme settings.
 */
function sanitize_hex_color(string $value, string $fallback): string
{
    // Variable $value stores this steps working value.
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
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
 * Build cover-image options for one gallery.
 */
function gallery_cover_options(int $galleryId, int $selectedImageId): string
{
    // Variable $images stores this steps working value.
    $images = gallery_images($galleryId, false);
    // Variable $html stores this steps working value.
    $html = '';
    foreach ($images as $image) {
        // Variable $selected stores this steps working value.
        $selected = $selectedImageId === (int) $image['id'] ? ' selected' : '';
        // Variable $label stores this steps working value.
        $label = ($image['title'] ?: $image['filename']) . ' (' . $image['relative_path'] . ')';
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
function cms_theme_css(): void
{
    $theme = theme_settings();
    $fontFamily = $theme['font'] === 'sans' ? 'Arial, Helvetica, sans-serif' : 'Georgia, Times New Roman, serif';
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    echo ':root{';
    echo '--accent:' . css_value((string) $theme['accent']) . ';';
    echo '--accent-dark:' . css_value((string) $theme['accent_dark']) . ';';
    echo '--paper:' . css_value((string) $theme['paper']) . ';';
    echo '--panel:' . css_value((string) $theme['panel']) . ';';
    echo '--radius:' . (int) $theme['radius'] . 'px;';
    echo '--font-family:' . css_value($fontFamily) . ';';
    echo '}';
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
