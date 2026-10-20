<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Support\Format;

/**
 * A domain's contact (WHOIS) details as the registrar holds them (1.10.0): read with WHMCS's
 * DomainGetWhoisInfo, changed field by field, and written back with DomainUpdateWhoisInfo.
 *
 * NOTHING IS GUESSED. Registrars name their fields differently ("First_Name" and "Last_Name", or one
 * "Name"; "Postcode" or "Zip"), so a change is written only into a field the registrar itself returned,
 * under the name it returned, and every other field and every other contact goes back exactly as read.
 * WHMCS documents the update's XML as "See DomainGetWhoisInfo": what was read is what is written.
 * A field this registrar does not keep is refused, never invented.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class WhoisContacts
{
    /** The contact kinds a client may name, and how WHMCS labels each. */
    const KINDS = array('registrant' => 'Registrant', 'admin' => 'Admin', 'tech' => 'Tech', 'billing' => 'Billing');

    /**
     * Each detail a client can change, and the field names registrars use for it, compared without
     * case, spaces or underscores. "name" is the single full-name field some registrars keep instead
     * of a first and a last name.
     */
    const FIELDS = array(
        'first_name' => array('firstname', 'first'),
        'last_name' => array('lastname', 'last', 'surname'),
        'name' => array('name', 'fullname', 'contactname'),
        'company' => array('organisationname', 'organizationname', 'organisation', 'organization', 'companyname', 'company'),
        'email' => array('email', 'emailaddress'),
        'phone' => array('phone', 'phonenumber', 'telephone', 'tel'),
        'address1' => array('address1', 'address', 'street', 'street1'),
        'address2' => array('address2', 'street2'),
        'city' => array('city', 'town'),
        'state' => array('state', 'region', 'stateprovince', 'province', 'county'),
        'postcode' => array('postcode', 'zip', 'zipcode', 'postalcode'),
        'country' => array('country', 'countrycode'),
    );

    /** How a detail is named to the client. */
    const WORDS = array(
        'first_name' => 'first name', 'last_name' => 'last name', 'name' => 'name', 'company' => 'organisation',
        'email' => 'email', 'phone' => 'phone', 'address1' => 'address', 'address2' => 'second address line',
        'city' => 'city', 'state' => 'state or region', 'postcode' => 'postcode', 'country' => 'country',
    );

    /**
     * The changes the client asked for, checked. Only what they named: an empty field is left alone.
     *
     * @return array<string, string> detail => new value
     * @throws ActionError
     */
    public static function requested(array $args)
    {
        $out = array();
        foreach (array_keys(self::WORDS) as $key) {
            if ($key === 'name' || !isset($args[$key]) || !is_scalar($args[$key])) {
                continue;
            }
            $value = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $args[$key])));
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > 100) {
                throw new ActionError('invalid_args', 'The new ' . self::WORDS[$key] . ' is too long. Ask for it again.');
            }
            if ($key === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new ActionError('invalid_args', '"' . Format::cut($value, 60) . '" is not an email address. Ask for it again.');
            }
            if ($key === 'phone' && !preg_match('/^\+?[0-9][0-9 .()-]{5,30}$/', $value)) {
                throw new ActionError('invalid_args', '"' . Format::cut($value, 40) . '" is not a phone number. Ask for it with the country code, for example +44 20 7946 0000.');
            }
            if ($key === 'country') {
                if (!preg_match('/^[A-Za-z]{2}$/', $value)) {
                    throw new ActionError('invalid_args', 'Ask for the country as a two-letter code, for example GB, US or IN.');
                }
                $value = strtoupper($value);
            }
            $out[$key] = $value;
        }

        if (!$out) {
            throw new ActionError('invalid_args', 'Ask which contact details they want to change, and the new value of each, exactly as it should be registered.');
        }

        return $out;
    }

    /**
     * Which contacts a change is for: the registrant unless the client named another, or all of them
     * (array('*'): every contact the registrar returns).
     *
     * @return string[] WHMCS's labels
     */
    public static function kinds(array $args)
    {
        $asked = isset($args['contact']) && is_scalar($args['contact']) ? strtolower(trim((string) $args['contact'])) : '';
        if ($asked === '' || $asked === 'owner') {
            return array('Registrant');
        }
        if ($asked === 'all') {
            // Every contact the registrar keeps, whichever those are. @see apply
            return array('*');
        }
        if (!isset(self::KINDS[$asked])) {
            throw new ActionError('invalid_args', 'Ask which contact to change: the registrant (owner), admin, tech, billing or all of them.');
        }

        return array(self::KINDS[$asked]);
    }

    /**
     * The contacts in a DomainGetWhoisInfo answer: kind => field => value, scalars only. WHMCS's own
     * example sends each contact as a JSON string, its parameter table as an array: both are read.
     *
     * @return array<string, array<string, string>>
     */
    public static function parse(array $r)
    {
        $out = array();
        foreach ($r as $kind => $fields) {
            if (!is_string($kind) || in_array(strtolower($kind), array('result', 'message'), true)) {
                continue;
            }
            if (is_string($fields)) {
                $decoded = json_decode($fields, true);
                $fields = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($fields)) {
                continue;
            }
            $clean = array();
            foreach ($fields as $name => $value) {
                if (is_string($name) && self::tagName($name) !== '' && (is_scalar($value) || $value === null)) {
                    $clean[$name] = $value === null ? '' : (string) $value;
                }
            }
            if ($clean) {
                $out[$kind] = $clean;
            }
        }

        return $out;
    }

    /**
     * $contacts with $changes made to each of $kinds, in the field the registrar named for each.
     *
     * @param array<string, array<string, string>> $contacts as parse() returns them
     * @param string[]                             $kinds
     * @param array<string, string>                $changes  as requested() returns them
     * @return array{contacts: array, changed: array<string, string[]>}
     * @throws ActionError
     */
    public static function apply(array $contacts, array $kinds, array $changes, $domain)
    {
        $changed = array();
        if ($kinds === array('*')) {
            $kinds = array_keys($contacts);
        }
        foreach ($kinds as $kind) {
            $label = self::find(array_keys($contacts), $kind);
            if ($label === null) {
                throw new ActionError('not_allowed', 'The registrar keeps no ' . strtolower($kind) . ' contact for ' . $domain . ', so it cannot be changed from the chat.');
            }
            $fields = $contacts[$label];
            $targets = self::targets($fields, $changes);
            if ($targets['missing'] === 'name_pair') {
                throw new ActionError('invalid_args', 'The registrar keeps one full name for the ' . strtolower($kind) . ' contact of ' . $domain . ': ask for the first and the last name together.');
            }
            if ($targets['missing'] !== null) {
                throw new ActionError('not_allowed', 'The registrar keeps no ' . $targets['missing'] . ' for the ' . strtolower($kind) . ' contact of ' . $domain
                    . ', so it cannot be changed from the chat. The client can change the contact details in the client area, under the domain.');
            }
            foreach ($targets['set'] as $field => $value) {
                $fields[$field] = $value;
            }
            $contacts[$label] = $fields;
            $changed[$label] = array_keys($changes);
        }

        return array('contacts' => $contacts, 'changed' => $changed);
    }

    /**
     * The registrar's field for each change. A single full-name field takes the first and last name
     * together. `missing` names the first detail this registrar keeps no field for, in the client's words.
     *
     * @return array{set: array<string, string>, missing: string|null}
     */
    private static function targets(array $fields, array $changes)
    {
        $names = array_keys($fields);
        $set = array();
        $first = isset($changes['first_name']) ? $changes['first_name'] : null;
        $last = isset($changes['last_name']) ? $changes['last_name'] : null;
        if (($first !== null || $last !== null) && self::field($names, 'first_name') === null && self::field($names, 'last_name') === null) {
            $full = self::field($names, 'name');
            if ($full === null) {
                return array('set' => array(), 'missing' => 'name');
            }
            if ($first === null || $last === null) {
                return array('set' => array(), 'missing' => 'name_pair');
            }
            $set[$full] = $first . ' ' . $last;
            unset($changes['first_name'], $changes['last_name']);
        }
        foreach ($changes as $key => $value) {
            $field = self::field($names, $key);
            if ($field === null) {
                return array('set' => array(), 'missing' => self::WORDS[$key]);
            }
            $set[$field] = $value;
        }

        return array('set' => $set, 'missing' => null);
    }

    /** The registrar's own name for one detail, or null when it keeps none. */
    private static function field(array $names, $key)
    {
        foreach ($names as $name) {
            if (in_array(self::squash($name), self::FIELDS[$key], true)) {
                return $name;
            }
        }

        return null;
    }

    /** The answer's own label for a contact kind ("Registrant", "registrant"), or null. */
    private static function find(array $labels, $kind)
    {
        foreach ($labels as $label) {
            if (strtolower($label) === strtolower($kind)) {
                return $label;
            }
        }

        return null;
    }

    private static function squash($name)
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $name));
    }

    /** An XML element name for a field: WHMCS's own underscores for spaces, nothing else changed. */
    private static function tagName($name)
    {
        $tag = str_replace(' ', '_', trim((string) $name));

        return preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,63}$/', $tag) ? $tag : '';
    }

    /** The XML DomainUpdateWhoisInfo takes: every contact, every field, as read, with the changes. */
    public static function xml(array $contacts)
    {
        $xml = '<contactdetails>';
        foreach ($contacts as $kind => $fields) {
            $kindTag = self::tagName($kind);
            if ($kindTag === '') {
                continue;
            }
            $xml .= '<' . $kindTag . '>';
            foreach ($fields as $name => $value) {
                $tag = self::tagName($name);
                if ($tag !== '') {
                    $xml .= '<' . $tag . '>' . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $tag . '>';
                }
            }
            $xml .= '</' . $kindTag . '>';
        }

        return $xml . '</contactdetails>';
    }

    /** The changes in words the client checks: "email new@example.com, phone +44 20 7946 0000". */
    public static function describe(array $changes)
    {
        $parts = array();
        foreach ($changes as $key => $value) {
            $parts[] = self::WORDS[$key] . ' ' . $value;
        }

        return implode(', ', $parts);
    }
}
