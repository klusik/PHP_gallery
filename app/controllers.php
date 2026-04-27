<?php

declare(strict_types=1);

function cms_home(): void
{
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

function cms_gallery(): void
{
    $gallery = find_gallery_by_slug((string) ($_GET['slug'] ?? ''));
    if (!$gallery || ($gallery['visibility'] !== 'public' && !current_user())) {
        cms_not_found();
        return;
    }

    $stmt = db()->prepare("SELECT i.*, COALESCE(SUM(v.vote), 0) AS score
        FROM images i
        LEFT JOIN image_votes v ON v.image_id = i.id
        WHERE i.gallery_id = ? AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        GROUP BY i.id
        ORDER BY i.sort_order, i.filename");
    $stmt->execute([(int) $gallery['id']]);
    $images = $stmt->fetchAll();
    $children = child_galleries((int) $gallery['id'], true);

    render_header((string) $gallery['title']);
    render_breadcrumbs($gallery);
    echo '<section class="hero"><h1>' . e($gallery['title']) . '</h1><p>' . e($gallery['description']) . '</p>';
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
        $mediaUrl = url_for('media', ['id' => $image['id']]);
        echo '<article class="image-card" data-lightbox-image data-image-id="' . (int) $image['id'] . '" data-full-src="' . e($mediaUrl) . '" data-title="' . e($image['title'] ?: $image['filename']) . '" data-description="' . e($image['description']) . '" data-score="' . (int) $image['score'] . '">';
        echo '<a href="' . e($mediaUrl) . '"><img loading="lazy" src="' . e($mediaUrl) . '" alt="' . e($image['title'] ?: $image['filename']) . '"></a>';
        echo '<div class="image-meta"><h2>' . e($image['title'] ?: $image['filename']) . '</h2><p>' . e($image['description']) . '</p>';
        render_vote_form((int) $image['id'], (int) $image['score']);
        echo '</div></article>';
    }
    echo '</section>';
    render_lightbox();
    render_footer();
}

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

function render_gallery_card(array $gallery, bool $publicOnly): void
{
    $cover = gallery_cover_image((int) $gallery['id'], $publicOnly);
    echo '<article class="gallery-card"><a href="' . e(url_for('gallery', ['slug' => $gallery['slug']])) . '">';
    if ($cover) {
        echo '<img loading="lazy" src="' . e(url_for('media', ['id' => $cover['id']])) . '" alt="">';
    }
    echo '<span class="gallery-card-body"><h2>' . e($gallery['title']) . '</h2>';
    echo '<p>' . e($gallery['description']) . '</p>';
    echo '<p class="muted">' . (int) $gallery['image_count'] . ' images</p></span>';
    echo '</a></article>';
}

function render_vote_form(int $imageId, int $score): void
{
    echo '<form class="vote-row" method="post" action="' . e(url_for('vote')) . '" data-vote-form>';
    echo '<input type="hidden" name="image_id" value="' . $imageId . '">';
    echo '<span>Score: <strong data-score-for="' . $imageId . '">' . $score . '</strong></span>';
    echo '<button type="submit" name="vote" value="1" aria-label="Vote up">Up</button>';
    echo '<button type="submit" name="vote" value="-1" aria-label="Vote down">Down</button>';
    echo '</form>';
}

function render_lightbox(): void
{
    echo '<div class="lightbox" data-lightbox hidden>';
    echo '<button class="lightbox-close" type="button" data-lightbox-action="close">Close</button>';
    echo '<button type="button" data-lightbox-action="previous">Previous</button>';
    echo '<figure><img data-lightbox-img alt=""><figcaption><h2 data-lightbox-title></h2><p data-lightbox-description></p><p>Score: <strong data-lightbox-score>0</strong></p></figcaption></figure>';
    echo '<button type="button" data-lightbox-action="next">Next</button>';
    echo '</div>';
}

function cms_media(): void
{
    $image = find_image((int) ($_GET['id'] ?? 0));
    if (!$image) {
        cms_not_found();
        return;
    }
    $gallery = find_gallery((int) $image['gallery_id']);
    if (!$gallery || (($gallery['visibility'] !== 'public' || $image['visibility'] !== 'public') && !current_user())) {
        cms_not_found();
        return;
    }
    $path = image_abs_path($image, $gallery);
    if (!is_file($path)) {
        cms_not_found();
        return;
    }
    $mime = (string) ($image['mime_type'] ?: mime_content_type($path));
    if (!str_starts_with($mime, 'image/')) {
        cms_not_found();
        return;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=604800');
    readfile($path);
}

function cms_vote(): void
{
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    $imageId = (int) ($_POST['image_id'] ?? 0);
    $vote = (int) ($_POST['vote'] ?? 0);
    if (!in_array($vote, [-1, 1], true) || !find_image($imageId)) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid vote.']);
        return;
    }
    $user = current_user();
    if ($user) {
        $stmt = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
        $stmt->execute([$imageId, (int) $user['id'], $vote, now_sql(), now_sql()]);
    } else {
        $hash = visitor_hash();
        $stmt = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
        $stmt->execute([$imageId, $hash, $vote, now_sql(), now_sql()]);
    }
    $result = ['image_id' => $imageId, 'score' => vote_score($imageId)];
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode($result);
        return;
    }
    redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
}

function cms_download_gallery(): void
{
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    if (!$gallery || $gallery['visibility'] !== 'public') {
        cms_not_found();
        return;
    }
    $zip = build_gallery_zip((int) $gallery['id'], true);
    send_download($zip, slugify((string) $gallery['title']) . '.zip');
}

function cms_download_all(): void
{
    require_admin();
    $zip = build_all_zip();
    send_download($zip, 'all-galleries.zip');
}

function cms_admin_login(): void
{
    if (request_method() === 'POST') {
        verify_csrf();
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([(string) ($_POST['username'] ?? '')]);
        $user = $stmt->fetch();
        if ($user && password_verify((string) ($_POST['password'] ?? ''), (string) $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            redirect_to(url_for('admin'));
        }
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

function cms_admin_logout(): void
{
    unset($_SESSION['user_id']);
    redirect_to(url_for('home'));
}

function cms_admin(): void
{
    require_admin();
    sync_gallery_parent_ids();
    $galleries = db()->query("SELECT g.*, parent.title AS parent_title, COUNT(i.id) AS image_count FROM galleries g LEFT JOIN galleries parent ON parent.id = g.parent_id LEFT JOIN images i ON i.gallery_id = g.id AND i.relative_path NOT LIKE '%/%' GROUP BY g.id, parent.title ORDER BY g.folder_path")->fetchAll();
    render_header('Admin dashboard');
    echo '<section class="hero"><h1>Admin dashboard</h1><nav class="nav">';
    echo '<a class="button" href="' . e(url_for('admin_discover')) . '">Check for new gallery folders</a>';
    echo '<a class="button secondary" href="' . e(url_for('download_all')) . '">Download all galleries</a>';
    echo '</nav></section>';
    echo '<section class="panel"><h2>Galleries</h2><form method="post" action="' . e(url_for('admin_bulk_galleries')) . '">' . csrf_field();
    echo '<div class="bulk-row"><label>Bulk action<select name="action"><option value="scan">Scan/import images</option><option value="public">Set public</option><option value="draft">Set draft</option><option value="private">Set private</option></select></label><button type="submit">Apply to selected</button></div>';
    echo '<table><thead><tr><th>Select</th><th>Title</th><th>Parent</th><th>Folder</th><th>Status</th><th>Images</th><th>Actions</th></tr></thead><tbody>';
    foreach ($galleries as $gallery) {
        $depth = substr_count((string) $gallery['folder_path'], '/');
        echo '<tr class="' . ($depth > 0 ? 'is-subgallery' : '') . '"><td><input type="checkbox" name="gallery_ids[]" value="' . (int) $gallery['id'] . '"></td>';
        echo '<td><span class="tree-title" style="--depth: ' . $depth . '">' . ($depth > 0 ? '<span class="tree-branch" aria-hidden="true"></span>' : '') . e($gallery['title']) . '</span></td>';
        echo '<td>' . e($gallery['parent_title'] ?: '') . '</td><td>' . e($gallery['folder_path']) . '</td><td>' . e($gallery['visibility']) . '</td><td>' . (int) $gallery['image_count'] . '</td><td class="nav">';
        echo '<a href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '">Edit</a>';
        echo '</td></tr>';
    }
    echo '</tbody></table></form></section>';
    render_footer();
}

function cms_admin_discover(): void
{
    require_admin();
    $candidates = discover_gallery_candidates();
    render_header('New gallery folders');
    echo '<section class="panel"><h1>New gallery folders</h1>';
    if (!$candidates) {
        echo '<p>No new gallery folders found.</p>';
    } else {
        echo '<form method="post" action="' . e(url_for('admin_import')) . '">' . csrf_field();
        echo '<table><thead><tr><th>Import</th><th>Folder</th><th>Title</th><th>Visibility</th></tr></thead><tbody>';
        foreach ($candidates as $candidate) {
            echo '<tr><td><input type="checkbox" name="folders[]" value="' . e($candidate['folder_path']) . '"></td><td>' . e($candidate['folder_path']) . '</td><td>' . e($candidate['title']) . '</td><td>' . e($candidate['visibility']) . '</td></tr>';
        }
        echo '</tbody></table><button type="submit">Import selected detected galleries</button></form>';
    }
    echo '</section>';
    render_footer();
}

function cms_admin_import(): void
{
    require_admin();
    verify_csrf();
    $count = import_galleries($_POST['folders'] ?? []);
    redirect_to(url_for('admin', ['imported' => $count]));
}

function cms_admin_bulk_galleries(): void
{
    require_admin();
    verify_csrf();
    $galleryIds = array_map('intval', $_POST['gallery_ids'] ?? []);
    $action = (string) ($_POST['action'] ?? 'scan');
    $count = 0;
    if ($action === 'scan') {
        foreach ($galleryIds as $galleryId) {
            $count += scan_gallery_images($galleryId);
        }
        redirect_to(url_for('admin', ['scanned' => $count]));
    }
    if (in_array($action, ['draft', 'public', 'private'], true) && $galleryIds) {
        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        $stmt = db()->prepare('UPDATE galleries SET visibility = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$action, now_sql()], $galleryIds));
        foreach ($galleryIds as $galleryId) {
            $gallery = find_gallery($galleryId);
            if ($gallery) {
                write_gallery_sidecar($gallery);
            }
        }
        redirect_to(url_for('admin', ['updated' => count($galleryIds)]));
    }
    redirect_to(url_for('admin'));
}

function cms_admin_scan_images(): void
{
    require_admin();
    verify_csrf();
    $galleryIds = $_POST['gallery_ids'] ?? [];
    if (!$galleryIds && isset($_POST['gallery_id'])) {
        $galleryIds = [$_POST['gallery_id']];
    }
    $count = 0;
    foreach ($galleryIds as $galleryId) {
        $count += scan_gallery_images((int) $galleryId);
    }
    redirect_to(url_for('admin', ['scanned' => $count]));
}

function cms_admin_edit_gallery(): void
{
    require_admin();
    $gallery = find_gallery((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
    if (!$gallery) {
        cms_not_found();
        return;
    }
    if (request_method() === 'POST') {
        verify_csrf();
        $title = trim((string) $_POST['title']);
        $slug = trim((string) $_POST['slug']);
        $visibility = in_array($_POST['visibility'] ?? '', ['draft', 'public', 'private'], true) ? (string) $_POST['visibility'] : 'draft';
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $parentId = $parentId > 0 && find_gallery($parentId) ? $parentId : null;
        $coverImageId = (int) ($_POST['cover_image_id'] ?? 0);
        $coverImage = $coverImageId > 0 ? find_image($coverImageId) : null;
        $coverImageId = $coverImage && (int) $coverImage['gallery_id'] === (int) $gallery['id'] ? $coverImageId : null;
        $slug = $slug !== '' ? slugify($slug) : unique_slug(db(), $title, (int) $gallery['id']);
        $stmt = db()->prepare('UPDATE galleries SET parent_id = ?, cover_image_id = ?, title = ?, description = ?, slug = ?, visibility = ?, sort_order = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$parentId, $coverImageId, $title, (string) $_POST['description'], unique_slug_for_value($slug, (int) $gallery['id']), $visibility, (int) $_POST['sort_order'], now_sql(), (int) $gallery['id']]);
        $gallery = find_gallery((int) $gallery['id']);
        if ($gallery) {
            write_gallery_sidecar($gallery);
        }
        redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id'], 'saved' => 1]));
    }
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
    echo '<button type="submit">Save gallery</button></form></section>';
    echo '<section class="panel"><h2>Scan</h2><form method="post" action="' . e(url_for('admin_scan_images')) . '" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<button type="submit">Scan/import images in this gallery</button></form></section>';
    echo '<section class="panel"><h2>Images</h2><form method="post" action="' . e(url_for('admin_bulk_images')) . '">' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<div class="bulk-row"><label>Bulk action<select name="action"><option value="public">Set public</option><option value="draft">Set draft</option><option value="private">Set private</option><option value="cover">Set as title picture</option></select></label><button type="submit">Apply to selected</button></div>';
    echo '<table><thead><tr><th>Select</th><th>Preview</th><th>Image</th><th>Status</th><th>Cover</th><th>Actions</th></tr></thead><tbody>';
    foreach ($images as $image) {
        $isCover = (int) ($gallery['cover_image_id'] ?? 0) === (int) $image['id'];
        echo '<tr><td><input type="checkbox" name="image_ids[]" value="' . (int) $image['id'] . '"></td>';
        echo '<td><img class="admin-thumb" loading="lazy" src="' . e(url_for('media', ['id' => $image['id']])) . '" alt=""></td>';
        echo '<td>' . e($image['relative_path']) . '</td><td>' . e($image['visibility']) . '</td><td>' . ($isCover ? 'Title picture' : '') . '</td><td><a href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '">Edit</a></td></tr>';
    }
    echo '</tbody></table></form></section>';
    render_footer();
}

function cms_admin_bulk_images(): void
{
    require_admin();
    verify_csrf();
    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        cms_not_found();
        return;
    }
    $imageIds = array_map('intval', $_POST['image_ids'] ?? []);
    $action = (string) ($_POST['action'] ?? '');
    if (!$imageIds) {
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
    }
    $ownedIds = [];
    foreach ($imageIds as $imageId) {
        $image = find_image($imageId);
        if ($image && (int) $image['gallery_id'] === $galleryId) {
            $ownedIds[] = $imageId;
        }
    }
    if (!$ownedIds) {
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
    }
    if ($action === 'cover') {
        $stmt = db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$ownedIds[0], now_sql(), $galleryId]);
        $updated = find_gallery($galleryId);
        if ($updated) {
            write_gallery_sidecar($updated);
        }
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId, 'saved' => 1]));
    }
    if (in_array($action, ['draft', 'public', 'private'], true)) {
        $placeholders = implode(',', array_fill(0, count($ownedIds), '?'));
        $stmt = db()->prepare('UPDATE images SET visibility = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$action, now_sql()], $ownedIds));
    }
    redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId, 'updated' => count($ownedIds)]));
}

function cms_admin_edit_image(): void
{
    require_admin();
    $image = find_image((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
    if (!$image) {
        cms_not_found();
        return;
    }
    if (request_method() === 'POST') {
        verify_csrf();
        $visibility = in_array($_POST['visibility'] ?? '', ['draft', 'public', 'private'], true) ? (string) $_POST['visibility'] : 'public';
        $stmt = db()->prepare('UPDATE images SET title = ?, description = ?, visibility = ?, sort_order = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([(string) $_POST['title'], (string) $_POST['description'], $visibility, (int) $_POST['sort_order'], now_sql(), (int) $image['id']]);
        redirect_to(url_for('admin_edit_image', ['id' => $image['id'], 'saved' => 1]));
    }
    render_header('Edit image');
    echo '<section class="panel"><h1>Edit image</h1><p><img loading="lazy" src="' . e(url_for('media', ['id' => $image['id']])) . '" alt=""></p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $image['id'] . '">';
    echo '<label>Title<input name="title" value="' . e($image['title']) . '"></label>';
    echo '<label>Description<textarea name="description">' . e($image['description']) . '</textarea></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options((string) $image['visibility']) . '</select></label>';
    echo '<label>Sort order<input name="sort_order" type="number" value="' . (int) $image['sort_order'] . '"></label>';
    echo '<button type="submit">Save image</button></form></section>';
    render_footer();
}

function gallery_images(int $galleryId, bool $publicOnly): array
{
    $sql = "SELECT * FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= ' ORDER BY sort_order, filename';
    $stmt = db()->prepare($sql);
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll();
}

function visibility_options(string $selected): string
{
    $html = '';
    foreach (['draft', 'public', 'private'] as $visibility) {
        $html .= '<option value="' . e($visibility) . '"' . ($visibility === $selected ? ' selected' : '') . '>' . e($visibility) . '</option>';
    }
    return $html;
}

function gallery_parent_options(array $currentGallery): string
{
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    $html = '';
    $currentPath = rtrim((string) $currentGallery['folder_path'], '/');
    foreach ($galleries as $gallery) {
        if ((int) $gallery['id'] === (int) $currentGallery['id']) {
            continue;
        }
        $path = (string) $gallery['folder_path'];
        if ($path !== '' && str_starts_with($path . '/', $currentPath . '/')) {
            continue;
        }
        $selected = (int) ($currentGallery['parent_id'] ?? 0) === (int) $gallery['id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $gallery['id'] . '"' . $selected . '>' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

function gallery_cover_options(int $galleryId, int $selectedImageId): string
{
    $images = gallery_images($galleryId, false);
    $html = '';
    foreach ($images as $image) {
        $selected = $selectedImageId === (int) $image['id'] ? ' selected' : '';
        $label = ($image['title'] ?: $image['filename']) . ' (' . $image['relative_path'] . ')';
        $html .= '<option value="' . (int) $image['id'] . '"' . $selected . '>' . e($label) . '</option>';
    }
    return $html;
}

function unique_slug_for_value(string $slug, int $excludeGalleryId): string
{
    $pdo = db();
    $base = slugify($slug);
    $candidate = $base;
    $counter = 2;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM galleries WHERE slug = ? AND id <> ?');
        $stmt->execute([$candidate, $excludeGalleryId]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $candidate = $base . '-' . $counter;
        $counter++;
    }
}

function cms_setup(): void
{
    $key = (string) ($_GET['key'] ?? '');
    if ($key === '' || !hash_equals((string) cms_config()['setup_key'], $key)) {
        cms_not_found();
        return;
    }
    $ran = run_migrations();
    if (request_method() === 'POST') {
        verify_csrf();
        $username = trim((string) $_POST['username']);
        $password = (string) $_POST['password'];
        if ($username !== '' && $password !== '') {
            $stmt = db()->prepare('INSERT INTO users (username, password_hash, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), updated_at = VALUES(updated_at)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin', now_sql(), now_sql()]);
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

function cms_not_found(): void
{
    http_response_code(404);
    render_header('Not found');
    echo '<section class="panel"><h1>Not found</h1><p>The requested page was not found.</p></section>';
    render_footer();
}
