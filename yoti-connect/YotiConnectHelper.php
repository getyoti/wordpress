<?php
use Yoti\ActivityDetails;
use Yoti\YotiClient;

require_once __DIR__ . '/sdk/boot.php';

/**
 * Class YotiConnect
 *
 * @author Simon Tong <simon.tong@yoti.com>
 */
class YotiConnectHelper
{
    /**
     * @var array
     */
    public static $profileFields = array(
        ActivityDetails::ATTR_SELFIE => 'Selfie',
        ActivityDetails::ATTR_PHONE_NUMBER => 'Phone number',
        ActivityDetails::ATTR_EMAIL_ADDRESS => 'Email Address',
        ActivityDetails::ATTR_DATE_OF_BIRTH => 'Date of birth',
        ActivityDetails::ATTR_GIVEN_NAMES => 'Given names',
        ActivityDetails::ATTR_FAMILY_NAME => 'Family name',
        ActivityDetails::ATTR_FULL_NAME => 'Full name',
        ActivityDetails::ATTR_NATIONALITY => 'Nationality',
    );

    /**
     * Running mock requests instead of going to yoti
     * @return bool
     */
    public static function mockRequests()
    {
        return defined('YOTI_MOCK_REQUEST') && YOTI_MOCK_REQUEST;
    }

    /**
     * Login user
     */
    public function link()
    {
        $currentUser = wp_get_current_user();
        $config = self::getConfig();
        $token = (!empty($_GET['token'])) ? $_GET['token'] : null;

        // if no token then ignore
        if (!$token)
        {
            self::setFlash('Could not get Yoti token.', 'error');

            return false;
        }

        // init yoti client and attempt to request user details
        try
        {
            $yotiClient = new YotiClient($config['yoti_sdk_id'], $config['yoti_pem']['contents']);
            $yotiClient->setMockRequests(self::mockRequests());
            $activityDetails = $yotiClient->getActivityDetails($token);
        }
        catch (Exception $e)
        {
            self::setFlash('Yoti failed to connect to your account.', 'error');

            return false;
        }

        // if unsuccessful then bail
        if ($yotiClient->getOutcome() != YotiClient::OUTCOME_SUCCESS)
        {
            self::setFlash('Yoti failed to connect to your account.', 'error');

            return false;
        }

        // check if yoti user exists
        $userId = $this->getUserIdByYotiId($activityDetails->getUserId());

        // if yoti user exists in db but isn't an actual account then remove it from yoti table
        if ($userId && $currentUser->ID != $userId && !get_user_by('id', $userId))
        {
            // remove users account
            $this->deleteYotiUser($userId);
        }

        // if user isn't logged in
        if (!is_user_logged_in())
        {
            // Register new user
            if (!$userId)
            {
                // Prevent registration if new users are not allowed to register
                if(!$config['yoti_allow_registration'])
                {
                    self::setFlash("New user registrations are not allowed.", 'error');
                    return false;
                }

                $errMsg = $userId = null;
                try
                {
                    $userId = $this->createUser($activityDetails);
                }
                catch (Exception $e)
                {
                    $errMsg = $e->getMessage();
                }

                // no user id? no account
                if (!$userId)
                {
                    // if couldn't create user then bail
                    self::setFlash("Could not create user account. $errMsg", 'error');

                    return false;
                }
            }

            // log user in
            $this->loginUser($userId);
        }
        else
        {
            // if current logged in user doesn't match yoti user registered then bail
            if ($userId && $currentUser->ID != $userId)
            {
                self::setFlash('This Yoti account is already linked to another account.', 'error');
            }
            // if joomla user not found in yoti table then create new yoti user
            elseif (!$userId)
            {
                $this->createYotiUser($currentUser->ID, $activityDetails);
                self::setFlash('Your Yoti account has been successfully linked.');
            }
        }

        return true;
    }

    /**
     * Unlink account from currently logged in
     */
    public function unlink()
    {
        $currentUser = wp_get_current_user();

        // unlink
        if (is_user_logged_in())
        {
            $this->deleteYotiUser($currentUser->ID);
            self::setFlash('Your Yoti profile has been successfully unlinked from your account.');

            return true;
        }

        self::setFlash('Could not unlink from Yoti.');

        return false;
    }

    /**
     * @param $field
     * @param null $userId
     */
    public function binFile($field, $userId = null)
    {
        $user = wp_get_current_user();
        if (in_array('administrator', $user->roles))
        {
            $user = get_user_by('id', $userId);
        }

        if (!$user)
        {
            return;
        }

        $field = ($field == 'selfie') ? 'selfie_filename' : $field;
        $dbProfile = self::getUserProfile($user->ID);
        if (!$dbProfile || !array_key_exists($field, $dbProfile))
        {
            return;
        }

        $file = YotiConnectHelper::uploadDir() . "/{$dbProfile[$field]}";
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
     * @param $message
     * @param string $type
     */
    public static function setFlash($message, $type = 'message')
    {
        $_SESSION['yoti-connect-flash'] = ['type' => $type, 'message' => $message];
    }

    /**
     * @return mixed
     */
    public static function getFlash()
    {
        $message = null;
        if (!empty($_SESSION['yoti-connect-flash']))
        {
            $message = $_SESSION['yoti-connect-flash'];
            $_SESSION['yoti-connect-flash'] = null;
        }

        return $message;
    }

    /**
     * @param ActivityDetails $activityDetails
     * @return string
     */
    private function generateUsername($activityDetails)
    {
        $FULL_NAME = $activityDetails->getProfileAttribute(ActivityDetails::ATTR_FULL_NAME);
        $GIVEN_NAMES = $activityDetails->getProfileAttribute(ActivityDetails::ATTR_GIVEN_NAMES);
        $FAMILY_NAME = $activityDetails->getProfileAttribute(ActivityDetails::ATTR_FAMILY_NAME);

        $i = 2;
        $username = null;

        if($FULL_NAME)
        {
            $prefix = $FULL_NAME;
        }
        elseif($GIVEN_NAMES && $FAMILY_NAME)
        {
            $prefix = $GIVEN_NAMES . " " . $FAMILY_NAME;
        }
        else
        {
            $prefix = "YotiConnect";
        }

        if(get_user_by('login', $prefix))
        {
            do
            {
                $username = $prefix . " " . $i++;
            }
            while (get_user_by('login', $username));
        }
        else {
            $username = $prefix;
        }

        return $username;
    }

    /**
     * @param string $prefix
     * @param string $domain
     * @return string
     */
    private function generateEmail($prefix = 'yoticonnect-', $domain = 'example.com')
    {
        $i = 0;
        do
        {
            $email = $prefix . $i++ . "@$domain";
        }
        while (get_user_by('email', $email));

        return $email;
    }

    /**
     * @param int $length
     * @return mixed
     */
    private function generatePassword($length = 10)
    {
        return wp_generate_password($length);
    }

    /**
     * @param ActivityDetails $activityDetails
     * @return int
     * @throws Exception
     */
    private function createUser(ActivityDetails $activityDetails)
    {
        $email = null;

        if($activityDetails->getProfileAttribute(ActivityDetails::ATTR_EMAIL_ADDRESS))
        {
            $email = $activityDetails->getProfileAttribute(ActivityDetails::ATTR_EMAIL_ADDRESS);
        }
        else
        {
            $email = $this->generateEmail();
        }

        $username = $this->generateUsername($activityDetails);
        $password = $this->generatePassword();
        $userId = wp_create_user($username, $password, $email);
        $this->createYotiUser($userId, $activityDetails);

        return $userId;
    }

    /**
     * @param $yotiId
     * @return int
     */
    private function getUserIdByYotiId($yotiId)
    {
        // Query for users based on the meta data
        $users = (new WP_User_Query(
            array(
                'meta_key' => 'yoti_connect.identifier',
                'meta_value' => $yotiId,
            )
        ))->get_results();
        $user = reset($users);

        return ($user) ? $user->ID : null;
    }

    /**
     * @param $userId
     * @param ActivityDetails $activityDetails
     */
    private function createYotiUser($userId, ActivityDetails $activityDetails)
    {
        // create upload dir
        if (!is_dir(self::uploadDir()))
        {
            mkdir(self::uploadDir(), 0777, true);
        }

        $meta = array();
        foreach (self::$profileFields as $param => $label)
        {
            $meta[$param] = $activityDetails->getProfileAttribute($param);
        }

        $selfieFilename = null;
        $selfie = $activityDetails->getProfileAttribute(ActivityDetails::ATTR_SELFIE);
        if ($selfie)
        {
            $selfieFilename = md5("selfie_$userId") . ".png";
            file_put_contents(self::uploadDir() . "/$selfieFilename", $activityDetails->getProfileAttribute(ActivityDetails::ATTR_SELFIE));
            unset($meta[ActivityDetails::ATTR_SELFIE]);
            $meta['selfie_filename'] = $selfieFilename;
        }

        update_user_meta($userId, 'yoti_connect.profile', $meta);
        update_user_meta($userId, 'yoti_connect.identifier', $activityDetails->getUserId());
    }

    /**
     * @param int $userId joomla user id
     */
    private function deleteYotiUser($userId)
    {
        delete_user_meta($userId, 'yoti_connect.identifier');
        delete_user_meta($userId, 'yoti_connect.profile');
    }

    /**
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
     * @param $userId
     * @return mixed
     */
    public static function getUserProfile($userId)
    {
        $yotiId = get_user_meta($userId, 'yoti_connect.identifier');
        $dbProfile = get_user_meta($userId, 'yoti_connect.profile');
        $dbProfile = reset($dbProfile);

        return $dbProfile;
    }

    /**
     * @return string
     */
    public static function uploadDir()
    {
        return WP_CONTENT_DIR . '/uploads/yoti';
    }

    /**
     * @return string
     */
    public static function uploadUrl()
    {
        return content_url('/uploads/yoti');
    }

    /**
     * @return array
     */
    public static function getConfig()
    {
        if (self::mockRequests())
        {
            $config = require_once __DIR__ . '/sdk/sample-data/config.php';
            return $config;
        }

        return maybe_unserialize(get_option('yoti_connect'));
    }

    /**
     * @return null|string
     */
    public static function getLoginUrl()
    {
        $config = self::getConfig();
        if (empty($config['yoti_app_id']))
        {
            return null;
        }

        return YotiClient::getLoginUrl($config['yoti_app_id']);
    }
}