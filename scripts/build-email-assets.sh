#!/usr/bin/env bash
#
# build-email-assets.sh - build email-safe derivatives of the marketing artwork in R2.
#
# A mail body renders at a 600px content column, so every derivative targets 1200px wide (2x)
# and stays under 150 KB. The originals are 700 KB - 1.3 MB and are unusable in an email.
#
# usage:
#   scripts/build-email-assets.sh             build into dist/email-assets, print upload commands
#   scripts/build-email-assets.sh --upload    also PUT the keys that do not exist yet
#
# Sources come from https://cdn.earth-app.com/marketing/ because the bucket is the source of
# truth; data/marketing/ is never read. Derivatives go to a separate marketing/email/ prefix and
# an existing key is skipped rather than replaced, so no original or shipped asset can be lost.
# The prefix also gives the mail templates a stable key even when an original is re-exported.
#
# requires: curl and sips (both macOS built-ins); bunx wrangler only for --upload.

set -euo pipefail

CDN='https://cdn.earth-app.com'
MAX_WIDTH=1200
JPEG_QUALITY=80
BUDGET_BYTES=153600

# the letterbox banner; a 3:1 band cannot hold the whole moon and the far river bank at once,
# so start it at 26.7% of the height, which keeps the moon disc, the hill line and the water
BANNER_ASSET='circle-garden_dusk_after.png'
BANNER_OFFSET_PERMILLE=267
BANNER_ASPECT=3

QR_ASSET='qr_black.jpg'
QR_SIDE=600

POST_ASSETS=(
	launch_post_landscape.png
	launch_post_square.png
	offer_post_landscape.png
)

MOTD_ASSETS=(
	motd_air_smell.png
	motd_cloud_loves_you.png
	motd_deep_breath.png
	motd_doing_great.png
	motd_earth_app_has_you.png
	motd_friends_warning.png
	motd_last_outside.png
	motd_life_not_bad.png
	motd_long_week.png
	motd_move_forward.png
	motd_no_wrong_answer.png
	motd_ok_eventually.png
	motd_smiling.png
	motd_will_be_fine.png
)

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$REPO_ROOT/dist/email-assets"
CACHE="${TMPDIR:-/tmp}/mantle2-email-assets"

SRC_PATH=''
BUILT=()
OVERSIZED=0
TOTAL_BEFORE=0
TOTAL_AFTER=0

# #region helpers

die() {
	printf 'error: %s\n' "$*" >&2
	exit 1
}

require_tools() {
	command -v curl >/dev/null 2>&1 || die 'curl is required but was not found on PATH'
	command -v sips >/dev/null 2>&1 ||
		die 'sips is required and ships with macOS; this script cannot run on this platform'

	if [ "$1" = '1' ]; then
		command -v bunx >/dev/null 2>&1 || die '--upload needs bunx (install bun) to run wrangler'
		bunx wrangler --version >/dev/null 2>&1 ||
			die '--upload needs wrangler; run "bunx wrangler login" or set CLOUDFLARE_API_TOKEN'
	fi
}

bytes() {
	stat -f %z "$1"
}

# reads one sips property; sips echoes the filename first, so skip line 1
px() {
	sips -g "$1" "$2" | awk 'NR > 1 { print $2 }'
}

content_type() {
	case "$1" in
	*.png) printf 'image/png' ;;
	*.jpg | *.jpeg) printf 'image/jpeg' ;;
	*) die "unknown image type: $1" ;;
	esac
}

# caches downloads so a re-run is offline and idempotent
fetch() {
	local name="$1"
	SRC_PATH="$CACHE/$name"

	if [ ! -s "$SRC_PATH" ]; then
		curl -fsSL --retry 3 -o "$SRC_PATH.part" "$CDN/marketing/$name" ||
			die "download failed: $CDN/marketing/$name"
		mv "$SRC_PATH.part" "$SRC_PATH"
	fi
}

record() {
	local name="$1" before="$2" after="$3" note="${4:-}" pct=0

	[ "$before" -gt 0 ] && pct=$((100 - (after * 100 / before)))
	printf '%-32s %9s %9s %6s%%  %s\n' "$name" "$before" "$after" "$pct" "$note"

	TOTAL_BEFORE=$((TOTAL_BEFORE + before))
	TOTAL_AFTER=$((TOTAL_AFTER + after))
	BUILT+=("$name")

	if [ "$after" -gt "$BUDGET_BYTES" ]; then
		OVERSIZED=$((OVERSIZED + 1))
		printf '  WARN %s is %s bytes, over the %s byte budget\n' "$name" "$after" "$BUDGET_BYTES" >&2
	fi
}

# #endregion

# #region builders

# 1200x400 letterbox band cropped out of the full dusk scene
build_banner() {
	local out work width height band offset
	fetch "$BANNER_ASSET"

	out="$DIST/${BANNER_ASSET%.png}.jpg"
	work="$DIST/.work-banner.png"
	cp "$SRC_PATH" "$work"

	width="$(px pixelWidth "$work")"
	height="$(px pixelHeight "$work")"
	band=$((width / BANNER_ASPECT))
	offset=$((height * BANNER_OFFSET_PERMILLE / 1000))
	[ $((offset + band)) -gt "$height" ] && offset=$((height - band))

	sips -c "$band" "$width" --cropOffset "$offset" 0 "$work" >/dev/null
	sips --resampleHeightWidth $((MAX_WIDTH / BANNER_ASPECT)) "$MAX_WIDTH" "$work" >/dev/null
	sips -s format jpeg -s formatOptions "$JPEG_QUALITY" "$work" --out "$out" >/dev/null
	rm -f "$work"

	record "$(basename "$out")" "$(bytes "$SRC_PATH")" "$(bytes "$out")" "3:1 band at y=$offset"
}

# capped at 1200 wide, aspect preserved, re-encoded as jpeg
build_post() {
	local name="$1" out work width
	fetch "$name"

	out="$DIST/${name%.png}.jpg"
	work="$DIST/.work-post.png"
	cp "$SRC_PATH" "$work"

	width="$(px pixelWidth "$work")"
	if [ "$width" -gt "$MAX_WIDTH" ]; then
		sips --resampleWidth "$MAX_WIDTH" "$work" >/dev/null
	fi

	sips -s format jpeg -s formatOptions "$JPEG_QUALITY" "$work" --out "$out" >/dev/null
	rm -f "$work"

	record "$(basename "$out")" "$(bytes "$SRC_PATH")" "$(bytes "$out")" "${width}w -> $(px pixelWidth "$out")w"
}

# lossless png so the module edges stay hard; jpeg rings around them
build_qr() {
	local out work
	fetch "$QR_ASSET"

	out="$DIST/${QR_ASSET%.jpg}.png"
	work="$DIST/.work-qr.jpg"
	cp "$SRC_PATH" "$work"

	sips --resampleHeightWidth "$QR_SIDE" "$QR_SIDE" "$work" >/dev/null
	sips -s format png "$work" --out "$out" >/dev/null
	rm -f "$work"

	record "$(basename "$out")" "$(bytes "$SRC_PATH")" "$(bytes "$out")" "${QR_SIDE}x${QR_SIDE} lossless"
}

# normalize pass; these strips are already tiny so the source often wins
build_motd() {
	local name="$1" out width note=''
	fetch "$name"

	out="$DIST/$name"
	cp "$SRC_PATH" "$out"

	width="$(px pixelWidth "$out")"
	if [ "$width" -gt "$MAX_WIDTH" ]; then
		sips --resampleWidth "$MAX_WIDTH" "$out" >/dev/null
	fi

	# sips re-encodes to truecolor, which beats the optimized original surprisingly rarely
	if [ "$(bytes "$out")" -ge "$(bytes "$SRC_PATH")" ]; then
		cp "$SRC_PATH" "$out"
		note='kept source (re-encode was larger)'
	fi

	record "$name" "$(bytes "$SRC_PATH")" "$(bytes "$out")" "$note"
}

# #endregion

# #region upload

# a 200 from the CDN means the key is taken; the script must never PUT over it
key_exists() {
	[ "$(curl -sS -o /dev/null -w '%{http_code}' -I "$CDN/marketing/email/$1")" = '200' ]
}

print_upload_commands() {
	local name

	printf '\nupload commands (run with --upload, or paste these):\n'
	for name in "${BUILT[@]}"; do
		printf 'bunx wrangler r2 object put earth-app/marketing/email/%s --file=%s --content-type=%s --cache-control="public, max-age=31536000, immutable" --remote\n' \
			"$name" "$DIST/$name" "$(content_type "$name")"
	done
}

run_uploads() {
	local name

	printf '\nuploading new keys only:\n'
	for name in "${BUILT[@]}"; do
		if key_exists "$name"; then
			printf 'skip   marketing/email/%s exists; refusing to overwrite\n' "$name"
			continue
		fi

		printf 'put    marketing/email/%s\n' "$name"
		bunx wrangler r2 object put "earth-app/marketing/email/$name" \
			--file="$DIST/$name" \
			--content-type="$(content_type "$name")" \
			--cache-control='public, max-age=31536000, immutable' \
			--remote
	done
}

# #endregion

main() {
	local upload=0 name

	case "${1:-}" in
	'') ;;
	--upload) upload=1 ;;
	-h | --help)
		grep '^#' "$0"
		exit 0
		;;
	*) die "unknown argument: $1 (expected --upload)" ;;
	esac

	require_tools "$upload"
	mkdir -p "$CACHE" "$DIST"

	printf '%-32s %9s %9s %7s  %s\n' asset before after saved note
	build_banner
	for name in "${POST_ASSETS[@]}"; do
		build_post "$name"
	done
	build_qr
	for name in "${MOTD_ASSETS[@]}"; do
		build_motd "$name"
	done

	printf '%-32s %9s %9s %6s%%\n' "TOTAL (${#BUILT[@]} assets)" "$TOTAL_BEFORE" "$TOTAL_AFTER" \
		"$((100 - (TOTAL_AFTER * 100 / TOTAL_BEFORE)))"
	printf 'output: %s\n' "$DIST"

	if [ "$upload" = '1' ]; then
		run_uploads
	else
		print_upload_commands
	fi

	[ "$OVERSIZED" -eq 0 ] || die "$OVERSIZED derivative(s) exceed the $BUDGET_BYTES byte budget"
}

main "$@"
