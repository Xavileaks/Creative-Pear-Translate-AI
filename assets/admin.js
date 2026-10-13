(() => {
  'use strict';
  const config = window.CPTAIAdmin;
  const status = document.getElementById('cptai-status');
  const toastBox = document.getElementById('cptai-toast');
  const toastText = document.getElementById('cptai-toast-text');
  let toastTimer;
  let job = null;
  let stopped = false;
  let running = false;
  const message = text => { status.textContent = text; };
  function toast(text, error = false) {
    clearTimeout(toastTimer);
    toastText.textContent = text;
    toastBox.classList.toggle('is-error', error);
    toastBox.hidden = false;
    if (!error) toastTimer = setTimeout(() => { toastBox.hidden = true; }, 4500);
  }
  document.getElementById('cptai-toast-close').addEventListener('click', () => { toastBox.hidden = true; });
  async function request(action, values = {}) {
    const body = values instanceof FormData ? new FormData() : new URLSearchParams(values);
    if (values instanceof FormData) for (const [key, value] of values) body.append(key, value);
    body.set('action', `cptai_${action}`);
    body.set('nonce', config.nonce);
    const response = await fetch(config.ajax, {method: 'POST', credentials: 'same-origin', body});
    let result;
    try { result = await response.json(); }
    catch { throw new Error('La respuesta no es válida. Tu edición se conserva; recarga para renovar la sesión si el problema continúa.'); }
    if (!result.success) throw new Error(result.data?.message || 'No se pudo completar la petición. Tu edición se conserva.');
    return result.data;
  }
  const snapshot = data => JSON.stringify([...data].sort((a, b) => a[0].localeCompare(b[0]) || String(a[1]).localeCompare(String(b[1]))));
  const rows = [...document.querySelectorAll('.cptai-string-form')].map(form => {
    const field = form.querySelector('textarea');
    const button = form.querySelector('[type="submit"]');
    const card = form.closest('.cptai-string-row');
    const badge = form.querySelector('.cptai-row-status');
    const state = {form, field, button, card, badge, baseline: field.value, saving: false};
    state.update = () => {
      const dirty = field.value !== state.baseline;
      button.disabled = state.saving || !dirty;
      button.textContent = state.saving ? 'Guardando…' : 'Guardar';
      card.classList.toggle('is-dirty', dirty);
      badge.textContent = state.saving ? 'Guardando…' : dirty ? 'Sin guardar' : state.baseline ? 'Guardada' : 'Pendiente';
    };
    field.addEventListener('input', state.update);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (state.saving || field.value === state.baseline) return;
      const submitted = field.value;
      const body = new FormData(form);
      body.set('expected', state.baseline);
      state.saving = true;
      state.update();
      try {
        const result = await request('save_string', body);
        state.baseline = result.text;
        if (field.value === submitted) field.value = result.text;
        toast(result.translated ? 'Traducción guardada en todas sus apariciones.' : 'Se ha restablecido el texto original.');
      } catch (error) { toast(error.message, true); }
      finally { state.saving = false; state.update(); }
    });
    state.update();
    return state;
  });
  const settingsForm = document.getElementById('cptai-settings-form');
  for (const link of document.querySelectorAll('#cptai-open-settings, .cptai-settings-link')) {
    link.addEventListener('click', () => { document.getElementById('cptai-settings').open = true; });
  }
  let settingsBaseline = snapshot(new FormData(settingsForm));
  let settingsSaving = false;
  const settingsButton = settingsForm.querySelector('[type="submit"]');
  function settingsUpdate() {
    const dirty = snapshot(new FormData(settingsForm)) !== settingsBaseline;
    settingsButton.disabled = settingsSaving || !dirty;
    settingsButton.textContent = settingsSaving ? 'Guardando…' : 'Guardar ajustes';
  }
  settingsForm.addEventListener('input', settingsUpdate);
  settingsForm.addEventListener('change', settingsUpdate);
  settingsForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (settingsSaving) return;
    const submitted = new FormData(settingsForm);
    settingsSaving = true;
    settingsUpdate();
    try {
      const saved = await request('save_settings_ajax', submitted);
      const expected = new FormData();
      for (const [key, value] of submitted) expected.append(key, value);
      for (const provider of ['openai', 'claude']) {
        const keyField = settingsForm.elements[`${provider}_key`];
        if (keyField.value === submitted.get(`${provider}_key`)) keyField.value = '';
        keyField.placeholder = saved.keys[provider] ? 'Clave guardada' : 'Sin configurar';
        const forget = settingsForm.elements[`forget_${provider}`];
        if (forget.checked === submitted.has(`forget_${provider}`)) forget.checked = false;
        expected.set(`${provider}_key`, '');
        expected.delete(`forget_${provider}`);
        expected.set(`${provider}_model`, saved[`${provider}_model`]);
        const model = settingsForm.elements[`${provider}_model`];
        if (model.value === submitted.get(`${provider}_model`)) model.value = saved[`${provider}_model`];
      }
      expected.set('source', saved.source);
      expected.set('provider', saved.provider);
      expected.delete('targets[]');
      for (const target of saved.targets) expected.append('targets[]', target);
      for (const checkbox of settingsForm.querySelectorAll('[name="targets[]"]')) {
        if (checkbox.checked === submitted.getAll('targets[]').includes(checkbox.value)) {
          checkbox.checked = saved.targets.includes(checkbox.value);
        }
      }
      for (const key of ['footer', 'capture_gettext']) {
        expected.delete(key);
        if (saved[key]) expected.set(key, '1');
      }
      settingsBaseline = snapshot(expected);
      for (const id of ['cptai-target', 'cptai-filter-target']) {
        const select = document.getElementById(id);
        const previous = select.value;
        select.replaceChildren(...saved.targets.map(code => {
          const option = document.createElement('option');
          option.value = code;
          option.textContent = config.languages[code];
          return option;
        }));
        if (saved.targets.includes(previous)) select.value = previous;
      }
      if (config.source !== saved.source || JSON.stringify(config.targets) !== JSON.stringify(saved.targets)) {
        job = null;
        document.getElementById('cptai-ai').disabled = true;
        message('Idiomas guardados. Actualiza el diccionario para cargar las cadenas correspondientes.');
      }
      config.ready = Boolean(saved.keys[saved.provider] && saved[`${saved.provider}_model`]);
      document.getElementById('cptai-connection-hint').hidden = config.ready;
      for (const button of document.querySelectorAll('.cptai-row')) button.disabled = !config.ready;
      toast('Ajustes guardados.');
    } catch (error) { toast(error.message, true); }
    finally { settingsSaving = false; settingsUpdate(); }
  });
  settingsUpdate();
  window.addEventListener('beforeunload', event => {
    if (rows.some(row => row.field.value !== row.baseline) || snapshot(new FormData(settingsForm)) !== settingsBaseline) {
      event.preventDefault(); event.returnValue = '';
    }
  });
  const fields = () => ({url: document.getElementById('cptai-url').value, target: document.getElementById('cptai-target').value});
  for (const button of document.querySelectorAll('.cptai-models')) {
    button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        const models = await request('models', {provider: button.dataset.provider});
        const list = document.getElementById(`cptai-${button.dataset.provider}-models`);
        list.replaceChildren(...models.map(id => { const option = document.createElement('option'); option.value = id; return option; }));
        toast(`${models.length} modelos disponibles. Selecciona uno y guarda los ajustes.`);
      } catch (error) { toast(error.message, true); }
      finally { button.disabled = false; }
    });
  }
  document.getElementById('cptai-read').addEventListener('click', async () => {
    document.getElementById('cptai-read').disabled = true;
    document.getElementById('cptai-ai').disabled = true;
    job = null;
    message('Leyendo las cadenas de la página…');
    try {
      job = await request('prepare', fields());
      message(`${job.total} cadenas detectadas · ${job.added} nuevas · ${job.pending} pendientes. Actualiza el diccionario para editarlas.`);
      document.getElementById('cptai-ai').disabled = !job.pending || !config.ready;
      const preview = document.createElement('a');
      preview.href = job.preview; preview.textContent = 'Ver traducción'; preview.target = '_blank'; preview.rel = 'noopener';
      status.append(' ', preview);
      toast('Cadenas capturadas.');
    } catch (error) { message(error.message); toast(error.message, true); }
    finally { document.getElementById('cptai-read').disabled = false; }
  });
  document.getElementById('cptai-scan').addEventListener('click', async () => {
    const tab = window.open('about:blank', '_blank');
    if (tab) tab.opener = null;
    try {
      const result = await request('scan_link', fields());
      if (tab) tab.location.href = result.url;
      const link = document.createElement('a');
      link.href = result.url; link.textContent = 'Abrir captura'; link.target = '_blank'; link.rel = 'noopener';
      status.replaceChildren(document.createTextNode('Abre menús y desplegables para detectar sus textos. '), link);
    } catch (error) { if (tab) tab.close(); toast(error.message, true); }
  });
  document.getElementById('cptai-stop').addEventListener('click', () => { stopped = true; message('Se detendrá cuando termine el lote actual.'); });
  document.getElementById('cptai-ai').addEventListener('click', async () => {
    if (!job || running) return;
    running = true; stopped = false;
    document.getElementById('cptai-ai').disabled = true;
    document.getElementById('cptai-read').disabled = true;
    document.getElementById('cptai-stop').hidden = false;
    let complete = false;
    try {
      while (!stopped) {
        message('Traduciendo con la API…');
        const result = await request('batch', {job: job.job});
        message(`${result.done} de ${result.total} cadenas pendientes traducidas.`);
        if (result.complete) { complete = true; message('Traducciones guardadas. Actualiza el diccionario para revisarlas.'); toast('Traducciones guardadas.'); break; }
      }
    } catch (error) { message(error.message); toast(error.message, true); }
    finally {
      running = false;
      document.getElementById('cptai-ai').disabled = complete || !config.ready;
      document.getElementById('cptai-read').disabled = false;
      document.getElementById('cptai-stop').hidden = true;
    }
  });
  for (const button of document.querySelectorAll('.cptai-row')) {
    button.disabled = !config.ready;
    button.addEventListener('click', async () => {
      button.disabled = true;
      const field = document.getElementById(`cptai-string-${button.dataset.id}`);
      const previous = field.value;
      try {
        const result = await request('row', {id: button.dataset.id});
        if (field.value !== previous) { toast('Se conserva tu edición: el texto cambió mientras la IA respondía.'); return; }
        field.value = result.text;
        field.dispatchEvent(new Event('input', {bubbles: true}));
        toast('Sugerencia preparada. Revísala y guarda la traducción.');
      } catch (error) { toast(error.message, true); }
      finally { button.disabled = !config.ready; }
    });
  }
})();
