#!/usr/bin/env bash
# Deterministic synthetic corpus for the OCR evaluation (plan 13-01). Dev machine only (ImageMagick 6.9, python3 for JSON).
#
#   scripts/ocr-eval/generate.sh OUTDIR
#
# Renders tests/Fixtures/ocr-eval/<doc>.txt line by line (never `@file`: the default ImageMagick policy blocks it), makes the
# seeded degraded copies and the PDF variants, the 4000 px resolution probe and MANIFEST.json (synthetic: true, sha256 per
# file). Refuses an existing non-empty OUTDIR. Exits non-zero when a font lacks a glyph the text needs.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIX="$ROOT/tests/Fixtures/ocr-eval"
OUT="${1:?usage: generate.sh OUTDIR}"

if [ -e "$OUT" ] && [ -n "$(ls -A "$OUT" 2>/dev/null)" ]; then
  echo "refusing: $OUT exists and is not empty" >&2
  exit 1
fi
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

command -v convert >/dev/null || { echo "ImageMagick convert is required" >&2; exit 1; }
command -v python3 >/dev/null || { echo "python3 is required" >&2; exit 1; }
command -v fc-list >/dev/null || { echo "fontconfig (fc-list) is required" >&2; exit 1; }

declare -A FONT=(
  [LiberationSerif]=/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf
  [LiberationSans]=/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf
  [DejaVuSans]=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf
  [URWBookman]=/usr/share/fonts/opentype/urw-base35/URWBookman-Light.otf
  [NimbusRoman]=/usr/share/fonts/opentype/urw-base35/NimbusRoman-Regular.otf
  [Z003]=/usr/share/fonts/opentype/urw-base35/Z003-MediumItalic.otf
)
for f in "${!FONT[@]}"; do [ -f "${FONT[$f]}" ] || { echo "missing font file ${FONT[$f]}" >&2; exit 1; }; done

W=2480
H=3508
MARGIN=220
SEED=13
JPEG_Q=80

# The text of one document must be drawable: no line may start with @ or hold a backslash (annotate escapes), and every
# non-ASCII code point must exist in each font that draws it.
hex_codepoints() { python3 -I -c 'import sys; print(" ".join(sorted({"%04x" % ord(c) for c in open(sys.argv[1], encoding="utf-8").read() if ord(c) > 127})))' "$1"; }

check_glyphs() { # textfile fontname...
  local txt="$1"; shift
  local cps; cps="$(hex_codepoints "$txt")"
  [ -z "$cps" ] && return 0
  local name
  for name in "$@"; do
    local missing=""
    local cp
    for cp in $cps; do
      if ! fc-list ":charset=$cp" file | grep -F "${FONT[$name]}" >/dev/null; then missing="$missing U+${cp^^}"; fi
    done
    if [ -n "$missing" ]; then
      echo "font $name lacks glyphs for $txt:$missing" >&2
      exit 1
    fi
  done
}

if grep -nE '^@|\\' "$FIX"/*.txt >/dev/null; then echo "a ground-truth line starts with @ or holds a backslash" >&2; exit 1; fi

# JSON side: one tab separated line per document.
python3 -I - "$FIX/corpus.json" >"$WORK/docs.tsv" <<'PY'
import json, sys
c = json.load(open(sys.argv[1], encoding="utf-8"))
for d in c["docs"]:
    print("\t".join([d["id"], d["font"], str(d["pt"]), str(d.get("split", 0)),
                     ",".join(map(str, d["hand"])) or "-", ",".join(map(str, d["stamp"])) or "-", ",".join(map(str, d["small"])) or "-"]))
PY

in_list() { case ",$2," in *",$1,"*) return 0;; *) return 1;; esac; }

# Render one ground-truth page to $8 (PNG), page 2480x3508.
render_doc() { # id font pt split hand stamp small outfile
  local id="$1" font="$2" pt="$3" split="$4" hand="$5" stamp="$6" small="$7"
  local txt="$FIX/$id.txt"
  local px=$(( pt * 300 / 72 ))
  local lh=$(( px * 135 / 100 ))
  local args=(-seed "$SEED" -size "${W}x${H}" xc:white -fill black -strokewidth 0)
  local lines=()
  mapfile -t lines <"$txt"
  local n=${#lines[@]}
  local i y x line
  y=$MARGIN
  x=$MARGIN
  for (( i = 1; i <= n; i++ )); do
    line="${lines[$((i-1))]}"
    if [ "$split" -gt 0 ] && [ "$i" -eq $(( split + 1 )) ]; then x=$(( MARGIN + 1100 )); y=$MARGIN; fi
    if [ -z "$line" ]; then y=$(( y + lh )); continue; fi
    line="${line//%/%%}"
    if in_list "$i" "$hand"; then
      args+=(-font "${FONT[Z003]}" -pointsize $(( px * 125 / 100 )) -fill '#10206a' -annotate "+$x+$(( y + px ))" "$line" -fill black)
      y=$(( y + lh * 115 / 100 ))
    elif in_list "$i" "$stamp"; then
      args+=(-font "${FONT[$font]}" -pointsize "$px" -fill '#a01818' -annotate "+$(( x + 120 ))+$(( y + px ))" "$line" -fill black)
      y=$(( y + lh ))
    elif in_list "$i" "$small"; then
      args+=(-font "${FONT[$font]}" -pointsize $(( px * 62 / 100 )) -annotate "+$x+$(( y + px ))" "$line")
      y=$(( y + lh * 75 / 100 ))
    else
      args+=(-font "${FONT[$font]}" -pointsize "$px" -annotate "+$x+$(( y + px ))" "$line")
      y=$(( y + lh ))
    fi
  done
  if [ -n "$stamp" ]; then
    # A round stamp ring around the stamp lines (the first line is at about y of the stamp block).
    local first="${stamp%%,*}"
    local ys=$MARGIN j yy=$MARGIN
    for (( j = 1; j < first; j++ )); do
      if [ -z "${lines[$((j-1))]}" ]; then yy=$(( yy + lh )); elif in_list "$j" "$hand"; then yy=$(( yy + lh * 115 / 100 )); elif in_list "$j" "$small"; then yy=$(( yy + lh * 75 / 100 )); else yy=$(( yy + lh )); fi
    done
    args+=(-fill none -stroke '#a01818' -strokewidth 8 -draw "ellipse $(( MARGIN + 760 )),$(( yy + lh )) 800,$(( lh + 50 )) 0,360")
  fi
  convert "${args[@]}" -depth 8 "$8"
}

# One degraded copy of the clean page: condition, input PNG, output file (jpg unless noted).
degrade() { # condition in out
  local cond="$1" in="$2" out="$3" q="$JPEG_Q"
  local common=(-seed "$SEED")
  case "$cond" in
    clean)  convert "$in" -quality "$q" "$out" ;;
    rot3)   convert "$in" -background white -rotate 3 +repage -quality "$q" "$out" ;;
    rot8)   convert "$in" -background white -rotate -8 +repage -quality "$q" "$out" ;;
    rot90)  convert "$in" -rotate 90 +repage -quality "$q" "$out" ;;
    upside) convert "$in" -rotate 180 +repage -quality "$q" "$out" ;;
    persp)  convert "$in" -background white -virtual-pixel white -distort Perspective \
              "0,0 70,45  $W,0 $((W-90)),12  0,$H 0,$H  $W,$H $((W-20)),$((H-34))" -quality "$q" "$out" ;;
    blur)   convert "$in" -blur 0x2 -quality "$q" "$out" ;;
    lowc)   convert "$in" +level 30%,70% -quality "$q" "$out" ;;
    noise)  convert "${common[@]}" "$in" -attenuate 0.6 +noise Gaussian -quality "$q" "$out" ;;
    jpeg)   convert "$in" -quality 35 "$out" ;;
    phone)  convert "${common[@]}" "$in" \
              \( -size "${W}x${H}" radial-gradient:'#ffffff-#9a9a9a' -gravity center -crop "${W}x${H}+0+0" +repage \) \
              -compose multiply -composite -gravity none -background '#d8d4cc' -virtual-pixel background \
              -distort Perspective "0,0 40,60  $W,0 $((W-70)),20  0,$H 25,$((H-20))  $W,$H $((W-40)),$((H-60))" \
              -background '#d8d4cc' -rotate 1.5 +repage -blur 0x0.9 -attenuate 0.25 +noise Gaussian -quality 70 "$out" ;;
    *) echo "unknown condition $cond" >&2; exit 1 ;;
  esac
}

ITEMS="$WORK/items.tsv"
: >"$ITEMS"
add_item() { # id doc condition kind file
  printf '%s\t%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "$4" "$5" >>"$ITEMS"
}

mapfile -t CONDS < <(python3 -I -c 'import json,sys; print("\n".join(json.load(open(sys.argv[1]))["conditions"]))' "$FIX/corpus.json")

while IFS=$'\t' read -r id font pt split hand stamp small; do
  [ "$hand" = "-" ] && hand=""
  [ "$stamp" = "-" ] && stamp=""
  [ "$small" = "-" ] && small=""
  check_glyphs "$FIX/$id.txt" "$font"
  if [ -n "$hand" ]; then check_glyphs "$FIX/$id.txt" Z003; fi
  render_doc "$id" "$font" "$pt" "$split" "$hand" "$stamp" "$small" "$WORK/$id.png"
  for c in "${CONDS[@]}"; do
    degrade "$c" "$WORK/$id.png" "$OUT/$id.$c.jpg"
    add_item "${id%%-*}-$c" "$id" "$c" image "$id.$c.jpg"
  done
  # PDF variant of the clean page, A4 at 300 dpi.
  convert "$WORK/$id.png" -density 300 -units PixelsPerInch -quality 90 "$OUT/$id.pdf"
  add_item "${id%%-*}-pdf" "$id" pdf pdf "$id.pdf"
done <"$WORK/docs.tsv"

# Resolution probe: small text on a 4000 x 4000 canvas, legible only above 2048 px.
PROBE_SIZE="$(python3 -I -c 'import json,sys; print(json.load(open(sys.argv[1]))["probe"]["size"])' "$FIX/corpus.json")"
PROBE_PX="$(python3 -I -c 'import json,sys; print(json.load(open(sys.argv[1]))["probe"]["px"])' "$FIX/corpus.json")"
check_glyphs "$FIX/probe.txt" DejaVuSans
pargs=(-size "${PROBE_SIZE}x${PROBE_SIZE}" xc:white -fill black -font "${FONT[DejaVuSans]}" -pointsize "$PROBE_PX")
mapfile -t plines <"$FIX/probe.txt"
py=200
for line in "${plines[@]}"; do
  [ -z "$line" ] && continue
  pargs+=(-annotate "+200+$py" "${line//%/%%}")
  py=$(( py + 300 ))
done
convert "${pargs[@]}" -depth 8 "$OUT/probe.4000.png"
convert "$OUT/probe.4000.png" -quality 90 "$OUT/probe.4000.jpg"
add_item probe-png probe clean probe probe.4000.png
add_item probe-jpg probe clean probe probe.4000.jpg

GEN_HASH="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"
python3 -I - "$OUT" "$ITEMS" "$GEN_HASH" <<'PY'
import hashlib, json, os, sys
out, items, gen = sys.argv[1:4]
rows = []
for line in open(items, encoding="utf-8"):
    i, doc, cond, kind, f = line.rstrip("\n").split("\t")
    h = hashlib.sha256(open(os.path.join(out, f), "rb").read()).hexdigest()
    rows.append({"id": i, "doc": doc, "condition": cond, "kind": kind, "file": f, "sha256": h})
json.dump({"synthetic": True, "generator": "scripts/ocr-eval/generate.sh@" + gen, "items": rows},
          open(os.path.join(out, "MANIFEST.json"), "w", encoding="utf-8"), indent=2)
PY
echo "generated $(wc -l <"$ITEMS") items, $(du -sk "$OUT" | cut -f1) KB in $OUT"
