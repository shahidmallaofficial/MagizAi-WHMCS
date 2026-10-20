<?php

namespace MagizAI\Whmcs;

use WHMCS\Database\Capsule;

/**
 * Whether a WHMCS table or column exists, asked once per request.
 *
 * Every hasTable() and hasColumn() is a query against the database's own catalogue. They used to be
 * asked again for every product in a catalogue (up to 300 when MagizAI syncs the plan cards) and for
 * every upgrade target, although the answer cannot change while one request runs.
 *
 * A FAILURE TO ASK IS NOT AN ANSWER. It is neither remembered nor turned into "no": the exception
 * goes to the caller, exactly as Capsule's own call would. Several callers use the answer to decide
 * whether a privacy filter applies (private articles, admin-only fields), and "could not tell" must
 * never read as "there is no such column, so there is nothing to filter".
 *
 * Store::install() and Store::uninstall() forget every answer, because they change the tables.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Schema
{
    /** @var array<string, bool> */
    private static $tables = array();

    /** @var array<string, bool> */
    private static $columns = array();

    /** @return bool */
    public static function hasTable($table)
    {
        $key = strtolower((string) $table);

        if (!array_key_exists($key, self::$tables)) {
            self::$tables[$key] = (bool) Capsule::schema()->hasTable((string) $table);
        }

        return self::$tables[$key];
    }

    /** @return bool */
    public static function hasColumn($table, $column)
    {
        $key = strtolower((string) $table) . '.' . strtolower((string) $column);

        if (!array_key_exists($key, self::$columns)) {
            self::$columns[$key] = (bool) Capsule::schema()->hasColumn((string) $table, (string) $column);
        }

        return self::$columns[$key];
    }

    /** Forget every answer: after a table is created, altered or dropped, and between tests. */
    public static function forget()
    {
        self::$tables = array();
        self::$columns = array();
    }
}
