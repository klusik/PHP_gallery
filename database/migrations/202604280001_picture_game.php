<?php

return [
    "ALTER TABLE galleries ADD COLUMN picture_game_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility",
    "CREATE TABLE picture_game_votes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        gallery_id BIGINT UNSIGNED NOT NULL,
        image_a_id BIGINT UNSIGNED NOT NULL,
        image_b_id BIGINT UNSIGNED NOT NULL,
        winner_image_id BIGINT UNSIGNED NULL,
        voter_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY picture_game_votes_pair_voter_unique (gallery_id, voter_hash, image_a_id, image_b_id),
        KEY picture_game_votes_gallery_winner_index (gallery_id, winner_image_id),
        CONSTRAINT picture_game_votes_gallery_id_foreign FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
        CONSTRAINT picture_game_votes_image_a_id_foreign FOREIGN KEY (image_a_id) REFERENCES images(id) ON DELETE CASCADE,
        CONSTRAINT picture_game_votes_image_b_id_foreign FOREIGN KEY (image_b_id) REFERENCES images(id) ON DELETE CASCADE,
        CONSTRAINT picture_game_votes_winner_image_id_foreign FOREIGN KEY (winner_image_id) REFERENCES images(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
