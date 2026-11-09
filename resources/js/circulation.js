(() => {
    const dialog = document.createElement('dialog');
    dialog.setAttribute('aria-labelledby', 'circulation-confirm-title');
    dialog.setAttribute('aria-describedby', 'circulation-confirm-text');
    Object.assign(dialog.style, {border: '0', borderRadius: '14px', padding: '0', maxWidth: 'min(620px, 92vw)', width: '100%', fontFamily: '"Inter","Segoe UI","Noto Sans Bengali",sans-serif'});
    dialog.innerHTML = '<form method="dialog"><div class="card-body p-4"><h2 id="circulation-confirm-title">Confirm action</h2><p id="circulation-confirm-text" style="white-space:pre-wrap;overflow-wrap:anywhere"></p><div data-renew-fields hidden><label class="form-label" for="circulation-renew-days">Extension days</label><input class="form-control" id="circulation-renew-days" type="number" min="1" max="365" step="1"><p class="form-hint mt-2" data-renew-hint></p></div></div><div class="card-footer d-flex flex-wrap justify-content-end gap-2"><button class="btn btn-outline-secondary" value="cancel" formnovalidate>Cancel</button><button class="btn btn-primary" value="confirm">Confirm</button></div></form>';
    document.body.append(dialog);
    const approved = new WeakSet();
    let busy = false;
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-circulation-confirm]');
        if (!form || event.defaultPrevented || approved.has(form)) return;
        event.preventDefault();
        if (busy) return;
        const action = form.dataset.circulationConfirm;
        const loanDays = Number(form.dataset.loanDays);
        if (action === 'issue' && (!form.elements.namedItem('book_id')?.value || !form.elements.namedItem('member_id')?.value)) return;
        busy = true;
        const book = action === 'issue' ? form.querySelector('#book-search').value : `${form.dataset.book} (${form.dataset.accession})`;
        const member = action === 'issue' ? form.querySelector('#member-search').value : form.dataset.member;
        const title = {issue: 'Confirm book issue', reminder: 'Send return reminder?', renew: 'Renew this loan?', fine: 'Flag this issue for fine?', 'clear-fine': 'Clear the fine flag?'}[action];
        const text = {issue: `Issue this book to this member for ${loanDays} days?\n\nBook: ${book}\nMember: ${member}`, reminder: `Send a book return reminder to ${member}’s dashboard?\n\nBook: ${book}`, renew: `Extend the loan for ${member}?\n\nBook: ${book}\nCurrent due date: ${form.dataset.due ? new Date(form.dataset.due).toLocaleString() : 'Not recorded'}`, fine: `Flag this issue for separate fine reporting?\n\nBook: ${book}\nMember: ${member}`, 'clear-fine': `Clear the fine flag on this issue?\n\nBook: ${book}\nMember: ${member}`}[action];
        dialog.querySelector('#circulation-confirm-title').textContent = title || 'Confirm action';
        dialog.querySelector('#circulation-confirm-text').textContent = text || 'Continue?';
        const renewal = dialog.querySelector('[data-renew-fields]');
        const days = dialog.querySelector('#circulation-renew-days');
        renewal.hidden = action !== 'renew'; days.disabled = action !== 'renew'; days.required = action === 'renew'; days.value = String(loanDays);
        dialog.querySelector('[data-renew-hint]').textContent = `Default ${loanDays} days; choose 1–365 days. Extension starts today.`;
        dialog.returnValue = '';
        dialog.addEventListener('close', () => {
            busy = false;
            if (dialog.returnValue !== 'confirm') return;
            if (action === 'renew') form.elements.namedItem('days').value = days.value;
            let confirmed = form.elements.namedItem('confirmed');
            if (!confirmed) {confirmed = document.createElement('input'); confirmed.type = 'hidden'; confirmed.name = 'confirmed'; form.append(confirmed);}
            confirmed.value = '1'; approved.add(form);
            form.requestSubmit(event.submitter || undefined);
            approved.delete(form);
        }, {once: true});
        dialog.showModal();
        dialog.querySelector('button[value="cancel"]').focus();
    });
})();
