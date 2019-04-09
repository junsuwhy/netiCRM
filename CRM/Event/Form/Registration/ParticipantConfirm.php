<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 3.3                                               |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2010                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/**
 *
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2010
 * $Id$
 *
 */

require_once 'CRM/Event/Form/Registration.php';

/**
 * This class generates form components for processing Event
 *
 */
class CRM_Event_Form_Registration_ParticipantConfirm extends CRM_Event_Form_Registration {
  // optional credit card return status code
  // CRM-6060
  protected $_cc = NULL;

  protected $_participant = NULL;

  /**
   * Function to set variables up before form is built
   *
   * @return void
   * @access public
   */
  public function preProcess() {
    $this->_participantId = CRM_Utils_Request::retrieve('participantId', 'Positive', $this);

    $this->_cc = CRM_Utils_Request::retrieve('cc', 'String', $this);

    //get the contact and event id and assing to session.
    $values = array();
    $csContactID = $eventId = NULL;
    if ($this->_participantId) {
      require_once 'CRM/Event/BAO/Participant.php';
      $params = array('id' => $this->_participantId);
      CRM_Core_DAO::commonRetrieve('CRM_Event_DAO_Participant', $params, $values,
        array('contact_id', 'event_id', 'status_id', 'register_date')
      );
      $this->_participant = $values;
    }

    $this->_participantStatusId = $values['status_id'];
    $this->_eventId = CRM_Utils_Array::value('event_id', $values);
    $csContactId = CRM_Utils_Array::value('contact_id', $values);

    // make sure we have right permission to edit this user
    $this->_csContactID = NULL;
    if ($csContactId && $this->_eventId) {
      $session = CRM_Core_Session::singleton();
      $currentContact = $session->get('userID');
      // logged in contact
      if (!empty($currentContact)) {
        if ($csContactId == $currentContact) {
          $this->_csContactID = $csContactId;
        }
        else {
          $this->_csContactID = NULL;
          $config = CRM_Core_Config::singleton();
          $statusMessage = ts('Current logged in contact is not the same contact of participant. Please log out first and try again.');
          CRM_Core_Error::statusBounce($statusMessage, CRM_Utils_System::url('civicrm/event/info', "reset=1&id={$this->_eventId}&noFullMsg=1", FALSE, NULL, FALSE, TRUE));
        }
      }
      else {
        require_once 'CRM/Contact/BAO/Contact/Permission.php';
        if (CRM_Contact_BAO_Contact_Permission::validateChecksumContact($csContactId, $this)) {
          //since we have landing page so get this contact
          //id in session if user really want to walk wizard.
          $this->_csContactID = $csContactId;
        }
      }
    }

    if (!$this->_csContactID) {
      $config = CRM_Core_Config::singleton();
      CRM_Core_Error::statusBounce(ts('You do not have permission to access this event registration. Contact the site administrator if you need assistance.'), $config->userFrameworkBaseURL);
    }
  }

  /**
   * Function to build the form
   *
   * @return None
   * @access public
   */
  public function buildQuickForm() {
    $values = array();
    $params = array('id' => $this->_eventId);
    CRM_Event_BAO_Event::retrieve($params, $values['event']);

    $buttons = array();
    $expired = FALSE;

    // calculate expiration day base on registration day, #22026
    if (!empty($values['event']['expiration_time'])) {
      $baseTime = strtotime($this->_participant['register_date']);
      $plusDay = ceil($values['event']['expiration_time']/24);
      $expiredTime = CRM_Core_Payment::calcExpirationDate($baseTime, $plusDay);
      if (time() > $expiredTime) {
        $expired = TRUE;
      }
    }
    
    // only pending status class family able to confirm.
    $statusMsg = NULL;
    if (array_key_exists($this->_participantStatusId, CRM_Event_PseudoConstant::participantStatus(NULL, "class = 'Pending'")) && !$expired) {
      //need to confirm that though participant confirming
      //registration - but is there enough space to confirm.
      require_once 'CRM/Event/PseudoConstant.php';
      require_once 'CRM/Event/BAO/Participant.php';
      $emptySeats = CRM_Event_BAO_participant::pendingToConfirmSpaces($this->_eventId);
      $additonalIds = CRM_Event_BAO_participant::getAdditionalParticipantIds($this->_participantId);
      $requireSpace = 1 + count($additonalIds);
      if ($emptySeats !== NULL && ($requireSpace > $emptySeats)) {
        $statusMsg = ts("Oops, it looks like there are currently no available spaces for the %1 event.", array(1 => $values['event']['title']));
      }
      else {
        if ($this->_cc == 'fail') {
          $statusMsg = '<div class="bold">' . ts('Your Credit Card transaction was not successful. No money has yet been charged to your card.') . '</div><div><br />' . ts('Click the "Confirm Registration" button to complete your registration in %1, or click "Cancel Registration" if you are no longer interested in attending this event.', array(1 => $values['event']['title'])) . '</div>';
        }
        else {
          $url = CRM_Utils_System::url('civicrm/event/info', "reset=1&id={$this->_eventId}&noFullMsg=1",FALSE, NULL, FALSE, TRUE );
          $statusMsg = '<div class="bold">' . ts('Confirm your registration for %1.', array(1 => "<a href='$url' target='_blank'>".$values['event']['title']."</a>")) . '</div><div><br />' . ts('Click the "Confirm Registration" button to begin, or click "Cancel Registration" if you are no longer interested in attending this event.') . '</div>';
        }
        $buttons = array_merge($buttons, array(array('type' => 'next',
              'name' => ts('Confirm Registration'),
              'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
              'isDefault' => TRUE,
            )));
      }
    }

    // status class other than Negative should be able to cancel registration.
    if (array_key_exists($this->_participantStatusId, CRM_Event_PseudoConstant::participantStatus(NULL, "class != 'Negative'")) && !$expired) {
      $cancelConfirm = ts('Are you sure you want to cancel your registration for this event?');
      $buttons = array_merge($buttons, array(array('type' => 'submit',
            'name' => ts('Cancel Registration'),
            'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
            'js' => array('onclick' => 'return confirm(\'' . $cancelConfirm . '\');'),
          )));
      if (!$statusMsg) {
        $statusMsg = ts('You can cancel your registration for %1 by clicking "Cancel Registration".', array(1 => $values['event']['title']));
      }
    }
    if (!$statusMsg) {
      $statusMsg = ts("Oops, it looks like your registration for %1 has already been cancelled.",
        array(1 => $values['event']['title'])
      );
    }
    $this->assign('statusMsg', $statusMsg);

    $this->assign('event', $values['event']);
    $this->assign('isShowLocation', CRM_Utils_Array::value('is_show_location', $values['event']));

    $params = array('entity_id' => $this->_eventId, 'entity_table' => 'civicrm_event');
    require_once 'CRM/Core/BAO/Location.php';
    $values['location'] = CRM_Core_BAO_Location::getValues($params, TRUE);

    //To show the event location on maps directly on event info page
    $config = CRM_Core_Config::singleton();
    $locations = &CRM_Event_BAO_Event::getMapInfo($this->_eventId);
    if (!empty($locations) && CRM_Utils_Array::value('is_map', $values['event'])) {
      $this->assign('locations', $locations);
      $this->assign('mapProvider', $config->mapProvider);
      $this->assign('mapKey', $config->mapAPIKey);
      $sumLat = $sumLng = 0;
      $maxLat = $maxLng = -400;
      $minLat = $minLng = + 400;
      foreach ($locations as $location) {
        $sumLat += $location['lat'];
        $sumLng += $location['lng'];

        if ($location['lat'] > $maxLat) {
          $maxLat = $location['lat'];
        }
        if ($location['lat'] < $minLat) {
          $minLat = $location['lat'];
        }

        if ($location['lng'] > $maxLng) {
          $maxLng = $location['lng'];
        }
        if ($location['lng'] < $minLng) {
          $minLng = $location['lng'];
        }
      }

      $center = array('lat' => (float ) $sumLat / count($locations),
        'lng' => (float ) $sumLng / count($locations),
      );
      $span = array('lat' => (float )($maxLat - $minLat),
        'lng' => (float )($maxLng - $minLng),
      );
      $this->assign_by_ref('center', $center);
      $this->assign_by_ref('span', $span);
      if ($action == CRM_Core_Action::PREVIEW) {
        $mapURL = CRM_Utils_System::url('civicrm/contact/map/event',
          "eid={$this->_eventId}&reset=1&action=preview",
          TRUE, NULL, TRUE,
          TRUE
        );
      }
      else {
        $mapURL = CRM_Utils_System::url('civicrm/contact/map/event',
          "eid={$this->_eventId}&reset=1",
          TRUE, NULL, TRUE,
          TRUE
        );
      }

      $this->assign('skipLocationType', TRUE);
      $this->assign('mapURL', $mapURL);
    }

    $this->assign('location', $values['location']);

    $this->addButtons($buttons);
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return None
   */
  public function postProcess() {
    //get the button.
    $buttonName = $this->controller->getButtonName();
    $eventId = $this->_eventId;
    $participantId = $this->_participantId;

    if ($buttonName == '_qf_ParticipantConfirm_next') {
      //lets get contact id in session.
      $session = CRM_Core_Session::singleton();
      $session->set('userID', $this->_csContactID);

      //check user registration status is from pending class
      $url = CRM_Utils_System::url('civicrm/event/register', "reset=1&id={$eventId}&participantId={$participantId}");
      CRM_Utils_System::redirect($url);
    }
    elseif ($buttonName == '_qf_ParticipantConfirm_submit') {
      //need to registration status to 'cancelled'.
      require_once 'CRM/Event/PseudoConstant.php';
      require_once 'CRM/Event/BAO/Participant.php';
      $cancelledId = array_search('Cancelled', CRM_Event_PseudoConstant::participantStatus(NULL, "class = 'Negative'"));
      $additionalParticipantIds = CRM_Event_BAO_Participant::getAdditionalParticipantIds($participantId);

      $participantIds = array_merge(array($participantId), $additionalParticipantIds);
      $results = CRM_Event_BAO_Participant::transitionParticipants($participantIds, $cancelledId, NULL, TRUE);

      if (count($participantIds) > 1) {
        $statusMessage = ts("%1 Event registration(s) have been cancelled.", array(1 => count($participantIds)));
      }
      else {
        $statusMessage = ts("Your event registration has been cancelled.");
      }
      if (CRM_Utils_Array::value('mailedParticipants', $results)) {
        foreach ($results['mailedParticipants'] as $key => $displayName) {
          $statusMessage .= "<br />" . ts("Email has been sent to : %1", array(1 => $displayName));
        }
      }

      CRM_Core_Error::statusBounce($statusMessage, CRM_Utils_System::url('civicrm/event/info', "reset=1&id={$this->_eventId}&noFullMsg=1",
          FALSE, NULL, FALSE, TRUE
        ));
    }
  }
}

