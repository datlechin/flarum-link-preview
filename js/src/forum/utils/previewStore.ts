import app from 'flarum/forum/app';

import type { PreviewData, PreviewState } from '../../common/types';

/**
 * One request per URL, however many cards are asking for it.
 *
 * A discussion page can hold the same address a dozen times over, and every
 * card mounts on its own. Requests are therefore deduplicated here rather than
 * in the component, held for a moment so that a page load turns into one batch
 * instead of twenty requests, and remembered afterwards so scrolling back to a
 * post does not ask again.
 */

const TTL = 5 * 60 * 1000;
const MAX_ENTRIES = 200;

/** Matches `Config::MAX_BATCH_SIZE`; the server ignores anything past it. */
const MAX_BATCH_SIZE = 20;

const FLUSH_DELAY = 50;

/**
 * How long a batch may hold the queue before the queue moves on without it.
 * Comfortably past the server's own eight second fetch timeout, so this is a
 * last resort rather than a second deadline.
 */
const FLUSH_TIMEOUT = 30000;

/** For a failure the server never got to name. `errors.unknown` renders it. */
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
 * Ask for a preview, and be told when there is one.
 *
 * Returns at once. The callback fires once, when this URL settles either way,
 * and the answer is then read back with `previewFor`.
 */
export function loadPreview(url: string, onChange: () => void): void {
  const waiting = pending.get(url);

  if (waiting) {
    waiting.add(onChange);
    return;
  }

  const entry = entries.get(url);

  if (entry && entry.expires > Date.now()) return;

  // A stale entry is left where it is rather than dropped, so a post redrawn
  // after the TTL goes on showing its card while the replacement is fetched
  // instead of blinking back to a skeleton.
  pending.set(url, new Set([onChange]));
  queue.push(url);

  schedule();
}

/**
 * Say that a card for this URL is on screen, and later that it is gone.
 *
 * Eviction is what these are for. Cards read their answer back out of the store
 * on every redraw rather than keeping a copy, so an entry dropped while its
 * card is still mounted leaves that card in a skeleton it will never come out
 * of: nothing asks again, because the card only asks once, when it is created.
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

/**
 * For tests.
 */
export function resetPreviewStore(): void {
  entries.clear();
  pending.clear();
  retained.clear();
  queue.length = 0;

  if (timer !== null) clearTimeout(timer);

  timer = null;
  flushing = false;
}

function schedule(): void {
  if (timer !== null) return;

  timer = setTimeout(flush, FLUSH_DELAY);
}

function flush(): void {
  // Dropped before anything below can return. The batch manager this replaces
  // bailed out while a batch was in flight and left its handle set, so
  // `schedule()` went on believing a flush was already booked, nothing ever ran
  // again, and every URL queued after the first batch was stranded for the life
  // of the page.
  timer = null;

  if (flushing || queue.length === 0) return;

  flushing = true;

  // Spliced, rather than taking the whole queue and putting the overflow back.
  // That round trip is where the previous version lost the callbacks of
  // everything past the first chunk.
  const batch = queue.splice(0, MAX_BATCH_SIZE);

  let released = false;

  const release = (): void => {
    if (released) return;

    released = true;
    clearTimeout(guard);
    flushing = false;

    if (queue.length > 0) schedule();
  };

  // The lock is given a deadline rather than being left to the request to
  // release, because a request is not guaranteed to settle at all: core holds a
  // request that failed while the browser was offline and only settles it when
  // connectivity comes back, which may be never. The queue must not be stranded
  // behind it.
  const guard = setTimeout(release, FLUSH_TIMEOUT);

  send(batch)
    // `send` settles every URL in the batch itself. This is only here so that a
    // subscriber that throws cannot surface as an unhandled rejection.
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
 * site. A cross origin `GET` needs nothing but an `<img src>` to fire, which
 * would turn every visitor of any page anywhere into an outbound fetch from
 * this forum; a cross origin `POST` carrying JSON needs a preflight the forum
 * does not answer.
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
 * A preview that fails is already drawn as a failed card, so core's alert
 * banner would be a second and much louder report of something the reader can
 * see. It also fires per request, which on a post full of dead links means a
 * stack of banners over the forum.
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
  // A Map hands its keys back in insertion order, so the first one out is the
  // oldest. Refreshing an entry keeps its place, which is what makes this a
  // queue rather than a cache with a policy worth arguing about.
  for (const oldest of Array.from(entries.keys())) {
    if (entries.size <= MAX_ENTRIES) break;

    // A card on screen is reading this one. Two hundred is a bound on what is
    // worth remembering, not a licence to take an answer away from a card that
    // has nowhere else to get it.
    if (retained.has(oldest)) continue;

    entries.delete(oldest);
  }
}
