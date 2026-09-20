define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'department/index/index',
                    add_url: 'department/index/add',
                    edit_url: 'department/index/edit',
                    del_url: 'department/index/del',
                    multi_url: 'department/index/multi',
                    dragsort_url: '',
                    table: 'department',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'weigh',
                pagination: false,
                escape: false,
                fixedColumns: true,
                fixedRightNumber: 1,
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id')},

                        {field: 'name', title: __('Name'), align: 'left'},

                        {
                            field: 'weigh',
                            title: __('Weigh'),
                            formatter: function (value, row, index) {
                                return '<input type="text" class="form-control text-center text-weigh" data-id="' + row.id + '" value="' + value + '" style="width:50px;margin:0 auto;" />';
                            },
                            events: {
                                "dblclick .text-weigh": function (e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    return false;
                                }
                            }
                        },
                        {
                            field: 'createtime',
                            title: __('Createtime'),
                            visible: false,
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'updatetime',
                            title: __('Updatetime'),
                            visible: false,
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {field: 'status', title: __('Status'), formatter: Table.api.formatter.status},
                        {
                            field: 'tags',
                            title: __('标签属性'),
                            operate: "LIKE",
                            align: 'left',
                            formatter: function (value, row, index) {
                                return Table.api.formatter.flag.call(this, row['tags'], row, index);
                            }
                        },
                        {
                            field: 'id',
                            title: '<a href="javascript:;" class="btn btn-success btn-xs btn-toggle"><i class="fa fa-chevron-up"></i></a>',
                            operate: false,
                            formatter: Controller.api.formatter.subnode
                        },
                        {
                            field: 'operate',
                            title: __('Operate'),
                            table: table,
                            width: 160,
                            events: Table.api.events.operate,
                            formatter: Table.api.formatter.operate,
                            buttons: [
                                {
                                    name: 'add',
                                    text: __('Children'),
                                    classname: 'btn btn-info btn-xs btn-dialog',
                                    icon: 'fa fa-plus',
                                    url: 'department/index/add/parent_id/{ids}'
                                },
                                {
                                    name: 'index',
                                    text: __('Employee'),
                                    classname: 'btn btn-info btn-xs btn-dialog',
                                    icon: 'fa fa-users',
                                    url: 'department/admin/index/department_id/{ids}'
                                }

                            ]
                        }
                    ]
                ],
                search: false,
                commonSearch: false
            });

            // 绑定TAB事件
            $('.panel-heading a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
                var field = $(this).closest("ul").data("field");
                var value = $(this).data("value");
                var options = table.bootstrapTable('getOptions');
                options.pageNumber = 1;
                options.queryParams = function (params) {
                    params.model_id = value;
                    return params;
                };
                table.bootstrapTable('refresh', {});
                return false;
            });

            $(document).on("change", ".text-weigh", function () {
                $(this).data("params", {weigh: $(this).val()});
                Table.api.multi('', [$(this).data("id")], table, this);
                return false;
            });

            //当内容渲染完成后
            table.on('post-body.bs.table', function (e, settings, json, xhr) {
                //默认隐藏所有子节点
                $("a.btn[data-id][data-pid][data-pid!=0]").closest("tr").hide();
                $(".btn-node-sub.disabled[data-pid!=0]").closest("tr").hide();

                //显示隐藏子节点
                $(".btn-node-sub").off("click").on("click", function (e) {
                    var status = $(this).data("shown") || $("a.btn[data-pid='" + $(this).data("id") + "']:visible").size() > 0 ? true : false;
                    $("a.btn[data-pid='" + $(this).data("id") + "']").each(function () {
                        $(this).closest("tr").toggle(!status);
                        if (!$(this).hasClass("disabled")) {
                            $(this).trigger("click");
                        }
                    });
                    $(this).data("shown", !status);
                    return false;
                });

            });
            //展开隐藏一级
            $(document.body).on("click", ".btn-toggle", function (e) {
                $("a.btn[data-id][data-pid][data-pid!=0].disabled").closest("tr").hide();
                var that = this;
                var show = $("i", that).hasClass("fa-chevron-down");
                $("i", that).toggleClass("fa-chevron-down", !show);
                $("i", that).toggleClass("fa-chevron-up", show);
                $("a.btn[data-id][data-pid][data-pid!=0]").closest("tr").toggle(show);
                $(".btn-node-sub[data-pid=0]").data("shown", show);
            });
            //展开隐藏全部
            $(document.body).on("click", ".btn-toggle-all", function (e) {
                var that = this;
                var show = $("i", that).hasClass("fa-plus");
                $("i", that).toggleClass("fa-plus", !show);
                $("i", that).toggleClass("fa-minus", show);
                $(".btn-node-sub.disabled[data-pid!=0]").closest("tr").toggle(show);
                $(".btn-node-sub[data-pid!=0]").data("shown", show);
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        add: function () {
            Controller.api.bindevent();
            $("input[name='row[type]'][value=list]").trigger("click");
            $("select[name='row[model_id]']").trigger("change");
        },
        edit: function () {
            Controller.api.bindevent();
            $("input[name='row[type]']:checked").trigger("fa.event.typeupdated", "edit");
        },
         api: {
            formatter: {
                title: function (value, row, index) {
                    return !row.ismenu || row.status == 'hidden' ? "<span class='text-muted'>" + value + "</span>" : value;
                },
                name: function (value, row, index) {
                    return !row.ismenu || row.status == 'hidden' ? "<span class='text-muted'>" + value + "</span>" : value;
                },
                icon: function (value, row, index) {
                    return '<span class="' + (!row.ismenu || row.status == 'hidden' ? 'text-muted' : '') + '"><i class="' + value + '"></i></span>';
                },
                subnode: function (value, row, index) {
                    return '<a href="javascript:;" data-toggle="tooltip" title="' + __('Toggle sub menu') + '" data-id="' + row.id + '" data-pid="' + row.pid + '" class="btn btn-xs '
                        + (row.haschild == 1 || row.ismenu == 1 ? 'btn-success' : 'btn-default disabled') + ' btn-node-sub"><i class="fa fa-' + (row.haschild == 1 || row.ismenu == 1 ? 'sitemap' : 'list') + '"></i></a>';
                },

            },
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
                require.config({
                        paths: {
                            'tagsinput': '../addons/department/js/jquery.tagsinput',
                            'autocomplete': '../addons/department/js/jquery.autocomplete',
                        },
                        shim: {
                            'autocomplete': {
                                deps: ['jquery'],
                                exports: '$.fn.extend'
                            },
                            'tagsinput': {
                                deps: ['jquery', 'autocomplete', 'css!../addons/department/css/jquery.tagsinput.min.css'],
                                exports: '$.fn.extend'
                            }
                        }
                    }
                );
                require(['tagsinput'], function () {
                    //标签输入
                    var elem = "#c-tags";
                    var tags = $(elem);
                    tags.tagsInput({
                        width: 'auto',
                        defaultText: '输入后空格确认',
                        minInputWidth: 110,
                        height: '36px',
                        placeholderColor: '#999',
                        onChange: function (row) {
                            if (typeof callback === 'function') {

                            } else {
                                $(elem + "_addTag").focus();
                                $(elem + "_tag").trigger("blur.autocomplete").focus();
                            }
                        },
                  
                    });
                });

            }
        }
    };
    return Controller;
});
