#!/usr/bin/env bash

# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
#
# File: deploy.sh
# Module Type: macOS/Linux Wrapper Script
#
# Purpose:
#   Provides a macOS/Linux command wrapper for PHP Gallery tooling.
#
# Responsibilities:
#   - Forward command-line arguments to the main Bash deploy script
#   - Keep macOS/Linux invocation simple
#   - Ensure deployment packaging continues through the shell workflow
#   - Avoid duplicating script logic
#
# Author:
#   Rudolf Klusal
#
# Contact:
#   https://github.com/klusik
#
# License:
#   MIT License (see LICENSE file in repository)
#
# Notes:
#   - Keep comments and docstrings intact when modifying this file.
#   - Prefer small, readable changes over broad rewrites.
#
# Last Updated:
#   2026-05-12

set -euo pipefail

# Variable script_dir stores the folder containing this wrapper.
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

exec "${script_dir}/scripts/deploy.sh" "$@"
