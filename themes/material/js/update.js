(function () {
    'use strict';

    var translations = window.SSPKS_I18N || {};
    function t(key, fallback) {
        return typeof translations[key] === 'string' ? translations[key] : fallback;
    }

    /* Refresh result */
    function appendResult(list, emptyState, event) {
        var item = document.createElement('li');
        var name = document.createElement('strong');
        var detail = document.createElement('span');
        name.textContent = event.name || t('unknown_package', 'Unknown package');
        detail.textContent = event.detail || '';
        item.appendChild(name);
        item.appendChild(detail);
        list.appendChild(item);
        emptyState.hidden = true;
    }

    /* Index refresh */
    function setupUpdatePanel() {
        var panel = document.querySelector('[data-update-panel]');
        if (!panel) {
            return;
        }

        var form = panel.querySelector('[data-update-form]');
        var tokenInput = panel.querySelector('[data-update-token]');
        var button = panel.querySelector('[data-update-button]');
        var progress = panel.querySelector('[data-update-progress]');
        var percent = panel.querySelector('[data-update-percent]');
        var status = panel.querySelector('[data-update-status]');
        var successCount = panel.querySelector('[data-success-count]');
        var failureCount = panel.querySelector('[data-failure-count]');
        var totalCount = panel.querySelector('[data-total-count]');
        var successList = panel.querySelector('[data-success-list]');
        var failureList = panel.querySelector('[data-failure-list]');
        var successEmpty = panel.querySelector('[data-success-empty]');
        var failureEmpty = panel.querySelector('[data-failure-empty]');
        var endpoint = panel.getAttribute('data-endpoint');

        function setProgress(value) {
            var normalized = Math.max(0, Math.min(100, Number(value) || 0));
            progress.value = normalized;
            percent.textContent = normalized + '%';
        }

        function resetPanel() {
            panel.classList.remove('is-complete', 'is-error');
            successList.textContent = '';
            failureList.textContent = '';
            successEmpty.hidden = false;
            failureEmpty.hidden = false;
            successCount.textContent = '0';
            failureCount.textContent = '0';
            totalCount.textContent = '0';
            status.textContent = t('connecting', 'Connecting to update service…');
            setProgress(0);
        }

        function handleEvent(event) {
            if (typeof event.percent !== 'undefined') {
                setProgress(event.percent);
            }
            if (event.message) {
                status.textContent = event.message;
            }

            if (event.type === 'start') {
                totalCount.textContent = String(event.total || 0);
            } else if (event.type === 'progress') {
                if (typeof event.total !== 'undefined') {
                    totalCount.textContent = String(event.total);
                }
            } else if (event.type === 'success') {
                appendResult(successList, successEmpty, event);
                successCount.textContent = String(Number(successCount.textContent) + 1);
            } else if (event.type === 'failure') {
                appendResult(failureList, failureEmpty, event);
                failureCount.textContent = String(Number(failureCount.textContent) + 1);
            } else if (event.type === 'complete') {
                successCount.textContent = String(event.success || 0);
                failureCount.textContent = String(event.failed || 0);
                panel.classList.add('is-complete');
            } else if (event.type === 'error') {
                panel.classList.add('is-error');
            }
        }

        form.addEventListener('submit', function (submitEvent) {
            submitEvent.preventDefault();
            var token = tokenInput.value.trim();
            if (!token || panel.classList.contains('is-running')) {
                return;
            }

            resetPanel();
            panel.classList.add('is-running');
            button.disabled = true;
            button.textContent = t('updating', 'Updating…');

            fetch(endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/x-ndjson',
                    'X-SSpkS-Token': token,
                },
                cache: 'no-store',
                credentials: 'same-origin',
            })
                .then(function (response) {
                    var contentType = response.headers.get('content-type') || '';
                    if (contentType.indexOf('application/x-ndjson') === -1) {
                        throw new Error(t('invalid_response', 'The update service returned an invalid response.') + ' HTTP ' + response.status);
                    }
                    if (!response.body || typeof response.body.getReader !== 'function') {
                        throw new Error(t('no_stream', 'This browser does not support streaming progress.'));
                    }

                    var reader = response.body.getReader();
                    var decoder = new TextDecoder('utf-8');
                    var buffer = '';
                    var finished = false;

                    function consumeLine(line) {
                        if (!line.trim()) {
                            return;
                        }
                        var event = JSON.parse(line);
                        handleEvent(event);
                        if (event.type === 'complete' || event.type === 'error') {
                            finished = true;
                        }
                    }

                    function readChunk() {
                        return reader.read().then(function (result) {
                            buffer += decoder.decode(result.value || new Uint8Array(), { stream: !result.done });
                            var lines = buffer.split('\n');
                            buffer = lines.pop();
                            lines.forEach(consumeLine);

                            if (!result.done) {
                                return readChunk();
                            }
                            if (buffer.trim()) {
                                consumeLine(buffer);
                            }
                            if (!finished) {
                                throw new Error(t('connection_ended', 'The update connection ended early.'));
                            }
                        });
                    }

                    return readChunk();
                })
                .catch(function (error) {
                    panel.classList.add('is-error');
                    var message = error && error.message ? error.message : t('request_failed', 'The update request failed.');
                    if (/network error|failed to fetch|networkerror|更新连接提前中断/i.test(message)) {
                        message = t('resume_message', 'The connection was interrupted. Click Retry update to continue from the latest checkpoint.');
                    }
                    status.textContent = message;
                })
                .finally(function () {
                    tokenInput.value = '';
                    panel.classList.remove('is-running');
                    button.disabled = false;
                    button.textContent = t('retry', 'Retry update');
                });
        });
    }

    /* Page initialization */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupUpdatePanel, { once: true });
    } else {
        setupUpdatePanel();
    }
}());
