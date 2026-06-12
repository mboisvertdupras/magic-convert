<?php

/**
 * Minimal WordPress stubs for the nginx-panel notice tests, defined in the MagicConvert namespace.
 *
 * The production call chain for arming the notice is:
 *   NginxRulesNotice::arm() -> DismissableGlobalMessages::addDismissableMessage()
 *     -> State::getState()/setState() -> Option::getOption()/updateOption()
 *        -> Multisite::isNetworkActivated() (-> is_multisite()) and get_option()/update_option().
 *
 * Option, Multisite and State all live in `namespace MagicConvert`, where an UNQUALIFIED call like
 * `get_option(...)` resolves to `MagicConvert\get_option` FIRST if it exists. By defining these
 * three functions in the MagicConvert namespace we intercept the WordPress calls at that seam and
 * back them with an in-memory option store — no WordPress, no database. We do NOT touch the global
 * namespace, so other tests are unaffected.
 */

namespace MagicConvert {

    if (!function_exists('MagicConvert\\__mc_stub_option_store')) {
        function &__mc_stub_option_store(): array
        {
            static $store = [];
            return $store;
        }

        // Single-site only (keeps the chain short and deterministic for tests).
        function is_multisite(): bool
        {
            return false;
        }

        function get_option($name, $default = false)
        {
            $store = &__mc_stub_option_store();
            return array_key_exists($name, $store) ? $store[$name] : $default;
        }

        function update_option($name, $value, $autoload = null): bool
        {
            $store = &__mc_stub_option_store();
            $store[$name] = $value;
            return true;
        }

        function delete_option($name): bool
        {
            $store = &__mc_stub_option_store();
            unset($store[$name]);
            return true;
        }
    }
}

namespace MagicConvert\Tests {

    /** Clear the in-memory option store between tests. */
    function reset_wp_stub_options(): void
    {
        $store = &\MagicConvert\__mc_stub_option_store();
        $store = [];
    }
}
