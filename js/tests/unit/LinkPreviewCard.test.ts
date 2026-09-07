import { jest } from '@jest/globals';
import bootstrapForum from '@flarum/jest-config/src/bootstrap/forum';
import app from 'flarum/forum/app';
import mq from 'mithril-query';
import m from 'mithril';

import LinkPreviewCard from '../../src/forum/components/LinkPreviewCard';
import { loadPreview } from '../../src/forum/utils/previewStore';
import type { PreviewData, PreviewSuccess } from '../../src/common/types';

/**
 * Every assertion here is against the DOM a browser would be handed, because
 * that is where each of these bugs was found: a card blanked by one bare string,
 * a second anchor inside the first, a reload where a route was meant.
 */

const ORIGIN = window.location.origin;

/** Answers the stubbed endpoint gives back, keyed by URL. */
const answers = new Map<string, PreviewData>();

let asked = 0;

beforeAll(() => {
  bootstrapForum();

  // The test application loads core's locale and no extension's, so the strings
  // the card puts on screen have to be added by hand.
  app.translator.addTranslations({
    'datlechin-link-preview.forum.loading': 'Loading preview',
    'datlechin-link-preview.forum.clicks': '{count, plural, one {# click} other {# clicks}}',
    'datlechin-link-preview.forum.meta.replies': '{count, plural, one {# reply} other {# replies}}',
  });

  // The store under the card is the real one, so a card only reaches `ready`
  // through a request. A URL with no answer settles as a failure, which is what
  // the server says about a page it could not read.
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

/** `bootstrapForum` loads a forum resource but never boots the application. */
function forum(attributes: Record<string, unknown> = {}): void {
  app.forum = app.store.createRecord('forums');
  app.forum.pushData({ id: '1', type: 'forums', attributes: { apiUrl: `${ORIGIN}/api`, ...attributes } });
}

/** A URL nothing has asked about yet: the store remembers every answer it gets. */
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

/** The anchor the card stands in for, as core would have left it in the post. */
function anchor(href: string, attributes: Record<string, string> = {}): HTMLAnchorElement {
  const link = document.createElement('a');

  link.setAttribute('href', href);
  link.textContent = href;

  Object.entries(attributes).forEach(([name, value]) => link.setAttribute(name, value));

  return link;
}

// `mq(Component, attrs)` rather than `mq(m(Component, attrs))`: reusing a single
// vnode across redraws is a Mithril anti-pattern, and the redraw then renders
// the stale tree, so a change made by an event never appears.
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
  /**
   * The point of this test. Every item the row produces has to survive being
   * rendered: `ItemList.toArray()` boxes a primitive so it can hang `itemName`
   * off it, `listItems` puts that box in the `<li>`, and Mithril takes the
   * object for a component and reads `.view` off undefined. Put one bare string
   * back into that list and this render throws rather than shortening.
   */
  it('renders every item of a discussion card, site and clicks and tags and author and replies and date alike', async () => {
    const url = unasked();

    await seed(url, {
      type: 'discussion',
      title: 'How the batch queue stranded itself',
      description: 'An early return left a fired timer in place.',
      siteName: 'Flarum Community',
      meta: [
        { key: 'tags', text: 'Support' },
        { key: 'author', text: 'Alice' },
        { key: 'replies', count: 12 },
        { key: 'created', date: new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString() },
      ],
    });

    // The count is read off the anchor, and only where the anchor has been
    // replaced by the card.
    const link = anchor(url, { class: 'LinkPreview-source', 'data-clicks': '4' });
    const items = render(url, link).find('.LinkPreview-info > li');

    expect(items.length).toBe(6);

    // Descending priorities, so the order the server sent survives the sort.
    expect(items.slice(0, 5).map((item: any) => item.textContent.trim())).toEqual(['Flarum Community', '4 clicks', 'Support', 'Alice', '12 replies']);

    // The date item is a `<time>`, and its wording belongs to dayjs.
    expect(items[5].textContent.trim()).not.toBe('');
  });

  it('renders the title and the excerpt that follow the row', async () => {
    const url = unasked();

    await seed(url, { title: 'How the batch queue stranded itself', description: 'An early return left a fired timer in place.' });

    const rendered = render(url);

    // A card that lost its row lost these too, and read as an empty box.
    expect(rendered.first('.LinkPreview-title').textContent).toBe('How the batch queue stranded itself');
    expect(rendered.first('.LinkPreview-excerpt').textContent).toBe('An early return left a fired timer in place.');
  });

  it('prints a text item as it came, and weights the author as a name', async () => {
    const url = unasked();

    await seed(url, {
      meta: [
        { key: 'author', text: 'Alice' },
        { key: 'tags', text: 'Support' },
      ],
    });

    const rendered = render(url);

    expect(rendered.first('.LinkPreview-info .username').textContent).toBe('Alice');
    expect(rendered).toContainRaw('Support');
  });

  it('counts a count item through the translator', async () => {
    const url = unasked();

    await seed(url, { meta: [{ key: 'replies', count: 1 }] });

    expect(render(url).rootEl.textContent).toContain('1 reply');
  });

  it('prints a date item as a human time', async () => {
    const url = unasked();
    const date = new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString();

    await seed(url, { meta: [{ key: 'created', date }] });

    const time = render(url).first('.LinkPreview-info time[data-humantime]');

    expect(time.getAttribute('datetime')).toContain(date.slice(0, 10));
    expect(time.textContent.trim()).not.toBe('');
  });

  it('falls back to the host when a site names itself after its own title', async () => {
    const url = 'https://www.flarum.org/docs/extend';

    await seed(url, { title: 'Flarum Documentation', siteName: 'Flarum Documentation' });

    // Otherwise the card says the same thing twice, one line above the other.
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
      meta: [{ key: 'author', text: 'Alice' }],
    });

    const rendered = render(url);

    // Issue #47: a second anchor here, and a browser unnests them silently.
    expect(rendered.find('a').length).toBe(1);
    expect(rendered.find('a.LinkPreview > .LinkPreview-media .LinkPreview-image').length).toBe(1);
    expect(rendered.find('a.LinkPreview > .LinkPreview-body .LinkPreview-info').length).toBe(1);
    expect(rendered.find('a.LinkPreview > .LinkPreview-body .LinkPreview-title').length).toBe(1);
    expect(rendered.find('a.LinkPreview > .LinkPreview-body .LinkPreview-excerpt').length).toBe(1);
  });

  it('keeps an internal destination routable and in the same tab', async () => {
    const url = `${ORIGIN}/d/1`;

    await seed(url, { type: 'discussion', title: 'A discussion here' });

    // Core renders the link with a slug and a post number; the destination is
    // the bare `/d/1`, so the href extends the path rather than matching it.
    const link = anchor(`${ORIGIN}/d/1-a-discussion-here/3`, { class: 'UrlLink UrlLink--internal UrlLink--discussion' });
    const rendered = render(url, link, true);

    expect(rendered).toHaveElement('a.LinkPreview.UrlLink--internal');
    expect(rendered.first('a').getAttribute('target')).toBe('_self');

    // `UrlLink--discussion` is `white-space: nowrap`, which would flatten the
    // line clamps, and `LinkPreview-source` would hide the card itself.
    expect(rendered).not.toHaveElement('.UrlLink--discussion');
  });

  it('never marks a card routable when its href is a tracking route', async () => {
    const url = `${ORIGIN}/d/7`;

    await seed(url, { type: 'discussion', title: 'Counted on the way out' });

    // Link Clicks rewrites the href after core has stamped the class on it.
    // Core's click handler checks nothing but the origin, so the class here
    // would hand `/lcc/track` to a router that has no such route.
    const link = anchor('/lcc/track?u=9', { class: 'UrlLink UrlLink--internal' });
    const rendered = render(url, link, true);

    expect(rendered).not.toHaveElement('.UrlLink--internal');

    // The href stays exactly as the tracker wrote it, so the click is counted.
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

  // Seeded once for both tests: the store answers each URL once, and this one
  // has to be the address bar's own.
  beforeAll(() => seed(here, { type: 'forum', title: 'The index' }));

  function clickCard(eventData: Record<string, unknown>) {
    const route = jest.spyOn(m.route, 'set').mockImplementation(() => {});
    const rendered = render(here, anchor(here, { class: 'UrlLink UrlLink--internal' }), true);
    const card = rendered.first('a.LinkPreview');

    let prevented = false;

    // Registered after Mithril's own handler, so it sees what that handler did.
    card.addEventListener('click', (event: any) => (prevented = event.defaultPrevented));

    // mithril-query builds the event from this object (`new Event(name, init)`
    // in its index.js), so it is an init, whatever its shipped types say.
    rendered.click('a.LinkPreview', { button: 0, cancelable: true, ...eventData } as unknown as Event);

    // Read before restoring, which forgets the calls along with the spy.
    const routed = route.mock.calls.map(([path]) => path);

    route.mockRestore();

    return { prevented, routed };
  }

  /**
   * Core's `routeInternalLinks()` gives up on an address identical to the
   * current one so an anchor jump still works, and the browser then reloads the
   * whole application to arrive where the reader already is. A card carries no
   * fragment, so there is nothing to jump to.
   */
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

    // Not a large card with a hole in it.
    expect(rendered).not.toHaveElement('.LinkPreview-media');
    expect(rendered).toHaveElement('.LinkPreview--compact');
    expect(rendered).toHaveElement('.LinkPreview-title');
  });
});

describe('remote text a card is built from', () => {
  /**
   * Trojan Source pointed at a link preview: a title carrying a bidi override
   * reorders everything after it, so a card can be made to read as one address
   * while linking to another.
   */
  it('is stripped of the bidi controls that would reorder the card', async () => {
    const url = 'https://example.com/invoice';

    // Escaped rather than typed, so this file reads in the order it was written.
    await seed(url, {
      title: 'Invoice \u202Emoc.elpmaxe\u202C',
      description: 'Pay \u2066here\u2069',
      siteName: '\u202Eevil\u202C',
      meta: [{ key: 'author', text: 'Al\u202Dice' }],
    });

    const rendered = render(url);

    expect(rendered.rootEl.textContent).not.toMatch(/[\u202A-\u202E\u2066-\u2069]/);
    expect(rendered.first('.LinkPreview-title').textContent).toBe('Invoice moc.elpmaxe');
    expect(rendered.first('.LinkPreview-siteName').textContent).toBe('evil');
  });
});
