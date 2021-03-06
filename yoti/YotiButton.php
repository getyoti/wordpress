<?php

/**
 * Class YotiButton
 *
 * @author Yoti Ltd <sdksupport@yoti.com>
 */
class YotiButton
{
    /**
     * Default text for Yoti link button
     */
    const YOTI_LINK_BUTTON_TEXT = 'Use Yoti';

    /**
     * Display Yoti button.
     *
     * @param null $redirect
     * @param bool $from_widget
     * @param boolean $echo
     *
     * @return string|null
     */
    public static function render($redirect = NULL, $from_widget = FALSE, $echo = FALSE, $instance_config = [])
    {
        // Increment button ID
        static $button_id_suffix = 0;
        $button_id = 'yoti-button-' . ++$button_id_suffix;

        // Do not show the button if the plugin has not been configured.
        $config = YotiHelper::getConfig();
        if (!$config) {
            return NULL;
        }

        // Merge instance config with global config.
        $config = array_merge($config, array_filter($instance_config));

        // Default button text and linked status.
        $button_text = YotiButton::YOTI_LINK_BUTTON_TEXT;
        $is_linked = FALSE;

        // Button text and linked status for logged in users.
        if (is_user_logged_in()) {
            $button_text = 'Link to Yoti';
            $currentUser = wp_get_current_user();
            $is_linked = !empty(get_user_meta($currentUser->ID, 'yoti_user.identifier'));
        }

        // Override button text if set for widget instance.
        if (!empty($config['yoti_button_text'])) {
            $button_text = $config['yoti_button_text'];
        }

        $message = YotiHelper::getFlash();

        // Build unlink URL.
        $unlink_url = site_url('wp-login.php') . '?yoti-select=1&action=unlink&redirect=' . ($redirect ? '&redirect=' . rawurlencode($redirect) : '');
        $unlink_url = wp_nonce_url($unlink_url, 'yoti_verify', 'yoti_verify');

        $view = function () use (
            $is_linked,
            $message,
            $button_text,
            $from_widget,
            $config,
            $unlink_url,
            $button_id
        )
        {
            require __DIR__ . '/views/button.php';
        };

        if ($echo === FALSE) {
            ob_start();
            $view();
            return ob_get_clean();
        }
        $view();
    }
}