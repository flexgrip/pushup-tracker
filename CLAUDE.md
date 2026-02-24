# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Running the App

No build step required. Serve with any static HTTP server (required for the service worker to register):

```bash
python3 -m http.server 8080
# or
npx serve .
```

Then open `http://localhost:8080`.

Opening `index.html` directly as a `file://` URL works for basic functionality but the service worker will not register.

## Architecture

This is a zero-build PWA with no npm dependencies:

- **`index.html`** — the entire application. Contains all markup, styles (Tailwind via CDN), and logic (Alpine.js via CDN). The `pushupTracker()` Alpine component holds all state and methods.
- **`sw.js`** — service worker that caches `index.html` for offline use (cache-first strategy).
- **`manifest.json`** — PWA manifest enabling installability.

**State management:** All data lives in `localStorage` under the key `pushupData` as a JSON object `{ people: string[], currentPerson: string, data: { [person]: { [YYYY-MM-DD]: number } } }`.

**No backend, no accounts, no build tooling.** Modifications are made directly in `index.html`.

## Key Patterns

- Alpine.js reactive data is defined in `pushupTracker()` (inline `<script>` at bottom of `index.html`)
- Date keys use ISO format `YYYY-MM-DD` from `new Date().toISOString().split('T')[0]`
- Every mutation calls `this.save()` to persist to `localStorage`
- Tailwind classes are applied inline; dark theme (`bg-gray-900`) is the base
- Service worker cache name is `pushup-tracker-v1` — bump this in `sw.js` when cached assets change
