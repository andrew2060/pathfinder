/**
 * system route module
 */

define([
    'jquery',
    'app/init',
    'app/util',
    'bootbox'
], function($, Init, Util, bootbox) {
    'use strict';

    var config = {
        // module info
        moduleClass: 'pf-module',                                               // class for each module

        // system route module
        systemRouteModuleClass: 'pf-system-route-module',                       // class  for this module

        // headline toolbar
        systemModuleHeadlineIcon: 'pf-module-icon-button',                      // class for toolbar icons in the head

        systemSecurityClassPrefix: 'pf-system-security-',                       // prefix class for system security level (color)

        // dialog
        routeDialogId: 'pf-route-dialog',                                       // id for route dialog
        systemDialogSelectClass: 'pf-system-dialog-select',                     // class for system select Element
        systemInfoRoutesTableRowPrefix: 'pf-system-info-routes-row-',           // prefix class for a row in the route table
        systemInfoRoutesTableClass: 'pf-system-route-table'                     // class for route tables

    };

    // cache for system routes
    var cache = {
        systemRoutes: {}                                                        // jump information between solar systems
    };

    /**
     * callback function, adds new row to a dataTable with jump information for a route
     * @param context
     * @param routesData
     */
    var callbackAddRouteRow = function(context, routesData){

        for(var i = 0; i < routesData.length; i++){
            var routeData = routesData[i];

            // format routeData
            var rowData = formatRouteData(routeData);

            if(rowData){
                var cacheKey = routeData.route[0].system + '_' + routeData.route[ routeData.route.length - 1 ].system;

                // update route cache
                cache.systemRoutes[cacheKey] = rowData;

                addRow(context.dataTable, rowData);
            }
        }
    };

    /**
     * add a new dataTable row to the jump table
     * @param dataTable
     * @param rowData
     */
    var addRow = function(dataTable, rowData){
        var rowClass = config.systemInfoRoutesTableRowPrefix + dataTable.rows().data().length;

        // add new row
        var rowElement = dataTable.row.add( rowData ).draw().nodes().to$();
        rowElement.addClass( rowClass );

        rowElement.find('i').tooltip();
    };


    /**
     * show route dialog. User can search for systems and jump-info for each system is added to a data table
     * @param dialogData
     */
    var showFindRouteDialog = function(dialogData){

        var data = {
            id: config.routeDialogId,
            selectClass: config.systemDialogSelectClass,
            systemFrom: dialogData.systemFrom
        };

        requirejs(['text!templates/dialog/route.html', 'mustache'], function(template, Mustache) {

            var content = Mustache.render(template, data);

            // disable modal focus event -> otherwise select2 is not working! -> quick fix
            $.fn.modal.Constructor.prototype.enforceFocus = function() {};

            var findRouteDialog = bootbox.dialog({
                title: 'Search route',
                message: content,
                buttons: {
                    close: {
                        label: 'cancel',
                        className: 'btn-default',
                        callback: function(){
                            $(findRouteDialog).modal('hide');
                        }
                    },
                    success: {
                        label: '<i class="fa fa-fw fa-search"></i>&nbsp;search route',
                        className: 'btn-primary',
                        callback: function () {
                            // add new route to route table

                            // get form Values
                            var form = $('#' + config.routeDialogId).find('form');

                            var routeDialogData = $(form).getFormValues();

                            // validate form
                            form.validator('validate');

                            // check weather the form is valid
                            var formValid = form.isValidForm();

                            if(formValid === false){
                                // don't close dialog
                                return false;
                            }


                            var requestRouteData = [{
                                systemFrom: dialogData.systemFrom,
                                systemTo: routeDialogData.systemTo
                            }];


                            var contextData = {
                                dataTable: dialogData.dataTable
                            };

                            getRouteData(requestRouteData, contextData, callbackAddRouteRow);
                        }
                    }
                }
            });


            // init dialog
            findRouteDialog.on('shown.bs.modal', function(e) {

                var modalContent = $('#' + config.routeDialogId);

                // init system select live  search
                var selectElement = modalContent.find('.' + config.systemDialogSelectClass);

                selectElement.initSystemSelect({key: 'name'});
            });
        });
    };

    /**
     * requests route data from eveCentral API and execute callback
     * @param requestRouteData
     * @param contextData
     * @param callback
     */
    var getRouteData = function(requestRouteData, contextData, callback){

        var requestData = {routeData: requestRouteData};

        $.ajax({
            url: Init.path.searchRoute,
            type: 'POST',
            dataType: 'json',
            data: requestData,
            context: contextData
        }).done(function(routesData){
            // execute callback
            callback(this, routesData.routesData);
        });

    };

    /**
     * format route data from API request into dataTable row format
     * @param routeData
     * @returns {*[]}
     */
    var formatRouteData = function(routeData){

        var rowData = false;
        if(routeData.routePossible === true){
            // route data available

            // add route Data
            rowData = [routeData.route[ routeData.route.length - 1 ].system.toLowerCase(), routeData.routeJumps];

            var jumpData = [];

            // loop all systems on this route
            for(var i = 0; i < routeData.route.length; i++){
                var routeNodeData = routeData.route[i];

                var systemSec = Number(routeNodeData.security).toFixed(1).toString();
                var tempSystemSec = systemSec;

                if(tempSystemSec <= 0){
                    tempSystemSec = '0-0';
                }

                var systemSecClass = config.systemSecurityClassPrefix + tempSystemSec.replace('.', '-');

                var system = '<i class="fa fa-square ' + systemSecClass + '" ';
                system += 'data-toggle="tooltip" data-placement="bottom" data-container="body" ';
                system += 'title="' + routeNodeData.system + ' [' + systemSec + '] "></i>';
                jumpData.push( system );

            }

            rowData.push( jumpData.join(' ') );
        }


        return rowData;
    };

    /**
     * get the route finder moduleElement
     * @param systemData
     * @returns {*}
     */
    var getModule = function(systemData){

        var moduleElement = null;

        // load trade routes for k-space systems
        if(systemData.type.id === 2){

            // create new module container
            moduleElement = $('<div>', {
                class: [config.moduleClass, config.systemRouteModuleClass].join(' ')
            });


            // headline toolbar icons
            var headlineToolbar  = $('<h5>', {
                class: 'pull-right'
            }).append(
                    $('<i>', {
                        class: ['fa', 'fa-fw', 'fa-search', config.systemModuleHeadlineIcon].join(' '),
                        title: 'find route'
                    }).attr('data-toggle', 'tooltip')
                );

            moduleElement.append(headlineToolbar);

            // headline
            var headline = $('<h5>', {
                class: 'pull-left',
                text: 'Routes'
            });

            moduleElement.append(headline);

            // init tooltips
            var tooltipElements = moduleElement.find('[data-toggle="tooltip"]');
            tooltipElements.tooltip({
                container: 'body'
            });

            // crate new route table
            var table = $('<table>', {
                class: ['compact', 'stripe', 'order-column', 'row-border', config.systemInfoRoutesTableClass].join(' ')
            });

            moduleElement.append( $(table) );

            // init empty table
            var routesTable = table.DataTable( {
                paging: false,
                ordering: true,
                order: [ 1, 'asc' ],
                info: false,
                searching: false,
                hover: false,
                autoWidth: false,
                language: {
                    emptyTable:  'No routes added'
                },
                columnDefs: [
                    {
                        targets: 0,
                        orderable: true,
                        title: 'system&nbsp;&nbsp;&nbsp;'
                    },{
                        targets: 1,
                        orderable: true,
                        title: 'jumps&nbsp;&nbsp;&nbsp',
                        width: '40px',
                        class: 'text-right'
                    },{
                        targets: 2,
                        orderable: false,
                        title: 'route'
                    }
                ],
                data: [] // will be added dynamic
            });

        }

        return moduleElement;
    };

    var initModule = function(moduleElement, systemData){

        var systemFrom = systemData.name;
        var systemsTo = ['Jita', 'Amarr', 'Rens', 'Dodixie'];

        var routesTableElement =  moduleElement.find('.' + config.systemInfoRoutesTableClass);

        var routesTable = routesTableElement.DataTable();

        // init system search dialog -------------------------------------------------------------------------------

        moduleElement.find('.' + config.systemModuleHeadlineIcon).on('click', function(e){
            // show "find route" dialog

            var dialogData = {
                moduleElement: moduleElement,
                systemFrom: systemFrom,
                dataTable: routesTable
            };

            showFindRouteDialog(dialogData);
        })

        // fill routesTable with data ------------------------------------------------------------------------------
        var requestRouteData = [];

        for(var i = 0; i < systemsTo.length; i++){
            var systemTo = systemsTo[i];

            if(systemFrom !== systemTo){
                var cacheKey = systemFrom.toUpperCase() + '_' + systemTo.toUpperCase();

                if(cache.systemRoutes.hasOwnProperty(cacheKey)){
                    addRow(routesTable, cache.systemRoutes[cacheKey]);
                }else{
                    // get route data

                    requestRouteData.push({
                        systemFrom: systemFrom,
                        systemTo: systemTo
                    });
                }
            }
        }

        // check if routes data is not cached and is requested
        if(requestRouteData.length > 0){
            var contextData = {
                dataTable: routesTable
            };
            getRouteData(requestRouteData, contextData, callbackAddRouteRow);
        }
    };


    /**
     * updates an dom element with the system route module
     * @param systemData
     */
    $.fn.drawSystemRouteModule = function(systemData){

        var parentElement = $(this);

        // show route module
        var showModule = function(moduleElement){
            if(moduleElement){
                moduleElement.css({ opacity: 0 });
                parentElement.append(moduleElement);

                moduleElement.velocity('transition.slideDownIn', {
                    duration: Init.animationSpeed.mapModule,
                    delay: Init.animationSpeed.mapModule,
                    complete: function(){
                        initModule(moduleElement, systemData);
                    }
                });
            }
        };

        // check if module already exists
        var moduleElement = parentElement.find('.' + config.systemRouteModuleClass);

        if(moduleElement.length > 0){
            moduleElement.velocity('transition.slideDownOut', {
                duration: Init.animationSpeed.mapModule,
                complete: function(tempElement){
                    $(tempElement).remove();

                    moduleElement = getModule(systemData);
                    showModule(moduleElement);
                }
            });
        }else{
            moduleElement = getModule(systemData);
            showModule(moduleElement);
        }

    };

});