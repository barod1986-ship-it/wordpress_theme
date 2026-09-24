#!/usr/bin/env bash
# ملاحظات الإصدار: قسم الإصدار من CHANGELOG.md ثم طريقة التثبيت.
# الاستخدام: .github/scripts/release-notes.sh 1.6.0
set -euo pipefail
cd "$(dirname "$0")/../.."

version="$1"
notes=$(awk -v ver="$version" '/^## /{ p = ($2 == ver); next } p' CHANGELOG.md | sed -e '/./,$!d')
if [ -n "$notes" ]; then
	printf '## ما الجديد\n\n%s\n\n' "$notes"
fi
cat <<'NOTES'
## التثبيت

- **الإضافة** `retrovault-core.zip`: الإضافات ← أضف جديد ← رفع إضافة، ثم فعّلها (قبل القالب).
- **القالب** `retrovault-theme.zip`: المظهر ← القوالب ← أضف جديد ← رفع قالب، ثم فعّله.
- **التحديث:** من الإصدار 1.6.0 يظهر كل إصدار جديد تلقائياً في «لوحة التحكم ← التحديثات» ويُثبَّت بضغطة. قبله: ارفع الملفين بالطريقة نفسها واختر «استبدال الحالي بالمرفوع».

لا تستخدم ملفات «Source code» ولا زر «Code ← Download ZIP»: ووردبريس لا يقبلها.
NOTES
