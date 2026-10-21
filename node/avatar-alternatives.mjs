// Native Element Plus avatars outside CommonAvatar bypass plugin replacements.
// They duplicate adjacent names or accessible controls and are decorative.
export function ensureAvatarAlternatives(root) {
  for (const img of root.querySelectorAll('.el-avatar > img:not([alt])')) img.setAttribute('alt', '');
}
const observers = new WeakMap();
export function observeAvatarAlternatives(doc) {
  let entry = observers.get(doc);
  if (!entry) {
    const sync = () => ensureAvatarAlternatives(doc);
    const observer = new doc.defaultView.MutationObserver(sync);
    observer.observe(doc.documentElement, { childList: true, subtree: true });
    sync();
    entry = { observer, count: 0 };
    observers.set(doc, entry);
  }
  entry.count++;
  return () => {
    if (--entry.count === 0) { entry.observer.disconnect(); observers.delete(doc); }
  };
}
