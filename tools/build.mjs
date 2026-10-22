import {join,resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
const client=process.env.GUAQI_CLIENT_ROOT;
if(!client)throw new Error('Set GUAQI_CLIENT_ROOT to the existing licensed native client.');
const {buildRuntimePlugin}=await import(pathToFileURL(join(resolve(client),'scripts/runtime-plugin-sdk.mjs')).href);
console.log(await buildRuntimePlugin(resolve(import.meta.dirname,'..')));
