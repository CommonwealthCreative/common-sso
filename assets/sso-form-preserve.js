(function () {
  const FORM_SELECTOR = '.wpcf7 form';
  const STORAGE_KEY = 'cf7_form_data';

  function saveFormToSession() {
    const form = document.querySelector(FORM_SELECTOR);
    if (!form) return;

    const data = {};
    form.querySelectorAll('input, textarea, select').forEach(el => {
      if (el.name && ['checkbox', 'radio'].includes(el.type)) {
        data[el.name] = el.checked;
      } else if (el.name) {
        data[el.name] = el.value;
      }
    });

    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(data));
  }

  function restoreFormFromSession() {
    const form = document.querySelector(FORM_SELECTOR);
    if (!form) return;

    const data = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '{}');
    Object.entries(data).forEach(([name, value]) => {
      const field = form.querySelector(`[name="${name}"]`);
      if (!field) return;

      if (['checkbox', 'radio'].includes(field.type)) {
        field.checked = value;
      } else {
        field.value = value;
      }
    });
  }

  function clearSessionStorageOnSubmit() {
    const form = document.querySelector(FORM_SELECTOR);
    if (!form) return;
    form.addEventListener('submit', () => {
      sessionStorage.removeItem(STORAGE_KEY);
    });
  }

  function autoSubmitIfFlagged() {
    const form = document.querySelector(FORM_SELECTOR);
    if (!form) return;

    const flag = sessionStorage.getItem('cf7_auto_submit');
    if (flag === 'true') {
      sessionStorage.removeItem('cf7_auto_submit');
      setTimeout(() => form.submit(), 300); // small delay to ensure DOM is ready
    }
  }

  function saveFormUrlToServer() {
    const url = window.location.href;
    fetch('/wp-json/common-sso/v1/save-url', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({ url })
    }).then(() => {
      console.log('Saved form URL to server');
    }).catch(console.error);
  }

  document.addEventListener('DOMContentLoaded', () => {
    restoreFormFromSession();
    clearSessionStorageOnSubmit();
    autoSubmitIfFlagged();

    document.querySelectorAll('.sso-login-btn').forEach(button => {
      button.addEventListener('click', () => {
        saveFormToSession();
        saveFormUrlToServer();
        sessionStorage.setItem('cf7_auto_submit', 'true');
      });
    });
  });
})();
