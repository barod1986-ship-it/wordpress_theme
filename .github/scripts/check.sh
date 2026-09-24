#!/usr/bin/env bash
# فحص سريع قبل البناء: صياغة PHP وJS، وتطابق أرقام الإصدار الأربعة، ووجود الإصدار في CHANGELOG.md.
# يطبع رقم الإصدار، ويكتبه في GITHUB_OUTPUT داخل GitHub Actions.
set -euo pipefail
cd "$(dirname "$0")/../.."

php -v | head -n 1 >&2
find retrovault retrovault-core -name '*.php' -print0 | xargs -0 -n 1 php -l > /dev/null
find retrovault retrovault-core -name '*.js' -print0 | xargs -0 -n 1 node --check

header=$(grep -m 1 -oP '^\s*\*\s*Version:\s*\K\S+' retrovault-core/retrovault-core.php || true)
constant=$(grep -m 1 -oP "RETROVAULT_VERSION',\s*'\K[^']+" retrovault-core/retrovault-core.php || true)
theme=$(grep -m 1 -oP '^Version:\s*\K\S+' retrovault/style.css || true)
theme_constant=$(grep -m 1 -oP "RVT_VERSION',\s*'\K[^']+" retrovault/functions.php || true)
echo "plugin header: $header | RETROVAULT_VERSION: $constant | style.css: $theme | RVT_VERSION: $theme_constant" >&2
if [ -z "$header" ] || [ "$header" != "$constant" ] || [ "$header" != "$theme" ] || [ "$header" != "$theme_constant" ]; then
	echo "::error::Version numbers differ: plugin header=$header, RETROVAULT_VERSION=$constant, style.css=$theme, RVT_VERSION=$theme_constant"
	exit 1
fi
if ! grep -qx "## $header" CHANGELOG.md; then
	echo "::warning::CHANGELOG.md has no '## $header' section; the release notes will only contain install steps."
fi

if [ -n "${GITHUB_OUTPUT:-}" ]; then
	echo "version=$header" >> "$GITHUB_OUTPUT"
fi
echo "$header"
