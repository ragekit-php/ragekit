#!/usr/bin/env bash
# Runs every e2e test, several at once, each on its own Xvfb display. CI runs this too.
# Prints one line per test as it finishes, then the end of the log of each test that failed.
#
#   tests/e2e/all.sh              # RAGEKIT_E2E_JOBS tests at once (default 4)
#   tests/e2e/all.sh -j 2
#   tests/e2e/all.sh scroll tray  # only tests whose name contains one of the words
#
# Needs Xvfb plus what the tests themselves need (see each script). With ccache installed,
# /usr/lib/ccache goes first on PATH, so rebuilds of the same C++ are cached.
set -uo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
e2e="$root/tests/e2e"
jobs=${RAGEKIT_E2E_JOBS:-4}
filters=()
while [ $# -gt 0 ]; do
    case "$1" in
        -j) jobs=$2; shift 2 ;;
        -j*) jobs=${1#-j}; shift ;;
        *) filters+=("$1"); shift ;;
    esac
done

# name|commands. Longest first, so the slowest don't start last. The first entry builds inside
# demo/ (demos, kitchen sink, title bar share those build folders), so it stays one job, in order.
tests=(
    "Demos and kitchen sink (in demo/: normal builds, kitchen sink also Nano)|$e2e/demos.sh && DEMOS=kitchen-sink $e2e/demos.sh --nano && $e2e/kitchen-sink.sh && $e2e/kitchen-sink.sh --kit=material && $e2e/titlebar.sh"
    "ScrollView as a chat (normal, Nano, virtual)|$e2e/scroll.sh && $e2e/scroll.sh --nano && VIRTUAL=1 $e2e/scroll.sh"
    "Clipboard, text and pasted images (normal and Nano)|$e2e/clipboard.sh && $e2e/clipboard.sh --nano && $e2e/paste-image.sh && $e2e/paste-image.sh --nano"
    "Pressable Views, context menus and shortcuts (normal, Nano, Material)|$e2e/pressable.sh && $e2e/pressable.sh --nano && $e2e/pressable.sh --kit=material"
    "Markdown text and colour emoji (normal and Nano)|$e2e/rich-text.sh && $e2e/rich-text.sh --nano"
    "Arrays inside collection rows (normal and Nano)|$e2e/gallery.sh && $e2e/gallery.sh --nano"
    "Multipart upload through the http module (normal and Nano)|$e2e/upload.sh && $e2e/upload.sh --nano"
    "App links, single instance (normal and Nano)|$e2e/links.sh && $e2e/links.sh --nano"
    "Timers (normal and Nano)|$e2e/timer.sh && $e2e/timer.sh --nano"
    "Crypto module (normal and Nano)|$e2e/crypto.sh && $e2e/crypto.sh --nano"
    "Tray module, an app in a fake tray (normal and Nano)|$e2e/tray.sh && $e2e/tray.sh --nano"
    "Notifications module, a fake notification service (normal and Nano)|$e2e/notifications.sh && $e2e/notifications.sh --nano"
    "Dialogs module, file dialogs answered by a fake portal (normal and Nano)|$e2e/dialogs.sh && $e2e/dialogs.sh --nano"
    "Keyring module, a device key in a throwaway keyring (normal and Nano)|$e2e/keyring.sh && $e2e/keyring.sh --nano"
)

if [ -d /usr/lib/ccache ] && [[ ":$PATH:" != *":/usr/lib/ccache:"* ]]; then
    export PATH="/usr/lib/ccache:$PATH"
fi
[ -z "${RAGEKIT_TOOLCHAIN:-}" ] && [ -d "$root/.ragekit" ] && export RAGEKIT_TOOLCHAIN="$root/.ragekit"
# Each test's build compiles several files at once; together they shouldn't use more than the CPUs.
cpus=$(nproc 2>/dev/null || echo 4)
export RAGEKIT_JOBS=${RAGEKIT_JOBS:-$(( cpus / jobs > 0 ? cpus / jobs : 1 ))}
unset WAYLAND_DISPLAY

logs=$(mktemp -d)
selected=()
for entry in "${tests[@]}"; do
    name=${entry%%|*}
    if [ ${#filters[@]} -gt 0 ]; then
        match=0
        for word in "${filters[@]}"; do
            [[ "${name,,}" == *"${word,,}"* ]] && match=1
        done
        [ $match -eq 1 ] || continue
    fi
    selected+=("$entry")
done
[ ${#selected[@]} -gt 0 ] || { echo "e2e: no test matches ${filters[*]}"; exit 1; }

# One test: its own display (:100 + its number), its own log; prints "ok|FAIL seconds name".
run() {
    local n=$1 entry=$2
    local name=${entry%%|*} commands=${entry#*|} display=$((100 + n)) log="$logs/$n.log" start=$SECONDS xvfb status
    Xvfb ":$display" -screen 0 1280x1024x24 -nolisten tcp >/dev/null 2>&1 &
    xvfb=$!
    for _ in $(seq 1 50); do DISPLAY=":$display" xdpyinfo >/dev/null 2>&1 && break; sleep 0.1; done
    DISPLAY=":$display" bash -c "$commands" >"$log" 2>&1
    status=$?
    kill "$xvfb" 2>/dev/null
    wait "$xvfb" 2>/dev/null
    if [ $status -eq 0 ]; then
        printf 'ok   %4ds  %s\n' $((SECONDS - start)) "$name"
    else
        printf 'FAIL %4ds  %s\n' $((SECONDS - start)) "$name"
        touch "$logs/$n.failed"
    fi
}

echo "e2e: ${#selected[@]} tests, $jobs at once (logs in $logs)"
start=$SECONDS
for i in "${!selected[@]}"; do
    while [ "$(jobs -rp | wc -l)" -ge "$jobs" ]; do
        wait -n
    done
    run "$i" "${selected[$i]}" &
done
wait

failed=0
for i in "${!selected[@]}"; do
    if [ -e "$logs/$i.failed" ]; then
        failed=$((failed + 1))
        echo
        echo "--- ${selected[$i]%%|*}: last lines of $logs/$i.log"
        tail -n 40 "$logs/$i.log"
    fi
done
echo
echo "e2e: $(( ${#selected[@]} - failed ))/${#selected[@]} passed in $(( (SECONDS - start) / 60 )) min $(( (SECONDS - start) % 60 )) s"
[ $failed -eq 0 ] && rm -rf "$logs"
[ $failed -eq 0 ]
