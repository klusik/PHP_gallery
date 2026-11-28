# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_launcher_module_contracts.py
# Module Type: Windows Source Contract Tests
# Purpose: Keep extracted launcher responsibilities under explicit module owners.
# Responsibilities:
#   - Verify moved declarations have one package owner and stay out of the .pyw.
#   - Keep fixture patch targets aligned with the implementation owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Guard the explicit source ownership map for launcher decomposition."""

import ast
from pathlib import Path
import unittest

WINAPP_DIR = Path(__file__).resolve().parents[1]

# Purpose: Name moved declarations and their true implementation and test owners.
# Type: dict[str, dict[str, tuple[str, ...] | str | dict[str, tuple[str, ...]]]].
# Units: Python declarations. Scope: extracted launcher responsibilities.
# Consumers: LauncherModuleContracts.
# Rationale: Keep import/patch ownership explicit while the executable is split.
LAUNCHER_MODULE_OWNERS = {
    "uploader.http_transport": {
        "declarations": (
            "upload_endpoint_candidates", "normalize_upload_url",
            "revoke_upload_key", "GalleryKeyRedirectError",
            "LegacyUploadResponseError", "UploadPreparationError",
            "_GalleryKeyRedirectHandler", "_gallery_key_open",
            "post_json_resolved", "post_json", "post_binary_to_file",
            "LocalThumbnail", "multipart_field_part", "multipart_file_part",
            "multipart_upload", "upload_failure_needs_inventory_reconciliation",
            "inventory_candidate", "RemoteInventorySession", "sha256_file",
        ),
        "fixture": "http_transport",
        "patch_globals": {
            "http_transport": (
                "_gallery_key_open", "post_json_resolved", "post_json",
                "RemoteInventorySession",
            ),
        },
    },
    "uploader.watcher.state": {
        "declarations": (
            "WatcherConfig", "CapturedWatcherConfig", "capture_worker_config",
            "ConfigStore", "_ConfigModePort", "_ConfigModeTransaction",
            "UploadState", "write_linked_upload_state", "LinkedUploadState",
            "probe_linked_upload_outcome", "publish_linked_upload_observation",
            "review_linked_upload_marker", "trim_path",
            "normalize_ai_vision_backend",
        ),
        "fixture": "MAIN",
        "patch_globals": {},
    },
    "uploader.watcher.workers.common": {
        "declarations": ("FileStabilityTracker",),
        "fixture": "MAIN",
        "launcher_import": (
            "uploader.watcher.workers.common", ("FileStabilityTracker",),
        ),
        "patch_globals": {
            "watch_common": ("FileStabilityTracker",),
        },
    },
    "uploader.watcher.workers.thumbnails": {
        "declarations": (
            "image_has_alpha", "prepare_jpeg_image", "prepare_webp_image",
            "generate_local_thumbnails", "generate_linked_thumbnails",
        ),
        "fixture": "thumbnails",
        "launcher_import": (
            "uploader.watcher.workers.thumbnails", (
                "generate_linked_thumbnails", "generate_local_thumbnails",
                "image_has_alpha", "prepare_jpeg_image", "prepare_webp_image",
            ),
        ),
        "patch_globals": {},
    },
    "uploader.ai.heartbeat": {
        "declarations": ("PeriodicAIHeartbeat",),
        "fixture": "MAIN",
        "patch_globals": {},
    },
    "uploader.watcher.runtime": {
        "declarations": (
            "ensure_optional_support", "local_thumbnail_supported",
            "thumbnail_runtime_status", "install_dependencies_for_current_runtime",
        ),
        "fixture": "runtime",
        "launcher_import": ("uploader.watcher", ("runtime",)),
        "patch_globals": {
            "runtime": ("Image", "pystray", "local_thumbnail_supported"),
        },
    },
    "uploader.ai.dependencies": {
        "declarations": ("install_semantic_ai_dependencies_for_current_runtime",),
        "fixture": "ai_dependencies",
        "launcher_import": ("uploader.ai", ("dependencies",)),
        "patch_globals": {},
    },
    "uploader.watcher.workers.watcher": {
        "declarations": (
            "WatcherThread", "create_resumed_watch_worker",
            "watch_recovery_owner_held",
        ),
        "fixture": "watcher_worker",
        "launcher_import": (
            "uploader.watcher.workers.watcher", ("WatcherThread",),
        ),
        "patch_globals": {},
    },
    "uploader.imports.manual_upload": {
        "declarations": ("ManualUploadThread", "create_import_worker"),
        "fixture": "manual_upload_worker",
        "launcher_import": (
            "uploader.imports.manual_upload", ("ManualUploadThread",),
        ),
        "patch_globals": {},
    },
    "uploader.imports.preflight": {
        "declarations": ("ImportPreflightThread",),
        "fixture": "preflight_worker",
        "launcher_import": (
            "uploader.imports.preflight", ("ImportPreflightThread",),
        ),
        "patch_globals": {},
    },
    "uploader.ai.image_analysis": {
        "declarations": ("AIImageAnalyzer",),
        "fixture": "image_analysis",
        "launcher_import": ("uploader.ai.image_analysis", ("AIImageAnalyzer",)),
        "patch_globals": {},
    },
    "uploader.ai.worker": {
        "declarations": ("AIAnalysisWorkerThread", "create_ai_worker"),
        "fixture": "ai_worker",
        "launcher_import": (
            "uploader.ai.worker", ("AIAnalysisWorkerThread",),
        ),
        "patch_globals": {},
    },
    "uploader.watcher.desktop": {
        "declarations": ("DesktopCapture",),
        "fixture": "watcher_desktop",
        "launcher_import": (
            "uploader.watcher.desktop", ("DesktopCapture",),
        ),
        "patch_globals": {},
    },
    "uploader.watcher.configuration": {
        "declarations": ("DesktopConfiguration",),
        "fixture": "watcher_configuration",
        "launcher_import": (
            "uploader.watcher.configuration", ("DesktopConfiguration",),
        ),
        "patch_globals": {},
    },
    "uploader.watcher.ui.app": {
        "declarations": ("WatcherApp",),
        "fixture": "watcher_app",
        "launcher_import": ("uploader.watcher.ui.app", ("WatcherApp",)),
        "patch_globals": {},
    },
    "uploader.watcher.update_policy": {
        "declarations": (
            "UPDATE_CHECKPOINT_TIMEOUT_SECONDS",
            "UPDATE_CONTINUATION_LINK_SECONDS",
            "UPDATE_CONTINUATION_POLL_MS",
            "UPDATE_CONTINUATION_TARGET_RETRY_MS",
            "UPDATE_RESUME_EXCEPTION_CLASS_MAX_CHARS",
        ),
        "fixture": "update_policy",
        "launcher_import": (
            "uploader.watcher.update_policy",
            ("UPDATE_CHECKPOINT_TIMEOUT_SECONDS",),
        ),
        "patch_globals": {},
    },
    "uploader.watcher.event_policy": {
        "declarations": (
            "WATCHER_EVENT_BATCH_BUDGET_MS", "WATCHER_EVENT_BATCH_LIMIT",
            "WATCHER_EVENT_CONTINUATION_MS", "WATCHER_EVENT_IDLE_POLL_MS",
        ),
        "fixture": "event_policy",
        "launcher_import": (
            "uploader.watcher.event_policy", ("WATCHER_EVENT_BATCH_LIMIT",),
        ),
        "patch_globals": {},
    },
    "uploader.imports.policy": {
        "declarations": (
            "WATCHER_IMPORT_TREE_ROW_LIMIT", "protected_staging_job_ids",
        ),
        "fixture": "import_policy",
        "launcher_import": (
            "uploader.imports.policy", ("protected_staging_job_ids",),
        ),
        "patch_globals": {},
    },
    "uploader.launcher": {
        "declarations": (
            "setup_logging", "desktop_import_actions", "run_once", "parse_args",
            "_launch_desktop", "_start_qt_supervisor", "main",
        ),
        "fixture": "launcher",
        "launcher_import": ("uploader.launcher", ("main",)),
        "patch_globals": {},
    },
}


def source_declarations(path: Path) -> set[str]:
    """Return named top-level classes and functions from one Python source.

    Args:
        path (Path): Production source file to parse without importing it.

    Returns:
        set[str]: Names of top-level classes and function declarations.
    """
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
    names = {
        node.name
        for node in tree.body
        if isinstance(node, (ast.ClassDef, ast.FunctionDef, ast.AsyncFunctionDef))
    }
    for node in tree.body:
        if isinstance(node, (ast.Assign, ast.AnnAssign)):
            targets = node.targets if isinstance(node, ast.Assign) else [node.target]
            names.update(target.id for target in targets if isinstance(target, ast.Name))
    return names


class LauncherModuleContracts(unittest.TestCase):
    """Keep implementation and patch ownership aligned after launcher cuts."""

    def test_moved_declarations_have_one_owner(self) -> None:
        """Require moved names only in their owner and explicit launcher imports.

        Returns:
            None: Every mapped declaration has one implementation owner.
        """
        launcher_path = WINAPP_DIR / "gallery_watch_upload.pyw"
        launcher_source = launcher_path.read_text(encoding="utf-8")
        launcher_tree = ast.parse(launcher_source, filename=str(launcher_path))
        launcher_names = source_declarations(launcher_path)
        imported_names = {
            node.module: {alias.name for alias in node.names}
            for node in launcher_tree.body
            if isinstance(node, ast.ImportFrom) and node.module is not None
        }
        for module_name, contract in LAUNCHER_MODULE_OWNERS.items():
            owner_path = WINAPP_DIR.joinpath(*module_name.split("."))
            owner_path = owner_path.with_suffix(".py")
            owner_source = owner_path.read_text(encoding="utf-8")
            owner_names = source_declarations(owner_path)
            for declaration in contract["declarations"]:
                with self.subTest(module=module_name, declaration=declaration):
                    self.assertIn(declaration, owner_names)
                    self.assertNotIn(declaration, launcher_names)
            self.assertTrue(contract["declarations"])
            import_module, import_names = contract.get(
                "launcher_import", (module_name, contract["declarations"]),
            )
            self.assertIn(import_module, imported_names)
            self.assertTrue(
                set(import_names).intersection(imported_names[import_module])
            )


    def test_executable_is_an_early_bootstrap_and_compatibility_facade(self) -> None:
        """Keep implementation classes and startup functions in package owners.

        Returns:
            None: The `.pyw` contains early frozen CLI dispatch and import bindings only.
        """
        launcher_path = WINAPP_DIR / "gallery_watch_upload.pyw"
        names = source_declarations(launcher_path)
        forbidden = {
            "WatcherApp", "WatcherThread", "ManualUploadThread",
            "ImportPreflightThread", "AIAnalysisWorkerThread", "AIImageAnalyzer",
            "DesktopCapture", "DesktopConfiguration", "main", "run_once",
            "parse_args",
        }
        self.assertTrue(forbidden.isdisjoint(names))
        source = launcher_path.read_text(encoding="utf-8")
        for owner in (
            "from uploader.launcher import", "from uploader.watcher.ui.app import",
            "from uploader.watcher.workers.watcher import",
            "from uploader.imports.manual_upload import",
        ):
            with self.subTest(owner=owner):
                self.assertIn(owner, source)

    def test_watcher_lifecycle_and_event_pump_have_focused_owners(self) -> None:
        """Keep Watcher settings and timer ownership out of the large event mixin.

        Returns:
            None: The app composes focused lifecycle classes and one timer owner.
        """
        ui_root = WINAPP_DIR / "uploader" / "watcher" / "ui"
        aggregator = (ui_root / "settings_lifecycle.py").read_text(encoding="utf-8")
        preferences = (ui_root / "settings" / "preferences.py").read_text(encoding="utf-8")
        updates = (ui_root / "settings" / "updates.py").read_text(encoding="utf-8")
        shutdown = (ui_root / "settings" / "shutdown.py").read_text(encoding="utf-8")
        event_pump = (ui_root / "event_pump.py").read_text(encoding="utf-8")
        event_delivery = (ui_root / "event_delivery.py").read_text(encoding="utf-8")
        app = (ui_root / "app.py").read_text(encoding="utf-8")

        self.assertIn("class WatcherPreferencesMixin:", preferences)
        self.assertIn("class WatcherUpdateMixin:", updates)
        self.assertIn("class WatcherShutdownMixin:", shutdown)
        self.assertIn("class SettingsLifecycleMixin(WatcherPreferencesMixin, WatcherUpdateMixin, WatcherShutdownMixin):", aggregator)
        self.assertNotIn("def prepare_update_checkpoint(", aggregator)
        self.assertNotIn("def build_settings_tab(", aggregator)
        self.assertIn("self._event_pump = TkEventPump(", app)
        self.assertIn("self._event_pump.schedule(delay_ms, self.drain_events)", event_delivery)
        self.assertIn("self._event_pump.cancel()", event_delivery)
        self.assertIn("self.root.after(delay_ms, dispatch)", event_pump)
        self.assertIn("callback_id != self.after_id", event_pump)
        self.assertIn("self.root.after_cancel(callback_id)", event_pump)
        self.assertNotIn("_drain_after_id", app + event_delivery)

    def test_transport_fixture_patches_the_transport_owner(self) -> None:
        """Keep HTTP and inventory mocks at their implementation module.

        Returns:
            None: The fixture imports and patches the transport owner directly.
        """
        fixture_source = (
            (WINAPP_DIR / "tests" / "test_redesign.py")
            .read_text(encoding="utf-8")
        )
        self.assertIn("from uploader import http_transport", fixture_source)
        self.assertIn("mock.patch.object(http_transport", fixture_source)
        self.assertNotIn("mock.patch.object(MAIN, '_gallery_key_open'", fixture_source)

    def test_moved_worker_mocks_patch_the_package_owner(self) -> None:
        """Require lifecycle constructor mocks at their imported owner modules.

        Returns:
            None: Moved worker seams have no stale launcher patch targets.
        """
        fixture_names = (
            "test_redesign.py", "test_flight_watch_drain.py",
            "test_watcher_continuation.py", "test_localization.py",
        )
        fixture_sources = "\n".join(
            (WINAPP_DIR / "tests" / name).read_text(encoding="utf-8")
            for name in fixture_names
        )
        for contract in LAUNCHER_MODULE_OWNERS.values():
            patch_globals = contract["patch_globals"]
            for owner, names in patch_globals.items():
                for name in names:
                    with self.subTest(owner=owner, patch_global=name):
                        self.assertIn(f"mock.patch.object({owner}", fixture_sources)
                        self.assertTrue(
                            f'"{name}"' in fixture_sources
                            or f"'{name}'" in fixture_sources,
                        )


if __name__ == "__main__":
    unittest.main()
