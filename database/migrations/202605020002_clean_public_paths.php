<?php

return [
    "ALTER TABLE galleries ADD COLUMN url_slug VARCHAR(255) NULL AFTER slug",
    "ALTER TABLE galleries ADD COLUMN url_path VARCHAR(1024) NULL AFTER url_slug",
    "ALTER TABLE galleries ADD COLUMN url_path_hash CHAR(64) NULL AFTER url_path",
    "ALTER TABLE galleries ADD UNIQUE KEY galleries_url_path_hash_unique (url_path_hash)",
    "ALTER TABLE images ADD COLUMN url_slug VARCHAR(255) NULL AFTER filename",
    "ALTER TABLE images ADD KEY images_gallery_url_slug_index (gallery_id, url_slug)",
];
