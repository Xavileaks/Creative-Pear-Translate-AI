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
  let allRunning = false;
  let allStopped = false;
  let allJob = null;
  let siteRunning = false;
  let siteStopped = false;
  let siteJob = null;
  let readingSingle = false;
  const siteButton = document.getElementById('cptai-read-site');
  const siteStop = document.getElementById('cptai-site-stop');
  const siteStatus = document.getElementById('cptai-site-status');
  const siteProgress = document.getElementById('cptai-site-progress');
  const allButton = document.getElementById('cptai-all');
  const allStop = document.getElementById('cptai-all-stop');
  const allStatus = document.getElementById('cptai-all-status');
  const allProgress = document.getElementById('cptai-all-progress');
  const message = text => { status.textContent = text; };
  function toast(text, error = false) {
    clearTimeout(toastTimer);
    toastText.textContent = text;
    toastBox.classList.toggle('is-error', error);
    toastBox.hidden = false;
    if (!error) toastTimer = setTimeout(() => { toastBox.hidden = true; }, 4500);
  }
  document.getElementById('cptai-toast-close').addEventListener('click', () => { toastBox.hidden = true; });
  async function request(action, values = {}, signal, resolvePlan = true) {
    const body = values instanceof FormData ? new FormData() : new URLSearchParams(values);
    if (values instanceof FormData) for (const [key, value] of values) body.append(key, value);
    body.set('action', `cptai_${action}`);
    body.set('nonce', config.nonce);
    const response = await fetch(config.ajax, {method: 'POST', credentials: 'same-origin', body, signal});
    let result;
    try { result = await response.json(); }
    catch { throw new Error('La respuesta no es válida. Tu edición se conserva; recarga para renovar la sesión si el problema continúa.'); }
    if (!result.success) throw Object.assign(new Error(result.data?.message || 'No se pudo completar la petición. Tu edición se conserva.'), {code:result.data?.code});
    if (resolvePlan && result.data?.plan_ticket) {
      const ticket = result.data.plan_ticket;
      const deadline = Date.now() + 155000;
      while (Date.now() < deadline) {
        await new Promise(resolve => setTimeout(resolve, 1100));
        const next = await request('plan_result', {ticket}, signal, false);
        if (!next.plan_waiting) return next;
      }
      throw new Error('La petición al plan caducó. Comprueba que el asistente sigue abierto.');
    }
    return result.data;
  }
  const snapshot = data => JSON.stringify([...data].sort((a, b) => a[0].localeCompare(b[0]) || String(a[1]).localeCompare(String(b[1]))));
  const rows = new Map();
  const displayText = text => {
    text = text.replace(/\r\n?/g, '\n');
    return /\[cptai_(?:br|tag)_\d+\]/.test(text) ? text.replace(/\[cptai_tag_\d+\]/g, '').replace(/[ \t]*\[cptai_br_\d+\][ \t]*/g, '\n').split('\n').map(line => line.trim()).join('\n').trim() : text;
  };
  let rowRevision = 0;
  const dictionaryResults = document.getElementById('cptai-dictionary-results');
  const filterForm = document.querySelector('.cptai-filters');
  const filterStatus = document.getElementById('cptai-filter-status');
  const draftStatus = document.getElementById('cptai-draft-status');
  const dirtyRows = () => [...rows.values()].filter(row => row.field.value !== row.baseline);
  function updateDraftStatus() {
    const count = dirtyRows().length;
    draftStatus.hidden = !count;
    draftStatus.textContent = `${count} ${count === 1 ? 'edición sin guardar' : 'ediciones sin guardar'}. Se conservan al buscar, ordenar o cambiar de página; guarda antes de salir.`;
  }
  function bindRow(form) {
    const field = form.querySelector('textarea');
    const button = form.querySelector('[type="submit"]');
    const card = form.closest('.cptai-string-row');
    const badge = form.querySelector('.cptai-row-status');
    const id = form.elements.id.value;
    const state = {form, field, button, card, badge, baseline: field.value, saving: false, suggesting: false, changedAt: 0};
    state.update = () => {
      const dirty = field.value !== state.baseline;
      button.disabled = state.saving || !dirty;
      button.textContent = state.saving ? 'Guardando…' : 'Guardar';
      card.classList.toggle('is-dirty', dirty);
      badge.textContent = state.saving ? 'Guardando…' : dirty ? 'Sin guardar' : state.baseline ? 'Guardada' : 'Pendiente';
      updateDraftStatus();
    };
    field.addEventListener('input', state.update);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (state.saving || field.value === state.baseline) return;
      const submitted = field.value;
      const body = new FormData(form);
      body.set('expected', state.baseline);
      if (state.suggestion && displayText(state.suggestion) === submitted.trim()) body.set('formatted', state.suggestion);
      state.saving = true;
      state.update();
      try {
        const result = await request('save_string', body);
        state.baseline = result.text;
        state.changedAt = ++rowRevision;
        if (field.value === submitted) field.value = result.text;
        toast(result.translated ? 'Traducción guardada en todas sus apariciones.' : 'Se ha restablecido el texto original.');
      } catch (error) { toast(error.message, true); }
      finally { state.saving = false; state.update(); }
    });
    const aiButton = form.querySelector('.cptai-row');
    aiButton.disabled = !config.ready || allRunning;
    aiButton.addEventListener('click', async () => {
      if (state.suggesting) return;
      state.suggesting = true;
      aiButton.disabled = true;
      const previous = field.value;
      try {
        const result = await request('row', {id});
        if (field.value !== previous) { toast('Se conserva tu edición: el texto cambió mientras la IA respondía.'); return; }
        state.suggestion = result.text;
        field.value = displayText(result.text);
        state.update();
        toast('Sugerencia preparada. Revísala y guarda la traducción.');
      } catch (error) { toast(error.message, true); }
      finally { state.suggesting = false; aiButton.disabled = !config.ready || allRunning; }
    });
    rows.set(id, state);
    state.update();
    return state;
  }
  function bindResults(startedRevision = rowRevision) {
    for (const form of dictionaryResults.querySelectorAll('.cptai-string-form')) {
      const cached = rows.get(form.elements.id.value);
      if (cached && (cached.changedAt > startedRevision || cached.saving || cached.suggesting || cached.field.value !== cached.baseline)) {
        form.closest('.cptai-string-row').replaceWith(cached.card);
      } else {
        bindRow(form);
      }
    }
    updateDraftStatus();
  }
  bindResults();
  let filterTimer;
  let filterController;
  let filterRevision = 0;
  function scheduleFilter(page = 1, delay = 0) {
    clearTimeout(filterTimer);
    filterController?.abort();
    const revision = ++filterRevision;
    filterTimer = setTimeout(async () => {
      const controller = new AbortController();
      filterController = controller;
      const input = new FormData(filterForm);
      input.set('target', document.getElementById('cptai-filter-target').value);
      const startedRevision = rowRevision;
      input.set('paged', page);
      dictionaryResults.setAttribute('aria-busy', 'true');
      filterStatus.textContent = 'Buscando cadenas…';
      try {
        const result = await request('dictionary', input, controller.signal);
        if (revision !== filterRevision) return;
        dictionaryResults.innerHTML = result.html;
        bindResults(startedRevision);
        filterStatus.textContent = `${result.total} ${result.total === 1 ? 'coincidencia' : 'coincidencias'}.`;
        const url = new URL(window.location.href);
        for (const key of ['target', 's', 'pending', 'sort', 'paged']) url.searchParams.delete(key);
        url.searchParams.set('target', result.target);
        if (result.search) url.searchParams.set('s', result.search);
        if (result.pending) url.searchParams.set('pending', '1');
        url.searchParams.set('sort', result.sort);
        if (result.page > 1) url.searchParams.set('paged', result.page);
        history.replaceState(null, '', url);
      } catch (error) {
        if (revision !== filterRevision || error.name === 'AbortError') return;
        filterStatus.textContent = 'No se pudo actualizar. Puedes volver a intentarlo; tus ediciones se conservan.';
        toast(error.message, true);
      } finally {
        if (revision === filterRevision) dictionaryResults.removeAttribute('aria-busy');
      }
    }, delay);
  }
  document.getElementById('cptai-search').addEventListener('input', () => scheduleFilter(1, 250));
  for (const control of filterForm.querySelectorAll('select, [type="checkbox"]')) {
    control.addEventListener('change', () => scheduleFilter());
  }
  filterForm.addEventListener('submit', event => { event.preventDefault(); scheduleFilter(); });
  dictionaryResults.addEventListener('click', event => {
    const link = event.target.closest('.cptai-pagination a');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    scheduleFilter(Number(new URL(link.href).searchParams.get('paged')) || 1);
  });
  const addForm = document.getElementById('cptai-add-form');
  let adding = false;
  const addDirty = () => [...addForm.querySelectorAll('textarea, input')].some(field => field.value.trim());
  addForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (adding) return;
    const submitted = new FormData(addForm);
    submitted.set('target', document.getElementById('cptai-filter-target').value);
    const button = addForm.querySelector('[type="submit"]');
    const addStatus = document.getElementById('cptai-add-status');
    adding = true;
    button.disabled = true;
    button.textContent = 'Añadiendo…';
    try {
      const result = await request('add_string', submitted);
      for (const field of addForm.querySelectorAll('textarea, input')) {
        if (field.value === submitted.get(field.name)) field.value = '';
      }
      addStatus.textContent = 'Cadena añadida. Se aplicará también a mensajes que aparezcan después de enviar un formulario.';
      toast('Cadena manual añadida.');
      document.getElementById('cptai-filter-target').value = result.target;
      document.getElementById('cptai-search').value = result.original;
      filterForm.querySelector('[name="pending"]').checked = false;
      scheduleFilter();
    } catch (error) { addStatus.textContent = error.message; toast(error.message, true); }
    finally { adding = false; button.disabled = false; button.textContent = 'Añadir cadena'; }
  });
  const imagePanel = document.getElementById('cptai-image-panel');
  const imageForm = document.getElementById('cptai-image-form');
  const imageStatus = document.getElementById('cptai-image-status');
  const imageList = document.getElementById('cptai-image-list');
  const imageCancel = document.getElementById('cptai-image-cancel');
  let imageEdit = null;
  let imageSaving = false;
  let imageRevision = 0;
  function resetImage() {
    imageEdit = null; imageForm.reset(); imageForm.elements.original.readOnly = false;
    imageForm.querySelector('[type="submit"]').textContent = 'Añadir imagen'; imageCancel.hidden = true;
  }
  imageCancel.addEventListener('click', resetImage);
  async function loadImages() {
    const revision = ++imageRevision;
    const target = document.getElementById('cptai-filter-target').value;
    imageStatus.textContent = 'Cargando imágenes…';
    try {
      const result = await request('image_list', {target});
      if (revision !== imageRevision) return;
      imageList.replaceChildren();
      imageStatus.textContent = `${result.rows.length} sustituciones de imagen · ${config.languages[target] || target}.`;
      for (const row of result.rows) {
        const card = document.createElement('div'); card.className = 'cptai-image-item';
        const urls = document.createElement('div'); urls.className = 'cptai-image-urls';
        for (const [label,value] of [['Original',row.original],['Nueva imagen',row.replacement || 'Original restablecida']]) {
          const p = document.createElement('p'); const strong = document.createElement('strong'); strong.textContent = label;
          p.append(strong,document.createTextNode(value)); urls.append(p);
        }
        const actions = document.createElement('div'); actions.className = 'cptai-manual-controls';
        const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'cptai-button'; edit.textContent = 'Editar';
        edit.addEventListener('click', () => {
          if (imageSaving) return;
          imageEdit = {original:row.original, expected:row.replacement, target};
          imageForm.elements.original.value = row.original; imageForm.elements.original.readOnly = true;
          imageForm.elements.replacement.value = row.replacement;
          imageForm.querySelector('[type="submit"]').textContent = 'Guardar imagen'; imageCancel.hidden = false;
          imageForm.elements.replacement.focus();
        });
        const restore = document.createElement('button'); restore.type = 'button'; restore.className = 'cptai-button'; restore.textContent = 'Restablecer original'; restore.disabled = !row.replacement;
        restore.addEventListener('click', async () => {
          if (imageSaving) return;
          imageSaving = true; restore.disabled = true;
          try {
            await request('image_save',{target,original:row.original,replacement:'',expected:row.replacement});
            if (imageEdit?.original === row.original) resetImage();
            toast('Imagen original restablecida.'); await loadImages();
          } catch (error) { toast(error.message,true); restore.disabled = false; }
          finally { imageSaving = false; }
        });
        actions.append(edit,restore); card.append(urls,actions); imageList.append(card);
      }
    } catch (error) { if (revision === imageRevision) { imageStatus.textContent = error.message; toast(error.message,true); } }
  }
  imagePanel.addEventListener('toggle', () => { if (imagePanel.open) loadImages(); });
  document.getElementById('cptai-filter-target').addEventListener('change', () => {
    ++imageRevision; resetImage(); imageList.replaceChildren(); if (imagePanel.open) loadImages();
  });
  imageForm.addEventListener('submit', async event => {
    event.preventDefault(); if (imageSaving) return;
    const submitted = new FormData(imageForm);
    submitted.set('target', imageEdit?.target || document.getElementById('cptai-filter-target').value);
    if (imageEdit) submitted.set('expected',imageEdit.expected);
    const button = imageForm.querySelector('[type="submit"]'); imageSaving = true; button.disabled = true;
    try {
      await request('image_save',submitted);
      if (imageForm.elements.original.value === submitted.get('original') && imageForm.elements.replacement.value === submitted.get('replacement')) resetImage();
      toast('Imagen guardada para todas sus apariciones en este idioma.'); await loadImages();
    } catch (error) { imageStatus.textContent = error.message; toast(error.message,true); }
    finally { imageSaving = false; button.disabled = false; }
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
    settingsButton.disabled = siteRunning || readingSingle || allRunning || running || settingsSaving || !dirty;
    settingsButton.textContent = settingsSaving ? 'Guardando…' : 'Guardar ajustes';
  }
  settingsForm.addEventListener('input', settingsUpdate);
  settingsForm.addEventListener('change', settingsUpdate);
  settingsForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (settingsSaving || siteRunning || readingSingle || allRunning || running) return;
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
      expected.set('chatgpt_model', saved.chatgpt_model);
      if (settingsForm.elements.chatgpt_model.value === submitted.get('chatgpt_model')) settingsForm.elements.chatgpt_model.value = saved.chatgpt_model;
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
        siteJob = null;
        siteButton.textContent = 'Leer todo el sitio';
        document.getElementById('cptai-ai').disabled = true;
        message('Idiomas guardados. Actualiza el diccionario para cargar las cadenas correspondientes.');
      }
      config.provider = saved.provider;
      config.source = saved.source;
      config.targets = saved.targets;
      allJob = null;
      updateReady(saved.ready);
      toast('Ajustes guardados.');
    } catch (error) { toast(error.message, true); }
    finally { settingsSaving = false; settingsUpdate(); }
  });
  settingsUpdate();
  function updateReady(ready) {
    config.ready = Boolean(ready);
    document.getElementById('cptai-connection-hint').hidden = config.ready;
    for (const row of rows.values()) row.form.querySelector('.cptai-row').disabled = row.suggesting || allRunning || !config.ready;
    allButton.disabled = siteRunning || readingSingle || allRunning || running || !config.ready;
    siteButton.disabled = siteRunning || readingSingle || running || allRunning;
    if (!running) document.getElementById('cptai-ai').disabled = siteRunning || readingSingle || allRunning || !job?.pending || !config.ready;
  }
  const planState = document.getElementById('cptai-plan-status');
  const planDisconnect = document.getElementById('cptai-plan-disconnect');
  let checkingPlan = false;
  let planRevision = 0;
  async function checkPlan() {
    if (checkingPlan || document.hidden) return;
    checkingPlan = true;
    const revision = planRevision;
    try {
      const state = await request('plan_status');
      if (revision !== planRevision) return;
      planState.textContent = state.online ? `Asistente conectado${state.account ? ` · ${state.account}` : ''}` : state.linked ? 'Web vinculada · Abre el asistente' : 'Sin vincular';
      planState.classList.toggle('is-online', state.online);
      planDisconnect.disabled = !state.linked;
      if (config.provider === 'chatgpt') updateReady(state.ready);
    } catch { planState.textContent = 'No se pudo comprobar la conexión. Revisa tu sesión de WordPress.'; }
    finally { checkingPlan = false; }
  }
  planDisconnect.addEventListener('click', async () => {
    planDisconnect.disabled = true;
    try {
      await request('plan_disconnect');
      planRevision++;
      planState.textContent = 'Sin vincular';
      planState.classList.remove('is-online');
      if (config.provider === 'chatgpt') updateReady(false);
      toast('Esta web se ha desvinculado. Las claves API se conservan.');
    }
    catch (error) { toast(error.message, true); }
    await checkPlan();
  });
  checkPlan();
  setInterval(checkPlan, 6000);
  window.addEventListener('beforeunload', event => {
    if (siteRunning || readingSingle || running || allRunning || adding || addDirty() || imageSaving || [...imageForm.querySelectorAll('input')].some(field => field.value.trim()) || dirtyRows().length || [...rows.values()].some(row => row.saving || row.suggesting) || snapshot(new FormData(settingsForm)) !== settingsBaseline) {
      event.preventDefault(); event.returnValue = '';
    }
  });
  const fields = () => ({url: document.getElementById('cptai-url').value, target: document.getElementById('cptai-target').value});
  function captureBusy(busy) {
    for (const element of document.querySelectorAll('#cptai-url, #cptai-target, #cptai-content-select, [data-cptai-content]')) element.disabled = busy;
    const empty = !document.getElementById('cptai-url').value;
    document.getElementById('cptai-read').disabled = busy || empty;
    document.getElementById('cptai-scan').disabled = busy || empty;
  }
  let contentRevision = 0;
  let contentType = 'custom';
  let customUrl = document.getElementById('cptai-url').value;
  const contentSelect = document.getElementById('cptai-content-select');
  const contentStatus = document.getElementById('cptai-content-status');
  function resetPageJob() { if (siteRunning || readingSingle || running || allRunning) return; job = null; updateReady(config.ready); }
  contentSelect.addEventListener('change', () => { document.getElementById('cptai-url').value = contentSelect.value; resetPageJob(); });
  document.getElementById('cptai-url').addEventListener('input', resetPageJob);
  document.getElementById('cptai-target').addEventListener('change', () => { resetPageJob(); siteJob = null; siteButton.textContent = 'Leer todo el sitio'; });
  for (const tab of document.querySelectorAll('[data-cptai-content]')) {
    tab.addEventListener('click', async () => {
      if (siteRunning || readingSingle || running || allRunning) return;
      const revision = ++contentRevision;
      if (contentType === 'custom') customUrl = document.getElementById('cptai-url').value;
      contentType = tab.dataset.cptaiContent;
      resetPageJob();
      for (const item of document.querySelectorAll('[data-cptai-content]')) item.setAttribute('aria-pressed', String(item === tab));
      document.getElementById('cptai-custom-url').hidden = contentType !== 'custom';
      document.getElementById('cptai-content-choice').hidden = contentType === 'custom';
      contentStatus.hidden = contentType === 'custom';
      if (contentType === 'custom') {
        document.getElementById('cptai-url').value = customUrl;
        document.getElementById('cptai-read').disabled = false;
        document.getElementById('cptai-scan').disabled = false;
        return;
      }
      document.getElementById('cptai-content-label').textContent = tab.textContent;
      contentSelect.replaceChildren(); contentSelect.disabled = true;
      contentStatus.textContent = 'Buscando contenido publicado de este sitio…';
      document.getElementById('cptai-url').value = '';
      document.getElementById('cptai-read').disabled = true;
      document.getElementById('cptai-scan').disabled = true;
      try {
        const result = await request('content_list', {type:contentType});
        if (revision !== contentRevision) return;
        contentSelect.replaceChildren(...result.items.map(item => { const option = document.createElement('option'); option.value = item.url; option.textContent = item.title; return option; }));
        contentSelect.disabled = siteRunning || readingSingle || running || allRunning || !result.items.length;
        document.getElementById('cptai-url').value = contentSelect.value;
        document.getElementById('cptai-read').disabled = siteRunning || readingSingle || running || allRunning || !result.items.length;
        document.getElementById('cptai-scan').disabled = siteRunning || readingSingle || running || allRunning || !result.items.length;
        contentStatus.textContent = result.items.length ? `${result.items.length} disponibles. Solo se muestra contenido público sin contraseña.` : 'No hay contenido publicado disponible en esta categoría.';
      } catch (error) { if (revision === contentRevision) { contentStatus.textContent = error.message; toast(error.message, true); } }
    });
  }
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
    if (siteRunning || readingSingle || running || allRunning) return;
    readingSingle = true;
    captureBusy(true);
    updateReady(config.ready);
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
    finally { readingSingle = false; captureBusy(false); updateReady(config.ready); settingsUpdate(); }
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
    if (!job || siteRunning || readingSingle || running || allRunning) return;
    running = true; stopped = false;
    captureBusy(true);
    updateReady(config.ready);
    document.getElementById('cptai-ai').disabled = true;
    document.getElementById('cptai-read').disabled = true;
    document.getElementById('cptai-stop').hidden = false;
    let complete = false;
    try {
      while (!stopped) {
        message(config.provider === 'chatgpt' ? 'Traduciendo con tu plan de ChatGPT… Mantén el asistente abierto.' : 'Traduciendo con la API…');
        const result = await request('batch', {job: job.job});
        job.pending = Math.max(0, result.total - result.done);
        message(`${result.done} de ${result.total} cadenas pendientes traducidas.`);
        if (result.complete) { complete = true; message('Traducciones guardadas. Actualiza el diccionario para revisarlas.'); toast('Traducciones guardadas.'); break; }
      }
    } catch (error) { message(error.message); toast(error.message, true); }
    finally {
      running = false;
      updateReady(config.ready);
      document.getElementById('cptai-ai').disabled = complete || !config.ready;
      captureBusy(false);
      document.getElementById('cptai-stop').hidden = true;
    }
  });
  const skipDrafts = () => JSON.stringify([...rows.entries()].filter(([,row]) => row.field.value !== row.baseline || row.saving || row.suggesting).map(([id]) => Number(id)));
  allStop.addEventListener('click', () => {
    allStopped = true;
    allStop.disabled = true;
    allStatus.textContent = 'Se detendrá después de guardar la cadena actual.';
  });
  document.getElementById('cptai-filter-target').addEventListener('change', () => { allJob = null; allButton.textContent = 'Traducir todo'; });
  allButton.addEventListener('click', async () => {
    if (siteRunning || readingSingle || allRunning || running || !config.ready) return;
    const target = document.getElementById('cptai-filter-target').value;
    allRunning = true; allStopped = false;
    updateReady(config.ready);
    allButton.textContent = 'Traduciendo…';
    allStop.hidden = false; allStop.disabled = false;
    allStatus.hidden = false;
    allProgress.hidden = false;
    captureBusy(true);
    document.getElementById('cptai-filter-target').disabled = true;
    settingsButton.disabled = true;
    let complete = false;
    try {
      allStatus.textContent = 'Preparando todas las cadenas pendientes del idioma seleccionado…';
      if (!allJob || allJob.target !== target) allJob = await request('prepare_all', {target, sort:document.getElementById('cptai-sort').value, skip:skipDrafts()});
      allProgress.max = Math.max(1, allJob.total);
      allProgress.value = allJob.total - allJob.pending;
      if (!allJob.pending) complete = true;
      while (!allStopped && !complete) {
        allStatus.textContent = `${allProgress.value} de ${allJob.total} procesadas · Traduciendo y guardando la siguiente cadena…`;
        const result = await request('batch', {job:allJob.job, skip:skipDrafts()});
        allJob.pending = Math.max(0, result.total - result.done);
        allProgress.value = result.done;
        if (result.row) {
          const row = rows.get(String(result.row.id));
          if (row && !row.saving && !row.suggesting) {
            const dirty = row.field.value !== row.baseline;
            row.baseline = displayText(result.row.text);
            if (!dirty) row.field.value = row.baseline;
            row.changedAt = ++rowRevision;
            row.update();
          }
        }
        allStatus.textContent = `${result.done} de ${result.total} procesadas. Cada traducción queda guardada al terminar.`;
        complete = result.complete;
      }
      allStatus.textContent = complete ? 'Diccionario procesado. Se conservaron las traducciones y las ediciones pendientes.' : `${allProgress.value} de ${allJob.total} procesadas. Detenido; lo guardado se conserva.`;
      toast(complete ? 'Todas las traducciones pendientes se han procesado.' : 'Traducción detenida. Puedes continuar cuando quieras.');
      if (complete) allJob = null;
    } catch (error) {
      allStatus.textContent = `Traducción pausada: ${error.message} Lo guardado se conserva.`;
      toast(error.message, true);
      allJob = null;
    } finally {
      allRunning = false;
      captureBusy(false);
      document.getElementById('cptai-filter-target').disabled = false;
      allStop.hidden = true;
      allButton.textContent = allJob ? 'Continuar traducción' : 'Traducir todo';
      settingsUpdate();
      updateReady(config.ready);
      scheduleFilter();
    }
  });
  function showSiteResult(result) {
    siteProgress.max = Math.max(1, result.total);
    siteProgress.value = result.done;
    siteStatus.textContent = `${result.done} de ${result.total} URLs procesadas · ${result.added} cadenas nuevas · ${result.failed} errores${result.skipped ? ` · ${result.skipped} omitidas` : ''}.`;
    const failures = document.getElementById('cptai-site-failures');
    failures.replaceChildren(...result.failures.map(item => {
      const li = document.createElement('li');
      const link = document.createElement('a');
      link.href = item.url; link.textContent = item.url; link.target = '_blank'; link.rel = 'noopener';
      li.append(link, document.createTextNode(` — ${item.message}`));
      return li;
    }));
    if (result.failed > result.failures.length) {
      const li = document.createElement('li'); li.textContent = `Y ${result.failed - result.failures.length} errores más. Puedes volver a leer el sitio para reintentarlos.`; failures.append(li);
    }
    failures.hidden = !result.failed;
  }
  siteStop.addEventListener('click', () => { siteStopped = true; siteStop.disabled = true; siteStatus.textContent = 'Se detendrá después de guardar las cadenas de la página actual.'; });
  siteButton.addEventListener('click', async () => {
    if (siteRunning || readingSingle || running || allRunning) return;
    const target = document.getElementById('cptai-target').value;
    if (!target) { toast('Selecciona y guarda primero un idioma de destino.', true); return; }
    siteRunning = true; siteStopped = false;
    siteButton.textContent = 'Leyendo todo el sitio…';
    captureBusy(true); updateReady(config.ready); settingsUpdate();
    document.getElementById('cptai-site-reading').hidden = false;
    siteStop.hidden = false; siteStop.disabled = false;
    siteStatus.textContent = 'Preparando las páginas, entradas y productos publicados…';
    let complete = false;
    try {
      if (!siteJob || siteJob.target !== target) siteJob = await request('scan_prepare', {target});
      showSiteResult(siteJob);
      complete = siteJob.complete;
      while (!siteStopped && !complete) {
        siteJob = await request('scan_batch', {job:siteJob.job, offset:siteJob.done});
        showSiteResult(siteJob);
        complete = siteJob.complete;
      }
      siteStatus.append(complete ? ' Lectura terminada. Las cadenas están en el diccionario.' : ' Lectura detenida. Lo guardado se conserva.');
      toast(complete ? `Lectura terminada: ${siteJob.added} cadenas nuevas${siteJob.failed ? `, ${siteJob.failed} URLs con error` : ''}.` : 'Lectura detenida. Puedes continuar cuando quieras.', Boolean(siteJob.failed));
      if (complete) siteJob = null;
    } catch (error) {
      siteStatus.textContent = `Lectura pausada: ${error.message} Las cadenas ya guardadas se conservan.`;
      toast(error.message, true);
      if (['changed','job','offset'].includes(error.code)) siteJob = null;
    } finally {
      siteRunning = false;
      siteStop.hidden = true;
      siteButton.textContent = siteJob ? 'Continuar lectura' : 'Leer todo el sitio';
      captureBusy(false); updateReady(config.ready); settingsUpdate();
      scheduleFilter();
    }
  });
})();
