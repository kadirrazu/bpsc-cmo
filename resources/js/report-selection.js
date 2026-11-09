export function enhanceReportSelection(form) {
    const select = form.querySelector('[data-report-expert]');
    const list = form.querySelector('[data-selected-experts]');
    if (!select || !list) return;
    const doc = form.ownerDocument;
    const selected = new Map();
    const count = form.querySelector('[data-selection-count]');
    const status = form.querySelector('[data-selection-status]');
    const scope = value => form.querySelector(`input[name="scope"][value="${value}"]`);
    const resetLookup = () => {
        const input = select.closest('[data-expert-picker]')?.querySelector('.expert-search-input');
        if (input) {
            input.value = '';
            input.dispatchEvent(new doc.defaultView.Event('input', {bubbles: true}));
        } else select.value = '';
    };
    const render = () => {
        list.replaceChildren();
        selected.forEach((label, id) => {
            const chip = doc.createElement('div');
            chip.className = 'report-expert-chip';
            const input = doc.createElement('input');
            input.type = 'hidden'; input.name = 'expert_ids[]'; input.value = id;
            const text = doc.createElement('span'); text.textContent = label;
            const remove = doc.createElement('button');
            remove.type = 'button'; remove.className = 'btn btn-sm btn-link'; remove.textContent = 'Remove';
            remove.setAttribute('aria-label', `Remove ${label}`);
            remove.addEventListener('click', () => {
                selected.delete(id);
                if (!selected.size) scope('filtered').checked = true;
                render();
            });
            chip.append(input, text, remove); list.append(chip);
        });
        if (count) count.textContent = String(selected.size);
        if (status) status.textContent = selected.size ? `${selected.size} expert(s) selected.` : 'No expert selection. All current filters will be used.';
    };
    select.addEventListener('change', () => {
        if (!select.value) return;
        const id = select.value;
        if (selected.size >= 500 && !selected.has(id)) {
            if (status) status.textContent = 'Select up to 500 experts per download.';
            return;
        }
        selected.set(id, select.selectedOptions[0].textContent.trim());
        scope('selected').checked = true;
        render();
        resetLookup();
    });
    form.querySelector('[data-clear-experts]')?.addEventListener('click', () => {
        selected.clear(); scope('filtered').checked = true; render(); resetLookup();
    });
    form.addEventListener('submit', event => {
        if (scope('selected').checked && !selected.size) {
            event.preventDefault();
            if (status) status.textContent = 'Add at least one expert, or choose all matching experts.';
        }
    });
    render();
}

document.querySelectorAll('[data-report-selection]').forEach(enhanceReportSelection);
