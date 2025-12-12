(function () {
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
    };

    const colors = data.colors || {
        h2i: '#0ea5e9',
        image: '#22c55e',
        pdf: '#a855f7',
        tools: '#f97316',
    };

    const state = {
        chart: null,
        range: 'daily',
        maskedKey: '',
        lastKeyHide: null,
    };

    function setStatus(text, type) {
        if (!els.keyStatus) return;
        els.keyStatus.textContent = text || '';
        els.keyStatus.className = 'dsb-status ' + (type ? 'is-' + type : '');
    }

    function formatMasked(prefix, last4) {
        if (!prefix && !last4) return '—';
        return `${prefix || ''} •••• ${last4 || ''}`.trim();
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

    function renderSummary(summary) {
        const res = summary.data || summary;
        const plan = res.plan || {};
        const key = res.key || {};
        const usage = res.usage || {};
        const billing = res.billing || {};
        const per = res.per_endpoint || {};

        state.maskedKey = formatMasked(key.key_prefix, key.key_last4);
        if (els.keyDisplay) {
            els.keyDisplay.value = state.maskedKey;
        }
        if (els.keyCreated) {
            els.keyCreated.textContent = key.created_at ? `Created ${key.created_at}` : '';
        }
        if (els.keyToggle) {
            const status = (key.status || '').toLowerCase();
            const isActive = status !== 'disabled';
            els.keyToggle.textContent = isActive ? 'Disable' : 'Enable';
            els.keyToggle.dataset.state = isActive ? 'active' : 'disabled';
            setStatus(isActive ? 'Active' : 'Disabled', isActive ? 'success' : 'muted');
        }

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

        const used = usage.total_calls_used || 0;
        const limit = usage.total_calls_limit || null;
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
            els.usageWindow.textContent = billing.start || billing.end ? `${billing.start || ''} – ${billing.end || ''}` : '';
        }

        if (els.endpoint.h2i) els.endpoint.h2i.textContent = `${per.h2i_calls || 0} calls`;
        if (els.endpoint.image) els.endpoint.image.textContent = `${per.image_calls || 0} calls`;
        if (els.endpoint.pdf) els.endpoint.pdf.textContent = `${per.pdf_calls || 0} calls`;
        if (els.endpoint.tools) els.endpoint.tools.textContent = `${per.tools_calls || 0} calls`;
    }

    function renderUsage(payload) {
        const res = payload.data || payload;
        const labels = res.labels || [];
        const series = res.series || {};
        const ctx = document.getElementById('dsb-usage-chart');
        if (!ctx) return;

        if (state.chart && typeof state.chart.destroy === 'function') {
            state.chart.destroy();
        }

        const datasets = [
            { label: 'H2I', data: series.h2i || [], borderColor: colors.h2i, backgroundColor: colors.h2i, tension: 0.3 },
            { label: 'Image', data: series.image || [], borderColor: colors.image, backgroundColor: colors.image, tension: 0.3 },
            { label: 'PDF', data: series.pdf || [], borderColor: colors.pdf, backgroundColor: colors.pdf, tension: 0.3 },
            { label: 'Tools', data: series.tools || [], borderColor: colors.tools, backgroundColor: colors.tools, tension: 0.3 },
        ];

        updateLegend(datasets);

        const type = state.range === 'hourly' ? 'bar' : 'line';
        state.chart = new Chart(ctx.getContext('2d'), {
            type,
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
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
        els.rotate.disabled = true;
        post('dsb_dashboard_rotate')
            .then((json) => {
                if (!json || !json.success) throw new Error(data.strings.rotateError);
                const res = json.data || {};
                state.maskedKey = formatMasked(res.key_prefix, res.key_last4);
                if (els.keyDisplay) {
                    els.keyDisplay.value = res.key || state.maskedKey;
                }
                showModal(res.key || '');
            })
            .catch(() => alert(data.strings.rotateError))
            .finally(() => {
                els.rotate.disabled = false;
            });
    }

    function showModal(key) {
        if (!els.modal) return;
        els.modal.hidden = false;
        if (els.modalKey) {
            els.modalKey.value = key;
        }
        if (els.modalMessage) {
            els.modalMessage.textContent = data.strings.shownOnce;
        }
        if (state.lastKeyHide) {
            clearTimeout(state.lastKeyHide);
        }
        state.lastKeyHide = setTimeout(hideModal, 60000);
    }

    function hideModal() {
        if (els.modal) {
            els.modal.hidden = true;
        }
    }

    function handleCopy(target) {
        if (!target) return;
        target.select();
        target.setSelectionRange(0, target.value.length);
        navigator.clipboard
            .writeText(target.value)
            .then(() => alert(data.strings.copied))
            .catch(() => alert(data.strings.copyFailed));
    }

    function handleToggle() {
        const stateAttr = els.keyToggle.dataset.state || 'active';
        const action = stateAttr === 'active' ? 'disable' : 'enable';
        els.keyToggle.disabled = true;
        post('dsb_dashboard_toggle', { action_name: action })
            .then((json) => {
                if (!json || !json.success) throw new Error(data.strings.toggleError);
                const payload = json.data || {};
                const key = payload.key || {};
                const status = (key.status || (action === 'disable' ? 'disabled' : 'active')).toLowerCase();
                const isActive = status !== 'disabled';
                els.keyToggle.textContent = isActive ? 'Disable' : 'Enable';
                els.keyToggle.dataset.state = isActive ? 'active' : 'disabled';
                setStatus(isActive ? 'Active' : 'Disabled', isActive ? 'success' : 'muted');
            })
            .catch(() => alert(data.strings.toggleError))
            .finally(() => {
                els.keyToggle.disabled = false;
            });
    }

    function setRange(range) {
        state.range = range;
        els.rangeButtons.forEach((btn) => btn.classList.toggle('is-active', btn.dataset.range === range));
        fetchUsage(range);
    }

    function fetchSummary() {
        post('dsb_dashboard_summary')
            .then((json) => {
                if (!json || !json.success) throw new Error(data.strings.error);
                renderSummary(json.data);
            })
            .catch(() => {
                setStatus(data.strings.error, 'error');
            });
    }

    function fetchUsage(range) {
        post('dsb_dashboard_usage', { range })
            .then((json) => {
                if (!json || !json.success) throw new Error(data.strings.error);
                renderUsage(json.data);
            })
            .catch(() => {
                setStatus(data.strings.error, 'error');
            });
    }

    // Events
    if (els.rotate) {
        els.rotate.addEventListener('click', handleRotate);
    }
    if (els.keyCopy) {
        els.keyCopy.addEventListener('click', () => handleCopy(els.keyDisplay));
    }
    if (els.modalCopy) {
        els.modalCopy.addEventListener('click', () => handleCopy(els.modalKey));
    }
    if (els.modalClose) {
        els.modalClose.addEventListener('click', hideModal);
    }
    if (els.keyToggle) {
        els.keyToggle.addEventListener('click', handleToggle);
    }
    els.rangeButtons.forEach((btn) => {
        btn.addEventListener('click', () => setRange(btn.dataset.range));
    });

    fetchSummary();
    fetchUsage(state.range);
})();
