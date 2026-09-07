import app from 'flarum/forum/app';

import { allowsUrl } from './matchesList';

export interface PreviewTarget {
  link: HTMLAnchorElement;
  /** Where the link leads, which is not always what its `href` says. */
  url: string;
  /** Decided from `url`, never from the `href`, which may point elsewhere. */
  internal: boolean;
  block: HTMLElement;
  mode: 'replace' | 'after';
}

/**
 * Which links in a post are worth a card, and where the card goes.
 *
 * Only a bare address qualifies: words the writer chose are what they wanted
 * read. A link back at this forum is the exception, since core has replaced its
 * text with a `#123` label that a card can improve on, and only when the
 * discussion link stands alone in its paragraph.
 *
 * All of it is decided against the address the link actually leads to, which
 * `destinationOf` recovers first, because the `href` is no longer reliably it.
 */

/** A mention carries a name the writer chose. */
const MENTIONS = '.PostMention, .UserMention, .GroupMention, .TagMention';

/** Core's label for a link to a discussion on this forum. */
const DISCUSSION = '.UrlLink--discussion';

/** The span core renders the discussion's id into, inside that label. */
const DISCUSSION_ID = '.UrlLink-discussion';

/** Core's mark for a link the writer really did point at this forum. */
const INTERNAL = '.UrlLink--internal';

const HANDLED = '[data-link-preview]';

const CARD = '.LinkPreview-container';

/** A quote is somebody else's post and gets its card there; code only looks like an address. */
const EXCLUDED_ANCESTOR = 'blockquote, pre, code, [data-link-preview]';

const MEDIA = /\.(jpe?g|png|gif|svg|webp|avif|mp3|mp4|m4a|wav|ogg|webm)$/i;

const EMBEDDED = 'img, picture, video, audio, iframe, embed, object, svg';

/** A card is placed against one of these, never the inline element the address sits in. */
const BLOCKS = new Set(['P', 'DIV', 'LI', 'DD', 'DT', 'TD', 'TH', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'FIGCAPTION', 'SECTION', 'ARTICLE']);

export default function collectPreviewTargets(postBody: HTMLElement): PreviewTarget[] {
  // Clamped to match `Config::previewLimit`. Read raw, a stored 0 would switch
  // previews off in the browser while the server went on serving them.
  const max = Math.max(1, app.forum.attribute<number | undefined>('datlechin-link-preview.maxPreviewsPerPost') ?? 5);
  const skipMedia = app.forum.attribute<boolean | undefined>('datlechin-link-preview.skipMediaLinks') ?? false;
  const internalAllowed = app.forum.attribute<boolean | undefined>('datlechin-link-preview.previewInternalLinks') ?? true;

  // What the post has left, not what this pass may add: `onupdate` fires for
  // something as ordinary as a hover, so a per-pass count would let a post with
  // twenty links reach twenty cards after a few redraws.
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

  // A link with no block of its own is left alone: a card here would change the
  // top level node count Mithril remembers from `m.trust(contentHtml)`, and
  // wrapping the anchor instead leaves `vnode.dom` detached, which throws inside
  // Mithril on the next content change and freezes the body for good.
  if (block === postBody) return null;

  const alone = isSoleContent(link, block);
  const internal = isInternal(destination);
  const url = destination.href;

  if (internal) {
    if (!internalAllowed) return null;

    // Everything else on this forum keeps whatever core made of it: a profile
    // or tag page has no card worth showing, and a discussion link inside a
    // sentence is already a readable label.
    if (!link.matches(DISCUSSION) || !alone) return null;

    return { link, url, internal, block, mode: 'replace' };
  }

  if (link.matches(DISCUSSION) || !textIsTheAddress(link, destination)) return null;

  return { link, url, internal, block, mode: alone ? 'replace' : 'after' };
}

/**
 * The address this link leads to.
 *
 * Not simply the `href`: datlechin/flarum-link-clicks points tracked links at
 * `/lcc/track?u=`, where the token is signed over a row id, so the destination
 * cannot be read back out of the `href` at all. It leaves the stored XML alone,
 * which is why core's classification of the link is still honest and can
 * recover what the `href` no longer says.
 */
function destinationOf(link: HTMLAnchorElement): URL | null {
  return discussionDestination(link) ?? rewrittenDestination(link) ?? httpUrl(link.getAttribute('href') ?? '', document.baseURI);
}

/**
 * The id is read from the label, not the address: the label is rendered from
 * the stored `discussionid`, so it survives any rewriting of the `href`.
 */
function discussionDestination(link: HTMLAnchorElement): URL | null {
  if (!link.matches(DISCUSSION)) return null;

  const id = (link.querySelector(DISCUSSION_ID)?.textContent ?? '').trim().replace(/^#/, '');

  if (!/^\d+$/.test(id)) return null;

  return httpUrl(`${window.location.origin}${basePath()}/d/${id}`);
}

/**
 * The destination of a link whose `href` was rewritten after core classified
 * it, read back off the link's own text.
 *
 * The `UrlLink--internal` test is what makes trusting that text safe. Core
 * stamps the class on every link the writer really did point at this forum
 * (`Formatter::configureDefaultsOnLinks`), so a same origin `href` without it
 * can only have been put there by a rewrite. That leaves
 * `[https://good.com](https://myforum.test/x)`, which core does mark internal,
 * skipped rather than believed.
 */
function rewrittenDestination(link: HTMLAnchorElement): URL | null {
  if (link.matches(INTERNAL)) return null;

  const href = httpUrl(link.getAttribute('href') ?? '', document.baseURI);

  if (href === null || href.origin !== window.location.origin) return null;

  const text = httpUrl((link.textContent ?? '').trim());

  if (text === null || text.origin === window.location.origin) return null;

  return text;
}

/**
 * An `http` or `https` address, or nothing. Called without a base, nothing
 * relative parses, which is what makes it the test for "the text is an address".
 */
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

/**
 * Decided the way core decides it in `routeInternalLinks.ts`, base path and
 * all: the links core routes rather than reloads are exactly the ones whose
 * previews come from the database instead of an HTTP request.
 */
function isInternal(url: URL): boolean {
  if (url.origin !== window.location.origin) return false;

  const base = basePath();

  // A forum in a subdirectory shares its origin with its neighbours, which the
  // origin test alone would claim as internal.
  if (base && url.pathname !== base && !url.pathname.startsWith(base + '/')) return false;

  return true;
}

function basePath(): string {
  return app.forum.attribute<string | undefined>('basePath') || '';
}

/**
 * The address is all the link says.
 *
 * Core makes the same call in `labelDiscussionLinks.ts` before turning a
 * discussion address into a label, and the two have to agree. Written `href`,
 * resolved `href` and destination all count: a writer who typed `example.com`
 * meant the address as much as one who typed the scheme, and a rewritten `href`
 * matches neither.
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

/**
 * If the address is all its paragraph says the card stands in for it; inside a
 * sentence, the sentence keeps its link and the card follows the paragraph.
 */
function isSoleContent(link: HTMLAnchorElement, block: HTMLElement): boolean {
  if ((block.textContent ?? '').trim() !== (link.textContent ?? '').trim()) return false;

  // Text is not the whole of it: a picture beside the address would be left
  // standing alone. One inside the link goes wherever the link goes, which is
  // what core's labelled discussion links are made of.
  return Array.from(block.querySelectorAll(EMBEDDED)).every((element) => link.contains(element));
}
