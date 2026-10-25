export type PreviewLayout = 'large' | 'compact';
export interface PreviewImage {
    url: string;
    width: number | null;
    height: number | null;
}
export type MetaItem = {
    type: 'text' | 'user';
    text: string;
} | {
    type: 'count';
    key: string;
    count: number;
} | {
    type: 'date';
    date: string;
};
export interface PreviewSuccess {
    url: string;
    type: string;
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
export type PreviewState = {
    status: 'loading';
} | {
    status: 'ready';
    data: PreviewSuccess;
} | {
    status: 'failed';
    code: string;
};
