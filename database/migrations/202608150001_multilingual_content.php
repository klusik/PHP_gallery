<?php

/** Add optional source-language tags and translated gallery/image content. */

declare(strict_types=1);

return [
    "ALTER TABLE galleries ADD COLUMN content_language VARCHAR(2) NULL AFTER description",
    "ALTER TABLE images ADD COLUMN content_language VARCHAR(2) NULL AFTER description",
    "CREATE TABLE gallery_translations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        gallery_id BIGINT UNSIGNED NOT NULL,
        language_code VARCHAR(2) NOT NULL,
        title VARCHAR(255) NULL,
        description TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY gallery_translations_owner_language_unique (gallery_id, language_code),
        KEY gallery_translations_language_index (language_code, gallery_id),
        CONSTRAINT gallery_translations_gallery_foreign FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE image_translations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        image_id BIGINT UNSIGNED NOT NULL,
        language_code VARCHAR(2) NOT NULL,
        title VARCHAR(255) NULL,
        description TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY image_translations_owner_language_unique (image_id, language_code),
        KEY image_translations_language_index (language_code, image_id),
        CONSTRAINT image_translations_image_foreign FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
