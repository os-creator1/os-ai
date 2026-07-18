/*=========================================================================================
    File Name: components-alert.js
    ----------------------------------------------------------------------------------------
//  Item Name: Ultimate SMS - Bulk SMS Application For Marketing
//  Author: Codeglen
//  Author URL: https://codecanyon.net/user/codeglen
==========================================================================================*/
(function (window, document, $) {
  'use strict';

  var alertValidationInput = $('.alert-validation'),
    alertRegex = /^[0-9]+$/,
    alertValidationMsg = $('.alert-validation-msg');

  /* validation with alert */
  alertValidationInput.on('input', function () {
    if (alertValidationInput.val().match(alertRegex)) {
      alertValidationMsg.css('display', 'none');
    } else {
      alertValidationMsg.css('display', 'block');
    }
  });
})(window, document, jQuery);
