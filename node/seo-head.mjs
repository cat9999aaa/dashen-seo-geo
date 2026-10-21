export const PUBLIC_ORIGIN = 'https://dashen.wang';
export const ARTICLE_PATH = /^\/(?:[a-z]{2}(?:-[a-z]+)?\/)?article\/[1-9][0-9]{0,9}$/;
const publicUrl = value => {
  try { const u = new URL(value); return u.origin === PUBLIC_ORIGIN && !u.username && !u.password && !u.search && !u.hash ? u.href : ''; }
  catch { return ''; }
};
export const encodeJson = value => JSON.stringify(value).replace(/</g, '\\u003c').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
function absoluteIds(value) {
  if (!value || typeof value !== 'object') return;
  for (const [key, child] of Object.entries(value)) {
    if (key === '@id' && typeof child === 'string' && /^\/(?!\/)/.test(child)) value[key] = PUBLIC_ORIGIN + child;
    else if (child && typeof child === 'object') absoluteIds(child);
  }
}
function repairGraph(value, siteName) {
  if (!Array.isArray(value?.['@graph'])) return;
  const graph = value['@graph'];
  const publisherId = PUBLIC_ORIGIN + '/#organization';
  let needsPublisher = false;
  // Normalize entities and their references together before checking graph links.
  for (const node of graph) absoluteIds(node);
  for (const node of graph) {
    if (node?.['@type'] === 'Organization' && node['@id'] === publisherId && node.name === 'UME' && siteName) node.name = siteName;
    if (!['Article','TechArticle'].includes(node?.['@type'])) continue;
    if (Array.isArray(node.articleSection)) {
      node.articleSection = node.articleSection.filter(x => typeof x === 'string' && x.trim());
      if (!node.articleSection.length) delete node.articleSection;
    }
    if (node.publisher?.['@id'] === publisherId) needsPublisher = true;
    if (node.mainEntity?.['@id'] && !graph.some(x => x?.['@id'] === node.mainEntity['@id'])) delete node.mainEntity;
  }
  if (needsPublisher && siteName && !graph.some(x => x?.['@id'] === publisherId)) {
    graph.push({'@type':'Organization','@id':publisherId,name:siteName,url:PUBLIC_ORIGIN});
  }
}

/** Transform only the site's native SEO tags; the caller owns the request-scoped head. */
export function repairSeoTags(input, {path='/', locale=''} = {}) {
  const canonical = input.find(t => t.tag === 'link' && t.props?.rel === 'canonical')?.props?.href;
  if (!publicUrl(canonical)) return input;
  const tags = input.map(t => ({...t, props:{...t.props}}));
  const siteName = tags.find(t => t.tag === 'meta' && t.props.property === 'og:site_name')?.props.content;
  const localizedHome = /^[a-z]{2}(?:-[a-z]+)?$/.test(locale) && path === '/' + locale;
  for (const tag of tags) {
    if (localizedHome && tag.tag === 'link' && tag.props.rel === 'canonical') tag.props.href = PUBLIC_ORIGIN + path;
    if (localizedHome && tag.tag === 'meta' && tag.props.property === 'og:url') tag.props.content = PUBLIC_ORIGIN + path;
    if (tag.tag === 'script' && tag.props.type === 'application/ld+json' && !tag.props.id) {
      try { const value=JSON.parse(tag.innerHTML); repairGraph(value, siteName); tag.innerHTML=encodeJson(value); } catch { /* Preserve invalid or unrelated custom code. */ }
    }
  }
  return tags;
}

/** Use a normal head entry so Unhead owns deduplication and SSR hydration identity. */
export function translationHeadLinks(metadata,path) {
  if(metadata?.path!==path||publicUrl(metadata.canonical)!==PUBLIC_ORIGIN+path||!Array.isArray(metadata.alternates))return [];
  const links=metadata.alternates.filter(a=>/^(?:[a-z]{2}(?:-[A-Za-z0-9]{2,8})*|x-default)$/.test(a?.hreflang)&&publicUrl(a?.href));
  if(links.length!==metadata.alternates.length||links.length<2||!links.some(a=>a.href===metadata.canonical))return [];
  if(new Set(links.map(a=>a.hreflang)).size!==links.length)return [];
  return links.map(a=>({rel:'alternate',hreflang:a.hreflang,href:a.href}));
}

export function unwrapHeadMetadata(response) {
  let value = response?.data?.guaqi?.common?.module?.dashenSeoHead ?? response?.guaqi?.common?.module?.dashenSeoHead;
  try { return typeof value === 'string' ? JSON.parse(value) : value || null; } catch { return null; }
}
