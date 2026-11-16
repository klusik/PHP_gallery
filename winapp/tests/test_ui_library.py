# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_ui_library.py
# Module Type: WinApp Component Library Contract Tests
# Purpose: Keep action, dialog, range and style ownership in one UI library.
# Responsibilities:
#   - Reject raw toolkit constructors and style definitions in runtime consumers.
#   - Prove the guard detects aliases, derived bypasses and local style writes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable architectural regression coverage for the shared WinApp UI library."""

import ast
from pathlib import Path
import unittest


WINAPP_DIR = Path(__file__).resolve().parents[1]
LIBRARY_PATH = WINAPP_DIR / 'uploader' / 'ui_theme.py'


def library_bypasses(source: str) -> list[str]:
    """Find raw component construction/inheritance and external style mutation.

    Args:
        source (str): Complete Python consumer source, including its imports.
    Returns:
        list[str]: Line-numbered constructor, inheritance or style-write defects;
            type annotations and native isinstance checks remain permitted.
    """
    tree = ast.parse(source)
    aliases: dict[str, str] = {}
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            for item in node.names:
                aliases[item.asname or item.name.split('.')[0]] = (
                    item.name if item.asname else item.name.split('.')[0]
                )
        elif isinstance(node, ast.ImportFrom) and node.module is not None:
            for item in node.names:
                aliases[item.asname or item.name] = node.module + '.' + item.name

    def qualified(node: ast.AST) -> str | None:
        """Resolve imported names and attributes without executing the consumer.

        Args:
            node (ast.AST): Name or attribute expression from the parsed source.
        Returns:
            str | None: Imported dotted identity, or None for unrelated values.
        """
        if isinstance(node, ast.Name):
            return aliases.get(node.id)
        if isinstance(node, ast.Attribute):
            parent = qualified(node.value)
            return parent + '.' + node.attr if parent else None
        return None

    raw_components = {
        'tkinter.Button', 'tkinter.Scale', 'tkinter.Toplevel',
        'tkinter.ttk.Button', 'tkinter.ttk.Scale',
    }
    style_variables: set[str] = set()
    for node in ast.walk(tree):
        if (isinstance(node, ast.Assign) and isinstance(node.value, ast.Call)
                and qualified(node.value.func) == 'tkinter.ttk.Style'):
            style_variables.update(target.id for target in node.targets
                                   if isinstance(target, ast.Name))
    violations = []
    for node in ast.walk(tree):
        if isinstance(node, ast.ClassDef):
            for base in node.bases:
                if qualified(base) in raw_components:
                    violations.append(f'{node.lineno}: raw component inheritance')
        if not isinstance(node, ast.Call):
            continue
        if qualified(node.func) in raw_components:
            violations.append(f'{node.lineno}: raw component construction')
        if (isinstance(node.func, ast.Attribute) and node.func.attr in {
            'configure', 'map', 'layout', 'element_create', 'theme_create', 'theme_settings',
        }):
            receiver = node.func.value
            if (isinstance(receiver, ast.Name) and receiver.id in style_variables
                    or isinstance(receiver, ast.Call)
                    and qualified(receiver.func) == 'tkinter.ttk.Style'):
                violations.append(f'{node.lineno}: external style mutation')
    return violations


class UiLibraryContractTests(unittest.TestCase):
    """Keep appearance inheritance centralized without starting any UI."""

    def test_runtime_consumers_cannot_recreate_library_controls_or_styles(self) -> None:
        """Scan complete runtime consumers against the single component owner.

        Returns:
            None: Every runtime button/dialog/range uses library construction;
                every ttk style write belongs to the central library.
        """
        paths = [WINAPP_DIR / 'gallery_watch_upload.pyw',
                 *sorted((WINAPP_DIR / 'uploader').rglob('*.py'))]
        for path in paths:
            if path == LIBRARY_PATH:
                continue
            with self.subTest(path=path.relative_to(WINAPP_DIR).as_posix()):
                self.assertEqual([], library_bypasses(path.read_text(encoding='utf-8')))

    def test_guard_rejects_aliases_subclasses_and_each_style_write(self) -> None:
        """Prove negative controls fail while central imports/types remain legal.

        Returns:
            None: Aliased raw constructors, raw derived bases and local style
                mutations are detected; legitimate library use has no findings.
        """
        for source in (
            'from tkinter import ttk as kit\nkit.Button(parent)',
            'from tkinter.ttk import Button as Fresh\nFresh(parent)',
            'import tkinter as kit\nkit.Toplevel(parent)',
            'import tkinter.ttk as kit\nkit.Scale(parent)',
            'from tkinter import Button\nclass Fresh(Button): pass',
            'from tkinter import ttk\nttk.Style(root).configure("New.TButton", padding=8)',
            'from tkinter.ttk import Style as Registry\ns = Registry(root)\ns.layout("New.TButton", [])',
            'from tkinter import ttk\ns = ttk.Style(root)\ns.map("TButton", foreground=[])',
        ):
            with self.subTest(source=source):
                self.assertTrue(library_bypasses(source))
        self.assertEqual([], library_bypasses(
            'from tkinter import ttk\n'
            'from uploader.ui_theme import Button, Dialog, Slider\n'
            'button: ttk.Button = Button(parent)\n'
            'assert isinstance(button, ttk.Button)\n'
            'Dialog(parent)\nSlider(parent)\n'
            'ttk.Style(parent).lookup("TButton", "foreground")'
        ))


if __name__ == '__main__':
    unittest.main()
