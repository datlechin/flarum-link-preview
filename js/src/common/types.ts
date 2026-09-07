export type PreviewLayout = 'large' | 'compact';
export type PreviewType = 'link' | 'discussion';

export interface PreviewImage {
  url: string;
  width: number | null;
  height: number | null;
}

export interface DiscussionInfo {
  id: number;
  commentCount: number;
  participantCount: number;
  author: string | null;
  createdAt: string;
  tags: Array<{ name: string }>;
}

export interface PreviewSuccess {
  url: string;
  type: PreviewType;
  layout: PreviewLayout;
  title: string | null;
  description: string | null;
  siteName: string | null;
  favicon: string | null;
  image: PreviewImage | null;
  discussion?: DiscussionInfo;
}

export interface PreviewFailure {
  url: string;
  error: string;
}

export type PreviewData = PreviewSuccess | PreviewFailure;

export type PreviewState = { status: 'loading' } | { status: 'ready'; data: PreviewSuccess } | { status: 'failed'; code: string };
