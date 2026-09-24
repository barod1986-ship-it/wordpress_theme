#!/usr/bin/env bash
# يبني ملفَي التثبيت في dist/ ويتحقق من بنيتهما (مجلد واحد في جذر كل ملف، كما يطلب ووردبريس).
set -euo pipefail
cd "$(dirname "$0")/../.."

rm -rf dist
mkdir -p dist
zip -rqX dist/retrovault-core.zip retrovault-core -x '*.DS_Store' '*Thumbs.db' '*.log'
zip -rqX dist/retrovault-theme.zip retrovault -x '*.DS_Store' '*Thumbs.db' '*.log'
unzip -tq dist/retrovault-core.zip
unzip -tq dist/retrovault-theme.zip
unzip -l dist/retrovault-core.zip | grep -q ' retrovault-core/retrovault-core.php$'
unzip -l dist/retrovault-theme.zip | grep -q ' retrovault/style.css$'
ls -l dist
