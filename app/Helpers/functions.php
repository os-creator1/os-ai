<?php

if (! function_exists('isActive')) {
    /**
     * Set the active class to the current opened menu.
     *
     *
     * @return string
     */
    function isActive(array|string $route, string $className = 'active')
    {
        if (is_array($route)) {
            return in_array(Route::currentRouteName(), $route) ? $className : '';
        }
        if (Route::currentRouteName() == $route) {
            return $className;
        }
        if (strpos(URL::current(), $route)) {
            return $className;
        }

    }
}
