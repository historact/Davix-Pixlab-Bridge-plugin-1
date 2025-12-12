(function ($) {
    function formatKey(keyData) {
        if (!keyData) {
            return '—';
        }
        if (keyData.key_prefix && keyData.key_last4) {
            return keyData.key_prefix + '••••' + keyData.key_last4;
        }
        return '—';
    }

    function formatPlan(plan) {
        if (!plan) {
            return '—';
        }
        var parts = [];
        if (plan.plan_slug) {
            parts.push(plan.plan_slug);
        }
        if (plan.name) {
            parts.push(plan.name);
        }
        if (plan.monthly_quota_files) {
            parts.push('(' + plan.monthly_quota_files + ' files/mo)');
        }
        return parts.join(' ');
    }

    function formatUsage(usage) {
        if (!usage) {
            return '—';
        }
        var period = usage.period || '';
        var files = typeof usage.used_files !== 'undefined' ? usage.used_files : '—';
        var quota = typeof usage.monthly_quota_files !== 'undefined' ? usage.monthly_quota_files : '—';
        var bytes = typeof usage.used_bytes !== 'undefined' ? usage.used_bytes : '—';
        var calls = typeof usage.total_calls !== 'undefined' ? usage.total_calls : '—';
        var filesProcessed = typeof usage.total_files_processed !== 'undefined' ? usage.total_files_processed : '—';

        return [
            period,
            ': ',
            files,
            ' / ',
            quota,
            ' files, ',
            bytes,
            ' bytes, ',
            calls,
            ' calls, ',
            filesProcessed,
            ' files processed'
        ].join('');
    }

    function renderEndpointUsage(container, usage) {
        container.empty();
        if (!usage || !usage.per_endpoint) {
            container.text('—');
            return;
        }

        var perEndpoint = usage.per_endpoint;
        var endpoints = ['h2i', 'image', 'pdf', 'tools'];
        endpoints.forEach(function (endpoint) {
            if (!perEndpoint[endpoint]) {
                return;
            }
            var item = $('<div class="dsb-user-dashboard__endpoint"></div>');
            var calls = typeof perEndpoint[endpoint].calls !== 'undefined' ? perEndpoint[endpoint].calls : '—';
            var files = typeof perEndpoint[endpoint].files !== 'undefined' ? perEndpoint[endpoint].files : '—';
            item.text(endpoint + ': ' + calls + ' calls, ' + files + ' files');
            container.append(item);
        });

        if (!container.children().length) {
            container.text('—');
        }
    }

    function showStatus(container, message, isError) {
        container.text(message || '');
        if (isError) {
            container.addClass('dsb-error');
        } else {
            container.removeClass('dsb-error');
        }
    }

    function fetchSummary(wrapper) {
        var statusEl = wrapper.find('.dsb-user-dashboard__status');
        showStatus(statusEl, dsbUserDashboard.strings.loading, false);

        $.post(
            dsbUserDashboard.ajaxUrl,
            {
                action: 'dsb_user_summary',
                _nonce: wrapper.data('nonce') || dsbUserDashboard.nonce
            }
        )
            .done(function (response) {
                if (!response || !response.success || !response.data || !response.data.data) {
                    showStatus(statusEl, dsbUserDashboard.strings.error, true);
                    return;
                }
                var data = response.data.data;
                showStatus(statusEl, '', false);
                wrapper.find('[data-key-display]').text(formatKey(data.key));
                wrapper.find('[data-plan-display]').text(formatPlan(data.plan));
                wrapper.find('[data-usage-display]').text(formatUsage(data.usage));
                renderEndpointUsage(wrapper.find('[data-endpoint-display]'), data.usage);
            })
            .fail(function () {
                showStatus(statusEl, dsbUserDashboard.strings.error, true);
            });
    }

    function handleRotate(wrapper) {
        var statusEl = wrapper.find('.dsb-user-dashboard__status');
        var newKeyBlock = wrapper.find('[data-new-key]');
        var newKeyValue = wrapper.find('[data-new-key-value]');
        var hideTimer;

        wrapper.find('[data-rotate]').on('click', function () {
            if (!window.confirm('Regenerate your API key?')) {
                return;
            }

            $.post(
                dsbUserDashboard.ajaxUrl,
                {
                    action: 'dsb_user_rotate_key',
                    _nonce: wrapper.data('nonce') || dsbUserDashboard.nonce
                }
            )
                .done(function (response) {
                    if (!response || !response.success || !response.data || !response.data.data) {
                        showStatus(statusEl, dsbUserDashboard.strings.error, true);
                        return;
                    }
                    var data = response.data.data;
                    fetchSummary(wrapper);
                    if (data.key) {
                        newKeyValue.text(data.key);
                        newKeyBlock.prop('hidden', false);
                        showStatus(statusEl, dsbUserDashboard.strings.copynote, false);
                        if (hideTimer) {
                            clearTimeout(hideTimer);
                        }
                        hideTimer = setTimeout(function () {
                            newKeyBlock.prop('hidden', true);
                            newKeyValue.text('');
                        }, 60000);
                    } else {
                        newKeyBlock.prop('hidden', true);
                        newKeyValue.text('');
                    }
                })
                .fail(function (xhr) {
                    var message = dsbUserDashboard.strings.error;
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        message = xhr.responseJSON.data.message;
                    }
                    showStatus(statusEl, message, true);
                });
        });
    }

    $(function () {
        var wrapper = $('.dsb-user-dashboard');
        if (!wrapper.length) {
            return;
        }

        wrapper.each(function () {
            var el = $(this);
            fetchSummary(el);
            handleRotate(el);
        });
    });
})(jQuery);
