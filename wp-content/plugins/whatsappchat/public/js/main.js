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
  '<a href="https://wa.me/' + data.dataset.whatsappA + '" id="whatsapp_footer__button" target="_blank">Start Chat</a>' +
  '</div>';
  widget = document.createElement('div');
  widget.id = 'whatsapp_modal';
  widget.classList = 'active';
  widget.innerHTML = widgetHtml;
  app.append(widget);
};