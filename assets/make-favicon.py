#!/usr/bin/env python3
"""Generate the "Musings and delights" favicon: an alto clef.

The clef is U+1D121 MUSICAL SYMBOL C CLEF, rendered from the Noto Music font
rather than hand-drawn, so the result is a correctly formed C clef instead of an
approximation. The font is downloaded once and cached outside the repo.

Requires Pillow:  pip install Pillow

Examples
--------
    python3 assets/make-favicon.py                 # cream variant (the one deployed)
    python3 assets/make-favicon.py --variant amber
    python3 assets/make-favicon.py --all           # render every colourway
    python3 assets/make-favicon.py --size 512 --out /tmp/favicon.png

Output is a square PNG. WordPress derives the 32x32 / 180x180 / 192x192 site-icon
sizes from it, so a single 512x512 file is all that needs uploading.
"""

from __future__ import annotations

import argparse
import os
import shutil
import sys
import urllib.request

try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError:  # pragma: no cover
    sys.exit("Pillow is required. Install it with:  pip install Pillow")


CLEF = "\U0001D121"  # MUSICAL SYMBOL C CLEF (used for the alto clef)
NOTDEF = "\uE000"  # private-use codepoint: renders as .notdef when unsupported

FONT_URL = (
    "https://github.com/google/fonts/raw/main/ofl/notomusic/NotoMusic-Regular.ttf"
)
FONT_CACHE = os.path.join(
    os.environ.get("XDG_CACHE_HOME", os.path.expanduser("~/.cache")),
    "make-favicon",
    "NotoMusic-Regular.ttf",
)

# Colourway name -> (background, clef colour).
VARIANTS = {
    "cream": ("#faf7f2", "#b45309"),  # matches the site palette; this one is deployed
    "amber": ("#b45309", "#faf7f2"),
    "white": ("#ffffff", "#1c1917"),
}
DEFAULT_VARIANT = "cream"
DEFAULT_SIZE = 512
CLEF_HEIGHT_FRACTION = 0.76  # clef ink height as a fraction of the canvas
REFERENCE_SIZE = 200  # font size used to measure the glyph's natural proportions


def hex_to_rgb(value: str) -> tuple[int, int, int]:
    """Convert '#rrggbb' to an (r, g, b) tuple."""
    value = value.lstrip("#")
    return tuple(int(value[i : i + 2], 16) for i in (0, 2, 4))  # type: ignore[return-value]


def output_name(variant: str) -> str:
    """The deployed icon keeps the canonical filename; other colourways are suffixed."""
    if variant == DEFAULT_VARIANT:
        return "favicon-alto-clef.png"
    return f"favicon-alto-clef-{variant}.png"


def ensure_font(path: str) -> str:
    """Return a path to the Noto Music font, downloading it if it is not cached."""
    if os.path.exists(path):
        return path
    os.makedirs(os.path.dirname(path), exist_ok=True)
    print(f"downloading Noto Music font -> {path}")
    request = urllib.request.Request(FONT_URL, headers={"User-Agent": "make-favicon"})
    with urllib.request.urlopen(request, timeout=60) as response, open(path, "wb") as fh:
        shutil.copyfileobj(response, fh)
    return path


def render(
    font_path: str,
    background: tuple[int, int, int],
    foreground: tuple[int, int, int],
    out_path: str,
    size: int = DEFAULT_SIZE,
) -> str:
    """Render the clef centred on a square canvas and write it to out_path."""
    reference = ImageFont.truetype(font_path, REFERENCE_SIZE)
    clef_box = reference.getbbox(CLEF)

    # Guard against a font that lacks the glyph and silently draws .notdef.
    if clef_box == reference.getbbox(NOTDEF):
        raise SystemExit(f"'{font_path}' has no U+1D121 C clef glyph.")

    ink_height = clef_box[3] - clef_box[1]
    font_size = int(REFERENCE_SIZE * (size * CLEF_HEIGHT_FRACTION) / ink_height)
    font = ImageFont.truetype(font_path, font_size)

    box = font.getbbox(CLEF)
    ink_w, ink_h = box[2] - box[0], box[3] - box[1]

    image = Image.new("RGB", (size, size), background)
    ImageDraw.Draw(image).text(
        ((size - ink_w) / 2 - box[0], (size - ink_h) / 2 - box[1]),
        CLEF,
        font=font,
        fill=foreground,
    )
    image.save(out_path)
    print(f"wrote {out_path}  ({size}x{size}, clef ink {ink_w}x{ink_h})")
    return out_path


def main() -> int:
    parser = argparse.ArgumentParser(
        description='Generate the "Musings and delights" alto-clef favicon.'
    )
    parser.add_argument(
        "--variant",
        choices=sorted(VARIANTS),
        default=DEFAULT_VARIANT,
        help=f"colourway to render (default: {DEFAULT_VARIANT})",
    )
    parser.add_argument("--all", action="store_true", help="render every colourway")
    parser.add_argument(
        "--size", type=int, default=DEFAULT_SIZE, help="canvas size in px (default: 512)"
    )
    parser.add_argument("--out", help="output path (single variant only)")
    parser.add_argument("--font", default=FONT_CACHE, help="path to Noto Music .ttf")
    args = parser.parse_args()

    if args.all and args.out:
        parser.error("--out cannot be combined with --all")

    font_path = ensure_font(args.font)
    here = os.path.dirname(os.path.abspath(__file__))

    if args.all:
        for name, (bg, fg) in sorted(VARIANTS.items()):
            render(
                font_path,
                hex_to_rgb(bg),
                hex_to_rgb(fg),
                os.path.join(here, output_name(name)),
                args.size,
            )
        return 0

    bg, fg = VARIANTS[args.variant]
    out_path = args.out or os.path.join(here, output_name(args.variant))
    render(font_path, hex_to_rgb(bg), hex_to_rgb(fg), out_path, args.size)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
