<?php
/**
 * Instance Table External Module
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\InstanceTable\InstanceTable)) { exit(); }
header("Content-Type: application/json");
//{ "data": [...] }
echo json_encode(array('data' => $module->getInstanceData($_GET['record'], $_GET['event_id'], $_GET['form_name'])));