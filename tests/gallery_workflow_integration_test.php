<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_workflow_integration_test.php
 * Module Type: Regression Test
 * Purpose: Exercise authenticated workflows against disposable application data.
 * Responsibilities:
 *   - Verify HTTP outcomes against database and original-file postconditions.
 *   - Exercise direct-photo Smart Gallery tag isolation and Smart music.
 *   - Exercise independent media masters and storage accounting.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Real authenticated HTTP journeys with database and original-file postconditions.
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_http.php';

use GalleryWorkflow\Http;
use function GalleryWorkflow\check;
use function GalleryWorkflow\countRows;
use function GalleryWorkflow\envelope;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\row;
use function GalleryWorkflow\validateFixture;

/**
 * Bound continuations of the small disposable storage-accounting journey.
 * Type: int. Units: HTTP steps. Scope: this owned workflow fixture only.
 * Consumers: the manual file-statistics job below.
 * Rationale: 32 steps cover the seeded galleries at the production batch size;
 *     failure to complete must fail instead of spinning indefinitely.
 */
const GALLERY_WORKFLOW_STORAGE_MAX_STEPS = 32;

/**
 * Produce a small valid one-page PDF with deterministic cross-reference offsets.
 *
 * @return string Original PDF bytes used to verify unmodified HTTP streaming.
 */
function galleryWorkflowOfpFixturePdf(): string
{
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $objects = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>\nendobj\n",
        "4 0 obj\n<< /Length 0 >>\nstream\n\nendstream\nendobj\n",
    ];
    $offsets = [];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 5\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return (
        $pdf
            . "trailer\n<< /Root 1 0 R /Size 5 >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF\n"
    );
}

/**
 * Read the real rendered OFP URLs from a gallery page in the same HTTP session.
 *
 * @param Http $visitor The cookie-isolated anonymous HTTP client.
 * @param string $folderPath Physical gallery path in the migrated database.
 * @param int $galleryId Expected PDF-owning gallery ID.
 * @return array{inline:string,download:string} Root-relative public document links.
 */
function galleryWorkflowOfpLinks(
    Http $visitor,
    string $folderPath,
    int $galleryId,
): array {
    $page = $visitor->request(
        '/index.php?page=gallery&gallery_path=' . rawurlencode($folderPath),
    );
    check(
        $page['status'] === 200,
        'A visitor cannot open the source gallery by its direct URL.',
    );
    $document = new DOMDocument();
    check(
        @$document->loadHTML($page['body']),
        'Real gallery HTML cannot be parsed.',
    );
    $xpath = new DOMXPath($document);
    $inline = $xpath->query('//a[@data-simbrief-ofp-open]')->item(0);
    $download = $xpath->query('//a[@download="simbrief-ofp.pdf"]')->item(0);
    check(
        $inline instanceof DOMElement && $download instanceof DOMElement,
        'Source gallery rendered without both OFP actions.',
    );
    $expected = '/index.php?page=gallery_ofp_pdf&id=' . $galleryId;
    check(
        $inline->getAttribute('href') === $expected
            && $download->getAttribute('href') === $expected . '&download=1',
        'The rendered OFP anchors do not identify the owner gallery or correct same-origin routes.',
    );
    return [
        'inline' => $inline->getAttribute('href'),
        'download' => $download->getAttribute('href'),
    ];
}

/**
 * Verify that a real runtime response streams the exact original PDF bytes.
 *
 * @param Http $visitor Cookie-isolated visitor context shared with the gallery view.
 * @param string $route Route rendered in the source gallery or its legacy alias.
 * @param string $expectedPdf Original persisted PDF file bytes.
 * @param bool $download Whether the response must use attachment disposition.
 * @return void Throws if the visitor cannot retrieve the original PDF.
 */
function galleryWorkflowOfpAssertPdf(
    Http $visitor,
    string $route,
    string $expectedPdf,
    bool $download = false,
): void {
    $response = $visitor->request($route);
    check(
        $response['status'] === 200
            && str_starts_with(
                (string) ($response['headers']['content-type'] ?? ''),
                'application/pdf',
            )
            && str_starts_with(
                (string) ($response['headers']['content-disposition'] ?? ''),
                $download ? 'attachment;' : 'inline;',
            )
            && hash_equals($expectedPdf, $response['body']),
        'Real runtime OFP HTTP response is missing, not a PDF, or differs from the original file.',
    );
}

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo
        ($required ? 'BLOCKED' : 'SKIP')
            . " gallery workflow real database and HTTP require disposable runner\n";
    exit($required ? 1 : 0);
}
$stage = 'fixture validation';
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture(
        (string) getenv('GALLERY_WORKFLOW_FIXTURE'),
        $token,
    );
    $pdo = fixtureDatabase($directory, $token);
    $seed = json_decode(
        (string) file_get_contents($directory . '/seed.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $origin = json_decode(
        (string) file_get_contents($directory . '/endpoint.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['url'];
    $admin = new Http($origin);
    $anonymous = new Http($origin);
    $stage = 'login and negative mutation controls';
    $csrf = $admin->login($seed);
    $baseline = countRows($pdo, 'galleries');
    $fields = [
        'csrf_token' => $csrf,
        'title' => 'HTTP workflow',
        'folder_name' => 'http-workflow',
        'parent_id' => $seed['root_id'],
        'visibility' => 'public',
        'panel' => '1',
        'operation_key' => $admin->operationKey(
            '/index.php?page=admin_new_gallery&panel=1',
        ),
    ];
    check(
        $anonymous->request(
            '/index.php?page=admin_new_gallery',
            $fields,
            true,
        )['status'] === 401,
        'Anonymous creation must fail.',
    );
    check(
        $admin->request(
            '/index.php?page=admin_new_gallery',
            array_replace($fields, ['csrf_token' => 'invalid']),
            true,
        )['status'] === 400,
        'Invalid CSRF creation must fail.',
    );
    check(
        countRows($pdo, 'galleries') === $baseline
            && !is_dir($directory . '/galleries/seed/http-workflow'),
        'Rejected create changed persistence.',
    );
    echo "PASS gallery workflow login auth and CSRF controls\n";

    $stage = 'HTTP create persistence';
    $created = envelope(
        $admin->request('/index.php?page=admin_new_gallery', $fields, true),
        'Create',
    );
    $id = (int) $created['gallery_id'];
    $gallery = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    check(
        $gallery['title'] === $fields['title']
            && (int) $gallery['parent_id'] === $seed['root_id']
            && $gallery['visibility'] === 'public'
            && countRows($pdo, 'galleries') === ($baseline + 1),
        'Created row mismatch.',
    );
    $folder = $directory . '/galleries/' . $gallery['folder_path'];
    check(is_file($folder . '/gallery.json'), 'Create sidecar missing.');
    echo "PASS gallery workflow HTTP create database and sidecar\n";

    $stage = 'classic multipart upload';
    $sample = $directory . '/galleries/seed/sample-1.jpg';
    $upload = [
        'csrf_token' => $csrf,
        'gallery_id' => (string) $id,
        'upload_mode' => 'existing',
        'images[]' => new CURLFile($sample, 'image/jpeg', 'uploaded.jpg'),
        'operation_key' => $admin->operationKey('/index.php?page=admin_upload&gallery_id='
            . $id),
    ];
    $beforeImages = countRows($pdo, 'images');
    check(
        $anonymous->request(
            '/index.php?page=admin_upload',
            $upload,
            true,
        )['status'] === 401,
        'Anonymous upload must fail.',
    );
    check(
        $admin->request(
            '/index.php?page=admin_upload',
            array_replace($upload, ['csrf_token' => 'invalid']),
            true,
        )['status'] === 400,
        'Invalid CSRF upload must fail.',
    );
    check(
        countRows($pdo, 'images') === $beforeImages,
        'Rejected upload changed image rows.',
    );
    envelope(
        $admin->request('/index.php?page=admin_upload', $upload, true),
        'Upload',
    );
    $image = row($pdo, 'SELECT * FROM images WHERE gallery_id = ?', [$id]);
    check(
        countRows($pdo, 'images') === ($beforeImages + 1)
            && (int) $image['width'] === 48
            && (int) $image['height'] === 32,
        'Upload persistence mismatch.',
    );
    $original = $folder . '/' . $image['relative_path'];
    check(
        is_file($original)
            && hash_file('sha256', $original) === hash_file('sha256', $sample),
        'Uploaded original hash mismatch.',
    );
    echo "PASS gallery workflow multipart upload metadata and original hash\n";

    $stage = 'real Smart Gallery direct photo-tag scope';
    // Three owned photos form exact same-photo vectors: both image tags, one
    // image tag, and only deliberately mismatched music/PDF assignments.
    $tagPhotoNames = [
        'scope-second-' . $token . '.jpg',
        'scope-third-' . $token . '.jpg',
    ];
    foreach ($tagPhotoNames as $tagPhotoName) {
        $tagUpload = [
            'csrf_token' => $csrf,
            'gallery_id' => (string) $id,
            'upload_mode' => 'existing',
            'images[]' => new CURLFile(
                $sample,
                'image/jpeg',
                $tagPhotoName,
            ),
            'operation_key' => $admin->operationKey(
                '/index.php?page=admin_upload&gallery_id=' . $id,
            ),
        ];
        envelope(
            $admin->request(
                '/index.php?page=admin_upload',
                $tagUpload,
                true,
            ),
            'Upload a photo-tag scope fixture',
        );
    }
    $tagPhotoTwo = row(
        $pdo,
        'SELECT id FROM images WHERE gallery_id = ? AND filename = ?',
        [$id, $tagPhotoNames[0]],
    );
    $tagPhotoThree = row(
        $pdo,
        'SELECT id FROM images WHERE gallery_id = ? AND filename = ?',
        [$id, $tagPhotoNames[1]],
    );
    $tagPhotoIds = [
        (int) $image['id'],
        (int) ($tagPhotoTwo['id'] ?? 0),
        (int) ($tagPhotoThree['id'] ?? 0),
    ];
    check(
        min($tagPhotoIds) > 0
            && count(array_unique($tagPhotoIds)) === count($tagPhotoIds),
        'The real scope fixture must resolve three distinct physical photos.',
    );

    $tagRows = [];
    foreach ([
        ['Scope alpha', 'image'],
        ['Scope beta', 'image'],
        ['Scope inherited', 'image'],
        ['Scope direct gallery', 'image'],
        ['Scope music mismatch', 'music'],
        ['Scope PDF mismatch', 'pdf'],
    ] as $tagIndex => [$tagName, $mediaType]) {
        $tagSlug = 'scope-' . $token . '-' . ($tagIndex + 1);
        $pdo->prepare(
            'INSERT INTO tags (name, slug, media_type, created_at, updated_at) '
                . 'VALUES (?, ?, ?, NOW(), NOW())',
        )->execute([$tagName, $tagSlug, $mediaType]);
        $tagRows[$tagName] = (int) $pdo->lastInsertId();
    }
    $assignImageTag = $pdo->prepare(
        'INSERT INTO image_tags (image_id, tag_id) VALUES (?, ?)',
    );
    foreach ([
        [$tagPhotoIds[0], $tagRows['Scope alpha']],
        [$tagPhotoIds[0], $tagRows['Scope beta']],
        [$tagPhotoIds[1], $tagRows['Scope alpha']],
        [$tagPhotoIds[2], $tagRows['Scope music mismatch']],
        [$tagPhotoIds[2], $tagRows['Scope PDF mismatch']],
    ] as [$tagImageId, $tagId]) {
        $assignImageTag->execute([$tagImageId, $tagId]);
    }
    $pdo->prepare(
        'INSERT INTO gallery_tags (gallery_id, tag_id) VALUES (?, ?)',
    )->execute([
        (int) $seed['root_id'],
        $tagRows['Scope inherited'],
    ]);
    $pdo->prepare(
        'INSERT INTO gallery_tags (gallery_id, tag_id) VALUES (?, ?)',
    )->execute([
        $id,
        $tagRows['Scope direct gallery'],
    ]);

    $photoTagCases = [
        'photo tag has one' => [
            'field' => 'photo_tag',
            'operator' => 'has_tag',
            'value' => $tagRows['Scope alpha'],
            'expected' => [$tagPhotoIds[0], $tagPhotoIds[1]],
        ],
        'photo tag not one' => [
            'field' => 'photo_tag',
            'operator' => 'not_has_tag',
            'value' => $tagRows['Scope alpha'],
            'expected' => [$tagPhotoIds[2]],
        ],
        'photo tag any' => [
            'field' => 'photo_tag',
            'operator' => 'has_any_tags',
            'value' => [
                $tagRows['Scope alpha'],
                $tagRows['Scope beta'],
            ],
            'expected' => [$tagPhotoIds[0], $tagPhotoIds[1]],
        ],
        'photo tag all on same photo' => [
            'field' => 'photo_tag',
            'operator' => 'has_all_tags',
            'value' => [
                $tagRows['Scope alpha'],
                $tagRows['Scope beta'],
            ],
            'expected' => [$tagPhotoIds[0]],
        ],
        'photo tag untagged ignores wrong categories' => [
            'field' => 'photo_tag',
            'operator' => 'untagged',
            'expected' => [$tagPhotoIds[2]],
        ],
        'photo tag ignores inherited gallery assignment' => [
            'field' => 'photo_tag',
            'operator' => 'has_tag',
            'value' => $tagRows['Scope inherited'],
            'expected' => [],
        ],
        'photo tag negative ignores inherited gallery assignment' => [
            'field' => 'photo_tag',
            'operator' => 'not_has_tag',
            'value' => $tagRows['Scope inherited'],
            'expected' => $tagPhotoIds,
        ],
        'photo tag ignores direct gallery assignment' => [
            'field' => 'photo_tag',
            'operator' => 'has_tag',
            'value' => $tagRows['Scope direct gallery'],
            'expected' => [],
        ],
        'photo tag negative ignores direct gallery assignment' => [
            'field' => 'photo_tag',
            'operator' => 'not_has_tag',
            'value' => $tagRows['Scope direct gallery'],
            'expected' => $tagPhotoIds,
        ],
        'nested photo tag logic' => [
            'node' => [
                'type' => 'group',
                'operator' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'field' => 'photo_tag',
                        'operator' => 'has_tag',
                        'value' => $tagRows['Scope alpha'],
                    ],
                    [
                        'type' => 'group',
                        'operator' => 'NOT',
                        'children' => [[
                            'type' => 'condition',
                            'field' => 'photo_tag',
                            'operator' => 'has_tag',
                            'value' => $tagRows['Scope beta'],
                        ]],
                    ],
                ],
            ],
            'expected' => [$tagPhotoIds[1]],
        ],
        'legacy tag keeps inherited gallery semantics' => [
            'field' => 'tag',
            'operator' => 'has_tag',
            'value' => $tagRows['Scope inherited'],
            'expected' => $tagPhotoIds,
        ],
        'legacy tag keeps direct gallery semantics' => [
            'field' => 'tag',
            'operator' => 'has_tag',
            'value' => $tagRows['Scope direct gallery'],
            'expected' => $tagPhotoIds,
        ],
    ];
    $scopeSmartId = 0;
    $scopeSmartRoute =
        '/index.php?page=smart_gallery&slug=photo-tag-scope-workflow';
    foreach ($photoTagCases as $caseName => $case) {
        $photoCondition = $case['node'] ?? [
            'type' => 'condition',
            'field' => $case['field'],
            'operator' => $case['operator'],
        ];
        if (array_key_exists('value', $case)) {
            $photoCondition['value'] = $case['value'];
        }
        $scopeRule = [
            'version' => 1,
            'root' => [
                'type' => 'group',
                'operator' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'field' => 'gallery',
                        'operator' => 'equals',
                        'value' => $id,
                    ],
                    [
                        'type' => 'group',
                        'operator' => 'OR',
                        'children' => array_map(
                            static fn(string $filename): array => [
                                'type' => 'condition',
                                'field' => 'filename',
                                'operator' => 'equals',
                                'value' => $filename,
                            ],
                            [
                                (string) $image['filename'],
                                ...$tagPhotoNames,
                            ],
                        ),
                    ],
                    $photoCondition,
                ],
            ],
        ];
        $scopeSave = [
            'csrf_token' => $csrf,
            'action' => 'save',
            'title' => 'Photo tag scope workflow',
            'slug' => 'photo-tag-scope-workflow',
            'enabled' => '1',
            'visibility' => 'public',
            'placement_mode' => 'unlisted',
            'sort_mode' => 'capture_date',
            'sort_direction' => 'desc',
            'presentation_override_enabled' => '1',
            'presentation_lightbox_enabled' => '1',
            'rules_json' => json_encode(
                $scopeRule,
                JSON_THROW_ON_ERROR,
            ),
        ];
        if ($scopeSmartId > 0) {
            $scopeSave['id'] = $scopeSmartId;
        }
        $scopeSaveResult = envelope(
            $admin->request(
                '/index.php?page=admin_smart_galleries'
                    . ($scopeSmartId > 0
                        ? '&id=' . $scopeSmartId
                        : ''),
                $scopeSave,
                true,
            ),
            'Save ' . $caseName,
        );
        $scopeSmartId = (int) ($scopeSaveResult['smart_gallery_id']
            ?? $scopeSmartId);
        check($scopeSmartId > 0, 'Photo-tag Smart Gallery did not persist.');
        $scopeSmartRow = row(
            $pdo,
            'SELECT rule_version FROM smart_galleries WHERE id = ?',
            [$scopeSmartId],
        );
        check(
            (int) ($scopeSmartRow['rule_version'] ?? 0) === 1,
            'Photo-tag rules must remain in saved rule version 1.',
        );

        $scopePage = $anonymous->request($scopeSmartRoute);
        $scopeDocument = new DOMDocument();
        check(
            $scopePage['status'] === 200
                && @$scopeDocument->loadHTML($scopePage['body']),
            'Photo-tag Smart Gallery failed to render through the public query.',
        );
        $scopeXpath = new DOMXPath($scopeDocument);
        $scopePhotoNodes = $scopeXpath->query(
            '//*[@data-lightbox-image and @data-image-id]',
        );
        $actualPhotoIds = [];
        foreach ($scopePhotoNodes as $scopePhotoNode) {
            if (!$scopePhotoNode instanceof DOMElement) {
                continue;
            }
            $actualPhotoIds[] = (int) $scopePhotoNode->getAttribute(
                'data-image-id',
            );
        }
        $actualPhotoIds = array_values(array_unique($actualPhotoIds));
        sort($actualPhotoIds, SORT_NUMERIC);
        $expectedPhotoIds = $case['expected'];
        sort($expectedPhotoIds, SORT_NUMERIC);
        $scopedPhotoNames = array_combine(
            $tagPhotoIds,
            [
                (string) $image['filename'],
                ...$tagPhotoNames,
            ],
        );
        // Keep fixture names useful while withholding the ownership token.
        $safeScopedPhotoNames = array_map(
            static fn(string $filename): string => str_replace(
                $token,
                '[fixture-token]',
                $filename,
            ),
            $scopedPhotoNames,
        );
        $actualPhotoLabels = array_map(
            static fn(int $photoId): string => (string) $photoId
                . ':'
                . ($safeScopedPhotoNames[$photoId] ?? 'outside-scope'),
            $actualPhotoIds,
        );
        $expectedPhotoLabels = array_map(
            static fn(int $photoId): string => (string) $photoId
                . ':'
                . ($safeScopedPhotoNames[$photoId] ?? 'outside-scope'),
            $expectedPhotoIds,
        );
        check(
            $actualPhotoIds === $expectedPhotoIds,
            'Photo-tag case returned incorrect real photo membership: '
                . $caseName
                . '; expected=' . json_encode($expectedPhotoLabels)
                . '; actual=' . json_encode($actualPhotoLabels)
                . '; scoped=' . json_encode($safeScopedPhotoNames),
        );
    }
    $savedPhotoRule = row(
        $pdo,
        'SELECT rules_json FROM smart_galleries WHERE id = ?',
        [$scopeSmartId],
    );
    $wrongCategoryRule = [
        'version' => 1,
        'root' => [
            'type' => 'condition',
            'field' => 'photo_tag',
            'operator' => 'has_any_tags',
            'value' => [
                $tagRows['Scope music mismatch'],
                $tagRows['Scope PDF mismatch'],
            ],
        ],
    ];
    $wrongCategoryResponse = $admin->request(
        '/index.php?page=admin_smart_galleries',
        array_replace($scopeSave, [
            'rules_json' => json_encode(
                $wrongCategoryRule,
                JSON_THROW_ON_ERROR,
            ),
        ]),
        true,
    );
    check(
        $wrongCategoryResponse['status'] === 422
            && is_array($wrongCategoryResponse['json'])
            && ($wrongCategoryResponse['json']['ok'] ?? true) === false
            && row(
                $pdo,
                'SELECT rules_json FROM smart_galleries WHERE id = ?',
                [$scopeSmartId],
            ) === $savedPhotoRule,
        'Non-image tag IDs must be rejected without replacing the saved rule.',
    );

    $stage = 'real quick-tag mutation refreshes saved Smart Gallery membership';
    $alphaRule = [
        'version' => 1,
        'root' => [
            'type' => 'group',
            'operator' => 'AND',
            'children' => [
                [
                    'type' => 'condition',
                    'field' => 'gallery',
                    'operator' => 'equals',
                    'value' => $id,
                ],
                [
                    'type' => 'condition',
                    'field' => 'photo_tag',
                    'operator' => 'has_tag',
                    'value' => $tagRows['Scope alpha'],
                ],
            ],
        ],
    ];
    envelope(
        $admin->request(
            '/index.php?page=admin_smart_galleries'
                . '&id=' . $scopeSmartId,
            array_replace($scopeSave, [
                'rules_json' => json_encode(
                    $alphaRule,
                    JSON_THROW_ON_ERROR,
                ),
            ]),
            true,
        ),
        'Save mutable photo-tag Smart Gallery rule',
    );
    $readPhotoTagMembership = static function () use (
        $anonymous,
        $scopeSmartRoute,
    ): array {
        $page = $anonymous->request($scopeSmartRoute);
        $document = new DOMDocument();
        check(
            $page['status'] === 200 && @$document->loadHTML($page['body']),
            'Mutable photo-tag Smart Gallery failed to render.',
        );
        $xpath = new DOMXPath($document);
        $lightbox = $xpath->query('//*[@data-lightbox-config]')->item(0);
        check(
            $lightbox instanceof DOMElement,
            'Mutable Smart Gallery lightbox count is missing.',
        );
        $nodes = $xpath->query(
            '//*[@data-lightbox-image and @data-image-id]',
        );
        $ids = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $ids[] = (int) $node->getAttribute('data-image-id');
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return [
            'ids' => $ids,
            'total' => (int) $lightbox->getAttribute('data-lightbox-total'),
        ];
    };
    $expectedAlphaIds = [$tagPhotoIds[0], $tagPhotoIds[1]];
    sort($expectedAlphaIds, SORT_NUMERIC);
    $beforeQuickRemove = $readPhotoTagMembership();
    check(
        $beforeQuickRemove['ids'] === $expectedAlphaIds
            && $beforeQuickRemove['total'] === 2,
        'Saved photo_tag rule must initially expose both direct alpha assignments.',
    );
    $quickTagFields = [
        'csrf_token' => $csrf,
        'image_id' => $tagPhotoIds[0],
        'source_gallery_id' => $id,
    ];
    envelope(
        $admin->request(
            '/index.php?page=admin_public_update_image',
            $quickTagFields + [
                'action' => 'quick_tag_remove',
                'tag_id' => $tagRows['Scope alpha'],
            ],
            true,
        ),
        'Remove direct tag through quick photo-tag API',
    );
    $afterQuickRemove = $readPhotoTagMembership();
    check(
        $afterQuickRemove['ids'] === [$tagPhotoIds[1]]
            && $afterQuickRemove['total'] === 1,
        'Quick tag removal must refresh saved rule membership and count.',
    );
    envelope(
        $admin->request(
            '/index.php?page=admin_public_update_image',
            $quickTagFields + [
                'action' => 'quick_tag_remove',
                'tag_id' => $tagRows['Scope alpha'],
            ],
            true,
        ),
        'Repeat absent quick photo-tag removal',
    );
    check(
        $readPhotoTagMembership() === $afterQuickRemove,
        'Repeating removal of an absent direct tag must remain idempotent.',
    );
    envelope(
        $admin->request(
            '/index.php?page=admin_public_update_image',
            $quickTagFields + [
                'action' => 'quick_tag_add',
                'tag_id' => $tagRows['Scope alpha'],
            ],
            true,
        ),
        'Restore direct tag through quick photo-tag API',
    );
    $afterQuickAdd = $readPhotoTagMembership();
    check(
        $afterQuickAdd['ids'] === $expectedAlphaIds
            && $afterQuickAdd['total'] === 2,
        'Quick tag addition must restore saved rule membership and count.',
    );
    envelope(
        $admin->request(
            '/index.php?page=admin_smart_galleries',
            [
                'csrf_token' => $csrf,
                'action' => 'delete',
                'id' => $scopeSmartId,
            ],
            true,
        ),
        'Remove photo-tag scope Smart Gallery',
    );
    echo
        "PASS gallery workflow direct photo-tag scope, typed isolation and legacy behavior\n";

    $stage = 'real Smart Gallery source music union';
    // PCM header/sample dimensions and IDs are exact audio-format vectors.
    $audioSamples = str_repeat("\x80", 8);
    $audioBytes =
        'RIFF'
        . pack('V', 36 + strlen($audioSamples))
        . 'WAVEfmt '
        . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8)
        . 'data'
        . pack('V', strlen($audioSamples))
        . $audioSamples;
    $otherAudio = substr($audioBytes, 0, -1) . "\x81";
    $musicSources = [
        $seed['root_id'] => [[$audioBytes, 'Shared.wav', false]],
        $id => [
            [$audioBytes, 'Renamed.wav', false],
            [$otherAudio, 'Shared.wav', false],
            [substr($audioBytes, 0, -1) . "\x82", 'Hidden.wav', true],
        ],
        $seed['protected_id'] => [
            [substr($audioBytes, 0, -1) . "\x83", 'Denied.wav', false],
        ],
    ];
    $musicFolders = [];
    $musicFiles = [];
    foreach ($musicSources as $sourceId => $originals) {
        $sourceRow = row(
            $pdo,
            'SELECT folder_path FROM galleries WHERE id = ?',
            [$sourceId],
        );
        $musicFolder =
            $directory
            . '/galleries/'
            . $sourceRow['folder_path']
            . '/.gallery-music';
        $musicFolderExists = file_exists($musicFolder)
            || is_link($musicFolder);
        check(
            !is_link($musicFolder)
                && (is_dir($musicFolder)
                    || (!$musicFolderExists && mkdir($musicFolder, 0700))),
            'Smart audio seed requires an owned, non-symlink sidecar directory.',
        );
        $musicLockPath = $musicFolder . '/.lock';
        check(
            !is_link($musicLockPath),
            'Smart audio seed refuses a symlink coordination lock.',
        );
        $musicLock = fopen($musicLockPath, 'c');
        check(
            is_resource($musicLock),
            'Smart audio seed could not open the persistent coordination lock.',
        );
        // Public Smart Gallery reads earlier in this fixture can start attachment
        // reconciliation, which owns this same persistent sidecar lock.
        $musicLockAcquired = flock($musicLock, LOCK_EX);
        if (!$musicLockAcquired) {
            fclose($musicLock);
        }
        check(
            $musicLockAcquired,
            'Smart audio seed could not acquire the coordination lock.',
        );
        try {
            $musicEntries = scandir($musicFolder);
            $existingMusicEntries = is_array($musicEntries)
                ? array_values(array_diff($musicEntries, ['.', '..']))
                : [];
            check(
                is_array($musicEntries)
                    && ($existingMusicEntries === []
                        || $existingMusicEntries === ['.lock'])
                    && is_file($musicLockPath)
                    && !is_link($musicLockPath),
                'Smart audio seed requires no prior media/index payload; '
                    . 'the persistent regular coordination lock may remain.',
            );
            $musicFolders[] = $musicFolder;
            $musicTracks = [];
            foreach ($originals as $position => [$bytes, $name, $hidden]) {
                $trackId = sprintf('%032x', $position + 1);
                $file = $musicFolder . '/' . $trackId . '.bin';
                check(
                    file_put_contents($file, $bytes) === strlen($bytes),
                    'Could not seed exact source audio.',
                );
                $musicFiles[] = $file;
                $musicTracks[] = [
                    'id' => $trackId,
                    'filename' => $name,
                    'title' => $name,
                    'mime' => 'audio/wav',
                    'size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'hidden' => $hidden,
                    'downloadable' => $hidden,
                ];
            }
            $indexFile = $musicFolder . '/index.json';
            file_put_contents($indexFile, json_encode([
                'format' => 'gallery_music_v1',
                'revision' => str_repeat('a', 32),
                'enabled' => false,
                'tracks' => $musicTracks,
            ], JSON_THROW_ON_ERROR));
            $musicFiles[] = $indexFile;
        } finally {
            flock($musicLock, LOCK_UN);
            fclose($musicLock);
        }
    }
    $conditions = [];
    foreach (array_keys($musicSources) as $sourceId) {
        $conditions[] = [
            'type' => 'condition',
            'field' => 'gallery',
            'operator' => 'equals',
            'value' => $sourceId,
        ];
    }
    $smartCreated = envelope(
        $admin->request(
            '/index.php?page=admin_smart_galleries',
            [
                'csrf_token' => $csrf,
                'action' => 'save',
                'title' => 'Smart music workflow',
                'slug' => 'smart-music-workflow',
                'enabled' => '1',
                'visibility' => 'public',
                'placement_mode' => 'unlisted',
                'sort_mode' => 'capture_date',
                'sort_direction' => 'desc',
                'rules_json' => json_encode([
                    'version' => 1,
                    'root' => [
                        'type' => 'group',
                        'operator' => 'OR',
                        'children' => $conditions,
                    ],
                ], JSON_THROW_ON_ERROR),
                // One photo per page proves music membership is not page-local.
                'presentation_override_enabled' => '1',
                'presentation_grid_columns' => '1',
                'presentation_grid_rows' => '1',
                'presentation_pagination_enabled' => '1',
                'presentation_lightbox_enabled' => '1',
                'presentation_slideshow_enabled' => '1',
                'presentation_music_enabled' => '1',
            ],
            true,
        ),
        'Create Smart music gallery',
    );
    $smartId = (int) $smartCreated['smart_gallery_id'];
    $savedSmart = row(
        $pdo,
        'SELECT presentation_json FROM smart_galleries WHERE id = ?',
        [$smartId],
    );
    check(
        json_decode($savedSmart['presentation_json'], true)['music_enabled']
            === true,
        'Smart music autoplay preference was not persisted.',
    );
    $smartRoute = '/index.php?page=smart_gallery&slug=smart-music-workflow';
    $sourceAudioUrl = '';
    foreach (['', '&photo_page=2', '&source_gallery_id=' . $id] as $view) {
        $smartPage = $anonymous->request($smartRoute . $view . '&lang=cs');
        check(
            $smartPage['status'] === 200,
            'Real runtime Smart music page did not render.',
        );
        $document = new DOMDocument();
        check(
            @$document->loadHTML($smartPage['body']),
            'Smart music page HTML cannot be parsed.',
        );
        $xpath = new DOMXPath($document);
        check(
            $xpath->query(
                '//section[contains(@class,"hero")]'
                    . '//div[@data-gallery-attachment-applets]',
            )->length === 1
                && $xpath->query('//*[@data-music-duration-total]')->length
                    === 1,
            'Smart hero must use the shared responsive music summary applet.',
        );
        $launchers = $xpath->query('//a[@data-gallery-presentation-start]');
        $share = $xpath->query('//*[@data-presentation-url]');
        check(
            $launchers->length === 1 && $share->length === 1,
            'Smart gallery is missing its presentation launcher/share applet.',
        );
        $launchUrl = $launchers->item(0)->getAttribute('href');
        parse_str((string) parse_url($launchUrl, PHP_URL_QUERY), $launchQuery);
        check(
            $launchQuery['lang'] === 'cs'
                && $launchQuery['presentation'] === '1'
                && $launchQuery['autoplay'] === '1'
                && $launchQuery['presentation_music'] === '1'
                && $launchQuery['presentation_fullscreen'] === '1'
                && $launchQuery['presentation_interval'] === 'medium'
                && $launchQuery['presentation_transition'] === 'medium'
                && !isset($launchQuery['photo_page'])
                && (int) ($launchQuery['source_gallery_id'] ?? 0)
                    === (str_contains($view, 'source_gallery_id') ? $id : 0)
                && $share->item(0)->getAttribute('data-presentation-url')
                    === $launchUrl,
            'Smart share must snapshot playback, locale and selected source only.',
        );
        check(
            $anonymous->request($launchUrl)['status'] === 200,
            'Fresh anonymous Smart presentation link was rejected.',
        );
        if ($view === '') {
            foreach ([
                '/smart/smart-music-workflow?unexpected=1',
                '/smart/smart-music-workflow?presentation=1&presentation=1',
                '/smart/smart-music-workflow?presentation_music[]=1',
                '/smart/smart-music-workflow?source_gallery_id=-1',
                '//example.invalid/smart/smart-music-workflow?presentation=1',
            ] as $unsafeRoute) {
                $refused = false;
                try {
                    $anonymous->request($unsafeRoute);
                } catch (RuntimeException $error) {
                    $refused = str_contains(
                        $error->getMessage(),
                        'supported same-origin application route',
                    );
                }
                check(
                    $refused,
                    'Smart-link transport accepted an unsafe route.',
                );
            }
        }
        $scripts = $xpath->query('//script[@data-gallery-music-playlist]');
        check(
            $scripts->length === 1,
            'Smart page must own one merged playlist.',
        );
        $playlist = json_decode(
            $scripts->item(0)->textContent,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        check(
            $playlist['galleryId'] === -$smartId
                && $playlist['enabled']
                && count($playlist['tracks']) === 2,
            'Pagination/filtering lost source music, visibility or deduplication.',
        );
        $actualHashes = [];
        foreach ($playlist['tracks'] as $track) {
            $audio = $anonymous->request($track['url']);
            check(
                $audio['status'] === 200,
                'Merged source audio failed its real protected delivery route.',
            );
            $actualHashes[] = hash('sha256', $audio['body']);
            parse_str(
                (string) parse_url($track['url'], PHP_URL_QUERY),
                $params,
            );
            if ((int) $params['id'] === $id)
                $sourceAudioUrl = $track['url'];
        }
        sort($actualHashes);
        $expectedHashes = [
            hash('sha256', $audioBytes),
            hash('sha256', $otherAudio),
        ];
        sort($expectedHashes);
        check(
            $actualHashes === $expectedHashes,
            'Hidden/protected audio leaked or renamed bytes were not deduplicated.',
        );
    }
    check(
        $sourceAudioUrl !== '',
        'Missing independently addressable source audio.',
    );
    $pdo->prepare('UPDATE galleries SET visibility = ? WHERE id = ?')
        ->execute(['private', $id]);
    check(
        $anonymous->request($sourceAudioUrl)['status'] === 404,
        'Old merged audio URL bypassed a revoked source-gallery grant.',
    );
    $afterRevocation = $anonymous->request($smartRoute);
    check(
        $afterRevocation['status'] === 200
            && !str_contains($afterRevocation['body'], 'Renamed.wav')
            && !str_contains($afterRevocation['body'], 'Hidden.wav')
            && !str_contains($afterRevocation['body'], 'Denied.wav'),
        'Smart presentation retained inaccessible source metadata.',
    );
    $pdo->prepare('UPDATE galleries SET visibility = ? WHERE id = ?')
        ->execute(['public', $id]);

    $stage = 'Admin media capability switches and physical storage totals';
    $mediaPdfBytes = galleryWorkflowOfpFixturePdf();
    $mediaPdfSource = $directory . '/admin-attachment.pdf';
    file_put_contents($mediaPdfSource, $mediaPdfBytes);
    $mediaEditRoute = '/index.php?page=admin_edit_gallery&id=' . $id;
    $mediaPdfUpload = [
        'csrf_token' => $csrf,
        'id' => $id,
        'pdf_action' => 'upload',
        'pdf_title' => 'Stored attachment',
        'pdf_file' => new CURLFile(
            $mediaPdfSource,
            'application/pdf',
            'Stored.pdf',
        ),
    ];
    $mediaDocument = envelope($admin->request(
        $mediaEditRoute,
        $mediaPdfUpload,
        true,
    ), 'Upload independent media PDF');
    $mediaDocumentId = $mediaDocument['document_id'];
    $mediaPdfRoute =
        '/index.php?page=gallery_ofp_pdf&id='
        . $id
        . '&document='
        . $mediaDocumentId;
    $mediaOriginalPath =
        $folder
        . '/.gallery-pdf-documents/'
        . $mediaDocumentId
        . '.pdf';
    file_put_contents($folder . '/simbrief-ofp.pdf', $mediaPdfBytes);
    file_put_contents($folder . '/simbrief-ofp-manifest.json', json_encode([
        'format' => 'php_gallery_simbrief_ofp_manifest_v1',
        'ofp_pdf_file' => 'simbrief-ofp.pdf',
    ], JSON_THROW_ON_ERROR));
    $stage = 'Smart PDF applets, protected originals and presentation sharing';
    foreach (['', '&photo_page=2', '&source_gallery_id=' . $id] as $view) {
        $smartPdfPage = $anonymous->request($smartRoute . $view);
        $smartPdfDom = new DOMDocument();
        check(
            $smartPdfPage['status'] === 200
                && @$smartPdfDom->loadHTML($smartPdfPage['body']),
            'Smart PDF hero did not render through the production runtime.',
        );
        $smartPdfXpath = new DOMXPath($smartPdfDom);
        check(
            $smartPdfXpath->query(
                '//section[contains(@class,"hero")]//div[@data-gallery-attachment-applets]'
                    . '/section[@data-gallery-pdf-documents]',
            )->length === 1
                && $smartPdfXpath->query('//*[@data-pdf-page-total]')->length
                    === 1
                && str_contains($smartPdfPage['body'], 'Stored attachment')
                && str_contains($smartPdfPage['body'], 'SimBrief OFP'),
            'Pagination/filtering lost source PDF summaries or the OFP.',
        );
        foreach ($smartPdfXpath->query(
            '//section[@data-gallery-pdf-documents]//a[@data-simbrief-ofp-open]',
        ) as $pdfLink) {
            $pdfOriginal = $anonymous->request($pdfLink->getAttribute('href'));
            check(
                $pdfOriginal['status'] === 200
                    && str_starts_with($pdfOriginal['body'], '%PDF-'),
                'Smart PDF link did not retain protected physical delivery.',
            );
        }
        check(
            $smartPdfXpath->query(
                '//section[@data-gallery-pdf-documents]//form',
            )->length === 0,
            'Smart PDF aggregate exposed physical mutation controls.',
        );
    }
    foreach (['1', '0'] as $hidden) {
        envelope(
            $admin->request(
                $mediaEditRoute,
                [
                    'csrf_token' => $csrf,
                    'id' => $id,
                    'pdf_action' => 'visibility',
                    'document_id' => $mediaDocumentId,
                    'hidden' => $hidden,
                ],
                true,
            ),
            'Toggle source PDF visibility',
        );
        foreach ([$anonymous, $admin] as $audience) {
            $smartPdfPage = $audience->request($smartRoute);
            check(
                $smartPdfPage['status'] === 200
                    && str_contains($smartPdfPage['body'], 'Stored attachment')
                        === ($hidden === '0'),
                'Smart public discovery leaked hidden PDFs to an audience.',
            );
        }
        check(
            $anonymous->request($mediaPdfRoute)['status']
                === ($hidden === '1' ? 404 : 200),
            'Physical PDF delivery ignored visibility after Smart discovery.',
        );
    }
    $pdo->prepare('UPDATE galleries SET visibility = ? WHERE id = ?')
        ->execute(['private', $id]);
    check(
        $anonymous->request($mediaPdfRoute)['status'] === 404
            && !str_contains(
                $anonymous->request($smartRoute)['body'],
                'Stored attachment',
            ),
        'Smart PDF metadata or original survived source-access revocation.',
    );
    $pdo->prepare('UPDATE galleries SET visibility = ? WHERE id = ?')
        ->execute(['public', $id]);
    $mediaMusicSettings = envelope(
        $admin->request(
            $mediaEditRoute,
            [
                'csrf_token' => $csrf,
                'id' => $id,
                'music_action' => 'settings',
                'music_revision' => str_repeat('a', 32),
                'music_enabled' => '1',
            ],
            true,
        ),
        'Persist source music preference',
    );
    $musicFiles[] = $folder . '/.gallery-music/.lock';
    $mediaStoredHashes = [];
    foreach (array_merge($musicFiles, [
        $mediaOriginalPath,
        $folder . '/.gallery-pdf-documents/index.json',
        $folder . '/simbrief-ofp.pdf',
    ]) as $mediaPath) {
        $mediaStoredHashes[$mediaPath] = hash_file('sha256', $mediaPath);
    }

    $mediaFeatures = $admin->request('/index.php?page=admin_features');
    $mediaDocumentDom = new DOMDocument();
    check(
        $mediaFeatures['status'] === 200
            && @$mediaDocumentDom->loadHTML($mediaFeatures['body']),
        'Canonical Admin Features did not render.',
    );
    $mediaFeatureXpath = new DOMXPath($mediaDocumentDom);
    $mediaRegistryRevision = $mediaFeatureXpath
        ->query(
            '//input[@name="feature_registry_revision"]',
        )
        ->item(0)
        ->getAttribute('value');
    $mediaEnabledKeys = [];
    foreach ($mediaFeatureXpath->query(
        '//input[@name="enabled_features[]"][@checked]',
    ) as $mediaCheckbox) {
        $mediaEnabledKeys[] = $mediaCheckbox->getAttribute('value');
    }
    check(
        in_array('gallery_pdf_documents', $mediaEnabledKeys, true)
            && in_array('gallery_music', $mediaEnabledKeys, true),
        'New and upgraded installations must initially expose both media types.',
    );
    $mediaSetCapabilities = static function (
        bool $pdfEnabled,
        bool $musicEnabled,
    ) use ($admin, $csrf, $mediaRegistryRevision, $mediaEnabledKeys): void {
        $enabled = array_values(array_diff($mediaEnabledKeys, [
            'gallery_pdf_documents',
            'gallery_music',
        ]));
        if ($pdfEnabled)
            $enabled[] = 'gallery_pdf_documents';
        if ($musicEnabled)
            $enabled[] = 'gallery_music';
        $response = $admin->request('/index.php?page=admin_features', [
            'csrf_token' => $csrf,
            'feature_registry_revision' => $mediaRegistryRevision,
            'enabled_features' => $enabled,
        ]);
        check(
            $response['status'] === 302,
            'Media masters must save through the canonical Features form.',
        );
    };
    $mediaGalleryRoute =
        '/index.php?page=gallery&gallery_path='
        . rawurlencode($gallery['folder_path']);
    $stage = 'physical gallery presentation preferences';
    $presentationBefore = row(
        $pdo,
        'SELECT * FROM galleries WHERE id = ?',
        [$id],
    );
    $presentationEdit = [
        'csrf_token' => $csrf,
        'id' => $id,
        'title' => $presentationBefore['title'],
        'description' => $presentationBefore['description'],
        'parent_id' => $presentationBefore['parent_id'] ?? 0,
        'slug' => $presentationBefore['slug'],
        'visibility' => 'public',
        'folder_name' => basename($presentationBefore['folder_path']),
        'edit_revision' => (string) $presentationBefore['edit_revision'],
        'gallery_presentation_present' => '1',
        'gallery_presentation_enabled' => '1',
        'gallery_presentation_autoplay' => '1',
        'gallery_presentation_interval' => 'fast',
        'gallery_presentation_transition' => 'slow',
        'return_tab' => 'admin-edit-media',
    ];
    check(
        $anonymous->request($mediaEditRoute, $presentationEdit, true)['status']
            === 401,
        'Anonymous presentation settings write must be refused.',
    );
    envelope(
        $admin->request($mediaEditRoute, $presentationEdit, true),
        'Save presentation defaults',
    );
    $presentationSaved = row(
        $pdo,
        'SELECT presentation_json, edit_revision FROM galleries WHERE id = ?',
        [$id],
    );
    $presentationState = json_decode(
        $presentationSaved['presentation_json'],
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    check(
        $presentationState['music'] === false
            && $presentationState['autoplay'] === true
            && $presentationState['interval'] === 'fast'
            && $presentationState['transition'] === 'slow',
        'Presentation preferences did not persist in their gallery row.',
    );
    $presentationSidecar = json_decode(
        (string) file_get_contents(
            $folder . '/gallery.json',
        ),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    check(
        $presentationSidecar['presentation'] === $presentationState,
        'Gallery export lost presentation preferences.',
    );
    check(
        $admin->request($mediaEditRoute, $presentationEdit, true)['status']
            === 409,
        'Stale presentation changes must not overwrite a newer revision.',
    );
    $presentationMarkup = $anonymous->request($mediaGalleryRoute)['body'];
    check(
        str_contains($presentationMarkup, 'presentation_music=0')
            && str_contains($presentationMarkup, 'presentation_interval=fast')
            && str_contains($presentationMarkup, 'presentation_transition=slow')
            && str_contains($presentationMarkup, 'data-presentation-music="0"'),
        'Public launch/share URLs did not snapshot stored presentation defaults.',
    );
    $presentationEdit['edit_revision'] =
        (string) $presentationSaved['edit_revision'];
    $presentationEdit['gallery_presentation_enabled'] = '0';
    unset($presentationEdit['gallery_presentation_autoplay']);
    $presentationEdit['gallery_presentation_music'] = '1';
    envelope(
        $admin->request($mediaEditRoute, $presentationEdit, true),
        'Hide public presentation actions',
    );
    check(
        json_decode(
            row(
                $pdo,
                'SELECT presentation_json FROM galleries WHERE id = ?',
                [$id],
            )['presentation_json'],
            true,
        )['autoplay'] === false,
        'Explicit default autoplay-off did not persist.',
    );
    check(
        !str_contains(
            $anonymous->request($mediaGalleryRoute)['body'],
            'data-gallery-presentation-start',
        ),
        'Hidden presentation action remained.',
    );
    $presentationEdit['edit_revision'] = (string) row(
        $pdo,
        'SELECT edit_revision FROM galleries WHERE id = ?',
        [$id],
    )['edit_revision'];
    $presentationEdit['gallery_presentation_enabled'] = '1';
    $presentationEdit['gallery_presentation_autoplay'] = '1';
    envelope(
        $admin->request($mediaEditRoute, $presentationEdit, true),
        'Restore public presentation actions',
    );
    $presentationPage = $anonymous->request(
        $mediaGalleryRoute
            . '&lang=en&autoplay=1&presentation_music=0'
            . '&presentation_interval=fast&presentation_transition=slow',
    );
    check(
        $presentationPage['status'] === 200
            && str_contains(
                $presentationPage['body'],
                'data-gallery-presentation-start',
            ),
        'Fresh anonymous presentation link must reach the public gallery.',
    );
    foreach ([[false, true], [true, false], [false, false]] as [
        $pdfEnabled,
        $musicEnabled,
    ]) {
        $mediaSetCapabilities($pdfEnabled, $musicEnabled);
        $mediaPage = $anonymous->request($mediaGalleryRoute);
        check(
            $mediaPage['status'] === 200,
            'Disabling optional attachments broke the physical gallery.',
        );
        check(
            str_contains($mediaPage['body'], 'data-gallery-pdf-documents')
                === $pdfEnabled
                && str_contains(
                    $mediaPage['body'],
                    'data-gallery-music-playlist',
                )
                    === $musicEnabled,
            'Public PDF/music presentation ignored independent master state.',
        );
        if (!$pdfEnabled && !$musicEnabled) {
            check(
                str_contains(
                    $mediaPage['body'],
                    'data-gallery-presentation-start',
                )
                    && !str_contains($mediaPage['body'], 'data-pdf-page-total')
                    && !str_contains(
                        $mediaPage['body'],
                        'data-music-duration-total',
                    ),
                'Both masters OFF must retain photo presentation without'
                    . ' attachment summaries or players.',
            );
        }
        $mediaSmartPage = $anonymous->request($smartRoute);
        check(
            $mediaSmartPage['status'] === 200
                && str_contains(
                    $mediaSmartPage['body'],
                    'data-gallery-pdf-documents',
                ) === $pdfEnabled
                && str_contains(
                    $mediaSmartPage['body'],
                    'data-gallery-music-playlist',
                ) === $musicEnabled
                && str_contains(
                    $mediaSmartPage['body'],
                    'data-gallery-presentation-start',
                ),
            'Smart hero ignored independent media masters or lost presentation.',
        );
        $legacyMediaEditor = $admin->request($mediaEditRoute
            . '&tab=admin-edit-documents&panel=1&lang=en');
        $canonicalMediaLocation = (string) (
            $legacyMediaEditor['headers']['location'] ?? ''
        );
        check(
            $legacyMediaEditor['status'] === 302
                && str_contains($canonicalMediaLocation, 'tab=admin-edit-media')
                && str_contains($canonicalMediaLocation, 'media_section=pdf')
                && str_contains($canonicalMediaLocation, 'panel=1')
                && str_contains($canonicalMediaLocation, 'lang=en'),
            'The legacy Documents bookmark must preserve panel and language while redirecting to Media / PDF.',
        );
        $mediaEditor = $admin->request($canonicalMediaLocation);
        check(
            $mediaEditor['status'] === 200
                && str_contains(
                    $mediaEditor['body'],
                    'data-admin-tab-target="admin-edit-media"',
                )
                && str_contains(
                    $mediaEditor['body'],
                    'data-gallery-presentation-editor',
                ),
            'Core presentation settings must survive both attachment masters OFF.',
        );
        check(
            str_contains($mediaEditor['body'], 'data-gallery-pdf-documents')
                === $pdfEnabled
                && str_contains(
                    $mediaEditor['body'],
                    'data-gallery-music-playlist',
                )
                    === $musicEnabled,
            'Editor tables ignored independent media capability state.',
        );
        $mediaPicker = $admin->request(
            '/index.php?page=admin_upload&panel=1&gallery_id=' . $id,
        );
        $mediaPickerDom = new DOMDocument();
        check(
            $mediaPicker['status'] === 200
                && @$mediaPickerDom->loadHTML($mediaPicker['body']),
            'The mounted upload form failed after a capability change.',
        );
        $mediaPickerXpath = new DOMXPath($mediaPickerDom);
        $mediaAccept = $mediaPickerXpath
            ->query('//input[@name="images[]"]')
            ->item(0)
            ->getAttribute('accept');
        check(
            str_contains($mediaAccept, '.pdf') === $pdfEnabled
                && str_contains($mediaAccept, '.mp3') === $musicEnabled,
            'The ordinary file chooser advertises a disabled media format.',
        );
        check(
            $mediaPickerXpath->query(
                '//*[@data-gallery-upload-pdf-options]',
            )->length > 0
                === $pdfEnabled,
            'Disabled PDF conversion controls remained in the upload form.',
        );
        if (!$pdfEnabled) {
            foreach ([
                $mediaPdfRoute,
                '/index.php?page=gallery_ofp_pdf&id=' . $id,
                '/index.php?page=media&id=' . $id . '&ofp=1',
            ] as $mediaUrl) {
                $denied = $anonymous->request($mediaUrl);
                check(
                    $denied['status'] === 404
                        && str_starts_with(
                            $denied['headers']['content-type'] ?? '',
                            'text/plain',
                        )
                        && !str_contains(strtolower($denied['body']), '<html'),
                    'PDF OFF leaked bytes or a gallery HTML media response.',
                );
                check(
                    $anonymous->request(
                        $mediaUrl,
                        null,
                        false,
                        'HEAD',
                    )['status'] === 404,
                    'PDF HEAD escaped global OFF.',
                );
            }
            $denied = $admin->request($mediaEditRoute, $mediaPdfUpload, true);
            check(
                $denied['status'] === 403
                    && ($denied['json']['error'] ?? '') === 'feature_disabled',
                'A stale PDF upload form bypassed the server master.',
            );
            foreach (['begin', 'finalize'] as $mediaAction) {
                check(
                    $admin->request(
                        $mediaEditRoute,
                        [
                            'csrf_token' => $csrf,
                            'id' => $id,
                            'pdf_action' => $mediaAction,
                        ],
                        true,
                    )['status'] === 403,
                    'A stale PDF conversion action bypassed global OFF.',
                );
            }
            check(
                $admin->request(
                    $mediaEditRoute,
                    [
                        'csrf_token' => $csrf,
                        'id' => $id,
                        'simbrief_action' => 'upload_pdf',
                    ],
                    true,
                )['status'] === 403,
                'Historical SimBrief upload bypassed PDF OFF.',
            );
            check(
                $admin->request(
                    '/index.php?page=admin_upload_browser_batch',
                    [
                        'csrf_token' => $csrf,
                        'gallery_id' => $id,
                        'pdf_conversion_token' => str_repeat('a', 32),
                    ],
                    true,
                )['status'] === 403,
                'A prepared conversion batch bypassed PDF OFF.',
            );
        }
        if (!$musicEnabled) {
            $denied = $anonymous->request($sourceAudioUrl);
            check(
                $denied['status'] === 404
                    && str_starts_with(
                        $denied['headers']['content-type'] ?? '',
                        'text/plain',
                    ),
                'Music OFF leaked protected audio bytes.',
            );
            check(
                $anonymous->request(
                    $sourceAudioUrl,
                    null,
                    false,
                    'HEAD',
                )['status'] === 404,
                'Audio HEAD escaped global OFF.',
            );
            check(
                $admin->request(
                    $mediaEditRoute,
                    [
                        'csrf_token' => $csrf,
                        'id' => $id,
                        'music_action' => 'upload',
                        'music_revision' =>
                            $mediaMusicSettings['music_revision'],
                        'music_file' => new CURLFile(
                            $musicFiles[0],
                            'audio/wav',
                            'Must-not-upload.wav',
                        ),
                    ],
                    true,
                )['status'] === 403,
                'A stale music upload form bypassed the server master.',
            );
        }
    }
    foreach ($mediaStoredHashes as $mediaPath => $mediaDigest) {
        check(
            hash_file('sha256', $mediaPath) === $mediaDigest,
            'Capability OFF or a rejected operation changed retained bytes.',
        );
    }
    $storageRoute = '/index.php?page=admin_storage_statistics_update';
    $mediaStorage = $admin->request(
        $storageRoute,
        [
            'csrf_token' => $csrf,
            'action' => 'start',
        ],
        true,
    );
    for (
        $mediaStep = 0;
        $mediaStep < GALLERY_WORKFLOW_STORAGE_MAX_STEPS
        && ($mediaStorage['json']['status'] ?? '') === 'running';
        $mediaStep++
    ) {
        $mediaStorage = $admin->request(
            $storageRoute,
            [
                'csrf_token' => $csrf,
                'action' => 'step',
            ],
            true,
        );
    }
    check(
        $mediaStorage['status'] === 200
            && ($mediaStorage['json']['status'] ?? '') === 'complete',
        'The real manual accounting job did not complete.',
    );
    $mediaCache = row(
        $pdo,
        'SELECT setting_value FROM app_settings WHERE setting_key = ?',
        ['admin_storage_statistics_cache_v3'],
    );
    $mediaSnapshot = json_decode(
        $mediaCache['setting_value'],
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    check(
        $mediaSnapshot['pdf_count'] === 2
            && $mediaSnapshot['pdf_bytes'] === (2 * strlen($mediaPdfBytes))
            && $mediaSnapshot['music_count'] === 5
            && $mediaSnapshot['music_bytes'] === (5 * strlen($audioBytes))
            && $mediaSnapshot['total_media_bytes']
                === (
                    $mediaSnapshot['total_picture_bytes']
                    + $mediaSnapshot['pdf_bytes']
                    + $mediaSnapshot['music_bytes']
                ),
        'Physical totals lost disabled/hidden copies or counted Smart unions.',
    );
    $mediaSetCapabilities(true, true);
    check(
        $anonymous->request($mediaPdfRoute)['body'] === $mediaPdfBytes
            && $anonymous->request($sourceAudioUrl)['body'] === $otherAudio,
        'Re-enabling did not restore the same protected originals.',
    );
    check(
        str_contains(
            $anonymous->request($smartRoute)['body'],
            'data-gallery-music-playlist',
        ),
        'Re-enabling music did not restore the Smart playlist.',
    );
    foreach ([
        $mediaOriginalPath,
        $folder . '/.gallery-pdf-documents/index.json',
        $folder . '/.gallery-pdf-documents/.lock',
        $folder . '/simbrief-ofp.pdf',
        $folder . '/simbrief-ofp-manifest.json',
        $mediaPdfSource,
    ] as $mediaPath) {
        check(
            unlink($mediaPath),
            'Cannot retire the owned PDF capability seed.',
        );
    }
    check(
        rmdir($folder . '/.gallery-pdf-documents'),
        'Cannot retire the owned document sidecar.',
    );
    echo "PASS gallery workflow media masters and physical storage totals\n";

    envelope(
        $admin->request(
            '/index.php?page=admin_smart_galleries',
            [
                'csrf_token' => $csrf,
                'action' => 'delete',
                'id' => $smartId,
            ],
            true,
        ),
        'Remove owned Smart music gallery',
    );
    foreach ($musicFiles as $file)
        check(unlink($file), 'Cannot retire audio seed.');
    foreach ($musicFolders as $path) {
        // Automatic reconciliation can create the persistent source lock even
        // before these fixture tracks exist. Retire only fixture-owned audio;
        // unlinking that coordination inode would split concurrent writers.
        $entries = scandir($path);
        check(is_array($entries), 'Cannot inspect owned audio sidecar.');
        $remaining = array_values(array_diff($entries, ['.', '..']));
        check(
            $remaining === []
                || $remaining === ['.lock']
                && is_file($path . '/.lock')
                && !is_link($path . '/.lock'),
            'Unexpected payload remains in the owned audio sidecar.',
        );
        if ($remaining === []) {
            check(rmdir($path), 'Cannot retire empty audio sidecar.');
        }
    }
    echo
        "PASS gallery workflow real Smart music union and source authorization\n";

    $stage = 'real runtime OFP public HTTP and anonymous preflight';
    $ofpBytes = galleryWorkflowOfpFixturePdf();
    check(
        file_put_contents($folder . '/simbrief-ofp.pdf', $ofpBytes) === strlen(
            $ofpBytes,
        ),
        'Could not persist the original OFP bytes in the real fixture gallery.',
    );
    check(
        file_put_contents($folder . '/simbrief-ofp-manifest.json', json_encode([
            'format' => 'php_gallery_simbrief_ofp_manifest_v1',
            'ofp_pdf_file' => 'simbrief-ofp.pdf',
        ], JSON_THROW_ON_ERROR)) !== false,
        'Could not persist the real OFP attachment manifest.',
    );
    $ofpLinks = galleryWorkflowOfpLinks(
        $anonymous,
        (string) $gallery['folder_path'],
        $id,
    );
    galleryWorkflowOfpAssertPdf($anonymous, $ofpLinks['inline'], $ofpBytes);
    galleryWorkflowOfpAssertPdf(
        $anonymous,
        $ofpLinks['download'],
        $ofpBytes,
        true,
    );
    $ofpHead = $anonymous->request($ofpLinks['inline'], null, false, 'HEAD');
    check(
        $ofpHead['status'] === 200
            && $ofpHead['body'] === ''
            && str_starts_with(
                (string) ($ofpHead['headers']['content-type'] ?? ''),
                'application/pdf',
            )
            && (int) ($ofpHead['headers']['content-length'] ?? -1) === strlen(
                $ofpBytes,
            ),
        'Real runtime OFP HEAD must expose PDF headers but no content.',
    );
    galleryWorkflowOfpAssertPdf(
        $anonymous,
        '/index.php?page=media&id=' . $id . '&ofp=1',
        $ofpBytes,
    );
    galleryWorkflowOfpAssertPdf(
        $anonymous,
        '/index.php?page=media&id=' . $id . '&ofp=1&download=1',
        $ofpBytes,
        true,
    );
    $unexpectedOFP = $anonymous->request($ofpLinks['inline'] . '&unexpected=1');
    check(
        $unexpectedOFP['status'] === 404
            && $unexpectedOFP['body'] === "Not found.\n",
        'The real anonymous SEO guard must continue rejecting unknown query keys.',
    );
    echo
        "PASS gallery workflow original OFP public GET/HEAD/download/legacy and SEO guard\n";

    $stage = 'missing-folder catalog preservation';
    $childFields = array_replace($fields, [
        'title' => 'HTTP nested',
        'folder_name' => 'nested',
        'parent_id' => $id,
        'operation_key' => $admin->operationKey(
            '/index.php?page=admin_new_gallery&panel=1',
        ),
    ]);
    $childId = (int) envelope(
        $admin->request(
            '/index.php?page=admin_new_gallery',
            $childFields,
            true,
        ),
        'Child',
    )['gallery_id'];
    $catalogBefore = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    $childBefore = row(
        $pdo,
        'SELECT * FROM galleries WHERE id = ?',
        [$childId],
    );
    $imageBefore = row(
        $pdo,
        'SELECT * FROM images WHERE id = ?',
        [$image['id']],
    );
    $galleryCountBefore = countRows($pdo, 'galleries');
    // Both paths are beneath the validated disposable fixture; never touch the live site.
    $displaced = $directory . '/displaced-gallery';
    check(
        !file_exists($displaced) && rename($folder, $displaced),
        'Could not displace the owned fixture folder.',
    );
    try {
        $conflict = $admin->request(
            '/index.php?page=admin_new_gallery',
            array_replace($fields, [
                'operation_key' => $admin->operationKey(
                    '/index.php?page=admin_new_gallery&panel=1',
                ),
            ]),
            true,
        );
        $conflictBody = json_decode(
            $conflict['body'],
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        check(
            $conflict['status'] === 409
                && empty($conflictBody['ok'])
                && ($conflictBody['error_code'] ?? '')
                    === 'gallery_catalog_conflict',
            'Missing-folder create must return a typed conflict.',
        );
        check(
            row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id])
                === $catalogBefore
                && row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$childId])
                    === $childBefore
                && row(
                    $pdo,
                    'SELECT * FROM images WHERE id = ?',
                    [$image['id']],
                ) === $imageBefore
                && countRows($pdo, 'galleries') === $galleryCountBefore,
            'Refused creation changed the original catalog subtree.',
        );
        check(
            !is_dir($folder)
                && hash_file(
                    'sha256',
                    $displaced . '/' . $image['relative_path'],
                ) === hash_file('sha256', $sample),
            'Refused creation changed storage or lost the recoverable original.',
        );
    } finally {
        check(
            !file_exists($folder) && rename($displaced, $folder),
            'Could not restore the owned fixture folder.',
        );
    }
    echo
        "PASS gallery workflow missing-folder create preserves subtree and original\n";

    $stage = 'prepared upload lost response retry';
    $session = 'workflow-' . $token;
    $archive = $directory . '/batch.zip';
    $zip = new ZipArchive();
    check(
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) === true,
        'Could not create prepared upload fixture.',
    );
    $zip->addFromString('manifest.json', json_encode([
        'upload_session_id' => $session,
        'batch_index' => 0,
        'items' => [[
            'original_name' => 'retry.jpg',
            'prepared_name' => 'retry.jpg',
            'original_path' => 'originals/retry.jpg',
            'original_mime' => 'image/jpeg',
            'original_width' => 48,
            'original_height' => 32,
            'source_index' => 0,
            'variants' => [],
        ]],
    ], JSON_THROW_ON_ERROR));
    $zip->addFile($sample, 'originals/retry.jpg');
    $zip->setCompressionName('manifest.json', ZipArchive::CM_STORE);
    $zip->setCompressionName('originals/retry.jpg', ZipArchive::CM_STORE);
    $zip->close();
    $batch = [
        'csrf_token' => $csrf,
        'gallery_id' => (string) $id,
        'upload_session_id' => $session,
        'batch_index' => '0',
        'total_batches' => '1',
        'zip_batch' => new CURLFile($archive, 'application/zip', 'batch.zip'),
    ];
    $beforePreparedBatchImages = countRows($pdo, 'images');
    $beforePreparedBatchJpgFiles = glob($folder . '/*.jpg');
    check(
        is_array($beforePreparedBatchJpgFiles),
        'Could not enumerate fixture JPGs before prepared upload.',
    );
    // Intentionally discard the accepted response: the retry gets no client-side result from the first call.
    $admin->request('/index.php?page=admin_upload_browser_batch', $batch, true);
    $afterPreparedBatchJpgFiles = glob($folder . '/*.jpg');
    check(
        countRows($pdo, 'images') === ($beforePreparedBatchImages + 1)
            && is_array($afterPreparedBatchJpgFiles)
            && count($afterPreparedBatchJpgFiles)
                === count($beforePreparedBatchJpgFiles) + 1,
        'First prepared batch did not persist exactly one image.',
    );
    $accepted = row(
        $pdo,
        'SELECT id, relative_path FROM images WHERE gallery_id = ? ORDER BY id DESC LIMIT 1',
        [$id],
    );
    envelope(
        $admin->request(
            '/index.php?page=admin_upload_browser_batch',
            $batch,
            true,
        ),
        'Retry',
    );
    $afterPreparedBatchRetryJpgFiles = glob($folder . '/*.jpg');
    check(
        countRows($pdo, 'images') === ($beforePreparedBatchImages + 1),
        'Retry duplicated upload rows.',
    );
    check(
        row(
            $pdo,
            'SELECT id, relative_path FROM images WHERE gallery_id = ? ORDER BY id DESC LIMIT 1',
            [$id],
        ) === $accepted,
        'Retry changed upload identity.',
    );
    check(
        is_array($afterPreparedBatchRetryJpgFiles)
            && count($afterPreparedBatchRetryJpgFiles)
                === count($afterPreparedBatchJpgFiles),
        'Retry duplicated original files.',
    );
    echo
        "PASS gallery workflow prepared upload retry preserves identity and count\n";

    $stage = 'edit visibility and protected media';
    $edit = [
        'csrf_token' => $csrf,
        'id' => $id,
        'title' => 'HTTP edited',
        'description' => 'Persisted workflow description',
        'parent_id' => $seed['root_id'],
        'slug' => $gallery['slug'],
        'visibility' => 'unpublished',
        'edit_revision' => (string) row(
            $pdo,
            'SELECT edit_revision FROM galleries WHERE id = ?',
            [$id],
        )['edit_revision'],
    ];
    check(
        $anonymous->request(
            '/index.php?page=admin_edit_gallery&id=' . $id,
            $edit,
            true,
        )['status'] === 401,
        'Anonymous edit must fail.',
    );
    check(
        $admin->request(
            '/index.php?page=admin_edit_gallery&id=' . $id,
            array_replace($edit, ['csrf_token' => 'invalid']),
            true,
        )['status'] === 400,
        'Invalid CSRF edit must fail.',
    );
    check(
        row($pdo, 'SELECT title FROM galleries WHERE id = ?', [$id])['title']
            === $fields['title'],
        'Rejected edit changed title.',
    );
    envelope(
        $admin->request(
            '/index.php?page=admin_edit_gallery&id=' . $id,
            $edit,
            true,
        ),
        'Edit',
    );
    $updated = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    check(
        $updated['title'] === $edit['title']
            && $updated['description'] === $edit['description']
            && $updated['visibility'] === 'unpublished',
        'Edited database values mismatch.',
    );
    $sidecar = json_decode(
        (string) file_get_contents($folder . '/gallery.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    check(
        ($sidecar['title'] ?? '') === $edit['title'],
        'Edited sidecar title mismatch.',
    );
    // Unpublished is intentionally unlisted but accessible by direct URL in this CMS.
    check(
        $anonymous->request(
            '/index.php?page=media&id=' . $image['id'],
        )['status'] === 200,
        'Unpublished direct-access compatibility changed.',
    );

    $stage = 'real runtime unpublished OFP PDF against migrated gallery model';
    $unpublishedLinks = galleryWorkflowOfpLinks(
        $anonymous,
        (string) $updated['folder_path'],
        $id,
    );
    galleryWorkflowOfpAssertPdf(
        $anonymous,
        $unpublishedLinks['inline'],
        $ofpBytes,
    );
    galleryWorkflowOfpAssertPdf(
        $anonymous,
        $unpublishedLinks['download'],
        $ofpBytes,
        true,
    );
    $unpublishedHead = $anonymous->request(
        $unpublishedLinks['inline'],
        null,
        false,
        'HEAD',
    );
    check(
        $unpublishedHead['status'] === 200
            && $unpublishedHead['body'] === ''
            && str_starts_with(
                (string) ($unpublishedHead['headers']['content-type'] ?? ''),
                'application/pdf',
            )
            && (int) (
                $unpublishedHead['headers']['content-length'] ?? -1
            ) === strlen($ofpBytes),
        'Unpublished direct-link OFP HEAD lost its original metadata.',
    );
    galleryWorkflowOfpAssertPdf(
        $anonymous,
        '/index.php?page=media&id=' . $id . '&ofp=1',
        $ofpBytes,
    );
    galleryWorkflowOfpAssertPdf(
        $anonymous,
        '/index.php?page=media&id=' . $id . '&ofp=1&download=1',
        $ofpBytes,
        true,
    );
    echo
        "PASS gallery workflow unpublished OFP same-session gallery/GET/HEAD/download/legacy\n";

    $edit['visibility'] = 'private';
    $edit['edit_revision'] = (string) $updated['edit_revision'];
    envelope(
        $admin->request(
            '/index.php?page=admin_edit_gallery&id=' . $id,
            $edit,
            true,
        ),
        'Private',
    );
    check(
        row(
            $pdo,
            'SELECT visibility FROM galleries WHERE id = ?',
            [$id],
        )['visibility'] === 'private',
        'Private visibility was not persisted.',
    );
    check(
        $anonymous->request(
            '/index.php?page=media&id=' . $image['id'],
        )['status'] === 404,
        'Private original exposed.',
    );

    $stage = 'real runtime private OFP security boundary';
    foreach ([
        $unpublishedLinks['inline'],
        $unpublishedLinks['download'],
        '/index.php?page=media&id=' . $id . '&ofp=1',
    ] as $privateRoute) {
        $privatePdf = $anonymous->request($privateRoute);
        check(
            $privatePdf['status'] === 404
                && $privatePdf['body'] === 'Flight plan unavailable.',
            'Private source gallery PDF must not be disclosed to anonymous visitors.',
        );
    }
    galleryWorkflowOfpAssertPdf($admin, $unpublishedLinks['inline'], $ofpBytes);
    echo
        "PASS gallery workflow private OFP access control preserves real Admin access\n";

    $protectedImage = row(
        $pdo,
        'SELECT * FROM images WHERE gallery_id = ? LIMIT 1',
        [$seed['protected_id']],
    );
    foreach (['media', 'thumb'] as $route) {
        $denied = $anonymous->request(
            '/index.php?page=' . $route . '&id=' . $protectedImage['id'],
        );
        check(
            in_array($denied['status'], [302, 403, 404], true),
            'Protected media did not deny anonymous access.',
        );
    }
    $edit['visibility'] = 'public';
    $edit['edit_revision'] = (string) row(
        $pdo,
        'SELECT edit_revision FROM galleries WHERE id = ?',
        [$id],
    )['edit_revision'];
    envelope(
        $admin->request(
            '/index.php?page=admin_edit_gallery&id=' . $id,
            $edit,
            true,
        ),
        'Publish',
    );
    $public = $anonymous->request('/index.php?page=media&id=' . $image['id']);
    check(
        $public['status'] === 200
            && hash('sha256', $public['body']) === hash_file(
                'sha256',
                $original,
            ),
        'Published original unavailable or incorrect.',
    );

    $stage = 'real runtime republished and unlisted OFP';
    $republishedLinks = galleryWorkflowOfpLinks(
        $anonymous,
        (string) $gallery['folder_path'],
        $id,
    );
    galleryWorkflowOfpAssertPdf(
        $anonymous,
        $republishedLinks['inline'],
        $ofpBytes,
    );
    $pdo->prepare(
        "UPDATE galleries SET access_listing = 'unlisted' WHERE id = ?",
    )->execute([$id]);
    $unlistedLinks = galleryWorkflowOfpLinks(
        $anonymous,
        (string) $gallery['folder_path'],
        $id,
    );
    galleryWorkflowOfpAssertPdf(
        $anonymous,
        $unlistedLinks['inline'],
        $ofpBytes,
    );
    galleryWorkflowOfpAssertPdf(
        $anonymous,
        $unlistedLinks['download'],
        $ofpBytes,
        true,
    );
    $pdo->prepare(
        "UPDATE galleries SET access_listing = 'listed' WHERE id = ?",
    )->execute([$id]);
    echo "PASS gallery workflow original OFP republished/unlisted access\n";

    echo
        "PASS gallery workflow edit publish unpublish and protected media authorization\n";

    $stage = 'recoverable subtree delete and restore';
    $beforeDelete = countRows($pdo, 'galleries');
    $imagesBeforeSubtreeDelete = countRows($pdo, 'images');
    $delete = [
        'csrf_token' => $csrf,
        'gallery_ids' => [$id],
        'action' => 'delete',
    ];
    check(
        $admin->request(
            '/index.php?page=admin_bulk_galleries',
            array_replace($delete, ['csrf_token' => 'invalid']),
            true,
        )['status'] === 400,
        'Invalid CSRF delete must fail.',
    );
    check(
        $anonymous->request(
            '/index.php?page=admin_bulk_galleries',
            $delete,
            true,
        )['status'] === 401,
        'Anonymous delete must fail.',
    );
    check(
        countRows($pdo, 'galleries') === $beforeDelete && is_file($original),
        'Rejected delete changed persistence.',
    );
    check(
        $admin->request(
            '/index.php?page=admin_bulk_galleries',
            $delete,
        )['status'] === 302,
        'Delete fallback must redirect.',
    );
    check(
        row(
            $pdo,
            'SELECT id FROM galleries WHERE id IN (?, ?)',
            [$id, $childId],
        ) === []
            && !is_dir($folder),
        'Deleted subtree remains live.',
    );
    $trash = row(
        $pdo,
        "SELECT * FROM gallery_trash_entries WHERE status = 'trashed' ORDER BY id DESC LIMIT 1",
    );
    check($trash !== [], 'Recoverable trash record missing.');
    check(
        $anonymous->request(
            '/index.php?page=media&id=' . $image['id'],
        )['status'] !== 200,
        'Deleted original remains public.',
    );
    $restore = ['csrf_token' => $csrf, 'trash_token' => $trash['trash_token']];
    check(
        $anonymous->request(
            '/index.php?page=admin_trash_restore',
            $restore,
            true,
        )['status'] === 401,
        'Anonymous restore must fail.',
    );
    check(
        $admin->request(
            '/index.php?page=admin_trash_restore',
            array_replace($restore, ['csrf_token' => 'invalid']),
            true,
        )['status'] === 400,
        'Invalid CSRF restore must fail.',
    );
    envelope(
        $admin->request('/index.php?page=admin_trash_restore', $restore, true),
        'Restore',
    );
    $restored = row(
        $pdo,
        'SELECT * FROM galleries WHERE folder_path = ?',
        [$gallery['folder_path']],
    );
    check(
        $restored['title'] === $edit['title']
            && $restored['description'] === $edit['description']
            && $restored['visibility'] === 'public',
        'Restored gallery metadata mismatch.',
    );
    $child = row(
        $pdo,
        'SELECT parent_id FROM galleries WHERE title = ?',
        ['HTTP nested'],
    );
    check(
        (int) $child['parent_id'] === (int) $restored['id']
            && countRows($pdo, 'galleries') === $beforeDelete,
        'Restored subtree relationship mismatch.',
    );
    check(
        countRows($pdo, 'images') === $imagesBeforeSubtreeDelete
            && hash_file('sha256', $original) === hash_file('sha256', $sample),
        'Restored original mismatch.',
    );
    $admin->request('/index.php?page=admin_trash_restore', $restore, true);
    check(
        countRows($pdo, 'galleries') === $beforeDelete
            && countRows($pdo, 'images') === $imagesBeforeSubtreeDelete,
        'Repeat restore duplicated persistence.',
    );
    echo "PASS gallery workflow trash subtree restoration and repeat control\n";

    $stage = 'expired session';
    $admin->request('/index.php?page=admin_logout', ['csrf_token' => $csrf]);
    check(
        $admin->request(
            '/index.php?page=admin_new_gallery',
            $fields,
            true,
        )['status'] === 401,
        'Logged-out session could still mutate.',
    );
    check(
        countRows($pdo, 'galleries') === $beforeDelete,
        'Expired-session mutation changed rows.',
    );
    echo "PASS gallery workflow expired session rejects mutation\n";
} catch (Throwable $exception) {
    // No response bodies, SQL exceptions, cookies, configuration, or tokens reach audit/CI output.
    $detail = get_class($exception) === RuntimeException::class
        ? $exception->getMessage()
        : basename($exception->getFile()) . ' line ' . $exception->getLine();
    fwrite(STDERR, 'FAIL gallery workflow ' . $stage . ': ' . $detail . "\n");
    exit(1);
}
