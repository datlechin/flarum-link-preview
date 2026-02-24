export interface LinkPreviewData {
  title?: string;
  description?: string;
  site_name?: string;
  image?: string;
  error?: string;
}

export type LinkPreviewCallback = (data: LinkPreviewData) => void;
