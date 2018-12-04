<?php
/*-------------------------------------------------------+
| SYSTOPIA Mailingtools Extension                        |
| Copyright (C) 2018 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/


use CRM_Mailingtools_ExtensionUtil as E;

/**
 * Processor for anonymous open events
 */
class CRM_Mailingtools_AnonymousOpen {

  /**
   * Process an anonymous open event
   *
   * @param $mid int mailing ID
   * @return int|null OpenEvent ID or NULL disabled
   * @throws Exception if something failed.
   */
  public static function processAnonymousOpenEvent($mid) {
    $config = CRM_Mailingtools_Config::singleton();

    // check if we're enabled
    $enabled = $config->getSetting('anonymous_open_enabled');
    if (!$enabled) {
      return NULL;
    }

    // mid needs to be set
    $mid = (int) $mid;
    if (!$mid) {
      throw new Exception("Invalid mailing ID");
    }

    // NOW: find the event queue ID
    $event_queue_id = NULL;

    // FIRST: try by preferred contact
    $preferred_contact_id = (int) $config->getSetting('anonymous_open_contact_id');
    if ($preferred_contact_id) {
      $event_queue_id = CRM_Core_DAO::singleValueQuery("
        SELECT queue.id
        FROM civicrm_mailing_event_queue queue
        LEFT JOIN civicrm_mailing_job    job   ON queue.job_id = job.id
        WHERE queue.contact_id = %1
          AND job.mailing_id = %2", [
              1 => [$preferred_contact_id, 'Integer'],
              2 => [$mid,                  'Integer']]);
    }

    // SECOND: take the smallest contact ID
    if (empty($event_queue_id)) {
      $contact_id = CRM_Core_DAO::singleValueQuery("
        SELECT MIN(contact_id)
        FROM civicrm_mailing_event_queue queue
        LEFT JOIN civicrm_mailing_job    job   ON queue.job_id = job.id
        WHERE job.mailing_id = %1", [
          1 => [$mid, 'Integer']]);

      if ($contact_id) {
        $event_queue_id = CRM_Core_DAO::singleValueQuery("
        SELECT queue.id
        FROM civicrm_mailing_event_queue queue
        LEFT JOIN civicrm_mailing_job    job   ON queue.job_id = job.id
        WHERE queue.contact_id = %1
          AND job.mailing_id = %2", [
            1 => [$contact_id, 'Integer'],
            2 => [$mid, 'Integer']]);
      } else {
        throw new Exception("No contacts in queue for mailing [{$mid}]");
      }
    }

    // ERROR: if this is not set yet, something is wrong.
    if (empty($event_queue_id)) {
      throw new Exception("No found event in queue for mailing [{$mid}]");
    }

    // all good: add entry
    CRM_Core_Error::debug_log_message("Tracked anonymous open event for mailing [{$mid}]");
    CRM_Core_DAO::executeQuery("
        INSERT INTO civicrm_mailing_event_opened (event_queue_id, time_stamp)
        VALUES (%1, NOW())", [
            1 => [$event_queue_id, 'Integer']]);

    return $event_queue_id;
  }

  /**
   * This function will manipulate open tracker URLs in emails, so they point
   *  to the anonymous handler instead of the native one
   */
  public static function modifyEmailBody(&$body) {
    $config = CRM_Mailingtools_Config::singleton();
    if (!$config->getSetting('anonymous_open_enabled')
        || !$config->getSetting('anonymous_open_url')) {
      // NOT ENABLED
      return;
    }

    // get the base URL
    $core_config = CRM_Core_Config::singleton();
    $system_base = $core_config->userFrameworkBaseURL;

    // find all all relevant links and collect queue IDs
    if (preg_match_all("#{$system_base}sites/all/modules/civicrm/extern/open.php\?q=(?P<queue_id>[0-9]+)[^0-9]#i", $body, $matches)) {
      $queue_ids = $matches['queue_id'];

      if (!empty($queue_ids)) {
        // resolve queue_id => mailing_id
        $queue_id_to_mailing_id = self::getQueueID2MailingID($queue_ids);

        // replace open trackers
        foreach ($queue_id_to_mailing_id as $queue_id => $mailing_id) {
          $new_url = $config->getSetting('anonymous_open_url') . "?mid={$mailing_id}";
          $body = preg_replace("#{$system_base}sites/all/modules/civicrm/extern/open.php\?q={$queue_id}#i", $new_url, $body);
        }
      }
    }
  }

  /**
   * Resolve a list of queue ids to mailing IDs
   *
   * @param $queue_ids array list of queue IDs
   * @return array list of queue_id => mailing id
   *
   * @todo: pre-caching of all queue IDs for the current mailing?
   */
  public static function getQueueID2MailingID($queue_ids) {
    $queue_id_to_mailing_id = [];
    if (empty($queue_ids) || !is_array($queue_ids)) {
      return $queue_id_to_mailing_id;
    }

    // run the query
    $queue_id_list = implode(',', $queue_ids);
    $query = CRM_Core_DAO::executeQuery("
        SELECT queue.id       AS queue_id,
               job.mailing_id AS mailing_id
        FROM civicrm_mailing_event_queue queue
        LEFT JOIN civicrm_mailing_job    job   ON queue.job_id = job.id
        WHERE queue.id IN ({$queue_id_list})
        GROUP BY queue.id");
    while ($query->fetch()) {
      $queue_id_to_mailing_id[$query->queue_id] = $query->mailing_id;
    }

    return $queue_id_to_mailing_id;
  }
}