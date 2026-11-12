# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_362_b1_protocol.py
# Module Type: B1 Benchmark Compatibility Re-export
# Purpose: Preserve benchmark imports while the codec is owned by WinApp.
# Responsibilities:
#   - Re-export the experimental framed and multipart parser API.
#   - Keep benchmark-only imports independent of production transport code.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Compatibility imports for isolated B1 protocol benchmark tools."""

from winapp.uploader.thumbnail_batch_protocol import (
    BOUNDARY_PATTERN,
    BENCHMARK_BOUNDARY,
    FramedParser,
    MAX_ITEMS,
    MAX_RESPONSE_BYTES,
    MultipartParser,
    ProtocolError,
    ValidatedItem,
)

__all__ = [
    "BOUNDARY_PATTERN",
    "BENCHMARK_BOUNDARY",
    "FramedParser",
    "MAX_ITEMS",
    "MAX_RESPONSE_BYTES",
    "MultipartParser",
    "ProtocolError",
    "ValidatedItem",
]
