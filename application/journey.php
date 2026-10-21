<?php
/**
 * The progression journey, for the homepage (template/advance/tpl/server-info.php).
 *
 * Three chapters, one per expansion, each a list of phases in the order a character reaches them
 * (mod-individual-progression's tiers, merged where a tier only adds a boss), then the gate into the
 * next chapter. A phase is [icon, title, text, event?]: icon is a file in
 * template/advance/assets/img/icons without .jpg; event names the live world event of that phase.
 **/

return [
    [
        'name' => 'Classic',
        'levels' => 'Levels 1 to 60',
        'art' => '0-1',
        'text' => 'Azeroth on launch day. No Outland, no Northrend, no flying, and the raids of 2004 in the order they came.',
        'phases' => [
            ['icon' => 'achievement_boss_ragnaros', 'title' => 'Molten Core', 'text' => 'Ragnaros below Blackrock Mountain, and Onyxia in her lair.'],
            ['icon' => 'achievement_boss_nefarion', 'title' => 'Blackwing Lair', 'text' => 'Nefarian\'s halls open once the Firelord falls.'],
            ['icon' => 'achievement_boss_hakkar', 'title' => 'Zul\'Gurub', 'text' => 'Hakkar stirs, and both factions gather supplies for the war effort.'],
            ['icon' => 'achievement_boss_cthun', 'title' => 'Gates of Ahn\'Qiraj', 'text' => 'Bang the gong and the Scarab Wall opens on the Ruins and the Temple.', 'event' => 'The Ahn\'Qiraj War'],
            ['icon' => 'achievement_boss_kelthuzad_01', 'title' => 'Naxxramas', 'text' => 'The necropolis floats over the Plaguelands while the Scourge invades.', 'event' => 'Scourge Invasion'],
        ],
        'gate' => ['icon' => 'spell_arcane_portalshattrath', 'title' => 'The Dark Portal opens', 'text' => 'Defeat Kel\'Thuzad. Fel portals tear open across the old world, then the way to Outland lies open.', 'event' => 'Legion Incursion'],
    ],
    [
        'name' => 'The Burning Crusade',
        'levels' => 'Levels 58 to 70',
        'art' => '1-2',
        'text' => 'Outland, with the attunement chains that came with it.',
        'phases' => [
            ['icon' => 'achievement_boss_princemalchezaar_02', 'title' => 'Karazhan', 'text' => 'Medivh\'s tower, with Gruul and Magtheridon waiting in their lairs.'],
            ['icon' => 'achievement_boss_ladyvashj', 'title' => 'Serpentshrine and Tempest Keep', 'text' => 'Lady Vashj under Zangarmarsh and Kael\'thas in the Eye.'],
            ['icon' => 'achievement_boss_illidan', 'title' => 'Hyjal and Black Temple', 'text' => 'Archimonde at the World Tree, then Illidan on his throne.'],
            ['icon' => 'achievement_boss_kiljaedan', 'title' => 'Sunwell Plateau', 'text' => 'The Isle of Quel\'Danas, and Kil\'jaeden at the Sunwell.', 'event' => 'Zombie Infestation'],
        ],
        'gate' => ['icon' => 'achievement_zone_northrend_01', 'title' => 'The fleet sails north', 'text' => 'Defeat Kil\'jaeden. A plague reaches the capitals, then the ships leave for Northrend.'],
    ],
    [
        'name' => 'Wrath of the Lich King',
        'levels' => 'Levels 68 to 80',
        'art' => '2-3',
        'text' => 'Northrend, and the war that ends at the Frozen Throne.',
        'phases' => [
            ['icon' => 'achievement_boss_malygos_01', 'title' => 'Naxxramas returns', 'text' => 'Naxxramas over Dragonblight, Malygos and Sartharion.'],
            ['icon' => 'achievement_boss_yoggsaron_01', 'title' => 'Ulduar', 'text' => 'The titan city, and what sleeps beneath it.'],
            ['icon' => 'achievement_reputation_argentcrusader', 'title' => 'Trial of the Crusader', 'text' => 'The Argent Tournament\'s coliseum.'],
            ['icon' => 'achievement_boss_lichking', 'title' => 'Icecrown Citadel', 'text' => 'The Lich King on the Frozen Throne.'],
            ['icon' => 'spell_shadow_twilight', 'title' => 'Ruby Sanctum', 'text' => 'Halion, the last fight of the expansion.'],
        ],
    ],
];
