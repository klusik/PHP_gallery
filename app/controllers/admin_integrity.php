<?php

declare(strict_types=1);

/**
 * Admin integrity controller model.
 * 
 * This module renders integrity summaries and path lists, and handles the admin integrity screen. It is separated from visual theme customization.
 */

function render_admin_integrity_summary(array $integrityStatus): void
{
    // $status stores an intermediate value used by the surrounding gallery workflow.
    $status = (string) ($integrityStatus['status'] ?? 'error');
    // $label stores an intermediate value used by the surrounding gallery workflow.
    $label = integrity_status_label($status);
    // $modifiedCount stores an intermediate value used by the surrounding gallery workflow.
    $modifiedCount = count((array) ($integrityStatus['modified'] ?? []));
    // $missingCount stores an intermediate value used by the surrounding gallery workflow.
    $missingCount = count((array) ($integrityStatus['missing'] ?? []));
    // $unknownCount stores an intermediate value used by the surrounding gallery workflow.
    $unknownCount = count((array) ($integrityStatus['unknown'] ?? []));
    // $checkedAt stores an intermediate value used by the surrounding gallery workflow.
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
 * Handles render admin integrity path list logic for the gallery application.
 * @param mixed $title Input used by this operation.
 * @param mixed $paths Input used by this operation.
 * @return mixed Result produced by this operation.
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
 * Handles cms admin integrity logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_integrity(): void
{
    require_admin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        // $status stores an intermediate value used by the surrounding gallery workflow.
        $status = integrity_status(true);
        admin_log_event('info', 'integrity.checked', 'Admin ran the system integrity check.', [
            'status' => (string) ($status['status'] ?? 'unknown'),
            'modified' => count((array) ($status['modified'] ?? [])),
            'missing' => count((array) ($status['missing'] ?? [])),
            'unknown' => count((array) ($status['unknown'] ?? [])),
        ]);
        redirect_to(url_for('admin_integrity', ['checked' => 1]));
    }

    // $status stores an intermediate value used by the surrounding gallery workflow.
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

