# Anime World India Streaming API (PirateXPlay Backend)

High-performance PHP API for fetching anime streaming links and backup server embeds powered by **PirateXPlay** with **Upstash Redis Caching** (Parallel Multi-Account Storage & Sequential Read Failover) and **AniList ID Direct Lookup**.

---

## Features

- **Lean & Fast JSON Response**: Stream links and server embeds are prioritized at the top of the JSON payload. Heavy episode lists and poster arrays have been removed for ultra-fast response times.
- **AniList ID Direct Search**: Pass `anilistId` directly (e.g. `anilistId=20&ep=1`) and the API automatically resolves the anime title via AniList GraphQL, maps it to PirateXPlay, and caches the mapping permanently in Redis.
- **Multi-Language Audio & Subs**: Supports Japanese Audio (Subbed), English Dubbed, Hindi Dubbed, Tamil, Telugu, and Multi-Audio streams.
- **Upstash Redis Caching**:
  - **Sequential Read Failover**: Reads from Primary Redis -> Secondary Redis -> Tertiary Redis with zero downtime.
  - **Parallel Write Replication**: Writes cached JSON payloads across all configured Redis accounts simultaneously using `curl_multi`.
  - **Smart Dynamic TTL**:
    - **30 Days TTL** (2,592,000s) for completed series/movies (default).
    - **12 Hours TTL** (43,200s) for ongoing/releasing anime (`ongoing=true`).
  - **Force Refresh Parameter**: Bypass cache and re-scrape fresh links on demand (`refresh=true`).
- **Render Ready**: Includes `Dockerfile` and `render.yaml` for 1-click cloud deployment.

---

## Deployment (Render Cloud Hosting)

1. **Push to GitHub**: Ensure this repository is on your GitHub account.
2. **Deploy on Render**:
   - Go to [Render Dashboard](https://dashboard.render.com).
   - Click **New +** -> **Blueprint**.
   - Select your repository (`AnimeWorld-India-Api-Streaming-api-for-hIndi-animes`).
3. **Configure Environment Variables**:
   - `UPSTASH_REDIS_REST_URL`: Primary Upstash Redis REST URL
   - `UPSTASH_REDIS_REST_TOKEN`: Primary Upstash Redis REST Bearer Token
   - `UPSTASH_REDIS_REST_URL_2`: *(Optional)* Secondary Upstash Redis REST URL
   - `UPSTASH_REDIS_REST_TOKEN_2`: *(Optional)* Secondary Upstash Redis REST Bearer Token
   - `UPSTASH_REDIS_REST_URL_3`: *(Optional)* Tertiary Upstash Redis REST URL
   - `UPSTASH_REDIS_REST_TOKEN_3`: *(Optional)* Tertiary Upstash Redis REST Bearer Token

---

## API Documentation

All API endpoints are located under `/api/anime-world-india/v1/`.

### 1. Streaming Links Endpoint (Cached in Upstash Redis)

* **Endpoint**: `/api/anime-world-india/v1/stream.php`
* **Method**: `GET`
* **Description**: Fetches streaming iframe links and backup server embeds.

#### Query Parameters:
| Parameter | Type | Required? | Description |
| :--- | :--- | :--- | :--- |
| `anilistId` | int | Optional* | Numeric AniList ID (e.g. `20` for Naruto). Automatically resolves title and maps to stream! |
| `ep` | int/string | Optional | Episode number when using `anilistId` (e.g. `1`, `12`, default: `1`) |
| `id` or `episodeId` | string | Optional* | Direct episode slug (e.g. `naruto-season-1-46260-1x1`) |
| `movieId` | string | Optional* | Direct movie slug (e.g. `naruto-x-ut-2011-698940`) |
| `ongoing` | boolean | Optional | Pass `true` or `1` for airing/ongoing shows to set **12 Hours TTL** (default is **30 Days TTL**) |
| `refresh` or `force` | boolean | Optional | Pass `true` or `1` to bypass Redis cache, force a live re-scrape, and update Redis |

*\*Note: Either `anilistId`, `episodeId` (or `id`), OR `movieId` is required.*

#### Example Requests:

1. **Using AniList ID (Simplest for Mobile Apps)**:
   ```http
   GET /api/anime-world-india/v1/stream.php?anilistId=20&ep=1
   ```

2. **Using Direct Episode Slug**:
   ```http
   GET /api/anime-world-india/v1/stream.php?id=naruto-season-1-46260-1x1&ongoing=true
   ```

3. **Force Refresh**:
   ```http
   GET /api/anime-world-india/v1/stream.php?anilistId=20&ep=1&refresh=true
   ```

#### Ultra-Clean & Compact Success Response JSON:
```json
{
    "success": true,
    "cached": true,
    "ttl": 2592000,
    "stream": {
        "streamLink": "https://rubystm.com/e/1ar22v23frcs.html",
        "file": "https://rubystm.com/e/1ar22v23frcs.html",
        "servers": [
            {
                "name": "Server 1",
                "url": "https://rubystm.com/e/1ar22v23frcs.html"
            },
            {
                "name": "Server 2",
                "url": "https://vidstreaming.xyz/v/V2RG6YD4QbI9/"
            },
            {
                "name": "Server 3",
                "url": "https://gdmirrorbot.nl/embed/e44mpcm"
            }
        ]
    },
    "info": {
        "title": "Naruto Episode 1",
        "id": "naruto-season-1-46260-1x1",
        "anilistId": 20,
        "type": "episode",
        "source": "piratexplay.cc"
    }
}
```

---

### 2. Search Endpoint (Uncached)

* **Endpoint**: `/api/anime-world-india/v1/search.php`
* **Method**: `GET`
* **Parameters**: `query` (required), `p` (optional page number)

```http
GET /api/anime-world-india/v1/search.php?query=naruto&p=1
```
