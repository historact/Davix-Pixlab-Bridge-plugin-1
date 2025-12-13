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

    $(function(){
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

        if (typeof $.fn.wpColorPicker === 'function') {
            $('.dsb-color-field').wpColorPicker();
        }

        var $modal = $('[data-dsb-modal]');
        function closeModal(){
            $modal.removeClass('is-open');
        }

        $('.dsb-open-key-modal').on('click', function(e){
            e.preventDefault();
            $modal.addClass('is-open');
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

        $modal.on('click', function(e){
            if ($(e.target).is('[data-dsb-modal]')) {
                closeModal();
            }
        });
    });
})(jQuery);
