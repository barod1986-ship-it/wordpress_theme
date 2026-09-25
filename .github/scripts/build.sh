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
# بلا أنبوب إلى grep -q: يتوقف grep عند أول تطابق فيُقتل unzip وهو يكتب (SIGPIPE)، ومع pipefail
# كان البناء يفشل أحياناً. unzip نفسه يُرجع خطأً إن لم يجد المسار بالضبط في جذر الملف.
unzip -l dist/retrovault-core.zip retrovault-core/retrovault-core.php > /dev/null
unzip -l dist/retrovault-theme.zip retrovault/style.css > /dev/null
ls -l dist
