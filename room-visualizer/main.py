"""
Thin FastAPI wrapper around room_composer.py. Called by the dreamrugs-core
WordPress plugin's /room-preview REST route over the Docker-internal network
— not meant to be reachable from the public internet directly (in a real
deploy, don't publish this service's port outside the Docker network; the
host-port mapping in docker-compose.yml is for local dev convenience only).
"""

from fastapi import FastAPI, HTTPException, Query
from fastapi.responses import Response

import room_composer

app = FastAPI(title="DreamRugs Room Visualizer")


@app.get("/health")
def health():
    return {"status": "ok"}


@app.get("/room-only")
def room_only():
    try:
        jpeg = room_composer.room_only()
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))
    return Response(content=jpeg, media_type="image/jpeg")


@app.get("/compose")
def compose(
    rug_image_url: str = Query(..., description="Fully-qualified URL of the rug's product image"),
    opacity: float = Query(0.90, ge=0.0, le=1.0),
):
    try:
        jpeg = room_composer.compose(rug_image_url, opacity)
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f"Could not compose rug image: {exc}")
    return Response(content=jpeg, media_type="image/jpeg")


@app.post("/bust-cache")
def bust_cache():
    room_composer.bust_cache()
    return {"status": "cleared"}
