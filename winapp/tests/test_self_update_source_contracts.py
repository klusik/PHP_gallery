# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Enforce explicit updater documentation and type contracts.
# Responsibilities:
#   - Keep new updater modules free of deferred annotation imports.
#   - Require documented classes and documented, annotated definitions.
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_self_update_source_contracts.py
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Focused source contracts for the independent Windows updater modules."""

import ast
from pathlib import Path
import unittest

WINAPP = Path(__file__).resolve().parents[1]
SOURCES = (
    "uploader/self_update.py", "uploader/update_helper.py", "uploader/update_ui.py",
    "tests/test_self_update.py", "tests/test_update_helper.py", "tests/test_update_ui.py",
    "tests/test_self_update_source_contracts.py",
)


class SelfUpdateSourceContracts(unittest.TestCase):
    """Require the user's explicit code documentation and annotation style."""

    def test_updater_definitions_documented_and_typed(self) -> None:
        """Inspect every nested definition without importing GUI/native modules."""
        for relative in SOURCES:
            with self.subTest(source=relative):
                tree = ast.parse((WINAPP / relative).read_text(encoding="utf-8"))
                for node in ast.walk(tree):
                    if isinstance(node, ast.ImportFrom):
                        self.assertNotEqual(node.module, "__future__", relative)
                    if isinstance(node, (ast.ClassDef, ast.FunctionDef, ast.AsyncFunctionDef)):
                        location = f"{relative}:{node.lineno} {node.name}"
                        self.assertTrue(ast.get_docstring(node), location + " needs a docstring")
                    if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
                        self.assertIsNotNone(node.returns, location + " needs a return annotation")
                        arguments = node.args.posonlyargs + node.args.args + node.args.kwonlyargs
                        arguments += [argument for argument in (node.args.vararg, node.args.kwarg)
                                      if argument is not None]
                        for argument in arguments:
                            if argument.arg not in ("self", "cls"):
                                self.assertIsNotNone(argument.annotation,
                                                     location + " argument " + argument.arg)

    def test_version_resource_writer_is_documented_and_typed(self) -> None:
        """Check the new installer helper without auditing legacy definitions."""
        source = (WINAPP / "build_installer.py").read_text(encoding="utf-8")
        tree = ast.parse(source)
        self.assertFalse(
            any(isinstance(node, ast.ImportFrom) and node.module == "__future__"
                for node in tree.body),
            "build_installer.py must not import future annotations",
        )
        function = next(
            node for node in tree.body
            if isinstance(node, ast.FunctionDef) and node.name == "write_version_resource"
        )
        self.assertTrue(ast.get_docstring(function), "write_version_resource needs a docstring")
        self.assertIsNotNone(function.returns, "write_version_resource needs a return annotation")
        arguments = {argument.arg: argument for argument in function.args.args}
        self.assertIsNotNone(arguments["path"].annotation, "path needs an annotation")
        self.assertIsNotNone(arguments["version"].annotation, "version needs an annotation")


if __name__ == "__main__":
    unittest.main()
