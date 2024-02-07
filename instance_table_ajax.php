<?php
/**
 * Instance Table External Module
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\InstanceTable\InstanceTable)) { exit(); }
$record = htmlspecialchars($_GET['record'], ENT_QUOTES);
$event = htmlspecialchars($_GET['event_id'], ENT_QUOTES);
$form = htmlspecialchars($_GET['form_name'], ENT_QUOTES);
$fields = explode('|',htmlspecialchars($_GET['fields'], ENT_QUOTES));
$filter = htmlspecialchars($_GET['filter'], ENT_QUOTES);
$link_field = htmlspecialchars($_GET['link_field'], ENT_QUOTES);
$link_instance = htmlspecialchars($_GET['link_instance'], ENT_QUOTES);
header("Content-Type: application/json");
echo json_encode(array('data' => $module->getInstanceData($record, $event, $form, $fields, $filter, $link_field,
    $link_instance)));
