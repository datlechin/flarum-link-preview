import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import batchManager from '../utils/batch-manager';
import type { LinkPreviewData } from '../types';
import type Mithril from 'mithril';

interface LinkPreviewAttrs {
  link: HTMLAnchorElement;
  openLinksInNewTab: boolean;
  useGoogleFavicons: boolean;
}

export default class LinkPreview extends Component<LinkPreviewAttrs> {
  loading!: boolean;
  link!: HTMLAnchorElement;
  linkAttributes!: { [key: string]: string };
  linkClasses!: string;
  data!: LinkPreviewData | null;
  useGoogleFavicons!: boolean;
  imageError!: boolean;

  oninit(vnode: Mithril.Vnode<LinkPreviewAttrs, this>) {
    super.oninit(vnode);

    const attrs = vnode.attrs;

    this.loading = true;
    this.link = attrs.link;
    this.linkAttributes = Object.assign({}, ...Array.from(this.link.attributes, ({ name, value }) => ({ [name]: value })));
    this.linkClasses = (this.linkAttributes.class || '').replace(/\bLinkPreview-captured\b/g, '').trim();
    this.linkAttributes.target = attrs.openLinksInNewTab ? '_blank' : '_self';
    delete this.linkAttributes.class;
    delete this.linkAttributes.style;
    this.data = null;
    this.useGoogleFavicons = attrs.useGoogleFavicons;
    this.imageError = false;

    this.fetchData();
  }

  view() {
    if (this.loading) {
      return this.viewLoading();
    }

    if (this.data?.error) {
      return this.viewError();
    }

    return this.viewLoaded();
  }

  viewLoading() {
    return (
      <div className={classList('LinkPreview', 'LinkPreview--loading')}>
        <div className="LinkPreview-image">
          <LoadingIndicator display="unset" size="small" />
        </div>
        <div className="LinkPreview-main">
          <div className="LinkPreview-title">{this.getLink(this.getDomain())}</div>
          <div className="LinkPreview-domain">
            {this.useGoogleFavicons ? <img src={this.getFavicon()} alt="" /> : <Icon name="fas fa-external-link-alt" />}
            {this.getLink(this.getDomain())}
          </div>
        </div>
      </div>
    );
  }

  viewError() {
    return (
      <div className={classList('LinkPreview', 'LinkPreview--error')}>
        <div className="LinkPreview-main">
          <div className="LinkPreview-title">{this.getLink(this.getDomain())}</div>
          <div className="LinkPreview-description LinkPreview-description--error">{this.data!.error}</div>
          <div className="LinkPreview-domain">
            {this.useGoogleFavicons ? <img src={this.getFavicon()} alt="" /> : <Icon name="fas fa-external-link-alt" />}
            {this.getLink(this.getDomain())}
          </div>
        </div>
      </div>
    );
  }

  viewLoaded() {
    const image = this.getImage();
    const showImage = image && !this.imageError;
    const description = this.data?.description;

    return (
      <div className={classList('LinkPreview', 'LinkPreview--loaded')}>
        {showImage ? (
          <div className="LinkPreview-image">
            <img
              src={image}
              alt=""
              loading="lazy"
              onError={() => {
                this.imageError = true;
                m.redraw();
              }}
            />
          </div>
        ) : null}
        <div className="LinkPreview-main">
          <div className="LinkPreview-title">{this.getLink(this.data?.title ?? this.getDomain())}</div>
          {description ? <div className="LinkPreview-description">{description}</div> : null}
          <div className="LinkPreview-domain">
            {this.useGoogleFavicons ? <img src={this.getFavicon()} alt="" /> : <Icon name="fas fa-external-link-alt" />}
            {this.getLink(this.data?.site_name ?? this.getDomain())}
          </div>
        </div>
      </div>
    );
  }

  getLink(text?: string) {
    return (
      <a {...this.linkAttributes} className={classList('LinkPreview-link', this.linkClasses)}>
        {text ?? this.getDomain()}
      </a>
    );
  }

  getHref() {
    return this.link.href;
  }

  getDomain() {
    return this.getHref().split('/')[2];
  }

  getImage() {
    return this.data?.image || null;
  }

  getFavicon() {
    return this.useGoogleFavicons ? `https://www.google.com/s2/favicons?sz=64&domain_url=${this.getDomain()}` : null;
  }

  fetchData() {
    batchManager.add(this.getHref(), (data: LinkPreviewData) => {
      this.data = data;
      this.loading = false;
      m.redraw();
    });
  }
}
