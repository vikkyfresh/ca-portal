/**
 * ui-notify.js — replaces native alert()/confirm() popups (the ones that show
 * "localhost says") with styled in-app toast and confirm dialogs that match
 * the portal's own design instead of the browser's default chrome.
 *
 * Usage:
 *   notify('Something happened', 'success' | 'error' | 'warning' | 'info');
 *   const ok = await confirmDialog('Are you sure?', { title: 'Confirm', confirmText: 'Yes, continue' });
 */

(function () {
    if (window.notify && window.confirmDialog) return; // already loaded

    function ensureStyles() {
        if (document.getElementById('ui-notify-styles')) return;
        var style = document.createElement('style');
        style.id = 'ui-notify-styles';
        style.textContent = `
            .un-toast-wrap { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 100000; display: flex; flex-direction: column; gap: 10px; align-items: center; pointer-events: none; }
            .un-toast { pointer-events: auto; min-width: 260px; max-width: 420px; padding: 14px 18px; border-radius: 12px; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; font-size: .9rem; font-weight: 500; box-shadow: 0 10px 30px rgba(0,0,0,.25); display: flex; align-items: flex-start; gap: 10px; opacity: 0; transform: translateY(-12px); transition: all .25s ease; line-height: 1.5; }
            .un-toast.show { opacity: 1; transform: translateY(0); }
            .un-toast i { margin-top: 2px; flex-shrink: 0; }
            .un-toast.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
            .un-toast.error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
            .un-toast.warning { background: #fefce8; color: #854d0e; border: 1px solid #fde68a; }
            .un-toast.info    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
            .un-confirm-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.6); z-index: 100001; display: flex; align-items: center; justify-content: center; padding: 20px; opacity: 0; transition: opacity .2s ease; }
            .un-confirm-backdrop.show { opacity: 1; }
            .un-confirm-box { background: #fff; border-radius: 18px; padding: 28px 26px; max-width: 400px; width: 100%; box-shadow: 0 25px 60px rgba(0,0,0,.4); font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; transform: scale(.95); transition: transform .2s ease; }
            .un-confirm-backdrop.show .un-confirm-box { transform: scale(1); }
            .un-confirm-box h3 { font-size: 1.1rem; font-weight: 800; color: #0f172a; margin-bottom: 8px; }
            .un-confirm-box p { font-size: .9rem; color: #64748b; line-height: 1.6; margin-bottom: 22px; }
            .un-confirm-actions { display: flex; gap: 10px; }
            .un-confirm-actions button { flex: 1; padding: 12px; border-radius: 10px; font-size: .88rem; font-weight: 700; cursor: pointer; border: none; transition: opacity .15s; }
            .un-confirm-actions button:hover { opacity: .88; }
            .un-btn-cancel { background: #f1f5f9; color: #475569; }
            .un-btn-confirm { background: linear-gradient(to right, #0f172a, #1e3a8a); color: #fff; }
        `;
        document.head.appendChild(style);
    }

    function getToastWrap() {
        var wrap = document.querySelector('.un-toast-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'un-toast-wrap';
            document.body.appendChild(wrap);
        }
        return wrap;
    }

    var ICONS = {
        success: 'fa-circle-check',
        error: 'fa-circle-xmark',
        warning: 'fa-triangle-exclamation',
        info: 'fa-circle-info'
    };

    window.notify = function (message, type, duration) {
        type = type || 'info';
        duration = duration || 4500;
        ensureStyles();
        var wrap = getToastWrap();
        var toast = document.createElement('div');
        toast.className = 'un-toast ' + type;
        var iconClass = ICONS[type] || ICONS.info;
        var hasFA = !!document.querySelector('link[href*="font-awesome"]');
        toast.innerHTML = (hasFA ? '<i class="fas ' + iconClass + '"></i>' : '') + '<span>' + message + '</span>';
        wrap.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('show'); });
        setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () { toast.remove(); }, 250);
        }, duration);
    };

    window.confirmDialog = function (message, opts) {
        opts = opts || {};
        ensureStyles();
        return new Promise(function (resolve) {
            var backdrop = document.createElement('div');
            backdrop.className = 'un-confirm-backdrop';
            backdrop.innerHTML =
                '<div class="un-confirm-box">' +
                    '<h3>' + (opts.title || 'Please Confirm') + '</h3>' +
                    '<p>' + message + '</p>' +
                    '<div class="un-confirm-actions">' +
                        '<button class="un-btn-cancel" type="button">' + (opts.cancelText || 'Cancel') + '</button>' +
                        '<button class="un-btn-confirm" type="button">' + (opts.confirmText || 'Confirm') + '</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(backdrop);
            requestAnimationFrame(function () { backdrop.classList.add('show'); });

            function close(result) {
                backdrop.classList.remove('show');
                setTimeout(function () { backdrop.remove(); }, 200);
                resolve(result);
            }
            backdrop.querySelector('.un-btn-cancel').addEventListener('click', function () { close(false); });
            backdrop.querySelector('.un-btn-confirm').addEventListener('click', function () { close(true); });
            backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(false); });
        });
    };
})();
