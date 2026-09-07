/**
 * Runs at the address the markup below points back at, because `collectLinks`
 * decides what is internal against `window.location`.
 *
 * @jest-environment-options {"url": "https://f.test/"}
 */

import bootstrapForum from '@flarum/jest-config/src/bootstrap/forum';
import app from 'flarum/forum/app';

import collectPreviewTargets from '../../src/forum/utils/collectLinks';
import type { PreviewTarget } from '../../src/forum/utils/collectLinks';

beforeAll(() => bootstrapForum());

beforeEach(() => settings());

function settings(attributes: Record<string, unknown> = {}) {
  // `bootstrapForum` never boots the application, so `app.forum` is not
  // populated and every setting read here would come back undefined.
  app.forum = app.store.createRecord('forums');
  app.forum.pushData({ id: '1', type: 'forums', attributes });
}

/** A `.Post-body`, holding the markup core actually renders. */
function body(html: string): HTMLElement {
  const post = document.createElement('div');

  post.className = 'Post-body';
  post.innerHTML = html;

  return post;
}

function collect(html: string): PreviewTarget[] {
  return collectPreviewTargets(body(html));
}

function urls(targets: PreviewTarget[]): string[] {
  return targets.map((target) => target.url);
}

function external(path: string, text = `https://x.test${path}`): string {
  return `<a href="https://x.test${path}" rel="ugc nofollow">${text}</a>`;
}

function internal(path: string, text = `https://f.test${path}`): string {
  return `<a href="https://f.test${path}" class="UrlLink UrlLink--internal" target="_self" rel="noopener">${text}</a>`;
}

function discussion(id: string, href = `https://f.test/d/${id}-slug`): string {
  return `<a href="${href}" class="UrlLink UrlLink--internal UrlLink--discussion" target="_self" rel="noopener"><span class="UrlLink-discussion">#${id}</span></a>`;
}

/** A tracked link, as datlechin/flarum-link-clicks leaves it once core has rendered it. */
function tracked(text: string): string {
  return `<a href="https://f.test/lcc/track?u=TOKEN" class="LinkClicks-link" rel="ugc nofollow">${text}</a>`;
}

/** What a mounted card leaves behind: the wrapper, and the anchor it stood in for. */
function carded(path: string): string {
  const link = `<a href="https://x.test${path}" class="LinkPreview-source" data-link-preview rel="ugc nofollow">https://x.test${path}</a>`;

  return `<p><span class="LinkPreview-container" data-link-preview></span>${link}</p>`;
}

describe('which links get a card', () => {
  it('collects a bare address and stands the card in for it', () => {
    const post = body(`<p>${external('/a')}</p>`);
    const [target] = collectPreviewTargets(post);

    expect(target.url).toBe('https://x.test/a');
    expect(target.internal).toBe(false);
    expect(target.mode).toBe('replace');
    expect(target.link).toBe(post.querySelector('a'));
    expect(target.block).toBe(post.querySelector('p'));
  });

  it('keeps the sentence a link sits in and puts its card after it', () => {
    const targets = collect(`<p>Look at ${external('/a')} for the rest.</p>`);

    expect(targets).toHaveLength(1);
    expect(targets[0].mode).toBe('after');
  });

  it('never collects a link the writer gave words of their own', () => {
    expect(collect(`<p>${external('/a', 'the docs')}</p>`)).toEqual([]);
  });

  it('places the card against the block, not the inline element the address sits in', () => {
    const post = body(`<ul><li>Read <em>${external('/a')}</em> tonight</li></ul>`);
    const [target] = collectPreviewTargets(post);

    expect(target.block).toBe(post.querySelector('li'));
    expect(target.mode).toBe('after');
  });

  it('leaves a link with no block of its own alone', () => {
    // A card at the top level of the body changes the node count Mithril
    // remembers from `m.trust(contentHtml)`, and the next edit removes the
    // wrong nodes.
    expect(collect(external('/a'))).toEqual([]);
    expect(collect(`<strong>${external('/a')}</strong>`)).toEqual([]);
  });

  it('follows a paragraph that holds a picture beside the address', () => {
    const targets = collect(`<p>${external('/a')}<img src="https://x.test/p.png"></p>`);

    expect(targets[0].mode).toBe('after');
  });
});

describe('an address on a line of its own', () => {
  // Only a blank line makes the formatter open a new paragraph. Without one the
  // address shares a paragraph with the sentences around it, and reading the
  // paragraph left the address standing with its card stranded under the lot.
  it('stands in for an address the writer gave a line but no blank line', () => {
    const post = body(`<p>Read this first:<br>\n${external('/a')}<br>\nMind the version.</p>`);
    const [target] = collectPreviewTargets(post);

    expect(target.mode).toBe('replace');
    expect(target.block).toBe(post.querySelector('p'));
  });

  it('stands in for each of a run of addresses on consecutive lines', () => {
    const targets = collect(`<p>${external('/a')}<br>\n${external('/b')}</p>`);

    expect(urls(targets)).toEqual(['https://x.test/a', 'https://x.test/b']);
    expect(targets.map((target) => target.mode)).toEqual(['replace', 'replace']);
  });

  it('reads the line through whatever the writer wrapped the address in', () => {
    expect(collect(`<p>Read:<br><strong>${external('/a')}</strong><br>done</p>`)[0].mode).toBe('replace');
  });

  it('keeps a line that says more than the address', () => {
    expect(collect(`<p>Read:<br>look at ${external('/a')} tonight<br>done</p>`)[0].mode).toBe('after');
  });

  it('follows a line that holds a picture beside the address', () => {
    expect(collect(`<p>x<br>${external('/a')}<img src="https://x.test/p.png"><br>y</p>`)[0].mode).toBe('after');
  });

  it('stands in for a discussion label the writer gave a line of its own', () => {
    expect(collect(`<p>Answered here:<br>${discussion('8')}</p>`)[0].mode).toBe('replace');
  });
});

describe('the address a link really leads to', () => {
  it('follows a tracked link to the address in its text, not to the tracking href', () => {
    // Read as its href, no tracked link has its own address as its text, so
    // every post on a forum running link-clicks lost every preview it had.
    const targets = collect(`<p>${tracked('https://x.test/a')}</p>`);

    expect(urls(targets)).toEqual(['https://x.test/a']);
    expect(targets[0].internal).toBe(false);
    expect(targets[0].mode).toBe('replace');
  });

  it('refuses a link core marked internal whose text names another site', () => {
    // `[https://evil.test/x](https://f.test/u/bob)`. Believing the text here
    // would let a post show one site's card over a link leading to another.
    expect(collect(`<p>${internal('/u/bob', 'https://evil.test/x')}</p>`)).toEqual([]);
  });

  it('reads a discussion from its label rather than from the address it carries', () => {
    const targets = collect(`<p>${discussion('8')}</p>`);

    expect(urls(targets)).toEqual(['https://f.test/d/8']);
    expect(targets[0].internal).toBe(true);
    expect(targets[0].mode).toBe('replace');
  });

  it('reads a discussion label whose href has been rewritten from the label too', () => {
    expect(urls(collect(`<p>${discussion('8', 'https://f.test/lcc/track?u=TOKEN')}</p>`))).toEqual(['https://f.test/d/8']);
  });

  it('skips an address a browser would not fetch', () => {
    expect(collect('<p><a href="mailto:bob@f.test">mailto:bob@f.test</a></p>')).toEqual([]);
    expect(collect('<p><a href="ftp://x.test/a">ftp://x.test/a</a></p>')).toEqual([]);
  });
});

describe("the forum's own pages", () => {
  beforeEach(() => settings({ basePath: '' }));

  it('collects a user, a tag and the forum itself', () => {
    const targets = collect(`<p>${internal('/u/bob')}</p><p>${internal('/t/general')}</p><p>${internal('/')}</p>`);

    expect(urls(targets)).toEqual(['https://f.test/u/bob', 'https://f.test/t/general', 'https://f.test/']);
    expect(targets.every((target) => target.internal)).toBe(true);
  });

  it('leaves any other page of this forum alone rather than asking the server about it', () => {
    expect(collect(`<p>${internal('/settings')}</p>`)).toEqual([]);
    expect(collect(`<p>${internal('/tags')}</p>`)).toEqual([]);
  });

  it('collects a discussion label that is all its paragraph says', () => {
    expect(collect(`<p>${discussion('8')}</p>`)).toHaveLength(1);
  });

  it('leaves a discussion label inside a sentence as the label it already is', () => {
    expect(collect(`<p>See ${discussion('8')} for the rest.</p>`)).toEqual([]);
  });

  it('can be switched off without touching links to other sites', () => {
    settings({ 'datlechin-link-preview.previewInternalLinks': false });

    expect(urls(collect(`<p>${internal('/u/bob')}</p><p>${discussion('8')}</p><p>${external('/a')}</p>`))).toEqual(['https://x.test/a']);
  });
});

describe('a forum in a subdirectory', () => {
  beforeEach(() => settings({ basePath: '/forum' }));

  it('describes its own pages under the base path', () => {
    const targets = collect(`<p>${internal('/forum/u/bob')}</p>`);

    expect(urls(targets)).toEqual(['https://f.test/forum/u/bob']);
    expect(targets[0].internal).toBe(true);
  });

  it('builds a discussion address under the base path', () => {
    expect(urls(collect(`<p>${discussion('8', 'https://f.test/forum/d/8-slug')}</p>`))).toEqual(['https://f.test/forum/d/8']);
  });

  it('takes a neighbour sharing the origin for another site', () => {
    const targets = collect(`<p>${internal('/u/bob')}</p>`);

    expect(targets[0].internal).toBe(false);
  });
});

describe('how many cards a post may have', () => {
  it('previews five by default', () => {
    const links = ['/a', '/b', '/c', '/d', '/e', '/f'].map((path) => `<p>${external(path)}</p>`).join('');

    expect(collect(links)).toHaveLength(5);
  });

  it('stops at the limit, taking the links the post opens with', () => {
    settings({ 'datlechin-link-preview.maxPreviewsPerPost': 2 });

    expect(urls(collect(`<p>${external('/a')}</p><p>${external('/b')}</p><p>${external('/c')}</p>`))).toEqual([
      'https://x.test/a',
      'https://x.test/b',
    ]);
  });

  it('counts the cards the post already has, not only the ones this pass would add', () => {
    // `onupdate` fires for something as ordinary as hovering an avatar, so a
    // per-pass count let a post with twenty links reach twenty cards.
    settings({ 'datlechin-link-preview.maxPreviewsPerPost': 3 });

    const post = body(`${carded('/one')}${carded('/two')}<p>${external('/a')}</p><p>${external('/b')}</p><p>${external('/c')}</p>`);

    expect(urls(collectPreviewTargets(post))).toEqual(['https://x.test/a']);
  });

  it('collects nothing at all once the post is full', () => {
    settings({ 'datlechin-link-preview.maxPreviewsPerPost': 2 });

    expect(collectPreviewTargets(body(`${carded('/one')}${carded('/two')}<p>${external('/a')}</p>`))).toEqual([]);
  });

  it('still previews one when the limit is stored as none', () => {
    // A stored 0 clamps to 1 here, matching `Config::previewLimit`. Reading it
    // raw would switch previews off in the browser while the server went on
    // serving them.
    settings({ 'datlechin-link-preview.maxPreviewsPerPost': 0 });

    expect(collect(`<p>${external('/a')}</p><p>${external('/b')}</p>`)).toHaveLength(1);
  });
});

describe('what is not the writer linking', () => {
  it('leaves a mention alone, name and all', () => {
    expect(collect('<p><a href="https://f.test/u/bob" class="UserMention">@bob</a></p>')).toEqual([]);
    expect(collect('<p><a href="https://f.test/d/8/2" class="PostMention" data-id="2">@bob#2</a></p>')).toEqual([]);
  });

  it('leaves a quoted post to the post it was quoted from', () => {
    expect(collect(`<blockquote class="uncited"><div><p>${external('/a')}</p></div></blockquote>`)).toEqual([]);
  });

  it('skips an address that is only text that looks like one', () => {
    expect(collect(`<pre><code>${external('/a')}</code></pre>`)).toEqual([]);
    expect(collect(`<p>run <code>${external('/a')}</code> first</p>`)).toEqual([]);
  });

  it('never collects an anchor that already has a card', () => {
    expect(collectPreviewTargets(body(carded('/a')))).toEqual([]);
  });
});

describe('links the settings rule out', () => {
  it('skips a picture when picture links are switched off', () => {
    settings({ 'datlechin-link-preview.skipMediaLinks': true });

    expect(urls(collect(`<p>${external('/photo.jpg')}</p><p>${external('/a')}</p>`))).toEqual(['https://x.test/a']);
  });

  it('applies the blocklist to where a link leads, not to the address it carries', () => {
    settings({ 'datlechin-link-preview.blocklist': 'x.test' });

    expect(collect(`<p>${tracked('https://x.test/a')}</p>`)).toEqual([]);
  });
});
