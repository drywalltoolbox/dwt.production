"""Render the Drywall Toolbox storefront launch promo from live UI captures."""

from __future__ import annotations

import math
from pathlib import Path
import subprocess

import imageio_ffmpeg
import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont


ROOT = Path(__file__).resolve().parent
OUT = ROOT / "storefront-launch-film.mp4"
POSTER = ROOT / "storefront-launch-film-poster.png"
SRT = ROOT / "storefront-launch-film.srt"
W, H, FPS, DURATION = 1280, 720, 24, 18
TOTAL = FPS * DURATION

FONT_DIR = Path("C:/Windows/Fonts")
FONT_REG = FONT_DIR / "segoeui.ttf"
FONT_MED = FONT_DIR / "seguisb.ttf"
FONT_BOLD = FONT_DIR / "segoeuib.ttf"

COLORS = {
    "ink": (12, 17, 26), "paper": (243, 246, 251), "muted": (154, 169, 188),
    "cyan": (49, 103, 255), "blue": (82, 132, 255), "lime": (204, 242, 154),
    "line": (54, 69, 87), "white": (249, 251, 255),
}


def font(size: int, weight: str = "regular") -> ImageFont.FreeTypeFont:
    path = FONT_BOLD if weight == "bold" else FONT_MED if weight == "medium" else FONT_REG
    return ImageFont.truetype(str(path), size)


def load_screens() -> dict[str, Image.Image]:
    files = {
        "home": "storefront-home-capture.png",
        "listing": "storefront-products-overview-capture.png",
        "product": "storefront-product-quickview-capture.png",
    }
    return {key: Image.open(ROOT / name).convert("RGB") for key, name in files.items()}


def ease(value: float) -> float:
    value = max(0.0, min(1.0, value))
    return value * value * (3 - 2 * value)


def make_background() -> Image.Image:
    y, x = np.mgrid[0:H, 0:W]
    t = np.clip(y / H, 0, 1)[..., None]
    top = np.array([10, 16, 25], dtype=np.float32)
    bottom = np.array([18, 29, 43], dtype=np.float32)
    rgb = (top * (1 - t) + bottom * t).astype(np.uint8)
    im = Image.fromarray(rgb)
    glow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    gd.ellipse((720, -290, 1450, 440), fill=(34, 92, 220, 38))
    gd.ellipse((-400, 390, 380, 1160), fill=(42, 83, 150, 22))
    im = Image.alpha_composite(im.convert("RGBA"), glow.filter(ImageFilter.GaussianBlur(100))).convert("RGB")
    d = ImageDraw.Draw(im, "RGBA")
    for xx in range(32, W, 48):
        d.line((xx, 0, xx, H), fill=(145, 170, 196, 10), width=1)
    for yy in range(18, H, 48):
        d.line((0, yy, W, yy), fill=(145, 170, 196, 9), width=1)
    return im


BG = make_background()
SCREENS = load_screens()


def rounded_image(image: Image.Image, size: tuple[int, int], radius: int = 16) -> Image.Image:
    fitted = Image.new("RGB", size, (248, 249, 252))
    source = image.copy()
    source.thumbnail(size, Image.Resampling.LANCZOS)
    fitted.paste(source, ((size[0] - source.width) // 2, (size[1] - source.height) // 2))
    mask = Image.new("L", size, 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, size[0] - 1, size[1] - 1), radius, fill=255)
    fitted.putalpha(mask)
    return fitted


def draw_screen(canvas: Image.Image, screen: Image.Image, rect: tuple[int, int, int, int], zoom: float = 1.0, focus=(0.5, 0.5)) -> None:
    x, y, w, h = rect
    d = ImageDraw.Draw(canvas, "RGBA")
    d.rounded_rectangle((x + 10, y + 16, x + w + 10, y + h + 16), 24, fill=(0, 0, 0, 95))
    d.rounded_rectangle((x, y, x + w, y + h), 22, fill=(246, 248, 251, 255), outline=(255, 255, 255, 185), width=1)
    chrome_h = 36
    d.rounded_rectangle((x, y, x + w, y + chrome_h + 12), 22, fill=(237, 241, 246, 255))
    d.rectangle((x, y + 20, x + w, y + chrome_h + 12), fill=(237, 241, 246, 255))
    for i, color in enumerate(((255, 112, 103, 255), (255, 190, 75, 255), (74, 202, 120, 255))):
        d.ellipse((x + 17 + i * 15, y + 13, x + 25 + i * 15, y + 21), fill=color)
    bar = (x + 116, y + 9, x + w - 116, y + 27)
    d.rounded_rectangle(bar, 9, fill=(222, 228, 236, 255))
    d.text((bar[0] + 12, y + 10), "drywalltoolbox.com", fill=(102, 114, 130, 255), font=font(10))
    content = (x + 14, y + chrome_h + 3, w - 28, h - chrome_h - 17)
    picture = screen
    source_width, source_height = picture.size
    crop_height = min(source_height, int(source_width * 9 / 16))
    picture = picture.crop((0, 0, source_width, crop_height))
    if zoom > 1.001:
        sw, sh = picture.size
        cw, ch = int(sw / zoom), int(sh / zoom)
        cx = int(sw * focus[0])
        cy = int(sh * focus[1])
        left = max(0, min(sw - cw, cx - cw // 2))
        top = max(0, min(sh - ch, cy - ch // 2))
        picture = picture.crop((left, top, left + cw, top + ch))
    art = rounded_image(picture, (content[2], content[3]), 11)
    canvas.paste(art, (content[0], content[1]), art)


def draw_cursor(draw: ImageDraw.ImageDraw, x: float, y: float, scale: float = 1.0, click: float = 0.0) -> None:
    r = int((13 + click * 17) * scale)
    if click > 0:
        alpha = int(150 * (1 - click))
        draw.ellipse((x-r, y-r, x+r, y+r), outline=(84, 221, 216, alpha), width=2)
    pts = [(x, y), (x, y + 29*scale), (x + 7*scale, y + 21*scale), (x + 14*scale, y + 34*scale),
           (x + 20*scale, y + 31*scale), (x + 13*scale, y + 18*scale), (x + 24*scale, y + 18*scale)]
    draw.polygon(pts, fill=(10, 15, 24, 255), outline=(249, 251, 255, 255))


SCENES = [
    (0, 2.7, "The new standard", "in drywall tools.", "THE DRYWALL TOOLBOX STOREFRONT"),
    (2.7, 5.5, "Tools, parts, and", "repairs in one place.", "SHOP THE WAY YOU WORK"),
    (5.5, 9.0, "Find it by type,", "brand or SKU.", "SEARCH PRODUCTS  ·  BRANDS  ·  SKUS"),
    (9.0, 12.4, "Know the product.", "See details up close.", "PRODUCT DETAILS, ALL IN ONE VIEW"),
    (12.4, 15.1, "Built for the work", "ahead.", "DRYWALL TOOLBOX"),
    (15.1, 18.0, "Drywall Toolbox.", "Ready when you are.", "EXPLORE DRYWALLTOOLBOX.COM"),
]


def scene_at(seconds: float) -> tuple[int, float, float, float]:
    for i, (start, end, *_rest) in enumerate(SCENES):
        if start <= seconds < end or i == len(SCENES) - 1:
            duration = end - start
            local = (seconds - start) / duration
            fade_in = ease(min(1, (seconds - start) / 0.42))
            fade_out = ease(min(1, (end - seconds) / 0.35))
            return i, local, min(fade_in, fade_out), duration
    return 0, 0, 0, 1


def draw_frame(seconds: float) -> Image.Image:
    index, local, opacity, _ = scene_at(seconds)
    start, end, first, second, kicker = SCENES[index]
    frame = BG.copy().convert("RGBA")
    # Soft scene transition veil keeps the film calm and readable.
    panel = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(panel, "RGBA")

    # Brand rail
    d.text((58, 39), "DRYWALL TOOLBOX", font=font(16, "bold"), fill=COLORS["white"] + (255,))
    d.text((58, 62), "PRO TOOLS  ·  PARTS  ·  REPAIR", font=font(9, "medium"), fill=COLORS["muted"] + (255,))
    d.rounded_rectangle((58, 82, 124, 85), 2, fill=COLORS["cyan"] + (255,))
    d.text((1209, 48), "MADE FOR DRYWALL PROS", font=font(10, "medium"), fill=(170, 184, 201, 255), anchor="ra")
    d.line((55, 94, 1225, 94), fill=(255, 255, 255, 30), width=1)

    # Text block
    text_alpha = int(opacity * 255)
    d.text((64, 170), kicker, font=font(11, "bold"), fill=COLORS["cyan"] + (text_alpha,))
    d.rounded_rectangle((64, 199, 101, 202), 2, fill=COLORS["cyan"] + (text_alpha,))
    d.text((61, 228), first, font=font(36, "bold"), fill=COLORS["white"] + (text_alpha,), stroke_width=0)
    d.text((61, 276), second, font=font(36, "bold"), fill=(216, 226, 238, text_alpha), stroke_width=0)
    copy = {
        0: "Professional drywall tools,\nparts, and repair services.",
        1: "Browse the catalog by type.\nFind the right fit for the job.",
        2: "Search products, brands,\nand SKUs from one place.",
        3: "See the gallery, SKU, price,\nand product details up close.",
        4: "Tools, replacement parts,\nschematics, and repair service.",
        5: "Find your next tool at\nDrywallToolbox.com.",
    }[index]
    d.multiline_text((65, 366), copy, font=font(17), fill=(171, 187, 207, text_alpha), spacing=9)

    # Timed feature chips
    chips = [("TOOL TYPES", "01"), ("PRODUCT SEARCH", "02"), ("PRODUCT DETAILS", "03")]
    if index in (1, 2, 3, 4, 5):
        active = min(2, max(0, index - 1))
        x0 = 65
        for ci, (label, number) in enumerate(chips):
            width = (107, 136, 139)[ci]
            active_chip = ci <= active
            d.rounded_rectangle((x0, 454, x0 + width, 482), 14,
                                fill=COLORS["cyan"] + (27 if active_chip else 10,),
                                outline=COLORS["cyan"] + (115 if active_chip else 38,), width=1)
            d.text((x0 + 10, 462), number, font=font(9, "bold"), fill=COLORS["cyan"] + (text_alpha,))
            d.text((x0 + 29, 461), label, font=font(9, "medium"), fill=(222, 232, 243, text_alpha))
            x0 += width + 8

    # Product screen, with a slow editorial drift and a precise zoom beat.
    keys = {0: "home", 1: "listing", 2: "listing", 3: "product", 4: "listing", 5: "home"}
    screen = SCREENS[keys[index]]
    drift = 28 * (1 - ease(min(1, local)))
    sx, sy, sw, sh = 519 + int(drift), 148, 686, 438
    zoom, focus = 1.0, (0.5, 0.5)
    if index == 2:
        zoom, focus = 1.0 + 0.34 * ease(min(1, local * 1.7)), (0.5, 0.1)
    elif index == 3:
        zoom, focus = 1.0 + 0.12 * ease(min(1, local * 1.7)), (0.69, 0.39)
    draw_screen(frame, screen, (sx, sy, sw, sh), zoom=zoom, focus=focus)

    # Floating feature note, timed and anchored to the screenshot panel.
    if index in (1, 2, 3):
        note = {1: "SHOP  ·  TOOL TYPE", 2: "SEARCH  ·  SKU", 3: "TAPETECH  ·  90T"}[index]
        d.rounded_rectangle((936, 555, 1179, 590), 17, fill=(18, 29, 43, 242), outline=COLORS["cyan"] + (105,), width=1)
        d.ellipse((952, 568, 960, 576), fill=COLORS["cyan"] + (255,))
        d.text((971, 563), note, font=font(10, "bold"), fill=(230, 240, 249, 255))

    # Cursor traverses the UI and clicks once at the demonstration moment.
    if index in (0, 1, 2, 3):
        points = {
            0: ((610, 473), (645, 470)),
            1: ((625, 324), (668, 324)),
            2: ((795, 220), (848, 220)),
            3: ((849, 330), (849, 330)),
        }
        a, b = points[index]
        travel = ease(min(1, local * 1.55))
        cx = a[0] + (b[0] - a[0]) * travel
        cy = a[1] + (b[1] - a[1]) * travel + math.sin(local * math.pi * 2) * 5
        click_phase = max(0.0, 1 - abs(local - 0.64) / 0.12) if index in (1, 2) else 0
        draw_cursor(d, cx, cy, scale=0.92, click=click_phase)

    # Bottom timeline and scene index
    d.text((64, 635), "TOOLS  ·  PARTS  ·  REPAIR", font=font(10, "bold"), fill=(161, 177, 197, 220))
    d.text((1217, 635), f"0{index + 1}  /  06", font=font(10, "bold"), fill=(161, 177, 197, 220), anchor="ra")
    d.rounded_rectangle((64, 663, 1216, 667), 2, fill=(255, 255, 255, 36))
    d.rounded_rectangle((64, 663, 64 + int(1152 * seconds / DURATION), 667), 2, fill=COLORS["cyan"] + (235,))
    frame = Image.alpha_composite(frame, panel)
    return frame.convert("RGB")


def write_srt() -> None:
    blocks = []
    for i, (start, end, first, second, _kicker) in enumerate(SCENES, 1):
        def stamp(value: float) -> str:
            ms = int(round(value * 1000))
            hh, ms = divmod(ms, 3_600_000)
            mm, ms = divmod(ms, 60_000)
            ss, ms = divmod(ms, 1000)
            return f"{hh:02}:{mm:02}:{ss:02},{ms:03}"
        blocks.append(f"{i}\n{stamp(start)} --> {stamp(end)}\n{first} {second}\n")
    SRT.write_text("\n".join(blocks), encoding="utf-8")


def main() -> None:
    encoder = subprocess.Popen(
        [imageio_ffmpeg.get_ffmpeg_exe(), "-y", "-loglevel", "error", "-f", "rawvideo",
         "-pix_fmt", "rgb24", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
         "-an", "-c:v", "libx264", "-preset", "medium", "-crf", "18", "-pix_fmt",
         "yuv420p", "-movflags", "+faststart", str(OUT)], stdin=subprocess.PIPE)
    if encoder.stdin is None:
        raise RuntimeError("FFmpeg input pipe was not created")
    try:
        for index in range(TOTAL):
            seconds = index / FPS
            frame = draw_frame(seconds)
            if index == 15 * FPS + 12:
                frame.save(POSTER, optimize=True)
            encoder.stdin.write(np.asarray(frame, dtype=np.uint8).tobytes())
    finally:
        encoder.stdin.close()
    code = encoder.wait()
    if code != 0:
        raise RuntimeError(f"FFmpeg returned exit code {code}")
    write_srt()
    print(f"Rendered {OUT} ({W}x{H}, {FPS} fps, {DURATION}s)")
    print(f"Poster: {POSTER}")
    print(f"Captions: {SRT}")


if __name__ == "__main__":
    main()
