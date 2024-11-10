import app from 'flarum/forum/app';

const linkPreviewCache = new Map<string, any>();

class LinkPreviewBatchManager {
  private queue: Map<string, Function[]>;
  private timeout: NodeJS.Timeout | null;
  private processing: boolean;
  private readonly BATCH_DELAY: number;

  constructor() {
    this.queue = new Map();
    this.timeout = null;
    this.processing = false;
    this.BATCH_DELAY = 50; // ms

    this.setupCacheCleanup();
  }

  add(url: string, callback: Function) {
    const enableBatch = app.forum.attribute('datlechin-link-preview.enableBatchRequests');

    if (!enableBatch) {
      this.fetchSingle(url, callback);
      return;
    }

    if (linkPreviewCache.has(url)) {
      callback(linkPreviewCache.get(url));
      return;
    }

    if (!this.queue.has(url)) {
      this.queue.set(url, []);
    }
    this.queue.get(url)!.push(callback);

    this.scheduleProcessing();
  }

  scheduleProcessing() {
    if (!this.timeout) {
      this.timeout = setTimeout(() => this.process(), this.BATCH_DELAY);
    }
  }

  async fetchSingle(url: string, callback: Function) {
    try {
      const response = await app.request({
        url: `${app.forum.attribute('apiUrl')}/datlechin-link-preview`,
        method: 'GET',
        params: { url },
      });

      callback(response);
      linkPreviewCache.set(url, response);
    } catch (error) {
      callback({ error: 'Failed to fetch preview' });
    }
  }

  async process() {
    if (this.processing || this.queue.size === 0) return;

    this.processing = true;
    this.timeout = null;

    const urls = Array.from(this.queue.keys());
    const callbacks = Array.from(this.queue.values());
    this.queue.clear();

    try {
      const response = await this.fetchBatch(urls);
      this.handleSuccess(response, callbacks);
    } catch (error) {
      this.handleError(callbacks);
    }

    this.processing = false;

    if (this.queue.size > 0) {
      this.scheduleProcessing();
    }
  }

  async fetchBatch(urls: string[]): Promise<any> {
    return await app.request({
      url: `${app.forum.attribute('apiUrl')}/datlechin-link-preview/batch`,
      method: 'POST',
      body: { urls },
    });
  }

  handleSuccess(response: any, callbacks: Function[][]) {
    Object.entries(response).forEach(([url, data], index) => {
      linkPreviewCache.set(url, data);
      callbacks[index].forEach((cb) => cb(data));
    });
  }

  handleError(callbacks: Function[][]) {
    const errorData = { error: 'Failed to fetch preview' };
    callbacks.forEach((cbs) => cbs.forEach((cb) => cb(errorData)));
  }

  setupCacheCleanup() {
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        linkPreviewCache.clear();
      }
    });
  }

  clearCache() {
    linkPreviewCache.clear();
  }

  getCacheSize(): number {
    return linkPreviewCache.size;
  }
}

export default new LinkPreviewBatchManager();
