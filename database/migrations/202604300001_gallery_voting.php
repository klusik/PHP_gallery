<?php

return [
    "ALTER TABLE galleries ADD COLUMN voting_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility",
];
