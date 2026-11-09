export function enhanceExpertPicker(root) {
    const select = root.querySelector('select');
    if (!select || !root.dataset.url) return;
    const doc = root.ownerDocument;
    const control = doc.createElement('div');
    control.className = 'expert-search-control';
    const inputWrap = doc.createElement('div');
    inputWrap.className = 'expert-search-input-wrap';
    const icon = doc.createElement('i');
    icon.className = 'bi bi-search';
    icon.setAttribute('aria-hidden', 'true');
    const input = doc.createElement('input');
    input.type = 'text';
    input.id = `${select.id}-search`;
    input.className = 'form-control expert-search-input';
    const allowEmpty = root.dataset.allowEmpty === 'true';
    input.placeholder = root.dataset.placeholder || 'Search and select an expert…';
    input.autocomplete = 'off';
    input.maxLength = 150;
    input.required = !allowEmpty;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-describedby', root.dataset.helpId || 'expert-help');
    const clear = doc.createElement('button');
    clear.type = 'button';
    clear.className = 'expert-search-clear';
    clear.textContent = '×';
    clear.setAttribute('aria-label', 'Clear selected expert');
    const panel = doc.createElement('div');
    panel.className = 'expert-search-panel';
    panel.hidden = true;
    const results = doc.createElement('div');
    results.id = `${select.id}-results`;
    results.className = 'expert-search-results';
    results.setAttribute('role', 'listbox');
    results.setAttribute('aria-label', 'Expert search results');
    input.setAttribute('aria-controls', results.id);
    const status = doc.createElement('div');
    status.className = 'expert-search-message';
    status.setAttribute('role', 'status');
    const more = doc.createElement('button');
    more.type = 'button';
    more.className = 'expert-search-more';
    more.textContent = 'Load more experts';
    more.hidden = true;
    const summary = doc.createElement('div');
    summary.className = 'expert-selection-summary';
    summary.setAttribute('aria-live', 'polite');
    inputWrap.append(icon, input, clear);
    panel.append(results, status, more);
    control.append(inputWrap, panel);
    root.append(control, summary);
    const label = root.closest('form')?.querySelector('[data-expert-label]');
    if (label) label.htmlFor = input.id;
    let items = [], active = -1, nextPage = null, timer, controller, sequence = 0, selectedLabel = '';
    const initial = select.selectedOptions[0];
    if (select.value && initial) {
        selectedLabel = initial.textContent.trim();
        input.value = selectedLabel;
        summary.textContent = `Selected: ${selectedLabel}`;
    }
    select.hidden = true;
    select.required = false;
    const validity = () => input.setCustomValidity(select.value || (allowEmpty && input.value.trim() === '') ? '' : 'Search and select an expert from the results.');
    validity();
    const close = () => {
        panel.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };
    const open = () => { panel.hidden = false; input.setAttribute('aria-expanded', 'true'); };
    const setActive = index => {
        active = index;
        [...results.children].forEach((row, i) => row.setAttribute('aria-selected', String(i === active)));
        const row = results.children[active];
        if (row) { input.setAttribute('aria-activedescendant', row.id); row.scrollIntoView?.({block: 'nearest'}); }
        else input.removeAttribute('aria-activedescendant');
    };
    const choose = item => {
        clearTimeout(timer);
        controller?.abort();
        sequence++;
        let option = [...select.options].find(option => option.value === String(item.id));
        if (!option) { option = doc.createElement('option'); option.value = String(item.id); select.append(option); }
        option.textContent = item.label;
        select.value = String(item.id);
        selectedLabel = item.label;
        input.value = selectedLabel;
        summary.textContent = `Selected: ${item.label}${item.specialization ? ` · ${item.specialization}` : ''}`;
        validity(); close();
        select.dispatchEvent(new Event('change', {bubbles: true}));
        input.focus();
    };
    const render = () => {
        results.replaceChildren();
        items.forEach((item, index) => {
            const row = doc.createElement('div');
            row.id = `${select.id}-result-${index}`;
            row.className = 'expert-search-result';
            row.setAttribute('role', 'option');
            row.setAttribute('aria-selected', 'false');
            const name = doc.createElement('span');
            name.className = 'expert-result-name';
            name.textContent = item.name;
            const details = doc.createElement('span');
            details.className = 'expert-result-details';
            details.textContent = [item.type === 'departmental' ? 'Departmental Expert' : 'Expert', item.designation, item.organization, item.specialization].filter(Boolean).join(' · ');
            row.append(name, details);
            row.addEventListener('mousedown', event => event.preventDefault());
            row.addEventListener('click', () => choose(item));
            results.append(row);
        });
        setActive(-1);
    };
    async function search(query, page = 1) {
        controller?.abort();
        controller = new AbortController();
        const token = ++sequence;
        const url = new URL(root.dataset.url, doc.baseURI);
        url.searchParams.set('q', query);
        url.searchParams.set('page', String(page));
        status.textContent = 'Searching experts…';
        more.hidden = true;
        more.disabled = true;
        if (page === 1) { items = []; render(); }
        open();
        try {
            const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            if (!response.ok) throw new Error('Search unavailable');
            const data = await response.json();
            if (token !== sequence) return;
            items = page === 1 ? data.data : [...items, ...data.data];
            nextPage = data.next_page;
            render();
            status.textContent = items.length ? `${items.length} experts shown. Select a result${data.has_more ? ' or load more' : ''}.` : 'No matching experts. Try another name or keyword.';
            more.hidden = !data.has_more;
            more.disabled = false;
        } catch (error) {
            if (error.name === 'AbortError' || token !== sequence) return;
            status.textContent = 'Unable to search. Check your connection and type again to retry.';
            more.disabled = false;
        }
    }
    input.addEventListener('focus', () => { if (panel.hidden) search(select.value ? '' : input.value.trim()); });
    input.addEventListener('input', () => {
        select.value = '';
        selectedLabel = '';
        summary.textContent = '';
        validity();
        clearTimeout(timer);
        // Invalidate pending responses immediately, before the debounced request starts.
        sequence++;
        controller?.abort();
        items = []; render(); more.hidden = true; status.textContent = 'Searching experts…'; open();
        timer = setTimeout(() => search(input.value.trim()), 250);
    });
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') { clearTimeout(timer); sequence++; controller?.abort(); close(); return; }
        if (event.key === 'Tab') { close(); return; }
        if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault();
            if (panel.hidden) { search(select.value ? '' : input.value.trim()); return; }
            if (items.length) setActive(event.key === 'ArrowDown' ? (active + 1) % items.length : (active <= 0 ? items.length - 1 : active - 1));
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            if (!panel.hidden && active >= 0 && items[active]) choose(items[active]);
        }
    });
    clear.addEventListener('click', () => {
        select.value = ''; input.value = ''; selectedLabel = ''; summary.textContent = ''; validity();
        clearTimeout(timer); search(''); input.focus();
    });
    more.addEventListener('mousedown', event => event.preventDefault());
    more.addEventListener('click', () => { if (nextPage) search(select.value ? '' : input.value.trim(), nextPage); });
    root.addEventListener('focusout', event => {
        if (!root.contains(event.relatedTarget)) { clearTimeout(timer); sequence++; controller?.abort(); close(); }
    });
    doc.addEventListener('pointerdown', event => {
        if (!root.contains(event.target)) { clearTimeout(timer); sequence++; controller?.abort(); close(); }
    });
    root.closest('form')?.addEventListener('reset', () => {
        clearTimeout(timer); sequence++; controller?.abort(); close();
        setTimeout(() => { input.value = select.value ? select.selectedOptions[0].textContent.trim() : ''; summary.textContent = ''; validity(); }, 0);
    });
}

document.querySelectorAll('[data-expert-picker]').forEach(enhanceExpertPicker);
