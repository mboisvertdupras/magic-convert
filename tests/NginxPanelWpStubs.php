<?php

namespace MagicConvert {

    if (!function_exists('MagicConvert\\__mc_stub_option_store')) {
        function &__mc_stub_option_store(): array
        {
            static $store = [];
            return $store;
        }

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

    function reset_wp_stub_options(): void
    {
        $store = &\MagicConvert\__mc_stub_option_store();
        $store = [];
    }
}
