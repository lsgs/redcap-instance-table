# REDCap External Module: Instance Table

Use the action tag @INSTANCETABLE=form_name in a descriptive text field to include a table showing data from repeat instances of that form.

If project is longitudinal, use @INSTANCETABLE=event_name:form_name to specify the event and form (can be a repeating form or a form in a repeating event). Event defaults to current event if not specified.

* Add or Edit instances in popup window (View only if user has read-only permission for the repeating form).
* Option to hide fields from table using @INSTANCETABLE_HIDE tag on the repeating field.
* Option to specify column headings using @INSTANCETABLE_LABEL='label' tag (default is field label).
* Default behaviour is for a wide table to cause its container to grow. Use @INSTANCETABLE_SCROLLX to get a horizontal scroll-bar instead.
* Uses DataTables to facilitate sorting, search and paging within the table of instance data.
* Does not show table for users with no read permission to the repeating form.
* Displays in both regular data entry and survey forms.
* Survey view is read only with no links to the form instances.
* Adds an entry for @INSTANCE table into the Action Tags dialog on Project Setup and Online Designer pages.

## Example 
This example shows (on the right-hand side) a form containing three descriptive text fields utilising the @INSTANCETABLE action tag. 
* The first is tagged @INSTANCETABLE=nonschedule_arm_1:contact_log and hennce displays a table of data from the "Contact Log" form in the "Nonschedule" event".
* The second is tagged @INSTANCETABLE=unscheduled_arm_1:visit_data_page_1 and hence displays a table of data from the "Visit Data Page 1" form from the repeating "Unscheduled event.
* The third is tagged @INSTANCETABLE=unscheduled_arm_1:visit_data_page_2 and hence displays a table of data from the "Visit Data Page 1" form from the repeating "Unscheduled event.

![@INSTANCETABLE example](./instancetable.png)
