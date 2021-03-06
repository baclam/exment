<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
//use Encore\Admin\Controllers\HasResourceActions;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\PluginType;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use File;

class PluginController extends AdminControllerBase
{
    use HasResourceActions, RoleForm;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("plugin.header"), exmtrans("plugin.header"), exmtrans("plugin.description"));
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $content->row(view('exment::plugin.upload'));
        $content->body($this->grid());
        return $content;
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Plugin);
        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->like('uuid', exmtrans("plugin.uuid"));
            $filter->like('plugin_name', exmtrans("plugin.plugin_name"));
        });

        $grid->column('plugin_name', exmtrans("plugin.plugin_name"))->sortable();
        $grid->column('plugin_view_name', exmtrans("plugin.plugin_view_name"))->sortable();
        $grid->column('plugin_type', exmtrans("plugin.plugin_type"))->display(function ($value) {
            return PluginType::getEnum($value)->transKey("plugin.plugin_type_options") ?? null;
        })->sortable();
        $grid->column('author', exmtrans("plugin.author"));
        $grid->column('version', exmtrans("plugin.version"));
        $grid->column('active_flg', exmtrans("plugin.active_flg"))->display(function ($active_flg) {
            return boolval($active_flg) ? exmtrans("common.available_true") : exmtrans("common.available_false");
        });

        $grid->disableCreateButton();
        $grid->disableExport();
        
        $grid->actions(function ($actions) {
            $actions->disableView();
        });
        return $grid;
    }

    //Function use to upload file and update or add new record
    protected function store(Request $request)
    {
        //Check file existed in Request
        if ($request->hasfile('fileUpload')) {
            return PluginInstaller::uploadPlugin($request->file('fileUpload'));
        }
        // if not exists, return back and message
        return back()->with('errorMess', exmtrans("plugin.help.errorMess"));
    }

    //Delete record from database (one or multi records)
    protected function destroy($id)
    {
        $this->deleteFolder($id);
        if ($this->form()->destroy($id)) {
            return response()->json([
                'status' => true,
                'message' => trans('admin.delete_succeeded'),
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => trans('admin.delete_failed'),
            ]);
        }
    }

    //Delete one or multi folder corresponds to the plugins
    protected function deleteFolder($id)
    {
        $idlist = explode(",", $id);
        foreach ($idlist as $id) {
            $plugin = Plugin::getEloquent($id);
            if (!isset($plugin)) {
                continue;
            }
            $folder = $plugin->getFullPath();
            if (File::isDirectory($folder)) {
                File::deleteDirectory($folder);
            }
        }
    }

    //Check request when edit record to delete null values in event_triggers
    protected function update(Request $request, $id)
    {
        if (isset($request->get('options')['event_triggers']) === true) {
            $event_triggers = $request->get('options')['event_triggers'];
            $options = $request->get('options');
            $event_triggers = array_filter($event_triggers, 'strlen');
            $options['event_triggers'] = $event_triggers;
            $request->merge(['options' => $options]);
        }
        return $this->form()->update($id);
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $plugin = Plugin::getEloquent($id);

        // create form
        $form = new Form(new Plugin);
        $form->display('uuid', exmtrans("plugin.uuid"));
        $form->display('plugin_name', exmtrans("plugin.plugin_name"));
        $form->display('plugin_view_name', exmtrans("plugin.plugin_view_name"));
        // create as label
        $form->display('plugin_type', exmtrans("plugin.plugin_type"))->with(function ($value) {
            return PluginType::getEnum($value)->transKey("plugin.plugin_type_options") ?? null;
        });
        $form->display('author', exmtrans("plugin.author"));
        $form->display('version', exmtrans("plugin.version"));
        $form->switch('active_flg', exmtrans("plugin.active_flg"));
        $plugin_type = Plugin::getFieldById($id, 'plugin_type');
        $form->embeds('options', exmtrans("plugin.options.header"), function ($form) use ($plugin_type) {
            if (in_array($plugin_type, [PluginType::TRIGGER, PluginType::DOCUMENT])) {
                $form->multipleSelect('target_tables', exmtrans("plugin.options.target_tables"))->options(function ($value) {
                    $options = CustomTable::filterList()->pluck('table_view_name', 'table_name')->toArray();
                    return $options;
                })->help(exmtrans("plugin.help.target_tables"));
                // only trigger
                if ($plugin_type == PluginType::TRIGGER) {
                    $form->multipleSelect('event_triggers', exmtrans("plugin.options.event_triggers"))->options(function ($value) {
                        return getTransArray(Define::PLUGIN_EVENT_TRIGGER, "plugin.options.event_trigger_options");
                    })->help(exmtrans("plugin.help.event_triggers"));
                }
            } else {
                // Plugin_type = 'page'
                $form->text('uri', exmtrans("plugin.options.uri"));
            }
            $form->text('label', exmtrans("plugin.options.label"));
            $form->icon('icon', exmtrans("plugin.options.icon"))->help(exmtrans("plugin.help.icon"));
            $form->text('button_class', exmtrans("plugin.options.button_class"))->help(exmtrans("plugin.help.button_class"));
        })->disableHeader();

        // Role setting --------------------------------------------------
        // TODO:error
        //$this->addRoleForm($form, RoleType::PLUGIN);

        $form->disableReset();
        return $form;
    }
}
