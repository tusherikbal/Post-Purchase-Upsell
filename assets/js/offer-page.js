( function () {
	'use strict';

	var config = window.ppUpsellOffer || {};

	var acceptBtn = document.getElementById( 'pp-upsell-accept' );
	var declineBtn = document.getElementById( 'pp-upsell-decline' );
	var statusEl = document.getElementById( 'pp-upsell-status' );

	function setBusy( busy ) {
		if ( acceptBtn ) {
			acceptBtn.disabled = busy;
		}
		if ( declineBtn ) {
			declineBtn.disabled = busy;
		}
	}

	function setStatus( message ) {
		if ( statusEl ) {
			statusEl.textContent = message || '';
		}
	}

	function submit( action ) {
		setBusy( true );
		setStatus( '' );

		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'attempt_id', config.attemptId );
		body.set( 'token', config.token );
		body.set( 'nonce', config.nonce );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.redirect ) {
					window.location.href = json.data.redirect;
					return;
				}

				setBusy( false );
				setStatus(
					( json && json.data && json.data.message ) ||
						'Something went wrong. Please try again.'
				);
			} )
			.catch( function () {
				setBusy( false );
				setStatus( 'Network error. Please try again.' );
			} );
	}

	if ( acceptBtn ) {
		acceptBtn.addEventListener( 'click', function () {
			submit( 'pp_upsell_accept' );
		} );
	}

	if ( declineBtn ) {
		declineBtn.addEventListener( 'click', function () {
			submit( 'pp_upsell_decline' );
		} );
	}
} )();
