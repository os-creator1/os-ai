/*=========================================================================================
    File Name: theme-tokens.js
    Description: Design System M2 Slice 1 contract §6.12/§9 item 41. The single token-
    reading implementation, exposing one global namespace (window.PlatformTheme) for every
    inline chart-consuming <script> block. Reads live CSS custom properties via
    getComputedStyle() at call time, so callers always reflect whichever preset is
    currently active — never duplicated per page.
==========================================================================================*/
(function (window, document) {
    "use strict";

    function cssVar(name, fallback) {
        var value = getComputedStyle(document.documentElement).getPropertyValue("--" + name).trim();
        return value || fallback || "";
    }

    var PlatformTheme = {
        color: function (name, fallback) {
            return cssVar(name, fallback);
        },
        primary: function () {
            return cssVar("color-primary");
        },
        secondary: function () {
            return cssVar("color-secondary");
        },
        chartPalette: function () {
            var colors = [];
            for (var i = 1; i <= 8; i++) {
                colors.push(cssVar("color-chart-" + i));
            }
            return colors;
        },
        chartNeutral: function () {
            return cssVar("color-chart-neutral");
        },
        chartGrid: function () {
            return cssVar("color-chart-grid");
        },
        chartAxis: function () {
            return cssVar("color-chart-axis");
        },
        chartTooltipBg: function () {
            return cssVar("color-chart-tooltip-bg");
        },
        chartTooltipText: function () {
            return cssVar("color-chart-tooltip-text");
        },
    };

    window.PlatformTheme = PlatformTheme;
})(window, document);
