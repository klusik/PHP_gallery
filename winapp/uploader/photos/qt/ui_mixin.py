# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/qt/ui_mixin.py
# Module Type: Qt Photos domain mixin
# Purpose: Keep one cohesive Photos interaction domain outside the widget facade.
# Responsibilities: Preserve the QtPhotos lifecycle while grouping its callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Qt Photos behavior mixin declarations."""

from .support import (
    ActionButton,
    Callable,
    Event,
    FOLDER_AUTO_SHARE,
    FOLDER_PHOTO_RESERVE,
    GALLERY_FOLDER_SHARE_BOUNDS,
    GALLERY_FOLDER_SHARE_SCALE,
    LIGHTBOX_EDGE_INSET,
    LIGHTBOX_TOOLBAR_TOP_INSET,
    LinkedPhoto,
    LinkedPhotos,
    LinkingError,
    LinkingProgress,
    LinkingSession,
    LiveSiteCatalogue,
    MEDIA_RECENT_ORDER_PHOTO_LIMIT,
    OfflineRefreshPending,
    PALETTE,
    PHOTOS_CARD_FORMAT_ICON_SIZE,
    PHOTOS_HEADING_STACK_WIDTH,
    PHOTOS_HEADING_TITLE_MINIMUM,
    PHOTO_CARD_FORMATS,
    PHOTO_CARD_MARGINS,
    PHOTO_FORMAT_HORIZONTAL_INSET,
    PHOTO_FORMAT_VERTICAL_INSET,
    PHOTO_HEADING_RECORDING_SIZE,
    PHOTO_LOCATION_MAP_ZOOM,
    PHOTO_TREE_MARKER_SIZE,
    Path,
    PhotoCatalogue,
    PhotoRow,
    QApplication,
    QCheckBox,
    QDialog,
    QFileDialog,
    QHBoxLayout,
    QHideEvent,
    QIcon,
    QLabel,
    QMenu,
    QPoint,
    QRectF,
    QShowEvent,
    QSignalBlocker,
    QSize,
    QToolButton,
    QVBoxLayout,
    Qt,
    QtGalleryCacheProbe,
    ReadCancelled,
    SavedLinkRecovery,
    UI_PHOTO_FORMAT_LABEL_POINTS,
    desktop_upload_vault,
    discover_linking_capabilities,
    gettext,
    isfinite,
    linking_issuer,
    logging,
    probe_gallery_cache_status,
    qt_icon,
    requested_linking_scope,
    save_gallery_card_format,
    save_gallery_folder_split,
    save_gallery_photo_order,
    saved_desktop_gallery_binding,
    sys,
    tr,
    webbrowser,
)

class QtPhotosUiMixin:
    """Own one cohesive set of Qt Photos widget behaviors."""

    def _make_format_button(
        self, ratio: str, select: Callable[[str], None], *, folders: bool,
    ) -> QToolButton:
        """Use the same icon, visible label and focus treatment in both format strips.

        Args:
            ratio (str): Shared card format key.
            select (Callable[[str], None]): Applies one explicit local format.
            folders (bool): Build the separate subgallery format control.
        Returns:
            QToolButton: Localized toggle with an accessible visible caption.
        """
        caption = self._text('card_format_default') if ratio == 'default' else ratio
        prefix = 'photosFolderFormat' if folders else 'photosCardFormat'
        button = QToolButton()
        button.setObjectName(prefix + ''.join(part.title() for part in ratio.split(':')))
        button.setText(caption)
        button.setAccessibleName(caption)
        button.setToolTip(self._text('folder_format_hint' if folders else 'card_format_hint'))
        button.setToolButtonStyle(Qt.ToolButtonStyle.ToolButtonTextUnderIcon)
        button.setIconSize(QSize(*PHOTOS_CARD_FORMAT_ICON_SIZE))
        button.setIcon(qt_icon(
            'aspect_' + ratio, size=PHOTOS_CARD_FORMAT_ICON_SIZE, tone='muted', checked_tone='primary',
        ))
        button.setCheckable(True)
        caption_font = button.font()
        caption_font.setPointSize(UI_PHOTO_FORMAT_LABEL_POINTS)
        button.setFont(caption_font)
        button.setStyleSheet(
            'QToolButton {'
            f'color: {PALETTE["muted"]}; background: transparent; '
            'border: 1px solid transparent; border-radius: 6px; padding: 1px 3px; '
            '} QToolButton:checked {'
            f'color: {PALETTE["primary"]}; background: {PALETTE["surface_alt"]}; '
            f'border-color: {PALETTE["line"]}; '
            '} QToolButton:focus {'
            f'border-color: {PALETTE["focus"]}; '
            '} QToolButton:disabled {'
            f'color: {PALETTE["muted"]}; background: {PALETTE["surface"]}; }}'
        )
        content_width = max(button.iconSize().width(), button.fontMetrics().horizontalAdvance(caption))
        button.setFixedWidth(content_width + PHOTO_FORMAT_HORIZONTAL_INSET)
        button.ensurePolished()
        button.setFixedHeight(max(
            button.sizeHint().height(),
            button.iconSize().height() + button.fontMetrics().height() + PHOTO_FORMAT_VERTICAL_INSET,
        ))
        button.clicked.connect(lambda _checked=False: select(ratio))
        return button
    def prepare_graphics_readiness(self) -> None:
        """Expose the real Quick scene for the launcher's first-frame proof.

        Returns:
            None: The shell stays transparent until this scene completes a frame.
        """
        if (self._identity is None and not self._local_photos_active
                and not self.detailOpen):
            self._gallery_panel.hide()
            self._center_toolbar.hide()
            self._transport_row.hide()
            self._split.show()
    def finish_graphics_readiness(self) -> None:
        """Restore normal Photos pane visibility after the first Quick frame.

        Returns:
            None: A disconnected page returns to its single Connect card state.
        """
        self._sync_connection_state()
    def _layout_format_buttons(self) -> None:
        """Wrap ratio controls only when the available Photos width requires it.

        Returns:
            None: Preserves Tk's ratio order and keeps every caption visible.
        """
        width = self._format_actions.width()
        if width <= 0:
            return
        buttons = tuple(self._format_buttons.values())
        columns = len(buttons)
        layout = self._format_actions.layout()
        spacing = layout.horizontalSpacing()
        notice_width = (
            self._format_notice.sizeHint().width()
            if self._format_notice.isVisible() else 0
        )
        while columns > 1:
            needed = sum(
                max(button.minimumWidth() for button in buttons[column::columns])
                for column in range(columns)
            ) + spacing * (columns - 1)
            if notice_width:
                needed += notice_width + spacing
            if needed <= width:
                break
            columns -= 1
        if columns != self._format_columns:
            self._format_columns = columns
            for button in buttons:
                layout.removeWidget(button)
            for index, button in enumerate(buttons):
                layout.addWidget(button, index // columns, index % columns)
            layout.removeWidget(self._format_notice)
            notice_row = (len(buttons) - 1) // columns
            layout.addWidget(self._format_notice, notice_row, columns)
        rows = (len(buttons) + columns - 1) // columns
        row_height = max(button.minimumHeight() for button in buttons)
        height = rows * row_height + (rows - 1) * layout.verticalSpacing()
        if self._format_actions.minimumHeight() != height:
            self._format_actions.setFixedHeight(height)
    def _layout_gallery_heading(self) -> None:
        """Match the reference's right-aligned controls and narrow rows.

        Returns:
            None: Preserves real text and action widths at narrow viewport sizes.
        """
        layout = self._gallery_header_layout
        has_header_formats = layout.indexOf(self._format_row) >= 0 and not self._format_row.isHidden()
        ratio_width = sum(button.minimumWidth() for button in self._format_buttons.values())
        spacing = self._format_actions.layout().horizontalSpacing()
        ratio_width += spacing * (len(self._format_buttons) - 1)
        if not self._format_notice.isHidden():
            ratio_width += self._format_notice.sizeHint().width() + spacing
        format_width = (
            ratio_width + self._order_button.sizeHint().width()
            + self._format_row.layout().spacing()
            if has_header_formats else 0
        )
        group_spacing = layout.horizontalSpacing()
        group_width = self._gallery_heading_actions.sizeHint().width() + group_spacing
        if has_header_formats:
            group_width += format_width + group_spacing
        width = self._center_toolbar.width()
        stacked = (width < PHOTOS_HEADING_STACK_WIDTH
                   or group_width + PHOTOS_HEADING_TITLE_MINIMUM > width)
        paired = stacked and has_header_formats and group_width <= width
        minimum_formats = format_width if not stacked else 0
        if self._format_row.minimumWidth() != minimum_formats:
            self._format_row.setMinimumWidth(minimum_formats)
        title_width = self._gallery_title.fontMetrics().horizontalAdvance(self._gallery_title.text())
        title_actions_width = 0 if self._edit_title_button.isHidden() else (
            self._edit_title_button.sizeHint().width() + self._gallery_heading_title.layout().spacing()
        )
        if not self._capture_heading.isHidden():
            title_actions_width += PHOTO_HEADING_RECORDING_SIZE + self._gallery_heading_title.layout().spacing()
        title_available = width - title_actions_width - (0 if stacked else group_width)
        title_fits = title_width <= title_available
        self._gallery_title.setWordWrap(not title_fits)
        self._gallery_title.setMinimumWidth(title_width if title_fits else 0)
        # Grid coordinates are structural slots matching the Tk title/control rows.
        positions = [(self._gallery_heading_title, (0, 0, 1, 3 if stacked else 1))]
        if not stacked or paired:
            positions.append((self._gallery_heading_actions, (1 if paired else 0, 2, 1, 1)))
        else:
            positions.append((self._gallery_heading_actions, (2 if has_header_formats else 1, 0, 1, 3)))
        if has_header_formats:
            format_position = (1, 0, 1, 2 if paired else 3) if stacked else (0, 1, 1, 1)
            positions.append((self._format_row, format_position))
        for widget, position in positions:
            if tuple(layout.getItemPosition(layout.indexOf(widget))) != position:
                layout.removeWidget(widget)
                alignment = Qt.AlignmentFlag(0)
                if widget is self._gallery_heading_actions:
                    alignment = (Qt.AlignmentFlag.AlignRight if not stacked or paired
                                 else Qt.AlignmentFlag.AlignLeft)
                layout.addWidget(widget, *position, alignment=alignment)
    def _sync_folder_section(self) -> None:
        """Place direct subgalleries and the independent Photos strip in their sections.

        Returns:
            None: Updates only live child cards, controls and split placement.
        """
        session, gallery = self._session, self._gallery_id
        enabled = session is not None and gallery is not None and session.available()
        detail = self._lightbox_layout_restore is not None
        rows = self._gallery_children.get(gallery, ()) if enabled and gallery is not None else ()
        self._subgallery_switch.setVisible(bool(rows) and not detail)
        self._create_child_button.setVisible(enabled and not detail)
        self._edit_title_button.setVisible(enabled and not detail)
        self._create_child_button.setEnabled(enabled)
        folders = bool(rows) and self._subgallery_switch.isChecked() and not detail
        known_empty = self._gallery_has_photos is False
        mixed = folders and not known_empty
        if self._using_folder_section != mixed:
            self._using_folder_section = mixed
            if mixed:
                self._gallery_header_layout.removeWidget(self._format_row)
                self._photo_pane_layout.insertWidget(0, self._format_row)
            else:
                self._photo_pane_layout.removeWidget(self._format_row)
                self._gallery_header_layout.addWidget(self._format_row, 0, 1)
        self._folder_shelf.configure(
            session, gallery, rows[::-1] if self._folder_newest else rows, self._folder_format,
        )
        self._folder_section.setVisible(folders)
        self._photo_pane.setVisible(not folders or not known_empty)
        self._format_row.setVisible(enabled and self._gallery_has_photos is True and not detail)
        self._sync_simbrief_grid_action()
        self._empty_gallery_message.setVisible(enabled and known_empty and not folders and not detail)
        self._sync_format_actions()
        self._sync_folder_format_actions()
        self._layout_folder_formats()
        self._layout_folder_split()
        self._layout_gallery_heading()
    def set_capture_target(self, target: tuple[str, int, int] | None) -> None:
        """Display the exact current linked Watcher ledger's gallery marker.

        Args:
            target (tuple[str, int, int] | None): Operation's normalized issuer,
                account and gallery IDs, or None when the worker no longer owns them.
        Returns:
            None: Updates only the matching authorized tree and current heading.
        """
        if self._capture_target != target:
            self._capture_target = target
            self._capture_pulse = True
            self._sync_capture_markers()
            self._sync_simbrief_grid_action()
    def _refresh_tree_marker(self, gallery_id: int) -> None:
        """Compose observed cache, pin, and active Watcher states for one row.

        Args:
            gallery_id (int): Exact gallery represented by a mounted tree item.
        Returns:
            None: Updates the existing icon, tooltip and accessible description.
        """
        item = self._gallery_items.get(gallery_id)
        if item is not None:
            marker = self._gallery_state_icon(gallery_id, show_capture=True)
            if item.icon(0).cacheKey() != marker.cacheKey():
                item.setIcon(0, marker)
            description = self._gallery_marker_description(gallery_id)
            if item.toolTip(0) != description:
                item.setToolTip(0, description)
                item.setData(0, Qt.ItemDataRole.AccessibleDescriptionRole, description)
        if self._gallery_id == gallery_id:
            status = self._gallery_state_icon(gallery_id, show_capture=False)
            if self._gallery_options.icon().cacheKey() != status.cacheKey():
                self._gallery_options.setIcon(status)
    def _gallery_marker_description(self, gallery_id: int) -> str:
        """Describe each visible part of a gallery's composed status icon.

        Args:
            gallery_id (int): Gallery represented by this native tree item.
        Returns:
            str: Localized cache availability and any pin or Watcher overlays.
        """
        state = self._cache_states.get(gallery_id, 'unknown')
        lines = [self._text(f'cache_{state}')]
        if self._gallery_pins.get(gallery_id) is True:
            lines.append(self._text('cache_marker_pinned'))
        if gallery_id in self._capture_markers:
            lines.append(self._text(
                'cache_marker_watcher_descendant'
                if self._capture_marker_hollows.get(gallery_id) else 'cache_marker_watcher',
            ))
        return '\n'.join(lines)
    def _gallery_state_icon(self, gallery_id: int | None, *, show_capture: bool) -> QIcon:
        """Reuse the local cache glyph and optional Watcher overlay.

        Args:
            gallery_id (int | None): Exact gallery or the unknown default.
            show_capture (bool): Include the scoped Watcher state for tree rows.
        Returns:
            QIcon: Shared local status artwork at the tree and menu glyph size.
        """
        state = (self._cache_states.get(gallery_id) if gallery_id is not None else None) or 'unknown'
        pinned = gallery_id is not None and self._gallery_pins.get(gallery_id) is True
        recording = show_capture and gallery_id is not None and gallery_id in self._capture_markers
        hollow = recording and self._capture_marker_hollows.get(gallery_id, False)
        pulse = recording and self._capture_pulse
        marker = state, pinned, recording, hollow, pulse
        icon = self._tree_marker_icons.get(marker)
        if icon is None:
            icon = qt_icon(
                state, size=PHOTO_TREE_MARKER_SIZE, pinned=pinned,
                recording=recording, hollow=hollow, pulse=pulse, item_view=True,
            )
            self._tree_marker_icons[marker] = icon
        return icon
    def _queue_gallery_cache_probe(self, rows: tuple[PhotoRow, ...]) -> None:
        """Probe only already loaded linked rows while Photos is visible.

        Args:
            rows (tuple[PhotoRow, ...]): Current complete catalogue snapshot.
        Returns:
            None: Admits local cache-header reads through one cancellable lane.
        """
        session, gallery = self._session, self._gallery_id
        if (not self._photos_active or not self.isVisible() or self._local_photos_active
                or session is None or gallery is None or not session.available()):
            return
        revision = self._revision
        key = (session, gallery, revision)
        if self._cache_probe_key == key:
            return
        self._cache_probe_key = key

        def probe(cancelled: Event, publish: Callable[[QtGalleryCacheProbe], None]) -> None:
            """Read local cache metadata for the captured authorized snapshot.

            Args:
                cancelled (Event): Current Photos generation cancellation fence.
                publish (Callable[[QtGalleryCacheProbe], None]): Ordered lane result.
            Returns:
                None: No gallery listing or media transfer is performed.
            """
            if cancelled.is_set():
                raise ReadCancelled
            sources = tuple(row.source for row in rows if isinstance(row.source, LinkedPhoto))
            if len(sources) != len(rows):
                return
            row = probe_gallery_cache_status(
                session, gallery, sources or None, bool(sources), cancelled,
            )
            if row is None:
                raise ReadCancelled
            publish(QtGalleryCacheProbe(session, gallery, revision, row))

        self._cache_probe.submit(probe)
    def _receive_cache_probe(self, result: QtGalleryCacheProbe) -> None:
        """Apply a cache observation only to its unchanged Qt gallery owner.

        Args:
            result (QtGalleryCacheProbe): Local read tied to a session and revision.
        Returns:
            None: Refreshes the exact tree marker without touching photo pixels.
        """
        if (not self._photos_active or self._session is not result.session
                or self._gallery_id != result.gallery_id
                or self._revision != result.revision
                or not result.session.available()):
            return
        state, _cached, _total, _complete, pinned = result.row
        if state is not None:
            self._cache_states[result.gallery_id] = state
        else:
            self._cache_states.pop(result.gallery_id, None)
        if pinned is not None:
            self._gallery_pins[result.gallery_id] = pinned
        else:
            self._gallery_pins.pop(result.gallery_id, None)
        self._refresh_tree_marker(result.gallery_id)
    def _sync_capture_markers(self) -> None:
        """Refresh the active tree row, collapsed ancestors and selected marker.

        Returns:
            None: Leaves unrelated account, selection and renderer state unchanged.
        """
        target = self._capture_target
        markers: dict[int, bool] = {}
        if (target is not None and target[:2] == self._identity
                and self._session is not None and self._session.available()):
            item = self._gallery_items.get(target[2])
            if item is not None:
                markers[target[2]] = False
                ancestor = item.parent()
                while ancestor is not None:
                    if not ancestor.isExpanded():
                        gallery = int(ancestor.data(0, Qt.ItemDataRole.UserRole))
                        markers[gallery] = True
                    ancestor = ancestor.parent()
        changed = self._capture_markers.union(markers)
        self._capture_markers = set(markers)
        self._capture_marker_hollows = markers
        for gallery in changed:
            self._refresh_tree_marker(gallery)
        heading_marked = target is not None and target[2] == self._gallery_id and target[2] in markers
        was_marked = not self._capture_heading.isHidden()
        if heading_marked:
            icon = qt_icon('recording', size=PHOTO_HEADING_RECORDING_SIZE, pulse=self._capture_pulse)
            self._capture_heading.setPixmap(icon.pixmap(PHOTO_HEADING_RECORDING_SIZE))
        if heading_marked != was_marked:
            self._capture_heading.setVisible(heading_marked)
            self._layout_gallery_heading()
        self._sync_capture_pulse()
    def _sync_capture_pulse(self, _state: Qt.ApplicationState | None = None) -> None:
        """Animate the marker only in an active visible Photos workspace.

        Args:
            _state (Qt.ApplicationState | None): Optional native application state notification.
        Returns:
            None: A hidden, minimized or inactive view has no marker timer wakeups.
        """
        active = (bool(self._capture_markers) and self.isVisible() and not self.detailOpen
                  and not self.window().isMinimized()
                  and QApplication.applicationState() == Qt.ApplicationState.ApplicationActive)
        if active:
            if not self._capture_pulse_timer.isActive():
                self._capture_pulse_timer.start()
        else:
            self._capture_pulse_timer.stop()
    def _pulse_capture_marker(self) -> None:
        """Change only the visible scoped recording glyph's retained palette.

        Returns:
            None: Reuses the icon cache and never reads a gallery or recorder.
        """
        self._sync_capture_pulse()
        if self._capture_pulse_timer.isActive():
            self._capture_pulse = not self._capture_pulse
            self._sync_capture_markers()
    def _layout_folder_formats(self) -> None:
        """Wrap independent folder formats when a larger tree or DPI reduces space.

        Returns:
            None: All ratio captions and chronological order remain reachable.
        """
        if self._folder_section.isHidden():
            return
        layout = self._folder_formats.layout()
        controls = (self._folder_order, *self._folder_format_buttons.values())
        row = column = used = 0
        width = self._folder_formats.width()
        for button in controls:
            control_width = button.maximumWidth() if isinstance(button, QToolButton) else button.sizeHint().width()
            advance = control_width + layout.horizontalSpacing()
            if column and used + advance > width:
                row += 1
                column = used = 0
            index = layout.indexOf(button)
            if layout.getItemPosition(index)[:2] != (row, column):
                layout.removeWidget(button)
                layout.addWidget(button, row, column)
            column += 1
            used += advance
        row_height = max(
            self._folder_order.sizeHint().height(),
            *(button.minimumHeight() for button in self._folder_format_buttons.values()),
        )
        height = (row + 1) * row_height + row * layout.verticalSpacing()
        if self._folder_formats.minimumHeight() != height:
            self._folder_formats.setFixedHeight(height)
    def _sync_folder_format_actions(self) -> None:
        """Render the current per-gallery folder order, dimensions and save feedback.

        Returns:
            None: Updates native controls while preserving independently saved photo settings.
        """
        if self._folder_section.isHidden():
            return
        order = 'folder_order_newest' if self._folder_newest else 'folder_order_oldest'
        order_hint = self._text('folder_order_unsaved' if 'order' in self._folder_modes_unsaved else order)
        mark = ' !' if 'order' in self._folder_modes_unsaved else ''
        self._folder_order.setText(('↓' if self._folder_newest else '↑') + mark)
        self._folder_order.setAccessibleName(order_hint)
        self._folder_order.setToolTip(order_hint)
        for ratio, button in self._folder_format_buttons.items():
            selected = ratio == self._folder_format
            button.setChecked(selected)
            button.setIcon(qt_icon(
                'aspect_' + ratio, size=PHOTOS_CARD_FORMAT_ICON_SIZE,
                tone='primary' if selected else 'muted',
                checked_tone='primary',
            ))
            button.setToolTip(self._text(
                'folder_format_unsaved' if 'format' in self._folder_modes_unsaved else 'folder_format_hint',
            ))
    def _set_folder_format(self, value: str) -> None:
        """Change the current gallery's local folder ratio without changing photo tiles.

        Args:
            value (str): Shared aspect-format key from the native control.
        Returns:
            None: Retains current choices and exposes any local persistence failure.
        """
        if self._view_path is None or self._gallery_id is None or value not in PHOTO_CARD_FORMATS:
            return
        self._folder_format = value
        if save_gallery_card_format(self._view_path, self._gallery_id, value, folders=True):
            self._folder_modes_unsaved.discard('format')
        else:
            self._folder_modes_unsaved.add('format')
            self.status_message(self._text('folder_format_unsaved'))
        self._sync_folder_section()
    def _toggle_folder_order(self) -> None:
        """Reverse the authorized direct children and persist their separate order.

        Returns:
            None: Reorders folder cards and leaves the photos unchanged.
        """
        if self._view_path is None or self._gallery_id is None:
            return
        self._folder_newest = not self._folder_newest
        order = 'newest' if self._folder_newest else 'oldest'
        if save_gallery_photo_order(self._view_path, self._gallery_id, order, folders=True):
            self._folder_modes_unsaved.discard('order')
        else:
            self._folder_modes_unsaved.add('order')
        self._sync_folder_section()
    def _layout_folder_split(self) -> None:
        """Restore the saved divider or leave room for one compact automatic folder section.

        Returns:
            None: Keeps the reference photo reserve even at a narrow or short size.
        """
        if not self._using_folder_section:
            return
        split = self._folder_split
        height = split.height() - split.handleWidth()
        if height <= 0:
            return
        share = self._folder_split_share
        if share is None:
            cell = self._folder_shelf.gridSize()
            columns = max(1, self._folder_shelf.viewport().width() // max(1, cell.width()))
            rows = (len(self._folder_shelf.cards.rows) + columns - 1) // columns
            chrome = self._folder_formats.minimumHeight() + self._folder_section.layout().spacing()
            one_row = chrome + cell.height()
            wanted = min(max(int(height * FOLDER_AUTO_SHARE), one_row), chrome + cell.height() * rows)
        else:
            wanted = round(height * share / GALLERY_FOLDER_SHARE_SCALE)
        upper = min(wanted, max(0, height - FOLDER_PHOTO_RESERVE))
        split.setSizes([upper, height - upper])
    def _remember_folder_split(self, _position: int, _index: int) -> None:
        """Save the actual split ratio only after an operator drags the divider.

        Args:
            _position (int): Qt logical splitter position.
            _index (int): Qt handle index inside the two-pane split.
        Returns:
            None: Stores bounded thousandths with the current linked gallery.
        """
        upper, lower = self._folder_split.sizes()
        if self._using_folder_section and upper + lower > 0:
            share = round(upper * GALLERY_FOLDER_SHARE_SCALE / (upper + lower))
            self._save_folder_split(share)
    def _save_folder_split(self, share: int | None) -> None:
        """Save a bounded position or restore the shared automatic divider policy.

        Args:
            share (int | None): Proposed thousandths, or None for automatic height.
        Returns:
            None: Keeps the local layout with honest persistence feedback.
        """
        if not self._using_folder_section or self._view_path is None or self._gallery_id is None:
            return
        if share is not None:
            smallest, largest = GALLERY_FOLDER_SHARE_BOUNDS
            share = min(largest, max(smallest, share))
        self._folder_split_share = share
        saved = save_gallery_folder_split(self._view_path, self._gallery_id, share)
        hint = self._text('folder_split_hint' if saved else 'folder_split_unsaved')
        self._folder_split.handle(1).setToolTip(hint)
        self._layout_folder_split()
    def _sync_format_actions(self) -> None:
        """Reflect the selected gallery, saved ratio and local-write result.

        Returns:
            None: Controls remain disabled outside an authorized gallery.
        """
        enabled = self._identity is not None and self._gallery_id is not None
        self._order_button.setEnabled(enabled)
        self._format_actions.setVisible(enabled)
        if self._gallery_id is not None:
            self._format_notice.setVisible(self._gallery_id in self._format_unsaved)
        for ratio, button in self._format_buttons.items():
            selected = ratio == self._format
            button.setChecked(selected)
            button.setEnabled(enabled)
            button.setIcon(qt_icon(
                "aspect_" + ratio, size=PHOTOS_CARD_FORMAT_ICON_SIZE,
                tone="primary" if selected else "muted",
                checked_tone="primary",
            ))
            button.setToolTip(self._text(
                "card_format_unsaved"
                if self._gallery_id in self._format_unsaved
                else "card_format_hint",
            ))
        self._layout_gallery_heading()
        self._layout_format_buttons()
    def _sync_lightbox_controls(self) -> None:
        """Keep overlay labels and edge actions aligned with the retained frame.

        Returns:
            None: Only presentation state changes; the active detail read remains.
        """
        controls_visible = self.detailOpen and (not self._fullscreen or self._lightbox_controls_visible)
        self._detail_controls.setVisible(controls_visible)
        for button in self._detail_edges:
            button.setVisible(controls_visible)
        self._set_lightbox_layout(self.detailOpen)
        self._layout_lightbox_controls()
        self._sync_content_crop_action()
        self._detail_title_label.setText(self.detailTitle)
        self._detail_position_label.setText(self.detailPosition)
        index = self._detail_index
        for button in self._detail_edges:
            button.setEnabled(index is not None and len(self.model.rows) > 1)
        self._detail_location_button.setEnabled(self._detail_location() is not None)
    def _set_lightbox_layout(self, active: bool) -> None:
        """Let the retained detail scene fill Photos while preserving normal layout.

        Args:
            active (bool): Whether the current detail owns the visual workspace.
        Returns:
            None: The same Quick widget remains mounted and restores exact split sizes.
        """
        if active and self._lightbox_layout_restore is None:
            widgets = (
                self._connect_card_outer, self._connect_card,
                self._load_simbrief_button,
                self._center_toolbar, self._format_row,
                self._transport_row,
                self._gallery_panel, self.progress, self.status, self._inspector_panel,
                self._preload_row,
            )
            previous = {widget: widget.isHidden() for widget in widgets}
            self._lightbox_layout_restore = (previous, self._split.sizes())
            for widget in widgets:
                widget.hide()
            self.playback.close_panel()
            self._photos_layout.setContentsMargins(0, 0, 0, 0)
            self._center_layout.setContentsMargins(0, 0, 0, 0)
            self._sync_folder_section()
            self.lightbox_state_changed.emit(True)
            return
        if not active and self._lightbox_layout_restore is not None:
            previous, sizes = self._lightbox_layout_restore
            self._lightbox_layout_restore = None
            self._photos_layout.setContentsMargins(self._photos_margins)
            self._center_layout.setContentsMargins(*PHOTO_CARD_MARGINS)
            for widget, was_hidden in previous.items():
                if widget in (self._connect_card_outer, self._connect_card,
                              self._gallery_panel,
                              self.progress, self.status):
                    continue
                widget.setHidden(was_hidden)
            self._sync_connection_state()
            self._gallery_panel.setVisible(
                self._gallery_tree.topLevelItemCount() > 0,
            )
            self.progress.hide()
            self.status.setVisible(bool(self.status.text()))
            self._split.setSizes(sizes)
            self.lightbox_state_changed.emit(False)
    def _layout_lightbox_controls(self) -> None:
        """Center the compact toolbar card and limit its hit-test rectangle.

        Returns:
            None: All image-space pointer input remains outside the card bounds.
        """
        size = self._detail_toolbar.sizeHint()
        width = min(size.width(), max(1, self.quick.width() - 2 * LIGHTBOX_TOOLBAR_TOP_INSET))
        height = size.height()
        left = max(0, (self.quick.width() - width) // 2)
        self._detail_controls.setGeometry(
            left, LIGHTBOX_TOOLBAR_TOP_INSET, width, height,
        )
        inset = round(self.quick.width() * LIGHTBOX_EDGE_INSET)
        for position, button in enumerate(self._detail_edges):
            size = button.size()
            column = inset if position == 0 else self.quick.width() - inset - size.width()
            row = (self.quick.height() - size.height()) // 2
            button.setGeometry(column, row, size.width(), size.height())
            button.raise_()
        self._detail_controls.raise_()
        self._hdr_surface.set_controls(tuple(
            QRectF(control.geometry())
            for control in (self._detail_controls, *self._detail_edges)
            if not control.isHidden()
        ))
    def _show_lightbox_controls(self) -> None:
        """Reveal fullscreen chrome and restart its bounded idle timer.

        Returns:
            None: Pointer or keyboard use keeps accessible controls available.
        """
        if not self.detailOpen:
            return
        was_visible = self._lightbox_controls_visible
        self._lightbox_controls_visible = True
        if not was_visible or not self._detail_controls.isVisible():
            self._detail_controls.show()
            for button in self._detail_edges:
                button.show()
            self._layout_lightbox_controls()
        if self._fullscreen and self._detail_menu is None:
            self._lightbox_controls_timer.start()
    def _hide_lightbox_controls(self) -> None:
        """Hide only the fullscreen toolbar after pointer idle.

        Returns:
            None: The retained Quick image and HDR layer are not repainted.
        """
        if not self._fullscreen or not self.detailOpen:
            return
        self._lightbox_controls_visible = False
        self._detail_controls.hide()
        for button in self._detail_edges:
            button.hide()
        self._hdr_surface.set_controls(())
    def _detail_location(self) -> tuple[float, float] | None:
        """Read coordinate data only for the currently displayed linked image.

        Returns:
            tuple[float, float] | None: Valid coordinates from the exact live gallery.
        """
        index, session, gallery = self._detail_display_index, self._session, self._rendered_gallery
        if (index is None or not 0 <= index < len(self.model.rows)
                or session is None or gallery is None or not self._owns_gallery(session, gallery)):
            return None
        photo = self.model.rows[index].source
        if not isinstance(photo, LinkedPhoto):
            return None
        latitude, longitude = photo.latitude, photo.longitude
        if (type(latitude) not in (int, float)
                or type(longitude) not in (int, float)
                or not isfinite(latitude) or not isfinite(longitude)
                or not -90 <= latitude <= 90 or not -180 <= longitude <= 180):
            return None
        return float(latitude), float(longitude)
    def _show_location_menu(self) -> None:
        """Show the photo's validated coordinates beside the visible map pin.

        Returns:
            None: Opens the localized map action for the current linked image.
        """
        location = self._detail_location()
        if location is None:
            return
        latitude, longitude = location
        button = self._detail_location_button
        menu = QMenu(button)
        coordinates = menu.addAction(f"{latitude:.6f}, {longitude:.6f}")
        coordinates.setEnabled(False)
        menu.addAction(
            tr(self.language, "linking_media_open_map"),
            lambda: self._open_map(latitude, longitude),
        )
        self._open_detail_menu(menu, button.mapToGlobal(button.rect().bottomLeft()))
    def _show_lightbox_context(self, position: QPoint) -> None:
        """Offer secondary detail commands from the canvas context menu.

        Args:
            position (QPoint): Global pointer or keyboard invocation anchor.
        Returns:
            None: Leaves the primary Lightbox rail in the reference action order.
        """
        menu = QMenu(self.quick)
        index = self._detail_display_index
        if (index is not None and 0 <= index < len(self.model.rows)
                and isinstance(self.model.rows[index].source, LinkedPhoto)):
            menu.addAction(self._text('photo_info'), self._information_for_detail)
        key = 'linking_media_slideshow_stop' if self._playing else 'linking_media_slideshow_start'
        menu.addAction(tr(self.language, key), self.toggle_slideshow)
        self._open_detail_menu(menu, position)
    def _open_detail_menu(self, menu: QMenu, position: QPoint) -> None:
        """Anchor one transient popup to the current displayed detail.

        Args:
            menu (QMenu): Native menu created for the current detail action.
            position (QPoint): Global popup anchor.
        Returns:
            None: Retains the fullscreen rail while the popup is active.
        """
        if self._detail_menu is not None:
            self._detail_menu.close()
        self._detail_menu = menu
        self._lightbox_controls_timer.stop()
        menu.aboutToHide.connect(self._detail_menu_closed)
        menu.aboutToHide.connect(menu.deleteLater)
        menu.popup(position)
    def _detail_menu_closed(self) -> None:
        """Clear the popup reference and return control to the fullscreen timer.

        Returns:
            None: Preserves the retained photo and any permitted slideshow.
        """
        self._detail_menu = None
        if self._fullscreen and self.detailOpen:
            self._lightbox_controls_timer.start()
    def _open_map(self, latitude: float, longitude: float) -> None:
        """Open the current linked photo coordinates in OpenStreetMap.

        Args:
            latitude (float): Valid latitude from the accepted linked photo.
            longitude (float): Valid longitude from the accepted linked photo.
        Returns:
            None: Reports a browser launch failure in the Photos status line.
        """
        if self._detail_location() != (latitude, longitude):
            return
        url = (
            "https://www.openstreetmap.org/?mlat="
            f"{latitude:.6f}&mlon={longitude:.6f}#map={PHOTO_LOCATION_MAP_ZOOM}/"
            f"{latitude:.6f}/{longitude:.6f}"
        )
        try:
            opened = webbrowser.open(url)
        except Exception:
            opened = False
        if not opened:
            self.status_message(tr(self.language, "linking_media_map_open_failed"))
    def _sync_content_crop_action(self) -> None:
        """Name the fit action for its current crop/original result.

        Returns:
            None: The action restores the zoom when no matte was detected.
        """
        caption = tr(
            self.language,
            ("linking_media_show_original_frame" if self._detail_content_crop_enabled
             else "linking_media_fit_content") if self.detailHasContentCrop
            else "linking_media_fit",
        )
        self._detail_fit_button.setAccessibleName(caption)
        self._detail_fit_button.setToolTip(caption)
        self._detail_fit_button.setEnabled(bool(self._detail_keys))
    def _set_card_format(self, format_value: str) -> None:
        """Apply a presentation format without replacing authorized images.

        Args:
            format_value (str): One validated icon-row aspect ratio.
        Returns:
            None: Reflows only the visible Qt Quick delegates.
        """
        if format_value not in PHOTO_CARD_FORMATS:
            return
        self._format = format_value
        self._format_buttons[format_value].setChecked(True)
        if self._view_path is not None and self._gallery_id is not None:
            if save_gallery_card_format(
                self._view_path, self._gallery_id, self._format,
            ):
                self._format_unsaved.discard(self._gallery_id)
            else:
                self._format_unsaved.add(self._gallery_id)
        self._sync_format_actions()
        self.formatChanged.emit()
    def choose_files(self) -> None:
        """Open an explicit read-only local selection through the native picker.

        Returns:
            None: Uses the chosen files without modifying their contents.
        """
        if not self._can_disconnect():
            return
        paths, _selected = QFileDialog.getOpenFileNames(
            self, gettext("Open local photos"), "", gettext("Image files") + " (*.jpg *.jpeg *.png *.webp *.gif *.bmp *.tif *.tiff)",
        )
        if paths:
            self.open_files(tuple(Path(path) for path in paths))
    def open_files(self, paths: tuple[Path, ...]) -> None:
        """Replace the catalogue with explicitly selected local photo paths.

        Args:
            paths (tuple[Path, ...]): Paths from a native picker or isolated test.
        Returns:
            None: Displays bounded logical rows; file reads start in the viewport.
        """
        if not self._can_disconnect():
            return
        self.disconnect()
        self._local_photos_active = True
        self._gallery_title.setText(gettext("Local photos"))
        self._install_catalogue(tuple(
            PhotoRow(index, path.name, path)
            for index, path in enumerate(paths[:MEDIA_RECENT_ORDER_PHOTO_LIMIT])
        ))
        self._sync_connection_state()
        self.formatChanged.emit()
    def link(self) -> None:
        """Start explicit recovery or ask which gallery to authorize.

        Returns:
            None: Starts exact saved-link recovery or the normal browser flow.
        """
        if self._startup_saved_mode is not None:
            self._reapprove_startup_saved()
            return
        self._authorize(False)
