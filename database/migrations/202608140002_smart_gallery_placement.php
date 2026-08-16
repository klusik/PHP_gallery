<?php

/** Add explicit root, physical-gallery child, and unlisted Smart Gallery placement. */

declare(strict_types=1);

return [
    "ALTER TABLE smart_galleries
        ADD COLUMN placement_mode ENUM('unlisted', 'root', 'gallery') NOT NULL DEFAULT 'unlisted' AFTER visibility,
        ADD COLUMN parent_gallery_id BIGINT UNSIGNED NULL AFTER placement_mode,
        ADD KEY smart_galleries_placement_listing (placement_mode, parent_gallery_id, enabled, visibility, title),
        ADD CONSTRAINT smart_galleries_parent_gallery_foreign FOREIGN KEY (parent_gallery_id) REFERENCES galleries(id) ON DELETE SET NULL",
];
