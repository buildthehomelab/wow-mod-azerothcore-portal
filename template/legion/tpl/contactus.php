<?php
/**
 * Created by Amin.MasterkinG
 * Website : MasterkinG32.CoM
 * Email : lichwow_masterking@yahoo.com
 * Date: 11/26/2018 - 8:36 PM
 */
?>
<div class="content_box1" style="line-height: 1.5;">
    <?php if (!empty(get_config('contact_email'))) { $contact_email = htmlspecialchars(get_config('contact_email')); ?>
    <p><?php elang('email'); ?> : <a href="mailto:<?php echo $contact_email; ?>" style="color: #00FF00;"><?php echo $contact_email; ?></a></p>
    <?php } ?>
</div>
