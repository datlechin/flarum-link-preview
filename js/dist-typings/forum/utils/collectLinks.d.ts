export interface PreviewTarget {
    link: HTMLAnchorElement;
    url: string;
    internal: boolean;
    block: HTMLElement;
    mode: 'replace' | 'after';
}
export default function collectPreviewTargets(body: HTMLElement): PreviewTarget[];
