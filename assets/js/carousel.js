/**
 * Carousel behaviour.
 *
 * Only loaded when a carousel widget is on the page. Grid, list and badge
 * widgets ship no JavaScript at all.
 *
 * The markup is already a usable scroll-snap strip without this file, so if the
 * script is blocked, deferred, or delayed by a cache plugin the widget still
 * works — it just loses the arrows and autoplay.
 */
( function () {
	'use strict';

	var MOUNTED = 'gbrwCarouselMounted';

	function prefersReducedMotion() {
		return window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	function parseConfig( el ) {
		try {
			return JSON.parse( el.getAttribute( 'data-gbrw-carousel' ) ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function Carousel( el ) {
		var track = el.querySelector( '.gbrw-track' );
		if ( ! track ) {
			return;
		}

		var config = parseConfig( el );
		var prev = el.querySelector( '.gbrw-arrow--prev' );
		var next = el.querySelector( '.gbrw-arrow--next' );
		var dotsWrap = el.querySelector( '.gbrw-dots' );
		var cards = Array.prototype.slice.call( track.querySelectorAll( '.gbrw-card' ) );
		var timer = null;

		function pageWidth() {
			return track.clientWidth;
		}

		function pageCount() {
			return Math.max( 1, Math.ceil( track.scrollWidth / pageWidth() ) );
		}

		function currentPage() {
			return Math.round( track.scrollLeft / pageWidth() );
		}

		function goTo( page ) {
			track.scrollTo( { left: page * pageWidth(), behavior: prefersReducedMotion() ? 'auto' : 'smooth' } );
		}

		function atEnd() {
			// One pixel of slack: sub-pixel layout makes exact equality unreliable.
			return track.scrollLeft + pageWidth() >= track.scrollWidth - 1;
		}

		function syncControls() {
			if ( prev ) {
				prev.disabled = track.scrollLeft <= 0;
			}
			if ( next ) {
				next.disabled = atEnd();
			}
			if ( dotsWrap ) {
				var active = currentPage();
				Array.prototype.forEach.call( dotsWrap.children, function ( dot, i ) {
					dot.classList.toggle( 'gbrw-dot--on', i === active );
				} );
			}
		}

		function buildDots() {
			if ( ! dotsWrap ) {
				return;
			}
			dotsWrap.innerHTML = '';
			var total = pageCount();
			if ( total < 2 ) {
				return;
			}
			for ( var i = 0; i < total; i++ ) {
				var dot = document.createElement( 'button' );
				dot.type = 'button';
				dot.className = 'gbrw-dot';
				dot.setAttribute( 'aria-label', 'Go to slide ' + ( i + 1 ) );
				( function ( index ) {
					dot.addEventListener( 'click', function () {
						stop();
						goTo( index );
					} );
				} )( i );
				dotsWrap.appendChild( dot );
			}
		}

		function start() {
			// Autoplay is suppressed entirely when the visitor prefers reduced
			// motion, and whenever there is nothing to scroll.
			if ( ! config.autoplay || prefersReducedMotion() || cards.length < 2 ) {
				return;
			}
			stop();
			timer = window.setInterval( function () {
				if ( atEnd() ) {
					goTo( 0 );
				} else {
					goTo( currentPage() + 1 );
				}
			}, Math.max( 1500, config.interval || 6000 ) );
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		if ( prev ) {
			prev.addEventListener( 'click', function () {
				stop();
				goTo( Math.max( 0, currentPage() - 1 ) );
			} );
		}

		if ( next ) {
			next.addEventListener( 'click', function () {
				stop();
				goTo( currentPage() + 1 );
			} );
		}

		track.addEventListener( 'scroll', syncControls, { passive: true } );

		// Pause while the pointer or keyboard focus is inside the widget.
		el.addEventListener( 'mouseenter', stop );
		el.addEventListener( 'focusin', stop );
		el.addEventListener( 'mouseleave', start );

		// Do not animate a widget nobody is looking at.
		if ( 'IntersectionObserver' in window ) {
			new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						start();
					} else {
						stop();
					}
				} );
			}, { threshold: 0.2 } ).observe( el );
		} else {
			start();
		}

		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( function () {
				buildDots();
				syncControls();
			} ).observe( track );
		} else {
			window.addEventListener( 'resize', function () {
				buildDots();
				syncControls();
			} );
		}

		buildDots();
		syncControls();
	}

	/**
	 * Fallback breakpoints for browsers without container queries.
	 */
	function applySizeFallback( root ) {
		if ( window.CSS && CSS.supports && CSS.supports( 'container-type: inline-size' ) ) {
			return;
		}
		var apply = function () {
			var w = root.clientWidth;
			root.setAttribute( 'data-size', w >= 860 ? 'desktop' : ( w >= 480 ? 'tablet' : 'mobile' ) );
		};
		apply();
		window.addEventListener( 'resize', apply );
	}

	function mountAll() {
		var roots = document.querySelectorAll( '.gbrw-root' );
		Array.prototype.forEach.call( roots, applySizeFallback );

		var widgets = document.querySelectorAll( '[data-gbrw-carousel]' );
		Array.prototype.forEach.call( widgets, function ( el ) {
			if ( el.dataset[ MOUNTED ] ) {
				return;
			}
			el.dataset[ MOUNTED ] = '1';
			Carousel( el );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', mountAll );
	} else {
		mountAll();
	}

	// Page builders and AJAX navigation insert markup after load.
	if ( 'MutationObserver' in window ) {
		new MutationObserver( function () {
			mountAll();
		} ).observe( document.body || document.documentElement, { childList: true, subtree: true } );
	}
} )();
