/*=========================================================================================
    File Name: components-dropdown.js
    ----------------------------------------------------------------------------------------
//  Item Name: Ultimate SMS - Bulk SMS Application For Marketing
//  Author: Codeglen
//  Author URL: https://codecanyon.net/user/codeglen
==========================================================================================*/
(function (window, document, $) {
  'use strict';

  var dropdownMenuIcon = $('.dropdown-icon-wrapper .dropdown-item');

  // For Dropdown With Icons
  dropdownMenuIcon.on('click', function () {
    $('.dropdown-icon-wrapper .dropdown-toggle svg').remove();
    $(this).find('svg').clone().appendTo('.dropdown-icon-wrapper .dropdown-toggle');
    $('.dropdown-icon-wrapper .dropdown-toggle .dropdown-item').removeClass('dropdown-item');
  });
})(window, document, jQuery);
