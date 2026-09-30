#!/usr/bin/env bash
# Install QA dependencies only in a source copy without the distribution bundle.
set -euo pipefail
source_dir=$(cd "$(dirname "$0")/.." && pwd)
qa_dir=${1:?Usage: bin/prepare-qa.sh /absolute/path/to/new-qa-directory}
case "$qa_dir" in
    /*) ;;
    *) echo "QA directory must be an absolute path." >&2; exit 1 ;;
esac
if [ -e "$qa_dir" ]; then
    echo "QA directory must not already exist: $qa_dir" >&2
    exit 1
fi
case "$qa_dir/" in
    "$source_dir/"*) echo "QA directory must be outside the source checkout." >&2; exit 1 ;;
esac
mkdir -p "$qa_dir"
tar -C "$source_dir" --exclude='./.git' --exclude='./vendor' --exclude='./plugin-build' -cf - . | tar -C "$qa_dir" -xf -
cd "$qa_dir"
composer install --no-interaction --no-progress --prefer-dist
