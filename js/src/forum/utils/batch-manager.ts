import app from 'flarum/forum/app';
import type { LinkPreviewData, LinkPreviewCallback } from '../types';

interface CacheEntry {
  data: LinkPreviewData;
  timestamp: number;
}

const CACHE_TTL = 5 * 60 * 1000;
const MAX_BATCH_SIZE = 10;

const linkPreviewCache = new Map<string, CacheEntry>();

function getCached(url: string): LinkPreviewData | null {
  const entry = linkPreviewCache.get(url);
  if (!entry) return null;
  if (Date.now() - entry.timestamp > CACHE_TTL) {
    linkPreviewCache.delete(url);
    return null;
  }
  return entry.data;
}

function setCache(url: string, data: LinkPreviewData): void {
  linkPreviewCache.set(url, { data, timestamp: Date.now() });
}

class LinkPreviewBatchManager {
  private queue: Map<string, LinkPreviewCallback[]>;
  private timeout: ReturnType<typeof setTimeout> | null;
  private processing: boolean;
  private readonly BATCH_DELAY: number;

  constructor() {
    this.queue = new Map();
    this.timeout = null;
    this.processing = false;
    this.BATCH_DELAY = 50;
  }

  add(url: string, callback: LinkPreviewCallback) {
    const enableBatch = app.forum.attribute('datlechin-link-preview.enableBatchRequests');

    if (!enableBatch) {
      this.fetchSingle(url, callback);
      return;
    }

    const cached = getCached(url);
    if (cached) {
      callback(cached);
      return;
    }

    if (!this.queue.has(url)) {
      this.queue.set(url, []);
    }
    this.queue.get(url)!.push(callback);

    this.scheduleProcessing();
  }

  private scheduleProcessing() {
    if (!this.timeout) {
      this.timeout = setTimeout(() => this.process(), this.BATCH_DELAY);
    }
  }

  private async fetchSingle(url: string, callback: LinkPreviewCallback) {
    try {
      const response = await app.request<LinkPreviewData>({
        url: `${app.forum.attribute('apiUrl')}/datlechin-link-preview`,
        method: 'GET',
        params: { url },
      });

      setCache(url, response);
      callback(response);
    } catch {
      callback({ error: 'Failed to fetch preview' });
    }
  }

  private async process() {
    if (this.processing || this.queue.size === 0) return;

    this.processing = true;
    this.timeout = null;

    const allUrls = Array.from(this.queue.keys());
    const allCallbacks = Array.from(this.queue.values());
    this.queue.clear();

    const urls = allUrls.slice(0, MAX_BATCH_SIZE);
    const callbacks = allCallbacks.slice(0, MAX_BATCH_SIZE);

    // Re-queue overflow
    for (let i = MAX_BATCH_SIZE; i < allUrls.length; i++) {
      this.queue.set(allUrls[i], allCallbacks[i]);
    }

    try {
      const response = await this.fetchBatch(urls);
      this.handleSuccess(response, urls, callbacks);
    } catch {
      this.handleError(callbacks);
    }

    this.processing = false;

    if (this.queue.size > 0) {
      this.scheduleProcessing();
    }
  }

  private async fetchBatch(urls: string[]): Promise<Record<string, LinkPreviewData>> {
    return await app.request<Record<string, LinkPreviewData>>({
      url: `${app.forum.attribute('apiUrl')}/datlechin-link-preview/batch`,
      method: 'POST',
      body: { urls },
    });
  }

  private handleSuccess(response: Record<string, LinkPreviewData>, urls: string[], callbacks: LinkPreviewCallback[][]) {
    urls.forEach((url, index) => {
      const data = response[url] ?? { error: 'No preview available' };
      setCache(url, data);
      callbacks[index].forEach((cb) => cb(data));
    });
  }

  private handleError(callbacks: LinkPreviewCallback[][]) {
    const errorData: LinkPreviewData = { error: 'Failed to fetch preview' };
    callbacks.forEach((cbs) => cbs.forEach((cb) => cb(errorData)));
  }
}

const batchManager = new LinkPreviewBatchManager();
export default batchManager;
