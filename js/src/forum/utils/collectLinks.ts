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
 * Only a bare address qualifies: if the writer gave the link its own words,
 * those words are what they wanted read, and a card would talk over them.
 *
 * A link back at this forum is the one exception. Core has already replaced its
 * text with a `#123` label, so there is no address left to compare against, and
 * the label is exactly what a card can improve on. Only a discussion link
 * standing alone in its paragraph is taken: a profile, a tag page or a link
 * inside a sentence is left as core rendered it.
 *
 * All of that is decided against the address the link actually leads to, which
 * `destinationOf` recovers first, because the `href` is not reliably that
 * address any more.
 */

/**
 * Links that already say what they point at. A mention carries a name, and the
 * writer chose it.
 */
const MENTIONS = '.PostMention, .UserMention, .GroupMention, .TagMention';

/** Core's label for a link to a discussion on this forum. */
const DISCUSSION = '.UrlLink--discussion';

/** The span core renders the discussion's id into, inside that label. */
const DISCUSSION_ID = '.UrlLink-discussion';

/** Core's mark for a link the writer really did point at this forum. */
const INTERNAL = '.UrlLink--internal';

/** The mark this pass leaves on an anchor it has already handled. */
const HANDLED = '[data-link-preview]';

/** The wrapper `cardRegistry` mounts a card into. */
const CARD = '.LinkPreview-container';

/**
 * Quoted, preformatted and already previewed content. A quote is somebody
 * else's post and gets its own card there; code is text that happens to look
 * like an address.
 */
const EXCLUDED_ANCESTOR = 'blockquote, pre, code, [data-link-preview]';

const MEDIA = /\.(jpe?g|png|gif|svg|webp|avif|mp3|mp4|m4a|wav|ogg|webm)$/i;

const EMBEDDED = 'img, picture, video, audio, iframe, embed, object, svg';

/**
 * The elements a paragraph of a post can be. A card is placed against one of
 * these, never against the inline element the address happens to sit in.
 */
const BLOCKS = new Set(['P', 'DIV', 'LI', 'DD', 'DT', 'TD', 'TH', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'FIGCAPTION', 'SECTION', 'ARTICLE']);

export default function collectPreviewTargets(postBody: HTMLElement): PreviewTarget[] {
  // The server clamps this same row to at least one (`Config::previewLimit`),
  // because an administrator who typed a zero asked for fewer previews and not
  // for a forum where no link ever gets one. Read raw, a stored 0 would switch
  // previews off in the browser while the server went on serving them.
  const max = Math.max(1, app.forum.attribute<number | undefined>('datlechin-link-preview.maxPreviewsPerPost') ?? 5);
  const skipMedia = app.forum.attribute<boolean | undefined>('datlechin-link-preview.skipMediaLinks') ?? false;
  const internalAllowed = app.forum.attribute<boolean | undefined>('datlechin-link-preview.previewInternalLinks') ?? true;

  // The budget is what the post has left, not what this pass may add. An anchor
  // that already has a card is skipped by the checks below and so counts for
  // nothing, and this runs again on every `onupdate`, which fires for something
  // as ordinary as a hover on the author's avatar. Counted per pass, a post
  // with twenty links would reach twenty cards after a few redraws.
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

  // A link with no block of its own to sit in is left alone. Mithril renders
  // the body from `m.trust(contentHtml)` and remembers how many top level nodes
  // that produced, so there is nowhere to put a card here without changing that
  // count, and no way to give the anchor a wrapper of its own either: moving a
  // top level node leaves `vnode.dom` pointing at something detached, and the
  // next content change throws inside Mithril and freezes the body for good.
  // Core's formatter wraps post content in block elements, so this is rare.
  if (block === postBody) return null;

  const alone = isSoleContent(link, block);
  const internal = isInternal(destination);
  const url = destination.href;

  if (internal) {
    if (!internalAllowed) return null;

    // Everything else on this forum keeps whatever core made of it. A profile
    // or a tag page has no card worth showing, and a discussion link inside a
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
 * Not simply the `href`, because an extension may have replaced that with a
 * route of its own after core rendered the link. datlechin/flarum-link-clicks
 * points every tracked link at `/lcc/track?u=`, where the token is signed over
 * a row id rather than the address, so the destination cannot be read back out
 * of the `href` at all. What it deliberately does not touch is the stored XML,
 * which is why core's own classification of the link is still honest and can be
 * used to recover what the `href` no longer says.
 */
function destinationOf(link: HTMLAnchorElement): URL | null {
  return discussionDestination(link) ?? rewrittenDestination(link) ?? httpUrl(link.getAttribute('href') ?? '', document.baseURI);
}

/**
 * A link core labelled as a discussion here, rebuilt from the label.
 *
 * The id comes out of the label rather than the address because the label is
 * rendered from the stored `discussionid` attribute, so it says where the link
 * was written to go however the `href` has since been rewritten.
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
 * The `UrlLink--internal` test is what makes this safe. Core stamps that class
 * on every link the writer really did point at this forum
 * (`Formatter::configureDefaultsOnLinks`), so a same origin `href` without it
 * cannot have come from the writer and can only have been put there by
 * something that rewrote the link afterwards. That leaves
 * `[https://good.com](https://myforum.test/x)`, which core does mark internal,
 * to be skipped rather than believed.
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
 * An `http` or `https` address, or nothing. Anything else is not a page a card
 * could be drawn of, and without a base nothing relative parses at all, which
 * is what makes this the test for "the text is itself an address".
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
 * Whether this address points back at the forum.
 *
 * Decided the way core decides it in `routeInternalLinks.ts`, base path and
 * all, because a link core will route rather than reload is the same set of
 * links whose previews come from the database instead of an HTTP request.
 */
function isInternal(url: URL): boolean {
  if (url.origin !== window.location.origin) return false;

  const base = basePath();

  // A forum in a subdirectory shares its origin with everything else hosted
  // there, so the origin alone would claim the neighbours as well.
  if (base && url.pathname !== base && !url.pathname.startsWith(base + '/')) return false;

  return true;
}

function basePath(): string {
  return app.forum.attribute<string | undefined>('basePath') || '';
}

/**
 * The address is all the link says.
 *
 * Core makes the same call in `labelDiscussionLinks.ts:22` before it turns a
 * discussion address into a label, and the two have to agree: a link core
 * decided to leave alone is one this can still speak for. Both the written
 * `href` and the resolved one count, because a writer who typed `example.com`
 * meant the address just as much as one who typed the scheme. The destination
 * counts too, since a rewritten `href` matches neither and the destination is
 * the text in the first place.
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
 * Whether the address is the only thing its paragraph has to say. If it is, the
 * card stands in for it; if it is part of a sentence, the sentence keeps its
 * link and the card follows the paragraph.
 */
function isSoleContent(link: HTMLAnchorElement, block: HTMLElement): boolean {
  if ((block.textContent ?? '').trim() !== (link.textContent ?? '').trim()) return false;

  // Text is not the whole of it: a picture beside the address contributes none
  // of it, and hiding the link would leave the picture standing alone. One
  // inside the link goes wherever the link goes, which is what core's labelled
  // discussion links are made of.
  return Array.from(block.querySelectorAll(EMBEDDED)).every((element) => link.contains(element));
}
