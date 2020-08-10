<?php
defined('ABSPATH') or die();
/**
 * @var array $dbProfile
 * @var string $selfieUrl
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
if (!empty($selfieUrl))
{
    ?>
    <tr>
        <th><label><?php esc_html_e($profileFields[UserProfile::ATTR_SELFIE]); ?></label></th>
        <td>
            <img src="<?php esc_attr_e($selfieUrl); ?>" width="100" />
        </td>
    </tr>
<?php
}

foreach ($dbProfile as $attrName => $value)
{
    $label = isset($profileFields[$attrName]) ? $profileFields[$attrName] : $attrName;
    ?>
    <tr>
        <th><label><?php esc_html_e($label); ?></label></th>
        <td>
            <?php if (!empty($value)) { ?>
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