# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_native_gallery_canvas.py
# Module Type: Windows Tk Regression Test
# Purpose: Exercise the opt-in Canvas renderer in the linked-gallery viewer.
# Responsibilities:
#   - Verify logical keyboard focus and selection across reordered photos.
#   - Verify refresh identity, paging, viewport admission and root cleanup.
#   - Verify shared decoded reuse while Tk images remain viewer-scoped.
#   - Verify preview-failure feedback, live access revocation and context targets.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Windows-only integration coverage for Canvas-backed gallery rendering."""

import unittest

import test_native_gallery_viewer as viewer_tests


# Purpose: Reuse the original fixture vocabulary without importing its test class.
# Type: module aliases. Units: existing fixture names. Scope: moved Canvas tests.
# Consumers: Canvas test bodies below. Rationale: keep test logic unchanged while
# resolving its source-module globals in this independent unittest module.
threading = viewer_tests.threading
mock = viewer_tests.mock
native_gallery_viewer = viewer_tests.native_gallery_viewer
tk = viewer_tests.tk
LinkedGallery = viewer_tests.LinkedGallery
LinkedMediaPage = viewer_tests.LinkedMediaPage
LinkedPhoto = viewer_tests.LinkedPhoto
MediaReadTimings = viewer_tests.MediaReadTimings

# Purpose: Reuse the existing numeric fixture policies used by moved test bodies.
# Type: int | float aliases. Units: inherited from each source constant.
# Scope: Canvas integration tests. Consumers: moved test bodies below.
# Rationale: retain one source of truth for synthetic IDs, counts and deadlines.
TEST_GALLERY_ID = viewer_tests.TEST_GALLERY_ID
TEST_GUI_WAIT_SECONDS = viewer_tests.TEST_GUI_WAIT_SECONDS
TEST_PARENT_GALLERY_ID = viewer_tests.TEST_PARENT_GALLERY_ID
TEST_WORKER_JOIN_SECONDS = viewer_tests.TEST_WORKER_JOIN_SECONDS
TEST_NEXT_PAGE_IMAGE_ID = viewer_tests.TEST_NEXT_PAGE_IMAGE_ID
TEST_CANVAS_GRID_PHOTO_COUNT = viewer_tests.TEST_CANVAS_GRID_PHOTO_COUNT
TEST_SECOND_IMAGE_ID = viewer_tests.TEST_SECOND_IMAGE_ID
TEST_CANVAS_VIEWPORT_PHOTO_COUNT = viewer_tests.TEST_CANVAS_VIEWPORT_PHOTO_COUNT
TEST_FIRST_IMAGE_ID = viewer_tests.TEST_FIRST_IMAGE_ID
TEST_CANVAS_WHEEL_BURST_COUNT = viewer_tests.TEST_CANVAS_WHEEL_BURST_COUNT
TEST_WHEEL_DELTA_UNITS = viewer_tests.TEST_WHEEL_DELTA_UNITS


@unittest.skipUnless(
    viewer_tests.sys.platform == 'win32'
    and viewer_tests.tk is not None
    and viewer_tests.Image is not None
    and viewer_tests.ImageTk is not None,
    'The Canvas viewer regression requires Windows, Tk and Pillow.',
)
class NativeGalleryCanvasIntegrationTests(unittest.TestCase):
    """Verify Canvas integration against the existing real-Tk viewer fixture."""

    # Reuse only fixture lifecycle and builders; do not inherit native test cases.
    setUp = viewer_tests.NativeGalleryViewerTests.setUp
    _destroy_root = viewer_tests.NativeGalleryViewerTests._destroy_root
    _wait_until = viewer_tests.NativeGalleryViewerTests._wait_until
    _png = viewer_tests.NativeGalleryViewerTests._png
    _gallery_data = viewer_tests.NativeGalleryViewerTests._gallery_data
    _session = viewer_tests.NativeGalleryViewerTests._session

    def _open_viewer(
        self,
        session: viewer_tests.LinkingSession,
        gallery: LinkedGallery,
        thumbnail_memory: (
            native_gallery_viewer.GalleryThumbnailMemory | None
        ) = None,
        canvas_grid_enabled: bool | None = None,
        newest_first: bool = False,
    ) -> native_gallery_viewer._NativeGalleryViewer:
        """Mount a viewer with Canvas factory-default semantics for this suite.

        Args:
            session (LinkingSession): Autospecced media session for the case.
            gallery (LinkedGallery): Exact gallery displayed by the viewer.
            thumbnail_memory (GalleryThumbnailMemory | None): Optional shared
                session cache reused across viewer mounts.
            canvas_grid_enabled (bool | None): Explicit resolved choice, or None
                to use the configured factory default and environment.
            newest_first (bool): Use the real viewer's local reverse order.
        Returns:
            _NativeGalleryViewer: Controller mounted in this test's real root.
        """
        return viewer_tests.NativeGalleryViewerTests._open_viewer(
            self, session, gallery, thumbnail_memory, canvas_grid_enabled,
            newest_first,
        )

    _assert_no_browsing_toplevels = (
        viewer_tests.NativeGalleryViewerTests._assert_no_browsing_toplevels
    )

    def test_canvas_parent_destroy_cancels_pending_read(self) -> None:
        """Cancel a Canvas-owned media read when the root is destroyed.

        Returns:
            None: Tk references release before the cancelled worker is joined.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        worker_started = threading.Event()

        def wait_for_cancel(
            gallery_id: int,
            after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> LinkedMediaPage:
            """Hold the first page read until the viewer cancels it.

            Args:
                gallery_id (int): Gallery requested by the viewer.
                after_id (int | None): First-page cursor, expected to be None.
                cancelled (threading.Event | None): Viewer-owned cancellation.

            Returns:
                LinkedMediaPage: Synthetic page returned after observing cancel.
            """
            if gallery_id != TEST_GALLERY_ID or after_id is not None:
                raise ValueError('Unexpected page request in cancellation fixture')
            worker_started.set()
            if cancelled is None or not cancelled.wait(TEST_GUI_WAIT_SECONDS):
                raise TimeoutError('Viewer did not cancel its page read')
            return page

        session.media_page.side_effect = wait_for_cancel
        gallery = LinkedGallery(
            TEST_GALLERY_ID,
            TEST_PARENT_GALLERY_ID,
            'Pending Canvas viewer',
        )
        with mock.patch.dict(
            native_gallery_viewer.os.environ,
            {'PHP_GALLERY_CANVAS_GRID': '1'},
        ):
            viewer = self._open_viewer(session, gallery)
        self._wait_until(
            worker_started.is_set,
            'The synthetic page worker did not start.',
        )
        old_generation = viewer._generation
        self.root.destroy()
        self.assertTrue(viewer._closed)
        self.assertIsNotNone(viewer._canvas_grid)
        self.assertTrue(viewer._canvas_grid._closed)
        self.assertFalse(hasattr(viewer._canvas_grid, '_tooltip_message'))
        self.assertFalse(hasattr(viewer._canvas_grid, '_placeholder_font'))
        self.assertIsNone(viewer._canvas_grid._tooltip_refresh)
        self.assertTrue(viewer._cancelled.is_set())
        self.assertGreater(viewer._generation, old_generation)
        self.assertIsNotNone(viewer._worker)
        viewer._worker.join(TEST_WORKER_JOIN_SECONDS)
        self.assertFalse(viewer._worker.is_alive())
        self.assertEqual(1, session.media_page.call_count)
        self.assertTrue(viewer._events.empty())

    def test_canvas_opt_in_keeps_keyboard_identity_and_reordered_images(self) -> None:
        """Route keyboard actions and refreshes through logical Canvas tiles.

        Returns:
            None: Opt-in focus, selection, stale-proxy fencing and image reuse
                retain the native viewer's exact photo identities.
        """
        page, presentation, image_bytes = self._gallery_data()
        third = LinkedPhoto(TEST_NEXT_PAGE_IMAGE_ID, 'Third synthetic photo')
        page = LinkedMediaPage(
            page.gallery_id, page.parent_id, page.photos + (third,), None,
        )
        image_bytes[third.image_id] = self._png((80, 95, 210))
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        with mock.patch.dict(
            native_gallery_viewer.os.environ,
            {'PHP_GALLERY_CANVAS_GRID': '1'},
        ):
            viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: len(viewer._photo_images) == TEST_CANVAS_GRID_PHOTO_COUNT
            and all(viewer._photo_images) and viewer._worker is None,
            'Opt-in Canvas thumbnails did not settle.',
        )
        self.assertIsNotNone(viewer._canvas_grid)
        self.assertIsNone(viewer.grid_window)
        self.assertTrue(int(viewer.grid_canvas.cget('takefocus')))
        viewer.grid_canvas.focus_force()
        self.root.update()
        self.assertEqual(0, viewer._canvas_grid.focused_index)
        images_by_id = {
            photo.image_id: viewer._photo_images[index]
            for index, photo in enumerate(viewer._photos)
        }
        stale_proxy = viewer._tile_buttons[0]

        self.assertTrue(viewer.select_photo(0))
        viewer.grid_canvas.event_generate('<KeyPress-Right>')
        self.root.update()
        self.assertEqual((TEST_SECOND_IMAGE_ID,), viewer.selected_photo_ids())
        self.assertEqual(1, viewer._canvas_grid.focused_index)
        viewer.grid_canvas.event_generate('<KeyPress-space>')
        self.root.update()
        self.assertEqual((), viewer.selected_photo_ids())

        self.assertTrue(viewer.select_photo(0))
        viewer._canvas_grid.focus(0, ensure_visible=False)
        self.root.update()
        reordered = tuple(reversed(viewer._photos))
        focused_photo_id = viewer._photos[0].image_id
        session.media_page.return_value = LinkedMediaPage(
            page.gallery_id, page.parent_id, reordered, None,
        )
        viewer.refresh_photos()
        self._wait_until(
            lambda: viewer._worker is None
            and tuple(photo.image_id for photo in viewer._photos)
            == tuple(photo.image_id for photo in reordered),
            'Canvas metadata reorder did not settle.',
        )
        self.assertEqual(
            tuple(images_by_id[photo.image_id] for photo in reordered),
            tuple(viewer._photo_images),
        )
        self.assertEqual(2, viewer._canvas_grid.focused_index)
        self.assertEqual(
            focused_photo_id,
            viewer._photos[viewer._canvas_grid.focused_index].image_id,
        )
        self.assertEqual((focused_photo_id,), viewer.selected_photo_ids())
        self.assertNotEqual(stale_proxy, viewer._tile_buttons[0])
        with self.assertRaises(tk.TclError):
            stale_proxy.cget('image')
        self.root.update()
        for index, photo in enumerate(reordered):
            self.assertIs(
                images_by_id[photo.image_id],
                viewer._tile_buttons[index].cget('image'),
            )

    def test_canvas_opt_in_restores_focus_by_id_across_insert_and_delete(self) -> None:
        """Keep keyboard identity stable across non-symmetric metadata changes.

        Returns:
            None: Focus follows an inserted photo by ID and falls back to a
                valid nearest index after that photo is removed.
        """
        page, presentation, image_bytes = self._gallery_data()
        inserted = LinkedPhoto(TEST_NEXT_PAGE_IMAGE_ID + 1, 'Inserted photo')
        image_bytes[inserted.image_id] = self._png((80, 125, 90))
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        with mock.patch.dict(
            native_gallery_viewer.os.environ,
            {'PHP_GALLERY_CANVAS_GRID': '1'},
        ):
            viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: len(viewer._photos) == len(page.photos)
            and viewer._worker is None,
            'Opt-in Canvas first page did not settle.',
        )
        canvas_grid = viewer._canvas_grid
        self.assertIsNotNone(canvas_grid)
        viewer.grid_canvas.focus_force()
        self.root.update()
        viewer._canvas_grid.focus(1, force=False, ensure_visible=False)
        self.assertTrue(viewer.select_photo(1))
        focused_id = viewer._photos[1].image_id

        inserted_page = LinkedMediaPage(
            page.gallery_id, page.parent_id, (inserted,) + page.photos, None,
        )
        session.media_page.return_value = inserted_page
        viewer.refresh_photos()
        self._wait_until(
            lambda: viewer._worker is None
            and tuple(photo.image_id for photo in viewer._photos)
            == tuple(photo.image_id for photo in inserted_page.photos),
            'Canvas insertion refresh did not settle.',
        )
        self.assertEqual(2, canvas_grid.focused_index)
        self.assertEqual(
            focused_id,
            viewer._photos[canvas_grid.focused_index].image_id,
        )
        self.assertEqual((focused_id,), viewer.selected_photo_ids())

        deleted_page = LinkedMediaPage(
            page.gallery_id, page.parent_id, (inserted, page.photos[0]), None,
        )
        session.media_page.return_value = deleted_page
        viewer.refresh_photos()
        self._wait_until(
            lambda: viewer._worker is None
            and tuple(photo.image_id for photo in viewer._photos)
            == tuple(photo.image_id for photo in deleted_page.photos),
            'Canvas deletion refresh did not settle.',
        )
        self.assertEqual(1, canvas_grid.focused_index)
        self.assertEqual(
            deleted_page.photos[1].image_id,
            viewer._photos[canvas_grid.focused_index].image_id,
        )
        self.assertEqual((), viewer.selected_photo_ids())

    def test_canvas_opt_in_pages_and_coalesces_rapid_viewport_scroll(self) -> None:
        """Exercise real page continuation and fast Canvas wheel admission.

        Returns:
            None: Page IDs and selection stay stable while the bounded Canvas
                pool follows repeated viewport changes.
        """
        page, presentation, _image_bytes = self._gallery_data()
        photo_count = TEST_CANVAS_VIEWPORT_PHOTO_COUNT
        photos = tuple(
            LinkedPhoto(TEST_FIRST_IMAGE_ID + index, f'Canvas page {index + 1}')
            for index in range(photo_count)
        )
        image_bytes = {
            photo.image_id: self._png((index % 240, 70, 160))
            for index, photo in enumerate(photos)
        }
        first_page = LinkedMediaPage(
            page.gallery_id,
            page.parent_id,
            photos[:2],
            photos[1].image_id,
        )
        continuation = LinkedMediaPage(
            page.gallery_id, page.parent_id, photos[2:], None,
        )
        session = self._session(first_page, presentation, image_bytes)

        def read_page(
            gallery_id: int,
            after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> LinkedMediaPage:
            """Return the fixture page for its exact continuation cursor.

            Args:
                gallery_id (int): Requested physical gallery identity.
                after_id (int | None): Exclusive cursor for page continuation.
                cancelled (threading.Event | None): Viewer cancellation signal.

            Returns:
                LinkedMediaPage: First page or its deterministic continuation.

            Raises:
                ValueError: The viewer asks for a different cursor or gallery.
                RuntimeError: The captured request was cancelled.
            """
            if cancelled is not None and cancelled.is_set():
                raise RuntimeError('Synthetic Canvas page request cancelled')
            if gallery_id != TEST_GALLERY_ID:
                raise ValueError('Unexpected Canvas gallery request')
            if after_id is None:
                return first_page
            if after_id == photos[1].image_id:
                return continuation
            raise ValueError('Unexpected Canvas continuation cursor')

        session.media_page.side_effect = read_page
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        with mock.patch.dict(
            native_gallery_viewer.os.environ,
            {'PHP_GALLERY_CANVAS_GRID': '1'},
        ):
            viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: len(viewer._photos) == photo_count
            and viewer._worker is None,
            'Opt-in Canvas pages did not settle automatically.',
        )
        requested_cursors = []
        for request in session.media_page.call_args_list:
            gallery_id = (
                request.args[0] if request.args
                else request.kwargs.get('gallery_id')
            )
            cursor = (
                request.args[1] if len(request.args) > 1
                else request.kwargs.get('after_id')
            )
            if gallery_id == TEST_GALLERY_ID:
                requested_cursors.append(cursor)
        self.assertIn(None, requested_cursors)
        self.assertIn(photos[1].image_id, requested_cursors)
        self.assertEqual(
            tuple(photo.image_id for photo in photos),
            tuple(photo.image_id for photo in viewer._photos),
        )
        canvas = viewer.grid_canvas
        before = canvas.yview()[0]
        for _index in range(TEST_CANVAS_WHEEL_BURST_COUNT):
            canvas.event_generate(
                '<MouseWheel>',
                delta=-TEST_WHEEL_DELTA_UNITS,
                x=12,
                y=12,
                rootx=canvas.winfo_rootx() + 12,
                rooty=canvas.winfo_rooty() + 12,
            )
            self.root.update()
        self._wait_until(
            lambda: viewer._viewport_after_id is None
            and set(viewer._canvas_grid.active_indices)
            == viewer._admitted_thumbnail_indices,
            'Canvas viewport admission did not settle after wheel input.',
        )
        self.assertGreater(canvas.yview()[0], before)
        self.assertLess(
            len(viewer._canvas_grid.active_indices), photo_count,
        )
        self.assertTrue(viewer.select_photo(0))
        self.assertEqual((photos[0].image_id,), viewer.selected_photo_ids())
        self.assertEqual(
            set(viewer._canvas_grid.active_indices),
            viewer._admitted_thumbnail_indices,
        )
        canvas.event_generate('<KeyPress-End>')
        self.root.update()
        focused_index = viewer._canvas_grid.focused_index
        self.assertEqual(
            photos[-1].image_id,
            viewer._photos[focused_index].image_id,
        )
        self.assertGreater(canvas.yview()[0], 0.0)
        self.assertIn(focused_index, viewer._visible_photo_indices())

        canvas.event_generate('<KeyPress-Home>')
        self.root.update()
        focused_index = viewer._canvas_grid.focused_index
        self.assertEqual(
            photos[0].image_id,
            viewer._photos[focused_index].image_id,
        )
        self.assertIn(focused_index, viewer._visible_photo_indices())

        canvas.event_generate('<KeyPress-Next>')
        self.root.update()
        focused_index = viewer._canvas_grid.focused_index
        self.assertGreater(focused_index, 0)
        self.assertIn(focused_index, viewer._visible_photo_indices())

        canvas.event_generate('<KeyPress-Prior>')
        self.root.update()
        focused_index = viewer._canvas_grid.focused_index
        self.assertEqual(
            photos[0].image_id,
            viewer._photos[focused_index].image_id,
        )
        self.assertIn(focused_index, viewer._visible_photo_indices())

        self.assertTrue(viewer.select_photo(0))
        self.assertTrue(viewer.select_photo_range(2))
        selected_before_control_home = viewer.selected_photo_ids()
        canvas.event_generate('<Control-KeyPress-Home>')
        self.root.update()
        self.assertEqual(
            selected_before_control_home,
            viewer.selected_photo_ids(),
        )
        focused_index = viewer._canvas_grid.focused_index
        self.assertEqual(
            photos[0].image_id,
            viewer._photos[focused_index].image_id,
        )

        canvas.event_generate('<Shift-KeyPress-End>')
        self.root.update()
        selected_after_shift_end = viewer.selected_photo_ids()
        self.assertEqual(len(viewer._photos), len(selected_after_shift_end))
        self.assertEqual(
            {photo.image_id for photo in viewer._photos},
            set(selected_after_shift_end),
        )
        focused_index = viewer._canvas_grid.focused_index
        self.assertEqual(
            photos[-1].image_id,
            viewer._photos[focused_index].image_id,
        )

    def test_canvas_thumbnail_transport_failure_shows_photo_placeholder(self) -> None:
        """Render a failed authorized thumbnail as the current photo placeholder.

        Returns:
            None: The Canvas tile exposes its unavailable state and tooltip while
                successful sibling thumbnails still render normally.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        original_read = session.media_photo.side_effect
        self.assertTrue(callable(original_read))

        def read_photo_with_failure(
            gallery_id: int,
            image_id: int,
            thumbnail: bool,
            cancelled: threading.Event | None = None,
            *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: MediaReadTimings | None = None,
            use_preloaded_snapshot: bool = False,
        ) -> tuple[bytes, str]:
            """Fail one preview and delegate all other media reads unchanged.

            Args:
                gallery_id (int): Requested physical gallery ID.
                image_id (int): Requested image ID.
                thumbnail (bool): Whether this is the grid preview request.
                cancelled (threading.Event | None): Viewer cancellation signal.
                cache_eligible (bool | None): Public cache admission policy.
                thumbnail_etag (str | None): Current rendition validator.
                thumbnail_verified_at (float | None): Public preview proof time.
                preload_eligible (bool | None): Admin preview admission policy.
                preload_thumbnail_etag (str | None): Admin preview validator.
                preload_verified_at (float | None): Admin proof time.
                timings (MediaReadTimings | None): Mutable read timing facts.
                use_preloaded_snapshot (bool): Whether a verified saved full image
                    may satisfy this caller's authorized read.

            Returns:
                tuple[bytes, str]: Synthetic PNG body and media type for every
                    request except the deliberately unavailable preview.

            Raises:
                OSError: The first photo's preview is unavailable in this fixture.
            """
            if thumbnail and image_id == page.photos[0].image_id:
                raise OSError('Synthetic thumbnail transport unavailable')
            return original_read(
                gallery_id,
                image_id,
                thumbnail,
                cancelled,
                cache_eligible=cache_eligible,
                thumbnail_etag=thumbnail_etag,
                thumbnail_verified_at=thumbnail_verified_at,
                preload_eligible=preload_eligible,
                preload_thumbnail_etag=preload_thumbnail_etag,
                preload_verified_at=preload_verified_at,
                timings=timings,
                use_preloaded_snapshot=use_preloaded_snapshot,
            )

        session.media_photo.side_effect = read_photo_with_failure
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        with mock.patch.dict(
            native_gallery_viewer.os.environ,
            {'PHP_GALLERY_CANVAS_GRID': '1'},
        ):
            viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        expected_loaded = set(range(len(page.photos)))
        self._wait_until(
            lambda: viewer._worker is None
            and expected_loaded.issubset(viewer._thumbnail_loaded),
            'Canvas thumbnail success and failure results did not settle.',
        )

        failed_tile = viewer._tile_buttons[0]
        self.assertIsNone(viewer._photo_images[0])
        self.assertEqual(
            native_gallery_viewer.MEDIA_THUMBNAIL_PLACEHOLDER,
            failed_tile.cget('text'),
        )
        self.assertEqual(
            viewer._text('linking_media_thumbnail_unavailable')
            + '\n' + page.photos[0].title,
            viewer._tile_tooltips[0].get(),
        )
        self.assertIsNotNone(viewer._photo_images[1])
        failed_requests = [
            request for request in session.media_photo.call_args_list
            if len(request.args) > 1
            and request.args[1] == page.photos[0].image_id
            and request.kwargs.get('thumbnail') is True
        ]
        self.assertTrue(failed_requests)

    def test_canvas_refresh_closes_when_media_access_is_revoked(self) -> None:
        """Stop refresh before any request after live session access is revoked.

        Returns:
            None: The real viewer launch guard closes the Canvas and cancels its
                owned work without issuing another media page request.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        with mock.patch.dict(
            native_gallery_viewer.os.environ,
            {'PHP_GALLERY_CANVAS_GRID': '1'},
        ):
            viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: viewer._worker is None
            and all(viewer._photo_images),
            'Canvas viewer did not finish its authorized initial thumbnails.',
        )
        media_page_requests = session.media_page.call_count
        media_photo_requests = session.media_photo.call_count
        session.can_read_media.return_value = False

        viewer.refresh_photos()

        self.assertTrue(viewer._closed)
        self.assertIsNotNone(viewer._canvas_grid)
        self.assertTrue(viewer._canvas_grid._closed)
        self.assertTrue(viewer._cancelled.is_set())
        self.assertIsNone(viewer._worker)
        self.assertEqual(media_page_requests, session.media_page.call_count)
        self.assertEqual(media_photo_requests, session.media_photo.call_count)

    def test_explicit_native_choice_overrides_canvas_environment_opt_in(self) -> None:
        """Mount real native tiles when startup explicitly resolves Canvas off.

        Returns:
            None: An already-resolved startup False choice wins over raw env.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        with mock.patch.dict(
            native_gallery_viewer.os.environ,
            {'PHP_GALLERY_CANVAS_GRID': '1'},
        ):
            viewer = self._open_viewer(
                session, gallery, canvas_grid_enabled=False,
            )
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: len(viewer._photos) == len(page.photos)
            and all(viewer._photo_images) and viewer._worker is None,
            'The explicitly native viewer did not mount its photo tiles.',
        )
        self.assertIsNone(viewer._canvas_grid)
        self.assertIsNotNone(viewer.grid_window)
        self.assertTrue(all(
            isinstance(tile, viewer_tests.ttk.Button)
            for tile in viewer._tile_buttons
        ))

    def test_canvas_factory_default_is_enabled_without_environment_override(self) -> None:
        """Use Canvas when the persisted factory choice is enabled by default.

        Returns:
            None: An absent environment override uses the application factory
                default and mounts the actual Canvas renderer.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        with mock.patch.dict(native_gallery_viewer.os.environ):
            native_gallery_viewer.os.environ.pop(
                'PHP_GALLERY_CANVAS_GRID', None,
            )
            viewer = self._open_viewer(session, gallery)
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: len(viewer._photos) == len(page.photos)
            and bool(viewer._visible_thumbnail_indices)
            and viewer._visible_thumbnail_indices <= viewer._thumbnail_loaded
            and all(
                viewer._photo_images[index] is not None
                for index in viewer._visible_thumbnail_indices
            )
            and viewer._worker is None,
            'The factory-default Canvas viewport did not settle its images.',
        )
        self.assertIsNotNone(viewer._canvas_grid)
        self.assertIsNone(viewer.grid_window)
        self.assertTrue(viewer._visible_thumbnail_indices)
        self.assertTrue(all(
            viewer._photo_images[index] is not None
            for index in viewer._visible_thumbnail_indices
        ))
        diagnostics = viewer.render_diagnostics()
        self.assertIn('renderer=canvas', diagnostics)
        self.assertIn(
            'memory_capacity_entries='
            f'{native_gallery_viewer.MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT}',
            diagnostics,
        )
        self.assertIn(
            'memory_capacity_bytes='
            f'{native_gallery_viewer.MEDIA_THUMBNAIL_CACHE_MAX_BYTES}',
            diagnostics,
        )

    def test_canvas_reuses_fresh_memory_during_a_delayed_page_refresh(self) -> None:
        """Re-admit a decoded raster while a catalogue refresh is waiting.

        Returns:
            None: Fresh authorized RAM pixels are republished without a second
                media read; the independent metadata refresh remains in flight.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        memory = native_gallery_viewer.GalleryThumbnailMemory()
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        viewer = self._open_viewer(
            session, gallery, memory, canvas_grid_enabled=True,
        )
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: len(viewer._photos) == len(page.photos)
            and all(viewer._photo_images) and viewer._worker is None,
            'Initial Canvas thumbnails did not settle before refresh.',
        )
        initial_reads = session.media_photo.call_count
        initial_hits = memory.metrics_snapshot()['hits']
        viewer._release_thumbnail_image(0)
        entered = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)

        def delayed_page(
            gallery_id: int,
            after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> LinkedMediaPage:
            """Hold the authoritative refresh until the RAM hit is observed.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                after_id (int | None): Exclusive metadata cursor, if supplied.
                cancelled (threading.Event | None): Refresh cancellation token.
            Returns:
                LinkedMediaPage: The same authorized metadata for the fixture.
            """
            self.assertEqual(TEST_GALLERY_ID, gallery_id)
            self.assertIsNone(after_id)
            entered.set()
            release.wait(TEST_GUI_WAIT_SECONDS)
            if cancelled is not None and cancelled.is_set():
                return LinkedMediaPage(
                    TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, (), None,
                )
            return page

        session.media_page.side_effect = delayed_page
        viewer.refresh_photos()
        self._wait_until(
            lambda: entered.is_set()
            and viewer._worker_kind == 'refresh',
            'The delayed authoritative metadata refresh did not start.',
        )
        self.assertIsNone(viewer._photo_images[0])
        viewer._on_grid_viewport(None)
        self._wait_until(
            lambda: viewer._photo_images[0] is not None
            and viewer._worker_kind == 'refresh',
            'A fresh decoded RAM raster waited behind the metadata refresh.',
        )
        self.assertEqual(initial_reads, session.media_photo.call_count)
        self.assertGreater(memory.metrics_snapshot()['hits'], initial_hits)
        release.set()
        self._wait_until(
            lambda: viewer._worker is None and viewer._photo_images[0] is not None,
            'The authorized refresh did not finish after releasing its page.',
        )

    def test_cached_newest_order_refreshes_once_after_ram_render(self) -> None:
        """Render a fresh cached reverse order while one full refresh runs.

        Returns:
            None: The cached page's RAM thumbnails publish before the blocked
                authoritative sweep, and no duplicate full sweep follows it.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        memory = native_gallery_viewer.GalleryThumbnailMemory()
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        first = self._open_viewer(
            session, gallery, memory, canvas_grid_enabled=True,
        )
        self._wait_until(
            lambda: len(first._photos) == len(page.photos)
            and all(first._photo_images) and first._worker is None,
            'Source Canvas thumbnails did not settle.',
        )
        original_media_reads = session.media_photo.call_count
        first_host = first.frame.master
        first.close()
        first_host.destroy()

        newest_first = tuple(reversed(page.photos))
        session.cache_recent_media_order(
            TEST_GALLERY_ID,
            newest_first,
            native_gallery_viewer.time.monotonic(),
        )
        session.recent_media_order.return_value = newest_first
        entered = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)
        read_count: list[int] = []

        def delayed_page(
            gallery_id: int,
            after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> LinkedMediaPage:
            """Hold the one authoritative full-order refresh for observation.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                after_id (int | None): Exclusive server cursor, if supplied.
                cancelled (threading.Event | None): Viewer cancellation token.
            Returns:
                LinkedMediaPage: Complete ascending metadata from the fixture.
            """
            self.assertEqual(TEST_GALLERY_ID, gallery_id)
            read_count.append(1)
            if len(read_count) == 1:
                entered.set()
                release.wait(TEST_GUI_WAIT_SECONDS)
            return page

        session.media_page.side_effect = delayed_page
        newest = self._open_viewer(
            session,
            gallery,
            memory,
            canvas_grid_enabled=True,
            newest_first=True,
        )
        self.addCleanup(newest.close)
        self._wait_until(
            lambda: entered.is_set()
            and newest._worker_kind == 'refresh'
            and tuple(newest._photos) == newest_first
            and bool(newest._visible_thumbnail_indices)
            and newest._visible_thumbnail_indices <= newest._thumbnail_loaded
            and all(
                newest._photo_images[index] is not None
                for index in newest._visible_thumbnail_indices
            ),
            'The cached reverse-order viewport did not render during refresh.',
        )
        self.assertTrue(newest._visible_thumbnail_indices)
        self.assertTrue(all(
            newest._photo_images[index] is not None
            for index in newest._visible_thumbnail_indices
        ))
        self.assertEqual(original_media_reads, session.media_photo.call_count)
        release.set()
        self._wait_until(
            lambda: newest._worker is None
            and newest._worker_kind is None
            and not newest._refresh_pending
            and newest._events.empty(),
            'The authoritative cached-order refresh did not settle.',
        )
        self.assertEqual(1, len(read_count))
        self.assertEqual(original_media_reads, session.media_photo.call_count)

    def test_canvas_return_reuses_session_rasters_with_viewport_tk_ownership(self) -> None:
        """Reuse decoded shared pixels while releasing each closed viewer's Tk refs.

        Returns:
            None: Canvas re-entry hits the same session LRU without another
                media read; PhotoImages remain viewer-scoped and clear on close.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        memory = native_gallery_viewer.GalleryThumbnailMemory()
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        with mock.patch.dict(
            native_gallery_viewer.os.environ,
            {'PHP_GALLERY_CANVAS_GRID': '0'},
        ):
            first = self._open_viewer(
                session, gallery, memory, canvas_grid_enabled=True,
            )
        self._wait_until(
            lambda: len(first._photos) == len(page.photos)
            and all(first._photo_images) and first._worker is None,
            'Canvas cache source thumbnails did not settle.',
        )
        self.assertIsNotNone(first._canvas_grid)
        initial_reads = session.media_photo.call_count
        first_host = first.frame.master
        first.close()
        self.assertEqual([], first._photo_images)
        self.assertEqual(
            first._thumbnail_image_create_count,
            first._thumbnail_image_release_count,
        )
        self.assertTrue(memory.entries)
        first_host.destroy()

        before_return = memory.metrics_snapshot()
        with mock.patch.dict(
            native_gallery_viewer.os.environ,
            {'PHP_GALLERY_CANVAS_GRID': '0'},
        ):
            second = self._open_viewer(
                session, gallery, memory, canvas_grid_enabled=True,
            )
        self._wait_until(
            lambda: len(second._photos) == len(page.photos)
            and all(second._photo_images) and second._worker is None,
            'Canvas decoded-cache return did not settle.',
        )
        self.assertIsNotNone(second._canvas_grid)
        self.assertEqual(initial_reads, session.media_photo.call_count)
        self.assertGreater(second._thumbnail_warm_cache_hit_count, 0)
        after_return = memory.metrics_snapshot()
        self.assertGreater(after_return['hits'], before_return['hits'])
        self.assertLessEqual(
            sum(image is not None for image in second._photo_images),
            len(second._admitted_thumbnail_indices),
        )
        self.assertGreater(second._thumbnail_image_create_count, 0)

        second_host = second.frame.master
        second.close()
        self.assertEqual([], second._photo_images)
        self.assertEqual(
            second._thumbnail_image_create_count,
            second._thumbnail_image_release_count,
        )
        second_host.destroy()
        retained = tuple(image for image, _size, _at in memory.entries.values())
        self.assertEqual(
            {key[:2]: key for key in memory.entries},
            memory.identity_keys,
        )
        before_clear = memory.metrics_snapshot()
        memory.clear()
        after_clear = memory.metrics_snapshot()
        self.assertEqual(
            {
                key: value for key, value in before_clear.items()
                if key not in {'bytes', 'entries'}
            },
            {
                key: value for key, value in after_clear.items()
                if key not in {'bytes', 'entries'}
            },
        )
        self.assertEqual({}, memory.entries)
        self.assertEqual({}, memory.identity_keys)
        self.assertEqual(0, memory.size_bytes)
        for image in retained:
            with self.assertRaises(ValueError):
                image.getpixel((0, 0))

    def test_canvas_context_callback_receives_exact_photo_identity(self) -> None:
        """Route an actual Canvas context press to the exact loaded photo owner.

        Returns:
            None: The existing context callback receives the current descriptor
                without opening the full-resolution lightbox or reading detail.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        context_targets: list[LinkedPhoto | None] = []

        def record_context(
            photo: LinkedPhoto | None,
            event: tk.Event,
        ) -> str:
            """Record the descriptor delivered by the viewer's context owner.

            Args:
                photo (LinkedPhoto | None): Exact tile target or empty-grid input.
                event (tk.Event): Translated real Canvas context event.

            Returns:
                str: Tk break marker to consume the handled context gesture.
            """
            context_targets.append(photo)
            return 'break'

        with mock.patch.dict(
            native_gallery_viewer.os.environ,
            {'PHP_GALLERY_CANVAS_GRID': '1'},
        ):
            host = viewer_tests.ttk.Frame(self.root)
            host.pack(fill='both', expand=True)
            frame = native_gallery_viewer.mount_native_gallery_view(
                host,
                session,
                gallery,
                lambda: 'en',
                context_menu=record_context,
            )
            frame.pack(fill='both', expand=True)
            self.root.update_idletasks()
            self.root.update()
        viewer = frame._gallery_viewer_controller
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: viewer._worker is None
            and len(viewer._photos) == len(page.photos)
            and all(viewer._photo_images),
            'Canvas context fixture did not render its exact photo targets.',
        )

        viewer._tile_buttons[1].event_generate('<Button-3>')
        self.root.update()

        self.assertEqual(1, len(context_targets))
        target = context_targets[0]
        self.assertIsNotNone(target)
        self.assertIs(page.photos[1], target)
        self.assertEqual(page.photos[1].image_id, target.image_id)
        self.assertIsNone(viewer._lightbox)
        self.assertFalse(any(
            request.kwargs.get('thumbnail') is False
            for request in session.media_photo.call_args_list
        ))
        viewer.close()

