/**
 * Content Powered > SMTP Email Log page behaviour. Only loaded on that page.
 *
 * Settings tabs, rows that depend on a checkbox, confirmations, bulk
 * actions, Resend buttons in the log, the message tabs and "Load images"
 * on an email's page, copy buttons, the test email and the webhook test.
 * smtp_email_log_admin.ajaxUrl, .nonce and .i18n are passed in from PHP
 * (see SMTP_Email_Log_Admin::enqueue_assets).
 *
 * Text from the server is only ever set with textContent.
 */

/* global smtp_email_log_admin */
( function () {
	'use strict';

	var data = window.smtp_email_log_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		var form = document.querySelector( '.smtpel-form' );

		initTabs( form );
		initDepends( form );
		initConfirm();
		initBulk();
		initQuickResend();
		initMessageTabs();
		initImages();
		initCopy();
		initForward();
		initTest();
		initWebhookTest();
	} );

	// Settings tabs --------------------------------------------------------------

	function initTabs( form ) {
		var tabs = document.querySelectorAll( '[data-smtpel-tab]' );
		var panels = document.querySelectorAll( '[data-smtpel-panel]' );
		var referer = form ? form.querySelector( 'input[name="_wp_http_referer"]' ) : null;
		var refererBase = referer ? referer.value.split( '#' )[ 0 ] : '';

		if ( ! tabs.length ) {
			return;
		}

		function show( id ) {
			var found = false;

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-smtpel-tab' ) === id;

				found = found || active;
				tab.classList.toggle( 'is-active', active );
				if ( active ) {
					tab.setAttribute( 'aria-current', 'page' );
				} else {
					tab.removeAttribute( 'aria-current' );
				}
			} );

			if ( ! found ) {
				show( tabs[ 0 ].getAttribute( 'data-smtpel-tab' ) );
				return;
			}

			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-smtpel-panel' ) !== id;
			} );

			// Come back to this tab after saving.
			if ( referer ) {
				referer.value = refererBase + '#' + id;
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				var id = tab.getAttribute( 'data-smtpel-tab' );

				event.preventDefault();
				show( id );
				history.replaceState( null, '', '#' + id );
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.replace( '#', '' ) );
		} );

		show( window.location.hash.replace( '#', '' ) );
	}

	// Rows that only apply while a checkbox is ticked ---------------------------

	function initDepends( form ) {
		if ( ! form ) {
			return;
		}

		var rows = form.querySelectorAll( '[data-smtpel-depends]' );

		function update() {
			rows.forEach( function ( row ) {
				var key = row.getAttribute( 'data-smtpel-depends' );
				var box = form.querySelector( 'input[type="checkbox"][name$="[' + key + ']"]' );
				var off = !! box && ! box.checked;

				row.classList.toggle( 'is-disabled', off );
				row.querySelectorAll( 'input[type="number"]' ).forEach( function ( input ) {
					input.readOnly = off;
				} );
			} );
		}

		form.addEventListener( 'change', update );
		update();
	}

	// "Are you sure?" on buttons and links that delete things --------------------

	function initConfirm() {
		document.addEventListener( 'click', function ( event ) {
			var target = event.target.closest( '[data-smtpel-confirm]' );

			if ( target && ! window.confirm( target.getAttribute( 'data-smtpel-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	}

	// Bulk actions ---------------------------------------------------------------

	function initBulk() {
		var form = document.querySelector( '.smtpel-bulk-form' );

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			var action = form.querySelector( 'select[name="bulk_action"]' ).value;
			var picked = form.querySelectorAll( 'input[name="ids[]"]:checked' ).length;

			if ( ! action || ! picked ) {
				event.preventDefault();
				window.alert( data.i18n.bulkNone );
				return;
			}

			if ( ! window.confirm( 'delete' === action ? data.i18n.bulkDelete : data.i18n.bulkResend ) ) {
				event.preventDefault();
			}
		} );
	}

	// AJAX -----------------------------------------------------------------------

	function post( action, fields ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', data.nonce );
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	// Resend from the log ----------------------------------------------------------

	function initQuickResend() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-smtpel-resend]' );

			if ( ! button ) {
				return;
			}

			event.preventDefault();

			if ( ! window.confirm( data.i18n.resendConfirm ) ) {
				return;
			}

			button.disabled = true;
			button.textContent = data.i18n.sending;

			post( 'smtp_email_log_quick_resend', { id: button.getAttribute( 'data-smtpel-resend' ) } )
				.then( function ( json ) {
					var result = document.createElement( json && json.data && json.data.url ? 'a' : 'span' );

					result.className = json && json.success ? 'smtpel-resend-done' : 'smtpel-resend-fail';
					result.textContent = json && json.data && json.data.message ? json.data.message : data.i18n.failed;
					if ( json && json.data && json.data.url ) {
						result.href = json.data.url;
					}

					button.replaceWith( result );
				} )
				.catch( function () {
					button.disabled = false;
					button.textContent = data.i18n.failed;
				} );
		} );
	}

	// One email: message tabs, images, copy, forward --------------------------------

	function initMessageTabs() {
		var tabs = document.querySelectorAll( '[data-smtpel-msgtab]' );
		var panels = document.querySelectorAll( '[data-smtpel-msgpanel]' );

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var id = tab.getAttribute( 'data-smtpel-msgtab' );

				tabs.forEach( function ( other ) {
					var active = other === tab;

					other.classList.toggle( 'is-active', active );
					other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );

				panels.forEach( function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-smtpel-msgpanel' ) !== id;
				} );
			} );
		} );
	}

	function initImages() {
		var button = document.querySelector( '[data-smtpel-images]' );
		var frame = document.querySelector( '.smtpel-preview' );

		if ( ! button || ! frame ) {
			return;
		}

		button.addEventListener( 'click', function () {
			// Only images are let through; the frame still can't run scripts.
			frame.setAttribute(
				'srcdoc',
				frame.getAttribute( 'srcdoc' )
					.replace( 'img-src data: cid:', 'img-src data: cid: https: http:' )
					.replace( / data-smtpel-(src|srcset)=/g, ' $1=' )
			);
			button.disabled = true;
		} );
	}

	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-smtpel-copy], [data-smtpel-copy-target]' );
			var text;
			var label;

			if ( ! button || ! navigator.clipboard ) {
				return;
			}

			if ( button.hasAttribute( 'data-smtpel-copy-target' ) ) {
				var source = document.querySelector( button.getAttribute( 'data-smtpel-copy-target' ) );
				text = source ? source.textContent : '';
			} else {
				text = button.getAttribute( 'data-smtpel-copy' );
			}

			label = button.querySelector( 'span' );

			navigator.clipboard.writeText( text ).then( function () {
				if ( label ) {
					label.textContent = data.i18n.copied;
					window.setTimeout( function () {
						label.textContent = data.i18n.copy;
					}, 1500 );
				}
			} );
		} );
	}

	function initForward() {
		var field = document.querySelector( '[data-smtpel-forward]' );

		if ( ! field ) {
			return;
		}

		var form = field.closest( 'form' );

		function update() {
			var other = form.querySelector( 'input[name="recipient"][value="other"]' );

			field.disabled = ! other.checked;
			field.required = other.checked;
		}

		form.addEventListener( 'change', update );
		field.addEventListener( 'focus', function () {
			form.querySelector( 'input[name="recipient"][value="other"]' ).checked = true;
			update();
		} );
		update();
	}

	// Test email ---------------------------------------------------------------------

	function initTest() {
		var form = document.querySelector( '[data-smtpel-test]' );
		var result = document.querySelector( '[data-smtpel-test-result]' );

		if ( ! form || ! result ) {
			return;
		}

		var button = form.querySelector( 'button[type="submit"]' );
		var label = button.querySelector( 'span' );
		var idle = label.textContent;

		function part( name ) {
			return result.querySelector( '[data-smtpel-result-' + name + ']' );
		}

		function setIcon( kind ) {
			var template = document.querySelector( 'template[data-smtpel-icon="' + kind + '"]' );
			var holder = part( 'icon' );

			holder.textContent = '';
			if ( template ) {
				holder.appendChild( template.content.cloneNode( true ) );
			}
		}

		function show( ok, json ) {
			var box = result.querySelector( '.smtpel-outcome' );
			var details = json && json.data ? json.data : {};
			var meta = [];
			var link = part( 'link' );
			var error = part( 'error' );
			var note = part( 'note' );
			var smtp = part( 'smtp' );

			box.className = 'smtpel-outcome ' + ( ok ? 'is-good' : 'is-bad' );
			setIcon( ok ? 'ok' : 'bad' );

			part( 'title' ).textContent = ok ? details.message : ( details.title || details.message || data.i18n.failed );
			part( 'text' ).textContent = details.hint || '';
			part( 'text' ).hidden = ! details.hint;

			error.hidden = ok || ! details.error;
			error.querySelector( 'code' ).textContent = details.error || '';

			if ( details.transport ) {
				meta.push( data.i18n.via + ': ' + details.transport );
			}
			if ( details.reply ) {
				meta.push( data.i18n.reply + ': ' + details.reply );
			}
			part( 'meta' ).textContent = meta.join( ' · ' );

			note.hidden = ! details.note;
			note.textContent = details.note || '';

			link.hidden = ! details.url;
			if ( details.url ) {
				link.href = details.url;
				link.textContent = data.i18n.openLog;
			}

			smtp.hidden = ! details.transcript;
			smtp.querySelector( 'pre' ).textContent = details.transcript || '';

			result.hidden = false;
		}

		form.addEventListener( 'submit', function ( event ) {
			var to = form.querySelector( 'input[name="to"]' ).value;
			var format = form.querySelector( 'input[name="format"]:checked' );

			event.preventDefault();

			button.disabled = true;
			label.textContent = data.i18n.sending;

			post( 'smtp_email_log_test', { to: to, format: format ? format.value : 'html' } )
				.then( function ( json ) {
					if ( json && json.success ) {
						show( !! json.data.ok, json );
					} else {
						show( false, { data: { message: json && json.data && json.data.message ? json.data.message : data.i18n.failed } } );
					}
				} )
				.catch( function () {
					show( false, { data: { message: data.i18n.failed } } );
				} )
				.then( function () {
					button.disabled = false;
					label.textContent = idle;
				} );
		} );
	}

	// Webhook test ------------------------------------------------------------------

	function initWebhookTest() {
		var button = document.querySelector( '[data-smtpel-webhook-test]' );
		var result = document.querySelector( '[data-smtpel-webhook-result]' );
		var field = document.getElementById( 'smtpel-webhook_url' );

		if ( ! button || ! result || ! field ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			result.className = 'smtpel-test-line';
			result.textContent = data.i18n.testing;

			post( 'smtp_email_log_test_webhook', { url: field.value } )
				.then( function ( json ) {
					result.className = 'smtpel-test-line ' + ( json && json.success ? 'is-ok' : 'is-error' );
					result.textContent = json && json.data && json.data.message ? json.data.message : data.i18n.failed;
				} )
				.catch( function () {
					result.className = 'smtpel-test-line is-error';
					result.textContent = data.i18n.failed;
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}
}() );
