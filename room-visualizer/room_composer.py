"""
Composites a rug image onto the fixed luxury living room photo using
OpenCV's perspective transform (warpPerspective). Returns JPEG bytes.

Ported from the original app's backend/app/services/room_composer.py — the
core compositing math (perspective warp, LAB-space lighting match, two-layer
drop shadow, depth shading) is unchanged. What changed for this standalone
microservice: the room photo is a bundled local asset instead of a path into
the old FastAPI app's tree, and rug images are always fetched by URL (the
WordPress plugin always hands this service a fully-qualified WooCommerce
media URL, never a local filesystem path) rather than resolving /static or
frontend-public paths that don't exist in this deployment.
"""

import cv2
import numpy as np
import requests
import os

ROOM_LOCAL = os.path.join(os.path.dirname(__file__), "assets", "room-canvas.jpg")

# Floor region (percentages of room image dimensions) — same room photo as
# the original app, so these stay identical.
FLOOR = {"x": 8, "y": 58, "width": 80, "height": 30}
PERSPECTIVE = 0.72  # top edge width as fraction of bottom (room-specific)

# ── Simple in-memory caches ────────────────────────────────────────────────────
_room: np.ndarray | None = None
_rug_cache: dict[str, np.ndarray] = {}
_composite_cache: dict[str, bytes] = {}


def _load_room() -> np.ndarray:
    global _room
    if _room is not None:
        return _room
    img = cv2.imread(ROOM_LOCAL)
    if img is None:
        raise RuntimeError(f"Could not load room image at {ROOM_LOCAL}")
    _room = img
    return _room


def match_lighting(rug_bgr: np.ndarray, room_bgr: np.ndarray, region_pts: np.ndarray, strength: float = 0.4) -> np.ndarray:
    """Nudges the rug's brightness/warmth toward the room's own ambient lighting,
    sampled from the exact floor area (in the original photo) the rug will sit on —
    a warm-lit wood floor or a cool, shaded corner both leave a visible cast on
    whatever's placed there. Without this the rug reads as a flat sticker pasted
    over the photo regardless of how clean the perspective warp is.

    Partial statistic transfer in LAB space (Reinhard et al.): shift the rug's
    per-channel mean/std toward the room's, blended at `strength` so the rug's
    own color identity survives — a strength of 1.0 would recolor it entirely
    to the room's palette, which looks wrong for a patterned rug.
    """
    mask = np.zeros(room_bgr.shape[:2], dtype=np.uint8)
    cv2.fillConvexPoly(mask, region_pts.astype(np.int32), 255)
    sampled = room_bgr[mask > 0]
    if sampled.size == 0:
        return rug_bgr

    room_lab = cv2.cvtColor(sampled.reshape(-1, 1, 3), cv2.COLOR_BGR2LAB).astype(np.float32).reshape(-1, 3)
    room_mean = room_lab.mean(axis=0)
    room_std = room_lab.std(axis=0) + 1e-6

    rug_lab = cv2.cvtColor(rug_bgr, cv2.COLOR_BGR2LAB).astype(np.float32)
    flat = rug_lab.reshape(-1, 3)
    rug_mean = flat.mean(axis=0)
    rug_std = flat.std(axis=0) + 1e-6

    target_mean = rug_mean * (1 - strength) + room_mean * strength
    target_std = rug_std * (1 - strength) + room_std * strength

    matched = (rug_lab - rug_mean) * (target_std / rug_std) + target_mean
    matched = np.clip(matched, 0, 255).astype(np.uint8)
    return cv2.cvtColor(matched, cv2.COLOR_LAB2BGR)


_FETCH_HEADERS = {"User-Agent": "Mozilla/5.0 (compatible; DreamRugsRoomVisualizer/1.0)"}


def _load_rug(url: str) -> np.ndarray:
    if url in _rug_cache:
        return _rug_cache[url]
    r = requests.get(url, timeout=12, headers=_FETCH_HEADERS)
    r.raise_for_status()
    arr = np.frombuffer(r.content, np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise RuntimeError(f"Could not decode rug image: {url}")
    _rug_cache[url] = img
    return img


def compose(rug_image_url: str, opacity: float = 0.90) -> bytes:
    """Return JPEG bytes of the room with the rug placed on the floor."""
    cache_key = f"{rug_image_url}|{round(opacity, 2)}"
    if cache_key in _composite_cache:
        return _composite_cache[cache_key]

    room = _load_room().copy()
    rug = _load_rug(rug_image_url)

    room_h, room_w = room.shape[:2]

    # Convert floor-region percentages → pixel coordinates
    rx = int(FLOOR["x"] / 100 * room_w)
    ry = int(FLOOR["y"] / 100 * room_h)
    rw = int(FLOOR["width"] / 100 * room_w)
    rh = int(FLOOR["height"] / 100 * room_h)

    # Trapezoid: top edge is narrower (perspective — farther away)
    cx = rx + rw // 2
    top_w = int(rw * PERSPECTIVE)
    top_x = cx - top_w // 2

    # Floor destination corners (trapezoid)
    dst = np.float32([
        [top_x,          ry],
        [top_x + top_w,  ry],
        [rx + rw,        ry + rh],
        [rx,             ry + rh],
    ])

    # Match the rug's brightness/warmth to this room's floor lighting before
    # warping — sold the realism far more than the perspective math alone.
    rug = match_lighting(rug, room, dst, strength=0.35)
    rug_h, rug_w = rug.shape[:2]

    # Rug source corners (rectangle)
    src = np.float32([[0, 0], [rug_w, 0], [rug_w, rug_h], [0, rug_h]])

    # ── Perspective warp ──────────────────────────────────────────────────────
    M = cv2.getPerspectiveTransform(src, dst)
    warped = cv2.warpPerspective(rug, M, (room_w, room_h),
                                  flags=cv2.INTER_LINEAR,
                                  borderMode=cv2.BORDER_CONSTANT)

    # ── Rug mask (filled trapezoid) ───────────────────────────────────────────
    mask = np.zeros((room_h, room_w), dtype=np.float32)
    cv2.fillConvexPoly(mask, dst.astype(np.int32), 1.0)

    # ── Atmospheric depth shading ─────────────────────────────────────────────
    # The top of the rug (farther away) is slightly darker.
    depth = np.ones((room_h, room_w), dtype=np.float32)
    for y in range(ry, min(ry + rh, room_h)):
        t = (y - ry) / max(rh - 1, 1)           # 0 at top, 1 at bottom
        depth[y, :] = 0.62 + 0.38 * t            # 0.62 → 1.0

    # ── Two-layer drop shadow just outside the rug perimeter ─────────────────
    # A single soft blob reads as haze; a tight dark contact shadow right at
    # the edge plus a wider soft ambient one underneath is what a real rug
    # laid on a floor actually casts.
    contact_pts = np.float32([
        [top_x - 2,         ry + 1],
        [top_x + top_w + 2, ry + 1],
        [rx + rw + 4,       ry + rh + 4],
        [rx - 4,            ry + rh + 4],
    ])
    contact = np.zeros((room_h, room_w), dtype=np.float32)
    cv2.fillConvexPoly(contact, contact_pts.astype(np.int32), 0.65)
    contact = cv2.GaussianBlur(contact, (9, 9), 0)

    ambient_pts = np.float32([
        [top_x - 6,         ry + 3],
        [top_x + top_w + 6, ry + 3],
        [rx + rw + 12,      ry + rh + 10],
        [rx - 12,           ry + rh + 10],
    ])
    ambient = np.zeros((room_h, room_w), dtype=np.float32)
    cv2.fillConvexPoly(ambient, ambient_pts.astype(np.int32), 0.35)
    ambient = cv2.GaussianBlur(ambient, (35, 35), 0)

    shadow = np.clip(contact + ambient, 0, 1) * (1 - mask)  # only outside the rug

    # ── Composite ─────────────────────────────────────────────────────────────
    room_f  = room.astype(np.float32)
    warp_f  = warped.astype(np.float32)

    alpha_3 = np.stack([mask * opacity] * 3, axis=2)
    depth_3 = np.stack([depth] * 3, axis=2)
    shade_3 = np.stack([shadow] * 3, axis=2)

    # 1. Apply drop shadow to room
    result = room_f * (1.0 - shade_3)
    # 2. Blend perspective-warped rug with depth shading
    result = result * (1.0 - alpha_3) + warp_f * alpha_3 * depth_3

    result = result.clip(0, 255).astype(np.uint8)

    _, buf = cv2.imencode(".jpg", result, [cv2.IMWRITE_JPEG_QUALITY, 95])
    jpeg = buf.tobytes()
    _composite_cache[cache_key] = jpeg
    return jpeg


def room_only() -> bytes:
    """Return the bare room image as JPEG bytes (no rug)."""
    if "room_only" in _composite_cache:
        return _composite_cache["room_only"]
    room = _load_room()
    _, buf = cv2.imencode(".jpg", room, [cv2.IMWRITE_JPEG_QUALITY, 88])
    data = buf.tobytes()
    _composite_cache["room_only"] = data
    return data


def bust_cache() -> None:
    """Call after updating the room photo, or whenever a rug's own image changes."""
    global _room, _composite_cache, _rug_cache
    _room = None
    _composite_cache.clear()
    _rug_cache.clear()
