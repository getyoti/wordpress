<?php

namespace Yoti\WP;

/**
 * Class Admin
 *
 * @author Yoti SDK <sdksupport@yoti.com>
 */
class Admin
{
    /**
     * @var self
     */
    private static $_instance;

    /**
     * POST data.
     *
     * @var array
     */
    private $postData;

    /**
     * init
     */
    public static function init()
    {
        if (!self::$_instance)
        {
            self::$_instance = new self;

            self::$_instance->options();
        }
    }

    /**
     * singleton
     */
    private function __construct()
    {
    }

    /**
     * singleton
     */
    private function __clone()
    {
    }

    /**
     * options page for admin
     */
    private function options()
    {
        // Make sure user can edit
        if (!current_user_can('manage_options'))
        {
            return;
        }

        // Get current config
        $config = Config::load();

        // Check curl has preliminary extensions to run
        $errors = [];
        if (!function_exists('curl_version'))
        {
            $errors[] = "PHP module 'curl' not installed. Yoti requires it to work. Please contact your server administrator.";
        }
        if (!function_exists('json_decode'))
        {
            $errors[] = "PHP module 'json' not installed. Yoti requires it to work. Please contact your server administrator.";
        }
        if (version_compare(phpversion(), '5.4.0', '<')) {
            $errors[] = 'Yoti could not be installed. Yoti PHP SDK requires PHP 5.4 or higher.';
        }

        // Get data
        $data = $config;
        $updateMessage = '';

        if ($_SERVER['REQUEST_METHOD'] == 'POST')
        {
            try
            {
                $this->setPostData();

                $data['yoti_app_id'] = trim($this->postVar('yoti_app_id'));
                $data['yoti_scenario_id'] = trim($this->postVar('yoti_scenario_id'));
                $data['yoti_sdk_id'] = trim($this->postVar('yoti_sdk_id'));
                $data['yoti_company_name'] = trim($this->postVar('yoti_company_name'));
                $data['yoti_delete_pem'] = $this->postVar('yoti_delete_pem') ? TRUE : FALSE;
                $data['yoti_only_existing'] = $this->postVar('yoti_only_existing');
                $data['yoti_user_email'] = $this->postVar('yoti_user_email');
                $data['yoti_age_verification'] = $this->postVar('yoti_age_verification');
                $pemFile = $this->filesVar('yoti_pem', $config['yoti_pem']);

                // Validation
                if (!$data['yoti_app_id'])
                {
                    $errors['yoti_app_id'] = 'App ID is required.';
                }
                if (!$data['yoti_sdk_id'])
                {
                    $errors['yoti_sdk_id'] = 'Client SDK ID is required.';
                }
                if (empty($pemFile['name']))
                {
                    $errors['yoti_pem'] = 'PEM file is required.';
                }
                elseif (!empty($pemFile['tmp_name']) && !openssl_get_privatekey(file_get_contents($pemFile['tmp_name'])))
                {
                    $errors['yoti_pem'] = 'PEM file is invalid.';
                }
            }
            catch (\Exception $e) {
                $errors['yoti_admin_options'] = 'There was a problem saving form data. Please try again.';
            }

            // No errors? proceed
            if (!$errors)
            {
                // If pem file uploaded then process
                $name = $contents = NULL;
                if (!empty($pemFile['tmp_name']))
                {
                    $name = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $pemFile['name']);
                    if (!$name)
                    {
                        $name = md5($pemFile['name']) . '.pem';
                    }
                    $contents = file_get_contents($pemFile['tmp_name']);
                }
                // If delete not ticked
                elseif (!$data['yoti_delete_pem'])
                {
                    $name = $config['yoti_pem']['name'];
                    $contents = $config['yoti_pem']['contents'];
                }

                $data['yoti_pem'] = compact('name', 'contents');
                $config = $data;
                unset($config['yoti_delete_pem']);

                // Save config
                Config::save($config);
                $updateMessage = 'Yoti settings saved.';
            }
        }

        View::render('admin-options', [
            'data' => $data,
            'errors' => $errors,
            'updateMessage' => $updateMessage,
        ]);
    }

    /**
     * Sets POST data from request.
     */
    private function setPostData()
    {
        if (
            !isset($_POST['yoti_verify'])
            || !wp_verify_nonce($_POST['yoti_verify'], 'yoti_verify')
        ) {
            throw new \Exception('Could not verify request');
        }
        $this->postData = $_POST;
    }

    /**
     * @param string $var
     * @param null $default
     * @return null
     */
    private function postVar($var, $default = NULL)
    {
        return array_key_exists($var, $this->postData) ? $this->postData[$var] : $default;
    }

    /**
     * @param $var
     * @param NULL $default
     * @return NULL
     */
    private function filesVar($var, $default = NULL)
    {
        return (array_key_exists($var, $_FILES) && !empty($_FILES[$var]['name'])) ? $_FILES[$var] : $default;
    }
}