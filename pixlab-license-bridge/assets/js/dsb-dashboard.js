(function () {
    if (window.DSB_PIXLAB_DASHBOARD_INIT) return;
    window.DSB_PIXLAB_DASHBOARD_INIT = true;

    const data = window.dsbDashboardData || {};
    const labels = data.labels || {};
    const root = document.querySelector('.dsb-dashboard');
    if (!root || !data.ajaxUrl || !data.nonce) {
        return;
    }

    const statusLabels = {
        enabled: labels.dsb_label_enabled || 'Enabled',
        disabled: labels.dsb_label_disabled || 'Disabled',
        provisioning: labels.dsb_label_provisioning || (data.strings && data.strings.provisioning) || 'Provisioning...',
        expiredMessage:
            labels.dsb_label_expired_message ||
            'Your API key is expired. Please renew to enable.',
    };

    const els = {
        planName: root.querySelector('[data-plan-name]'),
        planLimit: root.querySelector('[data-plan-limit]'),
        billing: root.querySelector('[data-billing-window]'),
        keyDisplay: root.querySelector('[data-key-display]'),
        keyCreated: root.querySelector('[data-key-created]'),
        keyStatus: root.querySelector('[data-key-status]'),
        keyToggle: root.querySelector('[data-toggle-key]'),
        rotate: root.querySelector('[data-rotate-key]'),
        usageCalls: root.querySelector('[data-usage-calls]'),
        usagePercent: root.querySelector('[data-usage-percent]'),
        progress: root.querySelector('[data-progress-bar]'),
        endpoint: {
            h2i: root.querySelector('[data-endpoint-h2i]'),
            image: root.querySelector('[data-endpoint-image]'),
            pdf: root.querySelector('[data-endpoint-pdf]'),
            tools: root.querySelector('[data-endpoint-tools]'),
        },
        modal: root.querySelector('[data-modal]'),
        modalKey: root.querySelector('[data-modal-key]'),
        modalCopy: root.querySelector('[data-modal-copy]'),
        modalClose: root.querySelector('[data-modal-close]'),
        modalOverlay: root.querySelector('[data-modal-overlay]'),
        logs: {
            rows: root.querySelector('[data-log-rows]'),
            empty: root.querySelector('[data-log-empty]'),
            loading: root.querySelector('[data-log-loading]'),
            pagination: root.querySelector('[data-log-pagination]'),
            prev: root.querySelector('[data-log-prev]'),
            next: root.querySelector('[data-log-next]'),
            page: root.querySelector('[data-log-page]'),
        },
    };

    const state = {
        maskedKey: '',
        keyEnabled: true,
        lastSummary: null,
        logsPage: 1,
        perPage: 20,
    };

    function formatMasked(prefix, last4) {
        if (!prefix && !last4) return data.strings.loading || '—';
        return `${prefix || ''}••••${last4 || ''}`;
    }

    function post(action, payload = {}) {
        const body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', data.nonce);
        Object.keys(payload).forEach((key) => body.append(key, payload[key]));

        return fetch(data.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
        }).then((res) => res.json());
    }

    function handleResponse(json) {
        if (!json || json.status !== 'ok') {
            const message = json && json.message ? json.message : data.strings.error;
            if (data.isAdmin && json && json.debug) {
                console.warn('DSB dashboard debug:', json.debug);
            }
            throw new Error(message);
        }
        return json;
    }

    function setStatus(text, type) {
        if (!els.keyStatus) return;
        els.keyStatus.textContent = text || '';
        els.keyStatus.className = 'dsb-status ' + (type ? 'is-' + type : '');
    }

    function showToast(message) {
        const toast = document.createElement('div');
        toast.className = 'dsb-status is-success';
        toast.style.position = 'fixed';
        toast.style.bottom = '16px';
        toast.style.right = '16px';
        toast.style.zIndex = '10000';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    }

    function renderSummary(res) {
        const plan = res.plan || {};
        const key = res.key || {};
        const usage = res.usage || {};
        const per = res.per_endpoint || {};
        const validity = res.plan_validity || '';
        const provisioningStatus = (res.provisioning_status || '').toLowerCase();

        state.lastSummary = res;

        const keyStatus = (key.status || '').toLowerCase();
        const hasKeyMaterial = Boolean(key.key_prefix || key.key_last4);
        const isExpired = isKeyExpired(res);
        const isActive = keyStatus === 'active' && !isExpired;
        const isDisabled = keyStatus === 'disabled' || isExpired;
        const isProvisioning = provisioningStatus === 'pending' && !hasKeyMaterial && !isExpired;

        state.keyEnabled = isActive;
        state.maskedKey = key.key_prefix || key.key_last4 ? formatMasked(key.key_prefix, key.key_last4) : data.strings.loading;

        if (els.keyDisplay) {
            els.keyDisplay.value = key.key_prefix || key.key_last4 ? state.maskedKey : data.strings.loading;
        }
        if (els.keyCreated) {
            const createdLabel = labels.label_created || 'Created';
            els.keyCreated.textContent = key.created_at ? `${createdLabel} ${key.created_at}` : '';
        }
        updateToggleButton();
        if (isProvisioning) {
            const pendingText = statusLabels.provisioning;
            const nextRetry = res.next_retry_at ? ` ${data.strings.provisioningNext} ${res.next_retry_at}` : '';
            setStatus(`${pendingText}${nextRetry}`, 'muted');
        } else if (provisioningStatus === 'failed' && !isActive && !isDisabled) {
            const failedText = res.last_error || data.strings.provisioningFailed;
            setStatus(failedText, 'error');
        } else if (isActive) {
            setStatus(statusLabels.enabled, 'success');
        } else {
            setStatus(statusLabels.disabled, 'muted');
        }

        if (els.planName) {
            els.planName.textContent = plan.name || '—';
        }
        if (els.planLimit) {
            const limit = plan.limit != null ? plan.limit : null;
            const period = plan.billing_period ? `${plan.billing_period} plan` : '';
            const limitLabel = labels.label_usage_metered || 'Monthly limit';
            els.planLimit.textContent = limit != null ? `${period ? period + ' · ' : ''}${limitLabel}: ${limit}` : period || '';
        }
        if (els.billing) {
            els.billing.textContent = validity;
        }

        applyUsage(usage, per);
    }

    function applyUsage(usage = {}, per = {}) {
        const used = usage.total_calls_used ?? usage.used ?? 0;
        const limit = usage.total_calls_limit ?? usage.limit ?? null;
        const percent = usage.percent != null ? usage.percent : limit ? Math.min(100, Math.round((used / limit) * 100)) : null;

        const hasLimit = limit !== null && limit !== undefined;
        const usedLabel = labels.label_used_calls || 'Used Calls';

        if (els.usageCalls) {
            els.usageCalls.textContent = hasLimit ? `${usedLabel}: ${used} / ${limit}` : `${usedLabel}: ${used}`;
        }
        if (els.usagePercent) {
            els.usagePercent.textContent = percent != null ? `${percent}%` : '';
        }
        if (els.progress) {
            els.progress.style.width = `${percent != null ? percent : Math.min(100, used)}%`;
        }

        if (els.endpoint.h2i) els.endpoint.h2i.textContent = `${per.h2i_calls || 0} calls`;
        if (els.endpoint.image) els.endpoint.image.textContent = `${per.image_calls || 0} calls`;
        if (els.endpoint.pdf) els.endpoint.pdf.textContent = `${per.pdf_calls || 0} calls`;
        if (els.endpoint.tools) els.endpoint.tools.textContent = `${per.tools_calls || 0} calls`;
    }

    function formatBytes(bytes) {
        if (bytes === null || bytes === undefined) return '—';
        const value = Number(bytes);
        if (Number.isNaN(value)) return '—';

        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let size = value;
        let unitIndex = 0;

        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024;
            unitIndex += 1;
        }

        if (unitIndex === 0) return `${value} B`;
        return `${size.toFixed(1)} ${units[unitIndex]}`;
    }

    function handleRotate() {
        if (!window.confirm(data.strings.confirmRotate)) return;
        if (els.rotate) {
            els.rotate.disabled = true;
        }

        post('dsb_dashboard_rotate')
            .then(handleResponse)
            .then((json) => {
                const res = json || {};
                state.maskedKey = formatMasked(res.key_prefix, res.key_last4);
                if (els.keyDisplay) {
                    els.keyDisplay.value = res.key ? res.key : state.maskedKey;
                }
                openKeyModal(res.key || '');
                fetchSummary();
                loadLogs(1);
            })
            .catch((err) => alert(err.message || data.strings.rotateError))
            .finally(() => {
                if (els.rotate) {
                    els.rotate.disabled = false;
                }
            });
    }

    function handleToggle() {
        const nextState = !state.keyEnabled;
        if (els.keyToggle) {
            els.keyToggle.disabled = true;
        }

        if (nextState && isKeyExpired(state.lastSummary)) {
            setStatus(statusLabels.disabled, 'muted');
            alert(statusLabels.expiredMessage);
            if (els.keyToggle) {
                els.keyToggle.disabled = false;
            }
            return;
        }

        post('dsb_dashboard_toggle', { enabled: nextState ? '1' : '' })
            .then(handleResponse)
            .then(() => {
                state.keyEnabled = nextState;
                updateToggleButton();
                setStatus(nextState ? statusLabels.enabled : statusLabels.disabled, nextState ? 'success' : 'muted');
                fetchSummary();
                showToast(data.strings.toastSuccess);
            })
            .catch((err) => alert(err.message || data.strings.toggleError))
            .finally(() => {
                if (els.keyToggle) {
                    els.keyToggle.disabled = false;
                }
            });
    }

    function updateToggleButton() {
        if (!els.keyToggle) return;
        const toggleOff = (data.strings && data.strings.toggleOff) || 'Disable Key';
        const toggleOn = (data.strings && data.strings.toggleOn) || 'Enable Key';
        els.keyToggle.textContent = state.keyEnabled ? toggleOff : toggleOn;
    }

    function isKeyExpired(summary) {
        if (!summary) return false;
        const key = summary.key || {};
        const status = (key.subscription_status || '').toLowerCase();
        if (status === 'expired') return true;

        const validUntil = key.valid_until || '';
        if (!validUntil || validUntil === '—') return false;

        const normalized = String(validUntil).replace(/\//g, '-');
        const parsed = Date.parse(`${normalized}T23:59:59Z`);
        if (Number.isNaN(parsed)) return false;
        return parsed < Date.now();
    }

    function openKeyModal(key) {
        if (!els.modal) return;
        els.modal.classList.add('is-open');
        els.modal.setAttribute('aria-hidden', 'false');
        if (els.modalKey) {
            els.modalKey.value = key;
        }
        document.addEventListener('keydown', handleEscClose);
    }

    function closeKeyModal() {
        if (!els.modal) return;
        els.modal.classList.remove('is-open');
        els.modal.setAttribute('aria-hidden', 'true');
        if (els.modalKey) {
            els.modalKey.value = '';
        }
        document.removeEventListener('keydown', handleEscClose);
    }

    function handleEscClose(event) {
        if (event.key === 'Escape') {
            closeKeyModal();
        }
    }

    function handleModalBackdrop(event) {
        if (event.target === els.modalOverlay || event.target === els.modal) {
            closeKeyModal();
        }
    }

    function handleCopy(target) {
        if (!target || !target.value) return;
        target.select();
        target.setSelectionRange(0, target.value.length);
        navigator.clipboard.writeText(target.value).catch(() => {});
    }

    function fetchSummary() {
        post('dsb_dashboard_summary')
            .then(handleResponse)
            .then(renderSummary)
            .catch((err) => {
                setStatus(err.message || data.strings.error, 'error');
            });
    }

    function renderLogs(payload = {}) {
        const rows = payload.items || [];
        const page = payload.page || 1;
        const perPage = payload.per_page || state.perPage;
        const total = payload.total || rows.length;
        const totalPages = Math.max(1, Math.ceil(total / perPage));

        const displayValue = (value) => {
            if (value === null || value === undefined) return '—';
            if (typeof value === 'string' && value.trim() === '') return '—';
            return value;
        };

        if (els.logs.loading) {
            els.logs.loading.style.display = 'none';
        }

        if (els.logs.rows) {
            els.logs.rows.innerHTML = '';
            rows.forEach((item) => {
                const tr = document.createElement('tr');

                const status = (item.status || '').toLowerCase();
                const statusText =
                    status === 'success' ? 'Success' : status === 'error' ? 'Error' : '—';

                let errorText = '—';
                if (status === 'error') {
                    const message = displayValue(item.error_message);
                    const combined = displayValue(item.error);
                    const code = displayValue(item.error_code);
                    errorText =
                        message !== '—'
                            ? message
                            : combined !== '—'
                            ? combined
                            : code !== '—'
                            ? code
                            : 'Request failed.';
                }

                [
                    displayValue(item.timestamp),
                    displayValue(item.endpoint),
                    displayValue(item.action),
                    displayValue(item.files),
                    formatBytes(item.bytes_in),
                    formatBytes(item.bytes_out),
                    errorText,
                    statusText,
                ].forEach((value, index) => {
                    const td = document.createElement('td');
                    td.textContent = value;

                    if (index === 7 && status) {
                        td.classList.add(`is-${status}`);
                    }

                    if (index === 6 && status === 'error') {
                        td.classList.add('dsb-error');
                    }

                    tr.appendChild(td);
                });

                els.logs.rows.appendChild(tr);
            });
        }

        if (els.logs.empty) {
            els.logs.empty.style.display = rows.length ? 'none' : '';
        }

        if (els.logs.pagination && els.logs.page && els.logs.prev && els.logs.next) {
            els.logs.pagination.style.display = totalPages > 1 ? '' : 'none';
            els.logs.page.textContent = `Page ${page} of ${totalPages}`;
            els.logs.prev.disabled = page <= 1;
            els.logs.next.disabled = page >= totalPages;
            els.logs.prev.onclick = () => loadLogs(page - 1);
            els.logs.next.onclick = () => loadLogs(page + 1);
        }

        state.logsPage = page;
    }

    function loadLogs(page = 1) {
        if (els.logs.loading) {
            els.logs.loading.style.display = '';
        }
        post('dsb_dashboard_logs', { page, per_page: state.perPage })
            .then(handleResponse)
            .then(renderLogs)
            .catch((err) => {
                if (els.logs.loading) {
                    els.logs.loading.textContent = err.message || data.strings.error;
                }
            });
    }

    // Events
    if (els.rotate) {
        els.rotate.addEventListener('click', handleRotate);
    }
    if (els.keyToggle) {
        els.keyToggle.addEventListener('click', handleToggle);
    }
    if (els.modalCopy) {
        els.modalCopy.addEventListener('click', () => handleCopy(els.modalKey));
    }
    if (els.modalClose) {
        els.modalClose.addEventListener('click', closeKeyModal);
    }
    if (els.modalOverlay) {
        els.modalOverlay.addEventListener('click', handleModalBackdrop);
    }
    if (els.modal) {
        els.modal.addEventListener('click', handleModalBackdrop);
    }

    fetchSummary();
    loadLogs(state.logsPage);
})();
