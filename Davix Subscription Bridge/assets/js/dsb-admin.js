(function($){
    function initAjaxSelect($el){
        var action = $el.data('action');
        if(!action){
            return;
        }
        var args = {
            ajax: {
                url: (window.dsbAdminData || {}).ajaxUrl,
                dataType: 'json',
                delay: 250,
                data: function(params){
                    return {
                        action: action,
                        nonce: (window.dsbAdminData || {}).nonce,
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

        var $fields = $('.dsb-color-field');
        console.log('[DSB] color fields found:', $fields.length);
        console.log('[DSB] wpColorPicker exists', typeof $.fn.wpColorPicker);
        if (typeof $.fn.wpColorPicker !== 'function') {
            console.error('[DSB] wpColorPicker missing. Check enqueue.');
        } else {
            $fields.wpColorPicker();
        }

        function getModal(){
            return $('[data-dsb-modal]').first();
        }

        function closeModal(){
            var $modal = getModal();
            if (!$modal.length) {
                return;
            }
            $modal.removeClass('is-open').attr('aria-hidden', 'true');
            $('body').removeClass('dsb-modal-open');
        }

        $(document).on('click', '.dsb-open-key-modal', function(e){
            e.preventDefault();
            var $modal = getModal();
            if (!$modal.length) {
                console.error('[DSB] Create Key modal not found');
                return;
            }
            console.log('[DSB] Create Key clicked', { modalFound: $modal.length });
            $modal.addClass('is-open').attr('aria-hidden', 'false');
            $('body').addClass('dsb-modal-open');
        });

        $(document).on('click', '[data-dsb-modal-close]', function(e){
            e.preventDefault();
            closeModal();
        });

        $(document).on('keydown', function(e){
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        $(document).on('click', '[data-dsb-modal]', function(e){
            if ($(e.target).is('[data-dsb-modal]')) {
                closeModal();
            }
        });
    });
})(jQuery);
