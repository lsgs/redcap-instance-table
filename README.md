# REDCap External Module: Instance Table

Luke Stevens, Murdoch Children's Research Institute https://www.mcri.edu.au

[https://github.com/lsgs/redcap-instance-table](https://github.com/lsgs/redcap-instance-table)
********************************************************************************
## Summary

Use the action tag `@INSTANCETABLE=form_name` in a descriptive text field to include a table showing data from repeat instances of that form.

If project is longitudinal, use `@INSTANCETABLE=event_name:form_name` to specify the event and form (can be a repeating form or a form in a repeating event). Event defaults to current event if not specified.

* Add or Edit instances in popup window (View only if user has read-only permission for the repeating form).
* Uses DataTables to facilitate sorting, search and paging within the table of instance data.
* Does not show table for users with no read permission to the repeating form.
* Displays in both regular data entry and survey forms.
* Survey view is read only with no links to the form instances.
* Adds an entries for `@INSTANCETABLE` and the supplementary tags into the Action Tags dialog on Project Setup and Online Designer pages.

### Note on Instance Tables in Survey Mode
As noted above, instance tables in survey forms do not contain links to the instances (which may or may not be a survey form), nor an "Add New" button. This behaviour can nevertheless be implemented where desired utilising some HTML, and smart variables.

#### "Add New" Button
Include an "Add New" button using a descriptive text field containing an HTML button along these lines:
```
<a class="btn btn-success text-decoration-none" href="[survey-url:my_repeating_survey][new-instance]"><i class="fas fa-plus"></i> Add New</a>
```
#### Links to Edit Specific Survey Instances
If you wish to provide buttons that enable access to individual instances of a repeating surveys for editing, then include a `@CALCTEXT` field in your repeating survey that generates the HTML for an "Edit" button. In this example, the instance has an "Edit" button up until it has been completed, at which point the button changes to just a check mark to indicate completion:
```
@CALCTEXT( 
  if(
    [my_repeating_survey_complete][current-instance]='2', 
    '<i class="fas fa-check"></i>', 
    concat( 
      '<a class="btn btn-primaryrc btn-xs" style="color:#fff" href="', 
      [survey-url:my_repeating_survey][current-instance], 
      '"><i class="fas fa-edit mx-1"></i></a>'
    )
  )
)
```

## Configuration
The vast majority of this module's functionality is provided via the action tags described below. There is only one project-level setting:
- Allow pre-filling of fields via URL parameters in data entry mode (as for surveys)



## Additional Action Tags
### Tags Used Alongside @INSTANCETABLE
* `@INSTANCETABLE-SCROLLX`: Default behaviour is for a wide table to cause its container to grow. Use this tag to get a horizontal scroll-bar instead.
* `@INSTANCETABLE-HIDEADD`: Suppress the "Add New" button.
* `@INSTANCETABLE-PAGESIZE`: Override default choices for page sizing: specify integer default page size, or -1 for "All".
* `@INSTANCETABLE-HIDEINSTANCECOL`: Hide the "#" column containing instance numbers.
* `@INSTANCETABLE-VARLIST=rptfrmvar3,rptfrmvar1,rptfrmvar6,rptfrm_complete`: Include only the variables from the repeating form that appear in the comma-separated list. Also (from v1.5.1) can be used to set the order of columns in the table rather than using the order of fields from the form. (An alternative to using `@INSTANCETABLE-HIDE` for repeating form variables. Takes precedence over `@INSTANCETABLE-HIDE`  where both used from v1.5.1.)
* `@INSTANCETABLE-REF=fieldname`: Where you have an instance table on a repeating form - i.e. is referencing another repeating form - you can have the instances filtered to show only those where the current instance number is saved in a field on the other form.<br>For example, an instance table in a repeating "Visit" form may be configured to show only instances of the repeating "Medication" form where the current Visit instance is selected in the `visitref` field on the Medication form: `@INSTANCETABLE @INSTANCETABLE-REF=visitref`.<br>Note that if you use `@INSTANCETABLE-REF` for an instance table on a non-repeating form the filter will default to `<ref field>=1`.<br>New instances created by clicking the "Add New" button below the instance table will have the current visit instance pre-selected.
* `@INSTANCETABLE-FILTER='[v]="1"'`: Specify a logic expression to show only instances that match the filter expression. 
* `@INSTANCETABLE-ADDBTNLABEL='Button Label'`: Specify an alternative label for the "Add New" button.
* `@INSTANCETABLE-HIDECHOICEVALUES`: Suppress the display of choice field values and show only choice labels.
* `@INSTANCETABLE-HIDEFORMSTATUS`: Suppress display of the form status field in data entry view. (The form status field is always suppressed in survey mode.)
* `@INSTANCETABLE-HIDEFORMINMENU`: Hide the link to the repeating form in the Data Collection section of the project page menu.
* `@INSTANCETABLE-SORTCOL=n[:direction]`: Specify the column index for the default table sort. The index number should be a positive integer, with <code>1</code> being the first column (i.e. instance number, which is always present even if hidden using `@INSTANCETABLE-HIDEINSTANCECOL`). Direction is optional and can be `asc` (default) or `desc` (case-insensitive) for ascending or descending respectively. The default sort in the absence of this tag is on the first column in ascending order, i.e. `@INSTANCETABLE-SORTCOL=1:asc`. Note that your browser will remember any custom sorting that you apply, therefore this setting only applies a default sort when you first view an instance table.
* `@INSTANCETABLE-PREFILL=rptformvar=[pagevar]`: Have fields on new instances pre-filled with data from the current form (or elsewhere on the record) using `fieldname=value` pairs in the URL in a manner similar to survey form field pre-filling. 

### Tags Used for Fields on a Repeating Form 
* `@INSTANCETABLE-HIDE`: Ignore this field in instance all tables.
* `@INSTANCETABLE-LABEL='column header'`: Provide an alternative column title for the field in all instance tables.

### Note on Tag Form: `-` vs. `_`
The preferred form of these action tags changed in v1.11.0 of the module from containing `_` to containing `-` to give better rendering in the Online Designer. The change is backward-compatible and either form may be utilised, e.g. either `@INSTANCETABLE-HIDEADD` or `@INSTANCETABLE_HIDEADD` may be used to hide the "Add New button.

### Notes on Data Entry Form Field Pre-filling
* If the tag parameter contains piping expressions then values will be piped live - the form containing the instance table need not be saved first.
* Multiple fields can be pre-filled either by specifying multiple `@INSTANCETABLE-PREFILL` tags or by specifying a query string-form argument, e.g. `@INSTANCETABLE-PREFILL='v1=1&v2=2'`.
* The data entry form field pre-filling capability need not be limited to fields referenced in `@INSTANCETABLE-PREFILL` tags, but can be enabled for **_all fields and forms project-wide_** using the project-level setting "_Allow pre-filling of fields via URL parameters in data entry mode_".
* When the "_Allow pre-filling_" option is enabled in a project, adding `fieldname=value` pairs to the URL of an empty data entry form will have the fields prefilled with the specified values as if `fieldname` has the action tag `@DEFAULT='value'`. As with survey field pre-filling, this works for unsaved forms only.

## Example 
This example shows (on the right-hand side) a form containing three descriptive text fields utilising the `@INSTANCETABLE` action tag. 
* The first is tagged `@INSTANCETABLE=nonschedule_arm_1:contact_log` and hence displays a table of data from the "Contact Log" form in the "Nonschedule" event".
* The second is tagged `@INSTANCETABLE=unscheduled_arm_1:visit_data_page_1` and hence displays a table of data from the "Visit Data Page 1" form from the repeating "Unscheduled event.
* The third is tagged `@INSTANCETABLE=unscheduled_arm_1:visit_data_page_2` and hence displays a table of data from the "Visit Data Page 2" form from the repeating "Unscheduled event.

![@INSTANCETABLE example](./instancetable.png)
