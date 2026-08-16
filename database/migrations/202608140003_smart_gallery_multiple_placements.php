<?php

/** Allow one Smart Gallery to appear beneath any number of physical galleries. */

declare(strict_types=1);

return [
    "CREATE TABLE smart_gallery_placements (
        smart_gallery_id BIGINT UNSIGNED NOT NULL,
        gallery_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (smart_gallery_id, gallery_id),
        KEY smart_gallery_placements_gallery (gallery_id, smart_gallery_id),
        CONSTRAINT smart_gallery_placements_smart_foreign FOREIGN KEY (smart_gallery_id) REFERENCES smart_galleries(id) ON DELETE CASCADE,
        CONSTRAINT smart_gallery_placements_gallery_foreign FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT IGNORE INTO smart_gallery_placements (smart_gallery_id, gallery_id, created_at)
        SELECT id, parent_gallery_id, updated_at FROM smart_galleries
        WHERE placement_mode = 'gallery' AND parent_gallery_id IS NOT NULL",
];
