import { jest } from '@jest/globals';

import type { PreviewSuccess } from '../../src/common/types';

const FLUSH_DELAY = 50;
const FLUSH_TIMEOUT = 30000;
const MAX_BATCH_SIZE = 20;
const MAX_ENTRIES = 200;
const TTL = 5 * 60 * 1000;

let store: typeof import('../../src/forum/utils/previewStore');
let app: any;

let requests: Array<Record<string, any>>;
let answer: (options: any) => Promise<unknown>;

function card(url: string): PreviewSuccess {
  return {
    url,
    type: 'link',
    layout: 'large',
    title: `Whatever is at ${url}`,
    description: null,
    siteName: 'Example',
    favicon: null,
    image: null,
  };
}

function served(options: any): Promise<unknown> {
  const { urls, url } = options.body;

  return Promise.resolve(urls ? { data: Object.fromEntries(urls.map((each: string) => [each, card(each)])) } : { data: card(url) });
}

function deferred<T>() {
  let settle!: (value: T) => void;

  return { promise: new Promise<T>((resolve) => (settle = resolve)), settle };
}

async function fresh(attributes: Record<string, unknown> = {}) {
  jest.resetModules();

  const bootstrapForum = (await import('@flarum/jest-config/src/bootstrap/forum')).default;

  app = (await import('flarum/forum/app')).default;

  bootstrapForum();

  app.forum = app.store.createRecord('forums');
  app.forum.pushData({ id: '1', type: 'forums', attributes: { apiUrl: 'https://forum.test/api', ...attributes } });

  app.request = ((options: any) => {
    requests.push(options);

    return answer(options);
  }) as any;

  store = await import('../../src/forum/utils/previewStore');
}

beforeEach(async () => {
  jest.useFakeTimers();

  requests = [];
  answer = served;

  await fresh();
});

afterEach(() => jest.useRealTimers());

const urlsSent = () => requests.map((request) => request.body.urls ?? [request.body.url]);

describe('asking for a preview', () => {
  it('costs one request however many cards want the same url', async () => {
    const first = jest.fn();
    const second = jest.fn();

    store.loadPreview('https://example.com/a', first);
    store.loadPreview('https://example.com/a', second);

    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(urlsSent()).toEqual([['https://example.com/a']]);
    expect(first).toHaveBeenCalledTimes(1);
    expect(second).toHaveBeenCalledTimes(1);
    expect(store.previewFor('https://example.com/a')).toEqual({ status: 'ready', data: card('https://example.com/a') });
  });

  it('tells a card that turned up while the request was already in flight', async () => {
    const held = deferred<unknown>();

    answer = () => held.promise;

    const first = jest.fn();
    const late = jest.fn();

    store.loadPreview('https://example.com/a', first);

    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    store.loadPreview('https://example.com/a', late);

    held.settle({ data: { 'https://example.com/a': card('https://example.com/a') } });
    await jest.advanceTimersByTimeAsync(0);

    expect(requests).toHaveLength(1);
    expect(first).toHaveBeenCalledTimes(1);
    expect(late).toHaveBeenCalledTimes(1);
  });

  it('answers at once from what it already knows', async () => {
    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(requests).toHaveLength(1);
  });

  it('asks again once what it knows has gone stale', async () => {
    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    await jest.advanceTimersByTimeAsync(TTL);

    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(requests).toHaveLength(2);
  });

  it('keeps a stale card on screen while its replacement is fetched', async () => {
    const held = deferred<unknown>();

    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    await jest.advanceTimersByTimeAsync(TTL);

    answer = () => held.promise;
    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(store.previewFor('https://example.com/a').status).toBe('ready');
  });
});

describe('a failure', () => {
  beforeEach(() => {
    answer = (options: any) => {
      const { urls, url } = options.body;
      const failed = (each: string) => ({ url: each, error: 'unreachable' });

      return Promise.resolve(urls ? { data: Object.fromEntries(urls.map((each: string) => [each, failed(each)])) } : { data: failed(url) });
    };
  });

  it('is remembered, so a dead link is not fetched again on every redraw', async () => {
    store.loadPreview('https://example.com/gone', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(store.previewFor('https://example.com/gone')).toEqual({ status: 'failed', code: 'unreachable' });

    store.loadPreview('https://example.com/gone', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(requests).toHaveLength(1);
  });

  it('is asked about again once its ttl has passed', async () => {
    store.loadPreview('https://example.com/gone', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    await jest.advanceTimersByTimeAsync(TTL);

    store.loadPreview('https://example.com/gone', () => {});

    expect(store.previewFor('https://example.com/gone').status).toBe('loading');

    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(requests).toHaveLength(2);
  });

  it('is what a url the server left out of its answer settles as', async () => {
    answer = () => Promise.resolve({ data: {} });

    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(store.previewFor('https://example.com/a')).toEqual({ status: 'failed', code: 'unknown' });
  });

  it('settles every url in a batch when the request itself is refused', async () => {
    answer = () => Promise.reject(new Error('500'));

    const told = jest.fn();

    store.loadPreview('https://example.com/a', told);
    store.loadPreview('https://example.com/b', told);

    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(told).toHaveBeenCalledTimes(2);
    expect(store.previewFor('https://example.com/a').status).toBe('failed');
    expect(store.previewFor('https://example.com/b').status).toBe('failed');
  });
});

describe('the batch queue', () => {
  it('does not strand itself when one flush lands on top of another', async () => {
    const held = deferred<unknown>();

    answer = () => held.promise;

    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    store.loadPreview('https://example.com/b', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(urlsSent()).toEqual([['https://example.com/a']]);

    answer = served;
    held.settle({ data: { 'https://example.com/a': card('https://example.com/a') } });

    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(urlsSent()).toEqual([['https://example.com/a'], ['https://example.com/b']]);
    expect(store.previewFor('https://example.com/b').status).toBe('ready');
  });

  it('moves on when a request never settles at all', async () => {
    answer = () => new Promise(() => {});

    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    answer = served;
    store.loadPreview('https://example.com/b', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_TIMEOUT);
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(urlsSent()).toEqual([['https://example.com/a'], ['https://example.com/b']]);
  });

  it('keeps what will not fit in one batch rather than dropping it', async () => {
    const urls = Array.from({ length: MAX_BATCH_SIZE + 5 }, (_, index) => `https://example.com/${index}`);
    const told = jest.fn();

    urls.forEach((url) => store.loadPreview(url, told));

    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(urlsSent()).toEqual([urls.slice(0, MAX_BATCH_SIZE), urls.slice(MAX_BATCH_SIZE)]);

    expect(told).toHaveBeenCalledTimes(urls.length);
  });
});

describe('forgetting old answers', () => {
  async function fill(count: number, from = 0) {
    for (let index = from; index < from + count; index++) {
      store.loadPreview(`https://filler.test/${index}`, () => {});
    }

    for (let flushes = 0; flushes <= Math.ceil(count / MAX_BATCH_SIZE); flushes++) {
      await jest.advanceTimersByTimeAsync(FLUSH_DELAY);
    }
  }

  it('never drops one a card on screen is still holding', async () => {
    store.retainPreview('https://example.com/held');
    store.loadPreview('https://example.com/held', () => {});

    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);
    await fill(MAX_ENTRIES + 50);

    expect(store.previewFor('https://example.com/held').status).toBe('ready');
    expect(store.previewFor('https://filler.test/0').status).toBe('loading');
  });

  it('drops one again once the last card holding it has gone', async () => {
    store.retainPreview('https://example.com/held');
    store.retainPreview('https://example.com/held');
    store.releasePreview('https://example.com/held');

    store.loadPreview('https://example.com/held', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);
    await fill(MAX_ENTRIES + 50);

    expect(store.previewFor('https://example.com/held').status).toBe('ready');

    store.releasePreview('https://example.com/held');
    await fill(20, MAX_ENTRIES + 50);

    expect(store.previewFor('https://example.com/held').status).toBe('loading');
  });
});

describe('the endpoint', () => {
  it('is the batch one unless the forum says otherwise', async () => {
    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(requests[0].url).toBe('https://forum.test/api/datlechin-link-preview/batch');
    expect(requests[0].method).toBe('POST');
  });

  it('is asked once per url when the forum has batching switched off', async () => {
    await fresh({ 'datlechin-link-preview.batchRequests': false });

    store.loadPreview('https://example.com/a', () => {});
    store.loadPreview('https://example.com/b', () => {});

    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(requests.map((request) => request.url)).toEqual([
      'https://forum.test/api/datlechin-link-preview',
      'https://forum.test/api/datlechin-link-preview',
    ]);
    expect(urlsSent()).toEqual([['https://example.com/a'], ['https://example.com/b']]);
    expect(store.previewFor('https://example.com/b').status).toBe('ready');
  });

  it('is never reached with a GET', async () => {
    await fresh({ 'datlechin-link-preview.batchRequests': false });

    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(requests[0].method).toBe('POST');
  });

  it('is asked quietly, so a post full of dead links does not stack alerts', async () => {
    store.loadPreview('https://example.com/a', () => {});
    await jest.advanceTimersByTimeAsync(FLUSH_DELAY);

    expect(typeof requests[0].errorHandler).toBe('function');
  });
});
