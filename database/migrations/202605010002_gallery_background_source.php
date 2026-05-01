<?php

return [
    "ALTER TABLE galleries ADD COLUMN background_source ENUM('upload','existing','collage') NULL DEFAULT NULL AFTER cover_image_path",
];
