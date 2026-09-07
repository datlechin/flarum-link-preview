import bootstrapForum from '@flarum/jest-config/src/bootstrap/forum';
import app from 'flarum/forum/app';

import matchesList, { allowsUrl, parseList } from '../../src/forum/utils/matchesList';

/**
 * The same rules the server applies, applied a request earlier.
 *
 * These cases are the browser half of
 * `tests/unit/Preview/MatchesTheAllowAndBlockListsTest.php` and are kept in step
 * with it deliberately. Where the two sides disagree the server still wins, so a
 * blocklist mismatch costs a wasted request; an allowlist mismatch costs the
 * reader a card that should have been there, which is why the entry a rule is
 * written as gets as much attention here as the address it is matched against.
 */

beforeAll(() => {
  bootstrapForum();

  app.forum = app.store.createRecord('forums');
});

function lists(allowlist: string, blocklist: string): void {
  app.forum.pushData({
    id: '1',
    type: 'forums',
    attributes: {
      'datlechin-link-preview.allowlist': allowlist,
      'datlechin-link-preview.blocklist': blocklist,
    },
  });
}

const blocks = (entry: string, url: string) => matchesList(url, [entry]);

describe('an empty list', () => {
  it('matches nothing at all', () => {
    expect(matchesList('https://example.com/article', [])).toBe(false);
    expect(matchesList('http://192.0.2.10:8080/page?x=1', [])).toBe(false);
    expect(matchesList('https://любой.example/статья', [])).toBe(false);
  });
});

describe('a host entry', () => {
  it.each([
    ['covers the host itself', 'https://example.com'],
    ['covers it with a path', 'https://example.com/blog/post'],
    ['covers it with a query', 'https://example.com/search?q=flarum'],
    ['covers it over plain http', 'http://example.com/'],
    ['covers it with the www prefix the entry did not have', 'https://www.example.com/'],
    ['covers a subdomain', 'https://cdn.example.com/card.png'],
    ['covers a subdomain of a subdomain', 'https://a.b.example.com/x'],
    ['covers it in capitals', 'https://EXAMPLE.COM/X'],
  ])('%s', (_name, url) => {
    expect(blocks('example.com', url)).toBe(true);
  });

  it.each([
    ['does not cover another domain entirely', 'https://example.org/article'],
    ['does not cover a host that merely ends the same way', 'https://notexample.com/article'],
    ['does not cover a host that starts with it', 'https://example.com.evil.test/article'],
    ['does not cover the name in a path', 'https://other.test/go?to=example.com'],
  ])('%s', (_name, url) => {
    expect(blocks('example.com', url)).toBe(false);
  });
});

describe('an entry', () => {
  /**
   * The regression the rewrite exists for. A substring match read the entry as
   * text to find anywhere in the address, so a forum blocking `com` also lost
   * every link whose path said "comments".
   */
  it('no longer matches wherever it appears in the url', () => {
    expect(blocks('com', 'https://welcome.test/page')).toBe(false);
    expect(blocks('com', 'https://example.org/comments')).toBe(false);
    expect(blocks('com', 'https://uncommon.net/')).toBe(false);
  });

  /**
   * A single label is a legal host, so `com` covers every `.com` address under
   * the subdomain rule above. The PHP side records the same result under
   * `a_bare_entry_is_still_read_as_a_host`, and the two agreeing is the point.
   */
  it('written as a bare label is still read as a host', () => {
    expect(blocks('com', 'https://example.com/x')).toBe(true);
  });

  /**
   * The other regression. Anchoring only the front let `evil.com` match any
   * host that carried it as a prefix, which is a domain an attacker registers.
   */
  it('is not matched by a name that merely begins with it', () => {
    expect(blocks('evil.com', 'https://notevil.com.example.org/a')).toBe(false);
    expect(blocks('evil.com', 'https://notevil.com/a')).toBe(false);
    expect(blocks('evil.com', 'https://evil.com/a')).toBe(true);
    expect(blocks('evil.com', 'https://mail.evil.com/a')).toBe(true);
  });

  it('written as a url means the same as a host', () => {
    expect(blocks('HTTPS://WWW.Example.COM/', 'http://example.com/page')).toBe(true);
    expect(blocks('HTTPS://WWW.Example.COM/', 'https://www.example.com')).toBe(true);
    expect(blocks('HTTPS://WWW.Example.COM/', 'https://example.org/page')).toBe(false);
  });

  it('that is empty matches nothing', () => {
    // A trailing comma in the settings field is not a rule to block the whole
    // forum with.
    expect(matchesList('https://example.com/a', ['', '   '])).toBe(false);
  });
});

describe('an entry carrying more than a host', () => {
  /**
   * `subjectOf` reads the address through `URL`, so what it compares against
   * never has userinfo, a port or a root dot on it. An entry stripped by hand
   * kept all three and matched no address at all, which in an allowlist is a
   * preview the reader should have had and silently did not.
   *
   * The PHP reads entries through `parse_url`, which drops the same three, so
   * these also keep the two sides agreeing.
   */
  it('is read as the host an administrator meant', () => {
    expect(blocks('example.com:8443', 'https://example.com/a')).toBe(true);
    expect(blocks('someone@example.com', 'https://example.com/a')).toBe(true);
    expect(blocks('example.com.', 'https://example.com/a')).toBe(true);
  });

  it('still covers subdomains once the extra parts are gone', () => {
    expect(blocks('example.com:8443', 'https://docs.example.com/a')).toBe(true);
    expect(blocks('example.com.', 'https://docs.example.com/a')).toBe(true);
  });

  it('keeps a path entry a path entry', () => {
    expect(blocks('example.com:8443/news', 'https://example.com/news/today')).toBe(true);
    expect(blocks('example.com:8443/news', 'https://example.com/sport')).toBe(false);
  });

  it('matches nothing when there is no host left to read', () => {
    expect(blocks('   ', 'https://example.com/a')).toBe(false);
    expect(blocks('/', 'https://example.com/a')).toBe(false);
  });
});

describe('the host', () => {
  it.each([
    ['is read past a username in front of it', 'https://someone@example.com/a'],
    ['is read past a username and a password', 'https://someone:hunter2@example.com/a'],
    ['is read past something that looks like a host as the username', 'https://example.org@example.com/a'],
    ['is read past a port after it', 'https://example.com:8443/a'],
    ['is read past the default port written out', 'https://example.com:443/a'],
    ['is read as the fully qualified name it really is', 'https://example.com./a'],
    ['is read fully qualified and on a port', 'https://example.com.:8443/a'],
    ['is read fully qualified under a subdomain', 'https://cdn.example.com./a'],
    ['is read past a username and a port together', 'https://someone@example.com:8443/a'],
  ])('%s', (_name, url) => {
    // Userinfo, a port and a trailing dot all reach the same site, so a filter
    // that string-matched the text would be walked past by typing.
    expect(blocks('example.com', url)).toBe(true);
  });

  it('is never read out of the username', () => {
    // This address goes to example.com, so a filter reading the userinfo as a
    // host would refuse the wrong site.
    expect(blocks('tracker.test', 'https://tracker.test@example.com/a')).toBe(false);
  });
});

describe('a path entry', () => {
  it('matches only at a path boundary', () => {
    expect(blocks('example.com/blog', 'https://example.com/blog')).toBe(true);
    expect(blocks('example.com/blog', 'https://example.com/blog/')).toBe(true);
    expect(blocks('example.com/blog', 'https://example.com/blog/post')).toBe(true);
    expect(blocks('example.com/blog', 'https://example.com/blog?page=2')).toBe(true);

    expect(blocks('example.com/blog', 'https://example.com/blogger')).toBe(false);
    expect(blocks('example.com/blog', 'https://example.com/')).toBe(false);
    expect(blocks('example.com/blog', 'https://example.com/news/blog')).toBe(false);
  });

  it('is matched against the path and nothing after it', () => {
    // A query string is where somebody else's address ends up and a fragment
    // never reaches the server, so neither is part of a rule.
    expect(blocks('example.com/blog', 'https://example.com/blog#introduction')).toBe(true);
    expect(blocks('example.com/blog', 'https://example.com/blog/post#introduction')).toBe(true);

    expect(blocks('example.com/blog', 'https://example.com/search?q=/blog')).toBe(false);
    expect(blocks('example.com/blog', 'https://example.com/news#/blog')).toBe(false);
  });

  it('is matched in one case', () => {
    // An administrator writing a rule cannot know which case a link will
    // arrive in, and a host is already case insensitive.
    expect(blocks('example.com/Blog', 'https://example.com/blog/post')).toBe(true);
    expect(blocks('example.com/Blog', 'https://EXAMPLE.com/BLOG')).toBe(true);
  });

  it('does not reach into subdomains', () => {
    // A path only means something on the host it was written for, so this
    // entry is not the shorthand for a site that a bare host entry is.
    expect(blocks('example.com/blog', 'https://cdn.example.com/blog')).toBe(false);
  });
});

describe('a wildcard', () => {
  it('in the host does not cross a dot', () => {
    expect(blocks('evil*.test', 'https://evilcorp.test/a')).toBe(true);
    expect(blocks('evil*.test', 'https://evil123.test/a')).toBe(true);
    expect(blocks('evil*.test', 'https://evil.co.test/a')).toBe(false);
    expect(blocks('evil*.test', 'https://notevil.test/a')).toBe(false);
  });

  it('standing as a whole label still covers what is under it', () => {
    expect(blocks('*.example.com', 'https://cdn.example.com/a')).toBe(true);
    expect(blocks('*.example.com', 'https://a.b.example.com/a')).toBe(true);
    expect(blocks('*.example.com', 'https://example.com/a')).toBe(false);
  });

  it('in a path may cross a slash', () => {
    expect(blocks('example.com/*/edit', 'https://example.com/posts/1/edit')).toBe(true);
    expect(blocks('example.com/*/edit', 'https://example.com/edit')).toBe(false);
    expect(blocks('example.com/*/edit', 'https://example.com/posts/1/editor')).toBe(false);
  });

  it('leaves the pattern anchored at both ends', () => {
    expect(blocks('*.example.com', 'https://cdn.example.com.evil.test/a')).toBe(false);
    expect(blocks('*.example.com', 'https://prefixcdn.example.org/a')).toBe(false);
  });
});

describe('an address the browser cannot parse', () => {
  /**
   * The server parses with `parse_url`, which reads `//host/path` out of a
   * relative reference; `new URL` refuses one outright. Refusing is the safer
   * half of the disagreement only under an allowlist, and that is the case
   * asserted here.
   */
  it('is never claimed to match anything', () => {
    expect(blocks('example.com', '/relative/path')).toBe(false);
    expect(blocks('example.com', 'not a url')).toBe(false);
    expect(blocks('example.com', '')).toBe(false);
  });

  it('is not asked about when an allowlist is in force', () => {
    lists('example.com', '');

    expect(allowsUrl('/relative/path')).toBe(false);
  });
});

describe('reading the two lists off the forum', () => {
  it('lets everything through when neither is set', () => {
    lists('', '');

    expect(allowsUrl('https://example.com/a')).toBe(true);
    expect(allowsUrl('https://elsewhere.test/a')).toBe(true);
  });

  it('shuts out everything an allowlist does not name', () => {
    lists('example.com\ndocs.other.test', '');

    expect(allowsUrl('https://example.com/a')).toBe(true);
    expect(allowsUrl('https://cdn.example.com/a')).toBe(true);
    expect(allowsUrl('https://docs.other.test/a')).toBe(true);
    expect(allowsUrl('https://other.test/a')).toBe(false);
    expect(allowsUrl('https://elsewhere.test/a')).toBe(false);
  });

  it('lets the blocklist carve holes in the allowlist', () => {
    lists('example.com', 'secret.example.com, example.com/admin');

    expect(allowsUrl('https://example.com/blog/post')).toBe(true);
    expect(allowsUrl('https://secret.example.com/a')).toBe(false);
    expect(allowsUrl('https://example.com/admin/users')).toBe(false);
    expect(allowsUrl('https://elsewhere.test/a')).toBe(false);
  });
});

describe('parsing a list an administrator typed', () => {
  it('accepts one entry per line or a comma between them', () => {
    expect(parseList('example.com\nother.test')).toEqual(['example.com', 'other.test']);
    expect(parseList('example.com, other.test')).toEqual(['example.com', 'other.test']);
    expect(parseList('example.com,\nother.test')).toEqual(['example.com', 'other.test']);
  });

  it('drops the blank runs that trailing punctuation leaves behind', () => {
    expect(parseList('')).toEqual([]);
    expect(parseList('  \n , \n ')).toEqual([]);
    expect(parseList('example.com,')).toEqual(['example.com']);
    expect(parseList('  example.com  ,,  other.test  ')).toEqual(['example.com', 'other.test']);
  });
});
