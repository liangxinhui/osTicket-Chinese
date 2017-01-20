<?php
/*********************************************************************
    class.thread_actions.php

    Actions for thread entries. This serves as a simple repository for
    drop-down actions which can be triggered on the ticket-view page for an
    object's thread.

    Jared Hancock <jared@osticket.com>
    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2014 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
include_once(INCLUDE_DIR.'class.thread.php');

class TEA_ShowEmailHeaders extends ThreadEntryAction {
    static $id = 'view_headers';
    static $name = /* trans */ 'View Email Headers';
    static $icon = 'envelope';

    function isVisible() {
        global $thisstaff;

        if (!$this->entry->getEmailHeader())
            return false;

        return $thisstaff && $thisstaff->isAdmin();
    }

    function getJsStub() {
        return sprintf("$.dialog('%s');",
            $this->getAjaxUrl()
        );
    }

    function trigger() {
        switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            return $this->trigger__get();
        }
    }

    private function trigger__get() {
        $headers = $this->entry->getEmailHeader();

        include STAFFINC_DIR . 'templates/thread-email-headers.tmpl.php';
    }
}
ThreadEntry::registerAction(/* trans */ 'E-Mail', 'TEA_ShowEmailHeaders');

class TEA_EditThreadEntry extends ThreadEntryAction {
    static $id = 'edit';
    static $name = /* trans */ 'Edit';
    static $icon = 'pencil';

    function isVisible() {
        // Can't edit system posts
        return ($this->entry->staff_id || $this->entry->user_id)
            && $this->entry->type != 'R' && $this->isEnabled();
    }

    function isEnabled() {
        global $thisstaff;

        $T = $this->entry->getThread()->getObject();
        // You can edit your own posts or posts by your department members
        // if your a manager, or everyone's if your an admin
        return $thisstaff && (
            $thisstaff->getId() == $this->entry->staff_id
            || ($T instanceof Ticket
                && $T->getDept()->getManagerId() == $thisstaff->getId()
            )
            || ($T instanceof Ticket
                && $thisstaff->getRole($T->getDeptId())->hasPerm(ThreadEntry::PERM_EDIT)
            )
        );
    }

    function getJsStub() {
        return sprintf(<<<JS
var url = '%s';
$.dialog(url, [201], function(xhr, resp) {
  var json = JSON.parse(resp);
  if (!json || !json.thread_id)
    return;
  $('#thread-entry-'+json.thread_id)
    .attr('id', 'thread-entry-' + json.new_id)
    .html(json.entry)
    .find('.thread-body')
    .delay(500)
    .effect('highlight');
}, {size:'large'});
JS
        , $this->getAjaxUrl());
    }


    function trigger() {
        switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            return $this->trigger__get();
        case 'POST':
            return $this->trigger__post();
        }
    }

    protected function trigger__get() {
        global $cfg, $thisstaff;

        $poster = $this->entry->getStaff();

        include STAFFINC_DIR . 'templates/thread-entry-edit.tmpl.php';
    }

    function updateEntry($guard=false) {
        global $thisstaff;

        $old = $this->entry;
        $new = ThreadEntryBody::fromFormattedText($_POST['body'], $old->format);

        if ($new->getClean() == $old->body)
            // No update was performed
            return $old;

        $entry = ThreadEntry::create(array(
            // Copy most information from the old entry
            'poster' => $old->poster,
            'userId' => $old->user_id,
            'staffId' => $old->staff_id,
            'type' => $old->type,
            'threadId' => $old->thread_id,

            // Connect the new entry to be a child of the previous
            'pid' => $old->id,

            // Add in new stuff
            'title' => Format::htmlchars($_POST['title']),
            'body' => $new,
            'ip_address' => $_SERVER['REMOTE_ADDR'],
        ));

        if (!$entry)
            return false;

        // Note, anything that points to the $old entry as PID should remain
        // that way for email header lookups and such to remain consistent

        if ($old->flags & ThreadEntry::FLAG_EDITED
            // If editing another person's edit, make a new entry
            and ($old->editor == $thisstaff->getId() && $old->editor_type == 'S')
            and !($old->flags & ThreadEntry::FLAG_GUARDED)
        ) {
            // Replace previous edit --------------------------
            $original = $old->getParent();
            // Link the new entry to the old id
            $entry->pid = $old->pid;
            // Drop the previous edit, and base this edit off the original
            $old->delete();
            $old = $original;
        }

        // Move the attachments to the new entry
        $old->attachments->update(array(
            'object_id' => $entry->id
        ));

        // Mark the new entry as edited (but not hidden nor guarded)
        $entry->flags = ($old->flags & ~(ThreadEntry::FLAG_HIDDEN | ThreadEntry::FLAG_GUARDED))
            | ThreadEntry::FLAG_EDITED;

        // Guard against deletes on future edit if requested. This is done
        // if an email was triggered by the last edit. In such a case, it
        // should not be replaced by a subsequent edit.
        if ($guard)
            $entry->flags |= ThreadEntry::FLAG_GUARDED;

        // Log the editor
        $entry->editor = $thisstaff->getId();
        $entry->editor_type = 'S';

        // Sort in the same place in the thread
        $entry->created = $old->created;
        $entry->updated = SqlFunction::NOW();
        $entry->save(true);

        // Hide the old entry from the object thread
        $old->flags |= ThreadEntry::FLAG_HIDDEN;
        $old->save();

        return $entry;
    }

    protected function trigger__post() {
        global $thisstaff;

        if (!($entry = $this->updateEntry()))
            return $this->trigger__get();

        ob_start();
        include STAFFINC_DIR . 'templates/thread-entry.tmpl.php';
        $content = ob_get_clean();

        Http::response('201', JsonDataEncoder::encode(array(
            'thread_id' => $this->entry->id, # This is the old id!
            'new_id' => $entry->id,
            'entry' => $content,
        )));
    }
}
ThreadEntry::registerAction(/* trans */ 'Manage', 'TEA_EditThreadEntry');

class TEA_OrigThreadEntry extends ThreadEntryAction {
    static $id = 'previous';
    static $name = /* trans */ 'View History';
    static $icon = 'copy';

    function isVisible() {
        // Can't edit system posts
        return $this->entry->flags & ThreadEntry::FLAG_EDITED;
    }

    function getJsStub() {
        return sprintf("$.dialog('%s');",
            $this->getAjaxUrl()
        );
    }

    function trigger() {
        switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            return $this->trigger__get();
        }
    }

    private function trigger__get() {
        global $thisstaff;

        if (!$this->entry->getParent())
            Http::response(404, 'No history for this entry');
        $entry = $this->entry;
        include STAFFINC_DIR . 'templates/thread-entry-view.tmpl.php';
    }
}
ThreadEntry::registerAction(/* trans */ 'Manage', 'TEA_OrigThreadEntry');

class TEA_EditAndResendThreadEntry extends TEA_EditThreadEntry {
    static $id = 'edit_resend';
    static $name = /* trans */ 'Edit and Resend';
    static $icon = 'reply-all';

    function isVisible() {
        // Can only resend replies
        return $this->entry->staff_id && $this->entry->type == 'R'
            && $this->isEnabled();
    }

    protected function trigger__post() {
        $resend = @$_POST['commit'] == 'resend';

        if (!($entry = $this->updateEntry($resend)))
            return $this->trigger__get();

        if ($resend)
            $this->resend($entry);

        ob_start();
        include STAFFINC_DIR . 'templates/thread-entry.tmpl.php';
        $content = ob_get_clean();

        Http::response('201', JsonDataEncoder::encode(array(
            'thread_id' => $this->entry->id, # This is the old id!
            'new_id' => $entry->id,
            'entry' => $content,
        )));
    }

    function resend($response) {
        global $cfg, $thisstaff;

        if (!($object = $response->getThread()->getObject()))
            return false;

        $vars = $_POST;
        $dept = $object->getDept();
        $poster = $response->getStaff();

        if ($thisstaff && $vars['signature'] == 'mine')
            $signature = $thisstaff->getSignature();
        elseif ($poster && $vars['signature'] == 'theirs')
            $signature = $poster->getSignature();
        elseif ($vars['signature'] == 'dept' && $dept && $dept->isPublic())
            $signature = $dept->getSignature();
        else
            $signature = '';

        $variables = array(
            'response' => $response,
            'signature' => $signature,
            'staff' => $response->getStaff(),
            'poster' => $response->getStaff());
        $options = array('thread' => $response);

        // Resend response to collabs
        if (($object instanceof Ticket)
                && ($email=$dept->getEmail())
                && ($tpl = $dept->getTemplate())
                && ($msg=$tpl->getReplyMsgTemplate())) {

            $msg = $object->replaceVars($msg->asArray(),
                $variables + array('recipient' => $object->getOwner()));

            $attachments = $cfg->emailAttachments()
                ? $response->getAttachments() : array();
            $email->send($object->getOwner(), $msg['subj'], $msg['body'],
                $attachments, $options);
        }
        // TODO: Add an option to the dialog
        $object->notifyCollaborators($response, array('signature' => $signature));

        // Log an event that the item was resent
        $object->logEvent('resent', array('entry' => $response->id));

        // Flag the entry as resent
        $response->flags |= ThreadEntry::FLAG_RESENT;
        $response->save();
    }
}
ThreadEntry::registerAction(/* trans */ 'Manage', 'TEA_EditAndResendThreadEntry');

class TEA_ResendThreadEntry extends TEA_EditAndResendThreadEntry {
    static $id = 'resend';
    static $name = /* trans */ 'Resend';
    static $icon = 'reply-all';

    function isVisible() {
        // Can only resend replies
        return $this->entry->staff_id && $this->entry->type == 'R'
            && !parent::isEnabled();
    }
    function isEnabled() {
        return true;
    }

    protected function trigger__get() {
        global $cfg, $thisstaff;

        $poster = $this->entry->getStaff();

        include STAFFINC_DIR . 'templates/thread-entry-resend.tmpl.php';
    }

    protected function trigger__post() {
        $resend = @$_POST['commit'] == 'resend';

        if (@$_POST['commit'] == 'resend')
            $this->resend($this->entry);

        Http::response('201', 'Okee dokey');
    }
}
ThreadEntry::registerAction(/* trans */ 'Manage', 'TEA_ResendThreadEntry');
