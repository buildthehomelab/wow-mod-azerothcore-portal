<?php
/**
 * What the realm offers, for the homepage's feature book (template/advance/tpl/server-info.php).
 *
 * One chapter per tab. An entry is [icon, title, text]: icon is a file in
 * template/advance/assets/img/icons without .jpg, text allows **bold**. An optional 'class' key
 * colours the title in that class's colour.
 **/

return [
    'Adventure' => [
        'intro' => 'Dungeons and raids with more to chase, and a reason to go back.',
        'entries' => [
            ['icon' => 'inv_sword_48', 'title' => 'Lost Legendaries', 'text' => 'Legendaries cut from the game are back in the loot tables: Geddon and Garr\'s **Talismans of Binding**, Kazzak\'s **Shard of the Defiler**, Sapphiron\'s **Glaive of the Defender** and Nefarian\'s chromatic drake. Then there is the Ashbringer.'],
            ['icon' => 'inv_misc_coin_17', 'title' => 'Boss Coins', 'text' => 'Classic raid bosses and the endgame dungeon bosses pay **Tarnished Undermine Reals** into a wallet your whole account shares, plus a few for logging in each day. Spend them in the launcher\'s shop.'],
            ['icon' => 'trade_archaeology', 'title' => 'Archaeology', 'text' => 'Cataclysm\'s Archaeology arrives early: dig sites on every continent, eight races to research, rare finds to solve, and the **Unearthed Relic**, a weapon that grows with you.'],
            ['icon' => 'inv_misc_pocketwatch_01', 'title' => 'Daily raid lockouts', 'text' => 'Molten Core through Naxxramas reset every day, so a small group or a bot raid can try again tomorrow, not next week.'],
            ['icon' => 'inv_misc_groupneedmore', 'title' => 'Era-bound Dungeon Finder', 'text' => 'The Dungeon Finder follows your progression: it never sends you to a dungeon from an era you haven\'t reached.'],
            ['icon' => 'inv_alchemy_elixir_02', 'title' => 'Delver\'s Draught', 'text' => 'Every dungeon run starts with five draughts that restore half your health and mana, at any level.'],
            ['icon' => 'inv_misc_note_06', 'title' => 'Dungeon quests that pay', 'text' => 'One-time dungeon quests give **triple experience**, so the quest log is worth reading before you zone in.'],
        ],
    ],
    'Playerbots' => [
        'intro' => 'The world is never empty. Bots level, quest, trade and fight beside you.',
        'entries' => [
            ['icon' => 'achievement_character_human_male', 'title' => 'A populated realm', 'text' => 'Playerbots of every race and class level up, quest, sell on the Auction House and answer a party invite.'],
            ['icon' => 'achievement_boss_edwinvancleef', 'title' => 'Dungeon Clear', 'text' => 'Say **dc on** in party chat and your tank bot runs the dungeon: pulling, looting, resting and handling the levers, gongs and escorts along the way.'],
            ['icon' => 'achievement_boss_ragnaros', 'title' => 'Bot raids', 'text' => 'Type **.botraid assemble mc** and a 40-player raid of the right tanks, healers and hunters joins you, Horde and Alliance together.'],
            ['icon' => 'spell_arcane_teleportstormwind', 'title' => 'City Life', 'text' => 'Capitals fill up around the bank, the auction house and the flight master, busier by day and quieter at night.'],
            ['icon' => 'inv_scroll_03', 'title' => 'Bot chatter', 'text' => 'Bots talk in chat about what is going on around them, not from a list of canned lines.'],
        ],
    ],
    'Classes' => [
        'intro' => 'Every class keeps its Wrath kit, and several get more.',
        'entries' => [
            ['icon' => 'classicon_druid', 'class' => 'druid', 'title' => 'Druid', 'text' => 'Swipe costs no rage, dodges build it, and **Pulverize** arrives at 42. Travel Form works indoors, and bears can fish salmon out of the river with Swipe.'],
            ['icon' => 'classicon_hunter', 'class' => 'hunter', 'title' => 'Hunter', 'text' => 'Pets share your crit and haste and hit much harder. Marksmen with **Lone Wolf** call down a **Summon Hawk** instead.'],
            ['icon' => 'classicon_paladin', 'class' => 'paladin', 'title' => 'Paladin', 'text' => '**Holy Strike** for everyone at level 6, the tanking **Seal of Fury** at 10, and blessings that last an hour.'],
            ['icon' => 'classicon_rogue', 'class' => 'rogue', 'title' => 'Rogue', 'text' => 'Combo points carry over to your next target for 20 seconds after a kill, at their full count.'],
            ['icon' => 'classicon_shaman', 'class' => 'shaman', 'title' => 'Shaman', 'text' => '**Ghost Wolf** speeds up with your level, works indoors, and lets you mine without changing back.'],
            ['icon' => 'achievement_character_dwarf_male', 'title' => 'New race and class pairs', 'text' => 'Roll an **Orc Mage**, **Troll Warlock**, **Undead Paladin**, **Human Hunter**, **Gnome Priest** or **Dwarf Shaman**, each with its class quests and trainer.'],
            ['icon' => 'achievement_character_troll_male', 'title' => 'Reworked racials', 'text' => 'Every race gets two active racials and two passives, so no race is the wrong pick for a class.'],
            ['icon' => 'inv_misc_book_11', 'title' => 'A talent point every level', 'text' => 'Talents start at level 1, class spells are learned as you level, and **Dual Specialization** is open from level 10.'],
            ['icon' => 'spell_shadow_shadowwordpain', 'title' => 'Critical ticks', 'text' => 'Damage and healing over time can crit on every tick.'],
        ],
    ],
    'Collections' => [
        'intro' => 'Thousands of things to find, and an account that keeps them.',
        'entries' => [
            ['icon' => 'inv_chest_cloth_17', 'title' => 'Retail transmogrification', 'text' => 'An account-wide appearance collection with Sets, Outfits and illusions. Looks unlock as you loot, and each slot keeps its look when you change gear.'],
            ['icon' => 'inv_misc_book_09', 'title' => 'The Journal', 'text' => 'Dungeons, raids, mounts, pets, beasts and lore in one window, with 3D previews and where to get everything you\'re missing. Press **Shift-J**.'],
            ['icon' => 'achievement_level_80', 'title' => 'Retail mounts and achievements', 'text' => '1,240 mounts and 1,665 companions from later expansions are in the client, and 105 retail achievements already mail you theirs.'],
            ['icon' => 'ability_druid_catform', 'class' => 'druid', 'title' => '265 druid form looks', 'text' => 'Bear, cat, travel, flight, moonkin and tree. Every raid tier you clear unlocks a new batch, up to the Batbear.'],
            ['icon' => 'spell_nature_stoneskintotem', 'class' => 'shaman', 'title' => 'Shaman totem looks', 'text' => 'Every racial totem set from the start, and a retail set for each raid tier you clear.'],
            ['icon' => 'spell_shadow_summonfelguard', 'class' => 'warlock', 'title' => 'Warlock demon looks', 'text' => '74 looks for your demons, from Fel Imps and Shivarra to Darkglares and Doom Lords.'],
            ['icon' => 'ability_hunter_beasttaming', 'class' => 'hunter', 'title' => 'Beast Collection', 'text' => 'Keep every pet you tame, fill a field guide of 657 beast looks, and watch for the 1 in 100 **shiny** spawn.'],
            ['icon' => 'inv_pet_redpanda', 'title' => 'Red pandas', 'text' => '**Nari Rustpaw** in Booty Bay sells red panda companions, and the rideable **Great Red Panda** joins her stock as you clear raids.'],
            ['icon' => 'ability_mount_gryphon_01', 'title' => 'Shared across your account', 'text' => 'Mounts, companions and discovered flight paths carry over to every character you make.'],
        ],
    ],
    'World' => [
        'intro' => 'An Azeroth with weather, night and things happening in it.',
        'entries' => [
            ['icon' => 'spell_nature_callstorm', 'title' => 'Weather fronts', 'text' => 'Storms build, peak and drift across neighbouring zones, with fog, thunder and snow in 43 more zones than before.'],
            ['icon' => 'spell_arcane_starfire', 'title' => 'Real nights', 'text' => 'Nights are dark under a sky full of stars, and lamps and campfires light the ground. Pick your own darkness with **.night 1** to **5**.'],
            ['icon' => 'inv_torch_lit', 'title' => 'Night Watchman\'s Torch', 'text' => 'From dusk to dawn your character carries a lit torch whenever weapons are sheathed.'],
            ['icon' => 'achievement_zone_silithus_01', 'title' => 'Era events', 'text' => 'The **AQ gate war**, the **Scourge Invasion**, the **Legion Incursion** and the **Zombie Infestation** return when players reach each era.'],
            ['icon' => 'inv_misc_head_dragon_01', 'title' => 'World buffs', 'text' => '**Rallying Cry of the Dragonslayer**, **Warchief\'s Blessing** and **Spirit of Zandalar** go out on a random timer, with a warning 10 minutes ahead.'],
            ['icon' => 'inv_misc_map_01', 'title' => 'Rare spawns', 'text' => 'Bots leave rares alone, and the **Rare Map** on this site shows which are up right now.'],
            ['icon' => 'inv_misc_bag_10_black', 'title' => 'Black Market', 'text' => '**Mister Chu** takes bids in Booty Bay on rare mounts and companions. A new shipment arrives every day.'],
            ['icon' => 'spell_nature_resistnature', 'title' => 'Biomes', 'text' => 'Themed zones grant resistances while you\'re in them.'],
        ],
    ],
    'Crafting' => [
        'intro' => 'Professions and trade without the busywork.',
        'entries' => [
            ['icon' => 'inv_misc_bag_enchantedmageweave', 'title' => 'Reagent Bank', 'text' => 'One bank for crafting materials, shared by your whole account and open from anywhere. Recipes pull from it directly.'],
            ['icon' => 'trade_blacksmithing', 'title' => 'Retail profession window', 'text' => 'Search, filters, skill-up odds, recipes you\'re missing and where to learn them, and a profit view built from auction prices.'],
            ['icon' => 'inv_misc_coin_02', 'title' => 'Retail Auction House', 'text' => 'Buy, Sell and Auctions tabs with grouped search, favorites, commodity prices and a sales ledger.'],
            ['icon' => 'trade_engineering', 'title' => 'Every specialization', 'text' => 'Gnomish and Goblin, every smithing and leatherworking path, every tailoring and alchemy specialty. Learn them all.'],
            ['icon' => 'inv_misc_pocketwatch_02', 'title' => 'No craft cooldowns', 'text' => 'Transmutes, Mooncloth, spheres, prisms and research craft as often as you have the materials.'],
            ['icon' => 'inv_fabric_silk_02', 'title' => 'Stacks of 999', 'text' => 'Trade goods stack to 999, reagents and consumables to 200.'],
            ['icon' => 'trade_mining', 'title' => 'Gathering', 'text' => 'Mining and Skinning need no tools, and nodes respawn twice as fast.'],
        ],
    ],
    'Comfort' => [
        'intro' => 'The small things that make an evening of play go further.',
        'entries' => [
            ['icon' => 'inv_shirt_guildtabard_01', 'title' => 'Guild levels', 'text' => 'Guilds level from 1 to 25 on quests and boss kills, unlock perks along the way, and earn guild reputation for heirlooms, mounts and banners.'],
            ['icon' => 'ability_mount_ridinghorse', 'title' => 'Ride at level 5', 'text' => 'Apprentice Riding costs 5 silver at level 5, and mount speed grows with your level.'],
            ['icon' => 'inv_misc_rune_01', 'title' => '5-minute Hearthstone', 'text' => 'Go home, sell, and get back to it.'],
            ['icon' => 'inv_misc_bag_07', 'title' => 'Easy looting', 'text' => 'Loot every corpse at once, let your companion pet pick up the rest, and have gray junk sold the moment you loot it.'],
            ['icon' => 'inv_box_04', 'title' => 'One-click lockboxes', 'text' => 'Rogues right-click a lockbox to pick it and open it in one go.'],
            ['icon' => 'inv_bannerpvp_03', 'title' => 'Cross-faction groups', 'text' => 'Group, raid, trade and chat with friends on the other faction.'],
        ],
    ],
];
