<?php

/** Add optional presentation overrides to Smart Gallery definitions. */

declare(strict_types=1);

return [
    "ALTER TABLE smart_galleries
        ADD COLUMN presentation_json MEDIUMTEXT NULL AFTER sort_direction",
];
