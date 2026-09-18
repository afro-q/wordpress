#!/usr/bin/env python3
"""Generate the Equinilet favicon: an "E sharp" monogram.

The mark pairs a geometric E with a musical sharp sign (two upright stems and
two crossbars slanting up to the right, which is what distinguishes a sharp from
a hash). Everything is drawn from primitives rather than a font so edges land on
whole pixels and the mark stays readable at 32x32.

Colours match the site palette: lime accent (#c8f15a) and ink (#101b22).

Requires Pillow:  pip install Pillow

Examples
--------
    python3 assets/make-equinilet-favicon.py              # lime variant (deployed)
    python3 assets/make-equinilet-favicon.py --variant ink
    python3 assets/make-equinilet-favicon.py --all
    python3 assets/make-equinilet-favicon.py --size 32 --out /tmp/check.png

Output is a square, fully opaque PNG. WordPress derives the 32x32 / 180x180 /
192x192 / 270x270 site-icon sizes from it, so one 512x512 file is enough.
Opaque and full-bleed matters because the same file is used as the
apple-touch-icon, where iOS applies its own corner mask.
"""

from __future__ import annotations

import argparse
import os
import sys

try:
    from PIL import Image, ImageDraw
except ImportError:  # pragma: no cover
    sys.exit("Pillow is required. Install it with:  pip install Pillow")


# Colourway name -> (background, monogram colour).
VARIANTS = {
    "lime": ("#c8f15a", "#101b22"),  # matches the primary CTA; this one is deployed
    "ink": ("#101b22", "#c8f15a"),
    "paper": ("#f4f1e8", "#101b22"),
}
DEFAULT_VARIANT = "lime"
DEFAULT_SIZE = 512

# Letter E geometry, as fractions of the canvas.
E_LEFT = 0.13
E_RIGHT = 0.475
E_TOP = 0.28
E_BOTTOM = 0.80
E_BAR = 0.108
E_MIDDLE_ARM = 0.76  # middle arm length as a fraction of the full arm

# Sharp sign geometry, as fractions of the canvas.
SHARP_STEM_X = (0.625, 0.790)
SHARP_STEM_WIDTH = 0.068
SHARP_STEM_TOP = 0.185
SHARP_STEM_BOTTOM = 0.700
SHARP_BAR_LEFT = 0.570
SHARP_BAR_RIGHT = 0.865
SHARP_BAR_THICKNESS = 0.086
SHARP_BAR_CENTRES = (0.375, 0.545)  # left-hand y centre of each crossbar
SHARP_BAR_RISE = 0.058  # how far each crossbar climbs to the right


def hex_to_rgb(value: str) -> tuple[int, int, int]:
    """Convert '#rrggbb' to an (r, g, b) tuple."""
    value = value.lstrip("#")
    return tuple(int(value[i : i + 2], 16) for i in (0, 2, 4))  # type: ignore[return-value]


def output_name(variant: str) -> str:
    """The deployed icon keeps the canonical filename; other colourways are suffixed."""
    if variant == DEFAULT_VARIANT:
        return "favicon-equinilet-e-sharp.png"
    return f"favicon-equinilet-e-sharp-{variant}.png"


def draw_e(draw: "ImageDraw.ImageDraw", size: int, fill: tuple[int, int, int]) -> None:
    """Draw the letter E: a stem plus top, middle and bottom arms."""
    left = round(E_LEFT * size)
    right = round(E_RIGHT * size)
    top = round(E_TOP * size)
    bottom = round(E_BOTTOM * size)
    bar = max(2, round(E_BAR * size))
    middle_right = left + round((right - left) * E_MIDDLE_ARM)
    middle_top = round((top + bottom - bar) / 2)

    draw.rectangle([left, top, left + bar, bottom], fill=fill)
    draw.rectangle([left, top, right, top + bar], fill=fill)
    draw.rectangle([left, middle_top, middle_right, middle_top + bar], fill=fill)
    draw.rectangle([left, bottom - bar, right, bottom], fill=fill)


def draw_sharp(draw: "ImageDraw.ImageDraw", size: int, fill: tuple[int, int, int]) -> None:
    """Draw the sharp sign: upright stems crossed by two rising bars."""
    stem_width = max(2, round(SHARP_STEM_WIDTH * size))
    stem_top = round(SHARP_STEM_TOP * size)
    stem_bottom = round(SHARP_STEM_BOTTOM * size)
    for stem_x in SHARP_STEM_X:
        x = round(stem_x * size)
        draw.rectangle([x, stem_top, x + stem_width, stem_bottom], fill=fill)

    bar_left = round(SHARP_BAR_LEFT * size)
    bar_right = round(SHARP_BAR_RIGHT * size)
    half = max(1, round(SHARP_BAR_THICKNESS * size / 2))
    rise = round(SHARP_BAR_RISE * size)
    for centre in SHARP_BAR_CENTRES:
        y_left = round(centre * size)
        y_right = y_left - rise
        draw.polygon(
            [
                (bar_left, y_left - half),
                (bar_right, y_right - half),
                (bar_right, y_right + half),
                (bar_left, y_left + half),
            ],
            fill=fill,
        )


def render(
    background: tuple[int, int, int],
    foreground: tuple[int, int, int],
    out_path: str,
    size: int = DEFAULT_SIZE,
) -> str:
    """Draw the E-sharp monogram on a square canvas and write it to out_path."""
    if size < 32:
        raise SystemExit("--size must be at least 32px for the sharp to stay legible.")

    # Supersample so the slanted crossbars get antialiased, then downscale.
    scale = 4
    canvas = size * scale
    image = Image.new("RGB", (canvas, canvas), background)
    draw = ImageDraw.Draw(image)
    draw_e(draw, canvas, foreground)
    draw_sharp(draw, canvas, foreground)

    image = image.resize((size, size), Image.LANCZOS)
    image.save(out_path, optimize=True)
    print(f"wrote {out_path}  ({size}x{size})")
    return out_path


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Generate the Equinilet E-sharp favicon."
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
    args = parser.parse_args()

    if args.all and args.out:
        parser.error("--out cannot be combined with --all")

    here = os.path.dirname(os.path.abspath(__file__))

    if args.all:
        for name, (bg, fg) in sorted(VARIANTS.items()):
            render(
                hex_to_rgb(bg),
                hex_to_rgb(fg),
                os.path.join(here, output_name(name)),
                args.size,
            )
        return 0

    bg, fg = VARIANTS[args.variant]
    out_path = args.out or os.path.join(here, output_name(args.variant))
    render(hex_to_rgb(bg), hex_to_rgb(fg), out_path, args.size)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
