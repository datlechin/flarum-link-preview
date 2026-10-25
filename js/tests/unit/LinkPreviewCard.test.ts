import { jest } from '@jest/globals';
import bootstrapForum from '@flarum/jest-config/src/bootstrap/forum';
import app from 'flarum/forum/app';
import mq from 'mithril-query';
import m from 'mithril';

import LinkPreviewCard from '../../src/forum/components/LinkPreviewCard';
import { loadPreview } from '../../src/forum/utils/previewStore';
import type { PreviewData, PreviewSuccess } from '../../src/common/types';

const ORIGIN = window.location.origin;

const answers = new Map<string, PreviewData>();

let asked = 0;

beforeAll(() => {
  bootstrapForum();

  app.translator.addTranslations({
    'datlechin-link-preview.forum.loading': 'Loading preview',
    'datlechin-link-preview.forum.clicks': '{count, plural, one {# click} other {# clicks}}',
    'datlechin-link-preview.forum.meta.replies': '{count, plural, one {# reply} other {# replies}}',
    'acme-recipes.forum.link_preview.steps': '{count, plural, one {# step} other {# steps}}',
  });

  app.request = ((options: any) => {
    const data: Record<string, PreviewData> = {};

    (options.body.urls as string[]).forEach((url) => {
      const answer = answers.get(url);

      if (answer) data[url] = answer;
    });

    return Promise.resolve({ data });
  }) as any;

  forum();
});

beforeEach(() => forum());

function forum(attributes: Record<string, unknown> = {}): void {
  app.forum = app.store.createRecord('forums');
  app.forum.pushData({ id: '1', type: 'forums', attributes: { apiUrl: `${ORIGIN}/api`, ...attributes } });
}

function unasked(): string {
  return `https://example.com/article-${++asked}`;
}

function settled(url: string): Promise<void> {
  return new Promise((resolve) => loadPreview(url, () => resolve()));
}

async function seed(url: string, data: Partial<PreviewSuccess> = {}): Promise<void> {
  answers.set(url, {
    url,
    type: 'link',
    layout: 'compact',
    title: 'A page',
    description: null,
    siteName: null,
    favicon: null,
    image: null,
    ...data,
  });

  await settled(url);
}

function anchor(href: string, attributes: Record<string, string> = {}): HTMLAnchorElement {
  const link = document.createElement('a');

  link.setAttribute('href', href);
  link.textContent = href;

  Object.entries(attributes).forEach(([name, value]) => link.setAttribute(name, value));

  return link;
}

function render(url: string, link: HTMLAnchorElement = anchor(url), internal = false) {
  return mq(LinkPreviewCard, { link, url, internal });
}

describe('a card that has not been answered yet', () => {
  it('draws the placeholder text core styles, and nothing it does not know', () => {
    const url = unasked();

    const rendered = render(url);

    expect(rendered).toHaveElement('a.LinkPreview--loading .fakeText');
    expect(rendered.find('.fakeText').length).toBe(2);
    expect(rendered).not.toHaveElement('.LinkPreview-title');
    expect(rendered.first('a').getAttribute('aria-busy')).toBe('true');
    expect(rendered.first('a').getAttribute('aria-label')).toBe('Loading preview');
  });
});

describe('a card whose preview failed', () => {
  it('draws nothing at all, so the link is left as it was', async () => {
    const url = unasked();

    await settled(url);

    expect(render(url).rootEl.innerHTML).toBe('');
  });
});

describe('the row of facts under a card', () => {
  it('renders every item of a discussion card, site and clicks and tags and author and replies and date alike', async () => {
    const url = unasked();

    await seed(url, {
      type: 'discussion',
      title: 'How the batch queue stranded itself',
      description: 'An early return left a fired timer in place.',
      siteName: 'Flarum Community',
      meta: [
        { type: 'text', text: 'Support' },
        { type: 'user', text: 'Alice' },
        { type: 'count', key: 'datlechin-link-preview.forum.meta.replies', count: 12 },
        { type: 'date', date: new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString() },
      ],
    });

    const link = anchor(url, { class: 'LinkPreview-source', 'data-clicks': '4' });
    const items = render(url, link).find('.LinkPreview-info > li');

    expect(items.length).toBe(6);

    expect(items.slice(0, 5).map((item: any) => item.textContent.trim())).toEqual(['Flarum Community', '4 clicks', 'Support', 'Alice', '12 replies']);

    expect(items[5].textContent.trim()).not.toBe('');
  });

  it('renders the title and the excerpt that follow the row', async () => {
    const url = unasked();

    await seed(url, { title: 'How the batch queue stranded itself', description: 'An early return left a fired timer in place.' });

    const rendered = render(url);

    expect(rendered.first('.LinkPreview-title').textContent).toBe('How the batch queue stranded itself');
    expect(rendered.first('.LinkPreview-excerpt').textContent).toBe('An early return left a fired timer in place.');
  });

  it('prints a text item as it came, and weights the author as a name', async () => {
    const url = unasked();

    await seed(url, {
      meta: [
        { type: 'user', text: 'Alice' },
        { type: 'text', text: 'Support' },
      ],
    });

    const rendered = render(url);

    expect(rendered.first('.LinkPreview-info .username').textContent).toBe('Alice');
    expect(rendered).toContainRaw('Support');
  });

  it('counts a count item through the translator', async () => {
    const url = unasked();

    await seed(url, { meta: [{ type: 'count', key: 'datlechin-link-preview.forum.meta.replies', count: 1 }] });

    expect(render(url).rootEl.textContent).toContain('1 reply');
  });

  it('counts with the string of whichever extension built the card', async () => {
    const url = unasked();

    await seed(url, { meta: [{ type: 'count', key: 'acme-recipes.forum.link_preview.steps', count: 4 }] });

    expect(render(url).rootEl.textContent).toContain('4 steps');
  });

  it('prints a date item as a human time', async () => {
    const url = unasked();
    const date = new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString();

    await seed(url, { meta: [{ type: 'date', date }] });

    const time = render(url).first('.LinkPreview-info time[data-humantime]');

    expect(time.getAttribute('datetime')).toContain(date.slice(0, 10));
    expect(time.textContent.trim()).not.toBe('');
  });

  it('falls back to the host when a site names itself after its own title', async () => {
    const url = 'https://www.flarum.org/docs/extend';

    await seed(url, { title: 'Flarum Documentation', siteName: 'Flarum Documentation' });

    expect(render(url).first('.LinkPreview-siteName').textContent).toBe('flarum.org');
  });
});

describe('the anchor a card is made of', () => {
  it('is the only one, and holds the whole card', async () => {
    const url = unasked();

    await seed(url, {
      layout: 'large',
      title: 'One anchor',
      description: 'And everything inside it.',
      image: { url: 'https://example.com/hero.png', width: 1200, height: 630 },
      meta: [{ type: 'user', text: 'Alice' }],
    });

    const rendered = render(url);

    expect(rendered.find('a').length).toBe(1);
    expect(rendered.find('a.LinkPreview > .LinkPreview-media .LinkPreview-image').length).toBe(1);
    expect(rendered.find('a.LinkPreview > .LinkPreview-body .LinkPreview-info').length).toBe(1);
    expect(rendered.find('a.LinkPreview > .LinkPreview-body .LinkPreview-title').length).toBe(1);
    expect(rendered.find('a.LinkPreview > .LinkPreview-body .LinkPreview-excerpt').length).toBe(1);
  });

  it('keeps an internal destination routable and in the same tab', async () => {
    const url = `${ORIGIN}/d/1`;

    await seed(url, { type: 'discussion', title: 'A discussion here' });

    const link = anchor(`${ORIGIN}/d/1-a-discussion-here/3`, { class: 'UrlLink UrlLink--internal UrlLink--discussion' });
    const rendered = render(url, link, true);

    expect(rendered).toHaveElement('a.LinkPreview.UrlLink--internal');
    expect(rendered.first('a').getAttribute('target')).toBe('_self');

    expect(rendered).not.toHaveElement('.UrlLink--discussion');
  });

  it('never marks a card routable when its href is a tracking route', async () => {
    const url = `${ORIGIN}/d/7`;

    await seed(url, { type: 'discussion', title: 'Counted on the way out' });

    const link = anchor('/lcc/track?u=9', { class: 'UrlLink UrlLink--internal' });
    const rendered = render(url, link, true);

    expect(rendered).not.toHaveElement('.UrlLink--internal');

    expect(rendered.first('a').getAttribute('href')).toBe('/lcc/track?u=9');
  });

  it('opens an external destination in a new tab when the forum says so, and safely', async () => {
    forum({ 'datlechin-link-preview.openLinksInNewTab': true });

    const url = unasked();

    await seed(url);

    const rendered = render(url, anchor(url, { rel: 'nofollow ugc' }));

    expect(rendered.first('a').getAttribute('target')).toBe('_blank');
    expect(rendered.first('a').getAttribute('rel')!.split(' ')).toEqual(['nofollow', 'ugc', 'noopener']);
    expect(rendered).not.toHaveElement('.UrlLink--internal');
  });

  it('keeps an external destination in the same tab when it does not, and still opens it safely', async () => {
    forum({ 'datlechin-link-preview.openLinksInNewTab': false });

    const url = unasked();

    await seed(url);

    const rendered = render(url);

    expect(rendered.first('a').getAttribute('target')).toBe('_self');
    expect(rendered.first('a').getAttribute('rel')).toBe('noopener');
  });
});

describe('a click on a card pointing at the page already open', () => {
  const here = window.location.href;

  beforeAll(() => seed(here, { type: 'forum', title: 'The index' }));

  function clickCard(eventData: Record<string, unknown>) {
    const route = jest.spyOn(m.route, 'set').mockImplementation(() => {});
    const rendered = render(here, anchor(here, { class: 'UrlLink UrlLink--internal' }), true);
    const card = rendered.first('a.LinkPreview');

    let prevented = false;

    card.addEventListener('click', (event: any) => (prevented = event.defaultPrevented));

    rendered.click('a.LinkPreview', { button: 0, cancelable: true, ...eventData } as unknown as Event);

    const routed = route.mock.calls.map(([path]) => path);

    route.mockRestore();

    return { prevented, routed };
  }

  it('is routed rather than left to the browser', () => {
    const { prevented, routed } = clickCard({});

    expect(prevented).toBe(true);
    expect(routed).toEqual(['/']);
  });

  it('is left to the browser when the reader asked for a new tab', () => {
    const { prevented, routed } = clickCard({ metaKey: true });

    expect(prevented).toBe(false);
    expect(routed).toEqual([]);
  });
});

describe('a card whose image will not load', () => {
  it('drops the image and falls back to the compact card', async () => {
    const url = unasked();

    await seed(url, {
      layout: 'large',
      title: 'Broken hero',
      image: { url: 'https://example.com/gone.png', width: 1200, height: 630 },
    });

    const rendered = render(url);

    expect(rendered).toHaveElement('.LinkPreview--large .LinkPreview-image');

    rendered.trigger('.LinkPreview-image', 'error', {} as Event);

    expect(rendered).not.toHaveElement('.LinkPreview-media');
    expect(rendered).toHaveElement('.LinkPreview--compact');
    expect(rendered).toHaveElement('.LinkPreview-title');
  });
});

describe('remote text a card is built from', () => {
  it('is stripped of the bidi controls that would reorder the card', async () => {
    const url = 'https://example.com/invoice';

    await seed(url, {
      title: 'Invoice \u202Emoc.elpmaxe\u202C',
      description: 'Pay \u2066here\u2069',
      siteName: '\u202Eevil\u202C',
      meta: [{ type: 'user', text: 'Al\u202Dice' }],
    });

    const rendered = render(url);

    expect(rendered.rootEl.textContent).not.toMatch(/[\u202A-\u202E\u2066-\u2069]/);
    expect(rendered.first('.LinkPreview-title').textContent).toBe('Invoice moc.elpmaxe');
    expect(rendered.first('.LinkPreview-siteName').textContent).toBe('evil');
  });
});
