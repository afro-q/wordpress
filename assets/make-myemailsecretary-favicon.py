#!/usr/bin/env python3
"""Generate the My Email Secretary favicon: an envelope mark.

The product is an email client, so the mark is a plain envelope drawn from
primitives rather than a font, supersampled 4x and downsampled, so edges land
cleanly at 32x32. Finer detail (a level meter, a flap highlight) was tried and
dropped: below roughly 48px it turns to mush, and the envelope alone is
recognisable at every size the browser and iOS ask for.

Colours follow the site palette: ink (#2f4f4f) and pale mint (#d5f3ed).

Requires Pillow:  pip install Pillow

Examples
--------
    python3 assets/make-myemailsecretary-favicon.py            # ink variant (deployed)
    python3 assets/make-myemailsecretary-favicon.py --all
    python3 assets/make-myemailsecretary-favicon.py --size 32 --out /tmp/check.png

Output is a square, fully opaque PNG. WordPress derives the 32x32 / 180x180 /
192x192 / 270x270 site-icon sizes from it, so one 512x512 file is enough.
Opaque and full-bleed matters because the same file is used as the
apple-touch-icon, where iOS applies its own corner mask.
"""

from __future__ import annotations

import argparse
import sys

try:
    from PIL import Image, ImageDraw
except ImportError:  # pragma: no cover
    sys.exit("Pillow is required. Install it with:  pip install Pillow")


# Colourway name -> (background, mark colour).
VARIANTS = {
    "ink": ("#2f4f4f", "#d5f3ed"),  # this one is deployed
    "mint": ("#d5f3ed", "#2f4f4f"),
    "paper": ("#f7fbfa", "#2f4f4f"),
}
DEFAULT_VARIANT = "ink"
DEFAULT_SIZE = 512
SUPERSAMPLE = 4

# Envelope geometry, as fractions of the canvas.
ENV_LEFT = 0.155
ENV_RIGHT = 0.845
ENV_TOP = 0.305
ENV_BOTTOM = 0.700
ENV_STROKE = 0.062
ENV_CORNER = 0.058
# Flap apex sits below the middle so the fold reads like a real envelope.
ENV_FLAP_APEX_Y = 0.552


def rounded_square(draw: ImageDraw.ImageDraw, size: int, fill: str, radius_frac: float = 0.0) -> None:
    """Full-bleed background; full-bleed matters for the apple-touch-icon mask."""
    if radius_frac:
        draw.rounded_rectangle((0, 0, size - 1, size - 1), radius=int(size * radius_frac), fill=fill)
    else:
        draw.rectangle((0, 0, size, size), fill=fill)


def render(variant: str, size: int = DEFAULT_SIZE) -> Image.Image:
    background, mark = VARIANTS[variant]
    # Draw large, then downsample, so thin strokes stay smooth.
    big = size * SUPERSAMPLE
    image = Image.new("RGB", (big, big), background)
    draw = ImageDraw.Draw(image)
    rounded_square(draw, big, background)

    stroke = int(round(big * ENV_STROKE))
    left = int(round(big * ENV_LEFT))
    right = int(round(big * ENV_RIGHT))
    top = int(round(big * ENV_TOP))
    bottom = int(round(big * ENV_BOTTOM))

    # Envelope body outline, inset by half the stroke so it stays inside frame.
    half = stroke // 2
    draw.rounded_rectangle(
        (left + half, top + half, right - half, bottom - half),
        radius=int(round(big * ENV_CORNER)),
        outline=mark,
        width=stroke,
    )

    # Flap: two straight lines meeting at the apex.
    apex = (int(round(big * 0.5)), int(round(big * ENV_FLAP_APEX_Y)))
    draw.line([(left + half, top + half), apex], fill=mark, width=stroke)
    draw.line([apex, (right - half, top + half)], fill=mark, width=stroke)

    return image.resize((size, size), Image.LANCZOS)


def main() -> None:
    parser = argparse.ArgumentParser(description="Render the My Email Secretary favicon.")
    parser.add_argument(
        "--variant",
        choices=sorted(VARIANTS),
        default=DEFAULT_VARIANT,
        help=f"colourway to render (default: {DEFAULT_VARIANT})",
    )
    parser.add_argument("--size", type=int, default=DEFAULT_SIZE, help=f"edge length in pixels (default: {DEFAULT_SIZE})")
    parser.add_argument("--out", help="output path; defaults to assets/favicon-myemailsecretary-<variant>.png")
    parser.add_argument("--all", action="store_true", help="render every colourway")
    args = parser.parse_args()

    if args.all:
        targets = [(name, None) for name in sorted(VARIANTS)]
    else:
        targets = [(args.variant, args.out)]

    for name, out in targets:
        path = out or f"assets/favicon-myemailsecretary-{name}.png"
        render(name, args.size).save(path, "PNG", optimize=True)
        print(f"wrote {path} ({args.size}x{args.size}, {name})")


if __name__ == "__main__":
    main()
