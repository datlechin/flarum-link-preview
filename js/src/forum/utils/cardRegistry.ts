import LinkPreviewCard from '../components/LinkPreviewCard';
import type { PreviewTarget } from './collectLinks';
import { releasePreview, retainPreview } from './previewStore';

/**
 * Every card on the page, and the undoing of it.
 *
 * A card is a Mithril root of its own, mounted into a wrapper inside post HTML
 * that Mithril does not otherwise own. Two things make cleaning up after that
 * more work than it looks:
 *
 * `m.mount` adds the target to an internal subscription list and nothing ever
 * takes it off again, because Mithril has no reason to check `isConnected`. A
 * root that has been thrown away therefore goes on being redrawn on every
 * redraw of the page, for as long as the tab is open.
 *
 * And a post is thrown away wholesale far more often than it looks: core's
 * `Comment` renders its body with `m.trust(contentHtml)`, which Mithril diffs
 * by comparing the two strings, so one edited character replaces the entire
 * `.Post-body` subtree and orphans everything mounted inside it.
 *
 * Hence three defences rather than one: the sweep for orphans, the removal hook
 * for a post leaving the page, and `m.mount(wrapper, null)` in both, which is
 * the only call that actually cancels the subscription.
 */

/** What has to be put back if the card is taken away again. */
interface Card {
  link: HTMLAnchorElement;
  url: string;
}

const mounted = new Map<HTMLElement, Card>();

export function mountCard(target: PreviewTarget): void {
  const { link, url, internal, block, mode } = target;

  // Marked before anything else, so a redraw that lands mid-mount cannot
  // collect this anchor a second time. `collectPreviewTargets` skips an anchor
  // carrying this attribute, which is what keeps the pass idempotent across the
  // `onupdate` hook it also runs from.
  link.setAttribute('data-link-preview', '');

  const wrapper = document.createElement('span');

  // A `<span>` because the wrapper often ends up inside a `<p>`, where a `<div>`
  // is invalid nesting and the browser closes the paragraph around it. The
  // stylesheet gives it `display: block`.
  wrapper.className = 'LinkPreview-container';
  wrapper.setAttribute('data-link-preview', '');

  // Always inside a block of the post, never a sibling of a top level child of
  // `.Post-body`. Mithril renders the body from `m.trust(contentHtml)` and
  // remembers how many top level nodes that produced; on the next change it
  // removes exactly that many. One extra node there and it removes the wrong
  // ones, leaving a mounted root still connected to a detached subtree that the
  // `isConnected` sweep can never see. `collectPreviewTargets` drops any link
  // that has no such block, so there is nothing to check for here.
  if (mode === 'replace') {
    link.classList.add('LinkPreview-source');
    link.before(wrapper);
  } else {
    block.append(wrapper);
  }

  mounted.set(wrapper, { link, url });

  // Held for as long as the card is on screen, so the store cannot evict the
  // answer this card is drawn from and strand it in a skeleton. The URL is the
  // resolved destination, which is what the card asks the store for as well.
  retainPreview(url);

  m.mount(wrapper, { view: () => m(LinkPreviewCard, { link, url, internal }) });
}

/**
 * Unmount the cards whose post is no longer on the page.
 */
export function sweepDetachedCards(): void {
  for (const wrapper of Array.from(mounted.keys())) {
    if (!wrapper.isConnected) unmount(wrapper);
  }
}

/**
 * Unmount the cards inside a post that is being removed, while its DOM is still
 * attached and can be searched.
 */
export function unmountCardsWithin(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('.LinkPreview-container').forEach(unmount);
}

/**
 * Take the cards back out of a post and give it its plain links back, for a
 * reader who has just turned previews off and should not have to reload the
 * page to see that happen.
 */
export function removeCardsWithin(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('.LinkPreview-container').forEach((wrapper) => {
    const card = mounted.get(wrapper);

    unmount(wrapper);
    wrapper.remove();

    if (!card) return;

    card.link.classList.remove('LinkPreview-source');
    card.link.removeAttribute('data-link-preview');
  });
}

function unmount(wrapper: HTMLElement): void {
  const card = mounted.get(wrapper);

  if (!card) return;

  mounted.delete(wrapper);
  releasePreview(card.url);

  m.mount(wrapper, null);
}
