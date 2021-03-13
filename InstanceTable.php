<?php
/**
 * Instance Table External Module
 * @author Luke Stevens, Murdoch Children's Research Institute
 */

namespace MCRI\InstanceTable;

use ExternalModules\AbstractExternalModule;
use DateTimeRC;
use Files;
use Form;
use RCView;
use REDCap;

class InstanceTable extends AbstractExternalModule
{
    protected $isSurvey = false;
    protected $taggedFields = array();

    // global vars dependencies
    protected $Proj;
    protected $lang;
    protected $user_rights;
    protected $event_id;
    protected $record;
    protected $instance;
    protected $group_id;
    protected $repeat_instance;
    protected $defaultValueForNewPopup;

    const ACTION_TAG = '@INSTANCETABLE';
    const ACTION_TAG_HIDE_FIELD = '@INSTANCETABLE_HIDE';
    const ACTION_TAG_LABEL = '@INSTANCETABLE_LABEL';
    const ACTION_TAG_SCROLLX = '@INSTANCETABLE_SCROLLX';
    const ACTION_TAG_HIDEADDBTN = '@INSTANCETABLE_HIDEADD'; // i.e. hide "Add" button even if user has edit access to form
    const ACTION_TAG_REF = '@INSTANCETABLE_REF';
    const ACTION_TAG_SRC = '@INSTANCETABLE_SRC'; // deprecated
    const ACTION_TAG_DST = '@INSTANCETABLE_DST'; // deprecated
    const ADD_NEW_BTN_YSHIFT = '0px';
    const MODULE_VARNAME = 'MCRI_InstanceTable';
    const ACTION_TAG_DESC = 'Use with descriptive text fields to display a table of data from instances of a repeating form, or forms in a repeating event, with (for users with edit permissions) links to add/edit instances in a popup window.<br>* @INSTANCETABLE=my_form_name<br>* @INSTANCETABLE=event_name:my_form_name<br>There are some additional tags that may be used to further tweak the table behaviour. Take a look at the documentation via the External Modulers page for more information.';

    const ERROR_NOT_REPEATING_CLASSIC = '<div class="red">ERROR: "%s" is not a repeating form. Contact the project designer.';
    const ERROR_NOT_REPEATING_LONG = '<div class="red">ERROR: "%s" is not a repeating form for event "%s". Contact the project designer.';
    const ERROR_NO_VIEW_ACCESS = '<div class="yellow">You do not have permission to view this form\'s data.';

    public function __construct()
    {
        parent::__construct();
        global $Proj, $lang, $user_rights;
        $this->Proj = $Proj;
        $this->lang = &$lang;
        $this->user_rights = &$user_rights;
        $this->isSurvey = (PAGE === 'surveys/index.php');
    }

    public function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        $this->initHook($record, $instrument, $event_id, false, $group_id, $repeat_instance);
        $this->pageTop();

        if (isset($_GET['extmod_instance_table']) && $_GET['extmod_instance_table'] == '1') {
            // this is in the popup
            $this->popupViewTweaks();
        }
    }

    public function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        $this->initHook($record, $instrument, $event_id, true, $group_id, $repeat_instance);
        $this->pageTop();
    }

    public function redcap_save_record($project_id, $record = null, $instrument, $event_id, $group_id = null, $survey_hash = null, $response_id = null, $repeat_instance = 1)
    {
        // if saving an instance in the popup, get &extmod_instance_table=1 into the redirect link e.g. when missing required fields found
        if (isset($_GET['extmod_instance_table']) && $_GET['extmod_instance_table'] == '1') {
            $_GET['instance'] .= '&extmod_instance_table=1';
        }
    }

    protected function initHook($record, $instrument, $event_id, $isSurvey = false, $group_id, $repeat_instance)
    {
        $this->record = $record;
        $this->instrument = $instrument;
        $this->event_id = $event_id;
        $this->isSurvey = $isSurvey;
        $this->group_id = $group_id;
        $this->repeat_instance = $repeat_instance;
    }

    protected function pageTop()
    {
        // find any descriptive text fields tagged with @FORMINSTANCETABLE=form_name
        $this->setTaggedFields();

        if (count($this->taggedFields) > 0) {
            // check each specified form is a repeating form or a form in a repeating event
            $this->checkIsRepeating();

            // check user has read access to each specified form
            $this->checkUserPermissions();

            // set the markup for the DataTable (or error/no permission message)
            $this->setMarkup();

            // write the JavaScript to the page
            $this->insertJS();
        }
    }

    protected function setTaggedFields()
    {
        $this->taggedFields = array();

        $instrumentFields = REDCap::getDataDictionary('array', false, true, $this->instrument);

        foreach ($instrumentFields as $fieldName => $fieldDetails) {
            $matches = array();
            // /@INSTANCETABLE='?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/
            // asdf@INSTANCETABLE='eee_arm_1:fff_fff' asdf
            // Full match	4-39	@INSTANCETABLE='eee_arm_1:fff_fff'
            // Group 1.	20-37	eee_arm_1:fff_fff
            // Group 2.	20-30	eee_arm_1:
            if ($fieldDetails['field_type'] === 'descriptive' &&
                preg_match("/" . self::ACTION_TAG . "\s*=\s*'?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/", $fieldDetails['field_annotation'], $matches)) {

                if (REDCap::isLongitudinal() && strpos(trim($matches[1]), ':') > 0) {
                    $eventform = explode(':', trim($matches[1]), 2);
                    $eventId = REDCap::getEventIdFromUniqueEvent($eventform[0]);
                    $eventName = $eventform[0];
                    $formName = $eventform[1];
                } else {
                    $eventId = $this->event_id;
                    $eventName = '';
                    $formName = $matches[1];
                }

                $repeatingFormDetails = array();
                $repeatingFormDetails['field_name'] = $fieldName;
                $repeatingFormDetails['event_id'] = $eventId;
                $repeatingFormDetails['event_name'] = $eventName;
                $repeatingFormDetails['form_name'] = $formName;
                $repeatingFormDetails['permission_level'] = 0;
                $repeatingFormDetails['form_fields'] = array();
                $repeatingFormDetails['html_table_id'] = self::MODULE_VARNAME . '_' . $fieldName . '_tbl_' . $eventName . '_' . $formName;
                $repeatingFormDetails['html_table_class'] = self::MODULE_VARNAME . '_' . $eventName . '_' . $formName; // used to find tables to refresh after add/edit

                // filter records on an optional supplemental join key
                // useful in cases where repeating data entry forms are being used to represent relational data
                // e.g. when multiple medications are recorded on each visit (of which there can also be multiple),
                // the medications shown in the instance table on a visit instance should be filtered by their relationship with that visit instance
                $filter = '';
                $this->defaultValueForNewPopup = '';
                if (preg_match("/" . self::ACTION_TAG_REF . "\s*=\s*'?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/", $fieldDetails['field_annotation'], $matches)) {
                    $join_val = ($this->repeat_instance == null || empty($this->repeat_instance))
                        ? 1 : $this->repeat_instance;
                    $filter = "[" . trim($matches[1]) . "]='" . $join_val . "'";
                    $this->defaultValueForNewPopup = '&parent_instance=' . $join_val;
                    $repeatingFormDetails['form_ref'] = trim($matches[1]);
                }
                // keep legacy support for _SRC and _DST tags but any in use should be converted to _REF tags
                if (preg_match("/" . self::ACTION_TAG_SRC . "='?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/", $fieldDetails['field_annotation'], $matches)) {
                    $recordData = REDCap::getData('array', $this->record, $matches[1], $this->event_id, null, false, false, false, null, false); // export raw
                    $join_val = $recordData[1]['repeat_instances'][$this->event_id][$this->instrument][$this->repeat_instance][$matches[1]];
                    if (preg_match("/" . self::ACTION_TAG_DST . "='?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/", $fieldDetails['field_annotation'], $matches)) {
                        $filter = "[" . $matches[1] . "]='" . $join_val . "'";
                    }
                }

                $ajaxUrl = $this->getUrl('instance_table_ajax.php');
                $repeatingFormDetails['ajax_url'] = $ajaxUrl . "&record={$this->record}&event_id=$eventId&form_name=$formName&filter=$filter";
                $repeatingFormDetails['markup'] = '';

                if (preg_match("/" . self::ACTION_TAG_SCROLLX . "/", $fieldDetails['field_annotation'], $matches)) {
                    $repeatingFormDetails['scroll_x'] = true;
                } else {
                    $repeatingFormDetails['scroll_x'] = false;
                }

                if (preg_match("/" . self::ACTION_TAG_HIDEADDBTN . "/", $fieldDetails['field_annotation'], $matches)) {
                    $repeatingFormDetails['hide_add_btn'] = true;
                } else {
                    $repeatingFormDetails['hide_add_btn'] = false;
                }

                $this->taggedFields[] = $repeatingFormDetails;
            }
        }
    }

    protected function checkIsRepeating()
    {
        foreach ($this->taggedFields as $key => $repeatingFormDetails) {
            if (!$this->Proj->isRepeatingFormOrEvent($repeatingFormDetails['event_id'], $repeatingFormDetails['form_name'])) {
                $repeatingFormDetails['permission_level'] = -1; // error
            }
            $this->taggedFields[$key] = $repeatingFormDetails;
        }
    }

    protected function checkUserPermissions()
    {
        foreach ($this->taggedFields as $key => $repeatingFormDetails) {
            if ($this->isSurvey) {
                $repeatingFormDetails['permission_level'] = 2; // always read only in survey view
            } else if ($repeatingFormDetails['hide_add_btn']) {
                $repeatingFormDetails['permission_level'] = 2; // Hide "Add" button = "read only" (effectively!)
            } else if ($repeatingFormDetails['permission_level'] > -1) {
                switch ($this->user_rights['forms'][$repeatingFormDetails['form_name']]) {
                    case '1':
                        $repeatingFormDetails['permission_level'] = 1;
                        break; // view/edit
                    case '2':
                        $repeatingFormDetails['permission_level'] = 2;
                        break; // read only
                    case '3':
                        $repeatingFormDetails['permission_level'] = 1;
                        break; // view/edit + edit survey responses
                    case '0':
                        $repeatingFormDetails['permission_level'] = 0;
                        break; // no access
                    default :
                        $repeatingFormDetails['permission_level'] = -1;
                        break;
                }
            }
            $this->taggedFields[$key] = $repeatingFormDetails;
        }
    }

    protected function setMarkup()
    {
        foreach ($this->taggedFields as $key => $repeatingFormDetails) {
            switch ($repeatingFormDetails['permission_level']) {
                case 1: // view & edit
                    $repeatingFormDetails['markup'] = $this->makeHtmlTable($repeatingFormDetails['html_table_id'], $repeatingFormDetails['html_table_class'], $repeatingFormDetails['event_id'], $repeatingFormDetails['form_name'], true, $repeatingFormDetails['scroll_x']);
                    break;
                case '2': // read only
                    $repeatingFormDetails['markup'] = $this->makeHtmlTable($repeatingFormDetails['html_table_id'], $repeatingFormDetails['html_table_class'], $repeatingFormDetails['event_id'], $repeatingFormDetails['form_name'], false, $repeatingFormDetails['scroll_x']);
                    break;
                case '0': // no access
                    $repeatingFormDetails['markup'] = self::ERROR_NO_VIEW_ACCESS;
                    break;
                default: // -1 error
                    if (REDCap::isLongitudinal()) {
                        $eventName = $this->Proj->eventInfo[$repeatingFormDetails['event_id']]['name'];
                        $repeatingFormDetails['markup'] = sprintf(self::ERROR_NOT_REPEATING_LONG, $repeatingFormDetails['form_name'], $eventName);
                    } else {
                        $repeatingFormDetails['markup'] = sprintf(self::ERROR_NOT_REPEATING_CLASSIC, $repeatingFormDetails['form_name']);
                    }
                    break;
            }
            $this->taggedFields[$key] = $repeatingFormDetails;
        }
    }

    protected function makeHtmlTable($tableElementId, $tableFormClass, $eventId, $formName, $canEdit, $scrollX = false)
    {
        $scrollStyle = ($scrollX) ? "max-width:790px;" : "";
        $nColumns = 1; // start at 1 for # (Instance) column
        $html = '<div class="" style="margin-top:10px; margin-bottom:' . self::ADD_NEW_BTN_YSHIFT . ';">';
        $html .= '<table id="' . $tableElementId . '" class="table table-striped table-bordered table-condensed table-responsive ' . self::MODULE_VARNAME . ' ' . $tableFormClass . '" width="100%" cellspacing="0" style="' . $scrollStyle . '">';
        $html .= '<thead><tr><th>#</th>'; // .$this->lang['data_entry_246'].'</th>'; // Instance

        $repeatingFormFields = REDCap::getDataDictionary('array', false, null, $formName);

        foreach ($repeatingFormFields as $repeatingFormFieldDetails) {
            // ignore descriptive text fields and fields tagged @FORMINSTANCETABLE_HIDE
            $matches = array();
            if ($repeatingFormFieldDetails['field_type'] !== 'descriptive') {
                if (!preg_match("/" . self::ACTION_TAG_HIDE_FIELD . "/", $repeatingFormFieldDetails['field_annotation'])) {
                    $matches = array();
                    $relabel = preg_match("/" . self::ACTION_TAG_LABEL . "='(.+)'/", $repeatingFormFieldDetails['field_annotation'], $matches);
                    $colHeader = ($relabel) ? $matches[1] : $repeatingFormFieldDetails['field_label'];
                    $html .= "<th>$colHeader</th>";
                    $nColumns++;
                }
            }
        }
        if (!$this->isSurvey) {
            $html .= '<th>Form Status</th>'; // "Form Status" wording is hardcoded in MetaData::save_metadata()
            $nColumns++;
        }

        $html .= '</tr></thead>';

        // if survey form get data now (as have no auth for an ajax call)
        if ($this->isSurvey) {
            $html .= '<tbody>';
            $instanceData = $this->getInstanceData($this->record, $eventId, $formName, null, false);
            if (count($instanceData) === 0) {
                $html .= '<tr><td colspan="' . $nColumns . '">No data available in table</td></tr>';
            } else {
                foreach ($instanceData as $row => $rowValues) {
                    $html .= '<tr>';
                    foreach ($rowValues as $value) {
                        $html .= "<td>$value</td>";
                    }
                    $html .= '</tr>';
                }
            }
            $html .= '</tbody>';
        }

        $html .= '</table>';

        if ($canEdit) {
            $html .= '<div style="position:relative;top:' . self::ADD_NEW_BTN_YSHIFT . ';margin-bottom:5px;"><button type="button" class="btn btn-sm btn-success " onclick="' . self::MODULE_VARNAME . '.addNewInstance(\'' . $this->record . '\',' . $eventId . ',\'' . $formName . '\');"><span class="fas fa-plus-circle" aria-hidden="true"></span>&nbsp;' . $this->lang['data_entry_247'] . '</button></div>'; // Add new
        }
        return $html;
    }

    public function getInstanceData($record, $event, $form, $filter, $includeFormStatus = true)
    {
        $instanceData = array();

        $repeatingFormFields = REDCap::getDataDictionary('array', false, null, $form);

        $fieldsNeeded = array();
        foreach ($repeatingFormFields as $repeatingFormFieldName => $repeatingFormFieldDetails) {
            // ignore descriptive text fields and fields tagged @FORMINSTANCETABLE_HIDE
            if ($repeatingFormFieldDetails['field_type'] !== 'descriptive' &&
                !preg_match("/" . self::ACTION_TAG_HIDE_FIELD . "/", $repeatingFormFieldDetails['field_annotation'])) {
                $fieldsNeeded[] = $repeatingFormFieldName;
            }
        }
        if ($includeFormStatus) {
            $fieldsNeeded[] = $form . '_complete';
        }

        $recordData = REDCap::getData('array', $record, $fieldsNeeded, $event, null, false, false, false, $filter, true); // export labels not raw

        $formKey = ($this->Proj->isRepeatingEvent($event))
            ? ''     // repeating event - empty string key
            : $form; // repeating form  - form name key

        if (!empty($recordData[$record]['repeat_instances'][$event][$formKey])) {
            foreach ($recordData[$record]['repeat_instances'][$event][$formKey] as $instance => $instanceFieldData) {
                $thisInstanceValues = array();
                $thisInstanceValues[] = $this->makeInstanceNumDisplay($instance, $record, $event, $form, $instance);

                foreach ($instanceFieldData as $fieldName => $value) {
                    if (trim($value) === '') {
                        $thisInstanceValues[] = '';
                        continue;
                    }

                    $fieldType = $repeatingFormFields[$fieldName]['field_type'];

                    if ($fieldName === $form . '_complete') {
                        if ($this->isSurvey) {
                            continue;
                        }
                        $outValue = $this->makeFormStatusDisplay($value, $record, $event, $form, $instance);

                    } else if (in_array($fieldType, array("advcheckbox", "radio", "select", "checkbox", "dropdown", "sql", "yesno", "truefalse"))) {
                        $outValue = $this->makeChoiceDisplay($value, $repeatingFormFields, $fieldName);

                    } else if ($fieldType === 'text') {
                        $ontologyOption = $this->Proj->metadata[$fieldName]['element_enum'];
                        if ($ontologyOption !== '' && preg_match('/^\w+:\w+$/', $ontologyOption)) {
                            // ontology fields are text fields with an element enum like "BIOPORTAL:ICD10"
                            list($ontologyService, $ontologyCategory) = explode(':', $ontologyOption, 2);
                            $outValue = $this->makeOntologyDisplay($value, $ontologyService, $ontologyCategory);
                        } else {
                            // regular text fields have null element_enum
                            $outValue = $this->makeTextDisplay($value, $repeatingFormFields, $fieldName);
                        }

                    } else if ($fieldType === 'file') {
                        $outValue = $this->makeFileDisplay($value, $record, $event, $instance, $fieldName);

                    } else {
                        $outValue = $value;
                    }

                    $thisInstanceValues[] = $outValue;
                }

                $instanceData[] = $thisInstanceValues;
            }
            return $instanceData;
        }
    }

    protected function makeOpenPopupAnchor($val, $record, $event, $form, $instance)
    {
        if ($this->isSurvey) {
            return $val;
        }
        return '<a title="Open instance" href="javascript:;" onclick="' . self::MODULE_VARNAME . '.editInstance(\'' . $record . '\',' . $event . ',\'' . $form . '\',' . $instance . ');">' . $val . '</a>';
    }

    protected function makeInstanceNumDisplay($val, $record, $event, $form, $instance)
    {
        return $this->makeOpenPopupAnchor($val, $record, $event, $form, $instance);
    }

    protected function makeFormStatusDisplay($val, $record, $event, $form, $instance)
    {
        switch ($val) {
            case '2':
                $circle = '<img src="' . APP_PATH_IMAGES . 'circle_green.png" style="height:16px;width:16px;">';
                break;
            case '1':
                $circle = '<img src="' . APP_PATH_IMAGES . 'circle_yellow.png" style="height:16px;width:16px;">';
                break;
            default:
                $circle = '<img src="' . APP_PATH_IMAGES . 'circle_red.png" style="height:16px;width:16px;">';
                break;
        }
        return $this->makeOpenPopupAnchor($circle, $record, $event, $form, $instance);
    }

    protected function makeChoiceDisplay($val, $repeatingFormFields, $fieldName)
    {
        if ($this->Proj->metadata[$fieldName]['element_type'] === 'sql') {
            $choices = parseEnum(getSqlFieldEnum($this->Proj->metadata[$fieldName]['element_enum']));
        } else {
            $choices = parseEnum($this->Proj->metadata[$fieldName]['element_enum']);
        }

        if (is_array($val)) {
            foreach ($val as $valkey => $cbval) {
                if ($cbval === '1') {
                    $val[$valkey] = $this->makeChoiceDisplayHtml($valkey, $choices);
                } else {
                    unset($val[$valkey]);
                }
            }
            $outValue = implode('<br>', $val); // multiple checkbox selections one per line
        } else {
            $outValue = $this->makeChoiceDisplayHtml($val, $choices);
        }
        return $outValue;
    }

    protected function makeChoiceDisplayHtml($val, $choices)
    {
        if (array_key_exists($val, $choices)) {
            return $choices[$val] . ' <span class="text-muted">(' . $val . ')</span>';
        }
        return $val;
    }

    protected function makeTextDisplay($val, $repeatingFormFields, $fieldName)
    {
        if (trim($val) == '') {
            return '';
        }
        $valType = $repeatingFormFields[$fieldName]['text_validation_type_or_show_slider_number'];
        switch ($valType) {
            case 'date_mdy':
            case 'date_dmy':
            case 'datetime_mdy':
            case 'datetime_dmy':
            case 'datetime_seconds_mdy':
            case 'datetime_seconds_dmy':
                $outVal = DateTimeRC::datetimeConvert($val, 'ymd', substr($valType, -3)); // reformat raw ymd date/datetime value to mdy or dmy, if appropriate
                $outVal = $val; // stick with standard ymd format for better sorting
                break;
            case 'email':
                $outVal = "<a href='mailto:$val'>$val</a>";
                break;
            default:
                $outVal = htmlentities($val, ENT_QUOTES | ENT_SUBSTITUTE);
                break;
        }
        return $outVal;
    }

    protected function makeFileDisplay($val, $record, $event_id, $instance, $fieldName)
    {
        $downloadDocUrl = APP_PATH_WEBROOT . 'DataEntry/file_download.php?pid=' . PROJECT_ID . "&s=&record=$record&event_id=$event_id&instance=$instance&field_name=$fieldName&id=$val&doc_id_hash=" . Files::docIdHash($val);
        return "<button class='btn btn-defaultrc btn-xs' style='font-size:8pt;' onclick=\"window.open('$downloadDocUrl','_blank');return false;\">{$this->lang['design_121']}</button>";
    }

    protected function makeOntologyDisplay($val, $service, $category)
    {
        $sql = "select label from redcap.redcap_web_service_cache where project_id=" . db_escape(PROJECT_ID) . " and service='" . db_escape($service) . "' and category='" . db_escape($category) . "' and `value`='" . db_escape($val) . "'";
        $q = db_query($sql);
        $cachedLabel = db_result($q, 0);
//                $cachedLabel = db_result(db_query("select label from redcap.redcap_web_service_cache where project_id=".db_escape($PROJECT_ID)." and service='".db_escape($service)."' and category='".db_escape($category)."' and `value`='".db_escape($val)."'"), 0);
        return (is_null($cachedLabel) || $cachedLabel === '')
            ? $val
            : $cachedLabel . ' <span class="text-muted">(' . $val . ')</span>';
    }

    protected function insertJS()
    {
        ?>
      <style type="text/css">
          .<?php echo self::MODULE_VARNAME;?> tbody tr {
              font-weight: normal;
          }

          /*.greenhighlight {background-color: inherit !important; }*/
          /*.greenhighlight table td {background-color: inherit !important; }*/
      </style>
      <script type="text/javascript">
        'use strict';
        var <?php echo self::MODULE_VARNAME;?> =
        (function (window, document, $, app_path_webroot, pid, simpleDialog, undefined) { // var MCRI_FormInstanceTable ...
          var isSurvey = <?php echo ($this->isSurvey) ? 'true' : 'false';?>;
          var tableClass = '<?php echo self::MODULE_VARNAME;?>';
          var langYes = '<?php echo js_escape($this->lang['design_100']);?>';
          var langNo = '<?php echo js_escape($this->lang['design_99']);?>';
          var config = <?php echo json_encode($this->taggedFields, JSON_PRETTY_PRINT);?>;
          var taggedFieldNames = [];
          var defaultValueForNewPopup = '<?php echo js_escape($this->defaultValueForNewPopup);?>';

          function init() {
            config.forEach(function (taggedField) {
              taggedFieldNames.push(taggedField.field_name);
              $('#' + taggedField.field_name + '-tr td:last')
                .append(taggedField.markup);
              var thisTbl = $('#' + taggedField.html_table_id)
                .DataTable({
                  "stateSave": true,
                  "stateDuration": 0,
                  "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]]
                });
              if (!isSurvey) {
                thisTbl.ajax.url(taggedField.ajax_url).load();
              }
            });

            // override global function doGreenHighlight() so we can skip the descriptive text fields with tables
            var globalDoGreenHighlight = doGreenHighlight;
            doGreenHighlight = function (rowob) {
              if ($.inArray(rowob.attr('sq_id'), taggedFieldNames) === -1) {
                globalDoGreenHighlight(rowob);
              }
            };
          }

          $(document).ready(function () {
            init();
          });

          function instancePopup(title, record, event, form, instance) {
            var url = app_path_webroot + 'DataEntry/index.php?pid=' + pid + '&id=' + record + '&event_id=' + event + '&page=' + form + '&instance=' + instance + '&extmod_instance_table=1' + defaultValueForNewPopup;
            popupWindow(url, title, window, 700, 950);
            //refreshTableDialog(event, form);
            return false;
          }

          function popupWindow(url, title, win, w, h) {
            var y = win.top.outerHeight / 2 + win.top.screenY - (h / 2);
            var x = win.top.outerWidth / 2 + win.top.screenX - (w / 2);
            return win.open(url, title, 'status,scrollbars,resizable,width=' + w + ',height=' + h + ',top=' + y + ',left=' + x);
          }

          function refreshTableDialog() {
            simpleDialog('Refresh the table contents and display any changes (instances added, updated or deleted).'
              , 'Refresh Table?', null, 500
              , null, langNo
              , function () {
                // refresh all instance tables (e.g. to pick up changes to multiple forms across repeating event
                $('.' + tableClass).each(function () {
                  $(this).DataTable().ajax.reload(null, false); // don't reset user paging on reload
                });
              }, langYes
            );
          }

          return {
            addNewInstance: function (record, event, form) {
              instancePopup('Add instance', record, event, form, '1&extmod_instance_table_add_new=1');
              return false;
            },
            editInstance: function (record, event, form, instance) {
              instancePopup('View instance', record, event, form, instance);
              return false;
            }
          }
        })(window, document, jQuery, app_path_webroot, pid, simpleDialog);

        function refreshTables() {
          // refresh immediately , just in case the server has already processed the saveData call
          actuallyRefreshTables();
          // now start a timer and try it again after a second, to give the server ample time to persist the data
          window.setTimeout(actuallyRefreshTables(), 1500);
        }

        function actuallyRefreshTables() {
          var tableClass = '<?php echo self::MODULE_VARNAME;?>';
          $('.' + tableClass).each(function () {
            $(this).DataTable().ajax.reload(null, false); // don't reset user paging on reload
          });
        }

      </script>
        <?php
    }

    /**
     * popupViewTweaks
     * JS to hide unwanted elements in instance data entry popup window
     * Hide:
     * - left-hand menu column
     * - Save & Exit Record button
     * - Save & Go To Next Record button
     */
    protected function popupViewTweaks()
    {
        ?>
      <style type="text/css">
          .navbar-toggler, #west, #formSaveTip, #dataEntryTopOptionsButtons, #formtop-div {
              display: none !important;
          }

          /*div[aria-describedby="reqPopup"] > .ui-dialog-buttonpane > button { color:red !important; visibility: hidden; }*/
      </style>
      <script type="text/javascript">

        $.urlParamReplace = function (url, name, value) {
          var results = new RegExp('[\?&]' + name + '=([^&#]*)')
            .exec(url);
          if (results !== null) {
            return url.replace(name + '=' + results[1], name + '=' + value);
          } else {
            return url;
          }
        }

        $.urlParamValue = function (url, name) {
          var results = new RegExp('[\?&]' + name + '=([^&#]*)')
            .exec(url);
          if (results !== null) {
            return results[1];
          }
          return null;
        }

        $.redirectUrl = function (url) {
          window.location.href = url;
          return;
        }

        $(document).ready(function () {
          // add this window.unload for added reliability in invoking refreshTables on close of popup
          window.onunload = function () {
            window.opener.refreshTables();
          };

          $('#form').attr('action', $('#form').attr('action') + '&extmod_instance_table=1');
          $('button[name=submit-btn-saverecord]')// Save & Close
            .attr('name', 'submit-btn-savecontinue')
            .removeAttr('onclick')
            .click(function (event) {
              dataEntrySubmit(this);
              event.preventDefault();
              window.opener.refreshTables();
              window.setTimeout(window.close, 800);
            });
          /* highjacking existing buttons for custom functionality*/
          $('#submit-btn-savenextform')
            // Save & New Instance
            // default redcap behavior does not always have this option available
            .attr('name', 'submit-btn-savecontinue')
            .removeAttr('onclick')
            .text(<?php echo "'" . $this->lang['data_entry_275'] . "'"?>)
            .click(function (event) {
              var currentUrl = window.location.href;
              dataEntrySubmit(this);
              event.preventDefault();
              window.opener.refreshTables();
              var redirectUrl = $.urlParamReplace(currentUrl, "instance", 1)
                + '&extmod_instance_table_add_new=1';
              window.setTimeout($.redirectUrl, 500, redirectUrl);
            });
          $('#submit-btn-savenextinstance')
            // Save and Next Instance -- go to next instance of same parent
            // default redcap behavior goes to next instance, regardless of parent.
            .attr('name', 'submit-btn-savecontinue')
            .removeAttr('onclick')
            .text(<?php echo "'" . $this->lang['data_entry_276'] . "'"?>)
            .click(function (event) {
              var currentUrl = window.location.href;
              dataEntrySubmit(this);
              event.preventDefault();
              window.opener.refreshTables();
              var next = $.urlParamValue(currentUrl, "next_instance");
              var redirectUrl = currentUrl;
              if (next == null) {
                redirectUrl = currentUrl + '&next_instance=1';
              } else {
                redirectUrl = $.urlParamReplace(redirectUrl, "next_instance", parseInt(next) + 1);
              }
              window.setTimeout($.redirectUrl, 500, redirectUrl);
            });
          $('#submit-btn-saveexitrecord').css("display", "none");
          $('#submit-btn-savenextrecord').css("display", "none");
          $('button[name=submit-btn-cancel]')
            .removeAttr('onclick')
            .click(function () {
              window.opener.refreshTables();
              window.close();
            });
            <?php
            if ( isset($_GET['extmod_instance_table_add_new'])) {
            ?>
          $('button[name=submit-btn-deleteform]').css("display", "none");
            <?php
            } else {
            ?>
          $('button[name=submit-btn-deleteform]')
            .removeAttr('onclick')
            .click(function (event) {
              simpleDialog('<div style="margin:10px 0;font-size:13px;">' +
                  <?php echo '"' . $this->lang['data_entry_239'] .
                      '<div style=&quot;margin-top:15px;color:#C00000;&quot;>' .
                      $this->lang['data_entry_432'] . ' <b>' . $_GET['instance'] . '</b></div> ' .
                      '<div style=&quot;margin-top:15px;color:#C00000;font-weight:bold;&quot;>' .
                      $this->lang['data_entry_190'] . '</div> </div>"'?>,
                'DELETE ALL DATA ON THIS FORM?', null, 600, null,
                'Cancel',
                function () {
                  dataEntrySubmit(document.getElementsByName('submit-btn-deleteform')[0]);
                  event.preventDefault();
                  window.opener.refreshTables();
                  window.setTimeout(window.close, 300);
                },
                  <?php echo "'" . $this->lang['data_entry_234'] . "'"?>);//Delete data for THIS FORM only
              return false;
            });
            <?php
            }
            if (isset($_GET['__reqmsg'])) {
            ?>
          setTimeout(function () {
            $('div[aria-describedby="reqPopup"]').find('div.ui-dialog-buttonpane').find('button').not(':last').hide(); // .css('visibility', 'visible'); // required fields message show only "OK" button, not ignore & leave
          }, 100);
            <?php
            }
            ?>
        });
      </script>
        <?php
    }

    /**
     * - Augment the action_tag_explain content on project Design pages by
     * adding some additional tr following the last built-in action tag.
     * - If adding a new instance, read the current max instance and redirect
     * to a form with instance value current + 1
     * @param type $project_id
     */
    public function redcap_every_page_before_render($project_id)
    {
        if (PAGE === 'Design/action_tag_explain.php') {
            $lastActionTagDesc = end(Form::getActionTags());

            // which $lang element is this?
            $langElement = array_search($lastActionTagDesc, $this->lang);

            $lastActionTagDesc .= "</td></tr>";
            $lastActionTagDesc .= $this->makeTagTR(static::ACTION_TAG, static::ACTION_TAG_DESC);

            $this->lang[$langElement] = rtrim(rtrim(rtrim(trim($lastActionTagDesc), '</tr>')), '</td>');
        } else if (PAGE === 'DataEntry/index.php'
            && isset($_GET['extmod_instance_table'])
            && isset($_GET['extmod_instance_table_add_new'])) {
            // adding new instance - read current max and redirect to + 1
            $formKey = ($this->Proj->isRepeatingEvent($_GET['event_id']))
                ? ''             // repeating event - empty string key
                : $_GET['page']; // repeating form  - form name key

            $recordData = REDCap::getData('array', $_GET['id'], $_GET['page'] . '_complete', $_GET['event_id']);
            if (!empty($recordData[$_GET['id']]['repeat_instances'][$_GET['event_id']][$formKey])) {
                $currentInstances = array_keys($recordData[$_GET['id']]['repeat_instances'][$_GET['event_id']][$formKey]);
                $_GET['instance'] = 1 + end($currentInstances);
            } else $_GET['instance'] = 1;
        } else if (PAGE === 'DataEntry/index.php'
            && isset($_GET['extmod_instance_table'])
            && isset($_GET['next_instance'])) {
            // if "Save & Next" instance is clicked, but next instance belongs to a different parent,
            // then redirect to a new form
            $formKey = ($this->Proj->isRepeatingEvent($_GET['event_id']))
                ? ''             // repeating event - empty string key
                : $_GET['page']; // repeating form  - form name key
            $this->setTaggedFields();

            $formRef = '';
            foreach ($this->taggedFields as $key => $repeatingFormDetails) {
                if ($repeatingFormDetails['form_name'] === $formKey) {
                    $formRef = $repeatingFormDetails['form_ref'];
                    break;
                }
            }
            $recordData = REDCap::getData('array', $_GET['id'], $formRef, $_GET['event_id'], null, false, false
                , false, '[' . $formRef . ']="' . $_GET['parent_instance'] . '"');

            $currentInstances = array_keys($recordData[$_GET['id']]['repeat_instances'][$_GET['event_id']][$formKey]);
            if ($_GET['instance'] === end($currentInstances)) {
                // it's the last instance for this parent, so get the max instance of all the child instances and add 1
                $recordData = REDCap::getData('array', $_GET['id'], $_GET['page'] . '_complete', $_GET['event_id']);
                $currentInstances = array_keys($recordData[$_GET['id']]['repeat_instances'][$_GET['event_id']][$formKey]);
                $_GET['instance'] = 1 + end($currentInstances);
            } else {
                // find the next instance after this instance
                $key = array_search($_GET['instance'], $currentInstances);
                if ($key + $_GET['next_instance'] > count($currentInstances)) {
                    $_GET['instance'] = 1 + end($currentInstances);
                } else {
                    $_GET['instance'] = $currentInstances[$key + $_GET['next_instance']];
                }
            }
        }
    }

    /**
     * Make a table row for an action tag copied from
     * v8.5.0/Design/action_tag_explain.php
     * @param type $tag
     * @param type $description
     * @return type
     * @global type $isAjax
     */
    protected function makeTagTR($tag, $description)
    {
        global $isAjax;
        return RCView::tr(array(),
            RCView::td(array('class' => 'nowrap', 'style' => 'text-align:center;background-color:#f5f5f5;color:#912B2B;padding:7px 15px 7px 12px;font-weight:bold;border:1px solid #ccc;border-bottom:0;border-right:0;'),
                ((!$isAjax || (isset($_POST['hideBtns']) && $_POST['hideBtns'] == '1')) ? '' :
                    RCView::button(array('class' => 'btn btn-xs btn-rcred', 'style' => '', 'onclick' => "$('#field_annotation').val(trim('" . js_escape($tag) . " '+$('#field_annotation').val())); highlightTableRowOb($(this).parentsUntil('tr').parent(),2500);"), $this->lang['design_171'])
                )
            ) .
            RCView::td(array('class' => 'nowrap', 'style' => 'background-color:#f5f5f5;color:#912B2B;padding:7px;font-weight:bold;border:1px solid #ccc;border-bottom:0;border-left:0;border-right:0;'),
                $tag
            ) .
            RCView::td(array('style' => 'font-size:12px;background-color:#f5f5f5;padding:7px;border:1px solid #ccc;border-bottom:0;border-left:0;'),
                '<i class="fas fa-cube mr-1"></i>' . $description
            )
        );

    }
}
