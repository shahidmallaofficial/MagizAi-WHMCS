<?php

namespace MagizAI\Whmcs\Hooks;

use MagizAI\Whmcs\Identity\Session;
use MagizAI\Whmcs\Staff\AssistGrant;
use MagizAI\Whmcs\Staff\TicketAssist;
use MagizAI\Whmcs\Text;

/**
 * The MagizAI panel on the admin ticket page (1.10.0), from WHMCS's AdminAreaViewTicketPage hook
 * (developers.whmcs.com, hooks reference, Ticket: "Runs when an admin views a ticket", given the
 * ticketid, and "Returned HTML will be output to the page").
 *
 * Draft a reply, Improve (shorter, friendlier, more formal, spelling), Translate (into the client's
 * language or one picked) and Summarise. A result is shown under the buttons; Apply puts it in the
 * reply box and Undo puts back what was there. The panel never presses Send: the staff member does.
 * Text from MagizAI is only ever set as text (textContent, a textarea's value), never as HTML.
 *
 * Shown only while "MagizAI on the ticket page" is on (Setup tab), the module is connected, and an
 * admin is signed in. Nothing is sent anywhere until a button is pressed.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketAssistHook
{
    const LANGUAGES = array(
        'en' => 'English', 'ar' => 'Arabic', 'fr' => 'French', 'es' => 'Spanish', 'de' => 'German', 'pt' => 'Portuguese',
        'it' => 'Italian', 'hi' => 'Hindi', 'ur' => 'Urdu', 'tr' => 'Turkish', 'nl' => 'Dutch', 'bn' => 'Bengali',
    );

    /** @return string the panel, or '' when it is not shown */
    public static function render(array $vars)
    {
        try {
            $ticketId = isset($vars['ticketid']) && is_numeric($vars['ticketid']) ? (int) $vars['ticketid'] : 0;
            if ($ticketId < 1 || !TicketAssist::enabled()) {
                return '';
            }
            $session = Session::current();
            $grant = AssistGrant::mint((int) $session['admin_id'], $ticketId);
            if ($grant === '') {
                return '';
            }
            $token = function_exists('generate_token') ? (string) generate_token('plain') : '';

            return self::html($grant, $token);
        } catch (\Throwable $e) {
            // The ticket page must always open.
            return '';
        }
    }

    /** The panel and its script. Everything that varies is escaped or JSON-encoded. */
    public static function html($grant, $token)
    {
        $e = function ($text) {
            return Text::html($text);
        };
        $languages = '';
        foreach (self::LANGUAGES as $code => $name) {
            $languages .= '<option value="' . $e($code) . '">' . $e($name) . '</option>';
        }
        $config = json_encode(array(
            // The admin folder sits in WHMCS's root, whatever it is called: one level up is index.php.
            'url' => '../index.php?m=magizai&action=ticket_assist',
            'grant' => (string) $grant,
            'token' => (string) $token,
        ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        return '<div id="magizai-assist" style="border:1px solid #d8dde3;border-radius:6px;padding:10px 12px;margin:0 0 15px;background:#f8fafb">'
            . '<strong style="margin-right:8px">MagizAI</strong>'
            . '<button type="button" class="btn btn-default btn-sm" data-task="draft">Draft a reply</button> '
            . '<select class="form-control input-sm" data-role="style" style="display:inline-block;width:auto">'
            . '<option value="">Improve my text</option><option value="shorter">Shorter</option><option value="friendly">Friendlier</option>'
            . '<option value="formal">More formal</option><option value="fix">Fix spelling and grammar</option></select> '
            . '<select class="form-control input-sm" data-role="language" style="display:inline-block;width:auto">'
            . '<option value="">Translate my text</option><option value="client">Into the client\'s language</option>' . $languages . '</select> '
            . '<button type="button" class="btn btn-default btn-sm" data-task="summary">Summarise</button>'
            . '<div data-role="status" aria-live="polite" style="margin-top:8px"></div>'
            . '<div data-role="result" style="display:none;margin-top:8px">'
            . '<div data-role="text" style="white-space:pre-wrap;background:#fff;border:1px solid #e3e7eb;border-radius:4px;padding:8px;max-height:320px;overflow:auto"></div>'
            . '<div style="margin-top:6px"><button type="button" class="btn btn-primary btn-sm" data-role="apply">Apply</button> '
            . '<button type="button" class="btn btn-default btn-sm" data-role="undo" style="display:none">Undo</button> '
            . '<button type="button" class="btn btn-link btn-sm" data-role="close">Close</button></div></div>'
            . '<div class="text-muted" style="margin-top:6px;font-size:12px">Nothing goes to the client until you send the reply yourself. '
            . 'Check every draft before you send it.</div></div>'
            . '<script>(function(c){var p=document.getElementById("magizai-assist");if(!p)return;'
            . 'function q(s){return p.querySelector(s);}'
            . 'function box(){return document.getElementById("replymessage")||document.querySelector("form textarea[name=\'message\']");}'
            . 'var status=q("[data-role=status]"),result=q("[data-role=result]"),out=q("[data-role=text]"),apply=q("[data-role=apply]"),undo=q("[data-role=undo]"),before=null,last="";'
            . 'function say(t){status.textContent=t;}'
            . 'function show(t,task){last=t;out.textContent=t;result.style.display="block";apply.style.display=task==="summary"?"none":"inline-block";undo.style.display="none";}'
            . 'function ask(task,extra){var b=box();var draft=b?b.value:"";'
            . 'if((task==="rewrite"||task==="translate")&&draft.trim()===""){say("Write something in the reply box first.");return;}'
            . 'var f=new URLSearchParams();f.set("grant",c.grant);f.set("token",c.token);f.set("task",task);f.set("draft",draft);'
            . 'for(var k in extra){f.set(k,extra[k]);}say("Working...");'
            . 'fetch(c.url,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:f.toString()})'
            . '.then(function(r){return r.json();}).then(function(j){if(j&&j.ok){say("");show(j.text,task);}else{say(j&&j.message?j.message:"MagizAI could not answer just now.");}})'
            . '.catch(function(){say("MagizAI could not be reached just now.");});}'
            . 'p.addEventListener("click",function(ev){var t=ev.target;if(!t||!t.getAttribute)return;var task=t.getAttribute("data-task");if(task){ask(task,{});return;}'
            . 'var role=t.getAttribute("data-role");var b=box();'
            . 'if(role==="apply"&&b){before=b.value;b.value=last;b.dispatchEvent(new Event("input",{bubbles:true}));b.dispatchEvent(new Event("change",{bubbles:true}));undo.style.display="inline-block";b.focus();}'
            . 'if(role==="undo"&&b&&before!==null){b.value=before;before=null;b.dispatchEvent(new Event("input",{bubbles:true}));undo.style.display="none";}'
            . 'if(role==="close"){result.style.display="none";}});'
            . 'p.addEventListener("change",function(ev){var t=ev.target,role=t&&t.getAttribute?t.getAttribute("data-role"):null;if(!t.value)return;'
            . 'if(role==="style")ask("rewrite",{style:t.value});if(role==="language")ask("translate",{language:t.value});t.value="";});'
            . '})(' . $config . ');</script>';
    }
}
