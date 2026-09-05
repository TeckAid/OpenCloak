/* Cloaking SaaS - Visitor fingerprint tracker
 * Collects screen info and a persistent visitor token, then re-requests
 * the current URL with the payload attached.
 * Used by: fingerprint interstitial (direct links) and client-deployment mode.
 */
(function () {
    'use strict';

    function randomToken() {
        try {
            if (window.crypto && window.crypto.getRandomValues) {
                var bytes = new Uint8Array(16);
                window.crypto.getRandomValues(bytes);
                var out = '';
                for (var i = 0; i < bytes.length; i++) {
                    out += bytes[i].toString(16).padStart(2, '0');
                }
                return out;
            }
        } catch (e) {}

        return Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    }

    function token(storageKey) {
        if (!/^cloak_vtoken_[a-f0-9]{16}$/.test(storageKey || '')) {
            return '';
        }
        try {
            var t = localStorage.getItem(storageKey);
            if (t) return t;
            t = 'v' + randomToken();
            localStorage.setItem(storageKey, t);
            return t;
        } catch (e) {
            return '';
        }
    }

    function b64url(str) {
        try {
            return btoa(unescape(encodeURIComponent(str)))
                .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        } catch (e) {
            return '';
        }
    }

    function collect() {
        var n = (typeof navigator !== 'undefined') ? navigator : {};
        var ua = n.userAgent || '';
        var touch = (('ontouchstart' in window) || (n.maxTouchPoints > 0));
        return {
            w: window.screen ? window.screen.width : 0,
            h: window.screen ? window.screen.height : 0,
            dpr: window.devicePixelRatio || 1,
            touch: touch ? 1 : 0,
            cores: n.hardwareConcurrency || 0,
            lang: n.language || '',
            tz: (typeof Intl !== 'undefined' && Intl.DateTimeFormat)
                ? Intl.DateTimeFormat().resolvedOptions().timeZone : '',
            os: ua,
            ts: Math.floor(Date.now() / 1000)
        };
    }

    function attachParams(url, params) {
        var sep = url.indexOf('?') === -1 ? '?' : '&';
        return url + sep + params;
    }

    function go() {
        var cfg = window.__CLOAK_CFG__ || {};
        var fp = collect();
        var payload = b64url(JSON.stringify(fp));
        var visitor = token(cfg.visitorStorageKey || '');
        var target = cfg.redirect || window.location.pathname;
        var params = '_fph=' + encodeURIComponent(payload) + '&_fv=' + encodeURIComponent(visitor);
        window.location.replace(attachParams(target, params));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', go);
    } else {
        go();
    }
})();
