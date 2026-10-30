(()=>{
  'use strict';
  document.querySelectorAll('[data-location-fields]').forEach(root=>{
    if(root.dataset.locationReady)return;
    root.dataset.locationReady='1';
    const country=root.querySelector('[data-location-country]');
    const kenya=root.querySelector('[data-location-kenya]');
    const international=root.querySelector('[data-location-international]');
    const label=root.querySelector('[data-location-city-label]');
    const city=root.querySelector('[name="city"]');
    if(!country||!kenya||!international)return;
    function update(){
      const ke=country.value.trim().toLowerCase()==='kenya';
      [[kenya,!ke],[international,ke]].forEach(([section,hide])=>{
        section.hidden=hide;
        section.querySelectorAll('input,select').forEach(field=>{field.disabled=hide;if(root.dataset.locationRequired==='1')field.required=!hide;});
      });
      if(label)label.textContent=ke?'Town / Area':'City / Town / Locality';
      if(city)city.autocomplete=ke?'address-level4':'address-level3';
      // The residence country must NEVER overwrite phone_code or an entered phone.
    }
    country.addEventListener('change',update);
    update();
  });
})();
