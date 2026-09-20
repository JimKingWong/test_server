define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'department/admin/index',
                    add_url: 'department/admin/add',
                    edit_url: 'department/admin/edit',
                    del_url: 'department/admin/del',
                    multi_url: 'department/admin/multi',
                }
            });

            var table = $("#table");

            //在表格内容渲染完成后回调的事件
            table.on('post-body.bs.table', function (e, json) {

            });
            var columnss=[
                {field: 'state', checkbox: true, },
                {field: 'id', title: 'ID', sortable: true,},

                {field: 'username', title: __('Username'), operate: "LIKE",},
                {field: 'nickname', title: __('Nickname'), operate: "LIKE",},
                {field: 'status', title: __("Status"), searchList: {"normal":__('Normal'),"hidden":__('离职')}, formatter: Table.api.formatter.status},
                {field: 'dadmin', title: __('Principal') , operate:false,
                    formatter: function (value, row, index) {
                        var str=__('No');
                        if (value.length == 0)
                            return str ;
                        $.each(value,function(i,v){  //arrTmp数组数据
                            if (v.is_principal==1){
                                str='<span class="text-success">'+__('Yes')+'</span>' ;
                            }
                        });
                        return str ;
                    }
                },
                {field: 'data_scope', title: __('数据范围') , operate:false,
                    formatter: function (value, row, index) {
                        var str=__('默认');
                        if (value==1){
                            str='<span class="text-red">'+__('全部')+'</span>' ;
                        }else if ( row.dadmin.length > 0){
                            $.each(  row.dadmin,function(i,v){  //arrTmp数组数据
                                if (v.is_principal==1){
                                    str='<span class="text-success">'+__('部门')+'</span>' ;
                                }
                            });
                        }
                        return str ;
                    }
                },
                {
                    field: 'department_id',
                    title: __('Department'),
                    visible: false,
                    addclass: 'selectpage',
                    extend: 'data-source="department/index/index" data-field="name"',
                    operate: 'in',
                    formatter: Table.api.formatter.search
                },
                {
                    field: 'dadmin',
                    title: __('Department'),
                    formatter: function (value, row, index) {
                        if (value.length == 0)
                            return '-' ;
                        var department="";
                        $.each(value,function(i,v){  //arrTmp数组数据
                            if (v.department){
                                department+=department?','+v.department.name:v.department.name;
                            }
                        });
                        return  Table.api.formatter.flag.call(this, department, row, index);
                    }
                    , operate:false
                },

                {
                    field: 'groups',
                    title: __('Group'),
                    formatter: function (value, row, index) {
                        if (value.length == 0)
                            return '-' ;
                        var groups_text="";
                        $.each(value,function(i,v){  //arrTmp数组数据
                            if (v.get_group){
                                groups_text+=groups_text?','+v.get_group.name:v.get_group.name;
                            }
                        });
                        return  Table.api.formatter.flag.call(this, groups_text, row, index);
                    }
                    , operate:false
                },

                {field: 'email', title: __('Email'), operate: "LIKE",},

            ];
            if (Config.exits_mobile) {
                //如果是选择
                columnss.push({
                    field: 'mobile', title: __('Mobile'), operate: "LIKE",
                });
            }
            columnss.push(
                {field: 'logintime', title: __('Login time'), formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate,
                    buttons: [
                        {
                            name: 'principal',
                            text: __('Principal'),
                            title: __('Principal set'),
                            icon: 'fa fa-street-view',
                            classname: 'btn btn-xs btn-danger btn-dialog',
                            url: 'department/admin/principal',
                        },
						 {
                            name: 'qrcode',
                            text: __('绑定微信'),
                            title: __('绑定微信'),
                            icon: '',
                            classname: 'btn btn-xs btn-danger btn-dialog',
                            url: 'department/admin/qrcode?isview=1',
                        },
                    ],
                    formatter: function (value, row, index) {
                        return Table.api.formatter.operate.call(this, value, row, index);
                    }});

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                columns: [columnss],
                //启用固定列
                fixedColumns: true,
                //固定右侧列数
                fixedRightNumber: 1,
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            require(['jstree'], function () {
                //全选和展开
                $(document).on("click", "#checkall", function () {
                    $("#departmenttree").jstree($(this).prop("checked") ? "check_all" : "uncheck_all");
                });
                $(document).on("click", "#expandall", function () {
                    $("#departmenttree").jstree($(this).prop("checked") ? "open_all" : "close_all");
                });
                $('#departmenttree').on("changed.jstree", function (e, data) {
                    console.log(data.selected.join(","));
                    $(".commonsearch-table input[name=department_id]").val(data.selected.join(","));
                    table.bootstrapTable('refresh', {});
                    return false;
                });
                $('#departmenttree').jstree({
                    "themes": {
                        "stripes": true
                    },
                    "checkbox": {
                        "keep_selected_style": false,
                    },
                    "types": {
                        "channel": {
                            "icon": false,
                        },
                        "list": {
                            "icon": false,
                        },
                        "link": {
                            "icon": false,
                        },
                        "disabled": {
                            "check_node": false,
                            "uncheck_node": false
                        }
                    },
                    'plugins': ["types", "checkbox"],
                    "core": {
                        "multiple": true,
                        'check_callback': true,
                        "data": Config.departmentList
                    }
                });
            });

        },
        add: function () {
            Controller.api.bindevent();
        },
        principal:function(){
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
                $(document).on("change", "#department_ids", function(){
                    //变更后的回调事件
                    var dname=$(this).find("option:selected").first().text()
                    var nickname=$("#nickname").val();
                    var a = nickname.indexOf("-");

                    if (a!=-1){
                        nickname=nickname.substring(0, a);
                    }
                    dname = dname.replace(/\s*/g,"");
                    nickname+="-"+dname.replace(/&nbsp;|│|└|├\s*/ig, "");
                    $("#nickname").val(nickname);
                });
            },
        }



    };
    return Controller;
});
