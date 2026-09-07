import app from 'flarum/forum/app';

import { allowsUrl } from './matchesList';

export interface PreviewTarget {
  link: HTMLAnchorElement;
  url: string;
  internal: boolean;
  block: HTMLElement;
  mode: 'replace' | 'after';
}

const MENTIONS = '.PostMention, .UserMention, .GroupMention, .TagMention';

const DISCUSSION = '.UrlLink--discussion';

const DISCUSSION_ID = '.UrlLink-discussion';

const INTERNAL = '.UrlLink--internal';

const HANDLED = '[data-link-preview]';

const CARD = '.LinkPreview-container';

const EXCLUDED_ANCESTOR = 'blockquote, pre, code, [data-link-preview]';

const MEDIA = /\.(jpe?g|png|gif|svg|webp|avif|mp3|mp4|m4a|wav|ogg|webm)$/i;

const EMBEDDED = 'img, picture, video, audio, iframe, embed, object, svg';

const BLOCKS = new Set(['P', 'DIV', 'LI', 'DD', 'DT', 'TD', 'TH', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'FIGCAPTION', 'SECTION', 'ARTICLE']);

export default function collectPreviewTargets(postBody: HTMLElement): PreviewTarget[] {
  // Floored at 1 to match `Config::previewLimit`: a stored 0 would switch previews
  // off in the browser while the server went on serving them.
  const max = Math.max(1, app.forum.attribute<number | undefined>('datlechin-link-preview.maxPreviewsPerPost') ?? 5);
  const skipMedia = app.forum.attribute<boolean | undefined>('datlechin-link-preview.skipMediaLinks') ?? false;
  const internalAllowed = app.forum.attribute<boolean | undefined>('datlechin-link-preview.previewInternalLinks') ?? true;

  // What the post has left, not what this pass adds: `onupdate` fires for a hover,
  // so a per-pass count would let one post reach twenty cards after a few redraws.
  const budget = max - postBody.querySelectorAll(CARD).length;

  if (budget <= 0) return [];

  const targets: PreviewTarget[] = [];

  for (const link of Array.from(postBody.querySelectorAll('a'))) {
    if (targets.length >= budget) break;

    const target = targetFor(link, postBody, skipMedia, internalAllowed);

    if (target !== null) targets.push(target);
  }

  return targets;
}

function targetFor(link: HTMLAnchorElement, postBody: HTMLElement, skipMedia: boolean, internalAllowed: boolean): PreviewTarget | null {
  if (link.matches(HANDLED) || link.matches(MENTIONS)) return null;

  if (link.closest(EXCLUDED_ANCESTOR) !== null) return null;

  const destination = destinationOf(link);

  if (destination === null) return null;

  if (skipMedia && MEDIA.test(destination.pathname)) return null;

  if (!allowsUrl(destination.href)) return null;

  const block = blockOf(link, postBody);

  // A link with no block of its own is left alone: a card here would change the top
  // level node count Mithril remembers from `m.trust(contentHtml)`, and wrapping the
  // anchor instead leaves `vnode.dom` detached, which throws on the next edit.
  if (block === postBody) return null;

  const alone = isSoleContent(link, block);
  const internal = isInternal(destination);
  const url = destination.href;

  if (internal) {
    if (!internalAllowed) return null;

    if (!isPreviewableRoute(destination)) return null;

    // Core replaced this link's text with a `#123` label, so the address test below
    // cannot be asked of it.
    if (link.matches(DISCUSSION)) {
      return alone ? { link, url, internal, block, mode: 'replace' } : null;
    }
  }

  if (link.matches(DISCUSSION) || !textIsTheAddress(link, destination)) return null;

  return { link, url, internal, block, mode: alone ? 'replace' : 'after' };
}

/**
 * Not simply the `href`: datlechin/flarum-link-clicks points tracked links at
 * `/lcc/track?u=`, where a signed token stands in for the address. It leaves the
 * stored XML alone, so core's classes still say what the writer wrote.
 */
function destinationOf(link: HTMLAnchorElement): URL | null {
  return discussionDestination(link) ?? rewrittenDestination(link) ?? httpUrl(link.getAttribute('href') ?? '', document.baseURI);
}

/**
 * Read from the label core rendered rather than from the `href`, which is what
 * makes this survive a tracking route being written over it.
 */
function discussionDestination(link: HTMLAnchorElement): URL | null {
  if (!link.matches(DISCUSSION)) return null;

  const id = (link.querySelector(DISCUSSION_ID)?.textContent ?? '').trim().replace(/^#/, '');

  if (!/^\d+$/.test(id)) return null;

  return httpUrl(`${window.location.origin}${basePath()}/d/${id}`);
}

/**
 * The `UrlLink--internal` test is what makes trusting the link's text safe: core
 * stamps that class on every link the writer really did point at this forum, so a
 * same origin `href` without it can only have been put there by a rewrite.
 */
function rewrittenDestination(link: HTMLAnchorElement): URL | null {
  if (link.matches(INTERNAL)) return null;

  const href = httpUrl(link.getAttribute('href') ?? '', document.baseURI);

  if (href === null || href.origin !== window.location.origin) return null;

  const text = httpUrl((link.textContent ?? '').trim());

  if (text === null || text.origin === window.location.origin) return null;

  return text;
}

/** Called without a base nothing relative parses, which is what makes it the test for "the text is an address". */
function httpUrl(value: string, base?: string): URL | null {
  if (value === '') return null;

  let url: URL;

  try {
    url = new URL(value, base);
  } catch {
    return null;
  }

  return url.protocol === 'http:' || url.protocol === 'https:' ? url : null;
}

/** Decided the way core decides it in `routeInternalLinks.ts`, base path and all. */
function isInternal(url: URL): boolean {
  if (url.origin !== window.location.origin) return false;

  const base = basePath();

  // A forum in a subdirectory shares its origin with its neighbours.
  if (base && url.pathname !== base && !url.pathname.startsWith(base + '/')) return false;

  return true;
}

/** The shapes have to agree with `InternalPreviewer`, which is what actually answers. */
function isPreviewableRoute(url: URL): boolean {
  const base = basePath();

  // `isInternal` has already established that the base path is there in full.
  const path = trimSlash(base === '' ? url.pathname : url.pathname.slice(base.length));

  // The index, but not `/tags`: the only card that route could carry is titled
  // with the forum's own name, which says less than the address it replaces.
  if (path === '') return true;

  if (/^\/d\/\d+(?:-[^/]*)?(?:\/\d+)?$/.test(path)) return true;

  return /^\/u\/[^/]+$/.test(path) || /^\/t\/[^/]+$/.test(path);
}

function basePath(): string {
  return app.forum.attribute<string | undefined>('basePath') || '';
}

/**
 * Core makes the same call in `labelDiscussionLinks.ts` and the two have to agree.
 * Written `href`, resolved `href` and destination all count: a writer who typed
 * `example.com` meant the address as much as one who typed the scheme.
 */
function textIsTheAddress(link: HTMLAnchorElement, destination: URL): boolean {
  const text = trimSlash((link.textContent ?? '').trim());

  if (text === '') return false;

  return text === trimSlash((link.getAttribute('href') ?? '').trim()) || text === trimSlash(link.href) || text === trimSlash(destination.href);
}

function trimSlash(value: string): string {
  return value.replace(/\/$/, '');
}

function blockOf(link: HTMLAnchorElement, postBody: HTMLElement): HTMLElement {
  let node = link.parentElement;

  while (node !== null && node !== postBody) {
    if (BLOCKS.has(node.tagName)) return node;

    node = node.parentElement;
  }

  return postBody;
}

function isSoleContent(link: HTMLAnchorElement, block: HTMLElement): boolean {
  if ((block.textContent ?? '').trim() !== (link.textContent ?? '').trim()) return false;

  // A picture beside the address would be left standing alone; one inside the link goes with it.
  return Array.from(block.querySelectorAll(EMBEDDED)).every((element) => link.contains(element));
}
