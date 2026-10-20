<?php

namespace MagizAI\Whmcs\Install;

use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Settings;
use WHMCS\Database\Capsule;

/**
 * The "MagizAI verification code" email template (1.7.0): the email that carries the 6-digit code a
 * client types in the chat to confirm a change or a login reveal. @see \MagizAI\Whmcs\Action\Account\VerificationSend
 *
 * A WHMCS email template of type "general", so WHMCS sends it to the address on the account with the
 * merchant's own header, footer, signature and mail settings, and the merchant can change the wording
 * under System Settings, Email Templates, General Messages. Shipped in English (the default every
 * other language falls back to), French and Arabic.
 *
 * NEVER OVERWRITTEN ONCE EDITED. Created when it is missing (activation, every upgrade, the daily cron,
 * and a send that finds none). What the module wrote is remembered as a fingerprint per language: a
 * row that still matches it is the module's own text and is brought up to date when a release changes
 * that text; a row that does not match was edited by the merchant (or made by someone else) and is
 * left exactly as it is. Deactivating leaves the template in place, edits included.
 *
 * The SUBJECT never carries the code: the email history action reads subjects back into the chat.
 * A merchant who adds {$magizai_code} to the subject anyway is handled there. @see subjectCarriesCode
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class EmailTemplate
{
    /** The template's name, as SendEmail's `messagename` finds it. */
    const NAME = 'MagizAI verification code';

    const TABLE = 'tblemailtemplates';

    /** Setting: the fingerprint of the text the module last wrote, per WHMCS language ('' = default). */
    const SHIPPED_SETTING = 'verification_template_shipped';

    /** The merge fields the module passes with every send (SendEmail customvars). */
    const MERGE_FIELDS = array('magizai_code', 'magizai_expires_minutes', 'magizai_action', 'magizai_purpose', 'magizai_locale');

    /** Remembered for one request: whether a subject of this template names the code. */
    private static $subjectCarriesCode = null;

    /**
     * The shipped text, by WHMCS language name. '' is the default row WHMCS uses for every client
     * whose language has no row of its own.
     *
     * @return array<string, array{subject: string, message: string}>
     */
    public static function shipped()
    {
        $code = '<p style="font-size: 28px; font-weight: bold; letter-spacing: 6px; direction: ltr;">{$magizai_code}</p>';

        return array(
            '' => array(
                'subject' => 'Your verification code',
                'message' => '<p>Dear {$client_first_name},</p>'
                    . '<p>You asked for this in our live chat:</p>'
                    . '<p><strong>{$magizai_action}</strong></p>'
                    . '<p>To confirm it is you, type this code in the chat:</p>'
                    . $code
                    . '<p>The code expires in {$magizai_expires_minutes} minutes and works once. Nobody from our team will ask you for it.</p>'
                    . '<p>If you did not ask for this, you can ignore this email. Nothing changes without the code.</p>'
                    . '<p>{$signature}</p>',
            ),
            'french' => array(
                'subject' => 'Votre code de vérification',
                'message' => '<p>Bonjour {$client_first_name},</p>'
                    . '<p>Vous avez fait cette demande dans notre chat en direct :</p>'
                    . '<p><strong>{$magizai_action}</strong></p>'
                    . '<p>Pour confirmer qu\'il s\'agit bien de vous, saisissez ce code dans le chat :</p>'
                    . $code
                    . '<p>Ce code expire dans {$magizai_expires_minutes} minutes et ne fonctionne qu\'une seule fois. Notre équipe ne vous le demandera jamais.</p>'
                    . '<p>Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail. Rien ne change sans le code.</p>'
                    . '<p>{$signature}</p>',
            ),
            'arabic' => array(
                'subject' => 'رمز التحقق الخاص بك',
                'message' => '<div dir="rtl">'
                    . '<p>مرحبًا {$client_first_name}،</p>'
                    . '<p>طلبت ما يلي في الدردشة المباشرة:</p>'
                    . '<p><strong>{$magizai_action}</strong></p>'
                    . '<p>للتأكد من أنك صاحب الطلب، اكتب هذا الرمز في الدردشة:</p>'
                    . $code
                    . '<p>تنتهي صلاحية الرمز خلال {$magizai_expires_minutes} دقيقة ويمكن استخدامه مرة واحدة فقط. لن يطلبه منك أي شخص من فريقنا.</p>'
                    . '<p>إذا لم تطلب ذلك، تجاهل هذه الرسالة. لن يتغير شيء من دون الرمز.</p>'
                    . '<p>{$signature}</p>'
                    . '</div>',
            ),
        );
    }

    /**
     * Create every missing language row, and bring the module's own unedited rows up to date.
     * Idempotent. Throws when the database cannot be read or written: callers decide whether that
     * matters (a send refuses; activation, the upgrade step and the cron carry on).
     *
     * @return array{created: string[], updated: string[], kept: string[]} languages, '' for the default
     */
    public static function install()
    {
        $out = array('created' => array(), 'updated' => array(), 'kept' => array());
        if (!Schema::hasTable(self::TABLE)) {
            return $out;
        }

        $recorded = json_decode((string) Settings::get(self::SHIPPED_SETTING, ''), true);
        $recorded = is_array($recorded) ? $recorded : array();
        $before = $recorded;
        $now = date('Y-m-d H:i:s');

        foreach (self::shipped() as $language => $text) {
            $shipped = self::fingerprint($text['subject'], $text['message']);
            $row = self::row($language);

            if (!$row) {
                Capsule::table(self::TABLE)->insert(self::columns(array(
                    'type' => 'general', 'name' => self::NAME, 'subject' => $text['subject'], 'message' => $text['message'],
                    'attachments' => '', 'fromname' => '', 'fromemail' => '', 'disabled' => 0, 'custom' => 1,
                    'language' => $language, 'copyto' => '', 'blind_copy_to' => '', 'plaintext' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                )));
                $recorded[$language] = $shipped;
                $out['created'][] = $language;
                continue;
            }

            $current = self::fingerprint((string) $row->subject, isset($row->message) ? (string) $row->message : '');
            if ($current === $shipped) {
                $recorded[$language] = $shipped;
                continue;
            }

            if (isset($recorded[$language]) && $recorded[$language] === $current) {
                // The module's own earlier text, never edited: this release's text replaces it.
                Capsule::table(self::TABLE)->where('id', (int) $row->id)->update(self::columns(array(
                    'subject' => $text['subject'], 'message' => $text['message'], 'updated_at' => $now,
                )));
                $recorded[$language] = $shipped;
                $out['updated'][] = $language;
                continue;
            }

            // Edited by the merchant, or not the module's at all: left exactly as it is.
            $out['kept'][] = $language;
        }

        if ($recorded !== $before) {
            Settings::set(self::SHIPPED_SETTING, json_encode($recorded));
        }
        self::$subjectCarriesCode = null;

        return $out;
    }

    /**
     * The default row's state: ok, missing, disabled (the merchant ticked "Disabled" in Email
     * Templates) or unreadable. Never throws.
     */
    public static function status()
    {
        try {
            if (!Schema::hasTable(self::TABLE)) {
                return 'missing';
            }
            $row = self::row('');
        } catch (\Exception $e) {
            return 'unreadable';
        }

        if (!$row) {
            return 'missing';
        }

        return isset($row->disabled) && (int) $row->disabled === 1 ? 'disabled' : 'ok';
    }

    /**
     * Does a subject of this template (any language) name the code? Then the code is in the subject
     * of every such email, and the email history must not read it back into the chat. A template
     * that cannot be read counts as yes: masking a number by mistake costs nothing.
     */
    public static function subjectCarriesCode()
    {
        if (self::$subjectCarriesCode === null) {
            try {
                self::$subjectCarriesCode = Schema::hasTable(self::TABLE) && Capsule::table(self::TABLE)
                    ->where('name', self::NAME)->where('subject', 'like', '%magizai_code%')->exists();
            } catch (\Exception $e) {
                return true;
            }
        }

        return self::$subjectCarriesCode;
    }

    /** Forget what was remembered for the request that ran last. */
    public static function forget()
    {
        self::$subjectCarriesCode = null;
    }

    /** The template's row in one language ('' = the default), or null. */
    private static function row($language)
    {
        $query = Capsule::table(self::TABLE)->where('name', self::NAME);
        if ($language === '') {
            $query->where(function ($q) {
                $q->where('language', '')->orWhereNull('language');
            });
        } else {
            $query->where('language', (string) $language);
        }

        return $query->orderBy('id')->first();
    }

    /**
     * Only the columns this WHMCS has. Every text column of tblemailtemplates is NOT NULL with no
     * default on a real install, so each one it has is filled, never left out.
     */
    private static function columns(array $row)
    {
        $out = array();
        foreach ($row as $column => $value) {
            if (in_array($column, array('type', 'name', 'subject'), true) || Schema::hasColumn(self::TABLE, $column)) {
                $out[$column] = $value;
            }
        }

        return $out;
    }

    private static function fingerprint($subject, $message)
    {
        return sha1(trim((string) $subject) . "\n" . trim((string) $message));
    }
}
