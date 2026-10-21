import assert from 'node:assert/strict';
import { ensureAvatarAlternatives } from '../node/avatar-alternatives.mjs';
const missing = { attributes: {}, setAttribute(k,v) { this.attributes[k]=v; } };
let selector;
const root = { querySelectorAll(s) { selector=s; return [missing]; } };
ensureAvatarAlternatives(root);
assert.equal(selector, '.el-avatar > img:not([alt])');
assert.equal(missing.attributes.alt, '');
// Explicit author alternatives are excluded by the selector, not overwritten.
assert.doesNotThrow(() => ensureAvatarAlternatives({querySelectorAll:()=>[]}));
console.log('PASS avatar alternatives only target missing attributes');
