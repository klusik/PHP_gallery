# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/ui_file_picker.py
# Module Type: Windows Client Interface
# Purpose: Select files and directories inside the main application window.
# Responsibilities: Browse bounded directory results and validate selections.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Embedded file selection with asynchronous bounded directory browsing."""

import fnmatch
import os
from pathlib import Path
import queue
import threading
import tkinter as tk
from tkinter import ttk

from uploader.ui_shell import EmbeddedWindow, active_panel, messagebox

# Purpose: Bound each displayed directory listing while retaining typed paths.
# Type: int; Units: entries; Scope: one directory scan.
# Consumers: _scan_directory and the picker truncation notice.
# Rationale: 2000 entries keeps the selectable list responsive for large folders.
_DIRECTORY_ENTRY_LIMIT = 2000

# Purpose: Poll a background directory scan without blocking Tk.
# Type: int; Units: milliseconds; Scope: an open embedded picker.
# Consumers: _choose polling callback.
# Rationale: 100 ms promptly presents results without a busy Tk event loop.
_SCAN_POLL_MS = 100


def _scan_directory(path: Path, patterns: tuple[str, ...], directory_only: bool = False) -> tuple[list[tuple[str, bool]], bool, str]:
    """Read a bounded, sorted listing without hiding directories from navigation.

    Args:
        path (Path): Directory to enumerate.
        patterns (tuple[str, ...]): Case-insensitive filename patterns to include.
        directory_only (bool): Exclude files before applying the listing bound.
    Returns:
        tuple[list[tuple[str, bool]], bool, str]: Name/directory rows, truncation
            status, and an error message when the directory cannot be read.
    """
    rows = []
    truncated = False
    try:
        with os.scandir(path) as entries:
            for entry in entries:
                try:
                    directory = entry.is_dir()
                except OSError:
                    continue
                if not directory and directory_only:
                    continue
                if not directory and patterns and not any(
                    fnmatch.fnmatch(entry.name.lower(), pattern.lower())
                    or pattern == '*.*' for pattern in patterns
                ):
                    continue
                if len(rows) >= _DIRECTORY_ENTRY_LIMIT:
                    truncated = True
                    break
                rows.append((entry.name, directory))
    except OSError as exc:
        return [], False, str(exc)
    rows.sort(key=lambda row: (not row[1], row[0].casefold()))
    return rows, truncated, ''


def _choose(mode: str, parent: tk.Misc | None = None, title: str = '', initialdir: str | Path = '', initialfile: str | Path = '', filetypes: list[tuple[str, str | tuple[str, ...]]] | tuple[tuple[str, str | tuple[str, ...]], ...] = (), defaultextension: str = '', mustexist: bool = True) -> str | tuple[str, ...]:
    """Select existing files, a directory or a save destination in the root.

    Args:
        mode (str): One of directory, open, multiple or save.
        parent (tk.Misc | None): Invoking widget, or the default Tk root;
            a root resolves to the active panel for user-initiated selection.
        title (str): Optional picker heading.
        initialdir (str | Path): Initial directory path.
        initialfile (str | Path): Initial filename or destination.
        filetypes (list[tuple[str, str | tuple[str, ...]]] | tuple[tuple[str, str | tuple[str, ...]], ...]): Filter labels and filename patterns.
        defaultextension (str): Extension appended to a save name without one.
        mustexist (bool): Whether a directory selection must already exist.
    Returns:
        str | tuple[str, ...]: Selected path, selected paths for multiple mode,
            or an empty value when cancelled.
    """
    from uploader.ui_theme import _PALETTE

    owner = parent or tk._default_root
    if owner is owner.winfo_toplevel():
        owner = active_panel(owner) or owner
    panel = EmbeddedWindow(owner)
    panel.title(title or 'Select ' + ('folder' if mode == 'directory' else 'file'))
    body = ttk.Frame(panel, padding=12)
    body.pack(fill='both', expand=True)
    current = Path(initialdir).expanduser() if initialdir else Path.home()
    path_var = tk.StringVar(master=panel, value=str(current))
    name_var = tk.StringVar(master=panel, value=str(initialfile))
    status = tk.StringVar(master=panel, value='')
    result: list[str | tuple[str, ...]] = []
    completed = tk.BooleanVar(master=owner, value=False)
    listing: list[tuple[str, bool]] = []
    generation = [0]
    poll_timer: list[str | None] = [None]
    replies: queue.Queue[tuple[int, list[tuple[str, bool]], bool, str]] = queue.Queue()
    requests: queue.Queue[tuple[int, Path, tuple[str, ...], bool] | None] = queue.Queue(maxsize=1)

    def scan_worker() -> None:
        """Process only the newest queued request on one daemon worker.

        Returns:
            None: Posts scan results to the queue without calling Tk.
        """
        while True:
            request = requests.get()
            if request is None:
                return
            number, path, patterns, directories = request
            rows, truncated, error = _scan_directory(path, patterns, directories)
            replies.put((number, rows, truncated, error))

    threading.Thread(target=scan_worker, daemon=True).start()

    def enqueue(request: tuple[int, Path, tuple[str, ...], bool] | None) -> None:
        """Replace a pending scan with the newest request or stop signal.

        Args:
            request (tuple[int, Path, tuple[str, ...], bool] | None): Scan
                generation, path, patterns and folder-only flag, or stop signal.
        Returns:
            None: Leaves at most one request waiting for the worker.
        """
        try:
            requests.put_nowait(request)
        except queue.Full:
            try:
                requests.get_nowait()
            except queue.Empty:
                pass
            requests.put_nowait(request)
    controls = ttk.Frame(body)
    controls.pack(fill='x')
    path_entry = ttk.Entry(controls, textvariable=path_var)
    path_entry.pack(side='left', fill='x', expand=True)
    listing_frame = ttk.Frame(body)
    listing_frame.pack(fill='both', expand=True, pady=8)
    listing_box = tk.Listbox(
        listing_frame, selectmode='extended' if mode == 'multiple' else 'browse',
        exportselection=False, background=_PALETTE['surface'],
        foreground=_PALETTE['ink'], selectbackground=_PALETTE['surface_alt'],
        selectforeground=_PALETTE['ink'], highlightbackground=_PALETTE['line'],
        highlightcolor=_PALETTE['focus'],
    )
    listing_box.pack(side='left', fill='both', expand=True)
    scrollbar = ttk.Scrollbar(listing_frame, orient='vertical', command=listing_box.yview)
    scrollbar.pack(side='right', fill='y')
    listing_box.configure(yscrollcommand=scrollbar.set)
    ttk.Label(body, textvariable=status).pack(anchor='w')
    name_entry = ttk.Entry(body, textvariable=name_var)
    name_entry.pack(fill='x', pady=6)
    name_var.trace_add('write', lambda *_args: listing_box.selection_clear(0, 'end'))
    filters = list(filetypes) or [('All files', '*')]
    filter_index = tk.StringVar(master=panel, value=filters[0][0])
    filter_control = ttk.Combobox(body, textvariable=filter_index, values=[item[0] for item in filters], state='readonly')
    if mode != 'directory':
        filter_control.pack(fill='x')

    def refresh() -> None:
        """Start a new listing and discard replies from earlier navigation.

        Returns:
            None: Schedules a bounded scan on a daemon worker.
        """
        generation[0] += 1
        number = generation[0]
        path = Path(path_var.get()).expanduser()
        selected = next((patterns for label, patterns in filters if label == filter_index.get()), '*')
        patterns = (tuple(selected.split()) if isinstance(selected, str) else selected) if mode != 'directory' else ()
        status.set('Loading…')
        listing_box.delete(0, 'end')
        listing.clear()

        enqueue((number, path, patterns, mode == 'directory'))

    def poll() -> None:
        """Apply completed scans only when they match the current directory.

        Returns:
            None: Updates visible rows and reschedules while the picker exists.
        """
        try:
            while True:
                number, rows, truncated, error = replies.get_nowait()
                if number != generation[0]:
                    continue
                listing[:] = [row for row in rows if mode != 'directory' or row[1]]
                for name, directory in listing:
                    listing_box.insert('end', ('📁 ' if directory else '') + name)
                status.set(error or ('Listing truncated; enter a full path to select other items.' if truncated else ''))
        except queue.Empty:
            pass
        if panel.winfo_exists():
            poll_timer[0] = panel.after(_SCAN_POLL_MS, poll)

    def navigate(path: Path) -> None:
        """Navigate to an existing directory or display a useful error.

        Args:
            path (Path): Candidate directory entered or selected by the user.
        Returns:
            None: Refreshes the listing when the path is a directory.
        """
        if not path.is_dir():
            status.set('Folder does not exist or cannot be accessed.')
            return
        path_var.set(str(path))
        refresh()

    def selected_paths() -> list[Path]:
        """Resolve selected rows or the explicitly entered filename.

        Returns:
            list[Path]: Paths selected by the user, possibly an empty list.
        """
        indexes = listing_box.curselection()
        if indexes:
            return [Path(path_var.get()) / listing[index][0] for index in indexes]
        name = name_var.get().strip()
        return [Path(path_var.get()) / name] if name else []

    def finish(value: str | tuple[str, ...]) -> None:
        """Close the picker and release its synchronous caller.

        Args:
            value (str | tuple[str, ...]): Selected path or cancellation value.
        Returns:
            None: Stores the result before destroying the panel.
        """
        result.append(value)
        completed.set(True)
        panel.destroy()

    def accept() -> None:
        """Validate the chosen path and confirm replacement before saving.

        Returns:
            None: Navigates, reports invalid input or returns the selection.
        """
        paths = selected_paths()
        if mode == 'directory' and not paths:
            paths = [Path(path_var.get()).expanduser()]
        if not paths:
            status.set('Select an item or enter a path.')
            return
        if mode != 'directory' and len(paths) == 1 and paths[0].is_dir():
            navigate(paths[0])
            name_var.set('')
            return
        if mode == 'directory':
            if mustexist and not paths[0].is_dir():
                status.set('Select an existing folder.')
                return
        elif mode in ('open', 'multiple'):
            if any(not path.is_file() for path in paths):
                status.set('Select existing files.')
                return
        else:
            path = paths[0]
            if defaultextension and not path.suffix:
                path = path.with_suffix(defaultextension if defaultextension.startswith('.') else '.' + defaultextension)
            if not path.parent.is_dir():
                status.set('The destination folder does not exist.')
                return
            if path.exists() and not messagebox.askyesno('Replace file', 'Replace the existing file?', parent=panel):
                return
            paths = [path]
        values = tuple(str(path) for path in paths)
        finish(values if mode == 'multiple' else values[0])

    ttk.Button(controls, text='Go', command=lambda: navigate(Path(path_var.get()).expanduser())).pack(side='left')
    ttk.Button(controls, text='Up', command=lambda: navigate(Path(path_var.get()).parent)).pack(side='left')
    ttk.Button(controls, text='Home', command=lambda: navigate(Path.home())).pack(side='left')
    ttk.Button(controls, text='Root', command=lambda: navigate(Path(Path(path_var.get()).anchor or '/'))).pack(side='left')
    def invalidate_path(*_args: str) -> None:
        """Discard rows as soon as the user edits their source directory.

        Args:
            *_args (str): Tcl trace variable name, index and operation.
        Returns:
            None: Invalidates in-flight results and removes stale selections.
        """
        generation[0] += 1
        listing.clear()
        listing_box.delete(0, 'end')

    def open_selected(_event: tk.Event) -> None:
        """Navigate into a selected folder or accept a selected file.

        Args:
            _event (tk.Event): Double-click event on the result list.
        Returns:
            None: Opens the folder or validates the selected file.
        """
        paths = selected_paths()
        if len(paths) == 1 and paths[0].is_dir():
            navigate(paths[0])
            name_var.set('')
        elif mode != 'directory':
            accept()

    path_var.trace_add('write', invalidate_path)
    path_entry.bind('<Return>', lambda _event: navigate(Path(path_var.get()).expanduser()))
    listing_box.bind('<Double-Button-1>', open_selected)
    filter_control.bind('<<ComboboxSelected>>', lambda _event: refresh())
    footer = ttk.Frame(body)
    footer.pack(fill='x', pady=8)
    ttk.Button(footer, text='Select', command=accept).pack(side='left')
    cancel = () if mode == 'multiple' else ''
    ttk.Button(footer, text='Cancel', command=lambda: finish(cancel)).pack(side='left')
    panel.protocol('WM_DELETE_WINDOW', lambda: finish(cancel))

    def destroyed(event: tk.Event) -> None:
        """Release a waiting picker when its owning panel is destroyed.

        Args:
            event (tk.Event): Destroy event from this panel or its child.
        Returns:
            None: Cancels an unfinished selection and stops the worker.
        """
        if event.widget is not panel:
            return
        if poll_timer[0] is not None:
            try:
                panel.after_cancel(poll_timer[0])
            except tk.TclError:
                pass
            poll_timer[0] = None
        enqueue(None)
        if not result:
            result.append(cancel)
            try:
                completed.set(True)
            except tk.TclError:
                pass

    panel.bind('<Destroy>', destroyed, add='+')
    panel.grab_set()
    refresh()
    poll_timer[0] = panel.after(_SCAN_POLL_MS, poll)
    path_entry.focus_set()
    try:
        panel.wait_variable(completed)
    except tk.TclError:
        return cancel
    return result[0] if result else cancel


def askdirectory(**options: str | Path | bool | tk.Misc) -> str:
    """Select a directory using the embedded picker.

    Args:
        **options (str | Path | bool | tk.Misc): Supported title, parent, initialdir and mustexist options.
    Returns:
        str: Selected directory or an empty string when cancelled.
    """
    return _choose('directory', **options)


def askopenfilename(**options: str | Path | tk.Misc | list[tuple[str, str | tuple[str, ...]]] | tuple[tuple[str, str | tuple[str, ...]], ...]) -> str:
    """Select one existing file using the embedded picker.

    Args:
        **options (str | Path | tk.Misc | list[tuple[str, str | tuple[str, ...]]] | tuple[tuple[str, str | tuple[str, ...]], ...]): Supported picker configuration.
    Returns:
        str: Selected file or an empty string when cancelled.
    """
    return _choose('open', **options)


def askopenfilenames(**options: str | Path | tk.Misc | list[tuple[str, str | tuple[str, ...]]] | tuple[tuple[str, str | tuple[str, ...]], ...]) -> tuple[str, ...]:
    """Select existing files using the embedded picker.

    Args:
        **options (str | Path | tk.Misc | list[tuple[str, str | tuple[str, ...]]] | tuple[tuple[str, str | tuple[str, ...]], ...]): Supported picker configuration.
    Returns:
        tuple[str, ...]: Selected file paths, or an empty tuple on cancellation.
    """
    return _choose('multiple', **options)


def asksaveasfilename(**options: str | Path | tk.Misc | list[tuple[str, str | tuple[str, ...]]] | tuple[tuple[str, str | tuple[str, ...]], ...]) -> str:
    """Select a save destination and confirm replacement when needed.

    Args:
        **options (str | Path | tk.Misc | list[tuple[str, str | tuple[str, ...]]] | tuple[tuple[str, str | tuple[str, ...]], ...]): Supported picker configuration.
    Returns:
        str: Selected destination or an empty string when cancelled.
    """
    return _choose('save', **options)
