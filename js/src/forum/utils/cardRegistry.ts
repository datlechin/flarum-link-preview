import LinkPreviewCard from '../components/LinkPreviewCard';
import type { PreviewTarget } from './collectLinks';
import { releasePreview, retainPreview } from './previewStore';

/**
 * Every card on the page, and the undoing of it.
 *
 * A card is a Mithril root mounted into post HTML Mithril does not own.
 * `m.mount` subscribes the target and never unsubscribes it, so a discarded
 * root is redrawn for the life of the tab, and one edited character replaces
 * the whole `.Post-body` subtree, core rendering it from `m.trust(contentHtml)`
 * which Mithril diffs by string compare. Hence three defences: the sweep, the
 * removal hook, and `m.mount(wrapper, null)` in both, the only call that
 * actually cancels the subscription.
 */

/** What has to be put back if the card is taken away again. */
interface Card {
  link: HTMLAnchorElement;
  url: string;
}

const mounted = new Map<HTMLElement, Card>();

export function mountCard(target: PreviewTarget): void {
  const { link, url, internal, block, mode } = target;

  // Marked first, so a redraw landing mid-mount cannot collect this anchor
  // again. `collectPreviewTargets` skips anchors carrying the attribute, which
  // is what keeps the pass idempotent across `onupdate`.
  link.setAttribute('data-link-preview', '');

  const wrapper = document.createElement('span');

  // A `<span>` because the wrapper often lands inside a `<p>`, where a `<div>`
  // makes the browser close the paragraph. The stylesheet gives it `display: block`.
  wrapper.className = 'LinkPreview-container';
  wrapper.setAttribute('data-link-preview', '');

  // Always inside a block of the post, never a sibling of a top level child of
  // `.Post-body`: Mithril removes exactly as many top level nodes as
  // `m.trust(contentHtml)` produced, so one extra there and it removes the
  // wrong ones, stranding a mounted root in a detached subtree the
  // `isConnected` sweep can never see.
  if (mode === 'replace') {
    link.classList.add('LinkPreview-source');
    link.before(wrapper);
  } else {
    block.append(wrapper);
  }

  mounted.set(wrapper, { link, url });

  // Held while the card is on screen so the store cannot evict the answer it is
  // drawn from and strand it in a skeleton.
  retainPreview(url);

  m.mount(wrapper, { view: () => m(LinkPreviewCard, { link, url, internal }) });
}

export function sweepDetachedCards(): void {
  for (const wrapper of Array.from(mounted.keys())) {
    if (!wrapper.isConnected) unmount(wrapper);
  }
}

/** Called while the post's DOM is still attached, so the wrappers can be found. */
export function unmountCardsWithin(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('.LinkPreview-container').forEach(unmount);
}

/** Restores the plain links, so turning previews off does not need a reload. */
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
