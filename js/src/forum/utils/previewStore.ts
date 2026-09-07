import app from 'flarum/forum/app';

import type { PreviewData, PreviewState } from '../../common/types';

/**
 * One request per URL, however many cards are asking for it.
 *
 * Every card mounts on its own, so deduplication cannot live in the component.
 * Requests are held briefly to turn a page load into one batch, and remembered
 * afterwards so scrolling back to a post does not ask again.
 */

const TTL = 5 * 60 * 1000;
const MAX_ENTRIES = 200;

/** Matches `Config::MAX_BATCH_SIZE`; the server ignores anything past it. */
const MAX_BATCH_SIZE = 20;

const FLUSH_DELAY = 50;

/**
 * How long a batch may hold the queue before it moves on without it. Well past
 * the server's eight second fetch timeout, so this is a last resort rather than
 * a second deadline.
 */
const FLUSH_TIMEOUT = 30000;

/** For a failure the server never got to name. */
const UNKNOWN = 'unknown';

const LOADING: PreviewState = { status: 'loading' };

interface Entry {
  state: PreviewState;
  expires: number;
}

const entries = new Map<string, Entry>();
const pending = new Map<string, Set<() => void>>();
const retained = new Map<string, number>();
const queue: string[] = [];

let timer: ReturnType<typeof setTimeout> | null = null;
let flushing = false;

export function previewFor(url: string): PreviewState {
  return entries.get(url)?.state ?? LOADING;
}

/**
 * Returns at once. `onChange` fires once, when this URL settles either way, and
 * the answer is then read back with `previewFor`.
 */
export function loadPreview(url: string, onChange: () => void): void {
  const waiting = pending.get(url);

  if (waiting) {
    waiting.add(onChange);
    return;
  }

  const entry = entries.get(url);

  if (entry && entry.expires > Date.now()) return;

  // A stale success is left in place so a post redrawn after the TTL keeps
  // showing its card while the replacement is fetched, rather than blinking
  // back to a skeleton. A stale failure has no card to keep, and left here it
  // would read as settled and take the new card down before the answer lands.
  if (entry?.state.status === 'failed') entries.delete(url);

  pending.set(url, new Set([onChange]));
  queue.push(url);

  schedule();
}

/**
 * Guards against eviction. Cards re-read the store on every redraw rather than
 * keeping a copy, and only ask once, when created, so an entry dropped under a
 * mounted card leaves it in a skeleton it never comes out of.
 */
export function retainPreview(url: string): void {
  retained.set(url, (retained.get(url) ?? 0) + 1);
}

export function releasePreview(url: string): void {
  const count = (retained.get(url) ?? 0) - 1;

  if (count > 0) {
    retained.set(url, count);
  } else {
    retained.delete(url);
  }
}

function schedule(): void {
  if (timer !== null) return;

  timer = setTimeout(flush, FLUSH_DELAY);
}

function flush(): void {
  // Cleared before any early return below: a handle left set would convince
  // `schedule()` a flush was already booked, and nothing would ever run again.
  timer = null;

  if (flushing || queue.length === 0) return;

  flushing = true;

  // Spliced so the overflow stays queued, rather than draining the whole queue
  // and putting the remainder back, which loses the callbacks it carries.
  const batch = queue.splice(0, MAX_BATCH_SIZE);

  let released = false;

  const release = (): void => {
    if (released) return;

    released = true;
    clearTimeout(guard);
    flushing = false;

    if (queue.length > 0) schedule();
  };

  // The lock needs a deadline because the request may never settle: core holds
  // one that failed while offline until connectivity returns, which may be
  // never, and the queue must not be stranded behind it.
  const guard = setTimeout(release, FLUSH_TIMEOUT);

  send(batch)
    // `send` settles every URL itself; this only stops a throwing subscriber
    // surfacing as an unhandled rejection.
    .catch(() => undefined)
    .finally(release);
}

function send(urls: string[]): Promise<void> {
  if (app.forum.attribute<boolean | undefined>('datlechin-link-preview.batchRequests') ?? true) {
    return sendBatch(urls);
  }

  return Promise.all(urls.map(sendOne)).then(() => undefined);
}

async function sendBatch(urls: string[]): Promise<void> {
  let data: Record<string, PreviewData> = {};

  try {
    const response = await app.request<{ data?: Record<string, PreviewData> }>({
      method: 'POST',
      url: `${app.forum.attribute<string>('apiUrl')}/datlechin-link-preview/batch`,
      body: { urls },
      errorHandler: quietly,
    });

    data = response?.data ?? {};
  } catch {
    // Every URL in the batch settles as a failure below.
  }

  urls.forEach((url) => settle(url, data[url]));
}

/**
 * A `POST` rather than a `GET`, so the endpoint cannot be reached from another
 * site. A cross origin `GET` fires from nothing but an `<img src>`, turning any
 * visitor of any page into an outbound fetch from this forum; a cross origin
 * `POST` carrying JSON needs a preflight the forum does not answer.
 */
async function sendOne(url: string): Promise<void> {
  let data: PreviewData | undefined;

  try {
    const response = await app.request<{ data?: PreviewData }>({
      method: 'POST',
      url: `${app.forum.attribute<string>('apiUrl')}/datlechin-link-preview`,
      body: { url },
      errorHandler: quietly,
    });

    data = response?.data;
  } catch {
    // Settled as a failure below.
  }

  settle(url, data);
}

/**
 * A failure leaves the link alone and says nothing, and core's alert fires per
 * request, so a post full of dead links would stack banners over the forum.
 */
function quietly(): void {}

function settle(url: string, data: PreviewData | undefined): void {
  entries.set(url, { state: stateFor(data), expires: Date.now() + TTL });

  evict();

  const waiting = pending.get(url);

  pending.delete(url);
  waiting?.forEach((onChange) => onChange());
}

function stateFor(data: PreviewData | undefined): PreviewState {
  if (!data) return { status: 'failed', code: UNKNOWN };

  if ('error' in data) return { status: 'failed', code: data.error || UNKNOWN };

  return { status: 'ready', data };
}

function evict(): void {
  // A Map hands back keys in insertion order, so the first is the oldest.
  // Refreshing an entry keeps its place, making this a queue and not an LRU.
  for (const oldest of Array.from(entries.keys())) {
    if (entries.size <= MAX_ENTRIES) break;

    // A card on screen is reading this one, and has nowhere else to get it.
    if (retained.has(oldest)) continue;

    entries.delete(oldest);
  }
}
