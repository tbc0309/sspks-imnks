(function () {
    'use strict';
    var translations = window.SSPKS_I18N || {};
    function t(key, fallback) { return typeof translations[key] === 'string' ? translations[key] : fallback; }
    function setupUpdatePanel() {
        var panel = document.querySelector('[data-update-panel]');
        if (!panel) { return; }
        var form = panel.querySelector('[data-update-form]');
        var input = panel.querySelector('[data-update-token]');
        var buttons = Array.from(form.querySelectorAll('button[type="submit"]'));
        var status = panel.querySelector('[data-update-status]');
        var percent = panel.querySelector('[data-update-percent]');
        var progress = panel.querySelector('[data-update-progress]');
        var successList = panel.querySelector('[data-success-list]');
        var failureList = panel.querySelector('[data-failure-list]');
        var endpoint = panel.getAttribute('data-endpoint');
        var after = 0;
        var taskStarted = false;
        function permanent(message) { var error = new Error(message); error.permanent = true; return error; }
        function render(data) {
            progress.value = data.percent;
            percent.textContent = data.percent + '%';
            ['success','failed','total','added','changed','unchanged','deleted'].forEach(function (key) {
                var node = panel.querySelector('[data-' + (key === 'failed' ? 'failure' : key) + '-count]');
                if (node) { node.textContent = String(data[key] || 0); }
            });
            var message = data.phase === 'complete' ? t('index_complete','Index update completed.')
                : data.phase === 'delete' ? t('index_deleting','Checking removed files…')
                : data.mode === 'verify' ? t('index_verifying','Verifying package files…') : t('index_scanning','Checking changed packages…');
            status.textContent = message + (data.file ? ' ' + data.file : '')
                + (data.filePhase === 'hashing' ? ' · MD5 ' + data.filePercent + '%' : '');
            (data.events || []).forEach(function (event) {
                var list = event.type === 'failure' ? failureList : successList;
                var empty = panel.querySelector(event.type === 'failure' ? '[data-failure-empty]' : '[data-success-empty]');
                empty.hidden = true;
                // Keep the page responsive even for large repositories. All results remain in the task store.
                if (list.children.length >= 500) { list.firstElementChild.remove(); }
                var item = document.createElement('li');
                var name = document.createElement('strong');
                var detail = document.createElement('span');
                name.textContent = event.name;
                detail.textContent = t('index_' + event.category,event.category) + ' · ' + event.detail;
                item.appendChild(name); item.appendChild(detail); list.appendChild(item);
            });
            after = data.after || after;
        }
        async function request(body, token) {
            for (var attempt = 0; attempt < 4; attempt++) {
                var controller = new AbortController();
                var timer = setTimeout(function () { controller.abort(); }, 25000);
                try {
                    var response = await fetch(endpoint, {
                        method:'POST',headers:{'Accept':'application/json','X-SSpkS-Token':token},
                        body:new URLSearchParams(body),cache:'no-store',credentials:'same-origin',signal:controller.signal
                    });
                    // Check statuses before JSON: proxies may replace an error body with HTML.
                    if (response.status === 401 || response.status === 403) {
                        throw permanent(t('auth_invalid','The index update password is incorrect or not configured.'));
                    }
                    if (response.status === 429) {
                        throw permanent(t('auth_rate_limited','Too many password failures. Wait one minute before trying again.'));
                    }
                    if (response.status === 404) {
                        throw permanent(t('update_endpoint_missing','The update endpoint was not found. Check the update URL and server routing.'));
                    }
                    var data = null;
                    try { data = await response.json(); } catch (parseError) {}
                    if (data && data.code === 'auth_unavailable') {
                        throw permanent(t('auth_unavailable','Password verification is unavailable. Check server permissions and logs.'));
                    }
                    if (response.status === 409 || response.status >= 500) { throw new Error('temporary'); }
                    if (!response.ok || (data && data.type === 'error')) {
                        throw permanent(data && data.message ? data.message : t('request_failed','The update request failed.'));
                    }
                    if (!data || !['progress','complete'].includes(data.type) || typeof data.job !== 'string') {
                        throw permanent(t('update_response_invalid','The server returned an invalid update response. Check the proxy and server logs.'));
                    }
                    return data;
                } catch (error) {
                    if (error.permanent || attempt === 3) { throw error; }
                    status.textContent = taskStarted ? t('index_reconnecting','Connection interrupted; resuming saved progress…') : t('update_connect_retry','The connection failed; retrying. No task has been confirmed.');
                    await new Promise(function (resolve) { setTimeout(resolve,1000 * Math.pow(2,attempt)); });
                } finally { clearTimeout(timer); }
            }
        }
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (panel.classList.contains('is-running') || !input.value.trim()) { return; }
            var token = input.value.trim();
            var mode = event.submitter && event.submitter.value === 'verify' ? 'verify' : 'incremental';
            after = 0; taskStarted = false;
            progress.value = 0; percent.textContent = '0%';
            ['success','failure','total','added','changed','unchanged','deleted'].forEach(function (key) {
                var counter = panel.querySelector('[data-'+key+'-count]'); if (counter) { counter.textContent = '0'; }
            });
            successList.textContent = ''; failureList.textContent = '';
            panel.querySelector('[data-success-empty]').hidden = false;
            panel.querySelector('[data-failure-empty]').hidden = false;
            panel.classList.remove('is-error','is-complete');
            panel.classList.add('is-running'); panel.setAttribute('aria-busy','true');
            buttons.forEach(function (button) { button.disabled = true; });
            status.textContent = t('connecting','Connecting to update service…');
            try {
                var data = await request({operation:'start',mode:mode},token);
                taskStarted = true;
                render(data);
                while (data.type !== 'complete') {
                    data = await request({operation:'step',job:data.job,after:String(after)},token);
                    render(data);
                    if (data.type !== 'complete') { await new Promise(function (resolve) { setTimeout(resolve,100); }); }
                }
                panel.classList.add('is-complete');
            } catch (error) {
                panel.classList.add('is-error');
                status.textContent = error.permanent ? error.message : t('request_failed','The update request failed.') + ' ' + (taskStarted ? t('index_resume','Progress is saved. Start the update again to resume.') : t('update_not_confirmed','No update task was confirmed. Check the connection before trying again.'));
            } finally {
                token = ''; input.value = '';
                buttons.forEach(function (button) { button.disabled = false; });
                panel.classList.remove('is-running'); panel.setAttribute('aria-busy','false');
            }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded',setupUpdatePanel,{once:true}); }
    else { setupUpdatePanel(); }
}());