const whatsappChatInit = (data) => {
  const app = document.getElementById('whatsapp_app');
  if (!app) return;
  let widget = app.querySelector('#whatsapp_modal');
  if (widget) {
    if (widget.classList.contains('active')) {
      widget.classList.remove('active');
      widget.classList.add('inactive');
    } else {
      widget.classList.add('active');
      widget.classList.remove('inactive');
    }
    return;
  }
  const widgetHtml = '<div id="whatsapp_header">' +
  '<div id="whatsapp_header__avatar">' +
  '<img id="whatsapp_header__avatar_img" src="' + data.dataset.whatsappB + '">' +
  '</div>' +
  '<div id="whatsapp_header__title">' +
  '<div id="whatsapp_header__title_comp">' + data.dataset.whatsappC + '</div>' +
  '<div id="whatsapp_header__title_status">' + data.dataset.whatsappD + '</div>' +
  '</div>' +
  '</div>' +
  '<div id="whatsapp_body">' +
  '<div id="whatsapp_body__chat" class="whatsapp_body__chat_left">' +
  '<div class="whatsapp_body__chat_title">' + data.dataset.whatsappC + '</div>' +
  '<div id="whatsapp_body__chat_init" class="whatsapp_body__chat_content">' +
  '<div class="whatsapp_body__chat_init">' + data.dataset.whatsappE + '</div>' +
  '<div class="whatsapp_body__chat_ready">' + data.dataset.whatsappF + '</div>' +
  '</div>' +
  '</div>' +
  '</div>' +
  '<div id="whatsapp_footer">' +
  '<a href="#" id="whatsapp_footer__button" rel="noopener noreferrer">Start Chat</a>' +
  '</div>';
  widget = document.createElement('div');
  widget.id = 'whatsapp_modal';
  widget.classList = 'active';
  widget.innerHTML = widgetHtml;
  app.append(widget);
  widget.querySelector('#whatsapp_footer__button').addEventListener('click', whatsappChatStart);
};

// The number is never in the page: it is returned by the server only after a Turnstile check passes
const whatsappChatStart = (e) => {
  e.preventDefault();
  if (!e.isTrusted) return;
  const btn = e.currentTarget;
  const cfg = window.whatsappChatCfg || {};
  if (btn.dataset.busy || !cfg.siteKey) return;
  btn.dataset.busy = '1';
  const label = btn.textContent;
  btn.textContent = 'Verifying...';
  // Open the tab synchronously so popup blockers allow it, then point it at WhatsApp once verified
  const win = window.open('', '_blank');
  const done = (url) => {
    btn.textContent = label;
    delete btn.dataset.busy;
    if (url && win) { win.opener = null; win.location.href = url; }
    else { if (win) win.close(); if (!url) alert('Sorry, we could not verify your request. Please try again or call us.'); }
  };
  const run = () => {
    const holder = document.createElement('div');
    holder.style.cssText = 'position:fixed;bottom:90px;right:20px;z-index:2147483647';
    document.body.appendChild(holder);
    const cleanup = () => holder.remove();
    window.turnstile.render(holder, {
      sitekey: cfg.siteKey,
      action: 'whatsapp_chat',
      appearance: 'interaction-only',
      callback: (token) => {
        fetch(cfg.endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token: token })
        }).then((r) => r.ok ? r.json() : Promise.reject())
          .then((d) => { cleanup(); done(d.url); })
          .catch(() => { cleanup(); done(null); });
      },
      'error-callback': () => { cleanup(); done(null); },
      'timeout-callback': () => { cleanup(); done(null); }
    });
  };
  if (window.turnstile) return run();
  const sc = document.createElement('script');
  sc.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
  sc.onload = run;
  sc.onerror = () => done(null);
  document.head.appendChild(sc);
};
