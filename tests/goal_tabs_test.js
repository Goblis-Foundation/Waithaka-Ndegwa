'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const code=fs.readFileSync(path.join(__dirname,'../assets/js/goal-tabs.js'),'utf8');
function setup(hash=''){
 const ids=['goal-ventures','goal-incubation','goal-markets'],events={};
 function element(id){return {id,attrs:{},events:{},hidden:false,setAttribute(k,v){this.attrs[k]=v;},getAttribute(k){return this.attrs[k];},addEventListener(k,f){this.events[k]=f;},focus(){this.focused=true;}};}
 const tabs=ids.map(id=>{const e=element(id+'-tab');e.attrs.href='#'+id;return e;}),panels=ids.map(element),list=element('list');
 const root={querySelector:()=>list,querySelectorAll:s=>s==='[data-goal-tab]'?tabs:panels};
 const location={hash},context={document:{querySelectorAll:()=>[root]},location,history:{replaceState(_s,_t,url){location.hash=url;}},addEventListener(k,f){events[k]=f;}};
 vm.runInNewContext(code,context);
 return {tabs,panels,list,events,location};
}
let r=setup();assert.equal(r.list.attrs.role,'tablist');assert.deepEqual(r.panels.map(p=>p.hidden),[false,true,true]);
assert.equal(r.tabs[0].tabIndex,0);assert.equal(r.tabs[1].tabIndex,-1);assert.equal(r.panels[0].attrs['aria-labelledby'],r.tabs[0].id);
let prevented=false;r.tabs[1].events.click({preventDefault(){prevented=true;}});
assert.ok(prevented);assert.equal(r.location.hash,'#goal-incubation');assert.deepEqual(r.panels.map(p=>p.hidden),[true,false,true]);
r.tabs[1].events.keydown({key:'ArrowRight',preventDefault(){}});assert.ok(r.tabs[2].focused);assert.equal(r.tabs[2].attrs['aria-selected'],'true');
r.tabs[2].events.keydown({key:'ArrowRight',preventDefault(){}});assert.equal(r.tabs[0].attrs['aria-selected'],'true');
r.tabs[0].events.keydown({key:'End',preventDefault(){}});assert.equal(r.tabs[2].attrs['aria-selected'],'true');
r.tabs[2].events.keydown({key:'Home',preventDefault(){}});assert.equal(r.tabs[0].attrs['aria-selected'],'true');
prevented=false;r.tabs[0].events.keydown({key:'ArrowDown',preventDefault(){prevented=true;}});assert.equal(prevented,false);
r=setup('#goal-markets');assert.deepEqual(r.panels.map(p=>p.hidden),[true,true,false]);
r.location.hash='#goal-incubation';r.events.hashchange();assert.equal(r.tabs[1].attrs['aria-selected'],'true');
console.log('Goal tabs: keyboard, deep links, selection and panel visibility passed.');
