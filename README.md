# Anime World India Streaming API (PirateXPlay Backend)

High-performance PHP API for fetching anime streaming links, series details, movies, and episode metadata powered by **PirateXPlay** with **Upstash Redis Caching** (Parallel Multi-Account Storage & Sequential Read Failover).

---

## Features

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
* **Description**: Fetches streaming iframe links, download URLs, and backup server embeds for an episode or movie.

#### Query Parameters:
| Parameter | Type | Required? | Description |
| :--- | :--- | :--- | :--- |
| `id` or `episodeId` | string | Optional* | The slug/ID of the episode (e.g. `naruto-season-1-46260-1x1`) |
| `movieId` | string | Optional* | The slug/ID of the movie (e.g. `naruto-x-ut-2011-698940`) |
| `ongoing` | boolean | Optional | Pass `true` or `1` for airing/ongoing shows to set **12 Hours TTL** (default is **30 Days TTL**) |
| `refresh` or `force` | boolean | Optional | Pass `true` or `1` to bypass Redis cache, force a live re-scrape, and update Redis |

*\*Note: Either `episodeId` (or `id`) OR `movieId` is required.*

#### Example Request:
```http
GET /api/anime-world-india/v1/stream.php?id=naruto-season-1-46260-1x1&ongoing=true
```

#### Example Success Response:
```json
{
    "success": true,
    "type": "episode",
    "cached": false,
    "ttl": 43200,
    "source": "piratexplay.cc/episode",
    "series": {
        "title": "Naruto",
        "poster": "https://image.tmdb.org/t/p/w500/xppeysfvDKVx775MFuH8Z9BlpMk.jpg",
        "season": "Season 1",
        "totalEpisodes": "220"
    },
    "current": {
        "episodeId": "naruto-season-1-46260-1x1",
        "title": "Naruto Episode 1"
    },
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
            }
        ]
    }
}
```

---

### 2. Search Endpoint (Live Response - Uncached)

* **Endpoint**: `/api/anime-world-india/v1/search.php`
* **Method**: `GET`
* **Parameters**:
  * `query` (string, required): Search keyword (e.g., `naruto`, `jujutsu kaisen`).
  * `p` (int, optional): Page number (default: `1`).

#### Example Request:
```http
GET /api/anime-world-india/v1/search.php?query=naruto&p=1
```

---

### 3. Homepage Latest Releases (Live Response - Uncached)

* **Endpoint**: `/api/anime-world-india/v1/home.php`
* **Method**: `GET`
* **Description**: Fetches latest anime series and movies.

---

### 4. Seasons / Anime Info (Live Response - Uncached)

* **Endpoint**: `/api/anime-world-india/v1/seasons.php`
* **Method**: `GET`
* **Parameters**: `seriesID` (string, required)

---

### 5. Episodes List (Live Response - Uncached)

* **Endpoint**: `/api/anime-world-india/v1/episodes.php`
* **Method**: `GET`
* **Parameters**: `seasonId` (string, required)

---

### 6. Series Catalog (Live Response - Uncached)

* **Endpoint**: `/api/anime-world-india/v1/series.php`
* **Method**: `GET`
* **Parameters**: `p` (int, optional page number)

---

### 7. Movies Catalog (Live Response - Uncached)

* **Endpoint**: `/api/anime-world-india/v1/movie.php`
* **Method**: `GET`
* **Parameters**: `p` (int, optional page number)
