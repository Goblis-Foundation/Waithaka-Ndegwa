/* Keep the current destination visible in horizontally scrolling navigation. */
(()=>{'use strict';
 const selector='.workspace-section-tabs,.comm-tabs,.pub-nav,.wise-tabs,.public-explore-strip nav,.personal-page-nav,.goal-tabs,.editor-side-tabs';
 document.querySelectorAll(selector).forEach(rail=>{
  function reveal(item){
   if(!item||rail.scrollWidth<=rail.clientWidth)return;
   const outer=rail.getBoundingClientRect(),inner=item.getBoundingClientRect(),gap=8;
   if(inner.left<outer.left+gap)rail.scrollLeft-=outer.left+gap-inner.left;
   else if(inner.right>outer.right-gap)rail.scrollLeft+=inner.right-outer.right+gap;
  }
  function current(){reveal(rail.querySelector('[aria-selected="true"],[aria-current="page"]'));}
  current();
  rail.addEventListener('focusin',event=>{if(rail.contains(event.target))reveal(event.target);});
  new MutationObserver(current).observe(rail,{subtree:true,attributes:true,attributeFilter:['aria-selected','aria-current']});
  if(typeof ResizeObserver!=='undefined')new ResizeObserver(current).observe(rail);
 });
})();
