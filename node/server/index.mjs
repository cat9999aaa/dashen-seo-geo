// GuaQi serverSource exports query/action registrations, not a Nitro plugin.
// The official SDK supplies SSR components from manifest.componentReplacements.
// Do not register render:html here: the host never invokes such a hook.
export default {};
