<?php

namespace MagizAI\Whmcs;

use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Every email the module sends to a client goes through here, and the answer is believed.
 *
 * SendEmail fails more often than it looks: an invalid related id, a hook that aborts the send, a
 * template Smarty could not compile, a mail server that refused it. Every one of those came back as
 * result=error and was ignored, so the chat said "the password has been emailed to s***@…" for an
 * email that never left, and a password reset reported success after changing a live server
 * password nobody was then told.
 *
 * Callers build the body from Text::mail()-escaped values. This class sends it, checks WHMCS's
 * answer, and keeps any secret it was given out of WHMCS's module log, in every escaped form the
 * body carries it in.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Mailer
{
    /**
     * Send one custom email and report whether WHMCS says it went.
     *
     * @param string   $type    'general' (relId = client id) or 'domain' (relId = domain id)
     * @param int      $relId
     * @param string   $subject plain text; made safe here
     * @param string   $html    the body, built from Text::mail()-escaped values
     * @param string[] $secrets values that must never reach WHMCS's module log
     * @return bool
     */
    public static function send($type, $relId, $subject, $html, array $secrets = array())
    {
        $params = array(
            'customtype' => (string) $type,
            'id' => (int) $relId,
            'customsubject' => Text::subject($subject),
            'custommessage' => (string) $html,
        );

        try {
            $response = WhmcsApi::api('SendEmail', $params, false, 'internal', $secrets);
        } catch (\Throwable $e) {
            $response = array('result' => 'error', 'message' => $e->getMessage());
        }

        $sent = is_array($response) && isset($response['result']) && $response['result'] === 'success';

        if (!$sent && function_exists('logModuleCall')) {
            /*
             * MASKED HERE, AND LISTED FOR WHMCS TO MASK AS WELL. WHMCS swaps each listed string for
             * asterisks by EXACT value, and the body carries each secret ESCAPED (Text::mail): a
             * password such as Pa&ss{1} sat in the log as "Pa&amp;ss&#123;1&#125;", which no raw
             * value matches. Every form is masked before WHMCS sees the request, so the log is safe
             * whatever WHMCS's own masking does.
             */
            logModuleCall('magizai', 'SendEmail', self::forTheLog($params, $secrets), Text::maskDeep($response, $secrets), '', Text::secretForms($secrets));
        }

        return $sent;
    }

    /**
     * The request as staff may read it in WHMCS's module log: every form of every secret masked. A
     * body carrying a secret too short to mask safely is left out altogether.
     *
     * @param array<string, mixed> $params
     * @param string[]             $secrets
     * @return array<string, mixed>
     */
    private static function forTheLog(array $params, array $secrets)
    {
        if (Text::hasUnmaskable($secrets)) {
            $params['custommessage'] = '[not logged: it carries a value too short to mask]';
        }

        return Text::maskDeep($params, $secrets);
    }
}
