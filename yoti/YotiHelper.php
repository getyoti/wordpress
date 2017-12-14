<?php

use Yoti\ActivityDetails;
use Yoti\YotiClient;

require_once __DIR__ . '/sdk/boot.php';

/**
 * Class YotiHelper
 *
 * @author Yoti Ltd <sdksupport@yoti.com>
 */
class YotiHelper
{
    /**
     * Yoti config option name
     */
    const YOTI_CONFIG_OPTION_NAME = 'yoti_config';

    /**
     * Yoti SDK javascript library.
     */
    const YOTI_SDK_JAVASCRIPT_LIBRARY = 'https://sdk.yoti.com/clients/browser.2.0.1.js';

    /**
     * @var array
     */
    public static $profileFields = [
        ActivityDetails::ATTR_SELFIE => 'Selfie',
        ActivityDetails::ATTR_PHONE_NUMBER => 'Phone number',
        ActivityDetails::ATTR_DATE_OF_BIRTH => 'Date of birth',
        ActivityDetails::ATTR_GIVEN_NAMES => 'Given names',
        ActivityDetails::ATTR_FAMILY_NAME => 'Family name',
        ActivityDetails::ATTR_NATIONALITY => 'Nationality',
        ActivityDetails::ATTR_GENDER => 'Gender',
        ActivityDetails::ATTR_EMAIL_ADDRESS => 'Email Address',
        ActivityDetails::ATTR_POSTAL_ADDRESS => 'Postal Address',
    ];

    /**
     * Yoti WordPress SDK identifier.
     */
    const SDK_IDENTIFIER = 'WordPress';


    /**
     * Running mock requests instead of going to yoti
     *
     * @return bool
     */
    public static function mockRequests()
    {
        return defined('YOTI_MOCK_REQUEST') && YOTI_MOCK_REQUEST;
    }

    /**
     * Login user
     *
     * @param NULL $currentUser
     * @return bool
     */
    public function link($currentUser = NULL)
    {
        if (!$currentUser)
        {
            $currentUser = wp_get_current_user();
        }

        $config = self::getConfig();
        $token = (!empty($_GET['token'])) ? $_GET['token'] : NULL;

        // If no token then ignore
        if (!$token)
        {
            self::setFlash('Could not get Yoti token.', 'error');

            return FALSE;
        }

        // Init yoti client and attempt to request user details
        try
        {
            $yotiClient = new YotiClient(
                $config['yoti_sdk_id'],
                $config['yoti_pem']['contents'],
                YotiClient::DEFAULT_CONNECT_API,
                self::SDK_IDENTIFIER
            );
            $yotiClient->setMockRequests(self::mockRequests());
            $activityDetails = $yotiClient->getActivityDetails($token);
        }
        catch (Exception $e)
        {
            self::setFlash('Yoti failed to connect to your account.', 'error');

            return FALSE;
        }

        // If unsuccessful then bail
        if ($yotiClient->getOutcome() !== YotiClient::OUTCOME_SUCCESS)
        {
            self::setFlash('Yoti failed to connect to your account.', 'error');

            return FALSE;
        }

        // Check if Yoti user exists
        $wpYotiUid = $this->getUserIdByYotiId($activityDetails->getUserId());

        // If Yoti user exists in db but isn't an actual account then remove it from yoti table
        if ($wpYotiUid && $currentUser->ID !== $wpYotiUid && !get_user_by('id', $wpYotiUid))
        {
            // remove users account
            $this->deleteYotiUser($wpYotiUid);
        }

        // If user isn't logged in
        if (!$currentUser->ID)
        {
            // Register new user
            if (!$wpYotiUid)
            {
                $errMsg = NULL;
                // Attempt to connect by email
                $wpYotiUid = $this->shouldLoginByEmail($activityDetails, $config['yoti_user_email']);

                // If config only existing enabled then check if user exists, if not then redirect
                // to login page
                if (!$wpYotiUid)
                {
                    if (empty($config['yoti_only_existing']))
                    {
                        try
                        {
                            $wpYotiUid = $this->createUser($activityDetails);
                        }
                        catch (Exception $e)
                        {
                            $errMsg = $e->getMessage();
                        }
                    }
                    else
                    {
                        self::storeYotiUser($activityDetails);
                        wp_redirect(wp_login_url(!empty($_GET['redirect']) ? $_GET['redirect'] : home_url()));
                        exit;
                    }
                }

                // No user id? no account
                if (!$wpYotiUid)
                {
                    // if couldn't create user then bail
                    $this->setFlash("Could not create user account. $errMsg", 'error');

                    return FALSE;
                }
            }

            // Log user in
            $this->loginUser($wpYotiUid);
        }
        else
        {
            // If current logged in user doesn't match Yoti user registered then bail
            if ($wpYotiUid && $currentUser->ID !== $wpYotiUid)
            {
                self::setFlash('This Yoti account is already linked to another account.', 'error');
            }
            // If WP user not found in Yoti table then create new Yoti user
            elseif (!$wpYotiUid)
            {
                $this->createYotiUser($currentUser->ID, $activityDetails);
                self::setFlash('Your Yoti account has been successfully linked.');
            }
        }

        return TRUE;
    }

    /**
     * Unlink account from currently logged in user
     */
    public function unlink()
    {
        $currentUser = wp_get_current_user();

        // Unlink user account from Yoti
        if (is_user_logged_in())
        {
            $this->deleteYotiUser($currentUser->ID);
            self::setFlash('Your Yoti profile is successfully unlinked from your account.');

            return TRUE;
        }

        self::setFlash('Could not unlink from Yoti.');

        return FALSE;
    }

    /**
     * Display user profile image
     *
     * @param $field
     * @param null $userId
     */
    public function binFile($field, $userId = NULL)
    {
        $user = wp_get_current_user();
        if (in_array('administrator', $user->roles, TRUE))
        {
            $user = get_user_by('id', $userId);
        }

        if (!$user)
        {
            return;
        }

        $field = ($field === 'selfie') ? 'selfie_filename' : $field;
        $dbProfile = self::getUserProfile($user->ID);
        if (!$dbProfile || !array_key_exists($field, $dbProfile))
        {
            return;
        }

        $file = YotiHelper::uploadDir() . "/{$dbProfile[$field]}";
        if (!file_exists($file))
        {
            return;
        }

        $type = 'image/png';
        header('Content-Type:' . $type);
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }

    /**
     * Save Yoti user data in the session.
     *
     * @param \Yoti\ActivityDetails $activityDetails
     */
    public static function storeYotiUser(ActivityDetails $activityDetails)
    {
        $_SESSION['yoti-user'] = serialize($activityDetails);
    }

    /**
     * Retrieve Yoti user data from the session.
     *
     * @return ActivityDetails|null
     */
    public static function getYotiUserFromStore()
    {
        return $_SESSION && array_key_exists('yoti-user', $_SESSION) ? unserialize($_SESSION['yoti-user']) : NULL;
    }

    /**
     * Remove Yoti user data from the session.
     */
    public static function clearYotiUserStore()
    {
        unset($_SESSION['yoti-user']);
    }

    /**
     * Set user notification message.
     *
     * @param $message
     * @param string $type
     */
    public static function setFlash($message, $type = 'message')
    {
        $_SESSION['yoti-connect-flash'] = ['type' => $type, 'message' => $message];
    }

    /**
     * Get user notification message.
     *
     * @return mixed
     */
    public static function getFlash()
    {
        $message = NULL;
        if (!empty($_SESSION['yoti-connect-flash']))
        {
            $message = $_SESSION['yoti-connect-flash'];
            $_SESSION['yoti-connect-flash'] = NULL;
        }

        return $message;
    }

    /**
     * Clear Yoti flash message.
     */
    public static function clearFlash()
    {
        unset($_SESSION['yoti-connect-flash']);
    }

    /**
     * Generate Yoti unique username.
     *
     * @param ActivityDetails $activityDetails
     * @param string $prefix
     *
     * @return null|string
     */
    private function generateUsername(ActivityDetails $activityDetails, $prefix = 'yoti.user')
    {
        $givenName = $this->getUserGivenNames($activityDetails);
        $familyName = $activityDetails->getFamilyName();

        // If GivenName and FamilyName are provided use as user nickname/login
        if(NULL !== $givenName && NULL !== $familyName) {
            $userFullName = $givenName . ' ' . $familyName;
            $userProvidedPrefix = strtolower(str_replace(' ', '.', $userFullName));
            $prefix = validate_username($userProvidedPrefix) ? $userProvidedPrefix : $prefix;
        }

        // Get the number of user_login that starts with prefix
        $userQuery = new WP_User_Query(
            [
                'search' => $prefix . '*',
                // Search the `user_login` field only.
                'search_columns' => ['user_login'],
                // Return user count
                'count_total' => TRUE,
            ]
        );

        // Generate Yoti unique username
        $userCount = (int)$userQuery->get_total();
        $username = $prefix;
        // If we already have a login with this prefix then generate another login
        if ($userCount > 0) {
            do
            {
                $username = $prefix . ++$userCount;
            }
            while (get_user_by('login', $username));
        }

        return $username;
    }

    /**
     * If user has more than one given name return the first one
     *
     * @param ActivityDetails $activityDetails
     * @return null|string
     */
    private function getUserGivenNames(ActivityDetails $activityDetails)
    {
        $givenNames = $activityDetails->getGivenNames();
        $givenNamesArr = explode(' ', $activityDetails->getGivenNames());
        return (count($givenNamesArr) > 1) ? $givenNamesArr[0] : $givenNames;
    }

    /**
     * Generate Yoti unique user email.
     *
     * @param string $prefix
     * @param string $domain
     *
     * @return string
     */
    private function generateEmail($prefix = 'yoti.user', $domain = 'example.com')
    {
        // Get the number of user_email that starts with yotiuser-
        $userQuery = new WP_User_Query(
            [
                // Search for Yoti users starting with the prefix yotiuser-.
                'search' => $prefix . '*',
                // Search the `user_email` field only.
                'search_columns' => ['user_email'],
                // Return user count
                'count_total' => TRUE,
            ]
        );

        // Generate the default email
        $email = $prefix . "@$domain";

        // Generate Yoti unique user email
        $userCount = (int)$userQuery->get_total();
        if ($userCount > 0)
        {
            do
            {
                $email = $prefix . ++$userCount . "@$domain";
            }
            while (get_user_by('email', $email));
        }

        return $email;
    }

    /**
     * Generate Yoti user password.
     *
     * @param int $length
     * @return mixed
     */
    private function generatePassword($length = 10)
    {
        return wp_generate_password($length);
    }

    /**
     * Create user profile with Yoti data.
     *
     * @param ActivityDetails $activityDetails
     *
     * @return int
     *
     * @throws Exception
     */
    private function createUser(ActivityDetails $activityDetails)
    {
        $username = $this->generateUsername($activityDetails);
        $password = $this->generatePassword();
        $userProvidedEmail = $activityDetails->getEmailAddress();
        // If user has provided an email address and it's not in use then use it,
        // otherwise use Yoti generic email
        $userProvidedEmailCanBeUsed = is_email($userProvidedEmail) && !get_user_by('email', $userProvidedEmail);
        $email = $userProvidedEmailCanBeUsed ? $userProvidedEmail : $this->generateEmail();

        $userId = wp_create_user($username, $password, $email);
        // If there has been an error creating the user, stop the process
        if(is_wp_error($userId)) {
            throw new \Exception($userId->get_error_message(), 401);
        }

        $this->createYotiUser($userId, $activityDetails);

        return $userId;
    }

    /**
     * Get Yoti user by ID.
     *
     * @param $yotiId
     *
     * @return int
     */
    private function getUserIdByYotiId($yotiId)
    {
        // Query for users based on the meta data
        $users = (new WP_User_Query(
            [
                'meta_key' => 'yoti_user.identifier',
                'meta_value' => $yotiId,
            ]
        ))->get_results();
        $user = reset($users);

        return $user ? $user->ID : NULL;
    }

    /**
     * Create Yoti user profile.
     *
     * @param $userId
     * @param ActivityDetails $activityDetails
     */
    public function createYotiUser($userId, ActivityDetails $activityDetails)
    {
        // Create upload dir
        if (!is_dir(self::uploadDir()))
        {
            mkdir(self::uploadDir(), 0777, TRUE);
        }

        $meta = [];
        foreach (self::$profileFields as $param => $label)
        {
            $meta[$param] = $activityDetails->getProfileAttribute($param);
        }

        $selfieFilename = NULL;
        $selfie = $activityDetails->getSelfie();
        if ($selfie)
        {
            $selfieFilename = md5("selfie_$userId") . '.png';
            file_put_contents(self::uploadDir() . "/$selfieFilename", $selfie);
            unset($meta[ActivityDetails::ATTR_SELFIE]);
            $meta['selfie_filename'] = $selfieFilename;
        }

        $meta = $this->formatDateOfBirth($meta);

        update_user_meta($userId, 'yoti_user.profile', $meta);
        update_user_meta($userId, 'yoti_user.identifier', $activityDetails->getUserId());
    }

    /**
     * Format Date Of birth to d-m-Y.
     *
     * @param array $profileArr
     * @return array
     */
    private function formatDateOfBirth(array $profileArr)
    {
        if (isset($profileArr[ActivityDetails::ATTR_DATE_OF_BIRTH])) {
            $dateOfBirth = $profileArr[ActivityDetails::ATTR_DATE_OF_BIRTH];
            // Format date of birth to d-m-Y
            $profileArr[ActivityDetails::ATTR_DATE_OF_BIRTH] = date('d-m-Y', strtotime($dateOfBirth));
        }
        return $profileArr;
    }

    /**
     * Delete Yoti user profile.
     *
     * @param int $userId WP user id
     */
    private function deleteYotiUser($userId)
    {
        delete_user_meta($userId, 'yoti_user.identifier');
        delete_user_meta($userId, 'yoti_user.profile');
    }

    /**
     * Log user by ID.
     *
     * @param $userId
     */
    private function loginUser($userId)
    {
        $user = get_user_by('id', $userId);
        wp_set_current_user($userId, $user->user_login);
        wp_set_auth_cookie($userId);
        do_action('wp_login', $user->user_login);
    }

    /**
     * Get user profile by ID.
     *
     * @param $userId
     *
     * @return mixed
     */
    public static function getUserProfile($userId)
    {
        $dbProfile = get_user_meta($userId, 'yoti_user.profile');
        $dbProfile = reset($dbProfile);

        return $dbProfile;
    }

    /**
     * Get Yoti upload dir.
     *
     * @return string
     */
    public static function uploadDir()
    {
        return WP_CONTENT_DIR . '/uploads/yoti';
    }

    /**
     * Get Yoti upload dir URL.
     *
     * @return string
     */
    public static function uploadUrl()
    {
        return content_url('/uploads/yoti');
    }

    /**
     * Get Yoti Config.
     *
     * @return array
     */
    public static function getConfig()
    {
        if (self::mockRequests())
        {
            $config = require __DIR__ . '/sdk/sample-data/config.php';
            return $config;
        }

        return maybe_unserialize(get_option(YotiHelper::YOTI_CONFIG_OPTION_NAME));
    }

    /**
     * Remove Yoti config option data from WordPress option table.
     */
    public static function deleteYotiConfigData()
    {
        delete_option(YotiHelper::YOTI_CONFIG_OPTION_NAME);
    }

    /**
     * Get Yoti app login URL.
     *
     * @return null|string
     */
    public static function getLoginUrl()
    {
        $config = self::getConfig();
        if (empty($config['yoti_app_id']))
        {
            return NULL;
        }

        return YotiClient::getLoginUrl($config['yoti_app_id']);
    }

    /**
     * Attempt to connect by email
     *
     * @param ActivityDetails $activityDetails
     * @param string $emailConfig
     *
     * @return int|null
     */
    private function shouldLoginByEmail(ActivityDetails $activityDetails, $emailConfig)
    {
        $wpYotiUid = NULL;
        $email = $activityDetails->getEmailAddress();

        if ($email && !empty($emailConfig)) {
            $byMail = get_user_by('email', $email);
            if ($byMail) {
                $wpYotiUid = $byMail->ID;
                $this->createYotiUser($wpYotiUid, $activityDetails);
            }
        }
        return $wpYotiUid;
    }
}