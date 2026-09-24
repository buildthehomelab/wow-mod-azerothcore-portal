<?php
/**
 * Created by Amin.MasterkinG
 * Website : MasterkinG32.CoM
 * Email : lichwow_masterking@yahoo.com
 * Date: 04/02/2020 - 6:55 PM
 */
?>
    <section id="about" class="about">
        <div class="container">
            <div class="section-title" data-aos="fade-up">
                <h2><?php elang('about_server'); ?></h2>
                <p><?php elang('game_version'); ?>: <b><span style="color: #007a0c"><?php echo get_config('game_version'); ?></span></b>
                </p>
            </div>
            <div class="row">
                <div class="col-lg-6" data-aos="fade-right">
                    <div class="image">
                        <img src="<?php echo $antiXss->xss_clean(get_config("baseurl")); ?>/template/<?php echo $antiXss->xss_clean(get_config("template")); ?>/assets/img/jaina.png"
                             class="img-fluid" alt="">
                    </div>
                </div>
                <div class="col-lg-6" data-aos="fade-left">
                    <div class="content pt-4 pt-lg-0 pl-0 pl-lg-3 ">
                        <h3><?php elang('server_information'); ?>:</h3>
                        <ul>
                            <li><i class="bx bx-check-double"></i><?php elang('xp_rate'); ?>: <b>x1</b></li>
                            <li><i class="bx bx-check-double"></i><?php elang('drop_rate'); ?>: <b>Standard</b></li>
                            <li><i class="bx bx-check-double"></i><?php elang('start_level'); ?>: <b>1</b></li>
                            <li><i class="bx bx-check-double"></i><?php elang('max_level'); ?>: <b>80</b></li>
                        </ul>
                        <h3>Features:</h3>
                        <ul>
                            <li><i class="bx bx-check-double"></i><b>Reagent bank:</b> store all your crafting materials, open it from anywhere, and pull what a recipe needs in one click</li>
                            <li><i class="bx bx-check-double"></i>Individual progression through every tier (Vanilla, TBC, WotLK)</li>
                            <li><i class="bx bx-check-double"></i>Account-wide professions</li>
                            <li><i class="bx bx-check-double"></i>AoE loot</li>
                            <li><i class="bx bx-check-double"></i>Junk auto-sold on pickup</li>
                            <li><i class="bx bx-check-double"></i>Playerbots</li>
                            <li><i class="bx bx-check-double"></i>Server buffs</li>
                            <li><i class="bx bx-check-double"></i>Biome effects</li>
                            <li><i class="bx bx-check-double"></i>Mounts at level 5</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>