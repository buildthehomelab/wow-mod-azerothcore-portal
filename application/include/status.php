<?php
/**
 * @author Amin Mahmoudi (MasterkinG)
 * @copyright    Copyright (c) 2019 - 2024, MasterkinG32. (https://masterking32.com)
 * @link    https://masterking32.com
 **/

class status
{
    /** Where the characters table has `home_viewer`: a home screen viewer is a row nobody plays. */
    private static function has_home_viewers($realmID)
    {
        static $has = [];
        if (!isset($has[$realmID])) {
            try {
                database::$chars[$realmID]->executeQuery('SELECT `home_viewer` FROM characters LIMIT 0');
                $has[$realmID] = true;
            } catch (\Doctrine\DBAL\Exception\InvalidFieldNameException $e) {
                $has[$realmID] = false;
            }
        }
        return $has[$realmID];
    }

    /** Characters that are played, for a leaderboard reading the characters table. */
    private static function top_characters($realmID)
    {
        $queryBuilder = database::$chars[$realmID]->createQueryBuilder();
        $queryBuilder->from("characters");
        if (self::has_home_viewers($realmID)) {
            $queryBuilder->where("home_viewer = 0");
        }
        return $queryBuilder;
    }

    public static function get_character_by_guid($realmID, $guid)
    {
        if (!empty($guid)) {
            $queryBuilder = database::$chars[$realmID]->createQueryBuilder();
            $queryBuilder->select("name, race, class, gender, level")
                ->from("characters")
                ->where("guid = :guid")
                ->setParameter("guid", $guid);

            $statement = $queryBuilder->executeQuery();
            $datas = $statement->fetchAllAssociative();

            if (!empty($datas[0]["level"])) {
                return $datas[0];
            }
        }
        return false;
    }

    public static function get_top_achievements($realmID)
    {
        $queryBuilder = database::$chars[$realmID]->createQueryBuilder();
        $queryBuilder->select("guid, COUNT(*) as total")
            ->from("character_achievement");
        if (self::has_home_viewers($realmID)) {
            $queryBuilder->where("guid NOT IN (SELECT guid FROM characters WHERE home_viewer <> 0)");
        }
        $queryBuilder->groupBy("guid")
            ->orderBy("total", "DESC")
            ->setMaxResults(10);

        $statement = $queryBuilder->executeQuery();
        $datas = $statement->fetchAllAssociative();

        if (!empty($datas[0]["guid"])) {
            return $datas;
        }
        return false;
    }

    public static function get_top_arenateams($realmID)
    {
        $queryBuilder = database::$chars[$realmID]->createQueryBuilder();
        $queryBuilder->select("arenaTeamId, name, captainGuid, rating")
            ->from("arena_team")
            ->orderBy("rating", "DESC")
            ->setMaxResults(10);

        $statement = $queryBuilder->executeQuery();
        $datas = $statement->fetchAllAssociative();

        if (!empty($datas[0]["arenaTeamId"])) {
            return $datas;
        }
        return false;
    }

    public static function get_top_killers($realmID)
    {
        $queryBuilder = self::top_characters($realmID);
        $queryBuilder->select("name, race, class, gender, level, totalKills")
            ->orderBy("totalKills", "DESC")
            ->setMaxResults(10);
        $statement = $queryBuilder->executeQuery();
        $datas = $statement->fetchAllAssociative();

        if (!empty($datas[0]["totalKills"])) {
            return $datas;
        }
        return false;
    }

    public static function get_top_arenapoints($realmID)
    {
        $queryBuilder = self::top_characters($realmID);
        $queryBuilder->select("name, race, class, gender, level, arenaPoints")
            ->orderBy("arenaPoints", "DESC")
            ->setMaxResults(10);
        $statement = $queryBuilder->executeQuery();
        $datas = $statement->fetchAllAssociative();

        if (!empty($datas[0]["arenaPoints"])) {
            return $datas;
        }
        return false;
    }

    public static function get_top_honorpoints($realmID)
    {
        $queryBuilder = self::top_characters($realmID);

        if (get_config('expansion') >= 6) {
            $queryBuilder->select("name, race, class, gender, level, honorLevel, honor")
                ->orderBy("honorLevel", "DESC")
                ->addOrderBy("honor", "DESC")
                ->setMaxResults(10);
        } else {
            $queryBuilder->select("name, race, class, gender, level, totalHonorPoints")
                ->orderBy("totalHonorPoints", "DESC")
                ->setMaxResults(10);
        }

        $statement = $queryBuilder->executeQuery();
        $datas = $statement->fetchAllAssociative();

        if (!empty($datas[0]["level"])) {
            return $datas;
        }
        return false;
    }

    public static function get_top_playtime($realmID)
    {
        $queryBuilder = self::top_characters($realmID);
        $queryBuilder->select("name, race, class, gender, level, totaltime")
            ->orderBy("totaltime", "DESC")
            ->setMaxResults(10);

        $statement = $queryBuilder->executeQuery();
        $datas = $statement->fetchAllAssociative();

        if (!empty($datas[0]["totaltime"])) {
            return $datas;
        }
        return false;
    }

    public static function get_top_gold($realmID)
    {
        $queryBuilder = self::top_characters($realmID);
        $queryBuilder->select("name, race, level, totaltime, money")
            ->orderBy("money", "DESC")
            ->setMaxResults(10);

        $statement = $queryBuilder->executeQuery();
        $datas = $statement->fetchAllAssociative();

        if (!empty($datas[0]["money"])) {
            return $datas;
        }
        return false;
    }
}
