<?php
defined('ABSPATH') or die();
/**
 * @var array $dbProfile
 * @var bool $displayButton
 * @var int $userId
 */

use Yoti\Profile\UserProfile;
use Yoti\WP\Button;
use Yoti\WP\User;

// Display these fields
$profileFields = User::profileFields();
?>
<h2><?php esc_html_e('Yoti User Profile'); ?></h2>
<table class="form-table">
<?php
foreach ($dbProfile as $attrName => $value)
{
    $label = isset($profileFields[$attrName]) ? $profileFields[$attrName] : $attrName;

    // Display selfie as an image
    if ($attrName === User::SELFIE_FILENAME) {
        $selfieUrl = '';
        $label = $profileFields[UserProfile::ATTR_SELFIE];
        $selfieFileName = $dbProfile[User::SELFIE_FILENAME];
        $selfieFullPath = User::uploadDir() . '/' . $selfieFileName;
        if (!empty($selfieFileName) && is_file($selfieFullPath)) {
            $selfieUrl = User::selfieUrl($userId);
        }
    }
    ?>
    <tr>
        <th><label><?php esc_html_e($label); ?></label></th>
        <td>
            <?php if ($attrName === User::SELFIE_FILENAME && !empty($selfieUrl)) { ?>
                <img src="<?php esc_attr_e($selfieUrl); ?>" width="100" />
            <?php } elseif (!empty($value)) { ?>
                <?php esc_html_e($value); ?>
            <?php } else { ?>
                <i>(empty)</i>
            <?php } ?>
        </td>
    </tr>
<?php
}
?>
<?php if ($displayButton) { ?>
    <tr>
        <th></th>
        <td><?php Button::render($_SERVER['REQUEST_URI'], FALSE); ?></td>
    </tr>
<?php } ?>
</table>