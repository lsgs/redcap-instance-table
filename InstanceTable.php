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
        const ACTION_TAG_HIDE_FIELD = '@INSTANCETABLE[-_]HIDE';
        const ACTION_TAG_LABEL = '@INSTANCETABLE[-_]LABEL';
        const ACTION_TAG_SCROLLX = '@INSTANCETABLE[-_]SCROLLX';
        const ACTION_TAG_HIDEADDBTN = '@INSTANCETABLE[-_]HIDEADD'; // i.e. hide "Add" button even if user has edit access to form
        const ACTION_TAG_HIDEINSTANCECOL = '@INSTANCETABLE[-_]HIDEINSTANCECOL'; // i.e. hide the "#" column containing instance numbers
        const ACTION_TAG_VARLIST = '@INSTANCETABLE[-_]VARLIST'; // provide a comma-separated list of variables to include (not including any tagged HIDE)
        const ACTION_TAG_PAGESIZE = '@INSTANCETABLE[-_]PAGESIZE'; // Override default choices for page sizing: specify integer default page size, use -1 for All
        const ACTION_TAG_REF = '@INSTANCETABLE[-_]REF';
        const ACTION_TAG_SRC = '@INSTANCETABLE[-_]SRC'; // deprecated
        const ACTION_TAG_DST = '@INSTANCETABLE[-_]DST'; // deprecated
        const ACTION_TAG_FILTER = '@INSTANCETABLE[-_]FILTER';
        const ACTION_TAG_ADDBTNLABEL = '@INSTANCETABLE[-_]ADDBTNLABEL';
        const ACTION_TAG_HIDECHOICEVALUES = '@INSTANCETABLE[-_]HIDECHOICEVALUES';
        const ACTION_TAG_HIDEFORMSTATUS = '@INSTANCETABLE[-_]HIDEFORMSTATUS';
        const ACTION_TAG_HIDEFORMINMENU = '@INSTANCETABLE[-_]HIDEFORMINMENU';
        const ACTION_TAG_SORTCOL = '@INSTANCETABLE[-_]SORTCOL'; // Specify custom sort order, e.g. '@INSTANCETABLE_ORDER=2:asc' for column 2 ascending
        const ACTION_TAG_PREFILL = '@INSTANCETABLE[-_]PREFILL';
        const ADD_NEW_BTN_YSHIFT = '0px';
        const MODULE_VARNAME = 'MCRI_InstanceTable';

        const ERROR_NOT_REPEATING_CLASSIC = '<div class="red">ERROR: "%s" is not a repeating form. Contact the project designer.';
        const ERROR_NOT_REPEATING_LONG = '<div class="red">ERROR: "%s" is not a repeating form for event "%s". Contact the project designer.';
        const ERROR_NO_VIEW_ACCESS = '<div class="yellow">You do not have permission to view this form\'s data.';
        const REPLQUOTE_SINGLE = 'REPLQUOTE_SINGLE';
        const REPLQUOTE_DOUBLE = 'REPLQUOTE_DOUBLE';

        /**
         * redcap_every_page_top
         * - When doing Save & Exit or Delete Instance from popup then persist a flag on the session.
         *   If Record Home page is loading when this flag is present then window.close() 
         * - Field prefilling on DE forms - detect fieldname=? in $_GET (note this mechanism is NOT limited to the popup)
         * @param type $project_id
         */
        public function redcap_every_page_top($project_id) {
                if (PAGE==='DataEntry/record_home.php' && isset($_GET['id']) && isset($_SESSION['extmod_instance_table_closerec_home']) && $_GET['id']==$_SESSION['extmod_instance_table_closerec_home']) {
                        ?>
                        <script type="text/javascript">/* EM Instance Table */ window.close();</script>
                        <?php

                } else if (PAGE==='DataEntry/index.php' && isset($_GET['id']) && isset($_SESSION['extmod_instance_table_popup_save']) && $_GET['id']==$_SESSION['extmod_instance_table_popup_save']) {
                        ?>
                        <script type="text/javascript">/* EM Instance Table */ window.location.href = window.location.href+'&extmod_instance_table=1';</script>
                        <?php
                }
                unset($_SESSION['extmod_instance_table_closerec_home']);
                unset($_SESSION['extmod_instance_table_popup_save']);
                
                if (PAGE==='DataEntry/index.php' && isset($_GET['id']) && isset($_GET['page'])) {
                        global $Proj;
                        $allowPrefill = $this->getProjectSetting('allow-prefill');
                        if (empty($allowPrefill) && !isset($_GET['extmod_instance_table'])) return; 
                        if (!isset($Proj->forms[$this->escape($_GET['page'])]['fields'])) return; // do nothing if $_GET['page'] not valid
                        foreach (array_keys($Proj->forms[$this->escape($_GET['page'])]['fields']) as $formField) {
                                if (isset($_GET[$formField]) && $_GET[$formField]!='') {
                                        $Proj->metadata[$formField]['misc'] .= " @SETVALUE='".$this->escape(urldecode($_GET[$formField]))."'";
                                }
                        }
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

        public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
            $this->initHook($record, $instrument, $event_id, false, $group_id, $repeat_instance);
            if ($action == "get-data") {
                $event = intval($payload["event_id"]);
                $form = $this->framework->escape($payload["form_name"]);
                $fields = $this->framework->escape($payload["fields"]);
                $filter = $payload["filter"];
                $hideChoiceValues = (bool)$payload["hide_vals"];
                $hideFormStatus = (bool)$payload["hide_form_status"];
                $data = $this->getInstanceData($record, $event, $form, $fields, $filter, $this->instrument, !$hideFormStatus, $hideChoiceValues);
                return $data;
            }
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
                
                foreach ($instrumentFields as $instrumentField => $fieldDetails) {
                        $matches = array();
                        // /@INSTANCETABLE='?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/
                        // asdf@INSTANCETABLE='eee_arm_1:fff_fff' asdf
                        // Full match	4-39	@INSTANCETABLE='eee_arm_1:fff_fff'
                        // Group 1.	20-37	eee_arm_1:fff_fff
                        // Group 2.	20-30	eee_arm_1:
                        if ($fieldDetails['field_type']!=='descriptive') continue;
                        
                        $fieldDetails['field_annotation'] = \Form::replaceIfActionTag($fieldDetails['field_annotation'], $this->Proj->project_id, $this->record, $this->event_id, $this->instrument, $this->instance);
            
                        if (preg_match("/".self::ACTION_TAG."\s*=\s*'?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/", $fieldDetails['field_annotation'], $matches)) {

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
                                $repeatingFormDetails['field_name'] = $instrumentField;
                                $repeatingFormDetails['event_id'] = $eventId;
                                $repeatingFormDetails['event_name'] = $eventName;
                                $repeatingFormDetails['form_name'] = $formName;
                                $repeatingFormDetails['permission_level'] = "no-access";
                                $repeatingFormDetails['form_fields'] = array();
                                $repeatingFormDetails['html_table_id'] = self::MODULE_VARNAME.'_'.$instrumentField.'_tbl_'.$eventName.'_'.$formName;
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
                                        $recordData = REDCap::getData([
                                            'return_format' => 'array',
                                            'records' => $this->record,
                                            'fields' => $matches[1],
                                            'events' => $this->event_id,
                                            'exportAsLabels' => false, // export raw
                                        ]);
                                        $join_val  = $recordData[1]['repeat_instances'][$this->event_id][$this->instrument][$this->repeat_instance][$matches[1]];
                                        if (preg_match("/".self::ACTION_TAG_DST."='?((\w+_arm_\d+[a-z]?:)?\w+)'?\s?/", $fieldDetails['field_annotation'], $matches)) {
                                                $filter  = $this->escape("[" . $matches[1] ."]='" .$join_val."'");
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

                                if (preg_match("/".self::ACTION_TAG_SCROLLX."/", $fieldDetails['field_annotation'])) {
                                        $repeatingFormDetails['scroll_x'] = true;
                                } else {
                                        $repeatingFormDetails['scroll_x'] = false;
                                }

                                if (preg_match("/".self::ACTION_TAG_HIDEADDBTN."/", $fieldDetails['field_annotation'])) {
                                        $repeatingFormDetails['hide_add_btn'] = true;
                                } else {
                                        $repeatingFormDetails['hide_add_btn'] = false;
                                }

                                $matches = array();
                                if (preg_match("/".self::ACTION_TAG_ADDBTNLABEL."\s*=\'([^\']+)\'/", $fieldDetails['field_annotation'], $matches)) {
                                    $repeatingFormDetails['button_label'] = REDCap::filterHtml($matches[1]);
                                } else {
                                    $repeatingFormDetails['button_label'] = '';
                                }

                                if (preg_match("/".self::ACTION_TAG_HIDECHOICEVALUES."/", $fieldDetails['field_annotation'])) {
                                    $hideVals = $repeatingFormDetails['hide_choice_values'] = true;
                                } else {
                                    $hideVals = $repeatingFormDetails['hide_choice_values'] = false;
                                }

                                if ($this->isSurvey || preg_match("/".self::ACTION_TAG_HIDEFORMSTATUS."/", $fieldDetails['field_annotation'])) {
                                    $hideStatus = $repeatingFormDetails['hide_form_status'] = true;
                                } else {
                                    $hideStatus = $repeatingFormDetails['hide_form_status'] = false;
                                }

                                if (!$this->isSurvey && preg_match("/".self::ACTION_TAG_HIDEFORMINMENU."/", $fieldDetails['field_annotation'])) {
                                    $repeatingFormDetails['hide_form_in_menu'] = true;
                                } else {
                                    $repeatingFormDetails['hide_form_in_menu'] = false;
                                }

                                // pick up option for custom sort order
                                $matches = array();
                                if (preg_match("/".self::ACTION_TAG_SORTCOL."\s*=\s*[\"']?(\d+)(:asc|:desc)?[\"']?\s?/i", $fieldDetails['field_annotation'], $matches)) {
                                    $sortColumn = intval($matches[1]);
                                    $sortDirection = (array_key_exists(2, $matches)) ? strtolower($matches[2]) : null;
                                    $repeatingFormDetails['sort_column'] = ($sortColumn>1) ? $sortColumn-1 : 0; // use 1-based indexing in config, DT uses 0-based indexes for column refs
                                    $repeatingFormDetails['sort_direction'] = ($sortDirection==':desc') ? 'desc' : 'asc';
                                } else {
                                    $repeatingFormDetails['sort_column'] = 1;
                                    $repeatingFormDetails['sort_direction'] = 'asc';
                                }

                                // pick up option for field prefilling in new instances
                                $matches = $prefillNewFields = array();
                                $outerQuote = array('"',"'"); // work for both @INSTANCETABLE-PREFILL='pf1="[v]"' and @INSTANCETABLE-PREFILL="pf1='[v]'" quote patterns 
                                foreach ($outerQuote as $quoteChar) {
                                        if (preg_match_all("/".self::ACTION_TAG_PREFILL."\s*=\s*$quoteChar(.+)$quoteChar/", $fieldDetails['field_annotation'], $matches)) {
                                                foreach ($matches[1] as $match) { // match 0 is full string with tag, match 1 is each tag's param - can have multiple @INSTANCETABLE-PREFILL
                                                        $prefillNewFields[] = $this->escape(trim($match));
                                                }
                                        }
                                }
                                if (!empty($prefillNewFields)) {
                                        $labelContent='<div class="'.self::MODULE_VARNAME.'-prefill-container">';
                                        foreach ($prefillNewFields as $prefill) {
                                                $labelContent .= '<div class="'.self::MODULE_VARNAME.'-prefill">'.$prefill.'</div>';
                                        }
                                        $labelContent .= '</div>';
                                        // write prefill info into desc field's label so will get piping receivers around varnames
                                        $this->Proj->metadata[$instrumentField]['element_label'] .= $labelContent;
                                }
                                // $repeatingFormDetails['prefill_fields'] = $prefillNewFields; // no need to do this
                                
                                $repeatingFormDetails["ajax"] = [
                                    "event_id" => $eventId,
                                    "form_name" => $formName,
                                    "filter" => $filter,
                                    "fields" => $includeVars,
                                    'hide_vals' => $hideVals,
                                    'hide_form_status' => $hideStatus
                                ];
                                $repeatingFormDetails['markup'] = '';

                                $this->taggedFields[] = $repeatingFormDetails;
                        }
                }
        }

        protected function checkIsRepeating() {
                foreach ($this->taggedFields as $key => $repeatingFormDetails) {
                        if (!$this->Proj->isRepeatingFormOrEvent($repeatingFormDetails['event_id'], $repeatingFormDetails['form_name'])) {
                                $repeatingFormDetails['permission_level'] = "error";
                        }
                        $this->taggedFields[$key] = $repeatingFormDetails;
                }
        }
        
        protected function checkUserPermissions() {
                foreach ($this->taggedFields as $key => $repeatingFormDetails) {
                        if ($this->isSurvey) {
                                $repeatingFormDetails['permission_level'] = "read-only"; // always read only in survey view
                        } else if ($repeatingFormDetails['hide_add_btn']) {
                                $repeatingFormDetails['permission_level'] = "read-only"; // Hide "Add" button = "read only" (effectively!)
                        } else {
                                $permissionLevel = $this->user_rights['forms'][$repeatingFormDetails['form_name']];
                                if ($this->hasDataViewingRights($permissionLevel, "view-edit")) {
                                    $repeatingFormDetails['permission_level'] = "view-edit";
                                }
                                else if ($this->hasDataViewingRights($permissionLevel, "read-only")) {
                                    $repeatingFormDetails['permission_level'] = "read-only";
                                }
                                else if ($this->hasDataViewingRights($permissionLevel, "no-access")) {
                                    $repeatingFormDetails['permission_level'] = "no-access";
                                }
                                else {
                                    $repeatingFormDetails['permission_level'] = "error";
                                }
                        }
                        $this->taggedFields[$key] = $repeatingFormDetails;
                }
        }
        
        protected function hasDataViewingRights($permissionLevel, $permission) {
            if (method_exists('\UserRights', 'hasDataViewingRights')) {
                return call_user_func('\UserRights::hasDataViewingRights',$permissionLevel, $permission);
            } else {
                if ($permission=='view-edit' && ($permissionLevel==1 || $permissionLevel==3)) return true;
                if ($permission=='read-only' && ($permissionLevel==1 || $permissionLevel==3)) return true;
                switch ($permission) {
                    case 'view-edit'; if ($permissionLevel==1 || $permissionLevel==3) return true; break;
                    case 'read-only'; if ($permissionLevel==2) return true; break;
                    case 'no-access'; if ($permissionLevel==0) return true; break;
                    default : return 'error';
                }
            }
        }

        protected function setMarkup() {
                foreach ($this->taggedFields as $key => $repeatingFormDetails) {
                        switch ($repeatingFormDetails['permission_level']) {
                                case "view-edit":
                                        $repeatingFormDetails['markup'] = $this->makeHtmlTable($repeatingFormDetails, true);
                                        break;
                                case "read-only":
                                        $repeatingFormDetails['markup'] = $this->makeHtmlTable($repeatingFormDetails, false);
                                        break;
                                case "no-access":
                                        $repeatingFormDetails['markup'] = self::ERROR_NO_VIEW_ACCESS;
                                        break;
                                default: // error
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
                $instrumentField = $repeatingFormDetails['field_name'];
                $tableElementId = $repeatingFormDetails['html_table_id'];
                $tableFormClass = $repeatingFormDetails['html_table_class'];
                $eventId = $repeatingFormDetails['event_id'];
                $formName = $repeatingFormDetails['form_name'];
                $scrollX = $repeatingFormDetails['scroll_x'];
                $linkField = $repeatingFormDetails['link_field'];
                $linkValue = $repeatingFormDetails['link_instance'];
                $filter = $repeatingFormDetails['filter']; // The filter actually contains linkfield=linkvalue
                $varList = $repeatingFormDetails['var_list'];
                $btnLabel = ($repeatingFormDetails['button_label']=='') ? $this->lang['data_entry_247'] : $repeatingFormDetails['button_label'];
                $hideChoiceValues = $repeatingFormDetails['hide_choice_values'];
                $hideFormStatus = $repeatingFormDetails['hide_form_status'];

                $scrollStyle = ($scrollX) ? "display:block; max-width:790px;" : "";
                $nColumns = 1; // start at 1 for # (Instance) column
                $html = '<div class="" style="margin-top:10px; margin-bottom:'.self::ADD_NEW_BTN_YSHIFT.';">';
                $html .= '<table id="'.$tableElementId.'" class="table table-striped table-bordered table-condensed table-responsive '.self::MODULE_VARNAME.' '.$tableFormClass.'" width="100%" cellspacing="0" style="'.$scrollStyle.'">';
                $html .= '<thead><tr><th><span class="th-instance">#</span></th>'; // .$this->lang['data_entry_246'].'</th>'; // Instance
                
                $repeatingFormFields = REDCap::getDataDictionary('array', false, null, $formName);

                foreach ($varList as $var) {
                        $relabel = preg_match("/".self::ACTION_TAG_LABEL."='(.+)'/", $repeatingFormFields[$var]['field_annotation'], $matches);
                        $colHeader = ($relabel) ? $matches[1] : $repeatingFormFields[$var]['field_label'];

                        $colHeader = \Piping::replaceVariablesInLabel(
                            $colHeader,
                            $this->record,
                            $this->event_id,
                            $this->instance
                        );

                        $html .= "<th><span class=\"th-field\">$colHeader</span></th>";
                        $nColumns++;
                }
                if (!$hideFormStatus) {
                        $html.='<th><span class="th-status">Form Status</span></th>'; // "Form Status" wording is hardcoded in MetaData::save_metadata()
                        $nColumns++;
                }
                
                $html.='</tr></thead>';

                // if survey form get data on page load (as no add/edit and have no auth for an ajax call)
                if ($this->isSurvey) {
                        $instanceData = $this->getInstanceData($this->record, $eventId, $formName, $varList, $filter, $this->instrument, false, $hideChoiceValues);
                        if (count($instanceData) > 0) {
                                $html.='<tbody>';
                                foreach ($instanceData as $rowValues) {
                                        $html.='<tr>';
                                        foreach ($rowValues as $value) {
                                                $value = $this->escape($value);
                                                if (is_array($value)) {
                                                    $sort = (array_key_exists('sort',$value)) ? " data-sort='{$value['sort']}" : '';
                                                    $filter = (array_key_exists('filter',$value)) ? " data-filter='{$value['filter']}" : '';
                                                    if (array_key_exists('display',$value)) {
                                                        $display = $value['display'];
                                                    } else if (array_key_exists('_',$value)) {
                                                        $display = $value['_'];
                                                    } else {
                                                        $display = $value($value[array_key_first($value)]);
                                                    }
                                                    $html.="<td $sort $filter>$display</td>";
                                                } else {
                                                    $value = str_replace('removed="window.open(','onclick="window.open(',REDCap::filterHtml($value)); // <button removed="window.open( ... >Download</button> file downloads
                                                    $html.="<td>$value</td>";
                                                }
                                        }
                                        $html.='</tr>';
                                }
                                $html.='</tbody>';
                        } else {
                                // $html.='<tr><td colspan="'.$nColumns.'">No data available in table</td></tr>';
                                // unnecessary and DT does not support colspan in body tr // https://datatables.net/forums/discussion/32575/uncaught-typeerror-cannot-set-property-dt-cellindex-of-undefined
                        }
                }

                $html.='</table>';

                if ($canEdit) {
                        $disabled = (\Records::recordExists($this->Proj->project_id, $this->record)) ? '' : 'disabled="disabled" title="Record not yet saved"';
                        $html.='<div style="position:relative;top:'.self::ADD_NEW_BTN_YSHIFT.';margin-bottom:5px;"><button '.$disabled.' type="button" class="btn btn-sm btn-success " onclick="'.self::MODULE_VARNAME.'.addNewInstance(\''.$this->record.'\','.$eventId.',\''.$formName.'\',\''.$instrumentField.'\',\''.$linkField.'\',\''.$linkValue.'\');"><span class="fas fa-plus-circle mr-1" aria-hidden="true"></span>'.$btnLabel.'</button></div>'; // Add new
                }
                return $html;
        }
        
        public function getInstanceData($record, $event, $form, $fields, $filter, $formViewContext, $includeFormStatus=true, $hideChoiceValues=false) {
                global $Proj, $lang, $user_rights;
                $this->Proj = $Proj;
                $this->lang = &$lang;
                $this->user_rights = &$user_rights;
                $this->isSurvey = (PAGE==='surveys/index.php');
                $instanceData = array();
	
                // find any descriptive text fields tagged with @FORMINSTANCETABLE=form_name
                
                if (!$this->isSurvey) {
                    $this->setTaggedFields();
                }                
                $this->checkUserPermissions();
                
                $hasPermissions = false;
                foreach($this->taggedFields as $fieldDetails) {
                    if($fieldDetails["form_name"] == $form && in_array($fieldDetails["permission_level"], ["view-edit", "read-only"], true)) {
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

                if ($form!=$formViewContext && !empty($filter)) {
                    // if instance table of a repeating form/event is being viewed on a (different) repeating form/event 
                    // then replace any references to fields from the current view context in the logic expression with their current values
                    // this prevents [current-instance] being automatically added by LogicTester::preformatLogicEventInstanceSmartVariables()
                    // and enables the filter to pick up instances of another form with values that match something in the current repeating context
                    $filter = $this->replaceRepeatingContextValuesInLogicWithValues($filter);                                                
                }

                $recordData = REDCap::getData([
                    'returnFormat' => 'array',
                    'records' => [$record],
                    'fields' => $fields,
                    'events' => $event,
                    'filterLogic' => $filter,
                    'exportAsLabels' => true, // export labels not raw
                ]);

                $formKey = ($this->Proj->isRepeatingEvent($event))
                        ? ''     // repeating event - empty string key
                        : $form; // repeating form  - form name key
                        
                if (!empty($recordData[$record]['repeat_instances'][$event][$formKey])) {
                        foreach ($recordData[$record]['repeat_instances'][$event][$formKey] as $instance => $instanceFieldData) {
                                $instance = \intval($instance);
                                $thisInstanceValues = array();
                                $thisInstanceValues[] = $this->makeInstanceNumDisplay($instance, $record, $event, $form, $instance);

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
                                                $outValue = $this->makeFormStatusDisplay($value, $record, $event, $form, $instance);
                                                
                                        } else if (in_array($fieldType, array("advcheckbox", "radio", "select", "checkbox", "dropdown", "sql", "yesno", "truefalse"))) {
                                                $outValue = $this->makeChoiceDisplay($value, $repeatingFormFields, $fieldName, $hideChoiceValues);

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
        
        protected function makeOpenPopupAnchor($val, $record, $event, $form, $instance, $outline=false) {
                if ($this->isSurvey) {
                        return $val;
                }
                $class = ($outline) ? 'class="btn btn-xs btn-outline-secondary"' : '';
                return '<a '.$class.' title="Open instance" href="javascript:;" onclick="'.self::MODULE_VARNAME.'.editInstance(\''.$record.'\','.$event.',\''.$form.'\','.$instance.');">'.$val.'</a>';
        }
        
        protected function makeInstanceNumDisplay($val, $record, $event, $form, $instance) {
                return $this->makeOpenPopupAnchor($val, $record, $event, $form, $instance, true);
        }
        
        protected function makeFormStatusDisplay($val, $record, $event, $form, $instance) {
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
                return $this->makeOpenPopupAnchor($circle, $record, $event, $form, $instance);
        }
        
        protected function makeChoiceDisplay($val, $repeatingFormFields, $fieldName, $hideChoiceValues=false) {
                if ($this->Proj->metadata[$fieldName]['element_type']==='sql') {
                        $choices = parseEnum(getSqlFieldEnum($this->Proj->metadata[$fieldName]['element_enum']));
                } else {
                        $choices = parseEnum($this->Proj->metadata[$fieldName]['element_enum']);
                }
                
                if (is_array($val)) {
                        foreach ($val as $valkey => $cbval) {
                                if ($cbval==='1') {
                                        $val[$valkey] = $this->makeChoiceDisplayHtml($valkey, $choices, $hideChoiceValues);
                                } else {
                                        unset($val[$valkey]);
                                }
                        }
                        $outValue = implode('<br>', $val); // multiple checkbox selections one per line
                } else {
                        $outValue = $this->makeChoiceDisplayHtml($val, $choices, $hideChoiceValues);
                }
                return REDCap::filterHtml($outValue);
        }
        
        protected function makeChoiceDisplayHtml($val, $choices, $hideChoiceValues=false) {
                if (array_key_exists($val, $choices)) {
                        $valDisplay = ($hideChoiceValues) ? '' : ' <span class="text-muted">('.$val.')</span>';
                        return $choices[$val].$valDisplay;
                }
                return $val;
        }

        protected function makeTextDisplay($val, $repeatingFormFields, $fieldName) {
                if (trim($val)=='') { return ''; }
                $val = REDCap::filterHtml($val);
                $valType = $repeatingFormFields[$fieldName]['text_validation_type_or_show_slider_number'];
                switch ($valType) {
                    case 'date_mdy':
                    case 'date_dmy':
                    case 'datetime_mdy':
                    case 'datetime_dmy':
                    case 'datetime_seconds_mdy':
                    case 'datetime_seconds_dmy':
                        $displayVal = DateTimeRC::datetimeConvert($val, 'ymd', substr($valType, -3)); // reformat raw ymd date/datetime value to mdy or dmy, if appropriate
                        $outVal = array('_'=>$val, 'sort'=>$val, 'filter'=>$displayVal, 'display'=>$displayVal);
                        break;
                    case 'email':
                        $outVal = "<a href='mailto:$val'>$val</a>";
                        break;
                    default:
                        $outVal = $val;
                        break;
                }
                return $outVal;
        }

        protected function makeFileDisplay($val, $record, $event_id, $instance, $fieldName) {
                if ($this->isSurvey) {
                        $surveyHash = REDCap::filterHtml($_GET['s']);
                        $downloadDocUrl = APP_PATH_SURVEY . "index.php?pid=".PROJECT_ID."&__passthru=".urlencode("DataEntry/file_download.php")."&doc_id_hash=".Files::docIdHash($val)."&id=$val&s=$surveyHash&record=$record&event_id=$event_id&field_name=$fieldName&instance=$instance";
                } else {
                        $downloadDocUrl = APP_PATH_WEBROOT.'DataEntry/file_download.php?pid='.PROJECT_ID."&s=&record=$record&event_id=$event_id&instance=$instance&field_name=$fieldName&id=$val&doc_id_hash=".Files::docIdHash($val);
                }
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
                $this->framework->initializeJavascriptModuleObject();
                $jsmo_name = $this->framework->getJavascriptModuleObjectName();
                ?>
<style type="text/css">
    .<?php echo self::MODULE_VARNAME;?> tbody tr { font-weight:normal; }
    .<?php echo self::MODULE_VARNAME;?>-prefill-container { display:none; }
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
    var JSMO = <?=$jsmo_name?>;

    function init() {
        // console.log('Instance Table', config);
        config.forEach(function(taggedField) {
            taggedFieldNames.push(taggedField.field_name);
            $('#'+taggedField.field_name+'-tr td:last')
                    .append(taggedField.markup);
            switch(taggedField.page_size) {
                case 0:
                    taggedField.lengthVal = [10, 25, 50, 100, -1];
                    taggedField.lengthLbl = [10, 25, 50, 100, "<?=$lang['docs_44']?>"]; // "ALL"
                    taggedField.lengthChange = true;
                    break;
                case -1:
                    taggedField.lengthVal = [-1];
                    taggedField.lengthLbl = ["<?=$lang['docs_44']?>"]; // "ALL"
                    taggedField.lengthChange = false;
                    break;
                default:
                    taggedField.lengthVal = [taggedField.page_size];
                    taggedField.lengthChange = false;
            } 
            var thisTbl;
            if (isSurvey) {
                thisTbl = $('#'+taggedField.html_table_id)
                    .DataTable( {
                        "stateSave": true,
                        "stateDuration": 0,
                        "lengthMenu": [taggedField.lengthVal, taggedField.lengthLbl],
                        "lengthChange": taggedField.lengthChange,
                        "order": [[taggedField.sort_column, taggedField.sort_direction]],
                        "columnDefs": [{
                            "render": function (data, type, row) {
                                let val = data;
                                if ($.isPlainObject(data)) {
                                    if (data.hasOwnProperty(type)) { // e.g. sort, filter for dates
                                        val = data[type];
                                    }
                                }
                                return val;
                            },
                            "targets": "_all"
                        }]
                    } );
                if (!taggedField.show_instance_col) {
                    thisTbl.column( 0 ).visible( false );
                }
                if (taggedField.page_size!==0) {
                    thisTbl.page.len(taggedField.page_size).draw();
                }
            }
            else {
                if (taggedField.hide_form_in_menu) {
                    $('#data-collection-menu').find('a[id*='+taggedField.form_name+']').parent('div.formMenuList').hide()
                }

                JSMO.ajax('get-data', taggedField.ajax).then(function(data) {
                    thisTbl = $('#'+taggedField.html_table_id)
                        .DataTable( {
                            "stateSave": true,
                            "stateDuration": 0,
                            "lengthMenu": [taggedField.lengthVal, taggedField.lengthLbl],
                            "lengthChange": taggedField.lengthChange,
                            "order": [[taggedField.sort_column, taggedField.sort_direction]],
                            "columnDefs": [{
                                "render": function (data, type, row) {
                                    let val = data;
                                    if ($.isPlainObject(data)) {
                                        if (data.hasOwnProperty(type)) { // e.g. sort, filter for dates
                                            val = data[type];
                                        }
                                    }
                                    return val;
                                },
                                "targets": "_all"
                            }],
                            "data": data
                        } );
                    if (!taggedField.show_instance_col) {
                        thisTbl.column( 0 ).visible( false );
                    }
                    if (taggedField.page_size!==0) {
                        thisTbl.page.len(taggedField.page_size).draw();
                    }
                });
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

    function instancePopup(title, record, event, form, instance) {
        var url = app_path_webroot+'DataEntry/index.php?pid='+pid+'&id='+record+'&event_id='+event+'&page='+form+'&instance='+instance+'&extmod_instance_table=1';
        popupWindow(url,title,window,850,window.outerHeight * .8);
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
                    performTableRefresh(this);
                });
            },langYes
        );
    }

    function getPrefillParams(tblFld) {
        console.log(tblFld);
        var prefill = '';
        $('tr[sq_id='+tblFld+']').find('div.MCRI_InstanceTable-prefill-container').find('div.MCRI_InstanceTable-prefill').each(function(i,elem){
            var thisPF = $(elem).html().split(/=(.+)/s);
            var stripPipingReceivers = thisPF[1].replace(/<span class="piping_receiver piperec-(\d)+-([\w-])+">(.*)<\/span>/,'$3');
            prefill += '&'+thisPF[0]+'='+encodeURIComponent(decodeHTMLEntities(stripPipingReceivers));
        });
        return prefill;
    }

    function decodeHTMLEntities(text) {
        const textArea = document.createElement('textarea');
        textArea.innerHTML = text;
        return textArea.value;
    }

    return {
        addNewInstance: function(record, event, form, tblFld, linkFld, linkIns) {
            var ref = (linkFld=='')?'':'&link_field='+linkFld+'&link_instance='+linkIns;
            var prefill = getPrefillParams(tblFld);
            instancePopup('Add instance', record, event, form, '1&extmod_instance_table_add_new=1'+ref+prefill);
            return false;
        },
        editInstance: function(record, event, form, instance) {
            instancePopup('View instance', record, event, form, instance);
            return false;
        },
        getConfig: function() {
            return config;
        },
        getJSMO: function() {
            return JSMO;
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
            performTableRefresh(this);
        });
    }

    function performTableRefresh(tbl) {
        var id = tbl.id;
        var config = <?=self::MODULE_VARNAME?>.getConfig();
        var jsmo = <?=self::MODULE_VARNAME?>.getJSMO();
        var it = config.filter(function(x) { return x.html_table_id == id; })[0];
        jsmo.ajax('get-data', it.ajax).then(function(data) {
            var dt = $(tbl).DataTable();
            dt.clear();
            dt.rows.add(data);
            dt.draw();
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

            $record = $this->escape($_GET['id']);
            $delFormAlertMsg = ($longitudinal) ? RCView::tt("data_entry_243") : RCView::tt("data_entry_239");
			if (isset($Proj->forms[$_GET['page']]['survey_id']) && $user_rights['forms'][$_GET['page']] == '3' && isset($_GET['editresp'])) {
				$delFormAlertMsg .= RCView::div(array('style'=>'margin-top:15px;color:#C00000;'), RCView::tt("data_entry_241"));
            }
            $delFormAlertMsg .= RCView::div(array('style'=>'margin-top:15px;color:#C00000;'), RCView::tt_i("data_entry_559", array($_GET['instance'])));
            $delFormAlertMsg .= RCView::div(array('style'=>'margin-top:15px;color:#C00000;font-weight:bold;'), RCView::tt("data_entry_190"));
            $delFormAlertMsg = js_escape($delFormAlertMsg, true);

            $this->initializeJavascriptModuleObject();
            ?>
<style type="text/css">
    .navbar-toggler, #west, #formSaveTip, #dataEntryTopOptionsButtons, #formtop-div { display: none !important; }
    /*div[aria-describedby="reqPopup"] > .ui-dialog-buttonpane > button { color:red !important; visibility: hidden; }*/
    #submit-btn-savenextform, #submit-btn-saveexitrecord, #submit-btn-savenextrecord { display: none; }
</style>
<script type="text/javascript">
    /* EM Instance Table JS */
    $(function(){
        let module = <?=$this->getJavascriptModuleObjectName()?>;

        module.addingNewInstance = <?=(isset($_GET['extmod_instance_table_add_new'])) ? 1 : 0?>;
        module.showRequiredMessage = <?=(isset($_GET['__reqmsg'])) ? 1 : 0?>;
        module.urlParamReplace = function (url, name, value) {
            var results = new RegExp('[\?&]' + name + '=([^&#]*)').exec(url);
            if (results !== null) {
                return url.replace(name + '=' + results[1], name + '=' + value);
            } else {
                return url;
            }
        };
        module.urlGetParam = function (param_name) {
            var results = new RegExp('[\?&]' + param_name + '=([^&#]*)').exec(window.location.href);
            if (results !== null) {
                return results[1];
            } else {
                return null;
            }
        };
        module.redirectUrl = function(url) {
            window.location.href = url;
            return;
        };
        module.init = function() {
            // add this window.unload for added reliability in invoking refreshTables on close of popup
            window.onunload = function(){window.opener.refreshTables();};
            $('#form').attr('action',$('#form').attr('action')+'&extmod_instance_table=1');
            $('#form').append('<input type="hidden" name="extmod_instance_table_closerec_home" value="<?=$record?>">');
            $('#form').append('<input type="hidden" name="extmod_instance_table_popup_save" value="<?=$record?>">>');

            // suppress display of header links to other instances
            let currentDisplayDiv = $('#inviteFollowupSurveyBtn > div:first');
            $(currentDisplayDiv).find('span:first').insertBefore(currentDisplayDiv);
            $(currentDisplayDiv).hide();

            // set default value of ref field, if supplied
            var linkField = module.urlGetParam('link_field');
            if (linkField!==null) {
                var linkInput = $('[name='+linkField+']');
                if (linkInput.length) {
                    $(linkInput).val(module.urlGetParam('link_instance'));
                }
            }

            // changes to save/cancel/delete buttons in popup window
            $('button[name=submit-btn-saverecord]')// Save & Exit Form (here means save and close the popup)
                .removeAttr('onclick')
                .click(function(event) {
                    event.preventDefault();
                    window.opener.refreshTables();
                    dataEntrySubmit(this);
                });

            $('#submit-btn-savecontinue') // Save & Stay
                .attr('name', 'submit-btn-savecontinue')
                .removeAttr('onclick')
                .click(function(event) {
                    event.preventDefault();
                    window.opener.refreshTables();
                    dataEntrySubmit(this);
                });

            $('#submit-btn-savenextinstance')// Save & Next Instance (not necessarily a new instance)
                .attr('name', 'submit-btn-savenextinstance')
                .removeAttr('onclick')
                .click(function(event) {
                    event.preventDefault();
                    window.opener.refreshTables();
                    dataEntrySubmit(this);
                });

            $('button[name=submit-btn-cancel]')
                .removeAttr('onclick')
                .click(function() {
                    window.opener.refreshTables();
                    window.close();
                });
                
            // add &extmod_instance_table=1 to link in onclick of "Edit response" button where instance is a survey response to maintain the "in popup" settings
            var editSurveyBtn = $('#edit-response-btn');
            if (editSurveyBtn.length) {
                $(editSurveyBtn)
                    .removeAttr('onclick')
                    .click(function() {
                        window.location.href = app_path_webroot+'DataEntry/index.php?pid='+getParameterByName('pid')+'&page='+getParameterByName('page')+'&id='+getParameterByName('id')+'&event_id='+event_id+(getParameterByName('instance')==''?'':'&instance='+getParameterByName('instance'))+'&editresp=1&extmod_instance_table=1';
                        return false;
                    });
            }

            if (module.addingNewInstance) {
                $('#__DELETEBUTTONS__-div').css("display", "none");
            } else {
                $('button[name=submit-btn-deleteform]')
                    .removeAttr('onclick')
                    .click(function(event) {
                        simpleDialog(
                            '<div style="margin:10px 0;font-size:13px;"><?=$delFormAlertMsg?></div>',
                            '<?=$lang['data_entry_237']?> \"<?=$record?>\"<?=\RCView::tt('questionmark')?>'
                            ,null,600,null,lang.global_53,
                            function(){
                                event.preventDefault();
                                window.opener.refreshTables();
                                dataEntrySubmit( 'submit-btn-deleteform' );
                            },
                            '<?=\RCView::tt('data_entry_234','')?>' //'Delete data for THIS FORM only'
                        );
                        return false;
                    });
            }
            if (module.showRequiredMessage) {
                $('body').on('dialogopen', function(event) {
                    if(event.target.id=='reqPopup') {
                        $('div[aria-describedby="reqPopup"]').find('div.ui-dialog-buttonpane').find('button').not(':last').hide(); // .css('visibility', 'visible'); // required fields message show only "OK" button, not ignore & leave
                    }
                });
            }
        };

        $(document).ready(function(){
            module.init();
        });
    });
</script>
            <?php
        }

        /**
         * redcap_every_page_before_render
         * - When saving an instance in the popup, preserve the info that we're in the instance table popup so can render new form in this view.
         * - When doing Save & Exit or Delete Instance from popup then persist a flag on the session so can close popup on record home page.
         * - If adding a new instance, read the current max instance and redirect to a form with instance value current + 1 (can't just use &new due to internal redirect)
         * @param type $project_id
         */
        public function redcap_every_page_before_render($project_id) {
            if (isset($_POST['extmod_instance_table_popup_save'])) {
                $_SESSION['extmod_instance_table_popup_save'] = $_POST['extmod_instance_table_popup_save'];
            }
            if (isset($_POST['extmod_instance_table_closerec_home'])) {
                $_SESSION['extmod_instance_table_closerec_home'] = $_POST['extmod_instance_table_closerec_home'];
            }
            if (PAGE==='DataEntry/index.php' && isset($_GET['extmod_instance_table']) && isset($_GET['extmod_instance_table_add_new']) && !is_null($project_id) && isset(($_GET['event_id']))) {
                global $Proj;
                $this->Proj = $Proj;
                $this->isSurvey = false;
                // adding new instance - read current max and redirect to + 1
                $formKey = ($this->Proj->isRepeatingEvent($_GET['event_id']))
                        ? ''             // repeating event - empty string key
                        : $_GET['page']; // repeating form  - form name key

                $recordData = REDCap::getData([
                    'return_format' => 'array',
                    'records' => $_GET['id'],
                    'forms' => $_GET['page'].'_complete',
                    'events' => $_GET['event_id'],
                ]);

                if (array_key_exists($_GET['id'],$recordData) &&
                        array_key_exists('repeat_instances',$recordData[$_GET['id']]) &&
                        array_key_exists($_GET['event_id'], $recordData[$_GET['id']]['repeat_instances']) &&
                        array_key_exists($formKey, $recordData[$_GET['id']]['repeat_instances'][$_GET['event_id']]) ) {
                    $currentInstances = array_keys($recordData[$_GET['id']]['repeat_instances'][$_GET['event_id']][$formKey]);
                    $_GET['instance'] = (is_null($currentInstances)) ? 1 : 1 + end($currentInstances);
                } else {
                    $_GET['instance'] = 1;
                }
            }
        }

        /**
         * replaceRepeatingContextValuesInLogicWithValues
         * if instance table of a repeating form/event is being viewed on a (different) repeating form/event 
         * then replace any references to fields from the current view context in the logic expression with their current values
         * this prevents [current-instance] being automatically added by LogicTester::preformatLogicEventInstanceSmartVariables()
         * and enables the filter to pick up instances of another form with values that match something in the current repeating context
         * @param string $filter
         * @return string
         */
        protected function replaceRepeatingContextValuesInLogicWithValues(string $filter): string {
            $inRepeatingEvent = $this->Proj->isRepeatingEvent($this->event_id);
            $inRepeatingForm = $this->Proj->isRepeatingForm($this->event_id, $this->instrument);

            // if context for instance table is not repeating then leave filter unchanged
            if (!$inRepeatingEvent && !$inRepeatingForm) return $filter;

            // if filter contains no references to fields in the current repeating event/form context leave filter unchanged
            $repeatingFields = array();
            if ($inRepeatingForm) {
                $rptFormKey = $this->instrument;
                $repeatingFields = array_keys($this->Proj->forms[$this->instrument]['fields']);
            } else if ($inRepeatingEvent) {
                $rptFormKey = '';
                foreach ($this->Proj->eventsForms[$this->event_id] as $evt => $frm) {
                    $repeatingFields = array_merge($repeatingFields, array_keys($this->Proj->forms[$this->instrument]['fields']));
                }
            }

            $currentEventName = (\REDCap::isLongitudinal()) ? \REDCap::getEventNames(true, false, $this->event_id) : '';
            $currentContextData = \REDCap::getData([
                'return_format' => 'array',
                'records' => $this->record,
                'fields' => $repeatingFields,
                'events' => $this->event_id,
            ]);

            foreach ($repeatingFields as $rf) {
                if (!str_contains($filter, "[$rf]")) continue; // field not used in logic - ignore
                $currentContextValue = $currentContextData[$this->record]['repeat_instances'][$this->event_id][$rptFormKey][$this->repeat_instance][$rf] ?? '';
                $replaceValue = ($currentContextValue!=='' && (starts_with($this->Proj->metadata[$rf]['text_validation_type'], 'integer') || starts_with($this->Proj->metadata[$rf]['text_validation_type'], 'number'))) 
                    ? "$currentContextValue"
                    : "'$currentContextValue'";
                
                $replacePatterns = array();
                if (\REDCap::isLongitudinal()) {
                    $replacePatterns[] = "/\[$currentEventName\]\[$rf\](?:\[current-instance\])?/";
                    $replacePatterns[] = "/\[current-event\]\[$rf\](?:\[current-instance\])?/";
                } else {
                    $replacePatterns[] = "/\[$rf\](?:\[current-instance\])?/";
                }

                $filter = preg_replace($replacePatterns, $replaceValue, $filter);
            }

            return $filter;
        }
}
