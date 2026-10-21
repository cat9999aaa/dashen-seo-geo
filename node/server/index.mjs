// GuaQi serverSource exports query/action registrations, not a Nitro plugin.
// The official SDK supplies SSR components from manifest.componentReplacements.
// Do not register render:html here: the host never invokes such a hook.
export const actions = {
  'plugin.dashen.seo.head': ({path} = {}) => {
    if (typeof path !== 'string' || !/^\/(?:[a-z]{2}(?:-[a-z]+)?\/)?article\/[1-9][0-9]{0,9}$/.test(path)) {
      throw new TypeError('Invalid public article path');
    }
    return `query DashenSeoHead { guaqi { common { module { dashenSeoHead(path:${JSON.stringify(path)}) } } } }`;
  },
};
export default { actions };
