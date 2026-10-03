#!/usr/bin/env python3
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: scripts/source_contracts/python_scan.py
# Module Type: Python Declaration Scanner
# Purpose: Read Python AST declarations without importing or executing project code.
# Responsibilities:
#   - Emit documentation, signature and executable fingerprint records for the audit.
#   - Keep parser failures bounded and support native typed Google-style docstrings.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

import ast
import copy
import hashlib
import json
import re
import sys
from pathlib import Path


def normalize_doc(documentation: str) -> str:
    """Convert typed Google sections into the shared parameter/return contract.

    @param str documentation Native Python docstring, already dedented by AST.
    @return str Summary and typed tags; existing tag-style docstrings stay intact.
    """
    if re.search(r"@(param|return|returns)\b", documentation):
        return documentation
    result: list[str] = []
    section = "summary"
    for line in documentation.splitlines():
        stripped = line.strip()
        if stripped in ("Args:", "Arguments:", "Parameters:"):
            section = "params"
        elif stripped == "Returns:":
            section = "return"
        elif stripped == "Yields:":
            # A yielded element is not the callable's Iterator/Generator return type.
            # Generator functions must document that return contract separately.
            section = "other"
        elif re.fullmatch(r"[A-Za-z ]+:", stripped) and not line[:1].isspace():
            section = "other"
        elif section == "params":
            match = re.fullmatch(r"\*{0,2}(\w+)\s*(?:\((.+)\))?\s*:\s*(.*)", stripped)
            if match:
                name, type_name, description = match.groups()
                result.append(f"@param {{{type_name or ''}}} {name} {description}")
            elif result and stripped:
                result[-1] += " " + stripped
        elif section == "return":
            if stripped and (not result or not result[-1].startswith("@return ")):
                type_name, separator, description = stripped.partition(":")
                result.append(f"@return {{{type_name if separator else ''}}} {description if separator else stripped}")
            elif stripped:
                result[-1] += " " + stripped
        elif section == "summary":
            result.append(line)
    return "\n".join(result)


def annotation_text(annotation: ast.expr | None) -> str:
    """Read an annotation without evaluating forward references or runtime names.

    @param ast.expr|None annotation Parsed annotation or absent syntax.
    @return str Native type spelling with string forward references unwrapped.
    """
    if annotation is None:
        return ""
    if isinstance(annotation, ast.Constant) and isinstance(annotation.value, str):
        return annotation.value
    return ast.unparse(annotation)


def builtin_alias(match: re.Match[str]) -> str:
    """Lowercase a known typing container alias during lexical normalization.

    @param re.Match[str] match Matched legacy builtin container spelling.
    @return str Equivalent modern builtin spelling.
    """
    return match[0].lower()


def known_type(type_name: str) -> str:
    """Canonicalize only known builtin typing expressions for definite comparison.

    @param str type_name Native or documented annotation expression.
    @return str Canonical type, or empty string for aliases requiring human review.
    """
    # Nested forward references and Literal values need context-sensitive resolution;
    # compare neither rather than reporting a definite mismatch from string contents.
    if "'" in type_name or '"' in type_name or re.search(r"\bLiteral\b", type_name):
        return ""
    type_name = re.sub(r"\b(?:typing|collections\.abc)\.", "", type_name.strip())
    type_name = re.sub(r"\b(List|Dict|Tuple|Set|FrozenSet|Type)\b", builtin_alias, type_name)
    # Unknown aliases and user classes cannot be judged without name resolution.
    allowed = {"int", "str", "bool", "float", "bytes", "None", "object", "list", "dict", "tuple", "set", "frozenset", "type", "Optional", "Union", "Any", "Iterator", "Iterable", "Generator", "Awaitable", "Coroutine", "Callable", "Sequence", "Mapping", "Literal"}
    if any(name not in allowed for name in re.findall(r"\b[A-Za-z_]\w*\b", type_name)):
        return ""
    try:
        node = ast.parse(type_name, mode="eval").body
    except SyntaxError:
        return ""
    return canonical_type(node)


def union_members(node: ast.expr) -> list[str]:
    """Flatten only unions at the current level while preserving nested generics.

    @param ast.expr node Known annotation expression or individual union member.
    @return list[str] Canonical outer members without splitting nested type arguments.
    """
    if isinstance(node, ast.BinOp) and isinstance(node.op, ast.BitOr):
        return union_members(node.left) + union_members(node.right)
    if isinstance(node, ast.Subscript) and ast.unparse(node.value) in ("Optional", "Union"):
        arguments = list(node.slice.elts) if isinstance(node.slice, ast.Tuple) else [node.slice]
        members: list[str] = ["None"] if ast.unparse(node.value) == "Optional" else []
        for argument in arguments:
            members.extend(union_members(argument))
        return members
    return [canonical_type(node)]


def canonical_type(node: ast.expr) -> str:
    """Normalize union ordering, Optional and nested generic arguments recursively.

    @param ast.expr node Parsed known builtin annotation expression.
    @return str Comparable type expression with sorted union members.
    """
    if isinstance(node, ast.BinOp) and isinstance(node.op, ast.BitOr):
        return "|".join(sorted(set(union_members(node))))
    if isinstance(node, ast.Subscript):
        name = ast.unparse(node.value)
        arguments = list(node.slice.elts) if isinstance(node.slice, ast.Tuple) else [node.slice]
        if name in ("Optional", "Union"):
            return "|".join(sorted(set(union_members(node))))
        return name + "[" + ",".join(canonical_type(argument) for argument in arguments) + "]"
    return ast.unparse(node).replace(" ", "")


def type_issues(parameters: list[dict[str, str]], return_type: str, documentation: str) -> list[str]:
    """Find missing native annotations and definite builtin documentation mismatches.

    @param list[dict[str,str]] parameters Explicit parameters excluding bound receivers.
    @param str return_type Native return annotation, empty when absent.
    @param str documentation Normalized summary and typed contract tags.
    @return list[str] Stable rule identifiers without source values or type spellings.
    """
    issues: list[str] = []
    tags: dict[str, str] = {}
    for match in re.finditer(r"@param\s+(?:\{([^{}]*)\}|(\S+))\s+\$?(\w+)", documentation):
        tags[match[3]] = match[1] or match[2] or ""
    for parameter in parameters:
        name = parameter["name"]
        if not parameter["type"]:
            issues.append("typing.parameter_missing:" + name)
        native = known_type(parameter["type"])
        documented = known_type(tags.get(name, ""))
        if native and documented and native != documented and "Any" not in (native, documented):
            issues.append("parameter.type_mismatch:" + name)
    if not return_type:
        issues.append("typing.return_missing")
    match = re.search(r"@returns?\s+(?:\{([^{}]*)\}|(\S+))", documentation)
    native = known_type(return_type)
    documented = known_type((match[1] or match[2] or "") if match else "")
    if native and documented and native != documented and "Any" not in (native, documented):
        issues.append("return.type_mismatch")
    return issues


def executable_fingerprint(node: ast.AST) -> str:
    """Hash executable AST fields while removing all nested declaration docstrings.

    @param ast.AST node Class or function whose implementation is compared.
    @return str SHA-256 identity used internally; never included in user diagnostics.
    """
    executable = copy.deepcopy(node)
    for child in ast.walk(executable):
        if isinstance(child, (ast.ClassDef, ast.FunctionDef, ast.AsyncFunctionDef)) and ast.get_docstring(child) is not None:
            child.body = child.body[1:]
    return hashlib.sha256(ast.dump(executable, include_attributes=False).encode("utf-8")).hexdigest()


class DeclarationScanner(ast.NodeVisitor):
    """Collect nested Python classes and callables with stable containing identities."""

    def __init__(self) -> None:
        """Initialize isolated declaration and lexical owner state.

        @return None No project code or runtime modules are imported.
        """
        self.snapshots: list[dict[str, object]] = []
        self.owners: list[str] = []
        self.owner_kinds: list[str] = []

    def visit_ClassDef(self, node: ast.ClassDef) -> None:
        """Visit a class docstring and each nested declaration independently.

        @param ast.ClassDef node Parsed class with its original source coordinates.
        @return None Appends stable class and method snapshots.
        """
        self.collect(node, "class", [], "")

    def visit_FunctionDef(self, node: ast.FunctionDef | ast.AsyncFunctionDef) -> None:
        """Collect all positional, keyword-only, variadic and asynchronous signatures.

        @param ast.FunctionDef|ast.AsyncFunctionDef node Parsed callable declaration.
        @return None Appends callable evidence and visits nested definitions.
        """
        arguments = list(node.args.posonlyargs) + list(node.args.args)
        decorators = {ast.unparse(decorator).split("(", 1)[0] for decorator in node.decorator_list}
        is_method = bool(self.owner_kinds and self.owner_kinds[-1] == "class")
        if is_method and not decorators.intersection({"staticmethod", "builtins.staticmethod"}) and arguments:
            arguments = arguments[1:]  # Bound self/cls is implicit in a method's API.
        arguments += list(node.args.kwonlyargs)
        if node.args.vararg:
            arguments.append(node.args.vararg)
        if node.args.kwarg:
            arguments.append(node.args.kwarg)
        parameters = [{"name": argument.arg, "type": annotation_text(argument.annotation)} for argument in arguments]
        self.collect(node, "callable", parameters, annotation_text(node.returns))

    def visit_AsyncFunctionDef(self, node: ast.AsyncFunctionDef) -> None:
        """Route asynchronous functions through the same complete signature collector.

        @param ast.AsyncFunctionDef node Parsed async callable declaration.
        @return None Preserves async syntax in its executable fingerprint.
        """
        self.visit_FunctionDef(node)

    def collect(self, node: ast.ClassDef | ast.FunctionDef | ast.AsyncFunctionDef, kind: str, parameters: list[dict[str, str]], return_type: str) -> None:
        """Record one declaration and descend with its containing lexical identity.

        @param ast.ClassDef|ast.FunctionDef|ast.AsyncFunctionDef node Class or callable.
        @param str kind Shared class or callable declaration category.
        @param list[dict[str,str]] parameters Explicit signature parameters.
        @param str return_type Native return type, absent for classes.
        @return None Appends internal snapshots without executing the source.
        """
        documentation = normalize_doc(ast.get_docstring(node) or "")
        identity = "/".join(self.owners + [kind + ":" + node.name])
        record = {"kind": kind, "language": "python", "name": node.name, "line": node.lineno, "params": parameters, "return_type": return_type, "doc": documentation,
                  "type_issues": type_issues(parameters, return_type, documentation) if kind != "class" else []}
        self.snapshots.append({"identity": identity, "fingerprint": executable_fingerprint(node), "record": record, "uncertain": False})
        self.owners.append(kind + ":" + node.name)
        self.owner_kinds.append(kind)
        self.generic_visit(node)
        self.owner_kinds.pop()
        self.owners.pop()


def import_findings(tree: ast.AST) -> list[dict[str, object]]:
    """Find forbidden annotation future imports in parsed executable syntax.

    @param ast.AST tree Parsed source tree, never evaluated or imported.
    @return list[dict[str,object]] File-level line numbers and stable policy rules.
    """
    findings: list[dict[str, object]] = []
    for node in ast.walk(tree):
        if isinstance(node, ast.ImportFrom) and node.module == "__future__" and node.level == 0:
            if any(alias.name == "annotations" for alias in node.names):
                findings.append({"line": node.lineno, "rule": "python.future_annotations"})
    return sorted(findings, key=import_finding_line)


def import_finding_line(finding: dict[str, object]) -> int:
    """Read the source coordinate used to order file-level import diagnostics.

    @param dict[str,object] finding Import policy finding created by the AST scanner.
    @return int One-based line number of the forbidden import statement.
    """
    return int(str(finding["line"]))


def scan_sources(sources: dict[str, str]) -> dict[str, dict[str, list[dict[str, object]]]]:
    """Parse a batch of sources without following paths or evaluating annotations.

    @param dict[str,str] sources Opaque source IDs mapped to already discovered contents.
    @return dict[str,dict[str,list[dict[str,object]]]] Declaration and import reports per source ID.
    """
    result: dict[str, dict[str, list[dict[str, object]]]] = {}
    for identity, source in sources.items():
        scanner = DeclarationScanner()
        tree = ast.parse(source.removeprefix("\ufeff"))
        scanner.visit(tree)
        result[identity] = {"snapshots": scanner.snapshots, "import_findings": import_findings(tree)}
    return result


def main() -> int:
    """Read one temporary JSON request and emit bounded parser results.

    @return int Zero for complete AST coverage, two for an unreadable or invalid source.
    """
    try:
        request = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
        print(json.dumps(scan_sources(request), ensure_ascii=True))
        return 0
    except (OSError, ValueError, SyntaxError, TypeError, IndexError, RecursionError):
        print("Python source parsing could not complete; coverage unknown.", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
