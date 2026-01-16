from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from fastapi.staticfiles import StaticFiles
import httpx

REMOTE_BASE = "https://kashite.space/wp-json/kashiteapp/v0_1_0"

app = FastAPI()


# ----------------------------
# 共通: リモート呼び出しヘルパ
# ----------------------------
async def fetch_remote(path: str, params: dict | None = None):
  """
  kashite.space 側の KASHITE API をそのまま呼ぶためのヘルパ。
  path は "/ping" などを想定。
  """
  url = f"{REMOTE_BASE.rstrip('/')}/{path.lstrip('/')}"
  async with httpx.AsyncClient(timeout=20.0) as client:
      resp = await client.get(url, params=params)
  resp.raise_for_status()
  return resp.json()

# ----------------------------
# /api/test/* : kashite.space のプロキシ
# （ブラウザから直接 REMOTE_BASE を叩く運用でも残しておく）
# ----------------------------

@app.get("/api/test/ping")
async def api_test_ping():
  data = await fetch_remote("/ping")
  return JSONResponse(data)


@app.get("/api/test/filters")
async def api_test_filters():
  data = await fetch_remote("/filters")
  return JSONResponse(data)


@app.get("/api/test/price_range")
async def api_test_price_range():
  data = await fetch_remote("/price_range")
  return JSONResponse(data)


@app.get("/api/test/option_space_type")
async def api_test_option_space_type():
  data = await fetch_remote("/option_space_type")
  return JSONResponse(data)


@app.get("/api/test/option_space_use")
async def api_test_option_space_use():
  data = await fetch_remote("/option_space_use")
  return JSONResponse(data)


@app.get("/api/test/search_url")
async def api_test_search_url(request: Request):
  # クエリパラメータそのままを kashite.space 側へ
  params = dict(request.query_params)
  data = await fetch_remote("/search_url", params=params)
  return JSONResponse(data)


@app.get("/api/test/search_results")
async def api_test_search_results(url: str):
  # WordPress 側の /search_results は ?url=... を受け取る
  data = await fetch_remote("/search_results", params={"url": url})
  return JSONResponse(data)


# 任意で product / calendar 系のプロキシも将来足せるが、
# 現状フロントから REMOTE_BASE に直接アクセスする設計。


# ----------------------------
# 静的ファイル (index.html / style.css / app.js)
# ----------------------------

# / → static/index.html を返す (html=True)
app.mount("/", StaticFiles(directory="static", html=True), name="static")

