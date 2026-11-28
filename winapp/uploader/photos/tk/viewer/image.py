# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/tk/viewer/image.py
# Module Type: Windows Client UI mixin
# Purpose: Own a cohesive gallery viewer interaction domain.
# Responsibilities: Preserve the native viewer lifecycle and media callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Native gallery viewer behavior mixin."""

from .support import (
    Image,
    ImageTk,
    LinkedPhoto,
    LinkedPresentation,
    LinkingError,
    MEDIA_DETAIL_CACHE_ENTRY_LIMIT,
    MEDIA_DETAIL_CACHE_FRESH_SECONDS,
    MEDIA_DETAIL_CACHE_MAX_BYTES,
    MEDIA_DETAIL_PREFETCH_LIMIT,
    MEDIA_IMAGE_MAX_PIXELS,
    MEDIA_IMAGE_MAX_SIZE,
    MEDIA_INITIAL_THUMBNAIL_PRIORITY_LIMIT,
    MEDIA_QUEUE_ACTIVE_POLL_MS,
    MEDIA_REVISION_POLL_MS,
    MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,
    MEDIA_THUMBNAIL_CACHE_MAX_BYTES,
    MEDIA_THUMBNAIL_GUI_SLICE_SECONDS,
    MEDIA_THUMBNAIL_GUI_SLICE_SIZE,
    MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS,
    MEDIA_THUMBNAIL_PLACEHOLDER,
    MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS,
    MEDIA_THUMBNAIL_SIZE,
    MediaReadTimings,
    NATIVE_GALLERY_VIEWER_LOGGER,
    NATIVE_MEDIA_MAX_BYTES,
    PhotosDecodeError,
    PhotosDecodeFacts,
    _ThumbnailResult,
    _WorkerPayload,
    _healthy_http_thumbnail_group,
    _log_safe_media_failure,
    _log_safe_photos_observation,
    detect_black_matte_crop,
    format_safe_photos_decode_facts,
    io,
    logging,
    queue,
    threading,
    time,
)

class NativeGalleryImageMixin:
    """Own this cohesive native gallery viewer behavior domain."""

    def _prepare_image(
        self,
        body: bytes,
        max_size: tuple[int, int] = MEDIA_IMAGE_MAX_SIZE,
    ) -> bytes:
        """Decode, constrain and normalize one image as bounded PNG bytes.

        Args:
            body (bytes): Image response body from the linked session.
            max_size (tuple[int, int]): Maximum normalized image dimensions.

        Returns:
            bytes: Resized PNG bytes suitable for Tk-side PhotoImage creation.

        Raises:
            ValueError: Pillow is unavailable or image data exceeds policy.
        """
        if Image is None or len(body) > NATIVE_MEDIA_MAX_BYTES:
            raise ValueError('Image support unavailable or response too large')
        with Image.open(io.BytesIO(body)) as source:
            if source.width * source.height > MEDIA_IMAGE_MAX_PIXELS:
                raise ValueError('Image dimensions exceed policy')
            image = source.convert('RGB')
            if max_size == MEDIA_THUMBNAIL_SIZE:
                crop = detect_black_matte_crop(image)
                if crop != (0, 0, image.width, image.height):
                    cropped = image.crop(crop)
                    image.close()
                    image = cropped
                image.thumbnail(max_size, Image.Resampling.LANCZOS)
            else:
                image.thumbnail(max_size)
            output = io.BytesIO()
            image.save(output, format='PNG')
            result = output.getvalue()
        if len(result) > NATIVE_MEDIA_MAX_BYTES:
            raise ValueError('Normalized image exceeds policy')
        return result
    def _prepare_detail(self, body: bytes) -> 'Image.Image':
        """Decode and constrain a detail image on the owned worker thread.

        JPEG/MPO decoder subsampling is requested whenever source dimensions
        exceed the final detail bounds, allowing supported scaling to reduce
        RGB allocation before the final resize. The pixel ceiling remains a post-draft
        safety bound; unsupported oversized rasters are rejected before convert.

        Args:
            body (bytes): Bounded raster response returned by the linked session.

        Returns:
            Image.Image: RGB image sized for the native lightbox and zoom.

        Raises:
            PhotosDecodeError: Body or raster cannot be safely reduced within
                the existing decoded-pixel and detail-size limits.
        """
        source_size = (0, 0)
        decoded_size = (0, 0)
        image_format = 'OTHER'
        stage = 'source-open'
        if Image is None or len(body) > NATIVE_MEDIA_MAX_BYTES:
            reason = 'body-limit' if len(body) > NATIVE_MEDIA_MAX_BYTES else 'decode-failed'
            raise PhotosDecodeError(PhotosDecodeFacts(
                stage, reason, image_format, source_size, decoded_size,
                len(body), MEDIA_IMAGE_MAX_PIXELS, MEDIA_IMAGE_MAX_SIZE,
            ))
        image: 'Image.Image | None' = None
        try:
            with Image.open(io.BytesIO(body)) as source:
                source_size = source.size
                pillow_format = source.format
                image_format = (
                    pillow_format if pillow_format in (
                        'JPEG', 'MPO', 'PNG', 'WEBP', 'GIF', 'TIFF', 'BMP',
                    ) else 'OTHER'
                )
                decoded_size = source.size
                source_pixels = source.width * source.height
                is_jpeg = image_format in ('JPEG', 'MPO')
                if source_pixels > MEDIA_IMAGE_MAX_PIXELS and not is_jpeg:
                    raise PhotosDecodeError(PhotosDecodeFacts(
                        'pixel-check', 'pixel-limit', image_format,
                        source_size, decoded_size, len(body),
                        MEDIA_IMAGE_MAX_PIXELS, MEDIA_IMAGE_MAX_SIZE,
                    ))
                exceeds_detail_bounds = (
                    source.width > MEDIA_IMAGE_MAX_SIZE[0]
                    or source.height > MEDIA_IMAGE_MAX_SIZE[1]
                )
                if is_jpeg and exceeds_detail_bounds:
                    stage = 'draft'
                    try:
                        source.draft('RGB', MEDIA_IMAGE_MAX_SIZE)
                    except Exception:
                        raise PhotosDecodeError(PhotosDecodeFacts(
                            stage, 'decode-failed', image_format, source_size,
                            decoded_size, len(body), MEDIA_IMAGE_MAX_PIXELS,
                            MEDIA_IMAGE_MAX_SIZE,
                        )) from None
                    decoded_size = source.size
                if source.width * source.height > MEDIA_IMAGE_MAX_PIXELS:
                    raise PhotosDecodeError(PhotosDecodeFacts(
                        'pixel-check', 'pixel-limit', image_format,
                        source_size, decoded_size, len(body),
                        MEDIA_IMAGE_MAX_PIXELS, MEDIA_IMAGE_MAX_SIZE,
                    ))
                if (decoded_size[0] < source_size[0]
                        or decoded_size[1] < source_size[1]):
                    _log_safe_photos_observation(
                        format_safe_photos_decode_facts(PhotosDecodeFacts(
                            'draft', 'reduced', image_format, source_size,
                            decoded_size, len(body), MEDIA_IMAGE_MAX_PIXELS,
                            MEDIA_IMAGE_MAX_SIZE,
                        )),
                        self._observation_callback,
                    )
                stage = 'source-convert'
                decoded_size = source.size
                try:
                    image = source.convert('RGB')
                except Exception:
                    raise PhotosDecodeError(PhotosDecodeFacts(
                        stage, 'decode-failed', image_format, source_size,
                        decoded_size, len(body), MEDIA_IMAGE_MAX_PIXELS,
                        MEDIA_IMAGE_MAX_SIZE,
                    )) from None
            stage = 'resize'
            image.thumbnail(MEDIA_IMAGE_MAX_SIZE)
            result = image
            image = None
            return result
        except PhotosDecodeError:
            raise
        except Exception:
            raise PhotosDecodeError(PhotosDecodeFacts(
                stage, 'decode-failed', image_format, source_size,
                decoded_size, len(body), MEDIA_IMAGE_MAX_PIXELS,
                MEDIA_IMAGE_MAX_SIZE,
            )) from None
        finally:
            if image is not None:
                image.close()
    def _poll_queue(self) -> None:
        """Apply queued worker results on Tk's owning thread.

        Returns:
            None: Delivers results and reports authorized cache snapshots
                without idle polling or another catalogue request.
        """
        self._after_id = None
        if self._closed:
            return
        if not self.session.can_read_media():
            self.close()
            return
        # Some isolated lifecycle fixtures bypass construction; only a normally
        # constructed viewer owns this optional presentation mailbox.
        progress_lock = getattr(self, '_load_progress_lock', None)
        if progress_lock is not None:
            with progress_lock:
                progress = self._pending_load_progress
                self._pending_load_progress = None
                wait = self._pending_load_wait
                self._pending_load_wait = None
            if progress is not None and progress[0] == self._generation:
                indicator = getattr(self, '_load_progress', None)
                if indicator is not None:
                    indicator.metadata(progress[1])
            if wait is not None and wait[0] == self._generation:
                indicator = getattr(self, '_load_progress', None)
                if indicator is not None:
                    if wait[1] == 0 and wait[2]:
                        indicator.resume()
                    else:
                        indicator.waiting(wait[1], wait[2])
        cache_changed = False
        gui_started = time.perf_counter()
        gui_count = 0
        while True:
            try:
                generation, kind, result = self._events.get_nowait()
            except queue.Empty:
                break
            if generation != self._generation or self._closed:
                self._release_worker_result(result, kind)
                continue
            if (kind in ('detail_error', 'detail_unavailable')
                    and isinstance(result, tuple)
                    and result[0] != self._detail_serial):
                kind = 'detail_stale'
            try:
                if kind == 'revision':
                    if (self.frame.winfo_viewable()
                            and not (self._lightbox is not None
                                     and self._lightbox.visible)):
                        if self._revision is None or self._revision != result:
                            if (self._revision is not None
                                    and self.on_photos_changed is not None):
                                self.on_photos_changed()
                            self._refresh_revision = result
                            self._refresh_pending = True
                elif kind == 'refresh':
                    self._accept_refresh(result)
                    if self._refresh_foreground:
                        if self._photos:
                            self.status.configure(text='')
                            self.status.pack_forget()
                        else:
                            self._refresh_empty_page_status()
                    if self._refresh_revision is not None:
                        self._revision = self._refresh_revision
                        self._refresh_revision = None
                elif kind.startswith('refresh_'):
                    # Keep the prior committed revision so a later probe can
                    # retry an interrupted refresh without a tight retry loop.
                    self._refresh_revision = None
                    self._on_grid_viewport(None)
                    if self._refresh_foreground:
                        self.status.pack(anchor='w', pady=(4, 8))
                        error_key = (
                            'linking_media_throttled' if kind == 'refresh_throttled'
                            else 'linking_media_error'
                        )
                        self.status.configure(text=self._text(error_key))
                elif kind == 'revision_unsupported':
                    self._revision_supported = False
                elif kind in ('revision_error', 'revision_throttled'):
                    pass
                elif kind in ('page', 'page_recent'):
                    self._accept_page(*result)
                    self._presentation_pending = self.session.can_read_presentation()
                    if kind == 'page_recent':
                        self._refresh_pending = True
                elif kind == 'presentation':
                    if isinstance(result, LinkedPresentation):
                        self._presentation = result
                        self._tracks = tuple(result.tracks)
                        self._refresh_audio_button()
                        if (self._slideshow_running
                                and not self._slideshow_music_attempted
                                and self._audio_index is None
                                and result.music and self._tracks):
                            self._slideshow_music_attempted = True
                            self._toggle_track()
                            self._slideshow_started_music = self._audio_index is not None
                elif kind.startswith('presentation_'):
                    pass
                elif kind in ('more', 'slide_more'):
                    self._accept_page(
                        result, self._presentation,
                        continuing_slides=kind == 'slide_more' and self._slideshow_running,
                    )
                elif kind == 'thumbnail':
                    self._show_thumbnail(*result)
                elif kind == 'thumbnail_batch_item':
                    if not isinstance(result, _ThumbnailResult):
                        continue
                    valid_identity = bool(
                        result.gallery_id == self.gallery.gallery_id
                        and 0 <= result.index < len(self._photos)
                        and self._photos[result.index] == result.photo
                        and result.index in self._admitted_thumbnail_indices
                    )
                    if result.publication is not None:
                        valid_identity = bool(
                            valid_identity and not self._thumbnail_cancelled.is_set()
                            and self.session.thumbnail_publication_current(
                                result.publication, self._thumbnail_cancelled,
                            )
                        )
                    if not valid_identity:
                        if result.image is not None:
                            result.image.close()
                        continue
                    if (result.index < len(self._photo_images)
                            and self._photo_images[result.index] is not None):
                        if result.image is not None:
                            result.image.close()
                        self._thumbnail_discarded_count += 1
                        continue
                    self._thumbnail_queue_wait_ms += max(
                        0.0,
                        (time.perf_counter() - result.enqueued_at) * 1000.0,
                    )
                    show_started = time.perf_counter()
                    private_descriptor = (
                        result.photo.cache_eligible is not True
                        and (self.session.can_read_admin_media()
                             or result.photo.cache_eligible is False
                             or result.photo.preload_eligible is not None)
                    )
                    verified_private_response = (
                        result.timings.source == 'disk'
                        or result.timings.cache_stored
                    )
                    self._show_thumbnail(
                        result.index, result.image,
                        remember=(result.publication.ram_eligible
                                  if result.publication is not None else
                                  not private_descriptor or verified_private_response),
                    )
                    self._thumbnail_tk_ms += (
                        time.perf_counter() - show_started
                    ) * 1000.0
                    gui_count += 1
                    if (gui_count >= MEDIA_THUMBNAIL_GUI_SLICE_SIZE
                            or time.perf_counter() - gui_started
                            >= MEDIA_THUMBNAIL_GUI_SLICE_SECONDS):
                        break
                elif kind == 'detail':
                    self._show_detail(*result)
                elif kind == 'prefetch':
                    image_id, data, encoded = result
                    pending_index = self._pending_detail_index
                    active_cancelled = self._active_media_cancelled
                    adopt_prefetch = bool(
                        data is not None
                        and pending_index is not None
                        and 0 <= pending_index < len(self._photos)
                        and self._photos[pending_index].image_id == image_id
                        and self._active_media_photo_id == image_id
                        and active_cancelled is not None
                        and not active_cancelled.is_set()
                    )
                    if adopt_prefetch:
                        with self._thumbnail_metrics_lock:
                            self._detail_media_metrics[
                                'prefetch_adoptions'
                            ] += 1
                        self._pending_detail_index = None
                        cached_image = data.copy() if encoded is not None else None
                        self._show_detail(
                            self._detail_serial, image_id, data,
                            cached_image, encoded,
                        )
                    elif data is not None and encoded is not None:
                        self._remember_detail(image_id, data, encoded)
                    elif data is not None:
                        data.close()
                elif kind.startswith('audio_track'):
                    self._accept_audio_track(*result)
                elif kind == 'prefetch_error':
                    pass
                elif kind.endswith('_throttled'):
                    indicator = getattr(self, '_load_progress', None)
                    if (indicator is not None and indicator.active
                            and indicator.phase != 'waiting'):
                        indicator.waiting(None, False)
                    self._media_throttled = True
                    self._pending_prefetch_ids.clear()
                    self._pending_detail_index = None
                    self._thumbnail_cancelled.set()
                    if (kind.startswith('thumbnail') and type(result) is int
                            and result in self._visible_thumbnail_indices
                            and result not in self._thumbnail_pending):
                        self._thumbnail_pending.insert(0, result)
                    self._stop_slideshow()
                    self.status.pack(anchor='w', pady=(4, 8))
                    self.status.configure(
                        text=self._text('linking_media_throttled'),
                    )
                    self.retry_button.configure(state='normal')
                    self.retry_button.pack(side='left', padx=(8, 0))
                    self._retry_kind = (
                        'detail' if kind.startswith('detail') else
                        'thumbnail' if kind.startswith('thumbnail') else
                        'page' if kind.startswith(('page', 'more', 'slide_more'))
                        else 'thumbnail'
                    )
                    if kind.startswith('detail') and self._lightbox is not None:
                        self._lightbox.show_error(
                            self._text('linking_media_throttled'),
                        )
                elif kind == 'page_unavailable':
                    indicator = getattr(self, '_load_progress', None)
                    if indicator is not None and indicator.active:
                        indicator.fail(self._text('linking_media_load_failed'))
                    self.status.pack(anchor='w', pady=(4, 8))
                    self.status.configure(
                        text=self._text('linking_media_gallery_unavailable'),
                    )
                    self.retry_button.configure(state='normal')
                    self.retry_button.pack(side='left', padx=(8, 0))
                    self._retry_kind = 'page'
                    if self._page_request_mode == 'initial':
                        self._notify_preview_result(False)
                elif kind == 'detail_unavailable':
                    self.status.pack(anchor='w', pady=(4, 8))
                    self.status.configure(
                        text=self._text('linking_media_gallery_unavailable'),
                    )
                    self._show_detail(*result)
                    self.retry_button.configure(state='normal')
                    self.retry_button.pack(side='left', padx=(8, 0))
                    self._retry_kind = 'detail'
                elif kind.endswith('_error'):
                    if kind in ('page_error', 'more_error'):
                        indicator = getattr(self, '_load_progress', None)
                        if indicator is not None and indicator.active:
                            indicator.fail(
                                self._text('linking_media_load_failed'),
                            )
                    self.status.pack(anchor='w', pady=(4, 8))
                    self.status.configure(text=self._text('linking_media_error'))
                    if kind.startswith('detail'):
                        self._show_detail(*result)
                        self.retry_button.configure(state='normal')
                        self.retry_button.pack(side='left', padx=(8, 0))
                        self._retry_kind = 'detail'
                    elif kind == 'page_error':
                        self.retry_button.configure(state='normal')
                        self.retry_button.pack(side='left', padx=(8, 0))
                        self._retry_kind = 'page'
                        if self._page_request_mode == 'initial':
                            self._notify_preview_result(None)
                    elif kind in ('more_error', 'slide_more_error'):
                        self._stop_slideshow()
                        self.retry_button.configure(state='normal')
                        self.retry_button.pack(side='left', padx=(8, 0))
                        self._retry_kind = 'page'
            except Exception as exc:
                phase = (
                    "page-apply"
                    if kind in ("page", "page_recent", "more", "slide_more")
                    else "media-apply"
                )
                _log_safe_media_failure(phase, kind, exc)
                if kind == 'refresh':
                    self._refresh_revision = None
                    self._on_grid_viewport(None)
                if kind != 'refresh' or self._refresh_foreground:
                    self.status.configure(text=self._text('linking_media_error'))
            if kind in (
                'page', 'page_recent', 'more', 'slide_more', 'refresh',
                'thumbnail', 'thumbnail_batch_item', 'detail', 'prefetch',
            ):
                cache_changed = True
        local_delivered = 0
        while (gui_count < MEDIA_THUMBNAIL_GUI_SLICE_SIZE
               and (gui_count == 0 or time.perf_counter() - gui_started
                    < MEDIA_THUMBNAIL_GUI_SLICE_SECONDS)):
            local = self._thumbnail_disk_lane.pop_ready()
            if local is None:
                break
            valid = (
                local.gallery_id == self.gallery.gallery_id
                and local.index in self._admitted_thumbnail_indices
                and local.index < len(self._photos)
                and self._photos[local.index] == local.photo
                and not self._refresh_pending
            )
            if (valid and local.index < len(self._photo_images)
                    and self._photo_images[local.index] is not None):
                valid = False
            if not valid:
                if local.image is not None:
                    local.image.close()
                self._thumbnail_discarded_count += 1
            elif local.image is not None:
                self._thumbnail_queue_wait_ms += max(
                    0.0, (time.perf_counter() - local.enqueued_at) * 1000.0,
                )
                show_started = time.perf_counter()
                try:
                    self._show_thumbnail(local.index, local.image)
                    cache_changed = True
                except Exception as exc:
                    local.image.close()
                    _log_safe_media_failure('media-apply', 'thumbnail', exc)
                finally:
                    self._thumbnail_tk_ms += (
                        time.perf_counter() - show_started
                    ) * 1000.0
            elif (local.index not in self._thumbnail_pending
                  and local.index not in self._thumbnail_loaded):
                self._thumbnail_local_miss_count += 1
                self._thumbnail_pending.append(local.index)
            gui_count += 1
            local_delivered += 1
        self._thumbnail_pending.sort(
            key=lambda index: (index not in self._visible_thumbnail_indices, index),
        )
        cache_callback = getattr(self, 'on_cache_changed', None)
        if cache_changed and cache_callback is not None and not self._closed:
            cache_callback(self._photos, self._next_after is None)
        if not self._events.empty():
            self._schedule_queue_poll(MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS)
            return
        if self._worker is not None and not self._worker.is_alive():
            # The producer can publish between the first empty check and its
            # exit. Once it is dead, this final check is stable: never advance
            # the generation while its last raster is still undelivered.
            if not self._events.empty():
                self._schedule_queue_poll(
                    MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS,
                )
                return
            with self._thumbnail_result_lock:
                completion = self._http_batch_completion
                self._http_batch_completion = None
            if completion is not None:
                with self._thumbnail_metrics_lock:
                    physical = completion.timings
                    zero_http_defer = (
                        completion.outcome in (
                            'deferred_transient', 'deferred_wait',
                        ) and physical.http_requests == 0
                    )
                    if not zero_http_defer and completion.physical_complete:
                        self._thumbnail_batch_physical.observe(physical)
                        self._thumbnail_gate_wait_ms += physical.gate_wait_ms
                        self._thumbnail_renewal_ms += physical.renewal_ms
                        self._thumbnail_network_ms += physical.network_ms
                        self._thumbnail_network_reads += physical.network_reads
                        for name in self._thumbnail_transport_totals:
                            self._thumbnail_transport_totals[name] += getattr(physical, name)
                        self._http_batch_requests += sum(completion.stats.batch_sizes.values())
                        for size, count in completion.stats.batch_sizes.items():
                            self._http_batch_sizes[size] = (
                                self._http_batch_sizes.get(size, 0) + count
                            )
                        self._http_batch_frames += completion.stats.wire_items
                        self._http_batch_bytes += physical.encoded_bytes
                        self._http_batch_failures += completion.stats.operation_failures
                        self._http_batch_fallbacks += (
                            completion.stats.fallback_capability_missing
                            + completion.stats.fallback_unsupported
                            + completion.stats.fallback_wire_disabled
                            + completion.stats.fallback_restricted
                            + completion.stats.fallback_exceptional
                        )
                        for reason, count in (
                            ('missing', completion.stats.fallback_capability_missing),
                            ('unsupported', completion.stats.fallback_unsupported),
                            ('wire', completion.stats.fallback_wire_disabled),
                            ('restricted', completion.stats.fallback_restricted),
                            ('exceptional', completion.stats.fallback_exceptional),
                        ):
                            self._http_batch_fallback_reasons[reason] += count
                        if completion.stats.first_frame_ms is not None:
                            self._http_batch_first_frame_ms = completion.stats.first_frame_ms
                    elif not zero_http_defer and not completion.physical_complete:
                        self._http_batch_incomplete += 1
                if completion.generation == self._generation:
                    self._http_thumbnail_group_healthy = (
                        _healthy_http_thumbnail_group(completion)
                    )
                    if completion.outcome == 'proof_changed':
                        now = time.monotonic()
                        if (self._thumbnail_proof_refresh_attempt_at is None
                                or now - self._thumbnail_proof_refresh_attempt_at
                                >= MEDIA_REVISION_POLL_MS / 1000.0):
                            self._thumbnail_proof_refresh_attempt_at = now
                            self._thumbnail_proof_refresh_count += 1
                            self._refresh_pending = True
                    elif completion.outcome == 'deferred_transient':
                        indicator = getattr(self, '_load_progress', None)
                        if indicator is not None:
                            indicator.waiting(
                                completion.deferred_seconds, True,
                            )
                        self._http_batch_retry_indices = completion.remaining
                        self.status.pack(anchor='w', pady=(4, 8))
                        self.status.configure(
                            text=self._text('linking_media_retry_wait'),
                        )
                        self.retry_button.configure(state='disabled')
                        self.retry_button.pack_forget()
                        self._retry_kind = 'thumbnail'
                        if completion.deferred_seconds is not None:
                            self._schedule_thumbnail_resume(
                                completion.deferred_seconds,
                                completion.prior_operation_finished,
                            )
                    elif completion.outcome in (
                        'throttled', 'error', 'deferred_wait',
                    ):
                        indicator = getattr(self, '_load_progress', None)
                        if indicator is not None:
                            if completion.outcome == 'error':
                                indicator.fail(
                                    self._text('linking_media_load_failed'),
                                )
                            elif completion.outcome in (
                                'throttled', 'deferred_wait',
                            ):
                                indicator.waiting(
                                    completion.deferred_seconds, False,
                                )
                        self._http_batch_retry_indices = completion.remaining
                        self._media_throttled = completion.outcome in (
                            'throttled', 'deferred_wait',
                        )
                        if completion.outcome == 'error':
                            self._http_thumbnail_failure_latched = True
                        self.status.pack(anchor='w', pady=(4, 8))
                        self.status.configure(text=self._text(
                            'linking_media_retry_wait'
                            if completion.outcome == 'deferred_wait'
                            else 'linking_media_throttled'
                            if self._media_throttled
                            else 'linking_media_error',
                        ))
                        self.retry_button.configure(state='normal')
                        self.retry_button.pack(side='left', padx=(8, 0))
                        self._retry_kind = 'thumbnail'
                    if completion.outcome not in (
                        'cancelled', 'deferred_transient',
                    ):
                        for index in completion.remaining:
                            if index in self._thumbnail_pending:
                                self._thumbnail_pending.remove(index)
                            if index in self._admitted_thumbnail_indices:
                                self._thumbnail_loaded.add(index)
            self._worker = None
            self._worker_kind = None
            self._thumbnail_batch_indices = ()
            self._active_media_cancelled = None
            self._active_media_photo_id = None
        self._dispatch_pending_work()
        # Consuming a local result wakes the bounded producer. Continue its
        # next slice after yielding instead of charging every ready cache batch
        # the serialized network-worker polling interval. An empty local poll
        # still falls back to the normal interval, so slow I/O cannot busy-spin.
        self._schedule_queue_poll(
            MEDIA_THUMBNAIL_RESULT_CONTINUATION_MS
            if local_delivered and self._thumbnail_disk_lane.busy()
            else MEDIA_QUEUE_ACTIVE_POLL_MS,
        )
    def _release_worker_result(
        self, result: _WorkerPayload, kind: str,
    ) -> None:
        """Release decoded rasters that cannot reach the active viewer.

        Args:
            result (_WorkerPayload): Result from a superseded worker operation.
            kind (str): Fixed event kind identifying its payload shape.

        Returns:
            None: Pillow resources are released without Tk access.
        """
        if kind == 'thumbnail_batch_item' and isinstance(result, _ThumbnailResult):
            self._thumbnail_discarded_count += 1
            if result.image is not None:
                result.image.close()
            return
        if Image is None or not isinstance(result, tuple):
            return
        if kind == 'thumbnail' and len(result) >= 2:
            rasters = (result[1],)
        elif kind == 'detail' and len(result) >= 4:
            rasters = (result[2], result[3])
        elif kind == 'prefetch' and len(result) >= 2:
            rasters = (result[1],)
        else:
            return
        released: set[int] = set()
        for raster in rasters:
            if (isinstance(raster, Image.Image) and id(raster) not in released):
                raster.close()
                released.add(id(raster))
    def _dispatch_pending_work(self) -> None:
        """Dispatch foreground work, bounded tiles and visible speculation.

        Returns:
            None: Detail and page requests lead; audio stays ahead of tiles,
                then adjacent lightbox details precede grid thumbnails. Tiles
                still get a bounded initial turn before presentation/revision.
        """
        if self._closed or self._worker is not None:
            return
        if self._pending_detail_index is not None:
            pending = self._pending_detail_index
            self._pending_detail_index = None
            self._load_detail(pending)
            return
        if self._pending_page_request is not None:
            if (self._prefetch_edge_direction
                    and (self._lightbox is None
                         or not self._lightbox.visible)):
                self._pending_page_request = None
                self._prefetch_edge_direction = 0
            else:
                after_id, automatic = self._pending_page_request
                self._pending_page_request = None
                self._start_page_after(
                    after_id,
                    automatic=automatic,
                )
                return
        if self._refresh_pending and self._refresh_manual_pending:
            self._start_refresh()
            return
        if self._dispatch_audio_prefetch():
            return
        if self._pending_prefetch_ids and (
                self._lightbox is not None and self._lightbox.visible):
            if self._load_next_prefetch():
                return
        if self._refresh_pending:
            self._start_refresh()
            return
        revision_due = (
            not self._revision_initial_attempted
            and self._revision_supported
            and self.session.can_read_admin_media()
            and self.frame.winfo_viewable()
            and not (self._lightbox is not None and self._lightbox.visible)
            and bool(self._photos)
        )
        priority_due = revision_due or self._presentation_pending
        if (self._thumbnail_pending and not self._media_throttled
                and not self._http_thumbnail_failure_latched
                and self._thumbnail_resume_after_id is None
                and (not priority_due
                     or self._initial_thumbnail_priority_count
                     < MEDIA_INITIAL_THUMBNAIL_PRIORITY_LIMIT)):
            previous_worker = self._worker
            self._thumbnail_cancelled.clear()
            remaining = (
                MEDIA_INITIAL_THUMBNAIL_PRIORITY_LIMIT
                - self._initial_thumbnail_priority_count
                if priority_due else None
            )
            self._load_next_thumbnail(remaining)
            if self._worker is not None and self._worker is not previous_worker:
                if priority_due:
                    self._initial_thumbnail_priority_count += len(
                        self._thumbnail_batch_indices,
                    )
                return
        if self._presentation_pending:
            if (self._photos and not self._thumbnail_loaded
                    and (self._viewport_after_id is not None
                         or self._warm_thumbnail_pending
                         or self._thumbnail_disk_lane.busy())):
                return
            self._presentation_pending = False
            if self.session.can_read_presentation():
                self._launch(
                    'presentation', lambda: self.session.media_presentation(
                        self.gallery.gallery_id, cancelled=self._cancelled,
                    ),
                )
                return
        if revision_due:
            self._revision_initial_attempted = True
            self._launch(
                'revision', lambda: self.session.media_revision(
                    self.gallery.gallery_id, cancelled=self._cancelled,
                ),
            )
            return
        if (self._media_throttled or self._http_thumbnail_failure_latched
                or self._thumbnail_resume_after_id is not None):
            return
        if self._thumbnail_pending:
            self._thumbnail_cancelled.clear()
            self._load_next_thumbnail()
            if self._worker is not None:
                return
    def _image_memory_size(self, image: 'Image.Image') -> int:
        """Estimate the RGB storage retained by one prepared detail image.

        Args:
            image (Image.Image): Worker-prepared RGB detail image.

        Returns:
            int: Conservative storage estimate using four bytes per RGB pixel.
        """
        return image.width * image.height * 4
    def _remember_detail(
        self, image_id: int, image: 'Image.Image', encoded: bytes,
    ) -> None:
        """Retain prepared and encoded detail data within entry and byte budgets.

        Args:
            image_id (int): Gallery photo ID represented by the image.
            image (Image.Image): Independent worker-prepared RGB image.
            encoded (bytes): Bounded original body used for HDR rendering.

        Returns:
            None: Oldest images are released when either cache limit is reached.
        """
        if (type(image_id) is not int or image_id <= 0
                or Image is None or not isinstance(image, Image.Image)):
            if Image is not None and isinstance(image, Image.Image):
                image.close()
            return
        if not isinstance(encoded, bytes) or len(encoded) > NATIVE_MEDIA_MAX_BYTES:
            image.close()
            return
        photo = next(
            (item for item in self._photos if item.image_id == image_id), None,
        )
        private_candidate = bool(
            photo is not None and photo.cache_eligible is not True
            and (self.session.can_read_admin_media()
                 or photo.cache_eligible is False
                 or photo.preload_eligible is not None)
        )
        private_identity = (
            self.session.private_preload_cache_identity(
                self.gallery.gallery_id, photo,
            ) if (self.session.can_read_admin_media() and photo is not None
                  and private_candidate) else None
        )
        if (private_candidate and photo is not None
                and private_identity is None):
            previous = self._detail_cache.pop(image_id, None)
            if previous is not None:
                self._detail_cache_bytes -= previous[3]
                previous[1].close()
            image.close()
            return
        memory_size = self._image_memory_size(image) + len(encoded)
        if memory_size > MEDIA_DETAIL_CACHE_MAX_BYTES:
            image.close()
            return
        previous = self._detail_cache.pop(image_id, None)
        if previous is not None:
            self._detail_cache_bytes -= previous[3]
            previous[1].close()
        self._detail_cache[image_id] = (
            time.monotonic(), image, encoded, memory_size,
            ((private_identity[0], private_identity[1],
              private_identity[2], private_identity[3])
             if private_identity is not None else (None, None, None, None)),
        )
        self._detail_cache_bytes += memory_size
        while (len(self._detail_cache) > MEDIA_DETAIL_CACHE_ENTRY_LIMIT
               or self._detail_cache_bytes > MEDIA_DETAIL_CACHE_MAX_BYTES):
            _old_id, (_fetched_at, old_image, _old_encoded, old_size,
                      _old_proof) = (
                self._detail_cache.popitem(last=False)
            )
            self._detail_cache_bytes -= old_size
            old_image.close()
    def _cached_detail(self, image_id: int) -> 'Image.Image | None':
        """Return a fresh prepared detail and promote its LRU position.

        Args:
            image_id (int): Gallery photo ID requested by the current selection.

        Returns:
            Image.Image | None: Fresh RGB image, or None after absence/expiry.
        """
        entry = self._detail_cache.get(image_id)
        if entry is None:
            return None
        photo = next(
            (item for item in self._photos if item.image_id == image_id), None,
        )
        private_candidate = (
            photo.cache_eligible is not True
            and (self.session.can_read_admin_media()
                 or photo.cache_eligible is False
                 or photo.preload_eligible is not None)
        ) if photo is not None else False
        private_identity = (
            self.session.private_preload_cache_identity(
                self.gallery.gallery_id, photo,
            ) if (self.session.can_read_admin_media() and photo is not None
                  and private_candidate) else None
        )
        current_proof = (private_identity if private_identity is not None
                         else (None, None, None, None))
        if (private_candidate and photo is not None
                and (private_identity is None or entry[4] != current_proof)):
            del self._detail_cache[image_id]
            self._detail_cache_bytes -= entry[3]
            entry[1].close()
            return None
        fetched_at, image, _encoded, memory_size = entry[:4]
        if time.monotonic() - fetched_at > MEDIA_DETAIL_CACHE_FRESH_SECONDS:
            del self._detail_cache[image_id]
            self._detail_cache_bytes -= memory_size
            image.close()
            return None
        self._detail_cache.move_to_end(image_id)
        return image
    def _cached_detail_encoded(self, image_id: int) -> bytes | None:
        """Return the bounded source body paired with a fresh prepared detail.

        Args:
            image_id (int): Gallery photo ID requested by the current selection.
        Returns:
            bytes | None: Encoded response body, or None after absence/expiry.
        """
        if self._cached_detail(image_id) is None:
            return None
        entry = self._detail_cache.get(image_id)
        return entry[2] if entry is not None else None
    def _queue_adjacent_prefetch(self, index: int) -> None:
        """Queue adjacent album details with the current direction first.

        Args:
            index (int): Visible photo whose detail was just shown.

        Returns:
            None: At most two adjacent details, including page edges, are queued.
        """
        self._pending_prefetch_ids.clear()
        if not self.session.background_media_allowed():
            self._prefetch_edge_direction = 0
            return
        if (self._closed or self._lightbox is None
                or not self._lightbox.visible
                or self._pending_detail_index is not None
                or self._pending_page_request is not None):
            self._prefetch_edge_direction = 0
            return
        directions = (self._prefetch_direction,)
        if not self._slideshow_running:
            directions += (-self._prefetch_direction,)
        current_id = self._photos[index].image_id
        self._cached_detail(current_id)
        for direction in directions:
            target = index + direction
            if target < 0 or target >= len(self._photos):
                if self._next_after is None and len(self._photos) > 1:
                    target %= len(self._photos)
                elif target >= len(self._photos) and self._next_after is not None:
                    self._prefetch_edge_direction = 1
                    self._load_more()
                    continue
                elif target < 0 and self._next_after is not None:
                    self._prefetch_edge_direction = -1
                    self._load_more()
                    continue
                else:
                    continue
            if target == index:
                continue
            image_id = self._photos[target].image_id
            if self._cached_detail(image_id) is None:
                self._pending_prefetch_ids.append(image_id)
            if len(self._pending_prefetch_ids) >= MEDIA_DETAIL_PREFETCH_LIMIT:
                break
    def _load_next_prefetch(self) -> bool:
        """Fetch one still-needed adjacent image through the shared worker.

        Returns:
            bool: True when one prefetch worker started; false when none remain.
        """
        if (not self.session.background_media_allowed()
                or self._closed or self._lightbox is None
                or not self._lightbox.visible):
            self._pending_prefetch_ids.clear()
            self._prefetch_edge_direction = 0
            return False
        while self._pending_prefetch_ids:
            image_id = self._pending_prefetch_ids.pop(0)
            if self._cached_detail(image_id) is not None:
                continue
            photo = next(
                (item for item in self._photos if item.image_id == image_id),
                None,
            )
            if photo is None:
                continue

            operation_cancelled = threading.Event()
            self._active_media_cancelled = operation_cancelled
            self._active_media_photo_id = photo.image_id

            def operation() -> tuple[
                int, 'Image.Image', bytes | None,
            ]:
                """Read the adjacent image under its operation cancellation.

                Returns:
                    tuple[int, Image.Image, bytes | None]: Photo ID, prepared
                        raster and retained source, or None when only a safe
                        preview exists.
                """
                timings = MediaReadTimings()
                try:
                    image, body = self._read_prepared_detail(
                        photo, timings, cancelled=operation_cancelled,
                    )
                finally:
                    self._observe_detail_read('prefetch', timings)
                if operation_cancelled.is_set():
                    image.close()
                    raise LinkingError('Adjacent photo request was superseded.')
                private_descriptor = (
                    photo.cache_eligible is not True
                    and (self.session.can_read_admin_media()
                         or photo.cache_eligible is False
                         or photo.preload_eligible is not None)
                )
                if (private_descriptor and timings.source != 'disk'
                        and not timings.cache_stored):
                    body = None
                return photo.image_id, image, body

            started = self._launch(
                'prefetch', operation,
                error_payload=(image_id, None),
                operation_cancelled=operation_cancelled,
            )
            if (not started
                    and self._active_media_cancelled is operation_cancelled):
                self._active_media_cancelled = None
                self._active_media_photo_id = None
            return started
        return False
    def _show_thumbnail(
        self, index: int, data: 'Image.Image | None', *, remember: bool = True,
    ) -> None:
        """Attach one completed thumbnail on Tk's owning thread.

        Args:
            index (int): Zero-based photo index represented by the result.
            data (Image.Image | None): Prepared thumbnail, or None on read failure.
            remember (bool): Store freshly decoded bytes, or reuse the existing
                entry without extending its validation time.

        Returns:
            None: The admitted band retains a bounded image or placeholder;
                the next coalesced pass reconciles changed widget bounds.
        """
        if index in self._thumbnail_pending:
            self._thumbnail_pending.remove(index)
        if index >= len(self._tile_buttons):
            if data is not None:
                data.close()
            return
        if index not in self._admitted_thumbnail_indices:
            if data is not None:
                data.close()
            return
        presented_on_tk = False
        if data is not None and ImageTk is not None:
            image: object | None = None
            presented: 'Image.Image | None' = None
            try:
                presented = self._present_thumbnail(data)
                image = ImageTk.PhotoImage(presented, master=self.frame)
                self._thumbnail_image_create_count += 1
                if remember:
                    self._remember_thumbnail(self._photos[index], data.copy())
                previous = self._canonical_thumbnail_images.pop(index, None)
                if previous is not None:
                    previous.close()
                self._canonical_thumbnail_images[index] = data.copy()
                data.close()
                if self._photo_images[index] is not None:
                    self._thumbnail_image_release_count += 1
                self._photo_images[index] = image
                self._tile_buttons[index].configure(
                    image=image, text='', compound='image',
                    style='LinkedPhoto.TButton',
                )
                presented_on_tk = True
            except Exception:
                if image is not None:
                    self._thumbnail_image_release_count += 1
                    if self._photo_images[index] is image:
                        self._photo_images[index] = None
                data.close()
                data = None
            finally:
                if presented is not None:
                    presented.close()
        if data is None or ImageTk is None:
            if data is not None:
                data.close()
            self._tile_buttons[index].configure(
                image='', text=MEDIA_THUMBNAIL_PLACEHOLDER, compound='none',
                style='Placeholder.LinkedPhoto.TButton',
            )
            if index < len(self._tile_tooltips):
                self._tile_tooltips[index].set(
                    f"{self._text('linking_media_thumbnail_unavailable')}\n"
                    f"{self._tile_titles[index]}"
                )
        self._thumbnail_loaded.add(index)
        self._render_photo_selection((index,))
        if (presented_on_tk and not self._first_thumbnail_reported
                and index in self._visible_thumbnail_indices
                and self.frame.winfo_viewable()):
            self._first_thumbnail_reported = True
            callback = self.on_first_thumbnail_presented
            if callback is not None:
                try:
                    callback()
                except Exception:
                    # Diagnostics cannot interrupt genuine image display.
                    NATIVE_GALLERY_VIEWER_LOGGER.warning(
                        'First visible thumbnail diagnostic callback failed.',
                    )
        if presented_on_tk and index in self._visible_thumbnail_indices:
            indicator = getattr(self, '_load_progress', None)
            if indicator is not None and indicator.active:
                indicator.thumbnails(
                    sum(
                        visible_index < len(self._photo_images)
                        and self._photo_images[visible_index] is not None
                        for visible_index in self._visible_thumbnail_indices
                    ),
                    len(self._visible_thumbnail_indices),
                )
        # A natural-aspect image can be shorter than its placeholder inside a
        # fixed row. The frame extent then stays unchanged, so its Configure
        # event cannot repair visible indices at the edge of the viewport.
        self._on_grid_viewport(None)
    def _thumbnail_memory_key(
        self, photo: LinkedPhoto,
    ) -> tuple[int, int, bool | None, str | None, int | None] | None:
        """Authorize thumbnail reuse and identify its unchanged content.

        Args:
            photo (LinkedPhoto): Current server-authorized photo descriptor.

        Returns:
            tuple[int, int, bool | None, str | None, int | None] | None:
                Gallery, photo, public eligibility, validator and private
                account, or None when the live session denies reuse. Proof
                timestamps and epochs authorize access, not pixel identity.
        """
        if not self.session.can_reuse_cached_media():
            return None
        private_candidate = (
            photo.cache_eligible is not True
            and (self.session.can_read_admin_media()
                 or photo.cache_eligible is False
                 or photo.preload_eligible is not None)
        )
        private_identity = (
            self.session.private_preload_cache_identity(
                self.gallery.gallery_id, photo, thumbnail=True,
            ) if self.session.can_read_admin_media()
            and private_candidate else None
        )
        if private_candidate and private_identity is None:
            return None
        validator = (
            private_identity[1] if private_identity is not None
            else getattr(photo, 'thumbnail_etag', None)
        )
        if (private_identity is None and validator is None
                and photo.thumbnail_verified_at is not None):
            # The metadata epoch is an identity fence, not a content ETag.
            # Never reuse decoded pixels across independently refreshed pages
            # whose fallback-original bytes remain unproven.
            validator = 'page:' + repr(photo.thumbnail_verified_at)
        return (
            self.gallery.gallery_id, photo.image_id,
            getattr(photo, 'cache_eligible', None),
            validator,
            private_identity[0] if private_identity is not None else None,
        )
    def _cached_thumbnail(
        self,
        photo: LinkedPhoto,
        *,
        expected_key: (
            tuple[int, int, bool | None, str | None, int | None] | None
        ) = None,
    ) -> 'Image.Image | None':
        """Return unchanged decoded pixels only under current session authority.

        Args:
            photo (LinkedPhoto): Current server-authorized photo descriptor.
            expected_key (tuple[int, int, bool | None, str | None, int | None] | None):
                Key observed before the bounded Tk callback, or None when no
                cache identity was available.
        Returns:
            Image.Image | None: Independent raster copy, or None after a miss,
                public freshness expiry, or refusal by the live private proof.
        """
        if not self.session.can_read_media():
            return None
        key = self._thumbnail_memory_key(photo)
        if key is None or (expected_key is not None and key != expected_key):
            return None
        with self._thumbnail_memory.lock:
            cached = self._thumbnail_memory.entries.get(key)
            if cached is None:
                self._thumbnail_memory.misses += 1
                return None
            image, _size, stored_at = cached
            verified_at = photo.thumbnail_verified_at
            descriptor_current = (
                key[4] is not None
                or key[3] is not None and verified_at is not None
                and 0 <= time.monotonic() - verified_at
                < MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS
            )
            if (not descriptor_current and time.monotonic() - stored_at
                    > MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS):
                self._thumbnail_memory.entries.pop(key, None)
                self._thumbnail_memory.size_bytes -= _size
                if self._thumbnail_memory.identity_keys.get(key[:2]) == key:
                    self._thumbnail_memory.identity_keys.pop(key[:2], None)
                self._thumbnail_memory.expirations += 1
                self._thumbnail_memory.misses += 1
                image.close()
                return None
            self._thumbnail_memory.entries.move_to_end(key)
            self._thumbnail_memory.hits += 1
            return image.copy()
    def _remember_thumbnail(self, photo: LinkedPhoto, image: 'Image.Image') -> None:
        """Retain a bounded decoded thumbnail across same-session viewer mounts.

        Args:
            photo (LinkedPhoto): Current server-authorized photo descriptor.
            image (Image.Image): Independent RGB thumbnail copy to retain.
        Returns:
            None: Visible rasters lead eviction priority. Speculative overscan
                cannot evict history; all retained pixels obey the byte bound.
        """
        key = self._thumbnail_memory_key(photo)
        if key is None:
            image.close()
            with self._thumbnail_memory.lock:
                self._thumbnail_memory.admission_refusals += 1
            return
        # Pillow RGB is accounted at four bytes per pixel, conservatively
        # including alignment rather than relying on compressed source bytes.
        size = self._image_memory_size(image)
        if size > MEDIA_THUMBNAIL_CACHE_MAX_BYTES:
            image.close()
            with self._thumbnail_memory.lock:
                self._thumbnail_memory.admission_refusals += 1
            return
        memory = self._thumbnail_memory
        with memory.lock:
            identity = key[:2]
            old_key = memory.identity_keys.get(identity)
            if old_key is not None and old_key != key:
                old = memory.entries.pop(old_key, None)
                memory.identity_keys.pop(identity, None)
                if old is not None:
                    old_image, old_size, _stored_at = old
                    memory.size_bytes -= old_size
                    memory.validator_changes += 1
                    old_image.close()
            visible_keys: set[
                tuple[int, int, bool | None, str | None, int | None]
            ] = set()
            for index in self._visible_thumbnail_indices:
                if index >= len(self._photos):
                    continue
                visible_key = self._thumbnail_memory_key(self._photos[index])
                if visible_key is not None:
                    visible_keys.add(visible_key)
            speculative = key not in visible_keys and bool(visible_keys)
            previous_size = memory.entries[key][1] if key in memory.entries else 0
            if speculative and (
                (key not in memory.entries
                 and len(memory.entries) >= MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT)
                or memory.size_bytes - previous_size + size
                > MEDIA_THUMBNAIL_CACHE_MAX_BYTES
            ):
                memory.admission_refusals += 1
                image.close()
                return
            previous = memory.entries.pop(key, None)
            if previous is not None:
                memory.size_bytes -= previous[1]
                previous[0].close()
            memory.entries[key] = (image, size, time.monotonic())
            memory.identity_keys[identity] = key
            memory.size_bytes += size
            while (len(memory.entries) > MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT
                   or memory.size_bytes > MEDIA_THUMBNAIL_CACHE_MAX_BYTES):
                victim = next(
                    (candidate for candidate in memory.entries
                     if candidate not in visible_keys),
                    next(iter(memory.entries)),
                )
                evicted, evicted_size, _stored_at = memory.entries.pop(victim)
                memory.size_bytes -= evicted_size
                if memory.identity_keys.get(victim[:2]) == victim:
                    memory.identity_keys.pop(victim[:2], None)
                memory.evictions += 1
                evicted.close()
    def _select_photo(self, index: int, *, keep_slideshow: bool = False) -> None:
        """Open one selected tile as the current full-size detail.

        Args:
            index (int): Zero-based photo index in the current server page.
            keep_slideshow (bool): Whether an automatic page crossing owns it.

        Returns:
            None: The selected image becomes the foreground detail request.
        """
        if not keep_slideshow:
            self._stop_slideshow()
        self._wrap_after_metadata = 0
        self._select_first_after_page = False
        self._load_detail(index)
    def _read_photo_body(
        self, photo: LinkedPhoto, *, thumbnail: bool,
        timings: MediaReadTimings | None = None,
        cancelled: threading.Event | None = None,
    ) -> tuple[bytes, str]:
        """Read a thumbnail or saved full version through the session gates.

        Args:
            photo (LinkedPhoto): Current gallery descriptor and cache metadata.
            thumbnail (bool): Whether to request the server's bounded preview.
            timings (MediaReadTimings | None): Optional cache-admission witness.
            cancelled (threading.Event | None): Current detail operation owner.

        Returns:
            tuple[bytes, str]: Authorized body and validated media type.
        """
        return self.session.media_photo(
            self.gallery.gallery_id,
            photo.image_id,
            thumbnail=thumbnail,
            cancelled=(
                cancelled if cancelled is not None else self._cancelled
            ),
            cache_eligible=getattr(photo, 'cache_eligible', None),
            thumbnail_etag=getattr(photo, 'thumbnail_etag', None),
            thumbnail_verified_at=getattr(
                photo, 'thumbnail_verified_at', None,
            ),
            preload_eligible=getattr(photo, 'preload_eligible', None),
            preload_thumbnail_etag=getattr(
                photo, 'preload_thumbnail_etag', None,
            ),
            preload_verified_at=getattr(
                photo, 'preload_verified_at', None,
            ),
            timings=timings,
            use_preloaded_snapshot=not thumbnail,
        )
    def _read_prepared_detail(
        self, photo: LinkedPhoto,
        timings: MediaReadTimings | None = None,
        cancelled: threading.Event | None = None,
    ) -> tuple['Image.Image', bytes | None]:
        """Read and decode a selected detail, using one safe preview fallback.

        Args:
            photo (LinkedPhoto): Exact photo selected or prefetched in this view.
            timings (MediaReadTimings | None): Optional cache-admission witness.
            cancelled (threading.Event | None): Current detail operation owner.

        Returns:
            tuple[Image.Image, bytes | None]: Bounded image plus original bytes;
                preview fallback returns None so it is never cached as a detail.

        Raises:
            PhotosDecodeError: The original and any permitted preview cannot be
                reduced inside the existing decoder limits.
            LinkingError: Existing authorization, transport or cancellation gates fail.
        """
        operation_cancelled = (
            cancelled if cancelled is not None else self._cancelled
        )
        body, _mime = self._read_photo_body(
            photo, thumbnail=False, timings=timings,
            cancelled=operation_cancelled,
        )
        if operation_cancelled.is_set():
            raise LinkingError('Detail request was cancelled.')
        try:
            image = self._prepare_detail(body)
            if operation_cancelled.is_set():
                image.close()
                raise LinkingError('Detail request was cancelled.')
            return image, body
        except PhotosDecodeError as error:
            facts = error.facts
            if (facts.reason != 'pixel-limit'
                    or operation_cancelled.is_set()):
                raise
        if operation_cancelled.is_set():
            raise LinkingError('Detail request was cancelled.')
        _log_safe_photos_observation(
            format_safe_photos_decode_facts(facts),
            self._observation_callback,
        )
        try:
            preview_body, _preview_mime = self._read_photo_body(
                photo, thumbnail=True, timings=timings,
                cancelled=operation_cancelled,
            )
            preview = self._prepare_detail(preview_body)
            if operation_cancelled.is_set():
                preview.close()
                raise LinkingError('Detail request was cancelled.')
        except Exception:
            if operation_cancelled.is_set():
                raise LinkingError('Detail request was cancelled.') from None
            _log_safe_photos_observation(
                format_safe_photos_decode_facts(PhotosDecodeFacts(
                    'thumbnail-fallback', 'preview-fallback',
                    facts.image_format, facts.source_size, (0, 0), len(body),
                    MEDIA_IMAGE_MAX_PIXELS, MEDIA_IMAGE_MAX_SIZE,
                )),
                self._observation_callback,
            )
            raise
        if operation_cancelled.is_set():
            preview.close()
            raise LinkingError('Detail request was cancelled.')
        _log_safe_photos_observation(
            format_safe_photos_decode_facts(PhotosDecodeFacts(
                'thumbnail-fallback', 'preview-fallback',
                facts.image_format, facts.source_size, preview.size,
                len(body), MEDIA_IMAGE_MAX_PIXELS, MEDIA_IMAGE_MAX_SIZE,
            )),
            self._observation_callback,
        )
        return preview, None
