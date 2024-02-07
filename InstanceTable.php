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
        protected $isSurvey=false;
        protected $taggedFields=array();

        // global vars dependencies
        protected $Proj;
        protected $lang;
        protected $user_rights;
        protected $event_id;
        protected $record;
        protected $instrument;
        protected $instance;
        protected $group_id;
        protected $repeat_instance;
        protected $defaultValueForNewPopup;

        const ACTION_TAG = '@INSTANCETABLE';
        const ACTION_TAG_HIDE_FIELD = '@INSTANCETABLE_HIDE';
        const ACTION_TAG_LABEL = '@INSTANCETABLE_LABEL';
        const ACTION_TAG_SCROLLX = '@INSTANCETABLE_SCROLLX';
        const ACTION_TAG_HIDEADDBTN = '@INSTANCETABLE_HIDEADD'; // i.e. hide "Add" button even if user has edit access to form
        const ACTION_TAG_HIDEINSTANCECOL = '@INSTANCETABLE_HIDEINSTANCECOL'; // i.e. hide the "#" column containing instance numbers
        const ACTION_TAG_VARLIST = '@INSTANCETABLE_VARLIST'; // provide a comma-separated list of variables to include (not including any tagged HIDE)
        const ACTION_TAG_PAGESIZE = '@INSTANCETABLE_PAGESIZE'; // Override default choices for page sizing: specify integer default page size, use -1 for All
        const ACTION_TAG_REF = '@INSTANCETABLE_REF';
        const ACTION_TAG_SRC = '@INSTANCETABLE_SRC'; // deprecated
        const ACTION_TAG_DST = '@INSTANCETABLE_DST'; // deprecated
        const ACTION_TAG_FILTER = '@INSTANCETABLE_FILTER';
        const ADD_NEW_BTN_YSHIFT = '0px';
        const MODULE_VARNAME = 'MCRI_InstanceTable';

        const ERROR_NOT_REPEATING_CLASSIC = '<div class="red">ERROR: "%s" is not a repeating form. Contact the project designer.';
        const ERROR_NOT_REPEATING_LONG = '<div class="red">ERROR: "%s" is not a repeating form for event "%s". Contact the project designer.';
        const ERROR_NO_VIEW_ACCESS = '<div class="yellow">You do not have permission to view this form\'s data.';
        const REPLQUOTE_SINGLE = 'REPLQUOTE_SINGLE';
        const REPLQUOTE_DOUBLE = 'REPLQUOTE_DOUBLE';

        /**
         * redcap_save_record
         * When saving an instance in the popup, get &extmod_instance_table=1 into the redirect link e.g. when missing required fields found
         */
        public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
                if (isset($_GET['extmod_instance_table']) && $_GET['extmod_instance_table']=='1') {
                    $_GET['pid'] .= '&extmod_instance_table=1'; // $_GET['instance'] .= '&extmod_instance_table=1';
                }
        }

        /**
         * redcap_every_page_before_render
         * When doing Save & Exit or Delete Instance from popup then persist a flag on the session.
         * If Record Home page is loading when this flag is present then window.close()
         * @param type $project_id
         */
        public function redcap_every_page_top($project_id) {
                if (PAGE==='DataEntry/record_home.php' && isset($_GET['id']) && isset($_SESSION['extmod_closerec_home']) && $_GET['id']==$_SESSION['extmod_closerec_home']) {
                        ?>
                        <script type="text/javascript">/* EM Instance Table */ window.close();</script>
                        <?php
                        unset($_SESSION['extmod_closerec_home']);
                }
        }

        public function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
                $this->initHook($record, $instrument, $event_id, false, $group_id, $repeat_instance);
                $this->pageTop();

                if (isset($_GET['extmod_instance_table']) && $_GET['extmod_instance_table']=='1') {
                        // this is in the popup
                        $this->popupViewTweaks();
                }
        }

        public function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
                $this->initHook($record, $instrument, $event_id, true, $group_id, $repeat_instance);
                $this->pageTop();
        }

        protected function initHook($record, $instrument, $event_id, $isSurvey, $group_id, $repeat_instance) {
            global $Proj, $lang, $user_rights;
            $this->Proj = $Proj;
            $this->lang = &$lang;
            $this->user_rights = &$user_rights;
            $this->isSurvey = (PAGE==='surveys/index.php');
            $this->record = $record;
            $this->instrument = $instrument;
            $this->event_id = $event_id;
            $this->isSurvey = $isSurvey;
            $this->group_id = $group_id;
            $this->repeat_instance = $repeat_instance;
        }

        protected function pageTop() {
                // find any descriptive text fields tagged with @FORMINSTANCETABLE=form_name
                $this->setTaggedFields();

                if (count($this->taggedFields)>0) {
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

        protected function setTaggedFields() {
                $this->taggedFields = array();

                $instrumentFields = REDCap::getDataDictionary('array', false, true, $this->instrument);

                foreach ($instrumentFields as $fieldName => $fieldDetails) {
                        $matches = array();
                        // /@INSTANCETABLE='?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/
                        // asdf@INSTANCETABLE='eee_arm_1:fff_fff' asdf
                        // Full match	4-39	@INSTANCETABLE='eee_arm_1:fff_fff'
                        // Group 1.	20-37	eee_arm_1:fff_fff
                        // Group 2.	20-30	eee_arm_1:
                        if ($fieldDetails['field_type']==='descriptive' &&
                            preg_match("/".self::ACTION_TAG."\s*=\s*'?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/", $fieldDetails['field_annotation'], $matches)) {

                                if (REDCap::isLongitudinal() && strpos(trim($matches[1]), ':')>0) {
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
                                $repeatingFormDetails['html_table_id'] = self::MODULE_VARNAME.'_'.$fieldName.'_tbl_'.$eventName.'_'.$formName;
                                $repeatingFormDetails['html_table_class'] = self::MODULE_VARNAME.'_'.$eventName.'_'.$formName; // used to find tables to refresh after add/edit

                                // filter records on an optional supplemental join key
                                // useful in cases where repeating data entry forms are being used to represent relational data
                                // e.g. when multiple medications are recorded on each visit (of which there can also be multiple),
                                // the medications shown in the instance table on a visit instance should be filtered by their relationship with that visit instance
                                $filter = '';
                                $matches = array();
                                $this->defaultValueForNewPopup = '';
                                if (preg_match("/".self::ACTION_TAG_REF."\s*=\s*'?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/", $fieldDetails['field_annotation'], $matches)) {
                                        $join_val  = ($this->repeat_instance == null || empty($this->repeat_instance))
                                            ? 1 : $this->repeat_instance;
                                        $linkField = trim($matches[1]);
                                        $filter  = "[" . $linkField ."]='" .$join_val."'";
                                        $repeatingFormDetails['link_field'] = $linkField;
                                        $repeatingFormDetails['link_instance'] = $join_val;
                                }
                                // keep legacy support for _SRC and _DST tags but any in use should be converted to _REF tags
                                $matches = array();
                                if (preg_match("/".self::ACTION_TAG_SRC."='?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/", $fieldDetails['field_annotation'], $matches)) {
                                        $recordData = REDCap::getData('array', $this->record, $matches[1], $this->event_id, null, false, false, false, null, false); // export raw
                                        $join_val  = $recordData[1]['repeat_instances'][$this->event_id][$this->instrument][$this->repeat_instance][$matches[1]];
                                        if (preg_match("/".self::ACTION_TAG_DST."='?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/", $fieldDetails['field_annotation'], $matches)) {
                                               $filter  = "[" . $matches[1] ."]='" .$join_val."'";
                                        }
                                }

                                // pick up option for hiding instance col
                                $matches = array();
                                if (preg_match("/".self::ACTION_TAG_HIDEINSTANCECOL."\s?/", $fieldDetails['field_annotation'], $matches)) {
                                        $repeatingFormDetails['show_instance_col'] = false;
                                } else {
                                        $repeatingFormDetails['show_instance_col'] = true;
                                }

                                // pick up option for page size override
                                $matches = array();
                                if (preg_match("/".self::ACTION_TAG_PAGESIZE."\s*=\s*'?(-?\d+)'?\s?/", $fieldDetails['field_annotation'], $matches)) {
                                        $pageSize = intval($matches[1]);
                                        if ($pageSize < -1) {
                                            $pageSize = -1;
                                        }
                                        $repeatingFormDetails['page_size'] = $pageSize;
                                } else {
                                        $repeatingFormDetails['page_size'] = 0;
                                }

                                // pick up option for additional filter expression
                                $matches = array();
                                $outerQuote = array('"',"'"); // work for both @INSTANCETABLE_FILTER='[v]="1"' and @INSTANCETABLE_FILTER="[v]='1'" quote patterns
                                foreach ($outerQuote as $quoteChar) {
                                        if (preg_match("/".self::ACTION_TAG_FILTER."\s*=\s*$quoteChar(.+)$quoteChar/", $fieldDetails['field_annotation'], $matches)) {
                                                $addnlFilter = trim($matches[1]);
                                                $filter = (empty(trim($filter)))
                                                    ? $addnlFilter
                                                    : "($filter) and ($addnlFilter)";
                                        }
                                }
                                $filter = str_replace('<>','!=',$filter); // '<>' gets removed by REDCap::filterHtml()
                                $repeatingFormDetails['filter']=REDCap::filterHtml($filter);

                                // make column list for table: all form vars or supplied list, remove any with @INSTANCETABLE_HIDE
                                $repeatingFormFields = REDCap::getDataDictionary('array', false, null, $formName);
                                $includeVars = $requestedVars = $matches = array();
                                if (preg_match("/".self::ACTION_TAG_VARLIST."\s*=\s*'?([a-z][a-z0-9_]*(,[a-z][a-z0-9_]*)*)'?\s?/", $fieldDetails['field_annotation'], $matches)) {
                                        $requestedVars = explode(',',trim($matches[1]));
                                        $includeVars = array_intersect($requestedVars, array_keys($repeatingFormFields));
                                } else {
                                        $requestedVars = array();
                                        $includeVars = array_keys($repeatingFormFields);
                                }

                                foreach ($includeVars as $idx => $fieldName) {
                                        // remove descriptive text fields
                                        if ($repeatingFormFields[$fieldName]['field_type']==='descriptive') {
                                                unset($includeVars[$idx]);
                                        } else if (!in_array($fieldName, $requestedVars)){
                                                // ignore fields tagged @FORMINSTANCETABLE_HIDE - unless requested explicitly in varlist
                                                $matches = array();
                                                if (preg_match("/".self::ACTION_TAG_HIDE_FIELD."/", $repeatingFormFields[$fieldName]['field_annotation'])) {
                                                       unset($includeVars[$idx]);
                                                }
                                        }
                                }
                                reset($includeVars);
                                $repeatingFormDetails['var_list'] = $includeVars;

                                $ajaxUrl = $this->getUrl('instance_table_ajax.php');
                                $filter = htmlspecialchars(str_replace("'",self::REPLQUOTE_SINGLE,str_replace('"',self::REPLQUOTE_DOUBLE,$filter)), ENT_QUOTES);
                                $repeatingFormDetails['ajax_url'] = $ajaxUrl."&record={$this->record}&event_id=$eventId&form_name=$formName&filter=$filter&fields=".implode('|',$includeVars);
                                $repeatingFormDetails['markup'] = '';

                                if (preg_match("/".self::ACTION_TAG_SCROLLX."/", $fieldDetails['field_annotation'], $matches)) {
                                        $repeatingFormDetails['scroll_x'] = true;
                                } else {
                                        $repeatingFormDetails['scroll_x'] = false;
                                }

                                if (preg_match("/".self::ACTION_TAG_HIDEADDBTN."/", $fieldDetails['field_annotation'], $matches)) {
                                        $repeatingFormDetails['hide_add_btn'] = true;
                                } else {
                                        $repeatingFormDetails['hide_add_btn'] = false;
                                }

                                $this->taggedFields[] = $repeatingFormDetails;
                        }
                }
        }

        protected function checkIsRepeating() {
                foreach ($this->taggedFields as $key => $repeatingFormDetails) {
                        if (!$this->Proj->isRepeatingFormOrEvent($repeatingFormDetails['event_id'], $repeatingFormDetails['form_name'])) {
                                $repeatingFormDetails['permission_level'] = -1; // error
                        }
                        if ($this->Proj->isRepeatingEvent($this->event_id) && $this->event_id == $repeatingFormDetails['event_id']) {
                            $repeatingFormDetails['permission_level'] = -1; // error (current event is repeating, form in repeating event cannot be a repeating form)
                        }

                        $this->taggedFields[$key] = $repeatingFormDetails;
                }
        }

        protected function checkUserPermissions() {
                foreach ($this->taggedFields as $key => $repeatingFormDetails) {
                        if ($this->isSurvey) {
                                $repeatingFormDetails['permission_level'] = 2; // always read only in survey view
                        } else if ($repeatingFormDetails['hide_add_btn']) {
                                $repeatingFormDetails['permission_level'] = 2; // Hide "Add" button = "read only" (effectively!)
                        } else if ($repeatingFormDetails['permission_level'] > -1) {
                                switch ($this->user_rights['forms'][$repeatingFormDetails['form_name']]) {
                                        case '1': $repeatingFormDetails['permission_level'] = 1; break; // view/edit
                                        case '2': $repeatingFormDetails['permission_level'] = 2; break; // read only
                                        case '3': $repeatingFormDetails['permission_level'] = 1; break; // view/edit + edit survey responses
                                        case '0': $repeatingFormDetails['permission_level'] = 0; break; // no access
                                        default : $repeatingFormDetails['permission_level'] = -1; break;
                                }
                        }
                        $this->taggedFields[$key] = $repeatingFormDetails;
                }
        }

        protected function setMarkup() {
                foreach ($this->taggedFields as $key => $repeatingFormDetails) {
                        switch ($repeatingFormDetails['permission_level']) {
                                case 1: // view & edit
                                        $repeatingFormDetails['markup'] = $this->makeHtmlTable($repeatingFormDetails, true);
                                        break;
                                case '2': // read only
                                        $repeatingFormDetails['markup'] = $this->makeHtmlTable($repeatingFormDetails, false);
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

        protected function makeHtmlTable($repeatingFormDetails, $canEdit) {
                $tableElementId = $repeatingFormDetails['html_table_id'];
                $tableFormClass = $repeatingFormDetails['html_table_class'];
                $eventId = $repeatingFormDetails['event_id'];
                $formName = $repeatingFormDetails['form_name'];
                $scrollX = $repeatingFormDetails['scroll_x'];
                $linkField = $repeatingFormDetails['link_field'];
                $linkValue = $repeatingFormDetails['link_instance'];
                $filter = $repeatingFormDetails['filter']; // The filter actually contains linkfield=linkvalue
                $varList = $repeatingFormDetails['var_list'];

                $scrollStyle = ($scrollX) ? "display:block; max-width:790px;" : "";
                $nColumns = 1; // start at 1 for # (Instance) column
                $html = '<div class="" style="margin-top:10px; margin-bottom:'.self::ADD_NEW_BTN_YSHIFT.';">';
                $html .= '<table id="'.$tableElementId.'" class="table table-striped table-bordered table-condensed table-responsive '.self::MODULE_VARNAME.' '.$tableFormClass.'" width="100%" cellspacing="0" style="'.$scrollStyle.'">';
                $html .= '<thead><tr><th>#</th>'; // .$this->lang['data_entry_246'].'</th>'; // Instance

                $repeatingFormFields = REDCap::getDataDictionary('array', false, null, $formName);

                foreach ($varList as $var) {
                        $relabel = preg_match("/".self::ACTION_TAG_LABEL."='(.+)'/", $repeatingFormFields[$var]['field_annotation'], $matches);
                        $colHeader = ($relabel) ? $matches[1] : $repeatingFormFields[$var]['field_label'];
                        $html .= "<th>$colHeader</th>";
                        $nColumns++;
                }
                if (!$this->isSurvey) {
                        $html.='<th>Form Status</th>'; // "Form Status" wording is hardcoded in MetaData::save_metadata()
                        $nColumns++;
                }

                $html.='</tr></thead>';

                // if survey form get data on page load (as no add/edit and have no auth for an ajax call)
                if ($this->isSurvey) {
                        $instanceData = $this->getInstanceData($this->record, $eventId, $formName, $varList, $filter,
                            $linkField, $linkValue, false);
                        if (count($instanceData) > 0) {
                                $html.='<tbody>';
                                foreach ($instanceData as $rowValues) {
                                        $html.='<tr>';
                                        foreach ($rowValues as $value) {
                                                $value = str_replace('removed="window.open(','onclick="window.open(',REDCap::filterHtml($value)); // <button removed="window.open( ... >Download</button> file downloads
                                                $html.="<td>$value</td>";
                                        }
                                        $html.='</tr>';
                                }
                                $html.='</tbody>';
                        } else {
                                // $html.='<tr><td colspan="'.$nColumns.'">No data available in table</td></tr>';
                                // unnecessary and DT does not supprt colspan in body tr // https://datatables.net/forums/discussion/32575/uncaught-typeerror-cannot-set-property-dt-cellindex-of-undefined
                        }
                }

                $html.='</table>';

                if ($canEdit) {
                        $html.='<div style="position:relative;top:'.self::ADD_NEW_BTN_YSHIFT.';margin-bottom:5px;"><button type="button" class="btn btn-sm btn-success " onclick="'.self::MODULE_VARNAME.'.addNewInstance(\''.$this->record.'\','.$eventId.',\''.$formName.'\',\''.$linkField.'\',\''.$linkValue.'\');"><span class="fas fa-plus-circle" aria-hidden="true"></span>&nbsp;'.$this->lang['data_entry_247'].'</button></div>'; // Add new
                }
                return $html;
        }

        public function getInstanceData($record, $event, $form, $fields, $filter, $linkField,
                                        $linkValue, $includeFormStatus=true) {
                global $Proj, $lang, $user_rights;
                $this->Proj = $Proj;
                $this->lang = &$lang;
                $this->user_rights = &$user_rights;
                $this->isSurvey = (PAGE==='surveys/index.php');
                $instanceData = array();
                $filter = str_replace(self::REPLQUOTE_SINGLE,"'",str_replace(self::REPLQUOTE_DOUBLE,'"',$filter));

                // find any descriptive text fields tagged with @FORMINSTANCETABLE=form_name

                if (!$this->isSurvey) {
                    $this->setTaggedFields();
                }
                $this->checkUserPermissions();

                $hasPermissions = false;
                foreach($this->taggedFields as $fieldDetails) {
                    if($fieldDetails["form_name"] == $form && $fieldDetails["permission_level"] > 0) {
                        $hasPermissions = true;
                    }
                }

                $recordDag = $this->getDAG($record);
                if(!empty($this->user_rights["group_id"]) && $this->user_rights["group_id"] != $recordDag) {
                    $hasPermissions = false;
                }

                if(!$hasPermissions) {
                    return array();
                }

                $repeatingFormFields = REDCap::getDataDictionary('array', false, null, $form);

                // ignore any supplied fields not on the repeating form
                $fields = array_intersect($fields, array_keys($repeatingFormFields));

                if ($includeFormStatus) { $fields[] = $form.'_complete'; }

                $recordData = REDCap::getData('array', $record, $fields, $event, null, false, false, false, $filter, true); // export labels not raw

                $formKey = ($this->Proj->isRepeatingEvent($event))
                        ? ''     // repeating event - empty string key
                        : $form; // repeating form  - form name key

                if (!empty($recordData[$record]['repeat_instances'][$event][$formKey])) {
                        foreach ($recordData[$record]['repeat_instances'][$event][$formKey] as $instance => $instanceFieldData) {
                                $instance = \intval($instance);
                                $thisInstanceValues = array();
                                $thisInstanceValues[] = $this->makeInstanceNumDisplay($instance, $record, $event, $form, $instance, $linkField, $linkValue);

                                // foreach ($instanceFieldData as $fieldName => $value) {
                                foreach ($fields as $fieldName) { // loop through $fields not data array so cols can be in order specified in varlist
                                        $value = $instanceFieldData[$fieldName];
                                        if (is_string($value) && trim($value)==='') { // PHP 8 doesn't like trimming checkbox array!
                                                $thisInstanceValues[] = '';
                                                continue;
                                        }

                                        $fieldType = $repeatingFormFields[$fieldName]['field_type'];

                                        if ($fieldName===$form.'_complete') {
                                                if ($this->isSurvey) { continue; }
                                                $outValue = $this->makeFormStatusDisplay($value, $record, $event,
                                                    $form, $instance, $linkField, $linkValue);

                                        } else if (in_array($fieldType, array("advcheckbox", "radio", "select", "checkbox", "dropdown", "sql", "yesno", "truefalse"))) {
                                                $outValue = $this->makeChoiceDisplay($value, $repeatingFormFields, $fieldName);

                                        } else if ($fieldType==='text') {
                                                $ontologyOption = $this->Proj->metadata[$fieldName]['element_enum'];
                                                if ($ontologyOption!=='' && preg_match('/^\w+:\w+$/', $ontologyOption)) {
                                                        // ontology fields are text fields with an element enum like "BIOPORTAL:ICD10"
                                                        list($ontologyService, $ontologyCategory) = explode(':',$ontologyOption,2);
                                                        $outValue = $this->makeOntologyDisplay($value, $ontologyService, $ontologyCategory);
                                                } else {
                                                        // regular text fields have null element_enum
                                                        $outValue = $this->makeTextDisplay($value, $repeatingFormFields, $fieldName);
                                                }

                                        } else if ($fieldType==='notes') {
                                                $outValue = $this->makeTextDisplay($value, $repeatingFormFields, $fieldName);

                                        } else if ($fieldType==='file') {
                                                $outValue = $this->makeFileDisplay($value, $record, $event, $instance, $fieldName);

                                        } else {
                                                $outValue = htmlentities($value, ENT_QUOTES);
                                        }

                                        $thisInstanceValues[] = $outValue;
                                }

                                $instanceData[] = $thisInstanceValues;
                        }
                }
                return $instanceData;
        }

        protected function makeOpenPopupAnchor($val, $record, $event, $form, $instance, $linkField, $linkValue) {
                if ($this->isSurvey) {
                        return $val;
                }
                return '<a title="Open instance" href="javascript:;" onclick="'.self::MODULE_VARNAME.'.editInstance(\''.$record.'\','.$event.',\''.$form.'\','.$instance.',\''.$linkField.'\',\''.$linkValue.'\');">'.$val.'</a>';
        }

        protected function makeInstanceNumDisplay($val, $record, $event, $form, $instance, $linkField, $linkVal) {
                return $this->makeOpenPopupAnchor($val, $record, $event, $form, $instance, $linkField, $linkVal);
        }

        protected function makeFormStatusDisplay($val, $record, $event, $form, $instance, $linkField, $linkVal) {
                switch ($val) {
                    case '2':
                        $circle = '<img src="'.APP_PATH_IMAGES.'circle_green.png" style="height:16px;width:16px;">';
                        break;
                    case '1':
                        $circle = '<img src="'.APP_PATH_IMAGES.'circle_yellow.png" style="height:16px;width:16px;">';
                        break;
                    default:
                        $circle = '<img src="'.APP_PATH_IMAGES.'circle_red.png" style="height:16px;width:16px;">';
                        break;
                }
                return $this->makeOpenPopupAnchor($circle, $record, $event, $form, $instance, $linkField, $linkVal);
        }

        protected function makeChoiceDisplay($val, $repeatingFormFields, $fieldName) {
                if ($this->Proj->metadata[$fieldName]['element_type']==='sql') {
                        $choices = parseEnum(getSqlFieldEnum($this->Proj->metadata[$fieldName]['element_enum']));
                } else {
                        $choices = parseEnum($this->Proj->metadata[$fieldName]['element_enum']);
                }

                if (is_array($val)) {
                        foreach ($val as $valkey => $cbval) {
                                if ($cbval==='1') {
                                        $val[$valkey] = $this->makeChoiceDisplayHtml($valkey, $choices);
                                } else {
                                        unset($val[$valkey]);
                                }
                        }
                        $outValue = implode('<br>', $val); // multiple checkbox selections one per line
                } else {
                        $outValue = $this->makeChoiceDisplayHtml($val, $choices);
                }
                return REDCap::filterHtml($outValue);
        }

        protected function makeChoiceDisplayHtml($val, $choices) {
                if (array_key_exists($val, $choices)) {
                        return $choices[$val].' <span class="text-muted">('.$val.')</span>';
                }
                return $val;
        }

        protected function makeTextDisplay($val, $repeatingFormFields, $fieldName) {
                if (trim($val)=='') { return ''; }
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
                        $outVal = $val;
                        break;
                }
                return REDCap::filterHtml($outVal);
        }

        protected function makeFileDisplay($val, $record, $event_id, $instance, $fieldName) {
                $downloadDocUrl = APP_PATH_WEBROOT.'DataEntry/file_download.php?pid='.PROJECT_ID."&s=&record=$record&event_id=$event_id&instance=$instance&field_name=$fieldName&id=$val&doc_id_hash=".Files::docIdHash($val);
                $fileDlBtn = "<button class='btn btn-defaultrc btn-xs' style='font-size:8pt;' onclick=\"window.open('$downloadDocUrl','_blank');return false;\">{$this->lang['design_121']}</button>";
                return str_replace('removed=','onclick=',REDCap::filterHtml($fileDlBtn));
        }

        protected function makeOntologyDisplay($val, $service, $category) {
                $sql = "select label from redcap_web_service_cache where project_id=? and service=? and category=? and `value`=?";
                $q = $this->query($sql, [PROJECT_ID, $service, $category, $val]);
                $r = db_fetch_assoc($q);
                $cachedLabel = $r["label"];
                $ontDisplay = (is_null($cachedLabel) || $cachedLabel==='')
                        ? $val
                        : $cachedLabel.' <span class="text-muted">('.$val.')</span>';
                return REDCap::filterHtml($ontDisplay);
        }

        protected function insertJS() {
                global $lang;
                ?>
<style type="text/css">
    .<?php echo self::MODULE_VARNAME;?> tbody tr { font-weight:normal; }
    /*.greenhighlight {background-color: inherit !important; }*/
    /*.greenhighlight table td {background-color: inherit !important; }*/
</style>
<script type="text/javascript">
'use strict';
var <?php echo self::MODULE_VARNAME;?> = (function(window, document, $, app_path_webroot, pid, simpleDialog, undefined) { // var MCRI_InstanceTable ...
    var isSurvey = <?php echo ($this->isSurvey)?'true':'false';?>;
    var tableClass = '<?php echo self::MODULE_VARNAME;?>';
    var langYes = '<?php echo js_escape($this->lang['design_100']);?>';
    var langNo = '<?php echo js_escape($this->lang['design_99']);?>';
    var config = <?php echo json_encode($this->taggedFields, JSON_PRETTY_PRINT);?>;
    var taggedFieldNames = [];
    var lengthVal;
    var lengthLbl;
    var childInstances = []; // keeps instance ids of each table, keyed by tagged field name

    function init() {
        config.forEach(function(taggedField) {
            taggedFieldNames.push(taggedField.field_name);
            $('#'+taggedField.field_name+'-tr td:last')
                    .append(taggedField.markup);
            switch(taggedField.page_size) {
                case 0:
                    lengthVal = [10, 25, 50, 100, -1];
                    lengthLbl = [10, 25, 50, 100, "<?=$lang['docs_44']?>"]; // "ALL"
                    break;
                case -1:
                    lengthVal = [-1];
                    lengthLbl = ["<?=$lang['docs_44']?>"]; // "ALL"
                    break;
                default:
                    lengthVal = lengthLbl = [taggedField.page_size];
            }
            var thisTbl = $('#'+taggedField.html_table_id)
                    .DataTable( {
                        "stateSave": true,
                        "stateDuration": 0,
                        "lengthMenu": [lengthVal, lengthLbl]
                    } );
            if (!taggedField.show_instance_col) {
                thisTbl.column( 0 ).visible( false );
            }
            if (!isSurvey) {
              var ref = ""
              if (taggedField.link_field != "") {
                ref= "&link_field=" + taggedField.link_field + "&link_instance=" + taggedField.link_instance;
              }
              thisTbl.ajax.url(taggedField.ajax_url + ref).load();
              const regex = new RegExp('>([0-9]+)<');
              thisTbl.on( 'xhr', function () {
                childInstances[taggedField.link_field] = thisTbl.ajax.json().data.map(row => regex.exec(row[0])[1]);
              } );
            }
        });

        // override global function doGreenHighlight() so we can skip the descriptive text fields with tables
        var globalDoGreenHighlight = doGreenHighlight;
        doGreenHighlight = function(rowob) {
            if ( $.inArray(rowob.attr('sq_id'), taggedFieldNames) === -1) {
                globalDoGreenHighlight(rowob);
            }
        };
    }

    $(document).ready(function() {
        init();
    });

    function instancePopup(title, record, event, form, instance, linkField, linkIns) {
      const linkParams = (linkField=='') ? '':'&link_field='+linkField+'&link_instance='+linkIns;
      const childParams = (linkField !== '' && childInstances[linkField])
        ? "&children=" +  childInstances[linkField] : ""
      //console.log("childParams  " + childParams, childInstances)
      var url = app_path_webroot + 'DataEntry/index.php?pid=' + pid + '&id=' + record + '&event_id=' + event +
          '&page=' + form + '&instance=' + instance + '&extmod_instance_table=1' + linkParams + childParams;
        popupWindow(url,title,window,700,950);
          //refreshTableDialog(event, form);
        return false;
    }

    function popupWindow(url, title, win, w, h) {
        var y = win.top.outerHeight / 2 + win.top.screenY - (h / 2);
        var x = win.top.outerWidth / 2 + win.top.screenX - (w / 2);
        return win.open(url, title, 'status,scrollbars,resizable,width='+w+',height='+h+',top='+y+',left='+x);
    }

    function refreshTableDialog() {
        simpleDialog('Refresh the table contents and display any changes (instances added, updated or deleted).'
            ,'Refresh Table?',null,500
            ,null,langNo
            ,function() {
                // refresh all instance tables (e.g. to pick up changes to multiple forms across repeating event
                $('.'+tableClass).each(function() {
                    $(this).DataTable().ajax.reload( null, false ); // don't reset user paging on reload
                });
            },langYes
        );
    }

    return {
        addNewInstance: function(record, event, form, linkFld, linkIns) {
            instancePopup('Add instance', record, event, form, '1&extmod_instance_table_add_new=1', linkFld, linkIns);
            return false;
        },
        editInstance: function(record, event, form, instance, linkFld, linkIns) {
          // adding link params in edit url, otherwise, if "new instance" is clicked from popup, it loses the link
          // connection
          instancePopup('View instance', record, event, form, instance, linkFld, linkIns);
            return false;
        }
    }
      })(window, document, jQuery, app_path_webroot, pid, simpleDialog);

    function refreshTables() {
        // refresh immediately , just in case the server has already processed the save/delete call
        actuallyRefreshTables();
        // now start a timer and try it again after a second, to give the server ample time to persist the data
        window.setTimeout( actuallyRefreshTables(), 1500);
    }

    function actuallyRefreshTables() {
        var tableClass = '<?php echo self::MODULE_VARNAME;?>';
        $('.'+tableClass).each(function() {
            $(this).DataTable().ajax.reload( null, false ); // don't reset user paging on reload
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
        protected function popupViewTweaks() {
            global $lang, $longitudinal, $Proj, $user_rights;

            $record = \htmlspecialchars($_GET['id'], ENT_QUOTES);
            $delFormAlertMsg = ($longitudinal) ? RCView::tt("data_entry_243") : RCView::tt("data_entry_239");
			if (isset($Proj->forms[$_GET['page']]['survey_id']) && $user_rights['forms'][$_GET['page']] == '3' && isset($_GET['editresp'])) {
				$delFormAlertMsg .= RCView::div(array('style'=>'margin-top:15px;color:#C00000;'), RCView::tt("data_entry_241"));
			}
            $delFormAlertMsg .= RCView::div(array('style'=>'margin-top:15px;color:#C00000;'), RCView::tt_i("data_entry_559", array($_GET['instance'])));
			$delFormAlertMsg .= RCView::div(array('style'=>'margin-top:15px;color:#C00000;font-weight:bold;'), RCView::tt("data_entry_190"));
            $delFormAlertMsg = js_escape($delFormAlertMsg, true);

            ?>
<style type="text/css">
    .navbar-toggler, #west, #formSaveTip, #dataEntryTopOptionsButtons, #formtop-div { display: none !important; }
    /*div[aria-describedby="reqPopup"] > .ui-dialog-buttonpane > button { color:red !important; visibility: hidden; }*/
    #submit-btn-savenextform, #submit-btn-saveexitrecord, #submit-btn-savenextrecord { display: none; }
</style>
<script type="text/javascript">
    /* EM Instance Table JS */
    $.urlParamReplace = function (url, name, value) {
        var results = new RegExp('[\?&]' + name + '=([^&#]*)').exec(url);
        if (results !== null) {
            return url.replace(name + '=' + results[1], name + '=' + value);
        } else {
            return url;
        }
    }

    $.urlGetParam = function (param_name) {
        var results = new RegExp('[\?&]' + param_name + '=([^&#]*)').exec(window.location.href);
        if (results !== null) {
            return results[1];
        } else {
            return null;
        }
    }

    $.redirectUrl = function(url) {
        window.location.href = url;
        return;
    }

    $(document).ready(function() {
        // add this window.unload for added reliability in invoking refreshTables on close of popup
        window.onunload = function(){window.opener.refreshTables();};
        $('#form').attr('action',$('#form').attr('action')+'&extmod_instance_table=1');

        // set default value of ref field, if supplied
        var linkField = $.urlGetParam('link_field');
        if (linkField!==null) {
            var linkInput = $('[name='+linkField+']');
            if (linkInput.length) {
                $(linkInput).val($.urlGetParam('link_instance'));
            }
        }

        // changes to save/cancel/delete buttons in popup window
        $('button[name=submit-btn-saverecord]')// Save & Exit Form (here means save and close the popup)
            .removeAttr('onclick')
            .click(function(event) {
                $('#form').append('<input type="hidden" name="extmod_closerec_home" value="<?=$record?>">');
                event.preventDefault();
                window.opener.refreshTables();
                dataEntrySubmit(this);
            });
        $('#submit-btn-savenextinstance')// Save & Next Instance (not necessarily a new instance)
            .attr('name', 'submit-btn-savenextinstance')
            .removeAttr('onclick')
            .click(function(event) {
              const currentUrl = window.location.href;
              // this replace is currently causing issues in production redcap
              // but not sure why
              //var redirectUrl = $.urlParamReplace(currentUrl, "instance", 1+(1*$.urlGetParam("instance")));
              const nextInstance = 1+(parseInt($.urlGetParam("instance")));

              // if the next instance is not in the list of known instance ids then it is new.
              // don't want to go to the child instance of a different instance
              // NOTE: this is a change in behavior.  Previously, "nextInstance"
              // would move to the next child instance of a different instance
              const children = $.urlGetParam("children").split(",");
              let redirectUrl = ""
              if (children.includes("" + nextInstance)) {
                redirectUrl = $.urlParamReplace(currentUrl, "instance", String(nextInstance));
              } else {
                redirectUrl = $.urlParamReplace(currentUrl, "instance", "1&extmod_instance_table_add_new=1");
              }
                event.preventDefault();
                window.opener.refreshTables();
                dataEntrySubmit(this);
                window.setTimeout($.redirectUrl, 500, redirectUrl);
            });
        $('button[name=submit-btn-cancel]')
            .removeAttr('onclick')
            .click(function() {
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
            .click(function(event) {
                $('#form').append('<input type="hidden" name="extmod_closerec_home" value="<?=$record?>">');
                simpleDialog(
                    '<div style="margin:10px 0;font-size:13px;"><?=$delFormAlertMsg?></div>',
                    '<?=$lang['data_entry_237']?> \"<?=$record?>\"<?=$lang['questionmark']?>'
                    ,null,600,null,lang.global_53,
                    function(){
                        event.preventDefault();
                        window.opener.refreshTables();
                        dataEntrySubmit( 'submit-btn-deleteform' );
                    },
                    '<?=$lang['data_entry_234']?>' //'Delete data for THIS FORM only'
                );
                return false;
            });
                    <?php
                    }
                    if (isset($_GET['__reqmsg'])) {
                    ?>
                setTimeout(function() {
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
         * redcap_every_page_before_render
         * - When doing Save & Exit or Delete Instance from popup then persist a flag on the session so can close popup on record home page.
         * - If adding a new instance, read the current max instance and redirect to a form with instance value current + 1
         * - Augment the action_tag_explain content on project Design pages by adding some additional tr following the last built-in action tag.
         * @param type $project_id
         */
      public function redcap_every_page_before_render($project_id) {
                if (isset($_POST['extmod_closerec_home'])) {
                        $_SESSION['extmod_closerec_home'] = $_POST['extmod_closerec_home'];

                } else if (PAGE==='DataEntry/index.php' && isset($_GET['extmod_instance_table']) && isset($_GET['extmod_instance_table_add_new']) && !is_null($project_id) && ($_GET['event_id']) !== null) {
                        global $Proj;
                        $this->Proj = $Proj;
                        $this->isSurvey = false;
                        // adding new instance - read current max and redirect to + 1
                        $formKey = ($this->Proj->isRepeatingEvent($_GET['event_id']))
                                ? ''             // repeating event - empty string key
                                : $_GET['page']; // r epeating form  - form name key

                        $recordData = REDCap::getData('array',$_GET['id'],$_GET['page'].'_complete',$_GET['event_id']);

                        if (array_key_exists($_GET['id'],$recordData) &&
                            array_key_exists('repeat_instances',$recordData[$_GET['id']]) &&
                            array_key_exists($_GET['event_id'], $recordData[$_GET['id']]['repeat_instances'])) {
                                $currentInstances = array_keys($recordData[$_GET['id']]['repeat_instances'][$_GET['event_id']][$formKey]);
                                $_GET['instance'] = (is_null($currentInstances)) ? 1 : 1 + end($currentInstances);
                        } else {
                                $_GET['instance'] = 1;
                        }

                } else if (PAGE==='Design/action_tag_explain.php') {
                        $lastActionTagDesc = end(Form::getActionTags());

                        // which $lang element is this?
                        $langElement = array_search($lastActionTagDesc, $this->lang);

                        $lastActionTagDesc .= "</td></tr>";
                        $lastActionTagDesc .= $this->makeTagTR(static::ACTION_TAG, static::ACTION_TAG_DESC);

                        $this->lang[$langElement] = rtrim(rtrim(rtrim(trim($lastActionTagDesc), '</tr>')),'</td>');
                }
        }
}
