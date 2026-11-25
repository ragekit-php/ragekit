#!/usr/bin/env bash
# Prints the CHANGELOG.md section of one version, without its "## [x.y.z] - date" heading: the
# notes of that version's GitHub release. Wrapped lines are joined (GitHub shows each newline of a
# release's notes as a line break); headings, list items, tables and code blocks keep their lines.
#
#   .github/release-notes.sh 0.9.1        # or v0.9.1
set -euo pipefail

version=${1#v}
root=$(cd "$(dirname "$0")/.." && pwd)
notes=$(awk -v heading="## [$version]" '
    index($0, heading) == 1 { found = 1; next }
    found && /^## \[/ { exit }
    found { print }
' "$root/CHANGELOG.md")
if [ -z "${notes//[[:space:]]/}" ]; then
    echo "release-notes: CHANGELOG.md has no section for $version" >&2
    exit 1
fi
printf '%s\n' "$notes" | sed -e '/./,$!d' | awk '
    function flush() { if (line != "") print line; line = "" }
    /^```/ { flush(); fence = !fence; print; next }
    fence { print; next }
    /^[[:space:]]*$/ { flush(); print; next }
    /^(#|\||[[:space:]]*[-*] |[[:space:]]*[0-9]+\. )/ { flush(); line = $0; next }
    line != "" { sub(/^[[:space:]]+/, ""); line = line " " $0; next }
    { line = $0 }
    END { flush() }
'
