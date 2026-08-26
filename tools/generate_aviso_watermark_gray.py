"""Utility script to generate the grayscale aviso watermark image.

Run the script with Python 3 to create ``public/assets/img/icons/aviso-watermark-gray.png``.
The generated watermark is a transparent PNG that renders diagonal "AVISO"
text in a soft gray tone so it can be used as a PDF watermark.

Example::

    python tools/generate_aviso_watermark_gray.py

You can optionally provide a custom output path::

    python tools/generate_aviso_watermark_gray.py /tmp/aviso.png

The script requires Pillow (``pip install pillow``).
"""

from __future__ import annotations

import pathlib
import sys
from typing import Tuple

try:
    from PIL import Image, ImageDraw, ImageFilter, ImageFont
except ImportError as error:  # pragma: no cover - executed only when Pillow is missing.
    raise SystemExit(
        "Pillow is required to generate the aviso watermark. Install it with 'pip install pillow'."
    ) from error


CANVAS_SIZE: int = 1024
BACKGROUND_COLOR: Tuple[int, int, int, int] = (0, 0, 0, 0)
TEXT_COLOR: Tuple[int, int, int, int] = (128, 128, 128, 90)
SUBTEXT_COLOR: Tuple[int, int, int, int] = (128, 128, 128, 70)
TEXT: str = "AVISO"
SUBTEXT: str = "GRUPO GEREZ"
ROTATION_DEGREES: float = 24.0
FONT_NAME_CANDIDATES = (
    "DejaVuSans-Bold.ttf",
    "Arial Bold.ttf",
    "Arial.ttf",
    "Helvetica.ttf",
)


def load_font(size: int) -> ImageFont.FreeTypeFont:
    """Load the first available font from ``FONT_NAME_CANDIDATES``.

    Falls back to Pillow's default bitmap font when no TrueType font is found.
    """

    for name in FONT_NAME_CANDIDATES:
        try:
            return ImageFont.truetype(name, size=size)
        except OSError:
            continue

    return ImageFont.load_default()


def draw_centered_text(
    draw: ImageDraw.ImageDraw,
    text: str,
    font: ImageFont.FreeTypeFont,
    position: Tuple[float, float],
    fill: Tuple[int, int, int, int],
) -> None:
    """Draw text centered on ``position``."""

    bbox = draw.textbbox((0, 0), text, font=font)
    text_width = bbox[2] - bbox[0]
    text_height = bbox[3] - bbox[1]
    draw.text(
        (position[0] - text_width / 2, position[1] - text_height / 2),
        text,
        font=font,
        fill=fill,
    )


def create_watermark_image(size: int) -> Image.Image:
    """Build the RGBA watermark image."""

    base_image = Image.new("RGBA", (size, size), BACKGROUND_COLOR)

    # Draw the main text on a temporary layer so it can be rotated cleanly.
    text_layer = Image.new("RGBA", (size, size), BACKGROUND_COLOR)
    text_draw = ImageDraw.Draw(text_layer)

    main_font = load_font(size=int(size * 0.32))
    draw_centered_text(
        text_draw,
        TEXT,
        main_font,
        (size / 2.0, size / 2.0),
        TEXT_COLOR,
    )

    sub_font = load_font(size=int(size * 0.08))
    draw_centered_text(
        text_draw,
        SUBTEXT,
        sub_font,
        (size / 2.0, size / 2.0 + size * 0.18),
        SUBTEXT_COLOR,
    )

    rotated = text_layer.rotate(ROTATION_DEGREES, resample=Image.BICUBIC, expand=True)

    # Center the rotated layer onto the base image.
    rotated_width, rotated_height = rotated.size
    offset = (
        (size - rotated_width) // 2,
        (size - rotated_height) // 2,
    )

    base_image.alpha_composite(rotated, dest=offset)

    # Apply a gentle blur to soften the edges and produce a watermark feel.
    blurred = base_image.filter(ImageFilter.GaussianBlur(radius=size * 0.01))

    return blurred


def resolve_output_path(argv: list[str]) -> pathlib.Path:
    """Determine where to save the generated watermark image."""

    if len(argv) > 1:
        return pathlib.Path(argv[1]).expanduser().resolve()

    return pathlib.Path("public/assets/img/icons/aviso-watermark-gray.png").resolve()


def main(argv: list[str]) -> int:
    output_path = resolve_output_path(argv)
    output_path.parent.mkdir(parents=True, exist_ok=True)

    image = create_watermark_image(CANVAS_SIZE)
    image.save(output_path, format="PNG")

    print(f"Generated watermark at {output_path}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
