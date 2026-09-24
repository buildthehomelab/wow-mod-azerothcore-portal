<?php
/**
 * Created by Amin.MasterkinG
 * Website : MasterkinG32.CoM
 * Email : lichwow_masterking@yahoo.com
 * Date: 04/02/2020 - 6:55 PM
 */
?>
<section id="contact" class="contact section-bg">
        <div class="container">
            <div class="section-title">
                <h2><?php elang('contact'); ?></h2>
                <p><?php elang('edit_on'); ?> <b>"/template/advance/tpl/contact.php"</b>.</p>
            </div>
            <div class="row">
                <div class="col-lg-12">
                    <div class="info d-flex flex-column justify-content-center" data-aos="fade-right">
                        <div class="address">
                            <i class="icofont-google-map"></i>
                            <h4><?php elang('location'); ?>:</h4>
                            <p>Tehran, Iran</p>
                        </div>

                        <?php if (!empty(get_config('contact_email'))) { $contact_email = htmlspecialchars(get_config('contact_email')); ?>
                        <div class="email">
                            <i class="icofont-envelope"></i>
                            <h4><?php elang('email'); ?>:</h4>
                            <p><a href="mailto:<?php echo $contact_email; ?>"><?php echo $contact_email; ?></a></p>
                        </div>
                        <?php } ?>
                        <div class="phone">
                            <i class="icofont-phone"></i>
                            <h4><?php elang('call'); ?>:</h4>
                            <p>+98 915 620 9344</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>