<?php
/**
 * Created by Amin.MasterkinG
 * Website : MasterkinG32.CoM
 * Email : lichwow_masterking@yahoo.com
 * Date: 04/02/2020 - 6:55 PM
 */
?>
<section id="connect" class="services">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2><?php elang('how_to_connect'); ?></h2>
            <p><?php elang('realmlist'); ?>/<?php elang('realmlist'); ?>:
                <code><?php echo strtoupper(get_config('realmlist')); ?></code></p>
        </div>
        <div class="row">
            <div class="col-lg-6 order-2 order-lg-1">
                <div class="icon-box mt-5 mt-lg-0" data-aos="fade-up">
                    <i class="bx bx-user-plus"></i>
                    <h4><?php elang('create_account'); ?></h4>
                    <?php if (empty(get_config('disable_registration'))) { ?>
                    <p>First, create an account. You use it to log in to both the game and this website.
                        <a href="#" data-toggle="modal" data-target="#register-modal">Register now</a>.</p>
                    <?php } else { ?>
                    <p>Accounts are invite-only. Ask the server owner to create one for you.</p>
                    <?php } ?>
                </div>
                <div class="icon-box mt-5" data-aos="fade-up" data-aos-delay="100">
                    <i class="bx bx-download"></i>
                    <h4><?php elang('download_game'); ?></h4>
                    <p><?php elang('create_account_tip2'); ?></p>
                </div>
                <div class="icon-box mt-5" data-aos="fade-up" data-aos-delay="200">
                    <i class="bx bx-game"></i>
                    <h4><?php elang('setup_game'); ?></h4>
                    <p><?php elang('create_account_tip3'); ?></p>
                </div>
                <div class="icon-box mt-5" data-aos="fade-up" data-aos-delay="300">
                    <i class="bx bx-server"></i>
                    <h4><?php elang('change_server_address'); ?></h4>
                    <p><?php elang('create_account_tip4'); ?>
                        <code><?php echo strtoupper(get_config('realmlist')); ?></code></p>
                </div>
            </div>
            <div class="image col-lg-6 order-1 order-lg-2"
                 style='background-image: url("<?php echo $antiXss->xss_clean(get_config("baseurl")); ?>/template/<?php echo $antiXss->xss_clean(get_config("template")); ?>/assets/img/sylvanas.png");background-size: auto 100%;background-position: center;background-repeat: no-repeat;'
                 data-aos="fade-left" data-aos-delay="100"></div>
        </div>
        <?php $portalkeeper = realm_config::render(); if ($portalkeeper !== '') { ?>
        <div class="row mt-5">
            <div class="col-lg-12" data-aos="fade-up"><?php echo $portalkeeper; ?></div>
        </div>
        <?php } ?>
    </div>
</section>