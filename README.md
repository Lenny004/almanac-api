# Almanac API

A REST API for a date-driven discovery app. For any date, it builds a **briefing** from several public APIs (history, astronomy, weather), stores it in PostgreSQL, and lets authenticated users **bookmark** single discoveries (an event, a person, an APOD picture, ...).

Built with PHP 8.3, Symfony 7.4 (HttpClient, Security, Validator, Serializer, Console), Doctrine ORM + Migrations and PostgreSQL 16. There is no frontend.

## Contents

- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Create a user](#create-a-user)
- [Calling the API](#calling-the-api)
- [Prefetch command](#prefetch-command)
- [Tests and code quality](#tests-and-code-quality)
- [Error format](#error-format)
- [Data sources](#data-sources)
- [Caching and refresh policy](#caching-and-refresh-policy)
- [Database schema](#database-schema)
- [Running with Podman](#running-with-podman)
- [Notes](#notes)
- [Design explanation](#design-explanation)

## Requirements

- Docker with Docker Compose v2, **or** Podman (see [Running with Podman](#running-with-podman))
- Free port `8000` on your machine

You do not need PHP, Composer or PostgreSQL on your machine: everything runs in containers.

## Quick start

```bash
git clone <repository-url> almanac-api
cd almanac-api

# Optional: use your own NASA key instead of the rate-limited DEMO_KEY.
cp .env.example .env.local   # then edit NASA_API_KEY in .env.local

docker compose up -d --build
```

The first build takes a few minutes. When the `app` container starts, it runs the Doctrine migrations and then the web server. The API is available at **http://localhost:8000**.

```bash
curl http://localhost:8000/api/briefings/2020-11-10
```

- `.env.local` is optional and never committed. Without it, the app uses `NASA_API_KEY=DEMO_KEY` from `.env`.
- `docker compose up` (without `-d`) also works and shows the logs in the terminal.
- To stop: `docker compose down`. To also delete the database: `docker compose down -v`.

## Create a user

There is no registration endpoint. A console command creates a user and prints its access token:

```bash
docker compose exec app php bin/console almanac:user:create ana@example.com
```

```text
 [OK] User "ana@example.com" created.

Access token (shown only once, store it safely):
4f1c9e0d2b7a6c58e3d1f0a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9
```

Only a SHA-256 hash of the token is stored, so the token cannot be shown again. Save it in a shell variable for the examples below:

```bash
TOKEN=<the token printed above>
```

## Calling the API

All responses are JSON. Endpoints under `/api/bookmarks` and `/api/me` need the header `Authorization: Bearer <token>`. Briefings are public.

| Method | Path | Auth | Description |
| --- | --- | --- | --- |
| GET | `/api/briefings/{date}` | no | Briefing for a date (`YYYY-MM-DD`) |
| GET | `/api/me` | yes | The current user |
| POST | `/api/bookmarks` | yes | Create a bookmark |
| GET | `/api/bookmarks?page=1` | yes | List your bookmarks, 10 per page, newest first |
| GET | `/api/bookmarks/{id}` | yes | Show one of your bookmarks |
| DELETE | `/api/bookmarks/{id}` | yes | Delete one of your bookmarks |

### Get a briefing

```bash
curl http://localhost:8000/api/briefings/2020-11-10
```

Accepted dates: from `1995-06-16` (the first APOD picture) to today + 7 days (the weather forecast limit), in UTC. Response (shortened):

```json
{
  "date": "2020-11-10",
  "sources": {
    "apod": { "title": "...", "explanation": "...", "mediaType": "image", "url": "...", "hdUrl": "...", "copyright": null },
    "events": { "events": [ { "year": 2020, "text": "Armenia and Azerbaijan sign a ceasefire agreement, ...", "url": "https://en.wikipedia.org/wiki/Armenia" } ] },
    "births": { "people": [ { "year": 2002, "text": "Eduardo Camavinga, French footballer", "url": "https://en.wikipedia.org/wiki/Eduardo_Camavinga" } ] },
    "weather": { "location": "San Salvador, SV", "temperatureMax": 24.9, "temperatureMin": 18.9, "precipitation": 21.8, "unit": "celsius" },
    "year": { "title": "2020", "summary": "2020 (MMXX) was a leap year ...", "url": "https://en.wikipedia.org/wiki/2020", "thumbnail": "..." }
  },
  "missing": [],
  "fetchedAt": "2026-11-16T15:08:16+00:00"
}
```

When a source fails, its key is not in `sources` and its name is listed in `missing`, for example `"missing": ["apod"]`. The response is still `200`, even if every source fails, because the briefing itself was built correctly; the client reads `missing` to know what is absent.

### Who am I

```bash
curl http://localhost:8000/api/me -H "Authorization: Bearer $TOKEN"
```

```json
{"email":"ana@example.com","createdAt":"2026-11-16T15:08:15+00:00"}
```

### Create bookmarks

Body fields:

| Field | Required | Notes |
| --- | --- | --- |
| `type` | yes | `event`, `person`, `apod`, `year` or `weather` |
| `date` | yes | Date of the briefing (`YYYY-MM-DD`), same range as briefings |
| `title` | yes | Text to show, max 500 characters. For an event or a person, use the `text` from the briefing |
| `url` | no | Link to the discovery |
| `eventYear` | only for `event` | The `year` of the event in the briefing |

An event:

```bash
curl -X POST http://localhost:8000/api/bookmarks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"event","date":"2020-11-10","eventYear":2020,"title":"Armenia and Azerbaijan sign a ceasefire agreement","url":"https://en.wikipedia.org/wiki/Armenia"}'
```

```json
{"id":1,"type":"event","date":"2020-11-10","discoveryKey":"event:11-10:2020:1937dc68","title":"Armenia and Azerbaijan sign a ceasefire agreement","url":"https://en.wikipedia.org/wiki/Armenia","createdAt":"2026-11-16T15:08:34+00:00"}
```

The answer is `201 Created` with a `Location: /api/bookmarks/1` header. On a fresh database the first bookmark gets id `1`; in the next examples, use the `id` from your own responses.

A person:

```bash
curl -X POST http://localhost:8000/api/bookmarks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"person","date":"2020-11-10","title":"Eduardo Camavinga, French footballer","url":"https://en.wikipedia.org/wiki/Eduardo_Camavinga"}'
```

An APOD picture:

```bash
curl -X POST http://localhost:8000/api/bookmarks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"apod","date":"2020-11-10","title":"Astronomy Picture of the Day, 2020-11-10"}'
```

Sending the same discovery twice returns `409 Conflict`. Another user can bookmark the same discovery.

### List, show and delete bookmarks

```bash
curl "http://localhost:8000/api/bookmarks?page=1" -H "Authorization: Bearer $TOKEN"
```

```json
{"items":[{"id":3,"type":"apod","date":"2020-11-10","discoveryKey":"apod:2020-11-10","title":"Astronomy Picture of the Day, 2020-11-10","url":null,"createdAt":"2026-11-16T15:08:35+00:00"}, ...],"page":1,"perPage":10,"total":3,"totalPages":1}
```

The newest bookmarks come first. The output above is shortened to the first item.

```bash
curl http://localhost:8000/api/bookmarks/3 -H "Authorization: Bearer $TOKEN"
curl -X DELETE http://localhost:8000/api/bookmarks/3 -H "Authorization: Bearer $TOKEN"   # 204 No Content
```

A bookmark of another user answers `404`, the same as a bookmark that does not exist, so ids of other users are never revealed.

## Prefetch command

Fetches and stores the briefings of every date in a range (both dates included):

```bash
docker compose exec app php bin/console almanac:prefetch 2020-11-09 2020-11-10
```

Example output, on a day when NASA was not answering:

```text
[1/2] 2020-11-09 missing: apod
[2/2] 2020-11-10 missing: apod

 [WARNING] Processed 2 day(s): 0 complete, 2 with missing sources, 0 failed.

           Dates with missing sources: 2020-11-09, 2020-11-10
```

- It prints one line per day and a summary at the end.
- Dates that are already stored and valid are not fetched again (it uses the same cache as the API).
- One broken day does not stop the range: it is reported and the next day continues.

Exit codes:

| Code | Meaning |
| --- | --- |
| `0` | Every day was stored (some may have missing sources) |
| `1` | At least one day could not be fetched or stored at all |
| `2` | Invalid input: not a real date, outside the accepted range, or `from` after `to` |

There is no maximum range. A new day takes a few seconds, so a very large range (the whole history is about 11,000 days) takes hours. Run large ranges in smaller pieces.

## Tests and code quality

Run them inside the running `app` container:

```bash
docker compose exec app composer test       # PHPUnit
docker compose exec app composer phpstan    # PHPStan, level 6
docker compose exec app composer cs-check   # PHP-CS-Fixer (Symfony rules), shows problems only
docker compose exec app composer cs-fix     # PHP-CS-Fixer, fixes the files
```

`composer test` creates the `almanac_test` database if it does not exist, runs the migrations on it and then runs PHPUnit, so it works on a fresh clone. The tests use PostgreSQL, never SQLite, and never call the real upstream APIs: they use Symfony's `MockHttpClient`.

What is tested:

- `BriefingAggregatorTest`: all sources answer; one source fails (HTTP 500); a network error and a bad payload; asking only some sources.
- `BriefingProviderTest`: the refresh policy for past dates (no upstream calls for a complete date, only the missing sources are asked again after the refresh time).
- `ApodSourceTest`: the NASA key is sent in a header and never in the URL; the NASA logo placeholder is reported as unavailable.
- `BookmarkControllerTest`: create (event key, person key, `Location` header), duplicates (409), validation (422), no token (401), pagination, delete, and users never see or delete each other's bookmarks.

## Error format

Errors use [RFC 7807 Problem Details](https://www.rfc-editor.org/rfc/rfc7807) with `Content-Type: application/problem+json`.

```bash
curl http://localhost:8000/api/briefings/2020-02-31
```

```json
{"type":"about:blank","title":"Invalid date","status":400,"detail":"The date must be a real calendar date in YYYY-MM-DD format."}
```

Validation errors also list each field:

```json
{"type":"about:blank","title":"Unprocessable Content","status":422,"detail":"The request body is invalid.","violations":[{"field":"type","message":"The value you selected is not a valid choice."},{"field":"title","message":"This value should not be blank."}]}
```

| Status | When |
| --- | --- |
| 400 | Invalid or out-of-range date, invalid `page`, or a bookmark body that is not valid JSON |
| 401 | Missing or invalid token (also on a public briefing if an invalid token is sent); includes `WWW-Authenticate: Bearer` |
| 404 | Unknown route, or a bookmark that is not yours |
| 405 | Wrong HTTP method |
| 409 | The discovery is already bookmarked |
| 415 | The bookmark body is not JSON |
| 422 | The bookmark body fails validation |
| 500 | Unexpected error; internal details are never shown |

## Data sources

All calls are made server-side with Symfony HttpClient. Each source has a 5-second timeout and a 10-second maximum duration.

| Key | API | What we keep |
| --- | --- | --- |
| `apod` | NASA APOD | Title, explanation, media type, image URLs, copyright |
| `events` | Wikipedia On This Day (`/feed/onthisday/events/MM/DD`) | Up to 5 events, only from years up to the requested year |
| `births` | Wikipedia On This Day (`/feed/onthisday/births/MM/DD`) | Up to 5 people, only born up to the requested year |
| `weather` | Open-Meteo forecast or archive | Max/min temperature and precipitation for San Salvador, SV. Dates older than 5 days use the archive API |
| `year` | Wikipedia Page Summary (`/page/summary/{year}`) | Summary of the requested year |

How failures are handled:

- A timeout, a network error, an HTTP error or an unexpected payload makes that source **missing**. The other sources are not affected.
- When NASA has no real picture it can answer `200` with a generic NASA logo. This placeholder is detected and treated as missing, so it is never cached as a real picture.
- Every failure is logged with the source name and the date.
- The NASA key is sent in the `X-Api-Key` header, not in the `api_key` query parameter. The HTTP client writes URLs to logs and error messages, so a key in the URL could leak there.

## Caching and refresh policy

Briefings are stored in PostgreSQL (`briefing` table) and served from there. All dates are compared in UTC. The refresh time (TTL) is **1 hour**.

| Date | Policy |
| --- | --- |
| Past date, complete | Stored once and **never** fetched again: the past does not change. |
| Past date with missing sources | Nothing runs in the background. When someone requests the date again **and** the stored copy is older than 1 hour, only the **missing** sources are asked again. The sources already stored are never fetched again. |
| Today or a future date | Fetched again (all sources) when the stored copy is older than 1 hour, because the weather forecast changes during the day. |

Why retry missing sources of past dates: the brief says stored past dates must not hit upstream APIs again. We follow that for every piece of data we already have. A source that failed has no stored data, and an upstream outage (for example NASA being down) should not leave a hole in that date forever. The 1-hour wait means a broken upstream is called at most once per hour per date, not on every request.

## Database schema

Managed only with Doctrine Migrations (`migrations/`). They run automatically when the `app` container starts.

- **`briefing`**: `date` (DATE, **unique index**), `sources` (**JSONB**), `missing` (JSONB), `fetched_at`. Each source has a different shape, so JSONB keeps the normalized data without one table per source.
- **`app_user`**: `email` (unique), `token_hash` (SHA-256, unique, the token itself is never stored), `created_at`.
- **`bookmark`**: `user_id` (foreign key, `ON DELETE CASCADE`), `type`, `date`, `discovery_key`, `title`, `url`, `created_at`.
  - **Unique index** on `(user_id, discovery_key)`: the same user cannot bookmark the same discovery twice, even with two requests at the same moment.
  - **Index** on `(user_id, created_at)` for the paginated list.

The `discovery_key` identifies a discovery in a stable way:

| Type | Key | Why |
| --- | --- | --- |
| `event` | `event:MM-DD:<eventYear>:<hash of title>` | An event repeats on the same day every year, and several events can share a year |
| `person` | `person:MM-DD:<hash of title>` | A person is born on one day and month |
| `apod`, `weather` | `apod:YYYY-MM-DD` or `weather:YYYY-MM-DD` | One per date |
| `year` | `year:YYYY` | The same for every date of that year |

The hash is the first 8 characters of the SHA-1 of the trimmed title.

**Concurrent requests:** if two requests ask for the same new date at the same time, both may try to insert it. The unique index on `date` stops the second insert; the app catches that error and returns the row saved by the first request, so the client never gets a `500`.

## Running with Podman

The images use full names (`docker.io/library/...`), so Podman finds them without extra registry setup.

```bash
podman compose up -d --build
podman compose exec app composer test
```

`podman compose` uses `docker-compose` or `podman-compose` behind the scenes. `compose.yaml` loads `.env.local` with `required: false`. If your `podman-compose` version does not support that option and complains that `.env.local` is missing, create the file first:

```bash
cp .env.example .env.local
```

## Notes

- **`config/reference.php`**: Symfony 7.4 generates this file automatically in debug mode, only to help IDEs autocomplete PHP configuration. The app never reads it, so it is not in the repository (it is in `.gitignore`). This also keeps the rule that every committed PHP file has `declare(strict_types=1);`.
- **Bookmarks are not checked against the stored briefing.** The client sends the discovery data (type, date, title). The API validates it and builds the key, but it does not check that the event or person really appears in that briefing.
- Unknown JSON fields in the bookmark body are ignored.
- The page size is fixed to 10.
- `docker/start.sh` retries the migrations up to 10 times (3 seconds apart). Even with a healthy database, the `database` host name can take a moment to resolve on the first start.

## Design explanation

I chose to use all four suggested APIs. NASA APOD gives the picture of the day, Wikipedia "On This Day" gives historical events and famous people born that day, Open-Meteo gives the weather, and the Wikipedia page summary gives some context about the year. I split events and births into two sources, so a briefing has five sources in total. Births were also my way to support "person" bookmarks.

I organized the code by feature instead of by technical layer. The `Briefing/` folder has the sources, the aggregator and the cache logic, and the `Bookmark/` folder has the request DTO and the rules to create a bookmark. Controllers and console commands stay thin and only call these services. Every source implements `BriefingSourceInterface`, and Symfony injects all of them into the `BriefingAggregator` automatically, so adding a new source means writing one new class. Each source returns a small normalized array, never the raw response from the upstream API.

For input, the bookmark body is mapped to a DTO with `#[MapRequestPayload]` and checked with Validator attributes, so bad data never reaches the database. For output, I build simple arrays in the controllers. I didn't use serialization groups because the responses are small and this way is easier to read.

In the database, a briefing is one row per date with JSONB columns. I chose JSONB because every source has a different shape and the briefing is always read as a whole. Unique indexes protect the main rules: one briefing per date, and the same discovery can't be bookmarked twice by the same user.

The caching policy lives in `BriefingProvider`. A complete past date is never fetched again. If a past date has missing sources, only those sources are retried, at most once per hour, and only when someone asks for that date again. Today and future dates are refreshed after one hour, because the forecast can change.

For upstream errors, every request has a timeout. If a source fails, I log it and add its name to `missing`, but the rest of the briefing is still returned with status 200. Other errors are returned as RFC 7807 JSON by one exception listener.

I made some tradeoffs. The sources are called one after another, which is simple but can be slow when an API is slow. The weather is always for San Salvador, the city where I live. Bookmarks aren't checked against the stored briefing, and tokens never expire. That's fine for a test project, but not for production.

With more time, I would call the APIs in parallel, run the prefetch with Symfony Messenger, add rate limiting, publish OpenAPI docs and add token expiry.
