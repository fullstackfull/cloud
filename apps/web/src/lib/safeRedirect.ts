/**
 * Narrows a post-sign-in destination to somewhere inside this application.
 *
 * The sign-in page is the natural home of an open redirect: a link that lands a
 * customer on the real login form and then bounces them to an attacker's copy
 * is convincing precisely because the first hop was genuine. Only a path is
 * ever honoured - never a scheme, never a host, never a protocol-relative URL -
 * so the value cannot leave the origin however it was constructed.
 */
export function safeRedirect(candidate: unknown, fallback = '/'): string {
  if (typeof candidate !== 'string' || candidate === '') return fallback

  // A backslash is treated as a path separator by several browsers, so
  // "/\\evil.example" and a UNC-style path both navigate off-origin.
  if (candidate.includes('\\')) return fallback

  // Control characters are stripped by URL parsers before the scheme is read,
  // which turns a tab-split "java\\tscript:alert(1)" into a javascript: URL.
  // eslint-disable-next-line no-control-regex -- matching them is the point
  if (/[\u0000-\u001f\u007f]/.test(candidate)) return fallback

  // Must be a single-slash absolute path. This rejects "//host", "https://host",
  // "javascript:..." and any bare relative path that could resolve unpredictably.
  if (!candidate.startsWith('/') || candidate.startsWith('//')) return fallback

  return candidate
}
