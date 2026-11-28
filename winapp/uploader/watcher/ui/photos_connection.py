# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/photos_connection.py
# Module Type: Windows Client UI behavior
# Purpose: Own the photos connection interactions for WatcherApp.
# Responsibilities: Preserve the existing Watcher UI lifecycle and callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Watcher photos connection behavior owner."""

from uploader.watcher import runtime
from uploader.watcher import state as watcher_state
from uploader.localization import gettext, gettext_noop
from .support import (
    CONNECTION_DIALOG_GEOMETRY,
    CONNECTION_DIALOG_MINIMUM,
    CONNECTION_DIALOG_SCREEN_MARGIN,
    WATCHER_WINDOW_MINIMUM,
    _layout_display_bounds,
    _publish_watcher_text,
    _publish_watcher_value,
    tr,
)
from uploader.app_linking import LINKING_GALLERY_ID_MAX
from uploader.app_linking import LinkingError
from uploader.diagnostics import format_watch_recovery_diagnostic
from uploader.flight_jobs import connection_binding
from uploader.gallery_catalogue_cache import GalleryCatalogueCache
from uploader.gallery_view_preferences import load_gallery_card_format
from uploader.gallery_view_preferences import load_gallery_folder_split
from uploader.gallery_view_preferences import load_gallery_photo_order
from uploader.gallery_view_preferences import load_photos_tree_preferences
from uploader.gallery_view_preferences import photos_account_tree_path
from uploader.gallery_view_preferences import save_gallery_card_format
from uploader.gallery_view_preferences import save_gallery_folder_split
from uploader.gallery_view_preferences import save_gallery_photo_order
from uploader.gallery_view_preferences import save_photos_tree_preferences
from uploader.linked_upload import linked_target_binding
from uploader.linked_upload import verify_linked_upload_target
from uploader.macos_mode import assert_local_macos_profile
from uploader.mode_admission import LEGACY_UPLOAD_STATES
from uploader.photos_workspace import PhotosWorkspace
from uploader.state_store import UploadStateTooLargeError
from uploader.ui_layout import UI_LAYOUT_SAVE_DELAY_MS
from uploader.ui_layout import _clamp_ui_layout_state
from uploader.ui_layout import _write_ui_layout
from uploader.ui_shell import EmbeddedWindow
from uploader.ui_theme import Button
from uploader.ui_theme import GlassCard
from uploader.windows_file_identity import assert_local_windows_profile
import logging

class PhotosConnectionMixin:
    """Own this cohesive WatcherApp UI behavior domain."""

    def build_photos_tab(self, parent: "tk.Misc") -> None:
        """Build the main-window gallery tree and embedded photo workspace.

        Args:
            parent (tk.Misc): Main-window frame for the Photos page.

        Returns:
            None: Loads the exact current linked session without another window.
        """
        preferred_tree_width = self._ui_layout_state.photos_tree_width
        preferred_info_width = self._ui_layout_state.photos_info_width
        parent.rowconfigure(0, weight=1)
        parent.columnconfigure(0, weight=1)
        self.photos_workspace = PhotosWorkspace(
            parent,
            self.linking_manager.photos_session,
            self.open_photos,
            language=lambda: self.flights.ui_language.get(),
            manager=self.linking_manager,
            format_bytes=self.format_bytes,
            manage_link=self.manage_gallery_link,
            start_watcher=self.open_gallery_watcher,
            simbrief_available=self.simbrief_available,
            simbrief_action=self.flights.toggle_simbrief,
            load_tree_state=self.load_photos_tree_state,
            save_tree_state=self.save_photos_tree_state,
            load_selected_gallery=self.load_photos_selected_gallery,
            save_selected_gallery=self.save_photos_selected_gallery,
            load_photo_order=self.load_photos_photo_order,
            save_photo_order=self.save_photos_photo_order,
            load_card_format=self.load_photos_card_format,
            save_card_format=self.save_photos_card_format,
            load_folder_format=lambda session, gallery_id: self.load_photos_card_format(
                session, gallery_id, folders=True,
            ),
            save_folder_format=lambda session, gallery_id, value: self.save_photos_card_format(
                session, gallery_id, value, folders=True,
            ),
            load_folder_order=lambda session, gallery_id: self.load_photos_photo_order(
                session, gallery_id, folders=True,
            ),
            save_folder_order=lambda session, gallery_id, value: self.save_photos_photo_order(
                session, gallery_id, value, folders=True,
            ),
            load_folder_split=self.load_photos_folder_split,
            save_folder_split=self.save_photos_folder_split,
            catalogue_cache=self.photos_catalogue_cache,
            load_tree_width=lambda: preferred_tree_width,
            save_tree_width=self.save_photos_tree_width,
            load_info_width=lambda: preferred_info_width,
            save_info_width=self.save_photos_info_width,
            storage_parent=getattr(self, '_storage_header', None),
            storage_surface=getattr(self, '_storage_context_surface', None),
            diagnostic_callback=lambda message: self.events.put(
                ('warning', '[Photos] ' + message),
            ),
            canvas_grid_enabled=self._canvas_grid_enabled_at_startup,
            thumbnail_batch_size=lambda: self._thumbnail_batch_size,
            restart_with_qt=lambda: self._gui_backend_settings.restart_with_qt(),
        )
        self.photos_workspace.grid(row=0, column=0, sticky="nsew")
        self._sync_watcher_recording()
        address_trace = self.linking_manager.linked_address.trace_add(
            "write", lambda *_trace_info: self.photos_workspace.refresh_session(),
        )
        self.photos_workspace.bind(
            "<Destroy>",
            lambda event: self.linking_manager.linked_address.trace_remove(
                "write", address_trace,
            ) if event.widget is self.photos_workspace else None,
            add="+",
        )


    def _photos_tree_state_path(self, session: LinkingSession) -> Path | None:
        """Build a credential-free preference path for one verified account.

        Args:
            session (LinkingSession): Exact current Photos session.

        Returns:
            Path | None: Hashed site/account preference path, or None when stale.
        """
        identity_reader = getattr(self.linking_manager, "photos_site_identity", None)
        identity = identity_reader(session) if identity_reader is not None else None
        if identity is None:
            return None
        issuer, user_id = identity
        return photos_account_tree_path(watcher_state.CONFIG_DIR, issuer, user_id)


    def photos_catalogue_cache(
        self, session: LinkingSession,
    ) -> GalleryCatalogueCache | None:
        """Create the optional catalogue cache for the live approved identity.

        Args:
            session (LinkingSession): Exact current Photos session.

        Returns:
            GalleryCatalogueCache | None: Issuer/account/scope-bound cache, or
                None when the manager cannot verify the current account.
        """
        identity_reader = getattr(
            self.linking_manager, "photos_site_identity", None,
        )
        identity = identity_reader(session) if identity_reader is not None else None
        if identity is None:
            return None
        issuer, account_id = identity
        try:
            return GalleryCatalogueCache.for_current_user(
                issuer, account_id, session.approved_scope(),
            )
        except (OSError, TypeError, ValueError, LinkingError):
            return None


    def load_photos_photo_order(
        self, session: LinkingSession, gallery_id: int,
        *, folders: bool = False,
    ) -> str:
        """Load local order for the verified Photos site/account/gallery.

        Args:
            session (LinkingSession): Current approved site/account session.
            gallery_id (int): Selected gallery's positive identity.
            folders (bool): Use independent local child-folder preferences.
        Returns:
            str: newest or the original oldest order when local state is absent.
        """
        account_path = self._photos_tree_state_path(session)
        if account_path is None:
            return 'oldest'
        return load_gallery_photo_order(
            account_path.with_name(account_path.stem + '.view.json'), gallery_id, folders=folders,
        )


    def save_photos_photo_order(
        self, session: LinkingSession, gallery_id: int, order: str,
        *, folders: bool = False,
    ) -> bool:
        """Persist only local presentation order without changing upload settings.

        Args:
            session (LinkingSession): Current approved site/account session.
            gallery_id (int): Selected gallery's positive identity.
            order (str): oldest or newest local presentation direction.
            folders (bool): Use independent local child-folder preferences.
        Returns:
            bool: True after the verified account's atomic local preference write.
        """
        account_path = self._photos_tree_state_path(session)
        if account_path is None:
            return False
        return save_gallery_photo_order(
            account_path.with_name(account_path.stem + '.view.json'), gallery_id, order, folders=folders,
        )


    def load_photos_card_format(
        self, session: LinkingSession, gallery_id: int,
        *, folders: bool = False,
    ) -> str:
        """Load a local grid preference for the verified Photos identity.

        Args:
            session (LinkingSession): Current approved site/account session.
            gallery_id (int): Selected gallery's positive identity.
            folders (bool): Use independent local child-folder preferences.
        Returns:
            str: Supported saved format, or default without verified local state.
        """
        account_path = self._photos_tree_state_path(session)
        if account_path is None:
            return "default"
        view_path = account_path.with_name(account_path.stem + ".view.json")
        return load_gallery_card_format(view_path, gallery_id, folders=folders)


    def save_photos_card_format(
        self, session: LinkingSession, gallery_id: int, card_format: str,
        *, folders: bool = False,
    ) -> bool:
        """Persist a WinApp-only grid choice without touching upload settings.

        Args:
            session (LinkingSession): Current approved site/account session.
            gallery_id (int): Selected gallery's positive identity.
            card_format (str): Supported local card-format key.
            folders (bool): Use independent local child-folder preferences.
        Returns:
            bool: True only after the verified account's atomic local write;
                False when identity or persistence cannot be confirmed.
        """
        account_path = self._photos_tree_state_path(session)
        if account_path is None:
            return False
        view_path = account_path.with_name(account_path.stem + ".view.json")
        return save_gallery_card_format(view_path, gallery_id, card_format, folders=folders)


    def load_photos_folder_split(
        self, session: LinkingSession, gallery_id: int,
    ) -> int | None:
        """Read a gallery divider from this computer's verified identity file.

        Args:
            session (LinkingSession): Current approved site/account session.
            gallery_id (int): Selected physical gallery identity.
        Returns:
            int | None: Relative pane thousandths, or None for compact auto sizing.
        """
        account_path = self._photos_tree_state_path(session)
        if account_path is None:
            return None
        return load_gallery_folder_split(
            account_path.with_name(account_path.stem + '.view.json'), gallery_id,
        )


    def save_photos_folder_split(
        self, session: LinkingSession, gallery_id: int, share: int | None,
    ) -> bool:
        """Save only a local divider preference without an online gallery write.

        Args:
            session (LinkingSession): Current approved site/account session.
            gallery_id (int): Selected physical gallery identity.
            share (int | None): Pane thousandths, or None to clear the override.
        Returns:
            bool: True after the verified account's atomic local write.
        """
        account_path = self._photos_tree_state_path(session)
        if account_path is None:
            return False
        return save_gallery_folder_split(
            account_path.with_name(account_path.stem + '.view.json'),
            gallery_id, share,
        )


    def _schedule_ui_layout_save(self, event: "tk.Event") -> None:
        """Debounce visible main-window geometry updates on the Tk owner.

        Args:
            event (tk.Event): Configure event for the root or one of its children.

        Returns:
            None: Root-only changes schedule one bounded layout write.
        """
        if event.widget is not self.root or self.exiting:
            return
        if self._ui_layout_save_after is not None:
            try:
                self.root.after_cancel(self._ui_layout_save_after)
            except runtime.tk.TclError:
                pass
        self._ui_layout_save_after = self.root.after(
            UI_LAYOUT_SAVE_DELAY_MS, self._save_ui_layout_now,
        )


    def _save_ui_layout_now(self, *, allow_iconic: bool = False) -> None:
        """Persist the last valid normal geometry and current maximize state.

        Args:
            allow_iconic (bool): During minimize-to-tray only, accept a still
                normal-sized window while retaining its last safe position.

        Returns:
            None: Transient minimized/withdrawn/fullscreen/tiny geometry is
                ignored; minimize-to-tray can save a still normal-sized sample.
        """
        self._ui_layout_save_after = None
        if self.exiting:
            return
        current = self._ui_layout_state
        try:
            window_state = self.root.state()
            fullscreen = bool(self.root.attributes("-fullscreen"))
            visible = bool(self.root.winfo_viewable())
            width = int(self.root.winfo_width())
            height = int(self.root.winfo_height())
            x = int(self.root.winfo_x())
            y = int(self.root.winfo_y())
        except (runtime.tk.TclError, TypeError, ValueError):
            return
        iconic_normal = (
            allow_iconic and window_state == "iconic"
            and current.window_state == "normal"
            and width >= WATCHER_WINDOW_MINIMUM[0]
            and height >= WATCHER_WINDOW_MINIMUM[1]
        )
        if (fullscreen or (not visible and not iconic_normal)
                or (window_state not in ("normal", "zoomed") and not iconic_normal)
                or width <= 1 or height <= 1):
            return
        if iconic_normal:
            current = watcher_state.replace(
                current, width=width, height=height, window_state="normal",
            )
        elif window_state == "normal":
            current = watcher_state.replace(
                current, width=width, height=height, x=x, y=y,
                window_state="normal",
            )
        else:
            current = watcher_state.replace(current, window_state="zoomed")
        workspace = getattr(self, "photos_workspace", None)
        if workspace is not None:
            try:
                pane_width = int(workspace.current_tree_width())
            except (runtime.tk.TclError, TypeError, ValueError):
                pane_width = 0
            if pane_width > 0:
                current = watcher_state.replace(current, photos_tree_width=pane_width)
            try:
                info_width = int(workspace.current_info_width())
            except (runtime.tk.TclError, TypeError, ValueError, AttributeError):
                info_width = 0
            if info_width > 0:
                current = watcher_state.replace(current, photos_info_width=info_width)
        current = _clamp_ui_layout_state(
            current, _layout_display_bounds(self.root), WATCHER_WINDOW_MINIMUM,
        )
        try:
            _write_ui_layout(self._ui_layout_path, current)
        except (OSError, ValueError, TypeError):
            logging.debug("Desktop layout preferences could not be saved.")
            return
        self._ui_layout_state = current


    def save_photos_tree_width(self, width: int) -> None:
        """Persist one settled Photos sash width in the shared UI layout file.

        Args:
            width (int): Sash position in the current Panedwindow's Tk pixels.

        Returns:
            None: The width is clamped and atomically merged with window state.
        """
        if type(width) is not int or width <= 0:
            return
        current = watcher_state.replace(self._ui_layout_state, photos_tree_width=width)
        current = _clamp_ui_layout_state(
            current, _layout_display_bounds(self.root), WATCHER_WINDOW_MINIMUM,
        )
        try:
            _write_ui_layout(self._ui_layout_path, current)
        except (OSError, ValueError, TypeError):
            logging.debug("Photos tree width preference could not be saved.")
            return
        self._ui_layout_state = current


    def save_photos_info_width(self, width: int) -> None:
        """Persist one settled Photos inspector width in the UI layout file.

        Args:
            width (int): Desired right inspector width in Tk pixels.

        Returns:
            None: The width is clamped and atomically merged with window state.
        """
        if type(width) is not int or width <= 0:
            return
        current = watcher_state.replace(self._ui_layout_state, photos_info_width=width)
        current = _clamp_ui_layout_state(
            current, _layout_display_bounds(self.root), WATCHER_WINDOW_MINIMUM,
        )
        try:
            _write_ui_layout(self._ui_layout_path, current)
        except (OSError, ValueError, TypeError):
            logging.debug("Photos inspector width preference could not be saved.")
            return
        self._ui_layout_state = current


    def load_photos_tree_state(
        self, session: LinkingSession,
    ) -> Dict[int, bool]:
        """Load validated gallery expansion preferences for the live account.

        Args:
            session (LinkingSession): Exact current Photos session.

        Returns:
            Dict[int, bool]: Positive gallery IDs mapped to open state; invalid
                or unavailable preferences produce an empty mapping.
        """
        path = self._photos_tree_state_path(session)
        return load_photos_tree_preferences(path).opened if path is not None else {}


    def save_photos_tree_state(
        self, session: LinkingSession, state: Dict[int, bool],
    ) -> None:
        """Atomically save bounded gallery expansion preferences.

        Args:
            session (LinkingSession): Exact current Photos session.
            state (Dict[int, bool]): Positive gallery IDs mapped to booleans.

        Returns:
            None: Writes only preferences after rechecking exact live identity.
        """
        path = self._photos_tree_state_path(session)
        if path is not None and not save_photos_tree_preferences(path, opened=state):
            logging.debug("Photos tree preferences could not be saved.")


    def load_photos_selected_gallery(
        self, session: LinkingSession,
    ) -> int | None:
        """Load one positive saved gallery preference for the exact account.

        Args:
            session (LinkingSession): Exact current Photos session.

        Returns:
            int | None: Saved gallery ID from preference version 2, if valid.
        """
        path = self._photos_tree_state_path(session)
        return load_photos_tree_preferences(path).selected if path is not None else None


    def save_photos_selected_gallery(
        self, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Persist an explicit selection while preserving expansion settings.

        Args:
            session (LinkingSession): Exact current Photos session.
            gallery_id (int): Positive selected gallery ID from a fresh row.

        Returns:
            None: Updates only the version-2 preference for the verified account.
        """
        if (type(gallery_id) is not int
                or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX):
            return
        path = self._photos_tree_state_path(session)
        if path is not None and not save_photos_tree_preferences(path, selected=gallery_id):
            logging.debug("Photos tree selection could not be saved.")


    def open_photos(self) -> None:
        """Open the existing connection flow for the Photos workspace.

        Returns:
            None: Update handoff blocks new UI work; otherwise the existing
                address dialog starts or changes the live site connection.
        """
        if self.updates.blocked():
            return
        self.linking_manager.open_address_dialog()


    def saved_linked_mode_selected(self) -> bool:
        """Reflect durable linked selection without silently selecting a key.

        Returns:
            bool: True for linked mode or unavailable startup evidence, and
                False only for verified legacy or first-setup evidence.
        """
        try:
            return self.mode_owner.view().state not in (
                'legacy_unmigrated', 'legacy', 'first_setup',
            )
        except (AttributeError, watcher_state.ModeCoordinatorError):
            return True


    def saved_linked_upload_identity(self) -> tuple[str, str] | None:
        """Recover the exact saved destination even without a live grant.

        Returns:
            tuple[str, str] | None: Verified issuer and target fingerprint,
                or None when mode evidence is unavailable or legacy is selected.
        """
        try:
            record = self.mode_owner.view().record
        except watcher_state.ModeCoordinatorError:
            return None
        if record is None or record.mode != "linked":
            return None
        return (
            record.issuer,
            linked_target_binding(record.issuer, record.account_id, record.gallery_id),
        )


    def commit_linked_upload_target(
        self, target: watcher_state.LinkedUploadTarget, *, expected: ModeView | None = None,
    ) -> bool:
        """Publish and activate one explicitly chosen server-verified target.

        The linking worker must have freshly verified this exact gallery, Admin
        and scope before asking the Tk owner to commit. No request is sent here.

        Args:
            target (LinkedUploadTarget): Exact independently verified gallery.
            expected (ModeView | None): Captured unchanged same-target recovery
                view, or None for an explicit new target choice.

        Returns:
            bool: True only after local workspace admission and confirmed mode
                publication and activation.
        """
        if expected is not None and (
            not type(self)._watcher_connection_selected(self)
            or self.mode_owner.view() is not expected
        ):
            return False
        if (not self.linked_workspace_ready()
                or self.linked_target_change_refusal(target.binding()) is not None):
            return False
        try:
            if self.mode_owner.view().state == 'first_setup':
                # The explicit target choice also authorizes initializing the
                # missing config. Write only the loaded defaults, never
                # copy the linked URL or read unsaved API-key form fields.
                self.config = self._save_owned_config(self.config, first_setup=True)
            view = (self.mode_owner.select_linked(target) if expected is None
                    else self.mode_owner.select_linked(target, expected=expected))
            record = view.record
            return (
                view.state == 'linked_active' and record is not None
                and record.mode == 'linked' and record.issuer == target.issuer
                and record.account_id == target.user_id
                and record.gallery_id == target.gallery_id
                and record.scope == target.session.approved_scope()
            )
        except Exception:
            logging.warning('Linked target could not be activated safely.')
            return False


    def linked_workspace_ready(self) -> bool:
        """Check local profile ownership before requesting or starting uploads.

        Returns:
            bool: True for an admitted local Windows or macOS profile.
        """
        try:
            if runtime.sys.platform == "darwin":
                assert_local_macos_profile(watcher_state.CONFIG_DIR)
                if self.config_store.path.parent != watcher_state.CONFIG_DIR:
                    assert_local_macos_profile(self.config_store.path.parent)
            else:
                assert_local_windows_profile(watcher_state.CONFIG_DIR)
        except OSError:
            return False
        return True


    def commit_saved_api_key(self) -> bool:
        """Honor an explicit return to the unchanged saved gallery API key.

        Returns:
            bool: True only after the persistent owner confirms legacy mode.
        """
        if self.linked_target_change_refusal(None) is not None:
            return False
        try:
            view = self.mode_owner.select_legacy()
            return view.state in LEGACY_UPLOAD_STATES
        except Exception:
            logging.warning('Saved API-key mode could not be restored safely.')
            return False


    def linked_target_change_refusal(self, proposed_binding: str | None) -> str | None:
        """Explain the first blocker to an explicit upload-target change.

        Args:
            proposed_binding (str | None): Proposed linked target fingerprint,
                or None for the saved API-key connection.

        Returns:
            str | None: Short reason for refusal, or None when the mode
                and existing work permit the change.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            return gettext('Wait for the Watcher restart checkpoint to finish.')
        if isinstance(getattr(self, "_contextual_watch_target", None), watcher_state.LinkedUploadTarget):
            return gettext('Finish the current Watcher capture before changing its gallery.')
        if self.background_work_active() or self.flights.is_alive() or self.flights.busy():
            return gettext('Wait for the current Watcher, import or flight work to stop.')
        try:
            view = self.mode_owner.view()
        except watcher_state.ModeCoordinatorError:
            return gettext('Upload settings could not be verified. Restart WinApp and try again.')

        legacy_binding = connection_binding(
            watcher_state.normalize_upload_url(self.config.gallery_url), self.config.api_key,
        )
        record = view.record
        current_binding = (
            linked_target_binding(record.issuer, record.account_id, record.gallery_id)
            if record is not None and record.mode == 'linked'
            else legacy_binding
        )
        desired_binding = proposed_binding if proposed_binding is not None else legacy_binding
        try:
            # The saved mode identifies pending flights even when the linked
            # token has expired or WinApp has just restarted.
            if (self.flights.pending(binding=current_binding)
                    and desired_binding != current_binding):
                return gettext('Continue the current uploads in Watcher before changing the upload gallery.')
            # An accepted import retains its binding across restarts. Do not
            # replace its destination, but allow reconnecting to that same
            # exact target to review and explicitly recover incomplete work.
            # Check even a same-target reactivation: an orphaned or damaged job
            # cannot become safe solely because the in-memory target matches.
            # Never erase a completed binding as a side effect of this check.
            with watcher_state.accepted_work_owner(watcher_state.CONFIG_DIR):
                bindings = self.flights.import_bindings.entries()
                # Both ledgers are re-read under one cooperative owner. This
                # cannot attest to previously deleted or rolled-back work.
                result = self.job_store.admitted_imports_match_target(
                    bindings, desired_binding,
                )
                if result is not True:
                    return gettext('Review unfinished imports in Activity before changing the upload target.')
        except UploadStateTooLargeError:
            return gettext('Local upload history exceeds the safe size limit. Review Activity.')
        except (OSError, ValueError, RuntimeError):
            return gettext('Local upload history could not be verified. Review Activity.')
        return None


    def linked_target_changed(self) -> None:
        """Refresh the chosen connection and invalidate stale preflight results.

        Returns:
            None: Keeps the saved API-key fields untouched and requires a fresh
                import preflight for the newly selected destination.
        """
        self._import_detail_request = None
        if (self.manual_plan is not None
                and not (self.manual_worker and self.manual_worker.is_alive())):
            self.manual_plan = None
            self._preflight_approved = None
            self.current_job = None
            self.start_import_button.configure(state='disabled')
            self.manual_preflight_var.set(
                gettext('Gallery connection changed. Run import preflight again.'),
            )
        target = self.linking_manager.selected_upload_target()
        if target is not None:
            self._verified_legacy_gallery = None
            type(self)._publish_connection_text(self, "status", gettext_noop('Ready for uploads'))
            type(self)._publish_connection_text(
                self, "detail", gettext_noop('Choose a folder in Watcher or add files in Import.'),
            )
            self.gallery_label_var.set(gettext('Target gallery: ') + target.title)
            self.gallery_title_var.set(target.title)
        elif self.linking_manager.linked_upload_mode_selected():
            self._verified_legacy_gallery = None
            type(self)._publish_connection_text(self, "status", gettext_noop('Reconnect to upload'))
            type(self)._publish_connection_text(
                self, "detail", gettext_noop('The selected upload gallery needs a new link.'),
            )
            self.gallery_label_var.set(gettext('Target gallery: unknown'))
            self.gallery_title_var.set('')
        else:
            if self._restore_verified_legacy_gallery():
                type(self)._publish_connection_text(self, "status", gettext_noop('Connected'))
                type(self)._publish_connection_text(
                    self, "detail", gettext_noop('Authenticated endpoint available: {endpoint}'),
                    endpoint=watcher_state.normalize_upload_url(self.config.gallery_url),
                )
            else:
                type(self)._publish_connection_text(self, "status", gettext_noop('Not tested'))
                type(self)._publish_connection_text(
                    self, "detail", gettext_noop('Saved API-key connection selected.'),
                )
        self.refresh_connection_controls()


    def _restore_verified_legacy_gallery(self) -> bool:
        """Show legacy metadata only for a response verified against this binding.

        Returns:
            bool: True when its exact normalized URL/key binding remains valid.
        """
        verified = self._verified_legacy_gallery
        current_url = watcher_state.normalize_upload_url(self.config.gallery_url)
        current_key = self.config.api_key.strip()
        binding = connection_binding(current_url, current_key)
        if (verified is None or self.api_key_revoke_running
                or not current_url or not current_key
                or verified[1] != binding
                or watcher_state.normalize_upload_url(self.gallery_url_var.get()) != current_url
                or self.api_key_var.get().strip() != current_key):
            self._verified_legacy_gallery = None
            self.gallery_label_var.set(gettext('Target gallery: unknown'))
            self.gallery_title_var.set('')
            return False
        self.gallery_label_var.set(gettext('Target gallery: ') + verified[2])
        self.gallery_title_var.set(verified[3])
        return True


    def _invalidate_verified_legacy_gallery(
        self, *_trace_args: str,
    ) -> None:
        """Clear verified legacy metadata as soon as connection fields differ.

        Args:
            *_trace_args (str): Tk variable-trace name, index and operation.

        Returns:
            None: Linked target labels remain owned by the selected target.
        """
        if self._verified_legacy_gallery is None:
            return
        if (watcher_state.normalize_upload_url(self.gallery_url_var.get())
                != watcher_state.normalize_upload_url(self.config.gallery_url)
                or self.api_key_var.get().strip() != self.config.api_key.strip()):
            self._verified_legacy_gallery = None
            if (self.linking_manager.selected_upload_target() is None
                    and not self.linking_manager.linked_upload_mode_selected()):
                self.gallery_label_var.set(gettext('Target gallery: unknown'))
                self.gallery_title_var.set('')


    def probe_linked_uncertain_upload(
        self, target: watcher_state.LinkedUploadTarget, size: int,
    ) -> tuple[str, str]:
        """Observe one journaled hash under its target's exclusive lease.

        Args:
            target (LinkedUploadTarget): Exact live linked gallery and Admin.
            size (int): Size in bytes explicitly supplied by the user.

        Returns:
            tuple[str, str]: Server observation and the local marker digest.

        Raises:
            ValueError: The journal, session or identity cannot be verified.
        """
        return watcher_state.probe_linked_upload_outcome(target, size)


    def publish_linked_recovery(
        self, target: watcher_state.LinkedUploadTarget, marker: str, publish: Callable[[], None],
    ) -> bool:
        """Publish an observation only while its original journal is current.

        Args:
            target (LinkedUploadTarget): Exact linked gallery and Admin observed.
            marker (str): Digest of the exact locally observed uncertain marker.
            publish (Callable[[], None]): Tk-thread update without journal writes.

        Returns:
            bool: True only if the same marker was revalidated under the lease.
        """
        return watcher_state.publish_linked_upload_observation(
            target, marker, self.linking_manager.selected_upload_target, publish,
        )


    def review_linked_uncertainty(
        self, target: watcher_state.LinkedUploadTarget, marker: Optional[str],
    ) -> Optional[str]:
        """Read or acknowledge one exact retained unknown under its gallery lease.

        Args:
            target (LinkedUploadTarget): Current selected gallery and session.
            marker (Optional[str]): None for inspection; exact inspected marker
                only after explicit user confirmation to continue other files.

        Returns:
            Optional[str]: Current marker after inspection or verified review;
                None when work, the selected target or the journal changed.
        """
        if (self.background_work_active() or not self.linked_workspace_ready()
                or self.linking_manager.selected_upload_target() is not target):
            return None
        return watcher_state.review_linked_upload_marker(target, marker, self.linking_manager.selected_upload_target)


    def capture_linked_target(self) -> Optional[watcher_state.LinkedUploadTarget]:
        """Capture the explicitly selected target without legacy fallback.

        Returns:
            Optional[LinkedUploadTarget]: Live linked target, or None only when
                the user explicitly selected the saved API-key connection.

        Raises:
            ValueError: Linked mode lacks a live session, safe local profile or
                matching saved mode evidence.
        """
        if not self.linking_manager.linked_upload_mode_selected():
            return None
        target = self.linking_manager.selected_upload_target()
        if target is None:
            raise ValueError(
                'Linked gallery access expired. Reconnect or explicitly select '
                'the saved API-key connection.',
            )
        if not self.linked_workspace_ready():
            raise ValueError(tr(
                self.flights.ui_language.get(), 'linking_upload_workspace',
            ))
        try:
            view = self.mode_owner.view()
        except watcher_state.ModeCoordinatorError:
            raise ValueError('Linked mode evidence is unavailable.') from None
        record = view.record
        if (view.state != 'linked_active' or record is None
                or record.mode != 'linked' or record.issuer != target.issuer
                or record.account_id != target.user_id
                or record.gallery_id != target.gallery_id
                or record.scope != target.session.approved_scope()):
            raise ValueError('Linked gallery mode needs verification before uploading.')
        return target


    def _publish_connection_text(
        self, field: Literal["status", "detail"], message: str, **fields: str,
    ) -> None:
        """Publish a connection caption and retain its stable support text.

        Args:
            field (Literal["status", "detail"]): Caption being updated.
            message (str): Source-language caption or received diagnostic text.
            **fields (str): Named display values such as a verified endpoint.

        Returns:
            None: Retains source text separately from the displayed caption.
        """
        evidence = getattr(self, "_connection_diagnostic", None)
        if not isinstance(evidence, dict):
            self._connection_diagnostic = evidence = {}
        evidence[field] = message.format_map(fields) if fields else message
        caption = gettext(message)
        if fields:
            caption = caption.format_map(fields)
        variable = self.connection_status_var if field == "status" else self.connection_detail_var
        variable.set(caption)


    def refresh_connection_controls(self) -> None:
        """Refresh key settings and the shared Watcher/Import session actions.

        Returns:
            None: Selects actions from accepted work and the current mode,
                publishing changes to their wording, state and visibility.
        """
        current_url = self.gallery_url_var.get().strip()
        current_key = self.api_key_var.get().strip()
        saved_url = self.config.gallery_url.strip()
        saved_key = self.config.api_key.strip()
        key_present = bool(current_key)
        credentials_saved = current_url == saved_url and current_key == saved_key
        if not key_present:
            key_status = gettext("API key not configured")
        elif credentials_saved:
            key_status = gettext("API key configured and saved")
        else:
            key_status = gettext("API key or gallery URL changed, not saved")
        _publish_watcher_text(self, "api_key_status_var", key_status)
        blockers = self.api_key_request_blockers()
        if self.api_key_revoke_running:
            blocker_text = gettext(
                "Finishing accepted work. Open Flight progress for the current "
                "stage; the key is retained until required work is confirmed."
            )
        elif blockers:
            blocker_text = gettext("Revoke will stop and finish: {operations}.").format(
                operations="; ".join(blockers),
            )
        elif key_present and not credentials_saved:
            blocker_text = gettext(
                "Revocation unavailable because the connection fields contain "
                "unsaved changes. Save or restore the saved connection first."
            )
        elif key_present:
            blocker_text = gettext(
                "API key can be revoked now. Revocation is permanent for this key "
                "and removes it from the local configuration after server confirmation."
            )
        else:
            blocker_text = gettext(
                "Paste a gallery-scoped API key generated in PHP Gallery Admin "
                "before testing or uploading."
            )
        _publish_watcher_text(self, "revoke_blocker_var", blocker_text)
        enabled = key_present and credentials_saved
        action = self.flight_action_state()
        if action in ("begin", "finish"):
            action_label = self.simulator_presence.action_label(
                action, self.flights.ui_language.get()
            )
            if (action == "finish"
                    and isinstance(getattr(self, "_contextual_watch_target", None),
                                   watcher_state.LinkedUploadTarget)
                    and self.contextual_watcher_is_flight()):
                action_label = self.flights.text("finish_flight")
        else:
            action_label = self.flights.text({
                "progress": "show_upload_progress",
                "recover": "review_pending_uploads",
                "reconnect": "reconnect_upload_gallery",
            }.get(action, "show_upload_progress"))
        button = getattr(self, "revoke_button", None)
        if button is not None:
            _publish_watcher_value(
                self, "revoke_button_enabled", button, enabled,
                lambda: button.state(["!disabled"] if enabled else ["disabled"]),
            )
        show_action = action != "idle"
        for attr in ("finish_flight_button", "import_revoke_button"):
            button = getattr(self, attr, None)
            if button is not None:
                here = show_action and (
                    attr != "finish_flight_button"
                    or type(self)._watcher_connection_selected(self)
                )
                if here:
                    _publish_watcher_value(
                        self, attr + "_text", button, action_label,
                        lambda: button.configure(text=action_label),
                    )
                    _publish_watcher_value(
                        self, attr + "_enabled", button, True,
                        lambda: button.state(["!disabled"]),
                    )
                _publish_watcher_value(
                        self, attr + "_visible", button, here,
                        button.grid if here else button.grid_remove,
                )
        legacy_cleanup = bool(
            saved_key and not self.linking_manager.linked_upload_mode_selected()
            and action != "progress"
        )
        for attr in ("abandon_flight_button", "import_abandon_button"):
            button = getattr(self, attr, None)
            if button is not None:
                here = legacy_cleanup and (
                    attr != "abandon_flight_button"
                    or type(self)._watcher_connection_selected(self)
                )
                if here:
                    abandon_label = self.flights.text("abandon")
                    _publish_watcher_value(
                        self, attr + "_text", button, abandon_label,
                        lambda: button.configure(text=abandon_label),
                    )
                    _publish_watcher_value(
                        self, attr + "_enabled", button, True,
                        lambda: button.state(["!disabled"]),
                    )
                _publish_watcher_value(
                        self, attr + "_visible", button, here,
                        button.grid if here else button.grid_remove,
                )
        flight_detected = self.simulator_presence.flight_detected
        for attr in (
            "simbrief_button", "simbrief_settings_button", "simbrief_status_label",
        ):
            widget = getattr(self, attr, None)
            if widget is not None:
                _publish_watcher_value(
                    self, attr + "_visible", widget, flight_detected,
                    widget.grid if flight_detected else widget.grid_remove,
                )
        entry_state = "disabled" if self.api_key_revoke_running else "normal"
        for attr in ("api_key_entry", "gallery_url_entry"):
            entry = getattr(self, attr, None)
            if entry is not None:
                try:
                    _publish_watcher_value(
                        self, attr + "_state", entry, entry_state,
                        lambda: entry.configure(state=entry_state),
                    )
                except Exception:  # noqa: BLE001
                    logging.debug("Could not refresh connection entry state.", exc_info=True)


    def flight_action_state(
        self,
    ) -> Literal["idle", "begin", "finish", "progress", "recover", "reconnect"]:
        """Resolve the primary action from work and explicit upload mode.

        Returns:
            str: One visible lifecycle, recovery, reconnect or Begin action;
                a Photos-only link and saved legacy key do not hide Begin.
        """
        state = self.flights.session_state()
        if state == "working":
            return "progress"
        if state in ("pending", "blocked"):
            return "recover"
        if state == "active":
            return "finish"
        if self.manual_worker is not None and self.manual_worker.is_alive():
            return "idle"
        if (self.linking_manager.selected_upload_target() is not None
                or self.linking_manager.linked_upload_mode_selected()):
            return "reconnect"
        return "begin"


    def refresh_revoke_button_state(self) -> None:
        """
        Compatibility wrapper that refreshes all connection and revocation controls.


        Returns:
            None: Applies this action only after lifecycle admission succeeds.
        """
        self.refresh_connection_controls()


    def paste_api_key_from_clipboard(self) -> None:
        """Paste one API key from the clipboard into the masked Settings field.

        Returns:
            None: Applies a readable key and refreshes connection controls, or
                reports revocation, unreadable clipboard or absent input.
        """
        if self.api_key_revoke_running:
            runtime.messagebox.showwarning(gettext("API key"), gettext("Wait for API-key revocation to finish before replacing the key."))
            return
        try:
            value = str(self.root.clipboard_get()).strip()
        except Exception:  # noqa: BLE001
            runtime.messagebox.showwarning(gettext("API key"), gettext("The clipboard does not contain text."))
            return
        if not value:
            runtime.messagebox.showwarning(gettext("API key"), gettext("The clipboard does not contain an API key."))
            return
        self.api_key_var.set(value)
        _publish_watcher_text(self, "api_key_status_var", gettext("API key entered but not saved"))
        type(self)._publish_connection_text(self, "status", gettext_noop("Not tested"))
        type(self)._publish_connection_text(
            self, "detail", gettext_noop(
                "API key changed. Save and test the connection before starting work."
            ),
        )
        self.refresh_connection_controls()


    def link_gallery(self) -> None:
        """Run the action for the current site-link state.

        Returns:
            None: Opens the linking prompt or requests unlinking of the live site.
        """
        if not self.updates.blocked():
            if self.linking_manager.linked_address.get():
                self._cancel_ordinary_restart_for_action()
                self.linking_manager.unlink()
            else:
                self.linking_manager.open_address_dialog()


    def open_gallery_links(self) -> None:
        """Open the gallery address manager while a site is selected.

        Returns:
            None: Shows live and recent links, or waits for an active update.
        """
        if not self.updates.blocked():
            self.linking_manager.open_address_dialog()


    def choose_linked_gallery(self) -> None:
        """Show the gallery list beside the linked site's address.

        Returns:
            None: Opens the linked gallery picker or reports that a link is needed.
        """
        if not self.updates.blocked():
            self.linking_manager.open(self.root, self.linking_manager.address())


    def reconnect_upload_gallery(self) -> None:
        """Restore browser access or choose a destination from the linked site.

        Returns:
            None: Opens the existing linking or gallery dialog for this step.
        """
        if not self.updates.blocked():
            linked = self.linking_manager.linked_address.get()
            saved = self.saved_linked_upload_identity()
            if linked and (saved is None or linked == saved[0]):
                self.choose_linked_gallery()
            else:
                self.linking_manager.open_address_dialog(prefill=saved[0] if saved else "")


    def restore_capture_upload_target(
        self, binding: str, on_result: Callable[[bool], None],
    ) -> None:
        """Resolve the original saved API-key or linked capture destination.

        Args:
            binding (str): Exact original site/account/gallery fingerprint.
            on_result (Callable[[bool], None]): Tk callback receiving whether
                that same approved upload target was restored.
        Returns:
            None: Confirms the unchanged saved API-key binding or checks the
                existing linked session and logs the exact verification phase.
        """
        if not type(self)._watcher_connection_selected(self):
            on_result(False)
            return
        generation = getattr(self, '_update_resume_generation', 0)
        selected = self.linking_manager.selected_upload_target()
        if selected is not None:
            matches = selected.binding() == binding
            self.write_log(format_watch_recovery_diagnostic(
                'target', 'active_target_matches' if matches else 'active_target_changed',
            ), 'info' if matches else 'warning')
            on_result(matches)
            return
        try:
            view = self.mode_owner.view()
            if view.state in LEGACY_UPLOAD_STATES:
                url = watcher_state.normalize_upload_url(self.config.gallery_url)
                key = self.config.api_key.strip()
                matches = bool(url and key and connection_binding(url, key) == binding)
                self.write_log(format_watch_recovery_diagnostic(
                    'target', 'legacy_binding_matches' if matches else 'legacy_binding_unavailable',
                ), 'info' if matches else 'warning')
                on_result(matches)
                return
            record = view.record
            if (view.state != 'linked_active' or record is None
                    or record.mode != 'linked'):
                self.write_log(format_watch_recovery_diagnostic(
                    'target', 'saved_upload_mode_unverified',
                ), 'warning')
                on_result(False)
                return
            if linked_target_binding(record.issuer, record.account_id, record.gallery_id) != binding:
                self.write_log(format_watch_recovery_diagnostic(
                    'target', 'saved_gallery_binding_changed',
                ), 'warning')
                on_result(False)
                return
            session = self.linking_manager.resume_upload_session(
                record.issuer, record.account_id,
            )
            if session is None:
                browsing = self.linking_manager.photos_session()
                identity = (self.linking_manager.photos_site_identity(browsing)
                            if browsing is not None else None)
                reason = 'original_upload_link_unavailable'
                if identity == (record.issuer, record.account_id):
                    reason = 'original_account_lacks_upload_scope'
                elif identity is not None and identity[0] == record.issuer:
                    reason = 'different_account_on_original_site'
                self.write_log(format_watch_recovery_diagnostic('target', reason), 'warning')
                self.linking_manager.resume_saved_link(record.issuer)
                on_result(False)
                return
            scope = session.approved_scope()
        except (OSError, ValueError, RuntimeError) as exc:
            self.write_log(format_watch_recovery_diagnostic(
                'target', 'saved_link_check_failed', error=exc,
            ), 'warning')
            on_result(False)
            return
        self.write_log(format_watch_recovery_diagnostic(
            'target', 'verifying_original_gallery',
        ), 'info')

        def verify() -> None:
            """Check exact gallery access off Tk, preserving the original mode.

            Returns:
                None: Delivers a target or a fixed failure to the Tk event owner.
            """
            reason = 'gallery_verification_failed'
            diagnostic = ''
            try:
                target = verify_linked_upload_target(
                    session, record.issuer, record.account_id, scope, record.gallery_id,
                )
                # The normal admission owner takes its own OS-backed work lock.
                # Do not nest that non-reentrant file lock. Mode publication
                # separately compares the exact captured view under its mutex.
                reason = 'local_target_admission_refused'
                if (getattr(self, '_update_resume_generation', 0) != generation
                        or not type(self)._watcher_connection_selected(self)
                        or not self.commit_linked_upload_target(target, expected=view)):
                    raise ValueError('Saved upload target changed during recovery.')
                committed_record = self.mode_owner.view().record
            except Exception as exc:  # noqa: BLE001
                target = None
                committed_record = None
                diagnostic = format_watch_recovery_diagnostic('target', reason, error=exc)
            self.events.put({'kind': 'capture_target_restored', 'payload': {
                'target': target, 'record': committed_record, 'binding': binding,
                'scope': scope, 'on_result': on_result, 'diagnostic': diagnostic,
                'generation': generation,
            }})

        try:
            watcher_state.threading.Thread(
                target=verify, name='PHPGalleryCaptureTargetRestore', daemon=True,
            ).start()
        except RuntimeError as exc:
            self.write_log(format_watch_recovery_diagnostic(
                'target', 'verifier_start_failed', error=exc,
            ), 'warning')
            on_result(False)


    def manage_gallery_link(self) -> None:
        """Open the access controls for the active or entered installation.

        Returns:
            None: Shows access controls or requests a separate linking address.
        """
        if not self.updates.blocked():
            address = self.linking_manager.address()
            self.linking_manager.open(self.root, address, manage=True)


    def open_connection_dialog(self) -> None:
        """
        Open the legacy gallery URL and API-key editor.

        The dialog works with temporary values until Save or Save & Test is
        pressed, so closing it cannot accidentally replace a working key.

        Returns:
            None: Displays the URL and API-key controls.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        self.restore_from_tray()
        dialog = EmbeddedWindow(self.root)
        dialog.title(gettext("Gallery connection and API key"))
        dialog.transient(self.root)
        dialog.geometry(CONNECTION_DIALOG_GEOMETRY)
        dialog.minsize(*CONNECTION_DIALOG_MINIMUM)
        dialog.resizable(True, False)
        dialog.columnconfigure(0, weight=1)
        frame = GlassCard(dialog, padding=(18, 14))
        frame.grid(row=0, column=0, sticky="nsew")
        frame.columnconfigure(1, weight=1)

        url_var = runtime.tk.StringVar(value=self.gallery_url_var.get())
        key_var = runtime.tk.StringVar(value=self.api_key_var.get())
        reveal_var = runtime.tk.BooleanVar(value=False)
        runtime.ttk.Label(frame, text=gettext("PHP Gallery connection"), style="Section.TLabel").grid(row=0, column=0, columnspan=4, sticky="w", pady=(0, 4))
        runtime.ttk.Label(
            frame,
            text=gettext("Use a gallery-scoped API key from Admin. Your password is not entered into this app."),
            wraplength=520,
            style="Muted.TLabel",
        ).grid(row=1, column=0, columnspan=4, sticky="w", pady=(0, 8))
        runtime.ttk.Label(frame, text=gettext("Gallery URL")).grid(row=2, column=0, sticky="w", padx=(0, 8), pady=6)
        url_entry = runtime.ttk.Entry(frame, textvariable=url_var, width=70)
        url_entry.grid(row=2, column=1, columnspan=3, sticky="ew", pady=6)
        runtime.ttk.Label(frame, text=gettext("API key")).grid(row=3, column=0, sticky="w", padx=(0, 8), pady=6)
        key_entry = runtime.ttk.Entry(frame, textvariable=key_var, show="*", width=54)
        key_entry.grid(row=3, column=1, sticky="ew", pady=6)

        def toggle_reveal() -> None:
            """Reveal or mask the temporary credential field.

            Returns:
                None: Updates only the dialog's API-key display mode.
            """
            key_entry.configure(show="" if reveal_var.get() else "*")

        def paste_key() -> None:
            """Paste clipboard text into the dialog's temporary API-key field.

            Returns:
                None: Leaves stored credentials unchanged until a save action.
            """
            try:
                value = str(dialog.clipboard_get()).strip()
            except Exception:  # noqa: BLE001
                runtime.messagebox.showwarning(gettext("API key"), gettext("The clipboard does not contain text."), parent=dialog)
                return
            if not value:
                runtime.messagebox.showwarning(gettext("API key"), gettext("The clipboard does not contain an API key."), parent=dialog)
                return
            key_var.set(value)

        runtime.ttk.Checkbutton(frame, text=gettext("Reveal"), variable=reveal_var, command=toggle_reveal).grid(row=3, column=2, padx=6, pady=6)
        Button(frame, text=gettext("Paste"), command=paste_key).grid(row=3, column=3, pady=6)
        status = gettext("A key is currently stored locally.") if self.api_key_var.get().strip() else gettext("No API key is currently stored.")
        runtime.ttk.Label(frame, text=status, style="Muted.TLabel").grid(row=4, column=1, columnspan=3, sticky="w", pady=(0, 8))

        def apply_values(test_after: bool) -> None:
            """Validate, store, and optionally test temporary credentials.

            Args:
                test_after (bool): Whether to test authentication after saving.

            Returns:
                None: Preserves the current stored key when validation fails.
            """
            if self.updates.blocked():
                return
            if self.api_key_revoke_running:
                runtime.messagebox.showwarning(gettext("Connection"), gettext("Wait for API-key revocation to finish before changing credentials."), parent=dialog)
                return
            url = url_var.get().strip()
            key = key_var.get().strip()
            if not watcher_state.normalize_upload_url(url):
                runtime.messagebox.showerror(gettext("Connection"), gettext("Enter a valid HTTP(S) Gallery URL or upload endpoint."), parent=dialog)
                return
            if not key:
                runtime.messagebox.showerror(gettext("Connection"), gettext("Paste the gallery-scoped API key before saving."), parent=dialog)
                return
            canonical_url = watcher_state.normalize_upload_url(url)
            self.gallery_url_var.set(canonical_url)
            self.api_key_var.set(key)
            if not self.save_config():
                return
            dialog.destroy()
            type(self)._publish_connection_text(self, "status", gettext_noop("Not tested"))
            type(self)._publish_connection_text(
                self, "detail", gettext_noop("Connection saved. Testing authentication...")
                if test_after else gettext_noop(
                    "Connection saved. Test authentication before starting work."
                ),
            )
            self.refresh_connection_controls()
            if test_after:
                self.test_connection()

        revoke = Button(
            frame, text=gettext("Revoke saved API key..."),
            command=lambda: (dialog.destroy(), self.revoke_api_key()),
        )
        revoke.grid(row=5, column=0, columnspan=2, sticky="w", pady=(0, 3))
        if not self.api_key_var.get().strip():
            revoke.state(["disabled"])
        if (self.config.api_key.strip()
                and self.linking_manager.linked_upload_mode_selected()):
            def use_saved_key() -> None:
                """Select the saved key without changing the browser link.

                Returns:
                    None: Closes the editor on success or explains a refusal.
                """
                refusal = self.linking_manager.use_saved_api_key()
                if refusal is not None:
                    runtime.messagebox.showwarning(gettext("Upload via API key"), refusal, parent=dialog)
                else:
                    dialog.destroy()

            Button(
                frame, text=gettext("Use saved API key"), command=use_saved_key,
                style="Compact.TButton",
            ).grid(row=5, column=2, columnspan=2, sticky="e", pady=(0, 3))
        buttons = runtime.ttk.Frame(frame, style="Card.TFrame")
        buttons.grid(row=6, column=0, columnspan=4, sticky="ew", pady=(3, 0))
        buttons.columnconfigure(0, weight=1)
        Button(
            buttons, text=gettext("Cancel"), command=dialog.destroy,
        ).grid(row=0, column=1, padx=(0, 8))
        Button(
            buttons, text=gettext("Save"), command=lambda: apply_values(False),
        ).grid(row=0, column=2, padx=(0, 8))
        Button(
            buttons,
            text=gettext("✓  Save & Test"),
            command=lambda: apply_values(True),
            style="Primary.TButton",
        ).grid(row=0, column=3)
        dialog.bind("<Escape>", lambda _event: dialog.destroy())
        dialog.update_idletasks()
        screen_width = dialog.winfo_screenwidth()
        screen_height = dialog.winfo_screenheight()
        margin_width, margin_height = CONNECTION_DIALOG_SCREEN_MARGIN
        minimum_width, minimum_height = CONNECTION_DIALOG_MINIMUM
        width = min(
            max(dialog.winfo_reqwidth(), minimum_width),
            max(minimum_width, screen_width - margin_width),
        )
        height = min(
            max(dialog.winfo_reqheight(), minimum_height),
            max(minimum_height, screen_height - margin_height),
        )
        parent_x = self.root.winfo_rootx()
        parent_y = self.root.winfo_rooty()
        parent_width = self.root.winfo_width()
        parent_height = self.root.winfo_height()
        x = min(
            max(0, parent_x + (parent_width - width) // 2),
            max(0, screen_width - width - margin_width),
        )
        y = min(
            max(0, parent_y + (parent_height - height) // 2),
            max(0, screen_height - height - margin_height),
        )
        dialog.geometry(f"{width}x{height}+{x}+{y}")
        if url_var.get().strip():
            key_entry.focus_set()
        else:
            url_entry.focus_set()
        dialog.grab_set()


    def flight_action(self) -> None:
        """Run the same lifecycle action shown by Watcher and Import.

        Returns:
            None: Finishes capture, opens progress or retained-work review,
                or offers connection setup before work is accepted.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            return
        if self.updates.blocked():
            return
        if (getattr(self, '_queue_recovery_pending', False) is True
                and self.flight_action_state() == 'finish'):
            self.worker.request_drain()
            self._queue_recovery_finish_requested = True
            self.write_log('Finish saved; waiting for the existing queue recovery.', 'info')
            return
        action = self.flight_action_state()
        if action == "begin":
            self.open_connection_dialog()
        elif action == "finish":
            self._cancel_ordinary_restart_for_action()
            self._next_finish_trigger_reason = 'explicit_finish'
            if (isinstance(getattr(self, "_contextual_watch_target", None),
                           watcher_state.LinkedUploadTarget)
                    and not self.contextual_watcher_is_flight()):
                self.flights.begin(False, automatic=True, photos_only=True)
            elif isinstance(getattr(self, '_contextual_watch_target', None), watcher_state.LinkedUploadTarget):
                self.flights.finish_report()
            else:
                self.flights.finish_report(revoke=True)
        elif action == "progress":
            self.flights.show_progress()
        elif action == "recover":
            self.flights.show_recovery()
        elif action == "reconnect":
            self.reconnect_upload_gallery()


    def abandon_flight(self) -> None:
        """Offer explicit local recovery for a deleted or unreachable gallery.

        Returns:
            None: Uses the shared offline stop/abandon/key-cleanup workflow
                after confirmation; update ownership keeps its existing gate.
        """
        if (type(self).watcher_reset_blocked(self)
                or self.updates.blocked() or not self.config.api_key.strip()
                or self.linking_manager.linked_upload_mode_selected()):
            return
        self.flights.abandon()


    def forget_flight_connection(self, binding: str) -> None:
        """Persist removal of the captured key before updating visible state.

        Args:
            binding (str): Fingerprint of the explicitly abandoned saved
                endpoint and key, checked again immediately before persistence.
        Returns:
            None: Clears the saved key, resets the target and records local
                abandonment without claiming server revocation.
        Raises:
            ValueError: The saved endpoint/key no longer matches the decision.
            Exception: Persistence failure retains the original saved key and UI.
        """
        if binding != self.flights.binding():
            raise ValueError("Saved connection changed before local key cleanup.")
        cleared_config = watcher_state.replace(self.config, api_key="")
        cleared_config = self._save_owned_config(cleared_config)
        self.config = cleared_config
        self.api_key_var.set("")
        self._verified_legacy_gallery = None
        type(self)._publish_connection_text(self, "status", gettext_noop("Not configured"))
        self.gallery_label_var.set(gettext("Target gallery: unknown"))
        self.gallery_title_var.set("")
        message = tr("en", "flight_abandoned")
        type(self)._publish_connection_text(self, "detail", message)
        self.connection_detail_var.set(self.flights.text("flight_abandoned"))
        self.write_log(message, "system")
        self.record_activity("info", message, "system")
        self.refresh_connection_controls()


    def complete_legacy_revocation(self, operation: FinalizationWorker) -> None:
        """Clear only the captured key after its exact server-confirmed Finish.

        Args:
            operation (FinalizationWorker): Exact current finalizer retaining its
                legacy_revoke work and captured configuration generation.
        Returns:
            None: Atomically exchanges the sole revoke ticket for a verified
                ConfigStore save. Failure keeps cleanup visibly unconfirmed;
                a stale event cannot clear or acknowledge a newer operation.
        """
        if operation is not self.flights.operation or not operation.server_revocation_confirmed:
            return
        work: Optional[CoordinatedWork] = None
        try:
            if (operation.config_generation is None
                    or operation.config_generation is not self.config._snapshot_token
                    or operation.api_key != self.config.api_key
                    or operation.upload_url != watcher_state.normalize_upload_url(self.config.gallery_url)):
                raise ValueError('Captured revocation configuration changed.')
            work = operation.revocation_work()
            work.checkpoint()
            cleared = self._save_owned_config(
                watcher_state.replace(self.config, api_key=''), completed_work=work,
            )
        except Exception:  # noqa: BLE001
            detail = gettext_noop('Server revocation confirmed; local key cleanup needs review.')
            retryable = False
            if work is not None:
                try:
                    work.checkpoint()
                    retryable = True
                except RuntimeError:
                    pass
            operation.confirm_local_cleanup(detail, retryable=retryable)
            type(self)._publish_connection_text(self, "detail", detail)
            self.write_log(detail, 'error')
            self.record_activity('error', detail, 'system')
        else:
            self.config = cleared
            self.api_key_var.set('')
            self._verified_legacy_gallery = None
            type(self)._publish_connection_text(self, "status", gettext_noop('Not configured'))
            self.gallery_label_var.set(gettext('Target gallery: unknown'))
            self.gallery_title_var.set('')
            type(self)._publish_connection_text(
                self, "detail", gettext_noop('API key revoked and removed from local configuration.'),
            )
            operation.confirm_local_cleanup()
            self.write_log('Gallery API key revoked.', 'success')
            self.record_activity(
                'success', gettext_noop('Gallery API key revoked.'), 'system',
            )
        self.api_key_revoke_running = operation.local_cleanup_pending
        self.api_key_revoke_thread = operation if operation.local_cleanup_pending else None
        self.refresh_connection_controls()


    def revoke_api_key(self) -> None:
        """Confirm one combined stop, finish, upload and key-revocation action.

        Returns:
            None: Reuses finalization progress and never revokes before receipts.
        """
        if self.updates.blocked():
            return
        if (
            self.gallery_url_var.get().strip() != self.config.gallery_url.strip()
            or self.api_key_var.get().strip() != self.config.api_key.strip()
        ):
            runtime.messagebox.showwarning(
                gettext("Revoke API key"),
                gettext("Save or restore connection fields before revoking the saved key."),
            )
            return
        if not watcher_state.normalize_upload_url(self.config.gallery_url) or not self.config.api_key:
            runtime.messagebox.showwarning(gettext("Revoke API key"), gettext("A saved connection is required."))
            return
        self._verified_legacy_gallery = None
        self.gallery_label_var.set(gettext("Target gallery: unknown"))
        self.gallery_title_var.set("")
        self.flights.begin(True)
