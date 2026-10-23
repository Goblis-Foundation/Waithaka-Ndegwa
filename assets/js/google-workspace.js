'use strict';
(() => {
  const form = document.getElementById('google-drive-import');
  if (!form) return;
  const pick = document.getElementById('google-pick-doc');
  const status = document.getElementById('google-picked-doc');
  const submit = document.getElementById('google-import-doc');
  let loader;
  function loadPicker() {
    if (!loader) loader = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://apis.google.com/js/api.js';
      script.onload = () => window.gapi.load('picker', {callback: resolve, onerror: reject});
      script.onerror = reject;
      document.head.appendChild(script);
    });
    return loader;
  }
  pick.addEventListener('click', async () => {
    pick.disabled = true;
    submit.disabled = true;
    form.elements.file_id.value = '';
    try {
      status.textContent = 'Opening your Google file chooser…';
      const response = await fetch(form.dataset.pickerToken, {method: 'POST', body: new FormData(form), credentials: 'same-origin', cache: 'no-store'});
      if (!response.ok) throw new Error();
      const config = await response.json();
      if (!config.key || !config.token || !/^\d+$/.test(config.appId)) throw new Error();
      await loadPicker();
      const view = new google.picker.DocsView(google.picker.ViewId.DOCUMENTS).setMimeTypes('application/vnd.google-apps.document');
      const picker = new google.picker.PickerBuilder().addView(view).setOAuthToken(config.token).setDeveloperKey(config.key).setAppId(config.appId).setOrigin(window.location.origin).setCallback(data => {
        if (data.action === google.picker.Action.PICKED && data.docs && data.docs.length === 1) {
          form.elements.file_id.value = data.docs[0].id;
          status.textContent = 'Selected: ' + data.docs[0].name;
          submit.disabled = false;
        } else if (data.action === google.picker.Action.CANCEL) status.textContent = 'Selection cancelled. No document was imported.';
      }).build();
      picker.setVisible(true);
      config.token = '';
    } catch (_) { status.textContent = 'Connect and confirm Google Drive, then check the Picker API and restricted browser key in Google preferences.'; }
    finally { pick.disabled = false; }
  });
})();
