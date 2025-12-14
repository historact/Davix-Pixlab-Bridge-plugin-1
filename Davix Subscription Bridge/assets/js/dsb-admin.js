(function($){
    if (window.DSB_ADMIN_INIT_DONE) {
        return;
    }
    window.DSB_ADMIN_INIT_DONE = true;
    try {
        var config = window.DSB_ADMIN || {};
        window.DSB_ADMIN = config;

        console.log('[DSB] dsb-admin.js executed');
        window.DSB_ADMIN_EXECUTED = true;

        function dsbSendLog(level, message, context){
            try {
                var cfg = window.DSB_ADMIN || {};
                if (!cfg || !cfg.debug) { return; }
                if (!cfg.ajaxUrl || !cfg.nonce) { return; }
                var data = new FormData();
                data.append('action','dsb_js_log');
                data.append('nonce', cfg.nonce);
                data.append('level', level);
                data.append('message', message);
                data.append('context', JSON.stringify(context || {}));
                fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
            } catch (err) {
                // fail silently
            }
        }

        dsbSendLog('info', 'JS_EXECUTED', { href: window.location.href, tab: config ? config.tab : undefined });

        window.addEventListener('error', function(e){
            dsbSendLog('error', 'window.error', { message: e.message, file: e.filename, line: e.lineno, col: e.colno });
        });

        window.addEventListener('unhandledrejection', function(e){
            dsbSendLog('error', 'unhandledrejection', { reason: String(e.reason) });
        });

        function initAjaxSelect($el){
            var action = $el.data('action');
            if(!action){
                return;
            }
            var args = {
                ajax: {
                    url: config.ajaxUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params){
                        return {
                            action: action,
                            nonce: config.nonce,
                            q: params.term || ''
                        };
                    },
                    processResults: function(data){
                        return data && data.results ? data : { results: [] };
                    }
                },
                width: 'resolve',
                allowClear: true,
                placeholder: $el.data('placeholder') || ''
            };
            if (typeof $el.selectWoo === 'function') {
                $el.selectWoo(args);
            } else if (typeof $el.select2 === 'function') {
                $el.select2(args);
            }
        }

        function initStaticSelect($el){
            var args = {
                width: 'resolve',
                allowClear: true,
                placeholder: $el.data('placeholder') || ''
            };
            if (typeof $el.selectWoo === 'function') {
                $el.selectWoo(args);
            } else if (typeof $el.select2 === 'function') {
                $el.select2(args);
            }
        }

        function bindSelects(){
            if (!($.fn.selectWoo || $.fn.select2)) {
                console.warn('[DSB] select2/selectWoo missing');
                return;
            }

            $('.dsb-select-ajax').each(function(){
                initAjaxSelect($(this));
            });

            $('#dsb-plan').each(function(){
                initStaticSelect($(this));
            });

            $('#dsb-customer').on('select2:select selectWoo:select', function(e){
                var data = e.params && e.params.data ? e.params.data : {};
                $('#dsb-customer-email').val(data.email || data.text || '');
            }).on('change', function(){
                if(!$(this).val()){
                    $('#dsb-customer-email').val('');
                }
            });
        }

        $(function(){
            window.DSB_ADMIN_LOADED = true;
            console.log('[DSB] admin JS loaded', location.href);

            bindSelects();

            var hasPicker = typeof $.fn.wpColorPicker === 'function';
            var colorCount = $('.dsb-color-field').length;
            if (config && config.tab === 'style' && (hasPicker || colorCount)) {
                console.log('[DSB] wpColorPicker exists?', hasPicker, 'fields', colorCount);
                dsbSendLog('info', 'STYLE_INIT', { hasPicker: hasPicker, fieldCount: colorCount });
                if (hasPicker && colorCount) {
                    $('.dsb-color-field').wpColorPicker();
                    dsbSendLog('info', 'WPCOLORPICKER_INIT_DONE', {});
                }
            }

            function getModal(){
                return $('[data-dsb-modal]').first();
            }

            function dsbCloseModal(){
                var $modal = getModal();
                $modal.removeClass('is-open').attr('aria-hidden', 'true');
                $('body').removeClass('dsb-modal-open');
                dsbSendLog('info', 'modal closed', {});
            }

            $(document).on('click', '.dsb-open-key-modal', function(e){
                e.preventDefault();
                var $modal = getModal();
                var found = $modal.length;
                console.log('[DSB] Create Key clicked, modal found?', found);
                dsbSendLog('info', 'CREATE_KEY_CLICK', { modalFound: found });

                if (!found) {
                    return;
                }
                $modal.addClass('is-open').attr('aria-hidden', 'false');
                $('body').addClass('dsb-modal-open');
            });

            $(document).on('click', '[data-dsb-modal-close]', function(e){
                e.preventDefault();
                dsbCloseModal();
            });

            $(document).on('keydown', function(e){
                if (e.key === 'Escape') {
                    dsbCloseModal();
                }
            });

            $(document).on('click', '[data-dsb-modal]', function(e){
                if ($(e.target).is('[data-dsb-modal]')) {
                    dsbCloseModal();
                }
            });
        });
    } catch (err) {
        console.error('[DSB] dsb-admin.js fatal', err);
        try {
            if (typeof dsbSendLog === 'function') {
                dsbSendLog('error', 'dsb-admin.js fatal', { message: err && err.message ? err.message : String(err) });
            }
        } catch (e) {}
    }
})(jQuery);
