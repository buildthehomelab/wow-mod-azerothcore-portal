<?php
/**
 * Turns a WoW 3.3.5a combat log (Logs/WoWCombatLog.txt, plain or gzip) into a digest: the log cut
 * into fights, and per fight what a raid leader would ask about the group: who died to what, which
 * enemy abilities hit players, which casts were interrupted, which debuffs were dispelled, who
 * broke crowd control and who stood around.
 *
 * The 3.3.5 log has no zone or encounter events, so a fight is a stretch of damage between the
 * logging player's group and hostile creatures with no gap longer than CLOG_FIGHT_GAP, named after
 * the creature that took the most damage.
 *
 * Pure: no database, no environment. combatlog.php adds what needs those (bot names, boss entries).
 **/

declare(strict_types=1);

const CLOG_DIGEST_VERSION = 2;
const CLOG_FIGHT_GAP = 15.0;        // seconds without group-vs-hostile contact that end a fight
const CLOG_RUN_GAP = 1200.0;        // seconds between fights that start a new run
const CLOG_DEATH_WINDOW = 8.0;      // hits this long before a death are listed with it
const CLOG_ACTIVITY_BUCKET = 5.0;   // a player is "active" in a bucket they did something in
const CLOG_TABLE_ROWS = 40;         // rows kept per table per fight
const CLOG_MAX_RAW = 4294967296;    // bytes of log read before giving up: a real one is a few hundred MB
const CLOG_FEIGN_DEATH = '5384';
const CLOG_ALIVE_WINDOW = 15.0;     // a player who acts this soon after a UNIT_DIED, with no rez, didn't die
const CLOG_IN_FLIGHT = 1.5;         // a shot landing this soon after a death was fired before it
const CLOG_DEATH_BUFFS = 3;         // a real death strips the buffs: this many removed within a second of it
/** Spells a dead player casts to come back; acting with them doesn't mean the player was alive. */
const CLOG_SELF_REZ = ['Reincarnation' => 1, 'Use Soulstone' => 1, 'Soulstone Resurrection' => 1];
/** Spirit of Redemption: a priest keeps healing for 15 s after a real death. */
const CLOG_SPIRIT_OF_REDEMPTION = ['27827' => 1, '27792' => 1];

const CLOG_GROUP = 1;
const CLOG_PLAYER = 2;
const CLOG_HOSTILE = 4;
const CLOG_NPC = 8;
const CLOG_PET = 16;
const CLOG_MINE = 32;

/** What a unit is, from the flags column (COMBATLOG_OBJECT_*). */
function clog_unit_kind(string $hex): int
{
    $flags = (int)hexdec($hex);
    $kind = 0;
    if ($flags & 0x7) {          // mine, party or raid
        $kind |= CLOG_GROUP;
    }
    if ($flags & 0x1) {
        $kind |= CLOG_MINE;
    }
    if ($flags & 0x400) {
        $kind |= CLOG_PLAYER;
    }
    if ($flags & 0x800) {
        $kind |= CLOG_NPC;
    }
    if ($flags & 0x3000) {       // pet or guardian (totems are guardians)
        $kind |= CLOG_PET;
    }
    if ($flags & 0x40) {
        $kind |= CLOG_HOSTILE;
    }
    return $kind;
}

/** The creature entry inside a creature or vehicle GUID (0xF130EEEEEESSSSSS), or 0. */
function clog_guid_entry(string $guid): int
{
    return (strlen($guid) === 18 && ($guid[4] === '3' || $guid[4] === '5') && strncasecmp($guid, '0xF1', 4) === 0)
        ? (int)hexdec(substr($guid, 6, 6))
        : 0;
}

final class ClogDigest
{
    /** @var array<string,int> */
    private array $kindCache = [];
    /** @var array<string,float> */
    private array $dayBase = [];
    private int $year;
    private int $month;
    private int $offsetSeconds;

    private int $lines = 0;
    private int $skipped = 0;
    private ?float $first = null;
    private ?float $last = null;
    private string $firstClock = '';
    private string $lastClock = '';
    private ?string $logger = null;

    /** @var array<string,string> group players seen anywhere in the log, guid => name */
    private array $roster = [];
    /** @var array<string,list<array{0:float,1:string,2:string,3:int,4:int,5:string}>> last hits per player */
    private array $recent = [];
    /** @var array<string,float> */
    private array $feignAt = [];
    /** @var array<string,array{fight:int,index:int,t:float}> the newest death per player, for a Feign Death aura that arrives just after */
    private array $lastDeath = [];
    /** @var array<string,array{fight:int,index:int,t:float,drops:int}> deaths that could still turn out not to be (the player acts again) */
    private array $maybeAlive = [];
    /** @var array<string,list<float>> when group players lost a buff, over the last second */
    private array $buffDrops = [];

    private ?array $fight = null;
    private float $lastContact = 0.0;
    private string $clock = '';
    /** @var list<array> */
    private array $fights = [];
    /** @var list<array> deaths outside any fight (falls, lava on the way in) */
    private array $strayDeaths = [];

    public function __construct(int $loggedUntil, int $utcOffsetMinutes)
    {
        $this->offsetSeconds = $utcOffsetMinutes * 60;
        $local = $loggedUntil + $this->offsetSeconds;
        $this->year = (int)gmdate('Y', $local);
        $this->month = (int)gmdate('n', $local);
    }

    /** Unix time of a "10/12 18:03:21.123" stamp. The log has no year: it's the upload's, or the one before for later months. */
    private function time(string $date, string $clock): float
    {
        $base = $this->dayBase[$date] ?? null;
        if ($base === null) {
            $slash = strpos($date, '/');
            $month = (int)substr($date, 0, (int)$slash);
            $day = (int)substr($date, (int)$slash + 1);
            $year = $month > $this->month ? $this->year - 1 : $this->year;
            $base = (float)(gmmktime(0, 0, 0, $month, $day, $year) - $this->offsetSeconds);
            $this->dayBase[$date] = $base;
        }
        return $base + (int)substr($clock, 0, 2) * 3600 + (int)substr($clock, 3, 2) * 60 + (float)substr($clock, 6);
    }

    public function line(string $line): void
    {
        // A log truncated while the game had it open starts with megabytes of NUL bytes.
        $line = trim($line, "\0\r\n ");
        if ($line === '') {
            return;
        }
        $split = strpos($line, '  ');
        $space = strpos($line, ' ');
        if ($split === false || $space === false || $space >= $split || $split > 20 || $line[0] < '0' || $line[0] > '9') {
            $this->skipped++;
            return;
        }
        $f = explode(',', substr($line, $split + 2));
        $count = count($f);
        if ($count < 7) {
            $this->skipped++;
            return;
        }
        // A name with a comma in it ("Shoot, Bow"): let the CSV reader sort the quotes out.
        if (($f[2] !== '' && $f[2][0] === '"' && substr($f[2], -1) !== '"') || ($f[5] !== '' && $f[5][0] === '"' && substr($f[5], -1) !== '"')
            || ($count > 8 && $f[8] !== '' && $f[8][0] === '"' && substr($f[8], -1) !== '"')
            || ($count > 11 && $f[11] !== '' && $f[11][0] === '"' && substr($f[11], -1) !== '"')) {
            $f = str_getcsv(substr($line, $split + 2), ',', '"', '');
            $count = count($f);
            if ($count < 7) {
                $this->skipped++;
                return;
            }
        }

        $this->clock = substr($line, $space + 1, $split - $space - 1);
        $t = $this->time(substr($line, 0, $space), $this->clock);
        $this->lines++;
        if ($this->first === null) {
            $this->first = $t;
            $this->firstClock = substr($line, 0, $split);
        }
        $this->last = $t;
        $this->lastClock = substr($line, 0, $split);

        $event = $f[0];
        $src = $f[1];
        $dst = $f[4];
        $srcKind = $this->kindCache[$f[3]] ??= clog_unit_kind($f[3]);
        $dstKind = $this->kindCache[$f[6]] ??= clog_unit_kind($f[6]);

        $srcGroup = ($srcKind & CLOG_GROUP) && !($srcKind & CLOG_HOSTILE);
        $dstGroup = ($dstKind & CLOG_GROUP) && !($dstKind & CLOG_HOSTILE);
        $srcPlayer = $srcGroup && ($srcKind & CLOG_PLAYER);
        $dstPlayer = $dstGroup && ($dstKind & CLOG_PLAYER);
        $srcEnemy = ($srcKind & CLOG_HOSTILE) && !($srcKind & CLOG_PLAYER);
        $dstEnemy = ($dstKind & CLOG_HOSTILE) && !($dstKind & CLOG_PLAYER);

        if ($srcPlayer && !isset($this->roster[$src])) {
            $this->roster[$src] = trim($f[2], '"');
        }
        if ($dstPlayer && !isset($this->roster[$dst])) {
            $this->roster[$dst] = trim($f[5], '"');
        }
        if ($this->logger === null && ($srcKind & CLOG_MINE) && ($srcKind & CLOG_PLAYER)) {
            $this->logger = trim($f[2], '"');
        }
        if ($event === 'SPELL_AURA_REMOVED' && $dstPlayer && ($f[10] ?? '') === 'BUFF') {
            $this->buffDropped($t, $dst);
        }
        if ($this->maybeAlive) {
            $this->checkAlive($t, $event, $f, $src, $dst);
        }

        $isDamage = false;
        $isMiss = false;
        switch ($event) {
            case 'SWING_DAMAGE':
            case 'RANGE_DAMAGE':
            case 'SPELL_DAMAGE':
            case 'SPELL_PERIODIC_DAMAGE':
            case 'DAMAGE_SHIELD':
            case 'DAMAGE_SPLIT':
                $isDamage = true;
                break;
            case 'SWING_MISSED':
            case 'RANGE_MISSED':
            case 'SPELL_MISSED':
            case 'SPELL_PERIODIC_MISSED':
            case 'DAMAGE_SHIELD_MISSED':
                $isMiss = true;
                break;
        }

        // Fight boundaries: contact between the group and hostile creatures.
        if (($isDamage || $isMiss) && (($srcGroup && $dstEnemy) || ($srcEnemy && $dstGroup))) {
            if ($this->fight === null || $t - $this->lastContact > CLOG_FIGHT_GAP) {
                $this->closeFight();
                $this->fight = self::newFight($t, $this->clock);
            }
            $this->lastContact = $t;
            $this->fight['end'] = $t;
            $this->fight['end_clock'] = $this->clock;
        } elseif ($this->fight !== null && $t - $this->lastContact > CLOG_FIGHT_GAP) {
            $this->closeFight();
        }
        $inFight = $this->fight !== null;

        if ($isDamage) {
            if ($event === 'SWING_DAMAGE') {
                $amount = (int)($f[7] ?? 0);
                $overkill = (int)($f[8] ?? 0);
                $spellId = '';
                $spellName = 'Melee';
            } else {
                $amount = (int)($f[10] ?? 0);
                $overkill = (int)($f[11] ?? 0);
                $spellId = (string)($f[7] ?? '');
                $spellName = trim((string)($f[8] ?? ''), '"');
            }
            $done = $overkill > 0 ? max(0, $amount - $overkill) : $amount;

            if ($dstPlayer) {
                $srcName = trim($f[2], '"');
                if ($srcKind & CLOG_PLAYER) {
                    // Another player: mind control or a reflected spell.
                    $key = 'p:' . $spellId . ':' . $spellName;
                    $label = $srcName . ' (player)';
                } elseif ($spellId === '') {
                    $key = 'm:' . (clog_guid_entry($src) ?: $srcName);
                    $label = $srcName;
                } else {
                    $key = 's:' . $spellId;
                    $label = $srcName;
                }
                $this->hit($t, $dst, $key, $spellId, $spellName, $label, $amount, $overkill, $inFight && $src !== $dst);
            }
            if ($inFight) {
                if ($dstEnemy && $srcGroup) {
                    $this->enemy($dst, trim($f[5], '"'), 'taken', $done);
                    if ($srcPlayer) {
                        $this->player($src, $t)['dmg'] += $done;
                    } else {
                        $name = trim($f[2], '"');
                        $this->fight['pets'][$name] = ($this->fight['pets'][$name] ?? 0) + $done;
                    }
                } elseif ($srcEnemy && $dstGroup) {
                    $this->enemy($src, trim($f[2], '"'), 'done', $done);
                }
            }
            return;
        }

        if ($isMiss) {
            if ($inFight && $srcPlayer && $dstEnemy) {
                $this->player($src, $t);
            }
            return;
        }

        switch ($event) {
            case 'ENVIRONMENTAL_DAMAGE':
                if ($dstPlayer) {
                    $type = ucfirst(strtolower((string)($f[7] ?? 'environment')));
                    $this->hit($t, $dst, 'e:' . $type, '', $type, 'Environment', (int)($f[8] ?? 0), (int)($f[9] ?? 0), $inFight);
                }
                return;

            case 'SPELL_HEAL':
            case 'SPELL_PERIODIC_HEAL':
                if ($inFight && $srcPlayer) {
                    $amount = (int)($f[10] ?? 0);
                    $over = (int)($f[11] ?? 0);
                    $player = &$this->player($src, $t);
                    $player['heal'] += max(0, $amount - $over);
                    $player['over'] += $over;
                    unset($player);
                }
                return;

            case 'SPELL_CAST_START':
                // Only spells with a cast time log a start (instant ones log SPELL_CAST_SUCCESS instead),
                // so these are the casts that could have been interrupted.
                if ($inFight && $srcEnemy) {
                    $this->cast((string)$f[7], trim((string)$f[8], '"'), trim($f[2], '"'))['start']++;
                }
                return;

            case 'SPELL_CAST_SUCCESS':
                if (!$inFight) {
                    return;
                }
                if ($srcPlayer) {
                    $this->player($src, $t);
                }
                return;

            case 'SPELL_INTERRUPT':
                if ($inFight && $dstEnemy && $srcGroup && isset($f[11])) {
                    $cast = &$this->cast((string)$f[10], trim((string)$f[11], '"'), trim($f[5], '"'));
                    $cast['stopped']++;
                    $by = trim($f[2], '"');
                    $cast['by'][$by] = ($cast['by'][$by] ?? 0) + 1;
                    unset($cast);
                }
                return;

            case 'SPELL_AURA_APPLIED':
                $id = (string)($f[7] ?? '');
                if ($id === CLOG_FEIGN_DEATH && $dstPlayer) {
                    $this->feignAt[$dst] = $t;
                    $death = $this->lastDeath[$dst] ?? null;
                    if ($death !== null && $t - $death['t'] <= 2.0) {
                        $this->markFeign($death);
                    }
                    return;
                }
                if (!$inFight) {
                    return;
                }
                $type = $f[10] ?? '';
                if ($type === 'DEBUFF' && $dstPlayer && !$srcGroup) {
                    $debuff = &$this->debuff($id, trim((string)$f[8], '"'), $src === '0x0000000000000000' ? '' : trim($f[2], '"'));
                    $debuff['applied']++;
                    $debuff['victims'][$dst] = 1;
                    unset($debuff);
                    $this->fight['open'][$dst . $id] = $t;
                } elseif ($type === 'BUFF' && $dstEnemy && $srcEnemy) {
                    $buff = &$this->enemyBuff($id, trim((string)$f[8], '"'), trim($f[5], '"'));
                    $buff['applied']++;
                    unset($buff);
                }
                return;

            case 'SPELL_DISPEL':
            case 'SPELL_STOLEN':
                if (!$inFight || !$srcGroup || !isset($f[11])) {
                    return;
                }
                $id = (string)$f[10];
                $by = trim($f[2], '"');
                if ($dstPlayer) {
                    // Only what an enemy put there: a dispelled Mind Control or a friendly's debuff isn't on the list.
                    if (isset($this->fight['debuffs'][$id])) {
                        $debuff = &$this->fight['debuffs'][$id];
                        $debuff['dispelled']++;
                        $debuff['by'][$by] = ($debuff['by'][$by] ?? 0) + 1;
                        $since = $this->fight['open'][$dst . $id] ?? null;
                        if ($since !== null) {
                            $debuff['wait'] += $t - $since;
                            $debuff['waited']++;
                            unset($this->fight['open'][$dst . $id]);
                        }
                        unset($debuff);
                    }
                } elseif ($dstEnemy) {
                    $buff = &$this->enemyBuff($id, trim((string)$f[11], '"'), trim($f[5], '"'));
                    $buff['removed']++;
                    $buff['by'][$by] = ($buff['by'][$by] ?? 0) + 1;
                    unset($buff);
                }
                return;

            case 'SPELL_AURA_BROKEN':
            case 'SPELL_AURA_BROKEN_SPELL':
                if ($inFight && $dstEnemy && $srcGroup) {
                    $with = $event === 'SPELL_AURA_BROKEN_SPELL' ? trim((string)($f[11] ?? ''), '"') : 'Melee';
                    $key = trim((string)$f[8], '"') . "\t" . trim($f[2], '"') . "\t" . $with;
                    $this->fight['broken'][$key] = ($this->fight['broken'][$key] ?? 0) + 1;
                }
                return;

            case 'UNIT_DIED':
                if ($dstPlayer) {
                    $this->death($t, $dst, trim($f[5], '"'));
                } elseif ($inFight && $dstEnemy) {
                    $this->enemy($dst, trim($f[5], '"'), 'died', 1);
                }
                return;

            case 'SPELL_RESURRECT':
                if ($inFight && $dstPlayer) {
                    $this->fight['rezzes']++;
                }
                return;
        }
    }

    private static function newFight(float $t, string $clock): array
    {
        return [
            'start' => $t, 'end' => $t, 'clock' => $clock, 'end_clock' => $clock,
            'enemies' => [], 'players' => [], 'pets' => [], 'taken' => [], 'casts' => [],
            'debuffs' => [], 'buffs' => [], 'broken' => [], 'deaths' => [], 'open' => [], 'rezzes' => 0,
        ];
    }

    private function &member(string $guid): array
    {
        if (!isset($this->fight['players'][$guid])) {
            $this->fight['players'][$guid] = ['dmg' => 0, 'heal' => 0, 'over' => 0, 'taken' => 0, 'first' => null, 'buckets' => []];
        }
        return $this->fight['players'][$guid];
    }

    /** A group player who did something at $t: counts toward how active they were. */
    private function &player(string $guid, float $t): array
    {
        $player = &$this->member($guid);
        $player['first'] ??= $t - $this->fight['start'];
        $player['buckets'][(int)(($t - $this->fight['start']) / CLOG_ACTIVITY_BUCKET)] = 1;
        return $player;
    }

    private function enemy(string $guid, string $name, string $field, int $amount): void
    {
        $key = clog_guid_entry($guid) ?: $name;
        if (!isset($this->fight['enemies'][$key])) {
            $this->fight['enemies'][$key] = ['name' => $name, 'units' => [], 'taken' => 0, 'done' => 0, 'died' => 0];
        }
        $this->fight['enemies'][$key]['units'][$guid] = 1;
        $this->fight['enemies'][$key][$field] += $amount;
    }

    private function &cast(string $id, string $name, string $source): array
    {
        if (!isset($this->fight['casts'][$id])) {
            $this->fight['casts'][$id] = ['name' => $name, 'src' => $source, 'start' => 0, 'stopped' => 0, 'by' => []];
        }
        return $this->fight['casts'][$id];
    }

    private function &debuff(string $id, string $name, string $source): array
    {
        if (!isset($this->fight['debuffs'][$id])) {
            $this->fight['debuffs'][$id] = ['name' => $name, 'src' => $source, 'applied' => 0, 'dispelled' => 0,
                'wait' => 0.0, 'waited' => 0, 'victims' => [], 'by' => []];
        }
        return $this->fight['debuffs'][$id];
    }

    private function &enemyBuff(string $id, string $name, string $on): array
    {
        if (!isset($this->fight['buffs'][$id])) {
            $this->fight['buffs'][$id] = ['name' => $name, 'on' => $on, 'applied' => 0, 'removed' => 0, 'by' => []];
        }
        return $this->fight['buffs'][$id];
    }

    /** Damage a group player took. Kept per player for death reports, and per ability while a fight runs. */
    private function hit(float $t, string $guid, string $key, string $spellId, string $spell, string $source, int $amount, int $overkill, bool $count): void
    {
        $recent = &$this->recent[$guid];
        $recent[] = [$t, $source, $spell, $amount, $overkill, $key];
        if (count($recent) > 6) {
            array_shift($recent);
        }
        unset($recent);

        if (!$count || $this->fight === null) {
            return;
        }
        if (!isset($this->fight['taken'][$key])) {
            $this->fight['taken'][$key] = ['id' => $spellId, 'name' => $spell, 'src' => $source, 'hits' => 0,
                'total' => 0, 'max' => 0, 'victims' => [], 'kills' => 0];
        }
        $row = &$this->fight['taken'][$key];
        $row['hits']++;
        $row['total'] += $amount;
        if ($amount > $row['max']) {
            $row['max'] = $amount;
        }
        $row['victims'][$guid] = 1;
        unset($row);
        $this->member($guid)['taken'] += $amount;
    }

    private function death(float $t, string $guid, string $name): void
    {
        $hits = [];
        foreach ($this->recent[$guid] ?? [] as $hit) {
            if ($t - $hit[0] <= CLOG_DEATH_WINDOW) {
                $hits[] = $hit;
            }
        }
        $last = $hits ? $hits[count($hits) - 1] : null;
        $death = [
            't' => $t, 'clock' => $this->clock, 'name' => $name,
            'feign' => isset($this->feignAt[$guid]) && $t - $this->feignAt[$guid] <= 2.0,
            'by' => $last ? ['src' => $last[1], 'spell' => $last[2], 'amount' => $last[3], 'key' => $last[5]] : null,
            'hits' => array_map(static fn(array $hit): array => [
                'ago' => round($t - $hit[0], 1), 'src' => $hit[1], 'spell' => $hit[2], 'amount' => $hit[3], 'overkill' => $hit[4],
            ], $hits),
        ];
        unset($this->recent[$guid]);
        $death['alive'] = false;

        if ($this->fight !== null) {
            $this->fight['deaths'][] = $death;
            $this->lastDeath[$guid] = ['fight' => count($this->fights), 'index' => count($this->fight['deaths']) - 1, 't' => $t];
        } else {
            $this->strayDeaths[] = $death;
            $this->lastDeath[$guid] = ['fight' => -1, 'index' => count($this->strayDeaths) - 1, 't' => $t];
        }
        // A killing blow with overkill is a real death, whatever comes after it.
        $overkill = $last !== null && $last[4] > 0 && $t - $last[0] <= 1.0;
        if (!$death['feign'] && !$overkill) {
            $drops = 0;
            foreach ($this->buffDrops[$guid] ?? [] as $at) {
                if ($t - $at <= 1.0) {
                    $drops++;
                }
            }
            $this->maybeAlive[$guid] = $this->lastDeath[$guid] + ['drops' => $drops];
        }
    }

    /** The buffs go in the same tick as a real death, logged just before or after its UNIT_DIED. */
    private function buffDropped(float $t, string $guid): void
    {
        $drops = [];
        foreach ($this->buffDrops[$guid] ?? [] as $at) {
            if ($t - $at <= 1.0) {
                $drops[] = $at;
            }
        }
        $drops[] = $t;
        $this->buffDrops[$guid] = $drops;
        if (isset($this->maybeAlive[$guid]) && $t - $this->maybeAlive[$guid]['t'] <= 1.0) {
            $this->maybeAlive[$guid]['drops']++;
        }
    }

    /** A hunter's Feign Death logs UNIT_DIED too; the aura line can come a moment after it. */
    private function markFeign(array $death): void
    {
        $this->markDeath($death, 'feign');
    }

    private function markDeath(array $death, string $flag): void
    {
        if ($death['fight'] === -1) {
            $this->strayDeaths[$death['index']][$flag] = true;
        } elseif ($death['fight'] === count($this->fights) && $this->fight !== null) {
            $this->fight['deaths'][$death['index']][$flag] = true;
        } elseif (isset($this->fights[$death['fight']]['died'][$death['index']])) {
            // The fight is already summed up: take the death back out of its counts.
            $fight = &$this->fights[$death['fight']];
            $died = &$fight['died'][$death['index']];
            if (!$died['feign'] && !$died['alive']) {
                $fight['deaths']--;
                foreach ($fight['taken'] as &$row) {
                    if ($died['by'] !== null && $row['kills'] > 0 && $row['name'] === $died['by']['spell'] && $row['src'] === $died['by']['src']) {
                        $row['kills']--;
                        break;
                    }
                }
                unset($row);
            }
            $died[$flag] = true;
            unset($died, $fight);
        }
    }

    /** A dropped fight's deaths become stray ones; point the references that wait on them there too. */
    private function moveDeathToStray(int $index): void
    {
        $from = count($this->fights);
        $to = count($this->strayDeaths) - 1;
        foreach (['lastDeath', 'maybeAlive'] as $list) {
            foreach ($this->{$list} as $guid => $ref) {
                if ($ref['fight'] === $from && $ref['index'] === $index) {
                    $this->{$list}[$guid] = ['fight' => -1, 'index' => $to] + $ref;
                }
            }
        }
    }

    /**
     * Some UNIT_DIED lines are for players who never died: on 2026-10-13 two hunters in BRD "died"
     * 12 times between them and fired again 0.1 to 6.5 s later, with no rez, no Feign Death and no
     * buff lost (11 more hunters in a BWL log; nobody else). A death counts as not one when its last
     * hit had no overkill, the player kept their buffs, and then swings, starts or finishes a cast,
     * or lands a shot fired after it, before any rez. Damage from a ground spell (Death and Decay)
     * goes on after a real death, and bots can be rezzed with no log line, so acting again is not
     * enough on its own.
     */
    private function checkAlive(float $t, string $event, array $f, string $src, string $dst): void
    {
        foreach ($this->maybeAlive as $guid => $death) {
            if ($t - $death['t'] > CLOG_ALIVE_WINDOW) {
                unset($this->maybeAlive[$guid]);
            }
        }

        if ($event === 'SPELL_RESURRECT' && isset($this->maybeAlive[$dst])) {
            unset($this->maybeAlive[$dst]);
            return;
        }
        if ($event === 'SPELL_AURA_APPLIED' && isset($this->maybeAlive[$dst], CLOG_SPIRIT_OF_REDEMPTION[(string)($f[7] ?? '')])) {
            unset($this->maybeAlive[$dst]);
            return;
        }

        $death = $this->maybeAlive[$src] ?? null;
        if ($death === null) {
            return;
        }
        $since = $t - $death['t'];
        switch ($event) {
            case 'SPELL_CAST_START':
            case 'SPELL_CAST_SUCCESS':
                if (isset(CLOG_SELF_REZ[trim((string)($f[8] ?? ''), '"')])) {
                    unset($this->maybeAlive[$src]);
                    return;
                }
                $acted = $since > 0.05;
                break;
            case 'SWING_DAMAGE':
            case 'SWING_MISSED':
                $acted = $since > 0.05;
                break;
            case 'RANGE_DAMAGE':
            case 'RANGE_MISSED':
                $acted = $since > CLOG_IN_FLIGHT;
                break;
            default:
                $acted = false;
        }
        if ($acted && $since <= 1.0) {
            // The buffs of a real death can still be on their way.
            return;
        }
        if ($acted && $death['drops'] >= CLOG_DEATH_BUFFS) {
            unset($this->maybeAlive[$src]);
            return;
        }
        if ($acted) {
            $this->markDeath($death, 'alive');
            unset($this->maybeAlive[$src]);
        }
    }

    private function closeFight(): void
    {
        $fight = $this->fight;
        $this->fight = null;
        if ($fight === null) {
            return;
        }
        $duration = max(1.0, $fight['end'] - $fight['start']);

        $enemies = [];
        $totalTaken = 0;
        foreach ($fight['enemies'] as $key => $enemy) {
            $totalTaken += $enemy['taken'];
            $enemies[] = ['entry' => is_int($key) ? $key : 0, 'name' => $enemy['name'], 'count' => count($enemy['units']),
                'taken' => $enemy['taken'], 'done' => $enemy['done'], 'died' => $enemy['died']];
        }
        usort($enemies, static fn(array $a, array $b): int => $b['taken'] <=> $a['taken']);
        // Nothing but a stray hit or a critter: not a fight.
        if (!$enemies || ($totalTaken === 0 && !$fight['deaths'])) {
            foreach ($fight['deaths'] as $index => $death) {
                $this->strayDeaths[] = $death;
                $this->moveDeathToStray($index);
            }
            return;
        }
        $main = $enemies[0];

        $buckets = (int)ceil($duration / CLOG_ACTIVITY_BUCKET);
        $players = [];
        foreach ($fight['players'] as $guid => $player) {
            $players[] = [
                'name' => $this->roster[$guid] ?? $guid,
                'damage' => $player['dmg'], 'healing' => $player['heal'], 'overheal' => $player['over'],
                'taken' => $player['taken'],
                'first' => $player['first'] === null ? null : round($player['first'], 1),
                'active' => min(100, (int)round(100 * count($player['buckets']) / max(1, $buckets))),
            ];
        }
        usort($players, static fn(array $a, array $b): int => ($b['damage'] + $b['healing']) <=> ($a['damage'] + $a['healing']));

        $deaths = 0;
        foreach ($fight['deaths'] as $death) {
            if ($death['feign'] || $death['alive']) {
                continue;
            }
            $deaths++;
            $key = $death['by']['key'] ?? null;
            if ($key !== null && isset($fight['taken'][$key])) {
                $fight['taken'][$key]['kills']++;
            }
        }

        $taken = [];
        foreach ($fight['taken'] as $row) {
            $row['victims'] = count($row['victims']);
            $taken[] = $row;
        }
        usort($taken, static fn(array $a, array $b): int => [$b['kills'], $b['total']] <=> [$a['kills'], $a['total']]);

        $casts = [];
        foreach ($fight['casts'] as $id => $cast) {
            if ($cast['start'] === 0) {
                continue;
            }
            arsort($cast['by']);
            $casts[] = ['id' => (string)$id] + $cast;
        }
        usort($casts, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);

        $debuffs = [];
        foreach ($fight['debuffs'] as $id => $debuff) {
            arsort($debuff['by']);
            $debuffs[] = ['id' => (string)$id, 'name' => $debuff['name'], 'src' => $debuff['src'], 'applied' => $debuff['applied'],
                'victims' => count($debuff['victims']), 'dispelled' => $debuff['dispelled'],
                'wait' => $debuff['waited'] ? round($debuff['wait'] / $debuff['waited'], 1) : null, 'by' => $debuff['by']];
        }
        usort($debuffs, static fn(array $a, array $b): int => $b['applied'] <=> $a['applied']);

        $buffs = [];
        foreach ($fight['buffs'] as $id => $buff) {
            arsort($buff['by']);
            $buffs[] = ['id' => (string)$id] + $buff;
        }
        usort($buffs, static fn(array $a, array $b): int => $b['applied'] <=> $a['applied']);

        $broken = [];
        foreach ($fight['broken'] as $key => $times) {
            [$aura, $by, $with] = explode("\t", (string)$key);
            $broken[] = ['aura' => $aura, 'by' => $by, 'with' => $with, 'times' => $times];
        }
        usort($broken, static fn(array $a, array $b): int => $b['times'] <=> $a['times']);

        arsort($fight['pets']);
        $this->fights[] = [
            'start' => (int)round($fight['start']), 'clock' => substr($fight['clock'], 0, 8), 'duration' => (int)round($duration),
            // clog_apply_bosses() fills these in once every fight is known.
            'name' => $main['name'], 'entry' => $main['entry'], 'boss' => false, 'outcome' => 'left',
            'deaths' => $deaths, 'rezzes' => $fight['rezzes'],
            'enemies' => array_slice($enemies, 0, CLOG_TABLE_ROWS),
            'players' => $players,
            'pets' => array_slice($fight['pets'], 0, CLOG_TABLE_ROWS, true),
            'died' => array_map(static function (array $death): array {
                unset($death['t']);
                if ($death['by'] !== null) {
                    unset($death['by']['key']);
                }
                return $death;
            }, $fight['deaths']),
            'taken' => array_slice($taken, 0, CLOG_TABLE_ROWS),
            'casts' => array_slice($casts, 0, CLOG_TABLE_ROWS),
            'debuffs' => array_slice($debuffs, 0, CLOG_TABLE_ROWS),
            'buffs' => array_slice($buffs, 0, CLOG_TABLE_ROWS),
            'broken' => array_slice($broken, 0, CLOG_TABLE_ROWS),
        ];
    }

    public function finish(): array
    {
        $this->closeFight();

        // Runs: fights with no long break between them, e.g. one raid night or one dungeon.
        $runs = [];
        $previousEnd = null;
        foreach ($this->fights as $index => $fight) {
            if ($previousEnd === null || $fight['start'] - $previousEnd > CLOG_RUN_GAP) {
                $runs[] = ['start' => $fight['start'], 'clock' => $fight['clock'], 'end' => 0, 'fights' => []];
            }
            $run = &$runs[count($runs) - 1];
            $run['fights'][] = $index;
            $run['end'] = $fight['start'] + $fight['duration'];
            unset($run);
            $previousEnd = $fight['start'] + $fight['duration'];
        }

        // What killed players, over the whole log.
        $killers = [];
        $deaths = 0;
        foreach ($this->fights as $fight) {
            foreach ($fight['died'] as $death) {
                if ($death['feign'] || $death['alive']) {
                    continue;
                }
                $deaths++;
                $key = $death['by'] === null ? 'Unknown' : $death['by']['spell'] . "\t" . $death['by']['src'];
                $killers[$key] = ($killers[$key] ?? 0) + 1;
            }
        }
        arsort($killers);
        $topKillers = [];
        foreach (array_slice($killers, 0, 25, true) as $key => $kills) {
            [$spell, $source] = array_pad(explode("\t", (string)$key), 2, '');
            $topKillers[] = ['spell' => $spell, 'src' => $source, 'kills' => $kills];
        }

        $stray = [];
        foreach ($this->strayDeaths as $death) {
            unset($death['t']);
            if ($death['by'] !== null) {
                unset($death['by']['key']);
            }
            $stray[] = $death;
        }

        $names = array_values($this->roster);
        sort($names);
        $digest = [
            'version' => CLOG_DIGEST_VERSION,
            'logger' => $this->logger,
            'lines' => $this->lines, 'skipped' => $this->skipped,
            'start' => $this->first === null ? null : (int)$this->first,
            'end' => $this->last === null ? null : (int)$this->last,
            'first_clock' => $this->firstClock, 'last_clock' => $this->lastClock,
            'players' => $names,
            'bots' => [],
            'deaths' => $deaths,
            'killers' => $topKillers,
            'stray_deaths' => $stray,
            'runs' => $runs,
            // 'guess' until the realm's database says which creatures are bosses ('realm').
            'bosses' => 'guess',
            'fights' => $this->fights,
        ];
        clog_apply_bosses($digest, clog_guess_bosses($this->fights));
        return $digest;
    }
}

/**
 * Which creatures look like bosses when nothing better is known: never more than one of them in a
 * fight, and far more damage soaked up than the log's usual creature.
 *
 * @return list<int> creature entries
 */
function clog_guess_bosses(array $fights): array
{
    $most = [];
    $perUnit = [];
    foreach ($fights as $fight) {
        foreach ($fight['enemies'] as $enemy) {
            if ($enemy['entry'] > 0 && $enemy['taken'] > 0) {
                $most[$enemy['entry']] = max($most[$enemy['entry']] ?? 0, $enemy['count']);
                $perUnit[] = $enemy['taken'] / $enemy['count'];
            }
        }
    }
    if (!$perUnit) {
        return [];
    }
    sort($perUnit);
    $usual = $perUnit[intdiv(count($perUnit), 2)];

    $bosses = [];
    foreach ($fights as $fight) {
        if ($fight['duration'] < 30) {
            continue;
        }
        foreach ($fight['enemies'] as $enemy) {
            if ($enemy['entry'] > 0 && ($most[$enemy['entry']] ?? 0) === 1 && $enemy['taken'] >= 5 * $usual) {
                $bosses[$enemy['entry']] = true;
            }
        }
    }
    return array_keys($bosses);
}

/**
 * Names and judges every fight for a set of boss entries: a fight with a boss in it is named after
 * the boss and is a kill when the boss died; any other fight is named after the creature that took
 * the most damage and is a kill when all of those died.
 */
function clog_apply_bosses(array &$digest, array $bossEntries): void
{
    $isBoss = array_fill_keys($bossEntries, true);
    foreach ($digest['fights'] as &$fight) {
        $target = $fight['enemies'][0];          // most damage taken first
        $fight['boss'] = false;
        foreach ($fight['enemies'] as $enemy) {
            if (isset($isBoss[$enemy['entry']])) {
                $target = $enemy;
                $fight['boss'] = true;
                break;
            }
        }
        $fight['name'] = $target['name'];
        $fight['entry'] = $target['entry'];
        $killed = $target['died'] > 0 && ($fight['boss'] || $target['died'] >= $target['count']);
        $fight['outcome'] = $killed ? 'kill'
            : ($fight['deaths'] >= max(3, (int)ceil(0.6 * count($fight['players']))) ? 'wipe' : 'left');
    }
    unset($fight);
}

/**
 * Digests one log file (gzip or plain). $loggedUntil and $utcOffsetMinutes come from the upload:
 * the log's last write on the player's PC and that PC's offset from UTC, which give the stamps a
 * year and a time zone.
 */
function clog_digest_file(string $path, int $loggedUntil, int $utcOffsetMinutes): array
{
    $handle = gzopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The log could not be opened.');
    }
    $digest = new ClogDigest($loggedUntil, $utcOffsetMinutes);
    $read = 0;
    try {
        while (($line = gzgets($handle, 65536)) !== false) {
            $read += strlen($line);
            if ($read > CLOG_MAX_RAW) {
                throw new RuntimeException('The log unpacks to more than ' . (CLOG_MAX_RAW >> 30) . ' GB.');
            }
            $digest->line($line);
        }
    } finally {
        gzclose($handle);
    }
    return $digest->finish();
}
