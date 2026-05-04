<?php

return [
    "ALTER TABLE galleries ADD COLUMN show_filenames TINYINT(1) NOT NULL DEFAULT 0 AFTER voting_enabled",
];
