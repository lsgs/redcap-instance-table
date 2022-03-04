// @ts-check
(function () {

    function urlParamReplace(url, name, value) {
        var results = new RegExp('[\?&]' + name + '=([^&#]*)')
            .exec(url);
        if (results !== null) {
            return url.replace(name + '=' + results[1], name + '=' + value);
        } else {
            return url;
        }
    }

    function urlParamValue(url, name) {
        var results = new RegExp('[\?&]' + name + '=([^&#]*)')
            .exec(url);
        if (results !== null) {
            return results[1];
        }
        return null;
    }

    function urlRedirect(url) {
        window.location.href = url;
        return;
    }

    $(function () {
        // add this window.unload for added reliability in invoking refreshTables on close of popup
        window.onunload = function () {
            window.opener.refreshTables();
        };

        $('#form').attr('action', $('#form').attr('action') + '&extmod_instance_table=1');
        $('button[id=submit-btn-saverecord]')// Save & Close
            .attr('name', 'submit-btn-savecontinue')
            .removeAttr('onclick')
            .on('click', function(event) {
                // @ts-ignore - from REDCap
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
            .html('§DATA_ENTRY_275')
            .on('click', function (event) {
                var currentUrl = window.location.href;
                // @ts-ignore -- from REDCap
                dataEntrySubmit(this);
                event.preventDefault();
                window.opener.refreshTables();
                var redirectUrl = urlParamReplace(currentUrl, "instance", 1)
                    + '&extmod_instance_table_add_new=1';
                window.setTimeout(urlRedirect, 500, redirectUrl);
            });
        $('#submit-btn-savenextinstance')
            // Save and Next Instance -- go to next instance of same parent
            // default redcap behavior goes to next instance, regardless of parent.
            .attr('name', 'submit-btn-savecontinue')
            .removeAttr('onclick')
            .html('§DATA_ENTRY_276')
            .on('click', function (event) {
                var currentUrl = window.location.href;
                // @ts-ignore -- from REDCap
                dataEntrySubmit(this);
                event.preventDefault();
                window.opener.refreshTables();
                var next = urlParamValue(currentUrl, "next_instance");
                var redirectUrl = currentUrl;
                if (next == null) {
                    redirectUrl = currentUrl + '&next_instance=1';
                } else {
                    redirectUrl = urlParamReplace(redirectUrl, "next_instance", parseInt(next) + 1);
                }
                window.setTimeout(urlRedirect, 500, redirectUrl);
            });
        $('#submit-btn-saveexitrecord').css("display", "none");
        $('#submit-btn-savenextrecord').css("display", "none");
        $('button[name=submit-btn-cancel]')
            .removeAttr('onclick')
            .on('click', function () {
                window.opener.refreshTables();
                window.close();
            });
        //@ts-ignore - Injection
        if ('§ADD_NEW' == 'true') {
            $('#__DELETEBUTTONS__-tr').remove();
        }
        else {
            $('button[name=submit-btn-deleteform]')
                .removeAttr('onclick')
                .on('click', function (event) {
                    // @ts-ignore
                    simpleDialog('<div style="margin:10px 0;font-size:13px;">§DATA_ENTRY_239<div style=&quot;margin-top:15px;color:#C00000;&quot;>§DATA_ENTRY_432 <b>§INSTANCE</b></div> <div style=&quot;margin-top:15px;color:#C00000;font-weight:bold;&quot;>§DATA_ENTRY_190</div> </div>"',
                        'DELETE ALL DATA ON THIS FORM?', null, 600, null,
                        'Cancel',
                        function () {
                            // @ts-ignore
                            dataEntrySubmit(document.getElementsByName('submit-btn-deleteform')[0]);
                            event.preventDefault();
                            window.opener.refreshTables();
                            window.setTimeout(window.close, 300);
                        },
                        '§DATA_ENTRY_234'); //Delete data for THIS FORM only
                    return false;
                });
            // @ts-ignore - Injection
            if ('§REQ_MSG' == 'true') {
                setTimeout(function () {
                    $('div[aria-describedby="reqPopup"]').find('div.ui-dialog-buttonpane').find('button').not(':last').hide(); // .css('visibility', 'visible'); // required fields message show only "OK" button, not ignore & leave
                }, 100);
            }
        }
    });
})()
