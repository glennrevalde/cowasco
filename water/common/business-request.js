/* ==========================================================================
   Water Billing — business-page request helper
   --------------------------------------------------------------------------
   Loaded ONLY through common/navbar.php, i.e. only on authenticated business
   pages. The operator login / registration / password screens never include
   this file and are not affected by it.

   Responsibilities
   1. CSRF: every same-origin POST to ../process/*.php carries the session
      token — as a hidden csrf_token field (forms, FormData bodies), as a
      csrf_token property (JSON bodies) and as an X-CSRF-Token header. Forms
      rendered on the server already contain the field; this is the safety net
      for forms and requests that pages build dynamically.
   2. Fetch wrapper (F08): window.businessFetch / window.businessFetchJson turn
      "the API redirected me to an HTML page" into a visible, actionable message
      instead of a JSON parse error. Session expiry and access denials show a
      link to the unchanged login page; a configuration_required answer opens
      the setup tutorial when it is present on the page.
   Nothing here replays a financial POST after login.
   ========================================================================== */
(function () {
    'use strict';

    var config = window.BusinessPageConfig || {};
    var TOKEN = String(config.csrfToken || '');
    var LOGIN_URL = String(config.loginUrl || 'login.php');

    function isProcessUrl(url) {
        try {
            var resolved = new URL(String(url), window.location.href);
            if (resolved.origin !== window.location.origin) return false;
            return /\/process\/[^\/]+\.php$/i.test(resolved.pathname);
        } catch (e) {
            return false;
        }
    }

    function isAuthUrl(url) {
        try {
            var resolved = new URL(String(url), window.location.href);
            return /\/views\/(login|register|forgot_password|reset_password|account_setup_required)\.php$/i.test(resolved.pathname);
        } catch (e) {
            return false;
        }
    }

    /* ---- 1. CSRF injection ------------------------------------------------ */

    function ensureFormToken(form) {
        if (!(form instanceof HTMLFormElement) || TOKEN === '') return;
        var method = String(form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'post') return;
        if (!isProcessUrl(form.getAttribute('action') || form.action || '')) return;
        var existing = form.querySelector('input[name="csrf_token"]');
        if (existing) {
            if (existing.value === '') existing.value = TOKEN;
            return;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = TOKEN;
        form.appendChild(input);
    }

    document.addEventListener('submit', function (event) {
        ensureFormToken(event.target);
    }, true);

    function primeExistingForms() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) ensureFormToken(forms[i]);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', primeExistingForms, { once: true });
    } else {
        primeExistingForms();
    }

    function withToken(input, init) {
        init = init || {};
        var method = String(init.method || (input instanceof Request ? input.method : 'GET') || 'GET').toUpperCase();
        var url = input instanceof Request ? input.url : String(input);
        if (method === 'GET' || method === 'HEAD' || !isProcessUrl(url) || TOKEN === '') return [input, init];

        var headers = new Headers(init.headers || (input instanceof Request ? input.headers : undefined) || {});
        if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', TOKEN);
        if (!headers.has('X-Requested-With')) headers.set('X-Requested-With', 'XMLHttpRequest');
        init.headers = headers;
        if (init.credentials === undefined) init.credentials = 'same-origin';

        var body = init.body;
        if (body instanceof FormData) {
            if (!body.has('csrf_token')) body.set('csrf_token', TOKEN);
        } else if (body instanceof URLSearchParams) {
            if (!body.has('csrf_token')) body.set('csrf_token', TOKEN);
        } else if (typeof body === 'string' && body !== '') {
            var contentType = String(headers.get('Content-Type') || '').toLowerCase();
            if (contentType.indexOf('application/json') !== -1) {
                try {
                    var parsed = JSON.parse(body);
                    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed) && parsed.csrf_token === undefined) {
                        parsed.csrf_token = TOKEN;
                        init.body = JSON.stringify(parsed);
                    }
                } catch (e) { /* not JSON after all; header still carries the token */ }
            } else if (contentType.indexOf('application/x-www-form-urlencoded') !== -1 && body.indexOf('csrf_token=') === -1) {
                init.body = body + '&csrf_token=' + encodeURIComponent(TOKEN);
            }
        }
        return [input, init];
    }

    if (window.fetch && !window.fetch.__businessWrapped) {
        var nativeFetch = window.fetch.bind(window);
        var wrapped = function (input, init) {
            var pair = withToken(input, init);
            return nativeFetch(pair[0], pair[1]);
        };
        wrapped.__businessWrapped = true;
        window.fetch = wrapped;
    }

    /* ---- 2. Response interpretation (F08) --------------------------------- */

    var notice = null;
    function showNotice(message, options) {
        options = options || {};
        if (!notice) {
            notice = document.createElement('div');
            notice.className = 'business-request-notice';
            notice.setAttribute('role', 'alert');
            notice.setAttribute('aria-live', 'assertive');
            document.body.appendChild(notice);
        }
        notice.innerHTML = '';
        var text = document.createElement('div');
        text.className = 'business-request-notice-text';
        text.textContent = message;
        notice.appendChild(text);
        if (options.loginLink) {
            var link = document.createElement('a');
            link.href = LOGIN_URL;
            link.className = 'business-request-notice-link';
            link.textContent = 'Go to the sign-in page';
            notice.appendChild(link);
        }
        if (options.configure && options.configure.destination) {
            var cfg = document.createElement('a');
            cfg.href = options.configure.destination;
            cfg.className = 'business-request-notice-link';
            cfg.textContent = 'Configure now';
            notice.appendChild(cfg);
        }
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'business-request-notice-close';
        close.setAttribute('aria-label', 'Dismiss message');
        close.textContent = '×';
        close.addEventListener('click', function () { notice.classList.remove('is-visible'); });
        notice.appendChild(close);
        notice.classList.add('is-visible');
        if (options.configure && window.SetupTutorial && typeof window.SetupTutorial.showRequirement === 'function') {
            window.SetupTutorial.showRequirement(options.configure);
        }
    }

    function BusinessRequestError(message, details) {
        var err = new Error(message);
        err.name = 'BusinessRequestError';
        err.details = details || {};
        return err;
    }

    /**
     * Interpret a fetch() response from a business endpoint.
     * Resolves with parsed JSON. Rejects with a BusinessRequestError whose
     * message is safe to display; the user-visible notice is shown already.
     */
    function interpret(response) {
        var contentType = String(response.headers.get('Content-Type') || '').toLowerCase();
        var finalUrl = response.url || '';

        if (response.redirected && isAuthUrl(finalUrl)) {
            showNotice('Your session has ended or this action is not allowed for your profile. Nothing was saved.', { loginLink: true });
            return Promise.reject(BusinessRequestError('Session ended. Sign in again to continue.', { code: 'auth_redirect' }));
        }

        if (contentType.indexOf('application/json') === -1) {
            return response.text().then(function (text) {
                var looksLikeLogin = /login\.php|Sign in|Operator Login/i.test(text) || isAuthUrl(finalUrl);
                if (response.status === 401 || response.status === 403 || looksLikeLogin) {
                    showNotice('Your session has ended or this action is not allowed for your profile. Nothing was saved.', { loginLink: true });
                    return Promise.reject(BusinessRequestError('Session ended or access denied.', { code: 'auth', status: response.status }));
                }
                showNotice('The server did not answer this request with data (HTTP ' + response.status + '). Reload the page and try again. Nothing was saved.');
                return Promise.reject(BusinessRequestError('Unexpected non-JSON response (HTTP ' + response.status + ').', { code: 'non_json', status: response.status }));
            });
        }

        return response.json().then(function (payload) {
            if (payload && payload.code === 'configuration_required') {
                showNotice(payload.message || payload.error || 'Configuration is required before this action can run.', { configure: payload.configuration_required || {} });
                return Promise.reject(BusinessRequestError(payload.message || payload.error || 'Configuration required.', { code: 'configuration_required', payload: payload }));
            }
            if (response.status === 401) {
                showNotice(payload.error || payload.message || 'Your session has ended.', { loginLink: true });
                return Promise.reject(BusinessRequestError(payload.error || 'Session ended.', { code: 'auth', status: 401 }));
            }
            if (response.status === 419 || (response.status === 403 && payload && payload.code === 'csrf_invalid')) {
                showNotice(payload.error || payload.message || 'Your form session expired. Reload the page and try again.');
                return Promise.reject(BusinessRequestError(payload.error || 'Security token expired.', { code: 'csrf', status: response.status }));
            }
            if (response.status === 403) {
                showNotice(payload.error || payload.message || 'This action is not allowed for your profile.');
                return Promise.reject(BusinessRequestError(payload.error || 'Access denied.', { code: 'forbidden', status: 403 }));
            }
            return payload;
        }, function () {
            showNotice('The server returned an invalid response. Reload the page and try again. Nothing was saved.');
            return Promise.reject(BusinessRequestError('Invalid JSON response.', { code: 'invalid_json' }));
        });
    }

    function businessFetch(input, init) {
        return window.fetch(input, init);
    }

    function businessFetchJson(input, init) {
        init = init || {};
        var headers = new Headers(init.headers || {});
        if (!headers.has('Accept')) headers.set('Accept', 'application/json');
        init.headers = headers;
        return window.fetch(input, init).then(interpret, function (networkError) {
            showNotice('The request could not reach the server. Check the connection and try again. Nothing was saved.');
            throw BusinessRequestError('Network error: ' + (networkError && networkError.message ? networkError.message : 'unreachable'), { code: 'network' });
        });
    }

    window.businessFetch = businessFetch;
    window.businessFetchJson = businessFetchJson;
    window.BusinessRequest = {
        csrfToken: TOKEN,
        loginUrl: LOGIN_URL,
        isProcessUrl: isProcessUrl,
        interpret: interpret,
        showNotice: showNotice,
        withToken: withToken
    };
})();
