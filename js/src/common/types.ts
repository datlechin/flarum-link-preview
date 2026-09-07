export type PreviewLayout = 'large' | 'compact';
export type PreviewType = 'link' | 'discussion' | 'user' | 'tag' | 'forum';

export interface PreviewImage {
  url: string;
  width: number | null;
  height: number | null;
}

/**
 * `key` names a locale string under `datlechin-link-preview.forum.meta`, except
 * for the two shapes that carry their own text or their own formatting.
 */
export type MetaItem = { key: string; text: string } | { key: string; count: number } | { key: string; date: string };

export interface PreviewSuccess {
  url: string;
  type: PreviewType;
  layout: PreviewLayout;
  title: string | null;
  description: string | null;
  siteName: string | null;
  favicon: string | null;
  image: PreviewImage | null;
  meta?: MetaItem[];
}

export interface PreviewFailure {
  url: string;
  error: string;
}

export type PreviewData = PreviewSuccess | PreviewFailure;

export type PreviewState = { status: 'loading' } | { status: 'ready'; data: PreviewSuccess } | { status: 'failed'; code: string };
