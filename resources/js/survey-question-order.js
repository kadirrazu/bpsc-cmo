export function enhanceSurveyQuestionOrder(root) {
    const selected = root.querySelector('[data-order-selected]');
    const available = root.querySelector('[data-order-available]');
    const search = root.querySelector('[data-order-search]');
    if (!selected || !available) return;
    const bankZone = root.querySelector('[data-order-bank-zone]') || available;
    const selectedZone = root.querySelector('[data-order-selected-zone]') || selected;
    const rows = () => [...selected.querySelectorAll('[data-order-row]')];
    const status = root.querySelector('[data-order-status]');
    const title = row => row.querySelector('[data-order-title]').textContent;
    let dragged = null;
    function filter() {
        const term = search?.value.trim().toLocaleLowerCase() || '';
        const bankRows = [...available.querySelectorAll('[data-order-row]')];
        bankRows.forEach(row => row.hidden = !row.querySelector('[data-order-title]').textContent.toLocaleLowerCase().includes(term));
        const empty = root.querySelector('[data-order-no-matches]');
        if (empty) empty.hidden = bankRows.some(row => !row.hidden);
    }
    function action(row, name, visible, enabled = visible) {
        const button = row.querySelector(`[data-order-${name}]`);
        if (!button) return;
        button.hidden = !visible;
        button.disabled = !enabled;
    }
    function refresh(message = '') {
        const ordered = rows();
        root.querySelector('[data-order-count]').textContent = String(ordered.length);
        root.querySelector('[data-order-empty]').hidden = ordered.length > 0;
        ordered.forEach((row, index) => {
            row.hidden = false;
            row.dataset.orderState = 'selected';
            action(row, 'add', false);
            action(row, 'remove', true);
            action(row, 'up', true, index > 0);
            action(row, 'down', true, index < ordered.length - 1);
            row.querySelector('[data-order-check]').checked = true;
            row.querySelector('[data-order-position]').value = String(index + 1);
            row.querySelector('[data-order-position]').disabled = false;
            row.querySelector('[data-order-up]').disabled = index === 0;
            row.querySelector('[data-order-down]').disabled = index === ordered.length - 1;
            row.querySelector('[data-order-drag]').hidden = false;
            row.querySelector('[data-order-buttons]').hidden = false;
            const add = row.querySelector('[data-order-add]');
            if (add) add.hidden = true;
            const wrap = row.querySelector('[data-order-position-wrap]');
            if (wrap) wrap.hidden = false;
        });
        available.querySelectorAll('[data-order-row]').forEach(row => {
            row.dataset.orderState = 'available';
            action(row, 'add', true);
            action(row, 'remove', false);
            action(row, 'up', false);
            action(row, 'down', false);
            row.querySelector('[data-order-check]').checked = false;
            row.querySelector('[data-order-position]').disabled = true;
            row.querySelector('[data-order-position]').value = '';
            row.querySelector('[data-order-drag]').hidden = false;
            row.querySelector('[data-order-buttons]').hidden = true;
            const add = row.querySelector('[data-order-add]');
            if (add) add.hidden = false;
            const wrap = row.querySelector('[data-order-position-wrap]');
            if (wrap) wrap.hidden = true;
        });
        if (message && status) status.textContent = message;
        filter();
    }
    function add(row) {
        if (row.parentElement === selected) return;
        selected.append(row);
        refresh(`Added ${title(row)} at position ${rows().length}.`);
    }
    function remove(row) {
        if (row.parentElement !== selected) return;
        const ordered = rows();
        const index = ordered.indexOf(row);
        const next = ordered[index + 1] || ordered[index - 1];
        available.append(row);
        refresh(`Removed ${title(row)}. The question remains in the bank.`);
        if (next) next.querySelector('[data-order-check]').focus();
        else search?.focus();
    }
    function move(row, targetIndex) {
        const ordered = rows();
        const from = ordered.indexOf(row);
        targetIndex = Math.max(0, Math.min(ordered.length - 1, targetIndex));
        if (from < 0 || from === targetIndex) return;
        ordered.splice(from, 1); ordered.splice(targetIndex, 0, row);
        selected.append(...ordered);
        refresh(`Moved ${title(row)} to position ${targetIndex + 1}.`);
    }
    function clearDragState() {
        root.querySelectorAll('.is-dragging,.is-drop-target,.is-drop-zone').forEach(item => item.classList.remove('is-dragging', 'is-drop-target', 'is-drop-zone'));
    }
    root.querySelectorAll('[data-order-row]').forEach(row => {
        const check = row.querySelector('[data-order-check]');
        row.draggable = true;
        check.addEventListener('change', () => {
            if (check.checked) add(row); else remove(row);
        });
        row.querySelector('[data-order-add]')?.addEventListener('click', () => {
            add(row);
            check.focus();
        });
        row.querySelector('[data-order-remove]')?.addEventListener('click', () => remove(row));
        row.querySelector('[data-order-up]').addEventListener('click', () => move(row, rows().indexOf(row) - 1));
        row.querySelector('[data-order-down]').addEventListener('click', () => move(row, rows().indexOf(row) + 1));
        row.querySelector('[data-order-position]').addEventListener('change', event => {
            const target = Number.parseInt(event.target.value, 10);
            if (Number.isFinite(target)) move(row, target - 1);
            refresh();
        });
        row.addEventListener('dragstart', event => {
            const target = event.target.closest('input,button');
            if (target && !target.matches('[data-order-drag]')) { event.preventDefault(); return; }
            dragged = row;
            row.classList.add('is-dragging');
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', row.dataset.questionId);
            }
        });
        row.addEventListener('dragend', () => { clearDragState(); dragged = null; });
    });
    [bankZone, selectedZone].forEach(zone => {
        zone.addEventListener('dragover', event => {
            if (!dragged) return;
            event.preventDefault();
            zone.classList.add('is-drop-zone');
            if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
        });
        zone.addEventListener('dragleave', event => {
            if (!event.relatedTarget || !zone.contains(event.relatedTarget)) zone.classList.remove('is-drop-zone');
        });
        zone.addEventListener('drop', event => {
            if (!dragged) return;
            event.preventDefault();
            event.stopPropagation();
            if (zone === bankZone) {
                remove(dragged);
            } else {
                const target = event.target.closest('[data-order-row]');
                const wasSelected = dragged.parentElement === selected;
                if (target !== dragged) {
                    const ordered = rows().filter(item => item !== dragged);
                    let index = ordered.length;
                    if (target && target.parentElement === selected) {
                        const rect = target.getBoundingClientRect();
                        index = ordered.indexOf(target) + (event.clientY > rect.top + rect.height / 2 ? 1 : 0);
                    }
                    ordered.splice(index, 0, dragged);
                    selected.append(...ordered);
                    refresh(`${wasSelected ? 'Moved' : 'Added'} ${title(dragged)} at position ${index + 1}.`);
                }
            }
            clearDragState();
            dragged = null;
        });
    });
    search?.addEventListener('input', filter);
    refresh();
}

document.querySelectorAll('[data-survey-question-order]').forEach(enhanceSurveyQuestionOrder);
