(function () {
    if (window.DSB_PIXLAB_DASHBOARD_INIT) return;
    window.DSB_PIXLAB_DASHBOARD_INIT = true;

    const data = window.dsbDashboardData || {};
    const root = document.querySelector('.dsb-dashboard');
    if (!root || !data.ajaxUrl || !data.nonce) {
        return;
    }

    const els = {
        planName: root.querySelector('[data-plan-name]'),
        planLimit: root.querySelector('[data-plan-limit]'),
        billing: root.querySelector('[data-billing-window]'),
        keyDisplay: root.querySelector('[data-key-display]'),
        keyCreated: root.querySelector('[data-key-created]'),
        keyStatus: root.querySelector('[data-key-status]'),
        keyToggle: root.querySelector('[data-toggle-key]'),
        keyCopy: root.querySelector('[data-copy-key]'),
        rotate: root.querySelector('[data-rotate-key]'),
        usageCalls: root.querySelector('[data-usage-calls]'),
        usagePercent: root.querySelector('[data-usage-percent]'),
        usageTotal: root.querySelector('[data-usage-total]'),
        usageWindow: root.querySelector('[data-usage-window]'),
        progress: root.querySelector('[data-progress-bar]'),
        rangeButtons: root.querySelectorAll('[data-range]'),
        legend: root.querySelector('[data-chart-legend]'),
        endpoint: {
            h2i: root.querySelector('[data-endpoint-h2i]'),
            image: root.querySelector('[data-endpoint-image]'),
            pdf: root.querySelector('[data-endpoint-pdf]'),
            tools: root.querySelector('[data-endpoint-tools]'),
        },
        modal: root.querySelector('[data-modal]'),
        modalMessage: root.querySelector('[data-modal-message]'),
        modalKey: root.querySelector('[data-modal-key]'),
        modalCopy: root.querySelector('[data-modal-copy]'),
        modalClose: root.querySelector('[data-modal-close]'),
        modalOverlay: root.querySelector('[data-modal-overlay]'),
    };

    const colors = data.colors || {
        h2i: '#0ea5e9',
        image: '#22c55e',
        pdf: '#a855f7',
        tools: '#f97316',
    };

    const state = {
        chart: null,
        range: data.defaultRange || 'daily',
        maskedKey: '',
        keyEnabled: true,
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
        const billing = res.billing || {};
        const per = res.per_endpoint || {};

        const enabled = key.enabled !== false && (key.status || '').toLowerCase() !== 'disabled';
        state.keyEnabled = enabled;
        state.maskedKey = key.key_prefix || key.key_last4 ? formatMasked(key.key_prefix, key.key_last4) : data.strings.loading;

        if (els.keyDisplay) {
            els.keyDisplay.value = key.key_prefix || key.key_last4 ? state.maskedKey : data.strings.loading;
        }
        if (els.keyCreated) {
            els.keyCreated.textContent = key.created_at ? `Created ${key.created_at}` : '';
        }
        updateToggleButton();
        setStatus(enabled ? 'Active' : 'Disabled', enabled ? 'success' : 'muted');

        if (els.planName) {
            els.planName.textContent = plan.name || '—';
        }
        if (els.planLimit) {
            els.planLimit.textContent = plan.call_limit_per_month
                ? `${plan.call_limit_per_month} calls/month`
                : 'Usage metered';
        }
        if (els.billing) {
            const start = billing.start || '';
            const end = billing.end || '';
            els.billing.textContent = start || end ? `${start} – ${end}` : '';
        }

        applyUsage(usage, billing, per);
        if (res.history || res.labels || res.series) {
            renderHistory(res.history || { labels: res.labels || [], series: res.series || {} });
        }
    }

    function applyUsage(usage = {}, billing = {}, per = {}) {
        const used = usage.total_calls_used ?? usage.used ?? 0;
        const limit = usage.total_calls_limit ?? usage.limit ?? null;
        const percent = usage.percent != null ? usage.percent : limit ? Math.min(100, Math.round((used / limit) * 100)) : null;

        if (els.usageCalls) {
            els.usageCalls.textContent = limit ? `Used Calls: ${used} / ${limit}` : `Used Calls: ${used}`;
        }
        if (els.usagePercent) {
            els.usagePercent.textContent = percent != null ? `${percent}%` : '';
        }
        if (els.progress) {
            els.progress.style.width = `${percent != null ? percent : Math.min(100, used)}%`;
        }
        if (els.usageTotal) {
            els.usageTotal.textContent = billing.period ? `Period: ${billing.period}` : '';
        }
        if (els.usageWindow) {
            const start = billing.start || '';
            const end = billing.end || '';
            els.usageWindow.textContent = start || end ? `${start} – ${end}` : '';
        }

        if (els.endpoint.h2i) els.endpoint.h2i.textContent = `${per.h2i_calls || 0} calls`;
        if (els.endpoint.image) els.endpoint.image.textContent = `${per.image_calls || 0} calls`;
        if (els.endpoint.pdf) els.endpoint.pdf.textContent = `${per.pdf_calls || 0} calls`;
        if (els.endpoint.tools) els.endpoint.tools.textContent = `${per.tools_calls || 0} calls`;
    }

    function renderHistory(payload) {
        const labels = payload.labels || [];
        const series = payload.series || {};
        const ctx = document.getElementById('dsb-usage-chart');
        if (!ctx) return;

        if (state.chart && typeof state.chart.destroy === 'function') {
            state.chart.destroy();
        }

        const datasets = [
            { label: 'H2I', data: series.h2i || [], borderColor: colors.h2i, backgroundColor: `${colors.h2i}80`, stack: 'stack' },
            { label: 'Image', data: series.image || [], borderColor: colors.image, backgroundColor: `${colors.image}80`, stack: 'stack' },
            { label: 'PDF', data: series.pdf || [], borderColor: colors.pdf, backgroundColor: `${colors.pdf}80`, stack: 'stack' },
            { label: 'Tools', data: series.tools || [], borderColor: colors.tools, backgroundColor: `${colors.tools}80`, stack: 'stack' },
        ];

        updateLegend(datasets);

        state.chart = new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true },
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label(context) {
                                const label = context.dataset.label || '';
                                return `${label}: ${context.raw || 0}`;
                            },
                        },
                    },
                },
            },
        });
    }

    function updateLegend(datasets) {
        if (!els.legend) return;
        els.legend.innerHTML = '';
        datasets.forEach((ds) => {
            const item = document.createElement('span');
            item.className = 'dsb-legend__item';
            const swatch = document.createElement('span');
            swatch.className = 'dsb-legend__swatch';
            swatch.style.backgroundColor = ds.borderColor || ds.backgroundColor;
            const label = document.createElement('span');
            label.textContent = ds.label;
            item.appendChild(swatch);
            item.appendChild(label);
            els.legend.appendChild(item);
        });
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
                fetchUsage(state.range);
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

        post('dsb_dashboard_toggle', { enabled: nextState ? '1' : '' })
            .then(handleResponse)
            .then(() => {
                state.keyEnabled = nextState;
                updateToggleButton();
                setStatus(nextState ? 'Active' : 'Disabled', nextState ? 'success' : 'muted');
                fetchSummary();
                fetchUsage(state.range);
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
        els.keyToggle.textContent = state.keyEnabled ? data.strings.toggleOff : data.strings.toggleOn;
    }

    function openKeyModal(key) {
        if (!els.modal) return;
        els.modal.classList.add('is-open');
        els.modal.setAttribute('aria-hidden', 'false');
        if (els.modalKey) {
            els.modalKey.value = key;
        }
        if (els.modalMessage) {
            els.modalMessage.textContent = data.strings.shownOnce;
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
        navigator.clipboard
            .writeText(target.value)
            .then(() => alert(data.strings.copied))
            .catch(() => alert(data.strings.copyFailed));
    }

    function setRange(range) {
        state.range = range;
        els.rangeButtons.forEach((btn) => btn.classList.toggle('is-active', btn.dataset.range === range));
        fetchUsage(range);
    }

    function fetchSummary() {
        post('dsb_dashboard_summary')
            .then(handleResponse)
            .then(renderSummary)
            .catch((err) => {
                setStatus(err.message || data.strings.error, 'error');
            });
    }

    function fetchUsage(range) {
        post('dsb_dashboard_usage', { range })
            .then(handleResponse)
            .then((json) => {
                applyUsage(json.usage || {}, json.billing || {}, json.per_endpoint || {});
                renderHistory(json.history || { labels: json.labels || [], series: json.series || {} });
            })
            .catch((err) => setStatus(err.message || data.strings.usageError, 'error'));
    }

    // Events
    if (els.rotate) {
        els.rotate.addEventListener('click', handleRotate);
    }
    if (els.keyCopy) {
        els.keyCopy.addEventListener('click', () => handleCopy(els.keyDisplay));
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
    els.rangeButtons.forEach((btn) => {
        btn.addEventListener('click', () => setRange(btn.dataset.range));
    });

    fetchSummary();
    setRange(state.range);
})();
