/*=========================================================================================
  File Name: auth-register.js
  Description: Auth register js file.
  ----------------------------------------------------------------------------------------
  Item Name: Ultimate SMS - Bulk SMS Application For Marketing
  Author: Codeglen
  Author URL: https://codecanyon.net/user/codeglen
==========================================================================================*/


$(function () {
    ('use strict');

    var registerMultiStepsWizard = document.querySelector('.register-multi-steps-wizard'),
        pageResetForm = $('.auth-register-form'),
        select = $('.select2'),
        priceOption = $('.pricing-data'),
        btnSubmit = $('.btn-submit'),
        btnProcessing = $('.btn-processing');

    btnProcessing.hide();

    priceOption.delegate(".planPrice", "click", function (e) {
        e.stopPropagation();
        if ($(this).data('value') === '0.00') {
            $('.hide-for-free').hide();
        } else {
            $('.hide-for-free').show();
        }
    });

    // jQuery Validation
    // --------------------------------------------------------------------
    if (pageResetForm.length) {
        pageResetForm.validate({
            rules: {
                'email': {
                    required: true,
                    email: true
                },
                'password': {
                    required: true
                },
                'timezone': {
                    required: true
                },
                'locale': {
                    required: true
                }
            }
        });
    }

    // multi-steps registration
    // --------------------------------------------------------------------

    // Horizontal Wizard
    if (typeof registerMultiStepsWizard !== undefined && registerMultiStepsWizard !== null) {
        let numberedStepper = new Stepper(registerMultiStepsWizard),
            $form = $(registerMultiStepsWizard).find('form');
        $form.each(function () {
            let $this = $(this);
            $this.validate({
                rules: {
                    first_name: {
                        required: true
                    },
                    email: {
                        required: true,
                        email: true
                    },
                    password: {
                        required: true,
                        minlength: 8
                    },
                    password_confirmation: {
                        required: true,
                        minlength: 8,
                        equalTo: '#password'
                    },
                    timezone: {
                        required: true
                    },
                    locale: {
                        required: true
                    },
                    phone: {
                        required: true,
                        digits: true,
                        minlength: 9,
                        maxlength: 17,
                    },
                    address: {
                        required: true
                    },
                    city: {
                        required: true
                    }
                },
            });
        });

        $(registerMultiStepsWizard)
            .find('.btn-next')
            .each(function () {
                $(this).on('click', function (e) {
                    let isValid = $(this).parent().siblings('form').valid();
                    if (isValid) {
                        numberedStepper.next();
                    } else {
                        e.preventDefault();
                    }
                });
            });

        $(registerMultiStepsWizard)
            .find('.btn-prev')
            .on('click', function () {
                numberedStepper.previous();
            });

        $(registerMultiStepsWizard)
            .find('.btn-submit')
            .on('click', function () {
                let isValid = $(this).parent().siblings('form').valid();
                if (isValid) {

                    btnSubmit.hide();
                    btnProcessing.show();


                    let formData = $(registerMultiStepsWizard).find('form').serialize(),
                        csrfToken = $('meta[name="csrf-token"]').attr('content');

                    $.ajax({
                        type: 'POST',
                        url: '/register', // Replace with your Laravel endpoint URL
                        data: formData,
                        headers: {
                            'X-CSRF-TOKEN': csrfToken
                        },
                        success: function (data) {
                            btnSubmit.show();  // Remove the dot before the class name
                            btnProcessing.hide();

                            // Handle the response
                            if (data.status === 'error') {
                                toastr['error'](data.message, data.status, {
                                    closeButton: true,
                                    positionClass: 'toast-top-right',
                                    progressBar: true,
                                    newestOnTop: true,
                                });
                                return;
                            }

                            toastr['success'](data.message, data.status, {
                                closeButton: true,
                                positionClass: 'toast-top-right',
                                progressBar: true,
                                newestOnTop: true,
                            });


                            if (data.redirect) {
                                setTimeout(function () {
                                    window.location.href = data.redirect;
                                }, 2000);
                            }

                            $('#result-container').html(response.html);


                        },
                        error: function (error) {
                            btnSubmit.show();  // Remove the dot before the class name
                            btnProcessing.hide();
                            // Handle the error
                            toastr['error'](error.responseJSON.message, 'Error', {
                                closeButton: true,
                                positionClass: 'toast-top-right',
                                progressBar: true,
                                newestOnTop: true,
                            });
                        }
                    });
                }
            });
    }

    // select2
    select.each(function () {
        var $this = $(this);
        $this.wrap('<div class="position-relative"></div>');
        $this.select2({
            // the following code is used to disable x-scrollbar when click in select input and
            // take 100% width in responsive also
            dropdownAutoWidth: true,
            width: '100%',
            dropdownParent: $this.parent()
        });
    });

});

