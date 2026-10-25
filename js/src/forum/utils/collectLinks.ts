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

export default function collectPreviewTargets(body: HTMLElement): PreviewTarget[] {
  const max = Math.max(1, app.forum.attribute<number | undefined>('datlechin-link-preview.maxPreviewsPerPost') ?? 5);
  const skipMedia = app.forum.attribute<boolean | undefined>('datlechin-link-preview.skipMediaLinks') ?? false;
  const internalAllowed = app.forum.attribute<boolean | undefined>('datlechin-link-preview.previewInternalLinks') ?? true;

  const budget = max - body.querySelectorAll(CARD).length;

  if (budget <= 0) return [];

  const targets: PreviewTarget[] = [];

  for (const link of Array.from(body.querySelectorAll('a'))) {
    if (targets.length >= budget) break;

    const target = targetFor(link, body, skipMedia, internalAllowed);

    if (target !== null) targets.push(target);
  }

  return targets;
}

function targetFor(link: HTMLAnchorElement, body: HTMLElement, skipMedia: boolean, internalAllowed: boolean): PreviewTarget | null {
  if (link.matches(HANDLED) || link.matches(MENTIONS)) return null;

  if (link.closest(EXCLUDED_ANCESTOR) !== null) return null;

  const destination = destinationOf(link);

  if (destination === null) return null;

  if (skipMedia && MEDIA.test(destination.pathname)) return null;

  if (!allowsUrl(destination.href)) return null;

  const block = blockOf(link, body);

  if (block === body) return null;

  const alone = ownsItsLine(link, block);
  const internal = isInternal(destination);
  const url = destination.href;

  if (internal) {
    if (!internalAllowed) return null;

    if (link.matches(DISCUSSION)) {
      return alone ? { link, url, internal, block, mode: 'replace' } : null;
    }
  }

  if (link.matches(DISCUSSION) || !textIsTheAddress(link, destination)) return null;

  return { link, url, internal, block, mode: alone ? 'replace' : 'after' };
}

function destinationOf(link: HTMLAnchorElement): URL | null {
  return discussionDestination(link) ?? rewrittenDestination(link) ?? httpUrl(link.getAttribute('href') ?? '', document.baseURI);
}

function discussionDestination(link: HTMLAnchorElement): URL | null {
  if (!link.matches(DISCUSSION)) return null;

  const id = (link.querySelector(DISCUSSION_ID)?.textContent ?? '').trim().replace(/^#/, '');

  if (!/^\d+$/.test(id)) return null;

  return httpUrl(`${window.location.origin}${basePath()}/d/${id}`);
}

function rewrittenDestination(link: HTMLAnchorElement): URL | null {
  if (link.matches(INTERNAL)) return null;

  const href = httpUrl(link.getAttribute('href') ?? '', document.baseURI);

  if (href === null || href.origin !== window.location.origin) return null;

  const text = httpUrl((link.textContent ?? '').trim());

  if (text === null || text.origin === window.location.origin) return null;

  return text;
}

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

function isInternal(url: URL): boolean {
  if (url.origin !== window.location.origin) return false;

  const base = basePath();

  if (base && url.pathname !== base && !url.pathname.startsWith(base + '/')) return false;

  return true;
}

function basePath(): string {
  return app.forum.attribute<string | undefined>('basePath') || '';
}

function textIsTheAddress(link: HTMLAnchorElement, destination: URL): boolean {
  const text = trimSlash((link.textContent ?? '').trim());

  if (text === '') return false;

  return text === trimSlash((link.getAttribute('href') ?? '').trim()) || text === trimSlash(link.href) || text === trimSlash(destination.href);
}

function trimSlash(value: string): string {
  return value.replace(/\/$/, '');
}

function blockOf(link: HTMLAnchorElement, body: HTMLElement): HTMLElement {
  let node = link.parentElement;

  while (node !== null && node !== body) {
    if (BLOCKS.has(node.tagName)) return node;

    node = node.parentElement;
  }

  return body;
}

function ownsItsLine(link: HTMLAnchorElement, block: HTMLElement): boolean {
  const line = lineOf(link, block);

  if (line.length === 0) return false;

  const text = line
    .map((node) => node.textContent ?? '')
    .join('')
    .trim();

  if (text !== (link.textContent ?? '').trim()) return false;

  return line.every((node) => embeddedIn(node).every((element) => link.contains(element)));
}

function lineOf(link: HTMLAnchorElement, block: HTMLElement): Node[] {
  const nodes = Array.from(block.childNodes);
  const index = nodes.findIndex((node) => node.contains(link));

  if (index === -1) return [];

  let start = index;
  let end = index;

  while (start > 0 && nodes[start - 1].nodeName !== 'BR') start--;

  while (end < nodes.length - 1 && nodes[end + 1].nodeName !== 'BR') end++;

  return nodes.slice(start, end + 1);
}

function embeddedIn(node: Node): Element[] {
  if (!(node instanceof Element)) return [];

  const within = Array.from(node.querySelectorAll(EMBEDDED));

  return node.matches(EMBEDDED) ? [node, ...within] : within;
}
