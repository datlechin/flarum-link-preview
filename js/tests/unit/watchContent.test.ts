/**
 * @jest-environment-options {"url": "https://f.test/"}
 */

import bootstrapForum from '@flarum/jest-config/src/bootstrap/forum';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import m from 'mithril';

import LinkPreview from '../../src/forum/extenders/LinkPreview';
import commentBodies from '../../src/forum/utils/commentBodies';
import { topLevelElements } from '../../src/forum/utils/watchContent';

const LINK = '<a href="https://x.test/a">https://x.test/a</a>';

class Article extends Component<{ html: string }> {
  view() {
    return [m('h1.Article-title', 'Title'), m('div.Article-body', m.trust(this.attrs.html))];
  }
}

class Untouched extends Component<{ html: string }> {
  view() {
    return m('div.Article-body', m.trust(this.attrs.html));
  }
}

let root: HTMLElement;

beforeAll(() => {
  bootstrapForum();

  app.request = (() => new Promise(() => undefined)) as any;

  app.session = { user: null } as any;

  new LinkPreview().content(Article, '.Article-body').extend(app, { name: 'acme-articles', exports: {} });
});

beforeEach(() => {
  app.forum = app.store.createRecord('forums');
  app.forum.pushData({ id: '1', type: 'forums', attributes: { apiUrl: 'https://f.test/api' } });

  root = document.createElement('div');
  document.body.append(root);
});

afterEach(() => {
  m.render(root, null);
  root.remove();
});

describe('content another extension registers', () => {
  it('gets a card in the element its selector names', () => {
    m.render(root, m(Article, { html: `<p>${LINK}</p>` }));

    expect(root.querySelector('.Article-body .LinkPreview-container')).not.toBeNull();
    expect(root.querySelector('.Article-body a.LinkPreview-source')).not.toBeNull();
  });

  it('gets one again after a redraw brings a new link in', () => {
    m.render(root, m(Article, { html: '<p>Nothing yet.</p>' }));
    m.render(root, m(Article, { html: `<p>${LINK}</p>` }));

    expect(root.querySelectorAll('.LinkPreview-container')).toHaveLength(1);
  });

  it('is not given a second card for a link that already has one', () => {
    m.render(root, m(Article, { html: `<p>${LINK}</p>` }));
    m.render(root, m(Article, { html: `<p>${LINK}</p>` }));

    expect(root.querySelectorAll('.LinkPreview-container')).toHaveLength(1);
  });

  it('leaves a component nobody registered alone', () => {
    m.render(root, m(Untouched, { html: `<p>${LINK}</p>` }));

    expect(root.querySelector('.LinkPreview-container')).toBeNull();
  });
});

describe('the top level of a component', () => {
  it('is every node it rendered, when it rendered more than one', () => {
    let elements: HTMLElement[] = [];

    m.render(root, m({ view: () => [m('header'), m('div.Post-body')], oncreate: (vnode: any) => (elements = topLevelElements(vnode)) }));

    expect(elements.map((element) => element.tagName)).toEqual(['HEADER', 'DIV']);
  });
});

describe('the body of a comment', () => {
  function commentVnode(html: string, hidden = ''): any {
    root.innerHTML = `<article class="Post ${hidden}"><header class="Post-header"></header><div class="Post-body">${html}</div></article>`;

    return { dom: root.querySelector('.Post-header'), domSize: 2 };
  }

  it('is the `.Post-body` beside its header', () => {
    const bodies = commentBodies.call({ attrs: { isEditing: false } } as any, commentVnode(`<p>${LINK}</p>`));

    expect(bodies.map((body) => body.className)).toEqual(['Post-body']);
  });

  it('is left alone while the post is being edited', () => {
    expect(commentBodies.call({ attrs: { isEditing: true } } as any, commentVnode(`<p>${LINK}</p>`))).toEqual([]);
  });

  it('is left alone while a deleted post is collapsed', () => {
    expect(commentBodies.call({ attrs: { isEditing: false } } as any, commentVnode(`<p>${LINK}</p>`, 'Post--hidden'))).toEqual([]);
  });

  it('is read once the reader opens that deleted post', () => {
    const vnode = commentVnode(`<p>${LINK}</p>`, 'Post--hidden revealContent');

    expect(commentBodies.call({ attrs: { isEditing: false } } as any, vnode)).toHaveLength(1);
  });
});
