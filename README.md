# REDCap External Module: Instance Table

Use the action tag @INSTANCETABLE=form_name in a descriptive text field to include a table showing data from repeat instances of that form.

If project is longitudinal, use @INSTANCETABLE=event_name:form_name to specify the event and form (can be a repeating form or a form in a repeating event).

* Add or Edit instances in popup window (View only if user has read-only permission for the repeating form).
* Option to hide fields from table using @INSTANCETABLE_HIDE tag on the repeating field.
* Option to specify column headings using @INSTANCETABLE_LABEL='label' tag (default is field label).
* Uses DataTables to facilitate sorting, search and paging within the table of instance data.
* Does not show table for users with no read permission to the repeating form.
* Displays in both regular data entry and survey forms.
* Survey view is read only with no links to the form instances.
