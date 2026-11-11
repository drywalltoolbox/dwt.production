#!/usr/bin/env python3
"""Generate transparent storefront category thumbnails from canonical media.

Category media owns only the isolated product/tool pixels. The storefront owns
its surface treatment and responsive fitment. Outputs use a consistent square,
transparent canvas so every category card receives predictable media geometry
without cropping or distorting the tool's natural aspect ratio.
"""

from pathlib import Path

from PIL import Image, ImageChops, ImageDraw, ImageStat


ROOT = Path(__file__).resolve().parents[2]
SOURCE_ROOT = ROOT / "products" / "launch" / "media" / "media"
OUTPUT_ROOT = ROOT / "products" / "launch" / "media" / "categories" / "thumbnails"

# 512px supplies more than 4x source pixels for the desktop mega menu's 92px
# media bay while remaining small enough for navigation-critical downloads.
OUTPUT_SIZE = 512
TRANSPARENT_PADDING_RATIO = 0.075
WEBP_QUALITY = 86

# Background removal is deliberately conservative. Only near-background pixels
# connected to an image edge are removed, which protects light/chrome detail
# enclosed by the tool silhouette. A soft alpha transition reduces white matte
# fringing on antialiased studio edges.
HARD_BACKGROUND_DISTANCE = 10
SOFT_BACKGROUND_DISTANCE = 58
ALPHA_BOUNDS_THRESHOLD = 16

# Category slugs mirror docs/reference/ui/hero exactly. The selected source is
# the strongest representative product composition in the canonical media set.
SELECTIONS = {
    "angle-heads": "columbia_tools_ah_all_sizes_02.webp",
    "automatic-tapers": "columbia_tools_staper_03.webp",
    "automatic-taping-tool-cases": "columbia_tools_tcs_02.webp",
    "automatic-taping-tool-sets": "columbia_tools_pts_01.webp",
    "automatic-taping-tools": "columbia_tools_taper_02.webp",
    "box-fillers": "tapetech_90t_01.webp",
    "compound-applicators": "columbia_tools_ica41_01.webp",
    "compound-tubes": "columbia_tools_pcmt42_01.webp",
    "corner-boxes": "platinum_pt_ca8_01.webp",
    "corner-flushers": "columbia_tools_3sf_01.webp",
    "corner-rollers": "platinum_pt_cr_04.webp",
    "extendable-handles": "columbia_tools_pc1_handles_01.webp",
    "fixed-handles": "columbia_tools_pc1h_01.webp",
    "handles": "columbia_tools_pmhs_02.webp",
    "flat-boxes": "columbia_tools_12ffb_01.webp",
    "goosenecks": "tapetech_85t_01.webp",
    "loading-pumps": "columbia_tools_hmp_01.webp",
    "nail-spotters": "level5_4_754_02.webp",
    "semi-automatic-accessories": "tapetech_fhtt_02.webp",
    "semi-automatic-tapers": "columbia_tools_sat_01.webp",
    "semi-automatic-taping-tool-sets": "columbia_tools_tcs_01.webp",
    "semi-automatic-taping-tools": "columbia_tools_sat_01.webp",
    "semi-automatic-tool-cases": "columbia_tools_sacs_01.webp",
}


def _background_color(rgb: Image.Image) -> tuple[int, int, int]:
    """Estimate the studio background from all four image corners."""
    corners = Image.new("RGB", (4, 1))
    corners.putdata([
        rgb.getpixel((0, 0)),
        rgb.getpixel((rgb.width - 1, 0)),
        rgb.getpixel((0, rgb.height - 1)),
        rgb.getpixel((rgb.width - 1, rgb.height - 1)),
    ])
    return tuple(round(value) for value in ImageStat.Stat(corners).median)


def _maximum_channel_difference(rgb: Image.Image, background: tuple[int, int, int]) -> Image.Image:
    """Return an L mask containing max per-channel distance from background."""
    difference = ImageChops.difference(rgb, Image.new("RGB", rgb.size, background))
    red, green, blue = difference.split()
    return ImageChops.lighter(ImageChops.lighter(red, green), blue)


def _edge_connected_background(distance: Image.Image) -> Image.Image:
    """Mark near-background pixels that are connected to the image perimeter."""
    candidate = distance.point(
        lambda value: 0 if value <= SOFT_BACKGROUND_DISTANCE else 255,
        mode="L",
    )

    seeds = (
        (0, 0),
        (candidate.width - 1, 0),
        (0, candidate.height - 1),
        (candidate.width - 1, candidate.height - 1),
    )
    for seed in seeds:
        if candidate.getpixel(seed) == 0:
            ImageDraw.floodfill(candidate, seed, 128, thresh=0)

    return candidate


def isolate_foreground(image: Image.Image) -> Image.Image:
    """Remove only edge-connected studio background and retain natural alpha."""
    rgba = image.convert("RGBA")
    rgb = rgba.convert("RGB")
    distance = _maximum_channel_difference(rgb, _background_color(rgb))
    connected = _edge_connected_background(distance)

    source_alpha = rgba.getchannel("A")
    alpha = Image.new("L", rgba.size, 255)
    alpha_pixels = alpha.load()
    source_alpha_pixels = source_alpha.load()
    distance_pixels = distance.load()
    connected_pixels = connected.load()

    transition = SOFT_BACKGROUND_DISTANCE - HARD_BACKGROUND_DISTANCE
    for y in range(rgba.height):
        for x in range(rgba.width):
            source_value = source_alpha_pixels[x, y]
            if source_value == 0:
                alpha_pixels[x, y] = 0
                continue

            if connected_pixels[x, y] != 128:
                alpha_pixels[x, y] = source_value
                continue

            delta = distance_pixels[x, y]
            if delta <= HARD_BACKGROUND_DISTANCE:
                matte_alpha = 0
            else:
                matte_alpha = round(255 * (delta - HARD_BACKGROUND_DISTANCE) / transition)
                matte_alpha = max(0, min(255, matte_alpha))

            alpha_pixels[x, y] = min(source_value, matte_alpha)

    rgba.putalpha(alpha)
    return rgba


def crop_to_visible_content(image: Image.Image) -> Image.Image:
    """Crop to meaningful alpha and center it on a square transparent canvas."""
    alpha = image.getchannel("A")
    bounds_mask = alpha.point(
        lambda value: 255 if value >= ALPHA_BOUNDS_THRESHOLD else 0,
        mode="L",
    )
    bounds = bounds_mask.getbbox()
    if not bounds:
        raise RuntimeError("Background isolation produced no visible foreground")

    cropped = image.crop(bounds)

    padding = round(OUTPUT_SIZE * TRANSPARENT_PADDING_RATIO)
    content_limit = OUTPUT_SIZE - (2 * padding)
    scale = min(content_limit / cropped.width, content_limit / cropped.height)
    rendered = cropped.resize(
        (
            max(1, round(cropped.width * scale)),
            max(1, round(cropped.height * scale)),
        ),
        Image.Resampling.LANCZOS,
        reducing_gap=3.0 if scale < 0.5 else None,
    )

    canvas = Image.new("RGBA", (OUTPUT_SIZE, OUTPUT_SIZE), (0, 0, 0, 0))
    offset = (
        (OUTPUT_SIZE - rendered.width) // 2,
        (OUTPUT_SIZE - rendered.height) // 2,
    )
    canvas.alpha_composite(rendered, offset)
    return canvas


def verify_output(image: Image.Image, slug: str) -> None:
    """Fail if an output violates the transparent category-media contract."""
    if image.mode != "RGBA":
        raise RuntimeError(f"{slug}: expected RGBA output, got {image.mode}")

    alpha_min, alpha_max = image.getchannel("A").getextrema()
    if alpha_min != 0 or alpha_max == 0:
        raise RuntimeError(
            f"{slug}: output must contain transparent padding and visible foreground; "
            f"alpha_range=({alpha_min}, {alpha_max})"
        )

    if image.size != (OUTPUT_SIZE, OUTPUT_SIZE):
        raise RuntimeError(f"{slug}: expected {OUTPUT_SIZE}px square output, got {image.size}")


def generate(slug: str, source_name: str) -> tuple[int, int]:
    source = SOURCE_ROOT / source_name
    if not source.is_file():
        raise FileNotFoundError(f"Missing canonical source for {slug}: {source}")

    with Image.open(source) as original:
        isolated = isolate_foreground(original)
        thumbnail = crop_to_visible_content(isolated)
        verify_output(thumbnail, slug)
        thumbnail.save(
            OUTPUT_ROOT / f"{slug}.webp",
            "WEBP",
            quality=WEBP_QUALITY,
            method=6,
            exact=True,
        )
        return thumbnail.size


def main() -> None:
    mapped_categories = {
        path.name
        for path in (ROOT / "docs" / "reference" / "ui" / "hero").iterdir()
        if path.is_dir()
    }
    if mapped_categories != set(SELECTIONS):
        missing = sorted(mapped_categories - set(SELECTIONS))
        stale = sorted(set(SELECTIONS) - mapped_categories)
        raise RuntimeError(f"Category mapping mismatch; missing={missing}, stale={stale}")

    OUTPUT_ROOT.mkdir(parents=True, exist_ok=True)
    generated_sizes = {}
    for slug, source_name in sorted(SELECTIONS.items()):
        generated_sizes[slug] = generate(slug, source_name)

    dimensions = ", ".join(
        f"{slug}={width}x{height}"
        for slug, (width, height) in sorted(generated_sizes.items())
    )
    print(
        f"generated={len(SELECTIONS)} transparent=true size={OUTPUT_SIZE}x{OUTPUT_SIZE} "
        f"output={OUTPUT_ROOT} dimensions=[{dimensions}]"
    )


if __name__ == "__main__":
    main()
