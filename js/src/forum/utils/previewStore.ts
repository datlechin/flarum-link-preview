import app from 'flarum/forum/app';

import type { PreviewData, PreviewState } from '../../common/types';

const TTL = 5 * 60 * 1000;
const MAX_ENTRIES = 200;

const MAX_BATCH_SIZE = 20;

const FLUSH_DELAY = 50;

const FLUSH_TIMEOUT = 30000;

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

export function loadPreview(url: string, onChange: () => void): void {
  const waiting = pending.get(url);

  if (waiting) {
    waiting.add(onChange);
    return;
  }

  const entry = entries.get(url);

  if (entry && entry.expires > Date.now()) return;

  if (entry?.state.status === 'failed') entries.delete(url);

  pending.set(url, new Set([onChange]));
  queue.push(url);

  schedule();
}

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
  timer = null;

  if (flushing || queue.length === 0) return;

  flushing = true;

  const batch = queue.splice(0, MAX_BATCH_SIZE);

  let released = false;

  const release = (): void => {
    if (released) return;

    released = true;
    clearTimeout(guard);
    flushing = false;

    if (queue.length > 0) schedule();
  };

  const guard = setTimeout(release, FLUSH_TIMEOUT);

  send(batch)
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
  } catch {}

  urls.forEach((url) => settle(url, data[url]));
}

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
  } catch {}

  settle(url, data);
}

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
  for (const oldest of Array.from(entries.keys())) {
    if (entries.size <= MAX_ENTRIES) break;

    if (retained.has(oldest)) continue;

    entries.delete(oldest);
  }
}
