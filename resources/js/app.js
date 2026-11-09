import './survey-question-order.js';
import './expert-picker.js';
import './report-selection.js';
import '@tabler/core/dist/js/tabler.min.js';
document.addEventListener('submit', event => {
    const form = event.target.closest('form[data-confirm-delete]');
    if (form && !window.confirm(form.dataset.confirmMessage)) event.preventDefault();
});

// Values remain only in the existing field. Submission and page hiding restore masking.
const passwordControls = [];
document.querySelectorAll('[data-password-toggle]').forEach(button => {
    const input = button.parentElement.querySelector('input');
    if (!input) return;
    const setVisible = visible => {
        input.type = visible ? 'text' : 'password';
        const label = visible ? button.dataset.hideLabel : button.dataset.showLabel;
        button.setAttribute('aria-label', label);
        button.title = label;
        button.setAttribute('aria-pressed', String(visible));
        button.querySelector('i').className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
    };
    button.hidden = false;
    button.addEventListener('click', () => {
        setVisible(input.type === 'password');
        input.focus({preventScroll: true});
    });
    input.form?.addEventListener('submit', () => setVisible(false));
    passwordControls.push(() => setVisible(false));
});
window.addEventListener('pagehide', () => passwordControls.forEach(hide => hide()));
document.addEventListener('visibilitychange', () => {
    if(document.hidden) passwordControls.forEach(hide => hide());
});


document.addEventListener('submit', event => {
 const form = event.target.closest('form[data-confirm-submit]');
 if(form && !window.confirm(form.dataset.confirmSubmit)) event.preventDefault();
});

// Required multiple-selection groups need one checked option, not every checkbox.
document.querySelectorAll('[data-multiple-question][data-required]').forEach(group => {
 const boxes = [...group.querySelectorAll('input[type="checkbox"]')];
 const validate = () => boxes[0]?.setCustomValidity(boxes.some(box => box.checked) ? '' : 'Select at least one option.');
 boxes.forEach(box => box.addEventListener('change', validate));
 validate();
});

const typeSelect = document.querySelector('[data-question-type]');
if(typeSelect){
 const update = () => {
  const options = document.querySelector('[data-option-fields]');
  const ratings = document.querySelector('[data-rating-fields]');
  if(options){options.hidden = !['select','radio','multiple'].includes(typeSelect.value); options.querySelectorAll('input,textarea').forEach(input => input.disabled = options.hidden);}
  if(ratings){ratings.hidden = typeSelect.value !== 'rating'; ratings.querySelectorAll('input').forEach(input => input.disabled = ratings.hidden);}
 };
 typeSelect.addEventListener('change',update);update();
}
document.querySelectorAll('[data-print-report]').forEach(button => button.addEventListener('click',()=>window.print()));

import './viva.js';
