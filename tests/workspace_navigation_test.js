'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
function fixture(hash=''){
 const listeners={},timers=[],list={hidden:true,setAttribute(){}},names=['plan','publish','media','seo'];
 const make=(name,key)=>({dataset:{[key]:name},attrs:{},hidden:false,tabIndex:0,parentElement:list,classList:{toggle(){}},setAttribute(k,v){this.attrs[k]=v;},addEventListener(k,fn){this[k]=fn;},focus(){this.focused=true;},contains(el){return el.panel===this;}});
 const tabs=names.map(n=>make(n,'editorTab')),panels=names.map(n=>make(n,'editorPanel')),form={addEventListener(k,fn,capture){assert.equal(capture,true);this[k]=fn;}},location={hash};
 const context={document:{querySelectorAll:s=>s==='[data-editor-tab]'?tabs:panels,querySelector:()=>form},location,history:{state:{keep:1},replaceState(state,unused,hash){assert.equal(state.keep,1);location.hash=hash;}},addEventListener(k,fn){listeners[k]=fn;},setTimeout(fn){timers.push(fn);}};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/js/editor-tabs.js'),'utf8'),context);
 return {tabs,panels,form,list,location,listeners,timers};
}
let f=fixture('#editor-panel-seo');
assert.equal(f.list.hidden,false);assert.equal(f.tabs[3].attrs['aria-selected'],'true');assert.deepEqual(f.panels.map(p=>p.hidden),[true,true,true,false]);
let prevented=false;f.tabs[3].keydown({key:'ArrowRight',preventDefault(){prevented=true;}});
assert.ok(prevented);assert.equal(f.tabs[0].focused,true);assert.equal(f.location.hash,'#editor-panel-plan');
f.tabs[0].keydown({key:'End',preventDefault(){}});assert.equal(f.tabs[3].attrs['aria-selected'],'true');
f.tabs[3].keydown({key:'ArrowDown',preventDefault(){throw Error('Vertical scrolling intercepted');}});
f.location.hash='#editor-panel-media';f.listeners.hashchange();assert.equal(f.panels[2].hidden,false);
const field={panel:f.panels[1],focus(){this.focused=true;}},later={panel:f.panels[2],focus(){this.focused=true;}};
f.form.invalid({target:field});f.form.invalid({target:later});
assert.equal(f.panels[1].hidden,false);assert.equal(f.panels[2].hidden,true);f.timers.shift()();assert.equal(field.focused,true);assert.equal(later.focused,undefined);
f.form.invalid({target:later});assert.equal(f.panels[2].hidden,false);f.timers.shift()();
f=fixture('#unrelated-anchor');assert.equal(f.panels[0].hidden,false);
const source=fs.readFileSync(path.join(__dirname,'../views/write/editor.php'),'utf8');
assert.ok(!/data-editor-panel="[^"]+" hidden/.test(source),'No-JS settings must remain available');
let observer,resize,focusin;const item={getBoundingClientRect:()=>({left:250,right:330})};
const rail={scrollWidth:400,clientWidth:100,scrollLeft:0,getBoundingClientRect:()=>({left:0,right:100}),querySelector:()=>item,contains:()=>true,addEventListener(k,fn){focusin=fn;}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/js/navigation-rails.js'),'utf8'),{
 document:{querySelectorAll:()=>[rail]},
 MutationObserver:class{constructor(fn){observer=fn;}observe(){}},
 ResizeObserver:class{constructor(fn){resize=fn;}observe(){}}
});
assert.equal(rail.scrollLeft,238,'Current item revealed horizontally');
rail.scrollWidth=100;rail.scrollLeft=0;observer();resize();focusin({target:item});assert.equal(rail.scrollLeft,0,'Non-overflow rail must not move');
console.log('PASS: editor deep links, keyboard, hidden-field validation, fallback and horizontal navigation');
