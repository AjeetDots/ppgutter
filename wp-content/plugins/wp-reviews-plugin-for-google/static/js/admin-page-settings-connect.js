if (typeof TrustindexJsLoaded === 'undefined') {
	var TrustindexJsLoaded = {};
}

TrustindexJsLoaded.connect = true;

// autocomplete config
var TrustindexConnect = null;
jQuery(document).ready(function($) {
	let onConnectPopupClosed = function(tiWindow, button) {
		if (tiWindow) {
			$('#ti-connect-info').addClass('ti-d-none');
		}

		if (button) {
			button.removeClass('ti-btn-loading');
		}
	};

	/*************************************************************************/
	/* NO REG MODE */
	TrustindexConnect = {
		button: $('.ti-connect-platform .ti-btn'),
		form: $('#ti-connect-platform-form'),
		asyncRequest: function(callback, btn) {
			// get url params
			let params = new URLSearchParams({
				type: 'google',
				page_id: $('#ti-noreg-page-id').val().trim(),
				access_token: $('#ti-noreg-access-token').length ? $('#ti-noreg-access-token').val() : "",
				webhook_url: $('#ti-noreg-webhook-url').val(),
				email: $('#ti-noreg-email').val(),
				token: $('#ti-noreg-connect-token').val(),
				version: $('#ti-noreg-version').val()
			});

			// open window
			let tiWindow = window.open('https://admin.trustindex.io/source/wordpressPageRequest?' + params.toString(), 'trustindex', 'width=850,height=850,menubar=0' + popupCenter(850, 850));

			// show popup info
			$('#ti-connect-info').removeClass('ti-d-none');

			waitForPopupResponse(tiWindow, {
				adminUrl: 'https://admin.trustindex.io/',
				isResponse: function(data) {
					return data.success;
				},
				onResponse: function(data) {
					callback($('#ti-noreg-connect-token').val(), data.request_id, (data.manual_download | 0), data.place || null);
				},
				onClose: function() {
					onConnectPopupClosed(tiWindow, btn);
				}
			});
		}
	};

	let checkCrossOriginError = function(button, style = "margin-top: 15px;margin-bottom: 0") {
		fetch(window.location.href).then(function (response) {
			if ('same-origin' === response.headers.get('Cross-Origin-Opener-Policy')) {
				button
					.addClass('ti-btn-disabled')
					.after('<div class="ti-notice ti-notice-error" style="'+style+'"><p>Connect popup cannot work on this site. You have <code>Cross-Origin-Opener-Policy: same-origin</code> header.<br />Please change it to <code>same-origin-allow-popups</code> or remove it.</p></div>');
			}
		});
	};

	
		let tiConnectButton = $('.btn-connect-public');
		if (tiConnectButton.length) {
			tiConnectButton.click(function(event) {
				event.preventDefault();

				let button = $(this);
				let token = $('#ti-noreg-connect-token').val();

				button.addClass('ti-btn-loading').blur();

				// get url params
				let params = new URLSearchParams({
					type: 'Google',
					referrer: 'public',
					webhook_url: $('#ti-noreg-webhook-url').val(),
					token: token,
					version: $('#ti-noreg-version').val()
				});

				let tiWindow = window.open('https://admin.trustindex.io/source/edit2?' + params.toString(), 'trustindex', 'width=850,height=850,menubar=0' + popupCenter(850, 850));

				$('#ti-connect-info').removeClass('ti-d-none');

				waitForPopupResponse(tiWindow, {
					adminUrl: 'https://admin.trustindex.io/',
					isResponse: function(data) {
						return data.id;
					},
					onResponse: function(data) {
						let jsonStr = JSON.stringify(data).replace(/[\u0080-\uffff]/g, c => {
							return '\\u' + ('0000' + c.charCodeAt(0).toString(16)).slice(-4);
						});

						$('#ti-noreg-page-details').val(jsonStr);

						button.closest('form').submit();
					},
					onClose: function() {
						onConnectPopupClosed(tiWindow, button);
					}
				});
			});

			checkCrossOriginError(tiConnectButton);
		}

	
		// try reply again
		jQuery(document).on('click', '.btn-try-reply-again', function(event) {
			event.preventDefault();

			let btn = jQuery(this);
			let replyBox = btn.closest('td').find('.ti-reply-box');

			replyBox.attr('data-state', btn.data('state'));
			replyBox.find('.state-'+ btn.data('state') +' .btn-post-reply').attr('data-reconnect', 1).trigger('click');
		});

	// make async request on review download
	let tiDownloadReviewsButton = $('.btn-download-reviews:not(.ti-btn-disabled)');
	if (tiDownloadReviewsButton.length) {
		tiDownloadReviewsButton.on('click', function(event) {
			event.preventDefault();

			let btn = jQuery(this);

			TrustindexConnect.asyncRequest(function(token, request_id, manual_download, place) {
				if (place) {
					$.ajax({
						type: 'POST',
						data: {
							_wpnonce: btn.data('nonce'),
							download_data: JSON.stringify(place)
						}
					}).always(() => location.reload());
				}
				else {
					$.ajax({
						type: 'POST',
						data: {
							_wpnonce: btn.data('nonce'),
							review_download_request: token,
							review_download_request_id: request_id,
							manual_download: manual_download
						}
					}).always(() => location.reload());
				}
			}, btn);
		});

		checkCrossOriginError(tiDownloadReviewsButton, "margin-top: 0; margin-bottom: 20px");
	}

	// manual download
	$('#ti-review-manual-download').on('click', function(event) {
		event.preventDefault();

		let btn = $(this);
		btn.addClass('ti-btn-loading').blur();

		$.ajax({
			url: location.search.replace(/&tab=[^&]+/, '&tab=free-widget-configurator'),
			type: 'POST',
			data: {
				command: 'review-manual-download',
				_wpnonce: btn.data('nonce')
			},
			success: () => location.reload(),
			error: function() {
				btn.removeClass('ti-btn-loading');

				btn.removeClass('ti-toggle-tooltip').addClass('ti-show-tooltip');
				setTimeout(() => btn.removeClass('ti-show-tooltip').addClass('ti-toggle-tooltip'), 3000);
			}
		});
	});
});


// - import/popup-response.js
function waitForPopupResponse(tiWindow, options) {
	// popup blocked by the browser, no response will come
	if (!tiWindow) {
		options.onClose();

		return;
	}

	let adminOrigin = new URL(options.adminUrl).origin;
	let closeCheckTimer = null;

	let onMessage = function(event) {
		let isPopupMessage = event.source === tiWindow && event.origin === adminOrigin;

		if (!isPopupMessage || (options.isResponse && !options.isResponse(event.data))) {
			return;
		}

		stopWaiting();
		options.onResponse(event.data);

		tiWindow.close();
	};

	let stopWaiting = function() {
		clearInterval(closeCheckTimer);
		window.removeEventListener('message', onMessage);
	};

	window.addEventListener('message', onMessage);

	closeCheckTimer = setInterval(function() {
		if (tiWindow.closed) {
			stopWaiting();
			options.onClose();
		}
	}, 1000);
}
