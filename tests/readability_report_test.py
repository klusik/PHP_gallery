#!/usr/bin/env python3
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tests/readability_report_test.py
# Module Type: Advisory Reporter Regression
# Purpose: Check meaningful advisory outcomes, exclusions and immutable source.
# Responsibilities: Exercise real language adapters and complete report safety.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "scripts"))
import readability_report as reporter
from readability_documents import group_end


# Bound complete-report fixture children, including the rejected-SHA case.
# Type: int. Units: seconds. Scope: one subprocess in the real-Git smoke fixture.
# Consumers: successful report publication and mismatched-event rejection checks.
# Rationale: preserve the 45-second child budget below the PHP bridge's 75-second
# overall budget; small fixtures should fail promptly when a reporter stalls.
REPORT_FIXTURE_TIMEOUT_SECONDS: int = 45


class ReadabilityReportTests(unittest.TestCase):
    """Exercise actual scanners and Git blobs, including adverse boundaries."""

    def setUp(self) -> None:
        """Read the selected branch's actual advisory configuration.

        @return None Retain fresh rules for each independent case.
        """
        self.rules = json.loads((ROOT / "scripts/readability_rules.json").read_text())

    def test_width_tiers_and_display_columns(self) -> None:
        """Distinguish measured boundaries, tabs and Unicode display width.

        @return None Assert informational/prominent tiers without hard errors.
        """
        # Four-column tab + A (one) + wide ideograph (two) + e/mark (one).
        self.assertEqual(reporter.display_width("\tA界e\u0301", 4), 8)
        # Exact tier edges and interior samples protect the configured defaults.
        for width, tier in ((80, None), (81, "info"), (95, "info"),
                            (100, "info"), (101, "advisory"),
                            (130, "advisory"), (161, "priority")):
            # call( plus ); occupy seven columns; each item, entry uses six.
            source = ("call(" + "item, " * ((width - 7) // 6)
                      + "x" * ((width - 7) % 6) + ");")
            self.assertEqual(len(source), width)
            structure = {"code": [source], "literals": {}, "declarations": []}
            findings, _ = reporter.analyze("sample.js", "js", source,
                                           structure, self.rules)
            self.assertEqual([item.tier for item in findings], [tier] if tier else [])

    def test_real_tokenizers_ignore_literals_and_keep_nested_calls(self) -> None:
        """Keep URLs, heredocs, regexes and comments inert in PHP/JS/MJS.

        @return None Assert useful width advice only on actual code structure.
        """
        wide = "call(" + ", ".join(["argument_name"] * 8) + ");"
        sources = {
            "sample.php": {"language": "php", "source": "<?php\n" + wide},
            "sample.js": {"language": "js", "source": wide},
            "sample.mjs": {"language": "mjs", "source": wide},
            "literal.php": {"language": "php", "source":
                '<?php\n$x = <<<' + "'SQL'\n" + wide + "\nSQL;\n"},
            "literal.js": {"language": "js", "source":
                'const url = "https://example.test/' + "x" * 150 + '";\n'
                + "// " + wide + "\nconst regex = /a{2,200},b/;"},
            "literal-template.php": {"language": "php", "source":
                "<div data-value='" + "x" * 100
                + "'><?php echo escape($value, ENT_QUOTES); ?></div>"},
        }
        structures = reporter.token_adapter(ROOT, sources)["files"]
        for path, row in sources.items():
            findings, _ = reporter.analyze(path, row["language"], row["source"],
                                           structures[path], self.rules)
            self.assertEqual(len(findings), 0 if path.startswith("literal") else 1)

    def test_python_spacing_decorators_and_strings(self) -> None:
        """Respect Python top-level/class spacing and attached decorators.

        @return None Assert missing spacing without flagging a correct sibling.
        """
        source = ('def first():\n    return "' + "x" * 140
                  + '"\n\n@decorator\ndef second():\n    pass\n')
        findings, _ = reporter.analyze("sample.py", "python", source,
                                       reporter.python_structure(source), self.rules)
        self.assertEqual([item.rule for item in findings], ["declaration-spacing"])
        fixed = source.replace("\n\n@decorator", "\n\n\n@decorator")
        findings, _ = reporter.analyze("sample.py", "python", fixed,
                                       reporter.python_structure(fixed), self.rules)
        self.assertEqual(findings, [])
        fstring = 'message = f"' + "literal, " * 20 + '{value}"'
        findings, _ = reporter.analyze(
            "sample.py", "python", fstring,
            reporter.python_structure(fstring), self.rules,
        )
        self.assertEqual(findings, [])

    def test_php_js_spacing_preserves_doc_attachment(self) -> None:
        """Compare real named sibling boundaries before their attached docs.

        @return None Assert advice disappears after a semantic-free blank line.
        """
        for language in ("php", "js", "mjs"):
            source = ("<?php\n" if language == "php" else "")
            source += "function first() {}\n/** Second. */\nfunction second() {}\n"
            path = "sample." + language
            structure = reporter.token_adapter(ROOT, {
                path: {"language": language, "source": source},
            })["files"][path]
            findings, _ = reporter.analyze(path, language, source, structure, self.rules)
            self.assertEqual([item.rule for item in findings], ["declaration-spacing"])

    def test_css_context_and_documented_exceptions(self) -> None:
        """Keep data URLs inert and require narrow, explained exceptions.

        @return None Assert useful selector advice and visible stale exceptions.
        """
        source = ", ".join([".long-selector-name"] * 6) + " { color: red; }"
        findings, _ = reporter.analyze("sample.css", "css", source,
                                       reporter.css_structure(source), self.rules)
        self.assertEqual(len(findings), 1)
        exception = {"path": "sample.css", "rule": "line-width",
                     "contains": ".long-selector-name", "reason": "External snapshot."}
        retained, suppressed, stale = reporter.apply_exceptions(findings, [exception])
        self.assertEqual((len(retained), len(suppressed), len(stale)), (0, 1, 0))
        self.assertEqual(len(reporter.apply_exceptions([], [exception])[2]), 1)
        with self.assertRaises(ValueError):
            reporter.apply_exceptions(findings, [{**exception, "reason": ""}])

    def test_discovery_exclusions_and_immutable_blobs(self) -> None:
        """Read committed files, excluding generated/vendor/minified/link paths.

        @return None Prove modified/untracked bytes cannot enter source analysis.
        """
        directories = reporter.token_adapter(ROOT, {})["excluded_directories"]
        for path, mode in (("vendor/lib.js", "100644"),
                           ("public/assets/vendor/a.js", "100644"),
                           ("app/runtime/modules.php", "100644"),
                           ("bundle.min.js", "100644"),
                           ("alias.php", "120000")):
            self.assertTrue(reporter.exclusion(path, mode, self.rules, directories))
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            reporter.command(root, ["git", "init", "-q"])
            (root / "sample.js").write_text("committed();")
            reporter.command(root, ["git", "add", "sample.js"])
            reporter.command(root, ["git", "-c", "user.name=Fixture",
                "-c", "user.email=fixture@example.test", "commit", "-qm", "fixture"])
            sha = reporter.command(root, ["git", "rev-parse", "HEAD"]).decode().strip()
            (root / "sample.js").write_text("changed();")
            (root / "untracked.js").write_text("untracked();")
            sources, _ = reporter.snapshot(root, sha, self.rules, directories)
            self.assertEqual(list(sources), ["sample.js"])
            self.assertEqual(sources["sample.js"]["source"], "committed();")

    def test_tex_prose_and_literal_exceptions(self) -> None:
        """Recommend TeX prose wrapping while retaining opaque document syntax.

        @return None Assert prose advice and silence for commands/math/literals.
        """
        prose = "Readable source paragraphs preserve the same rendered meaning. " * 3
        findings, _ = reporter.analyze(
            "manual.tex", "tex", prose,
            reporter.tex_structure(prose), self.rules,
        )
        self.assertEqual(len(findings), 1)
        self.assertIn("paragraph breaks", findings[0].suggestion)
        protected = "\n".join([
            r"\url{https://example.test/" + "x" * 140 + "}",
            r"\codeword{outer{nested} (unbalanced literal " + prose + "}",
            r"\verb|" + prose + "|",
            r"$" + prose + "$",
            r"\[" + prose + r"\]",
            r"\begin{lstlisting}", prose, r"\end{lstlisting}",
            r"\begin{align*}", prose, r"\end{align*}",
            r"\begin{longtable}{ll}", prose, r"\end{longtable}",
            "% " + prose,
        ])
        findings, _ = reporter.analyze(
            "manual.tex", "tex", protected,
            reporter.tex_structure(protected), self.rules,
        )
        self.assertEqual(findings, [])

    def test_tex_escaped_controls_and_nested_groups(self) -> None:
        """Keep escaped percent/dollar controls from masking following prose.

        @return None Assert wrapping remains available outside opaque arguments.
        """
        source = (r"Coverage \% and cost \$ are prose, "
                  + "which remains readable with careful source wrapping. " * 3)
        structure = reporter.tex_structure(source)
        findings, _ = reporter.analyze(
            "manual.tex", "tex", source, structure, self.rules,
        )
        self.assertEqual(len(findings), 1)
        self.assertEqual(group_end("{nested{value} (text}", 0), 21)

    def test_markdown_prose_and_structural_exceptions(self) -> None:
        """Recommend Markdown prose wrapping without touching structural rows.

        @return None Assert literal/link/table/headings and code stay exempt.
        """
        prose = "Readable Markdown paragraphs preserve their intended meaning. " * 3
        findings, _ = reporter.analyze(
            "guide.md", "markdown", "- " + prose,
            reporter.markdown_structure("- " + prose), self.rules,
        )
        self.assertEqual(len(findings), 1)
        self.assertIn("list indent", findings[0].suggestion)
        protected = "\n".join([
            "# " + prose,
            "| Column | " + prose + " |",
            "[reference]: https://example.test/" + "x" * 140,
            "[caption](https://example.test/" + "x" * 140 + ")",
            "`" + prose + "`",
            "    " + prose,
            "```tex", prose, "```",
            "<!--", prose, "-->",
            "<pre>", prose, "</pre>",
        ])
        findings, _ = reporter.analyze(
            "guide.md", "markdown", protected,
            reporter.markdown_structure(protected), self.rules,
        )
        self.assertEqual(findings, [])

    def test_markdown_nested_fences_and_front_matter(self) -> None:
        """Require a matching fence and keep document metadata opaque.

        @return None Assert only the real prose after fenced examples is flagged.
        """
        prose = "This is intentionally wide prose for a readable source report. " * 3
        source = "\n".join([
            "---", "title: " + prose, "---",
            "~~~~", "~~~", prose, "~~~~", prose + "  ",
        ])
        findings, _ = reporter.analyze(
            "guide.md", "markdown", source,
            reporter.markdown_structure(source), self.rules,
        )
        self.assertEqual([item.line for item in findings], [8])
        self.assertTrue(findings[0].context.endswith("  "))

    def test_workflow_uses_selected_source_and_no_required_profiles(self) -> None:
        """Retain branch-only dispatch, immutable checkout and read-only authority.

        @return None Assert no default-branch evaluator or style gate is installed.
        """
        source = (ROOT / ".github/workflows/readability-report.yml").read_text()
        self.assertIn("  workflow_dispatch:\n", source)
        self.assertNotIn("    inputs:", source)
        self.assertNotIn("  push:", source)
        self.assertIn("ref: ${{ github.sha }}", source)
        self.assertIn('test "${WORKFLOW_SHA}" = "${GITHUB_SHA}"', source)
        self.assertIn("persist-credentials: false", source)
        self.assertIn("contents: read", source)
        self.assertNotIn(": write", source)
        self.assertIn("--profile=readability", source)
        self.assertNotIn("continue-on-error", source)
        self.assertIn("if: ${{ always() }}", source)

    def test_complete_report_success_escaping_and_no_rewrites(self) -> None:
        """Produce a full report from a real isolated committed source tree.

        @return None Prove findings exit zero, HTML is escaped and blobs stay intact.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "scripts").mkdir()
            (root / "docs").mkdir()
            shutil.copy2(ROOT / "docs/CODE_READABILITY.md",
                         root / "docs/CODE_READABILITY.md")
            for name in ("readability_report.py", "readability_documents.py",
                         "readability_tokens.php",
                         "readability_rules.json", "cli_guard.php"):
                shutil.copy2(ROOT / "scripts" / name, root / "scripts" / name)
            shutil.copytree(ROOT / "scripts/source_contracts",
                            root / "scripts/source_contracts")
            source = 'call("<script>alert(1)</script>", ' + ", ".join(
                ["argument_name"] * 8) + ");"
            (root / "sample.js").write_text(source)
            prose = "Ordinary document prose needs readable source wrapping. " * 3
            (root / "sample.tex").write_text(prose)
            (root / "sample.md").write_text(prose)
            reporter.command(root, ["git", "init", "-q"])
            reporter.command(root, ["git", "add", "."])
            reporter.command(root, ["git", "-c", "user.name=Fixture",
                "-c", "user.email=fixture@example.test", "commit", "-qm", "fixture"])
            environment = {key: value for key, value in os.environ.items()
                           if not key.startswith("GITHUB_")}
            result = subprocess.run(
                [sys.executable, "scripts/readability_report.py", "--output", "output"],
                cwd=root, env=environment, capture_output=True,
                timeout=REPORT_FIXTURE_TIMEOUT_SECONDS,
                check=False,
            )
            self.assertEqual(result.returncode, 0, result.stderr.decode())
            report = json.loads((root / "output/report.json").read_text())
            self.assertTrue(report["advisory_only"])
            self.assertTrue(report["findings"])
            document_languages = {
                row["language"] for row in report["findings"]
                if row["path"] in ("sample.tex", "sample.md")
            }
            self.assertEqual(document_languages, {"tex", "markdown"})
            document = (root / "output/report.html").read_text()
            self.assertNotIn("<script>alert(1)</script>", document)
            self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", document)
            for name in ("report.md", "summary.md", "diagnostics.jsonl"):
                self.assertTrue((root / "output" / name).is_file())
            self.assertEqual((root / "sample.js").read_text(), source)
            environment["GITHUB_SHA"] = "0" * 40
            rejected = subprocess.run(
                [sys.executable, "scripts/readability_report.py", "--output", "wrong"],
                cwd=root, env=environment, capture_output=True,
                timeout=REPORT_FIXTURE_TIMEOUT_SECONDS,
                check=False,
            )
            self.assertNotEqual(rejected.returncode, 0)
            self.assertFalse((root / "wrong/report.html").exists())

    def test_actual_repository_before_after_sample(self) -> None:
        """Calibrate width advice against the existing request identity line.

        @return None Assert a real wide call is found and focused wrapping helps.
        """
        path = "public/assets/gallery-modules/admin-cache-cleanup.js"
        guide = (ROOT / "docs/CODE_READABILITY.md").read_text(encoding="utf-8")
        original = re.search(r"^-([ ]+const runId = Array.from\(.+)$",
                             guide, re.M)[1]
        structure = reporter.token_adapter(ROOT, {
            path: {"language": "js", "source": original},
        })["files"][path]
        findings, _ = reporter.analyze(path, "js", original, structure, self.rules)
        self.assertEqual(len(findings), 1)
        self.assertGreater(findings[0].width, 100)
        proposed = [
            "        const runId = Array.from(",
            "            crypto.getRandomValues(new Uint8Array(16)),",
            "            byte => byte.toString(16).padStart(2, '0'),",
            "        ).join('');",
        ]
        self.assertTrue(all(reporter.display_width(line, 4) <= 80
                            for line in proposed))


if __name__ == "__main__":
    unittest.main(verbosity=2)
