# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_image_policy.py
# Module Type: Shared gallery presentation policy
# Purpose: Keep both gallery frontends within existing transport and raster budgets.
# Responsibilities: Own logical image bounds and initial thumbnail admission.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Define reusable image and viewport bounds independently of toolkit."""

# Purpose: Retain useful photo detail while bounding normalized image memory.
# Type: tuple[int, int]. Units: pixels. Scope: one selected detail image.
# Consumers: Tk and Qt detail decoders. Rationale: 2048 by 1536 supports inspection.
MEDIA_IMAGE_MAX_SIZE = (2048, 1536)
# Purpose: Bound thumbnail width and height on the responsive gallery grid.
# Type: tuple[int, int]. Units: pixels. Scope: one gallery photo thumbnail.
# Consumers: Tk and Qt thumbnail decoders.
# Rationale: 144 px bounds both natural-aspect dimensions and retained pixels.
MEDIA_THUMBNAIL_SIZE = (144, 144)
# Purpose: Start optional HTTP batching with the reviewed conservative group.
# Type: int. Units: images. Scope: first and downshifted network group.
# Consumers: Tk and Qt viewport reads. Rationale: Eight limits first-turn
# occupancy before a host proves the framed path healthy.
MEDIA_HTTP_BATCH_INITIAL_SIZE = 8
# Purpose: Downshift a slow group before admitting subsequent network work.
# Type: float. Units: seconds. Scope: one completed thumbnail group.
# Consumers: Tk and Qt viewport reads. Rationale: Two seconds bounds a
# speculative turn without asserting host latency acceptance.
MEDIA_HTTP_BATCH_SLOW_SECONDS = 2.0
# Purpose: Downshift groups approaching the framed response byte budget.
# Type: int. Units: bytes. Scope: one completed thumbnail response.
# Consumers: Tk and Qt viewport reads. Rationale: Six MiB leaves headroom
# beneath the independently enforced eight MiB wire cap.
MEDIA_HTTP_BATCH_LARGE_BYTES = 6 * 1024 * 1024
# Purpose: Coalesce wheel, scrollbar and geometry events into one viewport pass.
# Type: int. Units: milliseconds. Scope: one live gallery viewer.
# Consumers: Tk and Qt viewport scheduling. Rationale: 24 ms coalesces
# bursts while view position changes immediately.
MEDIA_VIEWPORT_COALESCE_MS = 24
# Purpose: Bound decoded image rasters before allocating a bitmap.
# Type: int. Units: pixels. Scope: one original or thumbnail response.
# Consumers: Tk and Qt gallery decoders.
# Rationale: 36 megapixels bounds raster memory. Bounded detail JPEG/MPO
# subsampling can qualify a larger original; other formats need this bound.
MEDIA_IMAGE_MAX_PIXELS = 36_000_000

# Purpose: Bound identity-index bookkeeping for decoded thumbnails.
# Type: int. Units: decoded images. Scope: one live frontend cache.
# Consumers: Tk and Qt photo memory. Rationale: 4,096 supports large albums
# without allowing unlimited index growth.
MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT = 4096
# Purpose: Cap decoded thumbnails independently of live display textures.
# Type: int. Units: bytes. Scope: one live frontend cache.
# Consumers: Tk and Qt photo memory. Rationale: 256 MiB keeps useful recent
# previews warm under the existing shared budget.
MEDIA_THUMBNAIL_CACHE_MAX_BYTES = 256 * 1024 * 1024
# Purpose: Bound thumbnail proof age and validator-free decoded reuse.
# Type: float. Units: seconds. Scope: one entry in a live session cache.
# Consumers: Tk and Qt photo memory. Rationale: One minute matches the
# existing freshness window. Pixels alone never extend authorization.
MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS = 60.0

# Purpose: Provide the established medium slideshow pace without server settings.
# Type: int. Units: seconds. Scope: one manually started slideshow.
# Consumers: Tk and Qt Lightbox. Rationale: Five seconds is the existing
# medium preset and leaves a visible reading interval before automatic advance.
MEDIA_SLIDESHOW_DEFAULT_SECONDS = 5
