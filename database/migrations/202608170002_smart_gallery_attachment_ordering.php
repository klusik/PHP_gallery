<?php

/** Add per-parent Smart Gallery placement area and deterministic ordering. */

declare(strict_types=1);

return [
    "ALTER TABLE smart_gallery_placements
        ADD COLUMN placement ENUM('top', 'bottom') NOT NULL DEFAULT 'bottom' AFTER gallery_id,
        ADD COLUMN placement_order INT NOT NULL DEFAULT 0 AFTER placement,
        ADD KEY smart_gallery_placements_render_order (gallery_id, placement, placement_order, smart_gallery_id)",
];
