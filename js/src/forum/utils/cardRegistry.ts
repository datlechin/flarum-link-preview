import LinkPreviewCard from '../components/LinkPreviewCard';
import type { PreviewTarget } from './collectLinks';
import { loadPreview, previewFor, releasePreview, retainPreview } from './previewStore';

interface Card {
  link: HTMLAnchorElement;
  url: string;
}

const mounted = new Map<HTMLElement, Card>();

export function mountCard(target: PreviewTarget): void {
  const { link, url, internal, block, mode } = target;

  link.setAttribute('data-link-preview', '');

  const wrapper = document.createElement('span');

  wrapper.className = 'LinkPreview-container';
  wrapper.setAttribute('data-link-preview', '');

  if (mode === 'replace') {
    link.classList.add('LinkPreview-source');
    link.before(wrapper);
  } else {
    block.append(wrapper);
  }

  mounted.set(wrapper, { link, url });

  retainPreview(url);

  m.mount(wrapper, { view: () => m(LinkPreviewCard, { link, url, internal }) });

  loadPreview(url, () => dropFailed(wrapper, url));
  dropFailed(wrapper, url);
}

export function sweepDetachedCards(): void {
  for (const wrapper of Array.from(mounted.keys())) {
    if (!wrapper.isConnected) unmount(wrapper);
  }
}

export function unmountCardsWithin(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('.LinkPreview-container').forEach(unmount);
}

export function removeCardsWithin(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('.LinkPreview-container').forEach((wrapper) => {
    takeDown(wrapper)?.link.removeAttribute('data-link-preview');
  });
}

function dropFailed(wrapper: HTMLElement, url: string): void {
  if (previewFor(url).status !== 'failed') return;

  takeDown(wrapper);
}

function takeDown(wrapper: HTMLElement): Card | undefined {
  const card = mounted.get(wrapper);

  unmount(wrapper);
  wrapper.remove();

  card?.link.classList.remove('LinkPreview-source');

  return card;
}

function unmount(wrapper: HTMLElement): void {
  const card = mounted.get(wrapper);

  if (!card) return;

  mounted.delete(wrapper);
  releasePreview(card.url);

  m.mount(wrapper, null);
}
