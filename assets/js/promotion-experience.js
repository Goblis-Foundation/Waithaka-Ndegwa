/* Progressive enhancement only. Native POST/CSRF and server policies remain authoritative. */
(() => {
  'use strict';
  const form = document.querySelector('[data-promote-form]');
  if (!form) return;
  const panels = Array.from(form.querySelectorAll('[data-promote-step]'));
  const nav = form.querySelector('[data-promote-navigation]');
  const controls = form.querySelector('[data-promote-controls]');
  const back = form.querySelector('[data-promote-back]');
  const next = form.querySelector('[data-promote-next]');
  const review = form.querySelector('[data-promote-review]');
  if (panels.length !== 5 || !nav || !controls || !back || !next || !review) return;
  const field = name => form.elements.namedItem(name);
  let current = 0;
  let submitting = false;
  const file = field('evidence');
  const fileConsent = field('evidence_ack');
  function attachments() {
    const selected = file?.files?.[0];
    if (!file || !fileConsent) return;
    let problem = '';
    if (selected && selected.size > 4 * 1024 * 1024) problem = 'Choose a file no larger than 4 MB.';
    else if (selected && (!/\.(?:jpe?g|png|pdf)$/i.test(selected.name) || (selected.type && !['image/jpeg', 'image/png', 'application/pdf'].includes(selected.type)))) problem = 'Choose one JPEG, PNG or PDF.';
    file.setCustomValidity(problem);
    fileConsent.required = Boolean(selected);
    form.querySelector('[data-promote-file-status]').textContent = selected ? `${selected.name} · ${(selected.size / 1024).toFixed(0)} KB. ${problem || 'Selected locally; not uploaded yet.'}` : 'No file selected.';
    form.querySelector('[data-promote-remove-file]').hidden = !selected;
  }
  function rules() {
    attachments();
    form.querySelectorAll('input[required]:not([type="checkbox"]):not([type="radio"]):not([type="file"]),textarea[required]').forEach(input => {
      input.setCustomValidity(input.value.trim() ? '' : 'Please complete this field.');
    });
    ['purchase_url', 'source_url'].forEach(name => {
      const input = field(name);
      if (!input || !input.value.trim()) return;
      let valid = false;
      try {
        const url = new URL(input.value.trim());
        valid = url.protocol === 'https:' && !url.username && !url.password && (!url.port || url.port === '443');
      } catch (_) { /* Report a useful field error; the server performs the stricter public-link checks. */ }
      input.setCustomValidity(valid ? '' : 'Use a complete public HTTPS link, without a username or password.');
    });
  }
  function invalidIn(index) {
    return Array.from(panels[index].querySelectorAll('input,textarea,select')).find(input => input.willValidate && !input.validity.valid);
  }
  function summaryRow(list, label, text) {
    const item = document.createElement('div');
    const term = document.createElement('dt');
    const detail = document.createElement('dd');
    term.textContent = label;
    detail.textContent = text || 'Not supplied';
    item.append(term, detail);
    list.append(item);
  }
  function planSummary() {
    const selected = form.querySelector('input[name="package"]:checked');
    if (!selected) return;
    const plan = selected.dataset;
    const updates = {name: plan.planName, price: plan.planPrice, rounds: `${plan.planRounds} round${plan.planRounds === '1' ? '' : 's'} on two agreed accounts`, posts: plan.planPosts, duration: plan.planDuration};
    Object.entries(updates).forEach(([key, text]) => { form.querySelector(`[data-selection-${key}]`).textContent = text; });
    form.querySelectorAll('.promote-package').forEach(card => {
      const checked = card.querySelector('input').checked;
      card.classList.toggle('is-selected', checked);
      card.querySelector('.promote-package-choice').textContent = checked ? 'Selected package' : 'Select this package';
    });
  }
  function renderReview() {
    review.replaceChildren();
    panels.slice(0, 4).forEach((panel, index) => {
      const section = document.createElement('section');
      const header = document.createElement('div');
      const heading = document.createElement('h4');
      const edit = document.createElement('button');
      const list = document.createElement('dl');
      heading.textContent = panel.querySelector('h3').textContent;
      edit.type = 'button';
      edit.className = 'promote-link-button';
      edit.dataset.promoteGo = String(index);
      edit.textContent = 'Edit';
      edit.setAttribute('aria-label', `Edit ${heading.textContent}`);
      header.append(heading, edit);
      if (index === 0) {
        const plan = form.querySelector('input[name="package"]:checked')?.dataset;
        if (plan) {
          summaryRow(list, 'Preferred package', plan.planName);
          summaryRow(list, 'Listed price', `${plan.planPrice} — exact quotation follows`);
          summaryRow(list, 'Scope', `1 sponsored article; ${plan.planRounds} rounds; ${plan.planPosts} individual social posts. ${plan.planDuration}.`);
        }
      } else {
        panel.querySelectorAll('.promote-field input:not([type="file"]),.promote-field textarea').forEach(input => {
          const label = input.closest('label').querySelector('span').textContent.replace(/\s*(?:optional|\*)\s*$/, '').trim();
          summaryRow(list, label, input.value.trim());
        });
        if (index === 3) summaryRow(list, 'Private attachment', file?.files?.[0]?.name || 'None selected');
      }
      section.append(header, list);
      review.append(section);
    });
    review.hidden = false;
  }
  function show(index, focus = true) {
    current = Math.max(0, Math.min(panels.length - 1, index));
    panels.forEach((panel, i) => { panel.hidden = i !== current; });
    nav.querySelectorAll('[data-promote-go]').forEach(button => {
      if (Number(button.dataset.promoteGo) === current) button.setAttribute('aria-current', 'step');
      else button.removeAttribute('aria-current');
    });
    const label = panels[current].querySelector('h3');
    form.querySelector('[data-promote-progress]').textContent = `Step ${current + 1} of ${panels.length}: ${label.textContent.replace(/^\d+\.\s*/, '')}`;
    form.querySelector('[data-promote-meter]').value = current + 1;
    back.hidden = current === 0;
    next.hidden = current === panels.length - 1;
    next.textContent = current === panels.length - 2 ? 'Review my brief' : 'Continue';
    planSummary();
    if (current === panels.length - 1) renderReview();
    if (focus) label.focus();
  }
  function validateThrough(last) {
    rules();
    for (let i = 0; i <= last; i++) {
      const input = invalidIn(i);
      if (input) { show(i, false); input.focus(); input.reportValidity(); return false; }
    }
    return true;
  }
  function go(index) {
    if (submitting || !Number.isInteger(index) || index < 0 || index >= panels.length) return;
    if (index > current && !validateThrough(index - 1)) return;
    show(index);
  }
  form.addEventListener('click', event => {
    const control = event.target.closest('[data-promote-go]');
    if (control) go(Number(control.dataset.promoteGo));
  });
  back.addEventListener('click', () => go(current - 1));
  next.addEventListener('click', () => go(current + 1));
  form.querySelector('[data-promote-remove-file]').addEventListener('click', () => { file.value = ''; fileConsent.checked = false; attachments(); });
  form.addEventListener('input', event => {
    if (typeof event.target.setCustomValidity === 'function') event.target.setCustomValidity('');
    if (event.target.name === 'package') planSummary();
  });
  form.addEventListener('change', event => {
    if (event.target.name === 'package') planSummary();
    if (event.target === file) attachments();
  });
  // Enter advances ordinary fields; it never sends from an earlier step or steals textarea/newline keys.
  form.addEventListener('keydown', event => {
    if (event.key === 'Enter' && !event.isComposing && current < panels.length - 1 && event.target.matches('input:not([type="file"]):not([type="checkbox"]):not([type="radio"])')) {
      event.preventDefault(); go(current + 1);
    }
  });
  form.addEventListener('submit', event => {
    if (submitting) { event.preventDefault(); return; }
    if (current !== panels.length - 1) { event.preventDefault(); go(current + 1); return; }
    if (!validateThrough(panels.length - 1)) { event.preventDefault(); return; }
    submitting = true;
    form.setAttribute('aria-busy', 'true');
    form.querySelector('[data-promote-submit]').disabled = true;
    form.querySelector('[data-promote-send-status]').textContent = 'Sending your brief. Wait for the website receipt before submitting again.';
  });
  window.addEventListener('pageshow', () => {
    submitting = false;
    form.removeAttribute('aria-busy');
    form.querySelector('[data-promote-submit]').disabled = false;
    form.querySelector('[data-promote-send-status]').textContent = '';
    planSummary(); attachments();
  });
  nav.hidden = false;
  controls.hidden = false;
  form.querySelector('[data-promote-selection]').hidden = false;
  form.noValidate = true;
  show(form.querySelector('[data-promote-server-error]') ? 4 : 0, false);
  attachments();
})();
