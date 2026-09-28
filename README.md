# Fieldwire-style Drawing Demo (Proof of Concept)

A small, self-contained demo of the core drawing experience of a construction-plan tool:

```
React
  → presigned URL          → Laravel
  → PUT PDF directly       → S3
  → upload-complete        → Laravel
  → queue job (Redis)      → ProcessPlanPdf
  → Poppler (pdftoppm)     → 200 DPI page PNGs
  → libvips (dzsave)       → Deep Zoom tile pyramid (.dzi + jpg tiles)
  → thumbnail (vips)       → JPEG
  → upload tiles           → S3
  → status polling         → React
  → OpenSeadragon          → pan / zoom / multi-page
  → Konva.js overlay       → annotations + issue pins (normalized coords)
  → PostgreSQL             → plans, pages, annotations, versions, issues
```

> ⚠️ **Demo only.** No auth, no organizations, no permissions, no Docker, no microservices.
> Generated tiles are uploaded with `public-read` ACLs for simplicity.

---

## 1. Project structure

```
demo-project/
├── backend/                          # Laravel 11
│   ├── app/
│   │   ├── Http/Controllers/         # PlanController, AnnotationController, IssueController
│   │   ├── Jobs/ProcessPlanPdf.php   # the only background job
│   │   ├── Models/                   # Plan, PlanPage, Annotation, AnnotationVersion, Issue
│   │   └── Services/                 # S3UploadService, PdfProcessingService
│   ├── config/                       # Laravel config (framework + .env driven)
│   ├── database/migrations/          # 5 domain tables + foundation tables
│   ├── routes/api.php                # every API route
│   ├── .env.example
│   └── composer.json
├── frontend/                         # React 18 + TypeScript + Vite
│   └── src/
│       ├── api/                      # plans.ts, annotations.ts, issues.ts (axios)
│       ├── components/               # PdfUploader, ProcessingStatus, PlanViewer,
│       │                             # PageSelector, AnnotationToolbar, AnnotationLayer,
│       │                             # IssueLayer, IssueForm, IssueDetails, TextEntry
│       ├── utils/coordinates.ts      # normalized <-> screen conversion (critical!)
│       ├── types/                    # plan.ts, annotation.ts, issue.ts
│       └── App.tsx
└── README.md
```

## 2. Database schema (PostgreSQL)

| Table | Purpose |
| --- | --- |
| `plans` | name, `original_pdf_key`, `status` (uploaded/processing/ready/failed), `page_count` |
| `plan_pages` | per page: `page_number`, `width`, `height`, `dzi_key`, `thumbnail_key` |
| `annotations` | `type` + `current_version` |
| `annotation_versions` | append-only rows: `version`, `geometry` (JSONB), `style` (JSONB) |
| `issues` | `title`, `description`, `status`, `priority`, normalized `x`, `y` |

Only **one** JSONB `geometry` store for all annotation types; coordinates are
always **normalized 0.0–1.0** relative to the full-resolution rendered page.

---

## 3. Local software prerequisites

- PHP ≥ 8.2 (with `pgsql`, `mbstring`, `xml`, `curl`, `zip` extensions)
- Composer
- Node.js ≥ 18 + npm
- PostgreSQL 14+
- Redis (only used for Laravel Queue)
- Poppler (`pdftoppm`, `pdfinfo`) — Ubuntu package `poppler-utils`
- libvips (`vips`, `dzsave`) — Ubuntu package `libvips-tools`
- An S3-compatible bucket (real AWS S3, or LocalStack/MinIO for free local testing)

### macOS (Homebrew)

```bash
brew install php composer
brew install node
brew install postgresql@16
brew install redis
brew install poppler
brew install vips
```

Start the services:

```bash
# PostgreSQL (start automatically at login):
brew services start postgresql@16

# Redis:
brew services start redis
```

Create the database (password is what you set in `.env`):

```bash
psql postgres
CREATE DATABASE fieldwire_demo;
# optionally set a password for the postgres user:
ALTER USER postgres WITH PASSWORD 'postgres';
\q
```

### Ubuntu (Debian/Ubuntu with apt)

```bash
sudo apt update
sudo apt install -y \
  php-cli php-pgsql php-mbstring php-xml php-curl php-zip \
  composer \
  postgresql redis-server \
  poppler-utils libvips-tools
```

Install Node.js/npm (if missing) e.g. via the NodeSource setup script, then start:

```bash
sudo service postgresql start
sudo service redis-server start

# create the database
sudo -u postgres psql -c "ALTER USER postgres WITH PASSWORD 'postgres';"
sudo -u postgres psql -c "CREATE DATABASE fieldwire_demo;"
```

> No special PHP extension is needed for Redis: the demo uses **predis**
> (pure PHP, already in `composer.json`) via `REDIS_CLIENT=predis`.

---

## 4. S3 setup (AWS or LocalStack/MinIO)

> Demo only — this bucket serves the PDF upload, and the generated tiles are
> public so OpenSeadragon can load them.

1. Create a bucket, e.g. `fieldwire-demo`.
2. Create credentials (access key + secret) that can read/write that bucket.
3. Put the values in `backend/.env` (see below).
4. Apply this CORS policy to the bucket (supports the `http://localhost:5173` origin):

```json
[
  {
    "AllowedHeaders": ["*"],
    "AllowedMethods": ["GET", "PUT", "HEAD"],
    "AllowedOrigins": ["http://localhost:5173"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3000
  }
]
```

**LocalStack or MinIO alternative** (no AWS account needed). Start e.g.
`localstack start` (or run MinIO), create the bucket, then in `backend/.env`:

```
AWS_ACCESS_KEY_ID=test
AWS_SECRET_ACCESS_KEY=test
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=fieldwire-demo
AWS_URL=http://127.0.0.1:4566/fieldwire-demo
AWS_ENDPOINT=http://127.0.0.1:4566
AWS_USE_PATH_STYLE_ENDPOINT=true
```

`AWS_URL` (bucket base URL) is what OpenSeadragon will request tiles from.

---

## 5. Backend setup & run

```bash
cd backend

composer install

cp .env.example .env
php artisan key:generate

# edit .env - set DB_* and AWS_* values (see .env.example for all options)
# example:
#   AWS_BUCKET=fieldwire-demo

php artisan migrate

# terminal 1 — Laravel API
php artisan serve
# -> http://127.0.0.1:8000

# terminal 2 — queue worker (Redis must be running; large plans take a while)
* * * * * cd /var/www/mcef-dashboard && /usr/bin/php artisan queue:work --tries=3 --timeout=900 >> /dev/null 2>&1
php artisan queue:work --tries=3 --timeout=900
```

The whole pipeline is async: `php artisan serve` only handles HTTP; the queue
worker does Poppler + libvips.

### Backend smoke test (no infra required)

`backend/smoke_http.php` boots the real HTTP kernel against in-memory SQLite and a
fake S3 service, then exercises the plan endpoints (presigned upload URL,
validation 422s, upload-complete + queue dispatch, show, 404s). It needs no
PostgreSQL, Redis, or AWS credentials:

```bash
cd backend
php smoke_http.php
# -> 13 passed, 0 failed
```

## 6. Frontend setup & run

```bash
cd frontend

npm install
npm run dev
# -> http://localhost:5173
```

The Vite dev server proxies `/api` → `http://127.0.0.1:8000`, so no CORS is
needed between the two. S3 still needs the CORS policy above for the direct
PUT upload and for loading the DZI/tiles.

## 7. `.env.example` highlights (backend)

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=fieldwire_demo
DB_USERNAME=postgres
DB_PASSWORD=postgres

QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_CLIENT=predis
REDIS_QUEUE_RETRY_AFTER=1800

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_URL=
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=false
```

Never commit real AWS credentials.

---

## 8. How the pieces fit together

| Step | Where |
| --- | --- |
| 1. Upload a PDF | `frontend` `PdfUploader` → `POST /api/plans/upload-url` |
| 2. Presigned S3 URL | `PlanController` (`S3UploadService::presignedUploadUrl`) |
| 3. Direct PUT to S3 | React `fetch(uploadUrl)` — never through Laravel |
| 4. Notify done | `POST /api/plans/upload-complete` → creates `Plan` (status `processing`) |
| 5. Queue | `ProcessPlanPdf::dispatch()` on the Redis queue |
| 6. Poppler | `PdfProcessingService` → `pdftoppm -png -r 200 -singlefile` |
| 7. libvips | `vips dzsave … --tile-size 512 --overlap 0 --suffix .jpg` + `vips thumbnail` |
| 8. Upload tiles | `S3UploadService::uploadFile` → `demo/plans/{id}/pages/{n}/…` |
| 9. DB records | `PlanPage` per page; `page_count`, status `ready` |
| 10. Rendering | React polls `GET /api/plans/{id}` every 2 s → OpenSeadragon opens `page.dzi` |
| 11. Overlay | Konva stage pinned via `utils/coordinates.ts` viewport math |
| 12. Persistence | annotations (versioned) + issues stored with normalized coordinates |

S3 layout example:

```
demo/
├── uploads/abc123.pdf
└── plans/1/
    ├── original/drawing.pdf
    └── pages/1/
        ├── page.dzi
        ├── thumbnail.jpg
        └── page_files/0/…_….jpg …
```

### Normalized coordinates (critical)

The backend only ever stores values in the range `0.0–1.0`:

```
normalizedX = imageX / imageWidth
```

`frontend/src/utils/coordinates.ts` maps between screen pixels and normalized
coordinates through the OpenSeadragon viewport (`pointFromPixel` /
`pixelFromPoint`), so annotations and pin icons stay glued to the drawing while
you pan, zoom, or resize the window.

### Annotation versioning

`POST /api/plan-pages/{page}/annotations` → `current_version = 1` +
`annotation_versions.version = 1`. `PATCH /api/annotations/{annotation}` never
overwrites — it always appends `version = current_version + 1` and bumps
`current_version`. The full history is readable via
`GET /api/annotations/{annotation}/versions`.

---

## 9. API summary

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/api/plans/upload-url` | presigned S3 PUT URL for a PDF |
| POST | `/api/plans/upload-complete` | create plan + dispatch `ProcessPlanPdf` |
| GET | `/api/plans/{plan}` | plan status + pages (with `dziUrl`) |
| GET/POST | `/api/plan-pages/{page}/annotations` | list / create annotations |
| PATCH | `/api/annotations/{annotation}` | edit → new version appended |
| GET | `/api/annotations/{annotation}/versions` | version history |
| DELETE | `/api/annotations/{annotation}` | delete annotation (+ versions) |
| GET/POST | `/api/plan-pages/{page}/issues` | list / create issues (normalized x, y) |
| GET/PATCH/DELETE | `/api/issues/{issue}` | read / update status+priority / delete |

---

## 10. Manual end-to-end tests

> Start PostgreSQL, Redis, `php artisan serve`, `php artisan queue:work`, `npm run dev`,
> then open http://localhost:5173.

1. **One-page PDF** — upload → status `processing` → `ready` → drawing opens in
   OpenSeadragon. Verify tiles render when zooming deep.
2. **Multi-page PDF** — all pages processed; `< 1/5 >` selector loads one page at
   a time (annotations/issues are page-specific).
3. **Rectangle annotation** — draw in *Rectangle* mode; pan/zoom/refresh the
   browser (reopen via *Plan ID*), the rectangle stays in the same drawing position.
4. **Edit rectangle** — switch to *Select*, drag the rectangle; confirm the
   selection bar shows a second version and
   `GET /api/annotations/{id}/versions` has both v1 and v2.
5. **Freehand annotation** — draw a squiggle; verify normalized points are
   stored in `annotation_versions.geometry.points`.
6. **Issue creation** — *Issue* mode → click the drawing → form → Save → pin
   appears with the issue number on it.
7. **Zoom/pan after issue creation** — the pin stays at the correct drawing spot.
8. **Issue lifecycle** — click the pin → details; Edit → set status
   `open → in_progress → resolved`, priority, `PATCH` persists after refresh.

### Troubleshooting

- **Plan stuck `processing`** → check `backend/storage/logs/laravel.log` and the
  `failed_jobs` table; the job marks the plan `failed` on any Poppler/libvips/S3 error.
- **Tiles don't load** → bucket CORS / public-read issue (see §4). Check the
  `dziUrl` in `GET /api/plans/{id}` opens in a browser.
- **Upload returns 403** → presign credentials / clock skew / CORS on PUT.
- **Queue not running** → start `php artisan queue:work` and make sure Redis is up.
