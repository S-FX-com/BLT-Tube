/**
 * BLT Tube — [blt_tube_gallery] front-end behaviour.
 *
 * Vanilla JS, no dependencies. Everything is event-delegated from the
 * document, so galleries inserted after page load (page-builder previews,
 * AJAX-loaded content) work without being initialised one by one.
 *
 * No YouTube iframe exists until a visitor clicks a video.
 */
(function () {
    'use strict';

    if (window.blttGallery) {
        return;
    }

    var VIDEO_ID = /^[A-Za-z0-9_-]{11}$/;
    var HOSTS = ['https://www.youtube-nocookie.com', 'https://www.youtube.com'];
    var IFRAME_ALLOW = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';

    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function isRtl(el) {
        return window.getComputedStyle(el).direction === 'rtl';
    }

    /* ---------------------------------------------------------------
     * Player
     * ------------------------------------------------------------- */

    function buildIframe(root, button) {
        var id = button.getAttribute('data-video-id') || '';

        if (!VIDEO_ID.test(id)) {
            return null;
        }

        var host = root.getAttribute('data-embed-host');

        if (HOSTS.indexOf(host) === -1) {
            host = HOSTS[0];
        }

        var iframe = document.createElement('iframe');

        iframe.className = 'bltt-gallery__iframe';
        iframe.title = button.getAttribute('data-title') || '';
        iframe.setAttribute('allow', IFRAME_ALLOW);
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        iframe.src = host + '/embed/' + id + '?autoplay=1&rel=0&playsinline=1';

        return iframe;
    }

    /** Put a playing slot back to its thumbnail, which also stops the video. */
    function restore(media) {
        var iframe = media.querySelector('.bltt-gallery__iframe');
        var button = media.querySelector('.bltt-gallery__thumb');

        if (iframe) {
            iframe.remove();
        }
        if (button) {
            button.hidden = false;
        }
        media.classList.remove('is-playing');
    }

    function playIn(root, media, button) {
        var iframe = buildIframe(root, button);

        if (!iframe) {
            return null;
        }

        // One video at a time per gallery.
        root.querySelectorAll('.bltt-gallery__media.is-playing').forEach(restore);

        var facade = media.querySelector('.bltt-gallery__thumb');

        if (facade) {
            facade.hidden = true;
        }

        media.appendChild(iframe);
        media.classList.add('is-playing');

        // The button that had focus is gone; hand focus to the player.
        iframe.focus({ preventScroll: true });

        return iframe;
    }

    function playInline(root, button) {
        var media = button.closest('.bltt-gallery__media');

        if (media) {
            playIn(root, media, button);
        }
    }

    /* ---------------------------------------------------------------
     * Featured layout: thumbnails drive the main player
     * ------------------------------------------------------------- */

    function setText(root, selector, value) {
        var el = root.querySelector(selector);

        if (el) {
            el.textContent = value || '';
        }

        return el;
    }

    function selectFeatured(root, button) {
        var stage = root.querySelector('.bltt-gallery__stage');
        var media = stage && stage.querySelector('.bltt-gallery__media');

        if (!media || !playIn(root, media, button)) {
            return;
        }

        setText(root, '.bltt-gallery__stage-title', button.getAttribute('data-title'));
        setText(root, '.bltt-gallery__stage-desc', button.getAttribute('data-desc'));

        var date = setText(root, '.bltt-gallery__stage-date', button.getAttribute('data-date'));

        if (date) {
            date.setAttribute('datetime', button.getAttribute('data-datetime') || '');
        }

        root.querySelectorAll('.bltt-gallery__items .bltt-gallery__thumb[aria-current]').forEach(function (el) {
            el.removeAttribute('aria-current');
        });
        button.setAttribute('aria-current', 'true');

        stage.scrollIntoView({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' });
    }

    /* ---------------------------------------------------------------
     * Lightbox — a native <dialog>: showModal() gives the focus trap, Esc to
     * close and an inert page behind it. Built on first use, inside the
     * gallery so it inherits the gallery's theming variables.
     * ------------------------------------------------------------- */

    function lightboxSupported() {
        return typeof window.HTMLDialogElement === 'function' &&
            typeof window.HTMLDialogElement.prototype.showModal === 'function';
    }

    function createLightbox(root) {
        var dialog = document.createElement('dialog');
        var bar = document.createElement('div');
        var close = document.createElement('button');
        var frame = document.createElement('div');

        dialog.className = 'bltt-gallery__lightbox';
        bar.className = 'bltt-gallery__lightbox-bar';
        frame.className = 'bltt-gallery__lightbox-frame';

        close.type = 'button';
        close.className = 'bltt-gallery__lightbox-close';
        close.setAttribute('aria-label', root.getAttribute('data-label-close') || 'Close video');
        close.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">' +
            '<path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>';

        bar.appendChild(close);
        dialog.appendChild(bar);
        dialog.appendChild(frame);

        close.addEventListener('click', function () {
            dialog.close();
        });

        // A click that lands on the dialog element itself is on the backdrop.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });

        // Fires for the close button, the backdrop and Esc alike.
        dialog.addEventListener('close', function () {
            frame.textContent = ''; // removing the iframe stops the video

            var opener = dialog.blttOpener;

            dialog.blttOpener = null;

            if (opener && document.contains(opener)) {
                opener.focus();
            }
        });

        root.appendChild(dialog);

        return dialog;
    }

    function openLightbox(root, button) {
        if (!lightboxSupported()) {
            playInline(root, button);
            return;
        }

        var iframe = buildIframe(root, button);

        if (!iframe) {
            return;
        }

        var dialog = root.querySelector('.bltt-gallery__lightbox') || createLightbox(root);
        var frame = dialog.querySelector('.bltt-gallery__lightbox-frame');

        frame.textContent = '';
        frame.appendChild(iframe);

        dialog.setAttribute('aria-label', button.getAttribute('data-title') || '');
        dialog.blttOpener = button;

        if (!dialog.open) {
            dialog.showModal();
        }
    }

    /* ---------------------------------------------------------------
     * Carousel
     * ------------------------------------------------------------- */

    function updateNav(root) {
        var track = root.querySelector('.bltt-gallery__track');
        var controls = root.querySelector('.bltt-gallery__controls');

        if (!track || !controls) {
            return;
        }

        var max = track.scrollWidth - track.clientWidth;
        var position = Math.abs(track.scrollLeft); // negative in RTL

        // Nothing to scroll: the buttons would only be noise.
        controls.hidden = max <= 1;

        var prev = controls.querySelector('.bltt-gallery__nav--prev');
        var next = controls.querySelector('.bltt-gallery__nav--next');

        // aria-disabled rather than disabled, so a focused button doesn't
        // drop keyboard focus when it reaches the end.
        if (prev) {
            prev.setAttribute('aria-disabled', position <= 1 ? 'true' : 'false');
        }
        if (next) {
            next.setAttribute('aria-disabled', position >= max - 1 ? 'true' : 'false');
        }
    }

    function page(root, button) {
        var track = root.querySelector('.bltt-gallery__track');

        if (!track || button.getAttribute('aria-disabled') === 'true') {
            return;
        }

        var direction = button.getAttribute('data-dir') === '-1' ? -1 : 1;

        track.scrollBy({
            left: direction * (isRtl(track) ? -1 : 1) * track.clientWidth,
            behavior: reducedMotion() ? 'auto' : 'smooth'
        });
    }

    /** Arrow keys, Home and End move between videos inside a scroller. */
    function onTrackKeydown(event) {
        var keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];

        if (keys.indexOf(event.key) === -1 || !event.target.closest) {
            return;
        }

        var current = event.target.closest('.bltt-gallery__thumb');
        var track = current && current.closest('.bltt-gallery__track');

        if (!track) {
            return;
        }

        var thumbs = Array.prototype.filter.call(
            track.querySelectorAll('.bltt-gallery__thumb'),
            function (el) { return !el.hidden; }
        );
        var index = thumbs.indexOf(current);
        var forward = isRtl(track) ? 'ArrowLeft' : 'ArrowRight';
        var target;

        if (event.key === 'Home') {
            target = 0;
        } else if (event.key === 'End') {
            target = thumbs.length - 1;
        } else {
            target = index + (event.key === forward ? 1 : -1);
        }

        if (target < 0 || target >= thumbs.length || target === index) {
            return;
        }

        event.preventDefault();
        thumbs[target].focus();
    }

    /* ---------------------------------------------------------------
     * Load more — reveals videos that are already in the page
     * ------------------------------------------------------------- */

    function loadMore(root, button) {
        var hidden = root.querySelectorAll('.bltt-gallery__item[hidden]');
        var batch = parseInt(root.getAttribute('data-per-page'), 10) || hidden.length;
        var first = null;

        Array.prototype.slice.call(hidden, 0, batch).forEach(function (item) {
            item.hidden = false;
            first = first || item.querySelector('.bltt-gallery__thumb');
        });

        if (hidden.length <= batch) {
            var wrap = button.closest('.bltt-gallery__more') || button;

            wrap.remove();
        }

        // Continue from the first newly shown video rather than the button.
        if (first) {
            first.focus({ preventScroll: true });
        }
    }

    /* ---------------------------------------------------------------
     * Thumbnails: maxres → hq fallback
     *
     * For a video with no HD thumbnail YouTube answers maxresdefault.jpg with
     * a 4:3 grey placeholder (and a 404 that still renders as an image), so
     * the tell is the aspect ratio: a real maxres is always 16:9.
     * ------------------------------------------------------------- */

    function useFallback(img) {
        var fallback = img.getAttribute('data-fallback');

        if (!fallback || img.getAttribute('data-fell-back')) {
            return;
        }

        img.setAttribute('data-fell-back', '1');
        img.removeAttribute('srcset');
        img.removeAttribute('sizes');
        img.src = fallback;
    }

    function checkThumb(img) {
        if (!img || !img.classList || !img.classList.contains('bltt-gallery__img')) {
            return;
        }

        var src = img.currentSrc || img.src || '';

        if (src.indexOf('maxresdefault') === -1 || !img.naturalWidth || !img.naturalHeight) {
            return;
        }

        if (img.naturalWidth / img.naturalHeight < 1.5) {
            useFallback(img);
        }
    }

    /* ---------------------------------------------------------------
     * Wiring
     * ------------------------------------------------------------- */

    document.addEventListener('click', function (event) {
        if (!event.target.closest) {
            return;
        }

        var el = event.target.closest('.bltt-gallery__thumb, .bltt-gallery__nav, .bltt-gallery__more-btn');
        var root = el && el.closest('.bltt-gallery');

        if (!root) {
            return;
        }

        if (el.classList.contains('bltt-gallery__nav')) {
            page(root, el);
            return;
        }

        if (el.classList.contains('bltt-gallery__more-btn')) {
            loadMore(root, el);
            return;
        }

        // play="youtube" renders plain links; let them navigate.
        if (el.tagName !== 'BUTTON') {
            return;
        }

        var mode = root.getAttribute('data-play');

        if (mode === 'stage' && !el.closest('.bltt-gallery__stage')) {
            selectFeatured(root, el);
        } else if (mode === 'lightbox') {
            openLightbox(root, el);
        } else {
            playInline(root, el);
        }
    });

    document.addEventListener('keydown', onTrackKeydown);

    // scroll, load and error don't bubble, but they can be captured.
    var scrollQueued = false;

    document.addEventListener('scroll', function (event) {
        var track = event.target;

        if (scrollQueued || !track.classList || !track.classList.contains('bltt-gallery__track')) {
            return;
        }

        scrollQueued = true;

        window.requestAnimationFrame(function () {
            scrollQueued = false;

            var root = track.closest('.bltt-gallery');

            if (root) {
                updateNav(root);
            }
        });
    }, true);

    document.addEventListener('load', function (event) {
        checkThumb(event.target);
    }, true);

    document.addEventListener('error', function (event) {
        var img = event.target;

        if (img && img.classList && img.classList.contains('bltt-gallery__img')) {
            useFallback(img);
        }
    }, true);

    /**
     * Sync state that can't be derived from events alone: carousel button
     * state, and thumbnails that finished loading before this script ran.
     * Safe to call repeatedly; call it after inserting a gallery by script.
     */
    function init(scope) {
        var context = scope && scope.querySelectorAll ? scope : document;

        context.querySelectorAll('.bltt-gallery').forEach(updateNav);

        context.querySelectorAll('.bltt-gallery__img').forEach(function (img) {
            if (img.complete) {
                checkThumb(img);
            }
        });
    }

    window.blttGallery = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }

    window.addEventListener('load', function () { init(); });
    window.addEventListener('resize', function () { init(); });
})();
