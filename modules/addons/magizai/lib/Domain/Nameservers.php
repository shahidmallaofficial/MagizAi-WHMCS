<?php

namespace MagizAI\Whmcs\Domain;

use WHMCS\Database\Capsule;

/**
 * Nameservers: what the registrar says a domain uses, the host's defaults, and a server's own.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Nameservers
{
    public static function nsList(array $r)
    {
        $out = array();
        foreach (array('ns1', 'ns2', 'ns3', 'ns4', 'ns5') as $k) {
            if (!empty($r[$k])) {
                $out[] = strtolower(trim($r[$k]));
            }
        }

        return $out;
    }

    public static function defaultNameservers()
    {
        $out = array();
        for ($i = 1; $i <= 5; $i++) {
            $ns = trim((string) \WHMCS\Config\Setting::getValue('DefaultNameserver' . $i));
            if ($ns !== '') {
                $out[] = strtolower($ns);
            }
        }

        return $out;
    }

    public static function serverNameservers($serverId)
    {
        if ($serverId <= 0) {
            return array();
        }
        $s = Capsule::table('tblservers')->where('id', $serverId)->first();
        if (!$s) {
            return array();
        }
        $out = array();
        for ($i = 1; $i <= 5; $i++) {
            $col = 'nameserver' . $i;
            if (!empty($s->$col)) {
                $out[] = strtolower(trim($s->$col));
            }
        }

        return $out;
    }
}
